/* Delicat Builder 9.4.2 - real-user speed measurement (loaded for a sampled share of page views,
 * after the page has loaded). Reads the browser's own performance entries and sends one small
 * beacon when the page is hidden: the kind of browser (app, in-app browser, web), whether the
 * phone is a modest one, and the timings. No cookie, no URL, no identifier. ES5 for old WebViews. */
(function () {
	'use strict';
	var w = window, d = document, n = navigator;
	if (w.__dlcRum) { return; }
	w.__dlcRum = 1;
	var me = d.currentScript || d.querySelector('script[data-endpoint][src*="rum.js"]');
	var ep = me && me.getAttribute('data-endpoint');
	if (!ep || !w.PerformanceObserver || !n.sendBeacon || !w.performance) { return; }

	var nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null;
	var act = nav && nav.activationStart > 0 ? nav.activationStart : 0; /* prerendered: count from activation */
	var m = { lcp: 0, fcp: 0, ttfb: nav ? Math.max(0, nav.responseStart - act) : 0, cls: 0 };
	var inter = {}, soft = [], sent = false, navStart = 0;

	function watch(type, cb, extra) {
		try {
			var o = new PerformanceObserver(function (list) { list.getEntries().forEach(cb); });
			var opts = { type: type, buffered: true };
			if (extra) { for (var k in extra) { opts[k] = extra[k]; } }
			o.observe(opts);
		} catch (e) { /* entry type not supported here */ }
	}
	watch('largest-contentful-paint', function (e) { m.lcp = Math.max(m.lcp, e.startTime - act); });
	watch('paint', function (e) { if (e.name === 'first-contentful-paint') { m.fcp = Math.max(0, e.startTime - act); } });
	/* CLS: the largest burst of shifts (1 s apart at most, 5 s long at most), input-driven ones excluded. */
	var sv = 0, s0 = 0, s1 = 0;
	watch('layout-shift', function (e) {
		if (e.hadRecentInput) { return; }
		if (sv && e.startTime - s1 < 1000 && e.startTime - s0 < 5000) { sv += e.value; } else { sv = e.value; s0 = e.startTime; }
		s1 = e.startTime;
		if (sv > m.cls) { m.cls = sv; }
	});
	/* INP: the slowest interaction (one in 50 skipped on busy pages, like Chrome does). */
	function ev(e) { if (e.interactionId) { inter[e.interactionId] = Math.max(inter[e.interactionId] || 0, e.duration); } }
	watch('event', ev, { durationThreshold: 40 });
	watch('first-input', ev);
	function inp() {
		var list = [], k;
		for (k in inter) { if (Object.prototype.hasOwnProperty.call(inter, k)) { list.push(inter[k]); } }
		if (!list.length) { return 0; }
		list.sort(function (a, b) { return b - a; });
		return list[Math.min(list.length - 1, Math.floor(list.length / 50))];
	}
	/* The engine's own navigations: tap to new page shown. */
	d.addEventListener('delicat:navigate', function () { navStart = performance.now(); });
	d.addEventListener('delicat:navigated', function () {
		if (navStart && soft.length < 20) { soft.push(Math.round(performance.now() - navStart)); }
		navStart = 0;
	});

	var ua = n.userAgent || '';
	var kind = /FBAN|FBAV|FB_IAB|Instagram|musical_ly|TikTok|BytedanceWebview|Snapchat/i.test(ua) ? 'social' : (/; ?wv\)/.test(ua) ? 'app' : 'web');
	function low() {
		var mem = Number(n.deviceMemory || 0), c = n.connection || {};
		return (mem > 0 && mem <= 2) || d.documentElement.classList.contains('delicat-low-power') || /2g/.test(c.effectiveType || '') ? 1 : 0;
	}

	function send() {
		var p = { k: kind, lo: low() };
		if (!sent) {
			p.f = 1;
			p.lcp = Math.round(m.lcp); p.fcp = Math.round(m.fcp); p.ttfb = Math.round(m.ttfb);
			p.cls = Math.round(m.cls * 1000) / 1000; p.inp = Math.round(inp());
		} else if (!soft.length) {
			return;
		}
		if (soft.length) { p.nav = soft; soft = []; }
		try { if (n.sendBeacon(ep, JSON.stringify(p))) { sent = true; } } catch (e) { /* page leaving */ }
	}
	d.addEventListener('visibilitychange', function () { if (d.visibilityState === 'hidden') { send(); } });
	w.addEventListener('pagehide', send);
}());
