import assert from "node:assert/strict";
import test from "node:test";
import {
  describesIncomingPayment,
  detectProvider,
  isActionable,
  parseSms,
} from "../src/lib/payments/parse.ts";

/**
 * Add real messages from the merchant handset here before going live. The
 * wording below is representative, not authoritative — see the note at the top
 * of src/lib/payments/parse.ts.
 */
const samples = [
  {
    name: "MonCash, Creole",
    sender: "MonCash",
    body: "Ou resevwa 500.00 HTG nan men JEAN PIERRE (50934567890). Nouvo balans ou se 2500.00 HTG. Transaction ID: 9F3K2L8M",
    expect: { provider: "moncash", amountCentimes: 500_00, msisdn: "34567890", transactionId: "9F3K2L8M" },
  },
  {
    name: "MonCash, French",
    sender: "MonCash",
    body: "Vous avez reçu 1,250.00 HTG de MARIE JOSEPH (+509 3812 4455). Reference: AB12CD34",
    expect: { provider: "moncash", amountCentimes: 1_250_00, msisdn: "38124455", transactionId: "AB12CD34" },
  },
  {
    name: "NatCash",
    sender: "NatCash",
    body: "NatCash: Ou resevwa 144 G nan men (50947112233). Ref: NC77812345",
    expect: { provider: "natcash", amountCentimes: 144_00, msisdn: "47112233", transactionId: "NC77812345" },
  },
  {
    name: "amount with a comma decimal",
    sender: "MonCash",
    body: "Ou resevwa 1 500,50 HTG nan men PAUL (50922334455). Transaction ID: XY9090",
    expect: { provider: "moncash", amountCentimes: 1_500_50, msisdn: "22334455", transactionId: "XY9090" },
  },
];

for (const sample of samples) {
  test(`parses: ${sample.name}`, () => {
    const parsed = parseSms(sample.sender, sample.body);
    assert.equal(parsed.provider, sample.expect.provider);
    assert.equal(parsed.amountCentimes, sample.expect.amountCentimes);
    assert.equal(parsed.msisdn, sample.expect.msisdn);
    assert.equal(parsed.transactionId, sample.expect.transactionId);
    assert.equal(isActionable(parsed, sample.body), true);
  });
}

test("an outgoing transfer is never treated as an incoming payment", () => {
  const body = "Ou voye 500.00 HTG bay JEAN (50934567890). Transaction ID: OUT123";
  assert.equal(describesIncomingPayment(body), false);
  assert.equal(isActionable(parseSms("MonCash", body), body), false);
});

test("a balance notification with no payer is not actionable", () => {
  const body = "Balans ou se 2500.00 HTG.";
  assert.equal(isActionable(parseSms("MonCash", body), body), false);
});

test("an SMS from an unknown sender is not actionable", () => {
  const body = "Ou resevwa 500.00 HTG nan men JEAN (50934567890).";
  assert.equal(detectProvider("Promo", body), "unknown");
  assert.equal(isActionable(parseSms("Promo", body), body), false);
});

test("a message with no recognisable amount is not actionable", () => {
  const body = "Ou resevwa yon pèman nan men JEAN (50934567890).";
  assert.equal(isActionable(parseSms("MonCash", body), body), false);
});
