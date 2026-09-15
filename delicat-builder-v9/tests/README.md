# Delicat Builder V9 — tests

Plain PHP harnesses. Nothing here is loaded by WordPress; run them from the
plugin directory with the `php` CLI.

```
php tests/test-cache-gate.php
```

## test-cache-gate.php

Exercises `Delicat_Builder_V9_Security::public_cache_allowed()` against minimal
WordPress stubs. This gate decides whether the homepage, archives, product
pages and native pages are answered from LiteSpeed's shared cache or rebuilt in
PHP, so it is the single most load-bearing function for storefront latency.

The suite asserts both halves of the contract:

* **Cacheable** — a guest carrying WooCommerce cart/session cookies, and any
  request tagged with campaign parameters (`utm_*`, `fbclid`, `gclid`) or
  catalog navigation (`paged`, `orderby`, layered-nav filters).
* **Private** — signed-in and password-gated identities, a non-default currency
  cookie, anything carrying an action or nonce, and unrecognised query keys
  (which would otherwise mint unbounded cache entries).

It also pins the memoisation contract: `DONOTCACHEPAGE` defined *after* the
first call must still force a bypass. Breaking that would publish a private
document to the shared cache.

## test-shared-document.php

A structural guard, not a unit test. `public_cache_allowed()` lets a guest
carrying a WooCommerce cart cookie be served from the shared cache, which is
only safe while every renderer that could print the current visitor's basket
asks `Security::shared_document()` first and emits neutral markup instead.

The mistake this protects against is a *new call site*, not a wrong return
value, so it scans the modules that render the always-present page chrome
(header, bottom nav, shell, drawer, menu, footer) for cart reads —
`get_cart_contents_count()`, `get_cart_subtotal()`, `->get_cart()` — and fails
on any that is not inside a function also consulting `shared_document()`,
`is_user_logged_in()` (public caching is refused for signed-in visitors), or a
JSON/no-cache responder.

It also pins that `shared_document()` still defers to `public_cache_allowed()`
rather than drifting into a second opinion about what is cacheable.

Both suites exit non-zero on failure, so they can gate a deploy:

```
php tests/test-cache-gate.php && php tests/test-shared-document.php
```
