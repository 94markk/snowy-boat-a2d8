# Custom packages validation

Validated offline with PHP 8.3.6:
- PHP syntax checks on both complete plugin trees: passed.
- Shared custom-package orchestration suite in both plugins: passed.
- Digital Gateway existing delivery, crypto, catalog, Player ID and sync suites: passed.
- Package checks cover 110+110, 110+341, per-part mapping/recipient/context, amount/subtotal/tax preservation, stale mappings, cross-server recipes, stock aggregation, tampered recipes, nested packages, mapping write protection, unsupported checkout safeguards and 95 monetary allocation cases.

The orchestration tests use WordPress/WooCommerce and provider adapter doubles. Existing gateway suites exercise their own native implementations against their existing offline doubles. This is not a live WordPress, browser, wallet-payment or supplier-delivery test. No live supplier purchase was made. Follow CUSTOM-PACKAGES.md before enabling custom packages on production.

Bootstrap correction 4.23.1: reproduced the original bug in a fresh PHP process (classes present, zero startup hooks). Corrected startup now registers hooks via normal file inclusion and the plugins_loaded event. Fresh-process tests pass for shared duplicate includes and the actual previously shipped Digital Gateway module loaded before or after the new module. Product editor checks and existing package/delivery/crypto/catalog/player/sync suites pass. This validates the PHP bootstrap and offline workflows, not a live WordPress browser session or supplier transaction.

Selection correction 4.23.2: live public page inspection and a browser click reproduced custom variation 21469 present, purchasable and active in variation data but absent from the native attribute_package select; the click left the selection empty. Added offline tests for the precise plus-sign value, default options, matching the existing variation, source options preserved, no forced selection when native variation data excludes an item, read-only rendering, parent save/sync persistence, disabled/custom publication, cache invalidation, management permission and one-time parent repair. No plugin was installed on the live site, and the corrected behavior has not been tested there after installation. No payment or supplier transaction was executed.
