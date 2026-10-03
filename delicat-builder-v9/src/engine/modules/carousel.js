/**
 * Product rails / carousels.
 *
 * Same markup contract as before (the server renders it, products.css styles it):
 *   [data-delicat-carousel]  data-delicat-total, data-delicat-initial, data-delicat-style,
 *                            data-delicat-managed-homepage, data-delicat-pagination-mode,
 *                            data-delicat-starting-label, data-delicat-buy-label
 *     [data-delicat-track]   the native scroller (CSS scroll-snap), holds [data-delicat-card]
 *     [data-delicat-prev] / [data-delicat-next] / [data-delicat-pagination]
 *     template[data-delicat-carousel-deferred]        server-built cards beyond the first N
 *     script[data-delicat-carousel-deferred-json]     or a JSON payload the browser builds from
 *
 * What changed versus carousel.js:
 *  - a rail that is near the viewport materialises ALL of its cards in a few
 *    frames instead of two at a time on idle, so a rail is never half empty
 *    when the shopper scrolls to it (the old blank-rail problem);
 *  - no IntersectionObserver churn: one observer, one activation;
 *  - everything binds through the module signal, so a soft navigation releases
 *    every listener and observer with no bookkeeping.
 * Visual behaviour (snapping, dots, arrows, keyboard, like button, badges,
 * srcset sizing) is unchanged.
 */
import { device, doc, emit, on, qsa, raf, safeUrl, cleanText, win } from '../core/dom.js';

const CARD_SIZES = '(max-width:960px) 41vw, 252px';
const LIKE_PATH = 'M12 20.6C11.4 20.2 3 14.9 3 9.1 3 6.4 5 4.4 7.5 4.4c1.9 0 3.6 1.1 4.5 2.7.9-1.6 2.6-2.7 4.5-2.7 2.5 0 4.5 2 4.5 4.7 0 5.8-8.4 11.1-9 11.5Z';
const CART_PATH = 'M7.2 18.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Zm9.2 0a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6ZM6.1 5.3l.5 2.1h11.7l-1.3 5.4a1.4 1.4 0 0 1-1.4 1.1H8.2a1.4 1.4 0 0 1-1.4-1.1L4.9 4.9H2.8a1 1 0 1 1 0-2h2.9l.4 2.4Zm1 4.1.6 2.5h7.5l.6-2.5H7.1Z';
const SVG = 'http://www.w3.org/2000/svg';

const classToken = (value, fallback) => String(value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '') || fallback;

const safeSrcset = (value) => {
	if (typeof value !== 'string' || !value) return '';
	const out = [];
	for (const part of value.split(',')) {
		const match = part.trim().match(/^(\S+)\s+(\d{1,4})w$/);
		if (!match) return '';
		const url = safeUrl(match[1], false);
		if (!url) return '';
		out.push(url + ' ' + Number(match[2]) + 'w');
	}
	return out.length > 1 ? out.join(', ') : '';
};

const badgeIcons = () => win.DelicatBadgeIcons || {};

function renderBadge(parent, badge) {
	parent.textContent = '';
	if (!badge || (!badge.t && !badge.ic)) return;
	const markup = badgeIcons()[String(badge.ic || '')];
	if (markup) {
		const svg = doc.createElementNS(SVG, 'svg');
		for (const [name, value] of Object.entries({ class: 'delicat-badge__icon', viewBox: '0 0 24 24', width: '14', height: '14', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.75', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' })) svg.setAttribute(name, value);
		svg.innerHTML = markup; /* server-authored constant */
		parent.appendChild(svg);
	}
	if (badge.t) {
		const text = doc.createElement('span');
		text.className = 'delicat-badge__text';
		text.textContent = String(badge.t);
		parent.appendChild(text);
	}
}

function buildLikeButton(productId, liked) {
	const cfg = (win.DelicaBuilderV9 && win.DelicaBuilderV9.heart) || {};
	const d = typeof cfg.path === 'string' && /^[MmCcLlHhVvSsZz0-9.\s-]+$/.test(cfg.path) ? cfg.path : LIKE_PATH;
	const button = doc.createElement('button');
	button.type = 'button';
	button.className = 'delicat-like delicat-product-card__like';
	button.dataset.delicatLike = '';
	button.dataset.productId = String(productId);
	button.setAttribute('aria-pressed', liked ? 'true' : 'false');
	button.setAttribute('aria-label', liked ? String(cfg.removeLabel || 'Retirer des favoris') : String(cfg.addLabel || 'Ajouter aux favoris'));
	const chip = doc.createElement('span');
	chip.className = 'delicat-like__chip';
	chip.setAttribute('aria-hidden', 'true');
	for (const name of ['delicat-like__outline', 'delicat-like__fill']) {
		const svg = doc.createElementNS(SVG, 'svg');
		svg.setAttribute('class', name);
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('width', '18');
		svg.setAttribute('height', '18');
		svg.setAttribute('focusable', 'false');
		const path = doc.createElementNS(SVG, 'path');
		path.setAttribute('d', d);
		svg.appendChild(path);
		chip.appendChild(svg);
	}
	button.appendChild(chip);
	return button;
}

function appendCartIcon(button) {
	const svg = doc.createElementNS(SVG, 'svg');
	svg.setAttribute('class', 'delicat-product-card__cta-svg');
	svg.setAttribute('viewBox', '0 0 24 24');
	svg.setAttribute('aria-hidden', 'true');
	svg.setAttribute('focusable', 'false');
	const path = doc.createElementNS(SVG, 'path');
	path.setAttribute('fill', 'currentColor');
	path.setAttribute('d', CART_PATH);
	svg.appendChild(path);
	button.appendChild(svg);
}

function appendPrice(parent, data, label) {
	if (!data || !data.p || !data.p.n) return;
	const price = doc.createElement('span');
	price.className = data.sub ? 'delicat-product-card__poster-price' : 'delicat-product-card__price';
	const prefix = doc.createElement('span');
	prefix.textContent = label;
	price.appendChild(prefix);
	const strong = doc.createElement('strong');
	const symbol = doc.createElement('span');
	symbol.className = 'delicat-price-symbol';
	symbol.textContent = String(data.p.s || '');
	const number = doc.createElement('span');
	number.className = 'delicat-price-number';
	number.textContent = String(data.p.n || '');
	strong.append(symbol, number);
	price.appendChild(strong);
	parent.appendChild(price);
}

export function buildCard(carousel, data) {
	if (!data || !Number.isInteger(Number(data.id))) return null;
	const productId = Number(data.id);
	const href = safeUrl(data.u, true);
	if (!href) return null;

	const article = doc.createElement('article');
	article.className = 'delicat-product-card';
	article.setAttribute('role', 'listitem');
	article.dataset.delicatCard = '';
	article.dataset.productId = String(productId);
	article.dataset.delicatCardIndex = String(Number(data.i || 0));
	article.dataset.delicatCardStyle = String(carousel.dataset.delicatStyle || 'delicat_jeux');

	const mediaWrap = doc.createElement('div');
	mediaWrap.className = 'delicat-product-card__media-wrap';
	const media = doc.createElement('a');
	media.className = 'delicat-product-card__media';
	media.href = href;
	media.dataset.delicatPrefetch = '';
	media.dataset.delicatProductLink = '';

	if (data.b && data.b.t) {
		const badge = doc.createElement('span');
		badge.className = `delicat-badge delicat-badge--topic delicat-badge--${classToken(data.b.o, 'topic')} delicat-badge--${classToken(data.b.k, 'topic')}`;
		renderBadge(badge, data.b);
		media.appendChild(badge);
	}
	if (data.im && data.im.u) {
		const imageUrl = safeUrl(data.im.u, false);
		if (imageUrl) {
			const img = doc.createElement('img');
			img.className = 'delicat-product-card__image';
			img.alt = cleanText(data.n);
			img.loading = 'lazy';
			img.decoding = 'async';
			img.fetchPriority = 'low';
			img.dataset.delicatCardImage = '1';
			if (Number(data.im.w) > 0) img.width = Number(data.im.w);
			if (Number(data.im.h) > 0) img.height = Number(data.im.h);
			const srcset = safeSrcset(data.im.ss);
			if (srcset) { img.sizes = CARD_SIZES; img.srcset = srcset; }
			img.src = imageUrl;
			media.appendChild(img);
		}
	}
	if (data.st && data.st.t) {
		const status = doc.createElement('span');
		status.className = `delicat-badge delicat-badge--status delicat-badge--${classToken(data.st.o, 'status')} delicat-badge--${classToken(data.st.k, 'status')}`;
		renderBadge(status, data.st);
		media.appendChild(status);
	}

	mediaWrap.appendChild(media);
	if (Number(data.he) === 1) mediaWrap.appendChild(buildLikeButton(productId, Number(data.hf) === 1));
	article.appendChild(mediaWrap);

	const body = doc.createElement('div');
	body.className = 'delicat-product-card__body';
	const poster = carousel.dataset.delicatManagedHomepage === '1';
	const name = doc.createElement(poster ? 'span' : 'a');
	name.className = poster ? 'delicat-product-card__poster-title' : 'delicat-product-card__name';
	if (!poster) { name.href = href; name.dataset.delicatPrefetch = ''; name.dataset.delicatProductLink = ''; }
	name.textContent = cleanText(data.n);
	(poster ? media : body).appendChild(name);
	appendPrice(body, data, String(carousel.dataset.delicatStartingLabel || 'À partir de'));

	if (Number(data.cta) === 1) {
		const cta = doc.createElement('a');
		cta.className = 'delicat-product-card__cta';
		cta.href = href;
		cta.dataset.delicatPrefetch = '';
		cta.dataset.delicatProductLink = '';
		cta.dataset.delicatInstantProduct = '';
		appendCartIcon(cta);
		const label = doc.createElement('span');
		label.textContent = String(carousel.dataset.delicatBuyLabel || 'Acheter');
		if (!poster) cta.appendChild(label);
		cta.setAttribute('aria-label', 'Voir les options de ' + cleanText(data.n));
		body.appendChild(cta);
	}
	article.appendChild(body);
	return article;
}

/* ---------------------------------------------------------------- state */

const rails = new WeakMap();

function railState(carousel) {
	let s = rails.get(carousel);
	if (s) return s;
	s = { items: null, cursor: 0, metrics: null, dots: [], building: false, done: false };
	rails.set(carousel, s);
	return s;
}

function deferredItems(carousel, s) {
	if (s.items) return s.items;
	const script = carousel.querySelector('script[data-delicat-carousel-deferred-json]');
	let items = [];
	if (script) {
		try { const parsed = JSON.parse(script.textContent || '[]'); if (Array.isArray(parsed)) items = parsed; } catch (_) {}
		script.remove();
	}
	s.items = items;
	return items;
}

function hasDeferred(carousel, s) {
	const template = carousel.querySelector('template[data-delicat-carousel-deferred]');
	if (template && template.content && template.content.children.length) return true;
	const items = deferredItems(carousel, s);
	return s.cursor < items.length;
}

function materialize(carousel, s, batch) {
	const track = carousel.querySelector('[data-delicat-track]');
	if (!track) return 0;
	const fragment = doc.createDocumentFragment();
	let appended = 0;
	const items = deferredItems(carousel, s);
	while (appended < batch && s.cursor < items.length) {
		const card = buildCard(carousel, items[s.cursor++]);
		if (card) { fragment.appendChild(card); appended++; }
	}
	if (appended < batch) {
		const template = carousel.querySelector('template[data-delicat-carousel-deferred]');
		if (template && template.content) {
			while (appended < batch && template.content.firstElementChild) { fragment.appendChild(template.content.firstElementChild); appended++; }
			if (!template.content.children.length) template.remove();
		}
	}
	if (!appended) return 0;
	track.appendChild(fragment);
	s.metrics = null;
	emit('delicat:carousel-materialized', { carousel }, carousel);
	return appended;
}

/** Build every remaining card over a few frames (never on the tap path). */
function complete(carousel, s) {
	if (s.building || s.done) return;
	if (!hasDeferred(carousel, s)) { s.done = true; return; }
	s.building = true;
	const batch = device.lowPower ? 3 : 6;
	const step = () => {
		if (!carousel.isConnected) { s.building = false; return; }
		materialize(carousel, s, batch);
		if (hasDeferred(carousel, s)) raf(step);
		else { s.building = false; s.done = true; }
	};
	raf(step);
}

function metrics(carousel, s, track) {
	if (s.metrics) return s.metrics;
	const card = track.querySelector('[data-delicat-card]');
	if (!card) return { cardStep: Math.max(1, track.clientWidth), visible: 1, total: 1, mode: 'pages' };
	const style = getComputedStyle(track);
	const gap = parseFloat(style.columnGap || style.gap || '0') || 0;
	const cardStep = Math.max(1, card.offsetWidth + gap);
	const visible = Math.max(1, Math.floor((track.clientWidth + gap) / cardStep));
	const total = Math.max(1, Number(carousel.dataset.delicatTotal || track.querySelectorAll('[data-delicat-card]').length || 1));
	const mode = carousel.dataset.delicatPaginationMode === 'cards' ? 'cards' : 'pages';
	s.metrics = { cardStep, visible, total, mode };
	return s.metrics;
}

/* ------------------------------------------------------------------ init */

let sharedResize = null;
const resizeCallbacks = new WeakMap();

export function initCarousel(carousel, signal) {
	if (carousel.dataset.delicatReady === '1') return;
	carousel.dataset.delicatReady = '1';
	const track = carousel.querySelector('[data-delicat-track]');
	if (!track) return;
	const s = railState(carousel);
	const prev = carousel.querySelector('[data-delicat-prev]');
	const next = carousel.querySelector('[data-delicat-next]');
	const pagination = carousel.querySelector('[data-delicat-pagination]');
	const behavior = device.reducedMotion ? 'auto' : 'smooth';

	const activate = () => {
		if (carousel.dataset.delicatActive === '1') return;
		carousel.dataset.delicatActive = '1';
		carousel.classList.add('is-active');
		complete(carousel, s);
	};

	const activeIndex = () => {
		const m = metrics(carousel, s, track);
		const atEnd = track.scrollLeft >= track.scrollWidth - track.clientWidth - 2;
		if (m.mode === 'cards') {
			const last = Math.max(0, m.total - m.visible);
			return atEnd ? last : Math.max(0, Math.min(last, Math.round(track.scrollLeft / m.cardStep)));
		}
		/* pages: the final page is usually partial, so the last dot is lit whenever the track is at its end. */
		if (atEnd && s.dots.length) return s.dots.length - 1;
		return Math.max(0, Math.round(track.scrollLeft / Math.max(1, track.clientWidth)));
	};
	const dotCount = () => {
		const m = metrics(carousel, s, track);
		if (m.mode === 'cards') return Math.max(1, m.total - m.visible + 1);
		return Math.max(1, Math.ceil(Math.max(1, m.cardStep * m.total) / Math.max(1, track.clientWidth)));
	};
	const syncDots = () => {
		if (!s.dots.length) return;
		const index = Math.max(0, Math.min(s.dots.length - 1, activeIndex()));
		s.dots.forEach((dot, i) => { const active = i === index; dot.classList.toggle('is-active', active); dot.setAttribute('aria-pressed', active ? 'true' : 'false'); });
	};
	const buildDots = () => {
		if (!pagination) return;
		const count = dotCount();
		if (s.dots.length === count) { syncDots(); return; }
		pagination.replaceChildren();
		s.dots = Array.from({ length: count }, (_, i) => {
			const dot = doc.createElement('button');
			dot.type = 'button';
			dot.className = 'delicat-carousel__dot';
			dot.dataset.delicatDot = String(i);
			dot.setAttribute('aria-label', `Aller à la diapositive ${i + 1}`);
			on(dot, 'click', () => {
				activate();
				raf(() => {
					const m = metrics(carousel, s, track);
					track.scrollTo({ left: m.mode === 'cards' ? m.cardStep * i : track.clientWidth * i, behavior });
				});
			}, { signal });
			pagination.appendChild(dot);
			return dot;
		});
		syncDots();
	};
	const scrollAmount = (direction) => {
		const m = metrics(carousel, s, track);
		return direction * (m.mode === 'cards' ? m.cardStep : Math.max(180, Math.round(track.clientWidth * 0.82)));
	};

	if (prev) on(prev, 'click', () => { activate(); track.scrollBy({ left: scrollAmount(-1), behavior }); }, { signal });
	if (next) on(next, 'click', () => { activate(); track.scrollBy({ left: scrollAmount(1), behavior }); }, { signal });

	let scrollTick = false;
	on(track, 'scroll', () => {
		if (scrollTick) return;
		scrollTick = true;
		activate();
		raf(() => { scrollTick = false; syncDots(); });
	}, { passive: true, signal });

	const interaction = (event) => {
		if (event && event.target && event.target.closest && event.target.closest('a[href],button,input,select,textarea,label')) { activate(); return; }
		activate();
	};
	on(track, 'PointerEvent' in win ? 'pointerdown' : 'touchstart', interaction, { passive: true, signal });
	on(track, 'focusin', interaction, { signal });
	on(track, 'keydown', (event) => {
		if (event.key === 'ArrowRight') { event.preventDefault(); if (next) next.click(); else track.scrollBy({ left: scrollAmount(1), behavior }); }
		else if (event.key === 'ArrowLeft') { event.preventDefault(); if (prev) prev.click(); else track.scrollBy({ left: scrollAmount(-1), behavior }); }
	}, { signal });

	buildDots();
	const onResize = () => { s.metrics = null; raf(buildDots); };
	if ('ResizeObserver' in win) {
		if (!sharedResize) sharedResize = new ResizeObserver((entries) => { for (const entry of entries) { const fn = resizeCallbacks.get(entry.target); if (fn) fn(); } });
		resizeCallbacks.set(track, onResize);
		sharedResize.observe(track);
		signal.addEventListener('abort', () => { sharedResize.unobserve(track); resizeCallbacks.delete(track); }, { once: true });
	} else {
		on(win, 'resize', onResize, { passive: true, signal });
	}

	/* Activation: a rail within ~1.5 screens of the viewport builds its cards now. */
	if ('IntersectionObserver' in win) {
		const observer = new IntersectionObserver((entries) => {
			for (const entry of entries) if (entry.isIntersecting) { activate(); observer.disconnect(); }
		}, { rootMargin: device.lowPower ? '160px 0px' : '420px 0px', threshold: 0.01 });
		observer.observe(carousel);
		signal.addEventListener('abort', () => observer.disconnect(), { once: true });
	} else {
		activate();
	}
}

export const carouselApi = {
	init(scope = doc, signal) { for (const carousel of qsa('[data-delicat-carousel]', scope)) initCarousel(carousel, signal || new AbortController().signal); },
	materialize(carousel) { const s = railState(carousel); return materialize(carousel, s, device.lowPower ? 3 : 6) > 0; },
	prefill(carousel) { complete(carousel, railState(carousel)); },
	complete(carousel) { complete(carousel, railState(carousel)); },
	buildCard,
};

export default function mount({ root, signal }) {
	win.DelicaBuilderV9Carousel = carouselApi;
	carouselApi.init(root, signal);
	/* Cards materialised while the tab was hidden or restored from bfcache finish quietly. */
	on(doc, 'visibilitychange', () => { if (!doc.hidden) for (const carousel of qsa('[data-delicat-carousel][data-delicat-active="1"]', root)) complete(carousel, railState(carousel)); }, { signal });
	on(win, 'pageshow', () => { for (const carousel of qsa('[data-delicat-carousel][data-delicat-active="1"]', root)) complete(carousel, railState(carousel)); }, { passive: true, signal });
}
