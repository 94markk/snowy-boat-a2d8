/* Delicat Session store (RC71.3). ES5, memory only, no jQuery required.
   Fast-start rules:
   - an obviously empty signed-out visit gets an immediate local guest snapshot;
   - signed-in/cart sessions synchronize from WooCommerce during idle;
   - cart/wallet changes are coalesced into one request;
   - the expensive Woo fragment event is not mirrored back into Session.
   This keeps launch/UI responsive while preserving WooCommerce as authority. */
(function () {
  'use strict';
  if (window.DelicatSession || !window.fetch) return;
  var cfg = window.DelicatSessionConfig || {};
  // Woo's wc-ajax routing works on the current WordPress route, including subdirectories.
  // A delayed localized config must never permanently disable the session store.
  if (!cfg.url) cfg.url = window.location.pathname + '?wc-ajax=delicat_session';
  var doc = document, html = doc.documentElement;
  var store = { data: null, ts: 0, pending: null, listeners: [] };
  var queuedRefresh = null;
  var MIN_GAP = 2500, STALE = 20000, eventTimer = 0, logoutBusy = false;
  var currentUrl = null, authReset = false, authRecover = false, authSync = false;
  function hasFlag(name) {
    var pattern = new RegExp('(?:[?&])' + name + '=1(?:&|$)');
    return pattern.test(window.location.search || '');
  }
  try {
    currentUrl = new URL(window.location.href);
    authReset = currentUrl.searchParams.get('delicat_auth_reset') === '1';
    authRecover = currentUrl.searchParams.get('delicat_auth_recover') === '1';
    authSync = currentUrl.searchParams.get('dip_auth_sync') === '1';
  } catch (e) {
    /* URL/searchParams is absent in a few old Android WebViews. Authentication
       recovery must still work there, so use a narrow read-only fallback. */
    authReset = hasFlag('delicat_auth_reset');
    authRecover = hasFlag('delicat_auth_recover');
    authSync = hasFlag('dip_auth_sync');
  }

  function clearDocumentCache() {
    try {
      if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'dbv9-clear-docs' });
      }
    } catch (e) {}
  }
  function stripAuthFlags() {
    if (!window.history || !window.history.replaceState) return;
    try {
      if (currentUrl) {
        currentUrl.searchParams.delete('delicat_auth_reset');
        currentUrl.searchParams.delete('delicat_auth_recover');
        currentUrl.searchParams.delete('dip_auth_sync');
        window.history.replaceState(window.history.state, '', currentUrl.pathname + (currentUrl.search || '') + (currentUrl.hash || ''));
        return;
      }
      var clean = (window.location.pathname || '/') + (window.location.search || '') + (window.location.hash || '');
      clean = clean.replace(/([?&])(delicat_auth_reset|delicat_auth_recover|dip_auth_sync)=1(&?)/g, function (all, prefix, key, suffix) {
        return prefix === '?' && suffix ? '?' : (prefix === '&' && suffix ? '&' : '');
      }).replace(/\?&/, '?').replace(/[?&]$/, '');
      window.history.replaceState(window.history.state, '', clean);
    } catch (e) {}
  }
  if (authReset || authRecover || authSync) { clearDocumentCache(); stripAuthFlags(); }

  function text(sel, value) { var els = doc.querySelectorAll(sel); for (var i = 0; i < els.length; i++) els[i].textContent = value == null ? '' : String(value); }
  function count(sel, n) {
    var els = doc.querySelectorAll(sel);
    for (var i = 0; i < els.length; i++) { els[i].textContent = String(n); els[i].hidden = !(n > 0); }
  }
  function renderedLoggedIn() { return !!(doc.body && doc.body.classList && doc.body.classList.contains('logged-in')); }
  function renderedCartCount() {
    var node = doc.querySelector('[data-dsb8-cart-count], [data-dbn-cart-count]');
    return node ? (parseInt(node.textContent, 10) || 0) : 0;
  }
  function hasWooSessionCookie() {
    var cookie = doc.cookie || '';
    return /(?:^|;\s*)(?:woocommerce_items_in_cart|wc_cart_hash|wp_woocommerce_session_[^=]*)=([^;]+)/i.test(cookie);
  }
  function minimalGuest() {
    return {
      loggedIn: false,
      name: '', initial: '', email: '', code: '',
      wallet: { text: '', raw: null },
      cart: { count: renderedCartCount() },
      urls: {},
      ts: Math.floor(Date.now() / 1000)
    };
  }
  function needsLiveSync() { return renderedLoggedIn() || renderedCartCount() > 0 || hasWooSessionCookie() || !!(store.data && store.data.loggedIn) || !!(store.data && store.data.cart && store.data.cart.count > 0); }
  /* A truly fresh empty guest document is already authoritative and should not
     generate a delayed Woo AJAX request just to confirm that zero is still zero.
     Re-check only when the document may have come from a persistent document
     cache / history restoration, where a pre-login guest shell can be stale. */
  function cachedDocumentLikely() {
    try {
      if (navigator.serviceWorker && navigator.serviceWorker.controller) return true;
      var entries = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
      var nav = entries && entries[0] ? entries[0] : null;
      if (!nav) return false;
      if (nav.type === 'back_forward') return true;
      return Number(nav.transferSize || 0) === 0 && Number(nav.decodedBodySize || 0) > 0;
    } catch (e) { return false; }
  }

  function apply() {
    var d = store.data;
    if (!d) return;
    /* A page copy rendered for the other sign-in state (service-worker/browser
       cache after login/logout) is replaced once from the network. */
    var rendered = renderedLoggedIn();
    if (!!d.loggedIn !== rendered && !window.__dbv9Reloaded) {
      var entries = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
      if (!(entries[0] && entries[0].type === 'reload')) {
        window.__dbv9Reloaded = true;
        clearDocumentCache()
        window.setTimeout(function () { window.location.reload(); }, 60);
        return;
      }
    }
    html.classList.toggle('dlc-session-user', !!d.loggedIn);
    html.classList.toggle('dlc-session-guest', !d.loggedIn);
    if (d.loggedIn && d.urls && d.urls.logout) {
      var logoutLinks = doc.querySelectorAll('.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]');
      for (var x = 0; x < logoutLinks.length; x++) logoutLinks[x].setAttribute('href', d.urls.logout);
    }
    if (d.cart) {
      var current = doc.querySelector('[data-dsb8-cart-count]');
      var before = current ? (parseInt(current.textContent, 10) || 0) : null;
      var next = parseInt(d.cart.count, 10) || 0;
      count('[data-dsb8-cart-count]', next);
      count('[data-dbn-cart-count]', next);
      if (current && before !== next && window.jQuery) {
        /* WooCommerce owns mini-cart markup. Ask it once only when count moves;
           Session intentionally does not listen to wc_fragments_refreshed. */
        try { window.jQuery(doc.body).trigger('wc_fragment_refresh'); } catch (e) {}
      }
      if (next === 0) {
        var list = doc.querySelector('.dsb8-cart-list'), foot = doc.querySelector('.dsb8-cart-panel__foot');
        if (list && !list.querySelector('.dsb8-cart-empty')) list.innerHTML = '<div class="dsb8-cart-empty">Votre panier est vide.</div>';
        if (foot) foot.hidden = true;
      }
    }
    // Always repaint private fields, including their empty/guest state.
    var walletText = d.loggedIn && d.wallet && d.wallet.text ? d.wallet.text : '—';
    text('[data-dlx-wallet-balance]', walletText);
    text('[data-delicat-wallet-balance]', walletText);
    text('[data-dlx-name]', d.loggedIn ? d.name : '');
    text('[data-dlx-email]', d.loggedIn ? d.email : '');
    text('[data-dlx-initial]', d.loggedIn ? d.initial : '');
    var code = doc.querySelectorAll('[data-dlx-code]');
    for (var c = 0; c < code.length; c++) {
      var value = d.loggedIn && d.code ? d.code : '';
      code[c].textContent = value;
      var btn = code[c].closest ? code[c].closest('[data-dlx-copy]') : null;
      if (btn) {
        if (value) btn.setAttribute('data-dlx-copy', value);
        else btn.removeAttribute('data-dlx-copy');
      }
    }
    try { doc.dispatchEvent(new CustomEvent('delicat:session', { detail: d })); } catch (e) {}
    for (var l = 0; l < store.listeners.length; l++) { try { store.listeners[l](d); } catch (e) {} }
  }

  function refresh(reason, force) {
    if (store.pending) {
      // A mutation arriving during a read needs one read AFTER that request.
      if (force && !queuedRefresh) {
        queuedRefresh = store.pending.then(function () {
          queuedRefresh = null;
          return refresh(reason, true);
        });
      }
      return queuedRefresh || store.pending;
    }
    var now = Date.now();
    if (!force && store.data && now - store.ts < MIN_GAP) return Promise.resolve(store.data);
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timeout;
    var deadline = new Promise(function (resolve, reject) {
      timeout = window.setTimeout(function () { if (controller) controller.abort(); reject(new Error('session-timeout')); }, 8000);
    });
    var request = fetch(cfg.url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store', signal: controller ? controller.signal : undefined })
      .then(function (r) { return r.ok ? r.json() : null; });
    store.pending = Promise.race([request, deadline])
      .then(function (d) {
        window.clearTimeout(timeout); store.pending = null;
        if (d && typeof d === 'object' && 'loggedIn' in d) { store.data = d; store.ts = Date.now(); apply(); }
        return store.data;
      })
      .catch(function () { window.clearTimeout(timeout); store.pending = null; return store.data; });
    return store.pending;
  }

  function queueRefresh(reason, delay) {
    if (eventTimer) window.clearTimeout(eventTimer);
    eventTimer = window.setTimeout(function () { eventTimer = 0; refresh(reason || 'event', true); }, typeof delay === 'number' ? delay : 90);
  }

  var ready = new Promise(function (resolve) {
    var idle = window.requestIdleCallback || function (cb) { return window.setTimeout(cb, 180); };
    if (!authReset && !authRecover && !authSync && !renderedLoggedIn() && renderedCartCount() === 0 && !hasWooSessionCookie()) {
      /* Empty guest pages do not need a network round trip before header/drawer
         can settle. Publish the authoritative empty state immediately. A late
         verification catches the rare stale service-worker document after auth
         without being on the critical launch path. */
      store.data = minimalGuest(); store.ts = Date.now(); apply(); resolve(store.data);
      if (cachedDocumentLikely()) {
        var late = window.requestIdleCallback || function (cb) { return window.setTimeout(cb, 5000); };
        late(function () { refresh('guest-verify', true); }, { timeout: 6000 });
      }
      return;
    }
    idle(function () { refresh('load', true).then(resolve, resolve); }, { timeout: 1200 });
  });

  window.DelicatSession = {
    get: function () { return store.data; },
    ready: ready,
    refresh: function (reason) { return refresh(reason || 'manual', true); },
    on: function (fn) { if (typeof fn === 'function') store.listeners.push(fn); if (store.data) { try { fn(store.data); } catch (e) {} } }
  };

  /* Clear any old HTML shell before authentication transitions. The event is
     capture-phase so it runs even when Identity/menus own the final click. */
  doc.addEventListener('click', function (event) {
    var node = event.target && event.target.closest ? event.target.closest('[data-dip-auth-open],[data-dl-open],a[href*="#delicat-login"],.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]') : null;
    if (!node) return;
    clearDocumentCache();
    var isLogout = !!(node.matches && node.matches('.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]'));
    if (!isLogout || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    event.stopPropagation();
    if (logoutBusy) return;
    logoutBusy = true;
    node.setAttribute('aria-busy', 'true');
    var fallback = node.getAttribute('href') || cfg.resetUrl || '/';
    refresh('logout', true).then(function (data) {
      var target = data && data.loggedIn && data.urls && data.urls.logout
        ? data.urls.logout
        : (data && !data.loggedIn ? (cfg.resetUrl || fallback) : fallback);
      window.location.assign(target);
    }, function () {
      window.location.assign(fallback);
    });
  }, true);

  if (authRecover) {
    var openIdentity = function () {
      var trigger = doc.querySelector('[data-dip-auth-open],[data-dl-open],a[href="#delicat-login"]');
      if (trigger && typeof trigger.click === 'function') trigger.click();
    };
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', function () { window.setTimeout(openIdentity, 30); }, { once: true });
    else window.setTimeout(openIdentity, 30);
  }

  doc.addEventListener('visibilitychange', function () { if (!doc.hidden && needsLiveSync() && Date.now() - store.ts > STALE) refresh('visible', true); });
  window.addEventListener('focus', function () { if (needsLiveSync() && Date.now() - store.ts > STALE) refresh('focus', true); });
  window.addEventListener('pageshow', function (e) { if (e.persisted) queueRefresh('bfcache', 40); });

  var events = ['delicat:cart-changed', 'delicat:wallet-changed', 'delicat:express-added', 'delicat:auth-changed', 'delicat:currency-changed'];
  for (var i = 0; i < events.length; i++) doc.addEventListener(events[i], function () { queueRefresh('event', 80); });
  doc.addEventListener('dlx:open', function () { if (needsLiveSync()) queueRefresh('drawer-open', 60); });

  function bindWoo() {
    if (!window.jQuery) return;
    try { window.jQuery(doc.body).on('added_to_cart removed_from_cart updated_wc_div', function () { queueRefresh('woo', 70); }); } catch (e) {}
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bindWoo, { once: true }); else bindWoo();
}());
