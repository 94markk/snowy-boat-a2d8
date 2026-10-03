/**
 * PWA runtime: service-worker registration at idle, the install prompt
 * (native prompt where available, iPhone instructions otherwise), the
 * Android app link (never offered on an iPhone), and what an installed app
 * needs to feel installed: the standalone class for CSS, a clean address on
 * launch, a refreshed session when the app comes back to the front, and a
 * one-time invitation to add the store to the home screen.
 */
import { device, doc, html, idle, on, win } from '../core/dom.js';
import { registerOverlay, releaseOverlay } from '../core/overlay.js';
import { session } from '../core/state.js';

const SHARE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="20" height="20"><path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const PLUS_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="20" height="20"><rect x="3.5" y="3.5" width="17" height="17" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8v8M8 12h8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

const store = {
	get(key) { try { return win.localStorage.getItem(key); } catch (_) { return null; } },
	set(key, value) { try { win.localStorage.setItem(key, value); } catch (_) {} },
};

export default function mount({ signal, config }) {
	const cfg = Object.assign({}, win.DelicaPWARuntime || {}, config.pwa || {});
	const androidPage = String(win.DelicatAndroidPage || config.androidPage || '');
	const SW = String(cfg.serviceWorker || '');
	const SCOPE = String(cfg.scope || '/');
	const UI = !!cfg.installUI;
	let prompt = null;

	/* ---- the installed app ---- */
	const standalone = device.standalone;
	if (standalone) html.classList.add('delicat-standalone');
	if (device.ios) html.classList.add('delicat-ios');
	if (standalone) {
		/* iOS launches at start_url (…?utm_source=pwa): the tag has done its job
		   once the page is open, and a clean address keeps caches and shared
		   links tidy. */
		try {
			const url = new URL(location.href);
			if (url.searchParams.get('utm_source') === 'pwa') { url.searchParams.delete('utm_source'); history.replaceState(history.state, '', url.pathname + (url.search || '') + url.hash); }
		} catch (_) {}
		/* Back to the front after a while: the cart count, wallet and alerts may have moved. */
		let hiddenAt = 0;
		on(doc, 'visibilitychange', () => {
			if (doc.hidden) { hiddenAt = Date.now(); return; }
			if (hiddenAt && Date.now() - hiddenAt > 90000) session.refresh('resume');
			hiddenAt = 0;
		}, { signal });
	}

	on(win, 'beforeinstallprompt', (event) => { event.preventDefault(); prompt = event; html.classList.add('delicat-pwa-installable'); }, { signal });

	const modal = () => {
		let m = doc.getElementById('dbv9-pwa-modal');
		if (m) return m;
		m = doc.createElement('div');
		m.id = 'dbv9-pwa-modal';
		m.className = 'dbv9-pwa-modal';
		m.innerHTML = '<div class="dbv9-pwa-backdrop" data-dbv9-pwa-close></div><section class="dbv9-pwa-card" role="dialog" aria-modal="true" aria-label="Installer Delicat Store"><button type="button" class="dbv9-pwa-close" data-dbv9-pwa-close aria-label="Fermer">×</button><h3 class="dbv9-pwa-title">Installer Delicat Store</h3><p class="dbv9-pwa-copy"></p><ol class="dbv9-pwa-steps" hidden></ol><button type="button" class="dbv9-pwa-action">Installer</button></section>';
		doc.body.appendChild(m);
		on(m, 'click', (event) => { if (event.target.closest('[data-dbv9-pwa-close]')) { m.classList.remove('is-open'); releaseOverlay('pwa'); } }, { signal });
		return m;
	};
	const open = async () => {
		if (!UI || standalone) return;
		const m = modal();
		const copy = m.querySelector('.dbv9-pwa-copy');
		const steps = m.querySelector('.dbv9-pwa-steps');
		const button = m.querySelector('.dbv9-pwa-action');
		steps.hidden = true;
		if (prompt) {
			copy.textContent = 'Ajoutez Delicat Store à votre écran d’accueil pour un accès plus rapide.';
			button.hidden = false;
			button.textContent = 'Installer';
			button.onclick = async () => { try { prompt.prompt(); await prompt.userChoice; } catch (_) {} prompt = null; m.classList.remove('is-open'); releaseOverlay('pwa'); };
		} else if (device.ios) {
			copy.textContent = 'Sur iPhone, l’application s’installe depuis Safari en deux gestes :';
			steps.innerHTML = '<li><span class="dbv9-pwa-step-icon">' + SHARE_ICON + '</span><span class="dbv9-pwa-step-text">Touchez <strong>Partager</strong> en bas de Safari.</span></li><li><span class="dbv9-pwa-step-icon">' + PLUS_ICON + '</span><span class="dbv9-pwa-step-text">Choisissez <strong>« Sur l’écran d’accueil »</strong>, puis <strong>Ajouter</strong>.</span></li>';
			steps.hidden = false;
			button.hidden = true;
		} else {
			copy.textContent = 'Ouvrez le menu de votre navigateur puis choisissez « Installer l’application » ou « Ajouter à l’écran d’accueil ».';
			button.hidden = true;
		}
		m.classList.add('is-open');
		registerOverlay('pwa');
	};

	/* The Android package is for Android: an iPhone gets the web app. */
	const goAndroid = (event) => {
		if (!androidPage || device.ios) return false;
		if (event) { event.preventDefault(); event.stopImmediatePropagation(); }
		location.assign(androidPage);
		return true;
	};

	on(doc, 'click', (event) => {
		if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
		const trigger = event.target instanceof Element ? event.target.closest('a[href="#install-app"],[data-dlx-install],[data-db-v9-pwa-open],[data-dbv9-pwa-open]') : null;
		if (!trigger) return;
		if (goAndroid(event)) return;
		if (trigger.matches('[data-db-v9-pwa-open],[data-dbv9-pwa-open],a[href="#install-app"]')) { event.preventDefault(); open(); }
	}, { capture: true, signal });
	on(doc, 'delicat:install-app', (event) => { if (!goAndroid(event)) open(); }, { capture: true, signal });
	on(doc, 'keydown', (event) => { if (event.key === 'Escape') { const m = doc.getElementById('dbv9-pwa-modal'); if (m && m.classList.contains('is-open')) { m.classList.remove('is-open'); releaseOverlay('pwa'); } } }, { signal });

	/* ---- the invitation: second visit, once a month, iPhone and Android alike ---- */
	const invite = () => {
		if (!UI || standalone || doc.hidden) return;
		if (!(device.ios || prompt)) return;
		const until = Number(store.get('dbv9-install-quiet') || 0);
		if (until && Date.now() < until) return;
		const visits = Number(store.get('dbv9-visits') || 0);
		if (visits < 2) return;
		if (doc.getElementById('dbv9-install-bar') || doc.querySelector('.dlx-drawer.is-open, .dbv9-pwa-modal.is-open')) return;
		const bar = doc.createElement('div');
		bar.id = 'dbv9-install-bar';
		bar.className = 'dbv9-install-bar';
		bar.setAttribute('role', 'status');
		bar.innerHTML = '<span class="dbv9-install-bar__icon">' + PLUS_ICON + '</span><span class="dbv9-install-bar__copy"><strong>Delicat en application</strong><small>Ajoutez-la à l’écran d’accueil.</small></span><button type="button" class="dbv9-install-bar__go" data-dbv9-pwa-open>Voir</button><button type="button" class="dbv9-install-bar__close" aria-label="Fermer">×</button>';
		doc.body.appendChild(bar);
		const quiet = (days) => { store.set('dbv9-install-quiet', String(Date.now() + days * 86400000)); bar.remove(); };
		on(bar, 'click', (event) => {
			if (event.target.closest('.dbv9-install-bar__close')) { quiet(30); return; }
			if (event.target.closest('[data-dbv9-pwa-open]')) { quiet(14); }
		}, { signal });
		win.setTimeout(() => bar.classList.add('is-in'), 30);
		win.setTimeout(() => { if (bar.isConnected) bar.remove(); }, 18000);
	};
	store.set('dbv9-visits', String(Number(store.get('dbv9-visits') || 0) + 1));
	if (!standalone) idle(() => win.setTimeout(invite, 6000), 5000);

	win.DelicatPWA = { open };
	if (androidPage && !device.ios) win.DelicatAndroidLinkBound = true;

	if (SW && 'serviceWorker' in navigator) {
		const register = () => navigator.serviceWorker.register(SW, { scope: SCOPE }).catch(() => {});
		if (doc.readyState === 'complete') idle(register, 3500); else win.addEventListener('load', () => idle(register, 3500), { once: true });
	}
}
