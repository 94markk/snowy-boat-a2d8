# Delicat Identity Pro 6.2.3 — Login Popup Fix

## Root cause
WordPress normally prints footer scripts around `wp_footer` priority 20. Version 6.2.2 rendered the `#delicat-login` modal at priority 99. `native-auth-modal.js` therefore executed first, did not find the modal, and returned without binding the Connexion trigger.

## Fix
- Render modal through `wp_body_open` when supported.
- Guarded `wp_footer` priority 5 fallback for themes without `wp_body_open`.
- Prevent duplicate modal rendering.
- JavaScript retries initialization on DOM ready / delayed markup to tolerate LiteSpeed, Elementor and other script optimizers.
- Bumped frontend asset version to 6.2.3 to invalidate cached JS/CSS.
- Kept database runtime schema at 6.2.2 because this release contains no schema change.

## Preserved security
All 6.2.2 WordPress/WooCommerce synchronization, rate limiting, lockout, OAuth, mobile-token, verification and account-policy protections remain intact.
