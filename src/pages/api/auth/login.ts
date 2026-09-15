import type { APIRoute } from "astro";
import {
  clearFailedLogins,
  createSession,
  isLockedOut,
  normalizeEmail,
  recordFailedLogin,
  sessionCookie,
  verifyPassword,
} from "../../../lib/auth";
import { writeAudit } from "../../../lib/db";
import { clientIp, isSameOrigin, json, methodNotAllowed, readJson } from "../../../lib/http";
import { allowRequest } from "../../../lib/ratelimit";

export const prerender = false;

/**
 * A password hash that no password matches, used to spend the same CPU on an
 * unknown email as on a known one. Without it, response time leaks which
 * addresses have accounts.
 */
const DUMMY_HASH =
  "pbkdf2-sha256$210000$AAAAAAAAAAAAAAAAAAAAAA==$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=";

export const POST: APIRoute = async ({ request, locals }) => {
  if (!isSameOrigin(request)) return json({ error: "forbidden_origin" }, 403);

  const env = locals.runtime?.env as Env | undefined;
  const db = env?.DB;
  if (!db) return json({ error: "unavailable" }, 503);

  if (!(await allowRequest(request, env, "login"))) return json({ error: "rate_limited" }, 429);

  const body = await readJson(request);
  if (!body.ok) return body.response;

  const email = normalizeEmail(String(body.value?.email ?? ""));
  const password = String(body.value?.password ?? "");

  const row = await db
    .prepare(
      `SELECT id, password_hash, status, failed_logins, locked_until, balance_centimes, locale
         FROM users WHERE email_normalized = ?`,
    )
    .bind(email)
    .first<any>();

  if (row && isLockedOut(row)) {
    return json({ error: "locked_out" }, 429);
  }

  const matches = await verifyPassword(password, row?.password_hash ?? DUMMY_HASH);

  // One message for every failure mode, so nothing distinguishes "no such
  // account" from "wrong password".
  if (!row || !matches || row.status !== "active") {
    if (row) await recordFailedLogin(db, row.id);
    return json({ error: "invalid_credentials" }, 401);
  }

  await clearFailedLogins(db, row.id);

  const session = await createSession(db, row.id, {
    ip: clientIp(request),
    userAgent: request.headers.get("User-Agent"),
  });

  await writeAudit(db, { actorUserId: row.id, action: "auth.login", ip: clientIp(request) });

  return json({ ok: true, balanceCentimes: row.balance_centimes, locale: row.locale }, 200, {
    "Set-Cookie": sessionCookie(session.token),
  });
};

export const ALL: APIRoute = () => methodNotAllowed("POST");
