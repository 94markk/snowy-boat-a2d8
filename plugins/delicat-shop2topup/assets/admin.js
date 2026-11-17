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
			// A request that never settles would leave `busy` stuck and stop polling
			// for the rest of the page's life, so every call is given a deadline.
			var options = { method: 'POST', credentials: 'same-origin', body: body };
			var timer = null;
			if (window.AbortController) {
				var controller = new window.AbortController();
				options.signal = controller.signal;
				timer = window.setTimeout(function () { controller.abort(); }, 20000);
			}
			window.fetch(config.ajaxUrl, options)
				.then(function (response) { return response.json(); })
				.then(function (json) {
					if (json && json.success && json.data) {
						paint(json.data);
					}
				})
				.catch(function () {})
				.then(function () {
					if (timer) {
						window.clearTimeout(timer);
					}
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

	function catalogImportProgress() {
		var box = document.querySelector('[data-dst2t-import-job]');
		if (!box || !config.ajaxUrl || !config.importNonce) return;
		var jobId = box.getAttribute('data-dst2t-import-job');
		var status = box.querySelector('[data-dst2t-import-status]');
		var count = box.querySelector('[data-dst2t-import-count]');
		var progress = box.querySelector('[data-dst2t-import-progress]');
		var summary = box.querySelector('[data-dst2t-import-summary]');
		var stopped = false;
		var busy = false;

		function paint(data) {
			if (!data) return;
			if (status) status.textContent = String(data.status || '').replace(/_/g, ' ');
			if (count) count.textContent = String(data.processed || 0) + ' / ' + String(data.total || 0);
			if (progress) progress.value = parseInt(data.percent || 0, 10);
			if (summary) summary.textContent = 'Created ' + String(data.created || 0) + ' · Updated ' + String(data.updated || 0) + ' · Failed ' + String(data.failed || 0);
			if (['complete', 'complete_with_errors', 'failed'].indexOf(data.status) !== -1) stopped = true;
		}

		function request(action) {
			if (busy || stopped || document.hidden) return;
			busy = true;
			var body = new window.FormData();
			body.append('action', action);
			body.append('nonce', config.importNonce);
			body.append('job_id', jobId);
			window.fetch(config.ajaxUrl, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body})
				.then(function (response) { return response.json(); })
				.then(function (json) { if (json && json.success && json.data) paint(json.data); })
				.catch(function () {})
				.then(function () { busy = false; });
		}

		// Advance one batch immediately. This is intentionally an authenticated AJAX
		// action rather than a public loopback request, so cron-disabled hosts work too.
		request('dst2t_import_step');
		window.setInterval(function () {
			request(stopped ? 'dst2t_import_status' : 'dst2t_import_step');
		}, 1800);
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

	function recoveryAssist() {
		var label = document.querySelector('[data-dst2t-recovery-status]');
		if (!label || !config.recoveryNonce || !config.ajaxUrl) return;
		var busy = false;
		function step() {
			if (busy || document.hidden) return;
			busy = true;
			var body = new window.FormData();
			body.append('action', 'dst2t_recovery_step');
			body.append('nonce', config.recoveryNonce);
			window.fetch(config.ajaxUrl, {method:'POST', credentials:'same-origin', cache:'no-store', body:body})
				.then(function(response) { return response.json(); })
				.then(function(json) {
					if (!json || !json.success) throw new Error('recovery');
					label.textContent = json.data && json.data.error ? 'Récupération : ' + json.data.error : 'Passage de récupération effectué. Actualisez la liste pour voir les états.';
				})
				.catch(function() { label.textContent = 'Récupération indisponible. Actualisez la page et vérifiez le planificateur.'; })
				.then(function() { busy = false; });
		}
		step();
		window.setInterval(step, 60000);
	}

	onReady(function () {
		recoveryAssist();
		selectAll();
		confirmations();
		revealers();
		callbackUrl();
		balance();
		catalogImportProgress();
	});
})();
