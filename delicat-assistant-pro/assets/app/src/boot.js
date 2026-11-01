/* Delicat Assistant 7 — boot script, printed inline on every page after window.DSA7_CONFIG.
 * Does nothing until the visitor shows intent: the chat app (one file, ~25 KB gzipped) is fetched on
 * the first tap, hover or focus of the launcher. Idempotent, because the Builder's instant page
 * switching re-runs footer scripts. */
(function (w, d) {
  'use strict';
  if (w.DelicatAssistant) return;
  var C = w.DSA7_CONFIG || {};
  var root = d.getElementById('dsa7-root');
  var btn = d.getElementById('dsa7-launcher');
  if (!root || !btn) return;
  var loading = 0;
  var waiters = [];

  function store(key, value) {
    try {
      if (value === undefined) return JSON.parse(sessionStorage.getItem(key) || 'null');
      sessionStorage.setItem(key, JSON.stringify(value));
    } catch (e) { /* private mode */ }
    return null;
  }
  function flush() {
    var queue = waiters;
    waiters = [];
    btn.removeAttribute('aria-busy');
    for (var i = 0; i < queue.length; i++) queue[i]();
  }
  function load(cb) {
    if (cb) waiters.push(cb);
    if (w.DelicatAssistantApp) return flush();
    if (loading) return;
    loading = 1;
    var s = d.createElement('script');
    s.src = C.js;
    s.async = true;
    s.onload = flush;
    s.onerror = function () { loading = 0; waiters = []; btn.removeAttribute('aria-busy'); };
    d.head.appendChild(s);
  }
  function badge(n) {
    var b = btn.querySelector('.dsa7-badge');
    n = +n || 0;
    b.hidden = n < 1;
    b.textContent = n > 9 ? '9+' : String(n);
  }
  var nudge = root.querySelector('.dsa7-nudge');
  function hideNudge(forever) {
    nudge.hidden = true;
    if (forever) store('dsa7n', { c: 99 });
  }
  function open(options) {
    hideNudge(1);
    if (!w.DelicatAssistantApp) btn.setAttribute('aria-busy', 'true');
    load(function () { w.DelicatAssistantApp.open(options || {}); });
  }

  w.DelicatAssistant = {
    v: C.v,
    open: open,
    badge: badge,
    close: function () { if (w.DelicatAssistantApp) w.DelicatAssistantApp.close(); }
  };

  btn.addEventListener('click', function () {
    if (w.DelicatAssistantApp && w.DelicatAssistantApp.isOpen()) w.DelicatAssistantApp.close();
    else open();
  });
  ['pointerenter', 'touchstart', 'focus'].forEach(function (type) {
    btn.addEventListener(type, function () { load(); }, { once: true, passive: true });
  });
  // Any link or button on the site can open the chat: data-delicat-assistant="team|help|community".
  d.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-delicat-assistant],a[href$="#delicat-assistant"]') : null;
    if (!t || root.contains(t)) return;
    e.preventDefault();
    open({ view: t.getAttribute('data-delicat-assistant') || '' });
  });

  // Guides shared on WhatsApp (?dap_guide=id) and #delicat-assistant open the chat directly.
  var guide = /[?&]dap_guide=([a-z0-9_]+)/.exec(location.search);
  if (guide || location.hash === '#delicat-assistant') open({ guide: guide ? guide[1] : '' });

  // [delicat_assistant] / [delicat_groups] embeds mount without a tap.
  var inline = d.querySelector('[data-dsa7-inline]');
  if (inline) load(function () { w.DelicatAssistantApp.mount(inline); });

  // Unread badge: only for visitors who have a conversation, at most once a minute (shared by every
  // page of the visit), never while the chat is loaded (it keeps its own count).
  function hasChat() {
    return (d.body && d.body.classList.contains('logged-in')) || /(?:^|;\s*)dap_has_chat=/.test(d.cookie);
  }
  function refresh() {
    if (d.hidden || w.DelicatAssistantApp || !w.fetch || !hasChat()) return;
    var cached = store('dsa7b');
    if (cached && Date.now() - cached.t < 60000) return badge(cached.n);
    w.fetch(C.ajax, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=dsa7_ping'
    }).then(function (r) { return r.json(); }).then(function (j) {
      var n = j && j.success ? +j.data.unread || 0 : 0;
      store('dsa7b', { t: Date.now(), n: n });
      badge(n);
    }).catch(function () {});
  }
  // An idle callback can wait forever on a busy page: never more than 3 s.
  if (w.requestIdleCallback) w.requestIdleCallback(refresh, { timeout: 3000 });
  else setTimeout(refresh, 1500);
  setInterval(refresh, 60000);

  // Nudge bubble: after the configured delay, a few times per visit at most.
  var N = C.nudge;
  if (N && N.max > 0 && !guide) {
    var seen = store('dsa7n') || { c: 0 };
    if (seen.c < N.max) {
      setTimeout(function () {
        if (w.DelicatAssistantApp && w.DelicatAssistantApp.isOpen()) return;
        nudge.querySelector('b').textContent = N.kicker;
        nudge.querySelector('span').textContent = N.text;
        nudge.hidden = false;
        seen.c++;
        store('dsa7n', seen);
        setTimeout(function () { hideNudge(0); }, N.visible * 1000);
      }, N.delay * 1000);
    }
    nudge.querySelector('.dsa7-nudge__open').addEventListener('click', function () { open(); });
    nudge.querySelector('.dsa7-nudge__x').addEventListener('click', function () { hideNudge(1); });
  }

  // Optional automatic opening, once per visit.
  if (C.auto && !store('dsa7a')) {
    store('dsa7a', 1);
    setTimeout(function () { open(); }, 1200);
  }
})(window, document);
