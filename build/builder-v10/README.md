# Builder V10 build inputs

- `engine-src/` — readable (prettified) engine modules of `assets/dist`, edited for V10. The
  release build re-minifies every module that differs from the prettified 9.14.2 originals
  (regenerate those with esbuild from the 9.14.2 `assets/dist`) and renames it by content hash.
- `tools/build-v10.mjs` — the owner's release build (skill `delicat-builder-release`) with
  `SKIP_CSS=1` to keep CSS bundles byte-identical. Run it on a fresh copy of the 9.14.2 dist.
- `tools/patch-chrome.mjs` — appends rules after a marker in `assets/css/chrome-fixes.css` to the
  shipped chrome bundle (used for 9.14.3/9.14.4 hotfixes).
- `partial-php-runtime.patch` — work of an interrupted agent, kept for review (not applied).
