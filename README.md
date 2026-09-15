# Delicat Store Haiti

Digital goods storefront for [delicastoreha.com](https://delicastoreha.com):
game top-ups, gift cards, streaming subscriptions and exchange services, priced
in gourdes and paid for from a wallet funded by MonCash or NatCash.

Trilingual — French, English and Haitian Creole.

## Stack

| Layer | Choice | Why |
| --- | --- | --- |
| Pages | [Astro](https://astro.build) | Ships almost no JavaScript; product pages are static HTML served from the edge. |
| Hosting | Cloudflare Workers | Runs in Cloudflare's network, close to Haiti. No server to patch. |
| Database | Cloudflare D1 (SQLite) | Same platform as the Worker, so no cross-network latency per query. |
| Payments | MonCash / NatCash via SMS matching | Stripe does not operate in Haiti and cannot charge in gourdes. |

Only four routes need a request at all — cart, wallet, account and search, plus
the API endpoints. Everything else is pre-rendered.

## Getting started

```bash
npm install
npx wrangler d1 migrations apply delicastoreha --local
npm run dev
```

Then open http://localhost:4321.

For deployment, the SMS forwarder contract and the domain cutover, see
**[DEPLOYMENT.md](./DEPLOYMENT.md)**.

## How it fits together

```
src/
  config.ts              Site details, currency, payment numbers, limits
  data/
    catalog.ts           Products, denominations and prices — the one source of truth
    legal.ts             Terms and privacy copy, in all three languages
  i18n/                  Locale routing and the full string table
  lib/
    auth.ts              Password hashing, sessions, login throttling
    wallet.ts            The append-only ledger
    orders.ts            Pricing, payment and fulfilment
    payments/            SMS parsing and matching to pending top-ups
    suppliers/           Distributor adapters (one stub today)
  middleware.ts          Attaches the signed-in user; sets security headers
  pages/
    [lang]/              The site, rendered once per locale
    api/                 Auth, wallet top-up, orders, SMS webhook
db/migrations/           Database schema
tests/                   Unit tests and a full browser run
```

## Paying

Customers can pay two ways, and both settle through the same ledger:

- **Directly with MonCash or NatCash.** The order is created `pending`, the
  customer is shown the exact amount and number to send to, and the order is
  paid the moment the confirmation SMS arrives.
- **From their wallet balance**, which they top up the same way.

A direct payment is credited to the wallet and spent on the order in one step,
so every gourde has a ledger row behind it — and if the order somehow cannot be
charged, the customer keeps the money instead of losing it.

## The two rules worth knowing

**Prices are never taken from the browser.** The cart holds variant ids and
quantities; `src/lib/orders.ts` looks up what each one costs and charges that.
A tampered cart changes nothing.

**The wallet is a ledger, not a number.** Every movement is a row that is written
once and never edited; a correction is a new row with the opposite sign. The
balance column is a cache, written in the same transaction as the row, and
`reconcile()` can prove the two agree at any time.

## Tests

```bash
npm test         # wallet, order pricing, SMS parsing and matching, i18n parity
npm run test:e2e # register → top up → forwarded SMS → wallet credited → buy
```

The end-to-end run needs `npm run dev` going in another terminal. It drives a
real browser and fails on any console error or CSP violation.
