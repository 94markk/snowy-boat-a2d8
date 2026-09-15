import assert from "node:assert/strict";
import test from "node:test";
import { createTestDb, seedUser } from "./helpers/d1.js";
import { placeMobileMoneyOrder, settleOrderPayment } from "../src/lib/orders.ts";
import { ingestSms, expireStalePayments } from "../src/lib/payments/match.ts";
import { credit, reconcile } from "../src/lib/wallet.ts";
import { getVariant } from "../src/data/catalog.ts";

const FF = "ff-latam-310";
const price = getVariant(FF).variant.priceCentimes;
const basket = [{ variantId: FF, qty: 1, fields: { player_id: "123456789" } }];

const paymentSms = (amountCentimes, msisdn = "34567890", txn = "9F3K2L8M") =>
  `Ou resevwa ${(amountCentimes / 100).toFixed(2)} HTG nan men TEST (509${msisdn}). Transaction ID: ${txn}`;

test("a mobile-money order is created pending and charges nothing yet", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);

  const result = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "34567890");

  assert.equal(result.ok, true);
  assert.equal(result.totalCentimes, price);
  assert.equal(result.payment.provider, "moncash");

  const order = await db.prepare("SELECT status, payment_method FROM orders WHERE id = ?").bind(result.orderId).first();
  assert.equal(order.status, "pending");
  assert.equal(order.payment_method, "moncash");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 0, "nothing may be charged before the money arrives");
});

test("the matching SMS pays the order and leaves the wallet at zero", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  const order = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "34567890");

  const outcome = await ingestSms(db, {
    externalId: "sms-1",
    deviceId: "phone",
    sender: "MonCash",
    body: paymentSms(price),
  });

  assert.equal(outcome.status, "matched");
  assert.equal(outcome.orderId, order.orderId);
  assert.equal(outcome.orderPaid, true);

  const row = await db.prepare("SELECT status FROM orders WHERE id = ?").bind(order.orderId).first();
  assert.equal(row.status, "paid");

  // The money passed straight through: credited, then spent on the order.
  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 0);

  const entries = await db
    .prepare("SELECT kind, amount_centimes FROM wallet_entries WHERE user_id = ? ORDER BY rowid")
    .bind(userId)
    .all();
  assert.deepEqual(
    entries.results.map((e) => [e.kind, e.amount_centimes]),
    [
      ["topup", price],
      ["purchase", -price],
    ],
    "both halves of the movement must be in the ledger",
  );

  const { drift } = await reconcile(db, userId);
  assert.equal(drift, 0);
});

test("a replayed payment SMS does not pay the order twice", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  const order = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "34567890");

  const sms = { externalId: "sms-1", deviceId: "phone", sender: "MonCash", body: paymentSms(price) };
  await ingestSms(db, sms);
  const replay = await ingestSms(db, sms);

  assert.equal(replay.status, "duplicate");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 0, "a replay must not credit a second time");

  const count = await db
    .prepare("SELECT COUNT(*) AS n FROM wallet_entries WHERE user_id = ?")
    .bind(userId)
    .first();
  assert.equal(count.n, 2, "exactly one credit and one debit");
});

test("settling twice is harmless", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  const order = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "34567890");
  await credit(db, { userId, amountCentimes: price, kind: "topup", idempotencyKey: "manual" });

  assert.equal(await settleOrderPayment(db, order.orderId), true);
  // A second call finds the order already paid and does nothing.
  assert.equal(await settleOrderPayment(db, order.orderId), false);

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 0);
});

test("paying the wrong amount leaves the order pending and the money in the wallet", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  const order = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "34567890");

  // The customer sends a round number instead of the exact total.
  const outcome = await ingestSms(db, {
    externalId: "sms-1",
    deviceId: "phone",
    sender: "MonCash",
    body: paymentSms(500_00),
  });

  assert.equal(outcome.status, "unmatched", "an amount that matches nothing is parked for staff");

  const row = await db.prepare("SELECT status FROM orders WHERE id = ?").bind(order.orderId).first();
  assert.equal(row.status, "pending");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 0, "an unmatched payment credits nobody automatically");
});

test("two pending orders for the same amount from the same number are refused", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);

  const first = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "34567890");
  const second = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "34567890");

  assert.equal(first.ok, true);
  assert.equal(second.ok, false);
  assert.equal(second.error, "too_many_pending", "the matcher could not tell the two apart");
});

test("the same amount from two different numbers is fine, and each settles its own order", async () => {
  const db = createTestDb();
  const alice = await seedUser(db, { id: "usr_a", email: "a@x.co" });
  const bob = await seedUser(db, { id: "usr_b", email: "b@x.co" });

  const orderA = await placeMobileMoneyOrder(db, alice, basket, "fr", "moncash", "34567890");
  const orderB = await placeMobileMoneyOrder(db, bob, basket, "fr", "moncash", "38001122");

  await ingestSms(db, {
    externalId: "sms-a",
    deviceId: "phone",
    sender: "MonCash",
    body: paymentSms(price, "34567890", "TXAAAAAA"),
  });
  await ingestSms(db, {
    externalId: "sms-b",
    deviceId: "phone",
    sender: "MonCash",
    body: paymentSms(price, "38001122", "TXBBBBBB"),
  });

  for (const [orderId, label] of [
    [orderA.orderId, "alice"],
    [orderB.orderId, "bob"],
  ]) {
    const row = await db.prepare("SELECT status FROM orders WHERE id = ?").bind(orderId).first();
    assert.equal(row.status, "paid", `${label}'s order should be paid`);
  }
});

test("an invalid payer number is refused before an order is written", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);

  const result = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "12");

  assert.equal(result.ok, false);
  assert.equal(result.error, "invalid_phone");

  const orders = await db.prepare("SELECT COUNT(*) AS n FROM orders").first();
  assert.equal(orders.n, 0);
});

test("an unpaid order fails once its payment window closes", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  const order = await placeMobileMoneyOrder(db, userId, basket, "fr", "moncash", "34567890");

  // Wind the window back so it has already closed.
  await db.prepare("UPDATE topup_requests SET expires_at = 1 WHERE order_id = ?").bind(order.orderId).run();
  await expireStalePayments(db);

  const row = await db.prepare("SELECT status, failure_reason FROM orders WHERE id = ?").bind(order.orderId).first();
  assert.equal(row.status, "failed");
  assert.equal(row.failure_reason, "payment_not_received");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 0, "an expired order charges nothing");
});

test("a wallet top-up still works alongside order payments", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);

  await db
    .prepare(
      `INSERT INTO topup_requests (id, user_id, provider, amount_centimes, payer_msisdn, status, purpose, created_at, expires_at)
       VALUES ('top_1', ?, 'natcash', 100000, '34567890', 'pending', 'topup', 0, 99999999999)`,
    )
    .bind(userId)
    .run();

  const outcome = await ingestSms(db, {
    externalId: "sms-1",
    deviceId: "phone",
    sender: "NatCash",
    body: paymentSms(1_000_00),
  });

  assert.equal(outcome.status, "matched");
  assert.equal(outcome.orderId, undefined, "a plain top-up has no order attached");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 1_000_00);
});
