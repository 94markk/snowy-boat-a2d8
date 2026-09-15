/**
 * POST /api/webhooks/sms
 *
 * Receives payment confirmation SMS forwarded from the merchant handset.
 *
 * This endpoint can create money, so it is the most sensitive surface on the
 * site. Four things guard it:
 *
 *   1. An HMAC-SHA256 signature over `timestamp.body`, using a shared secret
 *      the forwarder holds. An unsigned or wrongly signed request is rejected
 *      before the body is even parsed as JSON.
 *   2. A timestamp window, so a captured request cannot be replayed later.
 *   3. `externalId` uniqueness, so a genuine retry is stored once.
 *   4. The operator's own transaction id, so the same payment cannot be
 *      credited twice even under a different externalId.
 *
 * Nothing about the request body decides *who* gets credited — that comes from
 * matching the payer's number and amount to a top-up the customer declared in
 * advance. A forged body with someone else's details still has to pass the HMAC.
 */

import type { APIRoute } from "astro";
import { safeEqual } from "../../../lib/db";
import { json, methodNotAllowed } from "../../../lib/http";
import { fulfilOrder } from "../../../lib/orders";
import { ingestSms } from "../../../lib/payments/match";

export const prerender = false;

/** How far out of step the forwarder's clock may be. */
const TOLERANCE_SECONDS = 300;
const MAX_BODY_BYTES = 8_000;

async function hmacHex(secret: string, message: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  const signature = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(message));
  return [...new Uint8Array(signature)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

export const POST: APIRoute = async ({ request, locals }) => {
  const env = locals.runtime?.env as Env | undefined;
  const db = env?.DB;
  const secret = env?.SMS_WEBHOOK_SECRET;

  if (!db || !secret) {
    console.error("SMS webhook is not configured (DB or SMS_WEBHOOK_SECRET missing)");
    return json({ error: "unavailable" }, 503);
  }

  const signature = request.headers.get("X-DS-Signature");
  const timestamp = request.headers.get("X-DS-Timestamp");
  const deviceId = request.headers.get("X-DS-Device") ?? "unknown";

  if (!signature || !timestamp) return json({ error: "unsigned" }, 401);

  const age = Math.floor(Date.now() / 1000) - Number(timestamp);
  if (!Number.isFinite(age) || Math.abs(age) > TOLERANCE_SECONDS) {
    return json({ error: "stale_timestamp" }, 401);
  }

  // Read as text: the signature covers the exact bytes that were sent.
  const rawBody = await request.text();
  if (rawBody.length > MAX_BODY_BYTES) return json({ error: "payload_too_large" }, 413);

  const expected = await hmacHex(secret, `${timestamp}.${rawBody}`);
  if (!safeEqual(expected, signature.trim().toLowerCase())) {
    console.warn("Rejected SMS webhook: bad signature", { deviceId });
    return json({ error: "bad_signature" }, 401);
  }

  let payload: any;
  try {
    payload = JSON.parse(rawBody);
  } catch {
    return json({ error: "invalid_json" }, 400);
  }

  const externalId = String(payload?.externalId ?? "").slice(0, 200);
  const messageBody = String(payload?.body ?? "");

  if (!externalId || !messageBody) return json({ error: "missing_fields" }, 400);

  const outcome = await ingestSms(db, {
    externalId,
    deviceId: String(payload?.deviceId ?? deviceId).slice(0, 100),
    sender: payload?.sender ? String(payload.sender).slice(0, 100) : null,
    body: messageBody,
    receivedAt: Number.isSafeInteger(payload?.receivedAt) ? payload.receivedAt : undefined,
  });

  // A payment that settled an order releases the goods. This runs after the
  // response so the forwarding device is not held open by a supplier API.
  if (outcome.status === "matched" && outcome.orderId && outcome.orderPaid) {
    locals.runtime?.ctx?.waitUntil?.(
      fulfilOrder(db, env, outcome.orderId).catch((error) => {
        console.error("fulfilment failed", outcome.orderId, error);
      }),
    );
  }

  // Always 200 for anything the endpoint understood, so the forwarder stops
  // retrying. The outcome tells the operator what happened.
  return json({ ok: true, outcome: outcome.status }, 200);
};

export const ALL: APIRoute = () => methodNotAllowed("POST");
