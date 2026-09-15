# Delicat Identity Pro 6.9.2 — Integration / Security Fix Pass

## New-user admin notification
- Restored only the administrator-requested client identity fields: display name and e-mail.
- Name resolution uses WordPress first/last name, then WooCommerce billing first/last name, then display name/login fallback.
- Removed username, phone, role, numeric user ID and registration timestamp from the rendered admin email context.
- The CTA now targets the exact WordPress `user-edit.php?user_id=...` profile screen.
- The profile URL does not grant access: WordPress still requires an authenticated user with permission to edit that user.
- v6.9.1 privacy-mode default settings are migrated to the new profile CTA and copy without overwriting unrelated customizations.

## Unified notification consistency
- Pending-account approval alerts now identify the client by name/e-mail rather than exposing a raw numeric user ID.
- Pending-account CTA now says “Voir le profil client” and targets the exact user profile.
- WordPress/WooCommerce new-user e-mail rendering stays on the unified Email Studio engine.

## Rendering bugs fixed
- Removed `antispambot()` + `esc_html()` double-encoding from visible e-mail links in account/admin panels and the footer.
- Kept email addresses sanitized and safely escaped for both text and `mailto:` attributes.

## Package checks
- No old `class-dip-email-designer.php` duplicate renderer.
- No standalone `delicat-email-studio-pro.php` in the package.
- PHP syntax validation run across the package.
- JavaScript syntax validation run across the package.
- Zero-byte file scan completed.
- Exact duplicate-file hash scan completed.
