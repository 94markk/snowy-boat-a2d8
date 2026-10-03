/**
 * Wallet balance guard on checkout: when the wallet cannot cover the order
 * and the wallet is the chosen (or only) route, stop the submit before
 * WooCommerce sees it and show the recharge sheet.
 */
import { closest, doc, on, raf, tryFocus, win } from '../core/dom.js';
import { registerOverlay, releaseOverlay } from '../core/overlay.js';

export default function mount({ signal }) {
	const body = doc.body;
	if (!body.classList.contains('dpn-checkout') && !body.classList.contains('dnp-express-enabled')) return;
	const guard = () => doc.querySelector('[data-dpn-wallet-guard]');
	const modal = () => doc.querySelector('[data-dpn-wallet-modal]');
	const walletRoute = () => {
		const checked = doc.querySelector('input[name="payment_method"]:checked');
		if (checked) return /wallet/i.test(String(checked.value || ''));
		return !doc.querySelector('input[name="payment_method"]');
	};
	const setText = (root, selector, value) => { const node = root.querySelector(selector); if (node) node.textContent = value; };
	let closeTimer = 0;
	const open = (state) => {
		const m = modal();
		if (!m) return;
		if (closeTimer) { win.clearTimeout(closeTimer); closeTimer = 0; }
		setText(m, '[data-dpn-wallet-balance]', state.getAttribute('data-balance-text') || '');
		setText(m, '[data-dpn-wallet-total]', state.getAttribute('data-total-text') || '');
		setText(m, '[data-dpn-wallet-short]', state.getAttribute('data-short-text') || '');
		const link = m.querySelector('[data-dpn-wallet-link]');
		const url = state.getAttribute('data-wallet-url');
		if (link && url) link.setAttribute('href', url);
		m.hidden = false;
		body.classList.add('dpn-wallet-modal-open');
		registerOverlay('wallet');
		raf(() => raf(() => m.classList.add('is-open')));
		tryFocus(m.querySelector('[data-dpn-wallet-card]'));
	};
	const close = () => {
		const m = modal();
		if (!m || m.hidden) return;
		m.classList.remove('is-open');
		body.classList.remove('dpn-wallet-modal-open');
		releaseOverlay('wallet');
		closeTimer = win.setTimeout(() => { m.hidden = true; closeTimer = 0; }, 240);
	};
	const intercept = (event) => {
		const state = guard();
		if (!state || state.getAttribute('data-insufficient') !== '1' || !walletRoute()) return false;
		event.preventDefault();
		event.stopImmediatePropagation();
		event.stopPropagation();
		open(state);
		return true;
	};
	win.DelicatWalletGuard = {
		check(force) { const state = guard(); if (!state || state.getAttribute('data-insufficient') !== '1' || (!force && !walletRoute())) return false; open(state); return true; },
		close,
	};
	on(doc, 'click', (event) => {
		const target = event.target;
		if (!target || target.nodeType !== 1) return;
		if (closest(target, '#place_order')) { intercept(event); return; }
		if (closest(target, '[data-dpn-wallet-close]')) { event.preventDefault(); close(); }
	}, { capture: true, signal });
	on(doc, 'submit', (event) => { const form = event.target; if (form && form.classList && form.classList.contains('checkout')) intercept(event); }, { capture: true, signal });
	on(doc, 'keydown', (event) => { if (event.key === 'Escape') close(); }, { signal });
	signal.addEventListener('abort', () => { close(); delete win.DelicatWalletGuard; }, { once: true });
}
