# Delicat Identity Pro — audit 6.9.18

Scope: 67 PHP files, 13 JS, 13 CSS (~800 KB) read for the authentication,
session, cookie, OAuth, two-factor, passkey and endpoint-authorisation paths,
plus the sign-in popup's runtime behaviour on a low-end phone.

---

## 1. Root cause behind most of the reported symptoms

**`is_ssl()` was answering the wrong question, in 69 places.**

`is_ssl()` reports whether *PHP* was reached over TLS. On delicastoreha.com TLS
terminates at Cloudflare and at LiteSpeed, and the hop from there to PHP can be
plain http — so `is_ssl()` can be `false` on a site that is https end to end for
every customer.

The plugin already knew. `DIP_Plugin::proxy_https_detected()` existed for exactly
this case and its own docblock said it was *deliberately* confined to the admin
diagnostic: *"the OAuth gate itself still requires is_ssl()"*. Every gate
therefore kept the wrong answer, and each one fails differently:

| Gate | What a customer sees |
|---|---|
| `configured()`, `google_availability()` | Google login reports itself unconfigured; the button vanishes from the modal, My Account and checkout |
| `current_url()` → OAuth `redirect_uri` | Built as `http://…`, which Google refuses — **sign-in fails with an error** |
| `DIP_Two_Factor` (11 sites) | 2FA refuses outright: a customer with 2FA on cannot finish signing in at all |
| `wp_signon(…, is_ssl())`, `wp_set_auth_cookie(…, is_ssl())` (5 sites) | **The session cookie is issued without the `Secure` flag on an https site** |
| `DIP_Mobile_API` (5 sites) | Every mobile endpoint answers 403 |
| `DIP_Privileged_Social` | Administrator Google sign-in blocked |

**Fixed.** New `DIP_Request::is_secure()` reads `X-Forwarded-Proto`,
`X-Forwarded-Scheme`, `X-Forwarded-SSL` and `CF-Visitor` — but **only when the
site's own `home_url()` is https**. A plain-http install cannot be talked into
claiming encryption by a forged header, and on an https install a forged header
buys an attacker nothing they could not have by using https themselves. A proxy
chain is read from the client-facing end. `apply_filters('dip_request_is_secure')`
lets an unusual front end override it.

`DIP_Request::current_url()` now takes its scheme *and host* from `home_url()`,
never from `is_ssl()` or the `Host` header, and validates `REQUEST_URI` (the old
version also let a protocol-relative or CR/LF-bearing path through).

---

## 2. Logout landed on wp-login.php — two separate causes

**a. Nothing filtered where logout goes.** There was no `logout_redirect` filter
and no `wp_logout` action anywhere in the plugin, so WordPress' default applied:
`wp-login.php?loggedout=true`. Every customer who signed out got the grey
WordPress form, and then naturally tried to sign back in *there*.

**b. The logout nonce outlived its page.** `wp_logout_url()` signs the link with
a nonce bound to the session and a 12-hour tick. That link then sits in a page
restored from history, held by LiteSpeed, or left open overnight. By the time it
is clicked the nonce no longer verifies and WordPress answers with
*"Vous êtes en train de vous déconnecter… Voulez-vous réellement vous
déconnecter ?"* — a dead end.

**Fixed** in the new `DIP_Logout`:

- `logout_redirect` → the storefront, carrying `dip_auth_sync=1` so Builder V9's
  `session.js` drops its cached signed-in shell. Administrators keep the
  WordPress form, because signing out of the dashboard should land there.
- `logout_url` → every logout link gains a destination, whoever built it
  (WooCommerce, the theme, the menu builder).
- A requested destination is honoured only if it is not a sign-in screen —
  including **WooCommerce's My Account page**, which to a just-signed-out visitor
  is simply another login form.
- The nonce is still checked **first** and still wins. Only when it fails does
  the rescue ask a second question: is this a top-level, same-origin document
  navigation from a signed-in browser? `Sec-Fetch-Site` is set by the browser and
  cannot be forged from script, so a cross-site page cannot satisfy it and
  neither can an `<img>` or `<iframe>` — which is the entire threat a logout
  nonce defends against. Older browsers fall back to a same-origin `Referer`;
  a request with neither is refused. Every rescue is written to the audit log.
- `?loggedout=true` and `?dip_error=` on wp-login.php are redirected to the
  storefront. The real login form, password reset, registration and an admin's
  `redirect_to` into wp-admin are all left alone, so the dashboard stays
  reachable. `dip_logout_leave_login_page` switches it off.

---

## 3. A failed sign-in dumped the customer on wp-login.php

`DIP_Plugin::fail()` ended with
`wp_safe_redirect(add_query_arg('dip_error', $code, wp_login_url()))` whenever the
native-modal tracker was not set. So any social-login failure put a customer who
was in the storefront a second earlier onto the bare WordPress form with a
WordPress notice.

**Fixed.** wp-login.php is now reached only when the sign-in genuinely *began*
there (an administrator using the Google button on the WordPress form). Every
other failure returns to the storefront with `dip_auth_error=<code>` and
`#delicat-login`, where the popup already holds a French sentence for each of the
50 codes.

---

## 4. The sign-in popup

### What actually made it slow and buggy

1. **The dead button.** v4 disabled every submit button until a round trip to
   `admin-ajax.php` returned security nonces — and started that round trip only
   *after* the dialog opened. `admin-ajax.php` is uncached and boots all of
   WordPress; on a cheap phone that is 1–2 s during which the customer looks at a
   finished form whose button does nothing. If the request failed, **the button
   never came back at all** — the popup became a picture.
2. **Nonces lived in hidden `<input>`s**, readable by any script on the page, and
   were thrown away on close, costing another round trip on every reopen.
3. **`backdrop-filter: blur(8px)`** over the whole viewport — the single most
   expensive thing a low-end GPU can be asked for — composited on every frame of
   the fade-in.
4. **A `MutationObserver` on `documentElement` with `subtree: true`** re-querying
   the DOM on every mutation the page made.
5. The dialog was **re-parented into `<body>`** on every open, animated through
   two nested `requestAnimationFrame`s, and paused **380 ms** after a successful
   sign-in before navigating.
6. **The email field was focused 130 ms after opening**, so the keyboard shoved
   the dialog upward just as it finished animating in.
7. `width: min(560px, calc(100vw - 28px))` — `100vw` counts the scrollbar, so the
   card overflowed sideways on desktop.

### What 6.9.18 does

- Nonces are fetched **on intent** — `pointerdown` on any trigger, which fires
  long before the click completes — kept in closure memory, shared by every form,
  and reused across opens. **The submit button is never disabled** while waiting;
  pressing it starts the sign-in and one spinner covers both.
- A nonce a cache outlived repairs itself: one silent refetch and resend.
- No `backdrop-filter`, no re-parenting, no document-wide observer (the legacy
  dialog is hidden by the stylesheet; the observer, when a legacy snippet is
  actually present, watches `document.body`'s own child list only), one `rAF`, no
  artificial pause, `opacity` and `transform` only.
- On a touch device focus lands on the card, not in a text field.
- The card follows your thumb downward from the header and closes — the header is
  the handle precisely so the card itself keeps scrolling a form taller than the
  screen.
- The shadow was reduced from a 92 px blur to two tighter layers.
- `login.css` + `identity-modal-v3.css` (~32 KB) are no longer render-blocking on
  an ordinary shop page. A page that opens the dialog by itself keeps the
  blocking load so it can never flash unstyled; `<noscript>` keeps it working
  without JavaScript.

---

## 5. What the audit found to be sound

Worth saying, because it is most of the plugin:

- **Crypto** (`DIP_Crypto`): XSalsa20-Poly1305 via sodium, AES-256-GCM fallback,
  authenticated, random nonce/IV, key derived from `wp_salt('auth')` +
  `wp_salt('secure_auth')`. Correct.
- **Passkeys**: RP-ID hash comparison, exact origin equality, `crossOrigin`
  rejection, challenge compared with `hash_equals`, user-handle binding, and the
  expected origin already derived from `home_url()` rather than `is_ssl()`.
- **Two-factor**: pre-auth cookie + transient + browser binding, nonce bound to
  the token, attempt counter, rate limit, and a challenge page served under a
  strict `Content-Security-Policy` with `X-Frame-Options: DENY`.
- **Modal nonces are browser-bound** (HMAC'd against an HttpOnly first-party
  cookie), so a nonce harvested in another browser is useless.
- **SQL**: every query is either prepared or fully static. No user input reaches
  an identifier.
- **No** `eval`, `create_function`, `extract`, or raw `unserialize` anywhere.
- **Endpoint authorisation**: every `admin_post_*` and `wp_ajax_*` endpoint
  carries a capability check and a nonce; the public OTP, magic-link and
  registration endpoints are rate-limited per address and per IP bucket.
- Password-reset and verification e-mails are generic enough not to work as an
  account finder.

---

## 6. Recommendations (not done — your call)

1. **Set `$_SERVER['HTTPS']` in `wp-config.php`.** The fix above makes the plugin
   correct behind the proxy, but WordPress core, WooCommerce and every other
   plugin still call `is_ssl()`. Adding, above `require_once ABSPATH . 'wp-settings.php'`:
   ```php
   if ( ! empty( $_SERVER['HTTP_CF_VISITOR'] ) && str_contains( $_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"' ) ) { $_SERVER['HTTPS'] = 'on'; }
   elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( explode( ',', $_SERVER['HTTP_X_FORWARDED_PROTO'] )[0] ) ) { $_SERVER['HTTPS'] = 'on'; }
   ```
   fixes it for the whole site at once. Only do this if the origin is reachable
   *only* through Cloudflare.
2. **Do not add per-IP rate limiting to `dip_native_fresh_nonces_v2`.** It is the
   one public endpoint without one, and it looks like an omission — but Haitian
   mobile carriers NAT thousands of customers behind a handful of addresses, so
   a per-IP cap here would lock out real people. The endpoint only issues nonces.
3. **`bypass_cache_redirect`** appends a random `dip_nocache` value to the Google
   button URL on every render, which makes the HTML non-deterministic and defeats
   `ETag`/`If-None-Match` revalidation on every page carrying the button. Worth
   turning off unless a specific cache problem needed it.
4. **Eight nonces are issued per `fresh_nonces` call**; the popup uses three.
   Harmless, but the other five (OTP, magic link, customer registration) are
   handed to every visitor who touches the sign-in button.
5. **Consider removing the legacy Code Snippets compatibility layer** once you
   confirm the old snippet is gone. `legacy_snippet_active()`, the duplicate
   `delicat_*` AJAX actions and the suppression pass all exist only for it.
6. **`wp-login.php` is still reachable and still renders.** Nothing here hides it.
   If you want it gone for customers entirely, that is a server-level rule, not a
   plugin one.

---

## 7. Verification

- `tests/identity-auth-test.php` — 94 assertions. The classes are loaded and
  called against stubbed WordPress functions; **33 deliberate regressions were
  introduced one at a time and all 33 were caught.**
- `tests/browser/identity-modal-test.cjs` — 25 assertions in a real Chromium at
  390×844 with touch, over an `admin-ajax` deliberately delayed by 1.2 s (an
  ordinary round trip on a cheap phone on 3G). **Running the same suite against
  the 6.9.17 script times out on a permanently disabled submit button** — the
  reported bug, reproduced.
- `php -l` clean on all 68 PHP files; endpoint-authorisation and hook-signature
  checkers clean.

**Not verifiable here:** there is no live WordPress, WooCommerce, Google OAuth or
TeraWallet in this sandbox. Sign in, sign out, and sign in with a wrong password
on the real site before trusting any of this with customers.
