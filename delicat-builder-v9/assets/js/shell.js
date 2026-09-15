(() => {
	'use strict';

	const header = document.querySelector('[data-delicat-shell-header]');
	if (!header) return;

	const cfg = window.DelicaShellV9 || {};
	const body = document.body;
	const menuButton = header.querySelector('[data-delicat-menu-toggle]');
	const mobilePanel = header.querySelector('[data-delicat-mobile-panel]');
	const searchLayer = header.querySelector('[data-delicat-search-layer]');
	const searchOpen = [...header.querySelectorAll('[data-delicat-search-open]')];
	const searchClose = header.querySelector('[data-delicat-search-close]');
	const searchClear = header.querySelector('[data-delicat-search-clear]');
	const searchPopular = [...header.querySelectorAll('[data-delicat-search-term]')];
	const searchPopularWrap = header.querySelector('[data-delicat-search-popular]');
	const searchForm = header.querySelector('[data-delicat-search-form]');
	const searchInput = header.querySelector('#delicat-shell-search-input');
	const searchResults = header.querySelector('[data-delicat-search-results]');
	const searchStatus = header.querySelector('[data-delicat-search-status]');
	const searchRecent = header.querySelector('[data-delicat-search-recent]');
	const cartLayer = header.querySelector('[data-delicat-cart-layer]');
	const cartOpen = [...header.querySelectorAll('[data-delicat-cart-open]')];
	const cartClose = header.querySelector('[data-delicat-cart-close]');
	const cartBody = header.querySelector('[data-delicat-cart-body]');

	let lastSearchFocus = null;
	let lastCartFocus = null;
	let searchTimer = 0;
	let searchRequest = null;
	let searchSequence = 0;
	const searchCache = new Map();
	const searchCacheKey = String(cfg.searchCacheKey || 'delicat-builder-v9-search-cache');
	const searchCacheTtl = 15 * 60 * 1000;
	const requestTimeout = Math.max(2500, Math.min(8000, Number(cfg.requestTimeout) || 4500));
	let cartRequest = null;
	let cartSnapshot = null;
	let cartSnapshotAt = 0;

	const strings = Object.assign({
		loading: 'Chargement…',
		noResults: 'Aucun produit trouvé.',
		searchError: 'Recherche temporairement indisponible.',
		cartError: 'Impossible de charger le panier maintenant.',
		emptyCart: 'Votre panier est vide.',
		quantity: 'Qté',
		recent: 'Recherches récentes'
	}, cfg.strings || {});

	const isVisible = (node) => Boolean(node && !node.hidden);
	const syncModalState = () => {
		body.classList.toggle('delicat-shell-modal-open', isVisible(searchLayer) || isVisible(cartLayer));
	};

	const setMenu = (open) => {
		if (!menuButton || !mobilePanel) return;
		menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
		mobilePanel.hidden = !open;
	};

	const setCartCount = (value) => {
		const count = Math.max(0, Math.floor(Number(value) || 0));
		header.querySelectorAll('[data-delicat-cart-count]').forEach((node) => {
			node.textContent = String(count);
			node.hidden = count === 0;
		});
	};

	const wooCookieCount = () => {
		const match = document.cookie.match(/(?:^|;\s*)woocommerce_items_in_cart=([^;]*)/);
		if (!match) return null;
		const value = Number(decodeURIComponent(match[1] || '0'));
		return Number.isFinite(value) && value >= 0 ? Math.floor(value) : null;
	};

	const hydrateCartCountFromWoo = () => {
		const count = wooCookieCount();
		if (count !== null) setCartCount(count);
	};

	const clearNode = (node) => {
		while (node && node.firstChild) node.removeChild(node.firstChild);
	};

	const safeHref = (value, fallback = '#') => {
		try {
			const url = new URL(String(value || ''), window.location.href);
			if (url.protocol !== 'http:' && url.protocol !== 'https:') return fallback;
			return url.href;
		} catch (error) {
			return fallback;
		}
	};

	const postAction = async (action, values, signal) => {
		if (!cfg.ajaxUrl || !cfg.nonce) throw new Error('missing-config');
		const data = new URLSearchParams();
		data.set('action', action);
		data.set('nonce', String(cfg.nonce));
		Object.entries(values || {}).forEach(([key, value]) => data.set(key, String(value)));

		const response = await fetch(String(cfg.ajaxUrl), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: data.toString(),
			signal
		});
		if (!response.ok) throw new Error(`http-${response.status}`);
		const payload = await response.json();
		if (!payload || payload.success !== true) throw new Error('request-failed');
		return payload.data || {};
	};

	const normalizeQuery = (value) => String(value || '').trim().replace(/\s+/g, ' ').slice(0, 60);
	const queryKey = (value) => normalizeQuery(value).toLocaleLowerCase();
	const updateSearchControls = () => {
		const hasQuery = normalizeQuery(searchInput?.value || '').length > 0;
		if (searchClear) searchClear.hidden = !hasQuery;
		if (searchPopularWrap) searchPopularWrap.hidden = hasQuery;
	};

	/* RC55: recent searches live in a small first-party cookie (five terms, each
	   capped at 40 characters) and the result cache is memory-only for the life
	   of the document — no localStorage / sessionStorage, the storefront's
	   standing storage rule. */
	const RECENT_COOKIE = 'dbv9_rs';
	const readCookie = (name) => {
		const parts = document.cookie ? document.cookie.split(';') : [];
		for (let i = 0; i < parts.length; i++) {
			const pair = parts[i].replace(/^\s+/, '');
			if (pair.indexOf(name + '=') === 0) {
				try { return decodeURIComponent(pair.slice(name.length + 1)); } catch (_) { return ''; }
			}
		}
		return '';
	};
	const writeCookie = (name, value, maxAge) => {
		let cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax; max-age=' + maxAge;
		if (location.protocol === 'https:') cookie += '; Secure';
		document.cookie = cookie;
	};

	const getRecentSearches = () => {
		if (!cfg.recentKey) return [];
		try {
			const saved = JSON.parse(readCookie(RECENT_COOKIE) || '[]');
			return Array.isArray(saved) ? saved.filter((item) => typeof item === 'string' && item.trim()).map((item) => item.slice(0, 40)).slice(0, 5) : [];
		} catch (error) {
			return [];
		}
	};

	const saveRecentSearch = (query) => {
		const clean = normalizeQuery(query).slice(0, 40);
		if (!clean || !cfg.recentKey) return;
		const next = [clean, ...getRecentSearches().filter((item) => item.toLocaleLowerCase() !== clean.toLocaleLowerCase())].slice(0, 5);
		try {
			writeCookie(RECENT_COOKIE, JSON.stringify(next), 7776000);
		} catch (error) {}
	};

	const renderRecentSearches = () => {
		if (!searchRecent) return;
		clearNode(searchRecent);
		const recent = getRecentSearches();
		if (recent.length === 0 || normalizeQuery(searchInput?.value || '')) {
			searchRecent.hidden = true;
			return;
		}
		const label = document.createElement('strong');
		label.className = 'delicat-shell__recent-label';
		label.textContent = strings.recent;
		searchRecent.appendChild(label);
		const row = document.createElement('div');
		row.className = 'delicat-shell__recent-row';
		recent.forEach((query) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'delicat-shell__search-chip';
			button.textContent = query;
			button.addEventListener('click', () => {
				if (!searchInput) return;
				searchInput.value = query;
				updateSearchControls();
				searchInput.focus({ preventScroll: true });
				runLiveSearch(query, true);
			});
			row.appendChild(button);
		});
		searchRecent.appendChild(row);
		searchRecent.hidden = false;
	};

	const renderSearchResults = (items) => {
		if (!searchResults) return;
		clearNode(searchResults);
		if (!Array.isArray(items) || items.length === 0) {
			searchResults.hidden = true;
			return;
		}
		items.forEach((item) => {
			const link = document.createElement('a');
			link.className = 'delicat-shell__search-result';
			link.href = safeHref(item.url, '#');
			link.setAttribute('role', 'option');
			link.addEventListener('click', () => saveRecentSearch(searchInput?.value || item.name || ''));
			if (item.image) {
				const image = document.createElement('img');
				image.className = 'delicat-shell__search-result-image';
				image.src = safeHref(item.image, '');
				image.alt = '';
				image.loading = 'lazy';
				image.decoding = 'async';
				link.appendChild(image);
			}
			const copy = document.createElement('span');
			copy.className = 'delicat-shell__search-result-copy';
			const name = document.createElement('strong');
			name.textContent = String(item.name || '');
			copy.appendChild(name);
			if (item.category) {
				const category = document.createElement('small');
				category.textContent = String(item.category);
				copy.appendChild(category);
			}
			link.appendChild(copy);
			const meta = document.createElement('span');
			meta.className = 'delicat-shell__search-result-meta';
			if (item.price) {
				const price = document.createElement('strong');
				price.className = 'delicat-shell__search-result-price';
				price.textContent = String(item.price);
				meta.appendChild(price);
			}
			const stock = document.createElement('small');
			stock.textContent = item.in_stock === false ? 'Rupture de stock' : 'En stock';
			meta.appendChild(stock);
			link.appendChild(meta);
			searchResults.appendChild(link);
		});
		searchResults.hidden = false;
	};

	/* Memory-only: the Map above already holds every answer for this document.
	   Both hooks stay so the call sites are unchanged; keyed by searchCacheKey
	   for parity with the previous cache namespace. */
	const persistSearchCache = () => {
		try {
			if (searchCache.size > 24) {
				[...searchCache.keys()].slice(0, searchCache.size - 24).forEach((key) => searchCache.delete(key));
			}
		} catch (error) {}
	};

	const hydrateSearchCache = () => {
		try {
			if (!searchCacheKey) searchCache.clear();
		} catch (error) {}
	};

	const rememberSearch = (query, items) => {
		const key = queryKey(query);
		if (!key) return;
		searchCache.delete(key);
		searchCache.set(key, { at: Date.now(), items: Array.isArray(items) ? items : [] });
		while (searchCache.size > 24) searchCache.delete(searchCache.keys().next().value);
		persistSearchCache();
	};

	let localProductIndex = null;
	const buildLocalProductIndex = () => {
		const seen = new Set();
		const items = [];
		document.querySelectorAll('.delicat-product-card,[data-product-id].delicat-woo-product-card,li.product').forEach((card) => {
			const nameNode = card.querySelector('.delicat-product-card__name,.woocommerce-loop-product__title,h2,h3');
			const linkNode = nameNode?.closest?.('a[href]') || card.querySelector('a[data-delicat-product-link][href],a.woocommerce-LoopProduct-link[href],a[href*="/product/"]');
			const name = normalizeQuery(nameNode?.textContent || '');
			if (!name || !linkNode?.href) return;
			const url = safeHref(linkNode.href, '');
			if (!url || seen.has(url)) return;
			seen.add(url);
			const imageNode = card.querySelector('img');
			const priceNode = card.querySelector('.delicat-product-card__price strong,.price');
			/* pro.17: the topic badge is .delicat-badge--topic now. The old class is
			   kept in the list so a page still served from a cache written before
			   this release keeps its category in the search index. */
			const categoryNode = card.querySelector('.delicat-badge--topic .delicat-badge__text,.delicat-badge--topic,.delicat-product-card__tag,.delicat-archive-category');
			items.push({
				id: card.getAttribute('data-product-id') || url,
				name,
				url,
				image: imageNode?.currentSrc || imageNode?.src || '',
				price: normalizeQuery(priceNode?.textContent || ''),
				category: normalizeQuery(categoryNode?.textContent || ''),
				in_stock: !card.classList.contains('outofstock')
			});
		});
		localProductIndex = items;
		return items;
	};

	const instantLocalResults = (query) => {
		const key = queryKey(query);
		if (!key) return null;
		const items = Array.isArray(localProductIndex) ? localProductIndex : buildLocalProductIndex();
		const ranked = items
			.map((item) => {
				const name = String(item.name || '').toLocaleLowerCase();
				const category = String(item.category || '').toLocaleLowerCase();
				let score = 99;
				if (name === key) score = 0;
				else if (name.startsWith(key)) score = 1;
				else if (name.includes(key)) score = 2;
				else if (category.includes(key)) score = 3;
				return { item, score };
			})
			.filter((entry) => entry.score < 99)
			.sort((a, b) => a.score - b.score)
			.slice(0, Math.max(4, Number(cfg.searchLimit) || 6))
			.map((entry) => entry.item);
		return ranked.length ? ranked : null;
	};

	const instantCachedResults = (query) => {
		const key = queryKey(query);
		if (!key) return null;
		const exact = searchCache.get(key);
		if (exact && Date.now() - exact.at < searchCacheTtl) return exact.items;
		const unique = new Map();
		for (const entry of searchCache.values()) {
			if (!entry || !Array.isArray(entry.items)) continue;
			entry.items.forEach((item) => {
				const haystack = `${item?.name || ''} ${item?.category || ''}`.toLocaleLowerCase();
				if (haystack.includes(key) && item?.id != null) unique.set(String(item.id), item);
			});
		}
		return unique.size ? [...unique.values()].slice(0, Math.max(4, Number(cfg.searchLimit) || 6)) : null;
	};

	const resetSearchResults = () => {
		if (searchRequest) searchRequest.abort();
		searchRequest = null;
		if (searchTimer) window.clearTimeout(searchTimer);
		searchTimer = 0;
		searchSequence += 1;
		if (searchStatus) searchStatus.textContent = '';
		if (searchResults) {
			clearNode(searchResults);
			searchResults.hidden = true;
		}
		updateSearchControls();
		renderRecentSearches();
	};

	const runLiveSearch = async (rawQuery, force = false) => {
		if (!cfg.searchSuggestions || !searchResults || !searchStatus) return;
		const query = normalizeQuery(rawQuery);
		const minQuery = Math.max(2, Number(cfg.minQuery) || 2);
		if (query.length < minQuery) {
			resetSearchResults();
			return;
		}
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
		let timedOut = false;
		const timeoutId = window.setTimeout(() => {
			timedOut = true;
			controller.abort();
		}, requestTimeout);
		searchRequest = controller;
		searchRecent && (searchRecent.hidden = true);
		if (!cached) searchStatus.textContent = strings.loading;
		try {
			const data = await postAction('delicat_builder_v9_product_search', { q: query }, controller.signal);
			if (sequence !== searchSequence || normalizeQuery(searchInput?.value || '') !== query) return;
			const items = Array.isArray(data.results) ? data.results : [];
			rememberSearch(query, items);
			renderSearchResults(items);
			searchStatus.textContent = items.length ? '' : strings.noResults;
		} catch (error) {
			if (error && error.name === 'AbortError' && !timedOut) return;
			if (!cached) {
				searchStatus.textContent = strings.searchError;
				searchResults.hidden = true;
			}
		} finally {
			window.clearTimeout(timeoutId);
			if (searchRequest === controller) searchRequest = null;
		}
	};

	const queueLiveSearch = () => {
		if (!searchInput || !cfg.searchSuggestions) return;
		updateSearchControls();
		if (searchTimer) window.clearTimeout(searchTimer);
		const query = normalizeQuery(searchInput.value);
		if (query.length < Math.max(2, Number(cfg.minQuery) || 2)) {
			resetSearchResults();
			return;
		}
		const cached = instantCachedResults(query) || instantLocalResults(query);
		if (cached) {
			renderSearchResults(cached);
			if (searchStatus) searchStatus.textContent = cached.length ? '' : strings.noResults;
		}
		searchTimer = window.setTimeout(() => runLiveSearch(query), query.length <= 2 ? 90 : 60);
	};

	const setSearch = (open, restoreFocus = true) => {
		if (!searchLayer || searchOpen.length === 0) return;
		if (open && isVisible(cartLayer)) setCart(false, false);
		searchOpen.forEach((button) => {
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
			button.classList.toggle('is-active', open);
		});
		searchLayer.hidden = !open;
		syncModalState();
		if (open) {
			lastSearchFocus = document.activeElement;
			setMenu(false);
			updateSearchControls();
			renderRecentSearches();
			window.requestAnimationFrame(() => searchInput?.focus({ preventScroll: true }));
		} else {
			resetSearchResults();
			if (restoreFocus && lastSearchFocus instanceof HTMLElement) lastSearchFocus.focus({ preventScroll: true });
		}
	};

	document.addEventListener('delicat:navigation-complete', () => {
		localProductIndex = null;
	});

	const cartState = (message, className = '') => {
		if (!cartBody) return;
		clearNode(cartBody);
		const state = document.createElement('div');
		state.className = `delicat-shell__drawer-state ${className}`.trim();
		state.textContent = message;
		cartBody.appendChild(state);
	};

	const renderCart = (snapshot) => {
		if (!cartBody) return;
		clearNode(cartBody);
		const items = Array.isArray(snapshot.items) ? snapshot.items : [];
		setCartCount(snapshot.count || 0);

		if (items.length === 0) {
			const empty = document.createElement('div');
			empty.className = 'delicat-shell__drawer-empty';
			const title = document.createElement('strong');
			title.textContent = strings.emptyCart;
			empty.appendChild(title);
			if (snapshot.shopUrl) {
				const shop = document.createElement('a');
				shop.className = 'delicat-shell__drawer-primary';
				shop.href = safeHref(snapshot.shopUrl, '/');
				shop.textContent = 'Découvrir la boutique';
				empty.appendChild(shop);
			}
			cartBody.appendChild(empty);
			return;
		}

		const list = document.createElement('div');
		list.className = 'delicat-shell__drawer-items';
		items.forEach((item) => {
			const row = document.createElement(item.url ? 'a' : 'div');
			row.className = 'delicat-shell__drawer-item';
			if (item.url) row.href = safeHref(item.url, '#');

			if (item.image) {
				const image = document.createElement('img');
				image.src = safeHref(item.image, '');
				image.alt = '';
				image.loading = 'lazy';
				image.decoding = 'async';
				row.appendChild(image);
			}

			const copy = document.createElement('span');
			copy.className = 'delicat-shell__drawer-item-copy';
			const name = document.createElement('strong');
			name.textContent = String(item.name || '');
			copy.appendChild(name);
			const meta = document.createElement('small');
			meta.textContent = `${strings.quantity} ${Math.max(1, Number(item.quantity) || 1)}`;
			copy.appendChild(meta);
			row.appendChild(copy);
			const total = document.createElement('span');
			total.className = 'delicat-shell__drawer-item-total';
			total.textContent = String(item.lineTotal || '');
			row.appendChild(total);
			list.appendChild(row);
		});
		cartBody.appendChild(list);

		const summary = document.createElement('div');
		summary.className = 'delicat-shell__drawer-summary';
		const label = document.createElement('span');
		label.textContent = 'Sous-total';
		const subtotal = document.createElement('strong');
		subtotal.textContent = String(snapshot.subtotal || '');
		summary.append(label, subtotal);
		cartBody.appendChild(summary);

		const actions = document.createElement('div');
		actions.className = 'delicat-shell__drawer-actions';
		const cart = document.createElement('a');
		cart.className = 'delicat-shell__drawer-secondary';
		cart.href = safeHref(snapshot.cartUrl, '#');
		cart.textContent = 'Voir le panier';
		const checkout = document.createElement('a');
		checkout.className = 'delicat-shell__drawer-primary';
		checkout.href = safeHref(snapshot.checkoutUrl, cart.href);
		checkout.textContent = 'Commander';
		actions.append(cart, checkout);
		cartBody.appendChild(actions);
	};

	const loadCart = async (force = false) => {
		if (!cfg.cartDrawer || !cartBody) return;
		const fresh = cartSnapshot && (Date.now() - cartSnapshotAt < 8000);
		if (!force && fresh) {
			renderCart(cartSnapshot);
			return;
		}
		if (cartRequest) cartRequest.abort();
		const controller = new AbortController();
		let timedOut = false;
		const timeoutId = window.setTimeout(() => {
			timedOut = true;
			controller.abort();
		}, requestTimeout);
		cartRequest = controller;
		cartState(strings.loading, 'is-loading');
		try {
			const data = await postAction('delicat_builder_v9_cart_snapshot', {}, controller.signal);
			cartSnapshot = data;
			cartSnapshotAt = Date.now();
			renderCart(data);
		} catch (error) {
			if (error && error.name === 'AbortError' && !timedOut) return;
			cartState(strings.cartError, 'is-error');
		} finally {
			window.clearTimeout(timeoutId);
			if (cartRequest === controller) cartRequest = null;
		}
	};

	function setCart(open, restoreFocus = true) {
		if (!cartLayer || cartOpen.length === 0) return;
		if (open && isVisible(searchLayer)) setSearch(false, false);
		cartOpen.forEach((button) => {
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
			button.classList.toggle('is-active', open);
		});
		cartLayer.hidden = !open;
		syncModalState();

		if (open) {
			lastCartFocus = document.activeElement;
			setMenu(false);
			loadCart(false);
			window.requestAnimationFrame(() => cartClose?.focus({ preventScroll: true }));
		} else {
			if (cartRequest) cartRequest.abort();
			if (restoreFocus && lastCartFocus instanceof HTMLElement) lastCartFocus.focus({ preventScroll: true });
		}
	}

	const invalidateCart = () => {
		cartSnapshot = null;
		cartSnapshotAt = 0;
		hydrateCartCountFromWoo();
		if (isVisible(cartLayer)) loadCart(true);
	};

	menuButton?.addEventListener('click', () => setMenu(mobilePanel?.hidden !== false));
	searchOpen.forEach((button) => button.addEventListener('click', () => setSearch(true)));
	searchClose?.addEventListener('click', () => setSearch(false));
	searchClear?.addEventListener('click', () => {
		if (!searchInput) return;
		searchInput.value = '';
		resetSearchResults();
		searchInput.focus({ preventScroll: true });
	});
	searchPopular.forEach((button) => button.addEventListener('click', () => {
		if (!searchInput) return;
		const query = normalizeQuery(button.getAttribute('data-delicat-search-term') || button.textContent || '');
		searchInput.value = query;
		updateSearchControls();
		searchInput.focus({ preventScroll: true });
		runLiveSearch(query, true);
	}));
	let searchComposing = false;
	searchInput?.addEventListener('compositionstart', () => { searchComposing = true; }, { passive: true });
	searchInput?.addEventListener('compositionend', () => { searchComposing = false; queueLiveSearch(); }, { passive: true });
	searchInput?.addEventListener('input', () => { if (!searchComposing) queueLiveSearch(); }, { passive: true });
	searchForm?.addEventListener('submit', () => saveRecentSearch(searchInput?.value || ''));
	cartOpen.forEach((button) => button.addEventListener('click', () => setCart(true)));
	cartClose?.addEventListener('click', () => setCart(false));

	searchLayer?.addEventListener('click', (event) => {
		if (event.target === searchLayer) setSearch(false);
	});
	cartLayer?.addEventListener('click', (event) => {
		if (event.target === cartLayer) setCart(false);
	});

	document.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape') return;
		if (isVisible(searchLayer)) {
			setSearch(false);
			return;
		}
		if (isVisible(cartLayer)) {
			setCart(false);
			return;
		}
		if (mobilePanel && !mobilePanel.hidden) setMenu(false);
	});

	window.addEventListener('resize', () => {
		if (window.innerWidth > 960 && mobilePanel && !mobilePanel.hidden) setMenu(false);
	}, { passive: true });

	document.addEventListener('delicat:cart-count', (event) => {
		const count = Number(event.detail?.count);
		if (!Number.isFinite(count) || count < 0) return;
		setCartCount(count);
		cartSnapshot = null;
		cartSnapshotAt = 0;
	});

	if (window.jQuery && typeof window.jQuery === 'function') {
		window.jQuery(document.body).on('added_to_cart removed_from_cart updated_cart_totals wc_fragments_refreshed', invalidateCart);
	}

	hydrateSearchCache();
	hydrateCartCountFromWoo();
	renderRecentSearches();
})();
