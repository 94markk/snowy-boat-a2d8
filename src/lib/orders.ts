/**
 * Order creation and fulfilment.
 *
 * The flow is deliberately ordered so money and goods cannot get out of step:
 *
 *   1. Re-price the basket from the catalog. Client-supplied prices are ignored.
 *   2. Validate every required field (player id, email) against its pattern.
 *   3. Debit the wallet. If the balance is short, nothing else happens.
 *   4. Create the order as paid, then fulfil each line.
 *   5. Any line that fails permanently is refunded to the wallet.
 *
 * Debiting before fulfilling means a customer can never receive a code they did
 * not pay for. The refund in step 5 covers the opposite case.
 */

import { MAX_ORDER_LINES, MAX_QTY_PER_ITEM } from "../config";
import type { Locale } from "../i18n/ui";
import { getVariant, type Product, type RequiredField } from "../data/catalog";
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
  | { ok: true; orderId: string; reference: string; totalCentimes: number; balanceCentimes: number }
  | { ok: false; error: "insufficient_funds" | "empty" | "unknown_variant" | "unavailable" | "invalid_field"; detail?: string };

/** Prices the basket, takes payment from the wallet, and records the order. */
export async function placeOrder(
  db: D1Database,
  userId: string,
  basket: unknown,
  locale: Locale,
): Promise<PlaceOrderResult> {
  const priced = priceBasket(basket, locale);
  if (!priced.ok) return { ok: false, error: priced.error, detail: priced.detail };

  const orderId = newId("ord");
  const reference = newReference();
  const timestamp = now();

  // Charge first. An order only exists once it is paid for.
  const payment = await debit(db, {
    userId,
    amountCentimes: priced.totalCentimes,
    kind: "purchase",
    reference: orderId,
    idempotencyKey: `order:${orderId}`,
  });

  if (!payment.ok) {
    return { ok: false, error: "insufficient_funds" };
  }

  const statements = [
    db
      .prepare(
        `INSERT INTO orders (id, reference, user_id, status, total_centimes, locale, created_at, updated_at)
         VALUES (?, ?, ?, 'paid', ?, ?, ?, ?)`,
      )
      .bind(orderId, reference, userId, priced.totalCentimes, locale, timestamp, timestamp),
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
  ];

  await db.batch(statements);

  await writeAudit(db, {
    actorUserId: userId,
    action: "order.placed",
    target: orderId,
    detail: { reference, totalCentimes: priced.totalCentimes, lines: priced.lines.length },
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
