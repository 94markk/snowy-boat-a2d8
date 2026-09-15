/**
 * Delicat Builder V9 Pro — Session state store.
 *
 * One request, one copy of the truth. Header, drawer, bottom bar, wallet card
 * and notification bell all read from here instead of each asking the server.
 *
 * ES5. Memory only — nothing is written to browser storage, so a shared or
 * borrowed phone never leaks the previous shopper's balance.
 */
(function () {
  'use strict';

  var cfg = window.DBPStateConfig || {};
  var doc = document;

  if (!window.fetch || !cfg.url) return;
  if (window.DBPState) return;

  var state = null;
  var pending = null;
  var listeners = [];
  var lastSignature = '';
  var lastFetch = 0;

  function emit(name, detail) {
    var event;
    try {
      event = new CustomEvent(name, { detail: detail, bubbles: true });
    } catch (e) {
      event = doc.createEvent('CustomEvent');
      event.initCustomEvent(name, true, false, detail);
    }
    doc.dispatchEvent(event);
  }

  function signature(next) {
    if (!next) return '';
    return [
      next.user ? next.user.id : 0,
      next.cart ? next.cart.count : 0,
      next.cart ? next.cart.total : '',
      next.wallet ? next.wallet.raw : 0,
      next.alerts ? next.alerts.count : 0
    ].join('|');
  }

  function fetchState(reason) {
    if (pending) return pending;
    if (reason === 'nav' && state && Date.now() - lastFetch < 30000) { apply(state); return Promise.resolve(state); }

    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = setTimeout(function () { if (controller) controller.abort(); }, 8000);
    pending = fetch(cfg.url, {
      credentials: 'same-origin',
      signal: controller ? controller.signal : undefined,
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('status ' + response.status);
      return response.json();
    }).then(function (next) {
      if (!next || !next.ok) throw new Error('bad-state');

      var sig = signature(next);
      var changed = sig !== lastSignature;
      lastSignature = sig;
      state = next;
      lastFetch = Date.now();

      apply(next);
      notify(next);
      emit('delicat:pro:state', { state: next, changed: changed, reason: reason || 'load' });

      return next;
    })['catch'](function () {
      /* The storefront renders correctly without the store; it just shows the
         server-rendered counts until the next attempt succeeds. */
      return null;
    }).then(function (result) {
      clearTimeout(timer);
      pending = null;
      return result;
    });

    return pending;
  }

  /* ------------------------------------------------------------------ paint */

  function text(node, value) {
    if (node && node.textContent !== value) node.textContent = value;
  }

  function apply(next) {
    paintCart(next);
    paintWallet(next);
    paintAlerts(next);
    paintIdentity(next);
    refreshNonces(next);
  }

  function paintCart(next) {
    var count = next.cart ? next.cart.count : 0;
    var nodes = doc.querySelectorAll('[data-dbp-cart-count]');
    for (var i = 0; i < nodes.length; i++) {
      text(nodes[i], String(count));
      nodes[i].setAttribute('data-empty', count > 0 ? '0' : '1');
      nodes[i].hidden = count < 1;
    }

    var totals = doc.querySelectorAll('[data-dbp-cart-total]');
    for (var j = 0; j < totals.length; j++) {
      text(totals[j], next.cart ? next.cart.total : '');
    }

    doc.documentElement.setAttribute('data-dbp-cart', count > 0 ? String(count) : '0');
  }

  function paintWallet(next) {
    if (!next.wallet || !next.wallet.enabled) return;
    var nodes = doc.querySelectorAll('[data-dbp-wallet]');
    for (var i = 0; i < nodes.length; i++) {
      text(nodes[i], next.wallet.balance);
    }
  }

  function paintAlerts(next) {
    var count = next.alerts ? next.alerts.count : 0;
    var nodes = doc.querySelectorAll('[data-dbp-alerts]');
    for (var i = 0; i < nodes.length; i++) {
      text(nodes[i], String(count));
      nodes[i].hidden = count < 1;
    }
  }

  function paintIdentity(next) {
    var loggedIn = !!(next.user && next.user.loggedIn);
    doc.documentElement.setAttribute('data-dbp-auth', loggedIn ? 'in' : 'out');

    var names = doc.querySelectorAll('[data-dbp-user-name]');
    for (var i = 0; i < names.length; i++) {
      text(names[i], next.user ? next.user.firstName : '');
    }
  }

  /**
   * Replace nonces that were baked into cached HTML.
   *
   * A guest page served from the edge carries the nonce minted when it was
   * cached. Past the tick that nonce is dead and every add-to-cart from that
   * page fails silently — which is exactly what a shopper experiences as "the
   * button does nothing".
   */
  function refreshNonces(next) {
    if (!next.nonces) return;

    var map = {
      'woocommerce-add-to-cart': next.nonces.addToCart,
      'woocommerce-cart': next.nonces.wc,
      'wp_rest': next.nonces.rest
    };

    var fields = doc.querySelectorAll('input[type="hidden"][data-dbp-nonce]');
    for (var i = 0; i < fields.length; i++) {
      var action = fields[i].getAttribute('data-dbp-nonce');
      if (map[action]) fields[i].value = map[action];
    }

    if (window.wpApiSettings && next.nonces.rest) window.wpApiSettings.nonce = next.nonces.rest;
    if (window.wc_add_to_cart_params && next.nonces.addToCart) {
      window.wc_add_to_cart_params.wc_ajax_nonce = next.nonces.addToCart;
    }
  }

  function notify(next) {
    for (var i = 0; i < listeners.length; i++) {
      try { listeners[i](next); } catch (e) { /* one bad subscriber must not stop the rest */ }
    }
  }

  /* ---------------------------------------------------------------- schedule */

  function schedule(fn) {
    if (window.requestIdleCallback) window.requestIdleCallback(fn, { timeout: 1500 });
    else setTimeout(fn, 200);
  }

  /* The first paint belongs to the page, not to the session probe. */
  if (cfg.initial) {
    if (doc.readyState === 'complete') schedule(function () { fetchState('load'); });
    else window.addEventListener('load', function () { schedule(function () { fetchState('load'); }); }, { once: true });
  }

  /* Refresh on the events that can actually change the session, rather than
     on a timer. A poll costs a Haitian shopper data for nothing. */
  doc.addEventListener('delicat:pro:navigated', function () { if (state || cfg.initial) fetchState('nav'); });
  doc.addEventListener('added_to_cart', function () { fetchState('cart'); });
  doc.addEventListener('removed_from_cart', function () { fetchState('cart'); });

  /* WooCommerce's cart events travel through jQuery and never reach a native
     listener, which is why V9's badges could sit on a stale count. */
  if (window.jQuery) {
    window.jQuery(document.body).on(
      'added_to_cart removed_from_cart updated_cart_totals wc_fragments_refreshed',
      function () { fetchState('cart'); }
    );
  }
  doc.addEventListener('delicat:pro:refresh', function () { fetchState('manual'); });

  /* A page restored from the back/forward cache is a snapshot of a moment that
     may be minutes old — the cart, wallet balance and identity on it are stale,
     and the customer may even have signed out in another tab. Repaint before
     they can read it. session.js does the same for the V9 transport. */
  window.addEventListener('pageshow', function (event) {
    if (event && event.persisted) { fetchState('bfcache'); }
  });

  doc.addEventListener('visibilitychange', function () {
    if (doc.visibilityState !== 'visible' || (!cfg.initial && !state)) return;
    if (!state || Date.now() - (state.time * 1000) < 30000) return;
    fetchState('focus');
  });

  window.DBPState = {
    get: function () { return state; },
    refresh: function (reason) { return fetchState(reason || 'manual'); },
    subscribe: function (fn) {
      if (typeof fn !== 'function') return function () {};
      listeners.push(fn);
      if (state) { try { fn(state); } catch (e) {} }
      return function () {
        var at = listeners.indexOf(fn);
        if (at !== -1) listeners.splice(at, 1);
      };
    }
  };
})();
