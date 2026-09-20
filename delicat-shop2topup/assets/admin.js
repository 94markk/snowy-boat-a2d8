/* global dst2tAdmin */
(function () {
	'use strict';

	var config = window.dst2tAdmin || { strings: {} };
	var strings = config.strings || {};

	function onReady(fn) {
		if (document.readyState !== 'loading') {
			fn();
			return;
		}
		document.addEventListener('DOMContentLoaded', fn);
	}

	function selectAll() {
		var master = document.querySelector('.dst2t-select-all');
		if (!master) {
			return;
		}
		master.addEventListener('change', function () {
			Array.prototype.forEach.call(document.querySelectorAll('.dst2t-item-check'), function (checkbox) {
				checkbox.checked = master.checked;
			});
		});
	}

	// Identifiers stay masked until the operator asks for them.
	function revealers() {
		Array.prototype.forEach.call(document.querySelectorAll('.dst2t-reveal'), function (button) {
			button.addEventListener('click', function () {
				var holder = button.closest('.dst2t-secret');
				var target = holder ? holder.querySelector('.dst2t-secret-value') : null;
				if (!holder || !target) {
					return;
				}
				var full = holder.getAttribute('data-dst2t-secret') || '';
				var shown = holder.getAttribute('data-dst2t-shown') === '1';
				if (shown) {
					target.textContent = holder.getAttribute('data-dst2t-mask') || target.textContent;
					holder.setAttribute('data-dst2t-shown', '0');
					button.textContent = strings.reveal || 'Reveal';
					return;
				}
				holder.setAttribute('data-dst2t-mask', target.textContent);
				target.textContent = full;
				holder.setAttribute('data-dst2t-shown', '1');
				button.textContent = strings.hide || 'Hide';
			});
		});
	}

	function callbackUrl() {
		var field = document.querySelector('[data-dst2t-url]');
		var toggle = document.querySelector('[data-dst2t-toggle-url]');
		var copy = document.querySelector('[data-dst2t-copy-url]');
		if (!field) {
			return;
		}
		if (toggle) {
			toggle.addEventListener('click', function () {
				var hidden = field.getAttribute('type') === 'password';
				field.setAttribute('type', hidden ? 'text' : 'password');
				toggle.textContent = hidden ? (strings.hide || 'Hide') : (strings.reveal || 'Reveal');
			});
		}
		if (copy) {
			copy.addEventListener('click', function () {
				var done = function () {
					copy.textContent = strings.copied || 'Copied';
					window.setTimeout(function () {
						copy.textContent = strings.copy || 'Copy';
					}, 2000);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(field.value).then(done, function () {});
					return;
				}
				var type = field.getAttribute('type');
				field.setAttribute('type', 'text');
				field.select();
				try {
					document.execCommand('copy');
					done();
				} catch (error) {
					/* Clipboard is unavailable; the operator can still select the field. */
				}
				field.setAttribute('type', type);
			});
		}
	}

	function balance() {
		var targets = document.querySelectorAll('[data-dst2t-balance]');
		if (!targets.length || !config.ajaxUrl) {
			return;
		}
		var ages = document.querySelectorAll('[data-dst2t-balance-age]');
		var button = document.querySelector('[data-dst2t-refresh-balance]');
		var busy = false;

		function paint(payload) {
			Array.prototype.forEach.call(targets, function (node) {
				node.textContent = payload.formatted || '—';
			});
			Array.prototype.forEach.call(ages, function (node) {
				node.textContent = payload.age_label || '';
			});
			var card = document.querySelector('.dst2t-card-balance');
			if (card) {
				card.classList.toggle('is-low', !!payload.low);
			}
		}

		function fetchBalance(force) {
			if (busy) {
				return;
			}
			busy = true;
			if (force && button) {
				button.disabled = true;
				button.textContent = strings.refreshing || 'Refreshing…';
			}
			var body = new window.FormData();
			body.append('action', 'dst2t_balance');
			body.append('nonce', config.nonce);
			if (force) {
				body.append('force', '1');
			}
			window.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (response) { return response.json(); })
				.then(function (json) {
					if (json && json.success && json.data) {
						paint(json.data);
					}
				})
				.catch(function () {})
				.then(function () {
					busy = false;
					if (force && button) {
						button.disabled = false;
						button.textContent = strings.refresh || 'Refresh now';
					}
				});
		}

		if (button) {
			strings.refresh = button.textContent;
			button.addEventListener('click', function () {
				fetchBalance(true);
			});
		}

		if (config.auto && config.poll) {
			window.setInterval(function () {
				if (!document.hidden) {
					fetchBalance(false);
				}
			}, Math.max(30, parseInt(config.poll, 10)) * 1000);
		}
	}

	function confirmations() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-dst2t-confirm]'), function (form) {
			form.addEventListener('submit', function (event) {
				if (!window.confirm(form.getAttribute('data-dst2t-confirm'))) {
					event.preventDefault();
				}
			});
		});
	}

	onReady(function () {
		selectAll();
		confirmations();
		revealers();
		callbackUrl();
		balance();
	});
})();
