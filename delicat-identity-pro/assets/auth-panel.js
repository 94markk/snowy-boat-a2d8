/*
 * [delicat_auth_panel] sign-in: the identity modal's own AJAX endpoints
 * (DIP_Native_Auth: lockout, verified e-mail, approval and the 2FA step), so
 * the customer stays on the page, gets the precise message and types the
 * Authenticator code in the same card. The form still posts to wp-login.php
 * when this script cannot run, and for a username (the endpoint takes e-mail
 * addresses only). Passkeys are handled by passkeys.js (same form).
 */
(function () {
  'use strict';
  var FACTOR = ['dip_2fa_required', 'dip_2fa_invalid', 'dip_2fa_rate_limited'];
  var nonces = null, nonceAt = 0, pending = null;

  function cfg() { return window.DIPAuthPanel || {}; }
  function t(key, fallback) { var s = cfg().i18n || {}; return s[key] || fallback; }
  function field(form, name) { return form.querySelector('[name="' + name + '"]'); }

  function post(data) {
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null, timer;
    var deadline = new Promise(function (_, reject) {
      timer = setTimeout(function () { if (controller) controller.abort(); reject(new Error(t('slow', 'Le serveur met trop de temps à répondre. Réessayez.'))); }, 15000);
    });
    var network = fetch(cfg().ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store', signal: controller ? controller.signal : undefined })
      .then(function (r) { return r.text(); })
      .then(function (raw) { try { return JSON.parse(raw); } catch (e) { throw new Error(t('invalid', 'Réponse serveur invalide. Réessayez.')); } });
    return Promise.race([network, deadline]).then(function (r) { clearTimeout(timer); return r; }, function (e) { clearTimeout(timer); throw e; });
  }

  // Browser-bound nonces (HttpOnly cookie + nonce), as the modal frame gets them.
  function fresh(force) {
    if (!force && nonces && Date.now() - nonceAt < 300000) return Promise.resolve(nonces);
    if (pending) return pending;
    var data = new FormData();
    data.append('action', cfg().nonceAction);
    pending = post(data).then(function (r) {
      if (!r || !r.success || !r.data || !r.data.login) throw new Error((r && r.data && r.data.message) || t('prepare', 'Impossible de préparer la connexion. Réessayez.'));
      nonces = r.data; nonceAt = Date.now();
      return nonces;
    });
    pending.then(function () { pending = null; }, function () { pending = null; });
    return pending;
  }

  function show(form, text, type) {
    var el = form.querySelector('[data-dip-auth-message]');
    if (!el) return;
    el.textContent = text || '';
    el.hidden = !text;
    el.className = 'dip-passkey-status' + (type ? ' is-' + type : '');
  }

  function busy(form, on) {
    form.setAttribute('data-password-busy', on ? '1' : '0');
    form.setAttribute('aria-busy', on ? 'true' : 'false');
    var submit = form.querySelector('.dip-auth-primary');
    if (submit) submit.disabled = !!on;
    var resend = form.querySelector('[data-dip-auth-resend]');
    if (resend) resend.disabled = !!on;
  }

  function navigate(form, value) {
    var url;
    try { url = new URL(value || '/', window.location.href); } catch (e) { url = null; }
    if (!url || url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) url = new URL('/', window.location.href);
    // Same contract as passkeys.js: session-router.js must not reload this
    // page while it leaves; other tabs and the Builder V9 cache are told.
    try { window.__DIP_AUTH_NAVIGATING__ = true; } catch (e) {}
    try { var sw = navigator.serviceWorker; if (sw && sw.controller) sw.controller.postMessage({ type: 'dbv9-clear-docs' }); } catch (e) {}
    try { window.localStorage.setItem('dip-session-change', String(Date.now())); } catch (e) {}
    window.location.assign(url.href);
    setTimeout(function () { busy(form, false); show(form, t('stuck', 'Connexion réussie. Actualisez la page pour continuer.'), 'success'); }, 10000);
  }

  function openFactor(form) {
    var box = form.querySelector('[data-dip-auth-2fa]');
    if (!box) return;
    var input = box.querySelector('input');
    var first = box.hidden;
    box.hidden = false;
    if (input) {
      input.required = true;
      if (!first) input.value = '';
      try { input.focus(); } catch (e) {}
    }
  }

  function send(form, retried) {
    return fresh(false).then(function (n) {
      var data = new FormData();
      var remember = field(form, 'rememberme');
      var code = field(form, 'dip_2fa_code');
      data.set('action', cfg().loginAction);
      data.set('nonce', n.login);
      data.set('email', String(field(form, 'log').value || '').trim());
      data.set('password', field(form, 'pwd').value || '');
      if (remember && remember.checked) data.set('remember', '1');
      if (code && code.value) data.set('dip_2fa_code', String(code.value).trim());
      data.set('dl_hp', (field(form, 'dl_hp') || {}).value || '');
      data.set('redirect', (field(form, 'redirect_to') || {}).value || '');
      return post(data);
    }).then(function (r) {
      var d = (r && r.data) || {};
      if (r && !r.success && d.code === 'bad_nonce' && !retried) {
        nonces = null;
        return fresh(true).then(function () { return send(form, true); });
      }
      if (r && r.success) {
        show(form, d.message || t('success', 'Connexion réussie. Redirection…'), 'success');
        navigate(form, d.redirect);
        return;
      }
      busy(form, false);
      if (FACTOR.indexOf(d.code) !== -1) openFactor(form);
      show(form, d.message || t('failed', 'Impossible de continuer. Réessayez.'), 'error');
      var resend = form.querySelector('[data-dip-auth-resend]');
      if (resend) {
        resend.hidden = d.code !== 'dip_email_not_verified';
        if (!resend.hidden) resend.setAttribute('data-email', String(field(form, 'log').value || '').trim());
      }
    });
  }

  function usable() {
    var c = cfg();
    return !!(c.ajaxUrl && c.nonceAction && c.loginAction && window.fetch && window.FormData && window.Promise);
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('form[data-dip-auth-panel]') || e.defaultPrevented) return;
    var login = field(form, 'log');
    var identifier = login ? String(login.value || '').trim() : '';
    // Usernames, or no script support: the regular wp-login.php post.
    if (!usable() || identifier.indexOf('@') === -1) return;
    e.preventDefault();
    if (form.getAttribute('data-password-busy') === '1') return;
    if (form.getAttribute('data-passkey-busy') === '1') { show(form, t('passkeyBusy', 'Vérification en cours. Veuillez patienter.'), 'info'); return; }
    busy(form, true);
    show(form, t('busy', 'Connexion en cours…'), 'info');
    send(form, false).catch(function (err) {
      busy(form, false);
      show(form, (err && err.message) || t('network', 'Erreur réseau. Réessayez.'), 'error');
    });
  });

  document.addEventListener('click', function (e) {
    var button = e.target && e.target.closest ? e.target.closest('[data-dip-auth-resend]') : null;
    if (!button) return;
    var form = button.closest('form[data-dip-auth-panel]');
    var email = button.getAttribute('data-email') || '';
    if (!form || !email || !usable() || form.getAttribute('data-password-busy') === '1') return;
    e.preventDefault();
    busy(form, true);
    var deliver = function (retried) {
      return fresh(false).then(function (n) {
        var data = new FormData();
        data.set('action', cfg().resendAction);
        data.set('email', email);
        data.set('nonce', n.resend);
        return post(data);
      }).then(function (r) {
        if (r && !r.success && r.data && r.data.code === 'bad_nonce' && !retried) { nonces = null; return deliver(true); }
        busy(form, false);
        show(form, (r && r.data && r.data.message) || t('sent', 'Demande traitée.'), r && r.success ? 'success' : 'error');
        if (r && r.success) button.hidden = true;
      });
    };
    deliver(false).catch(function (err) { busy(form, false); show(form, (err && err.message) || t('network', 'Erreur réseau. Réessayez.'), 'error'); });
  });

  // Back/forward cache: never come back with a disabled button.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    Array.prototype.forEach.call(document.querySelectorAll('form[data-dip-auth-panel]'), function (form) { busy(form, false); show(form, ''); });
  });
})();
