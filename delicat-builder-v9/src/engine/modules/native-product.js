/**
 * Native product page: the sticky purchase dock mirrors the live add-to-cart /
 * buy-now buttons (stock, price, disabled state), progressive "Voir plus" for
 * long swatch grids. Content-scoped: re-mounts on every product swap.
 */
import { doc, inertDocument, jq, on, qsa, raf, win } from '../core/dom.js';

function moneyText(html) {
	const parsed = inertDocument('<body>' + String(html || ''));
	if (!parsed) return '';
	const node = parsed.querySelector('ins .woocommerce-Price-amount,ins .amount') || parsed.querySelector('.woocommerce-Price-amount:last-child,.amount:last-child');
	return (node ? node.textContent : parsed.body.textContent || '').replace(/\s+/g, ' ').trim();
}
const isDisabled = (btn) => !btn || btn.disabled || btn.classList.contains('disabled') || btn.getAttribute('aria-disabled') === 'true';

function boot(root, signal) {
	if (!root || root.dataset.dnpBound === '1') return;
	root.dataset.dnpBound = '1';
	const form = root.querySelector('form.cart');
	if (!form) return;
	const variable = root.dataset.variable === '1';
	const stock = root.querySelector('[data-dnp-stock]');
	const price = root.querySelector('[data-dnp-price]');
	const liveForm = () => (root.isConnected ? root : doc).querySelector('form.cart') || form;
	const liveVariation = () => liveForm().querySelector('input.variation_id,input[name="variation_id"]');
	const liveAdd = () => liveForm().querySelector('.single_add_to_cart_button');
	const liveBuy = () => liveForm().querySelector('.dnp-buy-now');
	const dock = doc.querySelector('[data-dnp-dock][data-product-id="' + root.dataset.productId + '"]');
	const dockPrice = dock && dock.querySelector('[data-dnp-dock-price]');
	const dockAdd = dock && dock.querySelector('[data-dnp-proxy="add"]');
	const dockBuy = dock && dock.querySelector('[data-dnp-proxy="buy"]');
	const baseOK = root.dataset.baseStock !== '0' && root.dataset.basePurchasable !== '0';
	let variationOK = variable ? false : baseOK;
	let currentPrice = variable ? 'Choisissez une option' : (root.dataset.basePrice || moneyText(price && price.innerHTML));
	let syncQueued = false;

	const selected = () => { const vi = liveVariation(); return !variable || !!(vi && parseInt(vi.value, 10) > 0); };
	const setStock = (ok, label) => {
		if (!stock) return;
		stock.classList.toggle('is-neutral', ok === null); stock.classList.toggle('is-in', ok === true); stock.classList.toggle('is-out', ok === false);
		const text = stock.querySelector('span');
		const next = label || (ok === null ? 'Sélectionnez une option' : (ok ? 'Disponible' : 'Rupture de stock'));
		if (text && text.textContent !== next) text.textContent = next;
	};
	const ready = () => selected() && variationOK === true && !isDisabled(liveAdd());
	const syncDock = () => {
		syncQueued = false;
		if (!root.isConnected || !dock || !dock.isConnected) return;
		if (variable && !variationOK && selected()) {
			const vi = liveVariation();
			const hint = vi && vi.form && vi.form.querySelector('.single_variation .stock');
			variationOK = !(hint && /out-of-stock|rupture/i.test(hint.className + ' ' + hint.textContent));
			if (variationOK) setStock(true, 'Disponible');
		}
		if (variable && selected() && (!currentPrice || currentPrice === 'Choisissez une option')) {
			const pr = liveForm().querySelector('.woocommerce-variation-price .price,.single_variation .price');
			const txt = pr ? moneyText(pr.innerHTML) : '';
			if (txt) currentPrice = txt;
		}
		const nextPrice = currentPrice || 'Choisissez une option';
		if (dockPrice && dockPrice.textContent !== nextPrice) dockPrice.textContent = nextPrice;
		const ok = ready();
		if (dockAdd && dockAdd.disabled === ok) dockAdd.disabled = !ok;
		if (dockBuy && dockBuy.disabled === ok) dockBuy.disabled = !ok;
		dock.hidden = false;
		doc.body.classList.add('dnp-dock-visible', 'dnp-floating-ready');
	};
	const scheduleSync = () => { if (syncQueued) return; syncQueued = true; raf(syncDock); };
	const resetVariable = () => { variationOK = false; currentPrice = 'Choisissez une option'; setStock(null); scheduleSync(); };

	if (dock) {
		on(dock, 'click', (event) => {
			const btn = event.target.closest('[data-dnp-proxy]');
			if (!btn || btn.disabled) return;
			const real = btn.dataset.dnpProxy === 'buy' ? liveBuy() : liveAdd();
			if (real && !isDisabled(real)) real.click(); else scheduleSync();
		}, { signal });
	}
	const $ = jq();
	if ($) {
		$(form).on('found_variation.dnp', (event, v) => {
			variationOK = !!(v && v.is_in_stock !== false && v.is_purchasable !== false && v.variation_is_active !== false);
			setStock(variationOK, variationOK ? 'Disponible' : 'Rupture de stock');
			const next = moneyText(v && v.price_html);
			if (price && v && v.price_html) { const parsed = inertDocument('<body>' + v.price_html); if (parsed) price.replaceChildren(...Array.from(parsed.body.childNodes)); }
			if (next) currentPrice = next;
			scheduleSync();
		});
		$(form).on('hide_variation.dnp reset_data.dnp', () => { if (variable) resetVariable(); else { variationOK = baseOK; setStock(baseOK); scheduleSync(); } });
		signal.addEventListener('abort', () => { try { $(form).off('.dnp'); } catch (_) {} }, { once: true });
	}
	on(form, 'dmc:total_updated', (event) => {
		const detail = event.detail || {};
		if (detail.ready && detail.formatted && detail.formatted !== '—') currentPrice = String(detail.formatted);
		else if (variable && !selected()) currentPrice = 'Choisissez une option';
		scheduleSync();
	}, { signal });
	if ('MutationObserver' in win) {
		const observer = new MutationObserver(scheduleSync);
		observer.observe(form, { attributes: true, subtree: true, childList: true, attributeFilter: ['disabled', 'class', 'aria-disabled', 'value'] });
		signal.addEventListener('abort', () => observer.disconnect(), { once: true });
	}
	on(form, 'change', scheduleSync, { signal });
	on(form, 'input', scheduleSync, { signal });
	for (const name of ['dmc:field_verified', 'ddg:verified', 'delicat:verified']) on(doc, name, scheduleSync, { signal });
	on(win, 'pageshow', scheduleSync, { signal });
	signal.addEventListener('abort', () => { doc.body.classList.remove('dnp-dock-visible', 'dnp-floating-ready'); }, { once: true });
	setStock(variable ? null : baseOK);
	scheduleSync();
}

function progressive(root, signal) {
	for (const grid of qsa('.ddsw-root:not(.ddsw-preset-delicat-abonnement-premium) .ddsw-grid', root)) {
		if (grid.dataset.dnpProgressive === '1') continue;
		const cards = Array.from(grid.children).filter((node) => node.classList && node.classList.contains('ddsw-card'));
		if (cards.length <= 12) continue;
		grid.dataset.dnpProgressive = '1';
		for (const card of cards.slice(12)) if (!card.classList.contains('is-selected')) card.hidden = true;
		const btn = doc.createElement('button');
		btn.type = 'button';
		btn.className = 'dnp-more-options';
		btn.textContent = 'Voir plus (' + (cards.length - 12) + ')';
		on(btn, 'click', () => { cards.forEach((card) => { card.hidden = false; }); btn.remove(); }, { once: true, signal });
		grid.insertAdjacentElement('afterend', btn);
	}
}

export default function mount({ root, signal }) {
	for (const node of qsa('.delicat-native-product', root)) { boot(node, signal); progressive(node, signal); }
	on(doc, 'dsb:content-updated', (event) => {
		if (event.detail && event.detail.source === 'engine') return;
		for (const node of qsa('.delicat-native-product')) { boot(node, signal); progressive(node, signal); }
	}, { signal });
}
