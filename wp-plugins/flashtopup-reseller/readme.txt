=== FlashTopup Reseller ===
Contributors: flashtopup
Tags: game topup, gift cards, reseller, woocommerce, vouchers, flashtopup
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.7.1
License: GPLv2 or later

Sell FlashTopup game top-ups, vouchers and gift cards through WooCommerce
with HMAC-signed API access, auto-import, conversion-rate display, and a
verified inbound webhook receiver.

== Description ==

* Connect with your reseller API ID + secret (HMAC-SHA256 signed,
  server-side only — the secret never reaches the browser).
* One-click product import that creates one **virtual variable WooCommerce
  product per game** with one **variation per package**, grouped by game
  category. No shortcode required.
* Auto-generated **order fields** (text + select) and Check-ID button on the
  WooCommerce product page. Required fields are validated on add-to-cart and
  carried through cart → checkout.
* **Conversion rate + markup**. Apply a USD→local-currency multiplier and an
  optional sell markup. Wallet is still debited in USD at the wholesale
  rate; the customer sees the local price.
* **Inbound webhook receiver** at
  `/wp-json/flashtopup/v1/callback`. The URL is shown on the plugin's
  Webhook tab — paste it into your FlashTopup reseller account's
  `callback_url`. Every event is verified with HMAC-SHA256, a ±5 minute
  timestamp window, and a single-use nonce before applying.
* **Auto-completion**: when the webhook reports a completed order, the
  matching WooCommerce order is moved to `Completed` automatically. This is enabled by default and remains configurable.
* **Webhook event log** kept on the plugin's Webhook tab for auditing.
* **Diagnostics tab** with environment info + a one-click import self-test
  that creates a synthetic product (no API call) so you can verify
  WooCommerce variation creation works on this server.
* **Sandbox mode** for safe testing (no wallet charge, no provider call).

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `flashtopup-reseller.zip` →
   Install → Activate. (WooCommerce must be active for the full flow.)
2. Go to **FlashTopup → Settings → Connection** and paste your API ID +
   Secret. Save.
3. On the **WooCommerce** tab, set your conversion rate (e.g. `134.50` for
   USD→NPR), display currency code (e.g. `NPR`), and an optional sell
   markup.
4. On the **Webhook** tab, copy the displayed URL and paste it into your
   FlashTopup reseller account's `callback_url` field.
5. Go to **Import Products** and import the games you want to sell.

== WooCommerce ==

Each imported game becomes one virtual variable product. Each package
becomes a variation under a single "Package" attribute. When the
WooCommerce order reaches Processing or Completed, the plugin calls the
FlashTopup Reseller API to place the upstream order, then records the
order id + status + delivery codes against the line item. The inbound
webhook keeps the WC order status in sync with the upstream provider.

== Changelog ==

= 1.7.1 =
* New WooCommerce products created by Import or Synchronize are saved as **Draft** first for administrator review.
* Synchronizing an existing product now preserves its current WooCommerce publication status instead of forcing it back to Published.
* Existing product/variation mapping, supplier ID repair, pricing, stock and field synchronization behavior is unchanged.

= 1.7.0 =
* Adds a dedicated **Synchronize imported products** action that refreshes the current supplier catalog without recreating matched WooCommerce products.
* Persists the supplier `product_id` on WooCommerce parent products and variations and automatically repairs changed supplier product IDs/codes.
* Reconciles changed package/service codes by unique package name to avoid duplicate variations when the supplier rotates service identifiers.
* Fully refreshes supplier prices, stock, fields, validation settings, max quantity and package metadata.
* Supplier-removed packages are safely marked out of stock and flagged as orphaned instead of remaining purchasable; the variation record is retained for historical order integrity.
* Uses a uniqueness-guarded name + product-type fallback only when both code and stored supplier ID no longer match, reducing accidental remaps.

= 1.6.8 =
* Enables Order auto-completion by default on new and existing installations.
* Runs the existing safe repair pass after upgrade so provider-completed FlashTopup orders can leave WooCommerce Processing automatically.
* Keeps the setting configurable for stores that require manual fulfillment review.
* Repairs incomplete settings rows during activation without overwriting other saved settings.

= 1.6.7 =
* Adds explicit TeraWallet compatibility using its stable WooCommerce gateway ID `wallet`, even when the visible name is changed to Delicat Wallet.
* Runs a late status pass after TeraWallet changes an order to Processing/On-hold.
* Adds a delayed Action Scheduler/WP-Cron verification to prevent wallet or object-cache races from reverting a provider-completed order to Processing.
* Keeps provider status authoritative: WooCommerce completes only after every FlashTopup item is confirmed completed.


= 1.6.6 =
* Fixes WooCommerce orders remaining Processing after FlashTopup already reports Completed.
* Treats WooCommerce Processing as payment confirmation for wallet gateways that do not immediately populate `date_paid`.
* Adds a late payment/status synchronization hook plus immediate completion after provider item updates.
* Adds a one-time safe repair for existing stuck Processing orders whose FlashTopup line items are already completed.
* Improves HPOS/object-cache persistence verification and variation-aware FlashTopup item detection.
* Keeps Delicat App order timelines synchronized with provider status changes.

= 1.6.0 =
* **Full-catalog import (bug fix)** — the importer used to fetch only the
  first page (200 products), silently truncating large catalogs. It now walks
  every page (per_page 500, following the API's `has_more` hint) so every
  product is available to import.
* **Refreshed storefront UI** for the `[flashtopup_reseller]` shortcode:
  searchable product grid with a live result count, polished modal with a
  quantity stepper (clamped to each package's max), an order-success card
  showing the order id + reference with copy buttons, and an automatic dark
  theme via `prefers-color-scheme`.
* Storefront prices now apply your conversion rate + display currency (to
  match the WooCommerce import), not just the raw USD price.
* **Refreshed admin UI** — brand-aligned hero, tabs, cards, status pills, and
  fully-styled balance / self-test / import-progress dialogs.
* New signed API client helpers: `all_products()` (paginated catalog) and
  `order_status_bulk()` (the `orders/status` endpoint, up to 50 ids at once).

= 1.5.0 =
* **Inbound webhook receiver** at `/wp-json/flashtopup/v1/callback` with
  HMAC + timestamp + nonce verification. Updates the matching WooCommerce
  order status, records provider order id + delivery codes on the line
  item, and writes an order note.
* **Conversion rate + display currency code** — apply USD→local currency
  conversion to all imported prices in WooCommerce.
* **Tabbed admin UI** (Connection / WooCommerce / Webhook / Diagnostics)
  with a status pill, copyable webhook URL, environment table, and a
  webhook event log.
* **Order auto-completion** toggle — automatically mark WC orders Completed
  when the FlashTopup webhook reports Completed.
* Code optimization: tighter null-safe access, less branching in admin
  save, cleaner CSS using brand-gradient tabs.

= 1.4.1 =
* Hardened variable-product creation: explicit custom-attribute id, and the
  product is verified to be retrievable after save. Self-test live-API
  probe also reports a sample product's package count.

= 1.4.0 =
* Added System Status box + import self-test diagnostic on the Import page.

= 1.3.0 =
* Game-by-game AJAX import with a progress window + per-game error log,
  fault-tolerant skipping, WooCommerce logger integration, balance popup,
  Settings link on the Plugins page.

= 1.2.0 =
* Each game becomes a virtual VARIABLE product, each package a VARIATION.

= 1.1.0 =
* Full WooCommerce support (no shortcode required); products carry order
  fields + Check-ID; automatic fulfillment on payment.

= 1.0.0 =
* Initial release: settings, product import with auto order fields,
  Check-ID, signed order placement, Sandbox mode,
  `[flashtopup_reseller]` shortcode.


= 1.6.3 =
* Synchronizes imported products with Delicat Secure Keys using an explicit `api_topup` delivery type.
* Prevents FlashTopup Free Fire Player-ID products from being treated as stored PIN products.
* Clears PIN/key redirect flags during every catalog import for parent products and variations.
* Strengthens WooCommerce auto-completion: mixed orders are never completed by FlashTopup alone.

= 1.6.2 =
* Synchronizes WooCommerce order completion with confirmed FlashTopup server status.
* Completes immediately when the placement response is already completed.
* Adds secure server-side status reconciliation when a webhook is delayed or blocked.
* Supports completed/success/delivered/fulfilled status aliases and wrapped webhook payloads.
* Prevents mixed or multi-item orders from completing until every FlashTopup line item is completed.

= 1.6.4 =
* Treat provider Validated/Approved/Confirmed responses as final completed delivery.
* Read status safely from nested API/webhook response shapes.
* Reload WooCommerce orders after item status writes for HPOS/object-cache compatibility.
* Complete only paid Processing/On-hold orders after every FlashTopup item is final.
