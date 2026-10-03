/**
 * Like button ([data-delicat-like]). One delegated handler for every button,
 * wherever the card was built. Spring fill + particle burst on a fixed layer,
 * optimistic state for signed-in customers with a coalesced sync and a
 * visible rollback, a first-party cookie for guests. Port of heart.js (pro.33).
 */
import { cookie, device, doc, emit, html, on, qsa, win } from '../core/dom.js';
import { ajax } from '../core/net.js';

const SELECTOR = '[data-delicat-like]';
const GUEST_MAX = 60;
const SYNC_DELAY = 280;

export default function mount({ root, signal, config }) {
	const cfg = (win.DelicaBuilderV9 && win.DelicaBuilderV9.heart) || config.heart || {};
	if (!cfg.enabled) return;
	const COOKIE = /^[a-z0-9_]+$/i.test(String(cfg.cookie || '')) ? String(cfg.cookie) : 'dbv9_fav';
	const canAnimate = typeof Element !== 'undefined' && typeof Element.prototype.animate === 'function';
	const labels = { add: String(cfg.addLabel || 'Ajouter aux favoris'), remove: String(cfg.removeLabel || 'Retirer des favoris') };
	const motionLevel = () => { if (!canAnimate || device.reducedMotion) return 0; return html.classList.contains('delicat-low-power') ? 1 : 2; };

	const readGuest = () => {
		if (!cfg.guest) return new Set();
		const raw = cookie.read(COOKIE);
		return new Set((raw ? raw.split(',') : []).map(Number).filter((id) => Number.isInteger(id) && id > 0).slice(-GUEST_MAX));
	};
	let guestFavorites = readGuest();
	const writeGuest = () => cookie.write(COOKIE, [...guestFavorites].slice(-GUEST_MAX).join(','), 31536000);

	const confirmed = new Map();
	const wanted = new Map();
	const inflight = new Set();
	const timers = new Map();
	const running = new WeakMap();

	const isLiked = (button) => button.getAttribute('aria-pressed') === 'true';
	const setPressed = (button, liked) => { button.setAttribute('aria-pressed', liked ? 'true' : 'false'); button.setAttribute('aria-label', liked ? labels.remove : labels.add); };
	const buttonsFor = (productId) => qsa(`${SELECTOR}[data-product-id="${productId}"]`);
	const announce = (productId, favorite) => emit('delicat:favorite-change', { productId, favorite });

	const remember = (element, animation) => { if (!animation) return; const list = running.get(element) || []; list.push(animation); running.set(element, list); };
	const settle = (element) => { for (const animation of running.get(element) || []) { try { animation.cancel(); } catch (_) {} } running.delete(element); };
	const spring = (from, to, stiffness, damping, velocity = 0) => {
		const dt = 1 / 120; const values = []; let x = from; let v = velocity;
		for (let i = 0; i < 180; i += 1) { values.push(x); v += (-stiffness * (x - to) - damping * v) * dt; x += v * dt; if (i > 10 && Math.abs(x - to) < 0.002 && Math.abs(v) < 0.05) break; }
		values.push(to);
		const frames = values.filter((_, i) => i % 2 === 0 || i === values.length - 1);
		return { keyframes: frames.map((s) => ({ transform: `scale(${s.toFixed(4)})` })), duration: Math.round(values.length * dt * 1000) };
	};
	const parts = (button) => ({ chip: button.querySelector('.delicat-like__chip') || button, fill: button.querySelector('.delicat-like__fill') });

	const burst = (button, level) => {
		const { chip, fill } = parts(button);
		const rect = chip.getBoundingClientRect();
		if (!rect.width || !doc.body) return;
		const layer = doc.createElement('div');
		layer.className = 'delicat-like-burst';
		layer.setAttribute('aria-hidden', 'true');
		layer.style.left = `${rect.left + rect.width / 2}px`;
		layer.style.top = `${rect.top + rect.height / 2}px`;
		layer.style.color = fill ? getComputedStyle(fill).color : '#ff4d91';
		const radius = rect.width / 2;
		const animations = [];
		if (level === 2) {
			const ring = doc.createElement('span');
			ring.className = 'delicat-like-burst__ring';
			ring.style.width = `${rect.width}px`; ring.style.height = `${rect.height}px`; ring.style.margin = `${-radius}px 0 0 ${-radius}px`;
			layer.appendChild(ring);
			animations.push(ring.animate([{ transform: 'scale(1)', opacity: 0.75 }, { transform: 'scale(1.8)', opacity: 0 }], { duration: 440, easing: 'cubic-bezier(.2,.75,.3,1)' }));
		}
		const count = level === 2 ? 7 : 5;
		const turn = Math.random() * Math.PI * 2;
		for (const band of (level === 2 ? [0, 1] : [0])) {
			for (let i = 0; i < count; i += 1) {
				const angle = turn + (Math.PI * 2 * (i + band * 0.5)) / count + (Math.random() - 0.5) * 0.22;
				const size = band === 0 ? 5 : 3.5;
				const startDistance = radius + 1;
				const endDistance = radius + (band === 0 ? 16 : 10) + Math.random() * 4;
				const dot = doc.createElement('span');
				dot.className = 'delicat-like-burst__dot';
				dot.style.width = `${size}px`; dot.style.height = `${size}px`; dot.style.margin = `${-size / 2}px 0 0 ${-size / 2}px`;
				layer.appendChild(dot);
				const cos = Math.cos(angle), sin = Math.sin(angle), peak = band === 0 ? 1 : 0.8;
				animations.push(dot.animate([
					{ transform: `translate(${cos * startDistance}px, ${sin * startDistance}px) scale(.4)`, opacity: 0 },
					{ transform: `translate(${cos * (startDistance + 4)}px, ${sin * (startDistance + 4)}px) scale(1)`, opacity: peak, offset: 0.18 },
					{ transform: `translate(${cos * (endDistance - 3)}px, ${sin * (endDistance - 3)}px) scale(.75)`, opacity: peak, offset: 0.62 },
					{ transform: `translate(${cos * endDistance}px, ${sin * endDistance}px) scale(.2)`, opacity: 0 },
				], { duration: 560 + Math.random() * 100, delay: band === 0 ? 50 : 95, easing: 'cubic-bezier(.22,.7,.35,1)', fill: 'backwards' }));
			}
		}
		doc.body.appendChild(layer);
		let removed = false;
		const remove = () => { if (removed) return; removed = true; layer.remove(); };
		Promise.all(animations.map((animation) => animation.finished)).then(remove, remove);
		win.setTimeout(remove, 1200);
	};

	const animateLike = (button, level) => {
		const { chip, fill } = parts(button);
		settle(button);
		if (!canAnimate || !fill) return;
		if (level === 0) { remember(button, fill.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 180, easing: 'linear' })); return; }
		const pop = spring(0, 1, 340, 14);
		remember(button, fill.animate(pop.keyframes, { duration: pop.duration, easing: 'linear' }));
		remember(button, fill.animate([{ opacity: 0 }, { opacity: 1, offset: 0.12 }, { opacity: 1 }], { duration: pop.duration, easing: 'linear' }));
		const bump = spring(0.86, 1, 520, 17);
		remember(button, chip.animate(bump.keyframes, { duration: bump.duration, easing: 'linear' }));
		burst(button, level);
	};
	const animateUnlike = (button, level) => {
		const { chip, fill } = parts(button);
		settle(button);
		if (!canAnimate || !fill) return;
		if (level === 0) { remember(button, fill.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: 'linear' })); return; }
		remember(button, fill.animate([{ transform: 'scale(1)', opacity: 1 }, { transform: 'scale(1.1)', opacity: 1, offset: 0.22 }, { transform: 'scale(.2)', opacity: 0 }], { duration: 260, easing: 'cubic-bezier(.45,0,.7,.4)' }));
		const dip = spring(0.9, 1, 460, 20);
		remember(button, chip.animate(dip.keyframes, { duration: dip.duration, easing: 'linear' }));
	};
	const shake = (button) => {
		if (motionLevel() === 0) return;
		const { chip } = parts(button);
		settle(chip);
		remember(chip, chip.animate([
			{ transform: 'translateX(0) rotate(0)' }, { transform: 'translateX(-4px) rotate(-7deg)' }, { transform: 'translateX(4px) rotate(6deg)' },
			{ transform: 'translateX(-3px) rotate(-4deg)' }, { transform: 'translateX(2px) rotate(2deg)' }, { transform: 'translateX(0) rotate(0)' },
		], { duration: 440, easing: 'ease-out' }));
	};
	const haptic = () => { try { if (motionLevel() > 0 && typeof navigator.vibrate === 'function') navigator.vibrate(10); } catch (_) {} };
	const paint = (productId, liked, except = null) => { for (const button of buttonsFor(productId)) { if (button === except || isLiked(button) === liked) continue; settle(button); setPressed(button, liked); } };

	const post = async (productId, state) => {
		try {
			const data = await ajax(String(cfg.action || 'delicat_builder_v9_favorite'), { nonce: String(cfg.nonce), product_id: String(productId), state: state ? '1' : '0' }, { url: cfg.ajaxUrl, timeout: 12000 });
			return !!(data && data.favorite);
		} catch (error) {
			const wrapped = new Error('favorite_failed');
			wrapped.status = error && error.status;
			throw wrapped;
		}
	};
	const promptSignIn = (productId) => {
		const handled = !emit('delicat:favorite-signin', { productId }, doc, true);
		if (handled) return;
		const trigger = doc.querySelector('[data-delicat-login], a[href$="#delicat-login"]');
		if (trigger) { trigger.click(); return; }
		location.hash = 'delicat-login';
	};
	const rollBack = (productId, liked, status) => {
		const level = motionLevel();
		for (const button of buttonsFor(productId)) {
			if (isLiked(button) !== liked) {
				setPressed(button, liked);
				if (liked) { const { fill } = parts(button); settle(button); if (canAnimate && fill) remember(button, fill.animate([{ opacity: 0, transform: 'scale(.6)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 220, easing: 'ease-out' })); }
				else animateUnlike(button, level);
			}
			shake(button);
		}
		announce(productId, liked);
		if (status === 401) promptSignIn(productId);
	};
	const schedule = (productId, delay = SYNC_DELAY) => {
		win.clearTimeout(timers.get(productId));
		timers.set(productId, win.setTimeout(() => { timers.delete(productId); sync(productId); }, delay));
	};
	const sync = async (productId) => {
		if (inflight.has(productId)) return;
		const target = wanted.get(productId);
		if (typeof target !== 'boolean' || target === confirmed.get(productId)) return;
		inflight.add(productId);
		try { confirmed.set(productId, await post(productId, target)); }
		catch (error) { const held = confirmed.get(productId) === true; wanted.set(productId, held); rollBack(productId, held, Number(error && error.status || 0)); }
		finally { inflight.delete(productId); if (wanted.get(productId) !== confirmed.get(productId)) schedule(productId, 0); }
	};

	const release = () => { for (const button of qsa(`${SELECTOR}.is-pressed`)) button.classList.remove('is-pressed'); };
	on(doc, 'pointerdown', (event) => { const button = event.target instanceof Element ? event.target.closest(SELECTOR) : null; if (button && (event.button === 0 || event.pointerType !== 'mouse')) button.classList.add('is-pressed'); }, { passive: true, signal });
	for (const type of ['pointerup', 'pointercancel', 'dragstart']) on(doc, type, release, { passive: true, signal });
	on(doc, 'scroll', release, { passive: true, capture: true, signal });
	on(win, 'blur', release, { signal });

	on(doc, 'click', (event) => {
		const button = event.target instanceof Element ? event.target.closest(SELECTOR) : null;
		if (!button) return;
		event.preventDefault();
		event.stopPropagation();
		release();
		const productId = Number(button.dataset.productId || 0);
		if (!Number.isInteger(productId) || productId <= 0) return;
		if ((!cfg.loggedIn && !cfg.guest) || (cfg.loggedIn && (!cfg.ajaxUrl || !cfg.nonce))) { shake(button); promptSignIn(productId); return; }
		const before = isLiked(button);
		const next = !before;
		const level = motionLevel();
		setPressed(button, next);
		paint(productId, next, button);
		if (next) { animateLike(button, level); haptic(); } else animateUnlike(button, level);
		announce(productId, next);
		if (!cfg.loggedIn) {
			guestFavorites.delete(productId);
			if (next) guestFavorites.add(productId);
			while (guestFavorites.size > GUEST_MAX) { const oldest = guestFavorites.values().next().value; guestFavorites.delete(oldest); paint(oldest, false); announce(oldest, false); }
			writeGuest();
			return;
		}
		if (!confirmed.has(productId)) confirmed.set(productId, before);
		wanted.set(productId, next);
		schedule(productId);
	}, { capture: true, signal });

	const hydrate = (scope = doc) => {
		for (const button of qsa(SELECTOR, scope && scope.querySelectorAll ? scope : doc)) {
			const id = Number(button.dataset.productId || 0);
			if (id <= 0) continue;
			let liked = null;
			if (!cfg.loggedIn) { if (cfg.guest) liked = guestFavorites.has(id); }
			else if (wanted.has(id)) liked = wanted.get(id);
			if (liked !== null && isLiked(button) !== liked) setPressed(button, liked);
		}
	};
	const refreshGuests = () => { if (cfg.loggedIn || !cfg.guest) return; guestFavorites = readGuest(); hydrate(doc); };
	on(doc, 'delicat:carousel-materialized', (event) => hydrate(event.target instanceof Element ? event.target : doc), { signal });
	on(doc, 'visibilitychange', () => { if (!doc.hidden) refreshGuests(); }, { signal });
	on(win, 'pageshow', (event) => { if (event.persisted) refreshGuests(); }, { signal });
	win.DelicaBuilderV9Heart = { hydrate, hydrateGuests: hydrate };
	hydrate(root);
}
