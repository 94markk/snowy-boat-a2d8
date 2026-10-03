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

## Third pass: menu and bar on every page, instant switching, iPhone app

* **Drawer and bottom bar on every page**: on the home and info pages every link in the menu
  turned purple, the brand title could vanish, and on the shop and product pages the wallet card
  and menu rows were taller: page stylesheets reached the chrome with element selectors
  (`body.delicat-native-document a`, `section`, the page's box-sizing reset). The native
  document rule is scoped to its content root and a guard sheet, last in the chrome bundle
  (`src/styles/chrome-guard.css`), restates every colour, box and icon size of the drawer, the
  bar and the header brand at a specificity no page rule reaches. Verified identical on home,
  shop, product and account.
* **Bottom bar**: the page no longer shows through the gap under the floating pill (a shelf fades
  the content into the page background), the lit tab is re-derived from the address on every
  navigation, and the bar stands above the home indicator in the installed app.
* **Instant switching**: a tap opens the next screen immediately with what the app already knows
  (the card's image, title and price for a product; a grid or rails for the shop and home) and
  fills it in when the document arrives; stylesheets a page needs load in parallel instead of one
  round trip each; the bar's destinations are prefetched while the page is idle; visited pages
  stay in memory for five minutes so Back is instant; two forced layouts at start-up (header and
  reveal set-up) are gone; the crossfade is skipped on phones that report 3GB or less.
* **Fewer bytes**: WooCommerce's catalog skin, theme-compat sheet and generic front-end script
  (16KB compressed, five requests) leave the native documents; the route and polish sheets are
  preloaded from the top of the head and precached by the service worker.
* **Server**: a signed-in customer used to rebuild the homepage on every view (112ms, 151
  queries here) because the fragment cache was reserved for guests; the body fragment is now
  shared under a key that includes the signed-in state, roles, currency and country, and a
  render is only stored when it carries no nonce, name or wallet figure.
* **iPhone app**: launch screens are generated for every iPhone and iPad size from the site icon
  (`uploads/delicat-builder-v9/splash`), the manifest carries an id, display override, split icon
  purposes and shortcuts, the status bar keeps its system look above the white header, the
  installed app gets a back control on every screen but the first, refreshes the session when it
  returns to the front, and an iPhone is offered the "Add to Home Screen" steps instead of the
  Android package, with a one-time invitation on the second visit.
* **Express checkout sheet**: the "Vos coordonnées" block showed the e-mail twice and no way to
  reach the customer. Each contact detail is shown once (a duplicate field from an extension is
  posted the same value), and the WhatsApp number is a required field right after the e-mail,
  with the phone keyboard, on the sheet and on the checkout page; it is saved as the order's
  billing phone. The `delicat_builder_v9_whatsapp_required` filter can relax it.
* **Low-end phones**: decorations that cost paint time (card and panel shadows, image
  transitions) are dropped on devices that report four cores or 4GB or less; scrolling was
  measured smooth (no frame over 32ms on home and shop at 4× CPU throttling).

## Fourth pass: the review sheet everywhere, moving testimonials, Safe Mode

* **"Laisser un avis" did nothing** on the product page and under the testimonials whenever the
  app had been opened on a page that ships no review config (the shop, the cart, a category):
  the review module's gate was only installed when the landing page printed that config, so
  every product opened afterwards by soft navigation had a dead button. The gate is now installed
  on every page, a small review config travels in the engine config (`config.reviews`: where to
  post, signed-in state, account URL), the sheet opens through the module's API on the tap that
  loaded it instead of a replayed click, a chunk that failed to load is tried again on the next
  tap, and a sheet removed behind the module's back no longer blocks every later opening. A
  signed-in customer whose prompt request fails sees "Réessayer", not a login link.
* **The testimonial rows stood still**: the two-row marquee is released by JavaScript (the rows
  are printed once and cloned when they come near the screen, then `is-loop-ready` starts the
  CSS loop); that code lived in the legacy core script the engine absorbs and had not been
  ported. It is now an engine module (`src/engine/modules/marquee.js`), mounted on every page
  that carries a marquee and again after each soft navigation. The loop runs on phones as well
  (RC90 had replaced it with manual snap-scrolling rows below 640px); it pauses while a row is
  off screen or held under a finger, falls back to manual rows under reduced motion, and the
  `delicat_builder_v9_testimonials_mobile_marquee` filter (false) restores the manual rows on
  phones. The legacy component bundle (`all-components.min.css`, served by the legacy delivery)
  is regenerated by the build from the component sources, so both deliveries agree.
* **Safe Mode tripped by foreign code**: a fatal raised outside the plugin that merely named a
  Builder class (`Class "Delicat_Builder_V9_…" not found` from a theme snippet, another plugin
  or a CLI script) switched the whole storefront to plain WordPress, which cannot cure that
  failure. Only a fatal in a Builder file, or one raised while running Builder code, trips it now.
* Version 9.3.1: the compiled page sheets and every cached fragment refresh on update.

## Fifth pass: iPhone fixes, the floating bar, slow phones

* **Hero search row broken on the live site** (a "Reche…" label inside the row, the arrow button
  pushed to a second line): the form prints a screen-reader label and relied on the theme or on
  WooCommerce's stylesheet for the `.screen-reader-text` rule that hides it; with WooCommerce's
  sheet trimmed on native documents, a theme without the rule showed the label. The chrome bundle
  now carries the rule on every page (legacy bundle included).
* **Floating bar**: the shelf added in the third pass (the page background fading up around the
  pill) read as an edge-to-edge strip with a line above it on the phone. The pill floats free
  again with its own shadow, kept on low-power phones too, and the colour pins that turned every
  idle tab grey are gone, so the theme's or Design Studio's bar colours apply.
* **Install invitation**: iPhone and iPad only (Safari never offers to install anything by
  itself; Android has the APK link and Chrome's own prompt), and it drops in from the top of the
  screen where a system banner would, above the WordPress bar for signed-in staff.
* **Speed, the real cause**: ten `[class*="…"]` selectors (launcher copy, chat widgets, rails,
  touch targets, the native-header guard) made the browser restyle the whole page whenever a class
  changed on `<html>`, `<body>` or `<main>`: about 15ms at desktop speed, 90ms on a slow phone,
  several times per navigation and at every tab update or section reveal. All ten are rewritten
  with named classes; a class change on a root element now costs nothing unless a rule uses that
  class. The per-navigation root changes were reworked on the same principle: the dimmed page no
  longer flips inherited `pointer-events`/`cursor` (a tap guard in the navigation module refuses
  taps on the outgoing page instead), the motion variables are written to the root once per
  profile, the product dock's page class comes with the document, and the bottom band is a
  non-inherited custom property (`@property`), so showing the dock restyles the body and the
  layout, not every element.
* **Skeleton on slow phones**: the placeholder is shown only when the document takes longer than
  160ms to arrive (260ms on low-power phones) and never on the slowest phones, so a fast response
  is painted once instead of twice; its shimmer is static on low-power phones. View transitions
  are skipped on low-power phones. Phones that report neither memory nor cores (Safari) are
  measured once per session with a short loop at idle and classed accordingly.
* **First product tap**: the product route's WordPress and WooCommerce scripts (jQuery, wp-util,
  the variation form …) are recorded with their exact URLs when a product page prints them and
  precached by the service worker; the comment-reply script is dropped on native product pages.
* **One style pass per page change**: the incoming route stylesheet is fetched ahead with a media
  query that never matches and switched on in the same task as the content swap (it used to
  restyle the outgoing page as soon as it landed, then the new page again); the outgoing sheet
  is switched off at the same moment, so the old page never loses its styles while still shown.
  The theme's body class is printed with the document, so the boot no longer adds it.
* **Measured** (local site, Chromium with CPU throttling and slow 4G, signed in; before → after):
  at 6× CPU a product opened from a home card 1688 → 1145ms, Back 892 → 436ms, a product already
  seen 823 → 675ms, the bar's tabs 747–1012 → 175–487ms; at 4× CPU the cold home DOMContentLoaded
  1623 → 1450ms, the card tap 1512 → 968ms, the bar's tabs 620–740 → 196–598ms. Any class change
  on a root element now costs 0ms where it cost 15ms (desktop speed) before.

## Build

```
cd delicat-builder-v9
npm install
npm run build        # → assets/dist/*, manifest.json, asset-versions.php, integrity-manifest.json
npm run build:watch
npm run build:dev    # unminified engine with readable names, for profiling
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
