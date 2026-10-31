DELICAT RECHARGE — v5.4.0  ·  Live balance update (no page refresh)
==================================================================

WHAT CHANGED (what the client sees)
-----------------------------------
When the client taps "✈️ Envoyer la demande", the wallet balance now
updates BY ITSELF — no page reload needed.

  • Instant credit (MonCash / Natcash Auto TC, when the matching SMS has
    already arrived): the balance counts up to the new amount right away,
    with a smooth easing animation and a soft green "credited" glow.
  • Deferred credit (still waiting on the payment SMS): the page quietly
    checks the balance in the background for up to 2 minutes and animates
    the moment the SMS credits the wallet — the client can just watch the
    number change, no refresh.

If the client already left / no balance card is on the page, nothing
happens (safe no-op). Respects "reduce motion" accessibility setting
(number still updates, just without the glow/scale).

HOW IT WORKS (technical)
------------------------
1) NEW endpoint  wp_ajax_delicat_balance  (logged-in only) returns the
   caller's current TeraWallet balance as { balance, balance_fmt,
   currency }. Nonce-protected (delicat_nonce), nocache_headers(), and
   rate-limited to 40 requests / minute / user (transient-based).
2) The two instant-credit responses (MonCash + Natcash-TC) now also carry
   the fresh balance, so the count-up starts with zero extra round-trips.
   try_instant_verify() already credits the wallet synchronously before
   responding, so the returned number is the real post-credit balance.
3) The balance card's amount now has  id="dw12-balance-amount"  and a
   data-balance="<raw float>" attribute so the JS can find it and knows
   the exact starting number (no locale-parsing guesswork). The final
   displayed value uses the server's balance_fmt, so it matches exactly
   what a page reload would show (same thousands separator).
4) form.js: animateBalance() = requestAnimationFrame count-up with
   easeOutCubic; watchBalance() = instant-animate + short background poll
   that stops the instant the number grows (or after 2 min).

BACKEND CREDIT LOGIC IS UNTOUCHED
---------------------------------
No change to verification, crediting, anti-double-dip locks, sender
binding, or the SMS matchers. This round only ADDS a read-only balance
endpoint and the front-end animation. Nonces, rate limits and the
manage_options/device-token authZ model are all unchanged.

FILES CHANGED
-------------
  delicat-recharge.php              5.3.3 -> 5.4.0 (busts CSS/JS cache)
  includes/class-ajax-handler.php   + handle_balance() + wallet_balance_
                                    payload(); balance merged into the 3
                                    auto-approved success responses
  includes/class-shortcode.php      balance amount div gets id +
                                    data-balance (raw float)
  assets/js/form.js                 live balance watcher + count-up
                                    animation; fired on submit success
  assets/css/form.css               small "credited" glow/scale keyframe
                                    (scoped to .dw12-amount--bump)

INSTALL
-------
Replace the delicat-recharge plugin folder with this one (no DB schema
change). Then CLEAR CACHE:
  • LiteSpeed Toolbox -> Purge All
  • Cloudflare -> Purge Everything
The version bump to 5.4.0 changes the ?ver= on form.css / form.js so
browsers fetch the new files.

QUICK TEST
----------
1) Open the wallet page (balance card + recharge form both visible).
2) Submit a MonCash / Natcash-TC recharge whose SMS has already been
   forwarded -> the balance should count up immediately with a glow.
3) Submit one BEFORE paying, then pay -> within a few seconds of the SMS
   arriving, the balance animates up on its own (no refresh).

VALIDATION (this round)
-----------------------
  • PHP: all 24 files' code regions brace/paren/bracket-balanced
    (HTML/CSS <style> regions and heredoc handled by a region-aware
    scanner — the classic "# selector / // URL" masker desync was ruled
    out as false positives).
  • JS: `node --check` clean.
  • CSS: braces balanced; appended hex colours valid.
