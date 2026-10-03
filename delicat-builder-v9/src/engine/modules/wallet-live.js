/**
 * Live wallet balance on wallet cards ([data-dsb-wallet] / .dsb-wallet-amount).
 * Polls only while a card is visible or the menu is open, backs off on 429,
 * stops on 401/403, and shares the value across tabs on a BroadcastChannel.
 */
import { doc, html, inertDocument, on, qsa, win } from '../core/dom.js';
import { ajax } from '../core/net.js';

export default function mount({ root, signal, config }) {
	const C = win.delicatBuilderV9WalletLive || config.walletLive;
	if (!C || !C.url || !C.nonce) return;
	const cards = () => qsa('[data-dsb-wallet]', doc);
	if (!cards().length) return;
	const IDLE = parseInt(C.idle, 10) || 45000, WATCH = parseInt(C.watch, 10) || 5000, MIN = 3000;
	let timer = 0, inflight = false, last = 0, watchUntil = 0, visible = false, stopped = false, backoff = 0;
	let channel = null;
	try { channel = new BroadcastChannel('dsb-wallet'); channel.onmessage = (event) => { if (event && event.data) apply(event.data, false); }; } catch (_) {}

	const menuOpen = () => html.classList.contains('dsb-menu-open') || html.classList.contains('dlx-open');
	const active = () => !doc.hidden && (menuOpen() || visible || Date.now() < watchUntil);
	const schedule = () => { win.clearTimeout(timer); if (!stopped && active()) timer = win.setTimeout(tick, Date.now() < watchUntil ? WATCH : IDLE); };
	function apply(data, broadcast) {
		const raw = parseFloat(data.raw);
		if (isNaN(raw)) return;
		for (const card of cards()) {
			const amount = card.querySelector('.dsb-wallet-amount');
			if (amount && typeof data.html === 'string' && data.html) {
				const parsed = inertDocument('<body>' + data.html);
				amount.replaceChildren(...(parsed ? Array.from(parsed.body.childNodes) : [doc.createTextNode(String(data.html))]));
			}
			card.setAttribute('data-balance-raw', String(raw));
		}
		for (const node of qsa('[data-dlx-wallet-balance],[data-delicat-wallet-balance]')) { if (typeof data.text === 'string' && data.text) node.textContent = data.text; }
		if (broadcast && channel) { try { channel.postMessage({ raw, html: data.html, text: data.text }); } catch (_) {} }
	}
	async function poll(force) {
		const now = Date.now();
		if (stopped || inflight || !cards().length || now < backoff || (!force && now - last < MIN)) return;
		last = now; inflight = true;
		try {
			const data = await ajax('delicat_builder_v9_wallet_balance', { nonce: C.nonce }, { url: C.url, signal });
			if (data) apply(data, true);
		} catch (error) {
			const status = error && error.status;
			if (status === 429) backoff = Date.now() + 120000;
			else if (status === 401 || status === 403) stopped = true;
		} finally { inflight = false; }
	}
	function tick() { poll(false); schedule(); }

	if ('IntersectionObserver' in win) {
		const observer = new IntersectionObserver((entries) => {
			for (const entry of entries) visible = entry.isIntersecting || visible;
			visible = cards().some((card) => card.getBoundingClientRect().height > 0 && entries.some((e) => e.target === card ? e.isIntersecting : false)) || entries.some((e) => e.isIntersecting);
			if (visible) poll(true);
			schedule();
		}, { rootMargin: '80px' });
		for (const card of cards()) observer.observe(card);
		signal.addEventListener('abort', () => observer.disconnect(), { once: true });
	}
	on(doc, 'dsb:menu-opened', () => win.setTimeout(() => { poll(true); schedule(); }, 450), { signal });
	on(doc, 'dlx:open', () => win.setTimeout(() => { poll(true); schedule(); }, 450), { signal });
	on(doc, 'dsb:menu-closed', schedule, { signal });
	on(doc, 'delicat:balance-changed', () => poll(true), { signal });
	on(doc, 'delicat:recharge-submitted', () => { watchUntil = Date.now() + 180000; poll(true); schedule(); }, { signal });
	on(doc, 'visibilitychange', () => { if (!doc.hidden && active()) poll(true); schedule(); }, { signal });
	signal.addEventListener('abort', () => { win.clearTimeout(timer); if (channel) { try { channel.close(); } catch (_) {} } }, { once: true });
	if (active()) poll(true);
	schedule();
}
