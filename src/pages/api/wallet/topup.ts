/**
 * POST /api/wallet/topup
 *
 * Registers an intent to pay: "I will send G500 from 509XXXXXXXX via MonCash."
 * No money moves here. The wallet is credited only when a matching confirmation
 * SMS arrives from the merchant handset — see src/lib/payments/match.ts.
 */

import type { APIRoute } from "astro";
import { PAYMENT_ACCOUNTS, WALLET, type PaymentProvider } from "../../../config";
import { normalizeMsisdn } from "../../../lib/auth";
import { newId, now, writeAudit } from "../../../lib/db";
import { clientIp, isSameOrigin, json, methodNotAllowed, readJson } from "../../../lib/http";
import { allowRequest } from "../../../lib/ratelimit";

export const prerender = false;

/** At most this many top-ups may be awaiting an SMS at once, per customer. */
const MAX_PENDING_PER_USER = 3;

export const POST: APIRoute = async ({ request, locals }) => {
  if (!isSameOrigin(request)) return json({ error: "forbidden_origin" }, 403);

  const user = locals.user;
  if (!user) return json({ error: "unauthenticated" }, 401);

  const env = locals.runtime?.env as Env | undefined;
  const db = env?.DB;
  if (!db) return json({ error: "unavailable" }, 503);

  if (!(await allowRequest(request, env, "topup"))) return json({ error: "rate_limited" }, 429);

  const body = await readJson(request);
  if (!body.ok) return body.response;

  const provider = String(body.value?.provider ?? "") as PaymentProvider;
  if (!(provider in PAYMENT_ACCOUNTS)) return json({ error: "invalid_provider" }, 400);

  const amountCentimes = Number(body.value?.amountCentimes);
  if (
    !Number.isSafeInteger(amountCentimes) ||
    amountCentimes < WALLET.minTopUpCentimes ||
    amountCentimes > WALLET.maxTopUpCentimes
  ) {
    return json({ error: "invalid_amount" }, 400);
  }

  const msisdn = normalizeMsisdn(String(body.value?.payerMsisdn ?? ""));
  if (!msisdn) return json({ error: "invalid_phone" }, 400);

  const pending = await db
    .prepare(
      `SELECT COUNT(*) AS n FROM topup_requests
        WHERE user_id = ? AND status = 'pending' AND expires_at > ?`,
    )
    .bind(user.id, now())
    .first<{ n: number }>();

  if ((pending?.n ?? 0) >= MAX_PENDING_PER_USER) {
    return json({ error: "too_many_pending" }, 409);
  }

  // Two pending requests with the same provider, number and amount would be
  // indistinguishable to the matcher, so refuse the duplicate.
  const clash = await db
    .prepare(
      `SELECT id FROM topup_requests
        WHERE user_id = ? AND provider = ? AND payer_msisdn = ? AND amount_centimes = ?
          AND status = 'pending' AND expires_at > ?`,
    )
    .bind(user.id, provider, msisdn, amountCentimes, now())
    .first<{ id: string }>();

  if (clash) {
    return json({ error: "duplicate_pending", topupId: clash.id }, 409);
  }

  const id = newId("top");
  const createdAt = now();
  const expiresAt = createdAt + WALLET.topUpTtlMinutes * 60;

  await db
    .prepare(
      `INSERT INTO topup_requests
         (id, user_id, provider, amount_centimes, payer_msisdn, status, created_at, expires_at)
       VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)`,
    )
    .bind(id, user.id, provider, amountCentimes, msisdn, createdAt, expiresAt)
    .run();

  await writeAudit(db, {
    actorUserId: user.id,
    action: "topup.requested",
    target: id,
    detail: { provider, amountCentimes },
    ip: clientIp(request),
  });

  return json(
    {
      ok: true,
      topupId: id,
      expiresAt,
      payTo: PAYMENT_ACCOUNTS[provider],
      amountCentimes,
    },
    201,
  );
};

export const ALL: APIRoute = () => methodNotAllowed("POST");
