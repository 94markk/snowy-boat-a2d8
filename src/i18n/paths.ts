import { LOCALES } from "./ui";

/**
 * Every localized page renders once per locale. Astro calls this from
 * `getStaticPaths`, which is what turns `src/pages/[lang]/…` into
 * `/fr/…`, `/en/…` and `/ht/…` at build time.
 */
export function localeStaticPaths() {
  return LOCALES.map((lang) => ({ params: { lang } }));
}
