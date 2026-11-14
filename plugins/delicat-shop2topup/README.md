# Delicat Shop2TopUp v2.6.1 — High-throughput instant fulfillment

This release uses a three-path fulfillment architecture: a detached single-use HTTPS loopback starts paid lines immediately, FastCGI post-response work provides a second local path when available, and Action Scheduler remains only the durable fallback. Signed supplier webhooks still settle completed orders synchronously. Supplier-side `pending` time cannot be eliminated locally, but WordPress queue starvation is removed from the normal path.

## Update 2.6.1 — modern product fields

The customer-facing game-account form now uses a premium Material-style UI with local inline SVG glyphs (no external font/CDN dependency). The large payment explanation banner has been removed from the product page; verification feedback appears only when the customer clicks **Vérifier votre compte**. This is a presentation-only update: WooCommerce payment approval, HMAC attestation, encrypted storage, idempotency and fast-lane fulfillment are unchanged.

## Update 2.6.1 — purchase-response hotfix

## v2.6.1 instant synchronization

This build adopts the low-latency pattern used in Delicat Digital Gateway while respecting SHOP2TOPUP's own contract:

- WooCommerce remains the payment authority. Supplier fulfillment is released only after `woocommerce_payment_complete`.
- A payment-complete HMAC attestation binds the exact paid order facts and mapped recipient data; post-payment edits fail closed.
- A valid SHOP2TOPUP callback is verified over the **raw request body** with HMAC-SHA256 and its signed `data` order object is applied immediately. No scheduler or second API read is required for the normal completion path.
- Status API reads are recovery only. A non-terminal order gets a bounded 20-second fallback check, while the signed webhook remains the primary real-time path.
- `/orders/create` accepts the documented response plus safe live envelope variants, but every response must still match the UUID persisted before submission.
- Secrets and voucher/customer data continue to use authenticated AES-256-GCM encryption at rest.


Start with INSTALLATION-2.6.1.md. Audit findings and verified checks are in AUDIT-2.6.1.md.

# Delicat Top-Up Fulfillment for WooCommerce — v2.4.5

A white-label WooCommerce integration for the SHOP2TOPUP Reseller API v1. Version 2.3 adds grouped WooCommerce variation imports and protected manual HTG/store pricing while preserving supplier USD cost, stock, requirements, webhooks, and fulfillment synchronization.

## v2.4.5 storefront UID visibility fix

- Direct top-up items always expose **UID / Player ID** because `player_id` is a fixed player-validation field even when the category has no additional dynamic requirements.
- Grouped variable products render the common account fields immediately, before a package is selected.
- Switching package variations preserves typed UID/server/region values when the field keys are still valid.
- Resetting the variation selector restores the default account form instead of removing it.
- Voucher/code products still do not receive Player ID fields.


## API contract used

- Base URL: `https://shop2topup.com/api/endpoints/v1`
- Authentication: `Authorization: Bearer <keyId>.<secret>`
- Account/balance: `GET /account`
- Catalog: big categories → categories → subcategories
- Requirements: `GET /catalog/category/:categoryId/requirements`
- Player validation: `POST /player/validate`
- Price: `GET /catalog/subcategory/:itemId/price`
- Create order: `POST /orders/create`
- Status: `GET /orders/:orderId`
- Batch reconciliation: `POST /orders/batch`
- Webhook signature: `X-Shop2Topup-Signature: sha256=<hex>` over the exact raw request body using HMAC-SHA256.

## v2.3 grouped variations + protected HTG pricing

- Recommended import structure is now **Variable product**: one game/service parent with each supplier denomination/package as a WooCommerce variation.
- The variation carries the exact supplier item ID, category ID, requirement schema, product type, stock snapshot, and cached USD cost used for fulfillment.
- The variable parent remains a WooCommerce draft until reviewed; importing additional supplier items later merges them into the same parent instead of creating duplicate product cards.
- **Manual WooCommerce Pricing** is the default. Supplier USD cost and customer retail price are separate values. Supplier sync refreshes cost/stock/requirements without rewriting a manual retail price.
- The Catalogue table has an independent selling-price field per supplier item. When WooCommerce currency is HTG, enter the customer price directly in gourdes before import.
- Existing manual WooCommerce variation prices are preserved on re-import. Older draft simple imports are marked as superseded when their mapped variation is created.
- Automatic USD conversion + markup remains available as an opt-in mode; selling-price synchronization only runs in that automatic mode.
- Variation selector label is configurable (for example Package, Amount, Diamonds, Points).

## v2.2 catalog rebuild

- Catalog browsing follows the current three-level provider structure and uses `product_type` (`topup` / `voucher`) as the primary type field. If the provider returns an empty category index, the plugin derives categories from the documented unfiltered subcategory/product feed instead of showing an unusable empty selector.
- Imports run as resumable small batches. While the catalog page is open, authenticated AJAX actively advances the queue; Action Scheduler/WP-Cron remains a fallback. This avoids the "queued forever" problem on hosts where loopback cron is blocked.
- Import selected products, an entire category, or the complete supplier catalog in one click.
- One failed item no longer cancels the rest of an import; progress and per-item failures are retained for review.
- New products are Draft. Re-imported products also return to Draft by default so price/content changes can be reviewed before publishing.
- Provider pagination is followed when the API returns a pagination envelope.
- Requirement fields are fetched once per category batch and attached to top-up products; voucher products do not receive irrelevant Player ID fields. `NO_REQUIREMENTS_FOUND` no longer aborts the import: the product is kept as a draft and a later sync can retry the schema.
- Product cost, type, requirements, provider availability, and supplier withdrawal/reappearance state are synchronized.
- A confirmed supplier quantity of zero or explicit unavailable status blocks purchase. Missing/blank stock data is never guessed to mean zero.
- When a previously withdrawn product reappears without a numeric stock value, stale plugin-owned zero stock is released so the product can become purchasable again.
- Automatic catalog synchronization defaults to every 15 minutes and remains configurable/disableable by the store manager.

## Digital Gateway features added

- Signed-in customers can reuse their five most recent Player ID combinations for each game/category.
- Player ID history stores the full relevant tuple (for example Player ID + server/region/zone), not only the main ID.
- Saved identities are encrypted with context-bound AES-256-GCM storage and are never embedded in cacheable storefront HTML.
- Selecting a saved identity fills the matching current requirement fields, but normal provider Player ID validation still runs before purchase where enabled.
- Customers can clear their saved Player ID history.
- Supplier details remain private from customer-facing product/order output.
- Existing idempotent UUID fulfillment, strict webhook HMAC verification, queued signed-callback reconciliation, scheduled reconciliation, balance monitoring, cost guards, voucher encryption, HPOS support, and redacted logging remain active.

## Important: IP allowlist

If the dashboard reports `IP_NOT_ALLOWED`, SHOP2TOPUP is rejecting requests from the WordPress server's outbound public IP. Catalog, balance, validation, and order endpoints cannot work until that IP is allowed in the reseller panel (or "Allow All IP Addresses" is enabled temporarily for testing). The plugin does not and should not bypass the provider's allowlist.

## Upgrade / setup

1. Back up the site, replace the old plugin with v2.4.5, and activate it.
2. Keep the complete `keyId.secret` credential configured and confirm **Test connection** succeeds.
3. Resolve any `IP_NOT_ALLOWED` error before attempting a catalog import.
4. In Settings choose **Variable product** + **Manual WooCommerce pricing**. If customers pay in gourdes, confirm the WooCommerce store currency is HTG.
5. Open **Catalogue**, choose a category, enter the customer-facing HTG price for the desired packages, and import them. Use **Import full catalog as drafts** only when you are comfortable pricing the new variations afterward.
6. Leave the Catalogue page open for the fastest import because the browser actively advances each authenticated batch. If you navigate away, Action Scheduler/WP-Cron continues as a fallback when available.
7. Review imported draft variable products and publish only after checking every variation price, required player fields, and availability.
8. Keep the private callback URL and webhook HMAC secret configured, then send a signed provider test.

Never send API credentials, webhook HMAC secrets, voucher codes, or customer Player IDs through chat or expose them in storefront JavaScript/HTML.


UID / Player ID verification: imported player-account fields named player_id, uid, user_id, userid, or playerid are recognized as the same player identifier for pre-purchase validation.
