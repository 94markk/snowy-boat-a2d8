import type { APIRoute } from "astro";
import { clearedSessionCookie, destroySession, SESSION_COOKIE } from "../../../lib/auth";
import { isSameOrigin, json, methodNotAllowed } from "../../../lib/http";

export const prerender = false;

export const POST: APIRoute = async ({ request, locals, cookies }) => {
  if (!isSameOrigin(request)) return json({ error: "forbidden_origin" }, 403);

  const db = (locals.runtime?.env as Env | undefined)?.DB;
  if (db) await destroySession(db, cookies.get(SESSION_COOKIE)?.value);

  return json({ ok: true }, 200, { "Set-Cookie": clearedSessionCookie() });
};

export const ALL: APIRoute = () => methodNotAllowed("POST");
