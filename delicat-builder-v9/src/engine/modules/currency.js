/**
 * Currency switcher ([data-dbv9-currency]): a change is a full reload with
 * ?dmc_currency=XXX, because prices on every surface (and the cached page copy)
 * change with it. Add-to-cart is removed from the URL so the reload never
 * re-adds an item.
 */
import { emit, on, qsa } from '../core/dom.js';
import { clearDocumentCache } from '../core/state.js';

const go = (code) => {
	if (!/^[A-Z]{3}$/.test(code || '')) return;
	const url = new URL(location.href);
	url.searchParams.set('dmc_currency', code);
	url.searchParams.delete('add-to-cart');
	/* Every cached document shows the old currency: drop the service-worker copies first. */
	clearDocumentCache();
	emit('delicat:currency-changed', { currency: code });
	location.assign(url.toString());
};

export default function mount({ root, signal }) {
	for (const node of qsa('[data-dbv9-currency]', root)) {
		if (node.dataset.ready === '1') continue;
		node.dataset.ready = '1';
		const select = node.querySelector('[data-dbv9-currency-select]');
		if (select) on(select, 'change', (event) => go(String(event.target.value || '').toUpperCase()), { signal });
		on(node, 'click', (event) => {
			const button = event.target instanceof Element ? event.target.closest('[data-dbv9-currency-value]') : null;
			if (button) go(String(button.dataset.dbv9CurrencyValue || '').toUpperCase());
		}, { signal });
	}
}
