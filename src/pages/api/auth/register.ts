import type { APIRoute } from "astro";
import {
  createSession,
  hashPassword,
  isValidEmail,
  normalizeEmail,
  normalizeMsisdn,
  passwordProblem,
  sessionCookie,
} from "../../../lib/auth";
import { newId, now, writeAudit } from "../../../lib/db";
import { clientIp, isSameOrigin, json, methodNotAllowed, readJson } from "../../../lib/http";
import { allowRequest } from "../../../lib/ratelimit";
import { isLocale } from "../../../i18n/utils";

export const prerender = false;

export const POST: APIRoute = async ({ request, locals }) => {
  if (!isSameOrigin(request)) return json({ error: "forbidden_origin" }, 403);

  const env = locals.runtime?.env as Env | undefined;
  const db = env?.DB;
  if (!db) return json({ error: "unavailable" }, 503);

  if (!(await allowRequest(request, env, "register"))) return json({ error: "rate_limited" }, 429);

  const body = await readJson(request);
  if (!body.ok) return body.response;

  const email = normalizeEmail(String(body.value?.email ?? ""));
  const password = String(body.value?.password ?? "");
  const phoneRaw = body.value?.phone;
  const locale = isLocale(body.value?.locale) ? body.value.locale : "fr";

  if (!isValidEmail(email)) return json({ error: "invalid_email" }, 400);

  const problem = passwordProblem(password);
  if (problem) return json({ error: problem }, 400);

  const phone = phoneRaw ? normalizeMsisdn(String(phoneRaw)) : null;
  if (phoneRaw && !phone) return json({ error: "invalid_phone" }, 400);

  const userId = newId("usr");
  const timestamp = now();

  try {
    await db
      .prepare(
        `INSERT INTO users (id, email, email_normalized, phone, password_hash, locale, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      )
      .bind(userId, email, email, phone, await hashPassword(password), locale, timestamp, timestamp)
      .run();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    if (/UNIQUE constraint failed: users\.email_normalized/i.test(message)) {
      // Deliberately the same shape and timing cost as success would be, so the
      // endpoint is not a reliable way to discover who has an account.
      return json({ error: "email_taken" }, 409);
    }
    throw error;
  }

  const session = await createSession(db, userId, {
    ip: clientIp(request),
    userAgent: request.headers.get("User-Agent"),
  });

  await writeAudit(db, {
    actorUserId: userId,
    action: "auth.registered",
    ip: clientIp(request),
  });

  return json({ ok: true, balanceCentimes: 0 }, 201, {
    "Set-Cookie": sessionCookie(session.token),
  });
};

export const ALL: APIRoute = () => methodNotAllowed("POST");
