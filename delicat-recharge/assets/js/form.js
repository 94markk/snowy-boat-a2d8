/* global jQuery */
(function () {
    'use strict';

    // Wait until jQuery AND delicatData are both available, then boot.
    function boot() {
        if (typeof window.jQuery === 'undefined' || typeof window.delicatData === 'undefined') {
            return setTimeout(boot, 50);
        }
        jQuery(function ($) {
            var d = window.delicatData;

            // Mask helper: "LOUIS MARKEN" -> "LOXXX MAXXX"
            function maskName(name) {
                return String(name || '').trim().split(/\s+/).map(function (w) {
                    return w.length > 2 ? w.substring(0, 2) + 'XXX' : w;
                }).join(' ');
            }

            // Inject payment details (run now + whenever a form appears).
            function fillNumbers() {
                $('#dr-mc-num').text(d.moncash_num);
                $('#dr-nc-num').text(d.natcash_num);

                // Names: keep full + masked in data attrs, show per current state.
                $('#dr-mc-nom, #dr-nc-nom').each(function () {
                    var $el  = $(this);
                    var full = $el.attr('id') === 'dr-mc-nom' ? d.moncash_nom : d.natcash_nom;
                    $el.attr('data-full', full);
                    $el.attr('data-masked', maskName(full));
                    // If currently revealed, show full; else masked (default).
                    $el.text($el.hasClass('dr-revealed') ? full : maskName(full));
                });
            }
            fillNumbers();

            // ── Eye toggle: show / hide full payment name ──
            $(document).on('click', '.dr-eye', function () {
                var $el = $('#' + $(this).data('target'));
                if ($el.hasClass('dr-revealed')) {
                    $el.removeClass('dr-revealed').text($el.attr('data-masked'));
                    $(this).text('👁️');
                } else {
                    $el.addClass('dr-revealed').text($el.attr('data-full'));
                    $(this).text('🙈');
                }
            });

            // ── Tab switching (delegated so it works whenever form loads) ──
            $(document).on('click', '#delicat-recharge-form .dr-tab', function () {
                var $form = $(this).closest('#delicat-recharge-form');
                var tab = $(this).data('tab') || 'moncash';
                $form.attr('data-active-tab', tab);
                $form.find('.dr-tab').removeClass('active').attr('aria-selected', 'false');
                $(this).addClass('active').attr('aria-selected', 'true');
                $form.find('#dr-tab').val(tab);

                $form.find('.dr-info-box').hide();
                $form.find('.dr-info-' + tab).show();

                // Compact dynamic helper: avoid repeating a large "method selected"
                // section while still making the current payment flow obvious.
                var $methodNote = $form.find('.dr-method-note-copy');
                if ($methodNote.length) {
                    if (tab === 'natcash_tc') {
                        $methodNote.html('<strong>Natcash Auto (TC)</strong> • validation automatique • rapide &amp; sécurisée');
                    } else if (tab === 'moncash') {
                        $methodNote.html('<strong>MonCash</strong> • recharge classique • vérification sécurisée');
                    } else {
                        $methodNote.html('<strong>Natcash</strong> • recharge sécurisée');
                    }
                }

                if (tab === 'manuel') {
                    $form.find('.dr-field-methode').show();
                    $form.find('.dr-proof-req').show();
                    $form.find('.dr-proof-optional').hide();
                } else {
                    $form.find('.dr-field-methode').hide();
                    $form.find('.dr-proof-req').hide();
                    $form.find('.dr-proof-optional').show();
                }
                // Natcash Auto: hide TransCode, show sender number (SMS auto-verify).
                if (tab === 'natcash') {
                    $form.find('.dr-field-natcash').show();
                    $form.find('.dr-field-trans').hide();
                } else {
                    $form.find('.dr-field-natcash').hide();
                    $form.find('.dr-field-trans').show();
                }
                fillNumbers();
            });

            // ── File input label ──
            $(document).on('change', '#dr-preuve', function () {
                var name = this.files.length ? this.files[0].name : 'Aucun fichier sélectionné';
                $('.dr-file-name').text(name);
            });

            // ── Submit ──
            $(document).on('click', '#dr-submit', function () {
                var $btn = $(this);
                var $msg = $('.dr-message');

                var tab     = $('#dr-tab').val() || 'natcash_tc';
                var montant = ($('#dr-montant').val() || '').trim();
                var trans   = ($('#dr-trans').val() || '').trim();
                var email   = ($('#dr-email').val() || '').trim();
                var methode = tab === 'manuel' ? $('#dr-methode').val() : tab;
                var fileEl  = document.getElementById('dr-preuve');

                if (!montant || parseFloat(montant) <= 0) {
                    return showMsg(d.i18n.required_amount, 'error');
                }
                if (parseFloat(montant) > 100000) {
                    return showMsg('❌ Le montant maximum autorisé est de 100,000 HTG.', 'error');
                }
                if (tab === 'natcash') {
                    var ncS = ($('#dr-nc-sender').val() || '').trim();
                    if (ncS.replace(/\D/g,'').length < 6) {
                        return showMsg('Veuillez entrer votre numéro Natcash (celui qui envoie).', 'error');
                    }
                } else {
                    if (!trans) {
                        return showMsg(d.i18n.required_trans, 'error');
                    }
                }
                if (tab === 'manuel' && (!fileEl || !fileEl.files.length)) {
                    return showMsg(d.i18n.required_proof, 'error');
                }
                if (d.logged_in === '0' && !email) {
                    return showMsg(d.i18n.required_email, 'error');
                }

                var fd = new FormData();
                fd.append('action',     'delicat_submit_recharge');
                fd.append('nonce',      d.nonce);
                fd.append('tab',        tab);
                fd.append('methode',    methode);
                fd.append('montant',    montant);
                fd.append('trans_code', trans);
                if (tab === 'natcash') {
                    var ncSender = ($('#dr-nc-sender').val() || '').trim();
                    fd.append('nc_sender', ncSender);
                }
                if (email)                     fd.append('email',  email);
                if (fileEl && fileEl.files[0]) fd.append('preuve', fileEl.files[0]);

                // UI snapshot only. The amount is used for the confirmation
                // animation after the existing server has confirmed a real credit.
                var submittedAmount = parseFloat(montant) || 0;

                setLoading(true, $btn);
                $msg.hide();

                $.ajax({
                    url:         d.ajax_url,
                    type:        'POST',
                    data:        fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        if (res && res.success) {
                            var approved = !!(res.data && res.data.auto_approved === true);
                            showMsg(res.data.message, approved ? 'success' : 'pending');

                            // The popup is intentionally NOT shown for a merely pending
                            // request. It appears only when the existing backend says the
                            // wallet was credited, or later when the balance watcher sees it.
                            if (approved) {
                                showRechargeSuccess(
                                    submittedAmount,
                                    (res.data && res.data.currency) ? res.data.currency : 'HTG',
                                    (res.data && res.data.balance_fmt) ? res.data.balance_fmt : null
                                );
                            }

                            resetForm();
                            // Update the wallet balance in place — no page refresh.
                            // On instant credit the response already carries the new
                            // balance (animate now); otherwise poll until the SMS credits.
                            var known = (res.data && typeof res.data.balance !== 'undefined')
                                ? parseFloat(res.data.balance) : NaN;
                            var knownFmt = (res.data && res.data.balance_fmt) ? res.data.balance_fmt : null;
                            watchBalance({
                                knownBalance: isNaN(known) ? undefined : known,
                                knownFmt:     knownFmt,
                                requestedAmount: submittedAmount,
                                currency: (res.data && res.data.currency) ? res.data.currency : 'HTG',
                                showSuccessOnIncrease: !approved
                            });
                        } else {
                            showMsg((res && res.data && res.data.message) || 'Une erreur est survenue.', 'error');
                        }
                    },
                    error: function () {
                        showMsg(d.i18n.network_error, 'error');
                    },
                    complete: function () {
                        setLoading(false, $btn);
                    }
                });
            });

            function showMsg(text, type, skipScroll) {
                var $msg = $('.dr-message');
                if (!$msg.length) { alert(text.replace(/<[^>]+>/g, '')); return; }
                $msg.removeClass('dr-success dr-error dr-pending').addClass('dr-' + type).html(text).show();
                if (!skipScroll) {
                    $('html,body').animate({ scrollTop: $msg.offset().top - 100 }, 350);
                }
            }

            function setLoading(on, $btn) {
                $btn.prop('disabled', on);
                $btn.find('.dr-btn-label').toggle(!on);
                $btn.find('.dr-btn-spin').toggle(on);
            }

            function resetForm() {
                $('#dr-montant, #dr-trans, #dr-email').val('');
                $('#dr-preuve').val('');
                $('.dr-file-name').text('Aucun fichier sélectionné');
            }

            /* ============================================================
               Premium success confirmation — presentation only.
               The backend remains the sole source of truth for crediting.
               ============================================================ */
            var lastSuccessKey = '';
            var successReturnFocus = null;

            function formatRechargeAmount(value) {
                var n = parseFloat(value);
                if (isNaN(n)) { return String(value || '0'); }
                try {
                    return new Intl.NumberFormat('fr-FR', {
                        minimumFractionDigits: Number.isInteger(n) ? 0 : 2,
                        maximumFractionDigits: 2
                    }).format(n);
                } catch (e) {
                    return String(Math.round(n * 100) / 100).replace('.', ',');
                }
            }

            function showRechargeSuccess(amount, currency, balanceFmt) {
                var modal = document.getElementById('dr-success-modal');
                if (!modal) { return; }

                amount = parseFloat(amount);
                if (isNaN(amount) || amount <= 0) { return; }
                currency = String(currency || 'HTG').toUpperCase();

                // Prevent an instant response + the live-balance poll from opening
                // the same confirmation twice.
                var key = String(amount) + '|' + currency + '|' + String(balanceFmt || '');
                if (key === lastSuccessKey && modal.classList.contains('is-open')) { return; }
                lastSuccessKey = key;

                var amountEl = document.getElementById('dr-success-amount');
                var currencyEl = document.getElementById('dr-success-currency');
                var balanceEl = document.getElementById('dr-success-balance');
                if (amountEl) { amountEl.textContent = formatRechargeAmount(amount); }
                if (currencyEl) { currencyEl.textContent = currency; }
                if (balanceEl) {
                    if (balanceFmt) {
                        balanceEl.hidden = false;
                        balanceEl.textContent = 'Nouveau solde : ' + balanceFmt + ' ' + currency;
                    } else {
                        balanceEl.hidden = true;
                        balanceEl.textContent = '';
                    }
                }

                successReturnFocus = document.activeElement;
                modal.setAttribute('aria-hidden', 'false');
                modal.classList.add('is-open');
                window.requestAnimationFrame(function () {
                    modal.classList.add('is-visible');
                    var dialog = modal.querySelector('.dr-success-dialog');
                    if (dialog) { try { dialog.focus({ preventScroll: true }); } catch (e) { dialog.focus(); } }
                });
            }

            function closeRechargeSuccess() {
                var modal = document.getElementById('dr-success-modal');
                if (!modal || !modal.classList.contains('is-open')) { return; }
                modal.classList.remove('is-visible');
                window.setTimeout(function () {
                    modal.classList.remove('is-open');
                    modal.setAttribute('aria-hidden', 'true');
                    if (successReturnFocus && typeof successReturnFocus.focus === 'function') {
                        try { successReturnFocus.focus({ preventScroll: true }); } catch (e) {}
                    }
                }, 220);
            }

            $(document).on('click', '[data-dr-success-close]', closeRechargeSuccess);
            $(document).on('keydown', function (event) {
                if (event.key === 'Escape' && $('#dr-success-modal').hasClass('is-open')) {
                    event.preventDefault();
                    closeRechargeSuccess();
                }
            });

            /* ============================================================
               Live wallet balance — update WITHOUT a page refresh.

               After a recharge is submitted we watch the balance:
                 • instant credit (MonCash / Natcash-TC SMS already in): the
                   response carries the new balance -> animate right away;
                 • deferred (waiting on the SMS): poll delicat_balance for a
                   short window and animate the moment the number grows.
               The count-up runs on requestAnimationFrame with easing, and a
               soft green glow pulses while it lands. Prefers-reduced-motion is
               respected (no transform/glow). Backend logic is untouched.
               ============================================================ */
            var balanceTimer = null;

            function getBalanceEl() { return document.getElementById('dw12-balance-amount'); }

            // The logical current balance (updated the instant an animation starts,
            // so overlapping polls never re-trigger for the same value).
            function readShownBalance() {
                var el = getBalanceEl();
                if (!el) { return null; }
                var n = parseFloat(el.getAttribute('data-balance'));
                return isNaN(n) ? null : n;
            }

            // Match the thousands separator the server used ("1 234" / "1,234" / "870").
            function detectSep(fmt) {
                var m = String(fmt || '').match(/\d(\D)\d{3}(?:\D|$)/);
                return m ? m[1] : '';
            }
            function groupInt(n, sep) {
                var s = String(Math.round(n));
                return sep ? s.replace(/\B(?=(\d{3})+(?!\d))/g, sep) : s;
            }

            function animateBalance(from, to, toFmt) {
                var el = getBalanceEl();
                if (!el) { return; }
                var strong = el.querySelector('strong');
                if (!strong) { return; }
                to = parseFloat(to);
                if (isNaN(to)) { return; }
                from = (typeof from === 'number' && !isNaN(from)) ? from : to;

                // Set the logical value up-front so concurrent polls don't re-fire.
                if (el.getAttribute('data-anim-target') === String(to)) { return; }
                el.setAttribute('data-anim-target', String(to));
                el.setAttribute('data-balance', String(to));

                var sep      = detectSep(toFmt);
                var finalTxt = toFmt || groupInt(to, sep);

                var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (reduce || !window.requestAnimationFrame) {
                    strong.textContent = finalTxt;
                    return;
                }

                el.classList.add('dw12-amount--bump');
                var start = null, dur = 900;
                function step(ts) {
                    if (start === null) { start = ts; }
                    var p = Math.min(1, (ts - start) / dur);
                    var e = 1 - Math.pow(1 - p, 3); // easeOutCubic
                    strong.textContent = groupInt(from + (to - from) * e, sep);
                    if (p < 1) {
                        window.requestAnimationFrame(step);
                    } else {
                        strong.textContent = finalTxt;
                        setTimeout(function () {
                            el.classList.remove('dw12-amount--bump');
                            el.removeAttribute('data-anim-target');
                        }, 280);
                    }
                }
                window.requestAnimationFrame(step);
            }

            function fetchBalance(cb) {
                if (!d || !d.ajax_url) { return cb(null); }
                $.ajax({
                    url: d.ajax_url, type: 'POST', dataType: 'json', cache: false,
                    data: { action: 'delicat_balance', nonce: d.nonce },
                    success: function (res) {
                        if (res && res.success && res.data && typeof res.data.balance !== 'undefined') {
                            cb({ value: parseFloat(res.data.balance), fmt: res.data.balance_fmt || null });
                        } else { cb(null); }
                    },
                    error: function () { cb(null); }
                });
            }

            function watchBalance(opts) {
                opts = opts || {};
                if (!getBalanceEl()) { return; } // live balance card is the safe UI signal

                var baseline = readShownBalance();
                if (baseline === null) { baseline = 0; }

                // Instant credit: the response already carried the new balance.
                var hasKnown = (typeof opts.knownBalance === 'number' && !isNaN(opts.knownBalance));
                if (hasKnown && opts.knownBalance > baseline + 0.001) {
                    animateBalance(baseline, opts.knownBalance, opts.knownFmt);
                }

                // Poll a little regardless — the SMS credit can land a beat later,
                // and this also catches the deferred (pending) case.
                if (balanceTimer) { clearInterval(balanceTimer); balanceTimer = null; }
                var interval = opts.interval || 2500;
                var maxMs    = opts.maxMs || 120000; // give up after 2 min
                var started  = Date.now();

                function tick() {
                    if (Date.now() - started > maxMs) {
                        clearInterval(balanceTimer); balanceTimer = null; return;
                    }
                    fetchBalance(function (bal) {
                        if (!bal || isNaN(bal.value)) { return; }
                        var current = readShownBalance();
                        if (current === null) { current = baseline; }
                        if (bal.value > current + 0.001) {
                            var creditedDelta = Math.max(0, bal.value - baseline);
                            animateBalance(current, bal.value, bal.fmt);
                            if (opts.showSuccessOnIncrease) {
                                var successAmount = creditedDelta > 0.001
                                    ? creditedDelta
                                    : (parseFloat(opts.requestedAmount) || 0);
                                showRechargeSuccess(successAmount, opts.currency || 'HTG', bal.fmt || null);
                                opts.showSuccessOnIncrease = false;
                                showMsg('✅ Recharge réussie ! <strong>' + formatRechargeAmount(successAmount) + ' ' + (opts.currency || 'HTG') + '</strong> ajoutés à votre compte.', 'success', true);
                            }
                            clearInterval(balanceTimer); balanceTimer = null;
                        }
                    });
                }
                setTimeout(tick, hasKnown ? 1400 : 500); // first quick check
                balanceTimer = setInterval(tick, interval);
            }

            // Sync fields/info-box to whichever tab is active on load (the first
            // enabled service may be MonCash, Natcash Auto, or Natcash Auto TC).
            function syncRechargeTab() {
                $('#delicat-recharge-form').each(function () {
                    var $form = $(this);
                    var $active = $form.find('.dr-tab.active').first();
                    if (!$active.length) { $active = $form.find('.dr-tab').first().addClass('active'); }
                    $active.trigger('click');
                });
            }
            syncRechargeTab();
            // Safari may restore an old DOM snapshot from back/forward cache.
            window.addEventListener('pageshow', syncRechargeTab);
        });
    }

    boot();
})();

/* ============================================================
   Delicat wallet — UNIFIED hero interactions (v5.1.7)
   Replaces every previous stacked send-menu handler (v5.0.6 → v5.1.6)
   with ONE clean, conflict-free implementation.

   Why the old menu never opened: six separate document click
   listeners were bound. In the capture phase they ran in order —
   one opened the menu, the next immediately toggled it shut and
   called stopImmediatePropagation(), cancelling the rest. Net: the
   button appeared dead. This single handler fixes that permanently.

   Backend / nonces / wallet debit-credit logic remain untouched.
   ============================================================ */
(function () {
  'use strict';
  if (window.__delicatWalletHeroBound) { return; }
  window.__delicatWalletHeroBound = true;

  function closest(el, sel) { return el && el.closest ? el.closest(sel) : null; }
  function smoothTo(el) {
    if (!el) { return; }
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var header = document.querySelector('#wpadminbar, .elementor-location-header, header.site-header, header');
    var offset = 20;
    if (header) {
      var rect = header.getBoundingClientRect();
      if (rect.bottom > 0 && rect.top <= 12) { offset += Math.min(120, rect.height || rect.bottom); }
    }
    var y = el.getBoundingClientRect().top + window.pageYOffset - offset;
    try {
      window.scrollTo({ top: Math.max(0, y), behavior: reduce ? 'auto' : 'smooth' });
    } catch (err) {
      window.scrollTo(0, Math.max(0, y));
    }
    el.classList.remove('dr-scroll-focus');
    window.setTimeout(function () {
      el.classList.add('dr-scroll-focus');
      window.setTimeout(function () { el.classList.remove('dr-scroll-focus'); }, 900);
    }, reduce ? 0 : 360);
  }

  /* ---- Envoyer dropdown menu ------------------------------- */
  function setMenu(wrap, open) {
    if (!wrap) { return; }
    wrap.classList.toggle('open', open);
    // Keep the native <details> attribute in sync so CSS matches either way.
    if (wrap.tagName && wrap.tagName.toLowerCase() === 'details') {
      if (open) { wrap.setAttribute('open', 'open'); } else { wrap.removeAttribute('open'); }
    }
    var btn = wrap.querySelector('[data-delicat-send-menu]');
    if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }
  function closeMenus(except) {
    document.querySelectorAll('.dw12-send-wrap.open, .delicat-send-wrap.open').forEach(function (w) {
      if (w !== except) { setMenu(w, false); }
    });
  }

  /* ---- QR receive modal ------------------------------------ */
  function openQr(card) {
    var modal = (card && card.querySelector('[data-delicat-qr-modal]')) || document.querySelector('[data-delicat-qr-modal]');
    if (!modal) { return; }
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('delicat-qr-lock');
  }
  function closeQr() {
    document.querySelectorAll('[data-delicat-qr-modal].open').forEach(function (m) {
      m.classList.remove('open');
      m.setAttribute('aria-hidden', 'true');
    });
    document.documentElement.classList.remove('delicat-qr-lock');
    if (window.delicatSend) { window.delicatSend.close(); }
  }

  /* ---- Reveal the secure transfer form --------------------- */
  function openTransfer() {
    // Single code path: the modal owns its own open/close and scroll lock, so
    // never duplicate that logic here. Two implementations fighting over the
    // same body class is what previously left the page unscrollable.
    if (window.delicatSend) { window.delicatSend.send(); return; }

    // Fallback only for pages still rendering the old inline panel.
    var panel = document.getElementById('envoyer-argent');
    var section = document.getElementById('dsm-section');
    if (panel) { panel.classList.add('is-visible'); }
    if (section) {
      section.classList.add('open');
      var head = section.querySelector('[data-dsm-toggle]');
      if (head) { head.setAttribute('aria-expanded', 'true'); }
    }
    smoothTo(panel || section);
  }
  function toggleSection(section) {
    if (!section) { return; }
    var open = !section.classList.contains('open');
    section.classList.toggle('open', open);
    var head = section.querySelector('[data-dsm-toggle]');
    if (head) { head.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }

  /* ---- Copy code ------------------------------------------- */
  function copyCode(btn) {
    var code = btn.getAttribute('data-dsm-copy') || '';
    var label = btn.querySelector('.dsm-copytxt');
    var old = label ? label.textContent : '';
    function done() {
      if (label) {
        label.textContent = '✓';
        btn.classList.add('ok');
        setTimeout(function () { label.textContent = old; btn.classList.remove('ok'); }, 1300);
      }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(code).then(done).catch(done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = code; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta); done();
    }
  }

  /* ---- ONE delegated click handler (bubble phase) ---------- */
  document.addEventListener('click', function (e) {
    var copy = closest(e.target, '[data-dsm-copy]');
    if (copy) { e.preventDefault(); copyCode(copy); return; }

    // Toggle the Envoyer dropdown. preventDefault stops the native
    // <details> toggle so this handler is the single source of truth.
    var summary = closest(e.target, '[data-delicat-send-menu]');
    if (summary) {
      e.preventDefault();
      var wrap = closest(summary, '.dw12-send-wrap, .delicat-send-wrap');
      var willOpen = wrap && !wrap.classList.contains('open');
      closeMenus(wrap);
      setMenu(wrap, !!willOpen);
      return;
    }

    // Menu → "À un autre client Delicat": reveal the secure form.
    var sendOpt = closest(e.target, '[data-delicat-scroll="send"]');
    if (sendOpt) { e.preventDefault(); closeMenus(); openTransfer(); return; }

    // Menu → "Recevoir par QR code".
    var qrOpen = closest(e.target, '[data-delicat-qr-open]');
    if (qrOpen) {
      e.preventDefault(); closeMenus();
      if (window.delicatSend) { window.delicatSend.qr(); }
      else { openQr(closest(qrOpen, '.dw12-card')); }
      return;
    }

    // Menu → "Historique des envois".
    var hist = closest(e.target, '[data-delicat-scroll="history"]');
    if (hist) {
      e.preventDefault(); closeMenus();
      var h = document.getElementById('historique') || document.querySelector('.dsm-history, .wallet-transactions, .woo-wallet-transactions');
      if (h) { smoothTo(h); }
      return;
    }

    // Recharger tile → scroll to the recharge form.
    var recharge = closest(e.target, '[data-delicat-scroll="recharge"]');
    if (recharge) {
      e.preventDefault();
      e.stopPropagation();
      closeMenus();
      var t = document.getElementById('delicat-recharge-form') || document.querySelector('.dr-wrap');
      if (t) {
        window.requestAnimationFrame(function () { smoothTo(t); });
        if (history.replaceState && location.hash === '#delicat-recharge-form') {
          history.replaceState(null, document.title, location.pathname + location.search);
        }
      }
      return;
    }

    // QR close.
    var qrClose = closest(e.target, '[data-delicat-qr-close]');
    if (qrClose) { e.preventDefault(); closeQr(); return; }

    // Secure-section header collapse toggle.
    var secHead = closest(e.target, '#dsm-section [data-dsm-toggle], .dsm-collapse [data-dsm-toggle]');
    if (secHead) { e.preventDefault(); toggleSection(closest(secHead, '.dsm-collapse') || document.getElementById('dsm-section')); return; }

    // Any click outside an open menu closes it.
    if (!closest(e.target, '.dw12-send-wrap, .delicat-send-wrap')) { closeMenus(); }
  });

  // Escape closes menu + QR.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.keyCode === 27) { closeMenus(); closeQr(); }
  });

  // Safety net for keyboard-driven native <details> toggles.
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (d && d.classList && d.classList.contains('dw12-send-wrap')) {
      var open = d.hasAttribute('open');
      d.classList.toggle('open', open);
      var btn = d.querySelector('[data-delicat-send-menu]');
      if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }
  }, true);

  // Deep-link: open the transfer form when the hash is #envoyer-argent.
  function hashOpen() { if (location.hash === '#envoyer-argent' && !window.delicatSend) { setTimeout(openTransfer, 120); } }
  window.addEventListener('hashchange', hashOpen);
  hashOpen();

  // v6.4.2: the static CSS gradient replaces injected decorative bubbles.

})();

/* ============================================================
   v6.1.0 — Recharge premium UI helpers
   Presentation-only helpers: copy buttons + field visual state.
   Submission/AJAX/payment/wallet logic above is untouched.
   ============================================================ */
(function () {
    'use strict';
    if (window.__delicatRechargeUiV610) { return; }
    window.__delicatRechargeUiV610 = true;

    function copyText(text, done) {
        text = String(text || '');
        if (!text) { return; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
            return;
        }
        fallbackCopy(text, done);
    }

    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        ta.style.pointerEvents = 'none';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        if (done) { done(); }
    }

    function markValue(input) {
        var field = input && input.closest ? input.closest('.dr-field') : null;
        if (!field) { return; }
        field.classList.toggle('dr-has-value', String(input.value || '').trim() !== '');
    }

    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('#delicat-recharge-form [data-dr-copy]') : null;
        if (!btn) { return; }
        event.preventDefault();
        copyText(btn.getAttribute('data-dr-copy'), function () {
            var label = btn.querySelector('.dr-copy-label');
            var old = label ? label.textContent : '';
            btn.classList.add('is-copied');
            if (label) { label.textContent = 'Copié'; }
            btn.setAttribute('aria-label', 'Numéro copié');
            window.setTimeout(function () {
                btn.classList.remove('is-copied');
                if (label) { label.textContent = old || 'Copier'; }
            }, 1300);
        });
    });

    document.addEventListener('input', function (event) {
        if (!event.target || !event.target.matches || !event.target.matches('#delicat-recharge-form input, #delicat-recharge-form select')) { return; }
        markValue(event.target);
    });

    function init() {
        document.querySelectorAll('#delicat-recharge-form input, #delicat-recharge-form select').forEach(markValue);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
