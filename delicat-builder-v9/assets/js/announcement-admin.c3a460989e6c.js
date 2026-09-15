/* Announcement Studio — media picker, model selector and live preview. ES5. */
(function () {
	'use strict';

	var MODELS = ['flash', 'aurora', 'midnight', 'minimal', 'boutique', 'spotlight'];

	function byId(id) {
		return document.getElementById(id);
	}

	function value(id, fallback) {
		var node = byId(id);
		return node ? node.value : fallback;
	}

	function checkedByName(suffix) {
		var nodes = document.querySelectorAll('input[name$="[' + suffix + ']"]');
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].checked) {
				return true;
			}
		}
		return false;
	}

	function refreshSelectedCards() {
		var cards = document.querySelectorAll('[data-dbv9-annc-model-card]');
		for (var i = 0; i < cards.length; i++) {
			var radio = cards[i].querySelector('input[type="radio"]');
			if (radio && radio.checked) {
				cards[i].classList.add('is-selected');
			} else {
				cards[i].classList.remove('is-selected');
			}
		}
	}

	function setModel(root, model) {
		for (var i = 0; i < MODELS.length; i++) {
			root.classList.remove('dbv9-annc--' + MODELS[i]);
		}
		if (MODELS.indexOf(model) === -1) {
			model = 'aurora';
		}
		root.classList.add('dbv9-annc--' + model);
	}

	function fillPreview(root, model) {
		setModel(root, model);
		root.style.setProperty('--dbv9-annc-accent', value('dbv9-annc-accent-field', '#ff5a1f') || '#ff5a1f');

		var title = root.querySelector('[data-dbv9-annc-preview-title]');
		var text = root.querySelector('[data-dbv9-annc-preview-text]');
		var emoji = root.querySelector('[data-dbv9-annc-preview-emoji]');
		var media = root.querySelector('[data-dbv9-annc-preview-media]');
		var mediaImg = media ? media.querySelector('img') : null;
		var primary = root.querySelector('[data-dbv9-annc-preview-primary]');
		var secondary = root.querySelector('[data-dbv9-annc-preview-secondary]');
		var imageUrl = value('dbv9-annc-image-field', '');
		var emojiValue = value('dbv9-annc-emoji-field', '🎉');
		var titleValue = value('dbv9-annc-title-field', 'Bienvenue sur Délicat Store');
		var textValue = value('dbv9-annc-message-field', '');
		var primaryValue = value('dbv9-annc-plabel', 'Voir l’offre');
		var secondaryValue = value('dbv9-annc-slabel', 'Plus tard');

		if (title) {
			title.textContent = titleValue;
			title.hidden = !titleValue;
		}
		if (text) {
			text.textContent = textValue;
			text.hidden = !textValue;
		}
		if (imageUrl && media && mediaImg) {
			mediaImg.src = imageUrl;
			media.hidden = false;
			if (emoji) emoji.hidden = true;
		} else {
			if (media) media.hidden = true;
			if (emoji) {
				emoji.textContent = emojiValue || '🎉';
				emoji.hidden = !emojiValue;
			}
		}
		if (primary) {
			primary.textContent = primaryValue || 'Voir l’offre';
			primary.hidden = !primaryValue;
		}
		if (secondary) {
			secondary.textContent = secondaryValue || 'Plus tard';
			secondary.hidden = !secondaryValue || !checkedByName('show_secondary');
		}
	}

	function openPreview(model) {
		var root = byId('dbv9-annc-admin-preview');
		if (!root) return;
		fillPreview(root, model);
		root.hidden = false;
		root.classList.remove('is-closing');
		document.body.classList.add('dbv9-annc-admin-preview-open');
		if (window.requestAnimationFrame) {
			window.requestAnimationFrame(function () {
				window.requestAnimationFrame(function () {
					root.classList.add('is-open');
				});
			});
		} else {
			root.classList.add('is-open');
		}
	}

	function closePreview() {
		var root = byId('dbv9-annc-admin-preview');
		if (!root || root.hidden) return;
		root.classList.remove('is-open');
		root.classList.add('is-closing');
		document.body.classList.remove('dbv9-annc-admin-preview-open');
		window.setTimeout(function () {
			root.classList.remove('is-closing');
			root.hidden = true;
		}, 260);
	}

	function setupModels() {
		var radios = document.querySelectorAll('[data-dbv9-annc-model-card] input[type="radio"]');
		for (var i = 0; i < radios.length; i++) {
			radios[i].addEventListener('change', refreshSelectedCards);
		}
		var cards = document.querySelectorAll('[data-dbv9-annc-model-card]');
		for (var c = 0; c < cards.length; c++) {
			cards[c].addEventListener('click', function (event) {
				if (event.target && (event.target.closest('button') || event.target.tagName === 'INPUT')) return;
				var radio = this.querySelector('input[type="radio"]');
				if (radio && !radio.checked) {
					radio.checked = true;
					refreshSelectedCards();
				}
			});
		}
		refreshSelectedCards();

		var previews = document.querySelectorAll('[data-dbv9-annc-preview]');
		for (var j = 0; j < previews.length; j++) {
			previews[j].addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				openPreview(this.getAttribute('data-dbv9-annc-preview') || 'aurora');
			});
		}

		var closers = document.querySelectorAll('[data-dbv9-annc-admin-close]');
		for (var k = 0; k < closers.length; k++) {
			closers[k].addEventListener('click', function (event) {
				event.preventDefault();
				closePreview();
			});
		}

		document.addEventListener('keydown', function (event) {
			if ((event.key === 'Escape' || event.key === 'Esc' || event.keyCode === 27) && !byId('dbv9-annc-admin-preview').hidden) {
				event.preventDefault();
				closePreview();
			}
		});
	}

	function setupMedia() {
		var field = byId('dbv9-annc-image-field');
		var pick = byId('dbv9-annc-image-pick');
		var clear = byId('dbv9-annc-image-clear');

		if (!field) return;

		if (clear) {
			clear.addEventListener('click', function () {
				field.value = '';
			});
		}

		if (!pick) return;
		if (!window.wp || !window.wp.media) {
			pick.style.display = 'none';
			return;
		}

		var frame = null;
		pick.addEventListener('click', function (event) {
			event.preventDefault();
			if (!frame) {
				frame = window.wp.media({
					title: 'Choisir une image pour l’annonce',
					button: { text: 'Utiliser cette image' },
					library: { type: 'image' },
					multiple: false
				});
				frame.on('select', function () {
					var selection = frame.state().get('selection').first();
					if (!selection) return;
					var data = selection.toJSON();
					var url = data.url || '';
					if (data.sizes && data.sizes.large && data.sizes.large.url) {
						url = data.sizes.large.url;
					}
					field.value = url;
				});
			}
			frame.open();
		});
	}

	function ready() {
		setupMedia();
		setupModels();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready);
	} else {
		ready();
	}
})();
