# Delicat Identity Pro 6.8.1 — Admin Google Secure Mode

## Purpose

Version 6.8.1 keeps Google sign-in available for a WordPress Administrator without allowing Google email matching, legacy social metadata, or a generic social-login setting to become an Administrator-session bypass.

## Administrator Google login policy

An Administrator Google login is allowed only when all of these are true:

1. HTTPS is active.
2. The provider is Google. Microsoft and other social providers cannot open Administrator sessions.
3. The immutable Google `sub` already belongs to that exact WordPress user.
4. The link was freshly re-verified through Google OAuth from an already authenticated Administrator session.
5. The approved link marker matches the user ID, exact Google `sub`, and current WordPress role fingerprint.
6. TOTP 2FA is enabled for the Administrator.
7. After Google finishes the first factor, Delicat still completes the browser-bound TOTP/recovery-code challenge before any WordPress authentication cookie is issued.

A matching Google email alone is never sufficient.

## Safe migration for an existing Administrator Google link

Existing/legacy Google links are deliberately **not silently trusted** after update.

Recommended sequence while your current Administrator session is still open:

1. Install 6.8.1 without logging out.
2. Open My Account → Security / Identity Security Center.
3. Make sure TOTP 2FA is active and your recovery codes are saved offline.
4. Confirm your identity in the reauthentication card if required.
5. In **Google Secure Mode — Administrateur**, select **Vérifier Google et autoriser cet Administrateur**.
6. Complete the fresh Google OAuth round trip with the exact Google account you want bound to the Administrator.
7. Test Google login in a separate private/incognito browser.
8. Confirm that Google is followed by the Delicat TOTP/recovery-code challenge.
9. Keep at least one independent recovery method: Passkey plus TOTP recovery codes is strongly recommended.

The plugin does not revoke the currently active Administrator session merely because 6.8.1 is installed. This lets the site owner complete the secure migration without creating an automatic lockout.

## Additional fixes

- Provider-link callbacks no longer issue a new WordPress authentication cookie. Linking is treated strictly as a configuration action.
- If a Google/Microsoft identity is already linked to another WordPress user, the link attempt is rejected inside the Identity resolver before avatar/name/provider metadata can be synchronized to the other account.
- Editors, Shop Managers, and other privileged non-Administrator accounts are always blocked from social login. This is no longer a downgradeable checkbox policy.
- If TOTP is disabled on an Administrator, the Google Secure Mode approval marker is revoked.
- Disconnecting Google revokes its Administrator Secure Mode approval.
- The approval marker is HMAC-bound to the Administrator ID, immutable Google subject, and current WordPress role fingerprint.
- Enabling/revoking Admin Google Secure Mode records security events and notifies the Administrator by email.
- Approving or revoking the secure Google relationship requires a current Administrator session and recent reauthentication; approval additionally requires a fresh Google OAuth proof.
- Existing Delicat 2FA `send_auth_cookies` boundary continues blocking parallel plugins from issuing a usable cookie for a 2FA-enabled account unless Delicat has authorized the second factor.

## Security Center behavior

The customer-facing Security Center shows the Admin Google Secure Mode card only to the current WordPress Administrator. It never exposes OAuth client secrets, tokens, the raw Google subject, TOTP secrets, Passkey private keys, recovery-code hashes, or global configuration to customers.

For Administrator security scoring, an old Google link does not count as a protected social method until Secure Mode approval is valid.

## Validation performed

Release source tree validation:

- 48 PHP files: syntax clean.
- 9 JavaScript files: syntax clean.
- 12 CSS files: parser clean.
- 47 internal `DIP_*` classes found.
- 57 Delicat admin-post actions classified.
- 0 unclassified Delicat admin-post actions.
- 0 action-policy overlaps.
- 0 unresolved internal static method references in the static scanner.
- 0 dangerous PHP execution primitives found (`eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, raw `unserialize`).
- 0 zero-byte files.
- 0 symlinks.

This is a defense-in-depth implementation audit, not a claim that any software is vulnerability-free. Production testing should include WordPress, WooCommerce, Google OAuth, TOTP/recovery codes, Passkeys, Hostinger/Cloudflare behavior, caching, and a real Administrator recovery drill.
