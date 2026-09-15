# Delicat Identity Pro 6.3.1 — Access Control & Protected Pages Audit

## Scope
Security review of WordPress/WooCommerce capability boundaries, My Account exposure, admin-post actions, customer self-service actions, REST routes, protected frontend pages, sessions and caching.

## Access model

### Administrator only (`manage_options`)
- Identity/OAuth settings and secrets
- Provider configuration/tests
- Global Security Center and audit summaries
- Migration/backup/restore/rollback tools
- Integrity scans and security-event clearing/export
- App Sync configuration and cleanup
- Analytics exports/refresh
- Email Designer administration/testing
- Visual Builder import/export/reset
- Performance/repair/support/SDK tools
- WooCommerce Identity configuration, including protected products/categories and role redirects
- System/Foundation repair tools

A centralized `DIP_Access_Guard` now blocks these actions before individual module callbacks execute, providing defense in depth if a future callback omits a capability check.

### Authenticated customer self-service only
Customers may operate only on their own account:
- View their own Identity dashboard
- View their own linked providers
- Disconnect their own provider when another login method remains
- View/manage their own devices
- Trust/untrust/forget only their own device records
- Revoke their own other Web/App sessions
- View their own recent security events
- Request their own password setup/recovery flow

These actions retain CSRF nonces and now also pass the centralized current-user self-service ownership boundary.

### Intentionally public authentication surfaces
The following remain public because they are required to authenticate a logged-out user:
- Native login / registration nonce bootstrap
- Native login / registration handlers
- Passwordless OTP and magic-link request/verification flows
- Google/mobile initial authentication endpoints
- Mobile refresh with possession of a valid refresh token and device/installation binding
- Biometric challenge/verify with registered installation/key proof
- App pairing create/status/exchange using short-lived pairing secret + browser binding
- Public mobile capability discovery (non-secret booleans/TTLs only)

Public does not mean unauthenticated access to account data: protected REST operations still require a valid bound mobile session.

## Protected frontend pages
`DIP_Access_Guard` now automatically protects:
- WooCommerce My Account endpoints except the public `lost-password` endpoint
- `identity-center`
- Delicat connected-account endpoint
- Pages containing `[delicat_identity_dashboard]`
- Pages containing `[delicat_security_center]`
- Pages containing `[delicat_connected_accounts]`
- Custom private page slugs configured in **Delicat Identity → Settings → Pages privées**

Default custom private slug: `my-wallet`.

Logged-out requests are redirected to the WooCommerce My Account login (or WordPress login fallback) with a safe return URL. The requested URL is also stored in a short-lived HttpOnly SameSite=Lax return cookie for compatible WooCommerce redirects.

## Protected response controls
For protected/account responses:
- `Cache-Control: no-store, no-cache, must-revalidate, private`
- `Pragma: no-cache`
- `X-Robots-Tag: noindex, nofollow, noarchive`
- `Referrer-Policy: same-origin`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `DONOTCACHEPAGE` is defined for protected frontend requests

## Stale-session defense
On every protected page, the customer account policy is re-evaluated. If a customer is subsequently marked pending/suspended or requires email re-verification, the current WordPress session token/auth cookie is revoked before protected content is rendered.

## My Account admin separation
The Identity dashboard now adds an **Administration** tab only when the current user has `manage_options`. It provides global Identity/security links and summary metrics. A customer forcing `?dip_tab=admin` is normalized back to the regular overview and never receives global data.

## WooCommerce capability correction
The WooCommerce Identity configuration previously accepted `manage_woocommerce`, which commonly includes shop managers. It now requires `manage_options`, matching the requested administrator-only security policy.

## REST ownership checks confirmed
- Mobile session listing filters by `get_current_user_id()`.
- Session deletion uses both session `id` and current `user_id`.
- Device/push/biometric updates resolve the currently bound mobile session.
- Customer device table updates/deletes require both `device_id` and current `user_id`.
- Customer audit history queries filter by current `user_id`.

## Operational note
Application/CDN caches that serve HTML before WordPress executes should also exclude private paths (at minimum My Account and configured private slugs). The plugin emits no-cache signals once WordPress runs, but an upstream cache can bypass PHP entirely if explicitly configured to cache authenticated/private pages.
