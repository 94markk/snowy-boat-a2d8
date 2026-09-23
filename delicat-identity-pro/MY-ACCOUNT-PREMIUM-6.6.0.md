# Delicat Identity Pro 6.6.0 — Premium My Account Redesign

This release redesigns the integrated WooCommerce My Account presentation while preserving the existing WordPress, WooCommerce, TeraWallet and Delicat Identity backend/security boundaries.

## UI changes

- Premium Identity profile header with circular avatar, verification state and customer-safe protection score.
- App-like horizontal account navigation with a stronger active state.
- Rebuilt wallet hero using a navy/indigo/violet premium visual system and a lightweight CSS wallet illustration (no external image dependency).
- Compact Recharger / Envoyer / Historique actions.
- Three-column account summary remains compact on mobile rather than becoming tall stacked cards.
- Rebuilt Identity Protection card with email, linked login, trusted-device and HTTPS states.
- Cleaner recent-order rows with explicit WooCommerce ownership validation retained in PHP.
- Premium Profile & Preferences header and responsive edit-account form.
- French presentation strings for the WooCommerce edit-account form on the account endpoint.
- WooCommerce's default dashboard introduction is hidden when the Delicat dashboard is active, preventing duplicate dashboard content.
- Lightweight transform/opacity entrance animation with reduced-motion support.

## Security preserved

- No authentication, OAuth, session, device, nonce, lockout, REST authorization or account-ownership logic was weakened or replaced.
- Wallet values still come directly from TeraWallet.
- Order data still comes directly from WooCommerce and recent orders are checked against the current customer ID before rendering.
- Security score/status is derived from Delicat Identity's customer-safe Security Center engine.
- Global Identity/OAuth/security configuration remains capability-gated for administrators and is not exposed in the customer dashboard.
- No database schema migration is introduced by this visual release.

## Deployment

Keep the old MY ACCOUNT MODERN UI v2.6.0 companion Code Snippet disabled. Purge page/CDN/browser caches after replacing the plugin so the versioned 6.6.0 CSS and JavaScript are loaded.
