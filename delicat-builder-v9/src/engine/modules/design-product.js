/**
 * Design Studio product page: variation option cards built from the Woo
 * selects, and the sticky buy bar mirroring the real add-to-cart button.
 */
import { device, doc, inertDocument, jq, on, raf, win } from '../core/dom.js';

export default function mount({ root, signal }) {
	const form = root.querySelector('form.variations_form, form.cart');
	const mountNode = root.querySelector('[data-delicat-design-options]');
	const buybar = doc.querySelector('[data-delicat-design-buybar]');
	if (!form && !mountNode && !buybar) return;

	const priceText = () => {
		const variation = doc.querySelector('.summary .woocommerce-variation-price .price');
		const main = doc.querySelector('.summary > p.price, .summary > span.price, .summary .price');
		const node = variation && variation.textContent.trim() ? variation : main;
		return node && node.textContent ? node.textContent.replace(/\s+/g, ' ').trim() : '';
	};
	const originalButton = form ? form.querySelector('.single_add_to_cart_button') : null;
	const selectionsValid = () => Array.from(form ? form.querySelectorAll('select[name^="attribute_"]') : []).every((select) => !!select.value);
	const buttonEnabled = () => !!(originalButton && !originalButton.disabled && !originalButton.classList.contains('disabled') && originalButton.getAttribute('aria-disabled') !== 'true' && selectionsValid());
	const syncTotal = () => { const total = doc.querySelector('[data-delicat-design-total]'); const value = priceText(); if (total && value) total.textContent = value; };
	const scrollToForm = () => form.scrollIntoView({ behavior: device.reducedMotion ? 'auto' : 'smooth', block: 'center' });

	const syncBar = () => {
		if (!buybar || !form || !originalButton) return;
		const enabled = buttonEnabled();
		for (const button of [buybar.querySelector('[data-delicat-design-cart]'), buybar.querySelector('[data-delicat-design-buy]')]) {
			if (!button) continue;
			button.disabled = !enabled;
			button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
		}
		syncTotal();
		buybar.hidden = false;
	};

	const buildVariationCards = () => {
		if (!mountNode || !form || !form.matches('form.variations_form')) return;
		const target = mountNode.querySelector('[data-delicat-design-groups]');
		const table = form.querySelector('table.variations');
		if (!target || !table) return;
		target.replaceChildren();
		const selects = Array.from(table.querySelectorAll('select[name^="attribute_"]'));
		let variationData = [];
		try { variationData = JSON.parse(form.dataset.product_variations || '[]'); } catch (_) { variationData = []; }
		const singleAttribute = selects.length === 1;
		for (const select of selects) {
			const row = select.closest('tr');
			const labelNode = row ? row.querySelector('th.label label') : null;
			const label = (labelNode && labelNode.textContent.trim()) || select.dataset.attribute_name || 'Option';
			const options = Array.from(select.options).filter((option) => option.value);
			if (!options.length) continue;
			const group = doc.createElement('fieldset');
			group.className = 'delicat-design-option-group';
			group.dataset.attribute = select.name;
			const legend = doc.createElement('legend');
			legend.className = 'delicat-design-option-group__legend';
			legend.textContent = label;
			group.appendChild(legend);
			const grid = doc.createElement('div');
			grid.className = 'delicat-design-option-grid';
			const syncGroup = () => {
				for (const button of grid.querySelectorAll('.delicat-design-option-card')) {
					const opt = Array.from(select.options).find((item) => item.value === button.dataset.value);
					const active = select.value === button.dataset.value;
					button.classList.toggle('is-selected', active);
					button.classList.toggle('is-disabled', !!(opt && opt.disabled));
					button.disabled = !!(opt && opt.disabled);
					button.setAttribute('aria-pressed', active ? 'true' : 'false');
				}
				syncBar();
			};
			for (const option of options) {
				const button = doc.createElement('button');
				button.type = 'button';
				button.className = 'delicat-design-option-card';
				button.dataset.value = option.value;
				button.setAttribute('aria-pressed', select.value === option.value ? 'true' : 'false');
				const name = doc.createElement('strong');
				name.textContent = option.textContent.trim();
				button.appendChild(name);
				if (singleAttribute && Array.isArray(variationData)) {
					const match = variationData.find((variation) => { const attrs = (variation && variation.attributes) || {}; return attrs[select.name] === option.value || attrs[select.name] === ''; });
					if (match && match.price_html) {
						const parsed = inertDocument(String(match.price_html));
						const price = parsed ? parsed.body.textContent.replace(/\s+/g, ' ').trim() : '';
						if (price) { const priceNode = doc.createElement('span'); priceNode.className = 'delicat-design-option-card__price'; priceNode.textContent = price; button.appendChild(priceNode); }
					}
				}
				on(button, 'click', () => { if (option.disabled) return; select.value = option.value; select.dispatchEvent(new Event('change', { bubbles: true })); syncGroup(); }, { signal });
				grid.appendChild(button);
			}
			group.appendChild(grid);
			target.appendChild(group);
			on(select, 'change', syncGroup, { signal });
			syncGroup();
		}
		if (target.children.length) {
			const reset = doc.createElement('button');
			reset.type = 'button';
			reset.className = 'delicat-design-options__reset';
			reset.textContent = 'Réinitialiser';
			on(reset, 'click', () => { for (const select of selects) { select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true })); } const r = form.querySelector('.reset_variations'); if (r) r.click(); }, { signal });
			target.appendChild(reset);
			table.classList.add('delicat-design-native-variations');
			mountNode.hidden = false;
		} else {
			mountNode.remove();
		}
	};

	const ensureBuyNowFields = () => {
		if (!form) return null;
		let flag = form.querySelector('input[name="delicat_buy_now"]');
		if (!flag) { flag = doc.createElement('input'); flag.type = 'hidden'; flag.name = 'delicat_buy_now'; flag.value = '0'; form.appendChild(flag); }
		let nonce = form.querySelector('input[name="delicat_buy_now_nonce"]');
		if (!nonce && buybar && buybar.dataset.buyNonce) { nonce = doc.createElement('input'); nonce.type = 'hidden'; nonce.name = 'delicat_buy_now_nonce'; nonce.value = buybar.dataset.buyNonce; form.appendChild(nonce); }
		return flag;
	};

	if (buybar && form && originalButton) {
		const cart = buybar.querySelector('[data-delicat-design-cart]');
		const buy = buybar.querySelector('[data-delicat-design-buy]');
		if (cart) on(cart, 'click', () => { if (!buttonEnabled()) { scrollToForm(); return; } const flag = ensureBuyNowFields(); if (flag) flag.value = '0'; originalButton.click(); }, { signal });
		if (buy) on(buy, 'click', () => { if (!buttonEnabled()) { scrollToForm(); return; } const flag = ensureBuyNowFields(); if (flag) flag.value = '1'; originalButton.click(); }, { signal });
		let frame = 0;
		const queue = () => { if (frame) return; frame = raf(() => { frame = 0; syncBar(); }); };
		const observer = new MutationObserver(queue);
		observer.observe(form, { subtree: true, childList: true, attributes: true, attributeFilter: ['disabled', 'class', 'value'] });
		signal.addEventListener('abort', () => observer.disconnect(), { once: true });
		on(form, 'change', queue, { passive: true, signal });
		on(form, 'input', queue, { passive: true, signal });
		const $ = jq();
		if ($) { $(form).on('found_variation.ddp reset_data.ddp woocommerce_variation_has_changed.ddp', queue); signal.addEventListener('abort', () => { try { $(form).off('.ddp'); } catch (_) {} }, { once: true }); }
		syncBar();
	}
	buildVariationCards();
	syncTotal();
}
