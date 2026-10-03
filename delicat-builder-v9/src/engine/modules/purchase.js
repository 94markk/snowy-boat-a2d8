/**
 * Purchase UI (classic WooCommerce surfaces): quantity steppers, cart
 * auto-update, the mobile purchase bar and WooCommerce notice toasts.
 */
import { device, doc, on, qsa, raf, win } from '../core/dom.js';

export default function mount({ root, signal, config }) {
	const cfg = win.DelicaPurchaseV9 || config.purchase || {};
	let cartTimer = 0;
	const numberOr = (value, fallback) => { const n = Number.parseFloat(value); return Number.isFinite(n) ? n : fallback; };
	const decimalsFor = (value) => { const text = String(value ?? ''); const dot = text.indexOf('.'); return dot === -1 ? 0 : Math.min(6, text.length - dot - 1); };
	const normalizeValue = (input, direction) => {
		if (!input || input.disabled || input.readOnly) return;
		const rawStep = input.getAttribute('step');
		const step = rawStep && rawStep !== 'any' ? Math.abs(numberOr(rawStep, 1)) : 1;
		const min = numberOr(input.getAttribute('min'), 0);
		const maxAttr = input.getAttribute('max');
		const max = maxAttr === null || maxAttr === '' ? Number.POSITIVE_INFINITY : numberOr(maxAttr, Number.POSITIVE_INFINITY);
		const next = Math.min(max, Math.max(min, numberOr(input.value, min) + direction * step));
		const decimals = Math.max(decimalsFor(step), decimalsFor(min));
		input.value = decimals > 0 ? Number(next.toFixed(decimals)).toString() : String(Math.round(next));
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
	};
	const scheduleCartUpdate = () => {
		if (!cfg.cart || !cfg.cartAutoUpdate) return;
		win.clearTimeout(cartTimer);
		cartTimer = win.setTimeout(() => { const button = doc.querySelector('.woocommerce-cart-form button[name="update_cart"]'); if (button && !button.disabled) button.click(); }, Math.max(250, Number(cfg.updateDelay) || 500));
	};
	if (cfg.quantityButtons) {
		on(doc, 'click', (event) => {
			const button = event.target.closest && event.target.closest('[data-delicat-qty-minus],[data-delicat-qty-plus]');
			if (!button) return;
			const quantity = button.closest('.quantity');
			const input = quantity && quantity.querySelector('input.qty');
			if (!input) return;
			event.preventDefault();
			normalizeValue(input, button.hasAttribute('data-delicat-qty-plus') ? 1 : -1);
			scheduleCartUpdate();
		}, { signal });
	}
	if (cfg.cart && cfg.cartAutoUpdate) on(doc, 'change', (event) => { if (event.target.matches && event.target.matches('.woocommerce-cart-form input.qty')) scheduleCartUpdate(); }, { signal });

	if (cfg.single && cfg.mobileDock) {
		const dock = doc.querySelector('[data-delicat-mobile-purchase]');
		const dockButton = dock && dock.querySelector('[data-delicat-dock-submit]');
		const dockPrice = dock && dock.querySelector('[data-delicat-dock-price]');
		const form = doc.querySelector('.summary form.cart');
		const originalButton = form && form.querySelector('.single_add_to_cart_button');
		if (!dock || !dockButton || !form || !originalButton) { if (dock) dock.remove(); }
		else {
			const mobile = win.matchMedia('(max-width: 768px)');
			let formVisible = true, frame = 0;
			const sync = () => {
				const disabled = !!(originalButton.disabled || originalButton.classList.contains('disabled') || originalButton.getAttribute('aria-disabled') === 'true');
				dockButton.disabled = disabled;
				dockButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
				const variationPrice = doc.querySelector('.summary .woocommerce-variation-price .price');
				const basePrice = doc.querySelector('.summary > p.price, .summary > span.price');
				const current = variationPrice && variationPrice.textContent.trim() ? variationPrice : basePrice;
				if (dockPrice && current && current.textContent.trim()) dockPrice.textContent = current.textContent.trim().replace(/\s+/g, ' ');
				dock.hidden = !mobile.matches || formVisible;
			};
			const queue = () => { if (frame) return; frame = raf(() => { frame = 0; sync(); }); };
			if ('IntersectionObserver' in win) {
				const observer = new IntersectionObserver((entries) => { for (const entry of entries) { formVisible = entry.isIntersecting; queue(); } }, { root: null, rootMargin: '-8px 0px -8px 0px', threshold: 0.05 });
				observer.observe(form);
				signal.addEventListener('abort', () => observer.disconnect(), { once: true });
			} else formVisible = false;
			const mutation = new MutationObserver(queue);
			mutation.observe(doc.querySelector('.summary') || form, { subtree: true, childList: true, attributes: true, attributeFilter: ['disabled', 'class', 'aria-disabled', 'value'] });
			signal.addEventListener('abort', () => { mutation.disconnect(); dock.hidden = true; }, { once: true });
			if (mobile.addEventListener) { mobile.addEventListener('change', queue); signal.addEventListener('abort', () => mobile.removeEventListener('change', queue), { once: true }); }
			on(dockButton, 'click', () => {
				sync();
				if (!dockButton.disabled && !originalButton.disabled && !originalButton.classList.contains('disabled')) { originalButton.click(); return; }
				form.scrollIntoView({ behavior: device.reducedMotion ? 'auto' : 'smooth', block: 'center' });
				const focusTarget = form.querySelector('select, input:not([type="hidden"]), button');
				win.setTimeout(() => { if (focusTarget) focusTarget.focus({ preventScroll: true }); }, 280);
			}, { signal });
			on(form, 'submit', () => { dock.classList.add('is-submitting'); dockButton.disabled = true; dockButton.setAttribute('aria-busy', 'true'); }, { signal });
			sync();
		}
	}

	if (cfg.noticeToasts) {
		const host = doc.querySelector('[data-delicat-purchase-toasts]');
		const live = doc.querySelector('[data-delicat-purchase-live]');
		if (host) {
			const selector = '.woocommerce-message,.woocommerce-error,.woocommerce-info,.wc-block-components-notice-banner';
			const classify = (notice) => (notice.classList.contains('woocommerce-error') || notice.classList.contains('is-error') ? 'error' : (notice.classList.contains('woocommerce-info') || notice.classList.contains('is-info') ? 'info' : 'success'));
			const announce = (notice) => {
				if (!(notice instanceof Element) || notice.dataset.delicatToastSeen === '1') return;
				const style = win.getComputedStyle(notice);
				if (style && (style.display === 'none' || style.visibility === 'hidden')) return;
				const raw = (notice.textContent || '').replace(/\s+/g, ' ').trim();
				if (!raw) return;
				notice.dataset.delicatToastSeen = '1';
				const kind = classify(notice);
				const toast = doc.createElement('div');
				toast.className = `delicat-purchase-toast is-${kind}`;
				toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
				toast.textContent = raw.slice(0, 320);
				host.appendChild(toast);
				if (live) live.textContent = toast.textContent;
				win.setTimeout(() => { toast.classList.add('is-leaving'); win.setTimeout(() => toast.remove(), 180); }, kind === 'error' ? 6200 : 4200);
			};
			qsa(selector).forEach(announce);
			const observer = new MutationObserver((records) => {
				for (const record of records) for (const node of record.addedNodes) {
					if (!(node instanceof Element)) continue;
					if (node.matches(selector)) announce(node);
					node.querySelectorAll(selector).forEach(announce);
				}
			});
			observer.observe(doc.body, { childList: true, subtree: true });
			signal.addEventListener('abort', () => observer.disconnect(), { once: true });
		}
	}
}
