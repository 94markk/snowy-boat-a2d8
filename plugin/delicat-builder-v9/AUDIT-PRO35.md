# Delicat Builder 9.2.0-pro.35 — engine audit and fixes

This release repairs verified source-level defects in the supplied pro.34 ZIP. It is not a certification that every feature is bug-free. No live website deployment or live payment was performed.

## Changes

- Removed the second copy of LiteSpeed exclusions, WooCommerce compatibility registration, plugin activation and plugins_loaded initialization from the bootstrap.
- Added a one-time Core boot guard to prevent repeat initialization within a request.
- Removed the duplicate bootstrap page compilation job. The existing Compiler job retains ownership, its capability check, cooldown and retry behavior; it now supplies the existing admin report.
- Replaced the unguarded express-payment include with the shared guarded loader, outside the activation sandbox.
- Removed the obsolete schema-7 migration that deleted payment locks and completed-intent records. The payment module still uses them. Previously deleted records cannot be recovered by this release.
- Disabled object instantiation when decoding serialized payment records and rejected custom-serialized object records too.
- Fixed repeated checkout DOM writes: class removal, coupon/dock disabled properties and total HTML are updated only when changed. WooCommerce remains the source of checkout state.
- Search index cache now varies by currency, locale and taxable address, and bypasses shared storage for requests refused by the existing public-cache gate. Existing visible search prices are retained. Third-party dynamic-price plugins may require their own cache exclusion.
- Guest favorite eviction now updates the visible heart as well as the cookie. Failed logged-in saves now broadcast the rolled-back state to downstream listeners.
- Restricted the asset rebuild tool to CLI so it cannot be triggered through a web request.
- Rebuilt asset URLs and integrity hashes. Preserved source files, active hashed copies and three generations of cache-compatible assets.

## Validation actually performed

- Full PHP file inventory and literal dependency scan: 110 PHP files; no missing literal plugin asset/template/include paths. This is static inspection, not PHP execution.
- Node syntax checks passed for all 94 original JavaScript files. The added regression test also passes syntax checking.
- Executable Node DOM-contract tests pass: checkout observer settles, dock follows WooCommerce disabled state, guest favorite limit stays consistent, and failed save publishes rollback.
- The checkout test fails against the original pro.34 source and passes against pro.35.
- Package verification checks source/hash equality, retained asset availability, every integrity-manifest entry, unique activation/startup hooks and ZIP readability.

PHP CLI is unavailable in this environment. PHP lint, the three existing PHP suites, WordPress activation, database-backed concurrency, real browsers and device performance were NOT run. The JavaScript tests use a focused DOM stub, not a complete browser. No speed percentage is claimed.

## Module review coverage

Every PHP file is listed below with its method and hook inventory. All modules participated in dependency scanning; this does not mean every branch was exercised or every method manually reviewed. Detailed changes focus on bootstrap, Core, Compiler, search cache, checkout presentation, favorites, payment serialization/migration and asset tooling. Existing header/menu runtime variants and admin variants are intentionally retained: they serve different load profiles. Notification REST namespaces likewise intentionally coexist.

Live integration checks still required: page builder/editor save and compile; homepage/carousels; product archives/search; header/menu on phone and tablet; single product, variations and product fields; cart/coupons/checkout; wallet and insufficient balance; login/logout/Google login; notifications/Web Push/PWA; reviews/live selling; currencies and tax; cache purge/CDN; maintenance/admin and rollback.

## Installation

1. Back up the current site/database and test this replacement ZIP on staging first. PHP 8.5 remains the package requirement from pro.34.
2. Replace the existing plugin via WordPress Upload Plugin → Replace current with uploaded. Keep the plugin directory `delicat-builder-v9`.
3. Open a Builder admin page to let its Compiler run, then check its report. Purge LiteSpeed and Cloudflare if configured.
4. Verify the live integration flows above before promoting to production. Keep the original ZIP and database backup for rollback.

## Removed files

- `RELEASE-PRO34.md` — Superseded release/audit documentation; not runtime code (2010 bytes)
- `PERFORMANCE-AUDIT-PRO31.md` — Superseded release/audit documentation; not runtime code (10411 bytes)
- `RELEASE-PRO33.md` — Superseded release/audit documentation; not runtime code (3199 bytes)
- `PERFORMANCE-AUDIT-PRO32.md` — Superseded release/audit documentation; not runtime code (1641 bytes)
- `assets/critical/products-home.366f4caa636b.css` — Hash is outside all three retained asset generations (2508 bytes)
- `assets/js/editor.950970965bf0.js` — Hash is outside all three retained asset generations (49476 bytes)
- `assets/js/carousel.d42494873b02.js` — Hash is outside all three retained asset generations (24152 bytes)
- `assets/js/heart.fa4066262ab3.js` — Hash is outside all three retained asset generations (7019 bytes)
- `assets/components/products.4cf3a1ce6409.css` — Hash is outside all three retained asset generations (42356 bytes)
- `assets/components/all-components.min.c044cc95752d.css` — Hash is outside all three retained asset generations (178682 bytes)
- `assets/css/app-polish.58e75566b12e.css` — Hash is outside all three retained asset generations (12899 bytes)
- `assets/css/app-tuning.d2e86efda396.css` — Hash is outside all three retained asset generations (19686 bytes)

## PHP module inventory

| File | Methods/functions | Hook registrations |
|---|---:|---:|
| `asset-versions.php` | 0 | 0 |
| `delicat-builder-v9.php` | 6 | 14 |
| `includes/class-delicat-builder-admin.php` | 10 | 4 |
| `includes/class-delicat-builder-announcement.php` | 20 | 5 |
| `includes/class-delicat-builder-app-polish.php` | 20 | 9 |
| `includes/class-delicat-builder-app-tuning.php` | 10 | 4 |
| `includes/class-delicat-builder-archive-builder.php` | 47 | 8 |
| `includes/class-delicat-builder-assets.php` | 14 | 5 |
| `includes/class-delicat-builder-audit-fixes.php` | 10 | 9 |
| `includes/class-delicat-builder-badges.php` | 15 | 3 |
| `includes/class-delicat-builder-bottom-nav.php` | 8 | 3 |
| `includes/class-delicat-builder-cache.php` | 21 | 18 |
| `includes/class-delicat-builder-carousel.php` | 12 | 0 |
| `includes/class-delicat-builder-cloudflare.php` | 16 | 9 |
| `includes/class-delicat-builder-compiler.php` | 23 | 4 |
| `includes/class-delicat-builder-core.php` | 13 | 0 |
| `includes/class-delicat-builder-design-admin.php` | 6 | 3 |
| `includes/class-delicat-builder-design.php` | 13 | 7 |
| `includes/class-delicat-builder-drawer.php` | 18 | 1 |
| `includes/class-delicat-builder-editor.php` | 13 | 5 |
| `includes/class-delicat-builder-express-checkout.php` | 7 | 3 |
| `includes/class-delicat-builder-express-payment.php` | 24 | 12 |
| `includes/class-delicat-builder-footer.php` | 16 | 4 |
| `includes/class-delicat-builder-front-slim.php` | 19 | 10 |
| `includes/class-delicat-builder-header-runtime.php` | 29 | 10 |
| `includes/class-delicat-builder-header-studio-8.php` | 40 | 12 |
| `includes/class-delicat-builder-heart-engine.php` | 12 | 1 |
| `includes/class-delicat-builder-hero-search.php` | 1 | 0 |
| `includes/class-delicat-builder-homepage-admin.php` | 12 | 9 |
| `includes/class-delicat-builder-homepage.php` | 28 | 0 |
| `includes/class-delicat-builder-identity-bridge.php` | 27 | 6 |
| `includes/class-delicat-builder-legal.php` | 17 | 3 |
| `includes/class-delicat-builder-live-selling.php` | 25 | 8 |
| `includes/class-delicat-builder-maintenance-admin.php` | 13 | 7 |
| `includes/class-delicat-builder-maintenance.php` | 33 | 4 |
| `includes/class-delicat-builder-media.php` | 5 | 0 |
| `includes/class-delicat-builder-menu-builder.php` | 44 | 5 |
| `includes/class-delicat-builder-menu-runtime.php` | 44 | 3 |
| `includes/class-delicat-builder-motion-admin.php` | 7 | 3 |
| `includes/class-delicat-builder-multi-currency.php` | 61 | 34 |
| `includes/class-delicat-builder-native-pages.php` | 15 | 5 |
| `includes/class-delicat-builder-native-product.php` | 39 | 15 |
| `includes/class-delicat-builder-notifications.php` | 62 | 21 |
| `includes/class-delicat-builder-pages.php` | 15 | 5 |
| `includes/class-delicat-builder-performance-admin.php` | 8 | 4 |
| `includes/class-delicat-builder-performance.php` | 26 | 6 |
| `includes/class-delicat-builder-product-switcher.php` | 12 | 5 |
| `includes/class-delicat-builder-production-admin.php` | 11 | 6 |
| `includes/class-delicat-builder-production.php` | 16 | 2 |
| `includes/class-delicat-builder-purchase-admin.php` | 7 | 3 |
| `includes/class-delicat-builder-purchase-native.php` | 61 | 22 |
| `includes/class-delicat-builder-purchase-ui.php` | 20 | 7 |
| `includes/class-delicat-builder-pwa.php` | 22 | 7 |
| `includes/class-delicat-builder-query-cache.php` | 8 | 1 |
| `includes/class-delicat-builder-release-admin.php` | 15 | 8 |
| `includes/class-delicat-builder-release.php` | 26 | 3 |
| `includes/class-delicat-builder-renderer.php` | 28 | 0 |
| `includes/class-delicat-builder-reviews-kernel.php` | 6 | 4 |
| `includes/class-delicat-builder-reviews.php` | 31 | 14 |
| `includes/class-delicat-builder-runtime-router.php` | 9 | 3 |
| `includes/class-delicat-builder-schema.php` | 19 | 0 |
| `includes/class-delicat-builder-security-admin.php` | 5 | 3 |
| `includes/class-delicat-builder-security.php` | 31 | 19 |
| `includes/class-delicat-builder-self-test.php` | 8 | 1 |
| `includes/class-delicat-builder-server-engine.php` | 24 | 5 |
| `includes/class-delicat-builder-session.php` | 7 | 2 |
| `includes/class-delicat-builder-shell-admin.php` | 8 | 3 |
| `includes/class-delicat-builder-shell-nav.php` | 6 | 1 |
| `includes/class-delicat-builder-shell.php` | 51 | 11 |
| `includes/class-delicat-builder-site-admin.php` | 6 | 2 |
| `includes/class-delicat-builder-site.php` | 14 | 7 |
| `includes/class-delicat-builder-stability.php` | 11 | 6 |
| `includes/class-delicat-builder-storefront-fix.php` | 47 | 30 |
| `includes/class-delicat-builder-swatches.php` | 65 | 15 |
| `includes/class-delicat-builder-transport.php` | 9 | 7 |
| `includes/class-delicat-builder-turbo-diagnostics.php` | 5 | 1 |
| `includes/class-delicat-builder-turbonav.php` | 15 | 7 |
| `includes/class-delicat-builder-unified-modules.php` | 33 | 5 |
| `includes/class-delicat-builder-unified-runtime.php` | 24 | 4 |
| `includes/class-delicat-builder-web-push.php` | 44 | 4 |
| `includes/class-delicat-builder-woo-admin.php` | 8 | 4 |
| `includes/class-delicat-builder-woo-ui.php` | 49 | 32 |
| `modules/product-fields/class-dmc-calc-admin.php` | 6 | 3 |
| `modules/product-fields/class-dmc-calc-eval.php` | 5 | 0 |
| `modules/product-fields/class-dmc-calculator.php` | 29 | 9 |
| `modules/product-fields/shim.php` | 6 | 0 |
| `pro/class-dbp-admin.php` | 6 | 3 |
| `pro/class-dbp-assets.php` | 8 | 7 |
| `pro/class-dbp-kernel.php` | 15 | 3 |
| `pro/class-dbp-nav.php` | 17 | 6 |
| `pro/class-dbp-security.php` | 9 | 8 |
| `pro/class-dbp-state.php` | 11 | 3 |
| `pro/class-dbp-type.php` | 6 | 4 |
| `templates/maintenance.php` | 0 | 0 |
| `templates/native-archive.php` | 0 | 0 |
| `templates/native-content-page.php` | 0 | 0 |
| `templates/native-page.php` | 0 | 0 |
| `templates/native-search.php` | 0 | 0 |
| `templates/native-single-product.php` | 0 | 0 |
| `templates/purchase/cart-empty.php` | 0 | 1 |
| `templates/purchase/cart.php` | 0 | 1 |
| `templates/purchase/result-count.php` | 0 | 0 |
| `templates/server-page.php` | 0 | 0 |
| `templates/server-v9-shell.php` | 0 | 0 |
| `templates/site-404.php` | 0 | 0 |
| `tests/test-cache-gate.php` | 36 | 2 |
| `tests/test-critical-budget.php` | 1 | 0 |
| `tests/test-shared-document.php` | 0 | 0 |
| `tools/rebuild-assets.php` | 3 | 0 |
| `uninstall.php` | 0 | 0 |
