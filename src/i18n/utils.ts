import { CURRENCY } from "../config";
import { DEFAULT_LOCALE, LOCALES, ui, type Locale, type UIKey } from "./ui";

/** Narrow an arbitrary string to a supported locale. */
export function isLocale(value: string | undefined): value is Locale {
  return !!value && (LOCALES as readonly string[]).includes(value);
}

/** Read the locale out of a URL such as /en/shop/coffee. */
export function getLocaleFromUrl(url: URL): Locale {
  const segment = url.pathname.split("/").filter(Boolean)[0];
  return isLocale(segment) ? segment : DEFAULT_LOCALE;
}

/**
 * Returns a `t(key)` function for the locale, falling back to the default
 * locale so a missing translation shows French rather than a bare key.
 */
export function useTranslations(locale: Locale) {
  return function t(key: UIKey, vars?: Record<string, string | number>): string {
    const table = ui[locale] as Record<string, string>;
    const fallback = ui[DEFAULT_LOCALE] as Record<string, string>;
    let out = table[key] ?? fallback[key] ?? key;
    if (vars) {
      for (const [name, value] of Object.entries(vars)) {
        out = out.replaceAll(`{${name}}`, String(value));
      }
    }
    return out;
  };
}

/**
 * Build an in-site path for a locale. Always returns a leading slash and never
 * a trailing one (except for the locale root), so canonical URLs stay stable.
 */
export function localePath(locale: Locale, path = ""): string {
  const clean = path.replace(/^\/+|\/+$/g, "");
  return clean ? `/${locale}/${clean}` : `/${locale}`;
}

/**
 * Given the current URL, return the equivalent path in another locale.
 * Used by the language switcher so it keeps the visitor on the same page.
 */
export function swapLocale(url: URL, target: Locale): string {
  const segments = url.pathname.split("/").filter(Boolean);
  if (isLocale(segments[0])) segments[0] = target;
  else segments.unshift(target);
  return `/${segments.join("/")}`;
}

const formatters = new Map<string, Intl.NumberFormat>();

/**
 * Format a price held in centimes as the site shows it: "G144".
 *
 * Intl has no HTG symbol worth using, so the amount is grouped by locale and
 * the "G" prefix from the current site is applied by hand.
 */
export function formatMoney(centimes: number, locale: Locale): string {
  const tag = locale === "en" ? "en-US" : "fr-HT";
  let formatter = formatters.get(tag);
  if (!formatter) {
    formatter = new Intl.NumberFormat(tag, {
      minimumFractionDigits: CURRENCY.fractionDigits,
      maximumFractionDigits: CURRENCY.fractionDigits,
    });
    formatters.set(tag, formatter);
  }
  const sign = centimes < 0 ? "-" : "";
  return `${sign}${CURRENCY.symbol}${formatter.format(Math.abs(centimes) / CURRENCY.minorUnits)}`;
}

/** Formats a timestamp (seconds since epoch) for display. */
export function formatDate(seconds: number, locale: Locale): string {
  const tag = locale === "en" ? "en-US" : "fr-FR";
  return new Intl.DateTimeFormat(tag, { dateStyle: "medium", timeStyle: "short" }).format(
    new Date(seconds * 1000),
  );
}
