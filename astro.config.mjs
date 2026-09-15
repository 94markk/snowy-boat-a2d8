// @ts-check
import { defineConfig } from "astro/config";
import sitemap from "@astrojs/sitemap";
import cloudflare from "@astrojs/cloudflare";

/**
 * Static by default: every page is pre-rendered HTML served from Cloudflare's
 * edge. Only the two routes under `src/pages/api/` opt out with
 * `export const prerender = false`, so the Worker runs for checkout and the
 * Stripe webhook and for nothing else.
 */
export default defineConfig({
  site: "https://delicastoreha.com",
  trailingSlash: "ignore",

  i18n: {
    defaultLocale: "fr",
    locales: ["fr", "en", "ht"],
    routing: {
      // Every locale is prefixed, including the default, so /fr/, /en/ and /ht/
      // are symmetrical and each has one canonical URL.
      prefixDefaultLocale: true,
    },
  },

  experimental: {
    /**
     * Astro emits the Content-Security-Policy and computes a hash for every
     * inline script and style it generates — including the small ones it
     * inlines into the HTML, and including on-demand pages, which a post-build
     * step could not reach. That is what keeps `script-src` free of
     * 'unsafe-inline', the directive that actually matters for XSS.
     */
    csp: {
      algorithm: "SHA-256",
      directives: [
        "default-src 'self'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        // `frame-ancestors` is deliberately absent: browsers ignore it in a
        // meta-delivered policy, and it is already set as a real HTTP header
        // in public/_headers and src/middleware.ts, which is where it counts.
      ],
      scriptDirective: { resources: ["'self'"] },
      styleDirective: { resources: ["'self'"] },
    },
  },

  integrations: [
    sitemap({
      i18n: {
        defaultLocale: "fr",
        locales: { fr: "fr-HT", en: "en", ht: "ht" },
      },
      // Per-visitor pages have nothing to rank.
      filter: (page) =>
        !["/cart", "/wallet", "/account", "/shop/search"].some((path) => page.includes(path)) &&
        !page.endsWith("/404"),
    }),
  ],

  adapter: cloudflare({
    platformProxy: { enabled: true },
    imageService: "compile",
  }),
});
