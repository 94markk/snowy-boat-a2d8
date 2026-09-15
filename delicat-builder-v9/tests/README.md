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
