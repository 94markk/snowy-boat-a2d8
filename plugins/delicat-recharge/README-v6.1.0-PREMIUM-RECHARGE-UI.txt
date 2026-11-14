Delicat Recharge Admin — v6.1.0 Premium Recharge UI
====================================================

Scope: RECHARGE FRONT-END UI ONLY.

Updated:
- Premium responsive Recharge layout inspired by the approved Delicat mockup.
- Responsive from narrow phones (~320px) through tablets and large desktop screens.
- Dynamic Natcash Auto (TC) / MonCash method selector with clear active state.
- 3-step visual guide per payment method.
- Cleaner payment account card with one-tap copy button.
- Better masked-name reveal control.
- Premium form fields, focus states, help disclosure and secure primary action.
- Desktop two-column layout; automatically stacks on tablet/mobile.
- iOS-friendly 16px mobile inputs to avoid Safari auto-zoom.
- Touch-friendly targets and reduced-motion support.
- Expensive blur is limited to larger screens for better low-end phone performance.

Not changed:
- AJAX submit action
- Nonces/security validation
- Natcash / MonCash verification logic
- TeraWallet credit logic
- Database/storage
- Admin settings
- REST/API classes
- Workflow/alerts/email/push logic

The plugin version was bumped only to 6.1.0 to invalidate cached CSS/JS and make the UI update installable as a normal upgrade.
