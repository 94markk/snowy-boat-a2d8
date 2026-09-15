import assert from "node:assert/strict";
import test from "node:test";
import { createTestDb, seedUser } from "./helpers/d1.js";
import { credit, debit, reconcile } from "../src/lib/wallet.ts";

test("credit adds money and records the running balance", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);

  const result = await credit(db, {
    userId,
    amountCentimes: 500_00,
    kind: "topup",
    idempotencyKey: "topup:1",
  });

  assert.equal(result.ok, true);
  assert.equal(result.balanceCentimes, 500_00);

  const { drift } = await reconcile(db, userId);
  assert.equal(drift, 0);
});

test("a repeated idempotency key credits only once", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);

  const first = await credit(db, { userId, amountCentimes: 300_00, kind: "topup", idempotencyKey: "topup:same" });
  const second = await credit(db, { userId, amountCentimes: 300_00, kind: "topup", idempotencyKey: "topup:same" });

  assert.equal(first.ok, true);
  assert.equal(second.ok, false);
  assert.equal(second.reason, "already_applied");

  const { cachedBalance, ledgerTotal } = await reconcile(db, userId);
  assert.equal(cachedBalance, 300_00, "the retry must not credit a second time");
  assert.equal(ledgerTotal, 300_00);
});

test("debit refuses to spend more than the balance", async () => {
  const db = createTestDb();
  const userId = await seedUser(db, { balance: 100_00 });

  const result = await debit(db, {
    userId,
    amountCentimes: 150_00,
    kind: "purchase",
    idempotencyKey: "order:1",
  });

  assert.equal(result.ok, false);
  assert.equal(result.reason, "insufficient_funds");

  const { cachedBalance, ledgerTotal, drift } = await reconcile(db, userId);
  assert.equal(cachedBalance, 100_00);
  assert.equal(ledgerTotal, 0, "a refused debit must leave no ledger row");
  // This user was seeded with a balance and no opening ledger entry, so the
  // whole seeded amount shows as drift. That is the fixture, not a defect —
  // the tests that seed through credit() assert drift is zero.
  assert.equal(drift, 100_00);
});

test("a failed debit rolls back completely, leaving no orphan ledger row", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);

  await credit(db, { userId, amountCentimes: 100_00, kind: "topup", idempotencyKey: "t1" });
  const refused = await debit(db, { userId, amountCentimes: 100_01, kind: "purchase", idempotencyKey: "o1" });

  assert.equal(refused.ok, false);

  const { drift } = await reconcile(db, userId);
  assert.equal(drift, 0, "cached balance and ledger must still agree");
});

test("sequential debits stop exactly at zero", async () => {
  const db = createTestDb();
  const userId = await seedUser(db);
  await credit(db, { userId, amountCentimes: 250_00, kind: "topup", idempotencyKey: "t1" });

  const a = await debit(db, { userId, amountCentimes: 100_00, kind: "purchase", idempotencyKey: "o1" });
  const b = await debit(db, { userId, amountCentimes: 100_00, kind: "purchase", idempotencyKey: "o2" });
  const c = await debit(db, { userId, amountCentimes: 100_00, kind: "purchase", idempotencyKey: "o3" });

  assert.equal(a.ok, true);
  assert.equal(b.ok, true);
  assert.equal(c.ok, false, "the third spend exceeds what is left");
  assert.equal(b.balanceCentimes, 50_00);

  const { drift, ledgerTotal } = await reconcile(db, userId);
  assert.equal(ledgerTotal, 50_00);
  assert.equal(drift, 0);
});

test("a zero or negative amount is rejected outright", async () => {
  const db = createTestDb();
  const userId = await seedUser(db, { balance: 100_00 });

  for (const amount of [0, -1, 1.5, Number.NaN, Number.MAX_SAFE_INTEGER + 2]) {
    const result = await debit(db, { userId, amountCentimes: amount, kind: "purchase", idempotencyKey: `k${amount}` });
    assert.equal(result.ok, false, `amount ${amount} must be refused`);
    assert.equal(result.reason, "invalid_amount");
  }
});
