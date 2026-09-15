# Menu Engine 3.0 and the like button — 9.3.0-pro.1

What changed, what was measured, and what was not.

## The finding that matters

The storefront menu was three engines deep, and every page paid for all of
them:

- `class-delicat-builder-menu-runtime.php` — the modern-menu runtime, 871
  lines, loaded on every storefront request.
- `class-delicat-builder-menu-builder.php` — a second, complete copy of the
  same runtime, behind a guard whose only job was to stop the duplicate
  declarations being an uncatchable fatal.
- `class-delicat-builder-drawer.php` — the RC29 drawer, layered on top, which
  owned the surface in practice and made most of the first two dead weight.

The cost was not mainly the bytes. It was that the **whole panel was live DOM
in every document**: the account row, the wallet card, the quick tiles, every
menu link, the bonus block, the social row and the footer — styled and laid out
during the load the shopper was actually waiting on, for a menu most visits
never open. On a four-core Android that work lands squarely on the critical
path, and it landed on every navigation.

Two smaller things went with it: 42 KB of preset CSS with `backdrop-filter`
glass (a blurred 390 px panel is a full-screen GPU readback on every frame it
moves), and a wallet poller that woke every 45 seconds for signed-in visitors
whether or not the menu had ever been opened.

## What replaces it

One engine, `class-delicat-builder-menu-engine.php`, with one stylesheet and
one script. The panel body ships inside an inert `<template>`: parsed, but
never styled, laid out or painted. The runtime hydrates it during the first
idle slice after load and parks it off screen, so the first tap on the three
bars runs a transform and nothing else. A tap that beats the idle callback
hydrates inline — one extra frame, once, instead of on every page.

The menu is printed at `wp_footer` instead of beside the header, so nothing
above the fold waits on it.

The design is unchanged: same light canvas, white cards, indigo type, gradient
icon tiles and pill badges the shop already ships.

## Measurements

Chromium 141.0.7390.37 (headless), the shipped stylesheet and
runtime, a fixture with the shop's eleven menu items and the bonus block.

**DOM carried by every page**

| | nodes |
| --- | --- |
| Live in the document at parse | **13** |
| Inert, inside the `<template>` | 209 |
| Live after idle hydration | 222 |

The 209 nodes are what the old engines put in every document, live, before the
page had finished loading.

**Bytes over the wire** (gzip -9, the shipped files)

| | before | after |
| --- | --- | --- |
| Menu CSS | 7,860 + 938 (presets) + 5,276 (drawer) | 5,694 |
| Menu JS | 3,895 + 7,407 (drawer) | 6,547 |
| Wallet poller | 1,256 | 0 — removed |
| **Total** | **26,632** | **12,241** |

The balance now refreshes when the menu opens, through the same nonce-guarded
read-only action, instead of on a timer.

**Behaviour verified in the browser**, at 390 / 414 / 430 px: open, close,
Escape, scrim tap, focus trap, section collapse, search filter, scroll-lock
restore (scroll position returns to where it was), swipe-to-close, and native
vertical scrolling of the list while swipe-to-close still arms.

Two bugs were found that way and fixed before shipping:

1. SVG icons collapsed to 0.09 px in tight flex rows — an `<svg>` is a flex
   item like any other, and was the first thing a full row shrank.
2. Swipe-to-close never armed. The scroller's default `touch-action` let the
   compositor claim the sideways gesture and cancel the pointer stream two
   frames in; `pan-y` on both the panel and the scroller fixes it without
   costing the list its native scrolling.

## The like button

Rebuilt in the same release. The mark is one shape drawn twice — an outline
with a fill parked at `scale(0)` over it — so "liked" is a transform on
something already on the card, not a second icon that has to arrive. The press
lands on `pointerdown` rather than on the click a phone sends up to 300 ms
later; the state flips before the network; a ring pulses off the bubble and six
sparks fly on their own bearings; the device buzzes where `navigator.vibrate`
is honoured.

Behind it, taps are coalesced: one request in flight per product, carrying the
state the shopper actually ended on. **Five taps inside one flight now cost one
request** (measured with a stubbed slow endpoint), where the old queue sent
five. A write the server refuses returns the button to the server's truth with
a shake instead of leaving it lying in the direction of the tap.

The styling had been spread across three stylesheets, and two of those rules
were actively wrong: a blanket "no animation on touch devices" list disabled
the one animation whose job is to answer a touch, and `app-polish.css` would
have stripped the fill off the new mark. It is now in one place, next to the
bubble it sits inside.

`prefers-reduced-motion`, `html.delicat-slow-net` and `html.delicat-low-power`
get the state change and no celebration. So does any device reporting
save-data, ≤2 GB of memory or ≤2 cores; the menu applies the same test at ≤4 to
drop its stagger and shorten the slide.

## What was NOT measured

- **No real device.** Everything above is a desktop Chromium fixture. The node
  counts and byte counts are exact; the frame timings a 4-core Android would
  produce were not sampled, and no Lighthouse or field (CrUX) numbers were
  taken before and after.
- **No WordPress.** The engine was rendered against stubbed WordPress and
  WooCommerce functions, not a live install, so option migration, TeraWallet
  balances, Identity's login modal and LiteSpeed's private-cache path still
  need a staging pass.
- **No Safari or Firefox run.** The runtime avoids anything exotic, but
  `content-visibility`, `inert`-free focus trapping and pointer capture behave
  differently enough across engines to be worth one manual check.

## How to confirm on the live site

1. View source on any storefront page: the menu should appear once, near the
   end of the document, as `<div class="dmenu" … hidden>` with about a dozen
   nodes, followed by `<template data-dmenu-template>`.
2. In DevTools, `document.querySelectorAll('.dmenu, .dmenu *').length` should
   read around a dozen on load and jump once the page goes idle.
3. Open the menu on a throttled profile (6× CPU, Slow 3G). The panel should
   slide in one transform; the Performance panel should show no layout work
   attributable to the menu on load, and no `backdrop-filter` anywhere.
4. Sign in, open the menu, and watch the network panel: exactly one
   `delicat_builder_v9_wallet_balance` request per open, at most one per 15
   seconds, and none while the menu stays shut.
5. Tap a heart five times quickly with the network panel open: one
   `delicat_builder_v9_favorite` request, and the button ends on the state the
   fifth tap asked for.
