/* Delicat like button — the favourite control, rebuilt.
 *
 * The old one toggled a class and posted a request. Everything a tap should
 * feel like was missing, and everything it should be careful about was too:
 * five quick taps sent five requests, a failure silently flipped the icon
 * back, and the only acknowledgement was a 260 ms scale on the bubble.
 *
 * This version:
 *
 *   - answers on the frame of the touch. pointerdown presses the button;
 *     the state flips optimistically on click, before any network.
 *   - springs: the fill scales out through an overshoot, a ring pulses off
 *     the bubble, six sparks fly, and the phone taps back where it can.
 *     All of it is transform and opacity, and every spark is created on the
 *     tapped button only and removed when it lands — idle cost is zero nodes.
 *   - coalesces. One request in flight per product, carrying the state the
 *     shopper actually ended on; taps during that flight are folded into the
 *     next one instead of queueing five round trips on a 3G connection.
 *   - tells the truth when a write fails: the button returns to the server's
 *     state with a shake, rather than lying in the direction of the tap.
 *
 * Guest favourites live in a small first-party cookie (comma-separated ids,
 * capped so it stays under ~400 B on every request), which is the storefront's
 * standing storage rule. Signed-in favourites live in user meta, written only
 * through the nonce-protected authenticated action.
 */
(() => {
	'use strict';

	const config = window.DelicaBuilderV9?.heart || {};
	if (!config.enabled) return;

	const SELECTOR = '[data-delicat-heart]';
	const COOKIE = 'dbv9_fav';
	const GUEST_MAX = 60;
	const YEAR = 31536000;

	/* ---------------------------------------------------------- motion */

	/* A tap always gets an answer; the decoration around it is what a slow
	 * phone or a reduced-motion request gives up. Read once — none of these
	 * change inside a page view. */
	const plain = (() => {
		try {
			if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return true;
		} catch (_) {}
		if (document.documentElement.classList.contains('delicat-slow-net')) return true;
		const link = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
		if (link?.saveData) return true;
		if (typeof navigator.deviceMemory === 'number' && navigator.deviceMemory <= 2) return true;
		return typeof navigator.hardwareConcurrency === 'number' && navigator.hardwareConcurrency <= 2;
	})();

	/* -------------------------------------------------------- guest set */

	const readCookie = (name) => {
		const parts = document.cookie ? document.cookie.split(';') : [];
		for (const part of parts) {
			const pair = part.replace(/^\s+/, '');
			if (pair.indexOf(`${name}=`) !== 0) continue;
			try { return decodeURIComponent(pair.slice(name.length + 1)); } catch (_) { return ''; }
		}
		return '';
	};

	const readGuest = () => {
		if (!config.guest) return new Set();
		try {
			const raw = readCookie(COOKIE);
			return new Set(
				(raw ? raw.split(',') : [])
					.map(Number)
					.filter((id) => Number.isInteger(id) && id > 0)
					.slice(-GUEST_MAX)
			);
		} catch (_) {
			return new Set();
		}
	};

	let guests = readGuest();

	const writeGuest = () => {
		try {
			let cookie = `${COOKIE}=${encodeURIComponent([...guests].slice(-GUEST_MAX).join(','))}; path=/; SameSite=Lax; max-age=${YEAR}`;
			if (location.protocol === 'https:') cookie += '; Secure';
			document.cookie = cookie;
		} catch (_) {}
	};

	/* ------------------------------------------------------------ state */

	const buttonsFor = (productId) =>
		document.querySelectorAll(`${SELECTOR}[data-product-id="${productId}"]`);

	const label = (liked) =>
		String(liked ? (config.removeLabel || 'Retirer des favoris') : (config.addLabel || 'Ajouter aux favoris'));

	const paint = (button, liked) => {
		button.classList.toggle('is-liked', liked);
		button.setAttribute('aria-pressed', liked ? 'true' : 'false');
		button.setAttribute('aria-label', label(liked));
	};

	/* Every card showing this product moves together — the same item can be in
	 * a carousel and a grid on one page. */
	const paintAll = (productId, liked) => {
		buttonsFor(productId).forEach((button) => paint(button, liked));
	};

	/* ------------------------------------------------------- celebration */

	const SPARKS = 6;

	const celebrate = (button) => {
		if (plain) return;

		/* Restarting the pop on a fast double tap needs the class off, a
		 * reflow, and the class on again — otherwise the second tap runs no
		 * animation at all. */
		button.classList.remove('is-popping');
		void button.offsetWidth;
		button.classList.add('is-popping');
		button.addEventListener('animationend', function done(event) {
			if (event.animationName !== 'delicat-like-pop') return;
			button.classList.remove('is-popping');
			button.removeEventListener('animationend', done);
		});

		const burst = document.createElement('span');
		burst.className = 'delicat-like__burst';
		burst.setAttribute('aria-hidden', 'true');
		for (let i = 0; i < SPARKS; i++) {
			const spark = document.createElement('i');
			/* Each spark leaves on its own bearing, with a little variation in
			 * distance so the ring does not read as a machine part. */
			spark.style.setProperty('--angle', `${(360 / SPARKS) * i + (i % 2 ? 12 : -8)}deg`);
			/* Far enough to clear the bubble: a spark that dies inside a 40 px
			 * circle it shares a colour with is a spark nobody sees. */
			spark.style.setProperty('--throw', `${27 + (i % 3) * 6}px`);
			burst.appendChild(spark);
		}
		button.appendChild(burst);
		window.setTimeout(() => burst.remove(), 700);

		try { navigator.vibrate?.(12); } catch (_) {}
	};

	const refuse = (button) => {
		if (plain) return;
		button.classList.remove('is-refused');
		void button.offsetWidth;
		button.classList.add('is-refused');
		window.setTimeout(() => button.classList.remove('is-refused'), 420);
	};

	/* ----------------------------------------------------------- network */

	/* One flight per product. A tap while a request is in the air updates the
	 * wanted state and is sent as a single follow-up when the first settles,
	 * so ten taps cost at most two requests instead of ten. */
	const flights = new Map();

	const post = async (productId, liked) => {
		const body = new URLSearchParams();
		body.set('action', 'delicat_builder_v9_favorite');
		body.set('nonce', String(config.nonce));
		body.set('product_id', String(productId));
		body.set('state', liked ? '1' : '0');

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

	const sync = (productId, wanted, known) => {
		const flight = flights.get(productId);
		if (flight) {
			flight.wanted = wanted;
			return;
		}

		const state = { wanted, known };
		flights.set(productId, state);

		const run = async () => {
			while (flights.get(productId)) {
				const sending = state.wanted;
				try {
					const confirmed = await post(productId, sending);
					state.known = confirmed;
					/* Nothing changed while this was in the air: the server and
					 * the screen now agree, so stop. */
					if (state.wanted === sending) {
						flights.delete(productId);
						paintAll(productId, confirmed);
						return;
					}
				} catch (_) {
					flights.delete(productId);
					paintAll(productId, state.known);
					buttonsFor(productId).forEach(refuse);
					return;
				}
			}
		};

		run();
	};

	/* ------------------------------------------------------------ guests */

	const hydrateGuests = (scope = document) => {
		if (config.loggedIn || !config.guest) return;
		scope.querySelectorAll(SELECTOR).forEach((button) => {
			const id = Number(button.dataset.productId || 0);
			if (id > 0) paint(button, guests.has(id));
		});
	};

	const promptSignIn = (button) => {
		refuse(button);
		let handled = false;
		try {
			handled = !document.dispatchEvent(new CustomEvent('delicat:favorite-signin', {
				bubbles: true,
				cancelable: true,
				detail: { productId: Number(button.dataset.productId || 0) }
			}));
		} catch (_) {
			handled = false;
		}
		if (handled) return;
		const trigger = document.querySelector('[data-delicat-login], a[href$="#delicat-login"]');
		if (trigger) {
			trigger.click();
			return;
		}
		window.location.hash = 'delicat-login';
	};

	/* ------------------------------------------------------------- input */

	/* The press lands on the touch, not on the click the browser sends up to
	 * 300 ms later. It is only the press: the state still flips on click, so a
	 * finger that slides off the button changes nothing. */
	document.addEventListener('pointerdown', (event) => {
		const button = event.target.closest?.(SELECTOR);
		if (!button) return;
		button.classList.add('is-pressing');
	}, { passive: true });

	const release = (event) => {
		const button = event.target?.closest?.(SELECTOR);
		if (button) button.classList.remove('is-pressing');
		else document.querySelectorAll(`${SELECTOR}.is-pressing`).forEach((node) => node.classList.remove('is-pressing'));
	};
	document.addEventListener('pointerup', release, { passive: true });
	document.addEventListener('pointercancel', release, { passive: true });

	document.addEventListener('click', (event) => {
		const button = event.target.closest?.(SELECTOR);
		if (!button) return;

		const productId = Number(button.dataset.productId || 0);
		if (!Number.isInteger(productId) || productId <= 0) return;

		event.preventDefault();
		button.classList.remove('is-pressing');

		const current = button.getAttribute('aria-pressed') === 'true';
		const next = !current;

		/* Every path below answers the tap. A button that renders with a
		 * pointer cursor and aria-pressed but returns silently reads as broken,
		 * and silence was the outcome for a guest whenever guest favourites
		 * were switched off, and for a signed-in visitor whose page was cached
		 * without a usable nonce. Both cases send the shopper to sign in. */
		if (!config.loggedIn) {
			if (!config.guest) {
				promptSignIn(button);
				return;
			}
			if (next) guests.add(productId);
			else guests.delete(productId);
			writeGuest();
			paintAll(productId, next);
			if (next) celebrate(button);
			return;
		}

		if (!config.ajaxUrl || !config.nonce) {
			promptSignIn(button);
			return;
		}

		paintAll(productId, next);
		if (next) celebrate(button);
		sync(productId, next, current);
	});

	/* ------------------------------------------------------- re-hydration */

	hydrateGuests();

	document.addEventListener('delicat:carousel-materialized', (event) => {
		hydrateGuests(event.target instanceof Element ? event.target : document);
	});
	document.addEventListener('delicat:navigation-complete', () => hydrateGuests(document));

	/* A cookie raises no cross-tab event, so re-read it when this tab comes
	 * back: a favourite added in another tab is reflected here. */
	document.addEventListener('visibilitychange', () => {
		if (document.hidden || config.loggedIn || !config.guest) return;
		guests = readGuest();
		hydrateGuests(document);
	});
	window.addEventListener('pageshow', (event) => {
		if (!event.persisted || config.loggedIn || !config.guest) return;
		guests = readGuest();
		hydrateGuests(document);
	});

	window.DelicaBuilderV9Heart = { hydrateGuests };
})();
