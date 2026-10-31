(() => {
	'use strict';

	const choose = document.querySelector('[data-shell-logo-choose]');
	const clear = document.querySelector('[data-shell-logo-clear]');
	const input = document.querySelector('[data-shell-logo-id]');
	const preview = document.querySelector('[data-shell-logo-preview]');

	choose?.addEventListener('click', () => {
		if (!window.wp?.media || !input) return;
		const frame = wp.media({
			title: 'Choose shell logo',
			button: { text: 'Use logo' },
			multiple: false,
			library: { type: 'image' }
		});
		frame.on('select', () => {
			const item = frame.state().get('selection').first()?.toJSON();
			if (!item?.id) return;
			input.value = String(item.id);
			if (preview) {
				const src = item.sizes?.medium?.url || item.sizes?.thumbnail?.url || item.url || '';
				preview.replaceChildren();
				if (src) {
					const image = document.createElement('img');
					image.src = String(src);
					image.alt = '';
					preview.appendChild(image);
				}
			}
		});
		frame.open();
	});

	clear?.addEventListener('click', () => {
		if (input) input.value = '0';
		if (preview) preview.textContent = '';
	});
})();