DELICAT RECHARGE / MODERN WALLET — v5.1.7

WHAT WAS FIXED
1. "Envoyer de l'argent" button now opens its dropdown menu on the first tap.
   Root cause: six separate send-menu click handlers had accumulated in
   form.js (v5.0.6 → v5.1.6). During the capture phase one handler opened
   the menu and the very next one toggled it shut and stopped further
   handlers, so the button looked dead. All six were replaced by ONE clean,
   conflict-free handler.
   - "À un autre client Delicat" reveals the secure transfer form.
   - "Recevoir par QR code" opens the small QR modal.
   - "Historique des envois" scrolls to the transaction history when present.
   - Outside-click and Escape close the menu; keyboard access is supported.

DESIGN
2. Richer neo-glassmorphism: more gradient colours on the balance card and on
   the page background (cyan, lavender, pink, violet and peach blooms).
3. More bubbles: an animated floating bubble field is injected behind the
   balance content, with tinted glass bubbles that drift gently. The original
   three bubbles now drift too. All motion respects prefers-reduced-motion.

UNCHANGED
- Wallet debit/credit logic, nonces, transfer limits, validation.
- MonCash, Natcash (auto + manual), TeraWallet, database, webhooks.
- Admin dashboard and licensing program.

AFTER INSTALL
1. Replace the old ZIP and activate v5.1.7.
2. Purge LiteSpeed + Cloudflare cache (version bumped to 5.1.7 to force new assets).
3. Reload /my-wallet/ in a private/incognito window.
4. Tap "Envoyer de l'argent" — the menu opens immediately.
