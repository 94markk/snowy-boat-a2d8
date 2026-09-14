# Delicat Builder security audit — 9.2.0-pro.10

Audit date: 9 September 2026. Input: 9.2.0-pro.9-speed-security.

This release fixes verified source-code weaknesses in the supplied Builder plugin. It has not been installed on the live store. This is a source audit and public-page inspection, not certification that the entire WordPress installation is vulnerability-free.

## Findings and fixes

| Finding | Risk and evidence | Fix |
|---|---|---|
| Login throttle could be overridden | High. Builder registered its rejection at authenticate priority 5; WordPress password callbacks at priority 20 can replace an earlier error with a valid user. Reproduced using the documented callback ordering in a local harness. | Enforce the throttle at PHP_INT_MAX, after core password authentication. Unthrottled users retain normal authentication. |
| Guest rate-limit identity was caller-controlled | Medium. An arbitrary cookie whose name began with wp_woocommerce_session_ selected the limiter bucket without validating a WooCommerce session. Changing the cookie reset the limit. | Guest buckets use REMOTE_ADDR; authenticated buckets use the current user ID. No forwarded-header trust was added. |
| Existing push subscription could be overwritten | Medium. Subscribe looked up an endpoint and updated its keys/account association without checking the existing browser keys. Someone who obtained the endpoint could disrupt or reassign the subscription. This did not prove decryption of existing notifications. | Existing auth and p256dh keys must match using constant-time comparisons before any update. New subscriptions and legitimate refreshes remain accepted. |
| HTML/JSON cache representation collision | High availability risk; confidentiality risk depends on cache configuration. The legacy header-only fragment path could mark JSON public under the ordinary page URL. A plain query-flag visit could also produce JSON. The supplied PDF shows a logged-in shop fragment, but does not prove cross-user disclosure. | Require GET plus the dedicated query flag and fetch header for JSON. Explicitly disable storage for all fragment responses and fetch them with no-store. Plain query-flag visits render HTML. |
| Personalized pages lacked one unconditional no-store boundary | Medium defense in depth. The general response guard depended on recognized private routes and the enabled setting; signed-in public pages could use optional private caching. | Logged-in requests, recognized session/currency cookies, fragment flags and sensitive routes/actions now trigger no-store and LiteSpeed no-cache. Added wallet aliases and token/download/logout query guards. |
| Product-field price and condition manipulation | High when discounted checkbox options or conditional charge fields are configured. Duplicate checkbox entries multiplied contributions; hidden values could alter conditions despite being server configuration. Numeric display values could exceed bounds while price calculation clamped them. | Deduplicate checkbox values, reject malformed scalar and non-finite numeric inputs, enforce configured numeric bounds, and take hidden values from server configuration. A regression fixture with a 100 base price and a -10 discount remains 90 when the checkbox is repeated. |
| Configured password defaults exposed publicly | Medium, conditional on an administrator having saved a default password. Defaults were included in public calculator JSON and the password input value. No live secret exposure was confirmed. | Strip password defaults from public configuration and render an empty password input. Existing encrypted fulfillment storage remains in place. |

## Scope reviewed

Inventory: 101 PHP files and 46 JavaScript files. Repository-wide searches covered request parameters, AJAX/admin-post/REST registrations, SQL and outbound HTTP calls, filesystem writes, redirects, escaping and caching. Detailed review focused on authentication, navigation, session/wallet snapshots, notification ownership, Web Push, favorites, reviews, product-field pricing/encryption, express checkout, editor/import/export permissions, maintenance bypass and compiled-file cleanup.

Existing protections observed in the reviewed paths include capability and nonce checks for admin actions; current-user scoping for session/wallet responses; owner-scoped notification state; prepared SQL values in search/push queries; safe outbound HTTP for push and currency updates; escaped storefront fields; and server-side calculator evaluation without PHP eval. These observations do not substitute for executing every integration with the deployed plugins.

## Website coverage

Public page content was retrieved through web access. Some responses were cached crawls, ranging from today to several weeks old; these are not fresh HTTP-header measurements or browser interaction tests.

- Home; shop pages 1 and 2; games, gift-card, subscription and exchange category sections.
- Attempted all 43 product links listed across the two shop pages. Content retrieved for 38. Prime Video, PUBG Mobile, Razer Gold, USDT and Xbox could not be retrieved.
- Cart and the public account/login page; support; tutorials; PIN redemption; Bon Kliyan rewards; Sondaj.
- Terms, privacy, refunds, delivery/returns, cookies, legal notice, and Exchange/Wallet terms.
- The App menu link resolved to the homepage.
- Wallet returned HTTP 429. Orders and checkout could not be retrieved. Authenticated account screens, wp-admin, populated checkout, payment callbacks, withdrawals and real transactions were not exercised.
- The attached shop PDF was text-extracted and visually inspected. It contains serialized navigation markup and logged-in/admin-bar markers. No victim session was used and no cross-user leakage was demonstrated.

Reference pages: [storefront](https://delicastoreha.com/), [shop](https://delicastoreha.com/shop/), [shop page 2](https://delicastoreha.com/shop/page/2/), [cart](https://delicastoreha.com/cart/), [account](https://delicastoreha.com/my-account/), [support](https://delicastoreha.com/support/). The authentication finding was checked against [WordPress authenticate ordering](https://developer.wordpress.org/reference/hooks/authenticate/) and [the core password callback](https://developer.wordpress.org/reference/functions/wp_authenticate_username_password/).

## Validation performed

- 101 PHP files passed PHP.wasm 8.5.10 syntax lint.
- 101 PHP files parsed successfully in PHP 7.4 grammar mode. This is not a PHP 7.4 runtime compatibility test.
- 46 JavaScript files passed node --check.
- 24 targeted PHP regression checks passed using local WordPress/database stubs: throttle ordering; valid login preservation; cookie rotation; separate guest buckets; fragment negotiation/malformed flags/methods; push ownership and valid refresh/new registration; repeated discounts; malformed checkbox/scalar/numeric values; numeric bounds; trusted hidden values; and password-default removal from public configuration.
- Tests made no production writes, sent no notifications and charged no wallet.
- The package integrity manifest was rebuilt with SHA-256 hashes for the final files. ZIP structure and hashes were checked after packaging.

No full WordPress/WooCommerce test installation or production credentials were supplied. Browser login/logout, PHP version and extensions on the host, gateway compatibility, actual CDN/LiteSpeed response headers, and two-account cache isolation remain staging gates.

## Installation and deployment checks

1. Back up the database and current plugin. Upload this ZIP to a staging copy using Plugins → Add New → Upload Plugin, and replace the existing Delicat Builder V9. Verify version 9.2.0-pro.10.
2. Purge LiteSpeed/page caches and any hosting/CDN cache, including existing URLs containing dbp_nav. New headers cannot remove old cached responses already stored upstream.
3. Confirm the homepage/shop load as HTML. Opening /shop/?dbp_nav=1 normally must also show the shop. A same-origin navigation fetch with its header must receive JSON with private, no-store and LiteSpeed no-cache.
4. Check login/logout, Google/passkey/2FA via the installed Identity plugin, then use two separate customer sessions to verify that wallet/cart/account data never crosses sessions.
5. Check one ordinary product, one variable product, one numeric calculator product and one password-field product. Confirm displayed totals equal server order totals, secrets do not appear in customer emails/REST, and authorized fulfillment staff can read the encrypted data.
6. Use gateway sandbox/test mode to verify checkout, wallet charging exactly once, order completion, payment callbacks and supplier delivery. Confirm push opt-in, refresh and unsubscribe on an existing browser.
7. Run Builder's integrity scan. Deploy to production only after those checks pass, then purge caches again.

## Remaining limits and tradeoffs

Navigation payloads and personalized pages are deliberately uncached in this release. Repeated page switches can require more PHP work than pro.9; public full-page catalog caching remains available. No speed improvement is claimed.

The transient-based limit counters are best-effort and not atomic under concurrent traffic. Guest limits use the server-observed IP: customers behind a shared proxy may share a bucket, and attackers using multiple IPs can spread requests. Host/firewall controls and trusted proxy configuration need separate review.

Identity Pro, TeraWallet, payment/SMS bridges, the exchange engine, rewards, supplier integration, WordPress core, the theme, hosting configuration and their deployed versions are not included in this archive. Their private functions and any historical compromise cannot be audited or repaired from this Builder ZIP. A full-site sign-off needs those sources, authenticated staging access, an installed-plugin/version inventory, and relevant logs.

Existing encrypted fulfillment records depend on WordPress salts. Preserve the current salts when deploying this update; key rotation requires a separate migration. The update changes neither balances nor order statuses and performs no database/schema migration.
