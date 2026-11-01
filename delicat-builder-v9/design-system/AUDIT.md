# Delicat Store — Design and front-end audit (Phase 1)

Date: 2026-11-01. Scope: delicat-builder-v9 9.5.5, delicat-identity-pro 6.15.4, delicat-recharge 6.5.2.
Method: code inventory of the three plugins (two read-only audits) + staging site (WordPress 7.1.4,
WooCommerce 11.2.1, TeraWallet 1.7.4, sample HTG catalogue) screenshots at 390 px light/dark and 1280 px.
Limits: the live site and its settings were not reachable from the build environment; staging runs the
plugins' default settings, so findings marked *(default config)* must be re-checked against the live setup.

## Brand
Logo (delicat-recharge/assets/img/delicat-logo.png): black "DELICAT", navy `#00004F` "STORE HAITI",
royal-blue wave `#2E48A8`, green tagline. Chosen direction: primary `#2563EB` (buttons, links) with
navy `#00004F` as the brand ink (headings, hero, brand surfaces). Today the storefront primary is violet.

## Scores (design-system audit, 0–10)
| Dimension | Score | Evidence |
|---|---|---|
| Colour consistency | 2 | ~70 custom-property prefixes, 1,045 names; six different primaries (violet `#695df6`/`#6d5dfc` storefront, orange `#f47721` cart, `#8b5cf6`/`#2f75ff` menu, `#2f5bea`/`#0b6bdc` Identity, `#4457e6` Recharge); 1,672 distinct colour literals in Builder, 1,117 in Recharge (1,651 raw literals in form.css). |
| Typography | 3 | 37 font stacks, 427 distinct font sizes, 36 weights (synthetic 650/750/850/950); no webfont is actually loaded; Impact and Playfair in components. |
| Spacing rhythm | 3 | 552 distinct padding values, 255 margins, 91 gaps; no spacing scale. |
| Component consistency | 3 | 5 product-card markups (two share `.delicat-product-card` with different inner markup), 5–6 variation-selector styles, ~18 dialog/sheet systems, 6+ toast systems, 4 sticky buy bars, 2 mini-carts. |
| Responsive | 6 | No horizontal overflow at 390 px; on desktop the theme's own header and page list render under the Builder header *(default config)*. |
| Dark mode | 5 | Works through the toggle (618 `html.dlc-theme-dark` rules) but new visitors with a dark phone get light (store default `light`); Identity runs a second detection (27 localStorage keys, 2 MutationObservers); base.css has an unguarded `prefers-color-scheme` block. |
| Animation | 6 | Motion layer with tokens; reduced-motion respected in engine; some decorative transitions. |
| Accessibility | 6 | Account/order pages fixed to 4.5:1; no focus token (119 hand-written `:focus-visible` rules); 550+ emoji used as icons in customer text. |
| Information density | 5 | Guest account page shows two "Connexion" blocks; product page badges row duplicates trust claims. |
| Polish | 5 | Skeletons and empty states exist per module but differ everywhere. |

Generic-pattern check: violet→pink gradients (`--dlc-accent-2 #d946b7`, variation cards on a purple→orange
gradient), 118 `backdrop-filter` declarations (68 real blurs, 62 in Recharge), emoji as interface icons.

## What loads (engine active)
Every page: engine 144 KB raw / ~50 KB gzip JS; CSS chrome 163 KB (27 KB gzip) + polish 40–63 KB.
Guests also get Identity `auth-shell.js`, `account-theme.js` (head), `session-router.js`, `login.css`
and ~22 KB of inline modal CSS. Product pages re-add jQuery + `wc-add-to-cart-variation` (swatches).
Account: ~110 KB Identity CSS + order-view 27 KB; wallet endpoints + Recharge `form.css` 286 KB and jQuery `form.js`.

## Consolidation list (what survives)
1. **Tokens** — one `--ds-*` set (light/dark/system) printed once, first, by the Builder; every legacy
   namespace (`--dlc`, `--delicat`, `--dbv9`, `--dn`, `--dnp`, `--dpn`, `--dsb`, `--dlx`, `--ft`, `--vo`, `--oc`,
   `--dipma`, `--dipapp`, `--dip`, `--dipb`, `--dr`, Recharge `--ds-ink…`) becomes an alias; duplicate dark blocks go.
2. **Dialog/sheet/toast** — one primitive (Drawer/express-sheet focus and scroll handling); the Identity
   modal keeps its iframe body inside it.
3. **Product card** — `Carousel::product_card` + its deferred payload for home, archive, related, search, 404.
4. **Navigation** — engine navigation + DBP server capture; retire `dbp-nav.js`, `shell-nav.js`, `prefetch.js`;
   one prefetch strategy.
5. **Cart/checkout** — purchase-native, express sheet, `dnp` dock; one buy bar, one mini-cart.
6. **Variation selector** — swatches inside native-product, ported to the engine without jQuery; retire
   design.php cards.
7. **Login** — native modal everywhere (account and checkout open or embed it); no forced `login.css`/inline CSS.
8. **Wallet** — one balance endpoint and one wallet card (Builder); Recharge keeps the recharge form logic,
   its CSS rebuilt on tokens (target < 40 KB).
9. **Header/drawer/bottom bar** — header-runtime `.dsb8-header`, the Drawer, `Bottom_Nav`; Studio-8 file
   becomes settings only; `[delicat_bottom_nav]` and the legacy modern menu stop rendering a second copy.
10. **Service worker** — push merged into `/delicat-v9-sw.js`; `pwa-runtime.js` removed; real update prompt
    ("Une nouvelle version de Delicat Store est disponible." / "Mettre à jour") and offline page.

Also: one icon set (Lucide, inline SVG, 1.75 stroke) replacing ~25 icon helpers and interface emoji; one
font (Inter variable, self-hosted Latin + Latin-Extended WOFF2, `font-display: optional`); spacing scale
4/8/12/16/20/24/32/40/48/64; radii 8/12/16/20/24/full.

## Phase plan
| Phase | Delivers | Removes |
|---|---|---|
| 2 Foundation | `--ds-*` tokens (Design Studio source), system theme default, Inter, icon set, base components | legacy dark token blocks, violet/orange literals, Impact/Playfair |
| 3 Global navigation | header, bottom bar, drawer, search overlay, dialog primitive, footer on tokens | duplicate header/menu/bottom-nav paths, legacy nav JS |
| 4 Commerce | home sections, unified product card, product page + selector, cart/checkout | 4 extra card markups, design.php cards, extra buy bars/mini-cart |
| 5 Customer services | account, wallet, orders, Mes achats, rewards, auth UI | Identity theme detection, Recharge 286 KB CSS, second balance endpoint |
| 6 Performance + PWA | merged service worker, update prompt, offline page, prefetch strategy | push worker, `pwa-runtime.js` |
| 7–8 Integration, release | E2E + security review, staged zips with rollback notes | — |
