# Delicat Storefront V10 Pro

A WooCommerce storefront that behaves like an installed app.

It is a rebuild of Delicat Builder V9, not a port. Nothing is copied; where V9's
behaviour was right the intent is kept and the implementation is new.

---

## Why a rebuild

An audit of V9 found thirty bugs. The expensive ones were not independent — they
were three structural faults showing up in different places.

**No single source of truth.** Fifteen stylesheets each held their own number for
how tall the bottom chrome is — 0, 82, 86, 88, 92, 96, 104, 164, 172, 178, 180,
190 and 196 pixels — and which one applied was decided by how many class names
its selector happened to carry. The bar is 106. The homepage reserved 96, so the
bar covered the product cards.

**Rules competing on specificity.** Making a fix stick meant adding class names
or reaching for `!important` — and among `!important` declarations specificity
still decides, so a `display: none` lost to a `display: block` three files away.

**Generated artefacts maintained by hand.** A combined stylesheet that nothing in
the repository could build had not been rebuilt in months, so every edit to its
four source files was discarded before reaching a browser. Silently: a CSS rule
that never applies raises no error.

None of those is fixable by fixing instances. V10 removes the categories.

---

## The four ideas

### 1. One token layer

`src/Design/Tokens.php` is the only place a number or colour exists. CSS reads
`var(--dlx-*)`; PHP reads `Tokens::get()`.

The occluded band — how much of the bottom of the screen is covered by fixed
chrome — is **derived**:

```
--dlx-tabbar-block: calc(tabbar-height + tabbar-float + safe-area)
body.dlx-has-tabbar  --dlx-band: var(--dlx-tabbar-block)
body.dlx-has-dock    --dlx-band: calc(var(--dlx-tabbar-block) + dock-height)
```

Anything needing clearance writes `padding-bottom: var(--dlx-band)` and is
correct at every width, in every combination, permanently. PHP states what it
rendered by adding a body class; CSS turns that into a measurement. Neither side
holds a number the other could disagree with.

Dark mode is the same keys restated once. No component carries a dark rule, so
no minifier can disable one — which is what happened to 25 of V9's.

### 2. Cascade layers instead of specificity

```css
@layer reset, base, layout, components, state;
```

Layer order beats specificity, so conflicts resolve in reading order rather than
by counting selectors. V10 contains exactly one `!important`, for `[hidden]`,
and the build fails if a second appears.

### 3. Navigation by the platform, not by a router

V9 fetched the next page, parsed it and swapped the body's `innerHTML`. Twelve of
its own modules never learned a page had changed and stopped working after the
first navigation; dark mode reverted on every swap; Back lost the scroll
position; and with JavaScript unavailable the store fell back to plain loads that
looked nothing like the app.

V10 has no router. It navigates by loading pages — real navigations, real history
entries, real Back — and asks the browser for three things:

| | |
|---|---|
| `@view-transition` | animate between the two documents |
| `view-transition-name` | recognise the header and tab bar as the same object on both pages, and hold them still |
| `speculationrules` | render the destination before the tap completes |

A prerendered page is not "fast", it is already finished: HTML parsed, CSS
applied, images decoded, layout done. Activating it is a paint.

Nothing can miss a navigation, because every navigation is a real page load.
Back works because it is the browser's Back. With JavaScript off the transitions
do not play and the store still works.

**307 lines of JavaScript**, against V9's 1,900-line navigation engine.

### 4. A declarative kernel

A module states which request shapes and routes it wants; the kernel loads the
matching set:

```php
final class Product extends Module {
    public static function routes(): array {
        return array( Context::ROUTE_PRODUCT );
    }
    public function register(): void { /* attach hooks */ }
}
```

There is no hand-curated list to drift, so V9's failure — a search index
registered only for admin screens, leaving the storefront's search box with
nothing to search — is not expressible.

---

## WooCommerce stays authoritative

V10 renders and restyles. Every price, stock state, total, tax and payment
decision is WooCommerce's.

There is no V10 payment endpoint, no signed intent, no status poller and no
per-customer lock. V9 had all four, and its lock outlived a failed payment: a
customer whose wallet was short was left unable to check out at all until someone
released them by hand from an admin screen.

A customer with no funds is declined by their gateway, sees that gateway's own
message, and can try another method immediately — because nothing of V10's
survives the request to stop them.

`tests/run.php` asserts that no file under `src/` registers a REST route, an
unauthenticated AJAX action, or calls WooCommerce's checkout processor.

---

## Building

```sh
node build/build.mjs
```

Zero dependencies. It concatenates the sources, asserts fifteen invariants,
strips comments, collapses whitespace, names each output after the hash of its
own contents and writes `dist/manifest.json` — the only map PHP reads.

**The minification is deliberately weak.** Comment-stripping and
whitespace-collapsing cannot change what a selector means; everything beyond that
can, and the saving it buys after Brotli is a fraction of a percent. V9's bundle
had been through a minifier that removed the space in `body :where(...)`, turning
"anything inside the page matching this" into "the page element itself, if it
carries this class" — which matches nothing.

The invariants:

- the layer order is declared first
- `calc()` and `clamp()` keep the spaces their grammar requires
- no `element:where()` produced by collapsing
- each `view-transition-name` is declared once
- `!important` is used once, for `[hidden]`
- no colour literals outside the token layer
- the band is written in one place
- fixed chrome adds no vertical offset outside the band
- breakpoints are the three designed tiers
- no `eval`, no `document.write`, no script renders the page

Each of the last four was written after a real regression; all of them are
verified to fail when that regression is reintroduced.

## Testing

```sh
php tests/run.php
```

46 tests. Each pins a property V9 got wrong, so getting it wrong again fails
loudly instead of quietly.

Layout is verified by measurement in Chromium at ten viewport widths from 360 to
1440: no horizontal overflow, the bar never covers content, the dock never covers
the bar, the band is reserved when there is chrome and zero when there is not.

---

## Installing alongside V9

V10 reads V9's saved colours, corner radius, menu and homepage layout, and writes
nothing back — `src/Compat/V9Options.php` is the only file that knows V9's option
shapes, so removing V9 later means deleting one file.

Both can be active at once while you compare them, though only one should render
the chrome. Deactivating V10 leaves nothing behind: it creates no tables, writes
no ledgers and schedules no cron jobs.

## Settings

One screen: five colours and a corner radius, with everything else derived. V9
had twenty-five studios exposing several hundred settings, and much of the
storefront's fragility came from exactly that — every component had its own
sizes, colours and breakpoints because every component had a page to fill.

Health is reported through WordPress's own Site Health, where a merchant or their
host already looks. Every check either names something that can be acted on, or
does not exist.
