# Browser tests

These run against a real Chromium with a stubbed server. They exist because the
things they cover cannot be reasoned about from the source alone:

- **checkout-sheet-test.js** — the money path. Asserts that the request
  WooCommerce receives is the one the button would have sent (every custom field
  included), that WooCommerce's own form and nonce are what land in the sheet,
  that no script from the response executes, that a low-balance decline offers
  the wallet while an ordinary validation error does not, and that every failure
  mode ends on the real checkout page rather than nowhere.

- **speculation-gate-test.js** — which speculation rules get registered on a
  fast connection and on a slow one.

Run them with a Chromium on the machine:

    NODE_PATH=/path/to/node_modules node tests/browser/checkout-sheet-test.js
    NODE_PATH=/path/to/node_modules node tests/browser/speculation-gate-test.js

They need `playwright` resolvable on NODE_PATH and a Chromium at the
`executablePath` each file names; adjust that line for your machine.
