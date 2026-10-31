(function () {
	'use strict';
	if (window.DelicaBuilderV9Islands) { return; }

	var cfg = window.DelicaBuilderV9 || {};
	var modules = cfg.modules || {};
	var loaded = {};

	function safeSrc(value) {
		var raw = String(value || '');
		if (!raw) { return ''; }
		var a = document.createElement('a');
		a.href = raw;
		var sameOrigin = a.protocol === location.protocol && a.host === location.host;
		var httpish = a.protocol === 'https:' || a.protocol === 'http:';
		return sameOrigin && httpish ? a.href : '';
	}

	function loadScript(name) {
		if (name === 'core' && window.DelicaBuilderV9Core) { return resolved(); }
		if (name === 'carousel' && window.DelicaBuilderV9Carousel) { return resolved(); }
		if (name === 'heart' && window.DelicaBuilderV9Heart) { return resolved(); }
		if (loaded[name]) { return loaded[name]; }
		var src = safeSrc(modules[name]);
		if (!src) { return resolved(); }
		var task = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = src;
			script.defer = true;
			script.setAttribute('data-delicat-island-module', name);
			script.addEventListener('load', resolve, { once: true });
			script.addEventListener('error', reject, { once: true });
			document.head.appendChild(script);
		});
		loaded[name] = task;
		return task;
	}

	function resolved() {
		return Promise.resolve();
	}

	function hydrateCarousel() {
		loadScript('core').then(function () {
			return loadScript('carousel');
		}).then(function () {
			if (cfg.heart && cfg.heart.enabled) { return loadScript('heart'); }
		})['catch'](function () {});
	}

	var observer = ('IntersectionObserver' in window)
		? new IntersectionObserver(function (entries) {
			for (var i = 0; i < entries.length; i++) {
				if (!entries[i].isIntersecting) { continue; }
				observer.unobserve(entries[i].target);
				hydrateCarousel();
			}
		}, { rootMargin: '850px 0px', threshold: 0.01 })
		: null;

	function scan(scope) {
		var root = scope && scope.querySelectorAll ? scope : document;
		var nodes = root.querySelectorAll('[data-delicat-island="carousel"]');
		for (var i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			if (node.getAttribute('data-dlc-island-seen')) { continue; }
			node.setAttribute('data-dlc-island-seen', '1');
			if (!observer) {
				hydrateCarousel();
				continue;
			}
			var rect = node.getBoundingClientRect();
			var viewH = window.innerHeight || document.documentElement.clientHeight;
			if (rect.top <= viewH * 1.7 && rect.bottom >= -300) {
				hydrateCarousel();
			} else {
				observer.observe(node);
			}
		}
	}

	function urgentHydrate(event) {
		var target = event.target;
		if (target && target.nodeType === 3) { target = target.parentNode; }
		if (target && target.closest && target.closest('[data-delicat-island="carousel"]')) {
			hydrateCarousel();
		}
	}
	document.addEventListener('pointerdown', urgentHydrate, { passive: true, capture: true });
	document.addEventListener('focusin', urgentHydrate, { passive: true, capture: true });
	document.addEventListener('delicat:navigation-complete', function () { scan(document); });

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { scan(document); }, { once: true });
	} else {
		scan(document);
	}

	window.DelicaBuilderV9Islands = { scan: scan, hydrateCarousel: hydrateCarousel };
})();
