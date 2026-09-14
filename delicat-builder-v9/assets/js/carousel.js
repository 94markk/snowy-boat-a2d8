(() => {
	'use strict';

	const core = window.DelicaBuilderV9Core || { reducedMotion: false, lowPower: false };
	const supportsIO = 'IntersectionObserver' in window;
	const supportsResize = 'ResizeObserver' in window;
	const idle = window.requestIdleCallback || ((cb) => setTimeout(() => cb({ timeRemaining: () => 0 }), 16));
	const deferredStates = new WeakMap();

	const resizeCallbacks = new WeakMap();
	const fallbackResizeCallbacks = new Set();
	let fallbackResizeTick = false;

	const sharedResizeObserver = supportsResize
		? new ResizeObserver((entries) => {
			for (const entry of entries) resizeCallbacks.get(entry.target)?.();
		})
		: null;

	if (!supportsResize) {
		window.addEventListener('resize', () => {
			if (fallbackResizeTick) return;
			fallbackResizeTick = true;
			requestAnimationFrame(() => {
				fallbackResizeTick = false;
				for (const callback of fallbackResizeCallbacks) callback();
			});
		}, { passive: true });
	}

	const safeUrl = (value, sameOrigin = false) => {
		try {
			const url = new URL(String(value || ''), location.href);
			if (!['http:', 'https:'].includes(url.protocol)) return '';
			if (sameOrigin && url.origin !== location.origin) return '';
			return url.href;
		} catch (_) {
			return '';
		}
	};

	const classToken = (value, fallback) => {
		const token = String(value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
		return token || fallback;
	};

const cleanText = (value) => String(value || '')
	.replace(/[\u{1F000}-\u{1FAFF}\u{1F1E6}-\u{1F1FF}\u2600-\u27BF\uFE0E\uFE0F]/gu, '')
	.replace(/\s{2,}/g, ' ')
	.trim();

/* Same emoji ranges the server-side App Polish handles, mapped to the same
   Builder stroke icons so dynamically materialized cards never fall back to
   system emoji glyphs. Unmapped emoji are dropped. */
const EMOJI_RE = /[\u{1F000}-\u{1FAFF}\u{1F1E6}-\u{1F1FF}\u2190-\u21FF\u2300-\u23FF\u2460-\u24FF\u25A0-\u27BF\u2B00-\u2BFF\uFE0F\uFE0E\u20E3\u2122\u3030]/gu;

const EMOJI_SVG = {
	'🎮': '<rect x="2" y="7" width="20" height="11" rx="4"/><path d="M7 11v3M5.5 12.5h3M15.5 12h.01M18 14h.01"/>',
	'🕹': '<rect x="2" y="7" width="20" height="11" rx="4"/><path d="M7 11v3M5.5 12.5h3M15.5 12h.01M18 14h.01"/>',
	'🎁': '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 12h18M12 8v13M8 8a2.5 2.5 0 0 1 0-5c2 0 4 5 4 5s2-5 4-5a2.5 2.5 0 0 1 0 5"/>',
	'👑': '<path d="M3 8l4 4 5-7 5 7 4-4-2 12H5z"/>',
	'⚡': '<path d="M13 2 4.5 13.2h6.2L10 22l9-12h-6.3L13 2Z"/>',
	'🔥': '<path d="M12 22a6 6 0 0 0 6-6c0-5-6-9-6-14 0 0-6 4-6 9a4 4 0 0 0 4 4 3 3 0 0 1-1 3 6 6 0 0 0 3 4Z"/>',
	'💎': '<path d="M12 3l9 7-9 11L3 10z"/><path d="M3 10h18"/>',
	'⭐': '<path d="M12 3l2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8z"/>',
	'🌟': '<path d="M12 3l2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8z"/>',
	'✨': '<path d="M12 3l2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8z"/>',
	'💰': '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
	'💵': '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
	'💳': '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
	'🛒': '<circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/><path d="M2 3h3l3 12h11l2-8H7"/>',
	'🛍': '<circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/><path d="M2 3h3l3 12h11l2-8H7"/>',
	'🔒': '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
	'🔐': '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
	'🛡': '<path d="M12 2l8 3v6c0 5-3 9-8 11-5-2-8-6-8-11V5z"/><path d="M9 11.5l2 2 4-4"/>',
	'📱': '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 18h4"/>',
	'🎧': '<path d="M4 13v-2a8 8 0 0 1 16 0v6h-4v-6h4M4 11v6h4v-6z"/><path d="M16 18h-4"/>',
	'🌍': '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 4 6 4 9s-1 6-4 9c-3-3-4-6-4-9s1-6 4-9z"/>',
	'🌏': '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 4 6 4 9s-1 6-4 9c-3-3-4-6-4-9s1-6 4-9z"/>',
	'🌐': '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 4 6 4 9s-1 6-4 9c-3-3-4-6-4-9s1-6 4-9z"/>',
	'🏷': '<path d="M3 4h8l10 10-7 7L4 11z"/><circle cx="8" cy="8" r="1.2"/>',
	'🏆': '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5a3 3 0 0 0 3 3M16 6h3a3 3 0 0 1-3 3M10 17h4M9 21h6M12 13v4"/>',
	'🎟': '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v8a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2z"/><path d="M12 6v12"/>',
	'❤': '<path d="M12 20.5S4.5 15.9 2.7 11C1.6 7.6 3.6 4.5 6.9 4.5c1.9 0 3.4 1 4.3 2.4.9-1.4 2.4-2.4 4.3-2.4 3.3 0 5.3 3.1 4.2 6.5-1.8 4.9-9.3 9.5-9.3 9.5Z"/>',
	'♥': '<path d="M12 20.5S4.5 15.9 2.7 11C1.6 7.6 3.6 4.5 6.9 4.5c1.9 0 3.4 1 4.3 2.4.9-1.4 2.4-2.4 4.3-2.4 3.3 0 5.3 3.1 4.2 6.5-1.8 4.9-9.3 9.5-9.3 9.5Z"/>',
	'💜': '<path d="M12 20.5S4.5 15.9 2.7 11C1.6 7.6 3.6 4.5 6.9 4.5c1.9 0 3.4 1 4.3 2.4.9-1.4 2.4-2.4 4.3-2.4 3.3 0 5.3 3.1 4.2 6.5-1.8 4.9-9.3 9.5-9.3 9.5Z"/>',
	'✅': '<path d="M4 12.5l5 5L20 6.5"/>',
	'🔎': '<circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21"/>',
	'🔍': '<circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21"/>',
	'📺': '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M8 21h8"/>',
	'▶': '<path d="M7 4l12 8-12 8z"/>',
	'💬': '<path d="M5 5h14v10H9l-4 4z"/>',
	'◉': '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/>',
	'🛡️': '<path d="M12 2l8 3v6c0 5-3 9-8 11-5-2-8-6-8-11V5z"/><path d="M9 11.5l2 2 4-4"/>',
	'🏷️': '<path d="M3 4h8l10 10-7 7L4 11z"/><circle cx="8" cy="8" r="1.2"/>',
};

const renderIconText = (parent, text) => {
	parent.textContent = '';
	const str = String(text || '');
	if (!str) return;
	const parts = str.split(EMOJI_RE);
	const emojis = str.match(EMOJI_RE) || [];
	for (let i = 0; i < parts.length; i += 1) {
		if (parts[i]) parent.appendChild(document.createTextNode(parts[i]));
		if (emojis[i]) {
			const markup = EMOJI_SVG[emojis[i]];
			if (markup) {
				const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
				svg.setAttribute('class', 'dbv9-emo');
				svg.setAttribute('viewBox', '0 0 24 24');
				svg.setAttribute('aria-hidden', 'true');
				svg.setAttribute('focusable', 'false');
				svg.innerHTML = markup;
				parent.appendChild(svg);
			}
		}
	}
};


	const appendHeartIcon = (parent) => {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		const path = document.createElementNS(ns, 'path');
		path.setAttribute('d', 'M12 20.5S4.5 15.9 2.7 11C1.6 7.6 3.6 4.5 6.9 4.5c1.9 0 3.4 1 4.3 2.4.9-1.4 2.4-2.4 4.3-2.4 3.3 0 5.3 3.1 4.2 6.5-1.8 4.9-9.3 9.5-9.3 9.5Z');
		svg.appendChild(path);
		parent.appendChild(svg);
	};

	const appendCartIcon = (button) => {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('class', 'delicat-product-card__cta-svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		const path = document.createElementNS(ns, 'path');
		path.setAttribute('fill', 'currentColor');
		path.setAttribute('d', 'M7.2 18.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Zm9.2 0a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6ZM6.1 5.3l.5 2.1h11.7l-1.3 5.4a1.4 1.4 0 0 1-1.4 1.1H8.2a1.4 1.4 0 0 1-1.4-1.1L4.9 4.9H2.8a1 1 0 1 1 0-2h2.9l.4 2.4Zm1 4.1.6 2.5h7.5l.6-2.5H7.1Z');
		svg.appendChild(path);
		button.appendChild(svg);
	};

	const appendPrice = (parent, data, label) => {
		if (!data?.p?.n) return;
		const price = document.createElement('span');
		price.className = data.sub ? 'delicat-product-card__poster-price' : 'delicat-product-card__price';

		const prefix = document.createElement('span');
		prefix.textContent = label;
		price.appendChild(prefix);

		const strong = document.createElement('strong');
		const symbol = document.createElement('span');
		symbol.className = 'delicat-price-symbol';
		symbol.textContent = String(data.p.s || '');
		const number = document.createElement('span');
		number.className = 'delicat-price-number';
		number.textContent = String(data.p.n || '');
		strong.append(symbol, number);
		price.appendChild(strong);
		parent.appendChild(price);
	};

	const buildCard = (carousel, data) => {
		if (!data || !Number.isInteger(Number(data.id))) return null;

		const productId = Number(data.id);
		const href = safeUrl(data.u, true);
		if (!href) return null;

		const article = document.createElement('article');
		article.className = 'delicat-product-card';
		article.setAttribute('role', 'listitem');
		article.dataset.delicatCard = '';
		article.dataset.productId = String(productId);
		article.dataset.delicatCardIndex = String(Number(data.i || 0));
		article.dataset.delicatCardStyle = String(carousel.dataset.delicatStyle || 'delicat_jeux');

		const mediaWrap = document.createElement('div');
		mediaWrap.className = 'delicat-product-card__media-wrap';

		const media = document.createElement('a');
		media.className = 'delicat-product-card__media';
		media.href = href;
		media.dataset.delicatPrefetch = '';
		media.dataset.delicatProductLink = '';

	if (data.b?.t) {
		const badge = document.createElement('span');
		badge.className = `delicat-product-card__tag delicat-product-card__tag--${classToken(data.b.k, 'category')}`;
		renderIconText(badge, data.b.t);
		media.appendChild(badge);
	}


		if (data.im?.u) {
			const imageUrl = safeUrl(data.im.u, false);
			if (imageUrl) {
				const img = document.createElement('img');
				img.className = 'delicat-product-card__image';
				img.src = imageUrl;
			img.alt = cleanText(data.n);
				img.loading = 'lazy';
				img.decoding = 'async';
				img.fetchPriority = 'low';
				img.dataset.delicatCardImage = '1';
				img.sizes = '(max-width:640px) 43vw, (max-width:960px) 30vw, 252px';
				if (Number(data.im.w) > 0) img.width = Number(data.im.w);
				if (Number(data.im.h) > 0) img.height = Number(data.im.h);
				media.appendChild(img);
			}
		}

	if (data.st?.t) {
		const status = document.createElement('span');
		status.className = `delicat-product-card__status delicat-product-card__status--${classToken(data.st.k, 'new')}`;
		renderIconText(status, data.st.t);
		media.appendChild(status);
	}


		const startingLabel = String(carousel.dataset.delicatStartingLabel || 'À partir de');

		mediaWrap.appendChild(media);

		if (Number(data.he) === 1) {
			const heart = document.createElement('button');
			heart.type = 'button';
			heart.className = `delicat-product-card__bubble delicat-product-card__heart${Number(data.hf) === 1 ? ' is-favorite' : ''}`;
			heart.dataset.delicatHeart = '';
			heart.dataset.productId = String(productId);
			heart.setAttribute('aria-pressed', Number(data.hf) === 1 ? 'true' : 'false');
			const heartCfg = window.DelicaBuilderV9?.heart || {};
			heart.setAttribute(
				'aria-label',
				Number(data.hf) === 1
					? String(heartCfg.removeLabel || 'Retirer des favoris')
					: String(heartCfg.addLabel || 'Ajouter aux favoris')
			);
			appendHeartIcon(heart);
			mediaWrap.appendChild(heart);
	} else if (Number(data.hs) === 1) {
		const bubble = document.createElement('span');
		bubble.className = 'delicat-product-card__bubble';
		bubble.setAttribute('aria-hidden', 'true');
		renderIconText(bubble, data.hi || '♥');
		mediaWrap.appendChild(bubble);
	}


		article.appendChild(mediaWrap);

		const body = document.createElement('div');
		body.className = 'delicat-product-card__body';

		const name = document.createElement('a');
		name.className = 'delicat-product-card__name';
		name.href = href;
		name.dataset.delicatPrefetch = '';
		name.dataset.delicatProductLink = '';
		name.textContent = cleanText(data.n);
		body.appendChild(name);
		appendPrice(body, data, startingLabel);

		if (Number(data.cta) === 1) {
			const cta = document.createElement('a');
			cta.className = 'delicat-product-card__cta';
			cta.href = href;
			cta.dataset.delicatPrefetch = '';
			cta.dataset.delicatProductLink = '';
			cta.dataset.delicatInstantProduct = '';
			appendCartIcon(cta);
			const label = document.createElement('span');
			label.textContent = String(carousel.dataset.delicatBuyLabel || 'Acheter');
			cta.appendChild(label);
			body.appendChild(cta);
		}

		article.appendChild(body);
		return article;
	};

	const deferredState = (carousel) => {
		if (deferredStates.has(carousel)) return deferredStates.get(carousel);

		const script = carousel.querySelector('script[data-delicat-carousel-deferred-json]');
		if (!script) return null;

		let items = [];
		try {
			const parsed = JSON.parse(script.textContent || '[]');
			if (Array.isArray(parsed)) items = parsed;
		} catch (_) {}

		const state = { items, cursor: 0 };
		deferredStates.set(carousel, state);
		script.remove();
		return state;
	};

	const metricCache = new WeakMap();

	const completionQueue = new Set();
	let completionFrame = 0;

	const invalidateMetrics = (carousel) => metricCache.delete(carousel);

	const hasDeferred = (carousel) => {
		const template = carousel.querySelector('template[data-delicat-carousel-deferred]');
		if (template?.content?.children?.length) return true;
		const state = deferredStates.get(carousel);
		if (state) return state.cursor < state.items.length;
		return Boolean(carousel.querySelector('script[data-delicat-carousel-deferred-json]'));
	};

	const materialize = (carousel, force = false) => {
		const track = carousel.querySelector('[data-delicat-track]');
		if (!track || (!force && carousel.dataset.delicatActive !== '1')) return false;

		const managed = carousel.dataset.delicatManagedHomepage === '1';
		const batch = managed ? (core.lowPower ? 1 : 2) : (core.lowPower ? 2 : 4);
		const fragment = document.createDocumentFragment();
		let appended = 0;

		const state = deferredState(carousel);
		if (state && state.cursor < state.items.length) {
			for (let i = 0; i < batch && state.cursor < state.items.length; i += 1) {
				const card = buildCard(carousel, state.items[state.cursor]);
				state.cursor += 1;
				if (card) {
					fragment.appendChild(card);
					appended += 1;
				}
			}
		} else {
			const template = carousel.querySelector('template[data-delicat-carousel-deferred]');
			if (template?.content?.children?.length) {
				for (let i = 0; i < batch && template.content.firstElementChild; i += 1) {
					fragment.appendChild(template.content.firstElementChild);
					appended += 1;
				}
				if (!template.content.children.length) template.remove();
			}
		}

		if (!appended) return false;
		track.appendChild(fragment);
		invalidateMetrics(carousel);
		carousel.dispatchEvent(new CustomEvent('delicat:carousel-materialized', { bubbles: true }));
		return true;
	};

const materializeUntil = (carousel, targetCards, maxBatches = 16) => {
	const track = carousel.querySelector('[data-delicat-track]');
	if (!track) return 0;

	const target = Math.max(0, Math.min(
		Number(carousel.dataset.delicatTotal || 0),
		Number(targetCards || 0)
	));
	let batches = 0;

	while (
		track.querySelectorAll('[data-delicat-card]').length < target
		&& hasDeferred(carousel)
		&& batches < maxBatches
	) {
		if (!materialize(carousel, true)) break;
		batches += 1;
	}
	return batches;
};

const prefill = (carousel, aggressive = false) => {
	const track = carousel.querySelector('[data-delicat-track]');
	if (!track || !hasDeferred(carousel)) return;

	const managed = carousel.dataset.delicatManagedHomepage === '1';
	const m = metrics(carousel, track);
	const existing = track.querySelectorAll('[data-delicat-card]').length;
	const initial = Math.max(1, Number(carousel.dataset.delicatInitial || existing || 1));
	const baseline = managed
		? Math.max(initial, m.visible + 1)
		: Math.max(initial, m.visible + 2);
	const target = aggressive
		? Math.min(m.total, Math.max(baseline, existing + (managed ? 2 : 4)))
		: Math.min(m.total, baseline);

	materializeUntil(carousel, target, aggressive ? 8 : 4);
};

	const requestMaterialize = (carousel, force = false) => {
		if (force) {
			requestAnimationFrame(() => materialize(carousel, true));
			return;
		}
		idle(() => materialize(carousel, false));
	};

	const flushCompletionQueue = () => {
		completionFrame = 0;
		if (!completionQueue.size) return;

		const frameBudget = core.lowPower ? 1 : 2;
		let processed = 0;

		for (const carousel of Array.from(completionQueue)) {
			if (!carousel.isConnected || !hasDeferred(carousel)) {
				completionQueue.delete(carousel);
				continue;
			}

			completionQueue.delete(carousel);
			materialize(carousel, true);
			if (hasDeferred(carousel)) completionQueue.add(carousel);

			processed += 1;
			if (processed >= frameBudget) break;
		}

		if (completionQueue.size) {
			completionFrame = requestAnimationFrame(flushCompletionQueue);
		}
	};

	const completeCarousel = (carousel) => {
		if (!carousel?.isConnected || !hasDeferred(carousel)) return;
		completionQueue.add(carousel);
		if (!completionFrame) completionFrame = requestAnimationFrame(flushCompletionQueue);
	};

	const completeManagedCarousels = (scope = document) => {
		scope.querySelectorAll('[data-delicat-carousel][data-delicat-managed-homepage="1"]').forEach((carousel) => {
			completeCarousel(carousel);
		});
	};

	const metrics = (carousel, track) => {
		const cached = metricCache.get(carousel);
		if (cached) return cached;

		const card = track.querySelector('[data-delicat-card]');
		if (!card) {
			const fallback = {
				cardStep: Math.max(1, track.clientWidth),
				visible: 1,
				total: 1,
				mode: 'pages'
			};
			metricCache.set(carousel, fallback);
			return fallback;
		}

		const style = getComputedStyle(track);
		const gap = parseFloat(style.columnGap || style.gap || '0') || 0;
		const cardStep = Math.max(1, card.offsetWidth + gap);
		const visible = Math.max(1, Math.floor((track.clientWidth + gap) / cardStep));
		const total = Math.max(1, Number(carousel.dataset.delicatTotal || 1));
		const mode = carousel.dataset.delicatPaginationMode === 'cards' ? 'cards' : 'pages';
		const result = { cardStep, visible, total, mode };
		metricCache.set(carousel, result);
		return result;
	};

	const nearEnd = (track, managed = false) => {
		const remaining = track.scrollWidth - track.scrollLeft - track.clientWidth;
		return remaining < Math.max(managed ? 90 : 260, track.clientWidth * (managed ? 0.3 : 0.7));
	};

	const setActive = (carousel, active) => {
		if (active) {
			carousel.dataset.delicatActive = '1';
			carousel.classList.add('is-active');

			prefill(carousel, false);
		} else {
			delete carousel.dataset.delicatActive;
			carousel.classList.remove('is-active');

		}
	};

	const managedObserver = supportsIO
		? new IntersectionObserver((entries) => {
			for (const entry of entries) setActive(entry.target, entry.isIntersecting);
		}, { root: null, rootMargin: core.lowPower ? '80px 0px' : '180px 0px', threshold: 0.01 })
		: null;

	const normalObserver = supportsIO
		? new IntersectionObserver((entries) => {
			for (const entry of entries) setActive(entry.target, entry.isIntersecting);
		}, { root: null, rootMargin: core.lowPower ? '120px 0px' : '280px 0px', threshold: 0.01 })
		: null;

	const init = (scope = document) => {
		scope.querySelectorAll('[data-delicat-carousel]').forEach((carousel) => {
			if (carousel.dataset.delicatReady === '1') return;
			carousel.dataset.delicatReady = '1';

			const track = carousel.querySelector('[data-delicat-track]');
			const prev = carousel.querySelector('[data-delicat-prev]');
			const next = carousel.querySelector('[data-delicat-next]');
			const pagination = carousel.querySelector('[data-delicat-pagination]');
			if (!track) return;

			const managed = carousel.dataset.delicatManagedHomepage === '1';
			const behavior = core.reducedMotion ? 'auto' : 'smooth';
			let dots = [];
			let scrollTick = false;

			const activeIndex = () => {
				const m = metrics(carousel, track);
				if (m.mode === 'cards') {
					return Math.max(0, Math.min(Math.max(0, m.total - m.visible), Math.round(track.scrollLeft / m.cardStep)));
				}
				return Math.max(0, Math.round(track.scrollLeft / Math.max(1, track.clientWidth)));
			};

			const dotCount = () => {
				const m = metrics(carousel, track);
				if (m.mode === 'cards') return Math.max(1, m.total - m.visible + 1);
				const fullWidth = Math.max(1, m.cardStep * m.total);
				return Math.max(1, Math.ceil(fullWidth / Math.max(1, track.clientWidth)));
			};

			const materializeForDot = (index) => {
				const m = metrics(carousel, track);
				const neededCards = Math.min(
					m.total,
					m.mode === 'cards' ? index + m.visible + 1 : (index + 1) * m.visible + 1
				);
				let safety = 0;
				while (
					track.querySelectorAll('[data-delicat-card]').length < neededCards
					&& hasDeferred(carousel)
					&& safety < 12
				) {
					if (!materialize(carousel, true)) break;
					safety += 1;
				}
			};

			const syncDots = () => {
				if (!dots.length) return;
				const index = Math.max(0, Math.min(dots.length - 1, activeIndex()));
				dots.forEach((dot, i) => {
					const active = i === index;
					dot.classList.toggle('is-active', active);
					dot.setAttribute('aria-pressed', active ? 'true' : 'false');
				});
			};

			const buildDots = () => {
				if (!pagination) return;
				const count = dotCount();
				if (dots.length === count) {
					syncDots();
					return;
				}
				pagination.replaceChildren();
				dots = Array.from({ length: count }, (_, i) => {
					const dot = document.createElement('button');
					dot.type = 'button';
					dot.className = 'delicat-carousel__dot';
					dot.dataset.delicatDot = String(i);
					dot.setAttribute('aria-label', `Go to slide ${i + 1}`);
					dot.addEventListener('click', () => {
						materializeForDot(i);
						requestAnimationFrame(() => {
							const m = metrics(carousel, track);
							const left = m.mode === 'cards' ? m.cardStep * i : track.clientWidth * i;
							track.scrollTo({ left, behavior });
						});
					});
					pagination.appendChild(dot);
					return dot;
				});
				syncDots();
			};

			const scrollAmount = (direction) => {
				const m = metrics(carousel, track);
				return direction * (m.mode === 'cards' ? m.cardStep : Math.max(180, Math.round(track.clientWidth * 0.82)));
			};

			prev?.addEventListener('click', () => track.scrollBy({ left: scrollAmount(-1), behavior }));
			next?.addEventListener('click', () => {
				if (nearEnd(track, managed)) materialize(carousel, true);
				track.scrollBy({ left: scrollAmount(1), behavior });
			});

			track.addEventListener('scroll', () => {
				if (scrollTick) return;
				if (carousel.dataset.delicatActive !== '1') setActive(carousel, true);
				scrollTick = true;
				requestAnimationFrame(() => {
					scrollTick = false;
					if (nearEnd(track, managed) && hasDeferred(carousel)) {

						prefill(carousel, true);
						completeCarousel(carousel);
					}
					syncDots();
				});
			}, { passive: true });

			const interactionPrefill = (event) => {

				if (event?.target?.closest?.('a[href],button,input,select,textarea,label')) return;

				if (carousel.dataset.delicatActive !== '1') setActive(carousel, true);
				if (hasDeferred(carousel)) {
					prefill(carousel, true);
					completeCarousel(carousel);
				}
			};
			if ('PointerEvent' in window) {
				track.addEventListener('pointerdown', interactionPrefill, { passive: true });
			} else {
				track.addEventListener('touchstart', interactionPrefill, { passive: true });
			}
			track.addEventListener('focusin', interactionPrefill);

			track.addEventListener('keydown', (event) => {
				if (event.key === 'ArrowRight') {
					event.preventDefault();
					next?.click();
				} else if (event.key === 'ArrowLeft') {
					event.preventDefault();
					prev?.click();
				}
			});

			buildDots();
			const resizeCallback = () => {
				invalidateMetrics(carousel);
				requestAnimationFrame(buildDots);
			};
			if (sharedResizeObserver) {
				resizeCallbacks.set(track, resizeCallback);
				sharedResizeObserver.observe(track);
			} else {
				fallbackResizeCallbacks.add(resizeCallback);
			}

			if (supportsIO) (managed ? managedObserver : normalObserver)?.observe(carousel);
			else setActive(carousel, true);

			window.setTimeout(() => {
				if (!carousel.isConnected || !hasDeferred(carousel)) return;
				const rect = carousel.getBoundingClientRect();
				const nearViewport = rect.bottom >= -200 && rect.top <= window.innerHeight * 1.65;
				if (nearViewport) {
					setActive(carousel, true);
					prefill(carousel, false);
				}
			}, core.lowPower ? 900 : 520);
		});
	};

	const recoverVisibleCarousels = () => {
		document.querySelectorAll('[data-delicat-carousel]').forEach((carousel) => {
			if (!hasDeferred(carousel)) return;
			const rect = carousel.getBoundingClientRect();
			if (rect.bottom >= -200 && rect.top <= window.innerHeight * 1.35) {
				setActive(carousel, true);
				prefill(carousel, false);
			}
		});
	};

	const completeNearViewport = () => {
		document.querySelectorAll('[data-delicat-carousel][data-delicat-managed-homepage="1"]').forEach((carousel) => {
			if (!hasDeferred(carousel)) return;
			const rect = carousel.getBoundingClientRect();
			const nearViewport = rect.bottom >= -240 && rect.top <= window.innerHeight * 1.45;
			if (!nearViewport) return;
			setActive(carousel, true);
			prefill(carousel, false);
		});
	};

	const postLoadComplete = () => {

		window.setTimeout(completeNearViewport, core.lowPower ? 520 : 240);
	};

	window.DelicaBuilderV9Carousel = {
		init,
		materialize,
		prefill,
		complete: completeCarousel
	};
	init();

	if ('complete' === document.readyState) {
		postLoadComplete();
	} else {
		window.addEventListener('load', postLoadComplete, { once: true, passive: true });
	}

	window.addEventListener('pageshow', () => {
		recoverVisibleCarousels();
		completeNearViewport();
	}, { passive: true });

	document.addEventListener('visibilitychange', () => {
		if (!document.hidden) {
			recoverVisibleCarousels();
			completeNearViewport();
		}
	});

	document.addEventListener('delicat:navigation-complete', () => {
		init(document);
		recoverVisibleCarousels();
		window.setTimeout(completeNearViewport, core.lowPower ? 360 : 140);
	});
})();
