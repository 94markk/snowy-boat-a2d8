> Historical notes for pro.30. See PERFORMANCE-AUDIT-PRO31.md for the current release, live measurements, validation limits and installation steps.

# Storefront latency work — 9.2.0-pro.30

What changed, why, and how to confirm it on the live site.

## The finding that matters

`Security::public_cache_allowed()` is the single gate deciding whether the
homepage, product archives, product pages, native pages and the asset layer are
served from LiteSpeed's shared cache or rebuilt in PHP. Two clauses in it were
switching the cache off for most real traffic:

1. **Any cookie whose name contained `woocommerce` counted as private.**
   WooCommerce sets one as soon as a shopper touches a product, so from that
   moment every homepage, archive and product view was a full dynamic render for
   the rest of their session. The same substring test also caught unrelated
   analytics cookies through `currency`, `wallet` and `tera`.

2. **Any query string bypassed the cache** (`! empty( $_GET )`). A query string
   does not make a document private — the cache key already includes it, so
   `?orderby=price` is simply a different cacheable page. In practice this meant
   every ad-tagged landing (`utm_*`, `fbclid`, `gclid`, `msclkid`) and every
   archive page-2 or sort change was rebuilt from scratch.

Both are why navigating from the homepage into a product or archive page did not
feel instant: those documents were not being cached, so prerendering and
prefetching had nothing cheap to fetch.

The correction layer for cached pages already existed and shipped — the RC32
script in `bottom-nav.php` reads the true cart count from the cookie and fixes
the badge, and `session.js` syncs whenever a Woo cookie is present. The cache
path had been designed for; the gate just never allowed it.

## The second finding

`Performance::critical_css()` appends whole stylesheets and skips any that
would cross the inline byte budget. A budget slightly too small therefore does
not trim the tail — it drops an entire route stylesheet, silently.

At the 7000-byte floor the archive got exactly that. `shell` minifies to 5309
bytes and `woo-archive` to 2246, so the pair overflowed by **555 bytes** and
the archive sheet was dropped on every shop and category page. That sheet
carries `display:grid` and `grid-template-columns`, so archives painted in
WooCommerce's float layout and only snapped into a grid once `woo-ui.css` had
downloaded and parsed. Product pages lost `purchase-single` the same way, 344
bytes over.

That is the most direct explanation of "archive pages are slow and the layout
is wrong": the page had no archive layout until a stylesheet arrived over the
network. The floor is now 8000, and `tests/test-critical-budget.php` fails if
any route's sheets stop fitting.

Worth knowing: the old floor was reachable from **Performance → critical CSS
budget** in the admin, so this was reproducible by hand. The input's minimum is
now aligned with the runtime clamp.

## What was done

| Phase | Change |
| --- | --- |
| 1 | Guest cart state kept out of shared documents (`Security::shared_document()`), then the cookie and query-string clauses narrowed to what genuinely personalises a page. Gate memoised. |
| 2 | `publicProductWarm` now defers to the cache gate, so product documents are prefetched for shoppers who already have a cart — the iOS Safari path in particular. |
| 3 | Archive mobile breakpoint aligned to the grid system (640px, was 680px). `Archive_Builder::current_target()`/`active_config()` memoised — they were recomputed ~11× per archive request, each with a term lookup. |
| 4 | `Compiler::maybe_recompile_for_version()` no longer leaves Builder pages permanently on the 177 KB component fallback after a partial failure. `Drawer::assets()` moved to priority 9 so its duplicate-CSS guard is order-independent. |
| 5 | `woocommerce_items_in_cart` declared through `litespeed_vary_cookies`, so the cart-fragments decision is part of the cache key instead of being wrong for half of shoppers. |
| 6 | Archives were shipping **no critical CSS at all** — the 7000-byte budget dropped the whole `woo-archive` sheet. Floor raised to 8000. The image-ratio setting was being overridden and now works; `sizes` follows the configured grid. |

Full reasoning is in the commit messages; each phase is one commit.

## Deploying

1. Upload the plugin directory as usual.
2. **Visit `wp-admin` once as an administrator.** This triggers
   `Compiler::maybe_recompile_for_version()`, which rebuilds each Builder page's
   compiled CSS bundle. Until it runs, Builder pages serve the 177 KB
   `all-components.min.css` fallback instead of the few component sheets they
   need.
3. **Purge LiteSpeed** (LiteSpeed Cache → Toolbox → Purge All). The cached copies
   from before this change contain server-rendered cart counts.

## One LiteSpeed setting worth changing

Campaign parameters are now cacheable rather than a bypass, which is the point
— but the cache key still includes them, so `?utm_source=fb`, `?utm_source=ig`
and `?fbclid=<anything>` each get their own entry for what is byte-for-byte the
same page. That is a poor hit rate, and with a unique `fbclid` per click it is
effectively one entry per visitor.

LiteSpeed can drop them from the cache key: **LiteSpeed Cache → Cache →
Excludes → Drop Query String**, one per line:

```
utm_source
utm_medium
utm_campaign
utm_term
utm_content
utm_id
gclid
gbraid
wbraid
gad_source
fbclid
msclkid
ttclid
twclid
igshid
mc_cid
mc_eid
_gl
```

Do **not** add `paged`, `orderby`, `order`, `min_price`, `max_price` or any
`filter_*` key — those genuinely change the page, and dropping them would serve
page 1 to someone asking for page 2.

With this set, every ad click on a given URL is one shared cache entry instead
of one per click.

## Confirming it worked

The plugin states its own cache decision in a response header, which is the
fastest way to check. From any machine:

```sh
# A product page. Expect: X-Delicat-V9-Product-Cache: public
curl -sSI https://delicastoreha.com/produit/<slug>/ | grep -i -E 'x-delicat|x-litespeed|cache-control'

# The same page as an engaged shopper. This is the case that used to bypass;
# expect public now, not bypass.
curl -sSI https://delicastoreha.com/produit/<slug>/ \
  --cookie 'woocommerce_items_in_cart=2' | grep -i -E 'x-delicat|x-litespeed'

# An ad-tagged landing. Expect public; this used to bypass on the query string.
curl -sSI 'https://delicastoreha.com/?fbclid=test123' | grep -i -E 'x-delicat|x-litespeed'

# An archive, page 2. Expect public.
curl -sSI 'https://delicastoreha.com/boutique/?paged=2' | grep -i -E 'x-delicat-v9-archive-cache|x-litespeed'
```

`X-LiteSpeed-Cache: hit` on the second identical request is the thing to watch —
that is the request that no longer costs PHP and MySQL time.

These must still report **bypass or private**, and it is worth checking that they
do:

```sh
curl -sSI https://delicastoreha.com/panier/                        # cart
curl -sSI https://delicastoreha.com/mon-compte/                    # account
curl -sSI 'https://delicastoreha.com/?add-to-cart=123'             # an action
```

For before/after timings, `time_starttransfer` is the number this work moves:

```sh
curl -sS -o /dev/null -w 'ttfb=%{time_starttransfer}s total=%{time_total}s\n' \
  https://delicastoreha.com/produit/<slug>/
```

Run it twice — the second run should be the cached one.

## What I could not check

**No live measurement was possible from the environment this work was done in.**
Outbound access to `delicastoreha.com` is denied by the sandbox network policy
(the proxy answers 403 to CONNECT), so every finding here comes from reading the
code, and the expected effect is reasoned rather than measured. The commands
above are how to get the real numbers.

The cache-gate contract is covered by `tests/test-cache-gate.php` (31
assertions, `php tests/test-cache-gate.php`), which pins both halves: what must
become cacheable, and what must stay private.

## Working on assets after this

Browsers never load `assets/css/foo.css`. `Audit_Fixes::asset_url()` rewrites
every plugin asset URL to the hashed twin named in `asset-versions.php`, so
**editing a plain stylesheet changes nothing on the storefront until the pipeline
is rebuilt** — and `integrity-manifest.json`, which `Production::verify()` checks
at runtime, would then report the file as modified.

```sh
php tools/rebuild-assets.php           # report what would change
php tools/rebuild-assets.php --write   # apply
```

It maintains the sha256/12 map, the hashed copies, the three-generation history
(older hashed files are kept on purpose so a page already in flight can still
fetch what it referenced) and the integrity manifest. Run it after **any** change
to a `.css`, `.js` or `.php` file in the plugin.

## Looked at, deliberately not changed

**The always-on PHP kernel.** ~21 classes and 431 KB of source are parsed and
booted on every storefront request, which looks alarming and mostly is not: with
OPcache enabled — standard on this host — including an already-compiled file is
a hash lookup, so the real per-request cost is `boot()` execution and roughly
200 `add_action()` registrations. That is single-digit milliseconds against the
300–800 ms a dynamic render costs, so it is noise next to the cache work above
and not worth the regression risk of re-scoping modules.

`legal.php` is the one clear candidate if this is ever revisited: 23.8 KB
always-on whose `boot()` registers three hooks, two of which (`admin_post_*`,
`delicat_builder_v9_version_purged`) cannot fire on a guest page view. The
bootstrap already has an idiomatic lazy-bridge pattern for this — see the
`woocommerce_order_status_completed` closure that loads the Reviews module on
demand. It was left alone because `Native_Pages` calls
`class_exists( 'Delicat_Builder_V9_Legal', false )` directly at lines 207 and
220, so a lazy bridge has to cover that call site too or the terms and privacy
pages silently lose their content. Small win, real risk.

**A duplicated wallet lookup.** `Session::payload()` calls TeraWallet's
`get_wallet_balance()` twice for a signed-in shopper — once for the formatted
text and once for the numeric value (`includes/class-delicat-builder-session.php`).
This runs on the hottest uncached endpoint, once per navigation. The numeric
value can be formatted with `wc_price()` instead, and the code already does
exactly that as a fallback, but the comment says TeraWallet's own formatting is
preferred, so changing it risks altering how balances display for a modest gain
on signed-in requests only.
