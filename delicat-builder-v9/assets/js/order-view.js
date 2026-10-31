/*
 * Delicat Builder V9 9.5.1 — order details « Commande #… » (Mon compte › Commandes).
 * ES2019, no dependency. Starts on page load and again after the engine switched account
 * sections in place (delicat:navigated); does nothing on pages without the order view.
 *  - « Copier le code » next to every code of the codes zone (also codes revealed later);
 *  - while the order is not final, its status is checked through the order-status route
 *    (signed read-only token, owner session); when it changes the page reloads, because
 *    codes are only ever drawn on the server. Pauses when hidden or offline, backs off on
 *    errors, stops after the configured minutes or when the page is left.
 */
(function () {
	'use strict';
	var w = window, d = document;
	var LADDER = [4, 5, 6, 8, 10, 12, 15, 20];

	function json(root) {
		var n = root.querySelector('script.dlc-vo-config');
		try { return n ? JSON.parse(n.textContent) || {} : {}; } catch (e) { return {}; }
	}
	function store(k, v) {
		try {
			if (v === undefined) { return w.sessionStorage.getItem('dlc_vo_' + k); }
			if (v === null) { w.sessionStorage.removeItem('dlc_vo_' + k); } else { w.sessionStorage.setItem('dlc_vo_' + k, String(v)); }
		} catch (e) { /* storage off */ }
		return null;
	}
	function copyText(text) {
		if (w.navigator.clipboard && w.isSecureContext) { return w.navigator.clipboard.writeText(text); }
		return new Promise(function (ok, ko) {
			var t = d.createElement('textarea'), r = false;
			t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.top = '0'; t.style.opacity = '0';
			d.body.appendChild(t); t.select();
			try { r = d.execCommand('copy'); } catch (e) { r = false; }
			if (t.parentNode) { t.parentNode.removeChild(t); }
			if (r) { ok(); } else { ko(); }
		});
	}
	function glyph(name) {
		var ns = 'http://www.w3.org/2000/svg', s = d.createElementNS(ns, 'svg'), u = d.createElementNS(ns, 'use');
		s.setAttribute('class', 'dlc-vo-i'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
		u.setAttribute('href', '#dlc-vo-i-' + name); s.appendChild(u);
		return s;
	}
	function say(root, msg) {
		var a = root.querySelector('[data-vo="announcer"]');
		if (!a) { return; }
		a.textContent = '';
		setTimeout(function () { a.textContent = msg; }, 60);
	}

	function codes(root, T) {
		var zone = root.querySelector('.dlc-vo-codes__body');
		if (!zone) { return; }
		function add(code) {
			if (code.getAttribute('data-vo-copy') || !code.textContent.trim()) { return; }
			var next = code.nextElementSibling;
			if (next && /\bcopy\b|copier/i.test(next.className + ' ' + next.textContent)) { code.setAttribute('data-vo-copy', '1'); return; }
			code.setAttribute('data-vo-copy', '1');
			var b = d.createElement('button'), label = d.createElement('span'), t = 0;
			b.type = 'button'; b.className = 'dlc-vo-copy';
			label.textContent = T.copy || 'Copier le code';
			b.appendChild(glyph('copy')); b.appendChild(label);
			b.addEventListener('click', function () {
				copyText(code.textContent.trim()).then(function () {
					b.classList.add('is-done'); label.textContent = T.copied || 'Copié';
					say(root, T.copied || 'Copié');
					clearTimeout(t);
					t = setTimeout(function () { b.classList.remove('is-done'); label.textContent = T.copy || 'Copier le code'; }, 1800);
				}, function () { say(root, T.copyFail || ''); });
			});
			code.insertAdjacentElement('afterend', b);
		}
		Array.prototype.forEach.call(zone.querySelectorAll('code'), add);
		if (w.MutationObserver) {
			new MutationObserver(function () { Array.prototype.forEach.call(zone.querySelectorAll('code'), add); }).observe(zone, { childList: true, subtree: true });
		}
	}

	function live(root, C) {
		var T = C.i18n || {}, el = root.querySelector('[data-vo="live"]'), label = root.querySelector('[data-vo="live-label"]');
		if (!C.live || !C.endpoint || !C.token || !w.fetch || !el) { return; }
		var started = Date.now(), busy = false, errors = 0, step = 0, timer = 0, stopped = false, fp = C.fingerprint;
		var key = 'r' + C.order;
		el.hidden = false;
		function setState(state, text) { el.setAttribute('data-state', state); if (label && text) { label.textContent = text; } }
		function stop(text) { stopped = true; clearTimeout(timer); setState('paused', text || T.paused); }
		function schedule(sec) { clearTimeout(timer); if (!stopped) { timer = setTimeout(poll, sec * 1000); } }
		function gone() { return !root.isConnected; }
		function poll() {
			if (stopped || busy) { return; }
			if (gone()) { stopped = true; return; }
			if (d.hidden) { setState('paused', T.paused); return; }
			if (w.navigator.onLine === false) { setState('paused', T.paused); schedule(15); return; }
			if (Date.now() - started > (C.maxMinutes || 30) * 60000) { stop(T.paused); return; }
			busy = true;
			var ctl = w.AbortController ? new AbortController() : null, to = setTimeout(function () { if (ctl) { ctl.abort(); } }, 10000), h = { 'Content-Type': 'application/json' };
			if (C.nonce) { h['X-WP-Nonce'] = C.nonce; }
			w.fetch(C.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: h, body: JSON.stringify({ order: C.order, token: C.token }), signal: ctl ? ctl.signal : undefined })
				.then(function (r) {
					if (r.status === 429) { throw { wait: parseInt(r.headers.get('Retry-After'), 10) || 30 }; }
					if (r.status === 401 || r.status === 403 || r.status === 404) { throw { fatal: true }; }
					if (!r.ok) { throw {}; }
					return r.json();
				})
				.then(function (s) {
					errors = 0;
					if (gone()) { stopped = true; return; }
					if (!s || !s.phase) { schedule(LADDER[Math.min(step, 7)]); return; }
					if (s.fingerprint && s.fingerprint !== fp) {
						/* Codes and the new tracker are drawn on the server: reload, at most 3 times
						 * in a row for this order (a reload that lands resets the count below). */
						var n = +(store(key) || 0);
						if (n < 3) {
							store(key, n + 1);
							stopped = true;
							say(root, T.updated || '');
							setTimeout(function () { if (!gone()) { w.location.reload(); } }, 400);
							return;
						}
						fp = s.fingerprint;
					}
					store(key, null);
					if (s.terminal) { stop(); el.hidden = true; return; }
					setState('live', T.live);
					step++;
					schedule(LADDER[Math.min(step, 7)]);
				}, function (e) {
					e = e || {};
					if (e.fatal) { stop(); return; }
					if (e.wait) { schedule(e.wait); return; }
					errors++;
					schedule(Math.min(60, LADDER[Math.min(step, 7)] * Math.pow(2, errors)));
				})
				.then(function () { busy = false; clearTimeout(to); });
		}
		d.addEventListener('visibilitychange', function () {
			if (!stopped && !d.hidden && !gone()) { setState('live', T.live); schedule(1); }
		});
		setState('live', T.live);
		schedule(LADDER[0]);
	}

	function init() {
		var root = d.getElementById('dlc-vo');
		if (!root || root.getAttribute('data-vo-bound')) { return; }
		root.setAttribute('data-vo-bound', '1');
		var C = json(root);
		codes(root, C.i18n || {});
		live(root, C);
	}

	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', init); } else { init(); }
	d.addEventListener('delicat:navigated', function () { init(); });
}());
