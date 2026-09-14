/* PRO20: native product POST, complete WooCommerce checkout in a same-origin frame. */
(function () {
  'use strict';
  var config = window.DelicatCheckoutSheet || {};
  var sheet = document.getElementById('dcs-sheet');
  if (!sheet || typeof sheet.showModal !== 'function') return;
  var body = sheet.querySelector('[data-dcs-body]');
  var trust = sheet.querySelector('[data-dcs-trust]');
  var loading = body.innerHTML;
  // The dock delegates to the real button so product validation click handlers
  // still execute and the same WooCommerce form/submitter reaches this module.
  window.DelicatCheckoutSheetOpen = function (form, button) {
    if (!form || !button || button.disabled || busy || paying) return;
    button.click();
  };
  var frame = null, busy = false, paying = false, previousOverflow = '', timer;
  var lastForm = null, lastSignature = '';
  function signature(data) {
    return JSON.stringify(Array.from(data.entries()).map(function (entry) {
      return [entry[0], typeof entry[1] === 'string' ? entry[1] : [entry[1].name, entry[1].size, entry[1].lastModified]];
    }));
  }
  function open() {
    previousOverflow = document.documentElement.style.overflow;
    sheet.showModal();
    document.documentElement.style.overflow = 'hidden';
  }
  function close() { if (!busy && !paying) sheet.close(); }
  sheet.addEventListener('cancel', function (event) { if (busy || paying) event.preventDefault(); });
  sheet.addEventListener('close', function () {
    document.documentElement.style.overflow = previousOverflow;
    // Keep the mounted checkout: reopening does not add the product a second time.
  });
  sheet.querySelector('[data-dcs-close]').addEventListener('click', close);
  sheet.addEventListener('click', function (event) {
    if (event.target !== sheet) return;
    var r = sheet.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) close();
  });
  function fallback() {
    clearTimeout(timer);
    // Never replay an ambiguous product POST or a payment request.
    window.location.assign(config.checkoutUrl);
  }
  function ready() {
    clearTimeout(timer);
    busy = false;
    body.querySelectorAll('[data-dcs-loading]').forEach(function (node) { node.remove(); });
    frame.style.visibility = 'visible'; frame.setAttribute('data-dcs-ready', '1');
    if (trust) trust.hidden = false;
  }
  window.addEventListener('message', function (event) {
    if (!frame || event.source !== frame.contentWindow || event.origin !== window.location.origin) return;
    var data = event.data || {};
    if (data.type === 'dcs:ready') ready();
    if (data.type === 'dcs:paying') paying = !!data.value;
    if (data.type === 'dcs:redirect') {
      try {
        var url = new URL(data.url, window.location.href);
        if (url.protocol === 'https:' || (url.protocol === 'http:' && location.protocol === 'http:')) window.location.assign(url.href);
      } catch (ignore) { /* Invalid gateway redirect: keep native checkout visible. */ }
    }
  });
  document.addEventListener('submit', function (event) {
    var form = event.target, button = event.submitter;
    if (!form || !form.matches('form.cart') || !button || button.name !== 'delicat_native_buy_now') return;
    if (!config.checkoutUrl) return;
    try {
      var actionUrl = new URL(form.action || location.href, location.href);
      var checkoutUrl = new URL(config.checkoutUrl, location.href);
      if (actionUrl.origin !== location.origin || checkoutUrl.origin !== location.origin) return;
    } catch (invalidUrl) { return; }
    event.preventDefault();
    event.stopImmediatePropagation();
    if (busy || paying) return;
    if (!form.reportValidity()) return;
    var variation = form.querySelector('[name="variation_id"]');
    if (variation && (!variation.value || variation.value === '0')) {
      window.alert('Veuillez choisir une option avant de continuer.');
      return;
    }
    var data = new FormData(form), key = signature(data);
    if (frame && form === lastForm && key === lastSignature) { open(); return; }
    lastForm = form; lastSignature = key;
    body.innerHTML = loading;
    if (trust) trust.hidden = true;
    frame = document.createElement('iframe');
    frame.name = 'delicat-checkout-' + Date.now();
    frame.title = 'Finaliser votre commande';
    frame.className = 'dcs__frame';
    frame.style.visibility = 'hidden';
    body.appendChild(frame);
    busy = true;
    open();
    frame.addEventListener('load', function () {
      try {
        var doc = frame.contentDocument;
        if (!doc || frame.contentWindow.location.href === 'about:blank') return;
        if (doc.querySelector('.dcs--embedded')) { ready(); return; }
        var errors = doc.querySelector('.woocommerce-error');
        if (errors) {
          clearTimeout(timer); busy = false;
          var notice = document.createElement('div');
          notice.className = 'woocommerce-error'; notice.setAttribute('role', 'alert');
          notice.textContent = errors.textContent;
          body.replaceChildren(notice); frame = null;
          return;
        }
        // Preserve native login/order-received redirects without replaying any POST.
        clearTimeout(timer);
        window.location.assign(frame.contentWindow.location.href);
      } catch (ignore) { fallback(); }
    });
    var oldTarget = form.getAttribute('target'), additions = [];
    function hidden(name, value) {
      var input = document.createElement('input'); input.type = 'hidden';
      input.name = name; input.value = value; form.appendChild(input); additions.push(input);
    }
    hidden(button.name, button.value || '1');
    hidden('dcs_frame', '1');
    // Simple products put add-to-cart on the OTHER submit button; include its ID.
    if (!data.has('add-to-cart')) {
      var add = form.querySelector('[name="add-to-cart"]');
      if (add) hidden('add-to-cart', add.value);
    }
    form.target = frame.name;
    timer = setTimeout(function () {
      var wait = body.querySelector('[data-dcs-loading]');
      if (!wait) return;
      var link = document.createElement('a');
      link.href = config.checkoutUrl; link.textContent = 'La connexion est lente. Continuer sur la page de paiement';
      link.className = 'dcs__btn dcs__btn--ghost'; wait.appendChild(link);
    }, 20000);
    try { HTMLFormElement.prototype.submit.call(form); }
    catch (error) { fallback(); }
    finally {
      additions.forEach(function (node) { node.remove(); });
      if (oldTarget === null) form.removeAttribute('target'); else form.setAttribute('target', oldTarget);
    }
  }, true);
})();
