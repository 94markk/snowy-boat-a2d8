/**
 * Matching an incoming payment SMS to a pending top-up.
 *
 * The customer declares, before paying, which number they will send from and
 * exactly how much. That gives three matching keys — provider, payer number and
 * amount — inside a bounded time window. A message that matches nothing is kept
 * for staff to resolve by hand; it is never guessed at.
 */

import { newId, now, writeAudit } from "../db";
import { credit } from "../wallet";
import { isActionable, parseSms, type ParsedSms } from "./parse";

export interface IncomingSms {
  /** Identifier from the forwarding device. Must be stable across retries. */
  externalId: string;
  deviceId: string;
  sender: string | null;
  body: string;
  receivedAt?: number;
}

export type MatchOutcome =
  | { status: "duplicate"; smsId: string }
  | { status: "ignored"; smsId: string; reason: string }
  | { status: "unmatched"; smsId: string; parsed: ParsedSms }
  | { status: "matched"; smsId: string; topupId: string; userId: string; amountCentimes: number };

/**
 * Store, parse and (when possible) apply an incoming SMS.
 *
 * Safe to call twice with the same `externalId`: the second call short-circuits
 * on the unique index before any money moves.
 */
export async function ingestSms(db: D1Database, sms: IncomingSms): Promise<MatchOutcome> {
  const receivedAt = sms.receivedAt ?? now();
  const parsed = parseSms(sms.sender, sms.body);
  const smsId = newId("sms");

  const actionable = isActionable(parsed, sms.body);

  try {
    await db
      .prepare(
        `INSERT INTO sms_messages
           (id, provider, external_id, device_id, sender, body,
            parsed_amount_centimes, parsed_msisdn, parsed_txn_id, status, received_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      )
      .bind(
        smsId,
        parsed.provider,
        sms.externalId,
        sms.deviceId,
        sms.sender,
        sms.body.slice(0, 2_000),
        parsed.amountCentimes,
        parsed.msisdn,
        parsed.transactionId,
        actionable ? "parsed" : "ignored",
        receivedAt,
      )
      .run();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    // Either the forwarder resent the same message, or the operator's own
    // transaction id has already been seen. Both mean: already handled.
    if (/UNIQUE constraint failed: sms_messages\.(external_id)/i.test(message)) {
      const existing = await db
        .prepare(`SELECT id FROM sms_messages WHERE external_id = ?`)
        .bind(sms.externalId)
        .first<{ id: string }>();
      return { status: "duplicate", smsId: existing?.id ?? "" };
    }
    if (/UNIQUE constraint failed:[^:]*sms_messages\.parsed_txn_id/i.test(message)) {
      const existing = await db
        .prepare(`SELECT id FROM sms_messages WHERE provider = ? AND parsed_txn_id = ?`)
        .bind(parsed.provider, parsed.transactionId)
        .first<{ id: string }>();
      return { status: "duplicate", smsId: existing?.id ?? "" };
    }
    throw error;
  }

  if (!actionable) {
    return {
      status: "ignored",
      smsId,
      reason: !parsed.amountCentimes
        ? "no_amount"
        : !parsed.msisdn
          ? "no_payer_number"
          : parsed.provider === "unknown"
            ? "unknown_provider"
            : "not_an_incoming_payment",
    };
  }

  // Oldest matching request first, so a customer who queued two identical
  // top-ups gets them settled in the order they were made.
  const candidate = await db
    .prepare(
      `SELECT id, user_id
         FROM topup_requests
        WHERE provider = ?
          AND payer_msisdn = ?
          AND amount_centimes = ?
          AND status = 'pending'
          AND expires_at > ?
        ORDER BY created_at ASC
        LIMIT 1`,
    )
    .bind(parsed.provider, parsed.msisdn, parsed.amountCentimes, receivedAt)
    .first<{ id: string; user_id: string }>();

  if (!candidate) {
    await db
      .prepare(`UPDATE sms_messages SET status = 'unmatched', processed_at = ? WHERE id = ?`)
      .bind(now(), smsId)
      .run();
    return { status: "unmatched", smsId, parsed };
  }

  // Claim the request before crediting. The conditional UPDATE means that if
  // two messages race for one request, only the first one gets it.
  const claim = await db
    .prepare(
      `UPDATE topup_requests
          SET status = 'matched', sms_id = ?, matched_at = ?
        WHERE id = ? AND status = 'pending'`,
    )
    .bind(smsId, now(), candidate.id)
    .run();

  if ((claim.meta?.changes ?? 0) === 0) {
    await db
      .prepare(`UPDATE sms_messages SET status = 'unmatched', processed_at = ? WHERE id = ?`)
      .bind(now(), smsId)
      .run();
    return { status: "unmatched", smsId, parsed };
  }

  const result = await credit(db, {
    userId: candidate.user_id,
    amountCentimes: parsed.amountCentimes!,
    kind: "topup",
    reference: candidate.id,
    // Tied to the top-up, so replaying this SMS through another path still
    // cannot credit the same top-up twice.
    idempotencyKey: `topup:${candidate.id}`,
  });

  if (!result.ok && result.reason !== "already_applied") {
    // Crediting failed for a reason we did not anticipate. Release the request
    // so it can still be settled, and leave the SMS for staff.
    await db
      .prepare(`UPDATE topup_requests SET status = 'pending', sms_id = NULL, matched_at = NULL WHERE id = ?`)
      .bind(candidate.id)
      .run();
    await db
      .prepare(`UPDATE sms_messages SET status = 'unmatched', processed_at = ? WHERE id = ?`)
      .bind(now(), smsId)
      .run();

    await writeAudit(db, {
      action: "topup.credit_failed",
      target: candidate.id,
      detail: { reason: result.reason, smsId },
    });

    return { status: "unmatched", smsId, parsed };
  }

  await db
    .prepare(`UPDATE sms_messages SET status = 'matched', processed_at = ? WHERE id = ?`)
    .bind(now(), smsId)
    .run();

  await writeAudit(db, {
    actorUserId: candidate.user_id,
    action: "topup.credited",
    target: candidate.id,
    detail: { smsId, amountCentimes: parsed.amountCentimes, provider: parsed.provider },
  });

  return {
    status: "matched",
    smsId,
    topupId: candidate.id,
    userId: candidate.user_id,
    amountCentimes: parsed.amountCentimes!,
  };
}

/** Marks pending top-ups past their window as expired. Called on a schedule. */
export async function expireStaleTopups(db: D1Database): Promise<number> {
  const result = await db
    .prepare(`UPDATE topup_requests SET status = 'expired' WHERE status = 'pending' AND expires_at <= ?`)
    .bind(now())
    .run();
  return result.meta?.changes ?? 0;
}
