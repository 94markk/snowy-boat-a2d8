/* Delicat Builder V9 — express review sheet (RC24 transport). ES5, no storage, no jQuery.
   1. "Acheter maintenant" posts the real product form through WooCommerce's
      classic add-to-cart handler (fetch). WooCommerce answers with JSON:
      accepted, or its own error notices (shown next to the button).
   2. The sheet loads WooCommerce's real checkout form for the current cart
      from wc-ajax=delicat_express_form and shows it as a review.
   3. Wallet payment uses the guarded WC_Checkout transport; other gateways use full checkout.
   Add ?dnp_express_debug=1 as a shop manager to see every step on screen. */
(function () {
  'use strict';
  var doc = document;
  var body = doc.body;
  if (!body) return;
  var cfg = {}, i18n = {};
  var raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 16); };
  var modal = null, sheet = null, scroller = null, notice = null, summary = null, details = null, pay = null, payLabel = null, terms = null, woo = null;
  var uncertain = false, checkingStatus = false;
  var busy = false, bypass = false, closeTimer = null, lastFocus = null, lastSignature = '', armed = false, paying = false, loading = false;
  var state = { total: '', insufficient: false, walletUrl: cfg.walletUrl || '', gateway: '', hasGateway: false };
  var debugPanel = null;

  function log(step, extra) {
    if (!cfg.debug) return;
    try {
      if (!debugPanel) {
        debugPanel = el('pre', 'dnp-express-debug');
        debugPanel.setAttribute('aria-hidden', 'true');
        body.appendChild(debugPanel);
      }
      debugPanel.textContent += new Date().toISOString().slice(11, 19) + ' ' + step + (extra !== undefined ? ' ' + (typeof extra === 'string' ? extra : JSON.stringify(extra)).slice(0, 300) : '') + '\n';
    } catch (e) {}
  }
  function closest(node, selector) {
    while (node && node.nodeType === 1) {
      if (node.matches ? node.matches(selector) : (node.msMatchesSelector && node.msMatchesSelector(selector))) return node;
      node = node.parentNode;
    }
    return null;
  }
  function text(node) { return node ? String(node.textContent || '').replace(/\s+/g, ' ').trim() : ''; }
  function el(tag, className, content) {
    var node = doc.createElement(tag);
    if (className) node.className = className;
    if (content !== undefined && content !== null) node.textContent = content;
    return node;
  }
  function form() { return woo ? woo.querySelector('form.checkout') : null; }
  function supported() { if (!cfg.formUrl || !cfg.checkoutUrl) resolveConfig(); return !!(window.fetch && window.FormData && cfg.formUrl && cfg.checkoutUrl); }

  /* WooCommerce answers with JSON; tolerate stray output before it exactly like Woo's own script does. */
  function parseJson(raw) {
    try { return JSON.parse(raw); } catch (e) {}
    var start = raw.indexOf('{"result');
    if (start === -1) start = raw.indexOf('{"success');
    var end = raw.lastIndexOf('}');
    if (start !== -1 && end > start) { try { return JSON.parse(raw.slice(start, end + 1)); } catch (e2) {} }
    return null;
  }
  function request(url, options) {
    options = options || {};
    var target = safeUrl(url);
    if (!target) return Promise.reject(new Error('unsafe-url'));
    options.credentials = 'same-origin'; options.cache = 'no-store'; options.redirect = 'error';
    var timer;
    var deadline = new Promise(function (resolve, reject) {
      timer = window.setTimeout(function () { reject(new Error('response-timeout')); }, 20000);
    });
    // A UI deadline never implies server cancellation and never authorizes a payment retry.
    var response = fetch(target, options).then(function (res) {
      return res.text().then(function (raw) { return { ok: res.ok, status: res.status, type: res.headers.get('content-type') || '', raw: raw }; });
    });
    return Promise.race([response, deadline]).then(function (value) { window.clearTimeout(timer); return value; }, function (err) { window.clearTimeout(timer); throw err; });
  }
  function checkStatus() {
    var f = form(); if (!f || !cfg.statusUrl || checkingStatus) return;
    checkingStatus = true;
    var fd = new FormData();
    ['delicat_intent', 'delicat_cart_hash', 'delicat_issued', 'delicat_intent_nonce'].forEach(function (name) {
      var input = f.querySelector('[name="' + name + '"]'); if (input) fd.append(name, input.value);
    });
    setNotice('Vérification du résultat…');
    request(cfg.statusUrl, { method: 'POST', body: fd }).then(function (res) {
      checkingStatus = false;
      var data = parseJson(res.raw);
      if (res.ok && data && data.state === 'confirmed' && safeUrl(data.redirect)) { window.location.href = safeUrl(data.redirect); return; }
      if (res.ok && data && data.state === 'rejected') {
        uncertain = false;
        setNotice('La validation a été refusée avant paiement. Corrigez vos informations et préparez une nouvelle vérification.');
        var retry = el('button', 'dnp-express-fallback', 'Revoir la commande'); retry.type = 'button';
        retry.addEventListener('click', function () { loadForm(); }); notice.appendChild(retry); return;
      }
      if (res.ok && data && data.state === 'declined') {
        uncertain = false;
        setNotice('Le paiement a été refusé (solde insuffisant ou refus de la passerelle) et aucun débit n’a été effectué. Rechargez votre wallet si nécessaire, puis préparez une nouvelle vérification.');
        var again = el('button', 'dnp-express-fallback', 'Revoir la commande'); again.type = 'button';
        again.addEventListener('click', function () { loadForm(); }); notice.appendChild(again); return;
      }
      setNotice('Résultat en cours de vérification. Consultez vos commandes ; ne payez pas une seconde fois.');
    }).catch(function () { checkingStatus = false; setNotice('Vérification momentanément indisponible. Consultez vos commandes ou contactez le support.'); });
  }

  /* ---------- sheet ---------- */
  function setNotice(message, kind) {
    if (!notice) return;
    notice.textContent = '';
    if (!message || (message.length !== undefined && !message.length)) { notice.hidden = true; return; }
    if (typeof message === 'string') notice.appendChild(el('p', '', message));
    else for (var i = 0; i < message.length; i++) notice.appendChild(el('p', '', message[i]));
    if (cfg.fullCheckout) {
      var link = el('a', 'dnp-express-fallback', paying || uncertain ? 'Vérifier mes commandes' : (i18n.fullCheckout || 'Ouvrir le paiement complet'));
      link.href = safeUrl(paying || uncertain ? cfg.ordersUrl : cfg.fullCheckout);
      notice.appendChild(link);
    }
    notice.className = 'dnp-express-notice is-' + (kind || 'error');
    notice.hidden = false;
    if (uncertain && cfg.statusUrl && !checkingStatus) {
      var statusButton = el('button', 'dnp-express-fallback', 'Vérifier ce paiement'); statusButton.type = 'button';
      statusButton.addEventListener('click', function () { statusButton.disabled = true; checkStatus(); }); notice.appendChild(statusButton);
    }
  }
  function setBusy(on, label) {
    busy = on;
    body.classList.toggle('dnp-express-busy', on);
    var buttons = doc.querySelectorAll('.dnp-buy-now,[data-dnp-proxy="buy"]');
    for (var i = 0; i < buttons.length; i++) {
      var b = buttons[i];
      if (on) {
        b.setAttribute('aria-busy', 'true');
        if (!b.getAttribute('data-dnp-express-label')) b.setAttribute('data-dnp-express-label', b.textContent);
        if (label) b.textContent = label;
      } else {
        b.removeAttribute('aria-busy');
        var original = b.getAttribute('data-dnp-express-label');
        if (original) b.textContent = original;
      }
    }
  }
  function setPay(mode) {
    if (!pay) return;
    pay.classList.remove('is-recharge', 'is-paying');
    pay.disabled = false;
    if (mode === 'loading') { pay.disabled = true; payLabel.textContent = i18n.loading || ''; }
    else if (mode === 'paying') { pay.disabled = true; pay.classList.add('is-paying'); payLabel.textContent = i18n.paying || ''; }
    else if (mode === 'recharge') { pay.classList.add('is-recharge'); payLabel.textContent = i18n.recharge || ''; }
    else if (mode === 'blocked') { pay.disabled = true; payLabel.textContent = i18n.pay || ''; }
    else { payLabel.textContent = (i18n.pay || '') + (state.total ? ' ' + state.total : ''); }
  }
  function open() {
    if (!modal) return;
    lastFocus = doc.activeElement;
    if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
    modal.hidden = false;
    body.classList.add('dnp-express-open');
    void modal.offsetHeight; /* commit the un-hide first so the slide-up transition plays… */
    modal.classList.add('is-open'); /* …but never depend on a frame callback to become visible */
    if (scroller) scroller.scrollTop = 0;
    if (sheet) { try { sheet.focus(); } catch (e) {} }
    log('sheet open');
    if (!uncertain) loadForm();
  }
  function close() {
    if (!modal || modal.hidden || paying) return;
    modal.classList.remove('is-open');
    body.classList.remove('dnp-express-open');
    closeTimer = window.setTimeout(function () { modal.hidden = true; closeTimer = null; }, 260);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
  }
  function inlineError(messages, anchor) {
    var host = doc.querySelector('.delicat-native-product .dnp-purchase') || (anchor && anchor.parentNode) || null;
    var old = doc.querySelector('[data-dnp-express-inline]');
    if (old) old.parentNode.removeChild(old);
    if (!host) { window.alert(messages.join('\n')); return; }
    var box = el('div', 'dnp-express-inline');
    box.setAttribute('data-dnp-express-inline', '1');
    box.setAttribute('role', 'alert');
    for (var i = 0; i < messages.length; i++) box.appendChild(el('p', '', messages[i]));
    host.insertBefore(box, host.firstChild);
    try { box.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
  }

  /* ---------- WooCommerce's checkout form for the current cart ---------- */
  function loadForm() {
    if (loading || paying) return;
    loading = true;
    setNotice('');
    setPay('loading');
    if (summary) summary.classList.add('is-loading');
    log('form load', cfg.formUrl);
    request(cfg.formUrl, { method: 'GET' })
      .then(function (res) {
        loading = false;
        log('form response', { status: res.status, type: res.type, length: res.raw.length });
        if (!res.ok || res.raw.indexOf('<form') === -1) { setNotice(i18n.network, 'error'); setPay('blocked'); return; }
        var parsed = inert(res.raw);
        var fresh = parsed ? parsed.querySelector('form.checkout') : null;
        woo.textContent = '';
        if (fresh) woo.appendChild(doc.importNode(fresh, true)); /* only the form itself; nothing else from the response is attached */
        if (!form()) { setNotice(i18n.network, 'error'); setPay('blocked'); log('form missing form.checkout'); return; }
        render();
      })
      .catch(function (err) { loading = false; log('form error', String(err)); setNotice(i18n.network, 'error'); setPay('blocked'); });
  }
  /* WooCommerce's HTML is parsed in an inert document: nothing in it can run or load. */
  function inert(html) {
    try { return new DOMParser().parseFromString('<!doctype html><body>' + String(html || ''), 'text/html'); } catch (e) { return null; }
  }
  function safeUrl(value) {
    try {
      var url = new URL(value || '', window.location.href);
      return value && url.protocol === 'https:' && url.origin === window.location.origin ? url.href : '';
    } catch (e) { return ''; }
  }
  function stripMessages(html) {
    var parsed = inert(html);
    var holder = parsed ? parsed.body : el('div');
    var items = holder.querySelectorAll('li');
    var out = [];
    for (var i = 0; i < items.length; i++) { var t = text(items[i]); if (t) out.push(t); }
    if (!out.length) { var t2 = text(holder); if (t2) out.push(t2); }
    return out.length ? out : (i18n.failed || '');
  }
  function row(label, value, className) {
    var line = el('div', 'dnp-express-row' + (className ? ' ' + className : ''));
    line.appendChild(el('span', 'dnp-express-row-label', label));
    var v = el('span', 'dnp-express-row-value');
    if (typeof value === 'string') v.textContent = value; else if (value) v.appendChild(value);
    line.appendChild(v);
    return line;
  }
  function chosenGateway() {
    var f = form();
    if (!f) return '';
    var radios = f.querySelectorAll('input[name="payment_method"]');
    var wallet = null, checked = null;
    for (var i = 0; i < radios.length; i++) {
      if (/wallet/i.test(radios[i].value)) wallet = radios[i];
      if (radios[i].checked) checked = radios[i];
    }
    var pick = wallet || checked || radios[0] || null;
    if (pick) { for (var k = 0; k < radios.length; k++) radios[k].checked = (radios[k] === pick); }
    return pick ? pick.value : '';
  }
  function render() {
    var f = form();
    if (!f || !summary) return;
    summary.classList.remove('is-loading');
    summary.textContent = '';
    var table = f.querySelector('.woocommerce-checkout-review-order-table');
    var items = table ? table.querySelectorAll('tr.cart_item') : [];
    var card = el('div', 'dnp-express-card');

    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      var nameCell = item.querySelector('.product-name');
      var nameNode = nameCell ? nameCell.cloneNode(true) : null;
      var metaNodes = [];
      var qty = '';
      if (nameNode) {
        var q = nameNode.querySelector('.product-quantity');
        if (q) { qty = text(q).replace(/^×\s*/, '').replace(/^x\s*/i, ''); q.parentNode.removeChild(q); }
        var lists = nameNode.querySelectorAll('dl.variation, .wc-item-meta');
        for (var m = 0; m < lists.length; m++) { metaNodes.push(lists[m]); lists[m].parentNode.removeChild(lists[m]); }
      }
      var line = el('div', 'dnp-express-item');
      var fullName = text(nameNode) || cfg.productName || '';
      var option = '';
      var sep = fullName.indexOf(' - ');
      if (sep > 0) { option = fullName.slice(sep + 3).trim(); fullName = fullName.slice(0, sep).trim(); }
      line.appendChild(row(i18n.product || 'Produit', fullName, 'is-name'));
      if (option) line.appendChild(row(i18n.option || 'Option', option));
      for (var n = 0; n < metaNodes.length; n++) {
        var labels = metaNodes[n].querySelectorAll('dt, .wc-item-meta-label');
        for (var l = 0; l < labels.length; l++) {
          var lab = text(labels[l]).replace(/:\s*$/, '');
          var val = text(labels[l].nextElementSibling);
          if (lab && val) line.appendChild(row(lab, val));
        }
      }
      if (qty && qty !== '1') line.appendChild(row(i18n.quantity || 'Quantité', qty));
      var totalCell = item.querySelector('.product-total');
      if (totalCell && items.length > 1) line.appendChild(row(i18n.subtotal || 'Sous-total', text(totalCell), 'is-line-total'));
      card.appendChild(line);
    }
    var extra = table ? table.querySelectorAll('tfoot tr.cart-discount, tfoot tr.fee, tfoot tr.shipping, tfoot tr.tax-rate, tfoot tr.tax-total') : [];
    if (extra.length) {
      var sub = table.querySelector('tfoot tr.cart-subtotal');
      if (sub) card.appendChild(row(text(sub.querySelector('th')) || i18n.subtotal, text(sub.querySelector('td')), 'is-muted'));
      for (var x = 0; x < extra.length; x++) {
        var label = extra[x].querySelector('th'), amount = extra[x].querySelector('td');
        if (label && amount) card.appendChild(row(text(label), text(amount), 'is-muted'));
      }
    }
    var totalRow = table ? table.querySelector('tfoot tr.order-total') : null;
    var totalAmount = totalRow ? (totalRow.querySelector('.woocommerce-Price-amount') || totalRow.querySelector('td')) : null;
    state.total = text(totalAmount);
    card.appendChild(row(i18n.total || 'Total', state.total, 'is-total'));
    summary.appendChild(card);

    var guard = f.querySelector('[data-dpn-wallet-guard]');
    state.insufficient = !!(guard && guard.getAttribute('data-insufficient') === '1');
    if (guard && guard.getAttribute('data-wallet-url')) state.walletUrl = safeUrl(guard.getAttribute('data-wallet-url'));
    state.hasGateway = f.querySelectorAll('input[name="payment_method"]').length > 0;
    state.gateway = chosenGateway();
    var wallet = el('div', 'dnp-express-wallet');
    if (guard) {
      wallet.appendChild(row(i18n.balance || 'Solde wallet', guard.getAttribute('data-balance-text') || ''));
      if (!state.insufficient) {
        var after = afterBalance(guard);
        if (after) wallet.appendChild(row(i18n.after || 'Après paiement', after, 'is-after'));
      }
    }
    if (state.gateway) {
      var lab2 = f.querySelector('label[for="payment_method_' + state.gateway + '"]');
      wallet.appendChild(row(i18n.payment || 'Paiement', state.gateway === 'wallet' ? 'Delicat Wallet' : (lab2 ? text(lab2) : state.gateway), 'is-gateway'));
    }
    if (wallet.childNodes.length) summary.appendChild(wallet);

    state.fullRequired = false;
    renderDetails(f);

    if (terms) {
      terms.textContent = '';
      var termsBox = f.querySelector('input[name="terms"]');
      if (true) {
        var consent = el('input'); consent.type = 'checkbox'; consent.id = 'dnp-express-consent';
        consent.setAttribute('aria-label', 'J’accepte les conditions d’utilisation');
        terms.appendChild(consent);
        terms.appendChild(doc.createTextNode(' J’accepte les' + ' '));
        var a = el('a', '', i18n.termsLabel || 'conditions d’utilisation');
        a.href = safeUrl(cfg.termsUrl) || '#'; a.target = '_blank'; a.rel = 'noopener noreferrer';
        terms.appendChild(a);
        terms.appendChild(doc.createTextNode('.'));
        var consentLabel = el('label');
        while (terms.firstChild) consentLabel.appendChild(terms.firstChild);
        terms.appendChild(consentLabel);
        terms.hidden = false;
      } else terms.hidden = true;
    }
    log('rendered', { items: items.length, total: state.total, gateway: state.gateway, insufficient: state.insufficient });
    if (state.insufficient) { setNotice(i18n.insufficient || '', 'warn'); setPay('recharge'); return; }
    if (!state.hasGateway && !/^\D*0(?:[.,]0+)?\D*$/.test(state.total)) setNotice(i18n.noGateway || '', 'warn');
    setPay('ready');
    if (state.gateway !== 'wallet' || state.fullRequired) payLabel.textContent = 'Continuer vers le paiement';
  }
  function afterBalance(guard) {
    var balance = parseFloat(guard.getAttribute('data-balance') || ''), total = parseFloat(guard.getAttribute('data-total') || '');
    if (isNaN(balance) || isNaN(total)) return '';
    var left = balance - total;
    var sample = guard.getAttribute('data-balance-text') || '';
    var digits = sample.replace(/[^\d.,]/g, '');
    var decimals = digits.indexOf('.') !== -1 ? digits.split('.').pop().length : 0;
    var formatted = left.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    var first = sample.search(/\d/);
    var prefix = first > 0 ? sample.slice(0, first) : '';
    var lastDigit = sample.search(/\d[^\d]*$/);
    var suffix = lastDigit !== -1 ? sample.slice(lastDigit + 1) : '';
    return prefix + formatted + suffix;
  }
  function renderDetails(f) {
    if (!details) return;
    details.textContent = '';
    var required = f.querySelectorAll('.woocommerce-billing-fields .validate-required input:not([type="hidden"]), .woocommerce-billing-fields .validate-required select, .woocommerce-billing-fields .validate-required textarea, .woocommerce-additional-fields .validate-required input:not([type="hidden"]), .woocommerce-additional-fields .validate-required select, .woocommerce-additional-fields .validate-required textarea');
    var shown = 0;
    for (var i = 0; i < required.length; i++) {
      var hidden = required[i]; if (hidden.disabled) continue;
      // Country/state or extension-driven checkout controls need Woo's full live update cycle.
      if (/country|state/.test(hidden.name) || hidden.type === 'file') state.fullRequired = true;
      var wrap = el('label', 'dnp-express-field');
      var labelNode = hidden.closest ? hidden.closest('.form-row') : null;
      labelNode = labelNode ? labelNode.querySelector('label') : null;
      wrap.appendChild(el('span', '', text(labelNode).replace(/\*\s*$/, '') || hidden.name));
      var input = hidden.cloneNode(true); input.id = 'dnpx-field-' + i;
      input.value = hidden.value || ''; input.checked = hidden.checked;
      input.removeAttribute('name'); input.setAttribute('aria-required', 'true');
      (function (source, target) {
        function sync() { target.value = source.value; target.checked = source.checked; }
        source.addEventListener('input', sync); source.addEventListener('change', sync);
      }(input, hidden));
      wrap.appendChild(input); details.appendChild(wrap); shown++;
    }
    if (shown) { details.insertBefore(el('h3', '', i18n.details || 'Vos coordonnées'), details.firstChild); details.hidden = false; }
    else details.hidden = true;
  }

  /* ---------- pay: WooCommerce's checkout endpoint ---------- */
  function checkout() {
    if (paying || loading || !pay || pay.disabled) return;
    if (state.insufficient) { window.location.href = safeUrl(state.walletUrl); return; }
    var f = form();
    if (!f) return;
    if (state.gateway !== 'wallet' || state.fullRequired) { window.location.href = safeUrl(cfg.fullCheckout); return; }
    var consent = terms.querySelector('input');
    if (!consent || !consent.checked) { setNotice('Veuillez accepter les conditions avant de payer.'); return; }
    var originalTerms = f.querySelector('input[name="terms"]');
    if (originalTerms) originalTerms.checked = true;
    paying = true;
    setNotice('');
    setPay('paying');
    var paymentData = new FormData(f); paymentData.append('delicat_consent', '1');
    request(cfg.checkoutUrl, { method: 'POST', body: paymentData }).then(function (res) {
      var data = parseJson(res.raw);
      if (!res.ok || !data || !data.result) throw new Error('unknown');
      if (data.result === 'success') {
        var redirect = safeUrl(data.redirect);
        if (redirect) { window.location.href = redirect; return; }
        paying = false; uncertain = true;
        setNotice('Commande reçue. Consultez vos commandes pour vérifier le paiement.');
        setPay('blocked'); return;
      }
      // A declined/uncertain charge is never automatically retried.
      paying = false; uncertain = true;
      setNotice(stripMessages(data.messages)); setPay('blocked');
    }).catch(function () {
      paying = false; uncertain = true;
      setNotice('Connexion interrompue : le paiement peut avoir été effectué. Consultez vos commandes avant toute nouvelle tentative.');
      setPay('blocked');
    });
  }

  /* ---------- add to cart: WooCommerce's classic handler ---------- */
  function signature(fd) {
    var parts = [];
    try { fd.forEach(function (value, key) { if (typeof value === 'string') parts.push(key + '=' + value); }); } catch (e) { return ''; }
    return parts.sort().join('&');
  }
  function fallback(button, why) {
    log('uncertain add-to-cart result', why);
    setBusy(false);
    // A failed response does not prove WooCommerce failed to add the item.
    // Do not replay the POST, either automatically or from the same page.
    uncertain = true;
    button.disabled = true;
    inlineError(['Résultat incertain. Vérifiez votre panier avant de réessayer.'], closest(button, 'form.cart'));
    var target = safeUrl(cfg.cartUrl);
    if (target) window.location.href = target;
  }
  function express(productForm, button) {
    var fd = new FormData(productForm);
    if (!fd.has('add-to-cart')) {
      var addButton = productForm.querySelector('.single_add_to_cart_button[name="add-to-cart"]');
      var pid = (addButton && addButton.value) || cfg.productId || '';
      if (pid) fd.append('add-to-cart', String(pid));
    }
    fd.append('delicat_native_buy_now', '1');
    fd.append('dpn_express', '1');
    fd.append('_delicat_express_nonce', cfg.addNonce || '');
    var sig = signature(fd);
    if (armed && sig && sig === lastSignature) { log('reopen (same selection)'); open(); return; }
    setBusy(true, i18n.adding || '');
    var action = productForm.getAttribute('action') || window.location.href;
    log('add-to-cart post', action);
    request(action, { method: 'POST', body: fd })
      .then(function (res) {
        var data = parseJson(res.raw);
        log('add-to-cart response', { status: res.status, type: res.type, data: data || res.raw.slice(0, 120) });
        if (data && data.success) { armed = true; lastSignature = sig; setBusy(false); try { doc.dispatchEvent(new CustomEvent('delicat:cart-changed')); } catch (e) {} open(); return; }
        if (data && data.success === false) {
          setBusy(false);
          var messages = (data.messages && data.messages.length) ? data.messages : [i18n.refused || ''];
          inlineError(messages, productForm);
          return;
        }
        fallback(button, 'non-json ' + res.status);
      })
      .catch(function (err) { fallback(button, String(err)); });
  }

  function resolveConfig() {
    var found = window.DelicatExpress || null;
    if (!found && modal && modal.getAttribute('data-config')) { try { found = JSON.parse(modal.getAttribute('data-config')); } catch (e) { found = null; } }
    cfg = found || {};
    i18n = cfg.i18n || {};
  }
  function refs() {
    modal = doc.querySelector('[data-dnp-express]');
    if (!modal) return false;
    sheet = modal.querySelector('[data-dnp-express-sheet]');
    scroller = modal.querySelector('[data-dnp-express-scroll]');
    notice = modal.querySelector('[data-dnp-express-notice]');
    summary = modal.querySelector('[data-dnp-express-summary]');
    details = modal.querySelector('[data-dnp-express-details]');
    pay = modal.querySelector('[data-dnp-express-pay]');
    payLabel = modal.querySelector('[data-dnp-express-pay-label]');
    terms = modal.querySelector('[data-dnp-express-terms]');
    woo = modal.querySelector('[data-dnp-express-woo]');
    return true;
  }
  var bound = false;
  doc.addEventListener('delicat:shell:navigated', function () {
    /* RC35: a new product arrived under the same document — fresh sheet, fresh config, no double listeners */
    armed = false; lastSignature = ''; busy = false; paying = false; loading = false;
    if (refs()) { cfg = window.DelicatExpress || {}; resolveConfig(); body.classList.add('dnp-express-ready'); }
    else body.classList.remove('dnp-express-ready');
  });
  function bind() {
    if (bound) return;
    modal = doc.querySelector('[data-dnp-express]');
    if (!modal) { return; }
    bound = true;
    resolveConfig();
    sheet = modal.querySelector('[data-dnp-express-sheet]');
    scroller = modal.querySelector('[data-dnp-express-scroll]') || sheet;
    notice = modal.querySelector('[data-dnp-express-notice]');
    summary = modal.querySelector('[data-dnp-express-summary]');
    details = modal.querySelector('[data-dnp-express-details]');
    pay = modal.querySelector('[data-dnp-express-pay]');
    payLabel = modal.querySelector('[data-dnp-express-pay-label]');
    terms = modal.querySelector('[data-dnp-express-terms]');
    woo = modal.querySelector('[data-dnp-express-woo]');
    refs();
    body.classList.add('dnp-express-ready');
    log('bound', { productId: cfg.productId });

    doc.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || target.nodeType !== 1) return;
      if (closest(target, '[data-dnp-express-close]')) { event.preventDefault(); close(); return; }
      if (closest(target, '[data-dnp-express-pay]')) { event.preventDefault(); checkout(); return; }
      var button = closest(target, '.dnp-buy-now');
      if (!button) return;
      if (bypass) { bypass = false; return; }
      var session = window.DelicatSession && window.DelicatSession.get ? window.DelicatSession.get() : null;
      if (session && session.loggedIn === false) { log('guest session → classic'); return; }
      var productForm = closest(button, 'form.cart');
      var root = closest(button, '.delicat-native-product');
      if (!productForm || (root && root.getAttribute('data-express') === '0')) { log('classic submit (form/express flag)'); return; }
      if (button.disabled || button.classList.contains('disabled')) { log('button disabled'); return; }
      if (!supported()) { log('unsupported', { fetch: !!window.fetch, formUrl: cfg.formUrl, checkoutUrl: cfg.checkoutUrl }); return; }
      event.preventDefault();
      if (event.stopImmediatePropagation) event.stopImmediatePropagation();
      event.stopPropagation();
      if (busy || paying) return;
      if (uncertain) { open(); return; }
      express(productForm, button);
    }, true);

    doc.addEventListener('keydown', function (event) {
      if (event.key === 'Tab' && modal && !modal.hidden) {
        var nodes = modal.querySelectorAll('button:not(:disabled),a[href],input:not([type="hidden"]),[tabindex="0"]');
        var visible = Array.prototype.filter.call(nodes, function (n) { return n.getClientRects().length; });
        var first = visible[0], last = visible[visible.length - 1];
        if (event.shiftKey && (doc.activeElement === first || doc.activeElement === sheet)) { event.preventDefault(); if (last) last.focus(); }
        else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); if (first) first.focus(); }
      }

      if ((event.key === 'Escape' || event.keyCode === 27) && modal && !modal.hidden && !body.classList.contains('dpn-wallet-modal-open')) close();
    });
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bind, { once: true });
  else bind();
}());
