# Delicat Builder V9 — App-Speed Kernel (9.3)

WordPress / WooCommerce storefront plugin for Delicat Top Up. Version 9.3 keeps every
design (carousels, header, drawer, bottom bar, product, cart, checkout, documents) and every
PHP feature, and rebuilds the front-end engine that delivers them.

```
delicat-builder-v9/            the plugin (upload this folder, or the zip tools/zip.mjs builds)
├── delicat-builder-v9.php     bootstrap, profiles, circuit breaker
├── includes/                  PHP modules (one class per feature), templates in templates/
├── pro/                       Pro kernel (routes, security headers, type layer)
├── modules/product-fields/    calculator module
├── assets/                    stylesheets, images, legacy per-file scripts (fallback only)
│   └── dist/                  BUILT: engine.<hash>.js, chunks/, <bundle>.<hash>.css, manifest.json
├── src/engine/                engine source (ES modules) → assets/dist/engine.*.js
│   ├── core/                  runtime (module registry), nav, state, net, overlay, dom
│   ├── modules/               one file per storefront feature
│   └── routes/                lazy chunks (product, purchase, widgets)
├── src/styles/                bundles.mjs (route bundle map), engine.css, fixes.css
└── tools/                     build.mjs, manifest.mjs, zip.mjs
```

## The engine (what changed in 9.3)

* **One runtime.** `assets/dist/engine.<hash>.js` (76 KB, ~25 KB gzipped) replaces the
  fourteen scripts a page used to load (core, carousel, islands, heart, hero-search, theme,
  motion, header, drawer, session, dbp-nav, dbp-state, shell-nav, prefetch, reviews, …).
  Route-specific code is a lazy chunk: product pages, cart/checkout, opt-in widgets, the
  notifications panel (fetched on the first tap of the bell), the legacy shell header.
* **Soft navigation everywhere.** Home, shop, categories, search, products and documents swap
  `<main>` in place with a View Transition (the tapped product image travels into the product
  page). Cart, checkout and account stay full loads. Stylesheets keep their cascade order on
  every swap; the previous route's sheet is switched off, not left applying.
* **One session store.** `?wc-ajax=delicat_session` now also carries the unread count, cart
  total and fresh nonces (cart, add-to-cart, REST, push, wallet, header cart, shell, express,
  reviews, favourites); the store patches every consumer so cached pages act with live tokens.
  The `?dbp_state=1` poller and the bottom-bar inline corrector no longer run.
* **Three stylesheets per page**, built by `tools/build.mjs` from the existing CSS files
  concatenated in the exact order WordPress printed them: `chrome` (theme, header, drawer,
  bottom bar, always-on widgets), one route bundle (`home`, `builder`, `product`, `shop`,
  `page`, `purchase`, `woo`, with `-design` and `-lite` variants) and `polish` (app-polish,
  app-tuning, storefront-polish, dbp-app, dbp-type, fixes). No rule was rewritten; the look
  is unchanged. Inline PHP CSS (critical, swatches, authoritative sizing) prints as before.
* **PHP delivery** lives in `includes/class-delicat-builder-engine.php`: it reads
  `assets/dist/manifest.json`, picks the bundles for the request, dequeues every legacy
  stylesheet whose file is inside them (re-printing their inline additions), absorbs the
  legacy scripts' configuration objects into one inline block, prints the module with
  modulepreload hints, and leaves anything it does not know about untouched. The legacy
  per-file delivery is still complete and is the fallback: `add_filter(
  'delicat_builder_v9_engine_active', '__return_false' )` or `?dbv9_engine=0` as an
  administrator. An HTML comment `<!-- delicat-engine 9.3.0 route=… -->` in `<head>` shows
  what was folded on a page.

Overlapping layers retired when the engine is on: dbp-nav.js, dbp-state.js, shell-nav.js,
prefetch.js, islands.js, TurboNav speculation rules and its view-transition block, the
notifications click-loader, the Front Slim script dequeue passes (already quieted by Pro).
All `.min` twins were removed (52 files, 540 KB); `asset-min-map.php` is empty by design.

## Bug fixes in 9.3

* Fatal `ArgumentCountError` on `[delicat_native_terms]` / `[delicat_native_privacy]`.
* Server engine called an undefined product renderer; HPOS compatibility declared from the
  wrong file at the wrong time (WooCommerce notice on every request).
* Bottom-bar and session cart counts painted WooCommerce's `woocommerce_items_in_cart` flag
  ("1") as a count.
* Calculator: a negative or non-finite total priced the product at 0.00; a cart line whose
  fields failed validation (Store API / app) reached checkout at the catalogue price.
* Compiled page stylesheets could not be rebuilt from ordinary wp-admin screens after an
  update (missing classes); undefined counters in "compile all".
* Checkout dock fallback polled forever; Self-Test expected schema 6 and a class that does
  not exist; PHP requirement shown as 8.5 (the plugin needs 8.3).
* PHP warnings: `theme_default`, `attachment_id`, `$hook`, `effect`, `page_transition`,
  `max_width`; deprecated `wp_targeted_link_rel`.
* Dark mode: dbp-type forced light text tokens on every non-homepage body; calculator inputs
  stayed white; legal pages had no dark tokens; checkout dock total was white on white.
* Currency switch now drops the service-worker document cache before reloading.
* LiteSpeed exclusion lists named globals that do not exist (`DelicaBuilderV9Config`,
  `DelicatShell`).

## Second pass: stability, layout, security, launch

* **Layout**: content root, footer and body each reserved a band for the bottom bar on phones
  (≈320px of empty space at the end of every page); one band remains. WooCommerce's PhotoSwipe
  markup no longer renders as grey boxes under product footers. The shop sort control is sized
  for its 16px text. The native shop and category archive received the `woo` bundle (woo-ui and
  site styles, 62KB) instead of its own 30KB `shop` bundle because both sheets share one handle;
  the engine now tells them apart by the file registered. Fixes live in `src/styles/fixes.css`,
  last in the cascade.
* **Stability**: stable section ids (no UUID churn on save), compiled-CSS signatures by content
  hash, service-worker document cache keyed by currency, engine precache under the URLs pages
  request, third-party script delay off in Safe Mode, guarded cross-module calls, runtime error
  capture in the engine (`delicat:engine:error`), and the Page Builder's archives view no longer
  reads an undefined hook variable.
* **Security**: rate limiter and login throttle keyed on the real client address behind
  Cloudflare (published edge ranges; `delicat_builder_v9_trusted_proxy_ranges` filter for other
  proxies), ten-minute lockout after twenty wrong app tokens, security headers reviewed.
* **Launch**: `Link: rel=preload` for the shell stylesheet and `modulepreload` for the engine
  (103 Early Hints where the edge supports it), one-year immutable caching for `assets/dist`
  via a generated `.htaccess`, a head-only output-buffer pass without LiteSpeed (~7ms per
  page), and WooCommerce's 63KB country/address tables dropped from carts that need no shipping or
  have the calculator off (they are dependencies of `wc-cart`, so that list is rewritten too).

## Build

```
cd delicat-builder-v9
npm install
npm run build        # → assets/dist/*, manifest.json, asset-versions.php, integrity-manifest.json
npm run build:watch
npm run zip          # → ../build/delicat-builder-v9.zip (excludes src/, tools/, node_modules/)
```

`assets/dist/` is committed so the plugin folder is installable without Node. Rebuild after
touching anything under `src/` or any stylesheet listed in `src/styles/bundles.mjs`.

## Verification done for this release

Local WordPress 6.8 + WooCommerce 10.1 (PHP 8.3, SQLite), Playwright on a 390×844 phone and a
1366×900 desktop: home, shop, category, two products, cart, checkout, account, search, 404 and
an info page load with zero console errors and zero PHP notices; interaction flows cover the
carousel, drawer, theme toggle, soft navigation home → product → back (scroll restored), bottom
bar, swatch selection, add to cart, cart, checkout and search suggestions. Every admin screen of
the plugin opens without notices. All 111 PHP files pass `php -l`.
