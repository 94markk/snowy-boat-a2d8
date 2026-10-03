/**
 * Bottom navigation bar and the (legacy) mobile dock: keep the cart badge and
 * the active item honest. The badge is painted by the session store; this
 * module covers the first paint from the WooCommerce cookie, the pressed
 * state, and the active item. The server marks the item and the navigation
 * engine copies that mark on every swap; this module then re-derives it from
 * the address, so a cached document, a route the server does not recognise
 * or a stale copy can never leave the wrong tab lit.
 */
import { doc, html, on, parseUrl, qsa, win } from '../core/dom.js';
import { paintCartCount, wooCookieCount } from '../core/state.js';

const trim = (path) => String(path || '/').replace(/\/+$/, '') || '/';

/** The bar item an address belongs to, or '' when the bar has no tab for it. */
export function keyFor(url, items) {
	const path = trim(url.pathname).toLowerCase();
	for (const item of items) {
		const target = parseUrl(item.href);
		if (target && trim(target.pathname).toLowerCase() === path && target.search === url.search) return item.getAttribute('data-dbn-key') || '';
	}
	const shop = items.find((item) => item.getAttribute('data-dbn-key') === 'shop');
	const shopUrl = shop ? parseUrl(shop.href) : null;
	const shopPath = shopUrl ? trim(shopUrl.pathname).toLowerCase() : '/shop';
	if (shopPath !== '/' && path.indexOf(shopPath + '/') === 0) return 'shop';
	if (/\/(?:product|produit|product-category|categorie-produit|product-tag|etiquette-produit)(?:\/|$)/.test(path)) return 'shop';
	if (url.searchParams.has('s') && url.searchParams.get('post_type') === 'product') return 'shop';
	if (/\/(?:my-wallet|woo-wallet|wallet|portefeuille)(?:\/|$)/.test(path)) return 'wallet';
	if (/\/(?:cart|panier)(?:\/|$)/.test(path)) return 'cart';
	if (/\/(?:my-account|mon-compte)(?:\/|$)/.test(path)) return 'account';
	return path === '/' ? 'home' : '';
}

/** Light the tab the current address belongs to. */
export function syncActive() {
	const items = qsa('.delicat-bottom-nav .dbn-item[data-dbn-key]');
	if (!items.length) return;
	const url = parseUrl(location.href);
	const key = url ? keyFor(url, items) : '';
	for (const item of items) {
		const active = key ? item.getAttribute('data-dbn-key') === key : item.classList.contains('is-active');
		item.classList.toggle('is-active', active);
		if (active) item.setAttribute('aria-current', 'page'); else item.removeAttribute('aria-current');
	}
}

export default function mount({ signal }) {
	const sync = () => { const n = wooCookieCount(); if (n !== null) paintCartCount(n); };
	sync();
	on(win, 'pageshow', sync, { passive: true, signal });
	on(doc, 'wc-blocks_added_to_cart', () => win.setTimeout(sync, 0), { signal });
	on(doc, 'wc-blocks_removed_from_cart', () => win.setTimeout(sync, 0), { signal });

	/* A tap on the bar should feel immediate: press state before the navigation starts. */
	on(doc, 'pointerdown', (event) => {
		const item = event.target instanceof Element ? event.target.closest('.delicat-bottom-nav .dbn-item, [data-delicat-mobile-dock] a') : null;
		if (!item) return;
		item.classList.add('is-pressed');
		const release = () => item.classList.remove('is-pressed');
		win.setTimeout(release, 420);
		on(doc, 'pointerup', release, { once: true, passive: true });
		on(doc, 'pointercancel', release, { once: true, passive: true });
	}, { passive: true, signal });

	syncActive();
	on(doc, 'delicat:navigated', syncActive, { signal });
	on(doc, 'delicat:placeholder', syncActive, { signal });
	on(win, 'popstate', () => win.setTimeout(syncActive, 0), { signal });

	/* 9.3.3: the bar hides while an overlay owns the screen (drawer, express
	   sheet, wallet modal, checkout dock). Those classes survive a swap on
	   purpose, so a sheet closed by the navigation itself, a page restored from
	   the back-forward cache or a cancelled transition could leave one behind
	   with nothing open, and the bar stayed hidden until the next full load.
	   Each class is checked against the element it stands for once things
	   have settled. */
	const heal = () => {
		const body = doc.body;
		if (body.classList.contains('dnp-express-open') && !doc.querySelector('[data-dnp-express].is-open')) body.classList.remove('dnp-express-open', 'dnp-express-busy');
		if (body.classList.contains('dpn-wallet-modal-open') && !doc.querySelector('[data-dpn-wallet-modal]:not([hidden])')) body.classList.remove('dpn-wallet-modal-open');
		if (body.classList.contains('dpn-checkout-dock-ready') && !body.classList.contains('dpn-checkout')) body.classList.remove('dpn-checkout-dock-ready');
		if (html.classList.contains('dlx-open') && !doc.querySelector('.dlx-drawer.is-open,.dlx-drawer.is-entering')) { html.classList.remove('dlx-open'); body.style.top = ''; }
		if (html.classList.contains('dsb-menu-open') && !doc.querySelector('.dlx-drawer.is-open')) html.classList.remove('dsb-menu-open');
		body.classList.remove('dbn-hidden');
	};
	let healTimer = 0;
	const healSoon = () => { win.clearTimeout(healTimer); healTimer = win.setTimeout(heal, 400); };
	on(doc, 'delicat:navigated', healSoon, { signal });
	on(win, 'pageshow', healSoon, { passive: true, signal });
	on(doc, 'visibilitychange', () => { if (!doc.hidden) healSoon(); }, { signal });
}
