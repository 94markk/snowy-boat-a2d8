/**
 * Runs before every on-demand route.
 *
 * Its job is to attach the signed-in user to `locals` so pages and endpoints
 * never parse cookies themselves, and to apply the security headers that must
 * be present on dynamic responses (static assets get theirs from `_headers`).
 */

import { defineMiddleware } from "astro:middleware";
import { resolveSession, SESSION_COOKIE, type SessionUser } from "./lib/auth";

declare global {
  namespace App {
    interface Locals {
      user: SessionUser | null;
    }
  }
}

const SECURITY_HEADERS: Record<string, string> = {
  "X-Content-Type-Options": "nosniff",
  "Referrer-Policy": "strict-origin-when-cross-origin",
  "X-Frame-Options": "DENY",
  "Content-Security-Policy": "frame-ancestors 'none'",
  "Permissions-Policy": "geolocation=(), microphone=(), camera=(), payment=(), usb=()",
  "Strict-Transport-Security": "max-age=31536000; includeSubDomains; preload",
};

export const onRequest = defineMiddleware(async (context, next) => {
  const db = context.locals.runtime?.env?.DB as D1Database | undefined;

  // Prerendered routes are built without a real request, so reading cookies
  // there is both meaningless and a warning. Only on-demand routes have a user.
  if (context.isPrerendered) {
    context.locals.user = null;
    return next();
  }

  context.locals.user = db
    ? await resolveSession(db, context.cookies.get(SESSION_COOKIE)?.value)
    : null;

  const response = await next();

  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    if (!response.headers.has(name)) response.headers.set(name, value);
  }

  return response;
});
