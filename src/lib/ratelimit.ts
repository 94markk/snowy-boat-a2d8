/**
 * Best-effort rate limiting.
 *
 * If a Cloudflare rate-limiting binding is configured it is used, since that is
 * consistent across the whole edge. Otherwise this falls back to a per-isolate
 * sliding window: enough to blunt one client hammering an endpoint, not enough
 * to stop a distributed flood. The durable protection for that is a Cloudflare
 * WAF rate-limiting rule — see DEPLOYMENT.md.
 */

const WINDOWS: Record<string, { limit: number; windowMs: number }> = {
  default: { limit: 30, windowMs: 60_000 },
  login: { limit: 10, windowMs: 60_000 },
  // Deliberately loose: Haitian mobile networks put many customers behind one
  // carrier-grade NAT address, so a tight per-IP cap locks out real signups.
  register: { limit: 20, windowMs: 60 * 60_000 },
  topup: { limit: 10, windowMs: 60_000 },
  order: { limit: 20, windowMs: 60_000 },
};

const hits = new Map<string, number[]>();

function localAllow(key: string, bucket: string): boolean {
  const { limit, windowMs } = WINDOWS[bucket] ?? WINDOWS.default!;
  const current = Date.now();
  const recent = (hits.get(key) ?? []).filter((t) => current - t < windowMs);
  recent.push(current);
  hits.set(key, recent);

  // Keep the map from growing without bound in a long-lived isolate.
  if (hits.size > 5_000) {
    for (const [existing, times] of hits) {
      if (times.every((t) => current - t >= windowMs)) hits.delete(existing);
    }
  }

  return recent.length <= limit;
}

export async function allowRequest(
  request: Request,
  env: Env | undefined,
  bucket = "default",
): Promise<boolean> {
  const ip = request.headers.get("CF-Connecting-IP") ?? "unknown";
  const key = `${bucket}:${ip}`;

  const limiter = env?.CHECKOUT_LIMITER;
  if (limiter) {
    try {
      const { success } = await limiter.limit({ key });
      return success;
    } catch {
      // Binding misconfigured — fall through rather than failing the request.
    }
  }
  return localAllow(key, bucket);
}
