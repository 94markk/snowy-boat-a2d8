/**
 * Session store — one copy of the truth for identity, cart count, wallet
 * balance, alert count and fresh nonces.
 *
 * Replaces the two previous stores (session.js and dbp-state.js) that each
 * polled their own endpoint on every page. Memory only; nothing touches
 * browser storage.
 *
 * Source: `config.session.url` (wc-ajax=delicat_session), which answers the
 * V9 session shape and, since 9.3, also the Pro-state fields (alerts, nonces,
 * cart total). Both shapes are accepted so an older cached document still
 * works during an upgrade.
 */
import { cookie, doc, emit, html, idle, jq, onWoo, qsa, renderedLoggedIn, win } from './dom.js';
import { config } from './runtime.js';
import { getJson } from './net.js';

const cfg = Object.assign({ minGap: 2500, stale: 20000, navStale: 30000 }, config.session || {});
const legacy = win.DelicatSessionConfig || {};
if (!cfg.url) cfg.url = legacy.url || (location.pathname + '?wc-ajax=delicat_session');
if (!cfg.resetUrl && legacy.resetUrl) cfg.resetUrl = legacy.resetUrl;

const store = { data: null, ts: 0, pending: null, queued: null, listeners: [], signature: '' };
let eventTimer = 0;
let logoutBusy = false;

/* ------------------------------------------------------------ auth flags */

let authReset = false;
let authRecover = false;
let authSync = false;
let currentUrl = null;
try {
	currentUrl = new URL(location.href);
	authReset = currentUrl.searchParams.get('delicat_auth_reset') === '1';
	authRecover = currentUrl.searchParams.get('delicat_auth_recover') === '1';
	authSync = currentUrl.searchParams.get('dip_auth_sync') === '1';
} catch (_) {
	const flag = (name) => new RegExp('(?:[?&])' + name + '=1(?:&|$)').test(location.search || '');
	authReset = flag('delicat_auth_reset');
	authRecover = flag('delicat_auth_recover');
	authSync = flag('dip_auth_sync');
}

export function clearDocumentCache() {
	try {
		if (navigator.serviceWorker && navigator.serviceWorker.controller) {
			navigator.serviceWorker.controller.postMessage({ type: 'dbv9-clear-docs' });
		}
	} catch (_) {}
}

function stripAuthFlags() {
	if (!win.history || !win.history.replaceState) return;
	try {
		if (currentUrl) {
			['delicat_auth_reset', 'delicat_auth_recover', 'dip_auth_sync'].forEach((key) => currentUrl.searchParams.delete(key));
			win.history.replaceState(win.history.state, '', currentUrl.pathname + (currentUrl.search || '') + (currentUrl.hash || ''));
		}
	} catch (_) {}
}

if (authReset || authRecover || authSync) { clearDocumentCache(); stripAuthFlags(); }

/* ---------------------------------------------------------------- shape */

function normalize(raw) {
	if (!raw || typeof raw !== 'object') return null;
	if ('loggedIn' in raw) {
		const d = raw;
		d.cart = d.cart || { count: 0 };
		d.wallet = d.wallet || { text: '', raw: null };
		d.urls = d.urls || {};
		return d;
	}
	if (raw.ok && raw.user) {
		/* Pro-state shape from an older cached page. */
		return {
			loggedIn: !!raw.user.loggedIn,
			id: raw.user.id || 0,
			name: raw.user.firstName || '',
			initial: String(raw.user.firstName || '').charAt(0).toUpperCase(),
			email: '',
			code: '',
			wallet: { text: raw.wallet && raw.wallet.enabled ? String(raw.wallet.balance || '') : '', raw: raw.wallet ? raw.wallet.raw : null, enabled: !!(raw.wallet && raw.wallet.enabled) },
			cart: { count: raw.cart ? Number(raw.cart.count) || 0 : 0, total: raw.cart ? raw.cart.total : '' },
			alerts: raw.alerts || { count: 0 },
			nonces: raw.nonces || null,
			urls: {},
			ts: Math.floor(Date.now() / 1000),
		};
	}
	return null;
}

function signature(d) {
	if (!d) return '';
	return [d.loggedIn ? 1 : 0, d.id || 0, d.cart ? d.cart.count : 0, d.cart ? d.cart.total || '' : '', d.wallet ? d.wallet.raw : '', d.alerts ? d.alerts.count : 0].join('|');
}

/* ---------------------------------------------------------------- paint */

const text = (selector, value) => { for (const node of qsa(selector)) { const next = value == null ? '' : String(value); if (node.textContent !== next) node.textContent = next; } };
const count = (selector, n) => {
	for (const node of qsa(selector)) {
		const next = String(n);
		if (node.textContent !== next) node.textContent = next;
		node.hidden = !(n > 0);
		node.setAttribute('data-empty', n > 0 ? '0' : '1');
		node.setAttribute('aria-label', n + ' article' + (n === 1 ? '' : 's') + ' dans le panier');
	}
};

export function renderedCartCount() {
	const node = doc.querySelector('[data-dsb8-cart-count],[data-dbn-cart-count],[data-dbp-cart-count],[data-delicat-cart-count]');
	return node ? (parseInt(node.textContent, 10) || 0) : 0;
}

/**
 * WooCommerce's `woocommerce_items_in_cart` cookie is a flag ("1" while the
 * cart holds anything), not a count. It can therefore only prove an empty
 * cart: 0 when the cookie is absent or "0", null (unknown) otherwise, in which
 * case the rendered count stands until the session answer arrives.
 */
export function wooCookieCount() {
	const match = doc.cookie.match(/(?:^|;\s*)woocommerce_items_in_cart=([^;]*)/);
	if (!match) return hasCartSession() ? null : 0;
	const value = Number(decodeURIComponent(match[1] || '0'));
	return value === 0 ? 0 : null;
}
const hasCartSession = () => /(?:^|;\s*)(?:wc_cart_hash|woocommerce_cart_hash|wp_woocommerce_session_[^=]*)=([^;]+)/i.test(doc.cookie || '');

const hasWooSessionCookie = () => cookie.has(/(?:^|;\s*)(?:woocommerce_items_in_cart|wc_cart_hash|wp_woocommerce_session_[^=]*)=([^;]+)/i);

export function paintCartCount(n) {
	n = Math.max(0, Math.floor(Number(n) || 0));
	count('[data-dsb8-cart-count]', n);
	count('[data-dbn-cart-count]', n);
	count('[data-dbp-cart-count]', n);
	count('[data-delicat-cart-count]', n);
	html.setAttribute('data-dbp-cart', n > 0 ? String(n) : '0');
	emit('delicat:cart-count', { count: n });
}

/**
 * A page served from a cache carries the nonces minted when it was cached.
 * The session answer carries current ones; patch every consumer so the next
 * action (add to cart, favourite, bell, wallet refresh …) is accepted.
 */
function refreshNonces(d) {
	const nonces = d && d.nonces;
	if (!nonces) return;
	const map = { 'woocommerce-add-to-cart': nonces.addToCart, 'woocommerce-cart': nonces.wc, wp_rest: nonces.rest };
	for (const field of qsa('input[type="hidden"][data-dbp-nonce]')) {
		const action = field.getAttribute('data-dbp-nonce');
		if (map[action]) field.value = map[action];
	}
	if (win.wpApiSettings && nonces.rest) win.wpApiSettings.nonce = nonces.rest;
	if (win.wc_add_to_cart_params && nonces.addToCart) win.wc_add_to_cart_params.wc_ajax_nonce = nonces.addToCart;
	if (nonces.heart && win.DelicaBuilderV9 && win.DelicaBuilderV9.heart) win.DelicaBuilderV9.heart.nonce = nonces.heart;
	if (nonces.walletLive) {
		if (win.delicatBuilderV9WalletLive) win.delicatBuilderV9WalletLive.nonce = nonces.walletLive;
		for (const node of qsa('[data-dlx-drawer][data-wallet-nonce]')) node.setAttribute('data-wallet-nonce', nonces.walletLive);
	}
	if (nonces.headerCart) for (const node of qsa('[data-dsb8-cart-root][data-nonce]')) node.setAttribute('data-nonce', nonces.headerCart);
	if (nonces.clearCart && win.DelicatStorefrontCommerce) win.DelicatStorefrontCommerce.clearNonce = nonces.clearCart;
	if (nonces.shell && win.DelicaShellV9) win.DelicaShellV9.nonce = nonces.shell;
	if (nonces.expressAdd && win.DelicatExpress) win.DelicatExpress.addNonce = nonces.expressAdd;
	for (const root of qsa('[data-dbv9-notifications]')) {
		const live = root._dbv9Cfg;
		let raw = null;
		try { raw = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (_) { raw = null; }
		for (const cfg of [live, raw]) {
			if (!cfg || typeof cfg !== 'object') continue;
			if (nonces.rest && cfg.nonce) cfg.nonce = nonces.rest;
			if (nonces.notifications && cfg.ajaxNonce) cfg.ajaxNonce = nonces.notifications;
			if (nonces.push && cfg.push && typeof cfg.push === 'object') cfg.push.nonce = nonces.push;
		}
		if (raw) { try { root.setAttribute('data-config', JSON.stringify(raw)); } catch (_) {} }
	}
}

function apply(reason) {
	const d = store.data;
	if (!d) return;

	/* A page copy rendered for the other sign-in state (service-worker /
	   browser cache after login or logout) is replaced once from the network. */
	const rendered = renderedLoggedIn();
	if (!!d.loggedIn !== rendered && !win.__dbv9Reloaded) {
		const entries = win.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
		if (!(entries[0] && entries[0].type === 'reload')) {
			win.__dbv9Reloaded = true;
			clearDocumentCache();
			win.setTimeout(() => location.reload(), 60);
			return;
		}
	}

	html.classList.toggle('dlc-session-user', !!d.loggedIn);
	html.classList.toggle('dlc-session-guest', !d.loggedIn);
	html.setAttribute('data-dbp-auth', d.loggedIn ? 'in' : 'out');

	if (d.loggedIn && d.urls && d.urls.logout) {
		for (const link of qsa('.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]')) link.setAttribute('href', d.urls.logout);
	}

	if (d.cart) {
		const current = doc.querySelector('[data-dsb8-cart-count]');
		const before = current ? (parseInt(current.textContent, 10) || 0) : null;
		const next = parseInt(d.cart.count, 10) || 0;
		paintCartCount(next);
		text('[data-dbp-cart-total]', d.cart.total || '');
		const $ = jq();
		if (current && before !== next && $ && reason !== 'woo') {
			/* WooCommerce owns mini-cart markup: ask it once, only when the count moved. */
			try { $(doc.body).trigger('wc_fragment_refresh'); } catch (_) {}
		}
		if (next === 0) {
			const list = doc.querySelector('.dsb8-cart-list');
			const foot = doc.querySelector('.dsb8-cart-panel__foot');
			if (list && !list.querySelector('.dsb8-cart-empty')) list.innerHTML = '<div class="dsb8-cart-empty">Votre panier est vide.</div>';
			if (foot) foot.hidden = true;
		}
	}

	const walletText = d.loggedIn && d.wallet && d.wallet.text ? d.wallet.text : '—';
	text('[data-dlx-wallet-balance]', walletText);
	text('[data-delicat-wallet-balance]', walletText);
	text('[data-dbp-wallet]', d.loggedIn && d.wallet && d.wallet.text ? d.wallet.text : '');
	text('[data-dlx-name]', d.loggedIn ? d.name : '');
	text('[data-dbp-user-name]', d.loggedIn ? d.name : '');
	text('[data-dlx-email]', d.loggedIn ? d.email : '');
	text('[data-dlx-initial]', d.loggedIn ? d.initial : '');
	for (const node of qsa('[data-dlx-code]')) {
		const value = d.loggedIn && d.code ? d.code : '';
		node.textContent = value;
		const button = node.closest ? node.closest('[data-dlx-copy]') : null;
		if (button) { if (value) button.setAttribute('data-dlx-copy', value); else button.removeAttribute('data-dlx-copy'); }
	}
	if (d.alerts) {
		const alerts = Number(d.alerts.count) || 0;
		for (const node of qsa('[data-dbp-alerts]')) { node.textContent = String(alerts); node.hidden = alerts < 1; }
	}
	refreshNonces(d);

	const sig = signature(d);
	const changed = sig !== store.signature;
	store.signature = sig;
	emit('delicat:session', d);
	emit('delicat:pro:state', { state: d, changed, reason: reason || 'load' });
	for (const listener of store.listeners) { try { listener(d); } catch (_) {} }
}

/* -------------------------------------------------------------- refresh */

export function refresh(reason = 'manual', force = true) {
	if (store.pending) {
		if (force && !store.queued) {
			store.queued = store.pending.then(() => { store.queued = null; return refresh(reason, true); });
		}
		return store.queued || store.pending;
	}
	const now = Date.now();
	if (!force && store.data && now - store.ts < cfg.minGap) return Promise.resolve(store.data);
	store.pending = getJson(cfg.url, {}, 8000)
		.then((raw) => {
			const d = normalize(raw);
			if (d) { store.data = d; store.ts = Date.now(); apply(reason); }
			return store.data;
		})
		.catch(() => store.data)
		.then((result) => { store.pending = null; return result; });
	return store.pending;
}

function queueRefresh(reason, delay = 90) {
	if (eventTimer) win.clearTimeout(eventTimer);
	eventTimer = win.setTimeout(() => { eventTimer = 0; refresh(reason, true); }, delay);
}

function minimalGuest() {
	return { loggedIn: false, name: '', initial: '', email: '', code: '', wallet: { text: '', raw: null }, cart: { count: wooCookieCount() ?? renderedCartCount() }, urls: {}, ts: Math.floor(Date.now() / 1000) };
}

const needsLiveSync = () => renderedLoggedIn() || renderedCartCount() > 0 || hasWooSessionCookie()
	|| !!(store.data && store.data.loggedIn) || !!(store.data && store.data.cart && store.data.cart.count > 0);

function cachedDocumentLikely() {
	try {
		if (navigator.serviceWorker && navigator.serviceWorker.controller) return true;
		const entries = win.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
		const nav = entries && entries[0];
		if (!nav) return false;
		if (nav.type === 'back_forward') return true;
		return Number(nav.transferSize || 0) === 0 && Number(nav.decodedBodySize || 0) > 0;
	} catch (_) { return false; }
}

export const ready = new Promise((resolve) => {
	if (!authReset && !authRecover && !authSync && !renderedLoggedIn() && renderedCartCount() === 0 && !hasWooSessionCookie()) {
		/* An obviously empty guest document is already authoritative: no request
		   on the launch path. A late verification covers a stale cached copy. */
		store.data = minimalGuest(); store.ts = Date.now(); apply('guest'); resolve(store.data);
		if (cachedDocumentLikely()) idle(() => { refresh('guest-verify', true); }, 6000);
		return;
	}
	idle(() => { refresh('load', true).then(resolve, resolve); }, 1200);
});

/* ---------------------------------------------------------------- events */

export function bindSessionEvents() {
	/* Clear any old HTML shell before authentication transitions, and drive
	   logout through a fresh nonce so a cached page cannot submit a dead one. */
	doc.addEventListener('click', (event) => {
		const node = event.target && event.target.closest
			? event.target.closest('[data-dip-auth-open],[data-dl-open],a[href*="#delicat-login"],.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]')
			: null;
		if (!node) return;
		clearDocumentCache();
		const isLogout = !!(node.matches && node.matches('.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]'));
		if (!isLogout || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		event.preventDefault();
		event.stopPropagation();
		if (logoutBusy) return;
		logoutBusy = true;
		node.setAttribute('aria-busy', 'true');
		const fallback = node.getAttribute('href') || cfg.resetUrl || '/';
		refresh('logout', true).then((data) => {
			const target = data && data.loggedIn && data.urls && data.urls.logout
				? data.urls.logout
				: (data && !data.loggedIn ? (cfg.resetUrl || fallback) : fallback);
			location.assign(target);
		}, () => location.assign(fallback));
	}, true);

	if (authRecover) {
		win.setTimeout(() => {
			const trigger = doc.querySelector('[data-dip-auth-open],[data-dl-open],a[href="#delicat-login"]');
			if (trigger && typeof trigger.click === 'function') trigger.click();
		}, 30);
	}

	doc.addEventListener('visibilitychange', () => { if (!doc.hidden && needsLiveSync() && Date.now() - store.ts > cfg.stale) refresh('visible', true); });
	win.addEventListener('focus', () => { if (needsLiveSync() && Date.now() - store.ts > cfg.stale) refresh('focus', true); });
	win.addEventListener('pageshow', (event) => { if (event.persisted) queueRefresh('bfcache', 40); });

	for (const name of ['delicat:cart-changed', 'delicat:wallet-changed', 'delicat:express-added', 'delicat:auth-changed', 'delicat:currency-changed', 'delicat:balance-changed']) {
		doc.addEventListener(name, () => queueRefresh('event', 80));
	}
	doc.addEventListener('delicat:pro:refresh', () => refresh('manual', true));
	doc.addEventListener('delicat:session:refresh', () => refresh('manual', true));
	doc.addEventListener('dlx:open', () => { if (needsLiveSync()) queueRefresh('drawer-open', 60); });
	doc.addEventListener('delicat:navigated', () => {
		if (!store.data) return;
		if (needsLiveSync() && Date.now() - store.ts > cfg.navStale) refresh('nav', true);
	});
	doc.addEventListener('wc-blocks_added_to_cart', () => queueRefresh('woo', 70));
	doc.addEventListener('wc-blocks_removed_from_cart', () => queueRefresh('woo', 70));
	onWoo('added_to_cart removed_from_cart updated_wc_div updated_cart_totals', () => queueRefresh('woo', 70));
	onWoo('wc_fragments_refreshed wc_fragments_loaded', () => {
		/* Fragments carry the authoritative count; mirror it without a request. */
		const n = wooCookieCount();
		if (n !== null) paintCartCount(n);
	});
}

export const session = {
	get: () => store.data,
	ready,
	refresh: (reason) => refresh(reason || 'manual', true),
	on(fn) { if (typeof fn === 'function') { store.listeners.push(fn); if (store.data) { try { fn(store.data); } catch (_) {} } } },
	subscribe(fn) {
		if (typeof fn !== 'function') return () => {};
		store.listeners.push(fn);
		if (store.data) { try { fn(store.data); } catch (_) {} }
		return () => { const at = store.listeners.indexOf(fn); if (at !== -1) store.listeners.splice(at, 1); };
	},
	paintCartCount,
};
