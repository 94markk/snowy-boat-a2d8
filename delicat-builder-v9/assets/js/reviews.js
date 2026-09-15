(function () {
	'use strict';

	var cfg = window.DelicatReviewsConfig || {};
	if (!cfg.ajaxUrl || !window.fetch || !document.body) { return; }
	if (window.__dlcReviewBooted) { return; }
	window.__dlcReviewBooted = true;

	var state = { nonce: '', max: 280, rating: 5, overlay: null, lastFocus: null, sending: false, done: false, due: false, name: '', promise: null, products: [], reviewed: [], product: 0, productName: '', productsPromise: null, dueProduct: null };
	var reduced = false;
	try { reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text) { node.textContent = text; }
		return node;
	}

	function icon(name) {
		var paths = {
			celebrate: '<path d="M8 3 6.5 7.5 2 9l4.5 1.5L8 15l1.5-4.5L14 9 9.5 7.5 8 3Z"/><path d="m17 13-1 3-3 1 3 1 1 3 1-3 3-1-3-1-1-3Z"/>',
			heart: '<path d="M12 20.5S4.5 15.9 2.7 11C1.6 7.6 3.6 4.5 6.9 4.5c1.9 0 3.4 1 4.3 2.4.9-1.4 2.4-2.4 4.3-2.4 3.3 0 5.3 3.1 4.2 6.5-1.8 4.9-9.3 9.5-9.3 9.5Z"/>',
			lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
			clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'
		};
		var wrap = el('div', 'dlc-review-emoji');
		wrap.innerHTML = '<svg class="dlc-review-icon" viewBox="0 0 24 24" aria-hidden="true">' + (paths[name] || paths.celebrate) + '</svg>';
		return wrap;
	}

	function post(action, fields) {
		var body = new FormData();
		body.append('action', action);
		Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); });
	}

	function close(snooze) {
		var overlay = state.overlay;
		if (!overlay) { return; }
		state.overlay = null;
		if (snooze && state.nonce) { post('delicat_builder_v9_review_snooze', { nonce: state.nonce }).catch(function () {}); }
		document.removeEventListener('keydown', onKey, true);
		document.body.classList.remove('dlc-review-modal-open');
		overlay.classList.remove('is-open');
		overlay.classList.add('is-closing');
		window.setTimeout(function () {
			if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
			if (state.lastFocus && state.lastFocus.focus) { try { state.lastFocus.focus(); } catch (e) {} }
		}, reduced ? 0 : 260);
	}

	function onKey(event) {
		if ('Escape' === event.key && state.overlay) {
			event.preventDefault();
			close(true);
		}
	}

	function inviteStep(dialog, firstName) {
		dialog.textContent = '';
		dialog.appendChild(closeButton());
		dialog.appendChild(icon('celebrate'));
		dialog.appendChild(el('h2', 'dlc-review-title',
			state.dueProduct && state.dueProduct.name ? ((firstName ? firstName + ', ' : '') + 'ton avis sur ' + state.dueProduct.name + ' ?') : (firstName ? (firstName + ', deuxième recharge réussie !') : 'Deuxième recharge réussie !')));
		dialog.appendChild(el('p', 'dlc-review-text',
			'Mèsi pou konfyans ou. Raconte ton expérience Delicat Store en 30 secondes — ton avis aide toute la communauté.'));

		var actions = el('div', 'dlc-review-actions');
		var primary = el('button', 'dlc-review-btn dlc-review-btn--primary', 'Laisser un avis');
		primary.type = 'button';
		primary.addEventListener('click', function () { formStep(dialog, firstName); });
		var later = el('button', 'dlc-review-btn dlc-review-btn--ghost', 'Plus tard');
		later.type = 'button';
		later.addEventListener('click', function () { close(true); });
		actions.appendChild(primary);
		actions.appendChild(later);
		dialog.appendChild(actions);
		primary.focus();
	}

	function formStep(dialog, firstName) {
		dialog.textContent = '';
		dialog.appendChild(closeButton());
		/* RC63: a review can target one WooCommerce product. From a product
		 * page the product is fixed; from the homepage the client picks one
		 * of their purchases (or "Delicat Store" for a site-wide review). */
		var fixedProduct = state.product > 0 ? { id: state.product, name: state.productName } : null;
		var productSelect = null;
		dialog.appendChild(el('h2', 'dlc-review-title', fixedProduct && fixedProduct.name ? 'Ton avis sur ' + fixedProduct.name : 'Ton avis sur Delicat Store'));
		dialog.appendChild(el('p', 'dlc-review-text', 'Note ton expérience et écris quelques mots.'));
		var productField = el('div', 'dlc-review-field');
		productField.style.display = 'none';
		var buildSelect = function () {
			var purchases = unreviewedPurchases();
			if (!purchases.length || productSelect) { return; }
			productField.style.display = '';
			productField.appendChild(el('label', 'dlc-review-label', 'Produit concerné'));
			productSelect = document.createElement('select');
			productSelect.className = 'dlc-review-input dlc-review-select';
			if (!state.done) {
				var generalOpt = document.createElement('option');
				generalOpt.value = '0';
				generalOpt.textContent = 'Delicat Store (en général)';
				productSelect.appendChild(generalOpt);
			}
			purchases.forEach(function (product) {
				var id = parseInt(product.id, 10) || 0;
				var opt = document.createElement('option');
				opt.value = String(id);
				opt.textContent = String(product.name || ('Produit #' + id));
				productSelect.appendChild(opt);
			});
			productSelect.value = String(parseInt(purchases[0].id, 10) || 0);
			productField.appendChild(productSelect);
		};
		if (!fixedProduct) {
			dialog.appendChild(productField);
			if (state.productsPromise) {
				buildSelect();
				ensureProducts().then(function () { if (state.overlay) { buildSelect(); } });
			} else if (state.nonce) {
				ensureProducts().then(function () { if (state.overlay) { buildSelect(); } });
			}
		}

		var stars = el('div', 'dlc-review-stars');
		var starButtons = [];
		for (var i = 1; i <= 5; i++) {
			(function (value) {
				var star = el('button', 'dlc-review-star', '★');
				star.type = 'button';
				star.setAttribute('aria-label', value + ' étoile' + (value > 1 ? 's' : '') + ' sur 5');
				star.addEventListener('click', function () {
					state.rating = value;
					starButtons.forEach(function (btn, index) {
						btn.classList.toggle('is-on', index < value);
					});
				});
				starButtons.push(star);
				stars.appendChild(star);
			})(i);
		}
		starButtons.forEach(function (btn) { btn.classList.add('is-on'); });
		dialog.appendChild(stars);

		var error = el('p', 'dlc-review-error', '');

		var quoteField = el('div', 'dlc-review-field');
		quoteField.appendChild(el('label', 'dlc-review-label', 'Ton expérience'));
		var quote = document.createElement('textarea');
		quote.className = 'dlc-review-textarea';
		quote.maxLength = state.max;
		quote.placeholder = 'Ex : Recharge reçue en 2 minutes, service rapide et sérieux…';
		var counter = el('div', 'dlc-review-counter', '0 / ' + state.max);
		quote.addEventListener('input', function () {
			counter.textContent = quote.value.length + ' / ' + state.max;
		});
		quoteField.appendChild(quote);
		quoteField.appendChild(counter);
		dialog.appendChild(quoteField);

		var nameField = el('div', 'dlc-review-field');
		nameField.appendChild(el('label', 'dlc-review-label', 'Ton prénom'));
		var name = document.createElement('input');
		name.className = 'dlc-review-input';
		name.type = 'text';
		name.maxLength = 40;
		name.value = firstName || '';
		nameField.appendChild(name);
		dialog.appendChild(nameField);

		var cityField = el('div', 'dlc-review-field');
		cityField.appendChild(el('label', 'dlc-review-label', 'Ta ville (optionnel)'));
		var city = document.createElement('input');
		city.className = 'dlc-review-input';
		city.type = 'text';
		city.maxLength = 40;
		city.placeholder = 'Ex : Port-au-Prince';
		cityField.appendChild(city);
		dialog.appendChild(cityField);

		dialog.appendChild(error);

		var actions = el('div', 'dlc-review-actions');
		var send = el('button', 'dlc-review-btn dlc-review-btn--primary', 'Envoyer mon avis');
		send.type = 'button';
		send.addEventListener('click', function () {
			if (state.sending) { return; }
			error.textContent = '';
			if (quote.value.replace(/\s+/g, ' ').trim().length < 8) {
				error.textContent = 'Écris quelques mots sur ton expérience.';
				quote.focus();
				return;
			}
			state.sending = true;
			send.disabled = true;
			send.textContent = 'Envoi…';
			var productId = fixedProduct ? fixedProduct.id : (productSelect ? (parseInt(productSelect.value, 10) || 0) : 0);
			post('delicat_builder_v9_review_submit', {
				nonce: state.nonce,
				rating: String(state.rating),
				quote: quote.value,
				name: name.value,
				city: city.value,
				product_id: String(productId)
			}).then(function (data) {
				state.sending = false;
				if (data && data.success) {
					state.done = true;
					if (productId && state.reviewed.indexOf(productId) === -1) { state.reviewed.push(productId); }
					thanksStep(dialog, data.data && data.data.message);
				} else {
					send.disabled = false;
					send.textContent = 'Envoyer mon avis';
					error.textContent = (data && data.data && data.data.message) || 'Une erreur est survenue. Réessaie.';
				}
			}).catch(function () {
				state.sending = false;
				send.disabled = false;
				send.textContent = 'Envoyer mon avis';
				error.textContent = 'Connexion impossible. Vérifie ton réseau et réessaie.';
			});
		});
		var later = el('button', 'dlc-review-btn dlc-review-btn--ghost', 'Plus tard');
		later.type = 'button';
		later.addEventListener('click', function () { close(true); });
		actions.appendChild(send);
		actions.appendChild(later);
		dialog.appendChild(actions);
		quote.focus();
	}

	function thanksStep(dialog, message) {
		dialog.textContent = '';
		var wrap = el('div', 'dlc-review-thanks');
		wrap.appendChild(icon('celebrate'));
		wrap.appendChild(el('h2', 'dlc-review-title', 'Mèsi anpil !'));
		wrap.appendChild(el('p', 'dlc-review-text', message || 'Ton avis sera publié après validation.'));
		dialog.appendChild(wrap);
		window.setTimeout(function () { close(false); }, 2600);
	}

	function closeButton() {
		var btn = el('button', 'dlc-review-close', '');
		btn.type = 'button';
		btn.setAttribute('aria-label', 'Fermer');
		btn.innerHTML = '<svg class="dlc-review-close-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>';
		btn.addEventListener('click', function () { close(true); });
		return btn;
	}


	function alreadyStep(dialog) {
		dialog.textContent = '';
		dialog.appendChild(closeButton());
		var wrap = el('div', 'dlc-review-thanks');
		wrap.appendChild(icon('heart'));
		wrap.appendChild(el('h2', 'dlc-review-title', state.product > 0 ? 'Tu as déjà noté ce produit' : 'Ton avis a déjà été reçu'));
		wrap.appendChild(el('p', 'dlc-review-text', 'Mèsi anpil pou sipò ou. Il sera publié après validation.'));
		dialog.appendChild(wrap);
		window.setTimeout(function () { close(false); }, 2600);
	}

	function loginStep(dialog) {
		dialog.textContent = '';
		dialog.appendChild(closeButton());
		dialog.appendChild(icon('lock'));
		dialog.appendChild(el('h2', 'dlc-review-title', 'Connecte-toi pour laisser ton avis'));
		dialog.appendChild(el('p', 'dlc-review-text', 'Ton avis vérifié aide toute la communauté Delicat Store. Connecte-toi à ton compte pour continuer.'));
		var actions = el('div', 'dlc-review-actions');
		var login = el('a', 'dlc-review-btn dlc-review-btn--primary', 'Se connecter');
		login.href = cfg.accountUrl || '#';
		var later = el('button', 'dlc-review-btn dlc-review-btn--ghost', 'Plus tard');
		later.type = 'button';
		later.addEventListener('click', function () { close(false); });
		actions.appendChild(login);
		actions.appendChild(later);
		dialog.appendChild(actions);
		login.focus();
	}

	function loadingStep(dialog) {
		dialog.textContent = '';
		dialog.appendChild(closeButton());
		var wrap = el('div', 'dlc-review-thanks');
		wrap.appendChild(icon('clock'));
		wrap.appendChild(el('p', 'dlc-review-text', 'Un instant…'));
		dialog.appendChild(wrap);
	}

	function fetchPrompt() {
		if (state.promise) { return state.promise; }
		state.promise = fetch(cfg.ajaxUrl + '?action=delicat_builder_v9_review_prompt' + (cfg.thanks ? '&thanks=1' : ''), { credentials: 'same-origin' })
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (data && data.success && data.data && data.data.nonce) {
					state.nonce = String(data.data.nonce || '');
					state.max = parseInt(data.data.max, 10) || 280;
					state.done = !!data.data.done;
					state.name = String(data.data.name || '').trim();
					state.due = !!data.data.due;
					/* RC66: a completed order queued a review for one product. */
					state.dueProduct = data.data.product && parseInt(data.data.product.id, 10) ? { id: parseInt(data.data.product.id, 10), name: String(data.data.product.name || '') } : null;
				}
				return data;
			});
		return state.promise;
	}

	/* RC65: purchases + reviewed products, one request per session, made only
	 * when the popup opens (RC63 fetched them on every page view). */
	function ensureProducts() {
		if (state.productsPromise) { return state.productsPromise; }
		state.productsPromise = post('delicat_builder_v9_review_products', { nonce: state.nonce }).then(function (data) {
			if (data && data.success && data.data) {
				state.products = Array.isArray(data.data.products) ? data.data.products : [];
				state.reviewed = Array.isArray(data.data.reviewed) ? data.data.reviewed.map(function (id) { return parseInt(id, 10) || 0; }) : [];
			}
			return data;
		}).catch(function () { state.productsPromise = null; });
		return state.productsPromise;
	}

	/* RC63: "already reviewed" is per product when the CTA targets one; the
	 * site-wide CTA still opens while a purchase is left to review. */
	function unreviewedPurchases() {
		return (state.products || []).filter(function (product) {
			var id = parseInt(product.id, 10) || 0;
			return id > 0 && state.reviewed.indexOf(id) === -1;
		});
	}
	function alreadyDone() {
		if (state.product > 0) { return state.reviewed.indexOf(state.product) !== -1; }
		return state.done && unreviewedPurchases().length === 0;
	}

	function onCtaClick(event) {
		event.preventDefault();
		var trigger = event.target;
		while (trigger && trigger !== document && !(trigger.hasAttribute && trigger.hasAttribute('data-dlc-review-open'))) { trigger = trigger.parentNode; }
		var pid = 0, pname = '';
		if (trigger && trigger.getAttribute) {
			pid = parseInt(trigger.getAttribute('data-dlc-review-product') || '0', 10) || 0;
			pname = trigger.getAttribute('data-dlc-review-product-name') || '';
		}
		if (!pid && cfg.productId) { pid = parseInt(cfg.productId, 10) || 0; pname = cfg.productName || ''; }
		state.product = pid;
		state.productName = pname;
		if (!cfg.loggedIn) {
			openWith(function (dialog) { loginStep(dialog); });
			return;
		}
		openWith(function (dialog) {
			loadingStep(dialog);
			(state.nonce ? Promise.resolve() : fetchPrompt()).then(function () {
				if (!state.overlay) { return; }
				if (!state.nonce) { loginStep(dialog); return; }
				return ensureProducts().then(function () {
					if (!state.overlay) { return; }
					if (alreadyDone()) { alreadyStep(dialog); } else { formStep(dialog, state.name); }
				});
			}).catch(function () {
				if (state.overlay) { loginStep(dialog); }
			});
		});
	}

	function bindButtons() {
		/*
		 * Delegated on the document, never on each button.
		 *
		 * The storefront swaps page content without a reload, and this script
		 * refuses to run twice (__dlcReviewBooted). So the buttons bound at
		 * first boot are thrown away by the first navigation, and the ones that
		 * replace them were never bound — the CTA looked alive and did nothing.
		 * One listener on the document survives every swap.
		 */
		document.addEventListener('click', function (event) {
			var node = event.target;
			while (node && node !== document) {
				if (node.nodeType === 1 && node.hasAttribute && node.hasAttribute('data-dlc-review-open')) {
					onCtaClick(event);
					return;
				}
				node = node.parentNode;
			}
		}, false);
	}

	function openWith(render) {
		if (state.overlay) { return; }
		state.lastFocus = document.activeElement;

		var overlay = el('div', 'dlc-review-overlay');
		var dialog = el('div', 'dlc-review-dialog');
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-label', 'Laisser un avis Delicat Store');
		overlay.appendChild(dialog);
		overlay.addEventListener('click', function (event) {
			if (event.target === overlay) { close(false); }
		});
		document.body.appendChild(overlay);
		document.body.classList.add('dlc-review-modal-open');
		state.overlay = overlay;
		document.addEventListener('keydown', onKey, true);

		render(dialog);

		if (reduced) {
			overlay.classList.add('is-open');
		} else {
			window.requestAnimationFrame(function () {
				window.requestAnimationFrame(function () { overlay.classList.add('is-open'); });
			});
		}
	}

	function open(firstName, directToForm) {
		state.product = state.dueProduct ? state.dueProduct.id : 0;
		state.productName = state.dueProduct ? state.dueProduct.name : '';
		openWith(function (dialog) {
			if (directToForm) {
				if (state.done) { alreadyStep(dialog); } else { formStep(dialog, firstName); }
			} else {
				inviteStep(dialog, firstName);
			}
		});
	}

	function boot() {
		bindButtons();
		if (!cfg.loggedIn) { return; }
		fetchPrompt().then(function () {
			if (state.due) {
				window.setTimeout(function () { open(state.name, false); }, 2200);
			}
		}).catch(function () {});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', boot, { once: true });
	} else {
		boot();
	}
})();
