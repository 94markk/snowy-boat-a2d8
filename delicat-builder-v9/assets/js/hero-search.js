(() => {
	'use strict';

	const normalize = (value) => {
		const raw = String(value || '');
		const folded = typeof raw.normalize === 'function' ? raw.normalize('NFD') : raw;
		return folded.replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
	};

	const init = (root) => {
		if (!(root instanceof Element) || root.dataset.delicatSearchReady === '1') return;
		const input = root.querySelector('[data-delicat-hero-search-input]');
		const results = root.querySelector('[data-delicat-hero-search-results]');
		const dataNode = root.querySelector('.delicat-builder-hero-search__data');
		if (!input || !results || !dataNode) return;

		let index = [];
		try {
			const parsed = JSON.parse(dataNode.textContent || '[]');
			index = Array.isArray(parsed) ? parsed : [];
		} catch (_) {
			index = [];
		}

		const minChars = Math.max(1, Math.min(4, Number(root.dataset.minChars || 2)));
		const resultLimit = Math.max(3, Math.min(8, Number(root.dataset.resultLimit || 5)));
		let active = -1;
		let current = [];
		let timer = 0;

		const close = () => {
			results.hidden = true;
			results.replaceChildren();
			active = -1;
			current = [];
			input.removeAttribute('aria-activedescendant');
		};

		const setActive = (next) => {
			const links = Array.from(results.querySelectorAll('[role="option"]'));
			if (!links.length) return;
			active = Math.max(0, Math.min(links.length - 1, next));
			links.forEach((link, i) => {
				link.classList.toggle('is-active', i === active);
				link.setAttribute('aria-selected', i === active ? 'true' : 'false');
			});
			const selected = links[active];
			if (selected?.id) input.setAttribute('aria-activedescendant', selected.id);
			selected?.scrollIntoView({ block: 'nearest' });
		};

		const rank = (item, query) => {
			const name = normalize(item?.name);
			if (!name) return 99;
			if (name === query) return 0;
			if (name.startsWith(query)) return 1;
			const words = name.split(/\s+/);
			if (words.some((word) => word.startsWith(query))) return 2;
			if (name.includes(query)) return 3;
			return 99;
		};

		const render = (query) => {
			const q = normalize(query);
			if (q.length < minChars || !index.length) {
				close();
				return;
			}

			current = index
				.map((item, position) => ({ item, position, score: rank(item, q) }))
				.filter((entry) => entry.score < 99)
				.sort((a, b) => a.score - b.score || a.position - b.position)
				.slice(0, resultLimit)
				.map((entry) => entry.item);

			results.replaceChildren();
			active = -1;

			if (!current.length) {
				const empty = document.createElement('div');
				empty.className = 'delicat-builder-hero-search__empty';
				empty.textContent = 'Aucun résultat rapide — appuyez sur Entrée pour rechercher toute la boutique.';
				results.append(empty);
				results.hidden = false;
				return;
			}

			current.forEach((item, i) => {
				const link = document.createElement('a');
				link.className = 'delicat-builder-hero-search__result';
				link.href = String(item.url || '#');
				link.id = `delicat-hero-search-result-${i}`;
				link.setAttribute('role', 'option');
				link.setAttribute('aria-selected', 'false');
				link.dataset.delicatPrefetch = '';

				if (item.image) {
					const img = document.createElement('img');
					img.src = String(item.image);
					img.alt = '';
					img.loading = 'lazy';
					img.decoding = 'async';
					img.width = 48;
					img.height = 48;
					link.append(img);
				} else {
					const placeholder = document.createElement('span');
					placeholder.className = 'delicat-builder-hero-search__result-placeholder';
					placeholder.textContent = '◫';
					link.append(placeholder);
				}

				const copy = document.createElement('span');
				copy.className = 'delicat-builder-hero-search__result-copy';
				const name = document.createElement('strong');
				name.textContent = String(item.name || '');
				copy.append(name);
				if (item.price) {
					const price = document.createElement('small');
					price.textContent = String(item.price);
					copy.append(price);
				}
				link.append(copy);

				const arrow = document.createElement('span');
				arrow.className = 'delicat-builder-hero-search__result-arrow';
				arrow.textContent = '→';
				link.append(arrow);
				results.append(link);
			});
			results.hidden = false;
		};

		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		results.id = results.id || `delicat-hero-search-results-${Math.random().toString(36).slice(2, 8)}`;
		input.setAttribute('aria-controls', results.id);

		input.addEventListener('input', () => {
			window.clearTimeout(timer);
			timer = window.setTimeout(() => {
				render(input.value);
				input.setAttribute('aria-expanded', results.hidden ? 'false' : 'true');
			}, 45);
		}, { passive: true });

		input.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				close();
				input.setAttribute('aria-expanded', 'false');
				return;
			}
			if (results.hidden || !current.length) return;
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				setActive(active < 0 ? 0 : active + 1);
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				setActive(active < 0 ? current.length - 1 : active - 1);
			} else if (event.key === 'Enter' && active >= 0 && current[active]?.url) {
				event.preventDefault();
				window.location.assign(String(current[active].url));
			}
		});

		root.addEventListener('focusout', () => {
			window.setTimeout(() => {
				if (!root.contains(document.activeElement)) {
					close();
					input.setAttribute('aria-expanded', 'false');
				}
			}, 90);
		});

		root.dataset.delicatSearchReady = '1';
	};

	const initAll = (scope = document) => {
		scope.querySelectorAll?.('[data-delicat-hero-search]').forEach(init);
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => initAll(), { once: true });
	} else {
		initAll();
	}
	document.addEventListener('delicat:navigation-complete', () => initAll());
})();
