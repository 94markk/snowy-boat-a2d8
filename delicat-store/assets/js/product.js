/**
 * Product page: give WooCommerce's cart form an id so the mobile dock's
 * buttons can submit it, carry "buy now" through the same POST, and keep
 * the dock's price in sync with the chosen variation.
 */
(function ($) {
	'use strict';

	var form = document.querySelector('form.cart');
	if (!form) { return; }
	form.id = form.id || 'ds-cart-form';

	/* Group WooCommerce's button with ours on desktop. */
	var wcButton = form.querySelector('.single_add_to_cart_button');
	var buyNow = form.querySelector('.ds-buy-now');
	if (wcButton && buyNow && wcButton.parentNode === buyNow.parentNode) {
		var wrap = document.createElement('div');
		wrap.className = 'ds-form-actions';
		wcButton.parentNode.insertBefore(wrap, wcButton);
		wrap.appendChild(wcButton);
		wrap.appendChild(buyNow);
	}

	/* Buy now: the same form, the same POST, plus one marker field. */
	function ensureMarker(on) {
		var marker = form.querySelector('input[name="ds_buy_now"]');
		if (on && !marker) {
			marker = document.createElement('input');
			marker.type = 'hidden';
			marker.name = 'ds_buy_now';
			marker.value = '1';
			form.appendChild(marker);
		} else if (!on && marker) {
			marker.remove();
		}
	}
	document.addEventListener('click', function (event) {
		var target = event.target.closest('button[type="submit"]');
		if (!target) { return; }
		var belongs = target.form === form || target.getAttribute('form') === form.id;
		if (!belongs) { return; }
		ensureMarker(target.hasAttribute('data-ds-buy-now'));
		/* Native validation runs on submit; make the first invalid field
		   visible on a phone before the browser's bubble appears. */
		var invalid = form.querySelector(':invalid');
		if (invalid) {
			invalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
		}
	});

	/* Dock mirrors the variation price and availability. */
	var dockPrice = document.querySelector('.ds-dock__price');
	var dockButtons = document.querySelectorAll('.ds-dock__actions .ds-btn');
	if ($ && dockPrice) {
		$(form).on('found_variation', function (event, variation) {
			if (variation && variation.price_html) {
				var tmp = document.createElement('div');
				tmp.innerHTML = variation.price_html;
				var price = tmp.querySelector('.price') || tmp;
				dockPrice.innerHTML = price.innerHTML;
			}
			var ok = variation && variation.is_purchasable && variation.is_in_stock;
			dockButtons.forEach(function (b) { b.disabled = !ok; });
		});
		$(form).on('reset_data', function () {
			dockButtons.forEach(function (b) { b.disabled = false; });
		});
	}
})(window.jQuery);
