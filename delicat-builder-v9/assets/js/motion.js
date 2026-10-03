(() => {
	'use strict';

	if (window.DelicaBuilderV9Motion) return;
	window.DelicaBuilderV9Motion = true;

	const cfg = Object.assign({
		enabled: true,
		effect: 'fade-up',
		duration: 440,
		intensity: 45,
		stagger: 55,
		baseDelay: 0,
		threshold: 0.04,
		desktop: true,
		tablet: true,
		mobile: true,
		mobileIntensity: 70
	}, window.DelicaBuilderV9MotionConfig || {});

	const doc = document.documentElement;
	const reduceQuery = window.matchMedia?.('(prefers-reduced-motion: reduce)');
	const allowedEffects = new Set(['fade-up', 'fade', 'scale', 'slide-left', 'slide-right', 'soft-zoom']);
	let observer = null;
	let failsafeTimer = 0;
	let resizeTimer = 0;
	let generation = 0;
	let lastDeviceClass = '';

	const viewportWidth = () => Math.max(320, Number(window.innerWidth || doc.clientWidth || 1024));
	const deviceClass = () => {
		const width = viewportWidth();
		return width <= 640 ? 'mobile' : (width <= 960 ? 'tablet' : 'desktop');
	};
	const deviceEnabled = (kind) => kind === 'mobile' ? Boolean(cfg.mobile) : (kind === 'tablet' ? Boolean(cfg.tablet) : Boolean(cfg.desktop));
	const reducedMotion = () => Boolean(reduceQuery?.matches || doc.classList.contains('delicat-reduce-motion'));

	const performanceProfile = (kind) => {
		const memory = Number(navigator.deviceMemory || 8);
		const cores = Number(navigator.hardwareConcurrency || 8);
		const saveData = Boolean(navigator.connection?.saveData || doc.classList.contains('delicat-save-data'));
		const lowPower = Boolean(doc.classList.contains('delicat-low-power') || memory <= 4 || cores <= 4);
		const veryLowPower = Boolean(memory <= 2 || cores <= 2);
		const mobileFactor = kind === 'mobile' ? Math.max(0.2, Math.min(1, Number(cfg.mobileIntensity || 70) / 100)) : 1;

		let factor = mobileFactor;
		let duration = Math.max(180, Math.min(900, Number(cfg.duration || 440)));
		let stagger = Math.max(0, Math.min(150, Number(cfg.stagger || 55)));
		let effect = allowedEffects.has(cfg.effect) ? cfg.effect : 'fade-up';
		let label = 'full';

		if (lowPower || saveData) {
			factor *= 0.72;
			duration = Math.min(duration, 380);
			stagger = Math.min(stagger, 28);
			label = 'adaptive';
		}
		if (veryLowPower) {
			factor *= 0.72;
			duration = Math.min(duration, 300);
			stagger = Math.min(stagger, 16);

			if (effect !== 'fade') effect = 'fade';
			label = 'ultra-light';
		}

		return { factor, duration, stagger, effect, label };
	};

	const setStatus = (status, profile = '') => {
		doc.dataset.dbv9MotionStatus = status;
		if (profile) doc.dataset.dbv9MotionProfile = profile;
		else delete doc.dataset.dbv9MotionProfile;
	};

	const disconnect = () => {
		if (failsafeTimer) clearTimeout(failsafeTimer);
		failsafeTimer = 0;
		observer?.disconnect();
		observer = null;
	};

	const revealAll = (scope = document) => {
		disconnect();
		scope.querySelectorAll?.('.dbv9-reveal:not(.dbv9-reveal--in)').forEach((el) => {
			el.style.transition = 'none';
			el.style.removeProperty('--dbv9-reveal-delay');
			el.classList.add('dbv9-reveal--in');
		});
	};

	const sectionChildren = (layout) => Array.from(layout?.children || []).filter((el) => el?.classList?.contains('delicat-section'));

	const start = (scope = document) => {
		disconnect();
		generation += 1;
		const run = generation;
		const kind = deviceClass();
		lastDeviceClass = kind;

		if (!cfg.enabled) {
			setStatus('disabled-setting');
			return;
		}
		if (!deviceEnabled(kind)) {
			setStatus('disabled-device');
			return;
		}
		if (reducedMotion()) {
			setStatus('reduced-motion');
			revealAll(scope);
			return;
		}
		if (!('IntersectionObserver' in window)) {
			setStatus('unsupported');
			return;
		}

		const profile = performanceProfile(kind);
		setStatus('active', profile.label);
		const baseIntensity = Math.max(0, Math.min(100, Number(cfg.intensity || 45)));
		const intensity = baseIntensity * profile.factor;
		const distance = Math.round(3 + (intensity / 100) * 26);
		const scaleIn = Math.max(0.95, 1 - (0.006 + (intensity / 100) * 0.028));
		const scaleOut = Math.min(1.045, 1 + 0.006 + (intensity / 100) * 0.022);

		doc.style.setProperty('--dbv9-motion-duration', profile.duration + 'ms');
		doc.style.setProperty('--dbv9-motion-distance', distance + 'px');
		doc.style.setProperty('--dbv9-motion-scale-in', String(scaleIn));
		doc.style.setProperty('--dbv9-motion-scale-out', String(scaleOut));

		const layouts = scope.querySelectorAll?.('.delicat-page-layout[data-delicat-page-layout]') || [];
		if (!layouts.length) {
			setStatus('no-builder-layout', profile.label);
			return;
		}

		const viewportH = Math.max(320, Number(window.innerHeight || doc.clientHeight || 800));
		const candidates = [];
		layouts.forEach((layout) => {
			sectionChildren(layout).forEach((section) => {
				if (section.classList.contains('dbv9-reveal--in')) return;
				const rect = section.getBoundingClientRect();

				if (rect.top < viewportH * 0.88 || rect.height < 2) return;
				candidates.push({ section, top: rect.top });
			});
		});

		if (!candidates.length) return;
		candidates.forEach(({ section }) => {
			section.classList.add('dbv9-reveal', 'dbv9-motion--' + profile.effect);
		});

		observer = new IntersectionObserver((entries) => {
			if (run !== generation) return;
			const incoming = entries
				.filter((entry) => entry.isIntersecting)
				.sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);

			incoming.forEach((entry, index) => {
				const el = entry.target;
				const baseDelay = Math.max(0, Math.min(300, Number(cfg.baseDelay || 0)));
				el.style.setProperty('--dbv9-reveal-delay', (baseDelay + Math.min(index, 4) * profile.stagger) + 'ms');
				el.classList.add('dbv9-reveal--in');
				observer?.unobserve(el);
			});
		}, {
			root: null,
			rootMargin: '0px 0px -3% 0px',
			threshold: Math.max(0.01, Math.min(0.3, Number(cfg.threshold || 0.04)))
		});

		candidates.forEach(({ section }) => observer.observe(section));

		failsafeTimer = window.setTimeout(() => revealAll(scope), 5500);
	};

	const boot = () => window.requestAnimationFrame(() => start(document));
	window.addEventListener('pageshow', () => window.requestAnimationFrame(() => start(document)), { passive: true });
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
	else boot();

	document.addEventListener('delicat:navigation-complete', () => {
		revealAll(document);
		window.requestAnimationFrame(() => start(document));
	});

	window.addEventListener('resize', () => {
		if (resizeTimer) clearTimeout(resizeTimer);
		resizeTimer = window.setTimeout(() => {
			if (deviceClass() === lastDeviceClass) return;
			revealAll(document);
			start(document);
		}, 180);
	}, { passive: true });

	if (reduceQuery?.addEventListener) {
		reduceQuery.addEventListener('change', () => {
			revealAll(document);
			start(document);
		});
	}
})();
