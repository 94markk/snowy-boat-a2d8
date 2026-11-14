## 2.8.3 — Custom package selection repair

- Fix custom packages present in variation data/cards but missing from the native WooCommerce attribute dropdown.
- Preserve exact local attribute labels (including spaces and plus signs), retain native options and keep WooCommerce availability checks.
- Restore custom labels on parent saves/import sync; invalidate variation caches after custom variation saves.
- Repair existing parents in bounded administrator-only batches. No supplier calls, no frontend writes, no forced variation IDs.
- Independent compatibility module works with an older shared package class and boots once across gateways.

## 2.8.2 — Package startup fix

- Fix PHP early class declaration causing the first-load class_exists guard to return before registering startup hooks.
- Register the shared bootstrap even when a legacy gateway owns the shared class; retain one-time boot protection.
- Add fresh-process regression tests covering normal loads, duplicate includes and legacy-first/legacy-last load order.
- Add an independent labeled Forfaits personnalisés editor box and top-of-product entry with version display. The WooCommerce tab links to the form without duplicate inputs.

## 2.8.1 — Product editor access

- Add Forfaits personnalisés directly to the variable product data tabs, with inline creation on Update and an existing-package list.
- Add a product sidebar shortcut to the standalone package manager.
- Keep the new editor independent of the shared module load order, including when Digital Gateway 4.23.0 is active.
- Nonce/capability checks; unrelated product saves do not create packages. Delivery behavior is unchanged.

## 2.8.0 — Forfaits personnalisés

- Product admin editor for custom direct-top-up package recipes and manual retail prices.
- Split classic checkout packages into native delivery lines before payment, with individual tracking and existing payment/idempotency guards.
- Shared module boots once; virtual source mappings protect importer identity and custom prices; stock checks cover repeated components.
- Explicit safeguards for stale recipes and unsupported checkout paths. See CUSTOM-PACKAGES.md for compatibility and validation.

# Changelog

## 2.7.4 — 2026-10-24
- Message « autre serveur » : il nomme uniquement le serveur du compte du joueur (champs account_region, actual_region, player_region, actual_server, account_server), jamais la région du produit demandé. Avant, un client déjà dans la bonne rubrique pouvait lire qu’il devait aller… dans cette même rubrique.

## 2.6.2 — Non-blocking WooCommerce product updates

- Removed supplier HTTP calls from the WooCommerce product/variation save request so **Mettre à jour / Update** responds immediately even on large variable products.
- Product mapping metadata is saved first, then requirements and supplier cost are refreshed in a background Action Scheduler/WP-Cron task.
- Queued refreshes re-check that the product is still mapped before contacting the supplier and never change the merchant's WooCommerce stock-management choice.
- Successful background refreshes clear stale synchronization-error metadata; failed background refreshes remain isolated from the product editor.

## 2.6.1 — Premium Material-style product fields

- Redesigned the storefront game-account block with a lighter, more modern mobile-first card layout.
- Added dependency-free inline Material-style SVG glyphs for the account title, UID/player field, region/server/zone fields, history, clear action, verification action and verification states.
- Removed the large WooCommerce/payment explanatory notice from the product form and keeps the initial verification status hidden until the customer performs an action.
- Upgraded the recent-ID picker, field focus states and verification button without changing payment authority, supplier mapping, fulfillment, encryption or webhook behavior.
- Reworked verification-result rendering to use DOM text nodes instead of HTML interpolation for player metadata.

## 2.6.0 — Instant signed synchronization + WooCommerce payment attestation

- Applies valid HMAC-signed `order.*` webhook payloads immediately to the matching WooCommerce fulfillment row instead of waiting for a second supplier status API call.
- Keeps API status reads as bounded recovery only; non-terminal orders schedule a 20-second fallback, within the documented per-order status rate limit.
- Adds robust HTTP 201 order-response normalization for live envelope variants while still binding every response to the persisted UUID idempotency key.
- Adds a cryptographic WooCommerce payment attestation (HMAC-SHA256) over order/customer/amount/currency/transaction and mapped fulfillment intent. Post-payment changes fail closed before supplier balance can be spent.
- Keeps HMAC-SHA256 verification over the exact raw webhook body and AES-256-GCM encryption at rest for credentials, vouchers and contextual customer data.

# 2.6.0 — Live price-response compatibility and purchase recovery
- Fixed the exact HTTP 200 `missing_price` failure seen on `/catalog/subcategory/:id/price` when the reseller API returns the successful price object directly inside `data`, `result`, `payload`, or at the top level instead of under a nested `price` object.
- Added strict normalization for flat account/order response objects without weakening `success` or identity/status validation.
- Added a safe catalog-price fallback only for temporary/structural price failures; `expected_unit_price` remains a hard supplier-side ceiling and WooCommerce payment approval remains mandatory before supplier submission.
- Clears the stale invalid-response dashboard banner after the same endpoint succeeds.
- Existing paid queued/intended orders keep their original UUID and can recover through Reconcile/Retry without creating a second supplier purchase.

# 2.4.8 — WooCommerce-first payment authority and fulfillment recovery

- WooCommerce is now the explicit payment authority. Supplier fulfillment is released only after `woocommerce_payment_complete` or durable WooCommerce paid-date evidence. A manual `processing` status alone cannot trigger a supplier purchase.
- Supplier/API outages no longer block add-to-cart or checkout in the default WooCommerce-first mode. Product fields are validated locally; the supplier is contacted only by the optional “Vérifier votre compte” action or after WooCommerce approves payment.
- Successful storefront player verification now returns a short-lived HMAC-signed token, so the verified player name can travel into the cart/order without a second supplier call. Tampered or stale tokens are ignored.
- If a player was not verified before checkout, player validation runs after payment and before `/orders/create`. Invalid player details pause fulfillment without charging the supplier wallet again.
- A malformed/transient live price response can fall back to the last known supplier cost only as `expected_unit_price`, which remains a supplier-enforced maximum. This allows safe progress without disabling price protection.
- `PRICE_INCREASED` now uses the documented `current_unit_price` from the error details for the next guarded retry with the same UUID, instead of looping indefinitely on a broken price endpoint.
- Documented temporary states such as `OUT_OF_STOCK`, `PRODUCT_UNAVAILABLE`, and `PRICING_UPDATING`, plus future errors marked `retryable=true`, remain in recovery instead of becoming permanent failures.
- Private URL registration, strict signed webhooks, Action Scheduler/WP-Cron fallback, idempotent UUID recovery, catalog synchronization, HTG retail-price separation, and cart de-duplication remain intact.
- Regression suite expanded to 210 behavioral tests. See AUDIT-2.6.0.md.

# 2.4.6

Recovery retry bounds, safe API response compatibility and diagnostics, accepted-order safeguards, and mobile table scrolling. See UPDATE-2.4.6.md for validation limitations and setup steps.

## 2.4.5 — Fulfillment invalid-response recovery
- Fixed preflight price failures being shown as an uncertain supplier submission even though no create-order POST had been sent.
- Price lookups now retry short-lived malformed/transient responses safely before pausing fulfillment.
- Preflight failures now increment Attempts so they cannot loop forever at 0 attempts.
- Create-order transport/invalid-response failures immediately reconcile by UUID before any later retry, preserving idempotency.
- Order requirements always include canonical player_id when a UID alias is present.
- Added safe response diagnostics (HTTP/content type/size/hash only; no credentials or raw body).

# Changelog

## 2.4.5 — UID field storefront visibility
- Fixed grouped variable products showing no UID / Player ID field until after a package variation was selected.
- Treats the documented `player_id` as a fixed direct-top-up field even when the category requirements endpoint returns no additional fields.
- Keeps vouchers free of irrelevant player-account inputs.
- Preserves matching account values while switching package variations and restores the common account form after variation reset.
- Added Delicat Builder native-product spacing compatibility.

## 2.4.0 — UID / Player ID verification
- Treats `uid`, `user_id`, `userid`, and `playerid` requirement fields as player identifiers in addition to `player_id`.
- Sends the canonical `player_id` required by SHOP2TOPUP player validation while preserving the supplier's original dynamic UID field.
- Labels player-account inputs as **UID / Player ID** and adds clear verification guidance on product pages.
- Player validation now runs for UID aliases before an item can be added to the cart.
- Last-five player history now uses the same UID alias detection and keeps server/zone/region companions.
- Improves customer-facing verification errors without exposing supplier details.

## 2.3.0
- Added grouped catalog import: one supplier game/service becomes a WooCommerce variable product and supplier denominations/packages become mapped variations.
- Added Manual WooCommerce Pricing as the protected default for HTG/store prices; supplier USD cost remains separate and continues to refresh.
- Added per-item selling-price inputs in the Catalogue screen so HTG prices can be set during import.
- Manual pricing now hard-blocks background selling-price rewrites even if an old price-sync flag was left enabled.
- Added configurable variation selector label (Package/Amount/Diamonds/etc.).
- Re-import merges new packages into an existing variable parent and preserves existing variation labels/prices.
- Older draft simple imports are marked as superseded and hidden from catalog when the equivalent grouped variation is created.
- Variation admin mapping now displays supplier USD cost as read-only and explicitly separates it from the WooCommerce retail price.
- Added regression coverage for grouped variable imports and independent manual retail vs supplier cost storage.

## 2.2.0
- Fixed catalog discovery for reseller accounts that return empty category indexes while the unfiltered product endpoint is populated.
- Added compatibility parsing for documented catalog payloads and harmless nested response envelopes.
- Added unfiltered-product fallback and local category derivation/filtering.
- Added a one-click full supplier catalog import that always creates new WooCommerce products as drafts.
- Added browser-driven authenticated import batches, with Action Scheduler/WP-Cron retained as fallback, so imports cannot remain queued on hosts with blocked cron.
- `NO_REQUIREMENTS_FOUND` no longer aborts an entire category import; products are imported as drafts and schema refresh can retry later.
- Preserves Player ID history, supplier privacy, stock synchronization, pricing controls, signed webhooks, and idempotent fulfillment from 2.1.0.

## 2.1.0

- Rebuilt catalog import as durable small background batches instead of one long synchronous admin request.
- Added import-selected and import-entire-category flows with live progress and isolated per-item failures.
- Updated catalog handling to the current `product_type` model and provider pagination envelope.
- Added safer stock synchronization: explicit zero/unavailable blocks purchase; omitted stock is never interpreted as zero; reappearing products recover from stale plugin-owned outage stock.
- Added automatic catalog synchronization every 15 minutes by default, with the interval still configurable.
- Added encrypted last-five Player ID history per signed-in customer and game/category, including associated server/region/zone fields.
- Added context-bound vault encryption for customer identity history and a customer clear-history action.
- Preserved normal provider Player ID validation, supplier privacy, draft imports, idempotent fulfillment, cost guards, signed webhooks, reconciliation, balance monitoring, voucher encryption, and HPOS support.
- Extended uninstall cleanup for catalog import jobs, stock state, feature migration state, and encrypted Player ID history.

## 2.0.0

- Rebuilt authentication around the official v1 contract.
- Removed undocumented bearer/header fallback modes.
- Added strict `keyId.secret` parser and encrypted storage.
- Rebuilt webhook verification around exact raw-body HMAC-SHA256.
- Invalid signatures now return HTTP 401 and are never treated as successful tests.
- Added safe, narrow registration-probe handling for the private callback URL.
- Signed webhook payloads settle orders immediately without an extra status call.
- Preserved idempotent order creation, reconciliation, balance monitoring, draft import, stock/catalog sync, dynamic player fields, and supplier privacy.
