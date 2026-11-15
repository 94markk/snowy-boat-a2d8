(function () {
  'use strict';
  if (window.__DIP_SESSION_ROUTER__) return;
  window.__DIP_SESSION_ROUTER__ = true;
  var cfg = window.DIPSession || {}, busy = false;
  function clearDocuments() {
    try { if (navigator.serviceWorker && navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage({type:'dbv9-clear-docs'}); } catch (e) {}
  }
  /**
   * Sign-in/out version as this tab sees it: the cross-tab marker written by
   * the modal, passkeys and logout (and by a dip_auth_sync landing that they
   * did not announce: Google, wp-login.php, WooCommerce forms), plus the last
   * dip_auth_sync landing of this tab. null: no storage.
   */
  function sessionVersion() {
    try { return String(localStorage.getItem('dip-session-change')) + '|' + String(sessionStorage.getItem('dip-auth-sync-seen')); } catch (e) { return null; }
  }
  function post(action, nonce) {
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer, data = new FormData(); data.set('action', action);
    if (nonce) data.set('nonce', nonce);
    var network = Promise.resolve().then(function () { return fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {method:'POST', body:data, credentials:'same-origin', cache:'no-store', signal:controller ? controller.signal : undefined}); })
      .then(function (r) { return r.json(); }).then(function (r) { if (!r.success) throw new Error((r.data && r.data.message) || 'Déconnexion impossible.'); return r.data; });
    var timeout = new Promise(function (_, reject) { timer = setTimeout(function () { if (controller) controller.abort(); reject(new Error('Le serveur met trop de temps à répondre. Réessayez.')); }, 15000); });
    return Promise.race([network, timeout]).finally(function () { clearTimeout(timer); });
  }
  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a[href]');
    if (!link) return;
    var url; try { url = new URL(link.href, location.href); } catch (e) { return; }
    if (url.origin !== location.origin) return;
    var endpoint = String(cfg.logoutEndpoint || 'customer-logout');
    var segments = url.pathname.split('/').filter(Boolean);
    var logout = (/\/wp-login\.php$/.test(url.pathname) && url.searchParams.get('action') === 'logout') || segments.indexOf(endpoint) !== -1;
    if (!logout) return;
    event.preventDefault(); event.stopImmediatePropagation();
    if (busy) return;
    busy = true; link.setAttribute('aria-busy','true');
    post('dip_session_status').then(function (state) { return state.loggedIn ? post('dip_session_logout', state.logoutNonce) : state; })
      .then(function () {
        clearDocuments();
        // Shared devices: the next person must not get this customer's checkout fields (checkout-restore.js).
        try { sessionStorage.removeItem('dip_wc_checkout_fields_v2'); } catch (e) {}
        try { localStorage.setItem('dip-session-change', String(Date.now())); } catch (e) {}
        window.__DIP_AUTH_NAVIGATING__ = true;
        location.assign(new URL('?dip_auth_sync=' + Date.now().toString(36), cfg.home || location.origin).href);
      })
      .catch(function (error) { window.alert(error.message || 'Déconnexion impossible. Réessayez.'); })
      .finally(function () { busy = false; link.removeAttribute('aria-busy'); });
  }, true);
  // The page this tab just left already told the other tabs (it wrote
  // dip-session-change before leaving: modal, passkeys, logout). Kept for the
  // next page only.
  var announced = null;
  try { announced = sessionStorage.getItem('dip-session-announced'); sessionStorage.removeItem('dip-session-announced'); } catch (e) {}
  window.addEventListener('pagehide', function () {
    if (!window.__DIP_AUTH_NAVIGATING__) return;
    try { sessionStorage.setItem('dip-session-announced', String(localStorage.getItem('dip-session-change'))); } catch (e) {}
  });
  var marker = new URLSearchParams(location.search).get('dip_auth_sync');
  if (marker !== null) {
    clearDocuments();
    // A sign-in that reached this landing through a server redirect (Google,
    // wp-login.php, WooCommerce forms) is announced here, as the modal does:
    // other tabs reload (listener below) and the Builder engine re-checks its
    // guest copy. Only on this tab's first landing with this marker, so a
    // reload, or a landing tab reloaded by another tab, writes nothing (no
    // reload ping-pong); not when the previous page already announced it, and
    // not on a back/forward visit to an older landing (no new sign-in).
    var announce = false, traversal = false;
    try { announce = sessionStorage.getItem('dip-auth-sync-seen') !== marker; sessionStorage.setItem('dip-auth-sync-seen', marker); } catch (e) { announce = false; }
    try {
      var nav = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
      traversal = nav ? nav.type === 'back_forward' : !!(performance.navigation && performance.navigation.type === 2);
    } catch (e) {}
    if (announce && !traversal) {
      try { if (announced !== String(localStorage.getItem('dip-session-change'))) localStorage.setItem('dip-session-change', String(Date.now())); } catch (e) {}
    }
  }
  var loadedVersion = sessionVersion();
  // Back/forward cache: an anonymous page is reloaded only when it was
  // rendered before a sign-in or sign-out (until 6.11.0 every restored page
  // was reloaded, so the back button never used the cache). Signed-in pages
  // (balance, cart) and browsers without storage keep reloading every time.
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) return;
    var personal = document.body && document.body.classList.contains('logged-in');
    if (!personal && loadedVersion !== null && sessionVersion() === loadedVersion) return;
    clearDocuments(); location.reload();
  });
  // Another tab signed in or out. Not while this page is itself leaving for
  // its sign-in destination (a reload would cancel that navigation).
  window.addEventListener('storage', function (event) {
    if (event.key !== 'dip-session-change' || window.__DIP_AUTH_NAVIGATING__) return;
    clearDocuments(); location.reload();
  });
})();
