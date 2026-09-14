/* Presentation bridge only. WooCommerce's checkout.js owns all payment submits. */
(function ($) {
  'use strict';
  if (window.parent === window) return;
  function notify(type, details) {
    window.parent.postMessage(Object.assign({ type: 'dcs:' + type }, details || {}), window.location.origin);
  }
  $(function () {
    var form = document.querySelector('form.checkout');
    if (!form) { notify('ready'); return; }
    var marker = document.createElement('input');
    marker.type = 'hidden'; marker.name = 'dcs_frame'; marker.value = '1'; form.appendChild(marker);
    var lowDialog = null, lowSignature = '';
    function lowFunds(force) {
      var state = document.querySelector('[data-dcs-wallet]');
      var method = form.querySelector('[name="payment_method"]:checked');
      var walletSelected = method ? /wallet/i.test(method.value) : !form.querySelector('[name="payment_method"]');
      if (!force && (!state || state.getAttribute('data-short') !== '1' || !walletSelected)) {
        lowSignature = '';
        if (lowDialog && lowDialog.open) lowDialog.close();
        return;
      }
      var config = window.DelicatCheckoutSheet || {};
      if (!config.walletUrl) return;
      var signature = state ? state.getAttribute('data-balance') + ':' + state.getAttribute('data-required') : 'gateway-decline';
      if (!force && signature === lowSignature) return;
      lowSignature = signature;
      if (!lowDialog) {
        lowDialog = document.createElement('dialog'); lowDialog.className = 'dcs-low-dialog';
        lowDialog.setAttribute('aria-labelledby', 'dcs-low-title');
        var title = document.createElement('h2'); title.id = 'dcs-low-title'; title.textContent = 'Solde insuffisant'; lowDialog.appendChild(title);
        var text = document.createElement('p'); text.textContent = 'Votre solde est trop faible pour cet achat. Rechargez votre compte, puis revenez finaliser votre commande.'; lowDialog.appendChild(text);
        var amount = document.createElement('p'); amount.setAttribute('data-dcs-low-amount', ''); lowDialog.appendChild(amount);
        var link = document.createElement('a'); link.href = config.walletUrl; link.textContent = 'Recharger mon compte';
        link.setAttribute('data-dcs-topup', ''); link.className = 'dcs__btn dcs__btn--primary'; lowDialog.appendChild(link);
        var back = document.createElement('button'); back.type = 'button'; back.textContent = 'Retour au paiement'; back.className = 'dcs__btn dcs__btn--ghost';
        back.addEventListener('click', function () { lowDialog.close(); }); lowDialog.appendChild(back);
        document.querySelector('.dcs--embedded').appendChild(lowDialog);
      }
      lowDialog.querySelector('[data-dcs-low-amount]').textContent = state ? 'Solde : ' + state.getAttribute('data-balance') + ' — Montant nécessaire : ' + state.getAttribute('data-required') : '';
      if (!lowDialog.open) lowDialog.showModal();
    }
    $(document.body).on('updated_checkout.dcs', function () { lowFunds(false); });
    $(document.body).on('checkout_error.dcs', function () {
      notify('paying', { value: false });
      var error = form.querySelector('.woocommerce-error');
      if (error && /insufficient|not enough|solde.*(?:insuffisant|faible)|fonds.*insuffisant/i.test(error.textContent)) lowFunds(true);
      else if (error) error.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });
    // The native success event supplies the gateway's redirect. Returning false
    // only prevents navigation of the child frame; the parent follows that URL.
    $(form).on('checkout_place_order_success.dcs', function (event, result) {
      if (!result || !result.redirect) return;
      notify('redirect', { url: result.redirect });
      return false;
    });
    var observer = new MutationObserver(function () {
      notify('paying', { value: form.classList.contains('processing') });
    });
    observer.observe(form, { attributes: true, attributeFilter: ['class'] });
    $(document.body).on('updated_checkout.dcs', function () {
      var total = form.querySelector('.order-total .woocommerce-Price-amount');
      var button = form.querySelector('#place_order');
      // Use Woo's freshly rendered total and respect gateway-specific labels.
      var method = form.querySelector('[name="payment_method"]:checked');
      if (button && total && !(method && method.getAttribute('data-order_button_text'))) {
        button.textContent = 'Payer maintenant ' + total.textContent.trim();
      }
    });
    var footerObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(function () {
      var footer = form.querySelector('#payment .place-order');
      document.querySelector('.dcs--embedded').style.setProperty('--dcs-embedded-footer', ((footer ? footer.offsetHeight : 200) + 24) + 'px');
    }) : null;
    function observeFooter() {
      if (!footerObserver) return;
      footerObserver.disconnect();
      var footer = form.querySelector('#payment .place-order');
      if (footer) footerObserver.observe(footer);
    }
    $(document.body).on('updated_checkout.dcs', observeFooter);
    observeFooter();
    document.addEventListener('click', function (event) {
      var link = event.target.closest('a[data-dpn-wallet-link],a[data-dcs-topup]');
      if (link) { event.preventDefault(); notify('redirect', { url: link.href }); }
    });
    notify('ready');
  });
})(window.jQuery);
