/* =============================================================================
   nav.js — everything V10 needs JavaScript for during a navigation.
   -----------------------------------------------------------------------------
   Eighty lines, against V9's 1,900-line navigation engine, because the browser
   is doing the navigating. This file only tells it which way the customer is
   going, so the content slides the right way, and marks the tab that is about
   to become current so the bar responds to the tap instantly rather than when
   the next page paints.

   Every behaviour here is an enhancement. With this file blocked, missing or
   erroring, pages still navigate, transitions still play (without direction),
   and the store is entirely usable.
   ============================================================================= */

(function () {
  'use strict';

  var root = document.documentElement;

  /* ---------------------------------------------------------------------
     Direction
     ------------------------------------------------------------------ */

  /**
   * Which way is this navigation going?
   *
   * The Navigation API answers precisely for a history traversal — comparing
   * the index we are leaving with the one we are arriving at — and for
   * everything else a push is forward. Browsers without it fall through to
   * "forward", which is the common case and the safe default.
   */
  function direction(activation) {
    if (!activation) return 'forward';

    var type = activation.navigationType;
    if (type === 'reload' || type === 'replace') return 'same';

    if (type === 'traverse') {
      var from = activation.from;
      var to = activation.entry;
      if (from && to && typeof from.index === 'number' && typeof to.index === 'number') {
        return to.index < from.index ? 'back' : 'forward';
      }
      return 'back';
    }

    return 'forward';
  }

  function mark(value) {
    root.setAttribute('data-dlx-nav', value);
  }

  /*
   * `pagereveal` fires on the incoming document before its first frame, which
   * is the only moment at which the direction can still affect the transition.
   * It is also the event that exists on a bfcache restore, so a Back that never
   * ran any of our code still gets the right animation.
   */
  if ('onpagereveal' in window) {
    window.addEventListener('pagereveal', function (event) {
      mark(direction(window.navigation && window.navigation.activation));

      /* Nothing else to do: the browser owns the transition from here. We do
       * not await it, resolve it, or skip it. */
      void event;
    });
  }

  /*
   * `pageswap` fires on the outgoing document. Setting the attribute here too
   * means the *leaving* animation is directional as well, not just the arrival.
   */
  if ('onpageswap' in window) {
    window.addEventListener('pageswap', function (event) {
      var activation = event.activation || (window.navigation && window.navigation.activation);
      mark(direction(activation));
    });
  }

  /* ---------------------------------------------------------------------
     Immediate tab feedback
     ------------------------------------------------------------------ */

  /*
   * A native tab bar highlights the tapped tab on touch-down, not when the next
   * screen finishes rendering. Because the destination is usually prerendered
   * the gap is small, but "small" is still perceptible and this closes it.
   *
   * aria-current is moved rather than a class toggled, so assistive technology
   * and the visual state say the same thing.
   */
  var bar = document.querySelector('.dlx-tabbar');
  if (bar) {
    bar.addEventListener(
      'pointerdown',
      function (event) {
        var tab = event.target.closest ? event.target.closest('.dlx-tab') : null;
        if (!tab || tab.getAttribute('aria-current') === 'page') return;

        var current = bar.querySelector('.dlx-tab[aria-current="page"]');
        if (current) current.removeAttribute('aria-current');
        tab.setAttribute('aria-current', 'page');
      },
      { passive: true }
    );
  }

  /* ---------------------------------------------------------------------
     Theme
     ------------------------------------------------------------------ */

  /*
   * The stored choice is applied by a blocking inline script in <head>, before
   * the first paint, so there is never a flash of the wrong theme. This is only
   * the toggle. It writes the attribute the token layer reads and persists the
   * choice; there is no second source of truth and nothing to re-apply after a
   * navigation, because every navigation is a real page load that runs the
   * inline script again.
   *
   * V9 kept the theme in a class the router had to carry across each swap by
   * hand, and dropped it, so the store flipped to light on every page change.
   */
  document.addEventListener('click', function (event) {
    var toggle = event.target.closest ? event.target.closest('[data-dlx-theme-toggle]') : null;
    if (!toggle) return;

    event.preventDefault();

    var explicit = root.getAttribute('data-dlx-theme');
    var dark = explicit
      ? explicit === 'dark'
      : window.matchMedia('(prefers-color-scheme: dark)').matches;
    var next = dark ? 'light' : 'dark';

    root.setAttribute('data-dlx-theme', next);
    toggle.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');

    try {
      localStorage.setItem('dlx-theme', next);
    } catch (error) {
      /* Private mode, or storage disabled. The choice holds for this page. */
      void error;
    }
  });
})();
