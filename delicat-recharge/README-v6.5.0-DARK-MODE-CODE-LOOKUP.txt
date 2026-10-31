6.5.0 — dark mode + TransCode lookup (admin)

1. Recherche code (wp-admin > Recharges > 🔎 Recherche code)
   Type a TransCode / Txn ID to see whether it was already used and by whom:
   recharge requests carrying that code (status, wallet credited or not,
   client, dates, link to the request), the anti-double-credit register
   (delicat_code_spent) and the recent SMS log entries that contain it.
   Exact search ignores case, spaces, dashes and tabs (same normalisation
   as the auto-verification). "Contient" mode needs 6+ characters.
   - Read-only: it never credits, blocks, releases or edits anything.
   - manage_options only, nonce-checked AJAX, 60 searches / 10 min per admin,
     max 50 rows, last 40 searches kept with the code masked.
   - PIN and proof files are never returned.
   - Existing recharge, verification, SMS guard and wallet code unchanged.

2. Dark mode follows the site theme (Delicat Builder toggle, html.dlc-theme-dark)
   Balance card, recharge form, send-money dialogs, QR dialog, success
   dialog, [delicat_statut] list and the legacy .dmc/.dsm wallet blocks.
   Glass look without backdrop-filter/color-mix (old Android WebViews).
   The phone's own dark setting no longer darkens parts of the form while
   the site is in light mode. Light mode is unchanged, except: the receipt
   button text stays white under aggressive theme link colours, and dialogs
   keep their padding inside the screen width on themes without a
   border-box reset.

Files: assets/css/form.css (appended section + scoped OS block),
includes/class-client-ux.php (status list styles), includes/class-code-lookup.php
(new), delicat-recharge.php (version + loader line).
Install over the current plugin, then clear page/CDN caches.
