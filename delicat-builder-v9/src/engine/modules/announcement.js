/**
 * Announcement popup ([data-dbv9-announcement]). Audience and dismissal are
 * decided in the browser (cookie `dbv9_annc`) so the cached HTML is the same
 * for every visitor. Prerender-aware: the delay only starts once the page is
 * actually shown.
 */
import { cookie, doc, focusables, html, on, qsa, raf, renderedLoggedIn, win } from '../core/dom.js';
import { registerOverlay, releaseOverlay } from '../core/overlay.js';

const COOKIE = 'dbv9_annc';

function suppressFloatingHelp() {
	const hidden = [];
	let nodes;
	try { nodes = qsa('[data-delicat-assistant],[class*="assistant"],[id*="assistant"],[class*="chat"],[id*="chat"],[class*="support-widget"],[id*="support-widget"],iframe[title*="chat" i],iframe[title*="assistant" i]'); } catch (_) { nodes = []; }
	for (const node of nodes) {
		if (!node || (node.closest && node.closest('[data-dbv9-announcement]'))) continue;
		let style;
		try { style = win.getComputedStyle(node); } catch (_) { style = null; }
		if (!style || (style.position !== 'fixed' && style.position !== 'sticky')) continue;
		hidden.push({ node, display: node.style.getPropertyValue('display'), priority: node.style.getPropertyPriority('display') });
		node.style.setProperty('display', 'none', 'important');
	}
	return hidden;
}
const restoreFloatingHelp = (hidden) => { for (const item of hidden) { if (!item.node || !item.node.style) continue; if (item.display) item.node.style.setProperty('display', item.display, item.priority || ''); else item.node.style.removeProperty('display'); } };

function init(root, signal, engine) {
	const link = doc.getElementById('delicat-builder-v9-announcement-css');
	if (link && String(link.media || '').toLowerCase() === 'print') link.media = 'all';
	if (!root || root.getAttribute('data-dbv9-annc-ready') === '1') return;
	root.setAttribute('data-dbv9-annc-ready', '1');
	const dialog = root.querySelector('.dbv9-annc__dialog');
	if (!dialog) return;
	if (doc.body && root.parentNode !== doc.body) doc.body.appendChild(root);

	const signature = root.getAttribute('data-sig') || '';
	const frequency = root.getAttribute('data-frequency') || 'days';
	let days = parseInt(root.getAttribute('data-days'), 10); if (isNaN(days) || days < 1) days = 7;
	let delay = parseInt(root.getAttribute('data-delay'), 10); if (isNaN(delay) || delay < 0) delay = 0;
	const audience = root.getAttribute('data-audience') || 'all';
	const signedIn = () => { const session = engine.session && engine.session.get ? engine.session.get() : null; return session && typeof session.loggedIn === 'boolean' ? session.loggedIn : renderedLoggedIn(); };
	if (audience === 'guests' && signedIn()) return;
	if (audience === 'members' && !signedIn()) return;
	if (frequency !== 'always' && cookie.read(COOKIE) === signature) return;

	let lastFocus = null, isOpen = false, closeTimer = 0, hiddenHelp = [];
	const remember = () => { if (frequency === 'always') return; cookie.write(COOKIE, signature, frequency === 'session' ? 0 : days * 86400); };
	const onKeydown = (event) => {
		if (event.key === 'Escape' || event.key === 'Esc') { event.preventDefault(); close(); return; }
		if (event.key !== 'Tab') return;
		const targets = focusables(dialog);
		if (!targets.length) return;
		const first = targets[0], last = targets[targets.length - 1];
		if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
		else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
	};
	function open() {
		if (isOpen) return;
		isOpen = true;
		lastFocus = doc.activeElement;
		root.hidden = false;
		root.classList.remove('is-closing');
		html.classList.add('dbv9-annc-lock');
		registerOverlay('announcement');
		hiddenHelp = suppressFloatingHelp();
		raf(() => raf(() => root.classList.add('is-open')));
		const targets = focusables(dialog);
		if (targets.length) targets[0].focus();
		doc.addEventListener('keydown', onKeydown, true);
	}
	function close() {
		if (!isOpen) return;
		isOpen = false;
		remember();
		root.classList.remove('is-open');
		root.classList.add('is-closing');
		html.classList.remove('dbv9-annc-lock');
		releaseOverlay('announcement');
		restoreFloatingHelp(hiddenHelp);
		hiddenHelp = [];
		doc.removeEventListener('keydown', onKeydown, true);
		if (closeTimer) win.clearTimeout(closeTimer);
		closeTimer = win.setTimeout(() => { root.classList.remove('is-closing'); root.hidden = true; if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (_) {} } }, 320);
	}
	for (const dismiss of qsa('[data-dbv9-annc-dismiss]', root)) on(dismiss, 'click', (event) => { event.preventDefault(); close(); }, { signal });
	on(dialog, 'click', (event) => event.stopPropagation(), { signal });
	const go = root.querySelector('[data-dbv9-annc-go]');
	if (go) on(go, 'click', remember, { signal });
	const start = () => { if (delay > 0) win.setTimeout(open, delay); else open(); };
	if (doc.prerendering) doc.addEventListener('prerenderingchange', start, { once: true }); else start();
	signal.addEventListener('abort', () => { doc.removeEventListener('keydown', onKeydown, true); }, { once: true });
}

export default function mount({ signal, engine }) {
	for (const node of qsa('[data-dbv9-announcement]')) init(node, signal, engine);
}
