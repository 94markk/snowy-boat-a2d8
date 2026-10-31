/* Delicat Announcement Studio — storefront runtime.
 *
 * Vanilla ES5. No localStorage / sessionStorage: dismissal is stored in a
 * first-party cookie so the rendered HTML stays identical for every guest and
 * page caching keeps working.
 */
(function () {
	'use strict';

	var COOKIE = 'dbv9_annc';

	function readCookie(name) {
		var parts = document.cookie ? document.cookie.split(';') : [];
		for (var i = 0; i < parts.length; i++) {
			var pair = parts[i];
			while (pair.charAt(0) === ' ') {
				pair = pair.substring(1);
			}
			if (pair.indexOf(name + '=') === 0) {
				return decodeURIComponent(pair.substring(name.length + 1));
			}
		}
		return '';
	}

	function writeCookie(name, value, maxAgeSeconds) {
		var cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';
		if (maxAgeSeconds > 0) {
			cookie += '; max-age=' + maxAgeSeconds;
		}
		if (location.protocol === 'https:') {
			cookie += '; Secure';
		}
		document.cookie = cookie;
	}

	/* RC55: the `wordpress_logged_in_*` cookie is HttpOnly, so document.cookie
	 * never contains it and the previous check was always false — "members"
	 * never saw the popup and "guests" showed it to everyone. WordPress prints
	 * `logged-in` on <body> for a signed-in visitor, and that page is never
	 * served from the shared cache (Security::has_private_cookie), so the class
	 * is accurate for the person reading it. The session store, when it has
	 * already answered, is authoritative over a cached copy. */
	function isSignedIn() {
		var session = window.DelicatSession && window.DelicatSession.get ? window.DelicatSession.get() : null;
		if (session && typeof session.loggedIn === 'boolean') {
			return session.loggedIn;
		}
		return !!(document.body && document.body.classList && document.body.classList.contains('logged-in'));
	}

	function focusableIn(root) {
		var nodes = root.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		var out = [];
		for (var i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			if (node.offsetWidth || node.offsetHeight || node.getClientRects().length) {
				out.push(node);
			}
		}
		return out;
	}

	function suppressFloatingHelp() {
		var hidden = [];
		var selectors = [
			'[data-delicat-assistant]',
			'[class*="assistant"]', '[id*="assistant"]',
			'[class*="chat"]', '[id*="chat"]',
			'[class*="support-widget"]', '[id*="support-widget"]',
			'iframe[title*="chat" i]', 'iframe[title*="assistant" i]'
		].join(',');
		var nodes;
		try { nodes = document.querySelectorAll(selectors); } catch (e) { nodes = []; }
		for (var i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			if (!node || node.closest && node.closest('[data-dbv9-announcement]')) continue;
			var style;
			try { style = window.getComputedStyle(node); } catch (e2) { style = null; }
			if (!style || (style.position !== 'fixed' && style.position !== 'sticky')) continue;
			hidden.push({
				node: node,
				display: node.style.getPropertyValue('display'),
				priority: node.style.getPropertyPriority('display')
			});
			node.style.setProperty('display', 'none', 'important');
		}
		return hidden;
	}

	function restoreFloatingHelp(hidden) {
		for (var i = 0; i < hidden.length; i++) {
			var item = hidden[i];
			if (!item.node || !item.node.style) continue;
			if (item.display) item.node.style.setProperty('display', item.display, item.priority || '');
			else item.node.style.removeProperty('display');
		}
	}

	function activateStylesheet() {
		var link = document.getElementById('delicat-builder-v9-announcement-css');
		if (link && String(link.media || '').toLowerCase() === 'print') {
			link.media = 'all';
		}
	}

	function init(root) {
		activateStylesheet();
		if (!root || root.getAttribute('data-dbv9-annc-ready') === '1') {
			return;
		}
		root.setAttribute('data-dbv9-annc-ready', '1');

		var dialog = root.querySelector('.dbv9-annc__dialog');
		if (!dialog) {
			return;
		}

		/* A transformed/filter ancestor can turn position:fixed into an
		 * ancestor-relative box on Safari and Chromium. Move the modal to body
		 * once so "center" always means the actual visual viewport and the
		 * announcement escapes theme/header stacking contexts. */
		if (document.body && root.parentNode !== document.body) {
			document.body.appendChild(root);
		}

		var signature = root.getAttribute('data-sig') || '';
		var frequency = root.getAttribute('data-frequency') || 'days';
		var days = parseInt(root.getAttribute('data-days'), 10);
		var delay = parseInt(root.getAttribute('data-delay'), 10);
		var audience = root.getAttribute('data-audience') || 'all';

		if (isNaN(days) || days < 1) {
			days = 7;
		}
		if (isNaN(delay) || delay < 0) {
			delay = 0;
		}

		/* Audience is decided in the browser, never in PHP, so the cached HTML
		 * is the same document for every visitor. */
		if (audience === 'guests' && isSignedIn()) {
			return;
		}
		if (audience === 'members' && !isSignedIn()) {
			return;
		}

		if (frequency !== 'always' && readCookie(COOKIE) === signature) {
			return;
		}

		var lastFocus = null;
		var isOpen = false;
		var closeTimer = 0;
		var hiddenFloatingHelp = [];

		function remember() {
			if (frequency === 'always') {
				return;
			}
			/* "session" writes a session cookie (no max-age), so it clears when
			 * the browser is closed. */
			writeCookie(COOKIE, signature, frequency === 'session' ? 0 : days * 86400);
		}

		function open() {
			if (isOpen) {
				return;
			}
			isOpen = true;
			lastFocus = document.activeElement;

			root.hidden = false;
			root.classList.remove('is-closing');
			document.documentElement.classList.add('dbv9-annc-lock');
			hiddenFloatingHelp = suppressFloatingHelp();

			/* Force a frame so the transition runs from the closed state. */
			if (window.requestAnimationFrame) {
				window.requestAnimationFrame(function () {
					window.requestAnimationFrame(function () {
						root.classList.add('is-open');
					});
				});
			} else {
				root.classList.add('is-open');
			}

			var targets = focusableIn(dialog);
			if (targets.length) {
				targets[0].focus();
			}

			document.addEventListener('keydown', onKeydown, true);
		}

		function close() {
			if (!isOpen) {
				return;
			}
			isOpen = false;
			remember();

			root.classList.remove('is-open');
			root.classList.add('is-closing');
			document.documentElement.classList.remove('dbv9-annc-lock');
			restoreFloatingHelp(hiddenFloatingHelp);
			hiddenFloatingHelp = [];
			document.removeEventListener('keydown', onKeydown, true);

			if (closeTimer) {
				window.clearTimeout(closeTimer);
			}
			closeTimer = window.setTimeout(function () {
				root.classList.remove('is-closing');
				root.hidden = true;
				if (lastFocus && lastFocus.focus) {
					try {
						lastFocus.focus();
					} catch (e) {}
				}
			}, 320);
		}

		function onKeydown(event) {
			var key = event.key || '';
			if (key === 'Escape' || key === 'Esc' || event.keyCode === 27) {
				event.preventDefault();
				close();
				return;
			}
			if (key !== 'Tab' && event.keyCode !== 9) {
				return;
			}
			var targets = focusableIn(dialog);
			if (!targets.length) {
				return;
			}
			var first = targets[0];
			var last = targets[targets.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		}

		/* X button, secondary button and the overlay all resolve to the same
		 * dismissal path. */
		var dismissers = root.querySelectorAll('[data-dbv9-annc-dismiss]');
		for (var i = 0; i < dismissers.length; i++) {
			dismissers[i].addEventListener('click', function (event) {
				event.preventDefault();
				close();
			});
		}

		/* A click that starts inside the dialog and ends on the overlay (a
		 * drag/text selection) must not close it. */
		dialog.addEventListener('click', function (event) {
			event.stopPropagation();
		});

		var goButton = root.querySelector('[data-dbv9-annc-go]');
		if (goButton) {
			goButton.addEventListener('click', function () {
				/* Remember before navigating so the popup does not reappear on
				 * the destination page. */
				remember();
			});
		}

		/* TurboNav prerenders the next page on hover/touch intent, and timers run
		 * inside a prerendered document. Without this the delay would burn down
		 * before the visitor ever arrived, so the popup would be mid-open the
		 * instant the page was activated instead of easing in as configured. */
		function start() {
			if (delay > 0) {
				window.setTimeout(open, delay);
			} else {
				open();
			}
		}

		if (document.prerendering) {
			document.addEventListener('prerenderingchange', start, { once: true });
		} else {
			start();
		}
	}

	function boot() {
		var nodes = document.querySelectorAll('[data-dbv9-announcement]');
		for (var i = 0; i < nodes.length; i++) {
			init(nodes[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
