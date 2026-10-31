# Account and order details tests (Identity 6.15.2, Recharge 6.5.1, Builder 9.5.1)

WordPress test site (Playground build on SQLite) with `zz-wc-stub-v3.php` (WooCommerce stub with full
orders) and `zzz-vo-builder.php` (loads the Builder order modules as the Runtime Router does).

- `setup-vo.php` / `activate.php`: fixtures (7 order states + another customer's order) and activation.
- `vo-shots.cjs`, `vo-el.cjs`: order details screenshots per state, light/dark, 360–1280 px.
- `nav-account.cjs`: engine on static account pages (in-place switching, full-page cases, polling stop).
- `nav-real.cjs`: engine injected into the real Identity account pages (dashboard → orders → order → back,
  order with codes via 204, balance eye after in-place switching).
- `nav-bisect.cjs`, `hist-min4.cjs`: the Chrome Back quirk (pushState + same-URL navigation) and its fix.
- `acct-shots.cjs`, `eye.cjs`, `rewards.cjs`, `assets-check.cjs`, `growth.php`, `setup-account.php`:
  account home (wallet, rewards, recent orders, favourites) and Recharge asset loading.
