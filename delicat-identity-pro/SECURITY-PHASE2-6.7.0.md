# Delicat Identity Pro 6.7.0 — Identity Security Phase 2

## Scope

This release extends the existing 6.4/6.6 security architecture without replacing WordPress, WooCommerce, TeraWallet, OAuth, customer data, or the premium My Account UI. The focus is authentication strength, recovery safety, sensitive-action protection, mobile parity, and actionable security recommendations.

This is a static/code audit and hardened implementation. It is not a claim that no vulnerability exists. Production staging tests are still required on the real Hostinger/Cloudflare/WooCommerce/mobile environment.

## 1. TOTP two-factor authentication

- Standard 6-digit TOTP with a 30-second step and a small clock-skew window.
- Uses HMAC-SHA1 for Authenticator compatibility.
- TOTP secret is generated with `random_bytes(20)` and Base32 encoded.
- Secret is stored only through the existing `DIP_Crypto` authenticated-encryption layer.
- Once 2FA is marked enabled, inability to decrypt the secret fails closed; the account does not silently downgrade to password-only login.
- Enrollment requires an authenticated current-user session, WordPress nonce, recent authentication, HTTPS, and a successful Authenticator code before activation.
- New enrollment can be disabled globally without disabling already-enabled 2FA accounts.

### TOTP replay and concurrency controls

- The last accepted TOTP counter is stored per user.
- A previously accepted or older counter is rejected.
- TOTP replay-state updates use a short `add_option()`-based atomic lock.
- Recovery-code consumption uses a separate short atomic lock.
- Factor rate-limit bucket updates use a third short lock so parallel guesses cannot all read the same pre-increment counter.

The HOTP primitive was checked against the RFC 4226 counter-1 test vector and returned `287082`.

## 2. Recovery codes

- 10 recovery codes are generated on 2FA enrollment.
- New codes use 64 bits of random source data each, formatted in four readable groups.
- Persistent storage contains only `wp_hash_password()` hashes.
- Plaintext codes are held only in a session-bound encrypted transient for up to 10 minutes so the customer can save them.
- Each recovery code is consumed once.
- Regenerating recovery codes invalidates all previous recovery codes.
- Recovery-code regeneration requires recent authentication plus a fresh TOTP code.
- Disabling 2FA requires recent authentication plus a valid TOTP or recovery code.

## 3. Factor rate limiting

2FA attempts now use two simultaneous buckets:

- user + resolved client IP: 8 attempts / 10 minutes;
- account-wide: 20 attempts / 10 minutes.

These limits protect password login, browser second-factor challenges, mobile Google login, reauthentication, 2FA setup confirmation, recovery-code regeneration, and 2FA disable operations.

A successful second-factor verification clears the factor rate buckets.

## 4. Final authentication boundaries

Two independent protections now exist around WordPress password/cookie login:

1. the normal `authenticate` 2FA gate validates the second factor after the password authenticator succeeds;
2. a final high-priority authentication gate prevents a later third-party authenticator from replacing a 2FA error with a valid `WP_User` object.

A separate `send_auth_cookies` boundary blocks direct `wp_set_auth_cookie()` paths for 2FA-enabled users unless the request has already completed a Delicat second factor. Any unused WordPress session token created before that filter is destroyed.

## 5. WordPress Application Passwords

Application Passwords are separate API credentials and cannot present a TOTP prompt. For any account with Delicat 2FA enabled:

- Application Password authentication is rejected by default using WordPress' dedicated application-password authentication error hook;
- the WordPress management UI can remain visible so old credentials can be reviewed and deleted;
- an explicit developer filter is required to opt a protected account back into this parallel authentication method.

Customer Application Password availability remains subject to the existing Delicat customer policy as well.

## 6. Sensitive-action reauthentication

`DIP_Reauth` adds a recent-authentication marker bound to the exact WordPress session token.

Default window: 10 minutes (configurable from 5–60 minutes).

A recent login or explicit confirmation marks only the current browser session as recently authenticated. Confirmation supports:

- current WordPress password; or
- TOTP / one-time recovery code when 2FA is enabled.

Sensitive self-service actions pass through the centralized Access Guard before their own handler runs. Protected actions include device trust/removal, logout/revoke-other-sessions, provider unlink, password-setup operations, 2FA enrollment/confirmation/disable, and recovery-code regeneration.

WooCommerce My Account email/password changes are additionally blocked unless the current session is recently authenticated. Basic name/display-name edits stay usable without unnecessary prompts.

## 7. Social and passwordless login

For an account with 2FA enabled:

- Google/Microsoft OAuth does not create a WordPress auth cookie after the provider callback;
- OTP/magic-link passwordless login does not create a WordPress auth cookie after email verification;
- instead, Delicat creates a five-minute browser-bound pre-authentication challenge;
- the random challenge token is stored in an HttpOnly SameSite cookie, not exposed in the URL;
- state is bound to the existing Delicat anonymous browser token;
- only after TOTP/recovery verification is the WordPress auth cookie issued.

The challenge is no-store/noindex, denies framing, uses no-referrer/nosniff headers, requires HTTPS, and uses a nonce-based CSP style policy.

## 8. Mobile authentication parity

Accounts with 2FA enabled also require a second factor before a new mobile Google session can be issued.

`POST /wp-json/delicat-identity/v1/mobile/google` behavior:

- missing factor: HTTP 428 / `two_factor_required`;
- invalid factor: HTTP 401 / `two_factor_invalid`;
- rate limited: HTTP 429 / `two_factor_rate_limited`;
- retry with the same Google ID token request plus `two_factor_code`.

The factor can be a current TOTP or one-time recovery code.

When 2FA is enabled or disabled, all Delicat mobile sessions are revoked. The app must log in again under the new security state.

### Android/iOS integration requirement

The mobile client must handle 428 `two_factor_required`, prompt the customer for an Authenticator/recovery code, and retry the Google login request with `two_factor_code` while preserving its required Device ID and Installation ID headers.

## 9. App Sync pairing

A 2FA-protected web pairing exchange is allowed only when the exact mobile session that approved the pairing is still active.

This prevents an approval made before a security transition from surviving after 2FA enrollment/session revocation. The pairing remains one-time and browser-secret bound.

## 10. Administrator recovery design

This release intentionally does **not** create a web-based administrator bypass for 2FA.

Emergency recovery is server-console/WP-CLI only:

```text
wp delicat identity-2fa-reset <user-id|username|email>
```

The command:

- removes Delicat TOTP/recovery state for that user;
- revokes every WordPress session for that user;
- revokes every Delicat mobile session for that user;
- records a critical audit event.

Administrator 2FA is not globally forced yet. The admin recommendations panel first identifies every administrator missing 2FA and any protected administrator with too few recovery codes. This avoids introducing an unsafe lockout policy before all administrators have enrolled and stored recovery material.

## 11. Security recommendations engine

### Customer recommendations

The Security Center can recommend, based only on the current customer's own data:

- enable 2FA;
- renew recovery codes when fewer than two remain;
- verify email;
- link a secondary Google/Microsoft login method;
- approve a trusted primary device;
- use HTTPS.

### Administrator recommendations

The administrator-only recommendation panel checks:

- every Administrator account for 2FA coverage;
- administrator recovery-code health;
- existing Administrator Application Passwords that should be reviewed/removed;
- strict Delicat REST firewall;
- server encryption availability;
- HTTPS;
- production `WP_DEBUG` state;
- brute-force lockout;
- trusted-device tracking;
- new-device email alerts;
- private-page configuration;
- `DISALLOW_FILE_EDIT` production hardening;
- `FORCE_SSL_ADMIN` recommendation when HTTPS is already functioning.

No TOTP secret, recovery code, OAuth secret, token, or password is exposed in the recommendation output.

## 12. Existing security preserved

This phase keeps the prior controls, including:

- deny-by-default Delicat REST route firewall;
- device + installation binding for protected mobile API requests;
- current-user ownership checks for customer devices/sessions/orders;
- administrator-only global Identity settings;
- private My Account/page protection;
- no-store/noindex private response headers;
- customer suspension/email-verification revalidation;
- OAuth state/PKCE/nonce validation;
- Google/Microsoft immutable provider identifiers;
- brute-force lockouts;
- encrypted OAuth secrets;
- audit redaction;
- WooCommerce customer/role boundary protections.

## 13. Static validation performed

Working-tree validation before packaging:

- PHP files: 46, all `php -l` clean.
- JavaScript files: 8, all `node --check` clean.
- CSS files: 11, tinycss2 parser errors: 0.
- DIP classes: 45.
- unresolved internal static method references: 0.
- registered admin-post actions: 56.
- admin-only: 38.
- customer self-service: 13.
- intentionally public auth actions: 5.
- unclassified admin-post actions: 0.
- policy overlaps: 0.
- REST registrations: 17 / 16 unique route paths.
- zero-byte files: 0.
- symlinks: 0.
- dangerous PHP execution primitives (`eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, raw `unserialize`): none found.

The one raw `wp_redirect()` remains the OAuth provider redirect after the existing HTTPS/provider-host validation; internal/customer destinations use safe/validated redirects.

## 14. Required staging tests before production enforcement

1. WordPress `wp-login.php`: password-only user, correct 2FA, bad 2FA, recovery code, replayed TOTP.
2. Native Delicat popup: hidden second-factor field, reveal on `dip_2fa_required`, successful retry.
3. WooCommerce My Account login: same cases.
4. Google OAuth: provider callback must stop at the second-factor challenge before a WordPress cookie exists.
5. Passwordless OTP/magic: must stop at the second-factor challenge.
6. Application Password: verify it is rejected for an enabled-2FA Administrator while still visible for cleanup.
7. Sensitive actions: email/password change, provider unlink, device removal, session revoke, 2FA disable/regenerate.
8. Session revocation: enabling/disabling 2FA must invalidate other browser sessions and all mobile sessions as designed.
9. Android/iOS: 428 handling and retry with `two_factor_code` + Device ID + Installation ID.
10. App Sync: valid active approving mobile session succeeds; revoked/stale session cannot mint a browser cookie.
11. WP-CLI recovery: test on a staging administrator and confirm all sessions are revoked.
12. Hostinger/Cloudflare: ensure HTTPS is correctly detected at WordPress origin and protected account/API paths are excluded from shared full-page caching.
13. Server time: confirm NTP/time synchronization; TOTP depends on accurate server/device clocks.
14. SMTP: verify 2FA/security notification delivery and spam-folder behavior.

## 15. Next recommended phase

After 6.7.0 is proven stable in production-like staging:

1. Passkeys/WebAuthn with Face ID, Touch ID and Android platform authenticators.
2. Optional administrator 2FA enforcement **only after** all administrators are enrolled and recovery readiness is confirmed.
3. Dual-channel email-change confirmation (old address warning + new address proof) and more explicit sensitive-change notifications.
4. Passkey/recovery health dashboard and credential inventory.
5. Automated authorization regression tests for anonymous vs Customer A vs Customer B vs Administrator across every REST/admin-post/customer object path.
6. Security-key/passkey-based reauthentication for the highest-risk administrator actions.

Passkeys are intentionally not included in this release because RP ID/origin handling, credential lifecycle, lost-device recovery, cross-device passkeys, and administrator recovery need dedicated live browser/device testing.
