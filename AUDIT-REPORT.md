# Delicat plugins — security & speed audit (November 2026)

| Plugin | Before | After |
|---|---|---|
| Delicat Digital Gateway (FazerCards) | 4.23.2 | **4.24.0** |
| Delicat Top-Up Fulfillment (Shop2Topup) | 2.8.3 | **2.9.0** |
| Delicat Identity Pro | 6.17.7 | **6.18.0** |
| Delicat Recharge | 6.6.5 | **6.7.0** |
| Delicat Rewards | 4.0.0 | **4.1.0** |

Each plugin has a French changelog entry at the top of its CHANGELOG/readme.
Source is in `plugins/`; every change is a reviewable commit on top of the
unmodified baseline (`156c9e6`).

## 1. Supplier confirmation → WooCommerce delay (the 2-minute problem)

Orders the supplier had already delivered could stay « En cours » for one to
several minutes. Four causes, all fixed in the Gateway and Shop2Topup:

1. **The "fast lane" died silently on LiteSpeed.** Both plugins start a
   background request to check the supplier. It hung up after 0.5 s; WordPress
   reports no error for that kind of request, and LiteSpeed aborts a PHP
   request whose caller has already gone. The order then waited for WP-Cron.
   Now the request leaves after the customer's page is sent, with a 1.5–4 s
   window, and the worker answers immediately before doing any supplier work.
2. **Shop2Topup's after-response lane never ran on LiteSpeed** (it only knew
   PHP-FPM). LiteSpeed is now supported.
3. **Signed webhooks that lost a small race** (order lock, the 4 s per-order
   supplier read cooldown, supplier read lag) were pushed to the cron queue.
   They are now settled in the same request right after the supplier gets its
   200 reply — about 1–5 s in tests — and the queued job is cancelled.
4. **Background catalog work crowded out order checks.** Order jobs now have
   top priority in Action Scheduler; the Gateway's 30-second catalog sync runs
   as a low-priority background job with a shorter budget, and the admin-only
   supplier balance refreshes every 5 minutes instead of every 30 seconds.

Also: retries are paced to the supplier read cooldown (before, 2 of 3 retries
were wasted), the loopback chain covers ~1 minute instead of 20 s, and
Shop2Topup re-reads a fresh order twice in its first minute when no webhook
arrives (within the supplier's 3 reads / 60 s limit).

## 2. Security fixes

- **Recharge — HIGH:** stored XSS in wp-admin. A logged-in customer could
  upload an HTML file named `.jpg` as a payment proof; it ran when an admin
  clicked « Voir ». Uploads are now validated by content, and proofs are served
  with a safe type, `nosniff` and a sandbox CSP (also neutralises old files).
- **Recharge:** CSV exports no longer allow formula injection; an SMS without a
  Guard verdict no longer counts as proof.
- **Identity Pro:** atomic lock for simultaneous Google/Microsoft sign-ins.
- **Shop2Topup:** Player ID throttle could be bypassed with a forged
  `X-Forwarded-For`; the supplier cost guard could be skipped on the price
  fallback; `tests/` folder denied over HTTP.
- **Gateway / Shop2Topup:** deactivation/uninstall now really remove queued
  background jobs.

No exploitable auth bypass, IDOR, SQL injection or double-credit was found in
the money paths of the Gateway, Rewards or Identity Pro.

## 3. Bug fixes customers will notice

- Recharge: MonCash codes typed with spaces/dashes are matched on submit; a
  failed wallet credit no longer leaves the code "used" forever; a credited
  recharge can no longer be « rejeté »; the 10 transfers/hour limit works.
- Rewards: adding `DELICAT_REWARDS_HMAC_KEY` no longer blocks unclaimed prizes.
- Gateway & Shop2Topup: « Vérifier » works on old LiteSpeed-cached product
  pages (fresh security token, one retry).
- Identity Pro & Rewards: blank boxes/pages on older Android WebViews and
  in-app browsers fixed (`replaceChildren`, `inset`, `color-mix`, …).

## 4. Speed

- Gateway: top-up product pages no longer freeze up to 20 s when the supplier's
  verifiable-games list expires; ~15 option reads merged into one query.
- Rewards: code loaded on every visit 270 KB → 146 KB; fewer queries per bonus
  page view.
- Recharge: logo 478 KB → 13 KB; script 35 KB → 16 KB (deferred); CSS
  292 KB → 238 KB (33 KB gzipped); no third-party QR fetch on each page view.
- Shop2Topup & Gateway: recurring-job checks no longer run on storefront pages.

## 5. Junk removed

~60 stale per-version notes (`README-v*.txt`, `AUDIT-*`, `VALIDATION-*`,
`PLAYER-ID-FIX-*`, …), an unused integrity manifest and dead pre-3.3 Rewards
assets. Anything still useful was folded into each plugin's README/CHANGELOG.
Release zips exclude `tests/` and build tools.

## 6. To do on the server after installing

1. **Back up** the site, then install the 5 zips (Plugins → Add New → Upload,
   « Replace current with uploaded »).
2. **Purge** LiteSpeed Cache and the CDN.
3. **Real cron** (Hostinger hPanel → Cron Jobs), every minute:
   `wget -q -O - https://delicastoreha.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`
   and add `define('DISABLE_WP_CRON', true);` to `wp-config.php`.
4. **LiteSpeed `noabort`** for background requests — at the top of the site's
   root `.htaccess`:

   ```apache
   <IfModule LiteSpeed>
   RewriteEngine On
   RewriteRule ^(wp-cron\.php|wp-admin/admin-ajax\.php)$ - [E=noabort:1]
   </IfModule>
   ```
5. Check both supplier webhooks are registered and the last-received time moves
   after an order (Catalog Studio / Shop2Topup settings).
6. Check `/wp-content/plugins/delicat-shop2topup/readme.txt` answers 403.
7. Staff app: rejecting an already-credited recharge now returns HTTP 409
   `already_credited` — make sure the app shows the message.

## 7. Found but not changed (owner decision needed)

- Identity Pro: wp-login.php reveals which identifiers are staff; Google
  sign-in from the app still accepts tokens without a nonce (old app versions).
- Recharge: QR codes are generated by api.qrserver.com (the customer's code is
  sent to a third party); growth bonuses (off by default) can race; the admin
  recharge list runs ~400 queries; proofs saved by very old versions may still
  be at public upload URLs.
- Rewards: rotating `DELICAT_REWARDS_HMAC_KEY` resets referral fraud history.
- Shop2Topup: an admin manually setting an unpaid order to Processing still
  releases delivery (needed for old orders/gateways); `.pot` file is outdated.
- Gateway & Shop2Topup (shared package module): one product query per variable
  product on category pages.

## 8. How this was tested

No live WordPress was available. Every PHP file passes `php -l` (PHP 8.3),
every JS file passes `node --check`, and all plugin test suites pass, including
new latency tests (`delicat-digital-gateway/tests/delivery_smoke.php`,
`delicat-shop2topup/tests/sync-latency-smoke.php`, `…/tests/audit-290-smoke.php`)
that run the real plugin code against stubbed WordPress, WooCommerce and
supplier APIs. Recharge's CSS was compared in headless Chrome (44 scenarios).
Watch the first real orders after installing.
