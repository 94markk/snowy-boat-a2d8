# Delicat Identity Pro 6.8.0 — Passkeys / WebAuthn Security Phase

Date: 2026-08-10

## Scope

Version 6.8.0 extends the existing Delicat Identity security architecture with WebAuthn Passkeys while preserving the 6.7.0 TOTP 2FA, recovery codes, sensitive-action reauthentication, WordPress/WooCommerce account policy, mobile device binding, and the 6.6 premium My Account experience.

This phase is intentionally additive. It does not migrate or replace WordPress users, WooCommerce customers/orders, TeraWallet balances, linked OAuth providers, or existing mobile sessions as a data model.

## Security model

Passkeys are bound to the canonical WordPress Home URL host (RP ID) and exact configured origin. Production use requires HTTPS. User presence (UP) and user verification (UV) are mandatory for assertions, so authenticators must verify the user with an available local mechanism such as Face ID, Touch ID, Android biometrics, Windows Hello, device PIN, or a compatible security key.

The server stores only the credential identifier, public key, algorithm, signature counter, optional transport hints, label, creation time and last-use time. The private key remains with the authenticator. AAGUID is parsed only as part of the WebAuthn structure and is deliberately not persisted because the current verification policy does not need authenticator-model metadata.

Supported public-key algorithms in this release:

- ES256 / P-256 (`alg=-7`)
- RS256 (`alg=-257`)

## Passkey enrollment

Enrollment requires all of the following:

1. An authenticated current WordPress user.
2. Delicat self-service ownership policy for that exact current account.
3. A currently valid account according to Delicat Identity policy.
4. Recent sensitive-action reauthentication.
5. HTTPS and OpenSSL availability.
6. A browser-bound one-time WebAuthn ceremony with a five-minute lifetime.
7. Exact RP ID and origin validation.
8. User presence and user verification.

The browser is asked for privacy-preserving `attestation: none`. The plugin does not trust the registration metadata alone. After parsing the new credential public key, it starts a second, one-time WebAuthn assertion restricted to that newly created credential. The new Passkey is persisted only after the server verifies that assertion. This proves possession of the corresponding private key before enrollment is finalized.

Contradictory `id` and `rawId` values are rejected.

## Passwordless login

The Passkey login path is intentionally public only at the REST routing layer because the WebAuthn assertion itself performs authentication. It is still protected by:

- IP and identifier rate limits;
- a canonical per-account rate bucket for known accounts;
- fixed-size padded credential descriptors to reduce simple account/passkey-count enumeration;
- browser-bound, one-time ceremony state;
- exact challenge validation;
- exact origin validation;
- RP ID hash validation;
- required UP + UV flags;
- cryptographic signature verification;
- signature-counter replay/clone policy when both stored and returned counters are non-zero;
- current Delicat account-policy recheck immediately before issuing the WordPress session;
- the existing Delicat cookie-level second-factor boundary.

A successful UV-required Passkey assertion can satisfy Delicat's interactive second-factor requirement. TOTP and recovery codes remain available as fallback mechanisms for accounts that have configured them.

## Sensitive-action reauthentication

Existing users with an enrolled Passkey can confirm sensitive account operations with a Passkey. The reauthentication ceremony is bound to the currently authenticated account and browser and updates the existing session-bound recent-auth state only after a valid assertion.

Enrollment and deletion remain protected by recent authentication. Removing the final Passkey is rejected when the account has no other usable primary login method such as a customer-managed password or a linked Google/Microsoft provider.

## Concurrency and replay protections

Version 6.8.0 adds atomic option-based locks around:

- ceremony consumption;
- per-account Passkey counter updates;
- Passkey enrollment finalization;
- Passkey deletion;
- Passkey rate-limit read/modify/write operations.

This reduces race windows across concurrent PHP workers or persistent object-cache deployments.

Ceremonies are consumed once regardless of success or failure. Stale locks have short recovery windows.

For authenticators that implement a non-zero signature counter, a non-increasing non-zero counter is rejected and logged as a critical security event. Authenticators that legitimately always return zero remain supported.

## REST access control

Passkey REST routes are explicitly classified by the Delicat Identity deny-by-default REST firewall.

Intentionally public authentication routes:

- `POST /delicat-identity/v1/passkeys/login/options`
- `POST /delicat-identity/v1/passkeys/login/verify`

Cookie-authenticated current-user routes:

- `POST /passkeys/register/options`
- `POST /passkeys/register/verify`
- `POST /passkeys/register/proof`
- `POST /passkeys/reauth/options`
- `POST /passkeys/reauth/verify`
- `DELETE /passkeys/{credential}`

The customer routes additionally rely on WordPress REST cookie/nonce authentication, Delicat self-service ownership checks, and account-policy checks. Registration/removal require recent authentication.

Unknown Delicat Identity REST routes continue to be denied when the strict REST firewall is enabled.

## Recovery

Passkeys do not remove the existing TOTP/recovery-code recovery design. The system also includes a server-console-only emergency Passkey reset:

`wp delicat identity-passkeys-reset <user-id|username|email>`

This removes Delicat Passkeys for that account and revokes WordPress and Delicat mobile sessions. There is no public customer or wp-admin "bypass Passkeys" button.

## Security recommendations

The customer Security Center can recommend adding a Passkey when the account has none. The administrator recommendations panel evaluates Passkey adoption across administrator accounts without exposing credential IDs, public keys, TOTP secrets, recovery codes, or OAuth secrets.

Passkeys now contribute to the unified customer security score alongside TOTP, verified email, linked login methods, trusted devices and HTTPS.

## Privacy choices

- `attestation: none` is requested.
- AAGUID is not persisted.
- Private keys never enter WordPress.
- Public login responses use padded descriptor lists rather than exposing a Passkey count.
- Audit events store only short hashes of credential identifiers where needed.
- Passkey REST responses are covered by `no-store`, `no-cache`, `private`, `nosniff`, no-referrer and frame-denial headers from the Identity REST security layer.

## Static validation performed

The release tree was checked for:

- PHP syntax;
- JavaScript syntax;
- CSS parser errors;
- Delicat static class/method references;
- classified admin-post actions;
- classified Delicat Identity REST routes;
- empty files, symlinks and packaged junk;
- dangerous PHP execution primitives;
- direct public exposure of Passkey user meta;
- ES256 COSE-to-PEM conversion/signature verification;
- RS256 COSE-to-PEM conversion/signature verification;
- synthetic privacy-preserving WebAuthn `fmt=none` attestation parsing;
- rejection of non-empty attestation statements when `fmt=none` is expected.

## Operational requirements / staging tests

Static analysis cannot emulate a physical authenticator or browser security UI. Before enabling Passkeys broadly, test on the real HTTPS Delicat domain with at least:

- iPhone Safari + Face ID/Touch ID;
- Android Chrome + device biometrics/PIN;
- Windows Hello where applicable;
- a cross-device/synced Passkey if used by customers;
- an external security key if supported operationally;
- enrollment, login, reauthentication and deletion;
- TOTP/recovery fallback after a Passkey is unavailable;
- canonical www/non-www redirect behavior;
- Cloudflare/Hostinger HTTPS detection and cache exclusions;
- WordPress/WooCommerce normal password login regression;
- Google/Microsoft login regression;
- mobile API regression.

The WordPress Home URL should be the canonical hostname actually used by customers. WebAuthn deliberately rejects origin/RP mismatches.

## Residual recommendations

For a later controlled phase:

1. Require MFA for administrators only after every administrator has enrolled and tested at least two recovery-capable methods.
2. Add optional passkey-first / username-less discoverable-credential login after broader device testing.
3. Add administrator enrollment compliance reporting with scheduled reminders rather than hard lockout on day one.
4. Consider a dedicated FIDO/WebAuthn conformance library or certification process if Delicat Identity is later distributed as a general-purpose authentication product beyond the Delicat Store environment.
5. Keep WordPress core, WooCommerce, PHP/OpenSSL, Hostinger, Cloudflare and the device/browser matrix updated and tested.

## Audit limitation

This is a static code/security review plus local cryptographic structure tests. It is not a claim that the software has zero vulnerabilities, and it is not a FIDO conformance certification. Real authenticator, browser, reverse-proxy and production-domain testing remains required.
