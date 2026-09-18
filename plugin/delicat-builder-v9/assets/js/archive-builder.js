(() => {
	'use strict';

	const search = document.getElementById('delicat-archive-search');
	const picker = document.getElementById('delicat-archive-picker');

	if (search && picker) {
		const cards = Array.from(picker.querySelectorAll('[data-delicat-archive-card]'));
		search.addEventListener('input', () => {
			const q = String(search.value || '').toLocaleLowerCase().trim();
			for (const card of cards) {
				card.hidden = Boolean(q) && !String(card.dataset.search || '').includes(q);
			}
		}, { passive: true });
	}

	const form = document.getElementById('delicat-archive-form');
	const preview = document.getElementById('delicat-archive-preview');
	if (!form || !preview) return;

	const field = (name) => form.elements.namedItem(name);
	const value = (name, fallback = '') => field(name)?.value ?? fallback;
	const checked = (name) => Boolean(field(name)?.checked);
	const css = (name, val) => preview.style.setProperty(name, val);

	const sync = () => {
		const titleMode = value('config[title_mode]', 'dynamic');
		const title = titleMode === 'hidden'
			? ''
			: titleMode === 'custom'
				? value('config[custom_title]', '')
				: document.querySelector('.delicat-editor-topbar h1')?.textContent || 'Products';

		const titleEl = document.getElementById('delicat-archive-preview-title');
		const subtitleEl = document.getElementById('delicat-archive-preview-subtitle');
		if (titleEl) {
			titleEl.textContent = title;
			titleEl.hidden = !title;
		}
		if (subtitleEl) {
			subtitleEl.textContent = value('config[subtitle]', '') || 'Discover our products and instant services.';
		}

		css('--ab-bg', value('config[header_bg]', '#07091d'));
		css('--ab-border', value('config[header_border]', '#20254b'));
		css('--ab-title', value('config[title_color]', '#ffffff'));
		css('--ab-subtitle', value('config[subtitle_color]', '#9da4b8'));
		css('--ab-accent', value('config[accent_color]', '#6d5dfc'));
		css('--ab-radius', `${Math.max(0, Number(value('config[header_radius]', 26)) || 26)}px`);
		css('--ab-columns', String(Math.max(2, Math.min(6, Number(value('config[grid_desktop]', 4)) || 4))));
		css('--ab-gap', `${Math.max(4, Math.min(48, Number(value('config[grid_gap_d]', 22)) || 22))}px`);
		css('--ab-card-radius', `${Math.max(0, Math.min(36, Number(value('config[card_radius]', 20)) || 20))}px`);
		css('--ab-cta-radius', `${Math.max(8, Math.min(30, Number(value('config[cta_radius]', 18)) || 18))}px`);
		css('--ab-title-size', `${Math.max(12, Math.min(24, Number(value('config[title_size_d]', 15)) || 15))}px`);
		css('--ab-price-size', `${Math.max(12, Math.min(26, Number(value('config[price_size_d]', 16)) || 16))}px`);

		preview.dataset.cardStyle = value('config[card_style]', 'premium');
		preview.dataset.imageRatio = value('config[image_ratio]', 'square');
		preview.dataset.imageFit = value('config[image_fit]', 'cover');
		preview.classList.toggle('hide-header', !checked('config[show_header]'));
		preview.classList.toggle('hide-title', !checked('config[show_title]'));
		preview.classList.toggle('hide-price', !checked('config[show_price]'));
		preview.classList.toggle('hide-cta', !checked('config[show_cta]'));
		preview.classList.toggle('hide-tools', !checked('config[show_result_count]') && !checked('config[show_ordering]'));
		preview.classList.toggle('is-compact', checked('config[compact_mobile]'));
		for (const button of preview.querySelectorAll('.delicat-archive-preview__grid button')) {
			button.textContent = String(value('config[cta_label]', 'Acheter') || 'Acheter');
		}
	};

	form.addEventListener('input', sync, { passive: true });
	form.addEventListener('change', sync, { passive: true });
	sync();
})();
