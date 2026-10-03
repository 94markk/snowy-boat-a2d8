/**
 * Header Studio 8 runtime: hamburger → drawer, cart badge from the WooCommerce
 * cookie / fragments, the header cart panel ([data-dsb8-cart-root]) with
 * swipe-to-remove rows, and overlay coordination. Port of dsb8-beta2-header.js
 * with the session store as the single source of the badge.
 */
import { closest, device, doc, emit, html, jq, on, onWoo, qsa, raf, win } from '../core/dom.js';
import { paintCartCount, wooCookieCount } from '../core/state.js';
import { ajax } from '../core/net.js';

const SWIPE = 82;

function syncFromFragment() {
	const cookieCount = wooCookieCount();
	if (cookieCount !== null) { paintCartCount(cookieCount); return true; }
	const source = doc.querySelector('.dsb8-woo-cart-count-fragment');
	if (source) { paintCartCount(source.getAttribute('data-count') || source.textContent); return true; }
	const fallback = doc.querySelector('.cart-contents-count,.mini-cart-count,.widget_shopping_cart .count');
	if (fallback) { paintCartCount(fallback.textContent); return true; }
	return false;
}

export default function mount({ signal }) {
	/* ---- hamburger ---- */
	on(doc, 'click', (event) => {
		const button = closest(event.target, '[data-dsb8-menu-trigger]');
		if (!button) return;
		if (doc.querySelector('[data-dlx-drawer]')) return; /* the drawer module owns this trigger */
		const legacy = doc.querySelector('.dsb-menu-toggle,[data-dsb-menu-toggle],.dsb-modern-menu-toggle');
		if (legacy && legacy !== button) { legacy.click(); return; }
		emit('dsb8:overlay:open', { id: 'modern-menu', trigger: button });
	}, { signal });

	/* ---- a header that stays put ----
	   9.3.4 keeps <body> free of overflow so the sticky header pins in every
	   engine. Should a browser still let it scroll away (iOS WebKit has had
	   its quirks with sticky), the first scroll past the header notices and
	   pins it as a fixed bar, the page padded by the bar's height so nothing
	   moves. Checked once per scroll frame while unpinned, never once pinned. */
	const stickyHeader = doc.querySelector('.dsb8-header.is-sticky, html.delicat-standalone .dsb8-header');
	if (stickyHeader) {
		let pinned = html.classList.contains('dsb8-header-pinned');
		let frame = 0;
		const measure = () => { html.style.setProperty('--dsb8-header-h', stickyHeader.offsetHeight + 'px'); };
		const pin = () => { pinned = true; measure(); html.classList.add('dsb8-header-pinned'); on(win, 'resize', measure, { passive: true, signal }); on(doc, 'delicat:navigated', () => win.setTimeout(measure, 50), { signal }); };
		const check = () => {
			frame = 0;
			if (pinned || (win.pageYOffset || 0) < 160) return;
			if (stickyHeader.getBoundingClientRect().top < -8) pin();
		};
		on(win, 'scroll', () => { if (!pinned && !frame) frame = raf(check); }, { passive: true, signal });
	}

	/* ---- installed app: a way back ---- */
	if (device.standalone) {
		const row = doc.querySelector('.dsb8-header__row');
		const homeHref = (doc.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="home"]') || doc.querySelector('.dsb8-header__brand') || {}).href || '/';
		let homePath = '/';
		try { homePath = new URL(homeHref, location.href).pathname.replace(/\/+$/, '') || '/'; } catch (_) {}
		let button = null;
		const syncBack = () => {
			const here = location.pathname.replace(/\/+$/, '') || '/';
			const show = here !== homePath;
			if (show && !button && row) {
				button = doc.createElement('button');
				button.type = 'button';
				button.className = 'dsb8-header__action dsb8-header__back';
				button.setAttribute('aria-label', 'Retour');
				button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
				on(button, 'click', () => { if (history.length > 1) history.back(); else location.assign(homeHref); }, { signal });
				row.insertBefore(button, row.firstChild);
			}
			if (button) button.hidden = !show;
			const header = doc.querySelector('.dsb8-header');
			if (header) header.classList.toggle('has-back', show && !!button);
		};
		syncBack();
		on(doc, 'delicat:navigated', syncBack, { signal });
		on(doc, 'delicat:placeholder', syncBack, { signal });
		on(win, 'popstate', () => win.setTimeout(syncBack, 0), { signal });
	}

	/* ---- cart badge ---- */
	syncFromFragment();
	on(win, 'pageshow', (event) => { syncFromFragment(); if (event.persisted) { const $ = jq(); if ($) $(doc.body).trigger('wc_fragment_refresh'); } }, { signal });
	onWoo('added_to_cart removed_from_cart updated_wc_div updated_cart_totals wc_fragments_loaded wc_fragments_refreshed', () => win.setTimeout(syncFromFragment, 0), signal);
	onWoo('added_to_cart', (event, fragments) => {
		if (fragments && fragments['.dsb8-woo-cart-count-fragment']) {
			const box = doc.createElement('div');
			box.innerHTML = fragments['.dsb8-woo-cart-count-fragment'];
			const node = box.firstElementChild;
			if (node) paintCartCount(node.getAttribute('data-count') || node.textContent);
		}
	}, signal);
	if (win.DSB8 && win.DSB8.emit) win.DSB8.emit('component:ready', { id: 'header-beta2' });

	/* ---- cart panel ---- */
	let wasOpen = false;
	let lastPageY = 0, vvTop = 0; /* read when the panel opens, never at mount: a layout read here forced the whole page to lay out before first paint */
	let refreshTimer = 0;
	const roots = () => qsa('[data-dsb8-cart-root]');
	const anyOpen = () => !!doc.querySelector('[data-dsb8-cart-root].is-open');
	/* The cart panel never locks page scroll. The old runtime also wiped every
	   inline body style here on resize and fragment refresh, which destroyed the
	   drawer's scroll lock while the menu was open; only the class is touched now. */
	const unlockScroll = () => { html.classList.remove('dsb8-cart-locked'); };
	const setOpen = (root, open) => {
		if (!root) return;
		root.classList.toggle('is-open', !!open);
		const btn = root.querySelector('[data-dsb8-cart-trigger]');
		if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		wasOpen = !!open;
		if (open) { lastPageY = win.pageYOffset || 0; vvTop = win.visualViewport ? (win.visualViewport.pageTop || 0) : 0; }
		unlockScroll();
	};
	const closeAll = (except) => {
		for (const r of roots()) if (r !== except && r.classList.contains('is-open')) r.classList.remove('is-open');
		for (const b of qsa('[data-dsb8-cart-trigger]')) { const r = b.closest('[data-dsb8-cart-root]'); if (r && !r.classList.contains('is-open')) b.setAttribute('aria-expanded', 'false'); }
		if (!anyOpen()) wasOpen = false;
		unlockScroll();
	};
	const resetRow = (wrap) => {
		if (!wrap) return;
		delete wrap.dataset.busy;
		wrap.classList.remove('is-removing', 'swipe-right');
		wrap.style.setProperty('--dsb8-cart-dx', '0px');
		for (const prop of ['opacity', 'maxHeight', 'marginTop', 'marginBottom']) wrap.style[prop] = '';
	};
	const collapseRow = (wrap, done) => {
		wrap.style.maxHeight = wrap.offsetHeight + 'px';
		wrap.style.overflow = 'hidden';
		wrap.classList.add('is-removed');
		raf(() => raf(() => { wrap.style.maxHeight = '0px'; wrap.style.marginTop = '0px'; wrap.style.marginBottom = '0px'; wrap.style.opacity = '0'; }));
		win.setTimeout(done, 240);
	};
	const showError = (root, message) => {
		const list = root.querySelector('.dsb8-cart-list');
		if (!list) return;
		const old = list.querySelector('.dsb8-cart-error');
		if (old) old.remove();
		const box = doc.createElement('div');
		box.className = 'dsb8-cart-error';
		box.textContent = message || 'Impossible de supprimer cet article.';
		list.insertBefore(box, list.firstChild);
		win.setTimeout(() => box.remove(), 3200);
	};
	const updateAfterRemove = (root, data) => {
		let count = parseInt(data && data.count, 10);
		if (!isFinite(count) || count < 0) count = 0;
		paintCartCount(count);
		if (data && data.nonce) root.dataset.nonce = data.nonce;
		const foot = root.querySelector('.dsb8-cart-panel__foot');
		if (count === 0) {
			const list = root.querySelector('.dsb8-cart-list');
			if (list) list.innerHTML = '<div class="dsb8-cart-empty">Votre panier est vide.</div>';
			if (foot) foot.remove();
			return;
		}
		if (foot && data && data.subtotal) {
			const total = foot.querySelector('strong');
			if (total) { total.innerHTML = data.subtotal; total.classList.add('is-updated'); win.setTimeout(() => total.classList.remove('is-updated'), 560); }
		}
	};
	const quietRefresh = (root, data) => {
		const $ = jq();
		win.clearTimeout(refreshTimer);
		refreshTimer = win.setTimeout(() => {
			if ($) { $(doc.body).trigger('removed_from_cart', [{}, '', null]); $(doc.body).trigger('dsb8_cart_updated', [data || {}, root || null]); }
			emit('delicat:cart-changed', { source: 'header-cart' });
		}, 40);
	};
	const retryAfterRefresh = (key) => {
		const $ = jq();
		if (!$) { location.reload(); return; }
		const run = () => {
			$(doc.body).off('wc_fragments_refreshed wc_fragments_loaded', run);
			win.setTimeout(() => {
				const wrap = doc.querySelector('.dsb8-cart-swipe[data-cart-key="' + key.replace(/"/g, '\\"') + '"]');
				const root = wrap && wrap.closest('[data-dsb8-cart-root]');
				if (root) removeItem(root, wrap, true);
			}, 60);
		};
		$(doc.body).on('wc_fragments_refreshed wc_fragments_loaded', run);
		$(doc.body).trigger('wc_fragment_refresh');
	};
	async function removeItem(root, wrap, retried) {
		if (!root || !wrap || wrap.dataset.busy === '1') return;
		const key = wrap.getAttribute('data-cart-key');
		if (!key) return;
		wrap.dataset.busy = '1';
		wrap.classList.add('is-removing');
		try {
			const data = await ajax('delicat_builder_v9_header_remove_cart_item', { nonce: root.dataset.nonce || '', cart_item_key: key }, { url: root.dataset.ajax });
			collapseRow(wrap, () => { wrap.remove(); updateAfterRemove(root, data || {}); quietRefresh(root, data || {}); });
		} catch (error) {
			const code = error && error.data && error.data.code;
			if (code === 'stale_nonce' && !retried) { resetRow(wrap); retryAfterRefresh(key); return; }
			resetRow(wrap);
			showError(root, error && error.data && error.data.message);
		}
	}
	const bindSwipe = (root, wrap) => {
		if (wrap.dataset.bound === '1') return;
		wrap.dataset.bound = '1';
		let sx = 0, sy = 0, dx = 0, drag = false, axis = '';
		const start = (x, y) => { sx = x; sy = y; dx = 0; drag = true; axis = ''; wrap.classList.add('is-dragging'); };
		const move = (x, y, event) => {
			if (!drag) return;
			const mx = x - sx, my = y - sy;
			if (!axis) { if (Math.abs(mx) < 7 && Math.abs(my) < 7) return; axis = Math.abs(mx) > Math.abs(my) ? 'x' : 'y'; if (axis === 'y') { drag = false; wrap.classList.remove('is-dragging'); return; } }
			dx = Math.max(-150, Math.min(150, mx));
			if (event && event.cancelable) event.preventDefault();
			wrap.style.setProperty('--dsb8-cart-dx', dx + 'px');
			wrap.classList.toggle('is-swiping', Math.abs(dx) > 2);
			wrap.classList.toggle('swipe-right', dx > 0);
		};
		const end = () => {
			if (!drag) return;
			drag = false;
			wrap.classList.remove('is-dragging');
			if (Math.abs(dx) >= SWIPE) { wrap.style.setProperty('--dsb8-cart-dx', dx < 0 ? '-120%' : '120%'); win.setTimeout(() => removeItem(root, wrap), 120); }
			else { wrap.style.setProperty('--dsb8-cart-dx', '0px'); wrap.classList.remove('is-swiping', 'swipe-right'); }
			dx = 0;
		};
		if (win.PointerEvent) {
			on(wrap, 'pointerdown', (event) => { if (closest(event.target, 'button,a')) return; if (event.pointerType === 'mouse' && event.button !== 0) return; start(event.clientX, event.clientY); }, { signal });
			on(wrap, 'pointermove', (event) => move(event.clientX, event.clientY, event), { passive: false, signal });
			on(wrap, 'pointerup', end, { signal });
			on(wrap, 'pointercancel', end, { signal });
		} else {
			on(wrap, 'touchstart', (event) => { if (closest(event.target, 'button,a')) return; const t = event.touches[0]; start(t.clientX, t.clientY); }, { passive: true, signal });
			on(wrap, 'touchmove', (event) => { const t = event.touches[0]; move(t.clientX, t.clientY, event); }, { passive: false, signal });
			on(wrap, 'touchend', end, { signal });
		}
	};
	const initAll = () => {
		for (const root of roots()) for (const wrap of qsa('.dsb8-cart-swipe', root)) bindSwipe(root, wrap);
		if (wasOpen && !anyOpen()) { const root = doc.querySelector('[data-dsb8-cart-root]'); if (root) setOpen(root, true); }
		unlockScroll();
	};

	on(doc, 'click', (event) => {
		const target = event.target;
		if (!target || !target.closest) return;
		const trigger = target.closest('[data-dsb8-cart-trigger]');
		if (trigger) {
			event.preventDefault(); event.stopPropagation();
			const root = trigger.closest('[data-dsb8-cart-root]');
			if (!root) return;
			const open = !root.classList.contains('is-open');
			closeAll(root);
			setOpen(root, open);
			return;
		}
		if (target.closest('.dsb8-cart-panel__close')) { event.preventDefault(); setOpen(target.closest('[data-dsb8-cart-root]'), false); return; }
		const button = target.closest('.dsb8-cart-item__remove');
		if (button) { event.preventDefault(); event.stopPropagation(); removeItem(button.closest('[data-dsb8-cart-root]'), button.closest('.dsb8-cart-swipe')); return; }
		if (!target.closest('[data-dsb8-cart-root]')) closeAll();
	}, { signal });
	on(doc, 'keydown', (event) => { if (event.key === 'Escape') closeAll(); }, { signal });
	on(win, 'resize', unlockScroll, { passive: true, signal });
	on(win, 'pagehide', unlockScroll, { signal });

	/* A real page scroll closes the panel; the list's own scrolling does not. */
	let pageScrollFrame = 0;
	on(win, 'scroll', () => {
		if (!wasOpen || pageScrollFrame) return;
		pageScrollFrame = raf(() => { pageScrollFrame = 0; if (!wasOpen) return; const y = win.pageYOffset || 0; if (Math.abs(y - lastPageY) > 10) closeAll(); lastPageY = y; });
	}, { passive: true, signal });
	let pageTouchY = null;
	on(doc, 'touchstart', (event) => { if (!wasOpen || !event.touches || !event.touches[0]) { pageTouchY = null; return; } if (closest(event.target, '.dsb8-cart-list')) { pageTouchY = null; return; } pageTouchY = event.touches[0].clientY; }, { passive: true, capture: true, signal });
	on(doc, 'touchmove', (event) => { if (pageTouchY === null || !wasOpen || !event.touches || !event.touches[0]) return; if (Math.abs(event.touches[0].clientY - pageTouchY) > 8) { closeAll(); pageTouchY = null; } }, { passive: true, capture: true, signal });
	on(doc, 'touchend', () => { pageTouchY = null; }, { passive: true, capture: true, signal });
	on(doc, 'touchcancel', () => { pageTouchY = null; }, { passive: true, capture: true, signal });
	on(win, 'wheel', (event) => { if (wasOpen && Math.abs(event.deltaY) > 2 && !closest(event.target, '.dsb8-cart-list')) closeAll(); }, { passive: true, capture: true, signal });
	if (win.visualViewport) {
		on(win.visualViewport, 'scroll', () => { const next = win.visualViewport.pageTop || 0; if (wasOpen && Math.abs(next - vvTop) > 6) closeAll(); vvTop = next; }, { passive: true, signal });
	}

	initAll();
	onWoo('wc_fragments_refreshed wc_fragments_loaded added_to_cart removed_from_cart', () => win.setTimeout(initAll, 0), signal);
	const headerRoot = doc.querySelector('[data-dsb8-header]');
	if (headerRoot && win.MutationObserver) {
		let pending = 0;
		const observer = new MutationObserver((records) => {
			for (const record of records) {
				if (!record.addedNodes.length && !record.removedNodes.length) continue;
				win.clearTimeout(pending);
				pending = win.setTimeout(initAll, 60);
				return;
			}
		});
		observer.observe(headerRoot, { childList: true, subtree: true });
		signal.addEventListener('abort', () => observer.disconnect(), { once: true });
	}

	/* ---- overlay coordination ---- */
	const closeHeaderPanels = () => {
		for (const root of qsa('[data-dsb8-cart-root].is-open')) { root.classList.remove('is-open'); const trigger = root.querySelector('[data-dsb8-cart-trigger]'); if (trigger) trigger.setAttribute('aria-expanded', 'false'); }
		for (const root of qsa('.dsb541-notifications.is-open')) root.classList.remove('is-open');
	};
	on(doc, 'dsb8:overlay:open', (event) => {
		if (!event.detail || event.detail.id !== 'modern-menu') return;
		closeHeaderPanels();
		const legacy = doc.querySelector('.dsb-menu-toggle,[data-dsb-menu-toggle],.dsb-modern-menu-toggle');
		if (legacy && !legacy.matches('[data-dsb8-menu-trigger]') && !doc.querySelector('[data-dlx-drawer]')) legacy.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: win }));
	}, { signal });
	on(doc, 'click', (event) => { if (closest(event.target, '[data-dsb8-menu-trigger]')) closeHeaderPanels(); }, { capture: true, signal });
	on(doc, 'delicat:navigate', () => closeAll(), { signal });
}
