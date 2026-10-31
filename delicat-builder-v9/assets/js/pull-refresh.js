/* Delicat Pull-to-Refresh (RC75, reworked RC78) — ES5, no deps.
   The storefront locks overscroll so panels and rails feel native, which also
   removes the browser's own pull gesture (and standalone PWAs never had one).
   This restores it: drag down at the very top of the page and the storefront
   reloads. Only touch pointers, never while a drawer/overlay owns the screen,
   never inside a scrollable rail, and never during a horizontal swipe.

   RC78 — two costs this file was charging every page:

   1. It kept a non-passive `touchmove` listener on `document` for the whole
      session. A non-passive touchmove on the document takes the entire page
      off the browser's compositor scrolling fast path: every scroll, on every
      storefront page, had to wait for this handler before it could move. The
      listener is now attached only for the length of a gesture that already
      qualifies (single touch, page at scroll top, nothing else owning the
      screen) and removed the moment the finger lifts, so ordinary scrolling
      never sees it.

   2. scrollableAncestor() called getComputedStyle() on every ancestor of the
      touched node on every touchstart, which forces a style recalculation in
      the tap path. The cheap geometry test runs first now and the walk is
      depth-capped, so getComputedStyle is reached only for a node that is
      actually overflowing. */
(function () {
	'use strict';

	var doc = document, html = doc.documentElement;
	if (!('ontouchstart' in window) && !(navigator.maxTouchPoints > 0)) return;

	var THRESHOLD = 72;   /* travel needed to arm the refresh */
	var MAX = 104;        /* indicator never travels further than this */
	var MOVE_OPTS = { passive: false };
	var indicator = null, ring = null;
	var start = null, active = false, armed = false, busy = false, listening = false;

	function build() {
		if (indicator) return indicator;
		indicator = doc.createElement('div');
		indicator.className = 'dlx-ptr';
		indicator.setAttribute('aria-hidden', 'true');
		/* Inline safety net: if the polish stylesheet is cached or combined away,
		   the indicator must still be invisible and un-tappable. */
		indicator.style.position = 'fixed';
		indicator.style.left = '50%';
		indicator.style.pointerEvents = 'none';
		indicator.style.opacity = '0';
		ring = doc.createElement('div');
		ring.className = 'dlx-ptr__ring';
		ring.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
		indicator.appendChild(ring);
		doc.body.appendChild(indicator);
		return indicator;
	}

	/* Cheap test first: scrollHeight/clientHeight are plain layout reads, while
	   getComputedStyle materialises a full computed style object. Most ancestors
	   are not overflowing, so the expensive call is now rarely reached. */
	function scrollableAncestor(node) {
		var depth = 0;
		while (node && node !== doc.body && node.nodeType === 1 && depth < 12) {
			depth++;
			if (node.scrollHeight > node.clientHeight + 1) {
				var oy = '';
				try { oy = window.getComputedStyle(node).overflowY; } catch (e) { oy = ''; }
				if (oy === 'auto' || oy === 'scroll') return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function blocked() {
		if (busy) return true;
		if (html.classList.contains('dlx-open')) return true;                  /* drawer */
		if (doc.body.classList.contains('dsb8-overlay-open')) return true;     /* header overlays */
		if (doc.querySelector('.dlx-drawer.is-open,[data-dsb8-overlay].is-open,.dsb8-cart-panel.is-open')) return true;
		return false;
	}

	function listen() {
		if (listening) return;
		listening = true;
		doc.addEventListener('touchmove', onMove, MOVE_OPTS);
	}

	function unlisten() {
		if (!listening) return;
		listening = false;
		doc.removeEventListener('touchmove', onMove, MOVE_OPTS);
	}

	function move(distance) {
		var pulled = Math.min(MAX, distance * 0.5);
		build();
		indicator.style.transform = 'translate3d(-50%,' + pulled + 'px,0)';
		indicator.style.opacity = String(Math.min(1, pulled / 44));
		ring.style.transform = 'rotate(' + (pulled * 3) + 'deg)';
		var next = pulled >= THRESHOLD * 0.5;
		if (next !== armed) { armed = next; indicator.classList.toggle('is-armed', armed); }
	}

	function reset() {
		if (!indicator) return;
		indicator.classList.remove('is-armed');
		indicator.classList.add('is-settling');
		indicator.style.transform = '';
		indicator.style.opacity = '';
		window.setTimeout(function () { if (indicator) indicator.classList.remove('is-settling'); }, 260);
	}

	function refresh() {
		busy = true;
		build();
		indicator.classList.add('is-loading');
		indicator.style.transform = 'translate3d(-50%,64px,0)';
		indicator.style.opacity = '1';
		/* Older Android WebViews have no CustomEvent constructor. */
		try { doc.dispatchEvent(new CustomEvent('delicat:pull-refresh')); } catch (e) {}
		window.setTimeout(function () {
			try { window.location.reload(); } catch (e2) { window.location.href = window.location.href; }
		}, 140);
	}

	function onMove(e) {
		if (!start || e.touches.length !== 1) return;
		var dy = e.touches[0].clientY - start.y;
		var dx = e.touches[0].clientX - start.x;
		if (!active) {
			if (dy < 12 || Math.abs(dx) > Math.abs(dy) * 0.8) {
				if (dy < -4 || Math.abs(dx) > 12) { start = null; unlisten(); }
				return;
			}
			if ((window.pageYOffset || html.scrollTop || 0) > 0) { start = null; unlisten(); return; }
			active = true;
			build();
			indicator.classList.add('is-active');
			indicator.classList.remove('is-settling');
		}
		if (dy <= 0) { move(0); return; }
		move(dy);
		if (e.cancelable) e.preventDefault();
	}

	doc.addEventListener('touchstart', function (e) {
		start = null; active = false; armed = false;
		unlisten();
		if (e.touches.length !== 1 || blocked()) return;
		if ((window.pageYOffset || html.scrollTop || 0) > 0) return;
		if (scrollableAncestor(e.target)) return;
		start = { x: e.touches[0].clientX, y: e.touches[0].clientY };
		/* Attached here, not at load: the document keeps its scrolling fast path
		   for every gesture that is not a candidate pull. */
		listen();
	}, { passive: true });

	function end() {
		unlisten();
		if (!start) return;
		var wasArmed = armed;
		start = null;
		if (!active) return;
		active = false;
		if (indicator) indicator.classList.remove('is-active');
		if (wasArmed) refresh(); else reset();
		armed = false;
	}

	doc.addEventListener('touchend', end, { passive: true });
	doc.addEventListener('touchcancel', end, { passive: true });
	window.addEventListener('pageshow', function () { busy = false; unlisten(); if (indicator) { indicator.classList.remove('is-loading'); reset(); } });
}());
