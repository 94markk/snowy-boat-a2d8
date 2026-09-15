# Deploying Delicat Store Haiti

Everything here runs on Cloudflare: static pages from the edge cache, a Worker
for the handful of routes that need a request, and D1 (SQLite) for accounts,
wallets and orders. There is no server to patch and no database to expose.

---

## 1. Prerequisites

- A Cloudflare account (the free plan is enough to start).
- Node.js 20 or newer.
- Access to the DNS for `delicastoreha.com` — yours is at **Hostinger**.

```bash
npm install
npx wrangler login
```

---

## 2. Create the database

```bash
npx wrangler d1 create delicastoreha
```

Copy the `database_id` it prints into `wrangler.json`, replacing
`REPLACE_WITH_YOUR_D1_DATABASE_ID`. Then create the tables:

```bash
# Local, for development
npx wrangler d1 migrations apply delicastoreha --local

# Production
npx wrangler d1 migrations apply delicastoreha --remote
```

Migrations live in `db/migrations/`. To change the schema later, add a new
numbered file — never edit one that has already been applied.

---

## 3. Set the secrets

```bash
npx wrangler secret put SMS_WEBHOOK_SECRET
```

Generate the value with `openssl rand -hex 32`. **Anyone holding this secret can
credit wallets**, so treat it exactly like a payment key: store it once in your
password manager, give it only to the forwarding device, and rotate it if a
phone is lost or an employee leaves.

For local development the same values go in `.dev.vars` (already gitignored):

```
SMS_WEBHOOK_SECRET=some-local-only-value
```

---

## 4. Fill in your real details

| File | What to change |
| --- | --- |
| `src/config.ts` | Support email, phone, WhatsApp number, and the **MonCash and NatCash numbers customers send money to**. These are shown on the top-up screen, so a wrong number means lost payments. |
| `src/data/catalog.ts` | Your real products, denominations and prices. Prices are integer centimes: `145_00` is G145. |
| `public/images/products/` | Real product artwork. See the note in section 9. |
| `src/data/legal.ts` | Your terms and privacy policy. Have them reviewed. |

---

## 5. Deploy

```bash
npm run deploy
```

This builds the site and pushes it to Cloudflare Workers. You get a
`*.workers.dev` URL immediately — **review the new site there before touching
DNS**. The old site stays live the whole time.

---

## 6. Point the domain at it

Your DNS is at Hostinger, so there are two routes. The second is better.

### Option A — keep DNS at Hostinger (simplest)

Cloudflare Workers can serve a custom domain only when Cloudflare is
authoritative for it, so with DNS at Hostinger you would need a CNAME to your
`workers.dev` hostname. This works for `www.delicastoreha.com` but **not** for
the bare `delicastoreha.com`, because a bare domain cannot be a CNAME. You would
lose the apex domain.

### Option B — move DNS to Cloudflare (recommended)

You keep the domain registered at Hostinger; only the nameservers change. This
is free, takes about ten minutes of work plus propagation time, and is what lets
the apex domain, automatic TLS and the security rules below all work.

1. In Cloudflare: **Add a site** → `delicastoreha.com` → Free plan. Cloudflare
   scans your existing DNS records — **check that every record came across**,
   especially your email records (MX, SPF, DKIM). A missing MX record means mail
   to `@delicastoreha.com` stops arriving.
2. Cloudflare gives you two nameservers, e.g. `ana.ns.cloudflare.com`.
3. In Hostinger: **Domains → delicastoreha.com → DNS / Nameservers → Change
   nameservers → Use custom nameservers**, and enter the two Cloudflare ones.
4. Wait for propagation (usually under an hour, occasionally up to 24).
5. In Cloudflare: **Workers & Pages → delicastoreha → Settings → Domains &
   Routes → Add custom domain**, and add both `delicastoreha.com` and
   `www.delicastoreha.com`.

TLS certificates are issued automatically. Turn on **Always Use HTTPS** under
SSL/TLS → Edge Certificates, and set the SSL mode to **Full (strict)**.

> Do this cutover at a quiet hour, and only after you have clicked through the
> `workers.dev` site yourself.

---

## 7. The SMS forwarder

This is the part you said you would build. Here is the contract the site
expects.

### Endpoint

```
POST https://delicastoreha.com/api/webhooks/sms
```

### Headers

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json` |
| `X-DS-Timestamp` | Unix seconds when the request was signed |
| `X-DS-Signature` | `HMAC-SHA256(SMS_WEBHOOK_SECRET, "<timestamp>.<raw body>")`, lower-case hex |
| `X-DS-Device` | Any stable name for the handset, e.g. `shop-phone-1` |

### Body

```json
{
  "externalId": "unique-per-message-id-from-the-device",
  "sender": "MonCash",
  "body": "Ou resevwa 500.00 HTG nan men JEAN (50934567890). Transaction ID: 9F3K2L8M",
  "receivedAt": 1758000000
}
```

`externalId` **must be stable for a given message and different for every
message** — the row id from the device's SMS database is ideal. It is what makes
a retry safe.

### Responses

| Status | Meaning |
| --- | --- |
| `200 {"outcome":"matched"}` | A pending top-up was found and the wallet was credited. |
| `200 {"outcome":"unmatched"}` | Understood, but no pending top-up fits. Stored for staff. |
| `200 {"outcome":"duplicate"}` | Already processed. Stop retrying. |
| `200 {"outcome":"ignored"}` | Not an incoming payment (balance notice, outgoing transfer). |
| `401` | Bad or missing signature, or the timestamp is more than 5 minutes out. |

Retry only on `5xx` and network errors. Every `200` is final.

### Signing, in a sentence

```js
const payload = JSON.stringify(body);          // sign these exact bytes
const timestamp = Math.floor(Date.now() / 1000);
const signature = hmacSha256Hex(secret, `${timestamp}.${payload}`);
// then POST `payload` unchanged — re-serialising it will break the signature
```

### How matching works

Before paying, the customer tells the site the provider, the exact amount, and
the number they will send from — either when topping up their wallet, or when
confirming an order they want to pay for directly. An arriving SMS is matched on
those three things, inside a one-hour window.

A payment made **for an order** is credited to the customer's wallet and spent
on that order in the same step, then the order is fulfilled. A payment made as a
**plain top-up** just raises the balance. Either way the ledger records every
movement, so an order paid directly shows as a credit and a matching debit
rather than appearing from nowhere.

That design means:

- Two customers paying the **same amount from different numbers** never collide.
- A customer paying a **different amount than they declared** is not credited
  automatically — it lands in the unmatched list for you to resolve. Their order
  stays `pending` and is marked failed once its hour is up; nothing is charged.
- A **replayed or duplicated** message is rejected twice over: once by
  `externalId`, once by the operator's own transaction id.

### Before you go live: check the SMS patterns

`src/lib/payments/parse.ts` reads the amount, payer number and transaction id
out of the message text. The patterns are written against the wording MonCash
and NatCash are commonly seen to use — but neither operator publishes a stable
format, and **I could not verify them against real messages**.

Collect five or ten genuine confirmation SMS from the merchant handset, add them
to `tests/sms-parse.test.js`, and run `npm test`. Any that fail to parse tell you
exactly which pattern to adjust. Until you have done this, treat automatic
crediting as unproven and watch the unmatched list.

---

## 8. Suppliers

`src/lib/suppliers/index.ts` defines one interface. Today only a `stub` adapter
is registered, and it deliberately refuses every request rather than pretending
to have delivered — so an order is refunded to the customer's wallet instead of
silently failing.

To wire up your real distributor, add `src/lib/suppliers/<name>.ts` implementing
`SupplierAdapter`, register it, and set `supplier: "<name>"` on the products it
fulfils. Tell me which distributor you use and I will write the adapter.

Products with `fulfilment: "manual"` (Netflix, Crunchyroll, PayPal, Meru as
configured) wait for a human. **There is no admin panel yet** — see section 11.

---

## 9. Product artwork

`public/images/products/` currently holds generated placeholders. They are plain
on purpose: Free Fire, Netflix and Roblox key art is licensed material, and
publishing copies of it would expose the store to takedowns or worse.

Ask your distributor for the promotional assets they authorise resellers to use,
or commission your own, and replace each file keeping the same name.

---

## 10. Hardening checklist

Done in the code:

- Passwords hashed with PBKDF2-SHA256 (210,000 iterations); never stored in the clear.
- Session tokens stored as SHA-256 hashes, in a `__Host-` cookie (HttpOnly, Secure, SameSite=Lax).
- Login throttling with lockout; identical responses whether or not an account exists.
- Content-Security-Policy with no `unsafe-inline`, generated by Astro with per-page hashes.
- HSTS, `nosniff`, `frame-ancestors 'none'`, a restrictive Permissions-Policy.
- Every SQL statement parameterised; no string-built queries anywhere.
- Prices always recomputed server-side from the catalog; the browser never sends one.
- Wallet held as an append-only ledger, with the cached balance written in the same transaction.
- Webhook signatures verified with a constant-time comparison.

Worth doing in the Cloudflare dashboard:

1. **WAF rate limiting** — the in-code limiter is per-isolate and will not stop a
   distributed flood. Add rules under Security → WAF → Rate limiting rules:
   - `/api/auth/login` — 10 requests per minute per IP
   - `/api/auth/register` — 20 per hour per IP
   - `/api/orders` — 20 per minute per IP
   - `/api/webhooks/sms` — 60 per minute per IP
2. **Bot Fight Mode** — Security → Bots.
3. **Two-factor authentication on your Cloudflare account.** It now controls the
   site, the database and the payment secret.

---

## 11. What is not built yet

Be aware of these before switching the domain over:

- **No admin panel.** Manual-fulfilment orders and unmatched payments are in the
  database with nothing to view them through. Until one exists, use
  `npx wrangler d1 execute delicastoreha --remote --command "..."`. This matters
  most for payments that arrive with the wrong amount: nobody is told about them
  except through the query in section 12.
- **No supplier integration.** Every order currently refunds itself (section 8).
- **No email.** Nothing is sent on registration or delivery. Cloudflare Workers
  has no built-in mail; this needs a provider such as Resend or MailChannels.
- **No password reset.** A customer who forgets their password cannot recover it
  without email working first.
- **Old wallet balances are not migrated.** If customers hold balances on the
  current site, those must be exported and imported before cutover — otherwise
  they lose their money. This is the single biggest risk in the switch.

---

## 12. Day-to-day commands

```bash
npm run dev          # local dev server at localhost:4321
npm test             # unit tests (wallet, pricing, SMS parsing, i18n)
npm run test:e2e     # full browser run: register, top up, pay (needs dev server)
npm run check        # type checking
npm run deploy       # build and publish
```

Useful database queries:

```bash
# Payments that arrived but matched nothing
npx wrangler d1 execute delicastoreha --remote \
  --command "SELECT received_at, body FROM sms_messages WHERE status='unmatched' ORDER BY received_at DESC LIMIT 20"

# Orders that are paid but waiting on a human
npx wrangler d1 execute delicastoreha --remote \
  --command "SELECT o.reference, l.label, l.fields_json FROM order_lines l JOIN orders o ON o.id=l.order_id WHERE l.fulfilment_status='pending' AND o.status IN ('paid','fulfilling')"

# Orders still waiting for the customer's MonCash/NatCash payment
npx wrangler d1 execute delicastoreha --remote \
  --command "SELECT reference, payment_method, total_centimes, created_at FROM orders WHERE status='pending' ORDER BY created_at DESC"

# Prove a wallet balance matches its ledger
npx wrangler d1 execute delicastoreha --remote \
  --command "SELECT u.email, u.balance_centimes, COALESCE(SUM(w.amount_centimes),0) AS ledger FROM users u LEFT JOIN wallet_entries w ON w.user_id=u.id GROUP BY u.id HAVING u.balance_centimes <> ledger"
```

The last query should always return nothing. If it ever returns a row, stop and
investigate before processing more orders.
