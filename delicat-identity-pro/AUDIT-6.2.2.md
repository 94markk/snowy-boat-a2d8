# Delicat Identity Pro 6.2.2 — Security, UI & WordPress/WooCommerce Synchronization Audit

Date: 2026-08-07  
Scope: static source review and hardening of the Delicat Identity Pro plugin package, including native login/register modal, Google/Microsoft identity, WooCommerce integration, OTP/magic-link, trusted devices, mobile API and web/app pairing.

## Executive summary

This release consolidates Delicat authentication behind one account-policy layer instead of allowing separate WordPress, WooCommerce, social, passwordless and mobile paths to make independent trust decisions.

The most important fixes in 6.2.2 are:

1. Google ID tokens are verified locally with RS256 against Google's JWK set. The previous production dependency on Google's `tokeninfo` debugging endpoint is removed.
2. Google `sub` is the durable identity key. A Gmail or verified Google Workspace address can prove email ownership; a third-party Google address must additionally pass Delicat local email verification or be linked explicitly by an already-authenticated customer.
3. Microsoft mutable `email` / `preferred_username` claims are not accepted as automatic account ownership. The signed immutable provider identity remains the link key.
4. WooCommerce direct-login paths are guarded at the WordPress auth-cookie boundary. If Delicat policy blocks a customer, the pending WordPress session token is destroyed and WooCommerce's in-request current-user assignment is cleared.
5. Email verification is tied to the exact current `user_email`. Changing a customer email invalidates the proof and revokes WordPress plus Delicat mobile sessions until the new email is verified.
6. Customer Application Passwords are disabled by default so they cannot become a parallel API authentication route outside Delicat's customer policy. Staff/admin users are intentionally not affected.
7. Magic links and verification links are scanner-safe: GET only displays a confirmation page; the security-changing action requires a browser-bound POST confirmation.
8. Native modal, OTP, magic-link and registration actions use browser-bound, cache-resistant nonces and explicit POST actions.
9. Registration role selection is constrained by a capability boundary to prevent public account creation from becoming an author/editor/shop manager/admin through a third-party filter.
10. Mobile access tokens remain separate from WordPress cookies, require device/installation binding, and are revoked after relevant account-security changes.
11. Public registration state is centralized across WordPress and WooCommerce (My Account + checkout account creation), so native, social/mobile and passwordless paths cannot drift from the store account-creation policy.
12. The native modal reflects that state in the UI by hiding the registration tab when public registration is closed.
13. Social-only password fallback detection now relies on an actual password-hash change, not merely the presence of a POST field during profile updates.

No static audit can prove that a WordPress installation is “100% secure.” Runtime behavior also depends on WordPress/WooCommerce versions, the active theme, other plugins, Cloudflare/Hostinger configuration, SMTP, HTTPS/TLS, OAuth console settings and the production database. The release therefore includes a staging test matrix below.

## Findings fixed

### Critical / high-impact

#### Google production token validation
**Previous risk:** production validation could rely on `tokeninfo`, an endpoint Google documents for development/debugging.  
**Fix:** `DIP_Google::verify_id_token()` now performs local JWT verification with Google's JWKs and validates RS256, `kid`, signature, `iss`, `aud`, `azp` when applicable, `exp`, `nbf`, `iat`, nonce and `sub`. JWK responses are size bounded, cached and refreshed once on key rotation.

#### Google third-party email account linking
**Previous risk:** `email_verified=true` alone can be insufficient proof of current ownership for a non-Gmail/non-Workspace address.  
**Fix:** automatic local-email ownership is limited to Gmail or verified Google Workspace (`hd`) cases. Other Google accounts require Delicat's exact-email proof or an explicit link initiated by the already-authenticated local customer. Existing legacy links without hardened proof are remediated by requiring local verification before login.

#### Microsoft mutable email linking
**Previous risk:** Microsoft `email` / `preferred_username` can change and must not be an authorization/account-ownership key.  
**Fix:** automatic Microsoft account takeover/linking by mutable email is disabled. The signed provider identity is used as the durable link. A new local email must be verified by Delicat.

#### WooCommerce direct authentication paths
**Previous risk:** WooCommerce can create a customer and call `wc_set_customer_auth_cookie()` directly, bypassing `wp_signon()` and therefore bypassing a policy enforced only in the `authenticate` filter.  
**Fix:** Delicat re-runs policy at the end of WordPress authentication and also hooks `send_auth_cookies` as an independent final boundary. If blocked, the unsent session token is destroyed and the in-request WordPress current user is cleared.

#### Verification proof survived email changes
**Previous risk:** a previously verified account could change its WordPress email while retaining old verification state.  
**Fix:** the verified state now contains an exact normalized-email fingerprint plus hardened v2 proof marker. Customer email changes invalidate verification, previous-order-link synchronization state and pending verification data; all WordPress and Delicat mobile sessions are revoked and the new address must be verified.

#### Parallel customer API authentication
**Previous risk:** WordPress Application Passwords could provide a customer API authentication path outside Delicat mobile/session policy.  
**Fix:** Application Passwords are disabled for storefront customer accounts by default through WordPress's per-user availability filter. Staff/admin behavior is unchanged. A deliberate compatibility filter exists for installations that truly require customer Application Passwords.

#### Magic-link / verification-link GET side effects
**Previous risk:** email security scanners/prefetchers may open links automatically; a GET that immediately logs in or verifies a user is unsafe and can consume the link.  
**Fix:** GET is read-only and displays a hardened confirmation page. Mutation/login occurs only after explicit browser-bound POST confirmation. Email verification no longer auto-logs the customer in.

### Medium-impact hardening

- Existing WordPress accounts now use the same opaque User-ID-based brute-force bucket whether an attacker submits the account email or `user_login`, preventing alias rotation from doubling account-wide attempts. Unknown identifiers stay generic and are never exposed.
- The social OAuth lockout bucket uses the trusted resolved client IP only; rotating User-Agent strings no longer creates a fresh failure bucket, while audit fingerprints still distinguish browser context.
- OAuth cancellation/error callbacks consume and validate their one-time state before displaying an error and do not advance the lockout counter for unsolicited/cancelled callbacks.
- OAuth flows use state-specific HttpOnly cookies so two simultaneous Google/Microsoft login attempts in separate tabs do not overwrite each other.
- Runtime and Foundation database schema versions use separate option keys, preventing repeated frontend dbDelta/install work.
- WooCommerce-created Delicat customers are protected at the final `woocommerce_new_customer_data` filter priority so third-party filters cannot replace the intended user ID/login/email/password/safe role or inject privilege-bearing meta.
- WordPress/WooCommerce registration availability is resolved once and shared with native, OAuth/mobile, OTP, magic-link and custom-registration flows.
- New-device notifications are provider-neutral and trusted-device IP hashes use the same explicitly trusted proxy resolver as authentication throttling.
- WooCommerce return cookies are removed with matching Secure/HttpOnly/SameSite attributes after consumption.
- Native brute-force protection uses account+IP, IP-wide and account-wide buckets with progressive lockouts. The account-wide threshold is deliberately higher than account+IP to reduce attacker-induced denial of service.
- Policy failures such as “email not verified” or “pending approval” do not count as incorrect password guesses.
- Registration has both a successful-account limit and a short quota covering unsuccessful/invalid attempts.
- Existing-email registration responses are less useful for account enumeration.
- OTP state is bound to the requesting browser/device, limited to a small number of attempts, expires, and cannot be overwritten freely by another browser.
- Magic-link records are one-time and token-specific; email data is encrypted in transient state when crypto is available and no longer appears in new link URLs.
- Password changes and password resets revoke Delicat mobile sessions.
- Pending/suspended customer policy revokes active WordPress/mobile sessions where applicable.
- Mobile bearer auto-authentication is restricted to Delicat Identity REST routes and does not create a general WordPress REST authentication side effect.
- Protected mobile routes require a Delicat mobile session plus matching device and installation identifiers.
- Refresh tokens rotate, are stored only as hashes and reject replay.
- Biometric challenges are installation-bound, short-lived and one-time.
- Push tokens are encrypted at rest.
- App pairing uses a random pairing secret, browser binding, expiration, rate limits and atomic approved→consumed transition.
- OAuth flow `state` and browser cookies use strict formats; callback authorization codes are size bounded.
- External OAuth authorization destinations are restricted to approved HTTPS hosts.
- Google/Microsoft token and JWK HTTP responses are size bounded and redirects are disabled in security-sensitive calls.
- OAuth secrets are encrypted at rest; activation upgrades plaintext/legacy v1 secrets to v2 authenticated encryption when cryptography is available.
- Cloudflare visitor IP headers are ignored unless the installation explicitly opts into trusting Cloudflare, preventing direct-origin header spoofing.
- Destructive connected-account, trusted-device and session controls require POST + nonce rather than GET side effects.
- Trusted web-device identity now uses a random first-party HttpOnly/SameSite cookie and stores only its HMAC rather than treating User-Agent as a unique device.
- Public registration roles are checked against dangerous WordPress/WooCommerce capabilities. A third-party role filter cannot silently create a privileged public user.
- Past-order association is performed only after exact-email ownership is proven and is idempotent per verified email.

## WordPress / WooCommerce synchronization matrix

The platform registration switch recognizes WordPress `users_can_register`, WooCommerce `woocommerce_enable_myaccount_registration`, and WooCommerce `woocommerce_enable_signup_and_login_from_checkout`. Delicat's own `allow_registration` setting can further restrict account creation.

| Entry point | Shared customer policy | Exact email verification | Woo customer lifecycle | Session/device sync | Result |
|---|---:|---:|---:|---:|---|
| Native Delicat email/password modal | Yes | Yes | Yes | Yes | Unified |
| WordPress `wp-login.php` customer login | Yes | Yes | N/A | Yes | Unified |
| WooCommerce My Account login | Yes | Yes | Yes | Yes | Unified |
| WooCommerce My Account registration | Yes | Yes when required | Yes | Yes | Unified |
| Classic WooCommerce checkout account creation | Final cookie boundary | Yes when required | Native Woo flow preserved | Yes | Unified |
| WooCommerce Store API / Blocks customer creation | Final cookie boundary | Yes when required | Native Woo flow preserved | Yes | Unified policy boundary |
| Google web OAuth | Yes | Authoritative Google email or local proof | Yes | Yes | Unified |
| Microsoft web OAuth | Yes | Local proof for new/mutable email | Yes | Yes | Unified |
| OTP login | Yes | OTP proves exact email | Yes | Yes | Unified |
| Magic-link login | Yes | Link proves exact email after POST confirm | Yes | Yes | Unified |
| Email verification | Yes | Exact current `user_email` | N/A | Revokes stale sessions on change | Unified |
| Delicat mobile Google login | Yes | Same Google/local-email rules | Same WordPress user | Device-bound mobile session | Unified |
| Delicat mobile refresh | Rechecked each session | Rechecked | N/A | Rotating/replay protected | Unified |
| Delicat biometric login | Rechecked | Rechecked | N/A | Installation-bound | Unified |
| Web/app pairing | Rechecked before exchange | Rechecked | Same WordPress user | Atomic + browser/mobile binding | Unified |
| Customer email change | Forces re-verification | Yes | Woo customer unchanged | WP + mobile sessions revoked | Unified |
| Password change/reset | Policy preserved | Preserved | Woo customer unchanged | Mobile sessions revoked | Unified |
| WordPress Application Password | Disabled for customer by default | N/A | N/A | No bypass | Hardened |

## UI bugs fixed / hardened

- The Visual Builder no longer uses only `users_can_register`; its registration link now follows the same Delicat + WordPress/WooCommerce registration state as the native modal.
- When the native modal is enabled, Visual Builder’s “Créer un compte” opens the registration tab directly; when disabled, it falls back to the appropriate WordPress/WooCommerce registration destination.
- A single remaining Login tab no longer loses its active/ARIA state when Left/Right arrow keys are pressed.
- Visual Builder preview links use explicit identifiers, preventing “Mot de passe oublié” from being mistaken for the registration link when registration is unavailable.
- Modal is isolated from common WordPress/theme button/input styles.
- Mobile inputs use 16px on small screens to avoid iOS automatic zoom.
- Safe-area padding is included for iPhone notches/home indicator.
- Very short screens can scroll the overlay without trapping content below the viewport.
- Modal has focus trapping, Escape-to-close, focus restoration and keyboard navigation between tabs.
- `:focus-visible` treatment is present for tabs, social login, close, password-eye, links and actions.
- Reduced-motion preference disables nonessential modal/provider animation.
- Network requests have cancellation/timeout behavior so a closed modal does not continue updating stale UI.
- Nonce-dependent actions remain disabled until fresh browser-bound nonces are loaded.
- Server verification errors can expose the safe “resend verification” action without exposing sensitive provider data.
- Google text/alignment parameters from `[delicat_social_login_buttons]` are honored.
- The Google “select an account” setting now reaches the OAuth request.
- Microsoft automatic email-link UI no longer advertises a behavior intentionally disabled by security policy.
- `[delicat_account_button]` now uses its own shortcode metadata rather than the login-button tag.
- Dashboard provider layout now includes the already-supported `row` option.
- Invalid `font:700 inherit` button CSS was corrected to valid inherited font + explicit weight.
- Destructive buttons use real POST forms instead of links, reducing accidental prefetch/navigation actions.

## Static validation performed

- 60 plugin files inspected in the final working tree.
- 40 PHP files: PHP syntax validation passed.
- 6 JavaScript files: Node syntax validation passed.
- 10 CSS files: full stylesheet parsing with `tinycss2` passed with 0 parse errors.
- 39 `DIP_*` classes detected; no unresolved internal static method calls detected by the audit scanner.
- No plugin PHP use of `eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen` or raw `unserialize` found.
- No raw `wp_remote_get/post/request` calls found in the security-sensitive code; WordPress safe HTTP APIs are used for provider requests.
- The only raw `wp_redirect()` is the OAuth authorization redirect after the URL has passed Delicat's HTTPS/provider-host allowlist. Customer redirects use `wp_safe_redirect()`.
- No empty files in the final working tree.
- No symlinks or packaged `.zip`, `.bak`, `.tmp` or `.DS_Store` files in the final working tree.

## Required staging/runtime test matrix

Static validation is not a replacement for integration testing. Before production deployment, perform the following in a staging copy of the real site:

1. **Native modal**: incorrect password ×5, lockout message, successful login after expiry, Remember Me, logout, lost-password link, registration, duplicate email and verification resend.
2. **Google web**: new Gmail customer, existing Gmail customer, Google Workspace customer, third-party Google-email customer, changed Google email, canceled OAuth, stale callback, replayed state, explicit connected-account linking/unlinking.
3. **Microsoft**: new customer, existing explicitly linked customer, mutable email mismatch, unlink safety and cancellation.
4. **Woo My Account**: native registration with verification enabled; confirm the account is created but cannot auto-login until verified.
5. **Classic checkout**: create account during checkout; order creation must still succeed according to Woo settings, but an unverified account must not retain an authenticated customer cookie.
6. **Checkout Blocks / Store API**: repeat account creation and confirm the same final session policy.
7. **Email change**: change a customer email in My Account/profile; confirm the old browser session and mobile app session cease to authorize subsequent requests and the new email requires verification.
8. **Password reset/change**: confirm mobile refresh/access credentials are invalidated as intended.
9. **OTP**: correct code, incorrect code ×5, expiry, request from a second browser, unknown email and new-user setting.
10. **Magic link**: allow an email-security scanner/browser GET; confirm it does not consume or log in. Then confirm manually and verify one-time use/replay failure.
11. **Mobile Google**: Android and iOS client IDs, correct/incorrect audience, device/installation mismatch, refresh rotation, refresh replay, logout, revoked session.
12. **Biometric**: register key, issue challenge, valid signature, replay, different installation and disabled feature.
13. **App pairing**: create/status/approve/exchange, wrong browser token, wrong secret, expired request, duplicate exchange and blocked/unverified customer.
14. **WooCommerce order history/wallet**: ensure verified customers keep the same user ID and therefore preserve orders, TeraWallet data and transaction history.
15. **Performance/UI**: iPhone Safari, Android Chrome, low-end phone, 320px width, landscape/short height, dark theme active in site, LiteSpeed cache and Cloudflare cache.

## Deployment checklist

1. Take a full WordPress files + database backup.
2. Test this ZIP in staging before production.
3. Disable the old Code Snippets login/register modal. The modal is now native to Delicat Identity and running both would duplicate AJAX hooks, UI and lockouts.
4. Keep HTTPS enforced for the website and OAuth callback.
5. Confirm Google and Microsoft callback URIs exactly match the URLs shown in Delicat Identity settings.
6. Confirm Android/iOS Google OAuth client IDs in the mobile settings.
7. Confirm SMTP/mail delivery before requiring email verification, OTP or magic links.
8. If using `CF-Connecting-IP`, enable Delicat's Cloudflare trust only when the WordPress origin cannot be reached directly except through trusted Cloudflare infrastructure.
9. Purge LiteSpeed, CDN/Cloudflare and browser caches after updating.
10. Test WooCommerce checkout, My Account, wallet and mobile app using real test customer accounts before opening the update to all customers.

## Compatibility/data-preservation notes

- Plugin directory remains `delicat-google-login` so WordPress updates the existing plugin instead of installing a parallel identity plugin.
- No intentional deletion of WordPress users, WooCommerce orders, wallet balances or transaction history is part of the upgrade.
- Existing Google/Microsoft subject links are preserved; only unsafe legacy trust assumptions may require the affected customer to verify their current local email once.
- Existing mobile token tables are preserved. Security-triggered revocation is intentional when email/password/pending-state changes require it.
- Existing OAuth secret values are preserved if v2 encryption cannot be completed; the plugin does not overwrite a usable secret with an empty value.

## Residual risk / limitations

- Security depends on the surrounding WordPress installation. A compromised administrator, vulnerable plugin/theme, stolen hosting credentials or database compromise can defeat an authentication plugin.
- Email verification depends on trustworthy email delivery and the security of the customer's mailbox.
- Device cookies identify a browser installation, not a physical person; clearing cookies creates a new device identity.
- IP-based throttling is only one signal and shared/mobile networks require a balance between abuse resistance and false lockouts.
- Custom code can intentionally override some Delicat filters. Treat new filters/plugins that change authentication, roles, cookies or REST authentication as security-sensitive changes and re-audit after installation.

