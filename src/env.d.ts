/// <reference path="../.astro/types.d.ts" />
/// <reference types="astro/client" />

/**
 * Bindings and secrets.
 *
 * Secrets are set with `wrangler secret put <NAME>` in production and in
 * `.dev.vars` locally. They are read only by routes under `src/pages/api/` and
 * never reach the browser bundle.
 */
/**
 * Pulled in by name rather than through tsconfig's `types`, because loading all
 * of @cloudflare/workers-types globally replaces the DOM's `Element` with
 * HTMLRewriter's — which quietly breaks `append` and friends in client scripts.
 */
type D1Database = import("@cloudflare/workers-types").D1Database;

interface Env {
  /** D1 database binding, configured in wrangler.json. */
  DB: D1Database;
  /**
   * Shared secret the SMS forwarder signs each delivery with (HMAC-SHA256).
   * Anyone holding this can credit wallets, so treat it like a payment key.
   */
  SMS_WEBHOOK_SECRET: string;
  /** Optional Cloudflare rate-limiting binding. */
  CHECKOUT_LIMITER?: { limit(options: { key: string }): Promise<{ success: boolean }> };
}

type Runtime = import("@astrojs/cloudflare").Runtime<Env>;

declare namespace App {
  interface Locals extends Runtime {}
}
