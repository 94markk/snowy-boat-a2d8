# Delicat Identity Pro 6.9.15 — Google button diagnostics

Fixes the case where the Google button silently disappears from the Delicat
login modal, wp-login.php, My Account and checkout after an update.

## Why the button vanished

`DIP_Native_Auth::modal_markup()` only prints the social row when the button
HTML is non-empty:

```php
$google = DIP_Plugin::instance()->render_button([... 'provider' => 'google' ...]);
if (trim($google) !== '') { /* divider + button */ }
```

`render_button()` returns an empty string as soon as
`DIP_Google_Provider::is_configured()` fails:

```php
($settings['enabled'] ?? 'no') === 'yes'
  && is_ssl()
  && !empty($settings['client_id'])
  && !empty($settings['client_secret']);
```

Four distinct causes — social maintenance mode, Google disabled, a missing
Client ID, a missing or unreadable Client Secret — all produced the same blank
result, with no admin notice and no audit event. Neither the divider nor the
button appeared, so the modal looked as if Google had never been configured.

## The unreadable-secret state

`DIP_Plugin::settings()` decrypts the stored secret through `DIP_Crypto`, whose
key is derived from `wp_salt('auth')` and `wp_salt('secure_auth')`. If the
WordPress salts in `wp-config.php` change — host migration, a security plugin
regenerating keys, a restored configuration file — the stored ciphertext can no
longer be opened. `settings()` then returns an empty secret and the plugin
reported it exactly like "never configured": the admin Client Secret field even
rendered empty instead of `********`.

6.9.15 separates the two states. `secret_unreadable` means the ciphertext is
still in the database but the salts changed. The fix is to re-enter the Google
Client Secret from Google Cloud Console and save; restoring the previous salts
also recovers it.

## Destructive save path (fixed)

In `sanitize_settings()`, `$old` holds the **decrypted** settings. With an
unreadable secret, `$old['client_secret']` was `''`, so simply re-saving the
settings page wrote `''` back to the option and permanently destroyed a blob
that was still recoverable by restoring the salts. The routine now falls back
to the raw stored ciphertext whenever the decrypted value is empty and no
explicit "clear" checkbox was ticked.

## What 6.9.15 adds

1. `DIP_Plugin::google_availability()` returns one precise, non-secret state:
   `ok`, `maintenance`, `disabled`, `no_https`, `missing_client_id`,
   `missing_secret`, `secret_unreadable`.
2. `DIP_Plugin::google_availability_label()` maps that state to a French admin
   message. It never contains a secret, a token or a client credential.
3. An administrator notice on the Dashboard, Plugins and Delicat Identity
   screens naming the exact cause and linking to the settings.
4. The Google OAuth status card on the settings page reports the reason instead
   of a generic "À configurer".
5. **Tester la configuration Google** now also checks whether the public
   storefront button actually renders, so valid local credentials can no longer
   report success while the button stays hidden.
6. An administrator-only diagnostic line inside the login modal markup.
7. `DIP_Plugin::proxy_https_detected()` recognises Cloudflare and reverse-proxy
   HTTPS termination and tells the administrator to set HTTPS in
   `wp-config.php`. The OAuth gate itself still requires a genuinely encrypted
   request — no forwarded header is ever trusted to authorise a flow.
8. `wp_kses_post()` was stripping the inline provider `<svg>` from the modal
   button markup (SVG is not part of WordPress' allowed post HTML). Replaced
   with an explicit allowlist that keeps the exact SVG shape Identity Pro emits.

## Unchanged security properties

OAuth state, PKCE, nonce verification, Google signature and claim validation,
exact callback allowlisting, browser-bound cookies, privileged-account
blocking, TOTP policy, login throttling, encrypted secret storage and the
deny-by-default Identity REST firewall are untouched.

## Deployment

1. Plugins → Add New Plugin → Upload Plugin → choose this ZIP → replace the
   installed Identity Pro version.
2. Clear LiteSpeed and Cloudflare page caches.
3. Open Réglages → Delicat Identity and read the Google OAuth card.
   - `secret_unreadable` → re-enter the Client Secret and save.
   - `disabled` → tick the Google activation checkbox.
   - `maintenance` → turn off "Mode maintenance des connexions sociales".
   - `missing_client_id` → paste the Web/serveur Client ID.
   - `no_https` → add HTTPS detection to `wp-config.php` before
     `require_once ABSPATH . 'wp-settings.php'`.
4. Reload the storefront logged out and confirm the "ou continuer avec"
   divider and the Google button are back in the modal.
