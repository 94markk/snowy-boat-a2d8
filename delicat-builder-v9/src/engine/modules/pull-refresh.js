/**
 * Pull-to-refresh. Drag down at the very top of the page and the page is
 * fetched again from the network and swapped in place by the engine (9.3.4:
 * no full reload, so the chrome stays and the refresh takes a few hundred
 * milliseconds; session-bound pages such as the cart reload fully). Touch
 * only; stands down while any overlay owns the screen, inside scrollable
 * rails and during horizontal swipes. The non-passive touchmove listener
 * exists only for the length of a qualifying gesture, so ordinary scrolling
 * keeps the compositor fast path.
 */
import { doc, emit, html, on, raf, win } from '../core/dom.js';
import { anyOverlayOpen } from '../core/overlay.js';
import { nav } from '../core/nav.js';
import { session } from '../core/state.js';

const THRESHOLD = 72;
const MAX = 104;

export default function mount({ signal, config }) {
	if (!config.pullRefresh && !html.classList.contains('dlx-pull-refresh')) return;
	if (!('ontouchstart' in win) && !(navigator.maxTouchPoints > 0)) return;
	let indicator = null, ring = null, start = null, active = false, armed = false, busy = false, listening = false, frame = 0, pending = 0;

	const build = () => {
		if (indicator) return indicator;
		indicator = doc.createElement('div');
		indicator.className = 'dlx-ptr';
		indicator.setAttribute('aria-hidden', 'true');
		indicator.style.position = 'fixed'; indicator.style.left = '50%'; indicator.style.pointerEvents = 'none'; indicator.style.opacity = '0';
		ring = doc.createElement('div');
		ring.className = 'dlx-ptr__ring';
		ring.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
		indicator.appendChild(ring);
		const check = doc.createElement('div');
		check.className = 'dlx-ptr__check';
		check.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
		indicator.appendChild(check);
		doc.body.appendChild(indicator);
		return indicator;
	};
	const scrollableAncestor = (node) => {
		let depth = 0;
		while (node && node !== doc.body && node.nodeType === 1 && depth < 12) {
			depth++;
			if (node.scrollHeight > node.clientHeight + 1 || node.scrollWidth > node.clientWidth + 1) {
				let overflow = '';
				try { const style = win.getComputedStyle(node); overflow = style.overflowY + ' ' + style.overflowX; } catch (_) {}
				if (/auto|scroll/.test(overflow)) return node;
			}
			node = node.parentNode;
		}
		return null;
	};
	const blocked = () => busy || anyOverlayOpen();
	const paint = () => {
		frame = 0;
		const pulled = Math.min(MAX, pending * 0.5);
		build();
		indicator.style.transform = 'translate3d(-50%,' + pulled + 'px,0)';
		indicator.style.opacity = String(Math.min(1, pulled / 44));
		ring.style.transform = 'rotate(' + (pulled * 3) + 'deg)';
		const next = pulled >= THRESHOLD * 0.5;
		if (next !== armed) { armed = next; indicator.classList.toggle('is-armed', armed); }
	};
	const move = (distance) => { pending = distance; if (!frame) frame = raf(paint); };
	const reset = () => {
		if (!indicator) return;
		indicator.classList.remove('is-armed');
		indicator.classList.add('is-settling');
		indicator.style.transform = ''; indicator.style.opacity = '';
		win.setTimeout(() => { if (indicator) indicator.classList.remove('is-settling'); }, 260);
	};
	const hard = () => { try { location.reload(); } catch (_) { location.href = location.href; } };
	const refresh = () => {
		busy = true;
		build();
		indicator.classList.add('is-loading');
		indicator.classList.remove('is-armed');
		indicator.style.transform = 'translate3d(-50%,64px,0)';
		indicator.style.opacity = '1';
		emit('delicat:pull-refresh');
		const done = () => {
			busy = false;
			if (!indicator) return;
			indicator.classList.remove('is-loading');
			indicator.classList.add('is-done');
			win.setTimeout(() => { if (indicator) { indicator.classList.remove('is-done'); reset(); } }, 560);
		};
		let promise = null;
		try {
			nav.invalidate();
			session.refresh('pull-refresh');
			promise = nav.navigate(location.href, { push: false, fresh: true });
		} catch (_) { hard(); return; }
		/* No promise: the engine handed the address to a full load (a session-bound page). */
		if (!promise || typeof promise.then !== 'function') return;
		const timer = win.setTimeout(hard, 15000);
		promise.then(() => { win.clearTimeout(timer); done(); }, () => { win.clearTimeout(timer); hard(); });
	};
	const onMove = (event) => {
		if (!start || event.touches.length !== 1) return;
		const dy = event.touches[0].clientY - start.y;
		const dx = event.touches[0].clientX - start.x;
		if (!active) {
			if (dy < 12 || Math.abs(dx) > Math.abs(dy) * 0.8) { if (dy < -4 || Math.abs(dx) > 12) { start = null; unlisten(); } return; }
			if ((win.pageYOffset || html.scrollTop || 0) > 0) { start = null; unlisten(); return; }
			active = true;
			build();
			indicator.classList.add('is-active');
			indicator.classList.remove('is-settling');
		}
		move(dy <= 0 ? 0 : dy);
		if (event.cancelable) event.preventDefault();
	};
	const listen = () => { if (listening) return; listening = true; doc.addEventListener('touchmove', onMove, { passive: false }); };
	const unlisten = () => { if (!listening) return; listening = false; doc.removeEventListener('touchmove', onMove, { passive: false }); };
	const end = () => {
		unlisten();
		if (!start) return;
		const wasArmed = armed;
		start = null;
		if (!active) return;
		active = false;
		if (indicator) indicator.classList.remove('is-active');
		if (wasArmed) refresh(); else reset();
		armed = false;
	};

	on(doc, 'touchstart', (event) => {
		start = null; active = false; armed = false;
		unlisten();
		if (event.touches.length !== 1 || blocked()) return;
		if ((win.pageYOffset || html.scrollTop || 0) > 0) return;
		if (scrollableAncestor(event.target)) return;
		start = { x: event.touches[0].clientX, y: event.touches[0].clientY };
		listen();
	}, { passive: true, signal });
	on(doc, 'touchend', end, { passive: true, signal });
	on(doc, 'touchcancel', end, { passive: true, signal });
	on(win, 'pageshow', () => { busy = false; unlisten(); if (indicator) { indicator.classList.remove('is-loading'); reset(); } }, { signal });
	signal.addEventListener('abort', unlisten, { once: true });
}
