/* Delicat Wallet balance guard (RC19, shared with the RC20 express sheet; ES5, no storage, no endpoint).
   The server drops a hidden [data-dpn-wallet-guard] element next to Woo's
   place-order button; it is refreshed with the payment fragment on every
   update_order_review, so the numbers always match the current totals. When
   the wallet cannot cover the order and the wallet is the chosen (or only)
   payment route, the submit is stopped before WooCommerce sees it and a
   bottom sheet explains how to recharge. */
(function () {
  'use strict';
  var doc = document;
  var body = doc.body;
  if (!body || !body.classList || (!body.classList.contains('dpn-checkout') && !body.classList.contains('dnp-express-enabled'))) return;

  function closest(node, selector) {
    while (node && node.nodeType === 1) {
      if (node.matches ? node.matches(selector) : (node.msMatchesSelector && node.msMatchesSelector(selector))) return node;
      node = node.parentNode;
    }
    return null;
  }
  function guard() { return doc.querySelector('[data-dpn-wallet-guard]'); }
  function modal() { return doc.querySelector('[data-dpn-wallet-modal]'); }
  function walletRoute() {
    var checked = doc.querySelector('input[name="payment_method"]:checked');
    if (checked) return /wallet/i.test(String(checked.value || ''));
    /* No gateway at all (TeraWallet hides itself when the balance is short): the wallet is still the only route. */
    return !doc.querySelector('input[name="payment_method"]');
  }
  function setText(root, selector, value) {
    var el = root.querySelector(selector);
    if (el) el.textContent = value;
  }
  var closeTimer = null;
  function open(state) {
    var m = modal();
    if (!m) return;
    if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
    setText(m, '[data-dpn-wallet-balance]', state.getAttribute('data-balance-text') || '');
    setText(m, '[data-dpn-wallet-total]', state.getAttribute('data-total-text') || '');
    setText(m, '[data-dpn-wallet-short]', state.getAttribute('data-short-text') || '');
    var link = m.querySelector('[data-dpn-wallet-link]');
    var url = state.getAttribute('data-wallet-url');
    if (link && url) link.setAttribute('href', url);
    m.hidden = false;
    body.classList.add('dpn-wallet-modal-open');
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () { m.classList.add('is-open'); });
    });
    var card = m.querySelector('[data-dpn-wallet-card]');
    if (card && card.focus) { try { card.focus(); } catch (e) {} }
  }
  function close() {
    var m = modal();
    if (!m || m.hidden) return;
    m.classList.remove('is-open');
    body.classList.remove('dpn-wallet-modal-open');
    closeTimer = window.setTimeout(function () { m.hidden = true; closeTimer = null; }, 240);
  }
  /* RC21: the express review sheet asks for the check directly. */
  window.DelicatWalletGuard = {
    check: function (force) {
      var state = guard();
      if (!state || state.getAttribute('data-insufficient') !== '1' || (!force && !walletRoute())) return false;
      open(state);
      return true;
    },
    close: close
  };
  function intercept(event) {
    var state = guard();
    if (!state || state.getAttribute('data-insufficient') !== '1' || !walletRoute()) return false;
    event.preventDefault();
    if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    event.stopPropagation();
    open(state);
    return true;
  }

  doc.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || target.nodeType !== 1) return;
    if (closest(target, '#place_order')) { intercept(event); return; }
    if (closest(target, '[data-dpn-wallet-close]')) { event.preventDefault(); close(); }
  }, true);
  doc.addEventListener('submit', function (event) {
    var form = event.target;
    if (form && form.classList && form.classList.contains('checkout')) intercept(event);
  }, true);
  doc.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' || event.keyCode === 27) close();
  });
}());
