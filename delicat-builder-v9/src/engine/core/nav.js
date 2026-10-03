/**
 * Navigation engine — the one owner of link interception, document fetching,
 * page caching, prefetching, swapping and scroll restoration.
 *
 * Replaces TurboNav, shell-nav.js, dbp-nav.js, prefetch.js and the
 * product-switcher's private fetcher. Design:
 *
 *  - Every same-origin storefront link is a soft navigation, product pages
 *    included: the engine owns every script that runs on them, so there is no
 *    third-party lifecycle to be afraid of any more. Cart, checkout, account
 *    and anything WooCommerce renders per-session stay full document loads.
 *  - The next document is fetched as plain HTML (LiteSpeed / Cloudflare can
 *    answer it), parsed in an inert DOMParser document, and only <main> (plus
 *    a few portal nodes such as the express sheet) is swapped. Header, drawer,
 *    bottom bar and footer stay mounted — nothing flashes.
 *  - The swap runs inside a View Transition when the device allows it, with
 *    the tapped product image carried into the product page as a shared
 *    element, which is what makes a tap feel native.
 *  - A small in-memory page cache (60 s, 14 entries) is filled by intent
 *    prefetching (pointer, focus, first visible product cards) and cleared the
 *    moment the cart, wallet or session changes. Nothing is written to storage.
 *  - Scroll positions are kept per history entry and restored on Back/Forward,
 *    also across a full document reload through history.state.
 */
import { closest, device, doc, emit, html, idle, jq, on, parseUrl, qsa, raf, sameDocument, tryFocus, win } from './dom.js';
import { config, syncConfig } from './runtime.js';

const legacy = win.DBPNavConfig || win.DelicatShellNavConfig || {};
const cfg = Object.assign({
	enabled: true,
	products: true,
	transitions: true,
	prefetch: true,
	budget: 10,
	viewportBudget: 2,
	cacheTtl: 300000,
	cacheMax: 20,
	maxBytes: 600 * 1024,
	timeout: 8000,
	main: 'main[data-delicat-server-render],main[data-delicat-native-document],main',
	portals: ['[data-dnp-express]', '[data-dpn-wallet-modal]', '[data-dnp-dock]', '[data-delicat-mobile-purchase]', '[data-dpn-checkout-dock]'],
	hardClasses: ['dpn-checkout', 'dpn-cart', 'woocommerce-checkout', 'woocommerce-cart', 'woocommerce-account', 'delicat-native-checkout', 'delicat-native-cart'],
	locked: legacy.locked || ['/wp-admin', '/wp-login.php', '/wp-json', '/wc-api', '/webhook', '/oauth', '/callback'],
	hardPaths: ['/cart/', '/panier/', '/checkout/', '/commande/', '/my-account/', '/mon-compte/', '/order-pay/', '/order-received/', '/customer-logout/', '/lost-password/'],
	blockParams: legacy.blockParams || legacy.blockedParams || ['add-to-cart', 'remove_item', 'undo_item', 'wc-ajax', 'wc-api', '_wpnonce', 'nonce', 'action', 'dip_action', 'logout', 'customer-logout', 'key', 'token', 'code', 'session', 'payment_method', 'dmc_currency', 'delicat_maintenance_preview'],
	productPaths: legacy.productPaths || ['/product/', '/produit/'],
	generation: legacy.generation || '',
	forms: true,
	placeholder: true,
	tabWarm: true,
}, config.nav || {});

const MAIN = cfg.main;
const state = {
	token: 0,
	inflight: null,
	committing: false,
	swapped: false,
	scrolls: new Map(),
	renderedKey: location.pathname + location.search,
	loadedScripts: new Map(),
	loadedInline: new Map(),
	loadedStyles: new Set(),
	cache: new Map(),
	pending: new Map(),
	prefetched: 0,
	hoverTimer: 0,
	touchIntent: null,
	progressTimer: 0,
	progressEl: null,
	liveEl: null,
	lastCardImage: null,
	placeholder: null,
};

const listeners = {};
export function onNav(event, fn) { (listeners[event] = listeners[event] || []).push(fn); return () => { listeners[event] = (listeners[event] || []).filter((item) => item !== fn); }; }
const fire = (event, payload) => { for (const fn of listeners[event] || []) { try { fn(payload); } catch (_) {} } };

/* A stylesheet's `scroll-behavior: smooth` turns every programmatic scroll
   into a half-second glide; a swap, a restore and a placeholder must land at
   once. */
function jumpTo(y) {
	const previous = html.style.scrollBehavior;
	html.style.scrollBehavior = 'auto';
	try { win.scrollTo({ top: y, left: 0, behavior: 'instant' }); } catch (_) { win.scrollTo(0, y); }
	html.style.scrollBehavior = previous;
}

/* ------------------------------------------------------------ eligibility */

const normalise = (href) => { const url = parseUrl(href); return url ? url.origin + url.pathname + url.search : String(href || ''); };
const key = (url) => url.pathname + url.search;

function lockedPath(pathname) {
	const path = String(pathname || '').toLowerCase();
	return (cfg.locked || []).some((part) => part && (path === part || path.indexOf(part) === 0 || path.indexOf(part + '/') !== -1));
}

function hardPath(pathname) {
	const path = String(pathname || '').toLowerCase();
	return (cfg.hardPaths || []).some((part) => part && path.indexOf(part) !== -1);
}

export function isProductUrl(url) {
	const path = (url && url.pathname ? url.pathname : String(url || '')).toLowerCase();
	return (cfg.productPaths || []).some((base) => base && path.indexOf(base.toLowerCase()) !== -1);
}

/** Returns a URL when the engine may navigate there, otherwise null. */
export function eligible(href) {
	const url = parseUrl(href);
	if (!url) return null;
	if (url.origin !== location.origin || (url.protocol !== 'http:' && url.protocol !== 'https:')) return null;
	const path = url.pathname.toLowerCase();
	if (lockedPath(path) || hardPath(path)) return null;
	if (/\.(pdf|zip|jpe?g|png|gif|webp|avif|svg|mp4|mp3|apk|ipa|csv|xlsx?|docx?)$/i.test(path)) return null;
	for (const name of cfg.blockParams || []) if (url.searchParams.has(name)) return null;
	if (!cfg.products && isProductUrl(url)) return null;
	return url;
}

function interactiveLink(link) {
	if (!link || !link.href || !link.getAttribute('href')) return false;
	if (link.getAttribute('href').charAt(0) === '#') return false;
	if (link.hasAttribute('download')) return false;
	if (link.target && link.target !== '' && link.target !== '_self') return false;
	if (/\b(external|nofollow-app)\b/.test(link.getAttribute('rel') || '')) return false;
	if (link.hasAttribute('data-no-shell') || link.hasAttribute('data-dbp-skip') || link.hasAttribute('data-no-turbo') || link.hasAttribute('data-delicat-no-app') || link.getAttribute('data-delicat-nav') === 'hard') return false;
	if (link.closest('[data-no-turbo],[data-delicat-nav="hard"],.no-prerender')) return false;
	return true;
}

const currentMain = () => doc.querySelector(MAIN);

function hardDocument(body, main) {
	if (!body || !main) return true;
	for (const name of cfg.hardClasses || []) if (body.classList.contains(name)) return true;
	if (main.querySelector('form.checkout, form.woocommerce-cart-form, .woocommerce-account, .woocommerce-checkout')) return true;
	return false;
}

/* --------------------------------------------------------------- progress */

function progressNode() {
	if (state.progressEl && state.progressEl.isConnected) return state.progressEl;
	let bar = doc.getElementById('dbp-progress') || doc.querySelector('.dlc-progress');
	if (!bar) {
		bar = doc.createElement('div');
		bar.className = 'dlc-progress dbp-progress';
		bar.id = 'dbp-progress';
		bar.setAttribute('aria-hidden', 'true');
		doc.body.appendChild(bar);
	}
	state.progressEl = bar;
	return bar;
}

/* While a slow navigation dims the page, a tap on the outgoing content (a
   button, a form control) is refused; a tap on another link is a new
   destination and goes through. This replaces the pointer-events rules. */
function guardDimmedTaps(event) {
	if (!state.inflight || !html.classList.contains('delicat-navigating-slow')) return;
	const main = currentMain();
	if (!main || !(event.target instanceof Node) || !main.contains(event.target)) return;
	if (closest(event.target, 'a[href]')) return;
	event.preventDefault();
	event.stopPropagation();
}

function progressStart() {
	html.classList.add('delicat-navigating');
	win.clearTimeout(state.progressTimer);
	state.progressTimer = win.setTimeout(() => html.classList.add('delicat-navigating-slow'), 150);
	const bar = progressNode();
	bar.classList.remove('is-done');
	bar.classList.add('is-active');
}

function progressDone() {
	win.clearTimeout(state.progressTimer);
	html.classList.remove('delicat-navigating', 'delicat-navigating-slow', 'delicat-placeholder');
	const bar = state.progressEl;
	if (!bar) return;
	bar.classList.remove('is-active');
	bar.classList.add('is-done');
	win.setTimeout(() => bar.classList.remove('is-done'), 260);
}

function announce(title) {
	let live = state.liveEl || doc.getElementById('dbp-live');
	if (!live) {
		live = doc.createElement('div');
		live.id = 'dbp-live';
		live.className = 'dbp-sr-only dlc-sr-only';
		live.setAttribute('aria-live', 'polite');
		live.setAttribute('role', 'status');
		doc.body.appendChild(live);
	}
	state.liveEl = live;
	live.textContent = title;
}

/* ------------------------------------------------------------------ cache */

function remember(url, entry) {
	const k = url.href;
	state.cache.delete(k);
	state.cache.set(k, Object.assign({ ts: Date.now() }, entry));
	while (state.cache.size > cfg.cacheMax) state.cache.delete(state.cache.keys().next().value);
}

function recall(url) {
	const hit = state.cache.get(url.href);
	if (!hit) return null;
	if (Date.now() - hit.ts > cfg.cacheTtl) { state.cache.delete(url.href); return null; }
	return hit;
}

export function invalidate() {
	state.cache.clear();
	for (const [, ctl] of state.pending) { try { ctl.abort(); } catch (_) {} }
	state.pending.clear();
}

/* ---------------------------------------------------------------- fetching */

async function fetchDocument(url, { signal, prefetch = false } = {}) {
	const controller = new AbortController();
	const timer = win.setTimeout(() => controller.abort(new Error('navigation-timeout')), cfg.timeout);
	if (signal) {
		if (signal.aborted) controller.abort(signal.reason);
		else signal.addEventListener('abort', () => controller.abort(signal.reason), { once: true });
	}
	try {
		const init = { credentials: 'same-origin', cache: 'default', redirect: 'follow', signal: controller.signal, headers: { Accept: 'text/html' } };
		if (prefetch) { init.headers['X-Delicat-Prefetch'] = '1'; try { init.priority = 'low'; } catch (_) {} }
		const response = await fetch(url.href, init);
		const type = response.headers.get('content-type') || '';
		if (!response.ok || type.indexOf('text/html') === -1) {
			const error = new Error('status-' + response.status);
			error.status = response.status;
			throw error;
		}
		let finalUrl = url;
		if (response.redirected) {
			const landed = eligible(response.url);
			if (!landed) { const error = new Error('redirected'); error.redirect = response.url; throw error; }
			finalUrl = landed;
		}
		const cacheControl = String(response.headers.get('cache-control') || '').toLowerCase();
		const noStore = /(?:^|[,\s])(?:no-store|private)(?:[,\s]|$)/.test(cacheControl);
		const declared = Number(response.headers.get('content-length') || 0);
		if (prefetch && declared > cfg.maxBytes) { try { await response.body.cancel(); } catch (_) {} throw new Error('too-large'); }
		const text = await response.text();
		if (prefetch && text.length > cfg.maxBytes) throw new Error('too-large');
		return { html: text, url: finalUrl, noStore };
	} finally {
		win.clearTimeout(timer);
	}
}

/* ----------------------------------------------------------------- assets */

function indexExistingAssets() {
	for (const link of qsa('link[rel="stylesheet"][href]')) state.loadedStyles.add(normalise(link.href));
	for (const script of qsa('script[src]')) state.loadedScripts.set(normalise(script.src), true);
	for (const script of qsa('script[id]:not([src])')) state.loadedInline.set(script.id, script.textContent || '');
}

function isEngineScript(src) {
	return /\/assets\/dist\/(engine|chunks\/)[^?]*\.js/.test(src) || false;
}

function analyse(incoming) {
	const main = incoming.querySelector(MAIN);
	const body = incoming.body;
	if (!main || !body) throw new Error('main-missing');
	if (body.classList.contains('logged-in') !== doc.body.classList.contains('logged-in')) throw new Error('session-changed');
	if (hardDocument(body, main)) throw new Error('full-required');
	const generation = incoming.querySelector('meta[name="dbp-generation"],meta[name="delicat-generation"]');
	if (cfg.generation && (!generation || generation.content !== cfg.generation)) throw new Error('generation-changed');

	const styles = [];
	for (const node of incoming.querySelectorAll('head link[rel="stylesheet"][href], head style')) {
		if (node.tagName === 'LINK') styles.push({ href: node.href, media: node.media || 'all', id: node.id || '' });
		else if (node.id) styles.push({ id: node.id, code: node.textContent || '' });
	}
	const scripts = [];
	for (const script of incoming.querySelectorAll('script')) {
		const type = (script.getAttribute('type') || '').toLowerCase();
		const src = script.getAttribute('src') || script.getAttribute('data-src') || '';
		const inMain = !!script.closest(MAIN);
		if (type && !/^(text\/javascript|application\/javascript|litespeed\/javascript|module)$/.test(type)) continue;
		if (src) {
			const absolute = new URL(src, incoming.baseURI || location.href).href;
			const norm = normalise(absolute);
			if (isEngineScript(norm) || state.loadedScripts.get(norm)) continue;
			if (type === 'module') { scripts.push({ src: absolute, id: script.id || '', module: true }); continue; }
			/* An unlabelled external script is an opaque combined bundle we cannot run twice safely. */
			if (!script.id) throw new Error('unknown-script-bundle');
			const existing = doc.getElementById(script.id);
			if (existing && existing.src && normalise(existing.src) !== norm) throw new Error('script-version-changed');
			scripts.push({ src: absolute, id: script.id, defer: script.defer, async: script.async });
		} else if (script.id && /-js-(extra|before|after|translations)$/.test(script.id)) {
			scripts.push({ inline: true, id: script.id, code: script.textContent || '' });
		} else if (inMain && script.textContent.trim()) {
			scripts.push({ inline: true, id: '', code: script.textContent || '' });
		}
	}
	/* innerHTML/importNode makes scripts inert; drop them from main so they cannot be mistaken for live ones. */
	for (const script of main.querySelectorAll('script')) {
		const type = (script.getAttribute('type') || '').toLowerCase();
		if (!type || /^(text\/javascript|application\/javascript|litespeed\/javascript|module)$/.test(type)) script.remove();
	}
	const canonical = incoming.querySelector('link[rel="canonical"]');
	const description = incoming.querySelector('meta[name="description"]');
	const route = /(?:^|\s)dbp-route-([a-z]+)/.exec(body.className);
	return {
		main, body, title: incoming.title || '', bodyClass: body.className || '',
		canonical: canonical ? canonical.href : '', description: description ? description.content : '',
		route: route ? route[1] : 'page', styles, scripts,
		portals: (cfg.portals || []).map((selector) => ({ selector, nodes: Array.from(incoming.querySelectorAll(selector)).filter((node) => !node.closest(MAIN)) })),
		templates: Array.from(incoming.querySelectorAll('script[type="text/template"][id], script[type="text/html"][id]')).filter((node) => !node.closest(MAIN)),
		bottomNav: incoming.querySelector('.delicat-bottom-nav'),
	};
}

const stylePromises = new Map();
const styleNode = (href) => { const norm = normalise(href); return qsa('link[rel="stylesheet"][href]').find((link) => normalise(link.href) === norm) || null; };

/**
 * Load a stylesheet the next page needs. The cascade is decided by document
 * order, so a new sheet is inserted right after the previous sheet of the
 * incoming document (`after`), never appended after the late polish layers.
 * A sheet that was switched off by an earlier swap is switched back on. The
 * link is created and placed at once, so a page that needs several new
 * sheets downloads them in parallel instead of one round trip after another.
 */
function ensureStyle(item, after) {
	const norm = normalise(item.href);
	if (state.loadedStyles.has(norm)) {
		const existing = styleNode(item.href);
		if (existing) { existing.setAttribute('data-delicat-media', item.media || 'all'); existing.setAttribute('data-delicat-pending', '1'); return { node: existing, promise: Promise.resolve(existing) }; }
		state.loadedStyles.delete(norm);
	}
	if (stylePromises.has(norm)) return stylePromises.get(norm);
	const link = doc.createElement('link');
	link.rel = 'stylesheet';
	link.href = item.href;
	/* Loaded and parsed now, applied at the swap: a sheet that applies as soon
	   as it lands restyles the outgoing page for nothing (a full pass on a
	   slow phone), then the new page is restyled again. With a media query
	   that never matches the sheet arrives ready and the switch to its real
	   media is part of the swap's own style pass. */
	link.media = 'not all';
	link.setAttribute('data-delicat-media', item.media || 'all');
	link.setAttribute('data-delicat-pending', '1');
	if (item.id && !doc.getElementById(item.id)) link.id = item.id;
	const promise = new Promise((resolve, reject) => {
		let done = false;
		const finish = (ok) => {
			if (done) return;
			done = true;
			win.clearTimeout(timer);
			stylePromises.delete(norm);
			if (ok) { state.loadedStyles.add(norm); resolve(link); } else { link.remove(); reject(new Error('style-failed')); }
		};
		const timer = win.setTimeout(() => finish(true), 4000);
		link.onload = () => finish(true);
		link.onerror = () => finish(false);
	});
	if (after && after.parentNode === doc.head) after.insertAdjacentElement('afterend', link);
	else doc.head.appendChild(link);
	const entry = { node: link, promise };
	stylePromises.set(norm, entry);
	return entry;
}

/** Bring the document's stylesheets to the incoming page's set and order. */
async function syncStyles(styles) {
	const wanted = new Set();
	const loads = [];
	let anchor = null;
	for (const item of styles) {
		if (!item.href) continue;
		wanted.add(normalise(item.href));
		const entry = ensureStyle(item, anchor);
		if (entry) { anchor = entry.node; loads.push(entry.promise.catch(() => null)); }
	}
	/* Sheets only the previous page used stay cached but stop applying, the
	   way a direct load of this page would never have had them. */
	const retire = [];
	for (const link of qsa('link[rel="stylesheet"][href]')) {
		const norm = normalise(link.href);
		if (wanted.has(norm) || !isPluginAsset(norm)) continue;
		retire.push(link);
	}
	await Promise.all(loads);
	/* One style pass: the incoming sheets switch on and the outgoing ones off
	   in the same task as the content swap. */
	return () => {
		for (const node of qsa('link[rel="stylesheet"][data-delicat-pending]')) {
			node.removeAttribute('data-delicat-pending');
			const media = node.getAttribute('data-delicat-media') || 'all';
			if (node.media !== media) node.media = media;
			if (node.disabled) node.disabled = false;
			node.removeAttribute('data-delicat-inactive');
		}
		for (const link of retire) {
			if (link.disabled) continue;
			link.disabled = true;
			link.setAttribute('data-delicat-inactive', '1');
		}
	};
}

const isPluginAsset = (href) => /\/(?:plugins|mu-plugins)\/delicat-builder-v9\//.test(href) || /\/uploads\/delicat-builder-v9\//.test(href);

function applyInlineStyles(styles) {
	const wanted = new Set();
	for (const item of styles) {
		if (!item.code && item.href) continue;
		if (!item.id) continue;
		wanted.add(item.id);
		let node = doc.getElementById(item.id);
		if (node && node.tagName !== 'STYLE') continue;
		if (!node) { node = doc.createElement('style'); node.id = item.id; doc.head.appendChild(node); }
		if (node.textContent !== item.code) node.textContent = item.code;
		if (node.disabled) node.disabled = false;
	}
	for (const node of qsa('head style[id]')) {
		if (wanted.has(node.id)) continue;
		if (!/^(delicat-|dbp-|dbv9-|dsb)/.test(node.id)) continue;
		node.disabled = true;
	}
}

function runInline(item) {
	if (item.id) {
		if (state.loadedInline.get(item.id) === item.code) return;
		state.loadedInline.set(item.id, item.code);
		const previous = doc.getElementById(item.id);
		if (previous && previous.tagName === 'SCRIPT') previous.remove();
	}
	const node = doc.createElement('script');
	if (item.id) node.id = item.id;
	/* A name for profilers: an inline script otherwise shows as "(anonymous)". */
	node.text = item.code + '\n//# sourceURL=' + (item.id ? 'inline-' + item.id : 'inline-main') + '.js';
	(item.id ? doc.head : doc.body).appendChild(node);
}

function loadExternal(item) {
	const norm = normalise(item.src);
	state.loadedScripts.set(norm, true);
	return new Promise((resolve, reject) => {
		const node = doc.createElement('script');
		node.src = item.src;
		if (item.id) node.id = item.id;
		if (item.module) node.type = 'module';
		node.async = false;
		const timer = win.setTimeout(() => { state.loadedScripts.delete(norm); reject(new Error('script-timeout')); }, 10000);
		node.onload = () => { win.clearTimeout(timer); resolve(); };
		node.onerror = () => { win.clearTimeout(timer); state.loadedScripts.delete(norm); reject(new Error('script-failed')); };
		doc.body.appendChild(node);
	});
}

async function runScripts(scripts, token) {
	for (const item of scripts) {
		if (token !== state.token) return;
		if (item.inline) runInline(item);
		else await loadExternal(item);
	}
}

function warmScripts(scripts) {
	for (const item of scripts) {
		if (item.inline) continue;
		const url = parseUrl(item.src);
		if (!url || url.origin !== location.origin) continue;
		const link = doc.createElement('link');
		link.rel = item.module ? 'modulepreload' : 'preload';
		link.as = 'script';
		link.href = url.href;
		doc.head.appendChild(link);
	}
}

/* ------------------------------------------------------------------- swap */

function syncBodyClass(nextClass) {
	const keep = /^(dlx-|dlc-theme-|dlc-session-|delicat-home-mode-|dnp-express-|dpn-wallet-modal-open|delicat-navigating|dbv9-assistant-open|dbv9-notification-open|delicat-pwa-|admin-bar|logged-in|dsb8-overlay-open|dsb-menu-open|delicat-shell-modal-open|delicat-reduce-motion|delicat-low-power|dlc-session)/;
	const next = String(nextClass || '').split(/\s+/).filter(Boolean);
	for (const name of Array.from(doc.body.classList)) if (keep.test(name) && next.indexOf(name) === -1) next.push(name);
	const current = doc.body.className;
	const joined = next.join(' ');
	if (current !== joined) doc.body.className = joined;
}

function syncHead(payload) {
	if (payload.title) doc.title = payload.title;
	const canonical = doc.querySelector('link[rel="canonical"]');
	if (canonical && payload.canonical) canonical.href = payload.canonical;
	const description = doc.querySelector('meta[name="description"]');
	if (description && payload.description) description.content = payload.description;
}

function syncBottomNav(incomingNav) {
	if (!incomingNav) return;
	for (const item of qsa('.delicat-bottom-nav .dbn-item[data-dbn-key]')) {
		const next = incomingNav.querySelector('.dbn-item[data-dbn-key="' + item.getAttribute('data-dbn-key') + '"]');
		const active = !!(next && next.classList.contains('is-active'));
		item.classList.toggle('is-active', active);
		if (active) item.setAttribute('aria-current', 'page'); else item.removeAttribute('aria-current');
	}
}

/* The drawer and any other chrome outside <main> mark the current route with
   is-current; keep that truthful after a swap by matching hrefs. */
function syncCurrentLinks(url) {
	const target = normalise(url.href);
	for (const link of qsa('[data-dlx-drawer] a.dlx-item[href], .dsb8-header a[href][aria-current], .dsb8-header a.is-current[href]')) {
		const current = normalise(link.href) === target;
		link.classList.toggle('is-current', current);
		if (current) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
	}
}

/* WooCommerce prints its Underscore templates (tmpl-variation-template …) as
   <script type="text/template"> outside the content root; the variation form
   reads them by id at runtime, so the next page's templates must exist. */
function syncTemplates(templates) {
	for (const node of templates) {
		if (!node.id) continue;
		const existing = doc.getElementById(node.id);
		if (existing) { if (existing.textContent !== node.textContent) existing.textContent = node.textContent; continue; }
		doc.body.appendChild(doc.importNode(node, true));
	}
}

function swapPortals(portals) {
	for (const { selector, nodes } of portals) {
		for (const old of qsa(selector)) if (!old.closest(MAIN)) old.remove();
		for (const node of nodes) doc.body.appendChild(doc.importNode(node, true));
	}
}

function heroImage(main) {
	return main.querySelector('[data-dnp-hero] img, .dnp-gallery img, .delicat-native-product .woocommerce-product-gallery__image img, .delicat-native-product img.wp-post-image, .woocommerce-product-gallery__image:first-child img, .dnp-media img');
}

function applySwap(payload, url, { restoreTo, push }) {
	const current = currentMain();
	if (!current) return null;
	const fresh = doc.importNode(payload.main, true);
	if (push) {
		if (history.scrollRestoration) history.scrollRestoration = 'manual';
		history.pushState({ delicat: 1, dbv9: 1, dbp: 1, key: key(url), scroll: 0 }, '', url.href);
	}
	applyInlineStyles(payload.styles);
	current.replaceWith(fresh);
	swapPortals(payload.portals);
	syncTemplates(payload.templates);
	syncBodyClass(payload.bodyClass);
	syncHead(payload);
	syncBottomNav(payload.bottomNav);
	syncCurrentLinks(url);
	state.renderedKey = key(url);
	if (typeof restoreTo === 'number') jumpTo(restoreTo);
	else if (!url.hash) jumpTo(0);
	fresh.setAttribute('tabindex', '-1');
	raf(() => { if (fresh.isConnected) tryFocus(fresh); });
	state.swapped = true;
	return fresh;
}

function scrollToHash(url) {
	if (!url.hash || url.hash === '#') return;
	let name;
	try { name = decodeURIComponent(url.hash.slice(1)); } catch (_) { return; }
	const target = doc.getElementById(name) || (doc.getElementsByName ? doc.getElementsByName(name)[0] : null);
	if (target && target.scrollIntoView) { try { target.scrollIntoView({ behavior: 'instant', block: 'start' }); } catch (_) { target.scrollIntoView(); } }
}

function transitionsAllowed() {
	if (!cfg.transitions || !doc.startViewTransition) return false;
	if (device.reducedMotion || device.veryLowPower || device.lowPower) return false;
	if (device.memory > 0 && device.memory <= 3) return false;
	return true;
}

async function commit(payload, url, options, token) {
	warmScripts(payload.scripts);
	const activateStyles = await syncStyles(payload.styles);
	if (token !== state.token) return;

	let fresh = null;
	const run = () => {
		activateStyles();
		fresh = applySwap(payload, url, options);
		if (!fresh) throw new Error('swap-failed');
		const hero = isProductUrl(url) ? heroImage(fresh) : null;
		if (hero) hero.style.viewTransitionName = 'delicat-product-image';
	};

	if (transitionsAllowed()) {
		html.setAttribute('data-dbp-transition', '1');
		html.setAttribute('data-delicat-transition', isProductUrl(url) ? 'product' : (options.push === false ? 'back' : 'forward'));
		const source = (options.placeholder ? doc.querySelector('[data-delicat-placeholder] .dph__hero img') : null) || state.lastCardImage;
		if (source && source.isConnected) source.style.viewTransitionName = 'delicat-product-image';
		let failure = null;
		const transition = doc.startViewTransition(() => { try { run(); } catch (error) { failure = error; } });
		const clear = () => {
			html.removeAttribute('data-dbp-transition');
			html.removeAttribute('data-delicat-transition');
			if (source) source.style.viewTransitionName = '';
			const hero = fresh && heroImage(fresh);
			if (hero) hero.style.viewTransitionName = '';
			state.lastCardImage = null;
		};
		await transition.updateCallbackDone.catch(() => {});
		transition.finished.then(clear, clear);
		if (failure) throw failure;
	} else {
		html.setAttribute('data-dbp-fade', '1');
		run();
		win.setTimeout(() => html.removeAttribute('data-dbp-fade'), 200);
	}
	if (token !== state.token) return;

	await runScripts(payload.scripts, token);
	if (token !== state.token) return;
	syncConfig();

	const detail = { url: url.href, route: payload.route, main: fresh, source: 'engine' };
	fire('swapped', detail);
	emit('dsb:content-updated', { source: 'pro-nav', url: url.href, main: fresh });
	emit('delicat:shell:navigated', detail);
	emit('delicat:navigation-complete', detail);
	emit('delicat:pro:navigated', detail);
	emit('delicat:navigated', detail);
	const $ = jq();
	if ($ && $.fn && $.fn.wc_variation_form) {
		$(fresh).find('.variations_form').each(function () { const form = $(this); if (!form.data('wc_variation_form')) form.wc_variation_form(); });
	}
	if ($) { try { $(doc.body).trigger('post-load'); } catch (_) {} }
	emit('delicat:pro:ready', detail);
	announce(payload.title);
	if (typeof options.restoreTo !== 'number') scrollToHash(url);
	if (typeof win.gtag === 'function') { try { win.gtag('event', 'page_view', { page_location: url.href, page_title: doc.title }); } catch (_) {} }
}

/* ------------------------------------------------------------ placeholder */

/* What a native app does on a tap: it opens the next screen at once with what
   it already knows (the card's image, title and price) and fills it in when
   the data arrives. The placeholder is a <main> of the route's shape, so the
   chrome, the bar and the scroll position already behave as on the real page.
   It is only shown when nothing is cached and no prefetch is about to land. */
const esc = (value) => String(value || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/**
 * How long a tap waits for the document before the placeholder goes up. A
 * document that lands within the grace period is shown as it is: building
 * the placeholder costs a layout and a paint that a slow phone would pay
 * twice when the real page follows at once (the skeleton felt slower than
 * the page on cheap phones). The grace grows with how slow the phone is, and
 * the slowest phones never build one: the progress bar is their feedback.
 * -1 means no placeholder.
 */
function placeholderGrace() {
	if (cfg.placeholder === false) return -1;
	if (device.veryLowPower || html.classList.contains('delicat-very-low-power')) return -1;
	if (device.lowPower) return 260;
	return 160;
}

function placeholderRoute(url) {
	if (isProductUrl(url)) return 'product';
	if (/\/(?:product-category|categorie-produit|product-tag|etiquette-produit|shop|boutique)(?:\/|$)/i.test(url.pathname) || url.searchParams.has('s')) return 'shop';
	if (url.pathname === '/' || /^\/index\.php\/?$/.test(url.pathname)) return 'home';
	return 'page';
}

function placeholderFor(url, link) {
	const route = placeholderRoute(url);
	const main = doc.createElement('main');
	main.className = 'delicat-placeholder-main delicat-placeholder-main--' + route;
	main.setAttribute('data-delicat-native-document', '');
	main.setAttribute('data-delicat-placeholder', route);
	main.setAttribute('aria-busy', 'true');
	const card = link ? link.closest('[data-delicat-card],.delicat-product-card,.delicat-woo-product-card,li.product,.dpsr-switcher-option') : null;
	const text = (selector) => { const el = card ? card.querySelector(selector) : null; return el ? el.textContent.trim() : ''; };
	const img = card ? card.querySelector('img') : null;
	const src = img ? (img.currentSrc || img.src || '') : '';
	const cardStub = '<div class="dph__card"><span class="dph__thumb"></span><span class="dph__bar dph__bar--w80"></span><span class="dph__bar dph__bar--w40"></span></div>';
	let inner = '';
	if (route === 'product') {
		const title = text('.delicat-product-card__title, .delicat-product-card__name, .delicat-product-card__poster-title, .woocommerce-loop-product__title, h2, h3') || (img && img.alt ? img.alt : '') || (link ? link.getAttribute('aria-label') || '' : '');
		const price = text('.delicat-product-card__price, .price');
		inner = '<div class="dph__hero">' + (src ? '<img src="' + esc(src) + '" alt="" decoding="async">' : '') + '</div>'
			+ '<div class="dph__body"><h1 class="dph__title">' + (title ? esc(title) : '<span class="dph__bar dph__bar--w60 dph__bar--title"></span>') + '</h1>'
			+ (price ? '<p class="dph__price">' + esc(price) + '</p>' : '<span class="dph__bar dph__bar--w30"></span>')
			+ '<div class="dph__chips"><span class="dph__chip"></span><span class="dph__chip"></span><span class="dph__chip"></span><span class="dph__chip"></span></div>'
			+ '<span class="dph__bar dph__bar--btn"></span><span class="dph__bar"></span><span class="dph__bar dph__bar--w80"></span><span class="dph__bar dph__bar--w60"></span></div>';
	} else if (route === 'shop') {
		inner = '<div class="dph__body"><span class="dph__bar dph__bar--w40 dph__bar--title"></span><div class="dph__chips"><span class="dph__chip"></span><span class="dph__chip"></span><span class="dph__chip"></span></div><div class="dph__grid">' + cardStub.repeat(6) + '</div></div>';
	} else if (route === 'home') {
		inner = '<div class="dph__body"><span class="dph__hero dph__hero--home"></span><span class="dph__bar dph__bar--w40 dph__bar--title"></span><div class="dph__rail">' + cardStub.repeat(3) + '</div><span class="dph__bar dph__bar--w40 dph__bar--title"></span><div class="dph__rail">' + cardStub.repeat(3) + '</div></div>';
	} else {
		inner = '<div class="dph__body"><span class="dph__bar dph__bar--w60 dph__bar--title"></span><span class="dph__bar"></span><span class="dph__bar dph__bar--w80"></span><span class="dph__bar"></span><span class="dph__bar dph__bar--w40"></span></div>';
	}
	main.innerHTML = '<div class="dph dph--' + route + '">' + inner + '</div>';
	return { main, route };
}

function showPlaceholder(url, link) {
	const current = currentMain();
	if (!current) return false;
	let built;
	try { built = placeholderFor(url, link); } catch (_) { return false; }
	const { main, route } = built;
	try {
		if (history.scrollRestoration) history.scrollRestoration = 'manual';
		history.pushState({ delicat: 1, dbv9: 1, dbp: 1, key: key(url), scroll: 0 }, '', url.href);
	} catch (_) { return false; }
	current.replaceWith(main);
	const classes = Array.from(doc.body.classList).filter((name) => !/^dbp-route-/.test(name));
	classes.push('dbp-route-' + route, 'delicat-placeholder-active');
	doc.body.className = classes.join(' ');
	html.classList.add('delicat-placeholder');
	syncCurrentLinks(url);
	state.renderedKey = key(url);
	state.swapped = true;
	state.placeholder = { url: url.href, route };
	jumpTo(0);
	raf(() => { if (state.placeholder && (win.pageYOffset || 0) > 0) jumpTo(0); });
	emit('delicat:placeholder', { url: url.href, route });
	return true;
}

/* --------------------------------------------------------------- navigate */

function hardNavigate(url, replace) {
	try { state.scrolls.set(key(parseUrl(location.href)), win.pageYOffset); } catch (_) {}
	/* Hand scroll restoration back to the browser: the next document is its own. */
	try { if (history.scrollRestoration) history.scrollRestoration = 'auto'; } catch (_) {}
	const href = typeof url === 'string' ? url : url.href;
	if (replace) location.replace(href); else location.assign(href);
}

export async function navigate(url, options = {}) {
	if (!(url instanceof URL)) url = parseUrl(url);
	if (!url) return;
	if (state.committing) {
		/* Script initialisation cannot be cancelled safely; a second destination gets a fresh document. */
		state.token++;
		if (state.inflight) { try { state.inflight.abort(); } catch (_) {} }
		hardNavigate(url, options.push === false);
		return;
	}
	const token = ++state.token;
	const detail = { url: url.href, push: options.push !== false };
	if (!emit('delicat:navigate', detail, doc, true)) { hardNavigate(url, options.push === false); return; }

	state.scrolls.set(state.renderedKey, win.pageYOffset);
	try { history.replaceState(Object.assign({}, history.state || {}, { delicat: 1, dbv9: 1, scroll: win.pageYOffset || 0 }), '', location.href); } catch (_) {}
	if (state.inflight) { try { state.inflight.abort(); } catch (_) {} }

	progressStart();
	const controller = new AbortController();
	state.inflight = controller;
	let finalUrl = url;
	let placeholder = false;
	try {
		let entry = recall(url);
		if (!entry) {
			const pending = state.pending.get(url.href);
			const fresh = () => fetchDocument(url, { signal: controller.signal }).then((fetched) => { if (!fetched.noStore) remember(fetched.url, fetched); return fetched; });
			/* A prefetch in flight is used when it lands; a failed one is fetched again. */
			const promise = pending && pending.promise ? pending.promise.catch(() => null).then((found) => found || fresh()) : fresh();
			const grace = placeholderGrace();
			if (grace > 0) {
				entry = await Promise.race([promise.then((found) => found, () => null), new Promise((resolve) => win.setTimeout(() => resolve(null), grace))]);
				if (token !== state.token) return;
			}
			if (!entry) {
				if (grace >= 0 && options.push !== false) placeholder = showPlaceholder(url, options.link || null);
				entry = await promise;
				if (token !== state.token) return;
			}
		}
		if (token !== state.token) return;
		finalUrl = entry.url || url;
		indexExistingAssets();
		const incoming = new DOMParser().parseFromString(entry.html, 'text/html');
		const payload = analyse(incoming);
		state.committing = true;
		await commit(payload, finalUrl, placeholder ? Object.assign({}, options, { push: false, placeholder: true }) : options, token);
	} catch (error) {
		if (error && error.name === 'AbortError') return;
		if (token !== state.token) return;
		if (config.debug) console.warn('[delicat-engine] navigation fell back to a full load:', error && error.message);
		const target = error && error.redirect ? error.redirect : finalUrl;
		hardNavigate(target, placeholder || options.push === false || key(parseUrl(location.href)) === key(finalUrl));
	} finally {
		if (token === state.token) {
			state.inflight = null;
			state.committing = false;
			progressDone();
		}
	}
}

/* --------------------------------------------------------------- prefetch */

export function prefetch(href, { force = false } = {}) {
	if (cfg.prefetch === false) return null;
	const url = eligible(href);
	if (!url || sameDocument(url)) return null;
	if (recall(url)) return null;
	if (state.pending.has(url.href)) return state.pending.get(url.href).promise;
	if (!force && (device.slowNetwork || state.prefetched >= (device.lowPower ? Math.min(4, cfg.budget) : cfg.budget) || state.pending.size >= 2)) return null;
	if (force && device.saveData) return null;
	state.prefetched++;
	const controller = new AbortController();
	const promise = fetchDocument(url, { signal: controller.signal, prefetch: true })
		.then((entry) => { if (!entry.noStore) remember(entry.url, entry); return entry; })
		.catch(() => null)
		.finally(() => state.pending.delete(url.href));
	state.pending.set(url.href, { promise, controller });
	return promise;
}

function linkFrom(target) {
	return closest(target, 'a[href]');
}

function bindPrefetch() {
	const candidate = (target) => {
		const link = linkFrom(target);
		return link && interactiveLink(link) ? link : null;
	};
	on(doc, 'pointerover', (event) => {
		if (event.pointerType === 'touch') return;
		const link = candidate(event.target);
		if (!link) return;
		win.clearTimeout(state.hoverTimer);
		state.hoverTimer = win.setTimeout(() => prefetch(link.href), 80);
	}, { passive: true });
	on(doc, 'pointerout', (event) => {
		const link = candidate(event.target);
		if (!link || (event.relatedTarget && link.contains(event.relatedTarget))) return;
		win.clearTimeout(state.hoverTimer);
	}, { passive: true });
	on(doc, 'focusin', (event) => { const link = candidate(event.target); if (link) prefetch(link.href); });
	on(doc, 'pointerdown', (event) => {
		if (event.button || event.isPrimary === false) return;
		const link = candidate(event.target);
		if (!link) return;
		if (event.pointerType === 'touch') {
			const insideRail = !!link.closest('[data-delicat-track]');
			state.touchIntent = { link, x: event.clientX, y: event.clientY, timer: win.setTimeout(() => { state.touchIntent = null; prefetch(link.href, { force: true }); }, insideRail ? 140 : 70) };
			return;
		}
		prefetch(link.href, { force: true });
	}, { passive: true, capture: true });
	const cancelTouch = () => { if (state.touchIntent) { win.clearTimeout(state.touchIntent.timer); state.touchIntent = null; } };
	on(doc, 'pointermove', (event) => {
		if (!state.touchIntent || event.pointerType !== 'touch') return;
		if (Math.abs(event.clientX - state.touchIntent.x) > 8 || Math.abs(event.clientY - state.touchIntent.y) > 8) cancelTouch();
	}, { passive: true, capture: true });
	on(doc, 'pointercancel', cancelTouch, { passive: true, capture: true });
	on(doc, 'pointerup', cancelTouch, { passive: true, capture: true });
}

let visibleObserver = null;
function warmVisibleProducts() {
	if (device.slowNetwork || !('IntersectionObserver' in win) || cfg.viewportBudget <= 0) return;
	if (visibleObserver) visibleObserver.disconnect();
	let warmed = 0;
	const limit = device.lowPower ? 1 : cfg.viewportBudget;
	const observer = visibleObserver = new IntersectionObserver((entries) => {
		for (const entry of entries) {
			if (!entry.isIntersecting) continue;
			observer.unobserve(entry.target);
			if (warmed >= limit) { observer.disconnect(); return; }
			if (prefetch(entry.target.href)) warmed++;
		}
	}, { threshold: 0.3 });
	for (const link of qsa('a[data-delicat-product-link],a[data-delicat-instant-product],.delicat-woo-product-card a[href],li.product a.woocommerce-LoopProduct-link')) {
		if (interactiveLink(link) && eligible(link.href)) observer.observe(link);
	}
}

/* ------------------------------------------------------------- scroll back */

function restoreArrivalScroll() {
	let arrival = 0;
	try {
		const entries = win.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
		const byHistory = entries && entries[0] ? entries[0].type === 'back_forward' : (win.performance && performance.navigation && performance.navigation.type === 2);
		const saved = history.state;
		if (byHistory && saved && (saved.delicat || saved.dbv9) && saved.scroll > 0) arrival = saved.scroll;
	} catch (_) { arrival = 0; }
	if (history.scrollRestoration) history.scrollRestoration = 'manual';
	try { history.replaceState(Object.assign({}, history.state || {}, { delicat: 1, dbv9: 1, key: state.renderedKey, scroll: arrival || win.pageYOffset || 0 }), '', location.href); } catch (_) {}
	if (!(arrival > 0)) return;
	let userScrolled = false;
	const cancel = () => { userScrolled = true; };
	on(doc, 'wheel', cancel, { passive: true, once: true });
	on(doc, 'touchmove', cancel, { passive: true, once: true });
	let attempts = 0;
	let landed = -1;
	const restore = () => {
		if (userScrolled) return;
		const y = win.pageYOffset || 0;
		const expected = landed >= 0 ? landed : 0;
		if (Math.abs(y - expected) > 2 && Math.abs(y - arrival) > 2) { userScrolled = true; return; }
		jumpTo(arrival);
		landed = win.pageYOffset || 0;
		if (++attempts < 4 && Math.abs(landed - arrival) > 2) win.setTimeout(restore, 120 * attempts);
	};
	if (doc.readyState === 'complete') restore(); else win.addEventListener('load', restore, { once: true });
}

/* ------------------------------------------------------------------- bind */

export function bindNavigation() {
	if (!cfg.enabled || !win.fetch || !win.DOMParser || !history.pushState) return false;
	indexExistingAssets();

	on(doc, 'click', (event) => {
		if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		const link = linkFrom(event.target);
		if (!link || !interactiveLink(link)) return;
		const url = eligible(link.href);
		if (!url) return;
		if (sameDocument(url)) {
			if (url.hash) return;
			event.preventDefault();
			win.scrollTo({ top: 0, behavior: device.reducedMotion ? 'auto' : 'smooth' });
			return;
		}
		if (!currentMain()) return;
		event.preventDefault();
		const card = link.closest('[data-delicat-card],.delicat-product-card,.delicat-woo-product-card,li.product,.dpsr-switcher-option');
		state.lastCardImage = card ? card.querySelector('img') : null;
		navigate(url, { push: true, link });
	});

	if (cfg.forms) {
		on(doc, 'submit', (event) => {
			const form = event.target;
			if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
			if ((form.method || 'get').toLowerCase() !== 'get') return;
			if (form.hasAttribute('data-no-shell') || form.closest('[data-no-turbo],[data-delicat-nav="hard"]')) return;
			if (!form.matches('[role="search"],.woocommerce-product-search,[data-delicat-search-form],.search-form,[data-delicat-nav="soft"]')) return;
			const action = parseUrl(form.getAttribute('action') || location.href);
			if (!action) return;
			const params = new URLSearchParams(new FormData(form));
			action.search = params.toString();
			const url = eligible(action.href);
			if (!url || !currentMain()) return;
			event.preventDefault();
			navigate(url, { push: true });
		});
	}

	on(win, 'popstate', (event) => {
		if (!state.swapped) return; /* a fresh or bfcache-restored document already matches the address bar */
		if (!currentMain()) return;
		const url = parseUrl(location.href);
		if (!url) return;
		if (key(url) === state.renderedKey) return; /* hash-only traversal: the browser owns it */
		if (!eligible(url.href)) { hardNavigate(url, true); return; }
		const saved = state.scrolls.get(key(url));
		const restore = typeof saved === 'number' ? saved : (event.state && typeof event.state.scroll === 'number' ? event.state.scroll : 0);
		navigate(url, { push: false, restoreTo: restore });
	});

	on(win, 'pagehide', () => { try { history.replaceState(Object.assign({}, history.state || {}, { delicat: 1, dbv9: 1, scroll: win.pageYOffset || 0 }), '', location.href); } catch (_) {} });
	on(win, 'pageshow', progressDone);
	restoreArrivalScroll();

	/* Anything that changes the cart, wallet, identity or currency makes cached markup wrong. */
	const forget = () => invalidate();
	for (const name of ['delicat:cart-changed', 'delicat:wallet-changed', 'delicat:currency-changed', 'delicat:auth-changed', 'delicat:express-added', 'added_to_cart', 'removed_from_cart', 'wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart']) on(doc, name, forget);
	on(doc, 'delicat:pro:state', (event) => { if (event.detail && event.detail.changed) forget(); });
	const $ = jq();
	if ($) { try { $(doc.body).on('added_to_cart removed_from_cart updated_cart_totals', forget); } catch (_) {} }

	if (cfg.prefetch !== false) {
		bindPrefetch();
		const warm = () => idle(warmVisibleProducts, 2500);
		if (doc.readyState === 'complete') win.setTimeout(warm, 1200); else win.addEventListener('load', () => win.setTimeout(warm, 1200), { once: true });
		onNav('swapped', () => { state.prefetched = 0; win.setTimeout(warm, 600); });
		/* The bar's own destinations are the most tapped links on the site:
		   fetch the ones the engine may swap (home, shop) while the page is
		   idle, so a tab switch is answered from memory. */
		if (cfg.tabWarm !== false) {
			const warmTabs = () => idle(() => {
				if (device.slowNetwork || device.saveData || doc.hidden) return;
				for (const item of qsa('.delicat-bottom-nav .dbn-item[href]')) {
					const url = eligible(item.href);
					if (url && !sameDocument(url)) prefetch(url.href, { force: true });
				}
			}, 4000);
			if (doc.readyState === 'complete') win.setTimeout(warmTabs, 3500); else win.addEventListener('load', () => win.setTimeout(warmTabs, 3500), { once: true });
			onNav('swapped', () => win.setTimeout(warmTabs, 2500));
		}
	}

	html.classList.add('delicat-shell-nav', 'delicat-engine-nav');
	on(doc, 'click', guardDimmedTaps, { capture: true });
	return true;
}

export const nav = {
	navigate: (href, options) => { const url = eligible(href); if (url) return navigate(url, options || {}); location.assign(href); },
	prefetch: (href) => prefetch(href),
	invalidate,
	eligible,
	isProductUrl,
	on: onNav,
	stats: () => ({ cached: state.cache.size, pending: state.pending.size, prefetched: state.prefetched, slow: device.slowNetwork, transport: 'document' }),
};
