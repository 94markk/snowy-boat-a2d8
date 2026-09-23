# Delicat Identity Pro 6.3.0 — Complete Login Modal UI Rebuild

## Scope
The frontend login/register popup was rebuilt from scratch while preserving the existing secure Delicat Identity authentication backend.

## New UI architecture
- Unique modal ID: `dip-identity-modal`
- Unique CSS/DOM namespace: `dipx-*`
- New assets: `assets/identity-modal-v3.css` and `assets/identity-modal-v3.js`
- Old `native-auth-modal.css/js` removed from the package
- Legacy `#delicat-login` markup is suppressed when the native modal is active
- Capture-phase trigger intercepts legacy `#delicat-login`, `[data-dl-open]`, and new `[data-dip-auth-open]` triggers
- Critical modal script is marked `data-cfasync="false"` and `data-no-optimize="1"` to reduce failures caused by JS optimizers/Rocket Loader
- CSS-mask icons avoid global theme/plugin SVG sizing rules
- Google logo is rendered through an isolated CSS data image so global SVG rules cannot enlarge or hide it

## Preserved security/authentication backend
- Browser-bound nonces
- Namespaced AJAX actions
- Honeypot protection
- IP / IP+account / account-wide lockouts
- WordPress authentication pipeline
- WooCommerce customer/session synchronization
- Registration rate limiting
- Email verification and resend protections
- Google OAuth through Delicat Identity
- Account approval and verification policy
- Audit/security history

## Responsive/performance work
- 100dvh/safe-area support
- Small-screen and short-screen layouts
- No backdrop blur on small mobile screens
- Transform/opacity-only modal transitions
- Reduced-motion support
- Focus trap, Escape close, tab keyboard navigation
- iOS input font size kept at 16px to avoid zoom

## Compatibility triggers
- `[delicat_login_button]`
- `[data-dip-auth-open]`
- `[data-dl-open]`
- `<a href="#delicat-login">...`
- `<a href="#dip-identity-modal">...`

## Validation
- PHP syntax validation: passed
- JavaScript syntax validation: passed
- CSS parser validation: passed
- No zero-byte files
