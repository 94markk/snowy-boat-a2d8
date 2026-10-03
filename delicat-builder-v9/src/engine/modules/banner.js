/**
 * Banner rail: the position dots follow the card in view, and a tap on a dot
 * brings its card in. Scrolling itself is the browser's (snap points); this
 * only watches it, one frame at a time.
 */
import { device, on, qsa, raf } from '../core/dom.js';

export default function mount({ root, signal }) {
	for (const rail of qsa('[data-dbv9-banner-rail]', root)) {
		const track = rail.querySelector('[data-dbv9-banner-track]');
		const dots = track ? Array.from(rail.querySelectorAll('.delicat-banner-rail__dots i')) : [];
		if (!track || !dots.length) continue;
		const step = () => { const cards = track.children; return cards.length > 1 ? cards[1].offsetLeft - cards[0].offsetLeft : (cards[0] ? cards[0].offsetWidth : 0); };
		let queued = false;
		const paint = () => {
			queued = false;
			const size = step();
			const max = Math.max(0, track.scrollWidth - track.clientWidth);
			let index = size > 0 ? Math.round(track.scrollLeft / size) : 0;
			if (max > 0 && track.scrollLeft >= max - 2) index = dots.length - 1;
			index = Math.max(0, Math.min(dots.length - 1, index));
			dots.forEach((dot, k) => dot.classList.toggle('is-active', k === index));
		};
		on(track, 'scroll', () => { if (!queued) { queued = true; raf(paint); } }, { passive: true, signal });
		on(rail, 'click', (event) => {
			const dot = event.target instanceof Element ? event.target.closest('.delicat-banner-rail__dots i') : null;
			if (!dot) return;
			try { track.scrollTo({ left: dots.indexOf(dot) * step(), behavior: device.reducedMotion ? 'auto' : 'smooth' }); } catch (_) { track.scrollLeft = dots.indexOf(dot) * step(); }
		}, { signal });
		paint();
	}
}
