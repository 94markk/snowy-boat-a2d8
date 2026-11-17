/* Delicat Builder V9 — Maintenance page runtime (RC40).
 * ES5 only, no storage, no external request. Inlined into the 503 document.
 *
 * Two jobs:
 *  1. Tick the countdown against the SERVER clock, so a phone with a wrong
 *     local time still shows the real remaining delay.
 *  2. Ask the service worker to drop its cached documents. Without this, a
 *     returning visitor whose worker still holds a fresh storefront copy is
 *     served that copy instead of this page — the one way a closed site can
 *     still look open.
 */
(function () {
	'use strict';

	var root = document.getElementById('dm-root');
	if (!root) { return; }

	/* ---------- service worker: never serve the closed storefront ---------- */
	try {
		if (navigator.serviceWorker) {
			if (navigator.serviceWorker.controller) {
				navigator.serviceWorker.controller.postMessage({ type: 'dbv9-clear-docs' });
			}
			navigator.serviceWorker.getRegistrations().then(function (list) {
				for (var i = 0; i < list.length; i++) {
					try { list[i].update(); } catch (e) {}
					if (list[i].active) {
						try { list[i].active.postMessage({ type: 'dbv9-clear-docs' }); } catch (e2) {}
					}
				}
			})['catch'](function () {});
		}
	} catch (e) {}

	/* ---------- rotating announcements ---------- */
	var wrap = document.querySelector('.dm-notices');
	if (wrap) {
		var notes = wrap.querySelectorAll('.dm-note');
		var dots = wrap.querySelectorAll('.dm-dots button');
		if (notes.length > 1) {
			var current = 0;
			var auto = null;

			var show = function (next) {
				if (next === current) { return; }
				for (var i = 0; i < notes.length; i++) {
					notes[i].className = 'dm-note' + (i === next ? ' is-on' : '');
					if (dots[i]) { dots[i].className = i === next ? 'is-on' : ''; }
				}
				current = next;
			};

			var advance = function () { show((current + 1) % notes.length); };

			for (var d = 0; d < dots.length; d++) {
				(function (index) {
					dots[index].onclick = function () {
						if (auto) { clearInterval(auto); auto = null; }
						show(index);
					};
				})(d);
			}

			auto = setInterval(advance, 5000);
		}
	}

	/* ---------- countdown ---------- */
	var box = document.getElementById('dm-countdown');
	if (!box) { return; }

	var endTs = parseInt(box.getAttribute('data-end'), 10) || 0;
	var nowTs = parseInt(box.getAttribute('data-now'), 10) || 0;
	if (!endTs || !nowTs) { return; }

	var fields = {
		d: document.getElementById('dm-d'),
		h: document.getElementById('dm-h'),
		m: document.getElementById('dm-m'),
		s: document.getElementById('dm-s')
	};
	if (!fields.d || !fields.h || !fields.m || !fields.s) { return; }

	/* Correct for a wrong device clock once, at load. */
	var skew = Date.now() - (nowTs * 1000);

	function pad(value) {
		value = value < 0 ? 0 : value;
		return value < 10 ? '0' + value : String(value);
	}

	function paint(node, text) {
		if (node.firstChild && node.firstChild.nodeType === 3) {
			node.firstChild.nodeValue = text;
		} else {
			node.textContent = text;
		}
	}

	var timer = null;

	function tick() {
		var remaining = Math.floor(((endTs * 1000) + skew - Date.now()) / 1000);
		if (remaining <= 0) {
			paint(fields.d, '00');
			paint(fields.h, '00');
			paint(fields.m, '00');
			paint(fields.s, '00');
			if (timer) { clearInterval(timer); }
			/* The window has closed: one reload asks the server whether the
			 * store is open again. Never a loop — the server answers once. */
			setTimeout(function () { window.location.reload(); }, 4000);
			return;
		}
		paint(fields.d, pad(Math.floor(remaining / 86400)));
		paint(fields.h, pad(Math.floor((remaining % 86400) / 3600)));
		paint(fields.m, pad(Math.floor((remaining % 3600) / 60)));
		paint(fields.s, pad(remaining % 60));
	}

	tick();
	timer = setInterval(tick, 1000);

	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') { tick(); }
	});
})();
