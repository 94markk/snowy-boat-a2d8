# Delicat Identity Pro 6.4.0 — Security Architecture

## Scope

This release is the first security-architecture phase built on top of 6.3.1. It preserves the rebuilt 6.3 login/register popup and existing WordPress/WooCommerce/mobile authentication flows while adding centralized access-control boundaries.

## Administrator / customer separation

Global Identity controls require the WordPress `manage_options` capability. This includes OAuth/provider settings, migration and repair tools, security exports, App Sync configuration, WooCommerce Identity configuration, analytics exports, email configuration/tests, Visual Builder administration, integrity tools, and SDK/support exports.

Customer self-service remains available only to the authenticated customer and is limited to that customer's own linked providers, devices, sessions, and password-setup flow. Device database updates already include both `device_id` and `user_id` in the write condition.

## Admin-post action registry

Static audit result:

- 50 registered Delicat Identity `admin-post` actions
- 38 administrator-only actions
- 8 customer self-service actions
- 4 intentionally public authentication/registration actions
- 0 unclassified registered actions
- 0 policy overlaps

Each registered action retains its module-level nonce validation. The central action firewall is an additional boundary, not a replacement for nonce/capability checks.


## AJAX firewall

The `dip_*` AJAX namespace is also classified. Native login/register/nonce/resend actions are intentionally public but remain browser-nonce protected; the connected-account status endpoint requires an authenticated user and returns only that user's data. Unknown `dip_*` AJAX actions are denied unless explicitly allowed by an extension filter.

## REST firewall

The entire `/delicat-identity/v1/` namespace is now deny-by-default when strict mode is enabled (default).

Known public routes are limited to authentication bootstrap operations such as mobile Google login, refresh, capabilities, biometric challenge/verification, and one-time pairing create/status/exchange. These routes still perform their own token/proof/rate-limit checks.

Protected mobile routes require the existing Delicat mobile access token plus matching Device ID and Installation ID. The central firewall re-checks the account policy and revokes mobile sessions if the account is no longer permitted.

Static audit result:

- 17 REST registrations
- 16 unique route paths
- all current routes classified as public-auth or device-bound mobile routes
- unknown Identity routes denied by default

## Private pages

Protected access is enforced before template content is rendered. Protection covers:

- WooCommerce My Account private endpoints (lost-password remains public)
- `[delicat_identity_dashboard]`
- `[delicat_security_center]`
- `[delicat_connected_accounts]`
- configured private page slugs
- pages marked with the new administrator-only **Delicat Identity — Accès → Connexion requise** page-editor control
- Elementor pages containing protected Identity shortcodes

The actual WooCommerce My Account login host page is excluded from slug protection to prevent redirect loops.

Private requests use no-store/no-cache headers, noindex, SAMEORIGIN/nosniff headers, and `DONOTCACHEPAGE` where WordPress executes the request.

## WordPress settings capability protection

The following options groups are explicitly forced to `manage_options`:

- `dip_group`
- `dip_email_group`
- `dip_builder_group`
- `dip_auth_methods_group`

This protects `options.php` writes even if a menu or UI is hidden incorrectly by another plugin.

## My Account

Normal customers see only self-service Identity/Security tools. The Administration tab is added only when the current WordPress user has `manage_options`; direct `?dip_tab=admin` requests from customers fall back to the normal customer view.

Administrators now see the Security Architecture health score and central control checks in My Account and the WordPress Security Center.

## Passwordless reliability/security fix

The passwordless nonce refresh script now uses the isolated native-auth AJAX nonce action introduced by the rebuilt login system. This prevents the old Code Snippets `delicat_fresh_nonces` callback from hijacking cached-page nonce refreshes.

## Preserved data

No intentional migration deletes or modifies WooCommerce orders, WordPress users, TeraWallet balances, linked Google/Microsoft accounts, or existing Delicat mobile sessions.

## Not included in this phase

Passkeys/WebAuthn and full TOTP 2FA are intentionally not implemented in 6.4.0. They should be added as a separate security phase because authentication-factor enrollment/recovery needs dedicated live/staging testing and recovery design rather than being mixed into the access-control release.
