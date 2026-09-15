(function () {
  'use strict';
  if (window.__DIP_IDENTITY_MODAL_V5__) return;
  window.__DIP_IDENTITY_MODAL_V5__ = true;

  /*
   * Delicat Identity modal, v5.
   *
   * v4 worked, and on a fast phone you could not tell anything was wrong. On a
   * cheap one it felt broken, for four reasons that are all fixed here.
   *
   * 1. THE DEAD BUTTON. v4 disabled every submit button until a round trip to
   *    admin-ajax.php came back with security nonces, and it started that round
   *    trip only once the dialog was already open. admin-ajax.php is uncached and
   *    boots all of WordPress, so on a slow connection the customer looked at a
   *    finished, beautiful form whose button did nothing for a second or more -
   *    and if the request failed, the button never came back at all.
   *
   *    v5 starts fetching the moment a finger touches the trigger - pointerdown
   *    fires long before the dialog is painted - and never disables the button.
   *    Pressing it starts the sign-in; if the nonce has not landed yet the same
   *    spinner covers both. The customer waits for one thing, once, and the
   *    waiting is always the thing they asked for.
   *
   * 2. THE NONCES LIVED IN THE DOM. v4 wrote them into hidden inputs, where any
   *    script on the page could read them. v5 keeps them in this closure and
   *    attaches them at submit time. They are also kept between opens instead of
   *    being thrown away on close, which removes a round trip per reopen.
   *
   * 3. THE FRAME BUDGET. v4 blurred the whole viewport behind the card
   *    (backdrop-filter), re-parented the dialog into <body> on every open,
   *    animated through two nested requestAnimationFrames, and - whenever a
   *    legacy snippet was present - ran a MutationObserver over the entire
   *    document subtree that re-queried the DOM on every mutation the page made.
   *    All four are gone. v5 animates opacity and transform only.
   *
   * 4. THE KEYBOARD JUMP. v4 focused the email field 130ms after opening, so on
   *    a phone the keyboard shoved the dialog upward just as it finished
   *    animating in. v5 focuses the card on touch devices and the first field
   *    only where there is a real pointer.
   *
   * Plus one thing v4 did not have: the card follows your thumb downward and
   * closes, the way a native sheet does.
   */

  var cfg = window.DIPIdentityModal || {};
  var modalId = String(cfg.modalId || 'dip-identity-modal').replace(/^#/, '');
  var AJAX = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
  var TRIGGERS = '[data-dip-auth-open],[data-dl-open],a[href="#delicat-login"],a[href="#dip-identity-modal"],[aria-controls="dip-identity-modal"]';
  var OPEN_MS = 220;

  /* ---------------------------------------------------------------- */
  /* The security session. One fetch, kept, shared by every form.      */
  /* ---------------------------------------------------------------- */

  var nonces = null;
  var noncePromise = null;
  var controllers = [];

  function removeController(c) {
    for (var i = controllers.length - 1; i >= 0; i--) if (controllers[i] === c) controllers.splice(i, 1);
  }

  function request(url, options, timeout) {
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = null;
    options = options || {};
    options.credentials = 'same-origin';
    options.cache = 'no-store';
    options.headers = options.headers || { 'X-Requested-With': 'XMLHttpRequest' };
    if (controller) {
      options.signal = controller.signal;
      controllers.push(controller);
      timer = window.setTimeout(function () { try { controller.abort(); } catch (e) {} }, timeout || 15000);
    }
    return fetch(url, options).then(function (response) {
      if (timer) window.clearTimeout(timer);
      if (controller) removeController(controller);
      return response;
    }, function (error) {
      if (timer) window.clearTimeout(timer);
      if (controller) removeController(controller);
      throw error;
    });
  }

  function isAbort(error) { return !!(error && error.name === 'AbortError'); }

  function parseJSON(raw) { try { return JSON.parse(raw); } catch (e) { return null; } }

  /**
   * Resolve with the nonce set, fetching it at most once at a time.
   *
   * `force` is used after the server has rejected one as stale: the cached set
   * is dropped and a fresh one is fetched, so a page restored from history can
   * repair itself without the customer seeing anything but a spinner.
   */
  function ensureNonces(force) {
    if (force) { nonces = null; noncePromise = null; }
    if (nonces) return Promise.resolve(nonces);
    if (noncePromise) return noncePromise;

    var data = new FormData();
    data.append('action', cfg.nonceAction || 'dip_native_fresh_nonces_v2');

    noncePromise = request(AJAX, { method: 'POST', body: data }, 12000)
      .then(function (r) { return r.text(); })
      .then(function (raw) {
        var response = parseJSON(raw);
        if (!response) throw named('invalid_response', 'Réponse serveur invalide. Actualisez la page puis réessayez.');
        if (!response.success || !response.data) {
          throw named((response.data && response.data.code) || 'secure_session',
            (response.data && response.data.message) || 'Impossible d’initialiser la session sécurisée.');
        }
        if (!response.data.login) throw named('nonce_incomplete', 'La session sécurisée est incomplète. Actualisez la page puis réessayez.');
        nonces = response.data;
        noncePromise = null;
        return nonces;
      })
      .catch(function (error) {
        noncePromise = null;
        nonces = null;
        throw error;
      });

    return noncePromise;
  }

  function named(code, message) {
    var e = new Error(code);
    e.userMessage = message;
    return e;
  }

  /* Warm the session the instant a finger lands on a trigger, so the nonce is
     already here by the time anyone reaches the submit button. */
  function warm(event) {
    var node = event.target && event.target.closest ? event.target.closest(TRIGGERS) : null;
    if (!node) return;
    ensureNonces(false).catch(function () {});
  }
  document.addEventListener('pointerdown', warm, { capture: true, passive: true });
  document.addEventListener('touchstart', warm, { capture: true, passive: true });
  document.addEventListener('focusin', warm, true);

  /* ---------------------------------------------------------------- */

  function boot() {
    var root = document.getElementById(modalId);
    if (!root) {
      if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot, { once: true }); return; }
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
    var busy = false;
    var previousFocus = null;
    var closeTimer = null;
    var coarse = false;
    try { coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches); } catch (e) {}

    function abortAll() {
      controllers.slice().forEach(function (c) { try { c.abort(); } catch (e) {} });
      controllers = [];
    }

    function setMessage(text, type) {
      if (!text) {
        message.hidden = true;
        message.textContent = '';
        message.className = 'dipx-message';
        if (resend) { resend.hidden = true; resend.removeAttribute('data-email'); }
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
      resend.disabled = busy;
    }

    function prepareAuthTransition() {
      try {
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
          navigator.serviceWorker.controller.postMessage({ type: 'dbv9-clear-docs' });
        }
      } catch (e) {}
      try { document.dispatchEvent(new CustomEvent('delicat:auth-changed')); } catch (e) {}
    }

    /* ---- legacy Code Snippets modal ---- */

    function suppressLegacy() {
      try {
        document.documentElement.classList.remove('dl-modal-open');
        if (document.body) document.body.classList.remove('dl-modal-open');
      } catch (e) {}
      var legacy = document.querySelectorAll('#delicat-login');
      for (var i = 0; i < legacy.length; i++) {
        var node = legacy[i];
        if (!node || node === root || node.getAttribute('data-dip-legacy-auth') === 'suppressed') continue;
        node.setAttribute('aria-hidden', 'true');
        node.setAttribute('data-dip-legacy-auth', 'suppressed');
        node.hidden = true;
        try { if ('inert' in node) node.inert = true; } catch (e) {}
      }
    }

    /*
     * v4 observed documentElement with subtree:true and re-queried the DOM on
     * every mutation anywhere on the page - a permanent tax on a phone that is
     * already struggling. A legacy modal is appended to <body>, so watching
     * body's own child list is both sufficient and nearly free. Most of the work
     * is done by CSS in the stylesheet; this is the fallback for a node that
     * arrives later.
     */
    function watchLegacy() {
      suppressLegacy();
      if (!Number(cfg.legacyDetected || 0) || typeof MutationObserver === 'undefined' || !document.body) return;
      try { new MutationObserver(suppressLegacy).observe(document.body, { childList: true }); } catch (e) {}
    }

    /* ---- open / close ---- */

    function firstField() {
      var form = (!register || register.hidden) ? login : register;
      var candidates = form.querySelectorAll('input:not([type="hidden"]):not(.dipx-hp)');
      for (var i = 0; i < candidates.length; i++) {
        var el = candidates[i];
        if (!el.disabled && el.getClientRects && el.getClientRects().length) return el;
      }
      return null;
    }

    function focusInitial() {
      /*
       * On a phone, focusing a text field raises the keyboard, which resizes the
       * viewport mid-animation and throws the dialog upward. Focus the card
       * instead: Escape, the tab trap and screen readers all still work, and the
       * customer taps the field they actually want.
       */
      var target = coarse ? card : (firstField() || card);
      try { target.focus({ preventScroll: true }); } catch (e) { try { target.focus(); } catch (ignore) {} }
    }

    function openModal(preferredTab, trigger) {
      if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
      if (isOpen) { switchTab(preferredTab === 'register' ? 'register' : 'login', false); return; }

      suppressLegacy();
      restoreVerificationControls();
      previousFocus = (trigger && typeof trigger.focus === 'function') ? trigger : document.activeElement;
      isOpen = true;

      root.hidden = false;
      root.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('dipx-modal-open');
      document.body.classList.add('dipx-modal-open');
      setMessage('', '');
      switchTab(preferredTab === 'register' ? 'register' : 'login', false);
      try { card.scrollTop = 0; } catch (e) {}
      setCardOffset(0);

      /* Fetch only if the warm-up has not already done it. Never blocks anything. */
      ensureNonces(false).catch(function () {});

      window.requestAnimationFrame(function () {
        root.classList.add('is-open');
        focusInitial();
      });
    }

    function closeModal() {
      if (!isOpen) return;
      isOpen = false;
      busy = false;
      abortAll();
      root.classList.remove('is-open');
      root.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('dipx-modal-open');
      document.body.classList.remove('dipx-modal-open');
      setCardOffset(0);
      cleanHash();

      var forms = root.querySelectorAll('.dipx-form');
      for (var i = 0; i < forms.length; i++) loading(forms[i], false);

      closeTimer = window.setTimeout(function () {
        root.hidden = true;
        restoreVerificationControls();
        setMessage('', '');
        if (previousFocus && previousFocus.focus) {
          try { previousFocus.focus({ preventScroll: true }); } catch (e) { try { previousFocus.focus(); } catch (ignore) {} }
        }
      }, OPEN_MS);
    }

    function restoreVerificationControls() {
      var frozen = root.querySelectorAll('[data-dipx-verification-disabled="1"]');
      for (var i = 0; i < frozen.length; i++) {
        frozen[i].disabled = false;
        frozen[i].removeAttribute('data-dipx-verification-disabled');
      }
    }

    function cleanHash() {
      if (window.location.hash !== '#delicat-login' && window.location.hash !== '#dip-identity-modal') return;
      if (!window.history || !window.history.replaceState) return;
      try { window.history.replaceState(null, '', window.location.pathname + window.location.search); } catch (e) {}
    }

    function switchTab(name, focusTab) {
      var target = (name === 'register' && register) ? 'register' : 'login';
      var showLogin = target === 'login';
      for (var i = 0; i < tabs.length; i++) {
        var tab = tabs[i];
        var active = tab.getAttribute('data-dipx-tab') === target;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.setAttribute('tabindex', active ? '0' : '-1');
        if (active && focusTab) tab.focus();
      }
      login.hidden = !showLogin;
      if (register) register.hidden = showLogin;
      if (!showLogin && twoFactor) twoFactor.hidden = true;
      if (subtitle) subtitle.textContent = showLogin ? 'Connectez-vous pour continuer' : 'Créez votre compte Delicat Store';
      setMessage('', '');
    }

    /* ---- submitting ---- */

    function loading(form, on) {
      busy = on;
      form.setAttribute('aria-busy', on ? 'true' : 'false');
      var button = form.querySelector('.dipx-submit');
      var label = form.querySelector('.dipx-submit-label');
      var spinner = form.querySelector('.dipx-spinner');
      /* Disabled only while this very request is in flight - never because some
         other request has not finished yet. */
      if (button) button.disabled = on;
      if (label) label.hidden = on;
      if (spinner) spinner.hidden = !on;
      if (resend) resend.disabled = on;
    }

    function nonceFor(action) {
      if (!nonces) return '';
      if (action === (cfg.registerAction || 'dip_native_do_register_v2')) return nonces.register || '';
      return nonces.login || '';
    }

    function submit(form, action, retried) {
      if (busy) return;
      if (!form.checkValidity()) { form.reportValidity(); return; }

      loading(form, true);
      setMessage('', '');

      ensureNonces(false).then(function () {
        var data = new FormData(form);
        data.append('action', action);
        data.append('nonce', nonceFor(action));
        return request(AJAX, { method: 'POST', body: data }, 16000);
      }).then(function (r) {
        return r.text();
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
            for (var i = 0; i < form.elements.length; i++) {
              var element = form.elements[i];
              if (element.type !== 'hidden' && !element.disabled) {
                element.disabled = true;
                element.setAttribute('data-dipx-verification-disabled', '1');
              }
            }
            showResend(email ? email.value : '');
            return;
          }
          /* No artificial pause. The success line is painted, then we leave. */
          prepareAuthTransition();
          window.location.assign((response.data && response.data.redirect) || cfg.redirectUrl || '/');
          return;
        }

        var code = (response.data && response.data.code) || '';

        /* A page restored from history carries a nonce the server has since
           retired. Fetch a new one and resend, once, silently. */
        if (code === 'bad_nonce' && !retried) {
          loading(form, false);
          ensureNonces(true).then(function () {
            if (isOpen) submit(form, action, true);
          }).catch(function (error) {
            if (isOpen) setMessage((error && error.userMessage) || 'Session expirée. Actualisez la page puis réessayez.', 'error');
          });
          return;
        }

        if (code === 'dip_2fa_required' || code === 'dip_2fa_invalid' || code === 'dip_2fa_rate_limited') {
          if (twoFactor) {
            twoFactor.hidden = false;
            var factorInput = twoFactor.querySelector('input[name="dip_2fa_code"]');
            if (factorInput) {
              factorInput.required = true;
              try { factorInput.focus({ preventScroll: true }); } catch (e) {}
            }
          }
        }

        setMessage((response.data && response.data.message) || 'Une erreur est survenue.', 'error');
        if (code === 'dip_email_not_verified') {
          var loginEmail = form.querySelector('input[name="email"]');
          showResend(loginEmail ? loginEmail.value : '');
        }
        loading(form, false);
      }).catch(function (error) {
        loading(form, false);
        if (isAbort(error) || !isOpen) return;
        setMessage((error && error.userMessage) || 'Erreur réseau. Réessayez.', 'error');
      });
    }

    /* ---- drag the card away, the way a native sheet closes ---- */

    var dragFrom = null;
    var dragOffset = 0;

    /*
     * The offset travels as a custom property, not as an inline transform: the
     * short-viewport rule in the stylesheet sets transform with !important, and
     * an inline style cannot outrank that - a custom property feeds into it
     * instead. is-dragging takes the transition out of the way so the card sits
     * exactly under the thumb rather than easing towards it.
     */
    function setCardOffset(px) {
      dragOffset = px;
      if (px) {
        root.classList.add('is-dragging');
        root.style.setProperty('--dipx-drag', px + 'px');
      } else {
        root.classList.remove('is-dragging');
        root.style.removeProperty('--dipx-drag');
      }
    }

    /*
     * The HEADER is the handle, not the whole card.
     *
     * The card scrolls - on a short screen the form is taller than it is - so a
     * drag anywhere inside it has to stay available for scrolling. Claiming the
     * whole surface would mean either fighting the browser's own pan gesture or
     * setting touch-action:none on a scrollable box, and getting that wrong
     * costs a customer the bottom half of the form. The header never needs to
     * scroll, so it can own the gesture outright, which is also what a native
     * sheet does.
     */
    var handle = root.querySelector('.dipx-header') || card;

    handle.addEventListener('pointerdown', function (event) {
      if (!isOpen || !coarse || busy) return;
      if (event.pointerType === 'mouse' || card.scrollTop > 0) return;
      if (event.target && event.target.closest && event.target.closest('input,button,a,select,textarea,label')) return;
      dragFrom = event.clientY;
    }, { passive: true });

    handle.addEventListener('pointermove', function (event) {
      if (dragFrom === null) return;
      var delta = event.clientY - dragFrom;
      if (delta <= 0) { setCardOffset(0); return; }
      /* Resist a little, so a small drag reads as "not yet". */
      setCardOffset(delta < 12 ? 0 : (delta - 12) * 0.92);
    }, { passive: true });

    function endDrag() {
      if (dragFrom === null) return;
      var travelled = dragOffset;
      dragFrom = null;
      if (travelled > 110) { setCardOffset(0); closeModal(); return; }
      /* Let go of the transition first so the card eases back into place. */
      root.classList.remove('is-dragging');
      root.style.removeProperty('--dipx-drag');
      dragOffset = 0;
    }
    handle.addEventListener('pointerup', endDrag, { passive: true });
    handle.addEventListener('pointercancel', endDrag, { passive: true });
    handle.addEventListener('lostpointercapture', endDrag, { passive: true });

    /* ---- wiring ---- */

    window.DIPIdentityModalAPI = {
      open: function (tab, trigger) { openModal(tab === 'register' ? 'register' : 'login', trigger || null); },
      close: function () { closeModal(); },
      isOpen: function () { return !!isOpen; }
    };

    document.addEventListener('dip:identity-open', function (event) {
      var detail = (event && event.detail) || {};
      openModal(detail.tab === 'register' ? 'register' : 'login', detail.trigger || null);
    });
    try { document.dispatchEvent(new CustomEvent('dip:identity-ready', { detail: { modalId: modalId } })); } catch (e) {}

    /* Capture phase, so the legacy snippet's own document listener never runs. */
    document.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target.closest(TRIGGERS) : null;
      if (target) {
        event.preventDefault();
        event.stopPropagation();
        if (event.stopImmediatePropagation) event.stopImmediatePropagation();
        openModal(target.hasAttribute('data-dip-auth-register') || target.hasAttribute('data-dl-open-register') ? 'register' : 'login', target);
        return;
      }
      var closer = event.target && event.target.closest ? event.target.closest('[data-dip-auth-close]') : null;
      if (closer && root.contains(closer)) { event.preventDefault(); closeModal(); }
    }, true);

    for (var t = 0; t < tabs.length; t++) {
      (function (tab) {
        tab.addEventListener('click', function () { switchTab(tab.getAttribute('data-dipx-tab'), false); });
      })(tabs[t]);
    }

    var eyes = root.querySelectorAll('[data-dipx-eye]');
    for (var e = 0; e < eyes.length; e++) {
      (function (button) {
        button.addEventListener('click', function () {
          var input = button.parentNode.querySelector('input');
          if (!input) return;
          var reveal = input.type === 'password';
          input.type = reveal ? 'text' : 'password';
          button.classList.toggle('is-visible', reveal);
          button.setAttribute('aria-label', reveal ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        });
      })(eyes[e]);
    }

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
        var email = resend.getAttribute('data-email') || '';
        if (!email || busy) return;
        resend.disabled = true;
        ensureNonces(false).then(function (n) {
          var data = new FormData();
          data.append('action', cfg.resendAction || 'dip_native_resend_verification_v2');
          data.append('email', email);
          data.append('nonce', n.resend || '');
          return request(AJAX, { method: 'POST', body: data }, 14000);
        }).then(function (r) { return r.text(); }).then(function (raw) {
          var response = parseJSON(raw);
          if (response && response.success) {
            setMessage((response.data && response.data.message) || 'Demande envoyée.', 'success');
            resend.hidden = true;
            return;
          }
          if (response && response.data && response.data.code === 'bad_nonce') {
            ensureNonces(true).then(function () { if (isOpen) resend.click(); }).catch(function () {});
            return;
          }
          setMessage((response && response.data && response.data.message) || 'Impossible de renvoyer l’e-mail.', 'error');
        }).catch(function (error) {
          if (!isAbort(error) && isOpen) setMessage('Erreur réseau. Réessayez.', 'error');
        }).then(function () {
          if (!busy) resend.disabled = false;
        });
      });
    }

    document.addEventListener('keydown', function (event) {
      if (!isOpen) return;
      if (event.key === 'Escape') { event.preventDefault(); closeModal(); return; }
      if (tabs.length > 1 && (event.key === 'ArrowLeft' || event.key === 'ArrowRight') && document.activeElement && document.activeElement.hasAttribute('data-dipx-tab')) {
        event.preventDefault();
        switchTab(document.activeElement.getAttribute('data-dipx-tab') === 'login' ? 'register' : 'login', true);
        return;
      }
      if (event.key !== 'Tab') return;
      var nodes = card.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])');
      var items = [];
      for (var i = 0; i < nodes.length; i++) {
        var el = nodes[i];
        if (!el.closest('[hidden]') && el.getClientRects && el.getClientRects().length) items.push(el);
      }
      if (!items.length) { event.preventDefault(); card.focus(); return; }
      var first = items[0];
      var last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    watchLegacy();

    /* ---- what this page arrived asking for ---- */

    var params = null;
    try { params = new URLSearchParams(window.location.search); } catch (e) {}

    function consume(key) {
      if (!params) return '';
      var value = params.get(key) || '';
      if (!value || !window.history || !window.history.replaceState) return value;
      try {
        var url = new URL(window.location.href);
        url.searchParams.delete(key);
        var query = url.searchParams.toString();
        window.history.replaceState(null, '', url.pathname + (query ? '?' + query : '') + url.hash);
      } catch (ignore) {}
      return value;
    }

    var verified = consume('dip_verified') === '1';
    var authError = consume('dip_auth_error');
    var messages = window.DIPIdentityMessages || {};

    if (verified) {
      openModal('login');
      setMessage('✅ Adresse e-mail vérifiée. Vous pouvez maintenant vous connecter.', 'success');
    } else if (authError) {
      openModal('login');
      setMessage(messages[authError] || 'La connexion n’a pas pu être finalisée. Réessayez.', 'error');
    } else if (window.location.hash === '#delicat-login' || window.location.hash === '#dip-identity-modal') {
      openModal('login');
    }
  }

  boot();
})();
