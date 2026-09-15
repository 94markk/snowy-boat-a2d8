# Delicat Identity Pro 6.5.1 — Premium Secure My Account

## Goal
This release focuses on the authenticated WooCommerce My Account experience while keeping WordPress, WooCommerce, TeraWallet and Delicat Identity as the authoritative backend systems.

## Premium UI redesign
- New premium profile header with Delicat Identity status.
- Dark high-contrast wallet hero with lightweight visual effects.
- Responsive quick actions for Recharge, Send and History.
- Refined order/account summary cards.
- Dedicated Identity Protection card with customer-safe security indicators.
- Redesigned recent-orders list and empty states.
- Improved navigation, forms, tables and Identity Center integration.
- Responsive layouts for narrow Android devices, iPhone, tablets and desktop.
- Reduced-motion fallbacks and mobile removal of expensive backdrop blur.

## Security-focused customer UX
The My Account page displays only the signed-in customer's own security status. It does not expose global settings, OAuth credentials, audit summaries for other users, plugin configuration or administrator controls.

Customer-safe signals include:
- verified email status;
- linked login method status;
- trusted-device status;
- HTTPS session status;
- the same customer security score used by the Delicat Security Center.

## Security Center hardening
- Security Center frontend presentation moved out of inline `<style>` blocks into versioned assets.
- Device deletion/session logout confirmations moved out of inline JavaScript attributes into a versioned script.
- Device trust/untrust/forget actions remain POST-only and nonce protected.
- Device changes remain scoped by both `device_id` and `get_current_user_id()`.
- Recent security history remains queried by the current user's ID only.
- Logout-other-sessions only revokes the current user's WordPress and mobile sessions.

## Data ownership
- Wallet balance: TeraWallet (`woo_wallet()`).
- Orders/order totals: WooCommerce.
- Profile/account: WordPress + WooCommerce.
- Linked providers, devices, sessions, audit history: Delicat Identity.

No new account/wallet/order database store is introduced by this release.

## Existing security architecture preserved
- centralized `DIP_Access_Guard`;
- protected WooCommerce My Account endpoints;
- protected-page login requirement support;
- customer/admin permission split;
- private-page cache prevention/no-store handling;
- current-user ownership checks;
- browser-bound authentication nonces and lockout protections;
- mobile token/device/installation binding.
