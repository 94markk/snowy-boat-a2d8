(() => {
	'use strict';

	const config = window.DelicaBuilderV9?.heart || {};
	if (!config.enabled) return;

	const selector = '[data-delicat-heart]';
	/* RC55: guest favourites live in a small first-party cookie (comma-separated
	   product IDs, newest last, capped at 60 so the cookie stays under ~400 B on
	   every request) instead of localStorage — the storefront's standing
	   storage rule. Signed-in favourites are unchanged: they live in user meta. */
	const cookieKey = 'dbv9_fav';
	const GUEST_MAX = 60;
	let requestQueue = Promise.resolve();

	const readCookie = (name) => {
		const parts = document.cookie ? document.cookie.split(';') : [];
		for (let i = 0; i < parts.length; i++) {
			const pair = parts[i].replace(/^\s+/, '');
			if (pair.indexOf(name + '=') === 0) {
				try { return decodeURIComponent(pair.slice(name.length + 1)); } catch (_) { return ''; }
			}
		}
		return '';
	};
	const writeCookie = (name, value, maxAge) => {
		let cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax; max-age=' + maxAge;
		if (location.protocol === 'https:') cookie += '; Secure';
		document.cookie = cookie;
	};

	const readGuest = () => {
		if (!config.guest) return new Set();
		try {
			const raw = readCookie(cookieKey);
			const parsed = raw ? raw.split(',') : [];
			return new Set(
				parsed.map(Number).filter((id) => Number.isInteger(id) && id > 0).slice(-GUEST_MAX)
			);
		} catch (_) {
			return new Set();
		}
	};

	let guestFavorites = readGuest();

	const writeGuest = () => {
		try {
			writeCookie(cookieKey, [...guestFavorites].slice(-GUEST_MAX).join(','), 31536000);
		} catch (_) {}
	};

	const applyState = (button, favorite) => {
		button.classList.toggle('is-favorite', favorite);
		button.setAttribute('aria-pressed', favorite ? 'true' : 'false');
		button.setAttribute(
			'aria-label',
			favorite
				? String(config.removeLabel || 'Retirer des favoris')
				: String(config.addLabel || 'Ajouter aux favoris')
		);
	};

	const productButtons = (productId) =>
		Array.from(document.querySelectorAll(`${selector}[data-product-id="${productId}"]`));

	const applyProductState = (productId, favorite) => {
		productButtons(productId).forEach((button) => applyState(button, favorite));
	};

	const setProductPending = (productId, pending) => {
		productButtons(productId).forEach((button) => {
			button.classList.toggle('is-pending', pending);
			if (pending) button.setAttribute('aria-busy', 'true');
			else button.removeAttribute('aria-busy');
		});
	};

	const hydrateGuests = (scope = document) => {
		if (config.loggedIn || !config.guest) return;
		scope.querySelectorAll(selector).forEach((button) => {
			const id = Number(button.dataset.productId || 0);
			if (id > 0) applyState(button, guestFavorites.has(id));
		});
	};

	const postFavorite = async (productId, state) => {
		const body = new URLSearchParams();
		body.set('action', 'delicat_builder_v9_favorite');
		body.set('nonce', String(config.nonce));
		body.set('product_id', String(productId));
		body.set('state', state ? '1' : '0');

		const response = await fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: body.toString()
		});
		const payload = await response.json();
		if (!response.ok || !payload?.success) throw new Error('favorite_failed');
		return Boolean(payload.data?.favorite);
	};

	hydrateGuests();

	document.addEventListener('delicat:carousel-materialized', (event) => {
		hydrateGuests(event.target instanceof Element ? event.target : document);
	});

	document.addEventListener('delicat:navigation-complete', () => hydrateGuests(document));

	/* Cookies raise no cross-tab event; re-read the cookie when this tab is
	   shown again so a favourite toggled in another tab is reflected here. */
	document.addEventListener('visibilitychange', () => {
		if (document.hidden || config.loggedIn || !config.guest) return;
		guestFavorites = readGuest();
		hydrateGuests(document);
	});
	window.addEventListener('pageshow', (event) => {
		if (!event.persisted || config.loggedIn || !config.guest) return;
		guestFavorites = readGuest();
		hydrateGuests(document);
	});

	/* A short scale on the button itself, so the tap is acknowledged on the
	   frame it happens rather than after a network round trip. Skipped when the
	   visitor asked for reduced motion or the link is slow. */
	const pulse = (button) => {
		const root = document.documentElement;
		if (root.classList.contains('delicat-slow-net')) return;
		if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
		button.classList.remove('is-tapped');
		/* force a reflow so the class can be re-applied on a rapid double tap */
		void button.offsetWidth;
		button.classList.add('is-tapped');
		window.setTimeout(() => button.classList.remove('is-tapped'), 260);
	};

	const promptSignIn = (button) => {
		pulse(button);
		let handled = false;
		try {
			handled = !document.dispatchEvent(new CustomEvent('delicat:favorite-signin', {
				bubbles: true,
				cancelable: true,
				detail: { productId: Number(button.dataset.productId || 0) }
			}));
		} catch (e) { handled = false; }
		if (handled) return;
		const trigger = document.querySelector('[data-delicat-login], a[href$="#delicat-login"]');
		if (trigger) { trigger.click(); return; }
		window.location.hash = 'delicat-login';
	};

	document.addEventListener('click', (event) => {
		const button = event.target.closest?.(selector);
		if (!button || button.classList.contains('is-pending')) return;

		const productId = Number(button.dataset.productId || 0);
		if (!Number.isInteger(productId) || productId <= 0) return;

		const current = button.getAttribute('aria-pressed') === 'true';
		const next = !current;

		/* RC81: every path below now gives the tap an answer.
		   A heart that renders with a pointer cursor and aria-pressed but
		   returns silently reads as broken, and silence was the outcome for a
		   guest whenever guest favourites were switched off, and for a signed-in
		   visitor whose page was cached without a usable nonce. Both cases send
		   the shopper to sign in instead of doing nothing. */
		if (!config.loggedIn) {
			if (!config.guest) { promptSignIn(button); return; }
			if (next) guestFavorites.add(productId);
			else guestFavorites.delete(productId);
			writeGuest();
			applyProductState(productId, next);
			pulse(button);
			return;
		}

		if (!config.ajaxUrl || !config.nonce) { promptSignIn(button); return; }

		applyProductState(productId, next);
		setProductPending(productId, true);

		const task = async () => {
			try {
				const confirmed = await postFavorite(productId, next);
				applyProductState(productId, confirmed);
			} catch (_) {
				applyProductState(productId, current);
			} finally {
				setProductPending(productId, false);
			}
		};

		requestQueue = requestQueue.then(task, task);
	});

	window.DelicaBuilderV9Heart = { hydrateGuests };
})();
