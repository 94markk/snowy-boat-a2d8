DELICAT RECHARGE MODERN WALLET v5.0.5
Mobile Responsive Balance Fix

This safe update fixes the wallet balance design on mobile.

Fixed:
- Oversized balance card on iPhone/Android
- First section being too tall
- Code card spacing
- Recharge/Send buttons overflowing
- Copy button size on small screens
- Glass bubbles causing clipping
- Elementor/container width issues
- Old .dmc/.dsm snippet blocks overlapping on mobile

Untouched:
- Admin dashboard
- Licensing program
- Activation system
- Recharge logic
- MonCash/NatCash logic
- TeraWallet logic
- Database tables
- Security/nonces
- Existing shortcodes

Shortcodes available:
[delicat_wallet_balance]
[delicat_balance_card]
[delicat_recharge]

Recommended setup:
1. Replace the old plugin with this ZIP.
2. Clear LiteSpeed/Cloudflare/browser cache.
3. In Elementor, use only one balance shortcode at the top:
   [delicat_wallet_balance]
4. Keep the recharge form below it:
   [delicat_recharge]
5. Avoid stacking the old [delicat_mon_code] directly above the new balance shortcode, because the new balance shortcode already includes the personal code.
