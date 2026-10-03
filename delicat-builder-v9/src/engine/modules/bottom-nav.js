/**
 * Bottom navigation bar and the (legacy) mobile dock: keep the cart badge and
 * the active item honest. The badge is painted by the session store; this
 * module only covers the first paint from the WooCommerce cookie and the
 * active state on the initial route (the navigation engine syncs it on swaps).
 */
import { doc, on, qsa, win } from '../core/dom.js';
import { paintCartCount, wooCookieCount } from '../core/state.js';

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

	for (const item of qsa('.delicat-bottom-nav .dbn-item.is-active')) item.setAttribute('aria-current', 'page');
}
