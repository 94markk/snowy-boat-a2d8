(() => {
	'use strict';

	const doc = document;
	const form = doc.querySelector('form.variations_form, form.cart');
	const mount = doc.querySelector('[data-delicat-design-options]');
	const buybar = doc.querySelector('[data-delicat-design-buybar]');
	if (!form && !mount && !buybar) return;

	const priceText = () => {
		const variation = doc.querySelector('.summary .woocommerce-variation-price .price');
		const main = doc.querySelector('.summary > p.price, .summary > span.price, .summary .price');
		const node = variation && variation.textContent.trim() ? variation : main;
		return node?.textContent?.replace(/\s+/g, ' ').trim() || '';
	};

	const originalButton = form?.querySelector('.single_add_to_cart_button');
	const selectionsValid = () => {
		const selects = [...(form?.querySelectorAll('select[name^="attribute_"]') || [])];
		return selects.every((select) => Boolean(select.value));
	};

	const buttonEnabled = () => Boolean(
		originalButton &&
		!originalButton.disabled &&
		!originalButton.classList.contains('disabled') &&
		originalButton.getAttribute('aria-disabled') !== 'true' &&
		selectionsValid()
	);

	const syncTotal = () => {
		const total = doc.querySelector('[data-delicat-design-total]');
		const value = priceText();
		if (total && value) total.textContent = value;
	};

	const buildVariationCards = () => {
		if (!mount || !form?.matches('form.variations_form')) return;

		const target = mount.querySelector('[data-delicat-design-groups]');
		const table = form.querySelector('table.variations');
		if (!target || !table) return;

		target.innerHTML = '';
		const selects = [...table.querySelectorAll('select[name^="attribute_"]')];
		let variationData = [];
		try {
			variationData = JSON.parse(form.dataset.product_variations || '[]');
		} catch (_) {
			variationData = [];
		}
		const singleAttribute = selects.length === 1;

		for (const select of selects) {
			const row = select.closest('tr');
			const label = row?.querySelector('th.label label')?.textContent?.trim() || select.dataset.attribute_name || 'Option';
			const options = [...select.options].filter((option) => option.value);

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
					const match = variationData.find((variation) => {
						const attrs = variation?.attributes || {};
						return attrs[select.name] === option.value || attrs[select.name] === '';
					});
					if (match?.price_html) {
						const parsed = new DOMParser().parseFromString(String(match.price_html), 'text/html');
						const price = parsed.body.textContent?.replace(/\s+/g, ' ').trim();
						if (price) {
							const priceNode = doc.createElement('span');
							priceNode.className = 'delicat-design-option-card__price';
							priceNode.textContent = price;
							button.appendChild(priceNode);
						}
					}
				}

				button.addEventListener('click', () => {
					if (option.disabled) return;
					select.value = option.value;
					select.dispatchEvent(new Event('change', { bubbles: true }));
					syncGroup();
				});

				grid.appendChild(button);
			}

			group.appendChild(grid);
			target.appendChild(group);

			const syncGroup = () => {
				for (const button of grid.querySelectorAll('.delicat-design-option-card')) {
					const opt = [...select.options].find((item) => item.value === button.dataset.value);
					const active = select.value === button.dataset.value;
					button.classList.toggle('is-selected', active);
					button.classList.toggle('is-disabled', Boolean(opt?.disabled));
					button.disabled = Boolean(opt?.disabled);
					button.setAttribute('aria-pressed', active ? 'true' : 'false');
				}
				syncBar();
			};

			select.addEventListener('change', syncGroup);
			syncGroup();
		}

		if (target.children.length) {
			const reset = doc.createElement('button');
			reset.type = 'button';
			reset.className = 'delicat-design-options__reset';
			reset.textContent = 'Réinitialiser';
			reset.addEventListener('click', () => {
				for (const select of selects) {
					select.value = '';
					select.dispatchEvent(new Event('change', { bubbles: true }));
				}
				form.querySelector('.reset_variations')?.click();
			});
			target.appendChild(reset);

			table.classList.add('delicat-design-native-variations');
			mount.hidden = false;
		} else {
			mount.remove();
		}
	};

	const ensureBuyNowFields = () => {
		if (!form) return;
		let flag = form.querySelector('input[name="delicat_buy_now"]');
		if (!flag) {
			flag = doc.createElement('input');
			flag.type = 'hidden';
			flag.name = 'delicat_buy_now';
			flag.value = '0';
			form.appendChild(flag);
		}
		let nonce = form.querySelector('input[name="delicat_buy_now_nonce"]');
		if (!nonce && buybar?.dataset.buyNonce) {
			nonce = doc.createElement('input');
			nonce.type = 'hidden';
			nonce.name = 'delicat_buy_now_nonce';
			nonce.value = buybar.dataset.buyNonce;
			form.appendChild(nonce);
		}
		return flag;
	};

	const syncBar = () => {
		if (!buybar || !form || !originalButton) return;
		const cart = buybar.querySelector('[data-delicat-design-cart]');
		const buy = buybar.querySelector('[data-delicat-design-buy]');
		const enabled = buttonEnabled();

		for (const button of [cart, buy]) {
			if (!button) continue;
			button.disabled = !enabled;
			button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
		}

		syncTotal();
		buybar.hidden = false;
	};

	if (buybar && form && originalButton) {
		const cart = buybar.querySelector('[data-delicat-design-cart]');
		const buy = buybar.querySelector('[data-delicat-design-buy]');

		cart?.addEventListener('click', () => {
			if (!buttonEnabled()) {
				form.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
				return;
			}
			const flag = ensureBuyNowFields();
			if (flag) flag.value = '0';
			originalButton.click();
		});

		buy?.addEventListener('click', () => {
			if (!buttonEnabled()) {
				form.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
				return;
			}
			const flag = ensureBuyNowFields();
			if (flag) flag.value = '1';
			originalButton.click();
		});

		// RC39.11: variation plugins can emit many DOM mutations for one option
		// change. Batch them into one paint-frame sync instead of repeatedly
		// reading price/button state during the same task.
		let syncFrame = 0;
		const queueSyncBar = () => {
			if (syncFrame) return;
			syncFrame = window.requestAnimationFrame(() => {
				syncFrame = 0;
				syncBar();
			});
		};

		const observer = new MutationObserver(queueSyncBar);
		observer.observe(form, { subtree: true, childList: true, attributes: true, attributeFilter: ['disabled', 'class', 'value'] });

		form.addEventListener('change', queueSyncBar, { passive: true });
		form.addEventListener('input', queueSyncBar, { passive: true });

		if (window.jQuery) {
			window.jQuery(form).on('found_variation reset_data woocommerce_variation_has_changed', queueSyncBar);
		}

		syncBar();
	}

	buildVariationCards();
	syncTotal();
})();