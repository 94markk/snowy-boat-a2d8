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
  again with its own shadow, kept on low-power phones too, the colour pins that turned every
  idle tab grey are gone, and every tab wears the store's purple as on the owner's reference
  screen (the current tab keeps its bolder label).
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
* **Sticky header lost, and under the status bar in the installed app**: the Pro critical sheet
  set `overflow-x: hidden` on `<body>` while the root already clips sideways overflow; that made
  the body a scroll container, and a sticky header sticks to its nearest scroll container, so the
  header scrolled away on every page (in the installed app it slid under the clock and the
  island, unreadable and unreachable). The body now uses `overflow-x: clip`, which clips without
  creating a scroll container, so the header sticks again; in the installed app it carries the
  status-bar inset (`env(safe-area-inset-top)`), so its row sits below the clock and its
  background fills the strip like a native app's bar.
* **Measured** (local site, Chromium with CPU throttling and slow 4G, signed in; before → after):
  at 6× CPU a product opened from a home card 1688 → 1145ms, Back 892 → 436ms, a product already
  seen 823 → 675ms, the bar's tabs 747–1012 → 175–487ms; at 4× CPU the cold home DOMContentLoaded
  1623 → 1450ms, the card tap 1512 → 968ms, the bar's tabs 620–740 → 196–598ms. Any class change
  on a root element now costs 0ms where it cost 15ms (desktop speed) before.

## Sixth pass: the bar on every page, the installed app, the banner rail

* **The bottom bar vanished after each update, mostly in the installed app.** A document rendered
  before an update still names the previous build's hashed bundles (`chrome.<hash>.css`, the
  engine), and those files leave with the old plugin folder. A copy kept for a few minutes by Safari,
  by the app's service worker (documents are served stale-while-revalidate for up to ten minutes) or
  by Cloudflare came up with its inline critical CSS only: a styled header and chat launcher, no bar
  (its styles live in the chrome bundle), no drawer styles, no engine. The web server hands a missing
  file to WordPress, and `Delicat_Builder_V9_Engine::rescue_missing_asset()` now answers a request
  for a missing hashed bundle with the current build of the same bundle (only names listed in the
  build manifest; chunks and anything else stay 404). A stale page degrades to "current stylesheet
  and engine" instead of "no chrome". Probe: `stale-asset.mjs` (four old names rescued, five
  refused).
* **Bar on the cart page.** `Delicat_Builder_V9_Bottom_Nav::expected()` no longer excludes the cart;
  checkout and the thank-you page keep the dock. The checkout dock's hide rule is scoped to checkout
  (`body.dpn-checkout.dpn-checkout-dock-ready`). The cart page clears the bar (its padding comes from
  the `--dbv9-nav-band` rules) and the "Panier" tab lights up there.
* **Stale overlay classes.** The classes that hide the bar while something is open (`dnp-express-open`,
  `dpn-wallet-modal-open`, `dpn-checkout-dock-ready`, `dlx-open`, `dsb-menu-open`) survive swaps on
  purpose; the bottom-nav module re-checks each against its element 400 ms after a navigation, a
  `pageshow` and a return to the foreground, and clears the ones with nothing open.
* **Keyboard.** iOS lays fixed elements out under the keyboard; left as the system does (native apps
  hide their tab bar behind the keyboard as well).
* **Installed app, signed-in staff.** The WordPress toolbar sat under the status bar and pushed the
  header down. In standalone the toolbar is hidden and the html offset reset (`pwa-runtime.css`), the
  `delicat-standalone` class is set by an inline check in the head before the first paint, and the
  server stops printing the toolbar once the app has identified itself with its `dbv9_app` cookie
  (set by the engine in standalone; the launch address `?utm_source=pwa` counts too).
* **Install invitation.** iPhone and iPad only, as before, but now on every visit, about 2.5 s after
  the page is idle, and it stays until closed or opened; closing keeps it quiet for that visit only
  (`sessionStorage`). Never inside the installed app (standalone), where nothing about installing is
  shown. The second-visit threshold, the 18 s auto-dismiss and the monthly quiet period are gone.
* **Banner section → card rail.** The banner is a swipeable rail: a heading whose bold words are
  marked with `*stars*`, cards with a title, a line of text, an optional picture and an optional
  pill button, the next card peeking on phones (`min(82vw, 340px)`, edge to edge), three to a row
  on wide screens, browser snap scrolling, position dots moved by a 1 KB engine module
  (`src/engine/modules/banner.js`). The original fields are card 1, so existing banners keep their
  content; more cards are typed one per line as `Titre|Texte|Bouton|URL|#couleur|image id`; three
  looks (light, dark, tinted by each card's colour) and dark mode. Type-layer rules that made every
  `h2` bold and every link in `main` coloured are overridden with the needed specificity.

Verification (local site, iPhone 13 emulation unless noted): `stale-asset.mjs` 4 rescued / 5 refused;
`bar-probe.mjs` bar present at the top and scrolled on home, product, cart and shop, browser and app
emulation; `cart-probe.mjs` cart with an item, tab lit, bar unobstructed, no bar on checkout;
`app-admin.mjs` signed-in staff in standalone: no toolbar markup, html offset 0, header at 0;
`pass4-visual.mjs` invitation at the top on iPhone, none on Android; `banner-probe.mjs` five cards,
peek 44 px, dots following the scroll and a dot tap; `flows.mjs` 17/17 steps, no console errors.

## Seventh pass: pinned header on iPhone, pull-to-refresh, design audit

* **Header not pinned in the installed app on iPhone.** The root and `<body>` both clipped sideways
  overflow with `overflow-x: clip`. Chromium keeps a sticky header pinned under that, but WebKit does
  not reliably: a sticky element inside an `overflow-x: clip` box jitters or lets go (WebKit bug
  247130), and in Chromium the root `clip` also offset the header in captures. Now only the root
  clips (`html{overflow-x:hidden}`, which propagates to the viewport) and `<body>` is `overflow:
  visible` everywhere (theme-system, storefront-polish, native-product, the pro critical sheet), the
  only arrangement every engine keeps a sticky header pinned under. `sticky-visual.mjs` captures the
  scrolled header at the top on home and product; the `body{overflow-x:hidden}` variant breaks it.
  As a safety net the header module watches the first scroll past the header: if the header has
  left the viewport anyway, it is pinned as a fixed bar and the page padded by its height
  (`html.dsb8-header-pinned`), so no browser quirk can take it away; in the installed app the header
  is sticky whatever the header setting says. `pin-probe.mjs` breaks sticky on purpose and shows the
  pin taking over at the same scroll position. The header's cart badge no longer shows a red "0" on
  an empty cart (its `display: grid` beat the `hidden` attribute).
* **Cart page in the installed app.** The page head's own back chevron is hidden where the header
  already carries one; the page's bottom padding no longer stacks on the bar's band (an empty cart
  showed a screen of blank space); short pages keep a 40vh minimum on phones instead of 64vh; the
  footer mark is pinned to 48px whatever the theme says about images.
* **Pull-to-refresh on every page.** On by default on touch devices (filter
  `delicat_builder_v9_app_polish_pull_refresh`). The gesture is unchanged (drag at the very top,
  threshold 72px, stands down inside rails and overlays); the refresh now goes through the engine:
  the memory cache is dropped, the session (cart, wallet, alerts) refreshed, the page fetched again
  with `cache: 'reload'` and swapped in place, with a check mark when it lands. Session-bound pages
  (cart, checkout, account, wallet) reload fully. `ptr-probe.mjs` drives the gesture with real touch
  events through the DevTools protocol: indicator at 78px, armed, soft refresh, page not reloaded.
* **Design audit (`audit-shots.mjs`: 13 routes × phone, dark, installed app, desktop, plus drawer,
  cart panel, product and express states).** Fixed: the 404 page printed the theme's search form
  (visible "Search…" label, overflowing orange button) and orange buttons with purple text, replaced
  by the store's own search form and brand buttons; the sign-in form's "remember me" checkbox was
  stretched to the row width (checkbox beside its label, button on its own line now); the legal
  pages' summary numbered already numbered headings ("1. 1. …"); the product options' reset link sat
  red under every pack grid before anything was selected (hidden until a selection, French, quiet);
  the cart head's chevron floated between the title and the count (aligned with the title).
* **Performance audit at 4× CPU** (`scrolljank.mjs`, `navtiming.mjs`, `cpuprofile.mjs`, `vitals.mjs`):
  scrolling at a steady 17 ms per frame on home, shop and product; LCP 456–584 ms on a fast link,
  CLS 0.000 on all three; soft navigations 365–975 ms on slow 4G, cold home DCL 1.44 s. Every scroll
  listener is passive and frame-throttled, no `transition: all`, no blur filters on scrolling
  content. The hottest boot function is the once-per-session speed probe (65 ms at 4×, at idle).

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
