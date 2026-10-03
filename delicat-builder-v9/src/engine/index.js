/**
 * Delicat Builder V9 — storefront engine entry point.
 *
 * One ES module replaces the fourteen scripts the storefront used to load:
 * core, carousel, islands, heart, hero-search, theme, motion, drawer, header,
 * session, dbp-nav, dbp-state, shell-nav, prefetch … Each feature is a module
 * registered below with the scope it lives in (document or content), the DOM
 * it needs (`when`) and, for route-specific features, a lazy chunk.
 *
 * Boot order: session store events → navigation engine → document modules →
 * content modules for the current <main>. After every soft navigation the
 * content modules are released (their AbortSignal fires) and mounted again on
 * the new <main>.
 */
import { closest, device, doc, emit, html, idle, on, win } from './core/dom.js';
import { attach, config, define, engine, mountContent, mountDocument } from './core/runtime.js';
import { bindSessionEvents, session } from './core/state.js';
import { bindNavigation, nav } from './core/nav.js';
import { overlay } from './core/overlay.js';
import theme from './modules/theme.js';
import header from './modules/header.js';
import drawer from './modules/drawer.js';
import bottomNav from './modules/bottom-nav.js';
import carousel, { carouselApi } from './modules/carousel.js';
import heart from './modules/heart.js';
import heroSearch from './modules/hero-search.js';
import motion from './modules/motion.js';
import currency from './modules/currency.js';
import pwa from './modules/pwa.js';
import walletLive from './modules/wallet-live.js';
import marquee from './modules/marquee.js';
import banner from './modules/banner.js';

const VERSION = '9.3.3';
const hasClass = (name) => !!(doc.body && doc.body.classList.contains(name));
const productRoute = () => import('./routes/product.js');
const purchaseRoute = () => import('./routes/purchase.js');
const widgetsRoute = () => import('./routes/widgets.js');
const reviewsModule = () => import('./modules/reviews.js');
const pick = (loader, name) => () => loader().then((m) => ({ default: m[name] }));

/* ------------------------------------------------- chrome: document scope */

define({ name: 'theme', scope: 'document', mount: theme });
define({ name: 'header', scope: 'document', when: '[data-dsb8-header]', mount: header });
define({ name: 'drawer', scope: 'document', when: '[data-dlx-drawer]', mount: drawer });
define({ name: 'bottom-nav', scope: 'document', when: '[data-delicat-bottom-nav],[data-delicat-mobile-dock]', mount: bottomNav });
define({ name: 'shell-header', scope: 'document', when: '[data-delicat-shell-header]', load: () => import('./modules/shell-header.js') });
define({ name: 'pwa', scope: 'document', when: () => !!(win.DelicaPWARuntime || config.pwa || win.DelicatAndroidPage || config.androidPage), mount: pwa });
define({ name: 'reviews', scope: 'document', mount: reviewsGate });
define({ name: 'announcement', scope: 'document', when: '[data-dbv9-announcement]', load: pick(widgetsRoute, 'announcement') });
define({ name: 'live-selling', scope: 'document', when: () => !!(win.DelicatLiveSelling || config.liveSelling), load: pick(widgetsRoute, 'liveSelling') });
define({ name: 'pull-refresh', scope: 'document', when: () => !!config.pullRefresh, load: pick(widgetsRoute, 'pullRefresh') });
define({ name: 'notifications', scope: 'document', when: '[data-dbv9-notifications]', mount: notificationsGate });

/* ------------------------------------------- content: re-mounted per page */

define({ name: 'carousel', when: '[data-delicat-carousel]', mount: carousel });
define({ name: 'heart', when: '[data-delicat-like],[data-delicat-carousel],.delicat-product-card', mount: heart });
define({ name: 'hero-search', when: '[data-delicat-hero-search]', mount: heroSearch });
define({ name: 'motion', when: '.delicat-page-layout[data-delicat-page-layout],.dbv9-reveal', mount: motion });
define({ name: 'marquee', when: '[data-dbv9-marquee-clone]', mount: marquee });
define({ name: 'banner', when: '[data-dbv9-banner-rail]', mount: banner });
define({ name: 'currency', always: true, when: '[data-dbv9-currency]', mount: currency });
define({ name: 'wallet-live', always: true, when: '[data-dsb-wallet]', mount: walletLive });
define({ name: 'purchase', always: true, when: () => !!(win.DelicaPurchaseV9 || config.purchase), load: pick(purchaseRoute, 'purchase') });
define({ name: 'wallet-guard', always: true, when: () => hasClass('dpn-checkout') || hasClass('dnp-express-enabled'), load: pick(purchaseRoute, 'walletGuard') });
define({ name: 'storefront-commerce', always: true, when: () => hasClass('delicat-native-checkout') || hasClass('delicat-native-cart') || !!doc.querySelector('[data-dcn-clear-cart]'), load: pick(purchaseRoute, 'storefrontCommerce') });
define({ name: 'purchase-native', always: true, when: () => hasClass('dpn-cart') || hasClass('dpn-checkout') || !!win.DelicatPurchaseNative, load: () => import('./modules/purchase-native.js') });
define({ name: 'native-product', when: '.delicat-native-product', load: pick(productRoute, 'nativeProduct') });
define({ name: 'product-switcher', when: '.dpsr-switcher', load: pick(productRoute, 'productSwitcher') });
define({ name: 'design-product', always: true, when: (root) => !!(root.querySelector('[data-delicat-design-options]') || doc.querySelector('[data-delicat-design-buybar]')), load: pick(productRoute, 'designProduct') });
define({ name: 'calc', when: '.dmc-calc', load: pick(productRoute, 'calc') });
define({ name: 'express-checkout', always: true, when: '[data-dnp-express]', load: pick(productRoute, 'expressCheckout') });

/* ---------------------------------------------------------- notifications */

/**
 * The bell panel code is only needed once someone taps the bell. Until then a
 * capture-phase listener holds the first tap, loads the chunk, mounts it and
 * opens the panel for that tap; the chunk then owns the bell.
 */
let notificationsChunk = null;
const loadNotifications = () => notificationsChunk || (notificationsChunk = import('./modules/notifications.js'));
function notificationsGate({ signal }) {
	const gate = new AbortController();
	signal.addEventListener('abort', () => gate.abort(), { once: true });
	let mounting = null;
	on(doc, 'click', (event) => {
		const bell = closest(event.target, '[data-dbv9-notification-open]');
		if (!bell) return;
		event.preventDefault();
		event.stopPropagation();
		if (!mounting) mounting = loadNotifications().then((m) => m.default({ root: doc, signal, config, engine, reason: 'click' })).catch(() => null);
		mounting.then((api) => {
			gate.abort();
			const root = bell.closest('[data-dbv9-notifications]');
			if (api && root) api.open(root);
		});
	}, { capture: true, signal: gate.signal });
	/* Warm the chunk so the first tap opens without a network round-trip. */
	if (!device.saveData && !device.slowNetwork) idle(() => { if (!gate.signal.aborted) loadNotifications().catch(() => {}); }, 4000);
}

/* ---------------------------------------------------------------- reviews */

/**
 * The review flow (invite after an order, "laisser un avis" buttons) is a
 * chunk: a guest only needs it on a tap, a signed-in shopper gets it at idle
 * so the post-order invite still appears on its own.
 *
 * The gate is installed on every page, whatever the landing page printed: a
 * shopper who opens the app on the shop or the cart and then taps a product
 * still gets the sheet (the config travels in the engine config). A chunk
 * that failed to load is tried again on the next tap, and the sheet opens
 * through the module's API rather than a replayed click, so the tap that
 * loaded the module is the tap that opens it.
 */
function reviewsGate({ signal }) {
	const live = () => win.DelicatReviewsConfig || config.reviews || null;
	const gate = new AbortController();
	signal.addEventListener('abort', () => gate.abort(), { once: true });
	let mounting = null;
	const mountReviews = () => mounting || (mounting = reviewsModule()
		.then((m) => m.default({ root: doc, signal, config, engine, reason: 'lazy' }))
		.then((api) => { if (api) { gate.abort(); return api; } mounting = null; return null; })
		.catch(() => { mounting = null; return null; }));
	on(doc, 'click', (event) => {
		const trigger = closest(event.target, '[data-dlc-review-open]');
		if (!trigger) return;
		event.preventDefault();
		event.stopPropagation();
		mountReviews().then((api) => { if (api) api.open(trigger); });
	}, { capture: true, signal: gate.signal });
	const cfg = live();
	if (cfg && cfg.loggedIn) idle(() => { if (!gate.signal.aborted) mountReviews(); }, 3000);
}

/* ------------------------------------------------------------------ boot */

attach({ nav, session, overlay, config, carousel: carouselApi, mountContent, version: VERSION });
win.DelicatBuilderEngine = engine;
/* Compatibility surface for code written against the previous runtimes. */
win.DelicatSession = { get: session.get, ready: session.ready, refresh: session.refresh, on: session.on, subscribe: session.subscribe };
win.DBPState = { get: session.get, refresh: session.refresh, subscribe: session.subscribe };
win.DBPNav = { navigate: nav.navigate, prefetch: nav.prefetch, invalidate: nav.invalidate, stats: nav.stats };

const mainSelector = () => (config.nav && config.nav.main) || 'main[data-delicat-server-render],main[data-delicat-native-document],main';
const currentMain = () => doc.querySelector(mainSelector()) || doc.body;

/* Globals printed for one page (DelicatExpress, DelicaPurchaseV9 …) must not
   leak into the next page of a soft navigation; the server lists the names it
   printed and the engine drops the ones the new page did not print again. */
let pageGlobals = new Set(Array.isArray(config.globals) ? config.globals : []);
function dropStaleGlobals() {
	const next = new Set(Array.isArray(config.globals) ? config.globals : []);
	for (const name of pageGlobals) {
		if (next.has(name)) continue;
		try { delete win[name]; } catch (_) { win[name] = undefined; }
	}
	pageGlobals = next;
}

/* A failure anywhere in the runtime is reported once per message, never
   thrown across the page: the server-rendered document stays usable. */
function captureErrors() {
	const seen = new Set();
	const report = (message, source) => {
		const key = String(message).slice(0, 160);
		if (seen.has(key) || seen.size > 20) return;
		seen.add(key);
		if (config.debug) console.error('[delicat-engine]', source, message);
		emit('delicat:engine:error', { module: source, phase: 'runtime', message: key });
	};
	on(win, 'error', (event) => {
		const file = String((event && event.filename) || '');
		if (file && !/\/assets\/dist\//.test(file)) return;
		report((event && event.message) || 'error', 'window');
	});
	on(win, 'unhandledrejection', (event) => {
		const reason = event && event.reason;
		const stack = String((reason && reason.stack) || '');
		if (stack && !/\/assets\/dist\//.test(stack)) return;
		report((reason && reason.message) || String(reason || 'rejection'), 'promise');
	});
}

function boot() {
	captureErrors();
	bindSessionEvents();
	bindNavigation();
	nav.on('swapped', (detail) => { dropStaleGlobals(); mountContent(detail.main, 'navigate'); });
	html.classList.add('delicat-engine');
	mountDocument('load');
	mountContent(currentMain(), 'load').then(() => emit('delicat:engine:ready', { version: VERSION }));
}

if (doc.readyState === 'loading') on(doc, 'DOMContentLoaded', boot, { once: true });
else boot();
