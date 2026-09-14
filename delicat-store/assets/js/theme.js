/**
 * Delicat Store — storefront runtime.
 *
 * Deliberately small. Every navigation is a real page load; there is no
 * router. This file handles: the drawer, the colour-scheme toggle, help
 * dialogs on product fields, cart-count feedback after an AJAX add, and
 * the checkout wallet hint.
 */
(function () {
	'use strict';

	var doc = document;
	var root = doc.documentElement;
	root.classList.remove('no-js');

	/* ---- Colour scheme ------------------------------------------------ */
	function prefersDark() {
		return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
	}
	function currentTheme() {
		var forced = root.getAttribute('data-theme');
		if (forced === 'dark' || forced === 'light') { return forced; }
		return prefersDark() ? 'dark' : 'light';
	}
	function setTheme(next) {
		root.setAttribute('data-theme', next);
		try { localStorage.setItem('ds-theme', next); } catch (e) { /* private mode */ }
		var meta = doc.querySelector('meta[name="theme-color"]:not([media])');
		if (!meta) {
			meta = doc.createElement('meta');
			meta.name = 'theme-color';
			doc.head.appendChild(meta);
		}
		var styles = getComputedStyle(root);
		meta.content = next === 'dark' ? '#141726' : (styles.getPropertyValue('--ds-surface').trim() || '#ffffff');
	}
	doc.addEventListener('click', function (event) {
		var toggle = event.target.closest('[data-ds-theme-toggle]');
		if (!toggle) { return; }
		setTheme(currentTheme() === 'dark' ? 'light' : 'dark');
	});

	/* ---- Drawer -------------------------------------------------------- */
	var drawer = doc.querySelector('[data-ds-drawer]');
	var lastFocus = null;
	function openDrawer() {
		if (!drawer) { return; }
		lastFocus = doc.activeElement;
		drawer.hidden = false;
		doc.body.classList.add('ds-drawer-open');
		var opener = doc.querySelector('[data-ds-drawer-open]');
		if (opener) { opener.setAttribute('aria-expanded', 'true'); }
		var first = drawer.querySelector('[data-ds-drawer-close]');
		if (first) { first.focus(); }
	}
	function closeDrawer() {
		if (!drawer || drawer.hidden) { return; }
		drawer.hidden = true;
		doc.body.classList.remove('ds-drawer-open');
		var opener = doc.querySelector('[data-ds-drawer-open]');
		if (opener) { opener.setAttribute('aria-expanded', 'false'); }
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}
	doc.addEventListener('click', function (event) {
		if (event.target.closest('[data-ds-drawer-open]')) { event.preventDefault(); openDrawer(); return; }
		if (event.target.closest('[data-ds-drawer-close]')) { event.preventDefault(); closeDrawer(); }
	});
	doc.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') { closeDrawer(); }
	});
	window.addEventListener('pageshow', closeDrawer);

	/* ---- Help dialogs (product fields) -------------------------------- */
	doc.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ds-help]');
		if (!button) { return; }
		var dialog = doc.getElementById(button.getAttribute('data-ds-help'));
		if (!dialog) { return; }
		if (typeof dialog.showModal === 'function') {
			dialog.showModal();
		} else {
			dialog.setAttribute('open', '');
		}
	});
	doc.addEventListener('click', function (event) {
		var closer = event.target.closest('[data-ds-help-close]');
		if (closer) {
			var owner = closer.closest('dialog');
			if (owner) { owner.close ? owner.close() : owner.removeAttribute('open'); }
			return;
		}
		var dialog = event.target.closest('dialog.ds-help');
		if (!dialog || !dialog.open) { return; }
		var rect = dialog.getBoundingClientRect();
		var outside = event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
		if (outside) { dialog.close(); }
	});

	/* ---- AJAX add-to-cart feedback ------------------------------------ */
	if (window.jQuery) {
		window.jQuery(doc.body).on('added_to_cart', function (event, fragments, hash, button) {
			doc.body.classList.add('ds-adding');
			setTimeout(function () { doc.body.classList.remove('ds-adding'); }, 500);
			if (button && button.length) {
				var el = button[0];
				el.classList.add('added');
				setTimeout(function () { el.classList.remove('added'); }, 1600);
			}
		});

		/* Checkout: when the gateway declines for an empty wallet, surface the
		   top-up hint above WooCommerce's own message (never instead of it). */
		window.jQuery(doc.body).on('checkout_error', function () {
			var hint = doc.querySelector('[data-ds-wallet-hint]');
			var errors = doc.querySelector('.woocommerce-error');
			if (!hint || !errors) { return; }
			var text = (errors.textContent || '').toLowerCase();
			var lowFunds = /solde|insuffisant|insufficient|balance|fonds|wallet/.test(text);
			hint.hidden = !lowFunds;
		});
	}

	/* ---- Rails: keyboard-friendly horizontal scroll ------------------- */
	doc.querySelectorAll('.ds-products--rail').forEach(function (rail) {
		rail.setAttribute('tabindex', '0');
		rail.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowRight') { rail.scrollBy({ left: rail.clientWidth * 0.8, behavior: 'smooth' }); }
			if (event.key === 'ArrowLeft') { rail.scrollBy({ left: -rail.clientWidth * 0.8, behavior: 'smooth' }); }
		});
	});
})();
