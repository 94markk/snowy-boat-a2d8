import assert from "node:assert/strict";
import test from "node:test";
import { createTestDb, seedUser } from "./helpers/d1.js";
import { ingestSms } from "../src/lib/payments/match.ts";

const NOW = Math.floor(Date.now() / 1000);

async function seedTopup(db, { id = "top_1", userId = "usr_test", amount = 500_00, msisdn = "34567890", provider = "moncash", expiresIn = 3600 } = {}) {
  await db
    .prepare(
      `INSERT INTO topup_requests (id, user_id, provider, amount_centimes, payer_msisdn, status, created_at, expires_at)
       VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)`,
    )
    .bind(id, userId, provider, amount, msisdn, NOW, NOW + expiresIn)
    .run();
  return id;
}

// Transaction ids are as long as the operators actually issue; the parser
// deliberately ignores anything shorter than four characters.
const paymentSms = (amount = "500.00", msisdn = "50934567890", txn = "9F3K2L8M") =>
  `Ou resevwa ${amount} HTG nan men JEAN (${msisdn}). Transaction ID: ${txn}`;

test("a matching SMS credits the wallet exactly once", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await seedTopup(db, { userId });

  const outcome = await ingestSms(db, {
    externalId: "dev-1",
    deviceId: "phone-a",
    sender: "MonCash",
    body: paymentSms(),
  });

  assert.equal(outcome.status, "matched");
  assert.equal(outcome.amountCentimes, 500_00);

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 500_00);
});

test("the same forwarded message delivered twice credits once", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await seedTopup(db, { userId });

  const body = paymentSms();
  const first = await ingestSms(db, { externalId: "dev-1", deviceId: "phone-a", sender: "MonCash", body });
  const second = await ingestSms(db, { externalId: "dev-1", deviceId: "phone-a", sender: "MonCash", body });

  assert.equal(first.status, "matched");
  assert.equal(second.status, "duplicate");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 500_00, "a replayed delivery must not double-credit");
});

test("the same operator transaction id under a new external id still credits once", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await seedTopup(db, { userId });
  await seedTopup(db, { id: "top_2", userId });

  const body = paymentSms();
  await ingestSms(db, { externalId: "dev-1", deviceId: "phone-a", sender: "MonCash", body });
  const replay = await ingestSms(db, { externalId: "dev-2", deviceId: "phone-b", sender: "MonCash", body });

  assert.equal(replay.status, "duplicate", "the operator transaction id is the second line of defence");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 500_00);
});

test("a payment with no pending request is parked, not credited", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);

  const outcome = await ingestSms(db, {
    externalId: "dev-1",
    deviceId: "phone-a",
    sender: "MonCash",
    body: paymentSms(),
  });

  assert.equal(outcome.status, "unmatched");
  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 0);
});

test("a mismatched amount does not match a pending request", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await seedTopup(db, { userId, amount: 500_00 });

  const outcome = await ingestSms(db, {
    externalId: "dev-1",
    deviceId: "phone-a",
    sender: "MonCash",
    body: paymentSms("450.00"),
  });

  assert.equal(outcome.status, "unmatched");
});

test("a payment from a different number does not match", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await seedTopup(db, { userId, msisdn: "34567890" });

  const outcome = await ingestSms(db, {
    externalId: "dev-1",
    deviceId: "phone-a",
    sender: "MonCash",
    body: paymentSms("500.00", "50999887766"),
  });

  assert.equal(outcome.status, "unmatched");
});

test("an expired request is not matched", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await seedTopup(db, { userId, expiresIn: -60 });

  const outcome = await ingestSms(db, {
    externalId: "dev-1",
    deviceId: "phone-a",
    sender: "MonCash",
    body: paymentSms(),
  });

  assert.equal(outcome.status, "unmatched");
});

test("an outgoing-transfer SMS never credits anything", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await seedTopup(db, { userId });

  const outcome = await ingestSms(db, {
    externalId: "dev-1",
    deviceId: "phone-a",
    sender: "MonCash",
    body: "Ou voye 500.00 HTG bay JEAN (50934567890). Transaction ID: OUT99231",
  });

  assert.equal(outcome.status, "ignored");
  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 0);
});

test("two identical pending top-ups settle oldest-first, one SMS each", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await seedTopup(db, { id: "top_old", userId });
  await seedTopup(db, { id: "top_new", userId });

  const a = await ingestSms(db, { externalId: "s1", deviceId: "p", sender: "MonCash", body: paymentSms("500.00", "50934567890", "9F3K2L8M") });
  const b = await ingestSms(db, { externalId: "s2", deviceId: "p", sender: "MonCash", body: paymentSms("500.00", "50934567890", "7B2M4P1Q") });

  assert.equal(a.status, "matched");
  assert.equal(b.status, "matched");
  assert.notEqual(a.topupId, b.topupId, "each SMS claims its own request");

  const user = await db.prepare("SELECT balance_centimes FROM users WHERE id = ?").bind(userId).first();
  assert.equal(user.balance_centimes, 1_000_00);
});
