# Security audit and hardening — 9.2.0-pro.12

Input: uploaded 9.2.0-pro.11-navigation-efficiency ZIP. Date: 2026-09-09.

This is a source-code audit and tested patch of Delicat Builder. It is not a certification of the live WordPress installation. The plugin has not been installed on the store during this task. No real orders, payments, messages, balance changes or supplier requests were made.

## Findings addressed

| Finding | Impact | Change |
|---|---|---|
| Express add-to-cart automatically replays after an unreadable or lost response | The first POST may already have succeeded; clicking the native submit button again can repeat the cart mutation | No automatic replay. An ambiguous outcome disables resubmission in that page and opens the WooCommerce cart for reconciliation |
| Express payment has a second submission path, and re-enables payment after uncertain responses | A network failure is not evidence that a charge failed; the custom sheet lacks gateway-specific reconciliation | The sheet no longer POSTs payment data. Continue opens the ordinary WooCommerce checkout, which owns validation, terms, order creation and gateway processing |
| Express pre-handler removes same-product cart lines before WooCommerce validates the replacement | Invalid submissions can delete a valid selection; fetch metadata alone is not authorization | Remove that pre-validation mutation. Add a logged-in WordPress nonce and HTTPS/method guard at wp_loaded priority 18, before the WooCommerce handler at 20. Reject cross-site/sibling-site flagged requests |
| Maintenance diagnostic disables certificate verification | HTTPS connection does not authenticate the remote server | Safe WordPress HTTP request, certificate verification enabled, redirect following disabled, response size bounded |
| No mandatory HTTPS boundary for plugin-loaded requests | Insecure deployment or integrations could process plaintext submissions | New mandatory transport class enables WordPress SSL admin, upgrades generated same-host URLs, redirects ordinary HTTP GET/HEAD to the configured HTTPS authority, and rejects insecure POSTs and sensitive GET actions without replay |
| Outbound WordPress HTTP API can be configured to use plaintext or disable TLS validation | Credentials or operational data can travel without verified TLS | Reject non-HTTPS outbound requests; enforce certificate verification; reject HTTPS-to-HTTP redirects using the WordPress Requests hook. Cloudflare credential requests also do not follow redirects |
| Cloudflare purge token stored as plaintext | A database-only disclosure exposes the token | New saves encrypt with the existing authenticated sensitive-value vault. On init, a loaded Cloudflare module migrates a legacy token and removes its plaintext option field only after encryption succeeds. Bootstrap recognizes the encrypted profile. Blank token saves preserve existing ciphertext |
| Pro CSP code consolidates then replaces existing CSP headers | Multiple host policies could be collapsed and some protections discarded | Append a separate enforcing policy, retaining host policies; constrain framing/base URI/objects and upgrade insecure resource requests on HTTPS |
| Cart-removal endpoints accept array-shaped cart keys | Malformed input can reach WooCommerce's string-key operations and cause a request error | Both runtime and studio implementations require POST and scalar string nonce/cart key inputs before their existing nonce and rate-limit checks |

## Authority and duplicate operations

WordPress remains responsible for users, authentication, sessions, capabilities and nonce validation. No custom password hashing, user database or bypass login is introduced.

WooCommerce remains responsible for cart validation, pricing, checkout, consent, order creation and order status. Builder does not call a gateway's payment processing method or mark an order paid. Existing native product-field validation and encrypted fulfillment metadata are retained.

The patch removes Builder's custom payment POST and its ambiguous add-to-cart replay. It does not implement a second order engine or a homemade payment lock. Exactly-once charging and fulfillment across multiple tabs, retries, callbacks and supplier webhooks must be verified in TeraWallet, the payment gateway and supplier integration. Their code is absent from this archive. This release does not claim that all possible duplicate orders or charges are prevented.

Customer-visible change: the express sheet is a review step; its button opens the full WooCommerce checkout. Changing a product selection no longer silently deletes the earlier cart line; customers reconcile quantities and selections in the native cart.

## Audit coverage

Repository-wide inspection covered 102 PHP files and 46 JavaScript files: AJAX/admin-post/REST registrations, capability and nonce guards, current-user data ownership, SQL construction, outbound HTTP, redirect targets, filesystem writes/deletes, crypto functions, cache boundaries and checkout submission paths.

Detailed review included transport/security, Pro navigation/state, express checkout/bootstrap, native purchase submissions, both header cart-removal implementations, Cloudflare credentials, maintenance checks, Web Push ownership/encryption, notification authorization/storage, favorites, reviews, compiler cleanup, configuration exports and backup restore handlers.

Observed existing controls retained:
- Admin-post handlers have direct capability/nonce checks or delegate to guarded helpers. Restore operations also check recent privileged authentication through the existing Identity bridge.
- Cart/wallet state uses the current WordPress/WooCommerce session; navigation and private data retain pro.10 no-store protections.
- Search SQL uses prepared values; compiler deletion is constrained to generated files and permitted filenames.
- Product field secrets use authenticated encryption and are excluded from normal public field values. Product prices remain evaluated on the server without PHP eval.
- Web Push validates HTTPS endpoints, key shapes and existing subscription keys. The privileged Android notification integration retains its existing token authorization; it was not converted to another authentication system.

This inventory is not proof that every function or third-party combination is vulnerability-free. Legacy runtime/studio files serve different loading paths; deleting them based on repeated names would break those paths. This patch applies the relevant input fix to both.

## Validation completed

- 102 PHP files parsed successfully on PHP.wasm 8.5.8 using TOKEN_PARSE.
- 102 PHP files parsed in PHP 7.4 grammar mode. This is not a PHP 7.4 execution test.
- All 46 JavaScript files passed node --check.
- 41 focused PHP assertions passed in isolated stubs/extracted callback harnesses: canonical redirect targets and injection cases; mandatory SSL admin; outbound TLS and downgrade rejection; read-only versus mutating HTTP requests; authenticated-encryption round trip/tamper rejection; credential migration and unreadable credential handling; express nonce/auth/method/origin guards; malformed cart inputs in both implementations.
- Focused JavaScript checks passed: checkout handoff makes no payment POST; uncertain cart requests do not call the native submit button again; resubmission is blocked on that page; unsafe HTTP/external/script URLs are rejected.
- ZIP CRC and complete regenerated SHA-256 manifest checked during packaging.

A full WordPress/WooCommerce installation and gateway sandbox were not available. Tests do not establish live TLS configuration, payment integration compatibility, browser behavior on customer devices, or protection against concurrent gateway/supplier events.

## HTTPS deployment requirements

Before installing on production:
1. Verify a valid certificate and full HTTPS support at the host. Set both WordPress Address and Site Address to their correct HTTPS URLs.
2. If a proxy terminates TLS, configure the web server/wp-config.php so WordPress is_ssl() correctly recognizes trusted HTTPS requests. Never trust arbitrary client X-Forwarded-Proto headers. A misconfigured proxy can cause redirect loops or rejected requests with this strict build.
3. Enforce HTTPS at the host/CDN too. Plugin code cannot protect static files, previously cached responses, traffic before PHP loads, or data already sent in an initial HTTP request. Use modern TLS settings at that layer.
4. Convert supplier/SMS/payment API and callback URLs to verified HTTPS. The global WordPress HTTP API boundary intentionally rejects HTTP-only providers and TLS validation failures. Raw cURL/socket code in other plugins is outside this boundary.
5. Purge hosting/CDN/LiteSpeed caches after deploying, including old private responses and dbp_nav URLs.

Generated same-host URLs are upgraded; arbitrary external URLs are not silently rewritten in stored data. The frontend CSP asks browsers to upgrade insecure resources where it is active. HTTP-only third-party resources must be replaced. No database-wide URL replacement or server configuration edit was performed.

## Encryption and migration limits

The existing vault uses Sodium secretbox when available, otherwise AES-256-GCM with random nonces and authentication tags. This task exercised the OpenSSL path. Preserve existing WordPress authentication salts: encrypted fulfillment data and migrated credentials depend on them.

Cloudflare migration runs when its module is loaded and init executes. Enabled profiles load on normal storefront requests; disabled profiles can migrate when their Builder administration module is opened. If crypto is unavailable, migration does not delete the existing credential, and new nonempty token saves fail. Verify the migration before treating the database as free of that plaintext token. Existing database backups can still contain the old plaintext value.

Rolling back to pro.11 after migration requires re-entering the Cloudflare token in its settings or restoring the relevant backed-up option; pro.11 does not understand the new encrypted option key. Never print or share the token for verification.

This is not whole-database encryption. WordPress passwords remain WordPress hashes; other plugins, Android integration keys, historical backups and existing tokens require their own credential review. Encryption does not protect against an attacker who obtains both the database and the server's keys/salts.

## Required staging verification

Back up the database and plugin first. Test HTTPS and HTTP rejection, login/logout/Google/passkey/2FA, cart add/remove/change, express-to-native checkout, required fields and consent, gateway sandbox payment, wallet charging and refund behavior, callback retries, supplier completion, two-account cache isolation, encrypted fulfillment display for authorized staff, Cloudflare purge after migration, push registration and existing browser subscriptions. Use gateway/supplier logs to confirm a repeated callback cannot debit or fulfill twice.

Remaining scope includes Identity Pro, TeraWallet, payment/SMS bridges, exchange/withdrawal logic, supplier API plugin, theme, server and database permissions, installed versions and prior compromise. Those were not supplied. Existing transient-based abuse counters are best-effort rather than atomic distributed rate limits; edge/server rate limiting remains necessary for sustained abuse.

## Primary references

- WordPress HTTPS and trusted proxy configuration: https://developer.wordpress.org/advanced-administration/security/https/
- WordPress nonce limitations and capability checks: https://developer.wordpress.org/apis/security/nonces/
- WordPress hardening: https://developer.wordpress.org/advanced-administration/security/hardening/
- WordPress Requests hook dispatch: https://developer.wordpress.org/reference/classes/wp_http_requests_hooks/dispatch/
- WordPress HTTP redirect validation: https://developer.wordpress.org/reference/classes/wp_http/validate_redirects/
