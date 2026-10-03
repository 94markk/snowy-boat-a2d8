/**
 * CSS route bundles.
 *
 * The storefront used to request 9–14 stylesheets per page. The engine serves
 * three: `chrome` (every page, first), one route bundle, then `polish` (every
 * page, last). Inside a bundle the files are concatenated in exactly the order
 * WordPress used to print them (wp_enqueue_scripts priority order), because
 * the current look is decided by that cascade; no rule has been rewritten.
 *
 * The PHP side (Delicat_Builder_V9_Engine) picks the route bundle from the
 * request, dequeues every legacy stylesheet whose file is inside the chosen
 * bundles and re-prints their inline additions after the route bundle. A
 * stylesheet that is not part of any chosen bundle is left alone, so nothing
 * optional (app page, legacy shell, legacy menu) is ever lost.
 */
const component = (name) => `assets/components/${name}.css`;

/* Same order as all-components.min.css (the bundle shoppers were served). */
export const COMPONENTS = ['base', 'testimonials', 'trust-strip', 'products', 'newsletter', 'how-it-works', 'hero', 'bon-kliyan', 'text', 'banner', 'category-chips', 'faq', 'why-delicat', 'favorites', 'spacer'].map(component);

/* Priority 8 → 28: the app shell plus the small always-on widgets. */
const CHROME = [
	'assets/css/theme-system.css',
	'assets/dsb8-beta2-header.css',
	'assets/css/drawer.css',
	'assets/css/bottom-nav.css',
	'assets/css/pwa-runtime.css',
	'assets/css/notifications.css',
	'assets/css/currency.css',
	'src/styles/engine.css',
];

/* Priority 32: present on every storefront route, after the route owner (30). */
const REVIEWS = 'assets/css/reviews.css';
const DOCUMENT = 'assets/css/native-document.css'; /* 35 */
const FOOTER = 'assets/css/native-footer.css'; /* 40 */
const DESIGN = 'assets/css/design-studio.css'; /* 45, only when Design Studio is enabled */

/* Priority 998 → 100100: the correction layers that decide most of the look. */
const POLISH_HEAD = ['assets/css/app-polish.css', 'assets/css/app-tuning.css'];
const POLISH_TAIL = ['pro/assets/dbp-app.css', 'pro/assets/dbp-type.css', 'src/styles/fixes.css'];

/* The managed homepage starter uses these four components; a homepage that
   adds another section type is served the full `builder` set instead. */
export const HOME_COMPONENTS = COMPONENTS.filter((file) => /\/(base|products|hero|category-chips)\.css$/.test(file));

/* `name` and, when Design Studio has enqueued its sheet, `name-design`. */
const withDesign = (name, files) => ({ [name]: files, [`${name}-design`]: [...files, DESIGN] });

/*
 * Optional sheets stay as their own <link> and are not folded: calc.css (10),
 * express-checkout.css + wallet-guard.css (20), product-switcher.css (24),
 * native-pages.css (25), announcement.css and live-selling.css (34),
 * purchase-ui.css (40) on cart/checkout. Each prints before the route bundle,
 * which is where every one of them already stood in the cascade relative to
 * the route owner, except the three widget sheets, whose selectors are their
 * own (.dbv9-announcement, .dbv9-live-sale, .dbv9-currency).
 */
export const bundles = {
	chrome: CHROME,

	/* Managed homepage (starter components only, no Design Studio sheet, as before). */
	home: [...HOME_COMPONENTS, REVIEWS, DOCUMENT, FOOTER],
	'home-lite': [REVIEWS, DOCUMENT, FOOTER],
	/* Any other Builder page, or a homepage with extra section types. */
	...withDesign('builder', [...COMPONENTS, REVIEWS, DOCUMENT, FOOTER]),
	/* Builder pages served with their own compiled component sheet (uploads/…/compiled): that sheet stays. */
	...withDesign('builder-lite', [REVIEWS, DOCUMENT, FOOTER]),
	/* Native single product. */
	...withDesign('product', ['assets/css/native-product.css', REVIEWS, FOOTER, 'assets/css/purchase-ui.css']),
	/* Native shop / category archive. */
	...withDesign('shop', [REVIEWS, 'assets/css/native-archive.css', FOOTER]),
	/* Native documents: info pages, search, 404, account, thank-you, plain pages. */
	page: [REVIEWS, DOCUMENT, FOOTER, 'assets/css/site.css'],
	/* Cart and checkout (purchase-native owner). */
	purchase: [REVIEWS, DOCUMENT, FOOTER, 'assets/css/purchase-native.css', 'assets/css/wallet-guard.css'],
	/* Classic WooCommerce archive and search when the native archive is not published. */
	...withDesign('woo', [REVIEWS, 'assets/css/woo-ui.css', FOOTER, 'assets/css/site.css']),

	polish: [...POLISH_HEAD, ...POLISH_TAIL],
	'polish-storefront': [...POLISH_HEAD, 'assets/css/storefront-polish.css', ...POLISH_TAIL],
};
