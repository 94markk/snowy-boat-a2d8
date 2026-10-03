/**
 * Express review sheet (product page "Acheter maintenant" for signed-in
 * customers). The product form is posted through WooCommerce's own classic
 * add-to-cart handler; the sheet then loads WooCommerce's real checkout form
 * and pays through the guarded WC_Checkout transport. Content-scoped: every
 * product swap gets a fresh sheet, config and state.
 */
import { closest, doc, el, emit, inertDocument, normalizeSpaces, on, qsa, raf, tryFocus, win } from '../core/dom.js';
import { registerOverlay, releaseOverlay } from '../core/overlay.js';

export default function mount({ root, signal, engine }) {
	const modal = doc.querySelector('[data-dnp-express]');
	if (!modal || !win.fetch || !win.FormData) return;
	const body = doc.body;
	let cfg = {}, i18n = {};
	const resolveConfig = () => {
		let found = win.DelicatExpress || null;
		if (!found && modal.getAttribute('data-config')) { try { found = JSON.parse(modal.getAttribute('data-config')); } catch (_) { found = null; } }
		cfg = found || {};
		i18n = cfg.i18n || {};
	};
	resolveConfig();
	const sheet = modal.querySelector('[data-dnp-express-sheet]');
	const scroller = modal.querySelector('[data-dnp-express-scroll]') || sheet;
	const notice = modal.querySelector('[data-dnp-express-notice]');
	const summary = modal.querySelector('[data-dnp-express-summary]');
	const details = modal.querySelector('[data-dnp-express-details]');
	const pay = modal.querySelector('[data-dnp-express-pay]');
	const payLabel = modal.querySelector('[data-dnp-express-pay-label]');
	const terms = modal.querySelector('[data-dnp-express-terms]');
	const woo = modal.querySelector('[data-dnp-express-woo]');
	let uncertain = false, checkingStatus = false, busy = false, closeTimer = 0, lastFocus = null, lastSignature = '', armed = false, paying = false, loading = false, debugPanel = null;
	const state = { total: '', insufficient: false, walletUrl: '', gateway: '', hasGateway: false, fullRequired: false };
	body.classList.add('dnp-express-ready');

	const log = (step, extra) => {
		if (!cfg.debug) return;
		try {
			if (!debugPanel) { debugPanel = el('pre', 'dnp-express-debug'); debugPanel.setAttribute('aria-hidden', 'true'); body.appendChild(debugPanel); }
			debugPanel.textContent += new Date().toISOString().slice(11, 19) + ' ' + step + (extra !== undefined ? ' ' + (typeof extra === 'string' ? extra : JSON.stringify(extra)).slice(0, 300) : '') + '\n';
		} catch (_) {}
	};
	const text = (node) => normalizeSpaces(node ? node.textContent : '');
	const form = () => (woo ? woo.querySelector('form.checkout') : null);
	const safeUrl = (value) => { try { const url = new URL(value || '', location.href); return value && url.protocol === 'https:' && url.origin === location.origin ? url.href : ''; } catch (_) { return ''; } };
	const supported = () => { if (!cfg.formUrl || !cfg.checkoutUrl) resolveConfig(); return !!(cfg.formUrl && cfg.checkoutUrl); };
	const parseJson = (raw) => {
		try { return JSON.parse(raw); } catch (_) {}
		let start = raw.indexOf('{"result'); if (start === -1) start = raw.indexOf('{"success');
		const end = raw.lastIndexOf('}');
		if (start !== -1 && end > start) { try { return JSON.parse(raw.slice(start, end + 1)); } catch (_) {} }
		return null;
	};
	const request = async (url, options = {}) => {
		const target = safeUrl(url);
		if (!target) throw new Error('unsafe-url');
		const controller = new AbortController();
		const timer = win.setTimeout(() => controller.abort(), 20000);
		try {
			const res = await fetch(target, { ...options, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal });
			const raw = await res.text();
			return { ok: res.ok, status: res.status, type: res.headers.get('content-type') || '', raw };
		} finally { win.clearTimeout(timer); }
	};

	const setNotice = (message, kind) => {
		if (!notice) return;
		notice.textContent = '';
		if (!message || (message.length !== undefined && !message.length)) { notice.hidden = true; return; }
		if (typeof message === 'string') notice.appendChild(el('p', '', message)); else for (const m of message) notice.appendChild(el('p', '', m));
		if (cfg.fullCheckout) {
			const link = el('a', 'dnp-express-fallback', paying || uncertain ? 'Vérifier mes commandes' : (i18n.fullCheckout || 'Ouvrir le paiement complet'));
			link.href = safeUrl(paying || uncertain ? cfg.ordersUrl : cfg.fullCheckout);
			notice.appendChild(link);
		}
		notice.className = 'dnp-express-notice is-' + (kind || 'error');
		notice.hidden = false;
		if (uncertain && cfg.statusUrl && !checkingStatus) {
			const statusButton = el('button', 'dnp-express-fallback', 'Vérifier ce paiement'); statusButton.type = 'button';
			statusButton.addEventListener('click', () => { statusButton.disabled = true; checkStatus(); });
			notice.appendChild(statusButton);
		}
	};
	const setBusy = (onState, label) => {
		busy = onState;
		body.classList.toggle('dnp-express-busy', onState);
		for (const b of qsa('.dnp-buy-now,[data-dnp-proxy="buy"]')) {
			if (onState) { b.setAttribute('aria-busy', 'true'); if (!b.getAttribute('data-dnp-express-label')) b.setAttribute('data-dnp-express-label', b.textContent); if (label) b.textContent = label; }
			else { b.removeAttribute('aria-busy'); const original = b.getAttribute('data-dnp-express-label'); if (original) b.textContent = original; }
		}
	};
	const setPay = (mode) => {
		if (!pay) return;
		pay.classList.remove('is-recharge', 'is-paying');
		pay.disabled = false;
		if (mode === 'loading') { pay.disabled = true; payLabel.textContent = i18n.loading || ''; }
		else if (mode === 'paying') { pay.disabled = true; pay.classList.add('is-paying'); payLabel.textContent = i18n.paying || ''; }
		else if (mode === 'recharge') { pay.classList.add('is-recharge'); payLabel.textContent = i18n.recharge || ''; }
		else if (mode === 'blocked') { pay.disabled = true; payLabel.textContent = i18n.pay || ''; }
		else payLabel.textContent = (i18n.pay || '') + (state.total ? ' ' + state.total : '');
	};
	const open = () => {
		lastFocus = doc.activeElement;
		if (closeTimer) { win.clearTimeout(closeTimer); closeTimer = 0; }
		modal.hidden = false;
		body.classList.add('dnp-express-open');
		registerOverlay('express');
		void modal.offsetHeight;
		modal.classList.add('is-open');
		if (scroller) scroller.scrollTop = 0;
		tryFocus(sheet);
		log('sheet open');
		if (!uncertain) loadForm();
	};
	const close = () => {
		if (modal.hidden || paying) return;
		modal.classList.remove('is-open');
		body.classList.remove('dnp-express-open');
		releaseOverlay('express');
		closeTimer = win.setTimeout(() => { modal.hidden = true; closeTimer = 0; }, 260);
		tryFocus(lastFocus);
	};
	const inlineError = (messages, anchor) => {
		const host = doc.querySelector('.delicat-native-product .dnp-purchase') || (anchor && anchor.parentNode) || null;
		const old = doc.querySelector('[data-dnp-express-inline]');
		if (old) old.remove();
		if (!host) { win.alert(messages.join('\n')); return; }
		const box = el('div', 'dnp-express-inline');
		box.setAttribute('data-dnp-express-inline', '1');
		box.setAttribute('role', 'alert');
		for (const m of messages) box.appendChild(el('p', '', m));
		host.insertBefore(box, host.firstChild);
		try { box.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (_) {}
	};
	const checkStatus = () => {
		const f = form();
		if (!f || !cfg.statusUrl || checkingStatus) return;
		checkingStatus = true;
		const fd = new FormData();
		for (const name of ['delicat_intent', 'delicat_cart_hash', 'delicat_issued', 'delicat_intent_nonce']) { const input = f.querySelector('[name="' + name + '"]'); if (input) fd.append(name, input.value); }
		setNotice('Vérification du résultat…');
		request(cfg.statusUrl, { method: 'POST', body: fd }).then((res) => {
			checkingStatus = false;
			const data = parseJson(res.raw);
			if (res.ok && data && data.state === 'confirmed' && safeUrl(data.redirect)) { location.href = safeUrl(data.redirect); return; }
			if (res.ok && data && data.state === 'rejected') {
				uncertain = false;
				setNotice('La validation a été refusée avant paiement. Corrigez vos informations et préparez une nouvelle vérification.');
				const retry = el('button', 'dnp-express-fallback', 'Revoir la commande'); retry.type = 'button';
				retry.addEventListener('click', () => loadForm()); notice.appendChild(retry);
				return;
			}
			setNotice('Résultat en cours de vérification. Consultez vos commandes ; ne payez pas une seconde fois.');
		}).catch(() => { checkingStatus = false; setNotice('Vérification momentanément indisponible. Consultez vos commandes ou contactez le support.'); });
	};
	const stripMessages = (html) => {
		const parsed = inertDocument('<body>' + String(html || ''));
		const holder = parsed ? parsed.body : el('div');
		const out = [];
		for (const item of holder.querySelectorAll('li')) { const t = text(item); if (t) out.push(t); }
		if (!out.length) { const t2 = text(holder); if (t2) out.push(t2); }
		return out.length ? out : (i18n.failed || '');
	};
	const row = (label, value, className) => {
		const line = el('div', 'dnp-express-row' + (className ? ' ' + className : ''));
		line.appendChild(el('span', 'dnp-express-row-label', label));
		const v = el('span', 'dnp-express-row-value');
		if (typeof value === 'string') v.textContent = value; else if (value) v.appendChild(value);
		line.appendChild(v);
		return line;
	};
	const chosenGateway = () => {
		const f = form();
		if (!f) return '';
		const radios = Array.from(f.querySelectorAll('input[name="payment_method"]'));
		const wallet = radios.find((r) => /wallet/i.test(r.value)), checked = radios.find((r) => r.checked);
		const pick = wallet || checked || radios[0] || null;
		if (pick) for (const r of radios) r.checked = r === pick;
		return pick ? pick.value : '';
	};
	const afterBalance = (guard) => {
		const balance = parseFloat(guard.getAttribute('data-balance') || ''), total = parseFloat(guard.getAttribute('data-total') || '');
		if (isNaN(balance) || isNaN(total)) return '';
		const sample = guard.getAttribute('data-balance-text') || '';
		const digits = sample.replace(/[^\d.,]/g, '');
		const decimals = digits.indexOf('.') !== -1 ? digits.split('.').pop().length : 0;
		const formatted = (balance - total).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		const first = sample.search(/\d/);
		const lastDigit = sample.search(/\d[^\d]*$/);
		return (first > 0 ? sample.slice(0, first) : '') + formatted + (lastDigit !== -1 ? sample.slice(lastDigit + 1) : '');
	};
	const renderDetails = (f) => {
		if (!details) return;
		details.textContent = '';
		let shown = 0;
		const required = f.querySelectorAll('.woocommerce-billing-fields .validate-required input:not([type="hidden"]), .woocommerce-billing-fields .validate-required select, .woocommerce-billing-fields .validate-required textarea, .woocommerce-additional-fields .validate-required input:not([type="hidden"]), .woocommerce-additional-fields .validate-required select, .woocommerce-additional-fields .validate-required textarea');
		/* One field per detail: when the checkout carries the same detail twice
		   (two required e-mail fields, from WooCommerce and an extension), the
		   sheet shows it once and posts the same value into both. */
		const seen = new Map();
		required.forEach((hidden, i) => {
			if (hidden.disabled) return;
			if (/country|state/.test(hidden.name) || hidden.type === 'file') state.fullRequired = true;
			const rowNode = hidden.closest('.form-row');
			const labelNode = rowNode ? rowNode.querySelector('label') : null;
			const label = text(labelNode).replace(/\*\s*$/, '').replace(/\(optionnel\)|\(optional\)/i, '').trim() || hidden.name;
			const kind = (hidden.type === 'email' || /email/i.test(hidden.name)) ? 'email' : ((hidden.type === 'tel' || /phone|whatsapp/i.test(hidden.name)) ? 'tel' : hidden.type + ':' + label.toLowerCase());
			const twin = seen.get(kind);
			if (twin) {
				if (!hidden.value && twin.value) hidden.value = twin.value;
				twin.addEventListener('input', () => { hidden.value = twin.value; });
				twin.addEventListener('change', () => { hidden.value = twin.value; });
				return;
			}
			const wrap = el('label', 'dnp-express-field dnp-express-field--' + (kind.split(':')[0] || 'text'));
			wrap.appendChild(el('span', '', label));
			const input = hidden.cloneNode(true);
			input.id = 'dnpx-field-' + i;
			input.value = hidden.value || ''; input.checked = hidden.checked;
			input.removeAttribute('name'); input.setAttribute('aria-required', 'true');
			if (kind === 'tel') { input.type = 'tel'; input.setAttribute('inputmode', 'tel'); input.setAttribute('autocomplete', 'tel'); }
			if (kind === 'email') { input.setAttribute('inputmode', 'email'); input.setAttribute('autocomplete', 'email'); }
			const sync = () => { hidden.value = input.value; hidden.checked = input.checked; };
			input.addEventListener('input', sync); input.addEventListener('change', sync);
			wrap.appendChild(input);
			const hint = rowNode ? rowNode.querySelector('.description') : null;
			if (hint && text(hint)) wrap.appendChild(el('small', 'dnp-express-hint', text(hint)));
			details.appendChild(wrap);
			seen.set(kind, input);
			shown++;
		});
		if (shown) { details.insertBefore(el('h3', '', i18n.details || 'Vos coordonnées'), details.firstChild); details.hidden = false; }
		else details.hidden = true;
	};
	const render = () => {
		const f = form();
		if (!f || !summary) return;
		summary.classList.remove('is-loading');
		summary.textContent = '';
		const table = f.querySelector('.woocommerce-checkout-review-order-table');
		const items = table ? Array.from(table.querySelectorAll('tr.cart_item')) : [];
		const card = el('div', 'dnp-express-card');
		for (const item of items) {
			const nameCell = item.querySelector('.product-name');
			const nameNode = nameCell ? nameCell.cloneNode(true) : null;
			const metaNodes = [];
			let qty = '';
			if (nameNode) {
				const q = nameNode.querySelector('.product-quantity');
				if (q) { qty = text(q).replace(/^×\s*/, '').replace(/^x\s*/i, ''); q.remove(); }
				for (const list of nameNode.querySelectorAll('dl.variation, .wc-item-meta')) { metaNodes.push(list); list.remove(); }
			}
			const line = el('div', 'dnp-express-item');
			let fullName = text(nameNode) || cfg.productName || '';
			let option = '';
			const sep = fullName.indexOf(' - ');
			if (sep > 0) { option = fullName.slice(sep + 3).trim(); fullName = fullName.slice(0, sep).trim(); }
			line.appendChild(row(i18n.product || 'Produit', fullName, 'is-name'));
			if (option) line.appendChild(row(i18n.option || 'Option', option));
			for (const meta of metaNodes) for (const label of meta.querySelectorAll('dt, .wc-item-meta-label')) { const lab = text(label).replace(/:\s*$/, ''); const val = text(label.nextElementSibling); if (lab && val) line.appendChild(row(lab, val)); }
			if (qty && qty !== '1') line.appendChild(row(i18n.quantity || 'Quantité', qty));
			const totalCell = item.querySelector('.product-total');
			if (totalCell && items.length > 1) line.appendChild(row(i18n.subtotal || 'Sous-total', text(totalCell), 'is-line-total'));
			card.appendChild(line);
		}
		const extra = table ? Array.from(table.querySelectorAll('tfoot tr.cart-discount, tfoot tr.fee, tfoot tr.shipping, tfoot tr.tax-rate, tfoot tr.tax-total')) : [];
		if (extra.length) {
			const sub = table.querySelector('tfoot tr.cart-subtotal');
			if (sub) card.appendChild(row(text(sub.querySelector('th')) || i18n.subtotal, text(sub.querySelector('td')), 'is-muted'));
			for (const x of extra) { const label = x.querySelector('th'), amount = x.querySelector('td'); if (label && amount) card.appendChild(row(text(label), text(amount), 'is-muted')); }
		}
		const totalRow = table ? table.querySelector('tfoot tr.order-total') : null;
		state.total = text(totalRow ? (totalRow.querySelector('.woocommerce-Price-amount') || totalRow.querySelector('td')) : null);
		card.appendChild(row(i18n.total || 'Total', state.total, 'is-total'));
		summary.appendChild(card);
		const guard = f.querySelector('[data-dpn-wallet-guard]');
		state.insufficient = !!(guard && guard.getAttribute('data-insufficient') === '1');
		if (guard && guard.getAttribute('data-wallet-url')) state.walletUrl = safeUrl(guard.getAttribute('data-wallet-url'));
		state.hasGateway = f.querySelectorAll('input[name="payment_method"]').length > 0;
		state.gateway = chosenGateway();
		const wallet = el('div', 'dnp-express-wallet');
		if (guard) {
			wallet.appendChild(row(i18n.balance || 'Solde wallet', guard.getAttribute('data-balance-text') || ''));
			if (!state.insufficient) { const after = afterBalance(guard); if (after) wallet.appendChild(row(i18n.after || 'Après paiement', after, 'is-after')); }
		}
		if (state.gateway) { const lab2 = f.querySelector('label[for="payment_method_' + state.gateway + '"]'); wallet.appendChild(row(i18n.payment || 'Paiement', state.gateway === 'wallet' ? 'Delicat Wallet' : (lab2 ? text(lab2) : state.gateway), 'is-gateway')); }
		if (wallet.childNodes.length) summary.appendChild(wallet);
		state.fullRequired = false;
		renderDetails(f);
		if (terms) {
			terms.textContent = '';
			const consent = el('input'); consent.type = 'checkbox'; consent.id = 'dnp-express-consent'; consent.setAttribute('aria-label', 'J’accepte les conditions d’utilisation');
			const consentLabel = el('label');
			consentLabel.appendChild(consent);
			consentLabel.appendChild(doc.createTextNode(' J’accepte les '));
			const a = el('a', '', i18n.termsLabel || 'conditions d’utilisation');
			a.href = safeUrl(cfg.termsUrl) || '#'; a.target = '_blank'; a.rel = 'noopener noreferrer';
			consentLabel.appendChild(a);
			consentLabel.appendChild(doc.createTextNode('.'));
			terms.appendChild(consentLabel);
			terms.hidden = false;
		}
		log('rendered', { items: items.length, total: state.total, gateway: state.gateway, insufficient: state.insufficient });
		if (state.insufficient) { setNotice(i18n.insufficient || '', 'warn'); setPay('recharge'); return; }
		if (!state.hasGateway && !/^\D*0(?:[.,]0+)?\D*$/.test(state.total)) setNotice(i18n.noGateway || '', 'warn');
		setPay('ready');
		if (state.gateway !== 'wallet' || state.fullRequired) payLabel.textContent = 'Continuer vers le paiement';
	};
	const loadForm = () => {
		if (loading || paying) return;
		loading = true;
		setNotice('');
		setPay('loading');
		if (summary) summary.classList.add('is-loading');
		log('form load', cfg.formUrl);
		request(cfg.formUrl, { method: 'GET' }).then((res) => {
			loading = false;
			log('form response', { status: res.status, type: res.type, length: res.raw.length });
			if (!res.ok || res.raw.indexOf('<form') === -1) { setNotice(i18n.network, 'error'); setPay('blocked'); return; }
			const parsed = inertDocument('<body>' + res.raw);
			const fresh = parsed ? parsed.querySelector('form.checkout') : null;
			woo.textContent = '';
			if (fresh) woo.appendChild(doc.importNode(fresh, true));
			if (!form()) { setNotice(i18n.network, 'error'); setPay('blocked'); log('form missing form.checkout'); return; }
			render();
		}).catch((err) => { loading = false; log('form error', String(err)); setNotice(i18n.network, 'error'); setPay('blocked'); });
	};
	const checkout = () => {
		if (paying || loading || !pay || pay.disabled) return;
		if (state.insufficient) { location.href = safeUrl(state.walletUrl); return; }
		const f = form();
		if (!f) return;
		if (state.gateway !== 'wallet' || state.fullRequired) { location.href = safeUrl(cfg.fullCheckout); return; }
		const consent = terms.querySelector('input');
		if (!consent || !consent.checked) { setNotice('Veuillez accepter les conditions avant de payer.'); return; }
		const originalTerms = f.querySelector('input[name="terms"]');
		if (originalTerms) originalTerms.checked = true;
		paying = true;
		setNotice('');
		setPay('paying');
		const paymentData = new FormData(f);
		paymentData.append('delicat_consent', '1');
		request(cfg.checkoutUrl, { method: 'POST', body: paymentData }).then((res) => {
			const data = parseJson(res.raw);
			if (!res.ok || !data || !data.result) throw new Error('unknown');
			if (data.result === 'success') {
				const redirect = safeUrl(data.redirect);
				if (redirect) { location.href = redirect; return; }
				paying = false; uncertain = true;
				setNotice('Commande reçue. Consultez vos commandes pour vérifier le paiement.');
				setPay('blocked');
				return;
			}
			paying = false; uncertain = true;
			setNotice(stripMessages(data.messages)); setPay('blocked');
		}).catch(() => { paying = false; uncertain = true; setNotice('Connexion interrompue : le paiement peut avoir été effectué. Consultez vos commandes avant toute nouvelle tentative.'); setPay('blocked'); });
	};
	const signature = (fd) => { const parts = []; try { fd.forEach((value, key) => { if (typeof value === 'string') parts.push(key + '=' + value); }); } catch (_) { return ''; } return parts.sort().join('&'); };
	const fallback = (button, why) => {
		log('uncertain add-to-cart result', why);
		setBusy(false);
		uncertain = true;
		button.disabled = true;
		inlineError(['Résultat incertain. Vérifiez votre panier avant de réessayer.'], closest(button, 'form.cart'));
		const target = safeUrl(cfg.cartUrl);
		if (target) location.href = target;
	};
	const express = (productForm, button) => {
		const fd = new FormData(productForm);
		if (!fd.has('add-to-cart')) { const addButton = productForm.querySelector('.single_add_to_cart_button[name="add-to-cart"]'); const pid = (addButton && addButton.value) || cfg.productId || ''; if (pid) fd.append('add-to-cart', String(pid)); }
		fd.append('delicat_native_buy_now', '1');
		fd.append('dpn_express', '1');
		fd.append('_delicat_express_nonce', cfg.addNonce || '');
		const sig = signature(fd);
		if (armed && sig && sig === lastSignature) { log('reopen (same selection)'); open(); return; }
		setBusy(true, i18n.adding || '');
		const action = productForm.getAttribute('action') || location.href;
		log('add-to-cart post', action);
		request(action, { method: 'POST', body: fd }).then((res) => {
			const data = parseJson(res.raw);
			log('add-to-cart response', { status: res.status, type: res.type, data: data || res.raw.slice(0, 120) });
			if (data && data.success) { armed = true; lastSignature = sig; setBusy(false); emit('delicat:cart-changed', { source: 'express' }); open(); return; }
			if (data && data.success === false) { setBusy(false); inlineError((data.messages && data.messages.length) ? data.messages : [i18n.refused || ''], productForm); return; }
			fallback(button, 'non-json ' + res.status);
		}).catch((err) => fallback(button, String(err)));
	};

	on(doc, 'click', (event) => {
		const target = event.target;
		if (!target || target.nodeType !== 1) return;
		if (closest(target, '[data-dnp-express-close]')) { event.preventDefault(); close(); return; }
		if (closest(target, '[data-dnp-express-pay]')) { event.preventDefault(); checkout(); return; }
		const button = closest(target, '.dnp-buy-now');
		if (!button) return;
		const session = engine.session && engine.session.get ? engine.session.get() : null;
		if (session && session.loggedIn === false) { log('guest session → classic'); return; }
		const productForm = closest(button, 'form.cart');
		const productRoot = closest(button, '.delicat-native-product');
		if (!productForm || (productRoot && productRoot.getAttribute('data-express') === '0')) { log('classic submit (form/express flag)'); return; }
		if (button.disabled || button.classList.contains('disabled')) { log('button disabled'); return; }
		if (!supported()) { log('unsupported', { formUrl: cfg.formUrl, checkoutUrl: cfg.checkoutUrl }); return; }
		event.preventDefault();
		event.stopImmediatePropagation();
		event.stopPropagation();
		if (busy || paying) return;
		if (uncertain) { open(); return; }
		express(productForm, button);
	}, { capture: true, signal });
	on(doc, 'keydown', (event) => {
		if (event.key === 'Tab' && !modal.hidden) {
			const nodes = modal.querySelectorAll('button:not(:disabled),a[href],input:not([type="hidden"]),[tabindex="0"]');
			const visible = Array.prototype.filter.call(nodes, (n) => n.getClientRects().length);
			const first = visible[0], last = visible[visible.length - 1];
			if (event.shiftKey && (doc.activeElement === first || doc.activeElement === sheet)) { event.preventDefault(); if (last) last.focus(); }
			else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); if (first) first.focus(); }
		}
		if (event.key === 'Escape' && !modal.hidden && !body.classList.contains('dpn-wallet-modal-open')) close();
	}, { signal });
	signal.addEventListener('abort', () => { body.classList.remove('dnp-express-ready', 'dnp-express-open', 'dnp-express-busy'); releaseOverlay('express'); }, { once: true });
}
