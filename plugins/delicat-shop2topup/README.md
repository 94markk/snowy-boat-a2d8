# Delicat – Top-Up Fulfillment for WooCommerce — 2.10.0

White-label WooCommerce integration for the SHOP2TOPUP Reseller API v1, built for delicastoreha.com: signed webhooks, idempotent supplier orders, player validation, grouped variation catalog import, protected manual HTG pricing, balance monitoring, catalog/stock synchronization and vouchers encrypted at rest. WooCommerce stays the authority for payments and prices; the plugin only spends supplier balance for paid, attested order lines.

Release history: `CHANGELOG.md`. Custom packages (Forfaits personnalisés): `CUSTOM-PACKAGES.md`. WordPress.org-style summary: `readme.txt`.

## How a paid order is fulfilled

1. **Payment authority.** `woocommerce_payment_complete` records `_dst2t_wc_payment_approved` and an HMAC-SHA256 attestation over the order, customer, total, currency, transaction and every mapped line (supplier item, quantity, recipient fields). An edit after payment invalidates it and fulfillment fails closed. A manual switch to *processing* is not a payment; only legacy orders that carry WooCommerce's paid date are accepted without an attestation.
2. **Idempotency.** One UUID per mapped line is stored (order item meta and `wp_dst2t_orders`) before any supplier request. Timeouts, retries and operator *Retry* always look that UUID up first; a line is never bought twice.
3. **Fast lanes.** After the customer's response is flushed (`fastcgi_finish_request()` on PHP-FPM, `litespeed_finish_request()` on LiteSpeed/Hostinger), the same PHP worker submits up to two paid lines and re-reads their status twice in the first minute. Extra lines get a single-use detached worker (`admin-ajax.php?action=dst2t_fast_fulfill`, 256-bit token). Action Scheduler is the durable fallback; order jobs run at priority 0, before catalog and balance work.
4. **Status.** A valid signed webhook settles the line immediately. If it loses the race for the order lock, it is settled right after its own 200 response instead of waiting for WP-Cron; the queued canonical re-read stays as fallback. Without webhooks, status reads keep the documented cadence (3 reads per 60 s per order) and a batch reconciliation runs every minute.
5. **Price protection.** The live price is checked against the product's absolute maximum and the relative guard (*Maximum cost increase*, compared with the cached cost) before every purchase, and sent as `expected_unit_price`, which the supplier enforces. When the price endpoint is temporarily broken, the cached (or catalog) cost is used as that maximum, after the same guards. `PRICE_INCREASED` retries with the reported price once it passes the guards. Temporary supplier states (`OUT_OF_STOCK`, `PRODUCT_UNAVAILABLE`, `PRICING_UPDATING`, rate limits, 5xx, `retryable=true`) stay in recovery; anything else pauses the order on review without refunding automatically.
6. **Completion.** When every mapped line is delivered, WooCommerce payment is confirmed and no unmapped line controls the order, the order is completed (setting *Complete WooCommerce orders automatically*). Cancelled and refunded orders are never changed back by a late supplier event.

## Player validation

* The **Vérifier votre compte** button (`admin-ajax.php?action=dst2t_verify_player`) checks the UID with the supplier and returns a signed token valid 15 minutes; the verified name then travels into the cart without a second lookup. Editing a field invalidates it.
* Fields named `player_id`, `uid`, `user_id`, `userid` or `playerid` are the same identifier; `player_id` is always sent. Direct top-ups always show a UID field, vouchers never do.
* In the default WooCommerce-first mode a supplier outage never blocks add-to-cart or checkout: unverified lines are validated after payment, before the supplier order.
* A supplier answer for another account is rejected (`PLAYER_ID_MISMATCH`; IDs compared as strings, Free Fire UIDs exceed 32 bits). For Free Fire, a confirmed account region different from the product's (LATAM and NA are both *Americas*; Brazil and Europe are distinct) is refused, and the message names only the server the account is on. Unknown or ambiguous regions are not treated as a mismatch.
* The nickname shown is the one the supplier returns. If it is outdated the supplier must refresh its lookup; the plugin does not substitute names.
* Throttles: 20 checks per visitor and 300 per address per 5 minutes, and 120 per minute for the whole site (the address comes from forwarding headers a client can forge; the site ceiling protects the supplier key's own rate limit). Store managers are exempt.
* Product pages stay in the LiteSpeed cache longer than a WordPress nonce lives. When the server rejects the page's nonce, the script fetches a fresh one (`dst2t_verify_nonce`) and retries once.
* Signed-in customers can reuse their last five UID/server combinations per game: encrypted per user and category, loaded by authenticated AJAX, never printed in cacheable HTML.

## API contract used

* Base URL: `https://shop2topup.com/api/endpoints/v1`, `Authorization: Bearer <keyId>.<secret>`
* `GET /account` (balance), `GET /catalog/big-categories`, `/catalog/categories`, `/catalog/subcategories`, `GET /catalog/category/:id/requirements`, `GET /catalog/subcategory/:itemId/price`
* `POST /player/validate`, `POST /orders/create`, `GET /orders/:orderId`, `POST /orders/batch`
* Webhooks: `X-Shop2Topup-Signature: sha256=<hex>`, HMAC-SHA256 over the exact raw body.

Harmless envelope variants (`data`/`result`/`payload`, flat objects, UTF-8 BOM) are accepted, but every order response must match the stored UUID and a recognised status; HTML is never success.

## Installation and upgrade

1. Back up the site and database. Upload the ZIP in *Plugins → Add New → Upload Plugin* and **replace** the installed version. Do not uninstall first: settings, UUIDs and the callback URL are kept.
2. Purge the LiteSpeed (and CDN) cache, then reload a product page and the app.
3. *WooCommerce → Top-Up Provider → Settings*: paste the complete `keyId.secret` credential (the secret is shown only once in the provider panel), save, then **Test connection**. `IP_NOT_ALLOWED` means the server's outbound IP must be allowed in the provider panel; the plugin never bypasses that allowlist.
4. Keep **WooCommerce controls payment approval** and **Validate UID / Player ID** on.
5. Pricing in gourdes: *Import structure* = Variable product, *Customer pricing* = Manual, WooCommerce currency = HTG. Import from *Catalog* (products arrive as drafts), set each variation price, review, publish.
6. Place one small real order before selling broadly.

### Callback (webhook) registration

1. Dashboard → *Delivery callback*: copy the private URL with **Copy** (it contains a secret token: treat it like a password).
2. **Test this URL from here** must report 200 for both the unsigned probe and the signed test. It requires the plugin's own JSON answer, so a maintenance or cache page that answers 200 is reported as unexpected.
3. If the provider validates URLs before saving, click **Allow verification for 15 minutes**. Unsigned probes are then answered 2xx without processing anything; order events without a valid signature are always refused (HTTP 401).
4. In the provider panel use *Update Webhook*, then *Send Test*. *Dernier test signé du fournisseur* must show a new date. On 401, compare the webhook HMAC secret on both sides; the API key and the URL token are different values.
5. Delicat Builder maintenance mode: keep *allow payment callbacks* on. Only the exact callback route is opened through its filter.
6. Once events arrive on the private URL, turn off *Keep the old callback URL working* to retire the provider-named route.

### Scheduler

Action Scheduler needs a trigger. With `DISABLE_WP_CRON`, configure a server cron that calls `wp-cron.php` (or `wp action-scheduler run`) every minute. While the dashboard is open it also processes at most one due order per minute. If *Next reconcile* stays in the past, check *WooCommerce → Status → Scheduled Actions*. Recurring jobs are re-armed from wp-admin, WP-Cron and WP-CLI requests (never from storefront requests).

### Stuck or old orders

Use **Sync** first. For a paid order still blocked, use **Retry** once: it keeps the UUID and looks the supplier order up before any purchase. Unpaid, completed or refunded lines are not retried. Never mark an unpaid order *processing* to force delivery.

A queued line whose order stops being paid (cancelled, refunded, failed, trashed, or edited after payment) is parked with one order note and error code `ORDER_NOT_PAYABLE`; it resumes as soon as the order is paid again, or on Retry.

### Diagnostics

* *Dernière réponse API invalide* shows the endpoint, HTTP code, format and size of the last malformed answer, never its body. `INVALID_RESPONSE` alone does not prove a purchase happened or failed; Sync reads the truth by UUID.
* The callback card counts callbacks that failed authentication (missing or wrong signature).
* *Debug logging* writes request timing and error codes to WooCommerce logs (source `delicat-topup`); player data and secrets are never logged.

## Security notes

* Prefer wp-config constants: `DELICAT_S2T_KEY_ID`, `DELICAT_S2T_KEY_SECRET`, `DELICAT_S2T_WEBHOOK_SECRET`, and `DELICAT_S2T_ENCRYPTION_KEY` (32+ random characters, set before storing secrets; `DELICAT_S2T_ENCRYPTION_KEY_PREVIOUS` lets you rotate). Without it the AES-256-GCM key derives from the WordPress salts; rotating salts then needs a re-save of the credentials (the dashboard warns).
* Vouchers are encrypted at rest and shown only on completed orders: to the customer who owns the order, to a visitor holding the order key, in order emails and to store managers.
* Every admin action checks `manage_woocommerce` and a nonce. Public endpoints: the webhook routes, `dst2t_verify_player`, `dst2t_verify_nonce` and the single-use `dst2t_fast_fulfill` worker.
* White label (*Hide the provider from the storefront*): storefront assets are inlined with a neutral prefix, catalog copy is scrubbed, SKUs are keyed digests, the REST index hides the routes, and `.htaccess` denies the documentation, translation files and `tests/`. On nginx add the rule quoted in `.htaccess`. After deploying, `readme.txt` in this folder must answer 403.
* Never map one WooCommerce product to two automatic supplier plugins.

## WP-CLI

`wp delicat-s2t test` · `balance [--refresh]` · `reconcile` · `sync [--batches=5] [--per-batch=20]` · `status` · `webhook [--rotate]`

## Tests

Offline suites with WordPress/WooCommerce/supplier doubles (no database, no network, no purchase):

```
for t in tests/*.php; do php "$t" || echo FAIL $t; done
```

They refuse to run outside the command line. They do not replace a staging test on the real site.
