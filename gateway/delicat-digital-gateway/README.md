# Delicat Digital Gateway v4.14.2 — Player ID Server Diagnostic

This update adds a secure, read-only diagnostic inside **WooCommerce → FazerCards → Security** so the store can distinguish a Delicat mapping problem from a FazerCards/Free Fire validation outage without exposing the supplier API key.

## v4.14.2 changes

- Adds **Test FazerCards Player ID server** with a temporary Free Fire Player ID field.
- Runs four bounded server-to-server checks in order: authenticated `GET /me`, live `GET /topups/validate-id`, Free Fire capability/schema discovery, then read-only `POST /topups/validate-id`.
- Uses the validation category and Player ID field advertised by FazerCards' current live validation catalog instead of hardcoding the storefront product mapping.
- Classifies failures as credential/plan access, rate limiting, Player ID rejection, schema rejection, upstream/network unavailable, missing Free Fire capability, or healthy.
- Retries one transient Player ID read once; it never retries authentication/plan errors, throttling, or a normal invalid-player rejection.
- Never creates an upstream order, never debits FazerCards balance, and never writes the entered Player ID to options, transients, logs, order metadata, or the displayed diagnostic report.
- Keeps the v4.14.1 storefront recovery path unchanged.

### How to use

1. Open **WooCommerce → FazerCards → Security**.
2. Enter the same Free Fire Player ID that fails on the product page.
3. Click **Test FazerCards Player ID server**.
4. Read the four-stage result. `healthy` confirms the supplier validator works and the remaining issue is local mapping/UI; `upstream_unavailable`, persistent `5xx`, or transport failure points to FazerCards or its upstream Free Fire provider; `credentials_or_plan` points to API-account access; `free_fire_not_listed` means FazerCards' live validation catalog is currently not advertising Free Fire.

---

# Delicat Digital Gateway v4.14.1 — Player ID Validation Recovery

This hotfix targets the storefront error **“Impossible de vérifier ce Player ID pour le moment”** when the supplier validator is reachable only intermittently or its dynamic validation category/schema changed while WordPress still had a cached copy.

## v4.14.1 changes

- Keeps the official FazerCards `POST /topups/validate-id` request format and server-side API-key boundary unchanged.
- Refreshes the dynamic validation category/field catalog and retries once when the supplier rejects a stale category/schema.
- Retries once for bounded transient connection/5xx failures, but never amplifies API-key/plan errors (`401/403`) or supplier throttling (`429`).
- Shortens the interactive supplier timeout to 8 seconds and adds a 12-second browser abort so the product page cannot remain stuck indefinitely.
- Adds safe WooCommerce log diagnostics (`HTTP`, internal error code and provider machine code) without logging Player IDs, field values, API keys, tokens or secrets.
- Clears legacy UID validation caches once on upgrade.
- Applies the same recovery path to storefront verification and server-side reseller/cart revalidation; verification never charges a wallet or creates an upstream order.

If the error remains and the WooCommerce log reports HTTP `401` or `403`, the code path is working but the FazerCards API key/subscription does not currently have access to the validation route. If it reports `429`, wait for the supplier quota window. Persistent `5xx`/transport errors are upstream/network health issues.

---

# Delicat Digital Gateway v4.14.0 — Redundant Canonical Synchronization

This deep-audit rebuild addresses the confirmed failure mode where FazerCards
finishes and delivers a direct top-up but WooCommerce retains the earlier
`processing` snapshot. The supplier purchase is not repeated: every recovery
path is GET-only and is bound to the existing `ord-*` id, paid WooCommerce order,
and exact line item.

## Fault finding

The supplied evidence shows FazerCards completed and delivered `ord-848712`,
while the WooCommerce line item still stored `_dfr_remote_status=processing` and
had no later canonical observation. That places the demonstrated fault in the
WordPress/WooCommerce observation pipeline (missed/blocked webhook or delayed
background execution), not in FazerCards fulfillment. Production webhook
delivery history is still the final way to distinguish a blocked callback from
a stalled host runner.

## v4.14.0 changes

- Pre-arms a deterministic ten-minute plan of independent canonical
  `GET /orders/{id}` checkpoints before low-latency workers begin. A running job
  can no longer suppress or become its own successor.
- Adds two recurring, rate-bounded watchdog triggers (Action Scheduler and
  WP-Cron) that repair recent `Processing`/`On-hold` orders and completed code
  orders whose delivery material has not arrived; the same watchdog also repairs
  in-flight orders created through the optional reseller API bridge.
- Adds an owner-only storefront heartbeat for direct top-ups, gift cards, and
  game keys. It uses an order-bound nonce, strict account ownership, wallet
  proof, exact remote-item binding, and per-account/per-remote rate controls.
- Opening the exact order as an authorized WooCommerce administrator performs
  one canonical recovery read, with the same anti-amplification controls.
- Consolidates legacy, loopback, post-response, webhook, queue, plan, watchdog,
  customer, and administrator reads into one serialized canonical apply path.
- Aligns active polling to the official five-second wait cadence and enforces a
  shared 100-read/minute reserve below the supplier status endpoint ceiling.
- Records which path observed the terminal state and why WooCommerce completion
  was blocked, without storing supplier secrets or customer delivery payloads.
- Strengthens webhook verification with raw-body HMAC-SHA256, constant-time
  comparison, timestamp/replay limits, optional header/body identity matching,
  and a fresh canonical API read before any state mutation.
- Keeps credentials and delivered codes encrypted at rest with contextual AEAD
  (XChaCha20-Poly1305, or AES-256-GCM fallback) and requires HTTPS/TLS
  certificate verification for supplier requests.
- Runs a bounded upgrade repair pass for recent in-flight orders. It can never
  submit or charge a second supplier order.

## Required production check after upgrade

1. Open **WooCommerce → FazerCards → Security**.
2. Click **Configure & test FazerCards webhook**.
3. Confirm the provider URL matches the displayed HTTPS endpoint, the webhook is
   enabled, and the signed test passes.
4. Place one low-value direct top-up test and confirm its line item records a
   terminal reconciliation source and WooCommerce changes to `Completed`.

---

# Delicat Digital Gateway v4.13.1 — Durable Supplier Synchronization Hotfix

This hotfix fixes the exact failure observed on direct top-up order `ord-848712`:
the first fast status check saw `processing`, but the currently running Action
Scheduler action was mistaken for its own future successor. No later canonical
check remained queued, so WooCommerce could stay `Processing` after FazerCards
had completed the top-up.

## v4.13.1 changes

- Always schedules a durable canonical poll after a non-terminal fast check.
- Adds the retry attempt to each scheduled action's uniqueness identity, so the
  running action cannot suppress the next required poll.
- Applies the same lost-successor protection to storefront supplier-create
  retries and local WooCommerce completion retries.
- Runs a read-only upgrade repair sweep for recent in-flight WooCommerce orders,
  including orders stranded by v4.13.0. The sweep only reads known supplier IDs
  and cannot create or charge another supplier order.

The v4.13.0 webhook and security improvements remain included below.

---

# Delicat Digital Gateway v4.13.0 — Real-Time Supplier Synchronization

This release closes the synchronization gap where FazerCards could finish an
order while WooCommerce remained `Processing` until Action Scheduler ran, or
remained stuck when the queue runner did not run at all.

## v4.13.0 changes

- Adds official FazerCards webhook-account integration for
  `GET/PUT /account/webhook` and `POST /account/webhook/test`.
- Adds **Configure & test FazerCards webhook** to Catalog Studio → Security.
  The provider signing secret is read back server-to-server, authenticated-
  encrypted locally, and never displayed in HTML or JavaScript.
- Shows whether the provider webhook is enabled, matches this store's exact URL,
  passed a signed delivery test, and has consecutive delivery failures.
- Keeps the webhook fast path authoritative: the HMAC-signed event is a trigger,
  then the plugin re-reads `GET /orders/{id}` before changing delivery or Woo status.
- Extends the cron-independent, single-use-token watcher beyond the old two-second
  window. Chained canonical reads now cover supplier work completing during the
  critical first ~20 seconds without waiting for Action Scheduler or WP-Cron.
- Retains Action Scheduler/WP-Cron as a durable fallback for longer-running orders,
  transport errors, rate limits, or hosts that reject self-loopback requests.
- Adds a v4.13 read-only repair sweep for recent secure `Processing`/`On-hold`
  orders. It cannot submit or charge a second supplier order.
- Rebuilds a missing remote-order lookup from canonical WooCommerce line-item
  metadata only when exactly one item owns that supplier ID; duplicates fail closed.
- Accepts the documented empty successful response from FazerCards' webhook PUT
  endpoint and preserves the webhook-test endpoint's HTTP-200 `{ok:false}` result
  so receiver failures are reported accurately.

## Required one-time setup after upgrade

1. Open **WooCommerce → FazerCards → Security**.
2. Save the FazerCards API key if it is not already configured.
3. Click **Configure & test FazerCards webhook**.
4. Confirm **Provider webhook: Enabled & URL matched** and
   **Signed delivery test: Passed**.

Production webhook setup requires a public HTTPS WordPress Site URL. If
`DELICAT_FAZER_WEBHOOK_SECRET` is defined in `wp-config.php`, the configure tool
will verify it against the provider but will never rewrite the constant.

## Synchronization contract

The same terminal-state pipeline applies to direct top-ups, gift cards, and game
keys. WooCommerce completes only after the wallet debit is re-verified, every
tracked supplier order is canonically `completed`, and every required gift-card
or game-key code has been authenticated-encrypted locally.

Official references:

- https://api.fzr.cards/public/docs
- https://reseller.fazercards.com/en/docs/webhooks
- https://github.com/FZR-cards/fazercards-node

---

# Delicat Digital Gateway v4.12.0 — Secure Headless Storefront Bridge

This release connects the existing Digital Gateway product-field and Player ID
engine to Delicat App API 2.9.0 without moving pricing, verification, payment or
fulfillment authority into the frontend.

## v4.12.0 changes

- Exposes each imported product's sanitized DFR field schema through the
  existing `delicat_app_product_fields` catalog filter.
- Marks products that require a player/account identifier through
  `delicat_app_product_needs_player_id`.
- Adds authenticated
  `POST /wp-json/delicat-app/v1/catalog/product/{id}/player/verify` and reuses
  the existing App API HTTPS, version, kill-switch, token and rate guards.
- Reuses the existing supplier-backed Player ID validator. No upstream category,
  field mapping, API credential or client-supplied “verified” flag is trusted.
- Carries only declared, sanitized fields from authenticated App API cart calls
  into the existing WooCommerce add-to-cart validation and fulfillment metadata.
- Revalidates supported Player IDs server-side when the authoritative cart line
  is created whenever verification is required.
- Uses stable field hashes for App API cart retries while preserving unique
  website-form submissions.
- Avoids duplicate cart display rows when the App API and DFR both describe the
  same native field.

## v4.12.0 security boundary

- WooCommerce remains authoritative for product, variation, stock and cart.
- The App API token must authenticate the customer before verification or field
  bridging can run.
- The frontend cannot choose the supplier validation category or field mapping.
- Player ID verification failure prevents a protected line from entering the
  cart; no wallet debit or supplier purchase is attempted.
- Existing v4.11 wallet attestation, supplier idempotency, encrypted delivery,
  webhook replay protection and completion gates remain unchanged.

---

# Delicat Digital Gateway v4.10.0 — Cronless Instant Reconciliation

This release fixes the intermittent state shown by orders such as supplier `completed` while WooCommerce remains `Processing`. The primary cause observed in v4.9.2 timing metadata is an accepted fast action that never starts (`_dfr_timing_fast_queued` exists but `_dfr_timing_fast_started` does not).

## v4.10.0 changes

- First reconciliation no longer depends on Action Scheduler/WP-Cron.
- Adds a single-use 256-bit authenticated server loopback to reconcile supplier state immediately.
- Adds a PHP-FPM post-response fallback: the customer response is flushed first, then a bounded supplier reconciliation runs in the same request worker when `fastcgi_finish_request()` is available.
- Keeps Action Scheduler/WP-Cron only as a durable safety net.
- Action Scheduler fallback workers no longer sleep inside the queue.
- Serializes canonical supplier GETs so loopback, webhook, customer refresh and queue workers do not hammer the same remote order concurrently.
- Signed terminal webhook status is treated as a monotonic floor when the canonical GET replica still reports `processing`; gift-card/game-key delivery still requires the actual encrypted code before WooCommerce can complete.
- Adds a one-time non-purchasing repair sweep for recent stuck Processing/On-hold orders.
- Opening a WooCommerce admin order can trigger a nonblocking recovery check, but normal automation does not depend on an admin visit.
- No repair/reconciliation path can create a second supplier order; supplier creation remains idempotent and isolated to the original fulfillment path.

## Security

- Wallet ledger proof and cryptographic payment attestation remain mandatory before supplier fulfillment or WooCommerce completion.
- Internal loopback accepts no caller-supplied order identifiers: it receives only a random single-use token whose server-side transient binds the exact order/item/remote id.
- Tokens expire in 60 seconds and are consumed before supplier I/O.
- Supplier order IDs remain bound to the exact WooCommerce line item.
- Gift-card/game-key codes remain encrypted at rest and are never placed in cron/action arguments.
- Signed webhook verification, replay protection, HTTPS verification, rate limits, idempotency and redacted logs remain enabled.

---

# Delicat Digital Gateway v4.9.2 — Verified Woo Completion Finalizer

This hotfix targets the case where the supplier has already returned `completed` but WooCommerce remains `Processing`.

## Completion fixes

- Reloads the WC order and line-item metadata before the final completion decision, avoiding stale nested-hook/item-meta state.
- Verifies that `WC_Order::update_status('completed')` actually persisted in WooCommerce/HPOS instead of assuming the call succeeded.
- Adds a last-stage `woocommerce_payment_complete` finalizer at maximum priority.
- Adds a same-request `shutdown` finalizer. This is local database/security work only; it never calls the supplier.
- Adds a bounded immediate local retry if a third-party status hook leaves the order in `Processing`.
- Adds `_dfr_completion_block_reason` diagnostics for any completion gate that refuses the transition.
- Adds a one-time v4.9.2 repair scan for recent `Processing`/`On-hold` orders that are already securely fulfilled.
- Corrects `_dfr_completed_at`: it is now written only after WooCommerce storage confirms the order is truly `completed`.

## Security rules retained

WooCommerce is completed only when:

- the order was paid using TeraWallet (`wallet`);
- the wallet ledger debit still matches the customer/order/amount/currency;
- the cryptographic wallet attestation is valid for protected orders;
- every line item is a tracked Delicat digital line;
- every line has a valid supplier `ord-...` binding;
- every supplier state is `completed`;
- gift-card/game-key lines have their delivery code encrypted locally before completion.

No supplier purchase is repeated by the completion repair/finalizer.

# Delicat Digital Gateway v4.9.1 — Woo Completion Hotfix

- Fixes supplier `completed` orders remaining WooCommerce `Processing`.
- Auto-completion is enforced whenever Live orders + Auto fulfillment are enabled.
- Adds a secure one-time repair pass for recent stuck Processing/On-hold orders.
- Fast reconciler now re-runs Woo completion when it sees an already-terminal top-up.
- No supplier request is required for the repair; wallet ledger, cryptographic attestation, tracked-item status, and encrypted-code rules remain mandatory.

# Delicat Digital Gateway v4.9.1 — Latency Audit + Adaptive Instant Reconciliation

v4.9.1 keeps the v4.8 cryptographic wallet/payment protections and targets the remaining intermittent fulfillment delays found during a deep timing audit.

## Verified causes fixed in v4.9.1

1. **Queue starvation under concurrent orders**
   - v4.8's first Action Scheduler worker intentionally slept for ~7.6 seconds across its observation window, before supplier HTTP time.
   - Because background actions may share a constrained queue, later customer orders could wait behind a slow earlier order.
   - v4.9 splits the fast window into three short/fair queue rounds so one supplier order cannot monopolize the queue.

2. **Transient poll failures could add 120 seconds**
   - A temporary supplier/network failure in durable reconciliation used a fixed 120-second delay.
   - v4.9 uses short adaptive retries: 3, 5, 8, 12, 20, 30... seconds.
   - Real supplier `429 Retry-After` values are still respected rather than bypassed.

3. **Transient supplier-create failures could add 60+ seconds**
   - Gift-card/game-key/top-up creation previously used a 60-second retry floor after retryable failures.
   - v4.9 retries the same immutable request with the same idempotency key after 3, 6, 10, 20... seconds.
   - Transactional supplier POST calls are bounded to 10 seconds so a stalled upstream connection does not hold the checkout path for 20 seconds.

4. **Blocking second GET after completed-without-code**
   - The reseller path could issue another long supplier GET when status was already completed but the code had not yet appeared.
   - v4.9 never blocks the request on that second lookup; short background code reconciliation takes over.

5. **Artificial WP-Cron fallback floors**
   - Remaining 30-second fallback floors were removed. Requested retry delays are now preserved.

6. **Stale local fallback lock**
   - The fallback local mutation lock stale threshold was reduced from 120 seconds to 30 seconds. The protected section contains no supplier network operation.

## New latency diagnostics

Slow completed orders receive one internal WooCommerce order note (not customer-visible) when the delay is material. It may report:

- payment → complete duration
- supplier/code duration
- Action Scheduler queue wait
- supplier-create HTTP duration
- completion-hook duration
- last safe delay classification, such as:
  - `supplier_processing`
  - `supplier_code_not_ready`
  - `supplier_transport`
  - `supplier_rate_limit`
  - `action_scheduler_queue_delay`
  - `order_lock_contention`

Non-sensitive timing metadata is also retained with `_dfr_timing_*` keys. No gift-card code, Player ID, API key, wallet secret, or webhook secret is included in these diagnostics.

## Security retained

v4.9 preserves the v4.8 security boundary:

- TeraWallet-only mapped digital checkout
- local TeraWallet ledger verification
- direct reseller/Gold TeraWallet debits are independently re-verified against the durable wallet ledger
- cryptographic wallet-payment attestation
- attestation binds order/customer/amount/currency/transaction and exact purchased line items
- payment proof rechecked before supplier spend and before WooCommerce completion
- immutable encrypted fulfillment snapshots
- supplier idempotency keys
- current-schema HMAC webhook verification; legacy webhook mode is opt-in only
- atomic webhook replay protection
- canonical supplier reread before webhook-driven mutation
- local remote-order/item binding
- authenticated encryption for delivered codes
- owner-only, nonce-protected, rate-limited secret reveal
- API TLS verification and response-size limits
- reseller bearer + site-bound HMAC authorization for every API request

## Expected order flow

Wallet debit succeeds → WooCommerce payment accepted → wallet attestation verified → supplier request → immediate/fair reconciliation + signed webhook → delivery encrypted locally → WooCommerce Completed.

WooCommerce is never auto-completed before every tracked digital line item is supplier-completed and every gift-card/game-key code required by that line is securely stored.


## v4.11.0 security hardening

- wallet attestation v2 binds the encrypted supplier fulfillment intent, including offer and account/player fields
- reseller API requires site-bound HMAC proof on every endpoint, including reads that may expose balances/order delivery
- supplier webhooks are trigger-only; canonical supplier API state is required before terminal mutation
- canonical order locking no longer falls through to an independent fallback when the MySQL lock is merely busy
- live TeraWallet ledger is re-read at each financial authorization/completion gate
- reseller REST payloads are bounded to 128 KiB
- reseller product pricing/exposure saves require a plugin-specific CSRF nonce
- public validation, secret reveal and internal reconciliation rate limits fail closed if limiter storage fails
- internal loopback rate limiting is serialized to resist parallel-counter bypasses
- reseller terminal delivery and refunds re-verify the original wallet debit before exposing codes or creating wallet credit
