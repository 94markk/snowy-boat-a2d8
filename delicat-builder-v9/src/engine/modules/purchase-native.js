/**
 * Purchase Studio — native cart (body.dpn-cart-classic) and checkout
 * (body.dpn-checkout) presentation. Quantity updates and clearing submit
 * WooCommerce's real cart form and nonce; the checkout dock delegates to the
 * real place-order button. Three separate subtree observers on the checkout
 * root are now one, scheduled on a single timer.
 */
import { doc, normalizeSpaces, on, onWoo, qsa, win } from '../core/dom.js';

const foldedText = (value) => normalizeSpaces(value).toLowerCase().replace(/[àâäáãå]/g, 'a').replace(/ç/g, 'c').replace(/[èéêë]/g, 'e').replace(/[ìíîï]/g, 'i').replace(/[òóôöõ]/g, 'o').replace(/[ùúûü]/g, 'u');
const emitInput = (input, type) => input.dispatchEvent(new Event(type, { bubbles: true }));

export default function mount({ signal, config }) {
	const body = doc.body;
	const cfg = win.DelicatPurchaseNative || config.purchaseNative || {};
	const isCheckout = body.classList.contains('dpn-checkout');
	const isCart = body.classList.contains('dpn-cart-classic');
	if (!isCheckout && !isCart) return;

	/* ------------------------------------------------------------ checkout */
	if (isCheckout) {
		const root = doc.querySelector('.delicat-native-page-main,.wc-block-checkout,form.checkout') || body;
		const dock = doc.querySelector('[data-dpn-checkout-dock]');
		const dockButton = dock && dock.querySelector('[data-dpn-checkout-submit]');
		const dockTotal = dock && dock.querySelector('[data-dpn-checkout-total]');
		const couponButton = doc.querySelector('[data-dpn-coupon-open]');
		const placeOrderButton = () => doc.querySelector('#place_order,.wc-block-components-checkout-place-order-button');
		const couponControl = () => doc.querySelector('.woocommerce-form-coupon-toggle .showcoupon,.wc-block-components-totals-coupon-link');
		const totalElement = () => { const totals = qsa('#order_review tr.order-total .woocommerce-Price-amount,.wc-block-components-totals-footer-item .wc-block-formatted-money-amount,.wc-block-components-totals-footer-item__value'); return totals.length ? totals[totals.length - 1] : null; };
		const checkoutTotalText = () => { const t = totalElement(); return t ? normalizeSpaces(t.textContent) : ''; };

		const normalizeAuthentication = () => {
			const owner = doc.querySelector('.dip-wc-login-panel');
			if (!owner) return;
			body.classList.add('dpn-checkout-login-normalized');
			for (const candidate of qsa('.dip-divider,.dip-login-wrap,.woocommerce-form-login-toggle,form.woocommerce-form-login')) {
				if (candidate !== owner && !owner.contains(candidate) && !candidate.contains(owner)) candidate.classList.add('dpn-auth-duplicate');
			}
			for (const candidate of qsa('a,button,[role="button"]')) {
				if (owner.contains(candidate)) continue;
				const text = foldedText(candidate.textContent || candidate.getAttribute('aria-label'));
				if (text.indexOf('continuer avec google') === -1 && text.indexOf('continue with google') === -1) continue;
				const parent = candidate.closest('.dip-login-wrap,.dip-google-wrap,.dip-social-login,.woocommerce-info');
				(parent && !parent.contains(owner) ? parent : candidate).classList.add('dpn-auth-duplicate');
			}
			const sentences = ['veuillez vous connecter pour finaliser votre commande', 'please log in to complete your purchase', 'please log in to proceed to checkout'];
			for (const candidate of qsa('p,.woocommerce-info,.woocommerce-notice,.woocommerce-message')) {
				if (owner.contains(candidate) || candidate.querySelector('input,select,textarea,button')) continue;
				if (sentences.includes(foldedText(candidate.textContent).replace(/[.!]+$/, ''))) candidate.classList.add('dpn-auth-duplicate');
			}
			const walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
			const nodes = [];
			let node;
			while ((node = walker.nextNode())) nodes.push(node);
			for (const text of nodes) {
				if (text.parentElement && owner.contains(text.parentElement)) continue;
				const value = foldedText(text.nodeValue).replace(/[.!]+$/, '');
				if (String(text.nodeValue || '').indexOf('dc-native-login-required') !== -1 || sentences.includes(value)) text.nodeValue = '';
			}
		};

		const walletLabel = (owner, input) => { const labels = owner.querySelectorAll('label'); for (const label of labels) if (!input.id || label.getAttribute('for') === input.id) return label; return labels.length ? labels[0] : null; };
		const walletBalanceText = (value) => { const match = normalizeSpaces(value).match(/(?:current\s*balance|solde\s*disponible)\s*:?\s*([A-Z]{0,4}\s*-?\s*\d[\d\s.,]*(?:\s*[A-Z]{2,4})?)/i); return match ? normalizeSpaces(match[1]) : ''; };
		const moneyValue = (value) => {
			const source = normalizeSpaces(value).replace(/[^0-9,.-]/g, '');
			if (!source || !/\d/.test(source)) return null;
			const separator = Math.max(source.lastIndexOf(','), source.lastIndexOf('.'));
			const decimals = separator >= 0 ? source.length - separator - 1 : 0;
			const normalized = separator >= 0 && decimals > 0 && decimals <= 2 ? source.substring(0, separator).replace(/[,.]/g, '') + '.' + source.substring(separator + 1).replace(/[,.]/g, '') : source.replace(/[,.]/g, '');
			const parsed = parseFloat(normalized);
			return isNaN(parsed) ? null : parsed;
		};
		const afterBalanceText = (balanceText) => {
			const balance = moneyValue(balanceText), total = moneyValue(checkoutTotalText());
			if (balance === null || total === null) return '';
			const firstDigit = String(balanceText || '').search(/[0-9-]/);
			const prefix = firstDigit > -1 ? normalizeSpaces(String(balanceText).substring(0, firstDigit)) : '';
			const suffix = normalizeSpaces(balanceText).match(/\s([A-Z]{2,4})$/);
			const amount = Math.round(balance - total);
			return (prefix || '') + (amount < 0 ? '-' : '') + String(Math.abs(amount)).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (suffix ? ' ' + suffix[1] : '');
		};
		const enhanceWalletGateway = (scope) => {
			for (const input of qsa('input[type="radio"],input[name="payment_method"]', scope)) {
				const source = String(input.value || '') + ' ' + String(input.id || '') + ' ' + String(input.className || '');
				if (source.toLowerCase().indexOf('wallet') === -1) continue;
				const owner = input.closest('li,.wc_payment_method,.wc-block-components-radio-control-accordion-option,.wc-block-components-radio-control__option') || input.parentNode;
				if (!owner) continue;
				/* Rename TeraWallet's text nodes (never its inputs). */
				const walker = doc.createTreeWalker(owner, NodeFilter.SHOW_TEXT, null);
				const nodes = [];
				let node;
				while ((node = walker.nextNode())) nodes.push(node);
				for (const text of nodes) text.nodeValue = String(text.nodeValue || '').replace(/Wallet payment|Tera\s*Wallet/ig, String(cfg.walletTitle || 'Delicat Wallet')).replace(/Current Balance/ig, String(cfg.walletBalance || 'Solde disponible'));
				const label = walletLabel(owner, input);
				if (!label || label.contains(input)) continue;
				owner.classList.add('dpn-wallet-enhanced');
				let ui = label.querySelector('.dpn-wallet-ui');
				let balanceText;
				if (ui) { const amount = ui.querySelector('.dpn-wallet-balance'); balanceText = amount ? normalizeSpaces(amount.textContent) : normalizeSpaces(label.getAttribute('data-dpn-wallet-balance')); }
				else {
					balanceText = walletBalanceText(label.textContent) || walletBalanceText(owner.textContent);
					label.textContent = '';
					label.setAttribute('data-dpn-wallet-balance', balanceText);
					ui = doc.createElement('span'); ui.className = 'dpn-wallet-ui';
					const icon = doc.createElement('span'); icon.className = 'dpn-wallet-icon'; icon.setAttribute('aria-hidden', 'true');
					icon.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="6" width="18" height="13" rx="3"></rect><path d="M16 10h5v5h-5a2.5 2.5 0 0 1 0-5z"></path><path d="M7 6V4h10v2"></path></svg>';
					const copy = doc.createElement('span'); copy.className = 'dpn-wallet-copy';
					const title = doc.createElement('strong'); title.textContent = cfg.walletTitle || 'Delicat Wallet';
					copy.appendChild(title);
					if (balanceText) { const small = doc.createElement('small'); small.appendChild(doc.createTextNode((cfg.walletBalance || 'Solde disponible') + ' : ')); const amount = doc.createElement('b'); amount.className = 'dpn-wallet-balance'; amount.textContent = balanceText; small.appendChild(amount); copy.appendChild(small); }
					ui.append(icon, copy);
					label.appendChild(ui);
				}
				let after = owner.querySelector('.dpn-wallet-after');
				if (!after) { after = doc.createElement('span'); after.className = 'dpn-wallet-after'; after.innerHTML = '<span></span><strong></strong>'; after.querySelector('span').textContent = cfg.walletAfter || 'Solde après cet achat'; owner.insertBefore(after, label.nextSibling); }
				const next = afterBalanceText(balanceText);
				const value = after.querySelector('strong');
				if (!next) after.hidden = true; else { after.hidden = false; if (value && normalizeSpaces(value.textContent) !== next) value.textContent = next; }
			}
		};
		const normalizeMinimalFields = (scope) => {
			if (!body.classList.contains('dpn-checkout-minimal')) return;
			const specs = [['billing_email', cfg.emailLabel || 'Adresse e-mail', cfg.emailHelp || '', true], ['billing_first_name', cfg.nameLabel || 'Nom complet', '', true], ['billing_phone', cfg.phoneLabel || 'Numéro WhatsApp', cfg.phoneHelp || '', true]];
			for (const [id, text, help, required] of specs) {
				const field = scope.querySelector('#' + id + '_field') || doc.querySelector('#' + id + '_field');
				if (!field) continue;
				const label = field.querySelector('label');
				if (label && label.getAttribute('data-dpn-label') !== text) {
					label.textContent = text + ' ';
					if (required) { const mark = doc.createElement('abbr'); mark.className = 'required'; mark.setAttribute('title', 'obligatoire'); mark.textContent = '*'; label.appendChild(mark); }
					else { const optional = doc.createElement('span'); optional.className = 'optional'; optional.textContent = '(optionnel)'; label.appendChild(optional); }
					label.setAttribute('data-dpn-label', text);
				}
				if (help) { let description = field.querySelector('.description'); if (!description) { description = doc.createElement('span'); description.className = 'description'; field.appendChild(description); } if (normalizeSpaces(description.textContent) !== help) description.textContent = help; }
				if (id === 'billing_phone') { const input = field.querySelector('input'); if (input && !input.getAttribute('placeholder')) input.setAttribute('placeholder', '+509 XXXX XXXX'); }
			}
			const field = scope.querySelector('#billing_first_name_field') || doc.querySelector('#billing_first_name_field');
			if (field && (!field.previousElementSibling || field.previousElementSibling.className.indexOf('dpn-billing-subheading') === -1)) {
				const heading = doc.createElement('h3'); heading.className = 'dpn-billing-subheading'; heading.textContent = cfg.billingTitle || 'Détails de facturation';
				field.parentNode.insertBefore(heading, field);
			}
		};
		const cleanWalletTokens = (scope) => {
			const matcher = /\[\/?terawallet_balance(?:\s[^\]]*)?\]/ig;
			const walker = doc.createTreeWalker(scope, NodeFilter.SHOW_TEXT, null);
			const nodes = [];
			let node;
			while ((node = walker.nextNode())) { matcher.lastIndex = 0; if (matcher.test(node.nodeValue || '')) nodes.push(node); }
			const boxes = new Set();
			for (const text of nodes) { matcher.lastIndex = 0; text.nodeValue = String(text.nodeValue || '').replace(matcher, ''); const box = text.parentElement && text.parentElement.closest('.payment_box,.wc-block-components-radio-control-accordion-content'); if (box) boxes.add(box); }
			for (const box of boxes) if (!String(box.textContent || '').replace(/\s+/g, '') && !box.querySelector('input,select,textarea,button,a,img,iframe')) box.classList.add('dpn-wallet-token-empty');
		};
		const ensurePaymentHeading = () => {
			if (!body.classList.contains('dpn-checkout-classic')) return;
			const payment = doc.querySelector('#payment');
			if (!payment || (payment.previousElementSibling && payment.previousElementSibling.className.indexOf('dpn-payment-heading') !== -1)) return;
			const heading = doc.createElement('h2');
			heading.className = 'dpn-checkout-section-heading dpn-payment-heading';
			heading.innerHTML = '<span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="M3 10h18"></path></svg></span><b></b>';
			heading.querySelector('b').textContent = cfg.paymentTitle || 'Mode de paiement';
			payment.parentNode.insertBefore(heading, payment);
		};
		const ensureFreeServiceRow = () => {
			if (!body.classList.contains('dpn-checkout-classic')) return;
			const table = doc.querySelector('#order_review table.shop_table');
			if (!table) return;
			const row = table.querySelector('tr.dpn-service-free');
			if (table.querySelector('tr.fee')) { if (row) row.remove(); return; }
			if (row) return;
			const totalRow = table.querySelector('tr.order-total');
			if (!totalRow) return;
			const fresh = doc.createElement('tr');
			fresh.className = 'dpn-service-free';
			fresh.innerHTML = '<th></th><td data-title=""></td>';
			fresh.querySelector('th').textContent = cfg.serviceFee || 'Frais de service';
			fresh.querySelector('td').textContent = cfg.free || 'Gratuit';
			totalRow.parentNode.insertBefore(fresh, totalRow);
		};
		const syncPresentation = () => {
			const actual = placeOrderButton();
			const total = totalElement();
			const coupon = couponControl();
			ensurePaymentHeading();
			ensureFreeServiceRow();
			if (couponButton) { if (couponButton.disabled !== !coupon) couponButton.disabled = !coupon; body.classList.toggle('dpn-coupon-proxy-ready', !!coupon); }
			if (dockTotal && total && dockTotal.innerHTML !== total.innerHTML) dockTotal.replaceChildren(...Array.from(total.cloneNode(true).childNodes));
			if (!dock || !dockButton || !actual) { if (dockButton && !dockButton.disabled) dockButton.disabled = true; return; }
			actual.classList.add('dpn-native-place-order');
			const describedBy = String(actual.getAttribute('aria-describedby') || '');
			if (describedBy.indexOf('dpn-checkout-trust') === -1) actual.setAttribute('aria-describedby', (describedBy + ' dpn-checkout-trust').trim());
			const disabled = !!actual.disabled || actual.getAttribute('aria-disabled') === 'true';
			if (dockButton.disabled !== disabled) dockButton.disabled = disabled;
			dock.hidden = false;
			body.classList.add('dpn-checkout-dock-ready');
		};

		let timer = 0;
		const runAll = () => { timer = 0; normalizeAuthentication(); enhanceWalletGateway(root); normalizeMinimalFields(root); cleanWalletTokens(root); syncPresentation(); };
		const schedule = () => { if (timer) win.clearTimeout(timer); timer = win.setTimeout(runAll, 45); };
		runAll();
		const observer = new MutationObserver(schedule);
		observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'aria-disabled', 'class'] });
		signal.addEventListener('abort', () => { observer.disconnect(); win.clearTimeout(timer); }, { once: true });
		onWoo('updated_checkout payment_method_selected checkout_error', schedule, signal);
		on(win, 'pageshow', schedule, { signal });
		if (couponButton) on(couponButton, 'click', () => { const coupon = couponControl(); if (!coupon || couponButton.disabled) return; coupon.click(); win.setTimeout(() => { const input = doc.querySelector('#coupon_code,.wc-block-components-totals-coupon input'); if (input) input.focus(); }, 80); }, { signal });
		if (dockButton) on(dockButton, 'click', () => { const actual = placeOrderButton(); if (!actual || actual.disabled || actual.getAttribute('aria-disabled') === 'true') return; actual.click(); }, { signal });
	}

	/* ---------------------------------------------------------------- cart */
	if (!isCart) return;
	const form = doc.querySelector('.dpn-form');
	if (!form) return;
	const updateButton = form.querySelector('[data-dpn-update]');
	let updateTimer = 0, submitting = false, clearing = false;
	const ensureUpdateField = () => {
		if (updateButton) { updateButton.disabled = false; return updateButton; }
		let field = form.querySelector('input[name="update_cart"]');
		if (!field) { field = doc.createElement('input'); field.type = 'hidden'; field.name = 'update_cart'; field.value = '1'; form.appendChild(field); }
		return field;
	};
	const submitUpdate = () => {
		if (submitting) return;
		if (updateTimer) win.clearTimeout(updateTimer);
		updateTimer = 0;
		const submitter = ensureUpdateField();
		if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter && submitter.tagName === 'BUTTON' ? submitter : undefined);
		else if (submitter && submitter.tagName === 'BUTTON') submitter.click();
		else { submitting = true; body.classList.add('dpn-updating'); form.submit(); }
	};
	const scheduleUpdate = () => { if (clearing || submitting) return; if (updateTimer) win.clearTimeout(updateTimer); updateTimer = win.setTimeout(submitUpdate, Number(cfg.updateDelay) || 450); };
	const numberValue = (value, fallback) => { const parsed = parseFloat(value); return isNaN(parsed) ? fallback : parsed; };
	const decimalPlaces = (value) => { const text = String(value); return text.indexOf('.') === -1 ? 0 : text.length - text.indexOf('.') - 1; };
	const formatValue = (value, step) => { const precision = Math.min(6, decimalPlaces(step)); return precision ? value.toFixed(precision).replace(/0+$/, '').replace(/\.$/, '') : String(Math.round(value)); };
	const syncAtMinimum = (wrap, input) => { const value = numberValue(input.value, 1); const min = numberValue(input.getAttribute('min'), 0); wrap.setAttribute('data-at-one', value <= Math.max(1, min) ? '1' : '0'); };
	const setQuantity = (wrap, input, value, step) => {
		const max = numberValue(input.getAttribute('max'), 0), min = numberValue(input.getAttribute('min'), 0);
		if (max > 0) value = Math.min(value, max);
		value = Math.max(min, value);
		input.value = formatValue(value, step);
		syncAtMinimum(wrap, input);
		emitInput(input, 'input'); emitInput(input, 'change');
	};
	for (const wrap of qsa('[data-dpn-qty]')) {
		const input = wrap.querySelector('.qty');
		if (!input) continue;
		syncAtMinimum(wrap, input);
		const plus = wrap.querySelector('[data-dpn-plus]'), minus = wrap.querySelector('[data-dpn-minus]');
		if (plus) on(plus, 'click', () => { const step = numberValue(input.getAttribute('step'), 1); setQuantity(wrap, input, numberValue(input.value, 0) + step, step); scheduleUpdate(); }, { signal });
		if (minus) on(minus, 'click', () => {
			const step = numberValue(input.getAttribute('step'), 1), value = numberValue(input.value, 1);
			if (value <= Math.max(1, step)) { const removeUrl = wrap.getAttribute('data-remove'); if (removeUrl) location.assign(removeUrl); return; }
			setQuantity(wrap, input, value - step, step);
			scheduleUpdate();
		}, { signal });
		on(input, 'change', () => { const min = numberValue(input.getAttribute('min'), 0); const value = numberValue(input.value, Math.max(1, min)); if (value < min) input.value = String(min); syncAtMinimum(wrap, input); scheduleUpdate(); }, { signal });
	}
	/* swipe to reveal delete */
	const items = qsa('[data-dpn-swipe]');
	let open = null;
	const setDeleteAccessibility = (item, active) => { const link = item.querySelector('.dpn-swipe-delete-link'); if (!link) return; link.setAttribute('tabindex', active ? '0' : '-1'); link.setAttribute('aria-hidden', active ? 'false' : 'true'); };
	const closeItem = (item) => { if (!item) return; item.classList.remove('is-open', 'is-dragging'); const card = item.querySelector('.dpn-card'); if (card) card.style.removeProperty('--dpn-x'); setDeleteAccessibility(item, false); if (open === item) open = null; };
	for (const item of items) {
		const card = item.querySelector('.dpn-card');
		if (!card || !win.PointerEvent) continue;
		let startX = 0, startY = 0, dragging = false, beganOpen = false;
		on(item, 'pointerdown', (event) => {
			if (event.pointerType === 'mouse' && event.button !== 0) return;
			if (event.target.closest('a,button,input,select,textarea,label')) return;
			if (open && open !== item) closeItem(open);
			startX = event.clientX; startY = event.clientY; beganOpen = item.classList.contains('is-open'); dragging = true;
			if (item.setPointerCapture) item.setPointerCapture(event.pointerId);
		}, { signal });
		on(item, 'pointermove', (event) => {
			if (!dragging) return;
			const dx = event.clientX - startX, dy = event.clientY - startY;
			if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 8) { dragging = false; closeItem(item); return; }
			item.classList.add('is-dragging');
			card.style.setProperty('--dpn-x', Math.max(-96, Math.min(0, dx + (beganOpen ? -96 : 0))) + 'px');
		}, { signal });
		const finish = (event) => {
			if (!dragging) return;
			dragging = false;
			item.classList.remove('is-dragging');
			const dx = event.clientX - startX + (beganOpen ? -96 : 0);
			if (dx < -46) { item.classList.add('is-open'); card.style.setProperty('--dpn-x', '-96px'); setDeleteAccessibility(item, true); open = item; }
			else closeItem(item);
		};
		on(item, 'pointerup', finish, { signal });
		on(item, 'pointercancel', () => { dragging = false; closeItem(item); }, { signal });
	}
	on(doc, 'pointerdown', (event) => { if (open && !open.contains(event.target)) closeItem(open); }, { capture: true, signal });
	on(win, 'pageshow', () => items.forEach(closeItem), { signal });
	on(win, 'resize', () => items.forEach(closeItem), { signal });
	/* clear */
	const clear = doc.querySelector('[data-dpn-clear]');
	if (clear) {
		clear.hidden = false;
		on(clear, 'click', () => {
			if (submitting || clear.getAttribute('aria-busy') === 'true') return;
			if (cfg.clearConfirm && !win.confirm(cfg.clearConfirm)) return;
			clearing = true;
			clear.setAttribute('aria-busy', 'true');
			clear.disabled = true;
			form.noValidate = true;
			for (const input of qsa('[name^="cart["][name$="[qty]"]', form)) { input.setAttribute('min', '0'); input.value = '0'; }
			submitUpdate();
		}, { signal });
	}
	on(form, 'submit', () => { submitting = true; body.classList.add('dpn-updating'); if (updateButton) updateButton.disabled = false; }, { signal });
	body.classList.add('dpn-js');
}
