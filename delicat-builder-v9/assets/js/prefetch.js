(() => {
	'use strict';

	if (window.DBPNavConfig || window.DBPNav || window.DelicatShellNavConfig || window.DelicaBuilderV9Prefetch) return;

	const core = window.DelicaBuilderV9Core;
	if (!core) return;

	const cfg = core.cfg || {};
	const normalEnabled = Boolean(cfg.prefetch || cfg.predictiveMode);
	const enabled = Boolean(normalEnabled || cfg.instantProductLaunch);
	const delay = Math.max(40, Math.min(350, Number(cfg.intentDelay) || 90));
	const budget = Math.max(1, Math.min(12, Number(cfg.prefetchBudget) || 6));
	const effectiveBudget = core.lowPower ? Math.min(2, budget) : budget;
	const maxBytes = Math.max(131072, Math.min(1048576, Number(cfg.prefetchMaxBytes) || 524288));
	const networkAware = cfg.networkAware !== false;
	const instantProductLaunch = cfg.instantProductLaunch !== false;

	if (!enabled) return;

	const cache = new Map();
	const timers = new WeakMap();
	const active = new Map();
	let warmed = 0;
	let touchIntent = null;

	const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
	const networkConstrained = () => {
		if (!networkAware) return false;
		if (connection?.saveData) return true;
		const type = String(connection?.effectiveType || '').toLowerCase();
		/* RC79: 3g was missing. Warming a second document over a 3g link
		   steals bandwidth from the page the shopper is reading, which is
		   the common case in Haiti. downlink and rtt catch the links that
		   report a good effectiveType but do not behave like one. */
		if (type === 'slow-2g' || type === '2g' || type === '3g') return true;
		const dl = typeof connection?.downlink === 'number' ? connection.downlink : 0;
		if (dl > 0 && dl < 1.5) return true;
		if (typeof connection?.rtt === 'number' && connection.rtt > 300 && dl > 0 && dl < 3) return true;
		return document.documentElement.classList.contains('delicat-slow-net');
	};

	const candidateLink = (target) => target?.closest?.(
		'a[data-delicat-prefetch],a[data-delicat-app-link],a[data-delicat-product-link],a[data-delicat-instant-product],.delicat-shell__menu a,.delicat-shell__mobile-menu a,.delicat-woo-product-card a.woocommerce-LoopProduct-link,.delicat-woo-product-card a.delicat-archive-product-cta'
	);

	const instantLink = (target) => {
		if (!instantProductLaunch) return null;
		const link = target?.closest?.('a[data-delicat-instant-product][href]');
		return link || null;
	};

	const safeUrl = (href) => {
		const classifier = core.predictiveSafeUrl || core.eligibleUrl;
		return classifier ? classifier(href) : null;
	};

	const getCached = (href) => {
		const url = safeUrl(href);
		if (!url || !cache.has(url.href)) return null;

		const value = cache.get(url.href);
		cache.delete(url.href);
		cache.set(url.href, value);
		return value;
	};

	const trimCache = () => {
		const maxEntries = Math.max(2, effectiveBudget);
		while (cache.size > maxEntries) {
			const first = cache.keys().next().value;
			cache.delete(first);
		}
	};

	const warm = async (href, options = {}) => {
		const highConfidence = Boolean(options.highConfidence);
		if (networkConstrained() || document.visibilityState === 'hidden') return null;
		if (!highConfidence && warmed >= effectiveBudget) return null;

		const url = safeUrl(href);
		if (!url) return null;
		if (cache.has(url.href)) return cache.get(url.href);
		if (active.has(url.href)) return active.get(url.href).promise;

		if (!highConfidence) warmed += 1;

		const controller = new AbortController();
		const request = {
			method: 'GET',
			credentials: 'same-origin',
			signal: controller.signal,
			cache: 'default',
			priority: highConfidence ? 'high' : 'low'
		};

		if (!highConfidence) request.headers = { 'X-Delicat-Prefetch': '1' };

		let reusable = true;
		const promise = fetch(url.href, request).then(async (response) => {
			if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) {
				reusable = false;
				return null;
			}

			const cacheControl = String(response.headers.get('cache-control') || '').toLowerCase();
			if (/(?:^|[,\s])no-store(?:[,\s]|$)/.test(cacheControl)) {
				// A navigation cannot reuse a no-store warm response. Do
				// not retain a duplicate full document that the tap must download
				// again; the host/cache audit explains how to unlock this path.
				reusable = false;
				try { await response.body?.cancel(); } catch (_) {}
				return null;
			}

			const declared = Number(response.headers.get('content-length') || 0);
			if (declared > maxBytes) {
				reusable = false;
				try { await response.body?.cancel(); } catch (_) {}
				return null;
			}

			if (highConfidence) {

				const bytes = await response.arrayBuffer();
				if (bytes.byteLength > maxBytes) {
					reusable = false;
					return null;
				}
				return true;
			}

			const html = await response.text();
			if (new Blob([html]).size > maxBytes) {
				reusable = false;
				return null;
			}
			return html;
		}).catch((error) => {
			reusable = false;
			if (error?.name !== 'AbortError') return null;
			return null;
		}).finally(() => {
			active.delete(url.href);

			if (!reusable && cache.get(url.href) === promise) cache.delete(url.href);
		});

		active.set(url.href, { promise, controller });
		cache.set(url.href, promise);
		trimCache();
		return promise;
	};

	const cancelTimer = (link) => {
		const timer = timers.get(link);
		if (timer) {
			window.clearTimeout(timer);
			timers.delete(link);
		}
	};

	const schedule = (link, immediate = false) => {
		if (!normalEnabled || !link?.href || networkConstrained() || warmed >= effectiveBudget) return;
		if (!safeUrl(link.href)) return;

		cancelTimer(link);

		if (immediate) {
			warm(link.href);
			return;
		}

		const timer = window.setTimeout(() => {
			timers.delete(link);
			warm(link.href);
		}, delay);
		timers.set(link, timer);
	};

	const markLaunching = (link) => {
		if (!link) return;
		link.classList.add('is-launching');
		link.setAttribute('aria-busy', 'true');
		document.documentElement.classList.add('delicat-product-launching');
	};

	const clearLaunching = () => {
		document.documentElement.classList.remove('delicat-product-launching');
		document.querySelectorAll('a.is-launching[data-delicat-instant-product]').forEach((link) => {
			link.classList.remove('is-launching');
			link.removeAttribute('aria-busy');
		});
	};

	document.addEventListener('pointerover', (event) => {
		if (!normalEnabled) return;
		const link = candidateLink(event.target);
		if (!link) return;

		if (event.pointerType === 'touch' && link.closest('[data-delicat-carousel]')) {
			cancelTimer(link);
			return;
		}
		schedule(link, false);
	}, { passive: true });

	document.addEventListener('pointerout', (event) => {
		if (!normalEnabled) return;
		const link = candidateLink(event.target);
		if (!link) return;
		if (event.relatedTarget && link.contains(event.relatedTarget)) return;
		cancelTimer(link);
	}, { passive: true });

	document.addEventListener('focusin', (event) => {
		if (!normalEnabled) return;
		const link = candidateLink(event.target);
		if (link) schedule(link, false);
	});

	document.addEventListener('focusout', (event) => {
		if (!normalEnabled) return;
		const link = candidateLink(event.target);
		if (link) cancelTimer(link);
	});

	document.addEventListener('pointerdown', (event) => {
		if (event.pointerType === 'touch') return;
		const link = instantLink(event.target);
		if (!link?.href) return;
		warm(link.href, { highConfidence: true });
	}, { passive: true, capture: true });

	const cancelTouchIntent = () => {
		if (!touchIntent) return;
		if (touchIntent.timer) window.clearTimeout(touchIntent.timer);
		touchIntent = null;
	};

	document.addEventListener('touchstart', (event) => {
		const link = candidateLink(event.target);
		if (!link) return;

		const instant = instantLink(event.target);
		if (!instant && !normalEnabled) return;
		const insideCarousel = Boolean(link.closest('[data-delicat-carousel]'));

		if (insideCarousel && !instant) {
			cancelTimer(link);
			cancelTouchIntent();
			return;
		}

		const touch = event.touches?.[0];
		if (!touch) {
			if (instant) warm(link.href, { highConfidence: true });
			else schedule(link, false);
			return;
		}

		cancelTouchIntent();
		const archiveCard = Boolean(link.closest('.delicat-woo-product-card'));
		if (!instant && archiveCard && cfg.archiveTouchPrefetch === false) {
			return;
		}

		if (instant) {
			touchIntent = { link, x: touch.clientX, y: touch.clientY, instant: true, fired: false, timer: 0 };
			return;
		}

		const intentDelay = archiveCard ? 45 : Math.max(65, Math.min(120, delay));
		touchIntent = {
			link,
			x: touch.clientX,
			y: touch.clientY,
			instant: false,
			fired: false,
			timer: window.setTimeout(() => {
				if (!touchIntent || touchIntent.link !== link) return;
				touchIntent.fired = true;
				warm(link.href);
			}, intentDelay)
		};
	}, { passive: true });

	document.addEventListener('touchmove', (event) => {
		if (!touchIntent) return;
		const touch = event.touches?.[0];
		if (!touch) return;
		const dx = Math.abs(touch.clientX - touchIntent.x);
		const dy = Math.abs(touch.clientY - touchIntent.y);
		const threshold = touchIntent.instant ? 8 : 10;
		if (dx > threshold || dy > threshold) cancelTouchIntent();
	}, { passive: true });

	document.addEventListener('touchend', cancelTouchIntent, { passive: true });
	document.addEventListener('touchcancel', cancelTouchIntent, { passive: true });

	document.addEventListener('click', (event) => {
		if (event.defaultPrevented || event.button !== 0) return;
		if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		const link = instantLink(event.target);
		if (!link?.href || !safeUrl(link.href)) return;
		markLaunching(link);
	}, { capture: true });

	window.addEventListener('pageshow', clearLaunching, { passive: true });

	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState !== 'hidden') return;
		for (const { controller } of active.values()) {
			controller?.abort?.();
		}
	});

	window.DelicaBuilderV9Prefetch = {
		prefetch: warm,
		get: getCached,
		stats: () => ({ warmed, budget: effectiveBudget, configuredBudget: budget, cached: cache.size, active: active.size })
	};
})();
