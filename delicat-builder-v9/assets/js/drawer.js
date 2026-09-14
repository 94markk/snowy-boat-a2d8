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
  var open = false, lastFocus = null, lockY = 0, closeTimer = null, enterTimer = null, drag = null, walletTimer = null, pointerOpenAt = 0, swipeEndedAt = 0;
  var inertNodes = [], savedTop = '', bound = false;
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])';
  var raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 16); };

  function closest(node, sel) { while (node && node.nodeType === 1) { if (node.matches ? node.matches(sel) : (node.msMatchesSelector && node.msMatchesSelector(sel))) return node; node = node.parentNode; } return null; }
  /* A collapsed section and a filtered-out item are still in the DOM. Tabbing
     into something invisible is a dead key press, so they are left out. */
  function focusables() {
    if (!panel) return [];
    return Array.prototype.filter.call(panel.querySelectorAll(FOCUSABLE), function (node) {
      return node.getClientRects().length && !closest(node, '[hidden]') && !closest(node, '.is-collapsed');
    });
  }
  /*
   * The page behind is made genuinely inert, not merely hidden from screen
   * readers. aria-hidden tells assistive tech to ignore a region; it does not
   * stop a tap or a Tab key reaching it. The `inert` property does both.
   *
   * The previous state of each node is kept and put back, rather than assumed
   * to have been "not inert, no aria-hidden" - a page can legitimately have
   * either already set, and closing the menu must not clear someone else's.
   */
  function setInert(on) {
    if (on) {
      if (inertNodes.length) return;
      Array.prototype.forEach.call(doc.body.children, function (node) {
        if (node === drawer || node.contains(drawer) || /^(SCRIPT|STYLE|LINK)$/.test(node.tagName)) return;
        inertNodes.push({ node: node, inert: node.inert, aria: node.getAttribute('aria-hidden') });
        node.inert = true;
        node.setAttribute('aria-hidden', 'true');
      });
      return;
    }
    for (var i = 0; i < inertNodes.length; i++) {
      var saved = inertNodes[i];
      saved.node.inert = saved.inert;
      if (null === saved.aria) saved.node.removeAttribute('aria-hidden');
      else saved.node.setAttribute('aria-hidden', saved.aria);
    }
    inertNodes = [];
  }

  /* The three bars say whether the menu they control is open. */
  function expanded(value) {
    var openers = doc.querySelectorAll('[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle');
    for (var i = 0; i < openers.length; i++) {
      openers[i].setAttribute('aria-expanded', value ? 'true' : 'false');
      openers[i].setAttribute('aria-controls', drawer.id || 'delicat-drawer');
    }
  }
  /* savedTop, because body.style.top may not have been empty to begin with -
     another overlay on the page may be holding the same lock. */
  function lock() { lockY = window.pageYOffset || 0; savedTop = doc.body.style.top; html.classList.add('dlx-open'); doc.body.style.top = (-lockY) + 'px'; }
  function unlock() { html.classList.remove('dlx-open'); doc.body.style.top = savedTop; window.scrollTo({ top: lockY, left: 0, behavior: 'instant' }); }

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
    expanded(true);
    lastFocus = doc.activeElement;
    if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
    if (panel) panel.removeEventListener('transitionend', onCloseEnd);
    drawer.hidden = false;
    unwarm();
    drawer.setAttribute('aria-hidden', 'false');

    /*
     * Not tappable until it is actually on screen.
     *
     * unwarm() above makes the drawer hit-testable immediately, but the panel
     * spends the next 180ms sliding in from off screen. Anything tapped in that
     * window hits menu markup the customer cannot see yet: a second quick tap
     * on the three bars landed on the brand link at the top of the panel and
     * navigated to the home page. is-entering keeps the whole overlay out of
     * hit-testing until the slide has finished.
     */
    drawer.classList.add('is-entering');

    /*
     * Paint the moving panel first. Inerting every body child used to happen
     * before the first visual response and made the three-bar tap feel stuck.
     *
     * The frame split below was measured, not assumed. Four other orderings
     * were tried - starting the slide in the tap's own task, deferring the
     * scroll lock by a frame, deferring it by a task, collapsing the two frames
     * into one - and all four landed inside the run-to-run spread at 4x and 6x
     * CPU throttling (medians 82-95ms, individual taps 66-138ms). There is no
     * frame to win here: the tap already costs about one frame of real work.
     * Leave it alone unless a measurement says otherwise.
     */
    lock();
    if (scroller) scroller.scrollTop = 0;
    raf(function () {
      if (!open) return;
      drawer.classList.add('is-open');
      armEntered();
      raf(function () {
        if (!open) return;
        setInert(true);
        try { (panel.querySelector('[data-dlx-close]') || panel).focus({ preventScroll: true }); } catch (e) { try { panel.focus(); } catch (e2) {} }
      });
    });
    doc.dispatchEvent(new CustomEvent('dlx:open'));
  }

  function hide() {
    if (!drawer || !open) return;
    open = false;
    expanded(false);
    /* A drag in progress is abandoned, not carried into the next open. */
    drag = null;
    entered();
    drawer.classList.remove('is-open');
    drawer.classList.remove('is-dragging');
    if (panel) panel.style.transform = '';
    setInert(false);
    unlock();

    /*
     * Parking the panel again waits for the slide-out to finish. The old fixed
     * 180ms matched the transition's own 180ms exactly, so a single late frame
     * made the panel disappear while it was still on screen. transitionend is
     * the honest signal; the timer behind it only covers the case where no
     * transition ran at all (reduced motion, or a browser that skipped it).
     */
    if (closeTimer) { window.clearTimeout(closeTimer); }
    closeTimer = window.setTimeout(park, 240);
    if (panel) panel.addEventListener('transitionend', onCloseEnd);

    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
    doc.dispatchEvent(new CustomEvent('dlx:close'));
  }

  /* The slide-in is over: hand hit-testing back. transitionend is the honest
     signal; the timer covers reduced motion and any browser that skips it. */
  function entered() {
    if (enterTimer) { window.clearTimeout(enterTimer); enterTimer = null; }
    if (panel) panel.removeEventListener('transitionend', onEnterEnd);
    if (drawer) drawer.classList.remove('is-entering');
    /* Once the panel has arrived, not while it is moving: a fetch kicked off on
       the opening frame competes with the one animation that matters. */
    if (open) refreshWallet();
  }

  function onEnterEnd(e) {
    if (e.target !== panel || e.propertyName !== 'transform') return;
    entered();
  }

  function armEntered() {
    if (enterTimer) window.clearTimeout(enterTimer);
    enterTimer = window.setTimeout(entered, 240);
    if (panel) panel.addEventListener('transitionend', onEnterEnd);
  }

  function park() {
    if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
    if (panel) panel.removeEventListener('transitionend', onCloseEnd);
    if (!open) warm();
  }

  function onCloseEnd(e) {
    /* Only the panel's own slide, not a colour fade on something inside it. */
    if (e.target !== panel || e.propertyName !== 'transform') return;
    park();
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

  /* ---------------------------------------------------------------------
     Swipe to close

     The panel captures the pointer explicitly. Chromium already delivers the
     whole stream here without it - touch pointers get implicit capture, and a
     swipe past the panel's edge was checked with real touch and real pen input
     and ended cleanly either way - so this is not a fix for anything customers
     hit. It is here because the drag writes an inline transform and turns the
     transition off, and a stream that ends somewhere else would leave the menu
     parked halfway across the screen; lostpointercapture covers the one case
     the browser really can take the gesture away, a system back swipe or a
     notification shade.
     ------------------------------------------------------------------ */
  function endDrag(commit) {
    if (!drag) return;
    var d = drag; drag = null;
    drawer.classList.remove('is-dragging');
    /* A swipe is not a tap. Whatever the finger started on - and on this panel
       that is almost always a menu link - its click has to be thrown away, or
       swiping the menu shut navigates somewhere on the way out. */
    if (d.on) swipeEndedAt = Date.now();
    if (commit && d.on && Math.abs(d.dx) > Math.min(110, panel.offsetWidth * 0.3)) { hide(); return; }
    panel.style.transform = '';
  }

  function onDown(e) {
    if (!open || e.pointerType === 'mouse' || e.button) return;
    /*
     * Only the fields are excluded, not every control.
     *
     * This used to bail on `a` and `button` as well, which is nearly the whole
     * panel: the account row, the quick tiles, every menu item. Swiping to
     * close therefore did nothing unless the finger happened to land in a gap
     * between cards. The drag does not arm until the finger has travelled 10px
     * horizontally and more sideways than up, so a tap on a link is still a tap
     * - and endDrag above discards the click of a gesture that did arm.
     *
     * Text fields keep their own horizontal gestures: dragging in one moves the
     * caret and selects, and that must not slide the menu away instead.
     */
    if (closest(e.target, 'input,textarea,select')) return;
    drag = { x: e.clientX, y: e.clientY, dx: 0, on: false, id: e.pointerId, left: drawer.classList.contains('is-left') };
    try { if (panel.setPointerCapture) panel.setPointerCapture(e.pointerId); } catch (err) {}
  }
  function onMove(e) {
    if (!drag || (drag.id !== undefined && e.pointerId !== drag.id)) return;
    var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (!drag.on) { if (Math.abs(dx) < 10 || Math.abs(dx) < Math.abs(dy)) return; drag.on = true; drawer.classList.add('is-dragging'); }
    var out = drag.left ? Math.min(0, dx) : Math.max(0, dx);
    drag.dx = out;
    panel.style.transform = 'translateX(' + out + 'px)';
    if (e.cancelable) e.preventDefault();
  }
  function onUp(e) {
    if (drag && e && e.pointerId !== undefined && drag.id !== undefined && e.pointerId !== drag.id) return;
    endDrag(true);
    /* After endDrag, which may have closed the menu: releasing a capture on a
       panel that is on its way out is harmless, the other order is not. */
    if (e && e.pointerId !== undefined) {
      try { if (panel.releasePointerCapture && panel.hasPointerCapture && panel.hasPointerCapture(e.pointerId)) panel.releasePointerCapture(e.pointerId); } catch (err) {}
    }
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
    if (!drawer || bound) return;
    bound = true;
    panel = drawer.querySelector('[data-dlx-panel]');
    scroller = drawer.querySelector('[data-dlx-scroll]');
    search = drawer.querySelector('[data-dlx-search]');
    empty = drawer.querySelector('[data-dlx-empty]');
    html.classList.add('dlx-ready');

    /* Open on pointerdown so touch devices respond before the delayed click. */
    doc.addEventListener('pointerdown', function (e) {
      var trigger = closest(e.target, '[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle');
      if (!trigger || open) return;
      /*
       * A timestamp rather than a flag. The click that follows this pointerdown
       * has to be swallowed, or the menu would open and shut in one tap - but a
       * flag only clears when that click actually arrives on the trigger, and a
       * tap that slides off the button never produces one. The flag then sat
       * true for the rest of the page's life and swallowed the NEXT genuine
       * activation: after one slid tap, opening the menu from the keyboard
       * stopped working entirely. A timestamp expires on its own.
       */
      pointerOpenAt = Date.now();
      e.preventDefault();
      show();
    }, true);

    /* openers: the header hamburger, any [data-dlx-open], or the header's overlay event */
    doc.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || t.nodeType !== 1) return;

      /*
       * THE CLICK THAT BELONGS TO THE TAP THAT JUST OPENED THE MENU.
       *
       * Opening happens on pointerdown, which makes the drawer hit-testable
       * straight away. The click for that same tap is dispatched at pointerup
       * and hit-tests afresh - and by then the finger is over the drawer, not
       * over the three bars. It landed on the backdrop, the backdrop carries
       * data-dlx-close, and the menu shut itself in the same tap that opened
       * it. Traced, not guessed: pointerdown@path, pointerup@path, click@DIV,
       * with dlx:open and dlx:close both inside one tap.
       *
       * The tap has already been acted on, so its click is spent, wherever it
       * landed. The window is generous because a slow phone can take a while to
       * turn pointerup into click, and it clears itself either way.
       */
      if (pointerOpenAt) {
        var age = Date.now() - pointerOpenAt;
        pointerOpenAt = 0;
        if (age < 700) {
          e.preventDefault();
          if (e.stopImmediatePropagation) e.stopImmediatePropagation();
          return;
        }
      }

      /* The click belonging to a swipe, not a tap. See endDrag. */
      if (swipeEndedAt) {
        var since = Date.now() - swipeEndedAt;
        swipeEndedAt = 0;
        if (since < 700) {
          e.preventDefault();
          if (e.stopImmediatePropagation) e.stopImmediatePropagation();
          return;
        }
      }

      if (closest(t, '[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle')) {
        e.preventDefault();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        open ? hide() : show();
        return;
      }
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
      /* The system can take the pointer away mid-swipe - a notification shade, a
         back gesture. Without this the drag would never end. */
      panel.addEventListener('lostpointercapture', function () { endDrag(false); }, { passive: true });
    }
    window.addEventListener('pageshow', function (e) { if (e.persisted && open) hide(); });
    if (window.requestIdleCallback) window.requestIdleCallback(warm, { timeout: 2500 });
    else window.setTimeout(warm, 900);
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bind, { once: true }); else bind();
}());
