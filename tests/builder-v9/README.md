# Delicat Builder V9 — test harness (9.5.0)

Test-only tooling, not part of the plugin ZIP.

- `nav-test.cjs`: builds static storefront pages (home, shop, product, cart, account and wallet) around the plugin's real engine and bundles. It drives them on an emulated Pixel 7 and reports:
  - soft navigation, forward and back;
  - document fetches and the time to open a prefetched product, for guests and signed-in customers;
  - feedback after tapping a full-page tab (tab, skeleton);
  - clean-up after Back;
  - page errors.

  Usage: `node nav-test.cjs <plugin-dir> <guest|member> <footer-part.html> [shotsPrefix]`

  Environment variables:
  - `HARD_DELAY=<ms>`: slows the full pages.
  - `WALLET_204=1`: tests a cancelled navigation.
  - `MOTION=on`: keeps view transitions. Headless Chromium can leave a view transition pending, so the default emulates reduced motion.
- `footer-render.php`: renders the real footer class with a WordPress install. Usage: `php footer-render.php <wp-dir> <plugin-dir> <out.html>`.
- `shoot.cjs`: screenshots that footer at 390, 1280 and 320 px, light and dark. Usage: `node shoot.cjs <html> <out-prefix>`.
- `acc.cjs`: checks that the footer groups are tap-to-open on phones and always open on desktop.
