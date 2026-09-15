/* Delicat Menu Engine 3.0 — storefront navigation runtime.
 *
 * ES5, no dependencies, no storage. The engine exists because the old one made
 * every page pay for a menu almost nobody opened: the whole panel — account,
 * wallet, tiles, every link, the social row — was live DOM in the document on
 * every request, styled and laid out during the load that the shopper was
 * actually waiting on.
 *
 * Here the panel body ships inside a <template>. Template content is parsed
 * but inert: no style resolution, no layout, no paint, no selector matching.
 * The page load pays for the ~20 nodes of drawer chrome and nothing else.
 *
 * It is then hydrated during the first idle slice after load and parked
 * off-screen (visibility:hidden, still laid out), so the first tap on the
 * hamburger runs a transform and nothing more. A tap that beats the idle
 * callback hydrates inline — one extra frame, once, instead of on every page.
 */
(function () {
	'use strict';

	var doc = document;
	var html = doc.documentElement;
	var root = null;      /* .dmenu overlay          */
	var panel = null;     /* sliding panel           */
	var body = null;      /* scroll region           */
	var template = null;  /* <template> with the body markup */
	var search = null;
	var empty = null;

	var open = false;
	var hydrated = false;
	var bound = false;
	var warm = false;
	var lastFocus = null;
	var lockY = 0;
	var locked = false;
	var savedTop = '';
	var openedAt = 0;
	var openedX = 0;
	var openedY = 0;
	var swipedAt = 0;
	var walletAt = 0;
	var drag = null;
	var animTimer = 0;
	var filterFrame = 0;

	var TRIGGERS = '[data-dmenu-open],[data-dsb8-menu-trigger],.dsb-menu-toggle';
	var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])';
	var raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 16); };

	function closest(node, selector) {
		while (node && node.nodeType === 1) {
			if (node.matches ? node.matches(selector) : (node.msMatchesSelector && node.msMatchesSelector(selector))) return node;
			node = node.parentNode;
		}
		return null;
	}

	function emit(name) {
		try { doc.dispatchEvent(new CustomEvent(name, { detail: { menu: root } })); } catch (e) {}
	}

	/* A cheap phone is one that says it is: save-data, few cores, little RAM,
	 * or a visitor who asked for less motion. Read once — none of these change
	 * within a page view, and navigator.connection is not free to poll. */
	function lite() {
		var mode = root && root.getAttribute('data-performance');
		if (mode === 'ultra-lite') return true;
		if (mode === 'balanced') return false;
		var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
		if (conn && conn.saveData) return true;
		if (typeof navigator.deviceMemory === 'number' && navigator.deviceMemory <= 4) return true;
		if (typeof navigator.hardwareConcurrency === 'number' && navigator.hardwareConcurrency <= 4) return true;
		return !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function speed() {
		if (!root) return 220;
		var value = parseInt(root.getAttribute('data-speed') || '220', 10);
		if (isNaN(value) || value < 80) value = 220;
		return root.classList.contains('is-lite') ? Math.min(value, 140) : value;
	}

	/* ------------------------------------------------------------ hydrate */

	function hydrate() {
		if (hydrated || !root) return;
		hydrated = true;
		if (template && template.content && body) {
			/* One move of an existing fragment, not a parse: the nodes were
			 * built with the document and have simply never been rendered. */
			body.appendChild(template.content);
			if (template.parentNode) template.parentNode.removeChild(template);
			template = null;
			search = root.querySelector('[data-dmenu-search]');
			empty = root.querySelector('[data-dmenu-empty]');
			if (search) {
				search.addEventListener('input', onSearch);
				search.addEventListener('keydown', function (e) {
					if ((e.key === 'Enter' || e.keyCode === 13) && !search.value.trim()) e.preventDefault();
				});
			}
		}
		markCurrent();
	}

	/* Lay the panel out while the page is idle so the first tap is a transform
	 * and not a first layout of 200 nodes. Parked state is written inline, not
	 * as a class: a stale or combined stylesheet without the rule would
	 * otherwise leave a full-screen overlay swallowing every tap. */
	function park() {
		if (!root || open || warm) return;
		hydrate();
		warm = true;
		root.style.visibility = 'hidden';
		root.style.pointerEvents = 'none';
		root.hidden = false;
	}

	function unpark() {
		if (!root) return;
		warm = false;
		root.style.visibility = '';
		root.style.pointerEvents = '';
	}

	/* --------------------------------------------------------- open/close */

	function lock() {
		if (!root || root.getAttribute('data-scroll-lock') === 'no') return;
		locked = true;
		lockY = window.pageYOffset || 0;
		savedTop = doc.body.style.top;
		html.classList.add('dmenu-open');
		doc.body.style.top = (-lockY) + 'px';
	}

	function unlock() {
		if (!locked) return;
		locked = false;
		html.classList.remove('dmenu-open');
		doc.body.style.top = savedTop;
		window.scrollTo(0, lockY);
	}

	function expanded(value) {
		var nodes = doc.querySelectorAll(TRIGGERS);
		for (var i = 0; i < nodes.length; i++) {
			nodes[i].setAttribute('aria-expanded', value ? 'true' : 'false');
			nodes[i].setAttribute('aria-controls', root.id || 'delicat-menu');
		}
	}

	/* will-change is held for the length of the transition and then dropped;
	 * a permanent promise costs compositor memory on a device that has none. */
	function animating() {
		root.classList.add('is-animating');
		if (animTimer) window.clearTimeout(animTimer);
		animTimer = window.setTimeout(function () {
			animTimer = 0;
			root.classList.remove('is-animating', 'is-entering');
		}, speed() + 60);
	}

	function show() {
		if (!root || open) return;
		hydrate();
		open = true;
		lastFocus = doc.activeElement;
		unpark();
		root.hidden = false;
		root.setAttribute('aria-hidden', 'false');
		root.classList.add('is-entering');
		expanded(true);
		lock();
		if (body) body.scrollTop = 0;
		animating();
		raf(function () {
			if (!open) return;
			root.classList.add('is-open');
			raf(function () {
				if (!open) return;
				try { (panel.querySelector('[data-dmenu-close]') || panel).focus({ preventScroll: true }); } catch (e) {}
				refreshWallet();
			});
		});
		emit('dmenu:open');
		emit('dlx:open');
	}

	function hide(restoreFocus) {
		if (!root || !open) return;
		open = false;
		drag = null;
		root.classList.remove('is-open', 'is-dragging', 'is-entering');
		if (panel) panel.style.transform = '';
		root.setAttribute('aria-hidden', 'true');
		expanded(false);
		unlock();
		animating();
		window.setTimeout(function () { if (!open) park(); }, speed() + 60);
		if (restoreFocus !== false && lastFocus && lastFocus.focus) {
			try { lastFocus.focus({ preventScroll: true }); } catch (e) {}
		}
		lastFocus = null;
		emit('dmenu:close');
		emit('dlx:close');
	}

	/* ---------------------------------------------------------- highlight */

	/* After an in-place navigation the panel is still the old page's: the
	 * "you are here" row would keep pointing at where the shopper was. */
	function markCurrent() {
		if (!hydrated || !root) return;
		var here = location.pathname.replace(/\/+$/, '') || '/';
		var items = root.querySelectorAll('.dmenu__item');
		for (var i = 0; i < items.length; i++) {
			var href = items[i].getAttribute('href') || '';
			var path = href;
			if (href.indexOf('//') !== -1) {
				try { path = new URL(href, location.href).pathname; } catch (e) { path = href; }
			}
			path = String(path).split('?')[0].split('#')[0].replace(/\/+$/, '') || '/';
			var current = path === here;
			items[i].classList.toggle('is-current', current);
			if (current) items[i].setAttribute('aria-current', 'page');
			else items[i].removeAttribute('aria-current');
		}
	}

	/* ------------------------------------------------------------- search */

	function onSearch() {
		if (filterFrame) return;
		filterFrame = raf(function () {
			filterFrame = 0;
			filter(search ? search.value : '');
		});
	}

	function filter(value) {
		var query = String(value || '').trim().toLowerCase();
		var items = root.querySelectorAll('.dmenu__item[data-dmenu-filter]');
		var shown = 0;
		for (var i = 0; i < items.length; i++) {
			var hit = !query || (items[i].getAttribute('data-dmenu-filter') || '').indexOf(query) !== -1;
			items[i].parentNode.hidden = !hit;
			if (hit) shown++;
		}
		var sections = root.querySelectorAll('[data-dmenu-section]');
		for (var s = 0; s < sections.length; s++) {
			var visible = sections[s].querySelectorAll('li:not([hidden]) > .dmenu__item').length;
			sections[s].hidden = !!query && !visible;
			if (query) sections[s].classList.remove('is-collapsed');
		}
		if (empty) empty.hidden = !query || shown > 0;
	}

	/* ------------------------------------------------------------- wallet */

	/* One refresh per open, at most one every 15 s, through the existing
	 * nonce-protected read-only action. Fired after the panel has arrived: a
	 * fetch started on the opening frame competes with the one animation the
	 * shopper is looking at. */
	function refreshWallet() {
		var target = root.querySelector('[data-dmenu-balance]');
		if (!target) return;
		if (window.DelicatSession && window.DelicatSession.refresh) {
			window.DelicatSession.refresh('menu-open');
			return;
		}
		var url = root.getAttribute('data-ajax');
		var nonce = root.getAttribute('data-wallet-nonce');
		var now = Date.now();
		if (!url || !nonce || !window.fetch || (now - walletAt) < 15000) return;
		walletAt = now;
		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=delicat_builder_v9_wallet_balance&nonce=' + encodeURIComponent(nonce)
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			var data = payload && payload.data ? payload.data : payload;
			var text = data && (data.html || data.balance_html || data.balance);
			if (!payload || payload.success === false || !text) return;
			/* Server markup is parsed into a detached document and only its
			 * text is taken: nothing from the response reaches this DOM. */
			var parsed = null;
			try { parsed = new DOMParser().parseFromString('<!doctype html><body>' + String(text), 'text/html'); } catch (e) {}
			target.textContent = parsed ? parsed.body.textContent.trim() : String(text);
			target.classList.add('is-fresh');
			window.setTimeout(function () { target.classList.remove('is-fresh'); }, 800);
		})['catch'](function () {});
	}

	/* --------------------------------------------------------------- copy */

	function copy(button) {
		var value = button.getAttribute('data-dmenu-copy') || '';
		var label = button.querySelector('[data-dmenu-copy-label]');
		var done = function () {
			if (!label) return;
			var was = label.textContent;
			label.textContent = 'Copié ✓';
			button.classList.add('is-done');
			window.setTimeout(function () {
				label.textContent = was;
				button.classList.remove('is-done');
			}, 1400);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(value).then(done, done);
			return;
		}
		var field = doc.createElement('textarea');
		field.value = value;
		field.setAttribute('readonly', '');
		field.style.position = 'fixed';
		field.style.opacity = '0';
		doc.body.appendChild(field);
		field.select();
		try { doc.execCommand('copy'); } catch (e) {}
		doc.body.removeChild(field);
		done();
	}

	/* -------------------------------------------------------------- swipe */

	function endDrag(commit) {
		if (!drag) return;
		var finished = drag;
		drag = null;
		root.classList.remove('is-dragging');
		/* A swipe is not a tap: the click it would produce has to be thrown
		 * away, or swiping the menu shut navigates on the way out. */
		if (finished.armed) swipedAt = Date.now();
		if (commit && finished.armed && Math.abs(finished.dx) > Math.min(110, panel.offsetWidth * 0.3)) {
			hide();
			return;
		}
		panel.style.transform = '';
	}

	function onPointerDown(event) {
		if (!open || event.pointerType === 'mouse' || event.button || event.isPrimary === false) return;
		if (root.getAttribute('data-swipe') === 'no') return;
		/* Text fields keep their own horizontal gestures — dragging in one
		 * moves the caret, and that must not slide the menu away instead. */
		if (closest(event.target, 'input,textarea,select')) return;
		drag = {
			x: event.clientX,
			y: event.clientY,
			dx: 0,
			armed: false,
			id: event.pointerId,
			right: root.classList.contains('is-right'),
			frame: false
		};
		try { if (panel.setPointerCapture) panel.setPointerCapture(event.pointerId); } catch (e) {}
	}

	function onPointerMove(event) {
		if (!drag || (drag.id !== undefined && event.pointerId !== drag.id)) return;
		var dx = event.clientX - drag.x;
		var dy = event.clientY - drag.y;
		if (!drag.armed) {
			if (Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx)) { endDrag(false); return; }
			if (Math.abs(dx) < 10 || Math.abs(dx) < Math.abs(dy)) return;
			drag.armed = true;
			root.classList.add('is-dragging');
		}
		drag.dx = drag.right ? Math.max(0, dx) : Math.min(0, dx);
		if (!drag.frame) {
			var active = drag;
			drag.frame = true;
			raf(function () {
				active.frame = false;
				if (drag === active && open) panel.style.transform = 'translate3d(' + active.dx + 'px,0,0)';
			});
		}
		if (event.cancelable) event.preventDefault();
	}

	function onPointerUp(event) {
		if (drag && event && event.pointerId !== undefined && drag.id !== undefined && event.pointerId !== drag.id) return;
		endDrag(true);
		if (event && event.pointerId !== undefined) {
			try {
				if (panel.releasePointerCapture && panel.hasPointerCapture && panel.hasPointerCapture(event.pointerId)) {
					panel.releasePointerCapture(event.pointerId);
				}
			} catch (e) {}
		}
	}

	/* --------------------------------------------------------------- bind */

	function bind() {
		root = doc.querySelector('[data-dmenu]');
		if (!root || bound) return;
		bound = true;
		panel = root.querySelector('[data-dmenu-panel]');
		body = root.querySelector('[data-dmenu-body]');
		template = root.parentNode ? root.parentNode.querySelector('[data-dmenu-template]') : null;
		if (!template) template = doc.querySelector('[data-dmenu-template]');
		root.classList.toggle('is-lite', lite());

		/* Open on pointerdown: on touch the click is up to 300 ms behind, and
		 * that delay is most of what "slow menu" means on a cheap phone. */
		doc.addEventListener('pointerdown', function (event) {
			if (open || event.button || event.isPrimary === false) return;
			if (!closest(event.target, TRIGGERS)) return;
			/* A timestamp and a place, not a flag. The click belonging to this
			 * tap has to be swallowed — it is dispatched at pointerup and
			 * hit-tests afresh, by which time the finger is over the overlay,
			 * so it landed on the scrim and shut the menu inside the same tap.
			 * A flag would not do: a tap that slides off the button never
			 * produces a click, and the flag would then eat the next real one.
			 * The position matters as much as the time: a deliberate second tap
			 * on the scrim, to close what was just opened, lands somewhere else
			 * and must still count. */
			openedAt = Date.now();
			openedX = event.clientX;
			openedY = event.clientY;
			event.preventDefault();
			show();
		}, true);

		doc.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || target.nodeType !== 1) return;

			/* The click that belongs to the tap that just opened the menu. By
			 * the time it is dispatched the finger is over the overlay, not
			 * over the hamburger, so it would land on the scrim and shut the
			 * menu inside the same tap. */
			if (openedAt) {
				var age = Date.now() - openedAt;
				var moved = Math.abs(event.clientX - openedX) + Math.abs(event.clientY - openedY);
				openedAt = 0;
				if (age < 700 && moved < 30) {
					event.preventDefault();
					if (event.stopImmediatePropagation) event.stopImmediatePropagation();
					return;
				}
			}
			if (swipedAt) {
				var since = Date.now() - swipedAt;
				swipedAt = 0;
				if (since < 700) {
					event.preventDefault();
					if (event.stopImmediatePropagation) event.stopImmediatePropagation();
					return;
				}
			}

			if (closest(target, TRIGGERS)) {
				event.preventDefault();
				if (event.stopImmediatePropagation) event.stopImmediatePropagation();
				if (open) hide(); else show();
				return;
			}
			if (!open) return;
			if (closest(target, '[data-dmenu-close]')) { event.preventDefault(); hide(); return; }

			var toggle = closest(target, '[data-dmenu-toggle]');
			if (toggle && root.contains(toggle)) {
				var section = closest(toggle, '[data-dmenu-section]');
				var collapsed = section.classList.toggle('is-collapsed');
				toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
				return;
			}
			var copyButton = closest(target, '[data-dmenu-copy]');
			if (copyButton && root.contains(copyButton)) { event.preventDefault(); copy(copyButton); return; }

			var install = closest(target, '[data-dmenu-install]');
			if (install && root.contains(install)) {
				event.preventDefault();
				hide();
				try { doc.dispatchEvent(new CustomEvent('delicat:install-app')); } catch (e) {}
				return;
			}
			var link = closest(target, 'a[href]');
			if (link && root.contains(link) && !link.target) {
				var href = link.getAttribute('href') || '';
				if (href.charAt(0) !== '#') hide();
			}
		}, true);

		doc.addEventListener('keydown', function (event) {
			if (!open) return;
			if (event.key === 'Escape' || event.keyCode === 27) { event.preventDefault(); hide(); return; }
			if (event.key !== 'Tab' && event.keyCode !== 9) return;
			/* Collapsed sections and filtered-out rows are still in the DOM;
			 * tabbing into something invisible is a dead key press. */
			var list = Array.prototype.filter.call(panel.querySelectorAll(FOCUSABLE), function (node) {
				return node.getClientRects().length && !closest(node, '[hidden]') && !closest(node, '.is-collapsed');
			});
			if (!list.length) return;
			var first = list[0];
			var last = list[list.length - 1];
			if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
			else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
		});

		if (window.PointerEvent && panel) {
			panel.addEventListener('pointerdown', onPointerDown, { passive: true });
			panel.addEventListener('pointermove', onPointerMove, { passive: false });
			panel.addEventListener('pointerup', onPointerUp, { passive: true });
			panel.addEventListener('pointercancel', function () { endDrag(false); }, { passive: true });
			/* The system can take the pointer away mid-swipe — a notification
			 * shade, a back gesture — and the drag would never end. */
			panel.addEventListener('lostpointercapture', function () { endDrag(false); }, { passive: true });
		}

		doc.addEventListener('dsb:close-menu', function () { hide(false); });
		doc.addEventListener('dsb8:overlay:open', function (event) {
			if (!event.detail || event.detail.id !== 'modern-menu') return;
			if (event.stopImmediatePropagation) event.stopImmediatePropagation();
			show();
		}, true);
		doc.addEventListener('delicat:navigation-complete', function () {
			hide(false);
			markCurrent();
		});
		window.addEventListener('resize', function () { endDrag(false); }, { passive: true });
		window.addEventListener('pageshow', function (event) { if (event.persisted && open) hide(false); });

		html.classList.add('dmenu-ready');
		if (window.requestIdleCallback) window.requestIdleCallback(park, { timeout: 2500 });
		else window.setTimeout(park, 1200);
	}

	if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bind, { once: true });
	else bind();

	window.DelicatMenu = { open: show, close: hide, hydrate: hydrate };
}());
