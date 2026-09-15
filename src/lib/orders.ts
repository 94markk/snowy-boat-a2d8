/**
 * Order creation and fulfilment.
 *
 * There are two ways to pay, and both settle through the same ledger:
 *
 *   - From the wallet balance, immediately.
 *   - Directly with MonCash or NatCash, in which case the order is created
 *     `pending` and waits for its confirmation SMS. When that arrives the
 *     amount is credited to the wallet and spent on the order in one step, so
 *     every gourde still has a ledger row behind it — and a customer whose
 *     order cannot be charged keeps the money rather than losing it.
 *
 * Either way the order is only ever fulfilled after it is paid:
 *
 *   1. Re-price the basket from the catalog. Client-supplied prices are ignored.
 *   2. Validate every required field (player id, email) against its pattern.
 *   3. Take payment. If it cannot be taken, nothing else happens.
 *   4. Mark the order paid, then fulfil each line.
 *   5. Any line that fails permanently is refunded to the wallet.
 */

import { MAX_ORDER_LINES, MAX_QTY_PER_ITEM, WALLET } from "../config";
import type { Locale } from "../i18n/ui";
import { getVariant, type Product, type RequiredField } from "../data/catalog";
import { normalizeMsisdn } from "./auth";
import { newId, newReference, now, writeAudit } from "./db";
import { getSupplier } from "./suppliers";
import { credit, debit } from "./wallet";

export interface BasketLineInput {
  variantId: string;
  qty: number;
  fields?: Record<string, unknown>;
}

export interface PricedLine {
  product: Product;
  variantId: string;
  label: string;
  qty: number;
  unitPriceCentimes: number;
  lineTotalCentimes: number;
  fields: Record<string, string>;
}

export type PriceOutcome =
  | { ok: true; lines: PricedLine[]; totalCentimes: number }
  | { ok: false; error: "empty" | "unknown_variant" | "unavailable" | "invalid_field"; detail?: string };

/** Anchored test, so a pattern cannot match only part of the value. */
function fieldMatches(field: RequiredField, value: string): boolean {
  if (value.length === 0 || value.length > field.maxLength) return false;
  try {
    return new RegExp(`^(?:${field.pattern})$`, "u").test(value);
  } catch {
    return false;
  }
}

function sanitizeQty(value: unknown): number {
  const n = typeof value === "number" ? value : Number(value);
  if (!Number.isFinite(n)) return 0;
  const floored = Math.floor(n);
  return floored >= 1 ? Math.min(floored, MAX_QTY_PER_ITEM) : 0;
}

/**
 * Re-price an untrusted basket against the catalog. This is the only place an
 * order total is ever decided.
 */
export function priceBasket(input: unknown, locale: Locale): PriceOutcome {
  if (!Array.isArray(input) || input.length === 0) return { ok: false, error: "empty" };
  if (input.length > MAX_ORDER_LINES) return { ok: false, error: "empty" };

  const lines: PricedLine[] = [];
  let totalCentimes = 0;

  for (const entry of input) {
    if (!entry || typeof entry !== "object") return { ok: false, error: "unknown_variant" };

    const variantId = (entry as BasketLineInput).variantId;
    if (typeof variantId !== "string") return { ok: false, error: "unknown_variant" };

    const found = getVariant(variantId);
    if (!found) return { ok: false, error: "unknown_variant", detail: variantId };
    if (!found.variant.available) return { ok: false, error: "unavailable", detail: variantId };

    const qty = sanitizeQty((entry as BasketLineInput).qty);
    if (qty === 0) return { ok: false, error: "unknown_variant", detail: variantId };

    // Collect and check the product's required fields.
    const supplied = ((entry as BasketLineInput).fields ?? {}) as Record<string, unknown>;
    const fields: Record<string, string> = {};

    for (const field of found.product.requiredFields ?? []) {
      const raw = supplied[field.name];
      const value = typeof raw === "string" ? raw.trim() : "";
      if (!fieldMatches(field, value)) {
        return { ok: false, error: "invalid_field", detail: field.name };
      }
      fields[field.name] = value;
    }

    const lineTotalCentimes = found.variant.priceCentimes * qty;
    totalCentimes += lineTotalCentimes;

    lines.push({
      product: found.product,
      variantId,
      label: `${found.product.name}${found.product.region ? ` (${found.product.region})` : ""} — ${found.variant.label[locale]}`,
      qty,
      unitPriceCentimes: found.variant.priceCentimes,
      lineTotalCentimes,
      fields,
    });
  }

  return { ok: true, lines, totalCentimes };
}

export type PlaceOrderResult =
  | {
      ok: true;
      orderId: string;
      reference: string;
      totalCentimes: number;
      /** Present when paid from the wallet. */
      balanceCentimes?: number;
      /** Present when the customer must now send mobile money. */
      payment?: { topupId: string; provider: MobileMoneyProvider; expiresAt: number };
    }
  | {
      ok: false;
      error:
        | "insufficient_funds"
        | "empty"
        | "unknown_variant"
        | "unavailable"
        | "invalid_field"
        | "invalid_phone"
        | "too_many_pending";
      detail?: string;
    };

export type MobileMoneyProvider = "moncash" | "natcash";

/** Creates the order and its lines. Does not take payment. */
async function writeOrder(
  db: D1Database,
  userId: string,
  priced: Extract<PriceOutcome, { ok: true }>,
  locale: Locale,
  status: "pending" | "paid",
  paymentMethod: "wallet" | MobileMoneyProvider,
): Promise<{ orderId: string; reference: string }> {
  const orderId = newId("ord");
  const reference = newReference();
  const timestamp = now();

  await db.batch([
    db
      .prepare(
        `INSERT INTO orders
           (id, reference, user_id, status, total_centimes, locale, payment_method, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      )
      .bind(
        orderId,
        reference,
        userId,
        status,
        priced.totalCentimes,
        locale,
        paymentMethod,
        timestamp,
        timestamp,
      ),
    ...priced.lines.map((line) =>
      db
        .prepare(
          `INSERT INTO order_lines
             (id, order_id, product_id, variant_id, label, qty, unit_price_centimes, fields_json, supplier)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        )
        .bind(
          newId("lin"),
          orderId,
          line.product.id,
          line.variantId,
          line.label,
          line.qty,
          line.unitPriceCentimes,
          JSON.stringify(line.fields),
          line.product.supplier ?? null,
        ),
    ),
  ]);

  return { orderId, reference };
}

/** Prices the basket, takes payment from the wallet, and records the order. */
export async function placeOrder(
  db: D1Database,
  userId: string,
  basket: unknown,
  locale: Locale,
): Promise<PlaceOrderResult> {
  const priced = priceBasket(basket, locale);
  if (!priced.ok) return { ok: false, error: priced.error, detail: priced.detail };

  const { orderId, reference } = await writeOrder(db, userId, priced, locale, "pending", "wallet");

  const payment = await debit(db, {
    userId,
    amountCentimes: priced.totalCentimes,
    kind: "purchase",
    reference: orderId,
    idempotencyKey: `order:${orderId}`,
  });

  if (!payment.ok) {
    // Nothing was charged, so the order must not survive as a claim on goods.
    await db.prepare(`DELETE FROM orders WHERE id = ?`).bind(orderId).run();
    return { ok: false, error: "insufficient_funds" };
  }

  await db
    .prepare(`UPDATE orders SET status = 'paid', updated_at = ? WHERE id = ?`)
    .bind(now(), orderId)
    .run();

  await writeAudit(db, {
    actorUserId: userId,
    action: "order.placed",
    target: orderId,
    detail: { reference, totalCentimes: priced.totalCentimes, method: "wallet" },
  });

  return {
    ok: true,
    orderId,
    reference,
    totalCentimes: priced.totalCentimes,
    balanceCentimes: payment.balanceCentimes,
  };
}

/**
 * Creates an order that waits for a MonCash or NatCash payment.
 *
 * Nothing is charged and nothing is fulfilled here. The customer is told the
 * exact amount to send and from which number; `settleOrderPayment` runs when
 * the matching SMS arrives.
 */
export async function placeMobileMoneyOrder(
  db: D1Database,
  userId: string,
  basket: unknown,
  locale: Locale,
  provider: MobileMoneyProvider,
  payerMsisdnInput: string,
): Promise<PlaceOrderResult> {
  const priced = priceBasket(basket, locale);
  if (!priced.ok) return { ok: false, error: priced.error, detail: priced.detail };

  const payerMsisdn = normalizeMsisdn(payerMsisdnInput);
  if (!payerMsisdn) return { ok: false, error: "invalid_phone" };

  // Two pending payments with the same provider, number and amount would be
  // indistinguishable to the matcher, so refuse rather than guess later.
  const clash = await db
    .prepare(
      `SELECT id FROM topup_requests
        WHERE provider = ? AND payer_msisdn = ? AND amount_centimes = ?
          AND status = 'pending' AND expires_at > ?`,
    )
    .bind(provider, payerMsisdn, priced.totalCentimes, now())
    .first<{ id: string }>();

  if (clash) return { ok: false, error: "too_many_pending" };

  const { orderId, reference } = await writeOrder(db, userId, priced, locale, "pending", provider);

  const topupId = newId("pay");
  const createdAt = now();
  const expiresAt = createdAt + WALLET.topUpTtlMinutes * 60;

  await db
    .prepare(
      `INSERT INTO topup_requests
         (id, user_id, provider, amount_centimes, payer_msisdn, status, purpose, order_id, created_at, expires_at)
       VALUES (?, ?, ?, ?, ?, 'pending', 'order', ?, ?, ?)`,
    )
    .bind(topupId, userId, provider, priced.totalCentimes, payerMsisdn, orderId, createdAt, expiresAt)
    .run();

  await writeAudit(db, {
    actorUserId: userId,
    action: "order.awaiting_payment",
    target: orderId,
    detail: { reference, totalCentimes: priced.totalCentimes, provider },
  });

  return {
    ok: true,
    orderId,
    reference,
    totalCentimes: priced.totalCentimes,
    payment: { topupId, provider, expiresAt },
  };
}

/**
 * Charges a pending order against the wallet once its payment has landed.
 *
 * Called from the SMS matcher, right after the incoming amount was credited.
 * Returns true when the order moved to paid and is ready to fulfil.
 */
export async function settleOrderPayment(db: D1Database, orderId: string): Promise<boolean> {
  const order = await db
    .prepare(`SELECT id, user_id, status, total_centimes, reference FROM orders WHERE id = ?`)
    .bind(orderId)
    .first<{
      id: string;
      user_id: string;
      status: string;
      total_centimes: number;
      reference: string;
    }>();

  // Already paid, or gone: nothing to do, and safe to call twice.
  if (!order || order.status !== "pending") return false;

  const payment = await debit(db, {
    userId: order.user_id,
    amountCentimes: order.total_centimes,
    kind: "purchase",
    reference: orderId,
    idempotencyKey: `order:${orderId}`,
  });

  if (!payment.ok) {
    if (payment.reason === "already_applied") {
      // A previous run charged it; just move the order forward.
      await db
        .prepare(`UPDATE orders SET status = 'paid', updated_at = ? WHERE id = ? AND status = 'pending'`)
        .bind(now(), orderId)
        .run();
      return true;
    }

    // The credit should have covered this exactly. If it somehow did not, the
    // money stays in the customer's wallet rather than vanishing.
    await writeAudit(db, {
      actorUserId: order.user_id,
      action: "order.settle_failed",
      target: orderId,
      detail: { reason: payment.reason, totalCentimes: order.total_centimes },
    });
    return false;
  }

  await db
    .prepare(`UPDATE orders SET status = 'paid', updated_at = ? WHERE id = ?`)
    .bind(now(), orderId)
    .run();

  await writeAudit(db, {
    actorUserId: order.user_id,
    action: "order.placed",
    target: orderId,
    detail: { reference: order.reference, totalCentimes: order.total_centimes, method: "mobile_money" },
  });

  return true;
}

/**
 * Attempt delivery of every pending line on an order.
 *
 * Lines with no configured supplier, and those the supplier rejects as
 * permanent, are refunded. Retryable failures are left pending for the next
 * pass rather than refunded, so a supplier outage does not cancel real orders.
 */
export async function fulfilOrder(db: D1Database, env: Env, orderId: string): Promise<void> {
  const order = await db
    .prepare(`SELECT id, reference, user_id, status FROM orders WHERE id = ?`)
    .bind(orderId)
    .first<{ id: string; reference: string; user_id: string; status: string }>();

  if (!order || (order.status !== "paid" && order.status !== "fulfilling")) return;

  await db
    .prepare(`UPDATE orders SET status = 'fulfilling', updated_at = ? WHERE id = ?`)
    .bind(now(), orderId)
    .run();

  const { results: lines } = await db
    .prepare(
      `SELECT id, product_id, variant_id, qty, unit_price_centimes, fields_json, supplier, attempts
         FROM order_lines
        WHERE order_id = ? AND fulfilment_status IN ('pending', 'sent')`,
    )
    .bind(orderId)
    .all<any>();

  let refundedCentimes = 0;

  for (const line of lines ?? []) {
    const variant = getVariant(line.variant_id);
    const adapter = getSupplier(line.supplier);

    // Manual products, and anything with no adapter, wait for staff.
    if (!adapter || !variant) {
      await db
        .prepare(`UPDATE order_lines SET fulfilment_status = 'pending', last_error = ? WHERE id = ?`)
        .bind(adapter ? "unknown_variant" : "awaiting_manual_fulfilment", line.id)
        .run();
      continue;
    }

    let result;
    try {
      result = await adapter.fulfil(
        {
          lineId: line.id,
          orderReference: order.reference,
          supplierSku: variant.variant.supplierSku ?? line.variant_id,
          quantity: line.qty,
          fields: JSON.parse(line.fields_json || "{}"),
        },
        env,
      );
    } catch (error) {
      result = {
        status: "failed" as const,
        reason: error instanceof Error ? error.message : "adapter_threw",
        retryable: true as const,
      };
    }

    const attempts = (line.attempts ?? 0) + 1;

    if (result.status === "delivered") {
      await db
        .prepare(
          `UPDATE order_lines
              SET fulfilment_status = 'delivered', delivered_payload = ?, supplier_ref = ?,
                  delivered_at = ?, attempts = ?, last_error = NULL
            WHERE id = ?`,
        )
        .bind(result.payload, result.supplierRef ?? null, now(), attempts, line.id)
        .run();
      continue;
    }

    if (result.status === "pending") {
      await db
        .prepare(
          `UPDATE order_lines SET fulfilment_status = 'sent', supplier_ref = ?, attempts = ? WHERE id = ?`,
        )
        .bind(result.supplierRef, attempts, line.id)
        .run();
      continue;
    }

    if (result.retryable) {
      await db
        .prepare(`UPDATE order_lines SET fulfilment_status = 'pending', attempts = ?, last_error = ? WHERE id = ?`)
        .bind(attempts, result.reason.slice(0, 300), line.id)
        .run();
      continue;
    }

    // Permanent failure: mark it failed and give the money back.
    await db
      .prepare(`UPDATE order_lines SET fulfilment_status = 'failed', attempts = ?, last_error = ? WHERE id = ?`)
      .bind(attempts, result.reason.slice(0, 300), line.id)
      .run();

    const lineTotal = line.unit_price_centimes * line.qty;
    const refund = await credit(db, {
      userId: order.user_id,
      amountCentimes: lineTotal,
      kind: "refund",
      reference: orderId,
      // Keyed on the line, so a repeated fulfilment pass refunds only once.
      idempotencyKey: `refund:${line.id}`,
    });
    if (refund.ok) refundedCentimes += lineTotal;
  }

  await settleOrderStatus(db, orderId);

  if (refundedCentimes > 0) {
    await writeAudit(db, {
      actorUserId: order.user_id,
      action: "order.refunded",
      target: orderId,
      detail: { refundedCentimes },
    });
  }
}

/** Rolls the line states up into the order's own status. */
export async function settleOrderStatus(db: D1Database, orderId: string): Promise<void> {
  const counts = await db
    .prepare(
      `SELECT
         COUNT(*) AS total,
         SUM(CASE WHEN fulfilment_status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
         SUM(CASE WHEN fulfilment_status = 'failed'    THEN 1 ELSE 0 END) AS failed
       FROM order_lines WHERE order_id = ?`,
    )
    .bind(orderId)
    .first<{ total: number; delivered: number; failed: number }>();

  if (!counts) return;

  const status =
    counts.delivered === counts.total
      ? "delivered"
      : counts.failed === counts.total
        ? "refunded"
        : "fulfilling";

  await db
    .prepare(`UPDATE orders SET status = ?, updated_at = ? WHERE id = ?`)
    .bind(status, now(), orderId)
    .run();
}
