# Delicat Builder 9.2.0-pro.36 — session and navigation follow-up

Based on the completed pro.35 package, with all prior fixes retained. This is a targeted continuation of the engine audit, not a claim that all module behavior has been validated in WordPress.

## Fixes

- Both session clients now coalesce mutations received during an in-flight read into one subsequent read. Callers waiting for a forced refresh receive the subsequent state instead of the earlier snapshot.
- Pro state refresh has a real Promise deadline, including on browsers without AbortController. A hung request no longer leaves the store permanently pending. Late results are not applied after timeout.
- Pro wallet text is cleared when the wallet is disabled or unavailable. The standard session renderer clears old name, email, initials and customer code on guest state, removes the old copy-code attribute, and displays an em dash for an unavailable balance.
- Both clients listen for relevant custom auth/currency/cart/wallet change events; existing WooCommerce bindings remain.
- Pro visibility refresh uses local fetch age rather than comparing the device clock with a server timestamp.
- Navigation checks its token before each queued script. Superseded script queues stop.
- A second navigation while a page commit is in progress takes a full browser navigation; scripts already executing cannot safely be undone. Normal eligible page swaps remain available outside this phase.
- An external script with the same WordPress handle but a different URL forces a full page load, instead of silently retaining the previous script.
- Cross-page hash links scroll to their target after module initialization. Encoded anchors and old named anchors are supported; malformed encoded anchors fail safely. History scroll restoration keeps precedence.
- Rebuilt content-addressed assets and integrity manifest, retaining three asset generations.

## Validation

- `node tests/test-engine-pro35.js`: PASS (checkout observer and favorites regressions).
- `node tests/test-engine-pro36.js`: PASS (session race/coalescing, guest identity clearing, wallet clearing, timeout without AbortController, cancelled script queue, decoded/malformed anchors, mid-commit navigation).
- The original pro.35 source fails the new in-flight mutation test; patched source passes.
- JavaScript syntax checked across the final package; asset source/hash equality, retained history, manifest contents and ZIP CRC checked.
- No new PHP runtime logic was changed in this follow-up. The existing PHP execution limitation remains: PHP CLI is unavailable, so PHP lint and the three PHP harnesses were not run. Focused DOM-stub tests are not browser or WordPress integration tests.
- No live site deployment, live payment, authenticated site test or measured speed comparison was performed.

## Installation and remaining checks

Back up the current site/database, then install this ZIP as a replacement on staging. PHP 8.5 remains required. Open a Builder admin page so compiled page styles can rebuild. Purge LiteSpeed and Cloudflare as applicable.

Verify rapid archive navigation and browser Back, cross-page anchors, adding/removing items while opening the drawer, wallet updates, sign-in/sign-out and guest display. Then complete the staging checklist in AUDIT-PRO35.md, especially WooCommerce checkout, insufficient balance, product fields, variations, PWA and external login. Neither audit guarantees that every module is bug-free.

The module inventory and previous engine fixes are documented in AUDIT-PRO35.md.

## Obsolete hashed assets removed this build

- `assets/js/heart.024b052801dd.js`
- `assets/components/all-components.min.5085953b9390.css`
- `assets/components/products.78592c4b5d31.css`
