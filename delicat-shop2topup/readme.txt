=== Delicat – Top-Up Fulfillment for WooCommerce ===
Contributors: delicatstore
Tags: woocommerce, game topup, digital delivery, reseller api, vouchers
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

White-labelled reseller top-up fulfillment for WooCommerce: player validation, idempotent orders, signed
callbacks, draft catalog import, automatic wallet-balance monitoring, and scheduled catalog synchronization.

== Description ==

Connects paid WooCommerce products to a reseller top-up API (Shop2TopUp v1) and keeps that provider out of
everything your customers can see.

Highlights:

* White-label mode: inlined storefront assets, scrubbed catalog copy, side-loaded images, neutral SKUs,
  fulfillment metadata stripped from customer order views and emails, and the callback route hidden from the
  public REST index.
* Masked dashboard: fulfillment references and the callback URL are shown masked with an explicit reveal.
* Automatic wallet balance with a configurable interval, low-balance threshold, admin-bar readout, and an
  optional alert email.
* Catalog import that always saves products as drafts for review.
* Scheduled catalog synchronization of cost, requirement fields, and availability.
* Dynamic player, server, zone, and region fields from the provider catalog.
* Optional player-name validation before the customer pays.
* UUID idempotency keys saved before every upstream request.
* A fresh decimal-string price check immediately before purchase, with relative and absolute cost guards.
* Signed HMAC-SHA256 callback verification over the exact raw request body.
* Near-real-time WooCommerce status updates plus a rate-limit-safe batch fallback.
* Encrypted API credentials and voucher codes at rest (AES-256-GCM).
* WooCommerce HPOS support, Action Scheduler jobs, redacted logs, and WP-CLI diagnostics.

The API is live-only. Import creates drafts, but a paid order for a mapped product spends the reseller wallet.

== Installation ==

1. Back up the website and test on a staging copy first.
2. Install and activate WooCommerce.
3. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
4. Open WooCommerce > (your provider label) > Settings.
5. Enter the key ID, key secret, and callback signing secret.
6. Test the connection. The wallet balance starts refreshing automatically.
7. Copy the callback URL from the dashboard into the provider API access panel, then send a signed test.
8. Import products as drafts, or map an existing product in Product data > Top-Up.
9. Review exchange rate, markup, selling price, and cost guard, then publish.
10. Make one paid order using the smallest denomination before publishing broadly.

Never map the same WooCommerce product to two automatic supplier plugins. Doing so can deliver and charge twice.

== Secure wp-config.php configuration ==

The admin screen encrypts secrets using the WordPress authentication salts. Production sites can keep secrets
out of the database entirely:

`define( 'DELICAT_S2T_KEY_ID', 'your-key-id' );`

`define( 'DELICAT_S2T_KEY_SECRET', 'your-key-secret' );`

`define( 'DELICAT_S2T_WEBHOOK_SECRET', 'your-webhook-signing-secret' );`

`define( 'DELICAT_S2T_ENCRYPTION_KEY', 'a-stable-random-secret-of-at-least-32-characters' );`

The stable encryption key protects saved credentials and historical voucher codes from salt rotation. Set it
before entering credentials or processing voucher orders and do not rotate it without a data migration.

== Fulfillment behavior ==

Fulfillment starts only when WooCommerce reports the order as paid. One UUID is created per mapped line item
and is persisted before any network request. Network recovery always looks up that UUID before repeating a
create request.

Completion marks WooCommerce complete only when every order line is mapped and completed. Mixed
physical/digital orders retain WooCommerce control. Failed, refunded, partial, price-blocked, or ambiguous
cases move to on-hold for safe review; the plugin never performs an automatic payment refund.

Voucher codes are encrypted in order-item metadata and displayed only on completed orders to the order owner,
store managers, and the customer email template.

== WP-CLI ==

`wp delicat-s2t test`

`wp delicat-s2t balance --refresh`

`wp delicat-s2t reconcile`

`wp delicat-s2t sync --batches=5`

`wp delicat-s2t status`

`wp delicat-s2t webhook --rotate`

== Frequently Asked Questions ==

= Can customers tell which provider fulfills my orders? =

Not from your site, once white-label mode is on: storefront assets are inlined, imported copy and images are
scrubbed and re-hosted, internal metadata is stripped from customer order views and emails, SKUs use a neutral
prefix, and the callback route is hidden from the public REST index. The plugin folder and this readme still
name the provider on disk; rename the plugin folder if you want that closed too. See README.md for the full
list of limits.

= Does the balance really update on its own? =

Yes. A background job refreshes it on your interval, the dashboard polls it live while open, and it is
refreshed again right after each upstream order is created. A failed check keeps the last known value and shows
the error code.

= Are imported products published automatically? =

No. Imports are always saved as drafts. Re-imports are returned to draft as well unless you turn that off in
Settings.

= Does it support variable products? =

Yes. Map each variation to its own item and category id. The correct dynamic fields appear after the customer
chooses a variation.

= Does it auto-refund a customer when fulfillment fails? =

No. It places the WooCommerce order on hold and preserves both states for review. Refunds require a store
manager because payment gateways and business rules differ.

= What happens if a callback is missed? =

A once-per-minute Action Scheduler task reads pending UUIDs in batches of up to 50. This stays within the
documented batch limit and makes the callback an acceleration path rather than a single point of failure.

= Where are logs? =

WooCommerce > Status > Logs, source `delicat-shop2topup`. Secrets, player requirements, voucher codes, and
response bodies are not logged.

== Changelog ==

= 1.2.0 =

* White-label mode: inlined storefront assets, scrubbed catalog copy, side-loaded images, neutral SKU prefix,
  customer-facing metadata filtering, and the callback namespace hidden from the public REST index.
* Configurable provider label used everywhere in WordPress, including the menu and the plugins list.
* Masked dashboard identifiers with per-row reveal, plus a rebuilt dashboard with account, queue, automation
  health, and callback panels.
* Automatic wallet-balance refresh with a configurable interval, live dashboard polling, admin-bar readout,
  low-balance threshold, and optional alert email.
* Imports always save as drafts, with an option to return re-imports to draft.
* Scheduled catalog synchronization of cost, requirement fields, and availability, with withdrawn items set
  out of stock instead of deleted.
* New Orders and Tools screens, per-row re-sync, big-category catalog browsing, and a rotating private
  callback URL alongside the original one.
* Broader callback handling: every `order.*` event triggers an authoritative read, four signature header
  spellings are accepted, a missing timestamp no longer rejects the delivery, and unrelated events are
  acknowledged instead of rejected.
* New WP-CLI commands: `balance`, `sync`, `webhook`.
* Uninstall now also removes hashed order-note markers, plugin transients, and the new options.

= 1.1.0 =

* Correct unit-level webhook handling with asynchronous authoritative reconciliation.
* Protect cancelled/refunded orders, serialize settlement, and reject malformed vouchers.
* Correct empty requirements JSON, retry delays, and duplicate-job behavior.
* Preserve cost guards during import. See README.md for test evidence and limits.

= 1.0.0 =

* Initial integration.
