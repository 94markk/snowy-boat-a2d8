/**
 * Light / dark theme. Preference lives in the `dbv9_theme` first-party cookie
 * (light | dark | system); the resolved theme is written to <html> as
 * data-delicat-theme + dlc-theme-dark/light and mirrored on <body> and the
 * managed-homepage bridges that older stylesheets still read.
 *
 * The inline boot script printed by PHP applies the cookie before first paint;
 * this module takes over from there (toggle buttons, system changes, logo
 * swap, assistant chrome).
 */
import { cookie, device, doc, emit, html, on, qsa, raf, win } from '../core/dom.js';

const KEY = 'dbv9_theme';
const VALID = new Set(['light', 'dark', 'system']);
const systemQuery = win.matchMedia ? win.matchMedia('(prefers-color-scheme: dark)') : null;

let preference = 'light';
let switchTimer = 0;
let assistantObserver = null;
let assistantShell = null;
let assistantQueued = false;

const read = () => { const value = cookie.read(KEY); return VALID.has(value) ? value : null; };
const write = (value) => cookie.write(KEY, value, 31536000);
const effective = (value) => (value === 'system' ? (systemQuery && systemQuery.matches ? 'dark' : 'light') : (value === 'dark' ? 'dark' : 'light'));

function syncButtons(theme) {
	for (const button of qsa('[data-delicat-theme-toggle]')) {
		const dark = theme === 'dark';
		button.setAttribute('aria-pressed', dark ? 'true' : 'false');
		button.setAttribute('aria-label', dark ? 'Activer le mode clair' : 'Activer le mode sombre');
		button.dataset.delicatThemeState = theme;
		button.title = dark ? 'Mode clair' : 'Mode sombre';
	}
}

function syncLogo(theme) {
	for (const image of qsa('img[data-dsb8-logo-light]')) {
		const light = image.getAttribute('data-dsb8-logo-light') || '';
		const dark = image.getAttribute('data-dsb8-logo-dark') || '';
		const target = theme === 'dark' && dark ? dark : light;
		if (!target || image.getAttribute('src') === target) continue;
		image.setAttribute('src', target);
		image.removeAttribute('srcset');
		image.removeAttribute('sizes');
	}
	for (const logo of qsa('.dsb8-header__logo')) {
		const light = logo.querySelector('.dsb8-header__logo-light');
		const dark = logo.querySelector('.dsb8-header__logo-dark');
		if (!light || !dark) continue;
		const showDark = theme === 'dark';
		light.hidden = showDark; dark.hidden = !showDark;
		light.setAttribute('aria-hidden', showDark ? 'true' : 'false');
		dark.setAttribute('aria-hidden', showDark ? 'false' : 'true');
		light.style.setProperty('display', showDark ? 'none' : 'flex', 'important');
		dark.style.setProperty('display', showDark ? 'flex' : 'none', 'important');
	}
}

function syncBridges(theme) {
	const dark = theme === 'dark';
	const lightClass = 'delicat-home-mode-light';
	const darkClass = 'delicat-home-mode-dark';
	if (doc.body) {
		doc.body.classList.toggle('dlc-theme-dark', dark);
		doc.body.classList.toggle('dlc-theme-light', !dark);
		if (doc.body.classList.contains(lightClass) || doc.body.classList.contains(darkClass) || doc.body.classList.contains('delicat-builder-homepage-managed')) {
			doc.body.classList.toggle(darkClass, dark);
			doc.body.classList.toggle(lightClass, !dark);
		}
	}
	for (const node of qsa('[data-delicat-home-mode], [data-delicat-homepage-managed="1"], .delicat-page-layout--homepage-managed')) {
		node.classList.toggle(darkClass, dark);
		node.classList.toggle(lightClass, !dark);
		if (node.hasAttribute('data-delicat-home-mode')) node.setAttribute('data-delicat-home-mode', theme);
	}
}

/* The floating assistant (a separate plugin) is positioned by CSS keyed on
   body.dbv9-assistant-open; keep that class truthful. */
function syncAssistant() {
	if (!doc.body) return false;
	const shell = doc.querySelector('.dap-shell.dap-floating, .dap-shell .dap-floating');
	const retired = doc.querySelector('a[aria-label="WhatsApp"][href*="BXQXUCKDDI3GO1"][style*="position:fixed"]');
	if (retired) retired.remove();
	if (!shell) { doc.body.classList.remove('dbv9-assistant-open'); return false; }
	const launcher = shell.querySelector('.dap-launcher');
	const unread = shell.querySelector('.dap-unread');
	const expanded = launcher ? launcher.getAttribute('aria-expanded') : '';
	const dialog = shell.querySelector('[role="dialog"][aria-hidden="false"], .dap-panel.is-open, .dap-window.is-open, .dap-dialog.is-open, .dap-chat.is-open');
	const bounds = shell.getBoundingClientRect();
	const open = expanded === 'true' || shell.classList.contains('is-open') || shell.classList.contains('dap-open') || shell.classList.contains('is-expanded') || !!dialog || bounds.width > 240 || bounds.height > 240;
	doc.body.classList.toggle('dbv9-assistant-open', open);
	if (launcher && !launcher.getAttribute('aria-label')) launcher.setAttribute('aria-label', 'Ouvrir l’assistant Delicat');
	if (unread) {
		const value = (unread.textContent || '').trim();
		const n = Number.parseInt(value, 10);
		const empty = value === '' || (!Number.isNaN(n) && n <= 0);
		if (unread.hidden !== empty) unread.hidden = empty;
		unread.setAttribute('aria-hidden', empty ? 'true' : 'false');
	}
	if (assistantShell !== shell && typeof MutationObserver === 'function') {
		if (assistantObserver) assistantObserver.disconnect();
		assistantShell = shell;
		assistantObserver = new MutationObserver(() => {
			if (assistantQueued) return;
			assistantQueued = true;
			raf(() => { assistantQueued = false; syncAssistant(); });
		});
		assistantObserver.observe(shell, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-expanded', 'aria-hidden', 'class'] });
	}
	return true;
}

function primeAssistant() {
	if (syncAssistant() || typeof MutationObserver !== 'function') return;
	const discovery = new MutationObserver(() => { if (syncAssistant()) discovery.disconnect(); });
	discovery.observe(doc.body, { childList: true });
	win.setTimeout(() => discovery.disconnect(), 3500);
}

export function applyTheme(value, animate = false) {
	preference = VALID.has(value) ? value : 'light';
	const theme = effective(preference);
	if (animate && !device.reducedMotion) {
		html.classList.add('dlc-theme-switching');
		win.clearTimeout(switchTimer);
		switchTimer = win.setTimeout(() => html.classList.remove('dlc-theme-switching'), 220);
	}
	html.dataset.delicatThemePreference = preference;
	html.dataset.delicatTheme = theme;
	html.classList.toggle('dlc-theme-dark', theme === 'dark');
	html.classList.toggle('dlc-theme-light', theme === 'light');
	html.style.colorScheme = theme;
	syncBridges(theme);
	syncLogo(theme);
	syncButtons(theme);
	emit('delicat:themechange', { theme, preference });
}

function resync() {
	const theme = html.dataset.delicatTheme === 'dark' ? 'dark' : 'light';
	syncBridges(theme);
	syncLogo(theme);
	syncButtons(theme);
	primeAssistant();
}

export default function mount({ signal }) {
	if (device.saveData) html.classList.add('delicat-save-data');
	if (device.lowPower) html.classList.add('delicat-low-power', 'dsb8-low-power', 'dsb-cheap-device');
	else if (device.reducedMotion) html.classList.add('dsb-cheap-device');
	if (device.reducedMotion) html.classList.add('delicat-reduce-motion');

	applyTheme(read() || html.dataset.delicatThemePreference || 'light', false);

	on(doc, 'click', (event) => {
		const target = event.target instanceof Element ? event.target.closest('[data-delicat-theme-toggle]') : null;
		if (!target) return;
		event.preventDefault();
		const next = html.dataset.delicatTheme === 'dark' ? 'light' : 'dark';
		write(next);
		applyTheme(next, true);
	}, { capture: true, signal });

	if (systemQuery) {
		const onSystem = () => { if (preference === 'system') applyTheme('system', true); };
		if (systemQuery.addEventListener) systemQuery.addEventListener('change', onSystem);
		else if (systemQuery.addListener) systemQuery.addListener(onSystem);
	}

	on(doc, 'delicat:navigated', resync, { signal });
	on(win, 'pageshow', resync, { passive: true, signal });
	on(doc, 'click', (event) => {
		if (event.target instanceof Element && event.target.closest('.dap-shell')) { win.setTimeout(syncAssistant, 0); win.setTimeout(syncAssistant, 360); }
	}, { capture: true, signal });
	on(doc, 'keydown', (event) => { if (event.key === 'Escape') win.setTimeout(syncAssistant, 0); }, { capture: true, signal });
	resync();

	win.DelicaTheme = Object.freeze({
		get: () => html.dataset.delicatTheme || 'light',
		getPreference: () => preference,
		set: (value) => { const safe = VALID.has(value) ? value : 'light'; write(safe); applyTheme(safe, true); },
	});
}
