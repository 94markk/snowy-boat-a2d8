/**
 * Legacy app-shell header ([data-delicat-shell-header]): mobile panel, the
 * search layer with live suggestions and the cart drawer. Mounted only when
 * the Shell module renders this header; Header Studio owns the header
 * otherwise, so this is a lazy chunk.
 *
 * Behaviour kept from shell.js: recent searches in the dbv9_rs cookie (five
 * terms, 40 characters each), a memory-only result cache, instant results from
 * the cards already on the page, 8 s cart snapshot reuse.
 */
import { cookie, doc, el, on, qsa, raf, safeUrl, win } from '../core/dom.js';
import { ajax } from '../core/net.js';
import { wooCookieCount } from '../core/state.js';

const RECENT_COOKIE = 'dbv9_rs';
const SEARCH_TTL = 15 * 60 * 1000;

export default function mount({ signal, config }) {
	const header = doc.querySelector('[data-delicat-shell-header]');
	if (!header) return;
	const cfg = win.DelicaShellV9 || config.shell || {};
	const body = doc.body;
	const q = (selector) => header.querySelector(selector);
	const menuButton = q('[data-delicat-menu-toggle]');
	const mobilePanel = q('[data-delicat-mobile-panel]');
	const searchLayer = q('[data-delicat-search-layer]');
	const searchOpen = qsa('[data-delicat-search-open]', header);
	const searchClose = q('[data-delicat-search-close]');
	const searchClear = q('[data-delicat-search-clear]');
	const searchPopular = qsa('[data-delicat-search-term]', header);
	const searchPopularWrap = q('[data-delicat-search-popular]');
	const searchForm = q('[data-delicat-search-form]');
	const searchInput = q('#delicat-shell-search-input');
	const searchResults = q('[data-delicat-search-results]');
	const searchStatus = q('[data-delicat-search-status]');
	const searchRecent = q('[data-delicat-search-recent]');
	const cartLayer = q('[data-delicat-cart-layer]');
	const cartOpen = qsa('[data-delicat-cart-open]', header);
	const cartClose = q('[data-delicat-cart-close]');
	const cartBody = q('[data-delicat-cart-body]');

	const strings = Object.assign({
		loading: 'Chargement…', noResults: 'Aucun produit trouvé.', searchError: 'Recherche temporairement indisponible.',
		cartError: 'Impossible de charger le panier maintenant.', emptyCart: 'Votre panier est vide.', quantity: 'Qté', recent: 'Recherches récentes',
	}, cfg.strings || {});
	const requestTimeout = Math.max(2500, Math.min(8000, Number(cfg.requestTimeout) || 4500));
	const limit = Math.max(4, Number(cfg.searchLimit) || 6);

	let lastSearchFocus = null, lastCartFocus = null, searchTimer = 0, searchRequest = null, searchSequence = 0;
	let cartRequest = null, cartSnapshot = null, cartSnapshotAt = 0, localProductIndex = null;
	const searchCache = new Map();

	const isVisible = (node) => !!(node && !node.hidden);
	const syncModalState = () => body.classList.toggle('delicat-shell-modal-open', isVisible(searchLayer) || isVisible(cartLayer));
	const setMenu = (open) => { if (!menuButton || !mobilePanel) return; menuButton.setAttribute('aria-expanded', open ? 'true' : 'false'); mobilePanel.hidden = !open; };
	const setCartCount = (value) => {
		const count = Math.max(0, Math.floor(Number(value) || 0));
		for (const node of qsa('[data-delicat-cart-count]', header)) { node.textContent = String(count); node.hidden = count === 0; }
	};
	const hydrateCartCount = () => { const count = wooCookieCount(); if (count !== null) setCartCount(count); };
	const clearNode = (node) => { if (node) node.replaceChildren(); };
	const href = (value, fallback) => safeUrl(value) || fallback;
	const post = (action, values, requestSignal) => {
		if (!cfg.ajaxUrl || !cfg.nonce) return Promise.reject(new Error('missing-config'));
		return ajax(action, Object.assign({ nonce: String(cfg.nonce) }, values || {}), { url: String(cfg.ajaxUrl), signal: requestSignal, timeout: requestTimeout });
	};
	const normalizeQuery = (value) => String(value || '').trim().replace(/\s+/g, ' ').slice(0, 60);
	const queryKey = (value) => normalizeQuery(value).toLocaleLowerCase();
	const updateSearchControls = () => {
		const hasQuery = normalizeQuery(searchInput ? searchInput.value : '').length > 0;
		if (searchClear) searchClear.hidden = !hasQuery;
		if (searchPopularWrap) searchPopularWrap.hidden = hasQuery;
	};

	const recentSearches = () => {
		if (!cfg.recentKey) return [];
		try {
			const saved = JSON.parse(cookie.read(RECENT_COOKIE) || '[]');
			return Array.isArray(saved) ? saved.filter((item) => typeof item === 'string' && item.trim()).map((item) => item.slice(0, 40)).slice(0, 5) : [];
		} catch (_) { return []; }
	};
	const saveRecentSearch = (query) => {
		const clean = normalizeQuery(query).slice(0, 40);
		if (!clean || !cfg.recentKey) return;
		const next = [clean, ...recentSearches().filter((item) => item.toLocaleLowerCase() !== clean.toLocaleLowerCase())].slice(0, 5);
		cookie.write(RECENT_COOKIE, JSON.stringify(next), 7776000);
	};
	const renderRecentSearches = () => {
		if (!searchRecent) return;
		clearNode(searchRecent);
		const recent = recentSearches();
		if (!recent.length || normalizeQuery(searchInput ? searchInput.value : '')) { searchRecent.hidden = true; return; }
		searchRecent.appendChild(el('strong', 'delicat-shell__recent-label', strings.recent));
		const row = el('div', 'delicat-shell__recent-row');
		for (const query of recent) {
			const button = el('button', 'delicat-shell__search-chip', query);
			button.type = 'button';
			on(button, 'click', () => { if (!searchInput) return; searchInput.value = query; updateSearchControls(); searchInput.focus({ preventScroll: true }); runLiveSearch(query, true); }, { signal });
			row.appendChild(button);
		}
		searchRecent.appendChild(row);
		searchRecent.hidden = false;
	};

	const renderSearchResults = (items) => {
		if (!searchResults) return;
		clearNode(searchResults);
		if (!Array.isArray(items) || !items.length) { searchResults.hidden = true; return; }
		for (const item of items) {
			const link = el('a', 'delicat-shell__search-result');
			link.href = href(item.url, '#');
			link.setAttribute('role', 'option');
			on(link, 'click', () => saveRecentSearch((searchInput && searchInput.value) || item.name || ''), { signal });
			if (item.image) { const image = el('img', 'delicat-shell__search-result-image'); image.src = href(item.image, ''); image.alt = ''; image.loading = 'lazy'; image.decoding = 'async'; link.appendChild(image); }
			const copy = el('span', 'delicat-shell__search-result-copy');
			copy.appendChild(el('strong', '', String(item.name || '')));
			if (item.category) copy.appendChild(el('small', '', String(item.category)));
			link.appendChild(copy);
			const meta = el('span', 'delicat-shell__search-result-meta');
			if (item.price) meta.appendChild(el('strong', 'delicat-shell__search-result-price', String(item.price)));
			meta.appendChild(el('small', '', item.in_stock === false ? 'Rupture de stock' : 'En stock'));
			link.appendChild(meta);
			searchResults.appendChild(link);
		}
		searchResults.hidden = false;
	};

	const rememberSearch = (query, items) => {
		const key = queryKey(query);
		if (!key) return;
		searchCache.delete(key);
		searchCache.set(key, { at: Date.now(), items: Array.isArray(items) ? items : [] });
		while (searchCache.size > 24) searchCache.delete(searchCache.keys().next().value);
	};
	const buildLocalProductIndex = () => {
		const seen = new Set(), items = [];
		for (const card of qsa('.delicat-product-card,[data-product-id].delicat-woo-product-card,li.product')) {
			const nameNode = card.querySelector('.delicat-product-card__name,.woocommerce-loop-product__title,h2,h3');
			const linkNode = (nameNode && nameNode.closest('a[href]')) || card.querySelector('a[data-delicat-product-link][href],a.woocommerce-LoopProduct-link[href],a[href*="/product/"]');
			const name = normalizeQuery(nameNode ? nameNode.textContent : '');
			if (!name || !linkNode || !linkNode.href) continue;
			const url = safeUrl(linkNode.href);
			if (!url || seen.has(url)) continue;
			seen.add(url);
			const imageNode = card.querySelector('img');
			const priceNode = card.querySelector('.delicat-product-card__price strong,.price');
			const categoryNode = card.querySelector('.delicat-badge--topic .delicat-badge__text,.delicat-badge--topic,.delicat-product-card__tag,.delicat-archive-category');
			items.push({ id: card.getAttribute('data-product-id') || url, name, url, image: (imageNode && (imageNode.currentSrc || imageNode.src)) || '', price: normalizeQuery(priceNode ? priceNode.textContent : ''), category: normalizeQuery(categoryNode ? categoryNode.textContent : ''), in_stock: !card.classList.contains('outofstock') });
		}
		localProductIndex = items;
		return items;
	};
	const instantLocalResults = (query) => {
		const key = queryKey(query);
		if (!key) return null;
		const items = Array.isArray(localProductIndex) ? localProductIndex : buildLocalProductIndex();
		const ranked = items.map((item) => {
			const name = String(item.name || '').toLocaleLowerCase(), category = String(item.category || '').toLocaleLowerCase();
			let score = 99;
			if (name === key) score = 0; else if (name.startsWith(key)) score = 1; else if (name.includes(key)) score = 2; else if (category.includes(key)) score = 3;
			return { item, score };
		}).filter((entry) => entry.score < 99).sort((a, b) => a.score - b.score).slice(0, limit).map((entry) => entry.item);
		return ranked.length ? ranked : null;
	};
	const instantCachedResults = (query) => {
		const key = queryKey(query);
		if (!key) return null;
		const exact = searchCache.get(key);
		if (exact && Date.now() - exact.at < SEARCH_TTL) return exact.items;
		const unique = new Map();
		for (const entry of searchCache.values()) {
			if (!entry || !Array.isArray(entry.items)) continue;
			for (const item of entry.items) {
				const haystack = `${(item && item.name) || ''} ${(item && item.category) || ''}`.toLocaleLowerCase();
				if (haystack.includes(key) && item && item.id != null) unique.set(String(item.id), item);
			}
		}
		return unique.size ? [...unique.values()].slice(0, limit) : null;
	};
	const resetSearchResults = () => {
		if (searchRequest) searchRequest.abort();
		searchRequest = null;
		win.clearTimeout(searchTimer);
		searchTimer = 0;
		searchSequence += 1;
		if (searchStatus) searchStatus.textContent = '';
		if (searchResults) { clearNode(searchResults); searchResults.hidden = true; }
		updateSearchControls();
		renderRecentSearches();
	};
	const runLiveSearch = async (rawQuery, force = false) => {
		if (!cfg.searchSuggestions || !searchResults || !searchStatus) return;
		const query = normalizeQuery(rawQuery);
		if (query.length < Math.max(2, Number(cfg.minQuery) || 2)) { resetSearchResults(); return; }
		const cached = instantCachedResults(query) || instantLocalResults(query);
		if (cached) {
			renderSearchResults(cached);
			searchStatus.textContent = cached.length ? '' : strings.noResults;
			const exact = searchCache.get(queryKey(query));
			if (!force && exact && Date.now() - exact.at < 30000) return;
		}
		if (searchRequest) searchRequest.abort();
		const controller = new AbortController();
		const sequence = ++searchSequence;
		searchRequest = controller;
		if (searchRecent) searchRecent.hidden = true;
		if (!cached) searchStatus.textContent = strings.loading;
		try {
			const data = await post('delicat_builder_v9_product_search', { q: query }, controller.signal);
			if (sequence !== searchSequence || normalizeQuery(searchInput ? searchInput.value : '') !== query) return;
			const items = Array.isArray(data.results) ? data.results : [];
			rememberSearch(query, items);
			renderSearchResults(items);
			searchStatus.textContent = items.length ? '' : strings.noResults;
		} catch (error) {
			if (error && error.name === 'AbortError' && sequence !== searchSequence) return;
			if (!cached) { searchStatus.textContent = strings.searchError; searchResults.hidden = true; }
		} finally {
			if (searchRequest === controller) searchRequest = null;
		}
	};
	const queueLiveSearch = () => {
		if (!searchInput || !cfg.searchSuggestions) return;
		updateSearchControls();
		win.clearTimeout(searchTimer);
		const query = normalizeQuery(searchInput.value);
		if (query.length < Math.max(2, Number(cfg.minQuery) || 2)) { resetSearchResults(); return; }
		const cached = instantCachedResults(query) || instantLocalResults(query);
		if (cached) { renderSearchResults(cached); if (searchStatus) searchStatus.textContent = cached.length ? '' : strings.noResults; }
		searchTimer = win.setTimeout(() => runLiveSearch(query), query.length <= 2 ? 90 : 60);
	};

	const setSearch = (open, restoreFocus = true) => {
		if (!searchLayer || !searchOpen.length) return;
		if (open && isVisible(cartLayer)) setCart(false, false);
		for (const button of searchOpen) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); button.classList.toggle('is-active', open); }
		searchLayer.hidden = !open;
		syncModalState();
		if (open) {
			lastSearchFocus = doc.activeElement;
			setMenu(false);
			updateSearchControls();
			renderRecentSearches();
			raf(() => { if (searchInput) searchInput.focus({ preventScroll: true }); });
		} else {
			resetSearchResults();
			if (restoreFocus && lastSearchFocus instanceof HTMLElement) lastSearchFocus.focus({ preventScroll: true });
		}
	};

	const cartState = (message, className = '') => { if (!cartBody) return; clearNode(cartBody); cartBody.appendChild(el('div', `delicat-shell__drawer-state ${className}`.trim(), message)); };
	const renderCart = (snapshot) => {
		if (!cartBody) return;
		clearNode(cartBody);
		const items = Array.isArray(snapshot.items) ? snapshot.items : [];
		setCartCount(snapshot.count || 0);
		if (!items.length) {
			const empty = el('div', 'delicat-shell__drawer-empty');
			empty.appendChild(el('strong', '', strings.emptyCart));
			if (snapshot.shopUrl) { const shop = el('a', 'delicat-shell__drawer-primary', 'Découvrir la boutique'); shop.href = href(snapshot.shopUrl, '/'); empty.appendChild(shop); }
			cartBody.appendChild(empty);
			return;
		}
		const list = el('div', 'delicat-shell__drawer-items');
		for (const item of items) {
			const row = el(item.url ? 'a' : 'div', 'delicat-shell__drawer-item');
			if (item.url) row.href = href(item.url, '#');
			if (item.image) { const image = el('img'); image.src = href(item.image, ''); image.alt = ''; image.loading = 'lazy'; image.decoding = 'async'; row.appendChild(image); }
			const copy = el('span', 'delicat-shell__drawer-item-copy');
			copy.appendChild(el('strong', '', String(item.name || '')));
			copy.appendChild(el('small', '', `${strings.quantity} ${Math.max(1, Number(item.quantity) || 1)}`));
			row.appendChild(copy);
			row.appendChild(el('span', 'delicat-shell__drawer-item-total', String(item.lineTotal || '')));
			list.appendChild(row);
		}
		cartBody.appendChild(list);
		const summary = el('div', 'delicat-shell__drawer-summary');
		summary.append(el('span', '', 'Sous-total'), el('strong', '', String(snapshot.subtotal || '')));
		cartBody.appendChild(summary);
		const actions = el('div', 'delicat-shell__drawer-actions');
		const cart = el('a', 'delicat-shell__drawer-secondary', 'Voir le panier');
		cart.href = href(snapshot.cartUrl, '#');
		const checkout = el('a', 'delicat-shell__drawer-primary', 'Commander');
		checkout.href = href(snapshot.checkoutUrl, cart.href);
		actions.append(cart, checkout);
		cartBody.appendChild(actions);
	};
	const loadCart = async (force = false) => {
		if (!cfg.cartDrawer || !cartBody) return;
		if (!force && cartSnapshot && Date.now() - cartSnapshotAt < 8000) { renderCart(cartSnapshot); return; }
		if (cartRequest) cartRequest.abort();
		const controller = new AbortController();
		cartRequest = controller;
		cartState(strings.loading, 'is-loading');
		try {
			const data = await post('delicat_builder_v9_cart_snapshot', {}, controller.signal);
			cartSnapshot = data;
			cartSnapshotAt = Date.now();
			renderCart(data);
		} catch (error) {
			if (error && error.name === 'AbortError' && cartRequest !== controller) return;
			cartState(strings.cartError, 'is-error');
		} finally {
			if (cartRequest === controller) cartRequest = null;
		}
	};
	function setCart(open, restoreFocus = true) {
		if (!cartLayer || !cartOpen.length) return;
		if (open && isVisible(searchLayer)) setSearch(false, false);
		for (const button of cartOpen) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); button.classList.toggle('is-active', open); }
		cartLayer.hidden = !open;
		syncModalState();
		if (open) {
			lastCartFocus = doc.activeElement;
			setMenu(false);
			loadCart(false);
			raf(() => { if (cartClose) cartClose.focus({ preventScroll: true }); });
		} else {
			if (cartRequest) cartRequest.abort();
			if (restoreFocus && lastCartFocus instanceof HTMLElement) lastCartFocus.focus({ preventScroll: true });
		}
	}
	const invalidateCart = () => { cartSnapshot = null; cartSnapshotAt = 0; hydrateCartCount(); if (isVisible(cartLayer)) loadCart(true); };

	if (menuButton) on(menuButton, 'click', () => setMenu(!!(mobilePanel && mobilePanel.hidden)), { signal });
	for (const button of searchOpen) on(button, 'click', () => setSearch(true), { signal });
	if (searchClose) on(searchClose, 'click', () => setSearch(false), { signal });
	if (searchClear) on(searchClear, 'click', () => { if (!searchInput) return; searchInput.value = ''; resetSearchResults(); searchInput.focus({ preventScroll: true }); }, { signal });
	for (const button of searchPopular) on(button, 'click', () => {
		if (!searchInput) return;
		const query = normalizeQuery(button.getAttribute('data-delicat-search-term') || button.textContent || '');
		searchInput.value = query;
		updateSearchControls();
		searchInput.focus({ preventScroll: true });
		runLiveSearch(query, true);
	}, { signal });
	let composing = false;
	if (searchInput) {
		on(searchInput, 'compositionstart', () => { composing = true; }, { passive: true, signal });
		on(searchInput, 'compositionend', () => { composing = false; queueLiveSearch(); }, { passive: true, signal });
		on(searchInput, 'input', () => { if (!composing) queueLiveSearch(); }, { passive: true, signal });
	}
	if (searchForm) on(searchForm, 'submit', () => saveRecentSearch(searchInput ? searchInput.value : ''), { signal });
	for (const button of cartOpen) on(button, 'click', () => setCart(true), { signal });
	if (cartClose) on(cartClose, 'click', () => setCart(false), { signal });
	if (searchLayer) on(searchLayer, 'click', (event) => { if (event.target === searchLayer) setSearch(false); }, { signal });
	if (cartLayer) on(cartLayer, 'click', (event) => { if (event.target === cartLayer) setCart(false); }, { signal });
	on(doc, 'keydown', (event) => {
		if (event.key !== 'Escape') return;
		if (isVisible(searchLayer)) { setSearch(false); return; }
		if (isVisible(cartLayer)) { setCart(false); return; }
		if (mobilePanel && !mobilePanel.hidden) setMenu(false);
	}, { signal });
	on(win, 'resize', () => { if (win.innerWidth > 960 && mobilePanel && !mobilePanel.hidden) setMenu(false); }, { passive: true, signal });
	on(doc, 'delicat:navigation-complete', () => { localProductIndex = null; }, { signal });
	on(doc, 'delicat:cart-count', (event) => {
		const count = Number(event.detail && event.detail.count);
		if (!Number.isFinite(count) || count < 0) return;
		setCartCount(count);
		cartSnapshot = null;
		cartSnapshotAt = 0;
	}, { signal });
	on(doc, 'delicat:cart-changed', invalidateCart, { signal });
	const $ = win.jQuery;
	if (typeof $ === 'function') {
		$(doc.body).on('added_to_cart.dlcShell removed_from_cart.dlcShell updated_cart_totals.dlcShell wc_fragments_refreshed.dlcShell', invalidateCart);
		signal.addEventListener('abort', () => { try { $(doc.body).off('.dlcShell'); } catch (_) {} }, { once: true });
	}
	hydrateCartCount();
	renderRecentSearches();
}
