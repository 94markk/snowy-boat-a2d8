/**
 * Shared request/response helpers for the API routes.
 */

import { SITE } from "../config";

export function json(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      // Nothing an API route returns should ever sit in a shared cache.
      "Cache-Control": "no-store",
      ...headers,
    },
  });
}

export function methodNotAllowed(allow: string): Response {
  return json({ error: "method_not_allowed" }, 405, { Allow: allow });
}

/**
 * Same-origin check for state-changing requests.
 *
 * The session cookie is SameSite=Lax, and these endpoints require a JSON
 * content type — which forces a CORS preflight that no cross-site page can
 * satisfy. This is the third layer, and the one that is explicit.
 */
export function isSameOrigin(request: Request): boolean {
  const origin = request.headers.get("Origin");
  // Same-origin requests may legitimately omit Origin.
  if (!origin) return true;

  try {
    const host = new URL(origin).host;
    if (host === new URL(SITE.url).host) return true;
    // Preview deployments and local development.
    return host.endsWith(".workers.dev") || host.startsWith("localhost:") || host.startsWith("127.0.0.1:");
  } catch {
    return false;
  }
}

/** Parse a JSON body, refusing anything oversized or malformed. */
export async function readJson(
  request: Request,
  maxBytes = 8_000,
): Promise<{ ok: true; value: any } | { ok: false; response: Response }> {
  if (!request.headers.get("Content-Type")?.includes("application/json")) {
    return { ok: false, response: json({ error: "unsupported_media_type" }, 415) };
  }

  const raw = await request.text();
  if (raw.length > maxBytes) {
    return { ok: false, response: json({ error: "payload_too_large" }, 413) };
  }

  try {
    return { ok: true, value: JSON.parse(raw) };
  } catch {
    return { ok: false, response: json({ error: "invalid_json" }, 400) };
  }
}

export function clientIp(request: Request): string | null {
  return request.headers.get("CF-Connecting-IP");
}
