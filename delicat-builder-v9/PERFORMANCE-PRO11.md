# 9.2.0-pro.11 — navigation request efficiency

Changes:
- Explicitly disables fragment prefetch for the existing private/no-store endpoint, including sites with prefetch enabled in saved settings. Stops hover, touch and viewport requests whose completed content cannot safely be reused.
- Downloads same-origin script assets alongside navigation CSS using preload hints. Script execution still occurs through the existing ordered loader after the page swap. Already loaded or hinted scripts are skipped.
- Preserves all pro.10 no-store headers, client no-store handling, route exclusions, state invalidation and native product/checkout navigation.
- Updates the plugin version for asset cache invalidation and regenerates the integrity manifest.

Validation:
- JavaScript syntax check passed.
- Focused Node VM tests passed: legacy settings cannot enable fragment speculation; no-store payloads are not cached; invalidation clears entries; script hints are deduplicated and same-origin; hints do not execute scripts or mark them executed.
- Archive CRC and complete manifest validation passed during packaging.
- PHP CLI and a running WordPress/WooCommerce installation were unavailable. No live checkout, authentication or device benchmark was performed.

Install:
Upload this ZIP through WordPress Plugins > Add New > Upload Plugin, and replace the current Delicat Builder V9 copy. Keep your previous ZIP for rollback. Clear page/asset caches once after updating. Verify homepage > shop > product > cart > checkout, login/logout, wallet and browser Back/Forward on staging before production use.

Scope:
This is a targeted efficiency patch, not a public-content caching redesign. Navigation HTML remains private and freshly requested on click. No percentage speed increase is claimed. Public-only reusable fragments require a separate content/state boundary audit.
