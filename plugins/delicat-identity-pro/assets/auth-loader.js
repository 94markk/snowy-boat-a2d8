/* Delicat Identity V10 — sign-in window loader for guest catalogue pages.
   Printed inline in <head> (minified twin auth-loader.min.js) by
   DIP_Native_Auth::print_lazy_loader(), which then calls
   DIPAuthLoader({u: account page URL, b: base URL, f: [script paths], s: trigger selector}).
   The window's scripts (account-theme, auth-shell) are no longer part of
   the page load: they are fetched when the browser is idle
   after load, at once on an auth-state address, or on the first touch,
   hover or focus of a sign-in button. A tap that comes before them opens the
   window as soon as they run; on failure (or after 6 s) the tap opens the
   account page, as the pre-V10 fallback did. ES5 only. */
window.DIPAuthLoader = function (c) {
  'use strict';
  var w = window, d = document;
  if (w.__DIP_AUTH_LOADER__) return;
  w.__DIP_AUTH_LOADER__ = 1;
  // 0 idle, 1 waiting for the DOM (the configs are printed in the footer), 2 loading, 3 ready, 4 failed.
  var state = 0, queue = [], pending = null, timer = 0;
  var hash = /^#(delicat-login|dip-identity-modal)$/;
  var intents = ['pointerdown', 'touchstart', 'mouseover', 'focusin'];
  w.DIPAuthAccountUrl = c.u;

  function trigger(t) {
    if (t && t.nodeType === 3) t = t.parentElement;
    return t && t.closest ? t.closest(c.s) : null;
  }
  function accountPage() {
    var r = c.u;
    try { var x = new URL(c.u, location.href); x.searchParams.set('redirect_to', location.href); r = x.href; } catch (e) {}
    location.href = r;
  }
  function finish() {
    if (state > 2) return;
    state = w.DIPAuthShell ? 3 : 4;
    var callbacks = queue; queue = [];
    for (var i = 0; i < callbacks.length; i++) { try { callbacks[i](state === 3); } catch (e) {} }
  }
  function inject() {
    state = 2;
    var left = c.f.length;
    if (!left) { finish(); return; }
    c.f.forEach(function (path) {
      var s = d.createElement('script');
      s.src = c.b + path;
      s.async = false; // run in order: theme, then shell
      s.onload = s.onerror = function () { if (--left === 0) finish(); };
      d.head.appendChild(s);
    });
  }
  function load(callback) {
    if (callback) {
      if (state > 2) { callback(state === 3); return; }
      queue.push(callback);
    }
    if (state) return;
    if (d.readyState === 'loading') { state = 1; d.addEventListener('DOMContentLoaded', inject, {once: true}); return; }
    inject();
  }
  w.DIPAuthLoad = load;

  // A tap before the shell runs: keep it, open the window when it does.
  d.addEventListener('click', function (e) {
    if (w.DIPAuthShell) return;
    var t = trigger(e.target);
    if (!t) return;
    e.preventDefault(); e.stopImmediatePropagation();
    if (state === 4) { accountPage(); return; }
    pending = {t: t, tab: t.hasAttribute('data-dip-auth-register') || t.hasAttribute('data-dl-open-register') ? 'register' : 'login'};
    clearTimeout(timer);
    timer = setTimeout(function () { if (pending) { pending = null; accountPage(); } }, 6000);
    load(function (ok) {
      var p = pending, api = w.DIPIdentityModalAPI;
      pending = null; clearTimeout(timer);
      if (!p) return;
      try { if (ok && api && api.open(p.tab, p.t)) return; } catch (err) {}
      accountPage();
    });
  }, true);

  function intent(e) {
    if (!trigger(e.target)) return;
    intents.forEach(function (n) { d.removeEventListener(n, intent, true); });
    load();
  }
  intents.forEach(function (n) { d.addEventListener(n, intent, {capture: true, passive: true}); });

  ['dip:identity-open', 'dip:identity-open-request'].forEach(function (n) {
    d.addEventListener(n, function (e) {
      if (w.DIPAuthShell) return;
      var detail = e.detail || {};
      load(function (ok) { var api = w.DIPIdentityModalAPI; if (ok && api && !api.isOpen()) api.open(detail.tab, detail.trigger); });
    });
  });
  // The shell opens itself for these addresses when it starts.
  w.addEventListener('hashchange', function () { if (!w.DIPAuthShell && hash.test(location.hash)) load(); });
  var q = null;
  try { q = new URLSearchParams(location.search); } catch (e) {}
  if (hash.test(location.hash) || (q && (q.has('dip_auth_error') || q.get('dip_verified') === '1' || q.get('dip_verified') === '2' || q.get('dip_identity_open') === '1'))) load();

  // Otherwise once the page has loaded and the browser is idle (not with Save-Data).
  function idle() {
    var n = navigator.connection;
    if (n && n.saveData) return;
    if (w.requestIdleCallback) w.requestIdleCallback(function () { load(); }, {timeout: 4000});
    else setTimeout(function () { load(); }, 1500);
  }
  if (d.readyState === 'complete') idle(); else w.addEventListener('load', idle, {once: true});
};
