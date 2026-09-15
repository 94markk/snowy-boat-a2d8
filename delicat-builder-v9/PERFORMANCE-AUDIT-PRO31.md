# Delicat Store — performance audit and pro.31 update

Date: 15 September 2026
Input: delicat-builder-v9-9.2.0-pro.30 3.zip
Output version: 9.2.0-pro.31
Site inspected: https://delicastoreha.com/

## Result and limits

This release fixes the uncached archive navigation path, missing archive layout settings, duplicate script loading, unnecessary product-page navigation code, incorrect preload URLs, stalled session requests, and unsuccessful CSS compilation recovery.

The live website has NOT been modified. The ZIP is ready for installation. A 90% improvement across the entire website is NOT verified or guaranteed. Source fixes and local regression tests do not replace testing the installed plugin with WordPress, WooCommerce and the other active plugins.

## Live measurements

Public, signed-out HTTP GET requests were measured with curl using compression. Homepage, Jeux archive and Free Fire LATAM all returned HTTP 200. The site reports Builder pro.30. The homepage response explicitly reported `X-LiteSpeed-Cache: hit`.

The most useful comparison used the SAME curl connection, after a homepage request established TLS:

| Existing live route | Time to first byte | Total response time |
| --- | ---: | ---: |
| Jeux archive through old `?dbp_nav=1` navigation endpoint | 573 ms | 575 ms |
| Normal Jeux archive document | 117 ms | 160 ms |

The normal document's first byte arrived approximately 80% sooner in this paired sample. This is a comparison of two existing live request paths, NOT a before/after benchmark of the installed update. It is a small sample, not a percentile or a universal speed claim. Another normal-document request on a reused connection took 71 ms.

Separate fresh-connection requests had roughly 4.6–6.2 seconds TTFB, but 4.3–5.7 seconds were spent establishing the connection from the test environment. Those raw numbers cannot be attributed solely to WordPress or treated as a Haitian phone benchmark.

Compressed HTML transfer sizes observed: homepage 63,212 bytes; Jeux archive 48,492 bytes; Free Fire LATAM 62,145 bytes. No Lighthouse score, LCP, INP, CLS or real low-end-phone timing is claimed: this browser did not expose the performance timing API. Desktop page interaction and visual inspection were possible; mobile responsiveness was reviewed in source, not measured on a physical phone.

## Phases completed in the attached code

### 1. Navigation and server latency

`pro/assets/dbp-nav.js` now fetches the normal HTML URL using the existing HTTP cache policy. It no longer adds `dbp_nav=1`, sends a fragment header or forces `cache: no-store` on catalogue navigation. LiteSpeed can therefore serve its existing page-cache entry.

Only recognized Builder main elements can be swapped. Product, cart, checkout, account and wallet pages keep native navigation. Authentication-state changes, missing main content, unexpected scripts, module lifecycles and redirects fall back to a full document load. A build-generation marker prevents mixing a new release's content with an old shell.

There is no JavaScript HTML cache. Private documents are not retained for future clicks. Existing PHP cache/privacy gates, nonce checks and payment validation are unchanged. The old JSON endpoint remains for already-open pro.30 tabs, but pro.31 does not use it.

Removed the inactive fragment-prefetch machinery and memory-cache machinery from the active Pro browser runtime. History is updated when content is committed, not while its styles are still downloading; fallback navigation replaces an already-changed history entry.

### 2. Archive layout and asset lifecycle

The old fragment extracted stylesheet URLs but omitted inline styles. The omitted `delicat-builder-v9-woo-ui-inline-css` contains grid gap, card radius, text sizes and other archive settings. In the live archive reached from the homepage, cards visibly touched one another and lost their rounded layout.

The new document reader carries inline styles and external styles in document order, reconciles the page's previous styles, and waits for new required styles. It no longer paints the incoming page after an arbitrary 900 ms wait with essential CSS still missing.

Script URLs and WordPress IDs are indexed again before navigation, including LiteSpeed's delayed `data-src` scripts. Existing scripts are not executed again under a different URL spelling/version. This addresses the reproduced `wc-order-attribution-inputs` duplicate custom-element error. New script configuration and external scripts retain document order. Unknown combined bundles trigger native navigation rather than being executed twice.

Native archive CSS also receives fallback values, configurable mobile gaps/text/radii, wrapping breadcrumbs, bounded sort controls, visible keyboard focus, and a fully laid-out first product row. The unconditional per-card GPU transform is removed.

### 3. Product launch

Product preload URLs now use the same content-addressed files as the actual rendered page. Previously the warm list fetched plain filenames while the storefront used hashed filenames, wasting downloads and missing the browser cache on the subsequent product page.

The Pro navigation script is no longer enqueued on product, checkout, cart, account or wallet pages where the router is locked. The pro.30 navigation source alone was 34,867 bytes uncompressed. This avoids that unnecessary runtime on these pages; it is not a claim of an equal network saving after compression.

Product document warming remains limited, respects slow links/data-saving settings, and resets its allowance between catalogue pages. Browser support determines whether document prefetch/prerender is available. No instant-first-visit guarantee is made for Safari. WooCommerce variation forms, Player ID verification and express payment remain authoritative.

### 4. Shared session and page modules

A shared session request now has an eight-second deadline covering its JSON body. A stalled request can no longer hold all future refreshes indefinitely. Failure releases the pending request so the next interaction can retry.

Pro state reuses a recent in-memory state for navigation-only refreshes for up to 30 seconds; mutation/manual refreshes remain live. This does not cache payment authorization or replace checkout validation.

The testimonial observer disconnects before another page is initialized, preventing detached page elements from remaining observed during repeated page changes.

### 5. Homepage CSS compilation

The live homepage was still shipping `all-components.min.css`, indicating the fallback path. The compiler could return a fallback manifest without throwing; the version was then marked successfully compiled. This could leave the large stylesheet in use indefinitely.

Failed storage builds now record failure, use a 15-minute administrator retry cooldown and do not stamp success. Already-valid compiled pages with an existing file are skipped on retry. Cache purges occur only when a bundle was actually compiled, avoiding repeated purges when storage is unavailable. Public page requests still do not write CSS files.

Check upload-directory permissions if compilation continues to fail. The plugin cannot repair Hostinger file permissions from this ZIP.

### 6. Packaging and cleanup

Rebuilt content-addressed assets, asset history and integrity hashes. The existing three-generation asset retention rule is preserved so cached pages do not immediately lose their dependencies. Only an obsolete hashed archive CSS file outside that history is removed; active modules and compatibility source assets are retained.

## Validation

- Syntax checks: all 110 PHP files using PHP 8.5 via WordPress PHP-WASM; all 45 JavaScript source files using Node syntax checks.
- DOM regression tests: normal document URL/cache mode, inline archive settings, script-ID deduplication, no HTML retention, native product and sensitive-route bypasses, authentication mismatch, missing content, unknown bundle and inline-script fallbacks.
- Real saved LiteSpeed homepage and Jeux archive used as navigation fixtures; the archive settings and main content are preserved without reloading Woo order attribution.
- Session regression: an unresolved request times out and permits a subsequent retry.
- PHP compiler regression with WordPress API stubs: storage failure, retry cooldown, no cache purge on failure, recovery and success stamp.
- PHP enqueue regression: hashed product warm URLs and no router on the five locked route families.
- Generated asset map and integrity manifest checked before packaging.

The module review focused on the navigation, rendering, compiler, asset, archive, product, drawer, carousel, session, state and notification paths relevant to this latency problem. Syntax checks across all modules do NOT constitute a complete line-by-line correctness/security certification. Checkout/payment, authenticated accounts, supplier delivery, rewards and other third-party integrations still require staging/live acceptance testing. No money was moved and no test order was placed.

## Installation and verification

1. Keep a backup of pro.30 and the current site/database.
2. WordPress → Extensions/Plugins → Add New → Upload Plugin. Upload this ZIP and replace the existing Delicat Builder V9 plugin. Do not uninstall it or delete site data.
3. Open the WordPress dashboard once as administrator to trigger CSS compilation.
4. LiteSpeed Cache → Toolbox → Purge All. Purge Cloudflare HTML too if Cloudflare is serving cached pages.
5. Test signed-out and signed-in: homepage → Jeux → another category → Back → product; sorting, pagination, menu, dark mode, product options, Player ID, cart, wallet and express checkout.
6. Verify archive clicks request the normal URL, not `?dbp_nav=1`, and the duplicate WooCommerce custom-element error is absent. Confirm the homepage switches from the all-components fallback to a compiled page CSS file.
7. Repeat measurements on the same phone/network and cache state. A 90% reduction means a 1,000 ms baseline becomes 100 ms or less. Record both first visits and repeat visits separately.

Do not cache cart, checkout, wallet, account or authenticated state endpoints publicly. Do not remove sorting/filter/pagination parameters from cache keys. Investigate hosting/network delay separately if cached documents are still slow after deployment.
