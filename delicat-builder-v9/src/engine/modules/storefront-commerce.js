/**
 * Storefront commerce (non-native cart/checkout surfaces): checkout login
 * text normalisation, clear-cart button, swipe-to-reveal on cart rows.
 */
import { doc, on, qsa, win } from '../core/dom.js';
import { ajax } from '../core/net.js';

export default function mount({ root, signal, config }) {
	const body = doc.body;
	if (body.classList.contains('delicat-native-checkout')) {
		const scope = doc.querySelector('.delicat-native-page-main > .woocommerce') || doc.querySelector('.woocommerce');
		if (scope && scope.querySelector('.dip-wc-login-panel')) {
			body.classList.add('dcn-checkout-login-normalized');
			const walker = doc.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
			const nodes = [];
			while (walker.nextNode()) nodes.push(walker.currentNode);
			for (const node of nodes) if (node.nodeValue && node.nodeValue.indexOf('<span class="dc-native-login-required">') !== -1) node.nodeValue = 'Veuillez vous connecter pour finaliser votre commande.';
		}
	}

	const button = root.querySelector('[data-dcn-clear-cart]');
	const cfg = win.DelicatStorefrontCommerce || config.storefrontCommerce || {};
	if (button && cfg.clearEndpoint && cfg.clearNonce) {
		on(button, 'click', async () => {
			if (button.getAttribute('aria-busy') === 'true') return;
			if (cfg.clearConfirm && !win.confirm(cfg.clearConfirm)) return;
			button.setAttribute('aria-busy', 'true');
			button.disabled = true;
			try {
				await ajax(cfg.clearAction || 'delicat_builder_v9_clear_cart', { security: cfg.clearNonce }, { url: cfg.clearEndpoint });
				body.classList.add('dcn-cart-cleared');
				location.reload();
			} catch (_) {
				button.disabled = false;
				button.setAttribute('aria-busy', 'false');
				win.alert(cfg.clearError || 'Impossible de vider le panier. Réessayez.');
			}
		}, { signal });
	}

	if (body.classList.contains('delicat-native-cart') && win.matchMedia('(max-width: 820px)').matches) {
		let openRow = null;
		const close = (row) => { if (!row) return; row.classList.remove('is-dcn-open', 'is-dcn-dragging'); row.style.removeProperty('--dcn-swipe-x'); if (openRow === row) openRow = null; };
		for (const row of qsa('tr.cart_item', root)) {
			let startX = 0, startY = 0, dragging = false, beganOpen = false;
			on(row, 'pointerdown', (event) => {
				if (event.pointerType === 'mouse' && event.button !== 0) return;
				if (event.target.closest('a,button,input,select,textarea,label')) return;
				if (openRow && openRow !== row) close(openRow);
				startX = event.clientX; startY = event.clientY; beganOpen = row.classList.contains('is-dcn-open'); dragging = true;
				row.classList.add('is-dcn-dragging');
				if (row.setPointerCapture) row.setPointerCapture(event.pointerId);
			}, { signal });
			on(row, 'pointermove', (event) => {
				if (!dragging) return;
				const dx = event.clientX - startX, dy = event.clientY - startY;
				if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 8) { dragging = false; row.classList.remove('is-dcn-dragging'); row.style.removeProperty('--dcn-swipe-x'); return; }
				row.style.setProperty('--dcn-swipe-x', Math.max(-88, Math.min(0, dx + (beganOpen ? -88 : 0))) + 'px');
			}, { signal });
			const finish = (event) => {
				if (!dragging) return;
				dragging = false;
				row.classList.remove('is-dcn-dragging');
				const dx = event.clientX - startX + (beganOpen ? -88 : 0);
				row.style.removeProperty('--dcn-swipe-x');
				if (dx < -42) { row.classList.add('is-dcn-open'); openRow = row; } else close(row);
			};
			on(row, 'pointerup', finish, { signal });
			on(row, 'pointercancel', () => { dragging = false; row.classList.remove('is-dcn-dragging'); row.style.removeProperty('--dcn-swipe-x'); }, { signal });
		}
		on(doc, 'pointerdown', (event) => { if (openRow && !openRow.contains(event.target)) close(openRow); }, { capture: true, signal });
	}
}
