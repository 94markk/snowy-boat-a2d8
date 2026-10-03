/**
 * PWA runtime: service-worker registration at idle, the install prompt
 * (native prompt where available, instructions otherwise) and the Android app
 * link, which takes precedence over the web install when an app page is set.
 */
import { device, doc, html, idle, on, win } from '../core/dom.js';
import { registerOverlay, releaseOverlay } from '../core/overlay.js';

export default function mount({ signal, config }) {
	const cfg = Object.assign({}, win.DelicaPWARuntime || {}, config.pwa || {});
	const androidPage = String(win.DelicatAndroidPage || config.androidPage || '');
	const SW = String(cfg.serviceWorker || '');
	const SCOPE = String(cfg.scope || '/');
	const UI = !!cfg.installUI;
	let prompt = null;

	on(win, 'beforeinstallprompt', (event) => { event.preventDefault(); prompt = event; html.classList.add('delicat-pwa-installable'); }, { signal });

	const modal = () => {
		let m = doc.getElementById('dbv9-pwa-modal');
		if (m) return m;
		m = doc.createElement('div');
		m.id = 'dbv9-pwa-modal';
		m.className = 'dbv9-pwa-modal';
		m.innerHTML = '<div class="dbv9-pwa-backdrop" data-dbv9-pwa-close></div><section class="dbv9-pwa-card" role="dialog" aria-modal="true" aria-label="Installer Delicat Store"><button type="button" class="dbv9-pwa-close" data-dbv9-pwa-close aria-label="Fermer">×</button><div class="dbv9-pwa-icon">▣</div><h3>Installer Delicat Store</h3><p class="dbv9-pwa-copy"></p><button type="button" class="dbv9-pwa-action">Installer</button></section>';
		doc.body.appendChild(m);
		on(m, 'click', (event) => { if (event.target.closest('[data-dbv9-pwa-close]')) { m.classList.remove('is-open'); releaseOverlay('pwa'); } }, { signal });
		return m;
	};
	const open = async () => {
		if (!UI || device.standalone) return;
		const m = modal();
		const copy = m.querySelector('.dbv9-pwa-copy');
		const button = m.querySelector('.dbv9-pwa-action');
		if (prompt) {
			copy.textContent = 'Ajoutez Delicat Store à votre écran d’accueil pour un accès plus rapide.';
			button.hidden = false;
			button.textContent = 'Installer';
			button.onclick = async () => { try { prompt.prompt(); await prompt.userChoice; } catch (_) {} prompt = null; m.classList.remove('is-open'); releaseOverlay('pwa'); };
		} else if (device.ios) {
			copy.textContent = 'Dans Safari : touchez Partager, puis « Sur l’écran d’accueil » et confirmez.';
			button.hidden = true;
		} else {
			copy.textContent = 'Ouvrez le menu de votre navigateur puis choisissez « Installer l’application » ou « Ajouter à l’écran d’accueil ».';
			button.hidden = true;
		}
		m.classList.add('is-open');
		registerOverlay('pwa');
	};

	const goAndroid = (event) => {
		if (!androidPage) return false;
		if (event) { event.preventDefault(); event.stopImmediatePropagation(); }
		location.assign(androidPage);
		return true;
	};

	on(doc, 'click', (event) => {
		if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
		const trigger = event.target instanceof Element ? event.target.closest('a[href="#install-app"],[data-dlx-install],[data-db-v9-pwa-open]') : null;
		if (!trigger) return;
		if (goAndroid(event)) return;
		if (trigger.matches('[data-db-v9-pwa-open],a[href="#install-app"]')) { event.preventDefault(); open(); }
	}, { capture: true, signal });
	on(doc, 'delicat:install-app', (event) => { if (!goAndroid(event)) open(); }, { capture: true, signal });
	on(doc, 'keydown', (event) => { if (event.key === 'Escape') { const m = doc.getElementById('dbv9-pwa-modal'); if (m && m.classList.contains('is-open')) { m.classList.remove('is-open'); releaseOverlay('pwa'); } } }, { signal });

	win.DelicatPWA = { open };
	if (androidPage) win.DelicatAndroidLinkBound = true;

	if (SW && 'serviceWorker' in navigator) {
		const register = () => navigator.serviceWorker.register(SW, { scope: SCOPE }).catch(() => {});
		if (doc.readyState === 'complete') idle(register, 3500); else win.addEventListener('load', () => idle(register, 3500), { once: true });
	}
}
