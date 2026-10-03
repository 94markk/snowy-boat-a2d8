/**
 * Hero search ([data-delicat-hero-search]): instant local results from the
 * JSON index the server prints, keyboard navigation, Enter opens the result.
 */
import { doc, foldAccents, on, qsa, win } from '../core/dom.js';

function init(root, signal, engine) {
	if (!(root instanceof Element) || root.dataset.delicatSearchReady === '1') return;
	const input = root.querySelector('[data-delicat-hero-search-input]');
	const results = root.querySelector('[data-delicat-hero-search-results]');
	const dataNode = root.querySelector('.delicat-builder-hero-search__data');
	if (!input || !results || !dataNode) return;
	let index = [];
	try { const parsed = JSON.parse(dataNode.textContent || '[]'); index = Array.isArray(parsed) ? parsed : []; } catch (_) { index = []; }
	const minChars = Math.max(1, Math.min(4, Number(root.dataset.minChars || 2)));
	const resultLimit = Math.max(3, Math.min(8, Number(root.dataset.resultLimit || 5)));
	let active = -1, current = [], timer = 0;

	const close = () => { results.hidden = true; results.replaceChildren(); active = -1; current = []; input.removeAttribute('aria-activedescendant'); input.setAttribute('aria-expanded', 'false'); };
	const setActive = (next) => {
		const links = qsa('[role="option"]', results);
		if (!links.length) return;
		active = Math.max(0, Math.min(links.length - 1, next));
		links.forEach((link, i) => { link.classList.toggle('is-active', i === active); link.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
		const selected = links[active];
		if (selected && selected.id) input.setAttribute('aria-activedescendant', selected.id);
		if (selected) selected.scrollIntoView({ block: 'nearest' });
	};
	const rank = (item, query) => {
		const name = foldAccents(item && item.name);
		if (!name) return 99;
		if (name === query) return 0;
		if (name.startsWith(query)) return 1;
		if (name.split(/\s+/).some((word) => word.startsWith(query))) return 2;
		if (name.includes(query)) return 3;
		return 99;
	};
	const render = (query) => {
		const q = foldAccents(query);
		if (q.length < minChars || !index.length) { close(); return; }
		current = index.map((item, position) => ({ item, position, score: rank(item, q) })).filter((entry) => entry.score < 99).sort((a, b) => a.score - b.score || a.position - b.position).slice(0, resultLimit).map((entry) => entry.item);
		results.replaceChildren();
		active = -1;
		if (!current.length) {
			const empty = doc.createElement('div');
			empty.className = 'delicat-builder-hero-search__empty';
			empty.textContent = 'Aucun résultat rapide — appuyez sur Entrée pour rechercher toute la boutique.';
			results.append(empty);
			results.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			return;
		}
		current.forEach((item, i) => {
			const link = doc.createElement('a');
			link.className = 'delicat-builder-hero-search__result';
			link.href = String(item.url || '#');
			link.id = `delicat-hero-search-result-${i}`;
			link.setAttribute('role', 'option');
			link.setAttribute('aria-selected', 'false');
			link.dataset.delicatPrefetch = '';
			link.dataset.delicatProductLink = '';
			if (item.image) {
				const img = doc.createElement('img');
				img.src = String(item.image); img.alt = ''; img.loading = 'lazy'; img.decoding = 'async'; img.width = 48; img.height = 48;
				link.append(img);
			} else {
				const placeholder = doc.createElement('span');
				placeholder.className = 'delicat-builder-hero-search__result-placeholder';
				placeholder.textContent = '◫';
				link.append(placeholder);
			}
			const copy = doc.createElement('span');
			copy.className = 'delicat-builder-hero-search__result-copy';
			const name = doc.createElement('strong');
			name.textContent = String(item.name || '');
			copy.append(name);
			if (item.price) { const price = doc.createElement('small'); price.textContent = String(item.price); copy.append(price); }
			link.append(copy);
			const arrow = doc.createElement('span');
			arrow.className = 'delicat-builder-hero-search__result-arrow';
			arrow.textContent = '→';
			link.append(arrow);
			results.append(link);
		});
		results.hidden = false;
		input.setAttribute('aria-expanded', 'true');
	};

	input.setAttribute('role', 'combobox');
	input.setAttribute('aria-autocomplete', 'list');
	input.setAttribute('aria-expanded', 'false');
	results.id = results.id || `delicat-hero-search-results-${Math.random().toString(36).slice(2, 8)}`;
	input.setAttribute('aria-controls', results.id);

	on(input, 'input', () => { win.clearTimeout(timer); timer = win.setTimeout(() => render(input.value), 45); }, { passive: true, signal });
	on(input, 'keydown', (event) => {
		if (event.key === 'Escape') { close(); return; }
		if (results.hidden || !current.length) return;
		if (event.key === 'ArrowDown') { event.preventDefault(); setActive(active < 0 ? 0 : active + 1); }
		else if (event.key === 'ArrowUp') { event.preventDefault(); setActive(active < 0 ? current.length - 1 : active - 1); }
		else if (event.key === 'Enter' && active >= 0 && current[active] && current[active].url) { event.preventDefault(); engine.nav ? engine.nav.navigate(String(current[active].url)) : location.assign(String(current[active].url)); }
	}, { signal });
	on(root, 'focusout', () => { win.setTimeout(() => { if (!root.contains(doc.activeElement)) close(); }, 90); }, { signal });
	root.dataset.delicatSearchReady = '1';
}

export default function mount({ root, signal, engine }) {
	for (const node of qsa('[data-delicat-hero-search]', root)) init(node, signal, engine);
}
