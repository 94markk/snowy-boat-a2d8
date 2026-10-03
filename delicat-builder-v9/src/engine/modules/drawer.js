/**
 * Menu drawer ([data-dlx-drawer]). Opens from the header hamburger
 * ([data-dsb8-menu-trigger], [data-dlx-open], .dsb-menu-toggle) or the
 * header's dsb8:overlay:open event; closes on backdrop, ×, Escape, swipe or
 * any link tap. Focus is trapped, the page behind is inert, scroll is locked
 * without a layout jump. Same markup and classes as drawer.js (RC29).
 */
import { closest, doc, emit, focusables, html, on, qsa, raf, tryFocus, win } from '../core/dom.js';
import { session } from '../core/state.js';

export default function mount({ signal }) {
	const drawer = doc.querySelector('[data-dlx-drawer]');
	if (!drawer) return;
	const panel = drawer.querySelector('[data-dlx-panel]');
	const scroller = drawer.querySelector('[data-dlx-scroll]');
	const search = drawer.querySelector('[data-dlx-search]');
	const empty = drawer.querySelector('[data-dlx-empty]');
	let open = false, lastFocus = null, lockY = 0, savedTop = '', closeTimer = 0, enterTimer = 0, drag = null, pointerOpenAt = 0, swipeEndedAt = 0;
	let inertNodes = [];
	html.classList.add('dlx-ready');

	const setInert = (onState) => {
		if (onState) {
			if (inertNodes.length) return;
			for (const node of Array.from(doc.body.children)) {
				if (node === drawer || node.contains(drawer) || /^(SCRIPT|STYLE|LINK)$/.test(node.tagName)) continue;
				inertNodes.push({ node, inert: node.inert, aria: node.getAttribute('aria-hidden') });
				node.inert = true;
				node.setAttribute('aria-hidden', 'true');
			}
			return;
		}
		for (const saved of inertNodes) {
			saved.node.inert = saved.inert;
			if (saved.aria === null) saved.node.removeAttribute('aria-hidden'); else saved.node.setAttribute('aria-hidden', saved.aria);
		}
		inertNodes = [];
	};
	const expanded = (value) => {
		for (const opener of qsa('[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle')) {
			opener.setAttribute('aria-expanded', value ? 'true' : 'false');
			opener.setAttribute('aria-controls', drawer.id || 'delicat-drawer');
		}
	};
	const lock = () => { lockY = win.pageYOffset || 0; savedTop = doc.body.style.top; html.classList.add('dlx-open'); doc.body.style.top = (-lockY) + 'px'; };
	const unlock = () => { html.classList.remove('dlx-open'); doc.body.style.top = savedTop; win.scrollTo({ top: lockY, left: 0, behavior: 'instant' }); };

	/* Park the panel laid out but invisible while idle, so the first tap only runs a transform. */
	const warm = () => { if (open) return; drawer.classList.add('is-warm'); drawer.style.visibility = 'hidden'; drawer.style.pointerEvents = 'none'; drawer.hidden = false; drawer.setAttribute('aria-hidden', 'true'); };
	const unwarm = () => { drawer.classList.remove('is-warm'); drawer.style.visibility = ''; drawer.style.pointerEvents = ''; };

	const entered = () => {
		if (enterTimer) { win.clearTimeout(enterTimer); enterTimer = 0; }
		if (panel) panel.removeEventListener('transitionend', onEnterEnd);
		drawer.classList.remove('is-entering');
		if (open) session.refresh('drawer-open');
	};
	function onEnterEnd(event) { if (event.target === panel && event.propertyName === 'transform') entered(); }
	const park = () => { if (closeTimer) { win.clearTimeout(closeTimer); closeTimer = 0; } if (panel) panel.removeEventListener('transitionend', onCloseEnd); if (!open) warm(); };
	function onCloseEnd(event) { if (event.target === panel && event.propertyName === 'transform') park(); }

	const show = () => {
		if (open) return;
		open = true;
		expanded(true);
		lastFocus = doc.activeElement;
		if (closeTimer) { win.clearTimeout(closeTimer); closeTimer = 0; }
		if (panel) panel.removeEventListener('transitionend', onCloseEnd);
		drawer.hidden = false;
		unwarm();
		drawer.setAttribute('aria-hidden', 'false');
		drawer.classList.add('is-entering');
		lock();
		if (scroller) scroller.scrollTop = 0;
		raf(() => {
			if (!open) return;
			drawer.classList.add('is-open');
			if (enterTimer) win.clearTimeout(enterTimer);
			enterTimer = win.setTimeout(entered, 240);
			if (panel) panel.addEventListener('transitionend', onEnterEnd);
			raf(() => { if (!open) return; setInert(true); tryFocus(panel ? (panel.querySelector('[data-dlx-close]') || panel) : drawer); });
		});
		emit('dlx:open');
	};
	const hide = (restoreFocus) => {
		if (!open) return;
		open = false;
		expanded(false);
		drag = null;
		entered();
		drawer.classList.remove('is-open', 'is-dragging');
		if (panel) panel.style.transform = '';
		setInert(false);
		unlock();
		if (closeTimer) win.clearTimeout(closeTimer);
		closeTimer = win.setTimeout(park, 240);
		if (panel) panel.addEventListener('transitionend', onCloseEnd);
		if (restoreFocus !== false && lastFocus) tryFocus(lastFocus);
		emit('dlx:close');
	};

	const filter = (value) => {
		const q = String(value || '').trim().toLowerCase();
		let shown = 0;
		for (const item of qsa('.dlx-item', drawer)) {
			const hit = !q || (item.getAttribute('data-dlx-filter') || '').indexOf(q) !== -1;
			item.parentNode.hidden = !hit;
			if (hit) shown++;
		}
		for (const section of qsa('[data-dlx-section]', drawer)) {
			const visible = section.querySelectorAll('li:not([hidden]) > .dlx-item').length;
			section.hidden = !!q && !visible;
			if (q) section.classList.remove('is-collapsed');
		}
		drawer.classList.toggle('is-filtering', !!q);
		if (empty) empty.hidden = !q || shown > 0;
	};

	const endDrag = (commit) => {
		if (!drag) return;
		const d = drag; drag = null;
		drawer.classList.remove('is-dragging');
		if (d.on) swipeEndedAt = Date.now();
		if (commit && d.on && panel && Math.abs(d.dx) > Math.min(110, panel.offsetWidth * 0.3)) { hide(); return; }
		if (panel) panel.style.transform = '';
	};
	const onDown = (event) => {
		if (!open || event.pointerType === 'mouse' || event.button || event.isPrimary === false) return;
		if (closest(event.target, 'input,textarea,select')) return;
		drag = { x: event.clientX, y: event.clientY, dx: 0, on: false, id: event.pointerId, left: drawer.classList.contains('is-left'), frame: false };
		try { if (panel.setPointerCapture) panel.setPointerCapture(event.pointerId); } catch (_) {}
	};
	const onMove = (event) => {
		if (!drag || event.pointerId !== drag.id) return;
		const dx = event.clientX - drag.x, dy = event.clientY - drag.y;
		if (!drag.on && Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx)) { endDrag(false); return; }
		if (!drag.on) { if (Math.abs(dx) < 10 || Math.abs(dx) < Math.abs(dy)) return; drag.on = true; drawer.classList.add('is-dragging'); }
		drag.dx = drag.left ? Math.min(0, dx) : Math.max(0, dx);
		if (!drag.frame) {
			const active = drag; drag.frame = true;
			raf(() => { active.frame = false; if (drag === active && open) panel.style.transform = 'translateX(' + active.dx + 'px)'; });
		}
		if (event.cancelable) event.preventDefault();
	};
	const onUp = (event) => {
		if (drag && event && event.pointerId !== drag.id) return;
		endDrag(true);
		if (event && event.pointerId !== undefined) { try { if (panel.hasPointerCapture && panel.hasPointerCapture(event.pointerId)) panel.releasePointerCapture(event.pointerId); } catch (_) {} }
	};

	const copy = (button) => {
		const value = button.getAttribute('data-dlx-copy') || '';
		const label = button.querySelector('[data-dlx-copy-label]');
		const done = () => { if (!label) return; const was = label.textContent; label.textContent = 'Copié ✓'; button.classList.add('is-done'); win.setTimeout(() => { label.textContent = was; button.classList.remove('is-done'); }, 1400); };
		if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done, done);
		else { const ta = doc.createElement('textarea'); ta.value = value; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0'; doc.body.appendChild(ta); ta.select(); try { doc.execCommand('copy'); } catch (_) {} ta.remove(); done(); }
	};

	/* Open on pointerdown so touch devices respond before the delayed click. */
	on(doc, 'pointerdown', (event) => {
		const trigger = closest(event.target, '[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle');
		if (!trigger || open || event.button || event.isPrimary === false) return;
		pointerOpenAt = Date.now();
		event.preventDefault();
		show();
	}, { capture: true, signal });

	on(doc, 'click', (event) => {
		const t = event.target;
		if (!t || t.nodeType !== 1) return;
		if (pointerOpenAt) {
			const age = Date.now() - pointerOpenAt; pointerOpenAt = 0;
			if (age < 700) { event.preventDefault(); event.stopImmediatePropagation(); return; }
		}
		if (swipeEndedAt) {
			const since = Date.now() - swipeEndedAt; swipeEndedAt = 0;
			if (since < 700) { event.preventDefault(); event.stopImmediatePropagation(); return; }
		}
		if (closest(t, '[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle')) { event.preventDefault(); event.stopImmediatePropagation(); open ? hide() : show(); return; }
		if (!open) return;
		if (closest(t, '[data-dlx-close]')) { event.preventDefault(); hide(); return; }
		const toggle = closest(t, '[data-dlx-toggle]');
		if (toggle && drawer.contains(toggle)) { const section = closest(toggle, '[data-dlx-section]'); if (section) { const collapsed = section.classList.toggle('is-collapsed'); toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true'); } return; }
		const cp = closest(t, '[data-dlx-copy]');
		if (cp && drawer.contains(cp)) { event.preventDefault(); copy(cp); return; }
		const inst = closest(t, '[data-dlx-install]');
		if (inst && drawer.contains(inst)) { event.preventDefault(); hide(); emit('delicat:install-app'); return; }
		const link = closest(t, 'a[href]');
		if (link && drawer.contains(link) && !link.target) { const href = link.getAttribute('href') || ''; if (href.charAt(0) !== '#') hide(); }
	}, { capture: true, signal });

	on(doc, 'dsb:close-menu', () => hide(false), { signal });
	on(doc, 'delicat:navigate', () => hide(false), { signal });
	on(win, 'resize', () => endDrag(false), { passive: true, signal });
	on(doc, 'dsb8:overlay:open', (event) => { if (event.detail && event.detail.id === 'modern-menu') { event.stopImmediatePropagation(); show(); } }, { capture: true, signal });
	on(doc, 'keydown', (event) => {
		if (!open) return;
		if (event.key === 'Escape') { event.preventDefault(); hide(); return; }
		if (event.key === 'Tab') {
			const f = focusables(panel || drawer); if (!f.length) return;
			const first = f[0], last = f[f.length - 1];
			if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
			else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
		}
	}, { signal });
	if (search) {
		on(search, 'input', () => filter(search.value), { signal });
		on(search, 'keydown', (event) => { if (event.key === 'Enter' && !search.value.trim()) event.preventDefault(); }, { signal });
	}
	if (win.PointerEvent && panel) {
		on(panel, 'pointerdown', onDown, { passive: true, signal });
		on(panel, 'pointermove', onMove, { passive: false, signal });
		on(panel, 'pointerup', onUp, { passive: true, signal });
		on(panel, 'pointercancel', () => endDrag(false), { passive: true, signal });
		on(panel, 'lostpointercapture', () => endDrag(false), { passive: true, signal });
	}
	on(win, 'pageshow', (event) => { if (event.persisted && open) hide(); }, { signal });
	if (win.requestIdleCallback) win.requestIdleCallback(warm, { timeout: 2500 }); else win.setTimeout(warm, 900);

	win.DelicatDrawer = { open: show, close: hide, isOpen: () => open };
}
