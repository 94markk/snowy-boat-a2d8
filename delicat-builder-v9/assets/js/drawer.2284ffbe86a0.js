/* Delicat Drawer (RC29) — ES5, no jQuery, no storage.
   Opens from the header hamburger ([data-dsb8-menu-trigger] or the header's
   dsb8:overlay:open event), closes on backdrop, ×, Escape, swipe, or any link
   tap. Focus is trapped while open, the page is inert, scroll is locked
   without layout jump. Wallet balance refreshes through WooCommerce's own
   nonce-protected AJAX action. The search field filters the menu instantly and
   submits to the shop on Enter. */
(function () {
  'use strict';
  var doc = document, html = doc.documentElement;
  var drawer = null, panel = null, scroller = null, search = null, empty = null;
  var open = false, lastFocus = null, lockY = 0, closeTimer = null, drag = null, walletTimer = null, openedOnPointer = false;
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])';
  var raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 16); };

  function closest(node, sel) { while (node && node.nodeType === 1) { if (node.matches ? node.matches(sel) : (node.msMatchesSelector && node.msMatchesSelector(sel))) return node; node = node.parentNode; } return null; }
  function focusables() { return panel ? panel.querySelectorAll(FOCUSABLE) : []; }
  function setInert(on) {
    var kids = doc.body.children;
    for (var i = 0; i < kids.length; i++) {
      var k = kids[i];
      if (k === drawer || k.tagName === 'SCRIPT' || k.tagName === 'STYLE') continue;
      if (on) { if (!k.hasAttribute('data-dlx-inert')) { k.setAttribute('data-dlx-inert', k.getAttribute('aria-hidden') || ''); k.setAttribute('aria-hidden', 'true'); } }
      else if (k.hasAttribute('data-dlx-inert')) { var prev = k.getAttribute('data-dlx-inert'); if (prev) k.setAttribute('aria-hidden', prev); else k.removeAttribute('aria-hidden'); k.removeAttribute('data-dlx-inert'); }
    }
  }
  function lock() { lockY = window.pageYOffset || 0; html.classList.add('dlx-open'); doc.body.style.top = (-lockY) + 'px'; }
  function unlock() { html.classList.remove('dlx-open'); doc.body.style.top = ''; window.scrollTo(0, lockY); }

  /* Lay the panel out once while the page is idle, so the first three-bar tap
     only has to run a transform instead of a full layout + paint. The parked
     state is written as inline styles, never as a class: a cached or combined
     stylesheet without the .is-warm rule would otherwise leave a full-screen
     overlay sitting on top of the page and swallow every tap. */
  function warm() {
    if (!drawer || open) return;
    drawer.classList.add('is-warm');
    drawer.style.visibility = 'hidden';
    drawer.style.pointerEvents = 'none';
    drawer.hidden = false;
    drawer.setAttribute('aria-hidden', 'true');
  }
  function unwarm() {
    if (!drawer) return;
    drawer.classList.remove('is-warm');
    drawer.style.visibility = '';
    drawer.style.pointerEvents = '';
  }

  function show() {
    if (!drawer || open) return;
    open = true;
    lastFocus = doc.activeElement;
    if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
    drawer.hidden = false;
    unwarm();
    drawer.setAttribute('aria-hidden', 'false');

    /* Paint the moving panel first. Inerting every body child used to happen
       before the first visual response and made the three-bar tap feel stuck. */
    lock();
    if (scroller) scroller.scrollTop = 0;
    raf(function () {
      if (!open) return;
      drawer.classList.add('is-open');
      raf(function () {
        if (!open) return;
        setInert(true);
        try { (panel.querySelector('[data-dlx-close]') || panel).focus({ preventScroll: true }); } catch (e) { try { panel.focus(); } catch (e2) {} }
        refreshWallet();
      });
    });
    doc.dispatchEvent(new CustomEvent('dlx:open'));
  }

  function hide() {
    if (!drawer || !open) return;
    open = false;
    drawer.classList.remove('is-open');
    drawer.classList.remove('is-dragging');
    if (panel) panel.style.transform = '';
    setInert(false);
    unlock();
		closeTimer = window.setTimeout(function () { if (!open) warm(); closeTimer = null; }, 180);

    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
    doc.dispatchEvent(new CustomEvent('dlx:close'));
  }

  /* wallet: one refresh per open, through the existing nonce-protected action */
  function refreshWallet() {
    if (window.DelicatSession && window.DelicatSession.refresh) { window.DelicatSession.refresh('drawer-open'); return; } /* RC33: one store for every surface */
    var ajax = drawer.getAttribute('data-ajax'), nonce = drawer.getAttribute('data-wallet-nonce');
    var target = drawer.querySelector('[data-dlx-wallet-balance]');
    if (!ajax || !nonce || !target || !window.fetch || walletTimer) return;
    walletTimer = window.setTimeout(function () { walletTimer = null; }, 15000);
    var body = 'action=delicat_builder_v9_wallet_balance&nonce=' + encodeURIComponent(nonce);
    fetch(ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var d = data && data.data ? data.data : data;
        var text = d && (d.balance_html || d.balance || d.html);
        if (!data || data.success === false || !text) return;
        var inert = null; try { inert = new DOMParser().parseFromString('<!doctype html><body>' + String(text), 'text/html'); } catch (e) {}
        target.textContent = inert ? inert.body.textContent.trim() : String(text);
        target.classList.add('is-fresh'); window.setTimeout(function () { target.classList.remove('is-fresh'); }, 900);
      }).catch(function () {});
  }

  /* search: instant filter, Enter submits to the shop */
  function filter(value) {
    var q = String(value || '').trim().toLowerCase();
    var items = drawer.querySelectorAll('.dlx-item');
    var shown = 0;
    for (var i = 0; i < items.length; i++) {
      var hit = !q || (items[i].getAttribute('data-dlx-filter') || '').indexOf(q) !== -1;
      items[i].parentNode.hidden = !hit;
      if (hit) shown++;
    }
    var sections = drawer.querySelectorAll('[data-dlx-section]');
    for (var s = 0; s < sections.length; s++) {
      var visible = sections[s].querySelectorAll('li:not([hidden]) > .dlx-item').length;
      sections[s].hidden = !!q && !visible;
      if (q) sections[s].classList.remove('is-collapsed');
    }
    drawer.classList.toggle('is-filtering', !!q);
    if (empty) empty.hidden = !q || shown > 0;
  }

  /* swipe to close (horizontal drag on the panel) */
  function onDown(e) {
    if (!open || e.pointerType === 'mouse' || e.button) return;
    if (closest(e.target, 'input,textarea,select,button,a')) return;
    drag = { x: e.clientX, y: e.clientY, dx: 0, on: false, left: drawer.classList.contains('is-left') };
  }
  function onMove(e) {
    if (!drag) return;
    var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (!drag.on) { if (Math.abs(dx) < 10 || Math.abs(dx) < Math.abs(dy)) return; drag.on = true; drawer.classList.add('is-dragging'); }
    var out = drag.left ? Math.min(0, dx) : Math.max(0, dx);
    drag.dx = out;
    panel.style.transform = 'translateX(' + out + 'px)';
    if (e.cancelable) e.preventDefault();
  }
  function onUp() {
    if (!drag) return;
    var d = drag; drag = null;
    drawer.classList.remove('is-dragging');
    if (d.on && Math.abs(d.dx) > Math.min(110, panel.offsetWidth * 0.3)) { hide(); return; }
    panel.style.transform = '';
  }

  function copy(button) {
    var value = button.getAttribute('data-dlx-copy') || '';
    var label = button.querySelector('[data-dlx-copy-label]');
    var done = function () { if (label) { var was = label.textContent; label.textContent = 'Copié ✓'; button.classList.add('is-done'); window.setTimeout(function () { label.textContent = was; button.classList.remove('is-done'); }, 1400); } };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done, done);
    else { var ta = doc.createElement('textarea'); ta.value = value; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0'; doc.body.appendChild(ta); ta.select(); try { doc.execCommand('copy'); } catch (e) {} doc.body.removeChild(ta); done(); }
  }

  function bind() {
    drawer = doc.querySelector('[data-dlx-drawer]');
    if (!drawer) return;
    panel = drawer.querySelector('[data-dlx-panel]');
    scroller = drawer.querySelector('[data-dlx-scroll]');
    search = drawer.querySelector('[data-dlx-search]');
    empty = drawer.querySelector('[data-dlx-empty]');
    html.classList.add('dlx-ready');

    /* Open on pointerdown so touch devices respond before the delayed click. */
    doc.addEventListener('pointerdown', function (e) {
      var trigger = closest(e.target, '[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle');
      if (!trigger || open) return;
      openedOnPointer = true;
      e.preventDefault();
      show();
    }, true);

    /* openers: the header hamburger, any [data-dlx-open], or the header's overlay event */
    doc.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || t.nodeType !== 1) return;
      if (closest(t, '[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle')) { e.preventDefault(); if (e.stopImmediatePropagation) e.stopImmediatePropagation(); if (openedOnPointer) { openedOnPointer = false; return; } open ? hide() : show(); return; }
      if (!open) return;
      if (closest(t, '[data-dlx-close]')) { e.preventDefault(); hide(); return; }
      var toggle = closest(t, '[data-dlx-toggle]');
      if (toggle && drawer.contains(toggle)) { var section = closest(toggle, '[data-dlx-section]'); var collapsed = section.classList.toggle('is-collapsed'); toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true'); return; }
      var cp = closest(t, '[data-dlx-copy]');
      if (cp && drawer.contains(cp)) { e.preventDefault(); copy(cp); return; }
      var inst = closest(t, '[data-dlx-install]');
      if (inst && drawer.contains(inst)) { e.preventDefault(); hide(); doc.dispatchEvent(new CustomEvent('delicat:install-app')); return; }
      var link = closest(t, 'a[href]');
      if (link && drawer.contains(link) && !link.target) { var href = link.getAttribute('href') || ''; if (href.charAt(0) !== '#') hide(); }
    }, true);
    doc.addEventListener('dsb8:overlay:open', function (e) { if (e.detail && e.detail.id === 'modern-menu') { if (e.stopImmediatePropagation) e.stopImmediatePropagation(); show(); } }, true);
    doc.addEventListener('keydown', function (e) {
      if (!open) return;
      if (e.key === 'Escape' || e.keyCode === 27) { e.preventDefault(); hide(); return; }
      if (e.key === 'Tab' || e.keyCode === 9) {
        var f = focusables(); if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    if (search) {
      search.addEventListener('input', function () { filter(search.value); });
      search.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.keyCode === 13) && !search.value.trim()) e.preventDefault(); });
    }
    if (window.PointerEvent && panel) {
      panel.addEventListener('pointerdown', onDown, { passive: true });
      panel.addEventListener('pointermove', onMove, { passive: false });
      panel.addEventListener('pointerup', onUp, { passive: true });
      panel.addEventListener('pointercancel', onUp, { passive: true });
    }
    window.addEventListener('pageshow', function (e) { if (e.persisted && open) hide(); });
    window.addEventListener('resize', function () { if (open && window.innerWidth >= 1280) { /* keep open; panel width is capped by CSS */ } });
    if (window.requestIdleCallback) window.requestIdleCallback(warm, { timeout: 2500 });
    else window.setTimeout(warm, 900);
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bind, { once: true }); else bind();
}());
