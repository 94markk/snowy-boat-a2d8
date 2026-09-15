/**
 * POST /api/orders
 *
 * Places an order, paid either from the wallet balance or directly with
 * MonCash / NatCash. Prices come from the catalog, never from the request body
 * — see src/lib/orders.ts.
 */

import type { APIRoute } from "astro";
import { PAYMENT_ACCOUNTS } from "../../config";
import { isSameOrigin, json, methodNotAllowed, readJson } from "../../lib/http";
import { fulfilOrder, placeMobileMoneyOrder, placeOrder, type MobileMoneyProvider } from "../../lib/orders";
import { allowRequest } from "../../lib/ratelimit";
import { isLocale } from "../../i18n/utils";

export const prerender = false;

function isMobileMoney(value: unknown): value is MobileMoneyProvider {
  return value === "moncash" || value === "natcash";
}

export const POST: APIRoute = async ({ request, locals }) => {
  if (!isSameOrigin(request)) return json({ error: "forbidden_origin" }, 403);

  const user = locals.user;
  if (!user) return json({ error: "unauthenticated" }, 401);

  const env = locals.runtime?.env as Env | undefined;
  const db = env?.DB;
  if (!db) return json({ error: "unavailable" }, 503);

  if (!(await allowRequest(request, env, "order"))) return json({ error: "rate_limited" }, 429);

  const body = await readJson(request);
  if (!body.ok) return body.response;

  const locale = isLocale(body.value?.locale) ? body.value.locale : user.locale;
  const method = body.value?.method;

  /* ---- Paid with MonCash or NatCash ------------------------------------ */

  if (isMobileMoney(method)) {
    const result = await placeMobileMoneyOrder(
      db,
      user.id,
      body.value?.items,
      locale,
      method,
      String(body.value?.payerMsisdn ?? ""),
    );

    if (!result.ok) return json({ error: result.error, detail: result.detail }, 400);

    // Nothing is charged or delivered yet: the customer now sends the money,
    // and the confirmation SMS settles the order.
    return json(
      {
        ok: true,
        status: "awaiting_payment",
        reference: result.reference,
        orderId: result.orderId,
        totalCentimes: result.totalCentimes,
        payTo: PAYMENT_ACCOUNTS[method],
        expiresAt: result.payment?.expiresAt,
      },
      201,
    );
  }

  /* ---- Paid from the wallet balance ------------------------------------ */

  const result = await placeOrder(db, user.id, body.value?.items, locale);

  if (!result.ok) {
    const status = result.error === "insufficient_funds" ? 402 : 400;
    return json({ error: result.error, detail: result.detail }, status);
  }

  // Delivery runs after the response is sent, so the customer is not left
  // waiting on a distributor API. The order is already paid and recorded.
  locals.runtime?.ctx?.waitUntil?.(
    fulfilOrder(db, env!, result.orderId).catch((error) => {
      console.error("fulfilment failed", result.orderId, error);
    }),
  );

  return json(
    {
      ok: true,
      status: "paid",
      reference: result.reference,
      orderId: result.orderId,
      totalCentimes: result.totalCentimes,
      balanceCentimes: result.balanceCentimes,
    },
    201,
  );
};

export const ALL: APIRoute = () => methodNotAllowed("POST");
