# Delicat Design System 2.0

One source of design tokens for the storefront (Delicat Builder), Delicat Identity and Delicat Recharge.
Audit and phase plan: [AUDIT.md](AUDIT.md). Machine-readable tokens: [design-tokens.json](design-tokens.json)
(exported from `Delicat_Builder_V9_Design_System::export()`; regenerate it, never edit by hand).

## Where it lives
- `includes/class-delicat-builder-design-system.php` — the only place token values are defined.
  Printed inline as `<style id="delicat-ds-tokens">` at `wp_head` priority 0 (≈4.5 KB, 1.3 KB gzip), before
  the pre-paint theme boot and every stylesheet, on storefront, account, wallet and `wp-login.php` pages.
- Overrides: option `delicat_builder_v9_design_system` (`light|dark` → `primary`, `primary-hover`, `brand`,
  `background`, `surface`, `text`, `#RRGGBB` only) and the `delicat_ds_colors` filter. The Design Studio
  screen (Phase 3) writes this option.
- Other plugins: print the same tokens with `do_action( 'delicat_ds_print_tokens' )`; use
  `function_exists( 'delicat_ds_icon' )` before calling the icon helper.

## Decisions
| Decision | Why |
|---|---|
| Primary `#2563EB`, brand ink navy `#00004F` | Matches the logo (navy + royal-blue wave); owner choice 2026-11-01. Replaces six competing primaries. |
| Text pairs ≥ 4.5:1, controls/focus ≥ 3:1 | WCAG 2.2 AA. Measured: muted `#5F6B7E` ≥ 4.93:1 on every light surface; success/warning text use darker `-text` tokens (`#15803D`, `#B45309`, 5.02:1). |
| Theme default = follow the device | New visitors get light unless their phone asks for dark; a saved choice (cookie `dbv9_theme`) wins. |
| Inter variable, self-hosted, `font-display: optional` | One family, no layout shift when it loads, no third-party request; only the 48 KB Latin file is preloaded. |
| Material Symbols Rounded, `currentColor`, no emoji | Owner choice 2026-11-01: modern Material glyphs only; 96 curated icons (outline + `-fill` for active states) rendered server-side, or as a CSS mask via `icon_mask()` for markup the plugin does not own; every emoji removed from the interface. |
| Legacy variables alias tokens | `--dlc-*`, `--dipma-*`, `--dr-*`… keep working but resolve to `--ds-*`, so no component needs rewriting before its own phase. |

## Tokens
Colours (`--ds-color-*`, light / dark): `primary`, `primary-hover`, `primary-subtle`, `primary-contrast`,
`brand`, `brand-contrast`, `background`, `surface`, `surface-2`, `surface-elevated`, `text`, `text-muted`,
`border` (decorative), `border-strong` (inputs, 3:1), `success`/`success-text`, `warning`/`warning-text`,
`danger`/`danger-text`, `focus`, `overlay`.

Scale (theme-independent): `--ds-font-sans`, `--ds-font-mono`, `--ds-font-weight-{regular,medium,semibold,bold}`,
`--ds-text-{meta 12, secondary 13, body 15, button 15, card-title 15, section 21, page 28}` (mobile; components
step up on desktop), `--ds-space-{1,2,3,4,5,6,8,10,12,16}` = 4–64 px, `--ds-space-page` 16 px,
`--ds-radius-{sm 8, md 12, lg 16, xl 20, 2xl 24, full}`, `--ds-shadow-{1,2,3}` (per theme),
`--ds-duration-{press 110, tab 150, page 180, modal 200}ms`, `--ds-ease`, `--ds-tap-target` 44 px,
`--ds-icon-{sm 18, md 20, lg 24}`, `--ds-content-max` 1200 px.

## Rules for new work
1. Never write a colour, font, radius, shadow or duration literal in a component — use a `--ds-*` token.
2. Text on a surface uses `text` or `text-muted`; coloured status text uses the `-text` variants.
3. Icons come from `delicat_ds_icon()` (Material Symbols names with hyphens); no emoji anywhere in the interface or messages.
4. Motion uses the duration tokens and is disabled under `prefers-reduced-motion`.
5. Before adding a component, check AUDIT.md's consolidation list — extend the surviving implementation.
