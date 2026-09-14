(() => {
	'use strict';

	const core = window.DelicaBuilderV9Core;
	if (!core || !core.cfg.appNavigation) return;

	let controller = null;
	const loadedScripts = new Map();

	const shouldHandle = (link) => {
		if (!link || link.target || link.hasAttribute('download') || link.hasAttribute('data-delicat-no-app')) return false;
		if (link.getAttribute('rel')?.includes('external')) return false;

		if (
			link.hasAttribute('data-delicat-product-link')
			|| link.hasAttribute('data-delicat-instant-product')
			|| link.closest('.delicat-product-card,.delicat-woo-product-card')
		) return false;

		if (core.cfg.navigationScope === 'marked' && !link.hasAttribute('data-delicat-app-link')) return false;
		return Boolean(core.eligibleUrl(link.href));
	};

	const loadScript = (src) => {
		if (!src) return Promise.resolve(false);
		if (loadedScripts.has(src)) return loadedScripts.get(src);

		const promise = new Promise((resolve) => {
			const existing = Array.from(document.scripts).find((script) => script.src === src);
			if (existing) return resolve(true);

			const script = document.createElement('script');
			script.src = src;
			script.defer = true;
			script.onload = () => resolve(true);
			script.onerror = () => resolve(false);
			document.head.appendChild(script);
		});
		loadedScripts.set(src, promise);
		return promise;
	};

	const ensureStyles = async (incoming) => {
		const links = Array.from(incoming.querySelectorAll('link[rel="stylesheet"][id^="delicat-builder-v9"][href]'));
		const swaps = [];

		for (const incomingLink of links) {
			const href = new URL(incomingLink.href, location.href).href;
			const id = incomingLink.id;
			const existing = id ? document.getElementById(id) : null;
			if (existing?.href === href) continue;

			swaps.push(new Promise((resolve) => {
				const link = document.createElement('link');
				link.rel = 'stylesheet';
				link.href = href;
				link.dataset.delicatNavigationStyle = '1';
				link.onload = () => {
					if (existing && existing !== link) existing.remove();
					if (id) link.id = id;
					resolve(true);
				};
				link.onerror = () => resolve(false);
				document.head.appendChild(link);
			}));
		}

		if (swaps.length) await Promise.all(swaps);
	};

	const ensureModules = async (incoming) => {
		const tasks = [];
		if (incoming.querySelector('[data-delicat-carousel]') && !window.DelicaBuilderV9Carousel) {
			tasks.push(loadScript(core.cfg.modules?.carousel || ''));
		}

		if (incoming.querySelector('[data-delicat-heart]')) {

			if (!core.cfg.heart?.enabled) return false;
			if (!window.DelicaBuilderV9Heart) tasks.push(loadScript(core.cfg.modules?.heart || ''));
		}

		if (!tasks.length) return true;
		const results = await Promise.all(tasks);
		return results.every(Boolean);
	};

	const designContext = (doc) => {
		const body = doc?.body;
		if (!body) return '';
		const classes = Array.from(body.classList).filter((name) =>
			name === 'delicat-builder-homepage-managed'
			|| name.startsWith('delicat-design-')
			|| name.startsWith('delicat-home-mode-')
			|| name === 'delicat-app-shell-active'
		);
		return classes.sort().join('|');
	};

	const syncDesignContext = (incoming) => {
		const currentBody = document.body;
		const nextBody = incoming?.body;
		if (!currentBody || !nextBody) return;
		const managed = (name) => name === 'delicat-builder-homepage-managed'
			|| name.startsWith('delicat-design-')
			|| name.startsWith('delicat-home-mode-')
			|| name === 'delicat-app-shell-active';
		Array.from(currentBody.classList).filter(managed).forEach((name) => currentBody.classList.remove(name));
		Array.from(nextBody.classList).filter(managed).forEach((name) => currentBody.classList.add(name));
	};

	const parse = (html) => new DOMParser().parseFromString(html, 'text/html');

	const fallbackFrames = (style, phase, lowPower = false) => {
		if (lowPower || style === 'fade') {
			return phase === 'out'
				? [{ opacity: 1 }, { opacity: 0 }]
				: [{ opacity: 0 }, { opacity: 1 }];
		}
		if (style === 'scale') {
			return phase === 'out'
				? [{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(.994)' }]
				: [{ opacity: 0, transform: 'scale(1.006)' }, { opacity: 1, transform: 'scale(1)' }];
		}
		return phase === 'out'
			? [{ opacity: 1, transform: 'translate3d(0,0,0)' }, { opacity: 0, transform: 'translate3d(0,-5px,0)' }]
			: [{ opacity: 0, transform: 'translate3d(0,8px,0)' }, { opacity: 1, transform: 'translate3d(0,0,0)' }];
	};

	const animateFallback = (element, style, phase, duration) => {
		if (!element?.animate || core.reducedMotion) return Promise.resolve();
		try {
			const animation = element.animate(
				fallbackFrames(style, phase, Boolean(core.lowPower)),
				{
					duration: Math.max(90, Math.min(420, Math.round(duration * (phase === 'out' ? 0.55 : 0.8)))),
					easing: phase === 'out' ? 'cubic-bezier(.4,0,1,1)' : 'cubic-bezier(.22,.61,.36,1)',
					fill: 'both'
				}
			);
			return animation.finished.catch(() => undefined);
		} catch (_) {
			return Promise.resolve();
		}
	};

	const swap = async (incoming, url, push) => {
		const selector = core.cfg.contentSelector || 'main';
		const current = document.querySelector(selector);
		const next = incoming.querySelector(selector);

		if (
			!current
			|| !next
			|| !incoming.querySelector('[data-delicat-page-layout],[data-delicat-server-render]')
		) {
			location.href = url.href;
			return;
		}

		await ensureStyles(incoming);
		if (!await ensureModules(incoming)) {
			location.href = url.href;
			return;
		}

		let swapped = false;
		const doSwap = () => {
			if (swapped) return;
			swapped = true;
			current.replaceWith(document.importNode(next, true));
			if (designContext(incoming) !== designContext(document)) syncDesignContext(incoming);
			document.title = incoming.title || document.title;
			if (push) history.pushState({ delicat: true }, '', url.href);
			window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
			document.dispatchEvent(new CustomEvent('delicat:navigation-complete', { detail: { url: url.href } }));
		};

		const pageMotion = Boolean(core.cfg.motion?.pageTransitions && core.cfg.motion?.pageTransition !== 'none' && !core.reducedMotion);
		const pageStyle = String(core.cfg.motion?.pageTransition || 'fade-slide');
		const pageDuration = Math.max(120, Math.min(600, Number(core.cfg.motion?.pageDuration || 220)));

		if (pageMotion && document.startViewTransition) {
			try {
				const transition = document.startViewTransition(doSwap);
				await transition.finished;
			} catch (_) {
				doSwap();
			}
		} else if (pageMotion && current.animate) {

			await animateFallback(current, pageStyle, 'out', pageDuration);
			doSwap();
			const replacement = document.querySelector(selector);
			await animateFallback(replacement, pageStyle, 'in', pageDuration);
		} else {
			doSwap();
		}
	};

	const navigate = async (url, push = true) => {
		controller?.abort();
		controller = new AbortController();
		document.documentElement.classList.add('delicat-navigating');

		try {
			let html = null;
			const cached = window.DelicaBuilderV9Prefetch?.get(url.href);
			if (cached) html = await cached;

			if (!html) {
				const response = await fetch(url.href, {
					credentials: 'same-origin',
					signal: controller.signal,
					headers: { 'X-Delicat-Navigation': '1' }
				});
				if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) {
					throw new Error('Navigation fetch failed');
				}
				html = await response.text();
			}

			if (!html) throw new Error('Empty navigation response');
			await swap(parse(html), url, push);
		} catch (error) {
			if (error?.name !== 'AbortError') location.href = url.href;
		} finally {
			document.documentElement.classList.remove('delicat-navigating');
		}
	};

	document.addEventListener('click', (event) => {
		if (event.defaultPrevented || event.button !== 0) return;
		if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

		const link = event.target.closest?.('a[href]');
		if (!shouldHandle(link)) return;
		const url = core.eligibleUrl(link.href);
		if (!url) return;

		event.preventDefault();
		navigate(url, true);
	});

	window.addEventListener('popstate', () => {
		const url = core.eligibleUrl(location.href);
		if (url) navigate(url, false);
	});
})();
