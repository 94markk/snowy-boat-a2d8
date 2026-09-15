/**
 * The wallet ledger.
 *
 * Two invariants hold this together:
 *
 *  1. `wallet_entries` is append-only. A correction is a new row with the
 *     opposite sign, never an edit, so the history always explains the balance.
 *  2. Every movement writes the ledger row and updates `users.balance_centimes`
 *     inside one D1 batch, which is a single transaction. Either both land or
 *     neither does — the cached balance can never drift from the ledger.
 *
 * Debits carry the same `balance >= amount` guard on both statements, so two
 * concurrent spends cannot both pass: whichever commits second sees the reduced
 * balance and writes nothing.
 */

import { newId, now } from "./db";

export type EntryKind = "topup" | "purchase" | "refund" | "adjustment";

export type WalletResult =
  | { ok: true; balanceCentimes: number; entryId: string }
  | { ok: false; reason: "insufficient_funds" | "already_applied" | "invalid_amount" };

export interface MovementInput {
  userId: string;
  /** Always positive. Direction comes from the function you call. */
  amountCentimes: number;
  kind: EntryKind;
  reference?: string | null;
  /**
   * Stable across retries of the same logical event — a top-up id, an order id.
   * The UNIQUE constraint on it is what makes crediting a repeated webhook
   * delivery a no-op rather than free money.
   */
  idempotencyKey: string;
  createdBy?: string | null;
}

function isValidAmount(amount: number): boolean {
  return Number.isSafeInteger(amount) && amount > 0;
}

/** True when a D1 failure is the idempotency key hitting its UNIQUE index. */
function isDuplicateKey(error: unknown): boolean {
  const message = error instanceof Error ? error.message : String(error);
  return /UNIQUE constraint failed: wallet_entries\.idempotency_key/i.test(message);
}

async function currentBalance(db: D1Database, userId: string): Promise<number> {
  const row = await db
    .prepare(`SELECT balance_centimes FROM users WHERE id = ?`)
    .bind(userId)
    .first<{ balance_centimes: number }>();
  return row?.balance_centimes ?? 0;
}

/** Adds money to a wallet. */
export async function credit(db: D1Database, input: MovementInput): Promise<WalletResult> {
  if (!isValidAmount(input.amountCentimes)) return { ok: false, reason: "invalid_amount" };

  const entryId = newId("wal");
  const timestamp = now();
  const amount = input.amountCentimes;

  try {
    await db.batch([
      db
        .prepare(
          `INSERT INTO wallet_entries
             (id, user_id, amount_centimes, kind, reference, idempotency_key, balance_after, created_by, created_at)
           SELECT ?, ?, ?, ?, ?, ?, u.balance_centimes + ?, ?, ?
             FROM users u WHERE u.id = ?`,
        )
        .bind(
          entryId,
          input.userId,
          amount,
          input.kind,
          input.reference ?? null,
          input.idempotencyKey,
          amount,
          input.createdBy ?? null,
          timestamp,
          input.userId,
        ),
      db
        .prepare(`UPDATE users SET balance_centimes = balance_centimes + ?, updated_at = ? WHERE id = ?`)
        .bind(amount, timestamp, input.userId),
    ]);
  } catch (error) {
    if (isDuplicateKey(error)) return { ok: false, reason: "already_applied" };
    throw error;
  }

  return { ok: true, balanceCentimes: await currentBalance(db, input.userId), entryId };
}

/** Removes money from a wallet, refusing to go below zero. */
export async function debit(db: D1Database, input: MovementInput): Promise<WalletResult> {
  if (!isValidAmount(input.amountCentimes)) return { ok: false, reason: "invalid_amount" };

  const entryId = newId("wal");
  const timestamp = now();
  const amount = input.amountCentimes;

  let results;
  try {
    results = await db.batch([
      // The WHERE clause is the guard: with too little balance this inserts
      // nothing, and the UPDATE below matches nothing for the same reason.
      db
        .prepare(
          `INSERT INTO wallet_entries
             (id, user_id, amount_centimes, kind, reference, idempotency_key, balance_after, created_by, created_at)
           SELECT ?, ?, ?, ?, ?, ?, u.balance_centimes - ?, ?, ?
             FROM users u
            WHERE u.id = ? AND u.balance_centimes >= ?`,
        )
        .bind(
          entryId,
          input.userId,
          -amount,
          input.kind,
          input.reference ?? null,
          input.idempotencyKey,
          amount,
          input.createdBy ?? null,
          timestamp,
          input.userId,
          amount,
        ),
      db
        .prepare(
          `UPDATE users
              SET balance_centimes = balance_centimes - ?, updated_at = ?
            WHERE id = ? AND balance_centimes >= ?`,
        )
        .bind(amount, timestamp, input.userId, amount),
    ]);
  } catch (error) {
    if (isDuplicateKey(error)) return { ok: false, reason: "already_applied" };
    throw error;
  }

  // Nothing written means the guard rejected the spend.
  const inserted = results[0]?.meta?.changes ?? 0;
  if (inserted === 0) return { ok: false, reason: "insufficient_funds" };

  return { ok: true, balanceCentimes: await currentBalance(db, input.userId), entryId };
}

export interface LedgerRow {
  id: string;
  amountCentimes: number;
  kind: EntryKind;
  reference: string | null;
  balanceAfter: number;
  createdAt: number;
}

export async function recentEntries(
  db: D1Database,
  userId: string,
  limit = 25,
): Promise<LedgerRow[]> {
  const capped = Math.min(Math.max(Math.floor(limit), 1), 100);
  const { results } = await db
    .prepare(
      `SELECT id, amount_centimes, kind, reference, balance_after, created_at
         FROM wallet_entries
        WHERE user_id = ?
        ORDER BY created_at DESC, rowid DESC
        LIMIT ?`,
    )
    .bind(userId, capped)
    .all<any>();

  return (results ?? []).map((row) => ({
    id: row.id,
    amountCentimes: row.amount_centimes,
    kind: row.kind,
    reference: row.reference,
    balanceAfter: row.balance_after,
    createdAt: row.created_at,
  }));
}

/**
 * Recomputes a balance from the ledger and reports any drift from the cached
 * column. Nothing should ever be found; it exists so that "nothing was found"
 * is a fact rather than an assumption. Exposed through the admin panel.
 */
export async function reconcile(
  db: D1Database,
  userId: string,
): Promise<{ ledgerTotal: number; cachedBalance: number; drift: number }> {
  const row = await db
    .prepare(
      `SELECT COALESCE((SELECT SUM(amount_centimes) FROM wallet_entries WHERE user_id = ?), 0) AS ledger_total,
              (SELECT balance_centimes FROM users WHERE id = ?) AS cached`,
    )
    .bind(userId, userId)
    .first<{ ledger_total: number; cached: number }>();

  const ledgerTotal = row?.ledger_total ?? 0;
  const cachedBalance = row?.cached ?? 0;
  return { ledgerTotal, cachedBalance, drift: cachedBalance - ledgerTotal };
}
