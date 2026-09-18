# LiteSpeed Cache settings for Delicat Builder V9 Pro

Written against 9.2.0-pro.44. Several popular LiteSpeed options fight this plugin
directly; two of them can blank the storefront. Change one setting at a time,
purge, and re-check.

---

## Already handled in code — do not duplicate

| Concern | Where it is handled |
|---|---|
| Cart / checkout / account / wallet never cached | `Security::response_cache_tier()` returns `sensitive` and emits no-store |
| Empty vs non-empty cart kept apart | `woocommerce_items_in_cart` registered via `litespeed_vary_cookies` |
| One cached copy per currency | `dmc_currency` registered the same way (pro.44) |
| LCP images never lazy-loaded | `litespeed_media_lazy_img_excludes` |
| Plugin inline config not delayed or optimised | `litespeed_optm_js_defer_exc`, `litespeed_optm_gm_js_exc` |
| Ad and catalog URLs stay cacheable | `Security::query_is_cache_safe()` allowlist |

Adding these to LiteSpeed's own exclusion boxes is harmless but redundant.

---

## Turn these ON

**Object cache — the single biggest win.**
`Cache → Object` → Redis, then confirm "Connection Test" passes. Without it every
query-cache and fragment entry lands in `wp_options` as a transient, and expired
rows are swept on only ~5% of admin requests. The plugin is already
`wp_cache_*`-aware and picks Redis up with no further configuration.

**Browser cache.** `Cache → Browser` → on, TTL 1 year. Safe because assets carry a
content-hash `?ver=`: the URL changes whenever the bytes change, so a long TTL
cannot serve a stale file.

**UCSS (Unique CSS).** `Page Optimization → CSS → Generate UCSS`. The plugin ships
a `litespeed_ucss_whitelist` covering all of its class prefixes (`.dbv9-`,
`.dsb8-`, `.dmc-`, …), so it was built expecting this. Check a product page and
the homepage visually afterwards.

**Image optimization + WebP.** On a product catalogue this is almost certainly a
larger saving than anything left in the CSS or JS.

---

## Leave these OFF — they break this storefront

These are not cautious guesses. Each one is recorded in this codebase.

**Load CSS Asynchronously.** pro.1 did exactly this. LiteSpeed combines the site's
stylesheets and does not carry the inline `onload` through, so the combined sheet
loaded as print-only and *the storefront rendered with no CSS at all*. See the
header comment in `pro/class-dbp-assets.php`: "a technique that can blank the
store is a defect, not a setting."

**CSS / JS Combine.** Same failure mode, and pointless here anyway: the plugin
adds its own assets to `litespeed_optimize_css_excludes` and
`litespeed_optimize_js_excludes`, so they are not combined regardless.

**JS Defer / Delay.** `DBP_Kernel::defaults()` ships `defer_js => 0` deliberately:
WooCommerce and V9 print inline `jQuery(...)` blocks that run before a deferred
file and throw.

**Guest Mode / Guest Optimization.** Serves a generic first view and swaps it on
the client. That collides with the session and state engine. Staging only.

---

## Multi-currency

pro.44 caches a non-default currency instead of bypassing, but only when the
browser sent `dmc_currency`, it is valid and non-default, and it matches the
currency actually rendered. A currency resolved from the WooCommerce session or
from geolocation is invisible to the cache, so those still bypass — that is
deliberate, and it is what stops one currency's prices being served under
another's key.

If you ever see wrong prices, switch the whole path off without editing code:

```php
add_filter( 'delicat_builder_v9_currency_cache_vary', '__return_false' );
```

## Adding your own cache-safe query parameters

Only for parameters that change the URL and nothing in the document. `?lang=` and
`?attribute_pa_*` change what is rendered — they need a variant, not an allowlist
entry, so do not add them.

```php
add_filter( 'delicat_builder_v9_cache_safe_query_keys', function ( $keys ) {
    $keys[] = 'my_neutral_param';
    return $keys;
} );
```

---

## Order of work

1. Purge LiteSpeed (Toolbox → Purge All) and Cloudflare (Purge Everything).
2. Open any wp-admin page once — that rebuilds the compiled stylesheets after an
   update. Land on Dashboard, not Plugins.
3. Run `delicat-verify-pro43.sh` and keep the output as a baseline.
4. Change one setting, purge, re-run, compare.

If the probe reports no `x-litespeed-cache` header at all, page caching is not
running for the site. Fix that first; nothing else on this page matters until it is.
