# Delicat Identity Pro 6.5.0 — Unified My Account

## Architecture
- My Account presentation is now native to Delicat Identity Pro.
- WooCommerce remains the source of truth for orders, addresses, downloads and account details.
- TeraWallet remains the source of truth for wallet balance and wallet endpoint content.
- Delicat Identity remains the source of truth for linked providers, devices, sessions, policy and security.

## Duplicate UI cleanup
The plugin removes only known presentation callbacks from the old `DELICAT STORE — MY ACCOUNT MODERN UI` snippet and old `delicat_cs_dashboard` dashboard callback. It does not remove transfer, wallet, order, avatar backend or database functions.

The former `connected-accounts` WooCommerce navigation entry is hidden. Existing `/connected-accounts/` links are redirected to the single `identity-center` Security area with the linked-accounts tab selected.

## Security
- Account UI is rendered only for authenticated users.
- Recent order rendering verifies `order customer ID === current user ID`.
- Order queries are scoped to the current customer.
- Identity statistics use only the current user's provider/device records.
- Administrative Identity functions remain protected by `manage_options` through `DIP_Access_Guard`.
- Protected My Account requests continue to receive private/no-store headers from the 6.4 access guard.
- Legacy backend actions and WooCommerce ownership/security checks are unchanged.

## Performance
- New assets load only on authenticated WooCommerce My Account pages.
- Dashboard order totals use WooCommerce customer aggregate functions instead of loading all orders into memory.
- Recent orders are limited to three.
- Animations respect `prefers-reduced-motion`.
