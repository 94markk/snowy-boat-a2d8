=== Delicat Top-Up Connector ===
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later

Sell game top-ups, vouchers and gift cards from your wholesale supplier
through WooCommerce, with signed API access, automatic catalog/order
synchronization, and no supplier branding visible to customers.

== Description ==

* Signed (HMAC-SHA256) supplier API access, server-side only.
* Import: one virtual variable WooCommerce product per game, one variation per
  package. New products start as Draft for review.
* Player-ID fields + Check-ID on the product page, carried through checkout.
* Automatic fulfillment when the WooCommerce order is paid, with a per-item
  lock so an order is never placed twice.
* Automatic synchronization (Top-Up -> Settings -> Sync):
  - order webhooks update WooCommerce instantly;
  - every placed order that is not final is re-checked every minute;
  - imported products (prices, stock, packages, fields) are refreshed in the
    background every 1-60 minutes, and instantly on supplier product webhooks.
* Supplier name hidden from visitors: neutral plugin folder, private webhook
  URL hidden from the REST index, inline front-end assets, local product
  images with neutral file names, scrubbed customer-facing messages.

== Installation / upgrade from the supplier-named build ==

1. Deactivate (do NOT delete yet) the old "FlashTopup Reseller" plugin.
2. Plugins -> Add New -> Upload Plugin -> `delicat-topup-connector.zip` ->
   Install -> Activate. Settings, imported products and orders are kept.
3. Delete the old plugin. If WordPress asks, do NOT tick any "delete data" option.
4. Top-Up -> Settings -> Webhook: copy the new private URL into the supplier
   panel's callback_url, then untick "Also accept the old URL".
5. Top-Up -> Settings -> Sync: add the suggested server cron job for exact timing.

== Changelog ==

= 1.8.0 =
* SECURITY: the storefront shortcode's direct-order AJAX endpoint was open to
  anonymous visitors, letting anyone place supplier orders that debit the
  wallet without paying. Direct orders are now administrator-only; visitors
  are sent to the WooCommerce product page and pay at checkout.
* FIX: saving one settings tab reset the checkboxes of the other tabs (saving
  Connection turned off order auto-completion; saving WooCommerce turned off
  Check-ID and Sandbox). Each tab now only saves its own fields.
* FIX: the "Connection failed" reason was unreadable on the dark header. It
  is now shown clearly with the HTTP status / error code and hints (API keys,
  IP whitelist, server clock).
* FIX: API responses without an explicit success flag were reported as
  failures; any 2xx is now success unless the body reports an error.
* FIX: a failing catalog page no longer yields a silently partial catalog.
* FIX: stock flags sent as "false"/"0"/"out_of_stock" were treated as in stock.
* FIX: concurrent payment hooks could place the same supplier order twice.
* FIX: webhook signatures failed on sites with plain permalinks; nonces are
  only consumed after a valid signature.
* FIX: failed supplier orders were re-polled every 5 minutes forever.
* NEW: automatic catalog sync, one-minute pending-order sweep (bulk status
  endpoint), catalog webhooks, faster reconciliation back-off, Sync tab.
* NEW: supplier name hidden from visitors and scanners (see Description).

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
* Runs the existing safe repair pass after upgrade so provider-completed supplier orders can leave WooCommerce Processing automatically.
* Keeps the setting configurable for stores that require manual fulfillment review.
* Repairs incomplete settings rows during activation without overwriting other saved settings.

= 1.6.7 =
* Adds explicit TeraWallet compatibility using its stable WooCommerce gateway ID `wallet`, even when the visible name is changed to Delicat Wallet.
* Runs a late status pass after TeraWallet changes an order to Processing/On-hold.
* Adds a delayed Action Scheduler/WP-Cron verification to prevent wallet or object-cache races from reverting a provider-completed order to Processing.
* Keeps provider status authoritative: WooCommerce completes only after every supplier item is confirmed completed.


= 1.6.6 =
* Fixes WooCommerce orders remaining Processing after supplier already reports Completed.
* Treats WooCommerce Processing as payment confirmation for wallet gateways that do not immediately populate `date_paid`.
* Adds a late payment/status synchronization hook plus immediate completion after provider item updates.
* Adds a one-time safe repair for existing stuck Processing orders whose supplier line items are already completed.
* Improves HPOS/object-cache persistence verification and variation-aware supplier item detection.
* Keeps Delicat App order timelines synchronized with provider status changes.

= 1.6.0 =
* **Full-catalog import (bug fix)** — the importer used to fetch only the
  first page (200 products), silently truncating large catalogs. It now walks
  every page (per_page 500, following the API's `has_more` hint) so every
  product is available to import.
* **Refreshed storefront UI** for the `[topup_store]` shortcode:
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
* **Inbound webhook receiver** at `/wp-json/<supplier>/v1/callback` with
  HMAC + timestamp + nonce verification. Updates the matching WooCommerce
  order status, records provider order id + delivery codes on the line
  item, and writes an order note.
* **Conversion rate + display currency code** — apply USD→local currency
  conversion to all imported prices in WooCommerce.
* **Tabbed admin UI** (Connection / WooCommerce / Webhook / Diagnostics)
  with a status pill, copyable webhook URL, environment table, and a
  webhook event log.
* **Order auto-completion** toggle — automatically mark WC orders Completed
  when the supplier webhook reports Completed.
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
  `[topup_store]` shortcode.


= 1.6.3 =
* Synchronizes imported products with Delicat Secure Keys using an explicit `api_topup` delivery type.
* Prevents supplier Free Fire Player-ID products from being treated as stored PIN products.
* Clears PIN/key redirect flags during every catalog import for parent products and variations.
* Strengthens WooCommerce auto-completion: mixed orders are never completed by supplier alone.

= 1.6.2 =
* Synchronizes WooCommerce order completion with confirmed supplier server status.
* Completes immediately when the placement response is already completed.
* Adds secure server-side status reconciliation when a webhook is delayed or blocked.
* Supports completed/success/delivered/fulfilled status aliases and wrapped webhook payloads.
* Prevents mixed or multi-item orders from completing until every supplier line item is completed.

= 1.6.4 =
* Treat provider Validated/Approved/Confirmed responses as final completed delivery.
* Read status safely from nested API/webhook response shapes.
* Reload WooCommerce orders after item status writes for HPOS/object-cache compatibility.
* Complete only paid Processing/On-hold orders after every supplier item is final.
