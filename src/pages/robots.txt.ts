import type { APIRoute } from "astro";
import { SITE } from "../config";
import { LOCALES } from "../i18n/ui";

/**
 * Keeps per-visitor pages out of search results and points crawlers at the
 * sitemap the sitemap integration generates.
 */
export const GET: APIRoute = () => {
  const privatePaths = LOCALES.flatMap((locale) => [
    `Disallow: /${locale}/cart`,
    `Disallow: /${locale}/wallet`,
    `Disallow: /${locale}/account`,
    `Disallow: /${locale}/shop/search`,
  ]);

  return new Response(
    ["User-agent: *", "Allow: /", "Disallow: /api/", ...privatePaths, "", `Sitemap: ${SITE.url}/sitemap-index.xml`, ""].join("\n"),
    { headers: { "Content-Type": "text/plain; charset=utf-8" } },
  );
};
