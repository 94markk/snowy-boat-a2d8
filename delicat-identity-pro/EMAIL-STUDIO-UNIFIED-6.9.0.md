# Delicat Identity Pro 6.9.0 — Unified Email Studio

This release consolidates Identity Pro and Delicat Email Studio into a single plugin runtime.

## Removed duplicate

`includes/class-dip-email-designer.php` has been removed. Its `user_register` welcome message overlapped with WordPress/WooCommerce account notifications and could result in multiple registration emails.

## One notification layer

The embedded Email Studio now handles:

- WordPress admin new-user notification
- WordPress client new-user notification
- WordPress password reset notification
- WooCommerce order/customer transactional emails
- WooCommerce new-account/reset-password templates
- Delicat Identity password-change/security alerts
- Google/Microsoft linked-account alerts
- TOTP 2FA security alerts
- Passkey security alerts
- New-device alerts
- Registration email verification
- Passwordless OTP and magic-link emails
- Pending-account administrator alerts

## Migration behavior

1. Existing `delicat_email_studio_settings_v3` configuration is reused without duplicating it.
2. If no Email Studio settings exist, compatible branding values are imported from `dip_email_designer_settings`.
3. The standalone `Delicat Email Studio Pro` plugin is deactivated when the unified plugin is activated/upgraded, avoiding double WordPress/WooCommerce hooks.
4. The standalone plugin is not deleted automatically. This avoids destructive filesystem changes and makes rollback possible. After validation, it can safely be removed from WordPress Plugins.

## Security boundaries

- Email Studio settings require `manage_options`.
- Preview, test and export actions require WordPress nonces.
- Authentication/OAuth/TOTP/Passkey cryptography is not moved into the email renderer.
- Password-reset keys, verification tokens, OTP codes and magic-link tokens are not written to the audit log by the email hub.
- WordPress/WooCommerce continue to own account/order records and notification trigger timing.


Legacy cleanup: after migration, administrators with delete_plugins capability get a nonce-protected “Supprimer l’ancien Email Studio” action. Deletion is never performed silently.
