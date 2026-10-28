# 6.10.1 — Popup sizing and responsiveness

Preserves the working isolated form and native Google link from 6.10.0.

- Replaces fixed popup height with measured form height. Login no longer reserves registration-sized empty space. ResizeObserver updates for errors, 2FA and optional controls; measurements are coalesced with requestAnimationFrame and stopped while closed.
- Constrains height to the visual viewport, including the mobile keyboard. Long forms remain scrollable inside the frame.
- Refines spacing, typography, placeholders, password eye button and theme-protected circular close button. Inputs retain 16px text.
- Uses native modal dialog background isolation without mutating all storefront body children or observing widgets. Manual inert/pointer restoration remains for the fallback path.
- No blur or height animation on opening. No server/authentication settings changed.

Validation: 14 frame and 11 shell Node VM tests pass. JavaScript syntax checked. Tests cover login, registration, Google link availability, nonces, errors, 2FA, retries, close/reopen, native/fallback isolation, content sizing and viewport clamping. These fixtures do not verify real browser layout or benchmark performance. PHP, live WordPress, Google OAuth completion and physical phones were not tested for this release.

Install by uploading the ZIP in WordPress Plugins and replacing the existing plugin, without uninstalling. Purge page/CDN caches so cached HTML references the new asset version. Check both tabs, Google, password login, close/reopen and keyboard scrolling on the affected phone. This package has not been deployed to the live site.
