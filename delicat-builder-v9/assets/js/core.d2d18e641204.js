(() => {
	'use strict';
	if (window.DelicaBuilderV9Core) return;

	const cfg = window.DelicaBuilderV9 || {};
	const reducedMotion = Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);
	const compactTouchViewport = Boolean(window.matchMedia?.('(max-width: 640px), (hover: none) and (pointer: coarse)').matches);
	const saveData = Boolean(navigator.connection?.saveData);
	const memory = Number(navigator.deviceMemory || 8);
	const cores = Number(navigator.hardwareConcurrency || 8);
	const lowPower = Boolean(cfg.lowPowerMode && (saveData || memory <= 4 || cores <= 4));

	if (lowPower) document.documentElement.classList.add('delicat-low-power');
	if (saveData) document.documentElement.classList.add('delicat-save-data');
	if (reducedMotion) document.documentElement.classList.add('delicat-reduce-motion');

	const motionCfg = cfg.motion || {};
	const transitionStyles = new Set(['fade', 'fade-slide', 'scale']);
	if (motionCfg.pageTransitions && transitionStyles.has(motionCfg.pageTransition)) {
		document.documentElement.classList.add('dbv9-page-transition-' + motionCfg.pageTransition);
		document.documentElement.style.setProperty('--dbv9-page-duration', Math.max(120, Math.min(600, Number(motionCfg.pageDuration || 220))) + 'ms');
	}

	const eligibleUrl = (href) => {
		try {
			const url = new URL(href, location.href);
			if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return null;

			const path = url.pathname.toLowerCase();
			const blockedPaths = [
				'/wp-admin', '/wp-login.php', '/wp-json',
				'/cart', '/panier',
				'/checkout', '/commande',
				'/my-account', '/mon-compte',
				'/wc-api', '/webhook', '/oauth', '/callback'
			];
			if (blockedPaths.some((part) => path.includes(part))) return null;

			const blockedParams = [
				'add-to-cart', 'remove_item', 'undo_item', 'wc-ajax',
				'_wpnonce', 'nonce', 'action', 'dip_action', 'logout', 'customer-logout',
				'key', 'token', 'code', 'session', 'payment_method'
			];
			if (blockedParams.some((name) => url.searchParams.has(name))) return null;

			return url;
		} catch (_) {
			return null;
		}
	};

	const predictiveSafeUrl = (href) => {
		const url = eligibleUrl(href);
		if (!url) return null;

		if (url.search) return null;

		const path = url.pathname.toLowerCase();
		const sensitiveSegments = [
			'/order-pay/', '/order-received/', '/lost-password/',
			'/customer-logout/', '/pay/', '/payment/'
		];
		if (sensitiveSegments.some((segment) => path.includes(segment))) return null;

		return url;
	};

	/* RC71: testimonials used to arrive duplicated in the server HTML solely
	   to make the infinite marquee loop seamless. Clone only when the section is
	   about to become visible; first paint and HTML parsing stay lean. */
	const prepareMarqueeTrack = (track) => {
		if (!track || track.dataset.dbv9LoopReady === '1') return;
		track.dataset.dbv9LoopReady = '1';
		if (reducedMotion || compactTouchViewport) {
			track.classList.add('is-loop-ready', 'is-manual');
			return;
		}
		const originals = Array.from(track.children);
		if (!originals.length) {
			track.classList.add('is-loop-ready');
			return;
		}
		const fragment = document.createDocumentFragment();
		for (const original of originals) {
			const clone = original.cloneNode(true);
			clone.setAttribute('aria-hidden', 'true');
			clone.removeAttribute('role');
			clone.querySelectorAll('a,button,input,select,textarea,[tabindex]').forEach((node) => node.setAttribute('tabindex', '-1'));
			fragment.appendChild(clone);
		}
		track.appendChild(fragment);
		requestAnimationFrame(() => track.classList.add('is-loop-ready'));
	};
	const initDeferredMarquees = () => {
		const tracks = Array.from(document.querySelectorAll('[data-dbv9-marquee-clone]:not([data-dbv9-loop-ready="1"])'));
		if (!tracks.length) return;
		if (reducedMotion || compactTouchViewport) {
			tracks.forEach(prepareMarqueeTrack);
			return;
		}
		if ('IntersectionObserver' in window) {
			const observer = new IntersectionObserver((entries) => {
				for (const entry of entries) {
					if (!entry.isIntersecting) continue;
					observer.unobserve(entry.target);
					prepareMarqueeTrack(entry.target);
				}
			}, { rootMargin: '320px 0px' });
			tracks.forEach((track) => observer.observe(track));
			return;
		}
		const idle = window.requestIdleCallback || ((cb) => window.setTimeout(cb, 1000));
		idle(() => tracks.forEach(prepareMarqueeTrack), { timeout: 1800 });
	};

	window.DelicaBuilderV9Core = {
		cfg,
		reducedMotion,
		lowPower,
		eligibleUrl,
		predictiveSafeUrl
	};

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDeferredMarquees, { once: true });
	else initDeferredMarquees();
	document.addEventListener('delicat:navigation-complete', initDeferredMarquees);
})();
