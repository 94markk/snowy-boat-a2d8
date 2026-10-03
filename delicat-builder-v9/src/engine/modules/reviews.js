/**
 * Review invitation and review form (bottom-sheet modal). One delegated click
 * handler for [data-dlc-review-open] survives every soft navigation; the
 * prompt probe runs once per document for signed-in customers.
 */
import { device, doc, el, emit, on, trapTab, win } from '../core/dom.js';
import { ajax, getJson } from '../core/net.js';
import { registerOverlay, releaseOverlay } from '../core/overlay.js';

export default function mount({ signal, config }) {
	const cfg = win.DelicatReviewsConfig || config.reviews;
	if (!cfg || !cfg.ajaxUrl || !win.fetch) return;
	const state = { nonce: '', max: 280, rating: 5, overlay: null, lastFocus: null, sending: false, done: false, due: false, name: '', promise: null, products: [], reviewed: [], product: 0, productName: '', productsPromise: null, dueProduct: null };
	const reduced = device.reducedMotion;

	const icon = (name) => {
		const paths = {
			celebrate: '<path d="M8 3 6.5 7.5 2 9l4.5 1.5L8 15l1.5-4.5L14 9 9.5 7.5 8 3Z"/><path d="m17 13-1 3-3 1 3 1 1 3 1-3 3-1-3-1-1-3Z"/>',
			heart: '<path d="M12 20.5S4.5 15.9 2.7 11C1.6 7.6 3.6 4.5 6.9 4.5c1.9 0 3.4 1 4.3 2.4.9-1.4 2.4-2.4 4.3-2.4 3.3 0 5.3 3.1 4.2 6.5-1.8 4.9-9.3 9.5-9.3 9.5Z"/>',
			lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
			clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		};
		const wrap = el('div', 'dlc-review-emoji');
		wrap.innerHTML = '<svg class="dlc-review-icon" viewBox="0 0 24 24" aria-hidden="true">' + (paths[name] || paths.celebrate) + '</svg>';
		return wrap;
	};
	const post = (action, fields) => ajax(action, fields, { url: cfg.ajaxUrl }).then((data) => ({ success: true, data })).catch((error) => ({ success: false, data: error && error.data }));

	function close(snooze) {
		const overlay = state.overlay;
		if (!overlay) return;
		state.overlay = null;
		if (snooze && state.nonce) post('delicat_builder_v9_review_snooze', { nonce: state.nonce });
		doc.removeEventListener('keydown', onKey, true);
		doc.body.classList.remove('dlc-review-modal-open');
		releaseOverlay('reviews');
		overlay.classList.remove('is-open');
		overlay.classList.add('is-closing');
		win.setTimeout(() => { overlay.remove(); if (state.lastFocus && state.lastFocus.focus) { try { state.lastFocus.focus(); } catch (_) {} } }, reduced ? 0 : 260);
	}
	function onKey(event) {
		if (!state.overlay) return;
		if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
		trapTab(event, state.overlay.querySelector('.dlc-review-dialog'));
	}
	const closeButton = () => {
		const btn = el('button', 'dlc-review-close', '');
		btn.type = 'button';
		btn.setAttribute('aria-label', 'Fermer');
		btn.innerHTML = '<svg class="dlc-review-close-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>';
		btn.addEventListener('click', () => close(true));
		return btn;
	};
	const button = (cls, text, handler) => { const b = el('button', 'dlc-review-btn ' + cls, text); b.type = 'button'; b.addEventListener('click', handler); return b; };

	function inviteStep(dialog, firstName) {
		dialog.textContent = '';
		dialog.append(closeButton(), icon('celebrate'));
		dialog.append(el('h2', 'dlc-review-title', state.dueProduct && state.dueProduct.name ? ((firstName ? firstName + ', ' : '') + 'ton avis sur ' + state.dueProduct.name + ' ?') : (firstName ? (firstName + ', deuxième recharge réussie !') : 'Deuxième recharge réussie !')));
		dialog.append(el('p', 'dlc-review-text', 'Mèsi pou konfyans ou. Raconte ton expérience Delicat Store en 30 secondes — ton avis aide toute la communauté.'));
		const actions = el('div', 'dlc-review-actions');
		const primary = button('dlc-review-btn--primary', 'Laisser un avis', () => formStep(dialog, firstName));
		actions.append(primary, button('dlc-review-btn--ghost', 'Plus tard', () => close(true)));
		dialog.append(actions);
		primary.focus();
	}
	const unreviewedPurchases = () => (state.products || []).filter((product) => { const id = parseInt(product.id, 10) || 0; return id > 0 && state.reviewed.indexOf(id) === -1; });
	const alreadyDone = () => (state.product > 0 ? state.reviewed.indexOf(state.product) !== -1 : state.done && unreviewedPurchases().length === 0);
	function ensureProducts() {
		if (state.productsPromise) return state.productsPromise;
		state.productsPromise = post('delicat_builder_v9_review_products', { nonce: state.nonce }).then((res) => {
			if (res.success && res.data) { state.products = Array.isArray(res.data.products) ? res.data.products : []; state.reviewed = Array.isArray(res.data.reviewed) ? res.data.reviewed.map((id) => parseInt(id, 10) || 0) : []; }
			return res;
		}).catch(() => { state.productsPromise = null; });
		return state.productsPromise;
	}
	function formStep(dialog, firstName) {
		dialog.textContent = '';
		dialog.append(closeButton());
		const fixedProduct = state.product > 0 ? { id: state.product, name: state.productName } : null;
		let productSelect = null;
		dialog.append(el('h2', 'dlc-review-title', fixedProduct && fixedProduct.name ? 'Ton avis sur ' + fixedProduct.name : 'Ton avis sur Delicat Store'));
		dialog.append(el('p', 'dlc-review-text', 'Note ton expérience et écris quelques mots.'));
		const productField = el('div', 'dlc-review-field');
		productField.style.display = 'none';
		const buildSelect = () => {
			const purchases = unreviewedPurchases();
			if (!purchases.length || productSelect) return;
			productField.style.display = '';
			productField.append(el('label', 'dlc-review-label', 'Produit concerné'));
			productSelect = doc.createElement('select');
			productSelect.className = 'dlc-review-input dlc-review-select';
			if (!state.done) { const opt = doc.createElement('option'); opt.value = '0'; opt.textContent = 'Delicat Store (en général)'; productSelect.append(opt); }
			for (const product of purchases) { const opt = doc.createElement('option'); const id = parseInt(product.id, 10) || 0; opt.value = String(id); opt.textContent = String(product.name || ('Produit #' + id)); productSelect.append(opt); }
			productSelect.value = String(parseInt(purchases[0].id, 10) || 0);
			productField.append(productSelect);
		};
		if (!fixedProduct) {
			dialog.append(productField);
			if (state.productsPromise) { buildSelect(); ensureProducts().then(() => { if (state.overlay) buildSelect(); }); }
			else if (state.nonce) ensureProducts().then(() => { if (state.overlay) buildSelect(); });
		}
		const stars = el('div', 'dlc-review-stars');
		const starButtons = [];
		for (let value = 1; value <= 5; value++) {
			const star = el('button', 'dlc-review-star', '★');
			star.type = 'button';
			star.setAttribute('aria-label', value + ' étoile' + (value > 1 ? 's' : '') + ' sur 5');
			star.addEventListener('click', () => { state.rating = value; starButtons.forEach((btn, index) => btn.classList.toggle('is-on', index < value)); });
			starButtons.push(star);
			stars.append(star);
		}
		starButtons.forEach((btn) => btn.classList.add('is-on'));
		dialog.append(stars);
		const error = el('p', 'dlc-review-error', '');
		const quoteField = el('div', 'dlc-review-field');
		quoteField.append(el('label', 'dlc-review-label', 'Ton expérience'));
		const quote = doc.createElement('textarea');
		quote.className = 'dlc-review-textarea'; quote.maxLength = state.max; quote.placeholder = 'Ex : Recharge reçue en 2 minutes, service rapide et sérieux…';
		const counter = el('div', 'dlc-review-counter', '0 / ' + state.max);
		quote.addEventListener('input', () => { counter.textContent = quote.value.length + ' / ' + state.max; });
		quoteField.append(quote, counter);
		dialog.append(quoteField);
		const nameField = el('div', 'dlc-review-field');
		nameField.append(el('label', 'dlc-review-label', 'Ton prénom'));
		const name = doc.createElement('input'); name.className = 'dlc-review-input'; name.type = 'text'; name.maxLength = 40; name.value = firstName || '';
		nameField.append(name);
		dialog.append(nameField);
		const cityField = el('div', 'dlc-review-field');
		cityField.append(el('label', 'dlc-review-label', 'Ta ville (optionnel)'));
		const city = doc.createElement('input'); city.className = 'dlc-review-input'; city.type = 'text'; city.maxLength = 40; city.placeholder = 'Ex : Port-au-Prince';
		cityField.append(city);
		dialog.append(cityField, error);
		const actions = el('div', 'dlc-review-actions');
		const send = button('dlc-review-btn--primary', 'Envoyer mon avis', async () => {
			if (state.sending) return;
			error.textContent = '';
			if (quote.value.replace(/\s+/g, ' ').trim().length < 8) { error.textContent = 'Écris quelques mots sur ton expérience.'; quote.focus(); return; }
			state.sending = true; send.disabled = true; send.textContent = 'Envoi…';
			const productId = fixedProduct ? fixedProduct.id : (productSelect ? (parseInt(productSelect.value, 10) || 0) : 0);
			const res = await post('delicat_builder_v9_review_submit', { nonce: state.nonce, rating: String(state.rating), quote: quote.value, name: name.value, city: city.value, product_id: String(productId) });
			state.sending = false;
			if (res.success) { state.done = true; if (productId && state.reviewed.indexOf(productId) === -1) state.reviewed.push(productId); thanksStep(dialog, res.data && res.data.message); }
			else { send.disabled = false; send.textContent = 'Envoyer mon avis'; error.textContent = (res.data && res.data.message) || 'Une erreur est survenue. Réessaie.'; }
		});
		actions.append(send, button('dlc-review-btn--ghost', 'Plus tard', () => close(true)));
		dialog.append(actions);
		quote.focus();
	}
	function thanksStep(dialog, message) {
		dialog.textContent = '';
		const wrap = el('div', 'dlc-review-thanks');
		wrap.append(icon('celebrate'), el('h2', 'dlc-review-title', 'Mèsi anpil !'), el('p', 'dlc-review-text', message || 'Ton avis sera publié après validation.'));
		dialog.append(wrap);
		win.setTimeout(() => close(false), 2600);
	}
	function alreadyStep(dialog) {
		dialog.textContent = '';
		dialog.append(closeButton());
		const wrap = el('div', 'dlc-review-thanks');
		wrap.append(icon('heart'), el('h2', 'dlc-review-title', state.product > 0 ? 'Tu as déjà noté ce produit' : 'Ton avis a déjà été reçu'), el('p', 'dlc-review-text', 'Mèsi anpil pou sipò ou. Il sera publié après validation.'));
		dialog.append(wrap);
		win.setTimeout(() => close(false), 2600);
	}
	function loginStep(dialog) {
		dialog.textContent = '';
		dialog.append(closeButton(), icon('lock'), el('h2', 'dlc-review-title', 'Connecte-toi pour laisser ton avis'), el('p', 'dlc-review-text', 'Ton avis vérifié aide toute la communauté Delicat Store. Connecte-toi à ton compte pour continuer.'));
		const actions = el('div', 'dlc-review-actions');
		const login = el('a', 'dlc-review-btn dlc-review-btn--primary', 'Se connecter');
		login.href = cfg.accountUrl || '#';
		actions.append(login, button('dlc-review-btn--ghost', 'Plus tard', () => close(false)));
		dialog.append(actions);
		login.focus();
	}
	function loadingStep(dialog) {
		dialog.textContent = '';
		dialog.append(closeButton());
		const wrap = el('div', 'dlc-review-thanks');
		wrap.append(icon('clock'), el('p', 'dlc-review-text', 'Un instant…'));
		dialog.append(wrap);
	}
	function fetchPrompt() {
		if (state.promise) return state.promise;
		state.promise = getJson(cfg.ajaxUrl + '?action=delicat_builder_v9_review_prompt' + (cfg.thanks ? '&thanks=1' : ''), {}, 8000).then((data) => {
			if (data && data.success && data.data && data.data.nonce) {
				state.nonce = String(data.data.nonce || '');
				state.max = parseInt(data.data.max, 10) || 280;
				state.done = !!data.data.done;
				state.name = String(data.data.name || '').trim();
				state.due = !!data.data.due;
				state.dueProduct = data.data.product && parseInt(data.data.product.id, 10) ? { id: parseInt(data.data.product.id, 10), name: String(data.data.product.name || '') } : null;
			}
			return data;
		});
		return state.promise;
	}
	function openWith(render) {
		if (state.overlay) return;
		state.lastFocus = doc.activeElement;
		const overlay = el('div', 'dlc-review-overlay');
		const dialog = el('div', 'dlc-review-dialog');
		dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.setAttribute('aria-label', 'Laisser un avis Delicat Store');
		overlay.append(dialog);
		overlay.addEventListener('click', (event) => { if (event.target === overlay) close(false); });
		doc.body.append(overlay);
		doc.body.classList.add('dlc-review-modal-open');
		registerOverlay('reviews');
		state.overlay = overlay;
		doc.addEventListener('keydown', onKey, true);
		render(dialog);
		if (reduced) overlay.classList.add('is-open'); else requestAnimationFrame(() => requestAnimationFrame(() => overlay.classList.add('is-open')));
	}
	function open(firstName, directToForm) {
		state.product = state.dueProduct ? state.dueProduct.id : 0;
		state.productName = state.dueProduct ? state.dueProduct.name : '';
		openWith((dialog) => { if (directToForm) { if (state.done) alreadyStep(dialog); else formStep(dialog, firstName); } else inviteStep(dialog, firstName); });
	}
	on(doc, 'click', (event) => {
		const trigger = event.target instanceof Element ? event.target.closest('[data-dlc-review-open]') : null;
		if (!trigger) return;
		event.preventDefault();
		let pid = parseInt(trigger.getAttribute('data-dlc-review-product') || '0', 10) || 0;
		let pname = trigger.getAttribute('data-dlc-review-product-name') || '';
		const live = win.DelicatReviewsConfig || cfg;
		if (!pid && live.productId) { pid = parseInt(live.productId, 10) || 0; pname = live.productName || ''; }
		state.product = pid; state.productName = pname;
		if (!live.loggedIn) { openWith(loginStep); return; }
		openWith((dialog) => {
			loadingStep(dialog);
			(state.nonce ? Promise.resolve() : fetchPrompt()).then(() => {
				if (!state.overlay) return;
				if (!state.nonce) { loginStep(dialog); return; }
				return ensureProducts().then(() => { if (!state.overlay) return; if (alreadyDone()) alreadyStep(dialog); else formStep(dialog, state.name); });
			}).catch(() => { if (state.overlay) loginStep(dialog); });
		});
	}, { signal });
	signal.addEventListener('abort', () => close(false), { once: true });
	if (!cfg.loggedIn) return;
	const probe = () => fetchPrompt().then(() => { if (state.due) win.setTimeout(() => open(state.name, false), 2200); }).catch(() => {});
	if (doc.prerendering) doc.addEventListener('prerenderingchange', probe, { once: true }); else probe();
}
