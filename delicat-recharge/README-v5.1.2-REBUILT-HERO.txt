DELICAT RECHARGE v5.1.2 — Rebuilt Wallet Hero

What changed:
- Rebuilt only the first wallet/balance section as a clean responsive component.
- Backend untouched: admin, licensing, recharge engine, NatCash, MonCash, database, Ajax and wallet logic were not changed.
- Balance remains on the left and client/code card remains on the right on mobile.
- Recharge and Envoyer de l’argent remain side by side on mobile.
- Envoyer de l’argent dropdown opens correctly and connects to the old secure transfer form/logic.
- Recharge button smoothly scrolls to the recharge form.
- QR receive modal is smaller and mobile-friendly.
- Icons and typography are smaller and better fitted on phones.

Shortcodes:
[delicat_wallet_balance]
[delicat_balance_card]
[delicat_recharge]

Recommended page setup:
1. Put [delicat_wallet_balance] at the top of the wallet page.
2. Put [delicat_envoyer_argent] below it if you use the transfer form from your Code Snippets.
3. Put [delicat_recharge] below that for the recharge form.
4. Clear LiteSpeed/Cloudflare/browser cache after upload.
