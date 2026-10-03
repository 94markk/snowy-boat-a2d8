/**
 * Product switcher (.dpsr-switcher): tapping an option is now an ordinary soft
 * navigation to the other product. The engine swaps the page, keeps the
 * header, re-mounts the product modules and refreshes express / review
 * context, which the old private fetcher never did. Options are prefetched
 * on intent through the shared page cache.
 */
import { device, doc, on, qsa, win } from '../core/dom.js';

export default function mount({ root, signal, engine }) {
	for (const node of qsa('.dpsr-switcher', root)) {
		if (node.getAttribute('data-dpsr-bound') === '1') continue;
		node.setAttribute('data-dpsr-bound', '1');
		const options = () => qsa('.dpsr-switcher-option', node);
		const markActive = (href) => {
			const target = new URL(href, location.href).href;
			for (const a of options()) { const active = new URL(a.href, location.href).href === target; a.classList.toggle('is-active', active); a.setAttribute('aria-selected', active ? 'true' : 'false'); }
		};
		on(node, 'click', (event) => {
			const a = event.target.closest('.dpsr-switcher-option');
			if (!a || !node.contains(a) || a.classList.contains('is-active')) return;
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
			if (!engine.nav || !engine.nav.eligible(a.href)) return;
			event.preventDefault();
			event.stopPropagation();
			node.classList.add('is-loading');
			node.setAttribute('aria-busy', 'true');
			markActive(a.href);
			engine.nav.navigate(a.href);
		}, { signal });
		const warm = (event) => { const a = event.target.closest && event.target.closest('.dpsr-switcher-option'); if (a && !device.slowNetwork && engine.nav) engine.nav.prefetch(a.href); };
		on(node, 'pointerenter', warm, { capture: true, passive: true, signal });
		on(node, 'touchstart', warm, { capture: true, passive: true, signal });
		if (node.getAttribute('data-preload') === 'yes' && !device.slowNetwork && !device.lowPower && innerWidth > 820 && engine.nav) {
			const links = options().filter((a) => !a.classList.contains('is-active')).map((a) => a.href).slice(0, 4);
			let i = 0;
			const next = () => { if (i >= links.length || !node.isConnected || signal.aborted) return; engine.nav.prefetch(links[i++]); win.setTimeout(next, 260); };
			if ('requestIdleCallback' in win) requestIdleCallback(next, { timeout: 1800 }); else win.setTimeout(next, 1000);
		}
	}
}
