/**
 * Live selling toast: a public feed polled on a slow timer (visible tab only,
 * never over money pages), one toast per new sale, last-seen id in a cookie.
 */
import { cookie, device, doc, emit, on, qsa, raf, win } from '../core/dom.js';

export default function mount({ signal, config, engine }) {
	const cfg = win.DelicatLiveSelling || config.liveSelling;
	if (!cfg || !cfg.endpoint) return;
	const mobile = () => (win.matchMedia ? win.matchMedia('(max-width:899px)').matches : innerWidth < 900);
	if ((mobile() && cfg.showMobile === false) || (!mobile() && cfg.showDesktop === false)) return;
	const KEY = 'dbv9_live_sale_seen_v1';
	let timer = 0, interval = 0, busy = false, toast = null, hideTimer = 0, stylePromise = null, started = false;
	const lower = (v) => String(v == null ? '' : v).toLowerCase();
	const listOf = (v) => (Array.isArray(v) ? v.map(lower).filter(Boolean) : []);
	const blockedRoutes = listOf(cfg.blockedRoutes), blockedPaths = listOf(cfg.blockedPaths).filter((p) => p !== '/'), allowedRoutes = Array.isArray(cfg.allowedRoutes) ? listOf(cfg.allowedRoutes) : null;
	const sitePath = (href) => { let p = '/'; try { p = lower(decodeURIComponent(new URL(String(href || ''), location.href).pathname)); } catch (_) { return '/'; } const base = lower(cfg.basePath).replace(/\/+$/, ''); if (base && p.indexOf(base + '/') === 0) p = p.slice(base.length); p = p.replace(/^\/+|\/+$/g, ''); return p ? '/' + p + '/' : '/'; };
	const pathAllowed = (href) => { const p = sitePath(href); return !blockedPaths.some((b) => p.indexOf(b) === 0) && !(engine.nav && engine.nav.isProductUrl && false); };
	const routeAllowed = (route) => { const r = lower(route); if (!r) return true; if (blockedRoutes.indexOf(r) !== -1) return false; return !allowedRoutes || allowedRoutes.indexOf(r) !== -1; };
	let paused = !pathAllowed(location.href);
	const seen = () => cookie.read(KEY);
	const remember = (id) => cookie.write(KEY, String(id || ''), 2592000);
	const sameOrigin = (value) => { try { const u = new URL(String(value || ''), location.href); return u.origin === location.origin ? u.href : '#'; } catch (_) { return '#'; } };
	const relative = (ts) => { const sec = Math.max(0, Math.floor(Date.now() / 1000 - (Number(ts) || 0))); if (sec < 60) return "À l'instant"; const m = Math.floor(sec / 60); if (m < 60) return `Il y a ${m} min`; const h = Math.floor(m / 60); if (h < 24) return `Il y a ${h} h`; const d = Math.floor(h / 24); if (d < 2) return 'Hier'; return 'Récemment'; };
	const message = (product) => { const tpl = String(cfg.messageTemplate || 'Un client vient d’acheter {product}'); return tpl.includes('{product}') ? tpl.replace('{product}', String(product || '')) : `Un client vient d’acheter ${String(product || '')}`; };
	const notify = (visible) => { doc.body.classList.toggle('dbv9-live-sale-visible', !!visible); emit('delicat:live-sale', { visible: !!visible }); };
	const ensureStyle = () => {
		if (stylePromise) return stylePromise;
		stylePromise = new Promise((resolve) => {
			const href = String(cfg.styleUrl || '');
			if (!href) return resolve(true);
			const existing = doc.querySelector('link[data-dbv9-live-selling-style]');
			if (existing && existing.sheet) return resolve(true);
			const link = doc.createElement('link');
			link.rel = 'stylesheet'; link.href = href; link.dataset.dbv9LiveSellingStyle = '1';
			let done = false;
			const finish = (ok) => { if (done) return; done = true; win.clearTimeout(timeout); link.onload = null; link.onerror = null; if (!ok) link.remove(); resolve(ok); };
			const timeout = win.setTimeout(() => finish(false), 4000);
			link.onload = () => finish(true); link.onerror = () => finish(false);
			doc.head.appendChild(link);
		}).then((ok) => { if (!ok) stylePromise = null; return ok; });
		return stylePromise;
	};
	const ensureToast = () => {
		if (toast && toast.isConnected) return toast;
		toast = doc.createElement('aside');
		toast.className = 'dbv9-live-sale ' + (cfg.position === 'bottom_right' ? 'dbv9-live-sale--bottom-right' : 'dbv9-live-sale--bottom-left');
		toast.hidden = true;
		toast.setAttribute('aria-live', 'polite');
		toast.innerHTML = '<button class="dbv9-live-sale__close" type="button" aria-label="Fermer">×</button><a class="dbv9-live-sale__link" href="#"><span class="dbv9-live-sale__media"><img class="dbv9-live-sale__image" width="48" height="48" alt="" loading="lazy" decoding="async"></span><span class="dbv9-live-sale__copy"><small></small><b></b><em></em></span><span class="dbv9-live-sale__arrow" aria-hidden="true">→</span></a>';
		doc.body.appendChild(toast);
		const close = toast.querySelector('.dbv9-live-sale__close');
		close.hidden = cfg.showClose === false;
		on(close, 'click', () => hide(true), { signal });
		return toast;
	};
	function hide(user = false) {
		if (hideTimer) { win.clearTimeout(hideTimer); hideTimer = 0; }
		if (!toast || !toast.isConnected) { notify(false); return; }
		const t = toast;
		t.classList.remove('is-visible');
		win.setTimeout(() => { if (!t.classList.contains('is-visible')) { t.hidden = true; notify(false); } }, 180);
		if (user) { t.dataset.dismissed = '1'; stop(); }
	}
	async function show(item) {
		if (!item || !item.id || !item.title) return;
		if (!await ensureStyle() || doc.hidden || paused) return;
		const t = ensureToast();
		if (t.dataset.dismissed === '1') return;
		const a = t.querySelector('.dbv9-live-sale__link'), img = t.querySelector('.dbv9-live-sale__image'), small = t.querySelector('small'), b = t.querySelector('b'), em = t.querySelector('em');
		a.href = sameOrigin(item.url);
		small.textContent = String(cfg.label || '🔥 Vente en direct');
		b.textContent = message(item.title);
		em.textContent = relative(item.created);
		if (item.image) { img.src = item.image; img.closest('.dbv9-live-sale__media').hidden = false; } else { img.removeAttribute('src'); img.closest('.dbv9-live-sale__media').hidden = true; }
		t.hidden = false;
		raf(() => { t.classList.add('is-visible'); notify(true); });
		if (hideTimer) win.clearTimeout(hideTimer);
		hideTimer = win.setTimeout(() => hide(false), Math.min(15000, Math.max(3000, Number(cfg.displayDuration) || 6200)));
		remember(item.id);
	}
	async function poll() {
		if (paused || busy || doc.hidden || navigator.onLine === false || (toast && toast.dataset.dismissed === '1')) return;
		busy = true;
		let to = 0;
		try {
			const u = new URL(cfg.endpoint, location.href);
			const cursor = seen();
			if (cursor) u.searchParams.set('after', cursor);
			const ctl = new AbortController();
			to = win.setTimeout(() => ctl.abort(), 5000);
			const r = await fetch(u.href, { credentials: 'omit', headers: { Accept: 'application/json' }, signal: ctl.signal });
			if (!r.ok) return;
			const data = await r.json();
			const items = Array.isArray(data.items) ? data.items : [];
			if (items.length) await show(items[0]);
		} catch (_) {} finally { if (to) win.clearTimeout(to); busy = false; }
	}
	function stop() { if (timer) { win.clearTimeout(timer); timer = 0; } if (interval) { win.clearInterval(interval); interval = 0; } }
	function schedule() {
		if (paused || timer || interval) return;
		const delay = Math.min(60000, Math.max(4000, Number(cfg.initialDelay) || 9000));
		const every = Math.min(180000, Math.max(30000, Number(cfg.pollInterval) || 45000));
		timer = win.setTimeout(() => { timer = 0; started = true; poll(); interval = win.setInterval(poll, every); }, delay);
	}
	const begin = () => { if (doc.prerendering) doc.addEventListener('prerenderingchange', schedule, { once: true }); else schedule(); };
	begin();
	on(doc, 'visibilitychange', () => { if (!doc.hidden && started && !paused && (!toast || toast.dataset.dismissed !== '1')) poll(); }, { passive: true, signal });
	on(doc, 'delicat:navigated', (event) => {
		const d = (event && event.detail) || {};
		if (routeAllowed(d.route) && pathAllowed(d.url || location.href)) { if (paused) { paused = false; if (!toast || toast.dataset.dismissed !== '1') schedule(); } }
		else { paused = true; stop(); hide(false); }
	}, { signal });
	signal.addEventListener('abort', () => { stop(); if (toast) toast.remove(); }, { once: true });
}
