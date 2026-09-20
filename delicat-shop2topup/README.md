# Delicat – Top-Up Fulfillment for WooCommerce

Version 1.2.0. An installable WooCommerce bridge to the Shop2TopUp v1 reseller API that keeps the upstream
provider out of your storefront, watches your wallet balance by itself, and never publishes an imported product
without your review.

The plugin folder, text domain, and internal prefixes keep their original names so upgrades are in-place. The
*visible* name is neutral: menu entries, the plugins list, order notes, and storefront output use the
**provider label** you choose in Settings.

## What 1.2.0 adds

| Requirement | How it works now |
| --- | --- |
| Hide the supplier from the website | Storefront CSS/JS is inlined and its class/attribute/field prefix is swapped for a neutral one, imported titles/descriptions are scrubbed of provider names and URLs, catalog images are copied into your media library instead of hot-linked, fulfillment metadata is stripped from customer order views and emails, the callback route is hidden from the public REST index and answers a prober as a missing route, imported SKUs use a neutral prefix, and even the admin page slug is neutral |
| Encrypted / masked dashboard | Every fulfillment reference, supplier item id, and the callback URL render masked with a per-row **Reveal** control; API secrets and voucher codes are still AES-256-GCM encrypted at rest and are never rendered at all |
| See the balance, updating automatically | A background job refreshes the wallet on your chosen interval, the dashboard polls it live while open, the admin bar shows it, and a threshold raises a warning (optionally by email) before the wallet runs dry |
| Import must save as draft | Imports are always created as drafts, and re-imports are returned to draft too unless you turn that off |
| All features | Big-category catalog browsing, scheduled catalog/price/availability sync, an Orders screen with per-row Sync and Retry, a fulfillment column on the WooCommerce order list, a per-product "Refresh from provider" action, a Tools screen with manual jobs and environment diagnostics, a private rotating callback URL, broader webhook event handling, and six WP-CLI commands |

## Install and set up

1. Install the ZIP and activate it after WooCommerce.
2. Open **WooCommerce → your provider label → Settings**.
3. Save credentials, or define them in `wp-config.php`:

   ```php
   define( 'DELICAT_S2T_KEY_ID', 'your-key-id' );
   define( 'DELICAT_S2T_KEY_SECRET', 'your-key-secret' );
   define( 'DELICAT_S2T_WEBHOOK_SECRET', 'your-webhook-secret' );
   define( 'DELICAT_S2T_ENCRYPTION_KEY', 'a-stable-random-secret-of-at-least-32-characters' );
   ```

4. Use **Test connection**. The wallet balance appears on the dashboard and starts refreshing on its own.
5. Copy the callback URL from the dashboard into the provider panel and send its signed test.
6. If the API key has an IP allowlist, add the production server's stable outbound IP.
7. Import catalog items. They arrive as drafts so you can check the image, title, and selling price.
8. Place one smallest-denomination real order through your real payment flow before publishing anything.

The upstream API is live-only, so there is deliberately no fake "sandbox mode" switch.

## Hiding the provider

Turn on **Settings → Supplier privacy → Hide the provider from the storefront** (on by default). It changes:

- **Page source.** Storefront styles and scripts are inlined, so `/wp-content/plugins/<folder>/` never appears
  in a product page. Without this, the plugin folder name is readable by anyone who views source. The CSS
  classes, data attributes, form field names, and the style/script element ids all switch from `dst2t-` to a
  neutral `topup-` prefix at the same time, so there is no searchable fingerprint left either.
- **Imported copy.** Product names and descriptions are scrubbed of provider names and provider URLs on import
  and on every sync.
- **Images.** Catalog images are side-loaded into your media library. Nothing hot-links a provider CDN.
- **Customer order views and emails.** Internal `_dst2t_*` metadata and any row that names the provider is
  removed from the customer-facing item meta. This includes emails rendered *from* wp-admin — WooCommerce sends
  the customer's email during the admin request that changes the order status, so an `is_admin()` check alone
  would have leaked exactly the emails that matter. Shop managers still see everything on the order screen, and
  the customer still sees their own Player ID, server, and region.
- **REST.** The callback namespace is dropped from `/wp-json/`, a direct namespace index request 404s, and a
  request that arrives without a valid signature is answered with the same "no route" body WordPress uses for a
  URL that does not exist — so guessing the URL confirms nothing. The real reason is written to the WooCommerce
  log instead. The route itself keeps working for correctly signed deliveries.
- **SKUs.** WooCommerce publishes the SKU in the product summary, in JSON-LD for search engines, in the
  unauthenticated Store API, in the cart, and in order emails. New imports therefore get an **opaque** SKU:
  your **Imported SKU prefix** (default `tu-`) plus a keyed digest of the item, e.g. `tu-9F3A1C4B72`. It is
  stable, so re-imports still de-duplicate on it, but the provider's own item id cannot be read back out of
  it. Existing SKUs are never renamed — products imported before 1.2.0 keep `s2t-<item id>`, which does encode
  the provider's id, so change those SKUs by hand if that matters to you.

**Use an unbranded private callback URL** (on by default) serves the callback from a neutral REST namespace
with a 32-character secret token, e.g. `https://example.com/wp-json/store-callbacks/v1/<token>`. Treat that URL
like a password; **Generate a new callback URL** rotates it, and the old one stops accepting events
immediately.

The original `delicat-shop2topup/v1/webhook` route — whose *path* names the provider — stays registered so
upgrading never drops a delivery. Once the provider panel is pointed at the new URL and you have seen an event
arrive, turn off **Keep the old callback URL working** in Settings and that path stops being served at all.
The plugin will not let you retire it while the private route is disabled, so you can never end up with no
callback.

### What white-labelling does *not* do

Be realistic about the limits:

- **The plugin folder and its `readme.txt` still name the supplier on disk.** The bundled `.htaccess` denies
  direct HTTP reads of `.md`, `.txt`, `.po`, `.pot`, and `.mo` files, which closes this on Apache and LiteSpeed;
  on nginx add the equivalent rule printed at the top of that file. The folder *name* itself is still a
  giveaway to anyone who guesses it, so if you want that closed too, rename the plugin folder — the code uses
  no hard-coded folder name, so renaming works. You then update it by uploading a new ZIP under that same
  folder name. The translation catalogs no longer contain the provider name at all.
- **WooCommerce still logs under the source `delicat-shop2topup`.** That log is admin-only, and keeping the
  source stable means your existing log filters and support history keep working.
- **Store administrators see everything.** Masking protects screenshots, screen shares, and over-the-shoulder
  reading; it is not a permission boundary. Anyone with `manage_woocommerce` can reveal identifiers.
- **The provider still learns your site URL**, which is sent in the API `User-Agent` so their support can find
  your account.
- **Products imported before 1.2.0** keep their original SKU, title, description, and any hot-linked image.
  Re-import or run a catalog sync to scrub the copy; edit the SKU by hand if you want it changed.
- **Other plugins can leak it.** A site-wide "plugins used" debugger, a caching plugin that exposes asset
  manifests, or an exported REST index cached before the upgrade can still disclose the folder name.

## Wallet balance

- **Update the balance automatically** (default on) schedules a background refresh every **Refresh interval**
  minutes (default 10, minimum 1).
- The dashboard polls the cached value while the page is open and has a **Refresh now** button. Live calls are
  rate-limited to one per 15 seconds so a page left open cannot hammer the API.
- The balance is also refreshed out of band immediately after a supplier order is created, because that is the
  moment the wallet is debited.
- **Low balance alert** compares the wallet against your threshold in USD (0 disables it). Below it you get a
  dashboard warning, an admin notice, a WooCommerce log entry, and — optionally — one email per six hours to the
  WooCommerce stock-notification recipient.
- The wallet also appears in the admin bar for users who can manage WooCommerce.
- A failed check keeps the last known value and records the error code, so a provider outage never blanks the
  number without saying why.

## Import and catalog sync

Imported products are **always drafts**. New products are created as drafts; re-imported products are returned
to draft as well while **Always import as draft** is on (default). Turn it off if you prefer re-imports to keep
their published status.

Import also: applies your exchange rate and markup, copies the catalog image, files the product under a
WooCommerce category named after the game or service, stores the requirement schema, and preserves any
per-product absolute cost ceiling you had set.

**Automatic catalog sync** (off by default; hourly, 6-hourly, 12-hourly, or daily) walks mapped products in
batches of 20, refreshing the cached cost, the requirement fields, and availability. It only rewrites selling
prices when **Update WooCommerce prices during sync** is on, and it never overwrites a sale price you set by
hand. An item the provider has withdrawn is set out of stock and logged — never deleted, never unmapped. A
missing stock field is never read as zero.

## Abuse resistance on the storefront

Add-to-cart is unauthenticated, so two paths were bounded:

- Player validation is throttled in two buckets: 20 attempts per visitor and 300 per client address, both per
  five minutes (shop managers are exempt). The address is the one WooCommerce resolves, so a store behind
  Cloudflare or a load balancer does not end up with all its customers sharing one bucket. Over the limit the
  customer sees the normal "validation is busy, try again" message.
- Storefront assets are only enqueued on a product this plugin actually renders fields for.
- A requirement schema is only fetched when it has never been stored. A category that genuinely has no
  requirement fields stores an empty schema instead of being re-fetched on every attempt.

## Safety model

- Fulfillment requires a WooCommerce paid state.
- The UUID is saved to the order item and the lookup table before the supplier call.
- A timeout triggers `GET /orders/:uuid` before the same UUID may be replayed.
- Supplier money stays as six-decimal fixed-point strings for comparisons.
- Both cost guards fail closed. A cached baseline or ceiling of zero refuses the purchase rather than allowing
  it, because a zero baseline means the real cost was never learned; the hold note says exactly that so you can
  refresh the product and retry. A zero unit price coming back from the provider is treated as a missing price:
  it is never stored as the baseline and never becomes a selling price.
- Callback signatures use constant-time comparison over the exact raw bytes. Four header spellings and both
  `sha256=<hex>` and bare `<hex>` are accepted; none of them weakens verification.
- Any `order.*` event queues an authoritative read of the supplier order rather than trusting the payload.
  Unrelated events are acknowledged with 200 so the provider does not disable your endpoint.
- Callback state is monotonic and replay-safe; completed orders cannot be downgraded to pending.
- Failed, partial, refunded, price-blocked, or uncertain fulfillment goes on hold. The plugin never guesses a
  customer refund.
- HPOS is supported; orders are always loaded and changed through WooCommerce CRUD APIs.

## Status rules

| Provider state | Plugin action |
| --- | --- |
| `pending`, `processing`, `retrying` | Keep WooCommerce processing and schedule reconciliation |
| `completed` | Store delivery details; complete WooCommerce when every line is mapped and complete |
| `partial`, `failed`, `refunded` | Put WooCommerce on hold for store-manager review |
| Timeout/5xx/429 | Recover by UUID and retry with bounded backoff |
| Price outside guard | Do not create the supplier order; put WooCommerce on hold |

## WP-CLI

```bash
wp delicat-s2t test                 # validate credentials and show the account state
wp delicat-s2t balance --refresh    # print the wallet balance, live
wp delicat-s2t reconcile            # one safe batch reconciliation
wp delicat-s2t sync --batches=5     # run catalog sync batches
wp delicat-s2t status               # local counts, mapped products, callback URL
wp delicat-s2t webhook --rotate     # print, or rotate, the private callback URL
```

WooCommerce logs use source `delicat-shop2topup`. Debug mode adds endpoint timing, but never credentials,
request bodies, player data, voucher codes, or response bodies.

## Compatibility

WordPress 6.2+, PHP 7.4+, WooCommerce 7.0+, WooCommerce HPOS, Action Scheduler with a WP-Cron fallback.

## Upgrade and uninstall behavior

Deactivation stops scheduled jobs (reconciliation, balance refresh, catalog sync) but preserves mappings and
order state. Uninstall preserves data unless **Delete data when the plugin is uninstalled** was enabled first;
when it is, the fulfillment table, product and order-item metadata, hashed order-note markers, plugin options,
locks, and transients are all removed.

## Validation performed for this build

- PHP 8.4 syntax validation passed for every plugin PHP file.
- **76 isolated behavioral tests pass**: `php tests/regression.php` from this directory. They mock WordPress,
  WooCommerce, the database, and HTTP. The 25 tests from 1.1.0 are unchanged and still pass; 51 are new and
  cover white-labelling, dashboard masking, customer-facing metadata filtering (including the admin-rendered
  email case), callback signature and event tolerance, probe-resistant rejection, the private callback token
  and legacy-route retirement, wallet-balance caching and thresholds, settings round-tripping, stock
  mirroring, and the import-as-draft rules including the SKU-collision refusal.
- Every admin tab was additionally smoke-rendered against stubs, and a static pass confirms every cross-class
  call, static method, and class constant in the plugin resolves.
- These are not full WooCommerce integration tests.

## Practical limits of this build

- **The public API reference could not be re-read while building 1.2.0.** `shop2topup.com` is blocked by the
  network policy of the environment this version was built in, so the endpoint contract is carried over
  unchanged from 1.1.0 (reviewed 2026-09-20 against `/en/reseller-api/docs` and its catalog, player, orders,
  account, and errors pages). Everything new — wallet field names, stock fields, callback event names, callback
  signature headers — is deliberately written to accept several spellings rather than assume one. **Before you
  rely on automatic balance and stock in production, confirm on the dashboard that the balance is a real number
  and that a synced product's stock matches the provider panel.** If a value stays empty, the field name in
  your account's responses differs and needs one line added to the extractor.
- No API credentials, live purchases, real webhook deliveries, database concurrency tests, or live WordPress UI
  tests were available in this build session.
- Dynamic requirement fields target classic WooCommerce product forms. A custom purchase popup, a native app,
  or a block-based add-to-cart needs its own integration test and may need an adapter.
- Background timing depends on a working Action Scheduler or WP-Cron runner. Configure a server cron if
  traffic-driven cron is unreliable. Reconciliation handles up to 50 due items per run; catalog sync handles 20
  products per run.
- Balance polling costs one `/account` request per interval. Do not set the interval to one minute on an
  account with a strict rate limit.
- Back up before upgrading. Upload this ZIP over the existing plugin; do not run a second supplier plugin on
  the same mapped products. Existing settings, mappings, and order state are retained.
- Set a stable encryption key before first use. Changing it, the WordPress salts, or the site URL after storing
  secrets (when using the fallback key) requires a deliberate data migration.

## Live acceptance checklist

1. Test connection; confirm the account is enabled and the wallet balance is a real number that updates by
   itself after a few minutes.
2. Register the dashboard HTTPS callback upstream; verify the signed test timestamp updates.
3. Import one draft product; confirm it is a draft, that the title and description do not name the provider,
   and that the player/server fields render.
4. View the product page source and search for the provider name and the plugin folder name. Both should be
   absent.
5. With a smallest-denomination paid order, confirm one UUID and one supplier debit, then WooCommerce
   completion, then that the balance drops on the dashboard.
6. Open the customer's order page and the completed-order email; confirm no provider name and no internal
   identifiers appear.
7. Confirm scheduled actions execute and that a deliberately missed callback is recovered by reconciliation.
8. Test your actual mobile, desktop, checkout, and custom popup flows before broader publishing.
