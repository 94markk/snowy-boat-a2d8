# Delicat Builder V9 Pro — pro.37 → pro.38

Continuation of AUDIT-PRO37.md. Two bugs found here can sell a product for
**0.00**, both in the product-fields pricing formula evaluator.

The previous audits state plainly: *"PHP CLI is unavailable, so PHP lint and the
three PHP harnesses were not run."* That is how the `wp_rand` fatal and a failing
critical-CSS test shipped. PHP is available in this session, so this round is
mostly things only execution finds.

---

## 1. PHP requirement restored to 8.5

Done as asked. `Requires PHP: 8.5` and all four runtime gates are back.

One note for the record, kept in the code comment: the *syntax* floor is 8.3, not
8.5 — a single feature, the typed class constants in
`class-delicat-builder-heart-engine.php`. Nothing in the plugin uses 8.4 or 8.5
syntax or functions. The gate is a deliberate host requirement, not a parse
requirement, so it can be lowered later without touching code if the plugin ever
needs to run somewhere older. On a host below 8.5 the storefront keeps running on
plain WordPress and WooCommerce and administrators get a notice.

I also checked the codebase against 8.4/8.5 deprecations while targeting 8.5:
no implicitly nullable parameters, no `E_STRICT`, no removed constants, no
dynamic-property assignment, and no static call to a non-static method.

---

## 2. A formula naming an unknown variable priced the product at 0.00

`compute_total()` carries this comment, and it is correct in intent:

> A malformed formula must never make the product free. Fall back to the native
> price plus field contributions and leave a trace for the admin.

That protection is driven entirely by `$eval['ok']`. But in `tokenize()`, an
unknown variable resolved to `0.0` and the expression still reported **ok**:

```php
$val = isset( $vars[ $name ] ) ? (float) $vars[ $name ] : 0.0;
```

So the single most likely authoring mistake — naming a field that was renamed,
deleted, or simply mistyped — walked straight past the guard. A formula
`{base} * {qty_mult}` against a field actually called `qty_multiplier` evaluates
to `base * 0`, `ok` is true, `compute_total()` takes the success branch, and the
product sells for **0.00 with nothing logged**.

The author had already reasoned about this exact hazard one branch away, for
functions:

> An identifier followed by parentheses is always a function call. Reject unknown
> calls instead of silently treating the identifier as 0.

The variable half was simply never closed.

**Fixed.** An unknown variable now aborts tokenising and is reported as
`unknown_var:<name>`, which routes into the fallback and logging that already
exist — and the log line names the offending variable.

Rejecting is safe: the caller seeds `$vars` with `base`, `rate` and **every**
configured field id (inactive fields are seeded `0.0`), so a name missing from
`$vars` cannot be a legitimate reference.

---

## 3. Every arithmetic failure also reported a successful 0.00 — the worse one

`eval_rpn()` returned `0.0` for **every** failure: stack underflow, division by
zero, modulo by zero, `sqrt` of a negative, and a leftover stack. `0.0` is also a
perfectly legitimate result, so the two were indistinguishable.

`evaluate_checked()` then did:

```php
$result = self::eval_rpn( $rpn );
if ( ! is_finite( $result ) ) { return $fail( 'not_finite' ); }
return array( 'ok' => true, 'value' => (float) $result, … );
```

`is_finite( false )` coerces `false` to `0.0` and returns **true**. So the failure
was reported as a *successful* 0.00 and the product sold for free.

Measured on the shipped build:

| formula | reported |
|---|---|
| `1/0` | `ok=true value=0.0` |
| `sqrt(-1)` | `ok=true value=0.0` |
| `2+` | `ok=true value=0.0` |
| `*5` | `ok=true value=0.0` |
| `1%0` | `ok=true value=0.0` |

This defeats the entire purpose of `evaluate_checked()`, whose own docblock says
*"Callers that touch money must use this."*

**The realistic path is division by zero.** The caller seeds inactive fields with
`0.0`, so an ordinary configuration where a shopper leaves a field off makes
`{base} / {qty}` divide by zero — and the product becomes free.

**Fixed.** `eval_rpn()` now returns `false` for failure, which `0.0` can never
collide with, and `evaluate_checked()` tests `false === $result` strictly *before*
`is_finite()`. A formula that legitimately equals zero (`{base} - {base}`,
`{base} * 0`) still succeeds and returns `0.0`.

---

## 4. New regression test

`tests/test-formula-eval.php` — 29 assertions covering both bugs, the realistic
division-by-zero path, unknown function calls staying rejected, malformed input,
and the "legitimately zero must still succeed" case that makes the fix non-trivial.

---

## 5. Hunted and found clean

These were searched systematically, not sampled:

- **Pluggable functions on the boot path** (the `wp_rand` class of bug). Built a
  transitive call-graph scanner over every include-time entry point, validated it
  against the known pro.36 bug — it catches it — and it reports **clean** on the
  current tree.
- **WordPress conditional tags on `init` or earlier** (`is_product`, `is_cart`,
  `is_page`, …, which need the main query): none reachable.
- **Static calls to non-static methods** (fatal on PHP 8): none.
- **Calls to methods a class does not declare** (the RC18 `footer_expected` class
  of bug): none.
- **Money paths in the multi-currency module**: sound. `rate()` falls back to
  `1.0` (no conversion) rather than `0`, and every conversion path returns the
  original price on any doubt. No error-conflation there.

## 6. The three forked module pairs — re-examined, and I was too pessimistic

AUDIT-PRO37 flagged `header-runtime`/`header-studio-8`,
`menu-runtime`/`menu-builder` and `unified-runtime`/`unified-modules` as drifted
forks where a fix on one side would not reach the other. I diffed them
method-by-method and checked the three highest-risk divergences. All three are
**intentional**, not drift:

- `settings()` — the admin sanitises on **write** (`sanitize_settings()` does the
  full clamp, hex validation and URL migration), the runtime heals on **read**.
  Two halves of one correct design.
- `load_modules()` — the runtime defers product-fields to `wp` so guests do not
  parse ~50 KB of calculator PHP on the homepage; admin loads it eagerly because
  the editor needs it.
- Web Push loads eagerly in admin, through `maybe_load_web_push_transport()` at
  runtime.

The maintenance concern stands — two files sharing one class name is easy to get
wrong — but I found no bug caused by it, and merging them is not urgent. Treat
§5/§8 of AUDIT-PRO37 as downgraded accordingly.

---

## Verification

```
PHP lint                111 / 111 files clean
test-formula-eval        29 / 29   (new)
test-critical-budget      7 / 7
test-cache-gate          31 / 31
test-shared-document     13 / 13
test-engine-pro35        PASS
test-engine-pro36        PASS
asset map               116 / 116 present, hashes match
integrity manifest      0 mismatches
boot-path scanner       clean (validated against the known pro.36 fatal)
```

Still true, and worth repeating: nothing here has been exercised against a live
WordPress + WooCommerce install. Install on staging, purge LiteSpeed and
Cloudflare, and re-test product pricing with the calculator enabled — especially
any product whose formula divides by a field a shopper can leave empty.
