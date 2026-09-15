# Browser tests

These run against a real Chromium with a stubbed server. They exist because the
things they cover cannot be reasoned about from the source alone:

- **express-sheet-test.cjs** — the money path. Asserts that the request
  WooCommerce receives is the one the button would have sent (every custom field
  included), that WooCommerce's own form and nonce are what land in the sheet,
  that no script from the response executes, that a low-balance decline offers
  the wallet while an ordinary validation error does not, and that every failure
  mode ends on the real checkout page rather than nowhere.

- **express-loader-test.cjs** — the sheet's 44 KB of CSS and JS no longer sit on
  every product page; they load when someone reaches for the buy button. Which
  puts a loader between a customer and their purchase, so these cases are about
  that: the tap works once, whether or not the download has arrived, by finger or
  by keyboard, and a script that fails to load still lands them on the real
  checkout rather than doing nothing. It runs the REAL loader, printed by
  `scripts/emit-express-loader.php`, not a copy.

- **drawer-test.cjs** — the menu. Asserts that one tap opens it and leaves it
  open, that nothing is tappable while the panel is still sliding in, that every
  way of closing it works and hands the page back its scroll position, that the
  search filter narrows and restores the list, and that a swipe dismisses the
  menu without following the link it started on.

  Use real input here — `page.touchscreen`, or CDP `Input.dispatch*`. An earlier
  version dispatched `new PointerEvent(...)` and "found" a stuck half-open panel
  that neither a real finger nor a real stylus can produce, because touch
  pointers get implicit pointer capture. The swipe is exercised with a pen: CDP's
  synthetic touch does not run Chromium's gesture pipeline, so the part the
  browser decides is asserted as a `touch-action` declaration instead.

  `DRAWER_JS=/path/to/drawer.js` runs the suite against a different build of the
  script, which is how these cases were checked against the one that shipped.

- **shell-nav-test.cjs** — the scroll position a visitor comes back to. Asserts
  that pressing Back restores it, including on a page still growing, and that a
  visitor who has started reading is never thrown somewhere else by the restore
  still retrying behind them. Uses real back/forward navigation: the restore
  only acts on a navigation Chromium reports as `back_forward`.

- **speculation-gate-test.cjs** — which speculation rules get registered on a
  fast connection and on a slow one.

Run them with a Chromium on the machine:

    NODE_PATH=/path/to/node_modules node tests/browser/express-sheet-test.cjs
    NODE_PATH=/path/to/node_modules node tests/browser/express-loader-test.cjs
    NODE_PATH=/path/to/node_modules node tests/browser/drawer-test.cjs
    NODE_PATH=/path/to/node_modules node tests/browser/shell-nav-test.cjs
    NODE_PATH=/path/to/node_modules node tests/browser/speculation-gate-test.cjs

They need `playwright` resolvable on NODE_PATH and a Chromium at the
`executablePath` each file names; adjust that line for your machine.

The `.cjs` extension is not decoration: the repository's package.json declares
`"type": "module"`, so a `.js` file here is loaded as an ES module and `require`
throws before the first test runs.
