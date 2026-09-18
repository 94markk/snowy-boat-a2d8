9.2.0-pro.43: the plugin is installable again. The header declared `Requires PHP: 8.5` to match
the host, and WordPress refuses an uploaded plugin whose requirement exceeds the server's --
decided with version_compare() on the REPORTED VERSION STRING, not PHP_VERSION_ID. A
release-candidate string like 8.5.0RC2 compares lower than a plain 8.5, and a control panel's
configured version is not always what the web SAPI reports, so the package stopped installing on
a server that genuinely runs 8.5. The requirement is now 8.3, which is what the code actually
needs -- one feature, the typed class constants in heart-engine. Nothing uses 8.4 or 8.5 syntax.
Running on 8.5 is unchanged. A new test asserts the header, all three runtime gates and the real
syntax floor stay in agreement, so this cannot recur.

9.2.0-pro.42: fixes a regression introduced in pro.40. Loading the Compiler on every admin
request meant the first request after an update ran a 250-page stylesheet compile -- and that is
very often the request installing, updating or deleting a plugin, while WordPress is moving files
on disk. The combination could exceed max_execution_time, so the plugin appeared unable to
install or uninstall itself. The repair now skips every plugin/theme management screen and every
non-idempotent request, and carries a wall-clock budget so it can never time out an admin page;
it resumes on the next ordinary admin page. Ordinary screens still repair the stylesheets, which
was the point of the pro.40 change.

9.2.0-pro.41: from the 60-agent adversarial audit. A declined card PERMANENTLY locked a
signed-in customer out of checkout: guard_classic() takes a mutex for every signed-in classic
checkout on every gateway, WooCommerce creates the order before charging, and reclaim_abandoned()
refused on order_id BEFORE its age check -- so the guard could never be reclaimed at any age and
only an admin could free them. Nothing had been charged. Every product view fired a second full
WordPress bootstrap (/?dbp_state=1) even for a guest with no cookies, roughly doubling origin PHP
on the most-visited route; it now fires only for visitors who actually have state. The cache-tier
gate disagreed with the render gate, so six request shapes -- site search with a basket being the
everyday one -- printed the shopper's real cart AND shipped with no cache headers at all. The Pro
state endpoint answered on init:5 and walked through maintenance mode's API lock. Purge LiteSpeed
and Cloudflare after updating.

9.2.0-pro.40: speed. pro.30 replaced "any query string is private" with an allowlist in
public_cache_allowed(), but THREE other gates kept the old rule and vetoed it -- including one
that defines DONOTCACHEPAGE, killing the page cache, the fragment cache and LiteSpeed for the
whole request. Every ad click (?fbclid, ?utm_*, ?gclid) and every ?paged/?orderby hit was a full
PHP render. On an ad-driven store that is most of the traffic. Instant navigation was aborting on
EVERY page: one <script type="module"> in the destination (WordPress 6.5+ emits them everywhere
via the Interactivity API) threw before checking whether that module was already running, so the
engine hard-navigated and the browser downloaded the document a second time. Back from a product
dumped shoppers at the top of the listing. Inline wp_localize_script data froze at the first
page's values across soft navigation. Compiled page stylesheets now rebuild on any admin page
instead of only Delicat Builder screens, so the homepage stops falling back to the 176 KB bundle
after an update. Purge LiteSpeed and Cloudflare after updating.

9.2.0-pro.39: two money/checkout bugs. Cart prices COMPOUNDED: apply_cart_prices()
recomputed from the product object it had already marked up, and WooCommerce fires
woocommerce_before_calculate_totals several times per request, so a 250 base with a 50
option charged 300, then 350, then 400 -- and a percentage or {base}*{qty} formula
compounded geometrically. Shoppers were overcharged silently. Express checkout was
UNUSABLE in any non-base currency: the review sheet issued its cart hash in the display
currency while pay() re-hashed in base currency, so every express payment failed with
"Le panier a change" and refreshing never helped. Two new regression tests, both
verified to fail on the unfixed code. Purge LiteSpeed and Cloudflare after updating.

9.2.0-pro.38: two formula bugs that could sell a product for 0.00 are fixed. An unknown
variable in a pricing formula resolved to 0 and still reported success; every arithmetic failure
(division by zero, modulo by zero, sqrt of a negative, an incomplete formula) returned 0.0, which
evaluate_checked() could not tell apart from a formula that legitimately equals zero, so
compute_total() took the success branch and priced the product free with nothing logged.
`{base} / {qty}` with an inactive qty field reached this on ordinary configuration. PHP
requirement restored to 8.5. New tests/test-formula-eval.php covers both. Purge LiteSpeed and
Cloudflare caches after updating.

9.2.0-pro.37: Pro Kernel boots again (a wp_rand() call on the boot path threw before
WordPress had loaded pluggable.php, which silently disabled instant navigation, route-scoped
assets, critical CSS and the shared state store). Asset pipeline switched from duplicate hashed
files to ?ver= content hashes: 121 duplicate files and 1.77 MB removed. PHP floor corrected from
8.5 to the real requirement, 8.3. Homepage critical-CSS budget raised to 9500 bytes so the product
grid stops being dropped. Purge LiteSpeed and Cloudflare caches after updating.

9.2.0-pro.36: like buttons now render on the homepage and signed-in favourites save (see RELEASE-PRO34.md). Purge LiteSpeed and Cloudflare caches after updating.

9.2.0-pro.33: like button rebuilt and PHP 8.5 required (see RELEASE-PRO33.md). Purge LiteSpeed and Cloudflare caches after updating.

9.2.0-pro.10: security fixes and validation notes are in SECURITY-AUDIT-PRO10.md. Purge existing page/CDN caches after updating.

RC88: see AUDIT-RC88.md for the full live-site repair follow-up, secure product-field handling, deterministic customer-facing icons, coordinated Tutoriels hardening and staging validation requirements.

RC86: see AUDIT-RC86.md for the continued stability/responsiveness audit and staging validation requirements.

RC85: see AUDIT-RC85.md for the stability/performance audit and staging validation requirements.

RC84: see AUDIT-RC84.md for the current update and staging validation requirements.

DELICAT BUILDER V9 — 9.1.0-rc.82
Assistant Position Recovery production package

What RC71.6 fixes
-----------------
1. Closed Assistant: the launcher now sits at the true bottom-right edge because the deleted standalone WhatsApp button no longer needs a reserved slot.
2. Open Assistant: the chat is a stable mobile sheet tied to the visible viewport, not an oversized panel offset from the launcher.
3. Correct vertical placement: the sheet clears the WordPress admin toolbar at the top and the Delicat Bottom Nav at the bottom.
4. Commerce-safe clearance: product purchase and checkout docks receive their own panel bottom insets.
5. Cache cleanup: stale PWA or page-cache copies of the retired `BXQXUCKDDI3GO1` floating button are removed without hiding normal WhatsApp support links.
6. Assistant content keeps its own internal scrolling, keyboard behavior and reduced-motion support.

What RC71.5 fixes
-----------------
1. Floating actions: Delicat Assistant and WhatsApp are two equal app-style buttons in one bottom-right row instead of overlapping.
2. Cleaner launcher: the promotional text is removed at every viewport size and an empty or zero unread badge is hidden.
3. Open chat state: WhatsApp automatically yields while the Assistant panel is open and returns when the panel closes.
4. Safe mobile placement: the contact row clears the Bottom Nav, product purchase dock, checkout dock and iPhone safe area.
5. Phone-sized panel: known Assistant panel containers are constrained to the visible viewport with compact app-style corners.
6. Smooth and efficient behavior: transitions respect reduced-motion, while a scoped observer tracks only the Assistant subtree after it appears.

What RC71.4 improves
---------------------
1. Faster first screen: Shop, Product and Account routes reuse one app-shell stylesheet instead of requesting Theme, Header, Drawer and Bottom Nav separately.
2. Lighter background work: product asset warming is delayed until idle and capped by device memory; speculative product-page warming is reduced to one or two documents.
3. Mobile app scale: compact 64px header, 44–48px controls, iOS-safe 16px inputs, readable badges, balanced headings and consistent page gutters.
4. App-like storefront: refined homepage hero/carousels, product cards, archive cards, product purchase panels, cart, checkout, account and search layouts.
5. Smoother scrolling: below-fold homepage sections defer rendering, low-power devices avoid expensive effects, and live-sale images reserve their final dimensions.
6. Existing cross-document page transitions, reduced-motion support, authentication recovery and all WooCommerce/security authority remain intact.

What RC71.3 fixes
-----------------
1. Google recovery contract: wp-login.php uses `dip_error`, while RC71.2 listened only for `dip_auth_error`. Both forms are now normalized back into the Delicat storefront modal with the original safe error code.
2. Reliable sign-out: logout taps first request a fresh live session payload and use its current WordPress nonce, preventing stale cached Android pages from submitting an expired logout link.
3. Post-login synchronization: successful Identity transitions force a live session check and clear stale service-worker documents before the account UI renders.
4. Android card layout: legacy “Comment ça marche” and “Pourquoi Delicat” cards use explicit mobile flow/grid containment so text cannot collapse into the icon column or inherit desktop height.
5. Cached header compatibility: old two-logo markup is normalized at runtime, showing exactly the active light/dark logo even in Android WebViews.
6. OAuth state, PKCE, nonce, browser binding, 2FA, account policy, checkout, payments, wallet and WooCommerce authority remain unchanged.

What RC71.2 fixes
-----------------
1. Sign-out / sign-in recovery: every Builder logout returns through a one-time live-session reset so signed-out state settles immediately.
2. Native login: when signed out, Header and Bottom Nav “Compte” open Delicat Identity directly instead of WooCommerce / wp-login.php.
3. Google recovery: Identity errors that accidentally land on wp-login.php are routed back to the Delicat Store login modal without bypassing OAuth state, PKCE, nonce, 2FA or account policy.
4. Stale PWA state: HTML document caching is disabled while Delicat Identity is the authentication authority. Static CSS, JS, fonts and images remain cacheable for speed.
5. Private cache defense: HTML carrying Cache-Control private, no-cache or no-store is never written into Builder's document cache.
6. Header duplicate logo: Header Studio now renders one logo element. Dark mode swaps that element's source instead of keeping two logo links in the DOM.
7. Assistant on mobile: the launcher itself is pinned above the Bottom Nav regardless of its wrapper state, and promotional copy is hidden on phones.
8. RC71/RC71.1 performance and UI fixes remain in place. No checkout, payment, order, wallet, Player ID, supplier or WooCommerce authority logic was bypassed.

Install / acceptance test
-------------------------
1. Back up WordPress database + wp-content.
2. Upload this ZIP over the current Delicat Builder V9 and activate/replace the existing copy. Keep only ONE active Builder V9 copy.
3. Purge LiteSpeed Cache and Cloudflare/CDN once.
4. On the affected Android browser, first test in a Chrome Incognito tab. For an already-installed PWA/browser session that still shows the pre-update page for a few minutes, close/reopen the tab or clear that site's cached data once so the new service worker takes control.
5. Test: Google login -> sign out -> tap Compte -> Google login again. The customer must stay in the Delicat modal/storefront and must not land on wp-login.php.
6. Scroll the homepage. The Assistant must sit at the bottom-right edge above the Bottom Nav and must not show a “0” badge or promotional sentence.
7. Open the Assistant. The panel must stay inside the viewport, below the WordPress toolbar and above the Bottom Nav.
8. Reopen/close the menu and visit a product page. The Assistant panel must clear the Bottom Nav and purchase dock without covering controls.
9. Confirm the deleted standalone green WhatsApp button stays absent while WhatsApp links inside the Assistant, menu and footer still work.
10. Toggle light/dark mode. Only one Delicat header logo may be visible.
11. Finally test cart, wallet, checkout and an order flow as a production acceptance check.

Recommended production settings
-------------------------------
- Keep LiteSpeed page cache enabled for genuinely public GET pages.
- Keep JS Combine OFF for Builder/WooCommerce; Builder manages its own load order.
- Use persistent object cache when Hostinger provides Redis/Object Cache.
- Keep Cloudflare Brotli and HTTP/3 enabled; avoid Rocket Loader on WooCommerce/Builder/Identity scripts.
- Keep only one active Delicat Identity Pro and one active Delicat Builder V9 installation.

Authority and security
----------------------
WordPress/WooCommerce remain account, cart, checkout, payment and order authority. Delicat Identity Pro remains authentication/security authority when enabled. RC71.6 changes Assistant positioning and retired-button cache cleanup only; it does not accept an invalid Google callback or bypass any authentication or commerce check.
