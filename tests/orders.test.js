import assert from "node:assert/strict";
import test from "node:test";
import { createTestDb, seedUser } from "./helpers/d1.js";
import { priceBasket, placeOrder } from "../src/lib/orders.ts";
import { credit } from "../src/lib/wallet.ts";
import { getVariant } from "../src/data/catalog.ts";

const FF = "ff-latam-310";
const ffPrice = getVariant(FF).variant.priceCentimes;
const validFields = { player_id: "123456789" };

test("prices a basket from the catalog, not from the client", () => {
  const result = priceBasket(
    // A client claiming the item costs 1 gourde.
    [{ variantId: FF, qty: 2, fields: validFields, priceCentimes: 100, unitPrice: 1 }],
    "fr",
  );

  assert.equal(result.ok, true);
  assert.equal(result.totalCentimes, ffPrice * 2, "the catalog price wins");
});

test("rejects a variant that does not exist", () => {
  const result = priceBasket([{ variantId: "not-real", qty: 1 }], "fr");
  assert.equal(result.ok, false);
  assert.equal(result.error, "unknown_variant");
});

test("rejects a missing or malformed required field", () => {
  for (const fields of [undefined, {}, { player_id: "" }, { player_id: "abc" }, { player_id: "12" }]) {
    const result = priceBasket([{ variantId: FF, qty: 1, fields }], "fr");
    assert.equal(result.ok, false, `fields ${JSON.stringify(fields)} must be refused`);
    assert.equal(result.error, "invalid_field");
  }
});

test("a required-field pattern is anchored, so a partial match is refused", () => {
  const result = priceBasket(
    [{ variantId: FF, qty: 1, fields: { player_id: "123456789'; DROP TABLE users; --" } }],
    "fr",
  );
  assert.equal(result.ok, false);
  assert.equal(result.error, "invalid_field");
});

test("quantity is clamped and non-positive quantities are refused", () => {
  const huge = priceBasket([{ variantId: FF, qty: 1e9, fields: validFields }], "fr");
  assert.equal(huge.ok, true);
  assert.equal(huge.lines[0].qty, 10, "quantity is capped at MAX_QTY_PER_ITEM");

  for (const qty of [0, -3, "x", null]) {
    const result = priceBasket([{ variantId: FF, qty, fields: validFields }], "fr");
    assert.equal(result.ok, false);
  }
});

test("an empty or oversized basket is refused", () => {
  assert.equal(priceBasket([], "fr").ok, false);
  assert.equal(priceBasket("not-an-array", "fr").ok, false);
  const oversized = Array.from({ length: 21 }, () => ({ variantId: FF, qty: 1, fields: validFields }));
  assert.equal(priceBasket(oversized, "fr").ok, false);
});

test("placing an order debits exactly the catalog total", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await credit(db, { userId, amountCentimes: 5_000_00, kind: "topup", idempotencyKey: "t1" });

  const result = await placeOrder(db, userId, [{ variantId: FF, qty: 2, fields: validFields }], "fr");

  assert.equal(result.ok, true);
  assert.equal(result.totalCentimes, ffPrice * 2);
  assert.equal(result.balanceCentimes, 5_000_00 - ffPrice * 2);

  const order = await db.prepare("SELECT status, total_centimes FROM orders WHERE id = ?").bind(result.orderId).first();
  assert.equal(order.status, "paid");
  assert.equal(order.total_centimes, ffPrice * 2);
});

test("an order is refused, and nothing is written, when the balance is short", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await credit(db, { userId, amountCentimes: 10_00, kind: "topup", idempotencyKey: "t1" });

  const result = await placeOrder(db, userId, [{ variantId: FF, qty: 1, fields: validFields }], "fr");

  assert.equal(result.ok, false);
  assert.equal(result.error, "insufficient_funds");

  const orders = await db.prepare("SELECT COUNT(*) AS n FROM orders").first();
  assert.equal(orders.n, 0, "no order row may exist for an unpaid basket");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 10_00, "the balance is untouched");
});

test("order lines record the price charged, so a later catalog change cannot rewrite history", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await credit(db, { userId, amountCentimes: 5_000_00, kind: "topup", idempotencyKey: "t1" });

  const result = await placeOrder(db, userId, [{ variantId: FF, qty: 1, fields: validFields }], "fr");
  const line = await db
    .prepare("SELECT unit_price_centimes, label, fields_json FROM order_lines WHERE order_id = ?")
    .bind(result.orderId)
    .first();

  assert.equal(line.unit_price_centimes, ffPrice);
  assert.match(line.label, /Free Fire/);
  assert.deepEqual(JSON.parse(line.fields_json), validFields);
});
