/**
 * Testimonials marquee: the two review rows loop forever once the section is
 * near the screen. The server prints each row once; this module appends a
 * hidden copy so the CSS translate (−50 %) wraps seamlessly, then releases the
 * animation with `is-loop-ready` (the stylesheet holds it until then).
 *
 * The loop runs on phones too (the store's signature motion); the rows become
 * manual snap-scrolling lists only for reduced motion, or when the owner asks
 * for that on phones (`data-dbv9-marquee-mobile="manual"`). Rows out of view
 * are paused, as is a row the shopper is holding, so the animation costs
 * nothing while nobody looks at it.
 */
import { device, doc, on, qsa, raf, win } from '../core/dom.js';

const FOCUSABLE = 'a,button,input,select,textarea,[tabindex]';

export default function mount({ root, signal }) {
	const tracks = qsa('[data-dbv9-marquee-clone]', root).filter((track) => track.dataset.dbv9LoopReady !== '1');
	if (!tracks.length) return;
	const compact = !!(win.matchMedia && win.matchMedia('(max-width: 640px)').matches);

	const prepare = (track) => {
		if (track.dataset.dbv9LoopReady === '1') return;
		track.dataset.dbv9LoopReady = '1';
		const wrap = track.parentElement;
		const manual = device.reducedMotion || (compact && !!track.closest('[data-dbv9-marquee-mobile="manual"]'));
		if (manual) {
			track.classList.add('is-loop-ready', 'is-manual');
			if (wrap) wrap.classList.add('is-manual');
			return;
		}
		const originals = Array.from(track.children);
		if (!originals.length) { track.classList.add('is-loop-ready'); return; }
		const fragment = doc.createDocumentFragment();
		for (const original of originals) {
			const clone = original.cloneNode(true);
			clone.setAttribute('aria-hidden', 'true');
			clone.removeAttribute('role');
			clone.removeAttribute('id');
			clone.querySelectorAll(FOCUSABLE).forEach((node) => node.setAttribute('tabindex', '-1'));
			fragment.appendChild(clone);
		}
		track.appendChild(fragment);
		if (wrap) {
			const hold = (held) => () => track.classList.toggle('is-held', held);
			on(wrap, 'pointerdown', hold(true), { signal, passive: true });
			for (const type of ['pointerup', 'pointercancel', 'pointerleave']) on(wrap, type, hold(false), { signal, passive: true });
		}
		raf(() => track.classList.add('is-loop-ready'));
	};

	if (!('IntersectionObserver' in win)) { tracks.forEach(prepare); return; }
	/* One observer prepares a row as it approaches and pauses it while it is away. */
	const observer = new IntersectionObserver((entries) => {
		for (const entry of entries) {
			const track = entry.target.matches('[data-dbv9-marquee-clone]') ? entry.target : entry.target.querySelector('[data-dbv9-marquee-clone]');
			if (!track) continue;
			if (entry.isIntersecting) { prepare(track); track.classList.remove('is-offscreen'); }
			else if (track.dataset.dbv9LoopReady === '1') track.classList.add('is-offscreen');
		}
	}, { rootMargin: '320px 0px' });
	for (const track of tracks) observer.observe(track.parentElement || track);
	signal.addEventListener('abort', () => observer.disconnect(), { once: true });
}
