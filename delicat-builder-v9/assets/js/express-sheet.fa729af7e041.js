/* =============================================================================
   express-sheet.js — "Acheter maintenant" without leaving the product page.
   -----------------------------------------------------------------------------
   Read the class comment in class-delicat-builder-express-sheet.php first; it
   explains why this is a panel around WooCommerce's own form and not a checkout.

   The whole of this file's responsibility:

     1. take the POST the button was going to send anyway, and send it by fetch
     2. lift form.checkout out of the reply and put it in the panel
     3. tell WooCommerce its form has arrived
     4. when WooCommerce reports a decline, show it - and if the wallet is the
        reason, offer the way to fix it

   Everything else is WooCommerce's. This file never reads a price, never mints
   a nonce, never decides whether a payment may proceed, and never posts
   anywhere except the form's own action.

   FAILURE IS ALWAYS THE OLD BEHAVIOUR
   Every early return abandons the interception and lets the form submit
   normally, which lands the customer on the real checkout page. There is no
   path where this file failing stops someone buying something.
   ========================================================================== */

(function () {
	'use strict';

	var config = window.DelicatExpressSheet || {};
	var i18n = config.i18n || {};

	var sheet = document.getElementById('dxs-sheet');
	var jq = window.jQuery;

	/* No panel, no jQuery, or a browser without <dialog>: the button keeps doing
	   exactly what it does today. */
	if (!sheet || !jq || typeof sheet.showModal !== 'function') return;

	var body = sheet.querySelector('[data-dxs-body]');
	var scroller = sheet.querySelector('[data-dxs-scroll]') || body;
	var trust = sheet.querySelector('[data-dxs-trust]');
	var busy = false;
	var observer = null;

	/* ---------------------------------------------------------------------
	   Opening and closing
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
		if (trust) trust.hidden = true;
		sheet.classList.remove('dxs--low');
		unwatchFooter();
	});

	document.addEventListener('click', function (event) {
		if (event.target.closest && event.target.closest('[data-dxs-close]')) {
			event.preventDefault();
			close();
		}
	});

	/* A tap outside the panel closes it. The backdrop is outside the dialog's
	   own box, so a click whose coordinates fall outside that box is a backdrop
	   click - no extra element, no listener to leak. */
	sheet.addEventListener('click', function (event) {
		if (event.target !== sheet) return;
		var box = sheet.getBoundingClientRect();
		var inside =
			event.clientX >= box.left && event.clientX <= box.right &&
			event.clientY >= box.top && event.clientY <= box.bottom;
		if (!inside) close();
	});

	/* ---------------------------------------------------------------------
	   Keeping the pinned footer honest

	   The pay button is position:fixed inside the panel (the stylesheet says
	   why it cannot be sticky). Two heights have to be measured rather than
	   guessed, because both move with the customer's text size, their language,
	   and whether the terms line wraps:

	     --dxs-trust-h    how far above the panel's bottom edge the footer sits
	     --dxs-pad-bottom how much room the scroller leaves so the last field can
	                      be scrolled out from under the footer

	   The stylesheet carries a workable fallback for both, so a browser with no
	   ResizeObserver gets a roomier panel rather than a broken one.
	   ------------------------------------------------------------------ */

	function measure() {
		if (trust) {
			sheet.style.setProperty('--dxs-trust-h', (trust.hidden ? 0 : trust.offsetHeight) + 'px');
		}

		var footer = body.querySelector('#payment .place-order');
		var height = footer ? footer.offsetHeight : 0;
		/* 24px of air below the last field, so it never reads as clipped. */
		sheet.style.setProperty('--dxs-pad-bottom', (height ? height + 24 : 0) + 'px');
	}

	/*
	 * WooCommerce replaces the whole order-review block on every
	 * update_checkout - a payment method change, a coupon - so the footer
	 * measured a moment ago is gone and a new one is in its place. Re-observing
	 * on each update keeps the measurement attached to the element on screen.
	 */
	function watchFooter() {
		if (typeof ResizeObserver !== 'function') {
			measure();
			return;
		}

		if (observer) observer.disconnect();
		observer = new ResizeObserver(measure);

		var footer = body.querySelector('#payment .place-order');
		if (footer) observer.observe(footer);
		if (trust) observer.observe(trust);

		measure();
	}

	function unwatchFooter() {
		if (observer) { observer.disconnect(); observer = null; }
		sheet.style.removeProperty('--dxs-trust-h');
		sheet.style.removeProperty('--dxs-pad-bottom');
	}

	/* ---------------------------------------------------------------------
	   The interception
	   ------------------------------------------------------------------ */

	/*
	 * Captured at the form, not the button, because a customer can also submit
	 * with Enter from a field - and because the button's own name/value has to
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
		   and it is what tells WooCommerce this was "buy now" rather than "add
		   to cart", which is what sends it on to checkout. */
		if (submitter.name) data.append(submitter.name, submitter.value || '1');

		fetch(form.action || window.location.href, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			redirect: 'follow',
			/* X-Delicat-Express is what makes the server render the summary and
			   wallet cards. An ordinary visit to the checkout page sends no such
			   header and is unchanged. */
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Delicat-Express': '1' }
		})
			.then(function (response) {
				if (!response.ok) throw new Error('http ' + response.status);
				return response.text();
			})
			.then(function (html) {
				var doc = parse(html);
				var checkout = doc && doc.querySelector('form.checkout');
				if (!checkout) throw new Error('no checkout form in the reply');
				mount(doc, checkout);
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
	 * Parse the reply into an INERT document.
	 *
	 * Nothing in it executes, no image in it is fetched, and no script in it
	 * runs. Only the pieces named below are adopted, so a reply that is not the
	 * checkout page yields nothing and the catch above sends the customer to the
	 * real one.
	 */
	function parse(html) {
		try {
			return new DOMParser().parseFromString(html, 'text/html');
		} catch (error) {
			return null;
		}
	}

	function strip(node) {
		node.querySelectorAll('script').forEach(function (s) { s.remove(); });
		return node;
	}

	function mount(doc, form) {
		reset();

		/* Any notice WooCommerce put above the form - a stock warning, a coupon
		   result - travels with it, because the customer needs to read it. The
		   coupon PROMPT does not: this is the express path, and an invitation to
		   go and find a code belongs on the full checkout page. */
		var notices = document.createElement('div');
		notices.className = 'dxs__notices';
		doc.querySelectorAll('.woocommerce-error, .woocommerce-message, .woocommerce-info').forEach(function (node) {
			if (node.closest('.woocommerce-form-coupon-toggle') || node.querySelector('.showcoupon')) return;
			notices.appendChild(strip(node));
		});
		if (notices.firstChild) body.appendChild(notices);

		/* The order and wallet cards, rendered by PHP from WooCommerce's cart
		   and the wallet plugin's own balance. Lifted rather than rebuilt: no
		   figure on this screen is computed in the browser. */
		var summary = doc.querySelector('[data-dxs-summary]');
		if (summary) body.appendChild(strip(summary));

		/* Scripts inside the fetched markup are dropped. WooCommerce's own
		   checkout script is already on this page, enqueued by handle with its
		   own localised parameters; anything else that rode along is not
		   something a product page asked for. */
		body.appendChild(strip(form));

		if (trust) trust.hidden = false;

		/*
		 * The one line that hands over control.
		 *
		 * WooCommerce binds its checkout behaviour on this event: field
		 * validation, the payment method panels, the order review refresh, and
		 * the AJAX submit to its own endpoint. After this, the form in the panel
		 * behaves exactly as the form on the checkout page, because it IS that
		 * form with that script attached.
		 */
		jq(document.body).trigger('init_checkout');
		jq(document.body).trigger('update_checkout');

		watchFooter();
		busy = false;

		/*
		 * The server already compared the wallet against the total and said so
		 * on the summary. Telling the customer now, rather than after they have
		 * filled the form in and pressed pay, is the difference between a top-up
		 * and an abandoned order. Nothing is blocked by it.
		 */
		if (summary && summary.getAttribute('data-dxs-short') === '1' && config.walletUrl) {
			showLowFunds();
		}

		var first = form.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
		if (first) {
			try { first.focus({ preventScroll: true }); } catch (error) { /* older browsers */ }
		}
	}

	/* WooCommerce has just swapped the order review - and with it the footer. */
	jq(document.body).on('updated_checkout', function () {
		if (sheet.open) watchFooter();
	});

	function reset() {
		while (body.firstChild) body.removeChild(body.firstChild);
	}

	/* ---------------------------------------------------------------------
	   Declines
	   ------------------------------------------------------------------ */

	/*
	 * WooCommerce announces every checkout failure on this event, whatever
	 * caused it: a validation error, a gateway refusal, an empty wallet. The
	 * message is the gateway's own and is always shown - WooCommerce has already
	 * put it at the top of the form by the time this runs.
	 *
	 * All this adds is the next step for one specific case. A customer told
	 * "solde insuffisant" on a screen with no way to top up is a customer who
	 * leaves; the same customer with a button to the wallet comes back.
	 */
	jq(document.body).on('checkout_error', function (event, message) {
		var text = String(message || '').replace(/<[^>]*>/g, ' ').toLowerCase();
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
		if (body.querySelector('[data-dxs-low]')) return;

		var panel = document.createElement('div');
		panel.className = 'dxs__low';
		panel.setAttribute('data-dxs-low', '');
		panel.setAttribute('role', 'alert');

		var icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		icon.setAttribute('class', 'dxs__low-icon');
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
		actions.className = 'dxs__low-actions';

		var top = document.createElement('a');
		top.className = 'dxs__btn dxs__btn--primary';
		top.href = config.walletUrl;
		top.textContent = i18n.lowAction || 'Recharger mon compte';
		actions.appendChild(top);

		var back = document.createElement('button');
		back.type = 'button';
		back.className = 'dxs__btn dxs__btn--ghost';
		back.setAttribute('data-dxs-dismiss-low', '');
		back.textContent = i18n.lowSecondary || 'Retour au paiement';
		actions.appendChild(back);

		panel.appendChild(actions);

		/* Above the form, never instead of it: the cart is intact and the
		   customer may want a different payment method rather than a top-up. */
		body.insertBefore(panel, body.firstChild);
		sheet.classList.add('dxs--low');

		/* Scroll the panel, not the page behind it. */
		if (scroller && typeof scroller.scrollTo === 'function') scroller.scrollTo({ top: 0, behavior: 'smooth' });
	}

	document.addEventListener('click', function (event) {
		var dismiss = event.target.closest && event.target.closest('[data-dxs-dismiss-low]');
		if (!dismiss) return;
		event.preventDefault();
		var panel = body.querySelector('[data-dxs-low]');
		if (panel) panel.remove();
		sheet.classList.remove('dxs--low');
	});
})();
