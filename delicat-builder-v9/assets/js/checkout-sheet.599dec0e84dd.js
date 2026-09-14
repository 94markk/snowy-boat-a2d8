/* =============================================================================
   checkout-sheet.js — "Acheter maintenant" opens the checkout in a sheet.
   -----------------------------------------------------------------------------
   Read the class comment in class-delicat-builder-checkout-sheet.php first; it
   explains why this is a panel around WooCommerce's own form and not a checkout.

   The whole of this file's responsibility:

     1. take the POST the button was going to send anyway, and send it by fetch
     2. lift form.checkout out of the reply and put it in the sheet
     3. tell WooCommerce its form has arrived
     4. when WooCommerce reports a decline, show it — and if it reads like an
        empty wallet, offer the top-up page as well

   Everything else is WooCommerce's. In particular this file never reads a
   price, never mints a nonce, never decides whether a payment may proceed, and
   never posts anywhere except the form's own action.

   FAILURE IS ALWAYS THE OLD BEHAVIOUR
   Every early return below abandons the interception and lets the form submit
   normally, which lands the customer on the real checkout page. There is no
   path where this file failing stops someone buying something.
   ========================================================================== */

(function () {
	'use strict';

	var config = window.DelicatCheckoutSheet || {};
	var i18n = config.i18n || {};

	var sheet = document.getElementById('dcs-sheet');
	var jq = window.jQuery;

	/* No sheet, no jQuery, or no WooCommerce checkout script: the button keeps
	   doing exactly what it does today. */
	if (!sheet || !jq || typeof sheet.showModal !== 'function') return;

	var body = sheet.querySelector('[data-dcs-body]');
	var busy = false;

	/* ---------------------------------------------------------------------
	   Opening
	   ------------------------------------------------------------------ */

	function open() {
		if (sheet.open) return;
		sheet.showModal();
		document.documentElement.style.overflow = 'hidden';
	}

	function close() {
		if (sheet.open) sheet.close();
	}

	sheet.addEventListener('close', function () {
		document.documentElement.style.overflow = '';
		busy = false;
	});

	document.addEventListener('click', function (event) {
		if (event.target.closest && event.target.closest('[data-dcs-close]')) {
			event.preventDefault();
			close();
		}
	});

	/* A tap outside the panel closes it. The backdrop is outside the dialog's
	   own box, so a click whose coordinates fall outside that box is a backdrop
	   click — no extra element, no listener to leak. */
	sheet.addEventListener('click', function (event) {
		if (event.target !== sheet) return;
		var box = sheet.getBoundingClientRect();
		var inside =
			event.clientX >= box.left && event.clientX <= box.right &&
			event.clientY >= box.top && event.clientY <= box.bottom;
		if (!inside) close();
	});

	/* ---------------------------------------------------------------------
	   The interception
	   ------------------------------------------------------------------ */

	/*
	 * Captured at the form, not the button, because a customer can also submit
	 * with Enter from a field — and because the button's own name/value has to
	 * travel with the request. A submit event carries the submitter, so the
	 * FormData below is built exactly as the browser would have built it.
	 */
	document.addEventListener(
		'submit',
		function (event) {
			var form = event.target;
			if (!form || !form.classList || !form.classList.contains('cart')) return;

			var submitter = event.submitter;
			if (!submitter || submitter.name !== 'delicat_native_buy_now') return;

			/* Already working on one: let the browser do the normal thing rather
			   than firing a second request at the cart. */
			if (busy) return;

			/* A variable product with nothing chosen has no variation_id yet.
			   WooCommerce says so far better than we could, so let it: submit
			   normally and let the real page explain. */
			var variation = form.querySelector('input[name="variation_id"]');
			if (variation && form.classList.contains('variations_form') && !variation.value) return;

			event.preventDefault();
			busy = true;
			start(form, submitter);
		},
		true
	);

	function start(form, submitter) {
		reset();
		open();

		var data = new FormData(form);
		/* The submitter's own name/value is not in FormData unless we add it -
		   and it is what tells the server this was "buy now" rather than "add to
		   cart", which is what triggers the redirect to checkout. */
		if (submitter.name) data.append(submitter.name, submitter.value || '1');

		fetch(form.action || window.location.href, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			redirect: 'follow',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		})
			.then(function (response) {
				if (!response.ok) throw new Error('http ' + response.status);
				return response.text();
			})
			.then(function (html) {
				var checkout = extractCheckout(html);
				if (!checkout) throw new Error('no checkout form in the response');
				mount(checkout, html);
			})
			.catch(function () {
				/* Whatever went wrong, the page that can definitely handle this
				   is the checkout page. Go there. */
				busy = false;
				close();
				window.location.assign(config.checkoutUrl || form.action || window.location.href);
			});
	}

	/**
	 * Pull WooCommerce's checkout form out of the response.
	 *
	 * Parsed with DOMParser into an inert document: nothing in it executes, no
	 * image in it is fetched, and no script in it runs. Only the form element
	 * is adopted, so a reply that is not the checkout page yields nothing and
	 * the catch above sends the customer to the real one.
	 */
	function extractCheckout(html) {
		var doc;
		try {
			doc = new DOMParser().parseFromString(html, 'text/html');
		} catch (error) {
			return null;
		}

		var form = doc.querySelector('form.checkout');
		if (!form) return null;

		/* Scripts inside the fetched markup are dropped. WooCommerce's own
		   checkout script is already on this page, enqueued by handle with its
		   own localised parameters; anything else that rode along is not
		   something a product page asked for. */
		form.querySelectorAll('script').forEach(function (node) { node.remove(); });
		return form;
	}

	function mount(form, html) {
		reset();

		/* Any notice WooCommerce put above the form - a coupon message, a stock
		   warning - travels with it, because the customer needs to read it. */
		var notices = extractNotices(html);
		if (notices) body.appendChild(notices);

		body.appendChild(form);

		/*
		 * The one line that hands over control.
		 *
		 * WooCommerce binds its checkout behaviour on this event: field
		 * validation, the payment method panels, the order review refresh, and
		 * the AJAX submit to its own wc-ajax=checkout endpoint. After this, the
		 * form in the sheet behaves exactly as the form on the checkout page,
		 * because it IS that form with that script attached.
		 */
		jq(document.body).trigger('init_checkout');
		jq(document.body).trigger('update_checkout');

		busy = false;

		var first = form.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
		if (first) {
			try { first.focus({ preventScroll: true }); } catch (error) { /* older browsers */ }
		}
	}

	function extractNotices(html) {
		var doc;
		try {
			doc = new DOMParser().parseFromString(html, 'text/html');
		} catch (error) {
			return null;
		}

		var found = doc.querySelectorAll('.woocommerce-error, .woocommerce-message, .woocommerce-info');
		if (!found.length) return null;

		var wrap = document.createElement('div');
		wrap.className = 'dcs__notices';
		found.forEach(function (node) {
			node.querySelectorAll('script').forEach(function (s) { s.remove(); });
			wrap.appendChild(node);
		});
		return wrap;
	}

	function reset() {
		while (body.firstChild) body.removeChild(body.firstChild);
	}

	/* ---------------------------------------------------------------------
	   Declines
	   ------------------------------------------------------------------ */

	/*
	 * WooCommerce announces every checkout failure on this event, whatever
	 * caused it: a validation error, a gateway refusal, an empty wallet. The
	 * message is the gateway's own and is always shown — WooCommerce has
	 * already put it at the top of the form by the time this runs.
	 *
	 * All this adds is the next step for one specific case. A customer told
	 * "solde insuffisant" on a page with no way to top up is a customer who
	 * leaves; the same customer with a button to the wallet is a customer who
	 * comes back.
	 */
	jq(document.body).on('checkout_error', function (event, message) {
		var text = String(message || '')
			.replace(/<[^>]*>/g, ' ')
			.toLowerCase();

		if (!looksLikeLowFunds(text)) return;
		if (!config.walletUrl) return;

		showLowFunds();
	});

	function looksLikeLowFunds(text) {
		var needles = config.lowFunds || [];
		for (var i = 0; i < needles.length; i += 1) {
			if (text.indexOf(String(needles[i]).toLowerCase()) !== -1) return true;
		}
		return false;
	}

	function showLowFunds() {
		if (body.querySelector('[data-dcs-low]')) return;

		var panel = document.createElement('div');
		panel.className = 'dcs__low';
		panel.setAttribute('data-dcs-low', '');
		panel.setAttribute('role', 'alert');

		var icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		icon.setAttribute('class', 'dcs__low-icon');
		icon.setAttribute('viewBox', '0 0 24 24');
		icon.setAttribute('width', '28');
		icon.setAttribute('height', '28');
		icon.setAttribute('fill', 'none');
		icon.setAttribute('stroke', 'currentColor');
		icon.setAttribute('stroke-width', '1.75');
		icon.setAttribute('stroke-linecap', 'round');
		icon.setAttribute('aria-hidden', 'true');
		icon.innerHTML =
			'<path d="M3.5 8A2.5 2.5 0 0 1 6 5.5h11A1.5 1.5 0 0 1 18.5 7v1"/>' +
			'<rect x="3.5" y="8" width="17" height="11" rx="2.5"/>' +
			'<circle cx="16.2" cy="13.5" r="1.15"/>';
		panel.appendChild(icon);

		var title = document.createElement('h3');
		title.textContent = i18n.lowTitle || 'Solde insuffisant';
		panel.appendChild(title);

		var copy = document.createElement('p');
		copy.textContent = i18n.lowBody || '';
		panel.appendChild(copy);

		var actions = document.createElement('div');
		actions.className = 'dcs__low-actions';

		var top = document.createElement('a');
		top.className = 'dcs__btn dcs__btn--primary';
		top.href = config.walletUrl;
		top.textContent = i18n.lowAction || 'Recharger mon compte';
		actions.appendChild(top);

		var back = document.createElement('button');
		back.type = 'button';
		back.className = 'dcs__btn dcs__btn--ghost';
		back.setAttribute('data-dcs-dismiss-low', '');
		back.textContent = i18n.lowSecondary || 'Retour';
		actions.appendChild(back);

		panel.appendChild(actions);

		/* Above the form, not instead of it: the cart is intact and the customer
		   may want to pick a different payment method rather than top up. */
		body.insertBefore(panel, body.firstChild);
		panel.scrollIntoView({ block: 'nearest' });
	}

	document.addEventListener('click', function (event) {
		var dismiss = event.target.closest && event.target.closest('[data-dcs-dismiss-low]');
		if (!dismiss) return;
		event.preventDefault();
		var panel = body.querySelector('[data-dcs-low]');
		if (panel) panel.remove();
	});
})();
