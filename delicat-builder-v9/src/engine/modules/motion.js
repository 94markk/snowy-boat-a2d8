/**
 * Scroll-reveal motion for builder sections (.delicat-page-layout > .delicat-section).
 * Adaptive to device power and reduced-motion; the failsafe timer guarantees
 * no section can stay invisible.
 */
import { device, doc, html, on, qsa, raf, win } from '../core/dom.js';

const ALLOWED = new Set(['fade-up', 'fade', 'scale', 'slide-left', 'slide-right', 'soft-zoom']);

export default function mount({ root, signal, config }) {
	const cfg = Object.assign({ enabled: true, effect: 'fade-up', duration: 440, intensity: 45, stagger: 55, baseDelay: 0, threshold: 0.04, desktop: true, tablet: true, mobile: true, mobileIntensity: 70 }, win.DelicaBuilderV9MotionConfig || config.motion || {});
	let observer = null, failsafe = 0, resizeTimer = 0, generation = 0, lastDevice = '';

	const viewportWidth = () => Math.max(320, Number(win.innerWidth || html.clientWidth || 1024));
	const deviceClass = () => { const width = viewportWidth(); return width <= 640 ? 'mobile' : (width <= 960 ? 'tablet' : 'desktop'); };
	const deviceEnabled = (kind) => (kind === 'mobile' ? !!cfg.mobile : (kind === 'tablet' ? !!cfg.tablet : !!cfg.desktop));
	const profile = (kind) => {
		const mobileFactor = kind === 'mobile' ? Math.max(0.2, Math.min(1, Number(cfg.mobileIntensity || 70) / 100)) : 1;
		let factor = mobileFactor;
		let duration = Math.max(180, Math.min(900, Number(cfg.duration || 440)));
		let stagger = Math.max(0, Math.min(150, Number(cfg.stagger || 55)));
		let effect = ALLOWED.has(cfg.effect) ? cfg.effect : 'fade-up';
		let label = 'full';
		if (device.lowPower || device.saveData) { factor *= 0.72; duration = Math.min(duration, 380); stagger = Math.min(stagger, 28); label = 'adaptive'; }
		if (device.veryLowPower) { factor *= 0.72; duration = Math.min(duration, 300); stagger = Math.min(stagger, 16); effect = 'fade'; label = 'ultra-light'; }
		return { factor, duration, stagger, effect, label };
	};
	const setStatus = (status, label = '') => { html.dataset.dbv9MotionStatus = status; if (label) html.dataset.dbv9MotionProfile = label; else delete html.dataset.dbv9MotionProfile; };
	const disconnect = () => { if (failsafe) win.clearTimeout(failsafe); failsafe = 0; if (observer) observer.disconnect(); observer = null; };
	const revealAll = (scope = doc) => {
		disconnect();
		for (const el of qsa('.dbv9-reveal:not(.dbv9-reveal--in)', scope)) { el.style.transition = 'none'; el.style.removeProperty('--dbv9-reveal-delay'); el.classList.add('dbv9-reveal--in'); }
	};
	const start = (scope = doc) => {
		disconnect();
		generation += 1;
		const run = generation;
		const kind = deviceClass();
		lastDevice = kind;
		if (!cfg.enabled) { setStatus('disabled-setting'); return; }
		if (!deviceEnabled(kind)) { setStatus('disabled-device'); return; }
		if (device.reducedMotion) { setStatus('reduced-motion'); revealAll(scope); return; }
		if (!('IntersectionObserver' in win)) { setStatus('unsupported'); return; }
		const p = profile(kind);
		setStatus('active', p.label);
		const intensity = Math.max(0, Math.min(100, Number(cfg.intensity || 45))) * p.factor;
		html.style.setProperty('--dbv9-motion-duration', p.duration + 'ms');
		html.style.setProperty('--dbv9-motion-distance', Math.round(3 + (intensity / 100) * 26) + 'px');
		html.style.setProperty('--dbv9-motion-scale-in', String(Math.max(0.95, 1 - (0.006 + (intensity / 100) * 0.028))));
		html.style.setProperty('--dbv9-motion-scale-out', String(Math.min(1.045, 1 + 0.006 + (intensity / 100) * 0.022)));
		const layouts = qsa('.delicat-page-layout[data-delicat-page-layout]', scope);
		if (!layouts.length) { setStatus('no-builder-layout', p.label); return; }
		const viewportH = Math.max(320, Number(win.innerHeight || html.clientHeight || 800));
		const candidates = [];
		for (const layout of layouts) {
			for (const section of Array.from(layout.children)) {
				if (!section.classList || !section.classList.contains('delicat-section') || section.classList.contains('dbv9-reveal--in')) continue;
				const rect = section.getBoundingClientRect();
				if (rect.top < viewportH * 0.88 || rect.height < 2) continue;
				candidates.push(section);
			}
		}
		if (!candidates.length) return;
		for (const section of candidates) section.classList.add('dbv9-reveal', 'dbv9-motion--' + p.effect);
		observer = new IntersectionObserver((entries) => {
			if (run !== generation) return;
			const incoming = entries.filter((entry) => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
			incoming.forEach((entry, index) => {
				const el = entry.target;
				el.style.setProperty('--dbv9-reveal-delay', (Math.max(0, Math.min(300, Number(cfg.baseDelay || 0))) + Math.min(index, 4) * p.stagger) + 'ms');
				el.classList.add('dbv9-reveal--in');
				if (observer) observer.unobserve(el);
			});
		}, { root: null, rootMargin: '0px 0px -3% 0px', threshold: Math.max(0.01, Math.min(0.3, Number(cfg.threshold || 0.04))) });
		for (const section of candidates) observer.observe(section);
		failsafe = win.setTimeout(() => revealAll(scope), 5500);
	};

	raf(() => start(root));
	on(win, 'pageshow', () => raf(() => start(root)), { passive: true, signal });
	on(win, 'resize', () => {
		if (resizeTimer) win.clearTimeout(resizeTimer);
		resizeTimer = win.setTimeout(() => { if (deviceClass() === lastDevice) return; revealAll(root); start(root); }, 180);
	}, { passive: true, signal });
	if (device.reduceQuery && device.reduceQuery.addEventListener) {
		const onChange = () => { revealAll(root); start(root); };
		device.reduceQuery.addEventListener('change', onChange);
		signal.addEventListener('abort', () => device.reduceQuery.removeEventListener('change', onChange), { once: true });
	}
	signal.addEventListener('abort', () => revealAll(root), { once: true });
}
