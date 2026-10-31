/* Delicat Builder 9.4.0 - Confirmation de commande: live status, motion, code copy buttons, share
 * sheet and print. The receipt canvas (order-confirmation-receipt.js) loads on the first share intent.
 * No dependency and no third-party request. Server strings are written with textContent /
 * setAttribute only. Motion: transform / opacity only, levels full | calm (opacity) | off. */
(function () {
	'use strict';
	var d = document, w = window, root = d.getElementById('dlc-oc');
	if (!root) { return; }
	function json(id) { var n = d.getElementById(id); try { return n ? JSON.parse(n.textContent) : null; } catch (e) { return null; } }
	function $(s, c) { return (c || root).querySelector(s); }
	function $$(s, c) { return Array.prototype.slice.call((c || root).querySelectorAll(s)); }
	function fmt(s) { var a = arguments, i = 0; return String(s || '').replace(/%(?:(\d)\$)?[sd]/g, function (m, n) { var v = a[n ? +n : ++i]; return v == null ? '' : v; }); }
	function drop(n) { if (n && n.parentNode) { n.parentNode.removeChild(n); } }
	/* One-shot class animation; will-change only while it runs. */
	function once(el, cls, ms) {
		if (!el) { return; }
		el.classList.remove(cls); void el.offsetWidth; el.style.willChange = 'transform, opacity'; el.classList.add(cls);
		setTimeout(function () { el.classList.remove(cls); el.style.willChange = ''; }, ms);
	}
	function frame(f) { return (w.requestAnimationFrame || setTimeout)(f); }
	var C = json('dlc-oc-config') || {}, T = C.i18n || {}, html = d.documentElement.classList;
	var key = 'dlc-oc:' + C.order + ':';
	function store(k, v) { try { if (v !== undefined) { if (v === null) { sessionStorage.removeItem(key + k); } else { sessionStorage.setItem(key + k, v); } } return sessionStorage.getItem(key + k); } catch (e) { return null; } }

	/* One h1 per document: the page's own title is hidden visually but stays the h1; on a page that
	 * prints no h1 at all, the hero headline takes that role (same id, class and text, no visual change). */
	(function () {
		var h = $('[data-oc="headline"]'), n, i;
		if (!h || d.querySelector('h1')) { return; }
		n = d.createElement('h1');
		for (i = 0; i < h.attributes.length; i++) { n.setAttribute(h.attributes[i].name, h.attributes[i].value); }
		n.textContent = h.textContent; h.parentNode.replaceChild(n, h);
	}());

	/* A reload (ours or the gateway's) skips the card entrance: the server read this 20 s cookie. */
	function again(on) { try { d.cookie = 'dlc_oc_again=' + (on ? '1; max-age=20' : '; max-age=0') + '; path=' + location.pathname + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); } catch (e) { /* cookies off */ } }
	again(false);
	if (!C.terminal) { w.addEventListener('pagehide', function () { again(true); }); }

	/* Motion level: setting, lowered by reduced motion (off) or a slow phone / save-data (calm). */
	var conn = navigator.connection || {};
	var lite = html.contains('delicat-low-power') || html.contains('dsb-cheap-device') || html.contains('delicat-save-data') || !!conn.saveData || /2g/.test(conn.effectiveType || '');
	var motion = C.motion || 'full';
	if ((w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches) || html.contains('delicat-reduce-motion')) { motion = 'off'; } else if (lite && motion === 'full') { motion = 'calm'; }
	root.setAttribute('data-motion', motion);

	/* Infinite animations run only while seen: the hero ones and the delivery shimmer apart. */
	var hero = $('.dlc-oc-hero'), delivery = $('.dlc-oc-delivery'), heroOn = true, rowsOn = true;
	function pause() { root.classList.toggle('dlc-oc--paused', d.hidden || !heroOn); if (delivery) { delivery.classList.toggle('dlc-oc-delivery--paused', d.hidden || !rowsOn); } }
	if (w.IntersectionObserver) {
		var io = new IntersectionObserver(function (list) { list.forEach(function (e) { if (e.target === hero) { heroOn = e.isIntersecting; } else { rowsOn = e.isIntersecting; } }); pause(); });
		if (hero) { io.observe(hero); }
		if (delivery) { io.observe(delivery); }
	}

	/* Live regions: emptied first, written on the next frame, so the change is announced. */
	function say(el, msg) { if (!el) { return; } el.textContent = ''; frame(function () { el.textContent = msg; }); }
	function announce(pill, headline) { say($('[data-oc="announcer"]'), fmt(T.announce, pill, headline)); }
	var toastEl = $('.dlc-oc-toast'), toastT = 0, sheet = d.getElementById('dlc-oc-sheet');
	function toast(msg) {
		if (!toastEl) { return; }
		clearTimeout(toastT); say(toastEl, msg);
		frame(function () { toastEl.classList.add('is-on'); });
		toastT = setTimeout(function () { toastEl.classList.remove('is-on'); toastT = setTimeout(function () { toastEl.textContent = ''; }, 260); }, 2400);
	}
	function copyText(text) {
		if (navigator.clipboard && w.isSecureContext) { return navigator.clipboard.writeText(text); }
		return new Promise(function (ok, ko) {
			var t = d.createElement('textarea'), r = false;
			t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0'; (sheet && sheet.open ? sheet : d.body).appendChild(t); t.select();
			try { r = d.execCommand('copy'); } catch (e) { r = false; }
			drop(t); if (r) { ok(); } else { ko(); }
		});
	}
	function icon(name) {
		var ns = 'http://www.w3.org/2000/svg', s = d.createElementNS(ns, 'svg'), u = d.createElementNS(ns, 'use');
		s.setAttribute('class', 'dlc-oc-i'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false'); u.setAttribute('href', '#dlc-oc-i-' + name); s.appendChild(u);
		return s;
	}
	/* « Copier » next to every code; « Copié ! » cross-fades in place (constant width) + toast. The
	 * gateway's revealed codes carry their own copy button: that one is restyled, not doubled. */
	var codes = $('.dlc-oc-codes');
	function addCopy(code) {
		if (code.getAttribute('data-oc-copy') || !code.textContent.trim()) { return; }
		code.setAttribute('data-oc-copy', '1');
		var next = code.nextElementSibling;
		if (next && next.tagName === 'BUTTON') { return; }
		var b = d.createElement('button'), label = d.createElement('span'), done = d.createElement('span'), t = 0;
		b.type = 'button'; b.className = 'dlc-oc-btn dlc-oc-copy'; b.setAttribute('aria-label', T.copyCode);
		label.className = 'dlc-oc-copy__label'; label.appendChild(icon('copy')); label.appendChild(d.createTextNode(T.copy));
		done.className = 'dlc-oc-copy__done'; done.setAttribute('aria-hidden', 'true'); done.appendChild(icon('check')); done.appendChild(d.createTextNode(T.copied));
		b.appendChild(label); b.appendChild(done);
		b.addEventListener('click', function () {
			copyText(code.textContent.trim()).then(function () {
				clearTimeout(t); b.classList.add('is-done'); b.setAttribute('aria-label', T.codeDone);
				if (motion === 'full') { once(b, 'is-pop', 260); }
				toast(T.codeDone);
				t = setTimeout(function () { b.classList.remove('is-done'); b.setAttribute('aria-label', T.copyCode); }, 1800);
			}, function () { toast(T.copyFail); });
		});
		code.insertAdjacentElement('afterend', b);
	}
	if (codes) {
		/* « N° de série : X9F2-… »: the value becomes one inline block (textContent only), so the chip
		 * wraps before it and never inside it while it fits a line (CSS: .dlc-oc-codes__val). */
		$$('.dst2t-vouchers small', codes).forEach(function (s) {
			var t = s.textContent, i = t.lastIndexOf(': '), v;
			if (i < 0 || s.firstElementChild || !t.slice(i + 2).trim()) { return; }
			v = d.createElement('span'); v.className = 'dlc-oc-codes__val'; v.textContent = t.slice(i + 2);
			s.textContent = t.slice(0, i + 2); s.appendChild(v);
		});
		$$('code', codes).forEach(addCopy);
		if (w.MutationObserver) { new MutationObserver(function () { $$('code', codes).forEach(addCopy); }).observe(codes, { childList: true, subtree: true }); }
	}

	/* Live pill: one fixed row, only its text changes */
	var live = $('.dlc-oc-live'), liveLabel = $('.dlc-oc-live__label'), liveAgo = $('.dlc-oc-live__ago'), refresh = $('.dlc-oc-live__refresh'), lastOk = Date.now(), tickT = 0;
	function setLive(state, label, ago) { if (!live) { return; } live.setAttribute('data-state', state); liveLabel.textContent = label; liveAgo.textContent = ago || ''; }
	function hhmm(t) { var x = new Date(t); return ('0' + x.getHours()).slice(-2) + ':' + ('0' + x.getMinutes()).slice(-2); }
	function ago() { var s = Math.max(0, Math.round((Date.now() - lastOk) / 1000)); return s < 2 ? T.now : fmt(T.ago, s < 60 ? fmt(T.sec, s) : fmt(T.min, Math.floor(s / 60))); }
	function tick() { clearTimeout(tickT); if (!live || live.getAttribute('data-state') !== 'live') { return; } liveAgo.textContent = ago(); tickT = setTimeout(tick, Date.now() - lastOk < 60000 ? 1000 : 15000); }

	/* Status polling: one request at a time, backs off, pauses hidden or offline, stops when final. */
	var phase = C.phase, fp = C.fingerprint, started = Date.now(), busy = false, errors = 0, step = 0, timer = 0;
	var stopped = !C.live || !!C.terminal || !C.endpoint || !w.fetch, LADDER = [3, 4, 5, 6, 8, 10, 12, 15];
	var api = w.DlcOc = { T: T, fmt: fmt, toast: toast, copy: copyText, motion: motion, last: null, patch: null };
	function base() { return phase === 'awaiting_payment' ? 8 : phase === 'payment_review' ? 15 : phase === 'review' ? 30 : LADDER[Math.min(step, 7)]; }
	function schedule(sec) { clearTimeout(timer); if (!stopped) { timer = setTimeout(poll, sec * (lite ? 1500 : 1000)); } }
	function stop(state, label, again2) { stopped = true; clearTimeout(timer); clearTimeout(tickT); setLive(state, label); if (refresh) { refresh.hidden = !again2; } }
	function poll() {
		if (stopped || busy) { return; }
		if (d.hidden) { setLive('paused', T.paused); return; }
		if (navigator.onLine === false) { setLive('offline', T.offline); return; }
		if (Date.now() - started > (C.maxMinutes || 30) * 60000) { stop('stopped', T.idle, true); return; }
		busy = true;
		var ctl = w.AbortController ? new AbortController() : null, to = setTimeout(function () { if (ctl) { ctl.abort(); } }, 10000), h = { 'Content-Type': 'application/json' };
		if (C.nonce) { h['X-WP-Nonce'] = C.nonce; }
		fetch(C.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: h, body: JSON.stringify({ order: C.order, token: C.token }), signal: ctl ? ctl.signal : undefined })
			.then(function (r) {
				if (r.status === 429) { throw { wait: parseInt(r.headers.get('Retry-After'), 10) || 30 }; }
				if (r.status === 401 || r.status === 403 || r.status === 404) { throw { fatal: true }; }
				if (!r.ok) { throw {}; }
				return r.json();
			})
			.then(function (s) { errors = 0; lastOk = Date.now(); apply(s); }, function (e) {
				e = e || {};
				if (e.fatal) { stop('stopped', T.stopped, true); return; }
				if (e.wait) { schedule(e.wait); return; }
				errors++;
				if (errors >= 5) { setLive('offline', T.unstable); }
				schedule(Math.min(60, base() * Math.pow(2, errors)));
			})
			.then(function () { busy = false; clearTimeout(to); });
	}
	function apply(s) {
		if (!s || !s.phase || !s.steps) { schedule(base()); return; }
		if (s.fingerprint !== fp) {
			var prev = phase;
			fp = s.fingerprint; phase = s.phase; step = 0;
			render(s);
			api.last = s; if (api.patch) { api.patch(s); }
			announce(s.pill.label, s.headline);
			d.dispatchEvent(new CustomEvent('delicat:orderphase', { detail: { order: C.order, phase: phase } }));
			/* Codes, vouchers and the receipt are drawn on the server (the status route never carries
			 * them): reload to show them, after the tracker has moved. The receipt reload has its own
			 * once-per-tab flag; code reloads stop after 3 in a row that missed the state they aimed at
			 * (a reload landing on its target resets the count below: progress, not a loop). */
			var n = +(store('reloads') || 0), needReceipt = !!s.shareable && !!C.share && !d.getElementById('dlc-oc-receipt') && !store('receipt');
			if (needReceipt || (s.reload && n < 3)) {
				if (needReceipt) { store('receipt', 1); } else { store('reloads', n + 1); }
				store('seen', prev); store('target', s.fingerprint);
				stopped = true; clearTimeout(timer);
				setTimeout(function () { w.location.reload(); }, motion === 'off' ? 300 : 900);
				return;
			}
			store('seen', s.terminal ? null : phase);
			if (phase === 'delivered') { celebrate(980); }
		} else { step++; }
		if (s.terminal) { stop('final', fmt(T.final, hhmm(lastOk))); return; }
		setLive('live', T.live, ago()); tick(); schedule(base());
	}

	/* Applying a new state (A5 step progression, A6 text swap, tone cross-fade) */
	var ICONS = { awaiting_payment: 'clock', payment_review: 'shield', paid: 'box', sending: 'box', partial: 'box', review: 'alert', delivered: 'check', failed: 'x', cancelled: 'x', refunded: 'return' };
	var PROGRESS = { awaiting_payment: 0, payment_review: 0, paid: 1 / 3, sending: 1 / 3, review: 1 / 3, partial: 2 / 3, delivered: 1 };
	function swap(el, text) {
		if (!el || el.textContent === text) { return; }
		if (motion === 'off') { el.textContent = text; return; }
		el.classList.add('dlc-oc-swap-out');
		setTimeout(function () { el.textContent = text; el.classList.remove('dlc-oc-swap-out'); once(el, 'dlc-oc-swap-in', 280); }, 160);
	}
	var fadeT = 0;
	function tone(next) {
		var was = hero.getAttribute('data-tone');
		if (was === next) { return; }
		clearTimeout(fadeT);
		if (motion !== 'off' && was) { hero.removeAttribute('data-prev-tone'); void hero.offsetWidth; hero.setAttribute('data-prev-tone', was); fadeT = setTimeout(function () { hero.removeAttribute('data-prev-tone'); }, 500); }
		hero.setAttribute('data-tone', next);
	}
	/* Connector fill to p: 300 ms for a short move, up to 450 ms for the whole rail. Returns the duration. */
	var track = $('.dlc-oc-track'), fill = $('.dlc-oc-track__fill');
	function progressNow() { return track ? parseFloat(track.style.getPropertyValue('--p')) || 0 : 0; }
	function fillTo(p) {
		if (!track) { return 0; }
		var dur = motion === 'full' ? Math.round(300 + 150 * Math.min(1, Math.abs(p - progressNow()) * 1.5)) : (motion === 'calm' ? 200 : 0);
		if (fill) { fill.style.transitionDuration = dur ? dur + 'ms' : ''; }
		track.style.setProperty('--p', p);
		/* The rail glint runs on the stretch after the fill, toward the step in progress. */
		track.setAttribute('data-at', String(Math.max(0, Math.min(2, Math.round(3 * p)))));
		return dur;
	}
	/* Nodes reached between two fills pop as the fill arrives under them (spring, then the badge). */
	function popSteps(lis, from, to, dur) {
		if (motion !== 'full') { return; }
		lis.forEach(function (li) {
			var k = +li.getAttribute('data-i'), x = k / 3, t = to > from ? Math.max(0, Math.min(1, (x - from) / (to - from))) : 0, delay = Math.round(60 + dur * t);
			var node = $('.dlc-oc-step__node', li), ic = $('.dlc-oc-step__badge', li);
			if (node) { node.style.animationDelay = delay + 'ms'; }
			if (ic) { ic.style.animationDelay = delay + 'ms'; }
			once(li, 'is-pop', delay + 520);
			setTimeout(function () { if (node) { node.style.animationDelay = ''; } if (ic) { ic.style.animationDelay = ''; } }, delay + 540);
		});
	}
	var STEPS = $$('.dlc-oc-step');
	STEPS.forEach(function (li, i) { li.setAttribute('data-i', i); });
	/* FLIP (transform only, full motion): what a live change moves under the hero (the delivery card's
	 * content or the card itself leaving, a share button going) glides from where it was. The first
	 * rects are read before render() changes anything, the last ones after every change. A button
	 * inside the actions row moves by its own offset (its row moves too). */
	var flipT = 0;
	function flipFirst() {
		if (motion !== 'full') { return null; }
		var els = Array.prototype.filter.call(root.children, function (el) { return el !== hero && /\bdlc-oc-(card|actions|extra)\b/.test(el.className); }).concat($$('.dlc-oc-actions .dlc-oc-btn'));
		return { els: els, r: els.map(function (el) { return el.getBoundingClientRect(); }) };
	}
	function flipPlay(f) {
		if (!f) { return; }
		clearTimeout(flipT);
		f.els.forEach(function (el) { el.style.transition = 'none'; el.style.transform = ''; });
		var mv = f.els.map(function (el, i) {
			var a = f.r[i], b = el.parentNode && el.offsetParent ? el.getBoundingClientRect() : null;
			return b && (a.width || a.height) ? [a.left - b.left, a.top - b.top] : null;
		});
		f.els.forEach(function (el, i) {
			var m = mv[i], p = mv[f.els.indexOf(el.parentNode)] || [0, 0], dx = m ? m[0] - p[0] : 0, dy = m ? m[1] - p[1] : 0;
			if (Math.abs(dx) >= 1 || Math.abs(dy) >= 1) { el.style.transform = 'translate(' + dx + 'px,' + dy + 'px)'; }
		});
		void root.offsetWidth;
		f.els.forEach(function (el) { el.style.transition = el.style.transform ? 'transform .32s cubic-bezier(.2,.8,.2,1)' : ''; el.style.transform = ''; });
		flipT = setTimeout(function () { f.els.forEach(function (el) { el.style.transition = ''; }); }, 360);
	}
	function render(s) {
		/* The entrance has played (the first poll comes 2.5 s after load): its rules stop matching. */
		if (root.getAttribute('data-entrance') === 'full') { root.setAttribute('data-entrance', 'played'); }
		var flip = flipFirst();
		root.setAttribute('data-phase', s.phase); root.setAttribute('data-group', s.group);
		if (!s.shareable) { unshare(); }
		tone(s.tone);
		swap($('[data-oc="headline"]'), s.headline); swap($('[data-oc="message"]'), s.message);
		var pill = $('[data-oc="pill"]'), u = $('.dlc-oc-emblem__disc use'), cta = $('.dlc-oc-cta'), note = $('.dlc-oc-note'), icon2 = '#dlc-oc-i-' + (ICONS[s.phase] || 'box');
		pill.setAttribute('data-tone', s.pill.tone); swap(pill, s.pill.label);
		if (u && u.getAttribute('href') !== icon2) { u.setAttribute('href', icon2); if (motion !== 'off') { once($('.dlc-oc-emblem'), 'is-swap', 400); } }
		var from = progressNow(), moved = [], flow = '0';
		STEPS.forEach(function (li, i) {
			var st = s.steps[i]; if (!st) { return; }
			var was = (li.className.match(/\bis-(done|current|alert|ended|todo|skipped)\b/) || [])[1], sh = $('.dlc-oc-step__short', li);
			li.className = 'dlc-oc-step is-' + st.state;
			if (st.state === 'current' || st.state === 'alert') { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
			swap($('.dlc-oc-step__long', li), st.title);
			if (sh) { sh.textContent = st.short || st.title; }
			swap($('.dlc-oc-step__sub', li), st.subtitle);
			$('.dlc-oc-step__sr', li).textContent = st.state === 'done' ? ' — ' + T.done : st.state === 'ended' ? ' — ' + T.ended : '';
			var badge = $('.dlc-oc-step__badge use', li);
			if (badge) { badge.setAttribute('href', '#dlc-oc-i-' + (st.state === 'alert' ? 'alert' : st.state === 'ended' ? (s.phase === 'refunded' ? 'return' : 'x') : 'check')); }
			if (st.state === 'current') { flow = '1'; }
			if (was !== st.state && /^(done|current|alert)$/.test(st.state)) { moved.push(li); }
		});
		if (track) { track.setAttribute('data-flow', flow); }
		popSteps(moved, from, s.progress, fillTo(s.progress));
		if (cta) {
			var c = s.cta, pay = $('[data-cta-pay]', cta), oldc = cta.getAttribute('data-cta');
			if ((c === 'pay' || c === 'retry') && !pay) { c = 'none'; }
			if (c === 'none' && s.shareable && $('[data-cta-share]', cta)) { c = 'share'; }
			if (pay && (c === 'pay' || c === 'retry')) { pay.textContent = pay.getAttribute('data-' + c); }
			cta.setAttribute('data-cta', c); root.setAttribute('data-cta', c);
			if (c !== oldc && motion !== 'off') { once($(c === 'retry' || c === 'pay' ? '[data-cta-pay]' : '[data-cta-' + c + ']', cta), 'dlc-oc-swap-in', 300); }
		}
		$$('.dlc-oc-row').forEach(function (li, i) {
			var st = s.delivery && s.delivery.rows ? s.delivery.rows[i] : null;
			if (st && li.getAttribute('data-state') !== st) { li.setAttribute('data-state', st); swap($('.dlc-oc-row__chip', li), T[st] || ''); }
		});
		if (note && s.phase !== 'paid' && s.phase !== 'sending') { drop(note); }
		/* Payment confirmed live: the summary and the print sheet say « Total payé » like the receipt. */
		var tot = $('.dlc-oc-meta__total'), pp = d.querySelector('.dlc-oc-print__pill'), pt = d.querySelector('.dlc-oc-print__total span');
		var end = s.phase === 'cancelled' || s.phase === 'refunded', rtone = end ? 'neutral' : s.paid && s.phase !== 'failed' ? 'paid' : 'pending';
		if (tot && s.total_label) { tot.setAttribute('data-paid', s.paid ? '1' : '0'); $('dt', tot).textContent = s.total_label; }
		if (pp) { pp.textContent = s.pill.label; pp.setAttribute('data-tone', rtone); }
		if (pt) { pt.textContent = s.paid ? T.totalPaid : T.total; }
		d.body.className = d.body.className.replace(/\bdlc-oc-phase-\S+/, 'dlc-oc-phase-' + s.phase);
		flipPlay(flip);
	}

	/* The order left the paid phases while the page is open (cancelled, refunded): every share action,
	 * the sheet, the receipt data and the print sheet go; « Mes commandes » (or « Continuer mes
	 * achats ») takes the lead and the remaining buttons glide into place (render()'s FLIP). */
	function unshare() {
		if (root.getAttribute('data-share') !== '1') { return; }
		root.setAttribute('data-share', '0');
		var wasOpen = !!(sheet && sheet.open);
		if (wasOpen) { sheet.classList.remove('is-closing'); if (sheet.close) { sheet.close(); } else { sheet.removeAttribute('open'); } }
		if (toastEl && sheet && toastEl.parentNode === sheet) { root.appendChild(toastEl); }
		/* The sheet's opener goes away with it: focus lands on the hero action instead of the page. */
		if (wasOpen) { setTimeout(function () { var sup = $('.dlc-oc-cta [data-cta-support]'); if (sup && sup.offsetParent) { sup.focus(); } }, 0); }
		var next = $('.dlc-oc-actions [data-oc-next]');
		$$('[data-oc-share]').forEach(drop);
		if (next) { next.classList.remove('dlc-oc-btn--ghost'); next.classList.add('dlc-oc-btn--primary'); }
		[sheet, d.getElementById('dlc-oc-receipt'), d.getElementById('dlc-oc-print')].forEach(drop);
		sheet = null;
	}

	/* Totals count up once with the delivery celebration (width locked first: nothing moves). */
	function countUp() {
		if (motion !== 'full' || !w.requestAnimationFrame) { return; }
		$$('[data-oc-count]').forEach(function (el) {
			var txt = el.textContent, m = /^(-?)([0-9 ]+)(,\d\d)?( .+)$/.exec(txt);
			if (!m) { return; }
			var end = +m[2].replace(/ /g, '') + (m[3] ? +m[3].slice(1) / 100 : 0), t0 = 0, dur = 650, over = false;
			if (!(end > 0)) { return; }
			el.style.display = 'inline-block'; el.style.minWidth = el.getBoundingClientRect().width + 'px';
			function show(v) { var whole = Math.floor(v), s = String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); el.textContent = m[1] + s + (m[3] ? ',' + ('0' + Math.round((v - whole) * 100) % 100).slice(-2) : '') + m[4]; }
			/* The exact amount always comes back, even where frames are throttled (hidden tab, frame). */
			function finish() { if (!over) { over = true; el.textContent = txt; el.style.minWidth = ''; el.style.display = ''; } }
			function step2(now) {
				if (over) { return; }
				if (!t0) { t0 = now; }
				var k = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - k, 3);
				if (k < 1) { show(end * e); w.requestAnimationFrame(step2); } else { finish(); }
			}
			show(0); w.requestAnimationFrame(step2); setTimeout(finish, dur + 150);
		});
	}
	/* A7 success burst (about 1 s): emblem spring, two soft shock rings, 22 confetti pieces and 6
	 * twinkling stars, a gleam across the pill, a hop along the tracker, the totals count up. After a
	 * live switch or a reload into « delivered », and once per tab when a delivered order first opens. */
	function celebrate(hopAfter) {
		if (!C.celebrate || motion === 'off' || !hero) { return; }
		store('cheered', 1);
		/* Its node and badge animations replace the entrance ones: those rules stop matching first (the
		 * entrance has ended, so nothing moves when they do). */
		if (root.getAttribute('data-entrance') === 'full') { root.setAttribute('data-entrance', 'played'); }
		/* A live switch has just started the icon-swap pop on the same disc: the celebration replaces
		 * it from its first frame and keeps its class past its 420 ms (no restart, no snap). */
		var em = $('.dlc-oc-emblem');
		if (em) { em.classList.remove('is-swap'); }
		once(hero, 'is-celebrate', 1000);
		if (motion !== 'full' || !em) { return; }
		var box = d.createElement('span'), i, p, a, r;
		box.className = 'dlc-oc-burst'; box.setAttribute('aria-hidden', 'true');
		box.appendChild(d.createElement('b')); box.appendChild(d.createElement('b'));
		for (i = 0; i < 22; i++) {
			p = d.createElement('i'); a = i / 22 * Math.PI * 2 + Math.random() * 0.3; r = 46 + Math.random() * 46;
			p.style.setProperty('--x', Math.round(Math.cos(a) * r) + 'px'); p.style.setProperty('--y', Math.round(Math.sin(a) * r) + 'px');
			p.style.setProperty('--r', Math.round(Math.random() * 540 - 270) + 'deg'); p.style.animationDelay = Math.round(Math.random() * 90) + 'ms';
			box.appendChild(p);
		}
		for (i = 0; i < 6; i++) {
			p = d.createElement('em'); a = (i + 0.5) / 6 * Math.PI * 2; r = 34 + (i % 2) * 18;
			p.style.setProperty('--x', Math.round(Math.cos(a) * r) + 'px'); p.style.setProperty('--y', Math.round(Math.sin(a) * r) + 'px');
			p.style.animationDelay = (120 + i * 60) + 'ms';
			box.appendChild(p);
		}
		em.appendChild(box);
		setTimeout(function () { drop(box); }, 1300);
		if (track) { setTimeout(function () { once(track, 'is-cheer', 820); }, hopAfter || 0); }
		countUp();
	}
	/* After a reload the polite announcement of the old page was cut off: say it again here, and pick
	 * the motion up where the old page left it (the connector fills on from the previous step). */
	var seen = store('seen'), lag = 0;
	if (seen && seen !== phase) {
		setTimeout(function () { announce($('[data-oc="pill"]').textContent, $('[data-oc="headline"]').textContent); }, 1000);
		var to = progressNow();
		if (root.getAttribute('data-entrance') === 'none' && motion !== 'off' && fill && PROGRESS[seen] !== undefined && PROGRESS[seen] < to) {
			var from = PROGRESS[seen];
			fill.style.transition = 'none'; track.style.setProperty('--p', from); void fill.offsetWidth; fill.style.transition = '';
			lag = 160;
			setTimeout(function () {
				var dur = fillTo(to);
				popSteps(STEPS.filter(function (li) { var k = +li.getAttribute('data-i') / 3; return /\bis-(done|current|alert)\b/.test(li.className) && k > from + 0.01; }), from, to, dur);
			}, lag);
			lag += 450;
		}
		if (phase === 'delivered') { setTimeout(function () { celebrate(lag ? 0 : 200); }, Math.max(350, lag)); }
	} else if (phase === 'delivered' && root.getAttribute('data-entrance') === 'full' && !store('cheered')) {
		/* A delivered order opened for the first time in this tab: the page assembles, then celebrates. */
		setTimeout(function () { celebrate(0); }, 850);
	}
	if (C.terminal) { ['seen', 'reloads', 'receipt'].forEach(function (k) { store(k, null); }); } else { store('seen', phase); if (store('target') === fp) { store('reloads', null); } }
	store('target', null);

	d.addEventListener('visibilitychange', function () {
		pause();
		if (stopped) { return; }
		if (d.hidden) { setLive('paused', T.paused); } else if (Date.now() - lastOk > 3000) { schedule(0.4); } else { setLive('live', T.live, ago()); tick(); }
	});
	w.addEventListener('offline', function () { if (!stopped) { clearTimeout(timer); setLive('offline', T.offline); } });
	w.addEventListener('online', function () { if (!stopped) { schedule(0.4); } });
	w.addEventListener('pageshow', function (e) { if (e.persisted && !stopped) { schedule(0); } });
	if (refresh) { refresh.addEventListener('click', function () { w.location.reload(); }); }
	if (!stopped) { setLive('live', T.live, T.now); tick(); timer = setTimeout(poll, 2500); }
	/* 9.4.4: if a page swap ever replaces this page (the engine now leaves it with a full
	 * load), polling stops and the receipt sheet printed after the footer goes with it. */
	d.addEventListener('delicat:navigated', function () {
		if (root.isConnected) { return; }
		stopped = true; clearTimeout(timer); clearTimeout(tickT);
		['dlc-oc-print', 'dlc-oc-receipt'].forEach(function (id) { drop(d.getElementById(id)); });
	});

	/* Share sheet (paid phases only): opens at once; the receipt module draws into it when loaded. */
	var opener = null, loading = false, waiting = [];
	function receipt(cb) {
		if (w.DlcOcReceipt) { if (cb) { cb(w.DlcOcReceipt); } return; }
		if (cb) { waiting.push(cb); }
		if (loading || !C.receiptJs) { return; }
		loading = true;
		var sc = d.createElement('script');
		sc.src = C.receiptJs; sc.async = true;
		sc.onload = function () { var q = waiting; waiting = []; q.forEach(function (f) { if (w.DlcOcReceipt) { f(w.DlcOcReceipt); } }); };
		sc.onerror = function () { loading = false; waiting = []; toast(T.shareFail); };
		d.head.appendChild(sc);
	}
	function closeSheet() {
		if (!sheet || !sheet.open) { return; }
		var dlg = sheet, done = function () { dlg.classList.remove('is-closing'); if (dlg.close) { dlg.close(); } else { dlg.removeAttribute('open'); dlg.dispatchEvent(new Event('close')); } };
		if (motion === 'off') { done(); return; }
		dlg.classList.add('is-closing'); setTimeout(done, 200);
	}
	if (sheet) {
		$$('[data-oc-share]').forEach(function (b) {
			['pointerdown', 'focus', 'mouseenter'].forEach(function (ev) { b.addEventListener(ev, function () { receipt(null); }, { once: true, passive: true }); });
			b.addEventListener('click', function () {
				if (!sheet) { return; }
				opener = b; sheet.classList.remove('is-closing');
				/* A modal dialog makes the rest of the page inert: the toast lives inside it meanwhile. */
				if (toastEl) { sheet.appendChild(toastEl); }
				if (sheet.showModal) { sheet.showModal(); } else { sheet.setAttribute('open', ''); }
				receipt(function (r) { r.draw(); });
			});
		});
		sheet.addEventListener('close', function () { if (toastEl) { root.appendChild(toastEl); } if (opener && opener.parentNode) { opener.focus(); } });
		sheet.addEventListener('cancel', function (e) { e.preventDefault(); closeSheet(); });
		sheet.addEventListener('click', function (e) {
			if (e.target === sheet || e.target.closest('[data-oc-close]')) { closeSheet(); return; }
			var b = e.target.closest('[data-oc-act]'), a = b ? b.getAttribute('data-oc-act') : '';
			if (a === 'print') { closeSheet(); setTimeout(function () { w.print(); }, motion === 'off' ? 0 : 230); } else if (a) { receipt(function (r) { r.act(a); }); }
		});
	}
	/* A theme may wrap the footer: the print sheet must be a direct child of <body> to print alone. */
	w.addEventListener('beforeprint', function () { var p = d.getElementById('dlc-oc-print'); if (p && p.parentNode !== d.body) { d.body.appendChild(p); } });
}());
