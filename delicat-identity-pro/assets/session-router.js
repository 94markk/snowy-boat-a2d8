(function () {
  'use strict';
  if (window.__DIP_SESSION_ROUTER__) return;
  window.__DIP_SESSION_ROUTER__ = true;
  var cfg = window.DIPSession || {}, busy = false;
  function clearDocuments() {
    try { if (navigator.serviceWorker && navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage({type:'dbv9-clear-docs'}); } catch (e) {}
  }
  function post(action, nonce) {
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer, data = new FormData(); data.set('action', action);
    if (nonce) data.set('nonce', nonce);
    var network = fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {method:'POST', body:data, credentials:'same-origin', cache:'no-store', signal:controller ? controller.signal : undefined})
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
      .then(function () { clearDocuments(); try { localStorage.setItem('dip-session-change', String(Date.now())); } catch (e) {} location.assign(new URL('?dip_auth_sync=' + Date.now().toString(36), cfg.home || location.origin).href); })
      .catch(function (error) { window.alert(error.message || 'Déconnexion impossible. Réessayez.'); })
      .finally(function () { busy = false; link.removeAttribute('aria-busy'); });
  }, true);
  window.addEventListener('pageshow', function (event) { if (event.persisted) { clearDocuments(); location.reload(); } });
  window.addEventListener('storage', function (event) { if (event.key === 'dip-session-change') { clearDocuments(); location.reload(); } });
  if (new URLSearchParams(location.search).has('dip_auth_sync')) clearDocuments();
})();
