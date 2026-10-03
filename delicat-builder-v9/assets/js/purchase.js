(() => {
	'use strict';

	const cfg = window.DelicaPurchaseV9 || {};
	const doc = document;
	let cartTimer = 0;

	const numberOr = (value, fallback) => {
		const n = Number.parseFloat(value);
		return Number.isFinite(n) ? n : fallback;
	};

	const decimalsFor = (value) => {
		const text = String(value ?? '');
		const dot = text.indexOf('.');
		return dot === -1 ? 0 : Math.min(6, text.length - dot - 1);
	};

	const normalizeValue = (input, direction) => {
		if (!input || input.disabled || input.readOnly) return;

		const rawStep = input.getAttribute('step');
		const step = rawStep && rawStep !== 'any' ? Math.abs(numberOr(rawStep, 1)) : 1;
		const min = numberOr(input.getAttribute('min'), 0);
		const maxAttr = input.getAttribute('max');
		const max = maxAttr === null || maxAttr === '' ? Number.POSITIVE_INFINITY : numberOr(maxAttr, Number.POSITIVE_INFINITY);
		const current = numberOr(input.value, min);
		const nextRaw = current + (direction * step);
		const next = Math.min(max, Math.max(min, nextRaw));
		const decimals = Math.max(decimalsFor(step), decimalsFor(min));

		input.value = decimals > 0
			? Number(next.toFixed(decimals)).toString()
			: String(Math.round(next));

		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
	};

	const scheduleCartUpdate = () => {
		if (!cfg.cart || !cfg.cartAutoUpdate) return;

		window.clearTimeout(cartTimer);
		cartTimer = window.setTimeout(() => {
			const button = doc.querySelector('.woocommerce-cart-form button[name="update_cart"]');
			if (button && !button.disabled) {
				button.click();
			}
		}, Math.max(250, Number(cfg.updateDelay) || 500));
	};

	if (cfg.quantityButtons) {
		doc.addEventListener('click', (event) => {
			const button = event.target.closest?.('[data-delicat-qty-minus],[data-delicat-qty-plus]');
			if (!button) return;

			const quantity = button.closest('.quantity');
			const input = quantity?.querySelector('input.qty');
			if (!input) return;

			event.preventDefault();
			normalizeValue(input, button.hasAttribute('data-delicat-qty-plus') ? 1 : -1);
			scheduleCartUpdate();
		});
	}

	if (cfg.cart && cfg.cartAutoUpdate) {
		doc.addEventListener('change', (event) => {
			if (event.target.matches?.('.woocommerce-cart-form input.qty')) {
				scheduleCartUpdate();
			}
		});
	}

	const initMobileDock = () => {
		if (!cfg.single || !cfg.mobileDock) return;

		const dock = doc.querySelector('[data-delicat-mobile-purchase]');
		const dockButton = dock?.querySelector('[data-delicat-dock-submit]');
		const dockPrice = dock?.querySelector('[data-delicat-dock-price]');
		const form = doc.querySelector('.summary form.cart');
		const originalButton = form?.querySelector('.single_add_to_cart_button');

		if (!dock || !dockButton || !form || !originalButton) {
			dock?.remove();
			return;
		}

		const mobile = window.matchMedia('(max-width: 768px)');
		let formVisible = true;
		let syncFrame = 0;

		const sync = () => {
			const disabled = Boolean(
				originalButton.disabled ||
				originalButton.classList.contains('disabled') ||
				originalButton.getAttribute('aria-disabled') === 'true'
			);
			dockButton.disabled = disabled;
			dockButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');

			const variationPrice = doc.querySelector('.summary .woocommerce-variation-price .price');
			const basePrice = doc.querySelector('.summary > p.price, .summary > span.price');
			const currentPrice = variationPrice && variationPrice.textContent.trim()
				? variationPrice
				: basePrice;

			if (dockPrice && currentPrice?.textContent.trim()) {
				dockPrice.textContent = currentPrice.textContent.trim().replace(/\s+/g, ' ');
			}

			dock.hidden = !mobile.matches || formVisible;
		};

		const queueSync = () => {
			if (syncFrame) return;
			syncFrame = window.requestAnimationFrame(() => {
				syncFrame = 0;
				sync();
			});
		};

		if ('IntersectionObserver' in window) {
			const observer = new IntersectionObserver((entries) => {
				for (const entry of entries) {
					formVisible = entry.isIntersecting;
					queueSync();
				}
			}, {
				root: null,
				rootMargin: '-8px 0px -8px 0px',
				threshold: 0.05
			});
			observer.observe(form);
		} else {
			formVisible = false;
		}

		const mutationTarget = doc.querySelector('.summary') || form;
		const mutation = new MutationObserver(queueSync);
		mutation.observe(mutationTarget, {
			subtree: true,
			childList: true,
			attributes: true,
			attributeFilter: ['disabled', 'class', 'aria-disabled', 'value']
		});

		mobile.addEventListener?.('change', queueSync);

		dockButton.addEventListener('click', () => {
			sync();

			if (!dockButton.disabled && !originalButton.disabled && !originalButton.classList.contains('disabled')) {
				originalButton.click();
				return;
			}

			const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
			form.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' });
			const focusTarget = form.querySelector('select, input:not([type="hidden"]), button');
			window.setTimeout(() => focusTarget?.focus({ preventScroll: true }), 280);
		});

		form.addEventListener('submit', () => {
			dock.classList.add('is-submitting');
			dockButton.disabled = true;
			dockButton.setAttribute('aria-busy', 'true');
		});

		sync();
	};

	const initNotices = () => {
		if (!cfg.noticeToasts) return;

		const host = doc.querySelector('[data-delicat-purchase-toasts]');
		const live = doc.querySelector('[data-delicat-purchase-live]');
		if (!host) return;

		const selector = '.woocommerce-message,.woocommerce-error,.woocommerce-info,.wc-block-components-notice-banner';

		const classify = (notice) => {
			if (notice.classList.contains('woocommerce-error') || notice.classList.contains('is-error')) return 'error';
			if (notice.classList.contains('woocommerce-info') || notice.classList.contains('is-info')) return 'info';
			return 'success';
		};

		const announce = (notice) => {
			if (!(notice instanceof Element) || notice.dataset.delicatToastSeen === '1') return;
			const style = window.getComputedStyle?.(notice);
			if (style && (style.display === 'none' || style.visibility === 'hidden')) return;
			const raw = notice.textContent?.replace(/\s+/g, ' ').trim() || '';
			if (!raw) return;

			notice.dataset.delicatToastSeen = '1';
			const text = raw.slice(0, 320);

			const toast = doc.createElement('div');
			toast.className = `delicat-purchase-toast is-${classify(notice)}`;
			toast.setAttribute('role', classify(notice) === 'error' ? 'alert' : 'status');
			toast.textContent = text;
			host.appendChild(toast);

			if (live) live.textContent = text;

			window.setTimeout(() => {
				toast.classList.add('is-leaving');
				window.setTimeout(() => toast.remove(), 180);
			}, classify(notice) === 'error' ? 6200 : 4200);
		};

		doc.querySelectorAll(selector).forEach(announce);

		const observer = new MutationObserver((records) => {
			for (const record of records) {
				for (const node of record.addedNodes) {
					if (!(node instanceof Element)) continue;
					if (node.matches(selector)) announce(node);
					node.querySelectorAll?.(selector).forEach(announce);
				}
			}
		});

		observer.observe(doc.body, { childList: true, subtree: true });
	};

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', () => {
			initMobileDock();
			initNotices();
		}, { once: true });
	} else {
		initMobileDock();
		initNotices();
	}
})();