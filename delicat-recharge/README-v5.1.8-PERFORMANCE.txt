DELICAT RECHARGE / MODERN WALLET — v5.1.8

PERFORMANCE (fixes the laggy wallet page)
- The floating bubble animation added in v5.1.7 was the cause of the lag.
  Animated bubbles sat behind the frosted glass panels, so iPhone Safari
  had to re-blur the whole card on every animation frame. The bubbles are
  now STATIC decoration — no animation, no backdrop-filter, no will-change.
- Reduced the frosted-glass blur on the big panels (card, client box,
  secure transfer card) from ~28px to ~10px. The backgrounds are almost
  opaque, so it looks the same but is far cheaper to scroll on mobile.
- Removed a decorative ghost rectangle layer from the card (one less
  compositing layer, no visual change).
Result: smooth scrolling on iPhone.

LAYOUT
- "100% Sécurisé" now sits at the TOP-RIGHT of the "Recharger votre compte"
  card, including on phones (previously it dropped below the text).
- The "Envoyer de l'argent…" section title no longer gets clipped: it wraps
  cleanly and the arrow keeps its own space.

UNCHANGED
- The v5.1.7 send-menu click fix (Envoyer dropdown) stays in place.
- Wallet debit/credit, nonces, limits, validation, MonCash, Natcash,
  TeraWallet, database, webhooks, admin and licensing — all untouched.

AFTER INSTALL
1. Replace the old ZIP, activate v5.1.8.
2. Purge LiteSpeed + Cloudflare cache (version bumped to force new assets).
3. Reload /my-wallet/ in a private window — the page should scroll smoothly.
