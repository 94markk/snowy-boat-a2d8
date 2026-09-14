/**
 * Product edit screen: the "Champs client" repeater. State lives in the
 * hidden textarea as JSON; the DOM is rebuilt from it and written back on
 * every change, so what is saved is exactly what is shown.
 */
(function () {
	'use strict';

	var box = document.querySelector('[data-ds-fields-admin]');
	if (!box) { return; }

	var list = box.querySelector('[data-ds-fields-list]');
	var store = box.querySelector('[data-ds-fields-json]');
	var template = box.querySelector('[data-ds-field-template]');
	var fields = [];

	try { fields = JSON.parse(store.value || '[]'); } catch (e) { fields = []; }
	if (!Array.isArray(fields)) { fields = []; }

	var PRESETS = {
		player_id: {
			id: 'player_id', label: 'Player ID', type: 'text', required: 1,
			placeholder: 'Ex. 123456789', pattern: '[0-9]{5,20}', min_length: 5, max_length: 20,
			description: 'Vérifiez bien : une recharge envoyée sur un mauvais identifiant n\'est pas récupérable.',
			help_text: 'Ouvrez le jeu, touchez votre avatar ou votre pseudo en haut de l\'écran : l\'identifiant (une suite de chiffres) s\'affiche sous votre nom.'
		},
		account_email: {
			id: 'account_email', label: 'E-mail du compte', type: 'email', required: 1,
			placeholder: 'nom@exemple.com',
			description: 'L\'abonnement sera activé sur ce compte.'
		},
		moncash: {
			id: 'moncash_number', label: 'Numéro MonCash', type: 'tel', required: 1,
			placeholder: '509 XX XX XX XX', pattern: '[0-9 +]{8,16}',
			description: 'Le numéro qui recevra ou enverra le transfert.'
		}
	};

	var TYPE_KEYS = {
		heading: ['label', 'id', 'type', 'description'],
		content: ['id', 'type', 'content'],
		hidden: ['label', 'id', 'type', 'default', 'price'],
		toggle: ['label', 'id', 'type', 'required', 'placeholder', 'description', 'price', 'help_text', 'help_image'],
		select: ['label', 'id', 'type', 'required', 'placeholder', 'description', 'options_text', 'price', 'help_text', 'help_image'],
		radio: ['label', 'id', 'type', 'required', 'description', 'options_text', 'price', 'help_text', 'help_image'],
		checkbox: ['label', 'id', 'type', 'required', 'description', 'options_text', 'price', 'help_text', 'help_image'],
		number: ['label', 'id', 'type', 'required', 'placeholder', 'description', 'min', 'price', 'default', 'help_text', 'help_image']
	};
	var TEXT_KEYS = ['label', 'id', 'type', 'required', 'placeholder', 'description', 'pattern', 'min_length', 'default', 'help_text', 'help_image'];

	function slug(value) {
		return String(value || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
	}
	function optionsToText(options) {
		return (options || []).map(function (o) {
			var parts = [o.value || '', o.label || o.value || ''];
			if (o.price) { parts.push(o.price); }
			return parts.join(' | ');
		}).join('\n');
	}
	function textToOptions(text) {
		return String(text || '').split('\n').map(function (line) {
			var parts = line.split('|').map(function (s) { return s.trim(); });
			if (!parts[0]) { return null; }
			return { value: parts[0], label: parts[1] || parts[0], price: parts[2] ? parseFloat(parts[2]) || 0 : 0 };
		}).filter(Boolean);
	}
	function save() {
		store.value = JSON.stringify(fields);
	}
	function applyVisibility(card, type) {
		var keys = TYPE_KEYS[type] || TEXT_KEYS;
		card.setAttribute('data-type', type);
		card.querySelectorAll('.ds-fa-grid > label').forEach(function (label) {
			var control = label.querySelector('[data-k]');
			var key = control ? control.getAttribute('data-k') : '';
			var visible = keys.indexOf(key) !== -1;
			if (key === 'min_length' || key === 'max_length') { visible = keys.indexOf('min_length') !== -1; }
			if (key === 'min' || key === 'max') { visible = keys.indexOf('min') !== -1; }
			if (key === 'price_type') { visible = keys.indexOf('price') !== -1; }
			label.style.display = visible ? '' : 'none';
		});
	}
	function render() {
		list.innerHTML = '';
		fields.forEach(function (field, index) {
			var node = template.content.firstElementChild.cloneNode(true);
			node.querySelector('[data-ds-field-title]').textContent = field.label || field.id || 'Champ ' + (index + 1);
			node.querySelectorAll('[data-k]').forEach(function (control) {
				var key = control.getAttribute('data-k');
				if (key === 'options_text') {
					control.value = optionsToText(field.options);
				} else if (control.type === 'checkbox') {
					control.checked = !!field[key];
				} else {
					control.value = field[key] === undefined || field[key] === null ? '' : field[key];
				}
				control.addEventListener('input', function () {
					if (key === 'options_text') {
						field.options = textToOptions(control.value);
					} else if (control.type === 'checkbox') {
						field[key] = control.checked ? 1 : 0;
					} else {
						field[key] = control.value;
					}
					if (key === 'label' && !field._idTouched) {
						field.id = slug(control.value);
						var idInput = node.querySelector('[data-k="id"]');
						if (idInput) { idInput.value = field.id; }
					}
					if (key === 'id') { field._idTouched = true; field.id = slug(control.value); }
					if (key === 'type') { applyVisibility(node, control.value); }
					node.querySelector('[data-ds-field-title]').textContent = field.label || field.id || 'Champ';
					save();
				});
			});
			applyVisibility(node, field.type || 'text');
			node.querySelector('[data-ds-remove]').addEventListener('click', function () {
				if (!window.confirm('Supprimer ce champ ?')) { return; }
				fields.splice(index, 1);
				save();
				render();
			});
			node.querySelectorAll('[data-ds-move]').forEach(function (button) {
				button.addEventListener('click', function () {
					var to = button.getAttribute('data-ds-move') === 'up' ? index - 1 : index + 1;
					if (to < 0 || to >= fields.length) { return; }
					fields.splice(to, 0, fields.splice(index, 1)[0]);
					save();
					render();
				});
			});
			var media = node.querySelector('[data-ds-media]');
			if (media) {
				media.addEventListener('click', function () {
					if (!window.wp || !window.wp.media) { return; }
					var frame = window.wp.media({ title: 'Image d\'aide', multiple: false, library: { type: 'image' } });
					frame.on('select', function () {
						var att = frame.state().get('selection').first().toJSON();
						field.help_image = att.url;
						node.querySelector('[data-k="help_image"]').value = att.url;
						save();
					});
					frame.open();
				});
			}
			list.appendChild(node);
		});
		save();
	}

	box.querySelector('[data-ds-add-field]').addEventListener('click', function () {
		fields.push({ id: '', label: '', type: 'text', required: 1 });
		render();
		var last = list.lastElementChild;
		if (last) { last.querySelector('[data-k="label"]').focus(); }
	});
	box.querySelectorAll('[data-ds-preset]').forEach(function (button) {
		button.addEventListener('click', function () {
			var preset = PRESETS[button.getAttribute('data-ds-preset')];
			if (!preset) { return; }
			var copy = JSON.parse(JSON.stringify(preset));
			copy._idTouched = true;
			fields.push(copy);
			var enabled = box.querySelector('[name="ds_fields_enabled"]');
			if (enabled) { enabled.checked = true; }
			render();
		});
	});

	render();
})();
