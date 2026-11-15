=== Delicat – Top-Up Fulfillment for WooCommerce ===
Contributors: delicatstore
Tags: woocommerce, game topup, digital delivery, reseller api, vouchers
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 2.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

White-labelled SHOP2TOPUP fulfillment for WooCommerce with resilient background catalog import, dynamic Player ID fields, encrypted recent Player IDs, signed callbacks, balance monitoring, and catalog/stock synchronization.

== Description ==

Version 2.3 adds grouped WooCommerce variation imports and protected manual customer pricing. A game/service can be imported as one variable product with supplier denominations/packages as variations. Supplier USD cost remains internal and synchronized independently from the customer-facing WooCommerce price.

Core features:

* Exact v1 authentication: `Authorization: Bearer <keyId>.<secret>`.
* Big-category → category → subcategory browsing using the provider's current catalog structure.
* Import a game/service as one variable WooCommerce product with denomination/package variations (recommended), or keep the legacy separate-simple-product structure.
* Set each variation selling price independently in WooCommerce/HTG while supplier USD cost remains read-only and synchronized separately.
* Import selected products, an entire category, or the full supplier catalog as WooCommerce drafts.
* Background import with durable progress and isolated per-item errors.
* Scheduled catalog synchronization with 15-minute default interval.
* Safe availability logic: confirmed zero/unavailable blocks purchase; missing stock is never guessed as zero.
* Dynamic player/server/zone/region requirement fields.
* Optional provider player-name validation before purchase.
* Encrypted five-entry Player ID history for signed-in customers, including associated server/region/zone values.
* UUID idempotency keys persisted before upstream orders.
* Fresh live price check and configurable cost guards before wallet spend.
* Strict HMAC-SHA256 callbacks over the exact raw body plus scheduled reconciliation fallback.
* Encrypted API credentials and voucher codes at rest.
* White-label customer output, HPOS support, Action Scheduler integration, redacted logs, and WP-CLI diagnostics.

The API is live-only. A paid mapped order can spend the reseller wallet.

== Installation ==

1. Back up the website and preferably test on staging.
2. Install and activate WooCommerce.
3. Upload this ZIP through Plugins > Add New > Upload Plugin and replace the older version.
4. Open the plugin Settings and enter the complete `keyId.secret` API credential.
5. Use Test connection. If it reports `IP_NOT_ALLOWED`, allow the WordPress server's outbound public IP in SHOP2TOPUP before continuing.
6. Configure the private callback URL in the reseller panel and save the generated webhook HMAC secret in WordPress.
7. Send a signed webhook test.
8. Set **Import structure** to Variable product and **Customer pricing** to Manual WooCommerce pricing. If your storefront sells in gourdes, confirm WooCommerce > Settings > General > Currency is HTG.
9. Open Catalogue, choose a category, enter the customer-facing HTG price for each package you want, then import selected packages or the entire category. The import continues in the background.
10. Review the draft variable product, its variations, required Player ID fields, and availability, then publish.
11. Make one small paid test order before enabling the integration broadly.

Never map the same WooCommerce product to two automatic supplier plugins; that can create duplicate fulfillment and duplicate wallet charges.

== Secure wp-config.php configuration ==

Production sites can keep credentials out of the database:

`define( 'DELICAT_S2T_KEY_ID', 'your-key-id' );`

`define( 'DELICAT_S2T_KEY_SECRET', 'your-key-secret' );`

`define( 'DELICAT_S2T_WEBHOOK_SECRET', 'your-webhook-signing-secret' );`

`define( 'DELICAT_S2T_ENCRYPTION_KEY', 'a-stable-random-secret-of-at-least-32-characters' );`

Set a stable encryption key before entering credentials or accumulating encrypted voucher/Player-ID data. Do not rotate it without a data migration.

== Catalog behavior ==

Catalog imports are queued in small batches. In variable mode, one supplier category becomes one WooCommerce variable product and each supplier subcategory/item becomes a variation. The supplier item ID, supplier category ID, requirements, stock state, and USD cost live on the variation, so fulfillment always uses the exact package the customer selected. A failing image, item, or provider response is recorded without rolling back products that already imported successfully. New parent products are drafts. Re-imports return the parent to draft by default unless the store manager disables that safety setting.

`product_type` is used as the primary catalog type (`topup` or `voucher`). Requirement schemas are attached only where applicable. Provider pagination is followed when the API explicitly reports additional pages.

Stock is deliberately conservative. A confirmed quantity of 0 or explicit unavailable state sets the WooCommerce product out of stock. If the provider omits stock entirely, the plugin does not convert that omission into zero. Withdrawn items are retained but blocked; if they later reappear without numeric stock, stale plugin-owned outage stock is released.

== Manual HTG pricing ==

Manual WooCommerce Pricing is the recommended mode when Delicat Store sets retail prices in Haitian gourdes. The plugin keeps two separate values:

* **Supplier cost** — cached/live USD amount from the reseller API. It is used only for cost protection and fulfillment.
* **WooCommerce selling price** — the customer-facing store price (HTG when WooCommerce currency is HTG). You can enter it during catalog import or edit the variation normally in WooCommerce.

Supplier catalog sync may update cost and availability without changing a manual WooCommerce price. The live supplier price is fetched again before each paid fulfillment and is sent back as `expected_unit_price`; the retail HTG price is never sent as the supplier cost. Automatic conversion remains optional for stores that explicitly enable Automatic USD conversion + markup.

== Recent Player IDs ==

For signed-in customers, the plugin can remember up to five recent identity combinations per game/category after a successful verification or add-to-cart. Relevant fields such as Player ID, server, region, or zone are stored together and encrypted with user/category context. They are loaded through authenticated AJAX rather than embedded in cacheable HTML. Selecting a saved identity does not bypass normal provider validation.

== Fulfillment behavior ==

Fulfillment begins only after WooCommerce reports the order paid. One UUID is persisted per mapped line item before any upstream create request. Timeout recovery reads the original UUID rather than generating a second purchase.

A valid signed callback can settle the corresponding WooCommerce line immediately. Scheduled reconciliation remains a fallback for delayed or missed callbacks. Cancelled/refunded orders are not silently changed back by a late supplier event, and mixed physical/digital orders remain under WooCommerce control.

== WP-CLI ==

`wp delicat-s2t test`

`wp delicat-s2t balance --refresh`

`wp delicat-s2t reconcile`

`wp delicat-s2t sync --batches=5`

`wp delicat-s2t status`

`wp delicat-s2t webhook --rotate`

== Frequently Asked Questions ==

= Why can I connect but not load/import the catalog? =

If the dashboard shows `IP_NOT_ALLOWED`, the provider is refusing the WordPress server's outbound IP before the catalog code runs. Add that server IP to the API allowlist or temporarily enable Allow All IP Addresses in the reseller panel, then test again. Your phone/home IP is not necessarily the server's outbound IP.

= Does import publish products automatically? =

No. New imports are drafts. Re-imports also return to draft by default so supplier changes can be reviewed first.

= Can one failed item stop a whole category import? =

No. v2.1 processes small background batches and records failures per item. Successful products remain imported while the job continues.

= What happens when the supplier does not send a stock value? =

Nothing is guessed. Missing stock is not treated as zero. Only a confirmed numeric zero or explicit unavailable state blocks the product.

= Does saved Player ID history bypass validation? =

No. It is only an encrypted convenience/autofill feature. The normal requirement and provider validation logic still applies.

= Can customers see the supplier? =

The plugin scrubs supplier-specific storefront copy/metadata and keeps operational identifiers out of normal customer order output. Server administrators and other installed code still have access to WordPress/plugin files, so white-labelling is not a substitute for server access control.

== Changelog ==

The complete history is in CHANGELOG.md.

= 2.10.0 =
* Product pages: saved Player IDs are requested only when the shopper starts choosing a package or typing an ID (no admin-ajax request on every signed-in view); the account form is sent once per product instead of once per package.
* Admin, WP-CLI and product-editor code is no longer compiled on storefront requests (24 → 21 files, 449 → 348 KB).
* Shared package module: package index (format-marked, rebuilt from the real WordPress query results; tests/package-index-smoke.php), no query per variable product on shop pages.

= 2.9.0 =
* Supplier-confirmed deliveries complete the WooCommerce order within seconds: the post-response lane now runs on LiteSpeed, a signed callback that loses the order-lock race is settled right after its response, two extra status reads happen in the first minute, and order jobs outrank catalog jobs.
* The "Vérifier votre compte" button recovers from expired nonces on cached pages, works on older Android WebViews, and shows readable French errors.
* Player-verification throttles use fixed windows (shared mobile-carrier addresses no longer stay blocked) plus a site-wide ceiling against forged forwarding headers.
* Cost-guard fix on the price fallback path; queued lines of orders that are no longer paid are parked instead of re-queued every minute; faster storefront and catalog admin; complete uninstall cleanup; test scripts refuse to run over HTTP.

= 2.8.3 =
Custom package values restored in the native WooCommerce variation selector.

= 2.8.0 – 2.8.2 =
Forfaits personnalisés (custom package recipes), product-editor access and a startup fix for the shared module. See CUSTOM-PACKAGES.md.

= 2.7.0 – 2.7.4 =
Non-blocking product saves, compact Player ID card, stricter player identity checks, Free Fire region/server checks and messages.

= 2.6.1 =
Modernize the customer product fields with dependency-free Material-style SVG glyphs, compact recent-ID controls, cleaner UID inputs and a premium verification button. Remove the large payment-explanation banner while preserving the WooCommerce payment gate and v2.6.0 fast-lane fulfillment architecture.

= 2.6.0 =
Fix private webhook registration after HMAC setup, maintenance callback routing, queue successor scheduling, safe paid-order recovery and API payload validation.

= 2.4.6 =
Recovery retry bounds, response compatibility and diagnostics, accepted-order safeguards, mobile table scrolling.

= 2.4.5 =
* Fixed UID / Player ID fields not appearing on grouped variable products before a package was selected.
* Direct top-up products now always expose the canonical player_id field required by the player-validation API, even when the dynamic category requirements array is empty.
* Preserves entered UID/server values while package variations switch and restores the account form when variation selection is reset.


= 2.3.0 =
* Added grouped variable-product import: one game/service parent with supplier packages as WooCommerce variations.
* Added protected Manual WooCommerce Pricing for HTG/store prices, fully separated from supplier USD cost.
* Added per-variation selling-price inputs during catalog import.
* Supplier cost/stock/requirements can keep synchronizing without overwriting manual retail prices.
* Added configurable variation selector labels such as Package, Amount, Diamonds, or Points.
* Re-import merges packages into the existing variable parent and preserves merchant retail prices.

= 2.2.0 =
* Catalog import reliability rebuild: full catalog import, catalog fallbacks, browser-driven batches, and non-blocking missing requirements.

= 2.1.0 =

* Rebuilt catalog import as background batches with live progress, import-all, and per-item error isolation.
* Updated catalog type/pagination handling.
* Added safe stock synchronization and recovery for reappearing products.
* Added 15-minute default catalog synchronization.
* Added encrypted five-entry Player ID history with related server/region/zone values.
* Extended cleanup and security hardening while preserving strict auth/webhook behavior.

= 2.0.0 =

* Rebuilt authentication around the documented `Bearer keyId.secret` contract.
* Rebuilt strict raw-body HMAC webhook verification and immediate signed-callback settlement.
* Preserved reconciliation, balance monitoring, draft import, dynamic player fields, and supplier privacy.


UID / Player ID verification: imported player-account fields named player_id, uid, user_id, userid, or playerid are recognized as the same player identifier for pre-purchase validation.
