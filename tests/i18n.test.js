import assert from "node:assert/strict";
import test from "node:test";
import { DEFAULT_LOCALE, LOCALES, LOCALE_NAMES, LOCALE_TAGS, ui } from "../src/i18n/ui.ts";
import { localePath, swapLocale, useTranslations } from "../src/i18n/utils.ts";

test("every locale defines exactly the same keys", () => {
  const reference = Object.keys(ui[DEFAULT_LOCALE]).sort();

  for (const locale of LOCALES) {
    const keys = Object.keys(ui[locale]).sort();

    const missing = reference.filter((key) => !keys.includes(key));
    const extra = keys.filter((key) => !reference.includes(key));

    assert.deepEqual(missing, [], `${locale} is missing keys`);
    assert.deepEqual(extra, [], `${locale} has keys no other locale has`);
  }
});

test("no translation is left empty", () => {
  for (const locale of LOCALES) {
    for (const [key, value] of Object.entries(ui[locale])) {
      assert.ok(String(value).trim().length > 0, `${locale}.${key} is empty`);
    }
  }
});

test("every locale has a display name and a BCP-47 tag", () => {
  for (const locale of LOCALES) {
    assert.ok(LOCALE_NAMES[locale], `${locale} has no display name`);
    assert.ok(LOCALE_TAGS[locale], `${locale} has no language tag`);
  }
});

test("a missing key falls back to the default locale rather than showing the key", () => {
  const t = useTranslations("en");
  assert.equal(t("nav.shop"), ui.en["nav.shop"]);
});

test("localePath builds clean, prefixed paths", () => {
  assert.equal(localePath("fr"), "/fr");
  assert.equal(localePath("en", "shop"), "/en/shop");
  assert.equal(localePath("ht", "/shop/free-fire-latam/"), "/ht/shop/free-fire-latam");
});

test("swapLocale keeps the visitor on the same page", () => {
  assert.equal(swapLocale(new URL("https://x.test/fr/shop/roblox"), "ht"), "/ht/shop/roblox");
  assert.equal(swapLocale(new URL("https://x.test/en/wallet"), "fr"), "/fr/wallet");
  // A path with no locale prefix gains one rather than losing its first segment.
  assert.equal(swapLocale(new URL("https://x.test/shop"), "en"), "/en/shop");
});
