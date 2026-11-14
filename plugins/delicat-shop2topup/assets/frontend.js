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
		var data = Object.assign({
			action: historyConfig.action || 'dst2t_player_history',
			product_id: productId,
			nonce: historyConfig.nonce
		}, extra || {});
		return fetch(historyConfig.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: new URLSearchParams(data).toString()
		}).then(function (response) {
			return response.json();
		}).then(function (result) {
			if (!result || !result.success) throw new Error('history unavailable');
			return result.data || {};
		});
	}

	function verifyRequest(productId, requirements) {
		if (!verifyConfig || !verifyConfig.ajax || !verifyConfig.nonce) {
			return Promise.reject(new Error('verify unavailable'));
		}
		var params = new URLSearchParams();
		params.set('action', verifyConfig.action || 'dst2t_verify_player');
		params.set('nonce', verifyConfig.nonce);
		params.set('product_id', String(productId || ''));
		Object.keys(requirements || {}).forEach(function (key) {
			var value = requirements[key];
			if (Array.isArray(value)) {
				value.forEach(function (entry) { params.append('requirements[' + key + '][]', entry); });
			} else {
				params.append('requirements[' + key + ']', value == null ? '' : String(value));
			}
		});
		return fetch(verifyConfig.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: params.toString()
		}).then(function (response) {
			return response.json().then(function (json) {
				if (!response.ok || !json || !json.success) {
					var err = new Error((json && json.data && json.data.message) || 'verify failed');
					err.payload = json && json.data ? json.data : null;
					throw err;
				}
				return json.data || {};
			});
		});
	}

	function historyLabel(fields) {
		return Object.keys(fields || {}).map(function (key) { return fields[key]; }).filter(Boolean).join(' · ');
	}

	function initHistory(wrap) {
		if (!historyConfig || wrap.getAttribute('data-dst2t-history-ready') === '1') return;
		var productId = wrap.getAttribute('data-dst2t-history-product');
		if (!productId) return;
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
				wrap.querySelectorAll('[data-dst2t-field-key]').forEach(function (input) {
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
			if (firstField) firstField.before(box); else wrap.appendChild(box);
		}).catch(function () {
			wrap.removeAttribute('data-dst2t-history-ready');
		});
	}

	function getValues(wrap) {
		var values = {};
		wrap.querySelectorAll('[data-dst2t-field-key]').forEach(function (input) {
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
		box.replaceChildren();
	}

	function renderStatus(box, type, message, meta) {
		if (!box) return;
		box.className = 'dst2t-verify-status is-' + type;
		box.replaceChildren();

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
		var inputs = wrap.querySelectorAll('[data-dst2t-field-key]');
		inputs.forEach(function (input) {
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
				if (!wrap.isConnected || requestRevision !== revision || JSON.stringify(getValues(wrap)) !== JSON.stringify(values)) return;
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
				if (!wrap.isConnected || requestRevision !== revision) return;
				renderStatus(status, 'error', (error && error.payload && error.payload.message) || error.message || 'Impossible de vérifier le compte.', {});
			}).finally(function () {
				button.disabled = false;
				button.classList.remove('is-loading');
			});
		});
	}

	function scanHistory() {
		document.querySelectorAll('.dst2t-field-list[data-dst2t-history-product]').forEach(function (wrap) {
			initHistory(wrap);
			initVerify(wrap);
		});
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

			function paint(html, fallback) {
				var remembered = valuesFromTarget();
				var next = typeof html === 'string' ? html : '';
				if (!next && fallback) next = defaultHtml;
				$target.html(next).attr('aria-hidden', next ? 'false' : 'true');
				restoreValues(remembered);
				scanHistory();
			}

			$form.on('found_variation', function (_event, variation) {
				if (variation && variation.dst2t_enabled) {
					paint(variation.dst2t_fields_html || '', false);
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
		new MutationObserver(function () {
			if (!scheduled) {
				scheduled = true;
				setTimeout(function () { scheduled = false; scanHistory(); }, 100);
			}
		}).observe(document.documentElement, {childList: true, subtree: true});
	}
})(jQuery);
