## 4.23.2 — Custom package selection repair

- Fix custom packages present in variation data/cards but missing from the native WooCommerce attribute dropdown.
- Preserve exact local attribute labels (including spaces and plus signs), retain native options and keep WooCommerce availability checks.
- Restore custom labels on parent saves/import sync; invalidate variation caches after custom variation saves.
- Repair existing parents in bounded administrator-only batches. No supplier calls, no frontend writes, no forced variation IDs.
- Independent compatibility module works with an older shared package class and boots once across gateways.

## 4.23.1 — Package startup fix

- Fix PHP early class declaration causing the first-load class_exists guard to return before registering startup hooks.
- Register the shared bootstrap even when a legacy gateway owns the shared class; retain one-time boot protection.
- Add fresh-process regression tests covering normal loads, duplicate includes and legacy-first/legacy-last load order.

## 4.23.0 — Forfaits personnalisés

- Product admin editor for custom direct-top-up package recipes and manual retail prices.
- Split classic checkout packages into native delivery lines before payment, with individual tracking and existing payment/idempotency guards.
- Shared module boots once; virtual source mappings protect importer identity and custom prices; stock checks cover repeated components.
- Explicit safeguards for stale recipes and unsupported checkout paths. See CUSTOM-PACKAGES.md for compatibility and validation.

