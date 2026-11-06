(() => {
	'use strict';

	const root = document.getElementById('delicat-page-picker');
	const search = document.getElementById('delicat-page-search');
	const empty = document.getElementById('delicat-page-empty');
	const filters = Array.from(document.querySelectorAll('[data-delicat-page-filter]'));
	if (!root || !search) return;

	const cards = Array.from(root.querySelectorAll('[data-delicat-page-card]'));
	let activeFilter = 'all';

	const normalize = (value) => String(value || '').toLocaleLowerCase().trim();

	const apply = () => {
		const query = normalize(search.value);
		let visible = 0;

		for (const card of cards) {
			const text = normalize(card.dataset.search);
			const builder = card.dataset.builderStatus || 'disabled';
			const post = card.dataset.postStatus || 'published';

			const filterMatch =
				activeFilter === 'all'
				|| activeFilter === builder
				|| (activeFilter === 'draft' && post === 'draft');
			const searchMatch = !query || text.includes(query);
			const show = filterMatch && searchMatch;

			card.hidden = !show;
			if (show) visible += 1;
		}

		if (empty) empty.hidden = visible !== 0;
	};

	search.addEventListener('input', apply, { passive: true });

	for (const button of filters) {
		button.addEventListener('click', () => {
			activeFilter = button.dataset.delicatPageFilter || 'all';
			for (const item of filters) item.classList.toggle('is-active', item === button);
			apply();
		});
	}

	apply();
})();
