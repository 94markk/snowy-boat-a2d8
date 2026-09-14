/* Purchase Studio — Woo-native purchase interactions (RC51.58).
   ES5, no storage and no custom commerce endpoint. Quantity updates and
   clearing submit WooCommerce's real cart form and nonce; removal follows
   WooCommerce's own signed remove URL. */
(function () {
  'use strict';

  var body = document.body;
  if (!body) return;

  var config = window.DelicatPurchaseNative || {};
  var form = null;
  var updateButton = form ? form.querySelector('[data-dpn-update]') : null;
  var updateTimer = null;
  var submitting = false;
  var clearing = false;

  function addClass(element, name) {
    if (element && (' ' + element.className + ' ').indexOf(' ' + name + ' ') === -1) {
      element.className += ' ' + name;
    }
  }

  function removeClass(element, name) {
    if (element) {
      element.className = element.className.replace(new RegExp('\\s*\\b' + name + '\\b', 'g'), '');
    }
  }

  /*
   * Woo Wallet/TeraWallet keeps its original input value and gateway class.
   * This changes visible text nodes only, including fragments returned by
   * Woo's update_order_review request.
   */
  function renameWalletGateway(root) {
    var inputs;
    var i;
    var input;
    var owner;
    var walker;
    var node;
    var nodes;
    var j;
    var source;
    var title = String(config.walletTitle || 'Delicat Wallet');
    var balance = String(config.walletBalance || 'Solde disponible');

    if (!root || !root.querySelectorAll || !document.createTreeWalker || typeof NodeFilter === 'undefined') return;
    inputs = root.querySelectorAll('input[type="radio"],input[name="payment_method"]');
    for (i = 0; i < inputs.length; i++) {
      input = inputs[i];
      source = String(input.value || '') + ' ' + String(input.id || '') + ' ' + String(input.className || '');
      if (source.toLowerCase().indexOf('wallet') === -1) continue;

      owner = input.closest
        ? input.closest('li,.wc_payment_method,.wc-block-components-radio-control-accordion-option,.wc-block-components-radio-control__option')
        : input.parentNode;
      if (!owner) owner = input.parentNode;
      if (!owner) continue;

      nodes = [];
      walker = document.createTreeWalker(owner, NodeFilter.SHOW_TEXT, null, false);
      while ((node = walker.nextNode())) nodes.push(node);
      for (j = 0; j < nodes.length; j++) {
        nodes[j].nodeValue = String(nodes[j].nodeValue || '')
          .replace(/Wallet payment|Tera\s*Wallet/ig, title)
          .replace(/Current Balance/ig, balance);
      }
    }

		enhanceWalletGateway(root);
  }

	function cleanText(value) {
		return String(value || '').replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
	}

	function foldedText(value) {
		return cleanText(value).toLowerCase()
			.replace(/[àâäáãå]/g, 'a')
			.replace(/[ç]/g, 'c')
			.replace(/[èéêë]/g, 'e')
			.replace(/[ìíîï]/g, 'i')
			.replace(/[òóôöõ]/g, 'o')
			.replace(/[ùúûü]/g, 'u');
	}

	/*
	 * Delicat Identity is the single guest-login owner on this checkout. Some
	 * Identity versions also mount their legacy Google strip, while Woo can add
	 * its returning-customer toggle. Mark only copies outside the complete
	 * Identity panel; no authentication input, token or request is rewritten.
	 */
	function normalizeCheckoutAuthentication() {
		var owner;
		var root;
		var duplicates;
		var controls;
		var notices;
		var walker;
		var node;
		var textNodes = [];
		var i;
		var candidate;
		var parent;
		var textValue;

		if (body.className.indexOf('dpn-checkout') === -1) return;
		owner = document.querySelector('.dip-wc-login-panel');
		if (!owner) return;
		root = document.querySelector('.delicat-native-page-main') || body;
		addClass(body, 'dpn-checkout-login-normalized');

		duplicates = document.querySelectorAll(
			'.dip-divider,.dip-login-wrap,' +
			'.woocommerce-form-login-toggle,form.woocommerce-form-login'
		);
		for (i = 0; i < duplicates.length; i++) {
			candidate = duplicates[i];
			if (candidate !== owner && !owner.contains(candidate) && !candidate.contains(owner)) {
				addClass(candidate, 'dpn-auth-duplicate');
			}
		}

		controls = document.querySelectorAll('a,button,[role="button"]');
		for (i = 0; i < controls.length; i++) {
			candidate = controls[i];
			if (owner.contains(candidate)) continue;
			textValue = foldedText(candidate.textContent || candidate.getAttribute('aria-label'));
			if (textValue.indexOf('continuer avec google') === -1 && textValue.indexOf('continue with google') === -1) continue;
			parent = candidate.closest ? candidate.closest('.dip-login-wrap,.dip-google-wrap,.dip-social-login,.woocommerce-info') : null;
			addClass(parent && !parent.contains(owner) ? parent : candidate, 'dpn-auth-duplicate');
		}

		notices = document.querySelectorAll('p,.woocommerce-info,.woocommerce-notice,.woocommerce-message');
		for (i = 0; i < notices.length; i++) {
			candidate = notices[i];
			if (owner.contains(candidate) || candidate.querySelector('input,select,textarea,button')) continue;
			textValue = foldedText(candidate.textContent);
			if (
				textValue === 'veuillez vous connecter pour finaliser votre commande' ||
				textValue === 'veuillez vous connecter pour finaliser votre commande.' ||
				textValue === 'please log in to complete your purchase' ||
				textValue === 'please log in to complete your purchase.' ||
				textValue === 'please log in to proceed to checkout' ||
				textValue === 'please log in to proceed to checkout.'
			) {
				addClass(candidate, 'dpn-auth-duplicate');
			}
		}

		/* Woo can return the login-required sentence as a bare text node. */
		if (document.createTreeWalker && typeof NodeFilter !== 'undefined') {
			walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
			while ((node = walker.nextNode())) textNodes.push(node);
			for (i = 0; i < textNodes.length; i++) {
				node = textNodes[i];
				if (node.parentElement && owner.contains(node.parentElement)) continue;
				textValue = foldedText(node.nodeValue).replace(/[.!]+$/, '');
				if (
					String(node.nodeValue || '').indexOf('dc-native-login-required') !== -1 ||
					textValue === 'veuillez vous connecter pour finaliser votre commande' ||
					textValue === 'please log in to complete your purchase' ||
					textValue === 'please log in to proceed to checkout'
				) {
					node.nodeValue = '';
				}
			}
		}
	}

	function bindCheckoutAuthentication() {
		var root;
		var observer;
		var timer = null;
		if (body.className.indexOf('dpn-checkout') === -1) return;
		root = document.querySelector('.delicat-native-page-main') || body;

		function schedule() {
			if (timer) window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				timer = null;
				normalizeCheckoutAuthentication();
			}, 45);
		}

		normalizeCheckoutAuthentication();
		if (window.MutationObserver) {
			observer = new MutationObserver(schedule);
			observer.observe(root, { childList: true, subtree: true });
		}
		if (window.jQuery) window.jQuery(document.body).on('updated_checkout', schedule);
	}

	function walletLabel(owner, input) {
		var labels;
		var i;
		if (!owner || !owner.querySelectorAll) return null;
		labels = owner.querySelectorAll('label');
		for (i = 0; i < labels.length; i++) {
			if (!input.id || labels[i].getAttribute('for') === input.id) return labels[i];
		}
		return labels.length ? labels[0] : null;
	}

	function walletBalanceText(value) {
		var text = cleanText(value);
		var match = text.match(/(?:current\s*balance|solde\s*disponible)\s*:?\s*([A-Z]{0,4}\s*-?\s*\d[\d\s.,]*(?:\s*[A-Z]{2,4})?)/i);
		return match ? cleanText(match[1]) : '';
	}

	function moneyValue(value) {
		var source = cleanText(value).replace(/[^0-9,.-]/g, '');
		var comma = source.lastIndexOf(',');
		var dot = source.lastIndexOf('.');
		var separator = Math.max(comma, dot);
		var decimals = separator >= 0 ? source.length - separator - 1 : 0;
		var normalized;
		var parsed;

		if (!source || !/\d/.test(source)) return null;
		if (separator >= 0 && decimals > 0 && decimals <= 2) {
			normalized = source.substring(0, separator).replace(/[,.]/g, '') + '.' + source.substring(separator + 1).replace(/[,.]/g, '');
		} else {
			normalized = source.replace(/[,.]/g, '');
		}
		parsed = parseFloat(normalized);
		return isNaN(parsed) ? null : parsed;
	}

	function checkoutTotalText() {
		var totals = document.querySelectorAll(
			'#order_review tr.order-total .woocommerce-Price-amount,' +
			'.wc-block-components-totals-footer-item .wc-block-formatted-money-amount,' +
			'.wc-block-components-totals-footer-item__value'
		);
		return totals.length ? cleanText(totals[totals.length - 1].textContent) : '';
	}

	function afterBalanceText(balanceText) {
		var balance = moneyValue(balanceText);
		var total = moneyValue(checkoutTotalText());
		var firstDigit = String(balanceText || '').search(/[0-9-]/);
		var prefix = firstDigit > -1 ? cleanText(String(balanceText).substring(0, firstDigit)) : '';
		var suffixMatch = cleanText(balanceText).match(/\s([A-Z]{2,4})$/);
		var amount;
		var sign;
		var digits;

		if (balance === null || total === null) return '';
		amount = Math.round(balance - total);
		sign = amount < 0 ? '-' : '';
		digits = String(Math.abs(amount)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		return (prefix ? prefix : '') + sign + digits + (suffixMatch ? ' ' + suffixMatch[1] : '');
	}

	/*
	 * Build the supplied wallet card around TeraWallet's original radio. Only
	 * label DOM changes: input name/value/id, selected state and gateway hooks
	 * are never replaced or mirrored.
	 */
	function enhanceWalletGateway(root) {
		var inputs;
		var i;
		var input;
		var owner;
		var label;
		var balanceText;
		var ui;
		var icon;
		var copy;
		var title;
		var small;
		var amount;
		var after;
		var afterValue;
		var nextBalance;
		var source;

		if (!root || !root.querySelectorAll) return;
		inputs = root.querySelectorAll('input[type="radio"],input[name="payment_method"]');
		for (i = 0; i < inputs.length; i++) {
			input = inputs[i];
			source = String(input.value || '') + ' ' + String(input.id || '') + ' ' + String(input.className || '');
			if (source.toLowerCase().indexOf('wallet') === -1) continue;
			owner = input.closest
				? input.closest('li,.wc_payment_method,.wc-block-components-radio-control-accordion-option,.wc-block-components-radio-control__option')
				: input.parentNode;
			if (!owner) continue;
			label = walletLabel(owner, input);
			if (!label) continue;
			/* A few Block gateways wrap the radio inside the label; never remove it. */
			if (label.contains && label.contains(input)) continue;

			addClass(owner, 'dpn-wallet-enhanced');
			ui = label.querySelector ? label.querySelector('.dpn-wallet-ui') : null;
			if (ui) {
				amount = ui.querySelector('.dpn-wallet-balance');
				balanceText = amount ? cleanText(amount.textContent) : cleanText(label.getAttribute('data-dpn-wallet-balance'));
			} else {
				balanceText = walletBalanceText(label.textContent);
				if (!balanceText) balanceText = walletBalanceText(owner.textContent);
				label.textContent = '';
				label.setAttribute('data-dpn-wallet-balance', balanceText);

				ui = document.createElement('span');
				ui.className = 'dpn-wallet-ui';
				icon = document.createElement('span');
				icon.className = 'dpn-wallet-icon';
				icon.setAttribute('aria-hidden', 'true');
				icon.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="6" width="18" height="13" rx="3"></rect><path d="M16 10h5v5h-5a2.5 2.5 0 0 1 0-5z"></path><path d="M7 6V4h10v2"></path></svg>';

				copy = document.createElement('span');
				copy.className = 'dpn-wallet-copy';
				title = document.createElement('strong');
				title.textContent = config.walletTitle || 'Delicat Wallet';
				copy.appendChild(title);
				if (balanceText) {
					small = document.createElement('small');
					small.appendChild(document.createTextNode((config.walletBalance || 'Solde disponible') + ' : '));
					amount = document.createElement('b');
					amount.className = 'dpn-wallet-balance';
					amount.textContent = balanceText;
					small.appendChild(amount);
					copy.appendChild(small);
				}

				ui.appendChild(icon);
				ui.appendChild(copy);
				label.appendChild(ui);
			}

			after = owner.querySelector ? owner.querySelector('.dpn-wallet-after') : null;
			if (!after) {
				after = document.createElement('span');
				after.className = 'dpn-wallet-after';
				after.innerHTML = '<span></span><strong></strong>';
				after.querySelector('span').textContent = config.walletAfter || 'Solde après cet achat';
				owner.insertBefore(after, label.nextSibling);
			}
			afterValue = after.querySelector('strong');
			nextBalance = afterBalanceText(balanceText);
			if (!nextBalance) {
				after.hidden = true;
			} else {
				after.hidden = false;
				if (afterValue && cleanText(afterValue.textContent) !== nextBalance) afterValue.textContent = nextBalance;
			}
		}
	}

	function normalizeMinimalCheckoutFields(root) {
		var specs;
		var i;
		var field;
		var label;
		var mark;
		var optional;
		var description;
		var input;
		var heading;

		if (body.className.indexOf('dpn-checkout-minimal') === -1 || !root || !root.querySelector) return;
		specs = [
			['billing_email', config.emailLabel || 'Adresse e-mail', config.emailHelp || '', true],
			['billing_first_name', config.nameLabel || 'Nom complet', '', true],
			['billing_phone', config.phoneLabel || 'Numéro WhatsApp', config.phoneHelp || '', false]
		];
		for (i = 0; i < specs.length; i++) {
			field = root.querySelector('#' + specs[i][0] + '_field') || document.querySelector('#' + specs[i][0] + '_field');
			if (!field) continue;
			label = field.querySelector('label');
			if (label && label.getAttribute('data-dpn-label') !== specs[i][1]) {
				label.textContent = specs[i][1] + ' ';
				if (specs[i][3]) {
					mark = document.createElement('abbr');
					mark.className = 'required';
					mark.setAttribute('title', 'obligatoire');
					mark.textContent = '*';
					label.appendChild(mark);
				} else {
					optional = document.createElement('span');
					optional.className = 'optional';
					optional.textContent = '(optionnel)';
					label.appendChild(optional);
				}
				label.setAttribute('data-dpn-label', specs[i][1]);
			}
			if (specs[i][2]) {
				description = field.querySelector('.description');
				if (!description) {
					description = document.createElement('span');
					description.className = 'description';
					field.appendChild(description);
				}
				if (cleanText(description.textContent) !== specs[i][2]) description.textContent = specs[i][2];
			}
			if (specs[i][0] === 'billing_phone') {
				input = field.querySelector('input');
				if (input && !input.getAttribute('placeholder')) input.setAttribute('placeholder', '+509 XXXX XXXX');
			}
		}

		field = root.querySelector('#billing_first_name_field') || document.querySelector('#billing_first_name_field');
		if (field && (!field.previousElementSibling || field.previousElementSibling.className.indexOf('dpn-billing-subheading') === -1)) {
			heading = document.createElement('h3');
			heading.className = 'dpn-billing-subheading';
			heading.textContent = config.billingTitle || 'Détails de facturation';
			field.parentNode.insertBefore(heading, field);
		}
	}

  /*
   * Some wallet gateways serialize their description before WordPress can
   * execute the registered balance shortcode. Remove only that exact unresolved
   * token; never evaluate arbitrary shortcode text in the browser.
   */
  function cleanWalletTokens(root) {
    var matcher = /\[\/?terawallet_balance(?:\s[^\]]*)?\]/ig;
    var walker;
    var node;
    var nodes = [];
    var boxes = [];
    var i;
    var box;
    var value;

    if (!root || !document.createTreeWalker || typeof NodeFilter === 'undefined') return;
    renameWalletGateway(root);
		normalizeMinimalCheckoutFields(root);
    walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
    while ((node = walker.nextNode())) {
      matcher.lastIndex = 0;
      if (matcher.test(node.nodeValue || '')) nodes.push(node);
    }

    for (i = 0; i < nodes.length; i++) {
      matcher.lastIndex = 0;
      value = String(nodes[i].nodeValue || '').replace(matcher, '');
      nodes[i].nodeValue = value;
      box = nodes[i].parentElement && nodes[i].parentElement.closest
        ? nodes[i].parentElement.closest('.payment_box,.wc-block-components-radio-control-accordion-content')
        : null;
      if (box && boxes.indexOf(box) === -1) boxes.push(box);
    }

    for (i = 0; i < boxes.length; i++) {
      box = boxes[i];
      if (
        !String(box.textContent || '').replace(/\s+/g, '')
        && !box.querySelector('input,select,textarea,button,a,img,iframe')
      ) {
        addClass(box, 'dpn-wallet-token-empty');
      }
    }
  }

  function bindWalletCleanup() {
    var root;
    var observer;
    var timer = null;
    if (body.className.indexOf('dpn-checkout') === -1) return;

    root = document.querySelector('.delicat-native-page-main,form.checkout,.wc-block-checkout') || body;
    cleanWalletTokens(root);

    function schedule() {
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        timer = null;
        cleanWalletTokens(root);
      }, 40);
    }

    if (window.MutationObserver) {
      observer = new MutationObserver(schedule);
      observer.observe(root, { childList: true, subtree: true });
    }
    if (window.jQuery) {
      window.jQuery(document.body).on('updated_checkout payment_method_selected', schedule);
    }
  }

	/*
	 * Checkout presentation never serializes or posts checkout data. The dock
	 * becomes available only after Woo's real place-order button exists, then
	 * delegates to that exact button so Woo/gateway validation, nonces and form
	 * submission retain full control.
	 */
	function bindCheckoutPresentation() {
		var dock;
		var dockButton;
		var dockTotal;
		var root;
		var observer;
		var timer = null;
		var couponButton;

		if (body.className.indexOf('dpn-checkout') === -1) return;
		dock = document.querySelector('[data-dpn-checkout-dock]');
		dockButton = dock ? dock.querySelector('[data-dpn-checkout-submit]') : null;
		dockTotal = dock ? dock.querySelector('[data-dpn-checkout-total]') : null;
		root = document.querySelector('.delicat-native-page-main,.wc-block-checkout,form.checkout') || body;
		couponButton = document.querySelector('[data-dpn-coupon-open]');

		function placeOrderButton() {
			return document.querySelector('#place_order,.wc-block-components-checkout-place-order-button');
		}

		function couponControl() {
			return document.querySelector(
				'.woocommerce-form-coupon-toggle .showcoupon,' +
				'.wc-block-components-totals-coupon-link'
			);
		}

		function totalElement() {
			var totals = document.querySelectorAll(
				'#order_review tr.order-total .woocommerce-Price-amount,' +
				'.wc-block-components-totals-footer-item .wc-block-formatted-money-amount,' +
				'.wc-block-components-totals-footer-item__value'
			);
			return totals.length ? totals[totals.length - 1] : null;
		}

		function ensurePaymentHeading() {
			var payment;
			var heading;
			if (body.className.indexOf('dpn-checkout-classic') === -1) return;
			payment = document.querySelector('#payment');
			if (!payment || (payment.previousElementSibling && payment.previousElementSibling.className.indexOf('dpn-payment-heading') !== -1)) return;
			heading = document.createElement('h2');
			heading.className = 'dpn-checkout-section-heading dpn-payment-heading';
			heading.innerHTML = '<span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="M3 10h18"></path></svg></span>' +
				'<b></b>';
			heading.querySelector('b').textContent = config.paymentTitle || 'Mode de paiement';
			payment.parentNode.insertBefore(heading, payment);
		}

		/* A zero-fee display row mirrors the absence of Woo fee rows; it changes no total. */
		function ensureFreeServiceRow() {
			var table;
			var totalRow;
			var row;
			var hasFee;
			if (body.className.indexOf('dpn-checkout-classic') === -1) return;
			table = document.querySelector('#order_review table.shop_table');
			if (!table) return;
			row = table.querySelector('tr.dpn-service-free');
			hasFee = !!table.querySelector('tr.fee');
			if (hasFee) {
				if (row && row.parentNode) row.parentNode.removeChild(row);
				return;
			}
			if (row) return;
			totalRow = table.querySelector('tr.order-total');
			if (!totalRow || !totalRow.parentNode) return;
			row = document.createElement('tr');
			row.className = 'dpn-service-free';
			row.innerHTML = '<th></th><td data-title=""></td>';
			row.querySelector('th').textContent = config.serviceFee || 'Frais de service';
			row.querySelector('td').textContent = config.free || 'Gratuit';
			totalRow.parentNode.insertBefore(row, totalRow);
		}

		function syncCheckoutPresentation() {
			var actual = placeOrderButton();
			var total = totalElement();
			var coupon = couponControl();
			var describedBy;

			ensurePaymentHeading();
			ensureFreeServiceRow();

			if (couponButton) {
				couponButton.disabled = !coupon;
				if (coupon) addClass(body, 'dpn-coupon-proxy-ready');
				else removeClass(body, 'dpn-coupon-proxy-ready');
			}

			if (dockTotal && total) dockTotal.innerHTML = total.innerHTML;
			if (!dock || !dockButton || !actual) {
				if (dockButton) dockButton.disabled = true;
				return;
			}

			addClass(actual, 'dpn-native-place-order');
			describedBy = String(actual.getAttribute('aria-describedby') || '');
			if (describedBy.indexOf('dpn-checkout-trust') === -1) {
				actual.setAttribute('aria-describedby', (describedBy + ' dpn-checkout-trust').replace(/^\s+|\s+$/g, ''));
			}
			dockButton.disabled = !!actual.disabled || actual.getAttribute('aria-disabled') === 'true';
			dock.hidden = false;
			addClass(body, 'dpn-checkout-dock-ready');
		}

		function scheduleCheckoutSync() {
			if (timer) window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				timer = null;
				syncCheckoutPresentation();
			}, 45);
		}

		if (couponButton) {
			couponButton.addEventListener('click', function () {
				var coupon = couponControl();
				var input;
				if (!coupon || couponButton.disabled) return;
				coupon.click();
				window.setTimeout(function () {
					input = document.querySelector('#coupon_code,.wc-block-components-totals-coupon input');
					if (input) input.focus();
				}, 80);
			});
		}

		if (dockButton) {
			dockButton.addEventListener('click', function () {
				var actual = placeOrderButton();
				if (!actual || actual.disabled || actual.getAttribute('aria-disabled') === 'true') return;
				actual.click();
			});
		}

		if (window.MutationObserver) {
			observer = new MutationObserver(scheduleCheckoutSync);
			observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'aria-disabled', 'class'] });
		}
		if (window.jQuery) {
			window.jQuery(document.body).on('updated_checkout payment_method_selected checkout_error', scheduleCheckoutSync);
		}
		window.addEventListener('pageshow', scheduleCheckoutSync);
		syncCheckoutPresentation();
	}

  function emit(input, type) {
    var event;
    if (typeof Event === 'function') {
      event = new Event(type, { bubbles: true });
    } else {
      event = document.createEvent('Event');
      event.initEvent(type, true, false);
    }
    input.dispatchEvent(event);
  }

	bindCheckoutAuthentication();
	bindWalletCleanup();
	bindCheckoutPresentation();

  if (body.className.indexOf('dpn-cart-classic') === -1) return;
  form = document.querySelector('.dpn-form');
  updateButton = form ? form.querySelector('[data-dpn-update]') : null;

  function ensureUpdateField() {
    var field;
    if (updateButton) {
      updateButton.disabled = false;
      return updateButton;
    }
    field = form.querySelector('input[name="update_cart"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = 'update_cart';
      field.value = '1';
      form.appendChild(field);
    }
    return field;
  }

  function submitUpdate() {
    var submitter;
    if (!form || submitting) return;
    if (updateTimer) window.clearTimeout(updateTimer);
    updateTimer = null;
    submitter = ensureUpdateField();

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit(submitter && submitter.tagName === 'BUTTON' ? submitter : undefined);
    } else if (submitter && submitter.tagName === 'BUTTON') {
      submitter.click();
    } else {
      submitting = true;
      addClass(body, 'dpn-updating');
      form.submit();
    }
  }

  function scheduleUpdate() {
    if (clearing || submitting) return;
    if (updateTimer) window.clearTimeout(updateTimer);
    updateTimer = window.setTimeout(submitUpdate, Number(config.updateDelay) || 450);
  }

  function numberValue(value, fallback) {
    var parsed = parseFloat(value);
    return isNaN(parsed) ? fallback : parsed;
  }

  function decimalPlaces(value) {
    var text = String(value);
    return text.indexOf('.') === -1 ? 0 : text.length - text.indexOf('.') - 1;
  }

  function formatValue(value, step) {
    var precision = Math.min(6, decimalPlaces(step));
    return precision ? value.toFixed(precision).replace(/0+$/, '').replace(/\.$/, '') : String(Math.round(value));
  }

  function syncAtMinimum(wrap, input) {
    var value = numberValue(input.value, 1);
    var min = numberValue(input.getAttribute('min'), 0);
    wrap.setAttribute('data-at-one', value <= Math.max(1, min) ? '1' : '0');
  }

  function setQuantity(wrap, input, value, step) {
    var max = numberValue(input.getAttribute('max'), 0);
    var min = numberValue(input.getAttribute('min'), 0);
    if (max > 0) value = Math.min(value, max);
    value = Math.max(min, value);
    input.value = formatValue(value, step);
    syncAtMinimum(wrap, input);
    emit(input, 'input');
    emit(input, 'change');
  }

  function bindQuantity() {
    var wraps = document.querySelectorAll('[data-dpn-qty]');
    var i;
    for (i = 0; i < wraps.length; i++) {
      (function (wrap) {
        var input = wrap.querySelector('.qty');
        var minus = wrap.querySelector('[data-dpn-minus]');
        var plus = wrap.querySelector('[data-dpn-plus]');
        if (!input) return;
        syncAtMinimum(wrap, input);

        if (plus) {
          plus.addEventListener('click', function () {
            var step = numberValue(input.getAttribute('step'), 1);
            var value = numberValue(input.value, 0);
            setQuantity(wrap, input, value + step, step);
            scheduleUpdate();
          });
        }

        if (minus) {
          minus.addEventListener('click', function () {
            var step = numberValue(input.getAttribute('step'), 1);
            var value = numberValue(input.value, 1);
            if (value <= Math.max(1, step)) {
              var removeUrl = wrap.getAttribute('data-remove');
              if (removeUrl) window.location.assign(removeUrl);
              return;
            }
            setQuantity(wrap, input, value - step, step);
            scheduleUpdate();
          });
        }

        input.addEventListener('change', function () {
          var min = numberValue(input.getAttribute('min'), 0);
          var value = numberValue(input.value, Math.max(1, min));
          if (value < min) input.value = String(min);
          syncAtMinimum(wrap, input);
          scheduleUpdate();
        });
      }(wraps[i]));
    }
  }

  function setDeleteAccessibility(item, active) {
    var link = item ? item.querySelector('.dpn-swipe-delete-link') : null;
    if (!link) return;
    link.setAttribute('tabindex', active ? '0' : '-1');
    link.setAttribute('aria-hidden', active ? 'false' : 'true');
  }

  function bindSwipe() {
    var items = document.querySelectorAll('[data-dpn-swipe]');
    var open = null;
    var i;

    function close(item) {
      var card;
      if (!item) return;
      removeClass(item, 'is-open');
      removeClass(item, 'is-dragging');
      card = item.querySelector('.dpn-card');
      if (card) card.style.removeProperty('--dpn-x');
      setDeleteAccessibility(item, false);
      if (open === item) open = null;
    }

    function closeAll() {
      var index;
      for (index = 0; index < items.length; index++) close(items[index]);
    }

    for (i = 0; i < items.length; i++) {
      (function (item) {
        var card = item.querySelector('.dpn-card');
        if (!card || !window.PointerEvent) return;
        var startX = 0;
        var startY = 0;
        var dragging = false;
        var beganOpen = false;

        item.addEventListener('pointerdown', function (event) {
          var target = event.target;
          if (event.pointerType === 'mouse' && event.button !== 0) return;
          if (target.closest && target.closest('a,button,input,select,textarea,label')) return;
          if (open && open !== item) close(open);
          startX = event.clientX;
          startY = event.clientY;
          beganOpen = (' ' + item.className + ' ').indexOf(' is-open ') !== -1;
          dragging = true;
          if (item.setPointerCapture) item.setPointerCapture(event.pointerId);
        });

        item.addEventListener('pointermove', function (event) {
          var dx;
          var dy;
          var offset;
          if (!dragging) return;
          dx = event.clientX - startX;
          dy = event.clientY - startY;
          if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 8) {
            dragging = false;
            close(item);
            return;
          }
          addClass(item, 'is-dragging');
          offset = Math.max(-96, Math.min(0, dx + (beganOpen ? -96 : 0)));
          card.style.setProperty('--dpn-x', offset + 'px');
        });

        function finish(event) {
          var dx;
          if (!dragging) return;
          dragging = false;
          removeClass(item, 'is-dragging');
          dx = event.clientX - startX + (beganOpen ? -96 : 0);
          if (dx < -46) {
            addClass(item, 'is-open');
            card.style.setProperty('--dpn-x', '-96px');
            setDeleteAccessibility(item, true);
            open = item;
          } else {
            close(item);
          }
        }

        item.addEventListener('pointerup', finish);
        item.addEventListener('pointercancel', function () {
          dragging = false;
          close(item);
        });
      }(items[i]));
    }

    document.addEventListener('pointerdown', function (event) {
      if (open && !open.contains(event.target)) close(open);
    }, true);
    window.addEventListener('pageshow', closeAll);
    window.addEventListener('resize', closeAll);
  }

  function bindClear() {
    var button = document.querySelector('[data-dpn-clear]');
    if (!button || !form) return;
    button.hidden = false;

    button.addEventListener('click', function () {
      var inputs;
      var i;
      if (submitting || button.getAttribute('aria-busy') === 'true') return;
      if (config.clearConfirm && !window.confirm(config.clearConfirm)) return;

      clearing = true;
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
      form.noValidate = true;
      inputs = form.querySelectorAll('[name^="cart["][name$="[qty]"]');
      for (i = 0; i < inputs.length; i++) {
        inputs[i].setAttribute('min', '0');
        inputs[i].value = '0';
      }
      submitUpdate();
    });
  }

  if (form) {
    form.addEventListener('submit', function () {
      submitting = true;
      addClass(body, 'dpn-updating');
      if (updateButton) updateButton.disabled = false;
    });
  }

  addClass(body, 'dpn-js');
  bindQuantity();
  bindSwipe();
  bindClear();
}());
