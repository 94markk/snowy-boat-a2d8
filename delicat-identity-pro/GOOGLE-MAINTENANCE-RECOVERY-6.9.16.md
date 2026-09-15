# Delicat Identity Pro 6.9.16 — Google maintenance recovery

## Confirmed live cause

The public modal contained no `.dip-google-button` and no Google divider. A
direct logged-out provider readiness request ended at:

`/wp-login.php?dip_error=maintenance`

This proves the button was not removed by Delicat Builder CSS, Safari, the
modal JavaScript, Google Identity Services, or a missing icon. Identity Pro was
deliberately returning an empty provider row because
`social_login_maintenance=yes` was still stored.

## Recovery

6.9.16 runs a small versioned migration during a normal active-plugin update,
not only during plugin activation. It clears a legacy maintenance value once
when that value has no explicit 6.9.16+ administrator marker. Client IDs,
encrypted secrets, user identities, passkeys, sessions, orders and wallet data
are not changed.

After the migration, administrators can still intentionally enable the
emergency social-login switch. That choice is marked with the user and time so
later updates preserve it. A nonce-protected “Réactiver Google” action is also
available in the administrator warning.

The recovery queues `litespeed_purge_all` after all plugins finish loading.
LiteSpeed invalidates its page HTML, and Delicat Builder's configured
Cloudflare bridge receives the same event for edge-cache invalidation.

## Cache-safe behavior

If a cached page still contains a Google link while emergency maintenance is
active, Identity Pro now returns the visitor to the safe originating page and
opens the native modal with a local maintenance error. It no longer sends that
customer unexpectedly to `wp-login.php`.

## Deployment

1. Upload and replace Delicat Identity Pro with the 6.9.16 ZIP.
2. Purge LiteSpeed and Cloudflare page caches.
3. Open the storefront logged out; the divider and “Continuer avec Google” must
   be present.
4. Start Google once and confirm the destination host is
   `accounts.google.com`.

If Google remains unavailable, open Settings → Delicat Identity. The existing
non-secret diagnostic will identify HTTPS, Client ID, Client Secret, encrypted
secret readability, or provider enablement without exposing credentials.
