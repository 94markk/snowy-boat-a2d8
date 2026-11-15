(function ($) {
	'use strict';

	var historyConfig = window.dst2t_history || null;
	var verifyConfig = window.dst2t_verify || null;

	var iconPaths = {
		history: '<path d="M13 3a9 9 0 1 0 9 9h-2a7 7 0 1 1-2.05-4.95L15 10h7V3l-2.64 2.64A8.96 8.96 0 0 0 13 3zm-1 5h2v5H9v-2h3V8z"/>',
		delete: '<path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zm2-10h8v10H8V9zm7.5-5-1-1h-5l-1 1H5v2h14V4z"/>',
		check_circle: '<path d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2zm-1.41 14.59L6 12l1.41-1.41 3.18 3.17 6-6L18 9.17l-7.41 7.42z"/>',
		error: '<path d="M12 2 1 21h22L12 2zm1 15h-2v-2h2v2zm0-4h-2V9h2v4z"/>',
		sync: '<path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.96-.69 2.79l1.46 1.46A7.94 7.94 0 0 0 20 12c0-4.42-3.58-8-8-8zm-6 6c0-1.01.25-1.96.69-2.79L5.23 5.75A7.94 7.94 0 0 0 4 10c0 4.42 3.58 8 8 8v3l4-4-4-4v3c-3.31 0-6-2.69-6-6z"/>',
		info: '<path d="M11 17h2v-6h-2v6zm1-15a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm-1-11h2V7h-2v2z"/>'
	};

	var GENERIC_ERROR = 'Impossible de vérifier le compte pour le moment. Vérifiez votre connexion puis réessayez.';
	var historyCache = {};
	// Saved IDs are fetched once the shopper starts using the purchase form (a
	// package, a field), not on every product view: each fetch is a full uncached
	// WordPress request, and most views never reach the Player ID step.
	var historyWanted = false;

	function each(list, fn) {
		Array.prototype.forEach.call(list || [], fn);
	}

	function emptyNode(node) {
		while (node && node.firstChild) node.removeChild(node.firstChild);
	}

	function isAttached(node) {
		return !!node && (typeof node.isConnected === 'boolean' ? node.isConnected : document.documentElement.contains(node));
	}

	function encodeForm(pairs) {
		return pairs.map(function (pair) {
			return encodeURIComponent(pair[0]) + '=' + encodeURIComponent(pair[1] == null ? '' : String(pair[1]));
		}).join('&');
	}

	// POST to admin-ajax and always resolve with {ok, status, json}. A body that is
	// not JSON (a host error page, WordPress's bare "-1") gives json === null or a
	// number instead of a parse exception, so callers can show a readable message.
	// Falls back to jQuery on WebViews without fetch().
	function postForm(url, body) {
		if (window.fetch) {
			return window.fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: body
			}).then(function (response) {
				return response.text().then(function (text) {
					var json = null;
					try { json = JSON.parse(text); } catch (e) { json = null; }
					return {ok: response.ok, status: response.status, json: json};
				});
			});
		}
		return new Promise(function (resolve, reject) {
			$.ajax({url: url, type: 'POST', data: body, cache: false, dataType: 'text'}).always(function (a, textStatus, b) {
				var xhr = textStatus === 'success' ? b : a;
				if (!xhr || !xhr.status) { reject(new Error('network')); return; }
				var json = null;
				try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
				resolve({ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, json: json});
			});
		});
	}

	function materialIcon(name, extraClass) {
		var span = document.createElement('span');
		span.className = 'dst2t-material-icon ' + (extraClass || '');
		span.setAttribute('aria-hidden', 'true');
		span.innerHTML = '<svg viewBox="0 0 24 24" focusable="false">' + (iconPaths[name] || iconPaths.info) + '</svg>';
		return span;
	}

	function historyRequest(productId, extra) {
		if (!historyConfig || !historyConfig.ajax || !historyConfig.nonce) {
			return Promise.reject(new Error('history unavailable'));
		}
		var key = String(productId);
		// Picking package after package re-renders the form; reuse the answer for a
		// product already asked about instead of another round trip each time.
		if (!extra && historyCache[key]) return historyCache[key];
		var pairs = [
			['action', historyConfig.action || 'dst2t_player_history'],
			['product_id', productId],
			['nonce', historyConfig.nonce]
		];
		Object.keys(extra || {}).forEach(function (name) { pairs.push([name, extra[name]]); });
		var request = postForm(historyConfig.ajax, encodeForm(pairs)).then(function (res) {
			if (!res.json || !res.json.success) throw new Error('history unavailable');
			return res.json.data || {};
		});
		if (extra) {
			historyCache = {};
		} else {
			// Failures are remembered too: a product without saved-ID support used to
			// be asked again after every DOM change. A verification or a clear resets it.
			historyCache[key] = request;
		}
		return request;
	}

	// Cached product pages can carry a nonce WordPress no longer accepts (it lives
	// 12-24 h, and for a guest with a cart it is tied to that cart's session).
	function refreshVerifyNonce() {
		if (!verifyConfig || !verifyConfig.refresh) return Promise.reject(new Error('refresh unavailable'));
		return postForm(verifyConfig.ajax, encodeForm([['action', verifyConfig.refresh]])).then(function (res) {
			if (res.json && res.json.success && res.json.data && res.json.data.nonce) {
				verifyConfig.nonce = res.json.data.nonce;
				return true;
			}
			throw new Error('refresh failed');
		});
	}

	function verifyRequest(productId, requirements, retried) {
		if (!verifyConfig || !verifyConfig.ajax || !verifyConfig.nonce) {
			return Promise.reject(new Error(GENERIC_ERROR));
		}
		var pairs = [
			['action', verifyConfig.action || 'dst2t_verify_player'],
			['nonce', verifyConfig.nonce],
			['product_id', String(productId || '')]
		];
		Object.keys(requirements || {}).forEach(function (key) {
			var value = requirements[key];
			if (Array.isArray(value)) {
				value.forEach(function (entry) { pairs.push(['requirements[' + key + '][]', entry]); });
			} else {
				pairs.push(['requirements[' + key + ']', value == null ? '' : String(value)]);
			}
		});
		return postForm(verifyConfig.ajax, encodeForm(pairs)).then(function (res) {
			var json = res.json;
			var data = json && typeof json === 'object' && json.data ? json.data : null;
			// WordPress answers a rejected nonce with a bare -1 (HTTP 403).
			if (!retried && !data && (res.status === 403 || json === -1 || json === 0)) {
				return refreshVerifyNonce().then(function () {
					return verifyRequest(productId, requirements, true);
				}, function () {
					throw new Error(GENERIC_ERROR);
				});
			}
			if (!res.ok || !json || !json.success) {
				var err = new Error((data && data.message) || GENERIC_ERROR);
				err.payload = data;
				throw err;
			}
			return data || {};
		}, function () {
			throw new Error(GENERIC_ERROR);
		});
	}

	function historyLabel(fields) {
		return Object.keys(fields || {}).map(function (key) { return fields[key]; }).filter(Boolean).join(' · ');
	}

	// Inserting the box above a field the shopper is typing in must not move that
	// field under their finger or the keyboard.
	function insertKeepingFocus(wrap, box, before) {
		var active = document.activeElement;
		var keep = active && active !== document.body && wrap.contains(active) ? active : null;
		var top = keep ? keep.getBoundingClientRect().top : 0;
		if (before && before.parentNode) before.parentNode.insertBefore(box, before); else wrap.appendChild(box);
		if (!keep) return;
		var delta = keep.getBoundingClientRect().top - top;
		if (Math.abs(delta) > 1) window.scrollBy(0, delta);
	}

	function initHistory(wrap) {
		if (!historyConfig || wrap.getAttribute('data-dst2t-history-ready') === '1') return;
		var productId = wrap.getAttribute('data-dst2t-history-product');
		if (!productId) return;
		if (!historyWanted && !historyCache[String(productId)]) return;
		wrap.setAttribute('data-dst2t-history-ready', '1');

		historyRequest(productId).then(function (data) {
			var items = Array.isArray(data.items) ? data.items : [];
			var spec = data.spec || {};
			var box = document.createElement('div');
			box.className = 'dst2t-player-history';

			var heading = document.createElement('div');
			heading.className = 'dst2t-player-history-heading';
			heading.appendChild(materialIcon('history', 'dst2t-history-heading-icon'));
			var headingText = document.createElement('span');
			headingText.textContent = 'Mes 5 derniers UID / ID Joueur';
			heading.appendChild(headingText);

			var control = document.createElement('div');
			control.className = 'dst2t-history-control';
			control.appendChild(materialIcon('history', 'dst2t-history-leading-icon'));

			var select = document.createElement('select');
			select.className = 'dst2t-player-history-select';
			select.setAttribute('aria-label', 'Choisir un UID / ID Joueur récent');
			var empty = document.createElement('option');
			empty.value = '';
			empty.textContent = items.length ? 'Choisir un UID / ID Joueur récent…' : 'Aucun UID / ID Joueur enregistré pour ce jeu';
			select.disabled = !items.length;
			select.appendChild(empty);

			items.forEach(function (item, index) {
				var option = document.createElement('option');
				option.value = String(index);
				option.textContent = historyLabel(item.fields || {});
				select.appendChild(option);
			});
			control.appendChild(select);

			var note = document.createElement('small');
			note.className = 'dst2t-player-history-note';
			note.textContent = 'Les 5 derniers comptes sont mémorisés après vérification ou ajout au panier.';

			select.addEventListener('change', function () {
				if (select.value === '') return;
				var item = items[Number(select.value)] || {fields: {}};
				each(wrap.querySelectorAll('[data-dst2t-field-key]'), function (input) {
					var key = input.getAttribute('data-dst2t-field-key');
					if (Object.prototype.hasOwnProperty.call(spec, key)) {
						var value = Object.prototype.hasOwnProperty.call(item.fields || {}, key) ? item.fields[key] : '';
						if (input.multiple) {
							Array.prototype.forEach.call(input.options, function (option) { option.selected = String(option.value) === String(value); });
						} else {
							input.value = value;
						}
						input.dispatchEvent(new Event('input', {bubbles: true}));
						input.dispatchEvent(new Event('change', {bubbles: true}));
					}
				});
				note.textContent = 'UID / ID Joueur renseigné. Vous pouvez maintenant vérifier votre compte.';
			});

			var clear = document.createElement('button');
			clear.type = 'button';
			clear.className = 'dst2t-player-history-clear';
			clear.hidden = !items.length;
			clear.appendChild(materialIcon('delete', 'dst2t-clear-icon'));
			var clearText = document.createElement('span');
			clearText.textContent = 'Effacer';
			clear.setAttribute('aria-label', 'Effacer les cinq identifiants enregistrés pour ce jeu');
			clear.appendChild(clearText);
			clear.addEventListener('click', function () {
				clear.disabled = true;
				historyRequest(productId, {clear: '1'}).then(function () {
					box.remove();
					wrap.removeAttribute('data-dst2t-history-ready');
					initHistory(wrap);
				}).catch(function () {
					note.textContent = 'Impossible d’effacer. Rechargez la page et réessayez.';
					clear.disabled = false;
				});
			});

			box.appendChild(heading);
			box.appendChild(control);
			box.appendChild(note);
			heading.appendChild(clear);
			var firstField = wrap.querySelector('.dst2t-field');
			if (!isAttached(wrap)) return;
			insertKeepingFocus(wrap, box, firstField);
		}).catch(function () {
			wrap.removeAttribute('data-dst2t-history-ready');
		});
	}

	function getValues(wrap) {
		var values = {};
		each(wrap.querySelectorAll('[data-dst2t-field-key]'), function (input) {
			var key = input.getAttribute('data-dst2t-field-key');
			if (!key) return;
			if (input.multiple) {
				values[key] = Array.prototype.filter.call(input.options, function (option) { return option.selected; }).map(function (option) { return option.value; });
			} else {
				values[key] = input.value || '';
			}
		});
		return values;
	}

	function clearStatus(box) {
		if (!box) return;
		box.className = 'dst2t-verify-status';
		emptyNode(box);
	}

	function renderStatus(box, type, message, meta) {
		if (!box) return;
		box.className = 'dst2t-verify-status is-' + type;
		emptyNode(box);

		var head = document.createElement('div');
		head.className = 'dst2t-verify-head';
		head.appendChild(materialIcon(type === 'success' ? 'check_circle' : (type === 'error' ? 'error' : (type === 'loading' ? 'sync' : 'info')), 'dst2t-verify-status-icon' + (type === 'loading' ? ' is-spin' : '')));
		var text = document.createElement('div');
		text.className = 'dst2t-verify-status-text';
		text.textContent = String(message || '');
		head.appendChild(text);
		box.appendChild(head);

		var chips = [];
		if (meta && meta.player_name) chips.push('Joueur vérifié : ' + meta.player_name);
		if (meta && meta.region) chips.push('Région : ' + meta.region);
		if (meta && meta.server) chips.push('Serveur : ' + meta.server);
		if (meta && meta.zone_id) chips.push('Zone : ' + meta.zone_id);
		if (chips.length) {
			var metaWrap = document.createElement('div');
			metaWrap.className = 'dst2t-verify-meta';
			chips.forEach(function (item) {
				var chip = document.createElement('span');
				chip.textContent = item;
				metaWrap.appendChild(chip);
			});
			box.appendChild(metaWrap);
		}
	}

	function initVerify(wrap) {
		if (!verifyConfig || wrap.getAttribute('data-dst2t-verify-ready') === '1') return;
		var button = wrap.querySelector('.dst2t-verify-button');
		var status = wrap.querySelector('.dst2t-verify-status');
		if (!button || !status) return;
		wrap.setAttribute('data-dst2t-verify-ready', '1');
		clearStatus(status);

		var revision = 0;
		function invalidateVerification() {
			revision++;
			var token = wrap.querySelector('.dst2t-verification-token');
			if (token) token.value = '';
			clearStatus(status);
		}
		each(wrap.querySelectorAll('[data-dst2t-field-key]'), function (input) {
			input.addEventListener('input', invalidateVerification);
			input.addEventListener('change', invalidateVerification);
		});

		button.addEventListener('click', function () {
			var productId = wrap.getAttribute('data-dst2t-history-product') || '';
			var values = getValues(wrap);
			var hasPlayer = false;
			Object.keys(values).forEach(function (key) {
				if (/(^player_id$|^uid$|^user_id$|^userid$|^playerid$)/.test(key) && String(values[key] || '').trim() !== '') {
					hasPlayer = true;
				}
			});
			if (!hasPlayer) {
				renderStatus(status, 'error', 'Veuillez saisir votre UID / ID Joueur avant la vérification.', {});
				return;
			}
			invalidateVerification();
			var requestRevision = revision;
			button.disabled = true;
			button.classList.add('is-loading');
			renderStatus(status, 'loading', 'Vérification du compte en cours…', {});
			verifyRequest(productId, values).then(function (data) {
				// Never display a stale response as verification of edited fields.
				if (!isAttached(wrap) || requestRevision !== revision || JSON.stringify(getValues(wrap)) !== JSON.stringify(values)) return;
				historyCache = {};
				wantHistory();
				var previousHistory = wrap.querySelector('.dst2t-player-history');
				if (previousHistory) previousHistory.remove();
				wrap.removeAttribute('data-dst2t-history-ready');
				initHistory(wrap);
				var token = wrap.querySelector('.dst2t-verification-token');
				if (token) token.value = data.verification_token || '';
				renderStatus(status, 'success', data.message || 'Compte vérifié avec succès.', {
					player_name: data.player_name || '',
					region: data.meta && data.meta.region ? data.meta.region : '',
					server: data.meta && data.meta.server ? data.meta.server : '',
					zone_id: data.meta && data.meta.zone_id ? data.meta.zone_id : ''
				});
			}).catch(function (error) {
				if (!isAttached(wrap) || requestRevision !== revision) return;
				renderStatus(status, 'error', (error && error.payload && error.payload.message) || (error && error.message) || GENERIC_ERROR, {});
			}).then(function () {
				// Not .finally(): it is missing from WebViews older than Chrome 63.
				button.disabled = false;
				button.classList.remove('is-loading');
			});
		});
	}

	function scanHistory() {
		each(document.querySelectorAll('.dst2t-field-list[data-dst2t-history-product]'), function (wrap) {
			initHistory(wrap);
			initVerify(wrap);
		});
	}

	function wantHistory() {
		historyWanted = true;
		document.removeEventListener('pointerdown', onHistoryIntent, true);
		document.removeEventListener('focusin', onHistoryIntent, true);
		document.removeEventListener('change', onHistoryIntent, true);
		document.removeEventListener('click', onShopperInput, true);
		document.removeEventListener('keydown', onShopperInput, true);
	}

	// First sign of intent: a tap or focus in the fields, or a package chosen in
	// the product form with a native change (a plain select, the V10 package cards).
	function onHistoryIntent(event) {
		if (historyWanted) return;
		var target = event.target;
		if (!target || !target.closest) return;
		var hit = target.closest('.dst2t-field-list[data-dst2t-history-product]');
		if (!hit && event.type !== 'pointerdown') {
			var form = target.closest('form.cart');
			hit = form && form.querySelector('.dst2t-field-list[data-dst2t-history-product]');
		}
		if (!hit) return;
		wantHistory();
		scanHistory();
	}

	// The Builder V9 package cards (dlc-914-swatches.js) and other custom selects
	// set the WooCommerce select through jQuery, which fires no native event. Their
	// pick shows up as the card's dsb:plan_selected and WooCommerce's
	// woocommerce_variation_select_change and found_variation. Those also fire for
	// a package the page picks by itself (WooCommerce default attributes, the
	// Builder's recommended card), so they count only once the shopper has tapped
	// or typed in that form: opening a product page still fetches nothing.
	var shopperForm = null;
	var shopperSeen = false;

	function onShopperInput(event) {
		if (event.isTrusted === false) return;
		shopperSeen = true;
		var target = event.target;
		var form = target && target.closest ? target.closest('form.cart') : null;
		if (form) shopperForm = form;
	}

	function shopperUsed(form) {
		if (shopperForm) return shopperForm === form;
		// Nothing seen yet: LiteSpeed starts delayed scripts on the first tap, and the
		// Builder replays a tap on a card as a synthetic click. The browser's sticky
		// activation still records that the shopper tapped.
		var activation = !shopperSeen && window.navigator ? window.navigator.userActivation : null;
		return !!(activation && activation.hasBeenActive);
	}

	function onPackageIntent(event) {
		if (!historyWanted) {
			var target = event.target;
			var form = target && target.closest ? target.closest('form.cart') : null;
			if (!form || !form.querySelector('.dst2t-requirements, .dst2t-field-list[data-dst2t-history-product]') || !shopperUsed(form)) return;
			wantHistory();
		}
		// found_variation comes after WooCommerce painted the chosen package's fields.
		// The other two come before it: that repaint then loads the saved IDs.
		if (event.type === 'found_variation') scanHistory();
	}

	if (historyConfig) {
		document.addEventListener('pointerdown', onHistoryIntent, true);
		document.addEventListener('focusin', onHistoryIntent, true);
		document.addEventListener('change', onHistoryIntent, true);
		document.addEventListener('click', onShopperInput, true);
		document.addEventListener('keydown', onShopperInput, true);
		document.addEventListener('dsb:plan_selected', onPackageIntent, true);
		// Bubbles here from the form after its own handlers, the repaint included.
		$(document).on('found_variation woocommerce_variation_select_change', onPackageIntent);
	}

	$(function () {
		var $form = $('form.variations_form');
		var $target = $('.dst2t-requirements[data-dst2t-variable="1"]');

		if ($form.length && $target.length) {
			var defaultHtml = $target.first().html() || '';

			function valuesFromTarget() {
				var values = {};
				$target.find('[data-dst2t-field-key]').each(function () {
					var key = this.getAttribute('data-dst2t-field-key');
					if (!key) return;
					if (this.multiple) {
						values[key] = Array.prototype.filter.call(this.options, function (option) { return option.selected; }).map(function (option) { return option.value; });
					} else {
						values[key] = this.value || '';
					}
				});
				return values;
			}

			function restoreValues(values) {
				$target.find('[data-dst2t-field-key]').each(function () {
					var key = this.getAttribute('data-dst2t-field-key');
					if (!key || !Object.prototype.hasOwnProperty.call(values, key)) return;
					var value = values[key];
					if (this.multiple && Array.isArray(value)) {
						Array.prototype.forEach.call(this.options, function (option) { option.selected = value.indexOf(option.value) !== -1; });
					} else {
						this.value = value;
					}
				});
			}

			function paint(html, fallback, productId) {
				var remembered = valuesFromTarget();
				var next = typeof html === 'string' ? html : '';
				if (!next && fallback) next = defaultHtml;
				$target.html(next).attr('aria-hidden', next ? 'false' : 'true');
				if (next && productId) $target.find('[data-dst2t-history-product]').attr('data-dst2t-history-product', String(productId));
				restoreValues(remembered);
				scanHistory();
			}

			// The server sends each distinct form once per variation list; the other
			// packages carry only its key (dst2t_fields_key) and their own id.
			function variationFieldsHtml(variation, form) {
				if (typeof variation.dst2t_fields_html === 'string') return variation.dst2t_fields_html;
				var key = variation.dst2t_fields_key;
				if (!key) return '';
				var list = $(form).data('product_variations');
				if (Array.isArray(list)) {
					for (var i = 0; i < list.length; i++) {
						if (list[i] && list[i].dst2t_fields_key === key && typeof list[i].dst2t_fields_html === 'string' && list[i].dst2t_fields_html) {
							return list[i].dst2t_fields_html;
						}
					}
				}
				// Never leave the shopper without the account fields; the server still
				// validates them against the chosen package.
				return defaultHtml;
			}

			$form.on('found_variation', function (_event, variation) {
				if (variation && variation.dst2t_enabled) {
					paint(variationFieldsHtml(variation, this), false, variation.dst2t_fields_id);
				} else {
					paint('', false);
				}
			});

			$form.on('reset_data hide_variation', function () {
				paint('', true);
			});
		}
		scanHistory();
	});

	if (window.MutationObserver) {
		var scheduled = false;
		// Only element insertions can bring a new form in. Sliders, timers and
		// text updates elsewhere on the page no longer trigger a document scan.
		new MutationObserver(function (mutations) {
			if (scheduled) return;
			for (var i = 0; i < mutations.length; i++) {
				var added = mutations[i].addedNodes;
				for (var j = 0; j < added.length; j++) {
					if (added[j].nodeType === 1) {
						scheduled = true;
						setTimeout(function () { scheduled = false; scanHistory(); }, 100);
						return;
					}
				}
			}
		}).observe(document.documentElement, {childList: true, subtree: true});
	}
})(jQuery);
