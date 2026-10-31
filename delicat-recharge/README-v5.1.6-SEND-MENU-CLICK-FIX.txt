DELICAT RECHARGE v5.1.6 — SEND MENU CLICK FIX

Fixed:
- Envoyer de l’argent button now opens the small menu reliably.
- Rebuilt send menu using native <details>/<summary> behavior plus JavaScript fallback.
- À un autre client Delicat now opens the secure existing transfer form.
- Recevoir par QR code opens the smaller QR modal.
- Historique des envois scrolls to the transaction/history area when present.
- Backend, licensing, admin, wallet debit/credit logic, nonces, limits and validation remain untouched.

After install:
1. Clear LiteSpeed/Cloudflare/browser cache.
2. Reload /my-wallet/ in private/incognito mode.
3. Tap Envoyer de l’argent: the menu should open immediately.
