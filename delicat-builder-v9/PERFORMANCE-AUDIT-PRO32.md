# pro.32 responsive fixes

Changes: phone product tracks use two full cards plus 30% of a third at widths up to 640px; compact phone titles, labels and SVG icons; preserved 44px primary touch targets; narrower menu content spacing; viewport-height drawer, fixed header shrink and horizontal overflow; login close-menu event releases inert background and scroll lock without stealing popup focus; pointer cancellation resets instead of closing; vertical gestures cannot switch into drawer swipes; swipe transform writes coalesced per animation frame; non-primary pointer presses ignored.

Verification: local Chromium fixtures using shipped product/drawer styles and drawer runtime at 320, 360, 390, 768 and 1024px. Confirmed 2.3-card geometry on phones, scrollable drawer, no page overflow and login handoff restoring background interaction. JavaScript syntax checked. Content-addressed asset copies and integrity manifest rebuilt using PHP 7.4.

Limits: representative fixtures, not the production WordPress site. No measured speedup percentage or claim that every website bug is eliminated. Tablet portrait/landscape, real-device keyboard, installed theme overrides, checkout and authentication should be smoke-tested on staging. Existing Identity fixes must also be installed for the complete login flow.

Install: back up existing plugin, replace with this ZIP, purge WordPress/Hostinger/CDN caches and regenerate any combined CSS. Test menu, login/logout and checkout before production rollout. Retain older hashed asset generations for cached pages. Avoid delaying essential drawer/Identity scripts or stripping their critical styles.
