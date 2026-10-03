/**
 * Shared DOM, device and cookie helpers for every engine module.
 *
 * Rules that every module follows:
 *  - listeners are added with an AbortSignal (`on(target, type, fn, { signal })`)
 *    so a content swap can release them without bookkeeping;
 *  - no localStorage / sessionStorage: the storefront runs on shared phones,
 *    so the only client storage is a first-party cookie, and only where the
 *    previous runtime already used one;
 *  - layout reads happen inside requestAnimationFrame, never in a hot path.
 */

export const doc = document;
export const html = document.documentElement;
export const win = window;

export const qs = (selector, scope = doc) => scope.querySelector(selector);
export const qsa = (selector, scope = doc) => Array.from(scope.querySelectorAll(selector));

/* Browsers before Chrome 90 / Safari 15 ignore the `signal` listener option.
   Detect that once and fall back to an explicit removal on abort, so a
   content swap still releases every listener on those devices. */
const supportsListenerSignal = (() => {
	let seen = false;
	try {
		const probe = new AbortController();
		const target = new EventTarget();
		target.addEventListener('x', () => {}, { get signal() { seen = true; return probe.signal; } });
	} catch (_) { seen = false; }
	return seen;
})();

if (typeof Element !== 'undefined' && !Element.prototype.replaceChildren) {
	Element.prototype.replaceChildren = function replaceChildren(...nodes) {
		while (this.lastChild) this.removeChild(this.lastChild);
		if (nodes.length) this.append(...nodes);
	};
}

/** addEventListener that returns a remover and accepts a signal in options. */
export function on(target, type, fn, options) {
	if (!target) return () => {};
	target.addEventListener(type, fn, options);
	const remove = () => target.removeEventListener(type, fn, options);
	if (!supportsListenerSignal && options && options.signal && typeof options.signal.addEventListener === 'function') {
		if (options.signal.aborted) remove();
		else options.signal.addEventListener('abort', remove, { once: true });
	}
	return remove;
}

export function el(tag, className, text) {
	const node = doc.createElement(tag);
	if (className) node.className = className;
	if (text !== undefined && text !== null) node.textContent = String(text);
	return node;
}

export function emit(name, detail, target = doc, cancelable = false) {
	try {
		return target.dispatchEvent(new CustomEvent(name, { detail, bubbles: true, cancelable }));
	} catch (_) {
		return true;
	}
}

export function closest(node, selector) {
	if (!node) return null;
	if (node.nodeType === 3) node = node.parentNode;
	return node && node.nodeType === 1 && node.closest ? node.closest(selector) : null;
}

export const raf = (fn) => win.requestAnimationFrame(fn);
export const idle = (fn, timeout = 1200) => (win.requestIdleCallback ? win.requestIdleCallback(fn, { timeout }) : win.setTimeout(fn, Math.min(timeout, 200)));
export const sleep = (ms) => new Promise((resolve) => win.setTimeout(resolve, ms));

/* ---------------------------------------------------------------- cookies */

export const cookie = {
	read(name) {
		const parts = doc.cookie ? doc.cookie.split(';') : [];
		for (const part of parts) {
			const pair = part.replace(/^\s+/, '');
			if (pair.indexOf(name + '=') === 0) {
				try { return decodeURIComponent(pair.slice(name.length + 1)); } catch (_) { return ''; }
			}
		}
		return '';
	},
	write(name, value, maxAge) {
		let text = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';
		if (maxAge > 0) text += '; max-age=' + maxAge;
		if (location.protocol === 'https:') text += '; Secure';
		try { doc.cookie = text; } catch (_) {}
	},
	has(pattern) {
		return pattern.test(doc.cookie || '');
	},
};

/* ----------------------------------------------------------------- device */

const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
const memory = Number(navigator.deviceMemory || 0);
const cores = Number(navigator.hardwareConcurrency || 0);
const reduceQuery = win.matchMedia ? win.matchMedia('(prefers-reduced-motion: reduce)') : null;
const coarseQuery = win.matchMedia ? win.matchMedia('(hover: none) and (pointer: coarse)') : null;

export const device = {
	get saveData() { return !!(connection && connection.saveData); },
	get lowPower() {
		return this.saveData || (memory > 0 && memory <= 4) || (cores > 0 && cores <= 4) || html.classList.contains('delicat-low-power');
	},
	get veryLowPower() { return (memory > 0 && memory <= 2) || (cores > 0 && cores <= 2); },
	get reducedMotion() { return !!(reduceQuery && reduceQuery.matches) || html.classList.contains('delicat-reduce-motion'); },
	get touch() { return !!(coarseQuery && coarseQuery.matches) || navigator.maxTouchPoints > 0; },
	get standalone() { return (win.matchMedia && win.matchMedia('(display-mode: standalone)').matches) || win.navigator.standalone === true; },
	get ios() {
		const ua = navigator.userAgent || '';
		return /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && Number(navigator.maxTouchPoints || 0) > 1);
	},
	/** True when warming anything over the network would hurt the page being read. */
	get slowNetwork() {
		if (doc.hidden || navigator.onLine === false) return true;
		if (!connection) return false;
		const type = String(connection.effectiveType || '').toLowerCase();
		if (connection.saveData || type === 'slow-2g' || type === '2g' || type === '3g') return true;
		const downlink = typeof connection.downlink === 'number' ? connection.downlink : 0;
		if (downlink > 0 && downlink < 1.5) return true;
		if (typeof connection.rtt === 'number' && connection.rtt > 300 && downlink > 0 && downlink < 3) return true;
		return html.classList.contains('delicat-slow-net');
	},
	memory,
	cores,
	reduceQuery,
};

/* ------------------------------------------------------------------- urls */

export function parseUrl(href) {
	try { return new URL(String(href || ''), location.href); } catch (_) { return null; }
}

export function safeUrl(value, sameOrigin = false) {
	const url = parseUrl(value);
	if (!url || (url.protocol !== 'http:' && url.protocol !== 'https:')) return '';
	if (sameOrigin && url.origin !== location.origin) return '';
	return url.href;
}

export function sameDocument(url) {
	return !!url && url.pathname === location.pathname && url.search === location.search;
}

/* ------------------------------------------------------------------- text */

export const cleanText = (value) => String(value || '')
	.replace(/[\u{1F000}-\u{1FAFF}\u{1F1E6}-\u{1F1FF}☀-➿︎️]/gu, '')
	.replace(/\s{2,}/g, ' ')
	.trim();

export const normalizeSpaces = (value) => String(value || '').replace(/[  ]/g, ' ').replace(/\s+/g, ' ').trim();

export function foldAccents(value) {
	const raw = String(value || '');
	const folded = typeof raw.normalize === 'function' ? raw.normalize('NFD') : raw;
	return folded.replace(/[̀-ͯ]/g, '').toLocaleLowerCase().trim();
}

/** Parse untrusted HTML in an inert document: nothing in it runs or loads. */
export function inertDocument(markup) {
	try { return new DOMParser().parseFromString(String(markup || ''), 'text/html'); } catch (_) { return null; }
}

export const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

export function focusables(scope) {
	return qsa(FOCUSABLE, scope).filter((node) => node.getClientRects().length && !closest(node, '[hidden]') && !closest(node, '.is-collapsed'));
}

/** Keep Tab inside a dialog; returns true when the key was handled. */
export function trapTab(event, scope) {
	if (event.key !== 'Tab') return false;
	const list = focusables(scope);
	if (!list.length) return false;
	const first = list[0];
	const last = list[list.length - 1];
	if (event.shiftKey && (doc.activeElement === first || doc.activeElement === scope)) { event.preventDefault(); last.focus(); return true; }
	if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); return true; }
	return false;
}

export function tryFocus(node, options = { preventScroll: true }) {
	if (!node || typeof node.focus !== 'function') return;
	try { node.focus(options); } catch (_) { try { node.focus(); } catch (__) {} }
}

/** jQuery is optional; WooCommerce's own events travel through it. */
export const jq = () => (typeof win.jQuery === 'function' ? win.jQuery : null);

/** Bind to jQuery-dispatched events (WooCommerce) with signal cleanup. */
export function onWoo(events, fn, signal) {
	const $ = jq();
	if (!$) return;
	try { $(doc.body).on(events, fn); } catch (_) { return; }
	if (signal) signal.addEventListener('abort', () => { try { $(doc.body).off(events, fn); } catch (_) {} }, { once: true });
}

export const renderedLoggedIn = () => !!(doc.body && doc.body.classList.contains('logged-in'));
