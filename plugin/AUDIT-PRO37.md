# Delicat Builder V9 Pro — full audit, 9.2.0-pro.36 → pro.37

Scope: all 367 shipped files (110 PHP, 239 CSS/JS, templates, manifests).
Method: static analysis of every file, PHP lint on all 110, the plugin's own
five test suites, and a unit harness for the rewritten asset URL filter.

---

## 1. The headline finding: the Pro Kernel was dead

Your admin screenshot showed it, and the code confirms it:

> Pro Kernel — Boot failed … class-dbp-kernel.php … function wp_rand(). … scoped assets are OFF

`DBP_Kernel::boot()` → `deploy_check()` → `bump_generation()` called **`wp_rand()`**.
`wp_rand()` lives in `wp-includes/pluggable.php`, which WordPress loads *after*
plugin files are included. At that point the function does not exist, PHP throws,
and the `catch ( Throwable )` in `delicat-builder-v9.php:214` swallowed it.

`deploy_check()` only runs when the recorded version differs from the running one
— **every plugin update**. So every update killed the Pro layer for that request,
and with it:

| Layer | What you lost |
|---|---|
| `DBP_Nav` | Instant navigation. Clicking a product did a **full page load**. |
| `DBP_Assets` | Route-scoped assets + inlined critical CSS |
| `DBP_State` | Single shared session/state store |
| `DBP_Security` | Hardened headers, endpoint limits |
| `DBP_Type` | One type/colour/radius scale |
| `legacy_nav_off` | Legacy nav engines were **no longer silenced** — `shell-nav.js` (28 KB) and `prefetch.js` (10 KB) ran instead of, and alongside, the Pro engine |

It also meant `bump_generation()` never completed, so `wp_cache_flush()` and the
three `litespeed_purge_*` actions never fired — **a deploy never actually
invalidated the browser's copy.** That is why the site felt stale as well as slow.

This one line is the direct cause of "it is not fast, it is very slow" and of
"when I click on a single product I want it to load instantly".

**Fixed.** `random_int()` (native, always available, cryptographically secure)
with a `wp_rand()` preference when it exists. `deploy_check()` also moved to
`init` so the cache purges fire when LiteSpeed and the object cache actually
exist and a purge is real.

**Also fixed:** the boot-error record was sticky — nothing ever cleared it, so
Delicat Pro kept reporting a fault after it was resolved. It now clears itself on
the first successful admin request.

### What this restores for product pages

`TurboNav::prerender_targets()` was already written for exactly your ask: when the
Pro engine is active it emits speculation rules scoped to `/<product-base>/*`
(resolved through `wc_get_permalink_structure()`, so it correctly matches your
French `produit` slug). Chrome **prerenders** the product page on hover/touch
intent — HTML, CSS, JS and layout all done before the click. Navigation is
effectively 0 ms.

With the kernel dead, `pro_navigation_active()` was false and that scoping
collapsed to `/*`, prerendering *every* link — maximum mobile bandwidth, none of
the targeted benefit. The mechanism was built and correct; it was simply never
reached.

---

## 2. Duplicate files — 121 removed, 1.77 MB

Every asset shipped **twice**: `foo.css` and `foo.<hash>.css`.

- 239 CSS/JS files → **121 were hashed twins**
- 116 byte-identical to their source
- **5 had silently drifted** — the twin no longer matched the file it was built
  from, so browsers were served code the plugin no longer contained:
  `heart.js`, `purchase-native.js`, `session.js`, `dbp-nav.js`, `dbp-state.js`

`Audit_Fixes::asset_url()` rewrote every URL onto the twin, so both copies had to
ship — more than half of all CSS/JS bytes existed only to version the other half.

**Fixed.** Content-addressed **versions**, not filenames: the map now holds the
hash and applies it as `?ver=<hash>`, which is what WordPress already appends and
what LiteSpeed already varies on. The cache-busting guarantee is identical — the
URL changes if and only if the bytes change — with one file per asset.

Verified: all 116 assets present, all hashes match, no dangling references, and a
9-case unit test covering plain URLs, existing `?ver`, extra query params, http
staging scheme, unmapped files and foreign hosts. The rewrite is idempotent, which
matters because `Audit_Fixes::document()` re-applies it to every `<script>`/`<link>`.

`asset-history.json` (which tracked which twins were still safe to keep) is
retired — with no twins there is nothing to retain.

| | before | after |
|---|---|---|
| Files | 367 | 245 |
| Unpacked | 6.8 MB | 4.7 MB |
| Zip | 1.6 MB | 1.2 MB |

---

## 3. The plugin was one PHP release away from switching itself off

The header read `Requires PHP: 8.5`, enforced by a hard `PHP_VERSION_ID < 80500`
return, plus two more gates in the activation hook and production preflight.

PHP 8.5 is very new and most managed WordPress hosts do not offer it yet. On any
host below it, **the entire plugin silently disabled itself** and the storefront
fell back to plain WooCommerce.

All 110 files lint clean on PHP 8.4, and the real floor is **8.3** — one feature,
the typed class constants in `class-delicat-builder-heart-engine.php`. No 8.4 or
8.5 syntax or functions are used anywhere.

**Fixed.** All four gates corrected to 8.3, with the French admin notice and 16
file doc-headers updated to match.

---

## 4. The homepage was shipping without its product-grid critical CSS

The plugin's own `tests/test-critical-budget.php` **was already failing** on the
shipped build:

```
FAIL  builder homepage   5309 B of 8000 -- DROPPED: products-home (3621 B)
```

`critical_css()` appends whole files and skips any that would cross the budget, so
a budget slightly too small does not trim the tail — it drops an entire route
stylesheet, silently, on every request. Homepage needs 5309 + 3621 = 8930 against
a 8000-byte floor, so **the product grid painted unstyled and reflowed** on your
most-visited page on every load.

The test's own header documents this exact failure mode happening before at a 7000
floor. It happened again at 8000 and was shipping.

**Fixed.** Floor raised to 9500 — clears the homepage with room to spare, still far
below the ~14 KB first-flight window so the inline block arrives in the first round
trip. The admin input `min` was updated to match (the test asserts they agree), and
the runtime clamp auto-corrects existing installs that saved 8000.

All 7 budget assertions now pass.

---

## 5. Structure: three modules exist as forked twins

Three class names are declared in two files each:

| Class | Front-end file | Admin file | Shared lines |
|---|---|---|---|
| `Delicat_Builder_V9_Header_Studio_8` | `header-runtime.php` | `header-studio-8.php` | 243 / ~380 |
| `Delicat_Builder_V9_Menu_Builder` | `menu-runtime.php` | `menu-builder.php` | 436 / ~720 |
| `Delicat_Builder_V9_Unified_Modules` | `unified-runtime.php` | `unified-modules.php` | — |

They never load together — the bootstrap picks the `-runtime` variant for the
storefront and the other for Builder admin screens — so there is no fatal. But
they have **drifted roughly 35–40%**, which means a fix applied to one side does
not reach the other. `render_icon_menu()` is byte-identical in both header files;
other methods are not.

**Not changed, deliberately.** Merging three forked class pairs is a real refactor
that needs a staging site to verify, and I cannot run WordPress here. Doing it
blind is how you trade a slow store for a broken one. The recommended approach is
in §8.

---

## 6. Security — genuinely solid

I found no vulnerabilities. This code has clearly been audited before and it holds up:

- **No** `eval`, `assert`, `create_function`, `extract`, `system`, `shell_exec`, `passthru`
- `unserialize()` appears once, correctly guarded with `allowed_classes => false`
  (`class-delicat-builder-express-payment.php`) — PHP object injection is closed
- 18 `$wpdb` calls, all prepared. The single interpolated query
  (`web-push.php:94`) interpolates only `$wpdb->prefix`-derived table name, no user input
- **72 nonce/referer checks across 37 files** for 39 registered endpoints
- 6 `nopriv` AJAX endpoints, all read-only or cart-scoped, all nonce-gated
- `DISALLOW_FILE_EDIT` forced on
- File writes confined to the CSS compiler, using `LOCK_EX` + atomic `rename()`,
  with target filenames validated against `/^page-\d+-[a-f0-9]{16}\.css$/`
- Outbound HTTP is two `wp_remote_get()` calls (self-test, stability), no cURL
- Capability checks present on every admin action sampled

Two hardening notes, neither a vulnerability:
- `DBP_Security` (headers, endpoint limits) was **off entirely** while the kernel
  was dead — restoring the kernel restores it. Worth confirming the "Conservative
  security headers" toggle in your screenshot is still on after updating.
- The `?dbp=off` recovery switch is correctly gated on `manage_options`.

---

## 7. Per-request cost — what I checked and what is fine

- **Option churn:** 177 `get_option()` sites, but 113 writes use `autoload=false`
  and `wp_prime_option_caches()` already batches the nine hot storefront reads
  into one query. Correct as built.
- **Caching:** 84 transient/object-cache calls; your persistent object cache is
  green. Good.
- **Boot gating:** the bootstrap is careful — admin, AJAX, `delicat_session`,
  safe-mode and public paths each load a distinct module set, and several heavy
  modules (Cloudflare, production, announcement, shell, live-selling) are skipped
  entirely when their feature is off. The front-end path loads 21 modules.
- **Full-document buffers:** three exist, and they are not the problem I expected.
  `App_Polish::filter_document()` bails on one cheap regex when no emoji are
  present and is off by default. `Audit_Fixes::document()` walks every tag with
  `WP_HTML_Tag_Processor` on every front-end page — the one real per-request cost,
  but it is a streaming parser and it is doing necessary work (LiteSpeed
  exclusions, LCP image un-lazying). Left alone.
- **111 enqueue calls across 58 files** is a lot of places to reason about, but
  `DBP_Assets::route_map()` is honest that it is a safety net and that each site
  gates itself. With the kernel alive the net is back in place.

---

## 8. Recommended next, in order

1. **Install pro.37 and purge LiteSpeed + Cloudflare.** The generation bump now
   fires on `init`, but the caches holding the old combined stylesheet predate it.
2. **Confirm in Delicat Pro** that the Pro Kernel row is green and "scoped assets"
   reports ON. The stale boot error clears itself on first admin load.
3. **Clear the three-week-old circuit-breaker notice** from your screenshot. It is
   a record of a fatal on 08-28 that nothing clears, and the panel tells you to
   clear it by hand but gives you no button:
   ```
   wp option delete delicat_builder_v9_circuit_breaker_tripped
   wp option delete delicat_builder_v9_boot_failures
   ```
4. **Measure before doing more.** With the kernel restored the site should feel
   categorically different. Take a Lighthouse run on the homepage and on one
   product page *after* step 1 — further optimisation should be aimed at what that
   measurement shows, not guessed at.
5. **Then merge the forked modules (§5)**, on staging, one pair at a time, keeping
   the `-runtime` file as the survivor and moving admin-only methods behind an
   `is_admin()` branch. Diff each pair first — roughly 60% is already identical.

---

## Verification

```
PHP lint                110 / 110 files clean
test-critical-budget     7 / 7   (was 6 / 7 — pre-existing failure, now fixed)
test-cache-gate         31 / 31
test-shared-document    13 / 13
test-engine-pro35       PASS
test-engine-pro36       PASS
asset map               116 / 116 present, hashes match, 0 dangling refs
integrity manifest      244 files, 0 mismatches
asset URL filter        9 / 9 cases (new unit harness)
```

Every change is verifiable by static analysis or by these suites. Nothing here has
been exercised against a live WordPress + WooCommerce install — that is step 1
above, and it should be done on staging first.
