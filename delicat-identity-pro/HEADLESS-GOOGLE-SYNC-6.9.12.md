# Delicat Identity Pro 6.9.12: integrated Google storefront synchronization

This release upgrades the existing `delicat-google-login` plugin in place. It
does not install a second authentication plugin, change plugin identity,
replace WordPress accounts, or duplicate WooCommerce customers.

The production storefront uses the existing Identity Pro Google OAuth flow at
`https://delicastoreha.com/?dip_action=callback`. Existing Nextend Social Login
callbacks and just-in-time subject-based account continuity are preserved.

Google sign-in follows this sequence:

1. The storefront generates a browser-bound state and opens Identity Pro.
2. Identity Pro performs its existing Google/Nextend continuity checks, OAuth
   state/PKCE/nonce verification, customer-policy checks, and required 2FA.
3. Identity Pro synchronizes its verified `_dglp_google_sub` into Delicat App
   API's `_delicat_google_sub` only when both resolve to the same unique safe
   customer.
4. Identity Pro redirects to the exact approved storefront callback with a
   single-use credential that expires after two minutes.
5. The storefront exchanges that credential through the existing
   `/wp-json/delicat-app/v1/auth/identity/exchange` endpoint. The resulting
   customer session remains in a Secure, HttpOnly, SameSite=Lax cookie.

Upgrade by uploading this ZIP under WordPress Plugins → Add New Plugin → Upload
Plugin and choosing to replace the installed Delicat Identity Pro version.
Keep Delicat App API active. No extra Google plugin, new Google callback,
JavaScript origin, OAuth secret, or account migration is necessary.

Read-only identity readiness:
`https://delicastoreha.com/wp-json/delicat-identity/v1/headless/status`

Storefront sign-in:
`https://delicat-store-ultra-fast.louismarkenricky.chatgpt.site/login`

The public readiness endpoint never returns Google client secrets, customer
records, OAuth tokens, account identifiers, or storefront session tokens.
