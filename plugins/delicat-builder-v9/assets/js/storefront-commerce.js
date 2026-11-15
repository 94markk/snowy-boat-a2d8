(function () {
  'use strict';

  var body = document.body;
  if (!body) return;

  function normalizeCheckoutLogin() {
    if (!body.classList.contains('delicat-native-checkout')) return;
    var root = document.querySelector('.delicat-native-page-main > .woocommerce') || document.querySelector('.woocommerce');
    if (!root || !root.querySelector('.dip-wc-login-panel')) return;
    body.classList.add('dcn-checkout-login-normalized');

    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    var nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function (node) {
      if (node.nodeValue && node.nodeValue.indexOf('<span class="dc-native-login-required">') !== -1) {
        node.nodeValue = 'Veuillez vous connecter pour finaliser votre commande.';
      }
    });
  }

  function bindClearCart() {
    var button = document.querySelector('[data-dcn-clear-cart]');
    var config = window.DelicatStorefrontCommerce || {};
    if (!button || !config.clearEndpoint || !config.clearNonce) return;

    button.addEventListener('click', function () {
      if (button.getAttribute('aria-busy') === 'true') return;
      if (config.clearConfirm && !window.confirm(config.clearConfirm)) return;
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;

      var payload = new URLSearchParams();
      payload.set('action', config.clearAction || 'delicat_builder_v9_clear_cart');
      payload.set('security', config.clearNonce);
      window.fetch(config.clearEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: payload.toString()
      }).then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      }).then(function (result) {
        if (!result || !result.success) throw new Error('WooCommerce rejected the request');
        body.classList.add('dcn-cart-cleared');
        window.location.reload();
      }).catch(function () {
        button.disabled = false;
        button.setAttribute('aria-busy', 'false');
        window.alert(config.clearError || 'Impossible de vider le panier. Réessayez.');
      });
    });
  }

  function bindCartSwipe() {
    if (!body.classList.contains('delicat-native-cart') || !window.matchMedia('(max-width: 820px)').matches) return;
    var rows = Array.prototype.slice.call(document.querySelectorAll('tr.cart_item'));
    var openRow = null;

    function close(row) {
      if (!row) return;
      row.classList.remove('is-dcn-open', 'is-dcn-dragging');
      row.style.removeProperty('--dcn-swipe-x');
      if (openRow === row) openRow = null;
    }

    rows.forEach(function (row) {
      var startX = 0;
      var startY = 0;
      var dragging = false;
      var beganOpen = false;

      row.addEventListener('pointerdown', function (event) {
        if (event.pointerType === 'mouse' && event.button !== 0) return;
        if (event.target.closest('a,button,input,select,textarea,label')) return;
        if (openRow && openRow !== row) close(openRow);
        startX = event.clientX;
        startY = event.clientY;
        beganOpen = row.classList.contains('is-dcn-open');
        dragging = true;
        row.classList.add('is-dcn-dragging');
        if (row.setPointerCapture) row.setPointerCapture(event.pointerId);
      });

      row.addEventListener('pointermove', function (event) {
        if (!dragging) return;
        var dx = event.clientX - startX;
        var dy = event.clientY - startY;
        if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 8) {
          dragging = false;
          row.classList.remove('is-dcn-dragging');
          row.style.removeProperty('--dcn-swipe-x');
          return;
        }
        var offset = Math.max(-88, Math.min(0, dx + (beganOpen ? -88 : 0)));
        row.style.setProperty('--dcn-swipe-x', offset + 'px');
      });

      function finish(event) {
        if (!dragging) return;
        dragging = false;
        row.classList.remove('is-dcn-dragging');
        var dx = event.clientX - startX + (beganOpen ? -88 : 0);
        row.style.removeProperty('--dcn-swipe-x');
        if (dx < -42) {
          row.classList.add('is-dcn-open');
          openRow = row;
        } else {
          close(row);
        }
      }

      row.addEventListener('pointerup', finish);
      row.addEventListener('pointercancel', function () {
        dragging = false;
        row.classList.remove('is-dcn-dragging');
        row.style.removeProperty('--dcn-swipe-x');
      });
    });

    document.addEventListener('pointerdown', function (event) {
      if (openRow && !openRow.contains(event.target)) close(openRow);
    }, true);
  }

  normalizeCheckoutLogin();
  bindClearCart();
  bindCartSwipe();
}());
