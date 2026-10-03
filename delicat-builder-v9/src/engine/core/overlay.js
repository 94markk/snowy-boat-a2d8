/**
 * Overlay coordination: one scroll-lock counter and one "something is open"
 * registry shared by the drawer, express sheet, wallet modal, announcement,
 * reviews, notifications, PWA modal and the product-fields help modal.
 *
 * Several of those used to lock and unlock the body independently, so one
 * closing could release another's lock. The counter makes the lock honest, and
 * `anyOpen()` lets gestures (pull-to-refresh, swipe) stand down while any
 * overlay owns the screen.
 */
import { doc, html, win } from './dom.js';

const open = new Set();
let locks = 0;
let lockY = 0;
let savedTop = '';

export function lockScroll() {
	if (locks++ > 0) return;
	lockY = win.pageYOffset || 0;
	savedTop = doc.body.style.top;
	html.classList.add('delicat-scroll-locked');
	doc.body.style.top = (-lockY) + 'px';
}

export function unlockScroll() {
	if (locks === 0) return;
	if (--locks > 0) return;
	html.classList.remove('delicat-scroll-locked');
	doc.body.style.top = savedTop;
	try { win.scrollTo({ top: lockY, left: 0, behavior: 'instant' }); } catch (_) { win.scrollTo(0, lockY); }
}

export function registerOverlay(name) {
	open.add(name);
	html.setAttribute('data-delicat-overlay', Array.from(open).join(' '));
}

export function releaseOverlay(name) {
	open.delete(name);
	if (open.size) html.setAttribute('data-delicat-overlay', Array.from(open).join(' '));
	else html.removeAttribute('data-delicat-overlay');
}

export function anyOverlayOpen() {
	if (open.size) return true;
	return !!(
		html.classList.contains('dlx-open')
		|| html.classList.contains('dsb-menu-open')
		|| html.classList.contains('dbv9-annc-lock')
		|| html.classList.contains('dbv9-notification-open')
		|| doc.body.classList.contains('dnp-express-open')
		|| doc.body.classList.contains('dpn-wallet-modal-open')
		|| doc.body.classList.contains('dlc-review-modal-open')
		|| doc.body.classList.contains('dmc-modal-open')
		|| doc.body.classList.contains('delicat-shell-modal-open')
		|| doc.querySelector('[data-dsb8-cart-root].is-open, #dbv9-pwa-modal.is-open, #dbv9-push-ios-help.is-open, .dlx-drawer.is-open')
	);
}

export const overlay = { lockScroll, unlockScroll, register: registerOverlay, release: releaseOverlay, anyOpen: anyOverlayOpen };
