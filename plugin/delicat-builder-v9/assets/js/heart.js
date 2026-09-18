/*
 * Delicat Builder V9 — like button (pro.33).
 *
 * Rebuilt from scratch. One delegated handler for every
 * [data-delicat-like] button, wherever the card was built.
 *
 *  - The tap is answered on the frame it happens: the chip presses in on
 *    pointerdown, and on release the heart fills with a spring (real
 *    stiffness/damping, sampled into Web Animations keyframes) while a ring
 *    and a double ring of particles burst out. The burst is drawn on a fixed
 *    layer on <body>, so the card's rounded clipping can never cut it off.
 *  - Unliking deflates the heart instead of bursting.
 *  - Signed-in customers: the change is shown immediately and synced in the
 *    background. Rapid taps are coalesced into one request per settle, and
 *    if the server refuses, the button returns to what the server holds and
 *    shakes, so a failed save is never silent.
 *  - Guests: a first-party cookie (RC55 storage rule), no request at all.
 *  - prefers-reduced-motion gets a plain crossfade; Lite Mode
 *    (html.delicat-low-power) keeps the spring and a lighter burst.
 */
(() => {
	'use strict';

	const config = window.DelicaBuilderV9?.heart || {};
	if (!config.enabled || window.DelicaBuilderV9Heart) return;

	const SELECTOR = '[data-delicat-like]';
	const COOKIE = /^[a-z0-9_]+$/i.test(String(config.cookie || '')) ? String(config.cookie) : 'dbv9_fav';
	const GUEST_MAX = 60;
	const SYNC_DELAY = 280;
	const root = document.documentElement;
	const reducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
	const canAnimate = typeof Element !== 'undefined' && typeof Element.prototype.animate === 'function';
	const labels = {
		add: String(config.addLabel || 'Ajouter aux favoris'),
		remove: String(config.removeLabel || 'Retirer des favoris')
	};

	/* 0 = no movement, 1 = Lite Mode, 2 = full. Read on every tap: both
	   the media query and the Lite Mode class can change during a visit. */
	const motionLevel = () => {
		if (!canAnimate || reducedMotion?.matches) return 0;
		return root.classList.contains('delicat-low-power') ? 1 : 2;
	};

	/* ------------------------------------------------------------------ */
	/* Guest storage                                                       */
	/* ------------------------------------------------------------------ */

	const readCookie = () => {
		const parts = document.cookie ? document.cookie.split(';') : [];
		for (const part of parts) {
			const pair = part.replace(/^\s+/, '');
			if (pair.indexOf(COOKIE + '=') === 0) {
				try { return decodeURIComponent(pair.slice(COOKIE.length + 1)); } catch (_) { return ''; }
			}
		}
		return '';
	};

	const readGuest = () => {
		if (!config.guest) return new Set();
		const raw = readCookie();
		return new Set(
			(raw ? raw.split(',') : [])
				.map(Number)
				.filter((id) => Number.isInteger(id) && id > 0)
				.slice(-GUEST_MAX)
		);
	};

	let guestFavorites = readGuest();

	const writeGuest = () => {
		try {
			let cookie = COOKIE + '=' + encodeURIComponent([...guestFavorites].slice(-GUEST_MAX).join(','))
				+ '; path=/; SameSite=Lax; max-age=31536000';
			if (location.protocol === 'https:') cookie += '; Secure';
			document.cookie = cookie;
		} catch (_) {}
	};

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	const confirmed = new Map(); /* productId -> what the server holds */
	const wanted = new Map();    /* productId -> what the shopper last asked for */
	const inflight = new Set();
	const timers = new Map();

	const isLiked = (button) => button.getAttribute('aria-pressed') === 'true';

	const setPressed = (button, liked) => {
		button.setAttribute('aria-pressed', liked ? 'true' : 'false');
		button.setAttribute('aria-label', liked ? labels.remove : labels.add);
	};

	const buttonsFor = (productId) =>
		Array.from(document.querySelectorAll(`${SELECTOR}[data-product-id="${productId}"]`));

	const announce = (productId, favorite) => {
		try {
			document.dispatchEvent(new CustomEvent('delicat:favorite-change', { detail: { productId, favorite } }));
		} catch (_) {}
	};

	/* ------------------------------------------------------------------ */
	/* Motion                                                              */
	/* ------------------------------------------------------------------ */

	const running = new WeakMap();

	const remember = (element, animation) => {
		if (!animation) return;
		const list = running.get(element) || [];
		list.push(animation);
		running.set(element, list);
	};

	const settle = (element) => {
		for (const animation of running.get(element) || []) {
			try { animation.cancel(); } catch (_) {}
		}
		running.delete(element);
	};

	/* A damped spring integrated at 120 Hz. Evenly spaced samples map
	   straight onto evenly spaced keyframes with linear easing, which is
	   what lets a tap overshoot and settle like a physical object on every
	   browser that has element.animate(), without CSS linear() support. */
	const spring = (from, to, stiffness, damping, velocity = 0) => {
		const dt = 1 / 120;
		const values = [];
		let x = from;
		let v = velocity;
		for (let i = 0; i < 180; i += 1) {
			values.push(x);
			v += (-stiffness * (x - to) - damping * v) * dt;
			x += v * dt;
			if (i > 10 && Math.abs(x - to) < 0.002 && Math.abs(v) < 0.05) break;
		}
		values.push(to);
		const frames = values.filter((_, i) => i % 2 === 0 || i === values.length - 1);
		return {
			keyframes: frames.map((s) => ({ transform: `scale(${s.toFixed(4)})` })),
			duration: Math.round(values.length * dt * 1000)
		};
	};

	const parts = (button) => ({
		chip: button.querySelector('.delicat-like__chip') || button,
		fill: button.querySelector('.delicat-like__fill')
	});

	const burst = (button, level) => {
		const { chip, fill } = parts(button);
		const rect = chip.getBoundingClientRect();
		if (!rect.width || !document.body) return;

		const layer = document.createElement('div');
		layer.className = 'delicat-like-burst';
		layer.setAttribute('aria-hidden', 'true');
		layer.style.left = `${rect.left + rect.width / 2}px`;
		layer.style.top = `${rect.top + rect.height / 2}px`;
		layer.style.color = fill ? getComputedStyle(fill).color : '#ff4d91';

		const radius = rect.width / 2;
		const animations = [];

		if (level === 2) {
			const ring = document.createElement('span');
			ring.className = 'delicat-like-burst__ring';
			ring.style.width = `${rect.width}px`;
			ring.style.height = `${rect.height}px`;
			ring.style.margin = `${-radius}px 0 0 ${-radius}px`;
			layer.appendChild(ring);
			/* Starts on the chip's edge, never over the heart itself. */
			animations.push(ring.animate([
				{ transform: 'scale(1)', opacity: 0.75 },
				{ transform: 'scale(1.8)', opacity: 0 }
			], { duration: 440, easing: 'cubic-bezier(.2,.75,.3,1)' }));
		}

		const count = level === 2 ? 7 : 5;
		const turn = Math.random() * Math.PI * 2;
		const rings = level === 2 ? [0, 1] : [0];

		for (const band of rings) {
			for (let i = 0; i < count; i += 1) {
				const angle = turn + (Math.PI * 2 * (i + band * 0.5)) / count + (Math.random() - 0.5) * 0.22;
				const size = band === 0 ? 5 : 3.5;
				const startDistance = radius + 1;
				const endDistance = radius + (band === 0 ? 16 : 10) + Math.random() * 4;
				const dot = document.createElement('span');
				dot.className = 'delicat-like-burst__dot';
				dot.style.width = `${size}px`;
				dot.style.height = `${size}px`;
				dot.style.margin = `${-size / 2}px 0 0 ${-size / 2}px`;
				layer.appendChild(dot);

				const cos = Math.cos(angle);
				const sin = Math.sin(angle);
				const peak = band === 0 ? 1 : 0.8;
				animations.push(dot.animate([
					{ transform: `translate(${cos * startDistance}px, ${sin * startDistance}px) scale(.4)`, opacity: 0 },
					{ transform: `translate(${cos * (startDistance + 4)}px, ${sin * (startDistance + 4)}px) scale(1)`, opacity: peak, offset: 0.18 },
					{ transform: `translate(${cos * (endDistance - 3)}px, ${sin * (endDistance - 3)}px) scale(.75)`, opacity: peak, offset: 0.62 },
					{ transform: `translate(${cos * endDistance}px, ${sin * endDistance}px) scale(.2)`, opacity: 0 }
				], {
					duration: 560 + Math.random() * 100,
					delay: band === 0 ? 50 : 95,
					easing: 'cubic-bezier(.22,.7,.35,1)',
					fill: 'backwards'
				}));
			}
		}

		document.body.appendChild(layer);
		let removed = false;
		const remove = () => {
			if (removed) return;
			removed = true;
			layer.remove();
		};
		Promise.all(animations.map((animation) => animation.finished)).then(remove, remove);
		window.setTimeout(remove, 1200);
	};

	const animateLike = (button, level) => {
		const { chip, fill } = parts(button);
		settle(button);
		if (!canAnimate || !fill) return;

		if (level === 0) {
			remember(button, fill.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 180, easing: 'linear' }));
			return;
		}

		const pop = spring(0, 1, 340, 14);
		remember(button, fill.animate(pop.keyframes, { duration: pop.duration, easing: 'linear' }));
		remember(button, fill.animate(
			[{ opacity: 0 }, { opacity: 1, offset: 0.12 }, { opacity: 1 }],
			{ duration: pop.duration, easing: 'linear' }
		));

		const bump = spring(0.86, 1, 520, 17);
		remember(button, chip.animate(bump.keyframes, { duration: bump.duration, easing: 'linear' }));

		burst(button, level);
	};

	const animateUnlike = (button, level) => {
		const { chip, fill } = parts(button);
		settle(button);
		if (!canAnimate || !fill) return;

		if (level === 0) {
			remember(button, fill.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: 'linear' }));
			return;
		}

		remember(button, fill.animate([
			{ transform: 'scale(1)', opacity: 1 },
			{ transform: 'scale(1.1)', opacity: 1, offset: 0.22 },
			{ transform: 'scale(.2)', opacity: 0 }
		], { duration: 260, easing: 'cubic-bezier(.45,0,.7,.4)' }));

		const dip = spring(0.9, 1, 460, 20);
		remember(button, chip.animate(dip.keyframes, { duration: dip.duration, easing: 'linear' }));
	};

	const shake = (button) => {
		if (motionLevel() === 0) return;
		const { chip } = parts(button);
		settle(chip);
		remember(chip, chip.animate([
			{ transform: 'translateX(0) rotate(0)' },
			{ transform: 'translateX(-4px) rotate(-7deg)' },
			{ transform: 'translateX(4px) rotate(6deg)' },
			{ transform: 'translateX(-3px) rotate(-4deg)' },
			{ transform: 'translateX(2px) rotate(2deg)' },
			{ transform: 'translateX(0) rotate(0)' }
		], { duration: 440, easing: 'ease-out' }));
	};

	const haptic = () => {
		try {
			if (motionLevel() > 0 && typeof navigator.vibrate === 'function') navigator.vibrate(10);
		} catch (_) {}
	};

	/* Apply a state to every copy of a product's button. The tapped one is
	   animated by the caller; copies in other rails switch quietly. */
	const paint = (productId, liked, except = null) => {
		for (const button of buttonsFor(productId)) {
			if (button === except || isLiked(button) === liked) continue;
			settle(button);
			setPressed(button, liked);
		}
	};

	/* ------------------------------------------------------------------ */
	/* Server sync (signed-in customers)                                   */
	/* ------------------------------------------------------------------ */

	const post = async (productId, state) => {
		const body = new URLSearchParams();
		body.set('action', String(config.action || 'delicat_builder_v9_favorite'));
		body.set('nonce', String(config.nonce));
		body.set('product_id', String(productId));
		body.set('state', state ? '1' : '0');

		const controller = typeof AbortController === 'function' ? new AbortController() : null;
		const timeout = controller ? window.setTimeout(() => controller.abort(), 12000) : 0;
		try {
			const response = await fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
				body: body.toString(),
				signal: controller ? controller.signal : undefined
			});
			let payload = null;
			try { payload = await response.json(); } catch (_) { payload = null; }
			if (!response.ok || !payload || payload.success !== true) {
				const error = new Error('favorite_failed');
				error.status = response.status;
				throw error;
			}
			return Boolean(payload.data?.favorite);
		} finally {
			if (timeout) window.clearTimeout(timeout);
		}
	};

	const promptSignIn = (productId) => {
		let handled = false;
		try {
			handled = !document.dispatchEvent(new CustomEvent('delicat:favorite-signin', {
				bubbles: true,
				cancelable: true,
				detail: { productId }
			}));
		} catch (_) { handled = false; }
		if (handled) return;
		const trigger = document.querySelector('[data-delicat-login], a[href$="#delicat-login"]');
		if (trigger) { trigger.click(); return; }
		window.location.hash = 'delicat-login';
	};

	const rollBack = (productId, liked, status) => {
		const level = motionLevel();
		for (const button of buttonsFor(productId)) {
			if (isLiked(button) !== liked) {
				setPressed(button, liked);
				if (liked) {
					const { fill } = parts(button);
					settle(button);
					if (canAnimate && fill) {
						remember(button, fill.animate([{ opacity: 0, transform: 'scale(.6)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 220, easing: 'ease-out' }));
					}
				} else {
					animateUnlike(button, level);
				}
			}
			shake(button);
		}
		announce(productId, liked);
		/* 401 means the session ended. A 403 is a stale nonce on an old page,
		   which signing in again would not fix, so it only rolls back. */
		if (status === 401) promptSignIn(productId);
	};

	const schedule = (productId, delay = SYNC_DELAY) => {
		window.clearTimeout(timers.get(productId));
		timers.set(productId, window.setTimeout(() => {
			timers.delete(productId);
			sync(productId);
		}, delay));
	};

	const sync = async (productId) => {
		if (inflight.has(productId)) return;
		const target = wanted.get(productId);
		if (typeof target !== 'boolean' || target === confirmed.get(productId)) return;

		inflight.add(productId);
		try {
			confirmed.set(productId, await post(productId, target));
		} catch (error) {
			const held = confirmed.get(productId) === true;
			wanted.set(productId, held);
			rollBack(productId, held, Number(error?.status || 0));
		} finally {
			inflight.delete(productId);
			if (wanted.get(productId) !== confirmed.get(productId)) schedule(productId, 0);
		}
	};

	/* ------------------------------------------------------------------ */
	/* Interaction                                                         */
	/* ------------------------------------------------------------------ */

	const release = () => {
		for (const button of document.querySelectorAll(`${SELECTOR}.is-pressed`)) {
			button.classList.remove('is-pressed');
		}
	};

	document.addEventListener('pointerdown', (event) => {
		const button = event.target instanceof Element ? event.target.closest(SELECTOR) : null;
		if (button && (event.button === 0 || event.pointerType !== 'mouse')) button.classList.add('is-pressed');
	}, { passive: true });
	for (const type of ['pointerup', 'pointercancel', 'dragstart']) {
		document.addEventListener(type, release, { passive: true });
	}
	document.addEventListener('scroll', release, { passive: true, capture: true });
	window.addEventListener('blur', release);

	document.addEventListener('click', (event) => {
		const button = event.target instanceof Element ? event.target.closest(SELECTOR) : null;
		if (!button) return;
		event.preventDefault();
		release();

		const productId = Number(button.dataset.productId || 0);
		if (!Number.isInteger(productId) || productId <= 0) return;

		/* RC81 rule, kept: a heart never answers a tap with silence. Where it
		   cannot save, it says so and sends the shopper to sign in. */
		if ((!config.loggedIn && !config.guest) || (config.loggedIn && (!config.ajaxUrl || !config.nonce))) {
			shake(button);
			promptSignIn(productId);
			return;
		}

		const before = isLiked(button);
		const next = !before;
		const level = motionLevel();

		setPressed(button, next);
		paint(productId, next, button);
		if (next) {
			animateLike(button, level);
			haptic();
		} else {
			animateUnlike(button, level);
		}
		announce(productId, next);

		if (!config.loggedIn) {
			guestFavorites.delete(productId);
			if (next) guestFavorites.add(productId);
			while (guestFavorites.size > GUEST_MAX) {
				const oldest = guestFavorites.values().next().value;
				guestFavorites.delete(oldest);
				paint(oldest, false);
				announce(oldest, false);
			}
			writeGuest();
			return;
		}

		if (!confirmed.has(productId)) confirmed.set(productId, before);
		wanted.set(productId, next);
		schedule(productId);
	});

	/* ------------------------------------------------------------------ */
	/* Hydration                                                           */
	/* ------------------------------------------------------------------ */

	/* Pages served from the guest cache always render unliked hearts, and a
	   card materialised later carries the state it had when it was cached.
	   This brings every button in scope to the state this visitor holds. */
	const hydrate = (scope = document) => {
		const nodes = scope && scope.querySelectorAll ? scope.querySelectorAll(SELECTOR) : [];
		for (const button of nodes) {
			const id = Number(button.dataset.productId || 0);
			if (id <= 0) continue;
			let liked = null;
			if (!config.loggedIn) {
				if (config.guest) liked = guestFavorites.has(id);
			} else if (wanted.has(id)) {
				liked = wanted.get(id);
			}
			if (liked !== null && isLiked(button) !== liked) setPressed(button, liked);
		}
	};

	const refreshGuests = () => {
		if (config.loggedIn || !config.guest) return;
		guestFavorites = readGuest();
		hydrate(document);
	};

	document.addEventListener('delicat:carousel-materialized', (event) => {
		hydrate(event.target instanceof Element ? event.target : document);
	});
	document.addEventListener('delicat:navigation-complete', () => hydrate(document));
	/* Cookies raise no cross-tab event: re-read when this tab is shown again. */
	document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshGuests(); });
	window.addEventListener('pageshow', (event) => { if (event.persisted) refreshGuests(); });

	window.DelicaBuilderV9Heart = { hydrate, hydrateGuests: hydrate };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => hydrate(document), { once: true });
	} else {
		hydrate(document);
	}
})();
