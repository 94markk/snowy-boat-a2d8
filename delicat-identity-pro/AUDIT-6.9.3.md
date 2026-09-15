# Delicat Identity Pro 6.9.3 — Email Context & WooCommerce Token Fix

## Fixed

### WordPress new-user administrator notification
- Fixed a shared template-scope bug where `email-header.php` replaced the caller's `$ctx` variable with an empty generic context.
- The administrator new-user card now retains the actual new user's display/name data and email address.
- User resolution now accepts a `WP_User`, numeric user ID, object with `ID`, or array with `ID` and normalizes it through `get_userdata()`.
- The profile CTA remains the exact WordPress `user-edit.php?user_id=...` URL. WordPress authentication and `edit_user` capability checks remain authoritative when the administrator opens the link.

### WooCommerce subject and heading placeholders
- Fixed literal placeholders such as `{order_number}` appearing in inbox subjects.
- Email Studio custom subjects/headings are now resolved through the Email Studio token context and then passed through the WooCommerce email object's `format_string()` method.
- Order resolution supports the filter order object, numeric order IDs, and the WooCommerce email object's current order.
- No order recipient or sending trigger was changed.

### Shared email context integrity
- `email-header.php` now uses `$header_ctx` instead of overwriting `$ctx` in the caller's include scope.
- `email-footer.php` now uses `$footer_ctx` instead of overwriting `$ctx`.
- This also protects client new-account emails, password/reset messages, Identity security emails, and other custom emails that render through the same templates.

## Security
- No authentication bypasses added.
- No changes to password/reset token generation.
- No changes to WordPress/WooCommerce recipients.
- Exact client profile links still require WordPress authentication and authorization.
- Existing standalone Email Studio migration/deactivation logic is preserved.

## Validation
- 65 PHP files passed `php -l`.
- 10 JavaScript files passed `node --check`.
- 0 zero-byte files.
- 0 exact duplicate-file groups.
- ZIP integrity test passed.
