(function () {
  'use strict';
  if (window.__DIP_IDENTITY_MODAL_V4__) return;
  window.__DIP_IDENTITY_MODAL_V4__ = true;

  var cfg = window.DIPIdentityModal || {};
  var modalId = String(cfg.modalId || 'dip-identity-modal').replace(/^#/, '');
  var bootAttempts = 0;

  function boot() {
    var root = document.getElementById(modalId);
    if (!root) {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
        return;
      }
      if (bootAttempts++ < 30) window.setTimeout(boot, 100);
      return;
    }

    var card = root.querySelector('.dipx-card');
    var login = root.querySelector('.dipx-form-login');
    var register = root.querySelector('.dipx-form-register');
    var twoFactor = root.querySelector('[data-dipx-2fa]');
    var subtitle = root.querySelector('.dipx-subtitle');
    var message = root.querySelector('.dipx-message');
    var resend = root.querySelector('[data-dipx-resend]');
    var tabs = root.querySelectorAll('[data-dipx-tab]');
    if (!card || !login || !message) return;

    var isOpen = false;
    var isBusy = false;
    var nonceReady = false;
    var noncePromise = null;
    var nonceEpoch = 0;
    var viewportFrame = 0;
    var previousFocus = null;
    var controllers = [];
    var closeTimer = null;
    var stylePromise = null;

    function parseJSON(raw) {
      try { return JSON.parse(raw); } catch (e) { return null; }
    }

    function prepareAuthTransition() {
      try {
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
          navigator.serviceWorker.controller.postMessage({ type: 'dbv9-clear-docs' });
        }
      } catch (e) {}
      try { localStorage.setItem('dip-session-change', String(Date.now())); } catch (e) {}
      try { document.dispatchEvent(new CustomEvent('delicat:auth-changed')); } catch (e) {}
    }

    function removeController(controller) {
      controllers = controllers.filter(function (item) { return item !== controller; });
    }

    function request(url, options, timeout) {
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timer = null;
      options = options || {};
      if (controller) {
        options.signal = controller.signal;
        controllers.push(controller);
        timer = window.setTimeout(function () {
          try { controller.abort(); } catch (e) {}
        }, timeout || 15000);
      }
      var timedOut = false;
      var deadline = new Promise(function (_, reject) {
        if (timer) window.clearTimeout(timer);
        timer = window.setTimeout(function () {
          timedOut = true;
          if (controller) controller.abort();
          var error = new Error('timeout');
          error.userMessage = 'Le serveur met trop de temps à répondre. Réessayez.';
          reject(error);
        }, timeout || 15000);
      });
      return Promise.race([fetch(url, options).then(function (response) { return response.text().then(function (body) { return {text:function () { return Promise.resolve(body); }}; }); }), deadline]).catch(function (error) {
        if (timedOut) { error = new Error('timeout'); error.userMessage = 'Le serveur met trop de temps à répondre. Réessayez.'; }
        throw error;
      }).finally(function () {
        if (timer) window.clearTimeout(timer);
        if (controller) removeController(controller);
      });
    }

    function abortAll() {
      controllers.slice().forEach(function (controller) {
        try { controller.abort(); } catch (e) {}
      });
      controllers = [];
    }

    function isAbort(error) {
      return !!(error && error.name === 'AbortError');
    }

    function suppressLegacy() {
      try {
        document.documentElement.classList.remove('dl-modal-open');
        if (document.body) document.body.classList.remove('dl-modal-open');
      } catch (e) {}
      var legacy = document.querySelectorAll('#delicat-login');
      Array.prototype.forEach.call(legacy, function (node) {
        if (!node || node === root) return;
        node.setAttribute('aria-hidden', 'true');
        node.setAttribute('data-dip-legacy-auth', 'suppressed');
        node.hidden = true;
        try {
          node.style.setProperty('display', 'none', 'important');
          node.style.setProperty('visibility', 'hidden', 'important');
          node.style.setProperty('pointer-events', 'none', 'important');
          if ('inert' in node) node.inert = true;
        } catch (e) {}
      });
    }

    function watchLegacy() {
      // Scoped CSS hides the legacy modal without observing every page mutation.
      suppressLegacy();
    }

    function styleReady() {
      return window.getComputedStyle(root).getPropertyValue('--dipx-style-ready').trim() === '1';
    }
    function ensureStyle() {
      if (styleReady()) return Promise.resolve();
      if (stylePromise) return stylePromise;
      stylePromise = new Promise(function (resolve, reject) {
        var link = document.createElement('link'), timer, finished = false;
        function done(error) {
          if (finished) return;
          finished = true; window.clearTimeout(timer);
          if (error) { link.remove(); reject(error); } else resolve();
        }
        if (!cfg.modalCssUrl) { done(new Error('style_unavailable')); return; }
        link.rel = 'stylesheet'; link.href = cfg.modalCssUrl;
        link.setAttribute('data-no-optimize', '1');
        link.onload = function () { done(styleReady() ? null : new Error('style_unavailable')); };
        link.onerror = function () { done(new Error('style_unavailable')); };
        timer = window.setTimeout(function () { done(new Error('style_timeout')); }, 8000);
        document.head.appendChild(link);
      }).finally(function () { stylePromise = null; });
      return stylePromise;
    }

    function setMessage(text, type) {
      if (!text) {
        message.hidden = true;
        message.textContent = '';
        message.className = 'dipx-message';
        if (resend) {
          resend.hidden = true;
          resend.removeAttribute('data-email');
        }
        return;
      }
      message.textContent = text;
      message.className = 'dipx-message is-' + (type === 'success' ? 'success' : 'error');
      message.hidden = false;
    }

    function showResend(email) {
      if (!resend) return;
      resend.setAttribute('data-email', email || '');
      resend.hidden = !email;
      resend.disabled = !nonceReady || isBusy;
    }

    function clearNonces() {
      ['.dipx-nonce-login', '.dipx-nonce-register', '.dipx-nonce-resend'].forEach(function (selector) {
        var field = root.querySelector(selector);
        if (field) field.value = '';
      });
      nonceReady = false;
    }

    function setSubmitState(enabled) {
      Array.prototype.forEach.call(root.querySelectorAll('.dipx-submit'), function (button) {
        if (!isBusy) button.disabled = !enabled;
      });
      if (resend && !isBusy) resend.disabled = !enabled;
    }

    function refreshNonces(showError) {
      if (noncePromise) return noncePromise;
      var epoch = nonceEpoch;
      clearNonces();
      setSubmitState(false);
      var data = new FormData();
      data.append('action', cfg.nonceAction || 'dip_native_fresh_nonces_v2');

      noncePromise = request(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }, 12000).then(function (response) {
        return response.text();
      }).then(function (raw) {
        var response = parseJSON(raw);
        if (!response) {
          var invalid = new Error('invalid_response');
          invalid.userMessage = 'Réponse serveur invalide. Actualisez la page puis réessayez.';
          throw invalid;
        }
        if (!response.success || !response.data) {
          var denied = new Error((response.data && response.data.code) || 'secure_session');
          denied.userMessage = (response.data && response.data.message) || 'Impossible d’initialiser la session sécurisée.';
          throw denied;
        }

        if (epoch !== nonceEpoch) return false;
        var loginNonce = root.querySelector('.dipx-nonce-login');
        var registerNonce = root.querySelector('.dipx-nonce-register');
        var resendNonce = root.querySelector('.dipx-nonce-resend');
        if (loginNonce) loginNonce.value = response.data.login || '';
        if (registerNonce) registerNonce.value = response.data.register || '';
        if (resendNonce) resendNonce.value = response.data.resend || '';

        nonceReady = !!(
          loginNonce && loginNonce.value &&
          resendNonce && resendNonce.value &&
          (!register || (registerNonce && registerNonce.value))
        );
        if (!nonceReady) {
          var incomplete = new Error('nonce_incomplete');
          incomplete.userMessage = 'La session sécurisée est incomplète. Actualisez la page puis réessayez.';
          throw incomplete;
        }
        setSubmitState(true);
        return true;
      }).catch(function (error) {
        if (epoch !== nonceEpoch) return false;
        nonceReady = false;
        setSubmitState(true);
        if (showError && !isAbort(error) && isOpen) {
          setMessage((error && error.userMessage) || 'Impossible d’initialiser la session sécurisée. Vérifiez votre connexion puis réessayez.', 'error');
        }
        return false;
      }).finally(function () { if (epoch === nonceEpoch) noncePromise = null; });
      return noncePromise;
    }

    function activeForm() {
      return !register || register.hidden ? login : register;
    }

    function visible(element) {
      return !!(element && !element.disabled && !element.closest('[hidden]') && element.getClientRects && element.getClientRects().length);
    }

    function focusFirst() {
      // Avoid opening the keyboard and shifting the whole page on touch devices.
      if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
        try { card.focus({preventScroll:true}); } catch (e) { card.focus(); }
        return;
      }
      var form = activeForm();
      var candidates = form.querySelectorAll('input:not([type="hidden"]):not([tabindex="-1"]),button:not([disabled]),a[href]');
      var target = null;
      Array.prototype.some.call(candidates, function (element) {
        if (visible(element)) { target = element; return true; }
        return false;
      });
      if (!target) target = card;
      window.setTimeout(function () {
        if (!isOpen) return;
        try { target.focus({ preventScroll: true }); } catch (e) { try { target.focus(); } catch (ignore) {} }
      }, 130);
    }

    function focusables() {
      var nodes = card.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])');
      return Array.prototype.filter.call(nodes, visible);
    }

    function restoreVerificationControls() {
      Array.prototype.forEach.call(root.querySelectorAll('[data-dipx-verification-disabled="1"]'), function (element) {
        element.disabled = false;
        element.removeAttribute('data-dipx-verification-disabled');
      });
    }

    function cleanHash() {
      if (window.location.hash !== '#delicat-login' && window.location.hash !== '#dip-identity-modal') return;
      if (!window.history || !window.history.replaceState) return;
      try { window.history.replaceState(null, '', window.location.pathname + window.location.search); } catch (e) {}
    }

    function cleanVerifiedParam() {
      if (!window.history || !window.history.replaceState) return;
      try {
        var url = new URL(window.location.href);
        if (url.searchParams.get('dip_verified') === '1') {
          url.searchParams.delete('dip_verified');
          window.history.replaceState(null, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash);
        }
      } catch (e) {}
    }

    function consumeAuthError() {
      var code = '';
      if (!window.history || !window.history.replaceState) return code;
      try {
        var url = new URL(window.location.href);
        code = url.searchParams.get('dip_auth_error') || '';
        if (code) {
          url.searchParams.delete('dip_auth_error');
          window.history.replaceState(null, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash);
        }
      } catch (e) {}
      return code;
    }

    function updateViewport() {
      if (!isOpen || viewportFrame) return;
      viewportFrame = window.requestAnimationFrame(function () {
        viewportFrame = 0;
        var view = window.visualViewport;
        root.style.setProperty('--dipx-view-height', (view ? view.height : window.innerHeight) + 'px');
        root.style.setProperty('--dipx-view-top', (view ? view.offsetTop : 0) + 'px');
      });
    }
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', updateViewport);
      window.visualViewport.addEventListener('scroll', updateViewport);
    }
    window.addEventListener('resize', updateViewport);

    function openModal(preferredTab, focusSource) {
      if (!styleReady()) {
        if (stylePromise) return;
        ensureStyle().then(function () { openModal(preferredTab, focusSource); }).catch(function () {
          root.hidden = true;
          window.alert('Impossible de charger la connexion. Vérifiez votre réseau puis réessayez.');
        });
        return;
      }
      try { document.dispatchEvent(new CustomEvent('dsb:close-menu')); } catch (e) {}
      if (isOpen) { if (!isBusy) switchTab(preferredTab, false); return; }
      if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
      suppressLegacy();
      restoreVerificationControls();
      previousFocus = (focusSource && typeof focusSource.focus === 'function') ? focusSource : document.activeElement;
      isOpen = true;
      updateViewport();

      // Last body child wins equal max-z-index races against floating chat/cart widgets.
      try {
        if (document.body && document.body.lastElementChild !== root) document.body.appendChild(root);
      } catch (e) {}

      root.hidden = false;
      root.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('dipx-modal-open');
      document.body.classList.add('dipx-modal-open');
      setMessage('', '');
      switchTab(preferredTab === 'register' ? 'register' : 'login', false);
      try { card.scrollTop = 0; } catch (e) {}
      refreshNonces(true);

      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          if (!isOpen) return;
          root.classList.add('is-open');
        });
      });
    }

    function closeModal() {
      if (!isOpen) return;
      if (isBusy || root.getAttribute('data-passkey-busy') === '1') {
        setMessage('Vérification en cours. Veuillez patienter.', 'success');
        return;
      }
      isOpen = false;
      nonceEpoch++;
      noncePromise = null;
      isBusy = false;
      abortAll();
      clearNonces();
      root.classList.remove('is-open');
      root.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('dipx-modal-open');
      document.body.classList.remove('dipx-modal-open');
      cleanHash();
      Array.prototype.forEach.call(root.querySelectorAll('input[name="password"],input[name="dip_2fa_code"]'), function (input) { input.value = ''; });
      var password = root.querySelector('#dipx-login-pass');
      if (password) password.type = 'password';
      if (twoFactor) { twoFactor.hidden = true; twoFactor.querySelector('input').required = false; }

      Array.prototype.forEach.call(root.querySelectorAll('.dipx-form'), function (form) {
        form.setAttribute('aria-busy', 'false');
        var button = form.querySelector('.dipx-submit');
        var label = form.querySelector('.dipx-submit-label');
        var spinner = form.querySelector('.dipx-spinner');
        if (button) button.disabled = true;
        if (label) label.hidden = false;
        if (spinner) spinner.hidden = true;
      });

      closeTimer = window.setTimeout(function () {
        root.hidden = true;
        restoreVerificationControls();
        setMessage('', '');
        if (previousFocus && previousFocus.focus) {
          try { previousFocus.focus({ preventScroll: true }); } catch (e) { try { previousFocus.focus(); } catch (ignore) {} }
        }
      }, 170);
    }

    function switchTab(name, focusTab) {
      if (isBusy || root.getAttribute("data-passkey-busy") === "1") return;
      var target = (name === 'register' && register) ? 'register' : 'login';
      var showLogin = target === 'login';
    Array.prototype.forEach.call(tabs, function (tab) {
        var active = tab.getAttribute('data-dipx-tab') === target;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.setAttribute('tabindex', active ? '0' : '-1');
        if (active && focusTab) tab.focus();
      });
      login.hidden = !showLogin;
      if (register) register.hidden = showLogin;
      // Keep a required 2FA step visible when returning to the login tab.
      if (subtitle) subtitle.textContent = showLogin ? 'Connectez-vous pour continuer' : 'Créez votre compte Delicat Store';
      setMessage('', '');
      if (!focusTab && isOpen) focusFirst();
    }

    function loading(form, on) {
      isBusy = on;
      root.setAttribute('data-password-busy', on ? '1' : '0');
      Array.prototype.forEach.call(tabs, function (tab) { tab.disabled = on; });
      var passkey = root.querySelector('[data-dip-passkey-login]');
      if (passkey && !passkey.hasAttribute('aria-disabled')) passkey.disabled = on;
      form.setAttribute('aria-busy', on ? 'true' : 'false');
      var button = form.querySelector('.dipx-submit');
      var label = form.querySelector('.dipx-submit-label');
      var spinner = form.querySelector('.dipx-spinner');
      if (button) button.disabled = on || !nonceReady;
      if (label) label.hidden = on;
      if (spinner) spinner.hidden = !on;
    }

    function submit(form, action, retried) {
      if (isBusy || root.getAttribute('data-passkey-busy') === '1') return;
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      var nonce = form.querySelector('input[name="nonce"]');
      if (!nonceReady || !nonce || !nonce.value) {
        loading(form, true);
        refreshNonces(true).then(function (ok) {
          loading(form, false);
          if (!ok) setSubmitState(true);
          if (ok && isOpen) submit(form, action, true);
        });
        return;
      }

      var data = new FormData(form);
      data.set('action', action);
      loading(form, true);
      setMessage('', '');

      request(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }, 16000).then(function (response) {
        return response.text();
      }).then(function (raw) {
        var response = parseJSON(raw);
        if (!response) {
          setMessage('Réponse serveur invalide. Actualisez la page puis réessayez.', 'error');
          loading(form, false);
          return;
        }
        if (response.success) {
          setMessage((response.data && response.data.message) || 'Succès.', 'success');
          if (response.data && response.data.verificationRequired) {
            loading(form, false);
            var email = form.querySelector('input[name="email"]');
            Array.prototype.forEach.call(form.elements, function (element) {
              if (element.type !== 'hidden' && !element.disabled) {
                element.disabled = true;
                element.setAttribute('data-dipx-verification-disabled', '1');
              }
            });
            showResend(email ? email.value : '');
            return;
          }
          prepareAuthTransition();
          window.setTimeout(function () {
            window.location.assign((response.data && response.data.redirect) || cfg.redirectUrl || '/');
          }, 100);
          return;
        }

        if (response.data && response.data.code === 'bad_nonce' && !retried) {
          refreshNonces(true).then(function (ok) {
            loading(form, false);
            if (!ok) setSubmitState(true);
            if (ok && isOpen) submit(form, action, true);
          });
          return;
        }

        if (response.data && ['dip_2fa_required','dip_2fa_invalid','dip_2fa_rate_limited'].indexOf(response.data.code) !== -1) {
          if (twoFactor) {
            twoFactor.hidden = false;
            var factorInput = twoFactor.querySelector('input[name="dip_2fa_code"]');
            if (factorInput) {
              factorInput.required = true;
              window.setTimeout(function(){ try { factorInput.focus({preventScroll:true}); } catch(e) { try { factorInput.focus(); } catch(ignore) {} } }, 80);
            }
          }
        }
        setMessage((response.data && response.data.message) || 'Une erreur est survenue.', 'error');
        if (response.data && response.data.code === 'dip_email_not_verified') {
          var loginEmail = form.querySelector('input[name="email"]');
          showResend(loginEmail ? loginEmail.value : '');
        }
        loading(form, false);
      }).catch(function (error) {
        if (!isAbort(error) && isOpen) setMessage((error && error.userMessage) || 'Erreur réseau. Réessayez.', 'error');
        loading(form, false);
      });
    }

    // RC 6.9.8 — publish the modal API as soon as this script initializes.
    // The 6.9.6 build created it inside switchTab(), but the lazy loader asks
    // for the API immediately after script.onload, before any tab switch/open
    // has happened. That race caused the new popup to fall back instead of open.
    window.DIPIdentityModalAPI = {
      open: function (tab, trigger) { openModal(tab === 'register' ? 'register' : 'login', trigger || null); },
      close: function () { closeModal(); },
      isOpen: function () { return !!isOpen; }
    };
    document.addEventListener('dip:identity-open', function (event) {
      var detail = (event && event.detail) || {};
      openModal(detail.tab === 'register' ? 'register' : 'login', detail.trigger || null);
    });
    try {
      document.dispatchEvent(new CustomEvent('dip:identity-ready', { detail: { modalId: modalId } }));
    } catch (e) {}

    // Capture phase intentionally runs before the legacy Code Snippets document listener.
    document.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target.closest('[data-dip-auth-open],[data-dl-open],.dsb-account-login,a[href="#delicat-login"],a[href="#dip-identity-modal"],[aria-controls="dip-identity-modal"]') : null;
      if (target) {
        event.preventDefault();
        event.stopPropagation();
        if (event.stopImmediatePropagation) event.stopImmediatePropagation();
        openModal(target.hasAttribute('data-dip-auth-register') || target.hasAttribute('data-dl-open-register') ? 'register' : 'login', target);
        return;
      }

      if ((isBusy || root.getAttribute('data-passkey-busy') === '1') && event.target.closest && event.target.closest('#dip-identity-modal .dipx-social a')) { event.preventDefault(); event.stopImmediatePropagation(); return; }

      var closer = event.target && event.target.closest ? event.target.closest('[data-dip-auth-close]') : null;
      if (closer && root.contains(closer)) {
        event.preventDefault();
        closeModal();
      }
    }, true);

    Array.prototype.forEach.call(tabs, function (tab) {
      tab.addEventListener('click', function () {
        switchTab(tab.getAttribute('data-dipx-tab'), false);
      });
    });

    Array.prototype.forEach.call(root.querySelectorAll('[data-dipx-eye]'), function (button) {
      button.addEventListener('click', function () {
        var input = button.parentNode.querySelector('input');
        if (!input) return;
        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.classList.toggle('is-visible', reveal);
        button.setAttribute('aria-label', reveal ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
      });
    });

    login.addEventListener('submit', function (event) {
      event.preventDefault();
      submit(login, cfg.loginAction || 'dip_native_do_login_v2', false);
    });

    if (register) {
      register.addEventListener('submit', function (event) {
        event.preventDefault();
        submit(register, cfg.registerAction || 'dip_native_do_register_v2', false);
      });
    }

    if (resend) {
      resend.addEventListener('click', function () {
        if (isBusy || resend.disabled) return;
        var email = resend.getAttribute('data-email') || '';
        var nonce = root.querySelector('.dipx-nonce-resend');
        if (!email) return;
        if (!nonceReady || !nonce || !nonce.value) {
          refreshNonces(true).then(function (ok) { if (ok && isOpen) resend.click(); });
          return;
        }
        var data = new FormData();
        data.append('action', cfg.resendAction || 'dip_native_resend_verification_v2');
        data.append('email', email);
        data.append('nonce', nonce.value);
        resend.disabled = true;
        request(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
          method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }, 14000).then(function (response) { return response.text(); }).then(function (raw) {
          var response = parseJSON(raw);
          if (response && response.success) {
            setMessage((response.data && response.data.message) || 'Demande envoyée.', 'success');
            resend.hidden = true;
            return;
          }
          if (response && response.data && response.data.code === 'bad_nonce') {
            nonceReady = false;
            refreshNonces(true).then(function () { if (isOpen) setMessage('Session renouvelée. Cliquez pour renvoyer l’e-mail.', 'error'); });
            return;
          }
          setMessage((response && response.data && response.data.message) || 'Impossible de renvoyer l’e-mail.', 'error');
        }).catch(function (error) {
          if (!isAbort(error) && isOpen) setMessage((error && error.userMessage) || 'Erreur réseau. Réessayez.', 'error');
        }).finally(function () {
          if (!isBusy) resend.disabled = !nonceReady;
        });
      });
    }

    document.addEventListener('keydown', function (event) {
      if (!isOpen) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeModal();
        return;
      }
      if (tabs.length > 1 && (event.key === 'ArrowLeft' || event.key === 'ArrowRight') && document.activeElement && document.activeElement.hasAttribute('data-dipx-tab')) {
        event.preventDefault();
        switchTab(document.activeElement.getAttribute('data-dipx-tab') === 'login' ? 'register' : 'login', true);
        return;
      }
      if (event.key === 'Tab') {
        var items = focusables();
        if (!items.length) { event.preventDefault(); card.focus(); return; }
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });

    watchLegacy();
    window.setTimeout(suppressLegacy, 120);
    window.setTimeout(suppressLegacy, 600);
    clearNonces();
    setSubmitState(false);

    var verified = false;
    try { verified = (new URLSearchParams(window.location.search)).get('dip_verified') === '1'; } catch (e) {}
    var authError = consumeAuthError();
    var authMessages = {
      invalid_credentials: 'Identifiants incorrects. Vérifiez votre e-mail et votre mot de passe.',
      access_denied: 'Connexion Google annulée ou refusée.',
      rate_limited: 'Trop de tentatives. Réessayez plus tard.',
      temporarily_locked: 'Connexion temporairement bloquée. Réessayez plus tard.',
      dip_email_not_verified: 'Vérifiez votre adresse e-mail avant de vous connecter.',
      account_pending_approval: 'Votre compte attend l’approbation de l’administrateur.',
      privileged_auto_link_blocked: 'Ce compte sensible existe déjà. Delicat refuse une simple liaison par e-mail et doit reprendre son lien Google historique ou une liaison sécurisée.',
      duplicate_nextend_google_identity: 'Cette identité Google apparaît sur plusieurs comptes historiques. Une vérification administrateur est requise.',
      legacy_google_email_mismatch: 'L’adresse Google ne correspond pas exactement au compte Delicat historiquement lié.',
      legacy_google_owner_conflict: 'Le lien Google historique et l’adresse e-mail pointent vers deux comptes différents.',
      legacy_privileged_continuity_failed: 'Le lien Google historique n’a pas pu être validé de manière sécurisée.',
      email_exists: 'Un compte utilise déjà cette adresse. Connectez-vous avec votre mot de passe puis reliez Google depuis votre compte.',
      not_configured: 'Google Login n’est pas disponible actuellement.',
      provider_unavailable: 'Le fournisseur Google est momentanément indisponible. Réessayez.',
      invalid_authorization_url: 'La configuration de connexion Google est invalide. Vérifiez les réglages OAuth.',
      missing_callback_data: 'Google a renvoyé une réponse incomplète. Recommencez la connexion.',
      invalid_state: 'La session Google a expiré ou n’est plus valide. Recommencez depuis Delicat Store.',
      browser_binding_failed: 'La session Google n’est plus liée à ce navigateur. Fermez puis relancez la connexion.',
      token_transport_error: 'Impossible de contacter Google pour terminer la connexion. Réessayez.',
      token_error: 'Google n’a pas validé cette tentative de connexion. Recommencez.',
      token_response_too_large: 'Google a renvoyé une réponse inattendue. Recommencez la connexion.',
      google_keys_unavailable: 'Impossible de vérifier les clés de sécurité Google actuellement. Réessayez dans un instant.',
      google_keys_too_large: 'La réponse de sécurité Google est invalide. Réessayez.',
      google_keys_invalid: 'Les clés de sécurité Google n’ont pas pu être validées. Réessayez.',
      google_key_missing: 'Google a renouvelé sa clé de sécurité. Réessayez la connexion.',
      google_key_type: 'La clé de sécurité Google reçue n’est pas prise en charge.',
      google_key_use: 'La clé de sécurité Google reçue est invalide.',
      google_key_alg: 'L’algorithme de sécurité Google reçu est invalide.',
      google_key_invalid: 'La clé de sécurité Google reçue est invalide.',
      google_signature: 'La signature de sécurité Google n’a pas pu être vérifiée.',
      google_jwt_format: 'Le jeton de connexion Google est invalide. Recommencez.',
      google_jwt_header: 'La signature du jeton Google est invalide.',
      google_openssl_missing: 'Le serveur ne peut pas vérifier cryptographiquement Google. Contactez l’administrateur.',
      google_audience_missing: 'L’identifiant OAuth Google n’est pas configuré correctement.',
      invalid_audience: 'L’identifiant OAuth Google ne correspond pas à ce site. Vérifiez le Client ID.',
      invalid_issuer: 'L’émetteur du jeton Google est invalide.',
      invalid_token_time: 'Le jeton Google a expiré ou sa date est invalide. Recommencez.',
      invalid_nonce: 'La vérification anti-rejeu Google a échoué. Recommencez la connexion.',
      invalid_subject: 'L’identité Google reçue est invalide.',
      email_not_verified: 'Google n’a pas confirmé cette adresse e-mail.',
      userinfo_subject_mismatch: 'Le profil Google ne correspond pas au jeton de connexion. Recommencez.',
      two_factor_expired: 'La vérification en deux étapes a expiré. Recommencez la connexion.',
      two_factor_https_required: 'HTTPS est requis pour terminer la vérification en deux étapes.',
      two_factor_challenge_failed: 'Impossible de démarrer la vérification en deux étapes. Réessayez.',
      dip_2fa_rate_limited: 'Trop de tentatives 2FA. Réessayez dans quelques minutes.',
      admin_google_secure_mode_disabled: 'Google Secure Mode Administrateur est désactivé.',
      admin_google_https_required: 'HTTPS est requis pour Google Secure Mode Administrateur.',
      admin_google_identity_mismatch: 'Cette identité Google ne correspond pas à l’identité Administrateur approuvée.',
      admin_google_not_authorized: 'Cette identité Google doit être approuvée depuis votre Centre de sécurité Administrateur.',
      admin_google_two_factor_required: 'Activez TOTP 2FA avant d’utiliser Google avec votre compte Administrateur.',
      admin_google_link_authorization_failed: 'La liaison Google Administrateur n’a pas pu être autorisée de manière sécurisée.',
      privileged_link_requires_secure_mode: 'Confirmez votre identité et activez TOTP 2FA avant de relier Google à cet Administrateur.',
      provider_already_linked_elsewhere: 'Cette identité Google est déjà liée à un autre compte.'
    };

    if (verified) {
      cleanVerifiedParam();
      openModal('login');
      setMessage('✅ Adresse e-mail vérifiée. Vous pouvez maintenant vous connecter.', 'success');
    } else if (authError) {
      openModal('login');
      setMessage(authMessages[authError] || 'La connexion Google n’a pas pu être finalisée. Réessayez.', 'error');
    } else if (window.location.hash === '#delicat-login' || window.location.hash === '#dip-identity-modal') {
      openModal('login');
    }
  }

  boot();
})();
