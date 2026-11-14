(function () {
  'use strict';
  var root = document.getElementById('drw');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = '1';
  var C = JSON.parse(document.getElementById('drw-config').textContent), T = C.texts || {};
  var tiers = C.tiers || {}, state = null, claiming = false, stateSeq = 0, historySeq = 0;
  var page = 1, historyItems = [], activeOrder = 0, pinSeq = 0, pinTimer = 0, pinChecks = 0;
  var clockEnd = 0, clockStart = 0, clockRemaining = 0, toastTimer = 0, sessionPromise = null;
  var dialog = document.getElementById('drw-dialog'), returnFocus = null, suspended = false;
  // Native dialog uses the browser's top layer, above fixed site navigation/chat.
  document.body.appendChild(dialog);
  var toastBox = document.getElementById('drw-toast');
  function el(id) { return document.getElementById(id); }
  function text(id, value) { el(id).textContent = value == null ? '' : String(value); }
  function node(tag, cls, value) {
    var n = document.createElement(tag); if (cls) n.className = cls;
    if (value != null) n.textContent = String(value); return n;
  }
  function number(n, decimals) { return Number(n || 0).toLocaleString('fr-FR', {maximumFractionDigits: decimals || 0}); }
  function notice(message) { text('drw-notice', message || ''); el('drw-notice').hidden = !message; }
  function toast(message) {
    clearTimeout(toastTimer); toastBox.textContent = message; toastBox.hidden = false;
    toastTimer = setTimeout(function () { toastBox.hidden = true; }, 3800);
  }
  function clearSecrets() { activeOrder = 0; pinSeq++; clearTimeout(pinTimer); el('drw-pins').replaceChildren(); el('drw-pin-refresh').disabled = false; }
  function renewSession() {
    if (sessionPromise) return sessionPromise;
    sessionPromise = fetch(C.sessionUrl, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=delicat_rewards_session'})
      .then(function (r) { return r.json(); }).then(function (j) {
        if (!j.success || !j.data || Number(j.data.userId) !== Number(C.userId)) {
          clearSecrets(); if (dialog.open) dialog.close(); state = null;
          el('drw-tiers').replaceChildren(); el('drw-history-list').replaceChildren();
          throw new Error('Votre session a changé. Rechargez la page pour vous connecter.');
        }
        C.nonce = j.data.nonce;
      }).finally(function () { sessionPromise = null; });
    return sessionPromise;
  }
  var ajaxTransport = false;
  function transport(path, options, useAjax) {
    var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 30000);
    var url = C.rest + path, request = {method: options.method || 'GET', body: options.body, credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json'}};
    if (useAjax) {
      var parts = path.split('?'), params = options.body ? JSON.parse(options.body) : {};
      new URLSearchParams(parts[1] || '').forEach(function (v, k) { params[k] = v; });
      url = C.sessionUrl;
      request.method = 'POST'; request.headers = {'Content-Type': 'application/x-www-form-urlencoded'};
      request.body = new URLSearchParams({action: 'delicat_rewards_transport', nonce: C.nonce, path: parts[0], method: options.method || 'GET', params: JSON.stringify(params)}).toString();
    }
    return fetch(url, request).then(function (r) {
      return r.text().then(function (body) {
        var data; try { data = JSON.parse(body); } catch (_) { data = null; }
        if (!r.ok || !data || typeof data !== 'object') {
          var error = new Error(data && data.message || (r.status === 403 ? 'Accès bloqué (403). Actualisez la page ou contactez le support.' : 'Réponse du serveur indisponible.'));
          error.status = r.status; error.code = data && data.code; error.nonJson = !data;
          throw error;
        }
        if (suspended) throw new Error('Page inactive.');
        return data;
      });
    }).finally(function () { clearTimeout(timer); });
  }
  function api(path, options, retried) {
    options = options || {};
    var usingAjax = ajaxTransport;
    return transport(path, options, usingAjax).catch(function (error) {
      if (!retried && error.code === 'rest_cookie_invalid_nonce') {
        return renewSession().then(function () { return api(path, options, true); });
      }
      // Retry only a rejected request, keeping the same signed claim token.
      // Domain authorization failures stay failures; no blanket 403 bypass.
      if (!usingAjax && error.status === 403 && (error.nonJson || ['rest_forbidden', 'rest_disabled', 'rest_cannot_access'].indexOf(error.code) >= 0 || (retried && error.code === 'rest_cookie_invalid_nonce'))) {
        return renewSession().then(function () {
          ajaxTransport = true;
          return transport(path, options, true);
        });
      }
      throw error;
    });
  }
  var blockedMessages = {max: 'Limite mensuelle atteinte', cap: 'Plafond mensuel atteint', dry: 'Programme en mode test', frozen: 'Bonus temporairement bloqués'};
  function blocked(st) { return blockedMessages[st.blocked] || ''; }
  function render(s) {
    if (!s || !s.tiers || typeof s.tiers !== 'object') throw new Error('Progression indisponible. Actualisez la page.');
    state = s;
    text('drw-spend', number(s.monthSpend, 2) + ' ' + (T.currency || 'G'));
    text('drw-total', number(s.monthReward) + ' 💎');
    if (s.resetAt) {
      clockEnd = Number(s.resetAt); clockRemaining = Math.max(0, clockEnd - Number(s.serverTime || Date.now() / 1000)); clockStart = Date.now(); tick();
    }
    var keys = Object.keys(tiers), ready = keys.filter(function (k) { return s.tiers[k] && s.tiers[k].claimable; });
    var next = ready[0] || keys.find(function (k) { return s.tiers[k] && !blocked(s.tiers[k]); }) || keys[0];
    notice(s.frozen ? 'Vos bonus sont temporairement bloqués. Contactez le support.' : s.dry ? 'Programme en mode test : aucune récompense ne sera délivrée.' : '');
    text('drw-ready-count', ready.length ? ready.length + (ready.length === 1 ? ' pack disponible' : ' packs disponibles') : '');
    if (next) {
      var t = tiers[next], st = s.tiers[next] || {}, goal = Number(st.goal || t.goal || 1), progress = Number(st.progress || 0), owed = Math.max(0, Number(st.owed || 0));
      var pct = Math.min(100, Math.floor(progress / goal * 100));
      el('drw-ring').style.setProperty('--progress', pct + '%'); text('drw-percent', pct + '%');
      text('drw-next-title', t.label + ' · ' + number(t.reward) + ' 💎');
      // owed: purchases a refund took back after an earlier bonus; they are covered first.
      text('drw-next-message', blocked(st) || (st.claimable ? 'Votre bonus est prêt. Réclamez votre PIN ci-dessous.' : 'Encore ' + number(Math.max(0, goal - progress) + owed) + ' achat(s) éligible(s) pour ce pack' + (owed ? ', dont ' + number(owed) + ' pour compenser un remboursement.' : '.')));
    } else {
      text('drw-percent', '—'); text('drw-next-title', 'Aucun bonus actif'); text('drw-next-message', 'Revenez bientôt pour découvrir les prochains bonus.');
    }
    var list = el('drw-tiers'); list.replaceChildren(); list.setAttribute('aria-busy', 'false');
    if (!keys.length) list.appendChild(node('p', 'drw-help', 'Aucun bonus disponible pour le moment.'));
    keys.forEach(function (key, index) {
      var tier = tiers[key], stat = s.tiers[key] || {}, goal = Math.max(1, Number(stat.goal || tier.goal || 1)), progress = Number(stat.progress || 0);
      var pct = Math.min(100, Math.floor(progress / goal * 100)), available = !!stat.claimable, reason = blocked(stat);
      var card = node('article', 'drw-tier' + (available ? ' is-ready' : ''));
      card.style.setProperty('--tier-color', ['#58e0d3', '#85b4ff', '#d3c2ff', '#f5cf81'][index % 4]);
      var top = node('div', 'drw-tier-top'), info = node('div'), prize = node('div', 'drw-prize');
      info.appendChild(node('h3', '', tier.title || 'Free Fire')); info.appendChild(node('p', '', tier.sub || ''));
      info.appendChild(node('span', 'drw-badge', tier.label + ' bonus'));
      prize.appendChild(node('strong', '', number(tier.reward) + ' 💎'));
      prize.appendChild(node('span', '', available ? 'Disponible' : reason ? 'Indisponible' : 'En cours'));
      top.append(info, prize); card.appendChild(top);
      var label = node('div', 'drw-progress-label');
      label.append(node('strong', '', number(Math.min(progress, goal)) + ' / ' + number(goal) + ' achats'), node('span', '', pct + '%'));
      var track = node('div', 'drw-track'), fill = node('div', 'drw-fill'); fill.style.width = pct + '%';
      track.setAttribute('role', 'progressbar'); track.setAttribute('aria-label', 'Progression ' + tier.label); track.setAttribute('aria-valuemin', '0'); track.setAttribute('aria-valuemax', String(goal)); track.setAttribute('aria-valuenow', String(Math.min(progress, goal))); track.appendChild(fill);
      var owedHere = Math.max(0, Number(stat.owed || 0)), batches = Math.floor(progress / goal), remaining = Math.max(0, goal - progress) + owedHere;
      var detail = reason || (available ? number(progress) + ' achats comptabilisés · ' + batches + ' objectif(s) atteint(s).' : number(remaining) + ' achat(s) restant(s)' + (owedHere ? ', dont ' + number(owedHere) + ' pour compenser un remboursement.' : '.'));
      if (Number(stat.claimed || 0)) detail += ' ' + number(stat.claimed) + ' bonus déjà réclamé(s).';
      var button = node('button', 'drw-primary', available ? 'Réclamer ' + number(tier.reward) + ' 💎 gratuits' : reason || 'Continuez vos achats');
      button.type = 'button'; button.id = 'drw-claim-' + key; button.disabled = claiming || !available;
      button.onclick = function () { claim(key, button); };
      card.append(label, track, node('p', 'drw-remaining', detail), button); list.appendChild(card);
    });
  }
  function refreshState() {
    var seq = ++stateSeq;
    return api('/rewards').then(function (s) { if (seq === stateSeq && !claiming) render(s); });
  }
  function tick() {
    if (!clockEnd) return;
    var seconds = Math.max(0, Math.floor(clockRemaining - (Date.now() - clockStart) / 1000));
    var days = Math.floor(seconds / 86400), hours = Math.floor(seconds % 86400 / 3600), minutes = Math.floor(seconds % 3600 / 60);
    text('drw-reset', seconds ? 'Réinitialisation dans ' + days + ' j ' + hours + ' h ' + minutes + ' min' : 'Nouveau mois : actualisez votre progression.');
    if (!seconds && !claiming) { clockEnd = 0; refreshState().catch(function (e) { notice(e.message); }); }
  }
  function claim(key, button) {
    if (claiming || !state || !state.tiers[key] || !state.tiers[key].claimable) return;
    var token = state.tiers[key].token;
    if (!token) { notice('Actualisez votre progression avant de réclamer.'); return; }
    claiming = true; stateSeq++; returnFocus = button.id;
    root.querySelectorAll('[id^="drw-claim-"]').forEach(function (b) { b.disabled = true; });
    button.textContent = 'Préparation de votre récompense…'; button.setAttribute('aria-busy', 'true');
    api('/claim', {method: 'POST', body: JSON.stringify({tier: key, token: token})})
      .then(function (reply) {
        claiming = false; render(reply);
        if (reply.delivery) openDelivery(reply.delivery);
        else notice('Récompense enregistrée. Retrouvez son PIN dans « Mes PINs récompenses ».');
        page = 1; return loadHistory().catch(function () { notice('Récompense enregistrée. L’historique n’a pas pu être actualisé ; utilisez le PIN affiché ou cliquez sur Actualiser.'); });
      }).catch(function (e) {
        claiming = false;
        var claimError = e.name === 'AbortError' || e instanceof TypeError ? 'La réponse a été interrompue. Vérifiez « Mes PINs récompenses » avant de réclamer à nouveau.' : e.message;
        notice(claimError);
        // Never auto-repeat a POST after a timeout: reconcile read-only state and history.
        Promise.all([refreshState(), loadHistory()]).then(function () { notice(claimError); }).catch(function () {
          notice('Connexion interrompue. Rechargez la page pour vérifier votre récompense.');
          root.querySelectorAll('[id^="drw-claim-"]').forEach(function (b) { b.disabled = true; b.textContent = 'Rechargez pour vérifier'; });
        });
      });
  }
  function loadHistory() {
    var seq = ++historySeq, button = el('drw-history-refresh'); button.disabled = true;
    return api('/reward-pins?page=' + page).then(function (r) {
      if (seq !== historySeq) return;
      historyItems = r.items || []; var list = el('drw-history-list'); list.replaceChildren();
      if (!historyItems.length) list.appendChild(node('p', 'drw-help', 'Vos récompenses réclamées apparaîtront ici.'));
      historyItems.forEach(function (item) {
        var row = node('div', 'drw-history-item'), content = node('div'), tier = tiers[item.tier];
        content.appendChild(node('strong', '', item.amount ? number(item.amount) + ' 💎 · ' + (tier ? tier.label : 'Bonus') : 'Récompense Free Fire'));
        var date = item.date ? new Date(item.date).toLocaleDateString('fr-FR') : '';
        var status = {ready: 'PIN disponible', pending: 'En préparation', partial: 'Livraison partielle', unavailable: 'Indisponible'}[item.status] || 'En préparation';
        content.appendChild(node('p', '', [date, status].filter(Boolean).join(' · ')));
        var b = node('button', 'drw-secondary', item.status === 'ready' ? 'Voir mon PIN' : 'Vérifier'); b.type = 'button';
        b.id = 'drw-order-' + item.orderId;
        b.onclick = function () { returnFocus = b.id; openOrder(item.orderId); };
        row.append(content, b); list.appendChild(row);
      });
      el('drw-pagination').hidden = r.pages <= 1;
      el('drw-prev').disabled = page <= 1; el('drw-next').disabled = page >= r.pages;
      text('drw-page', page + ' / ' + Math.max(1, r.pages || 1));
    }).finally(function () { if (seq === historySeq) button.disabled = false; });
  }
  function showDialog() {
    if (!dialog.open) dialog.showModal();
  }
  function openOrder(id) {
    clearSecrets(); activeOrder = Number(id); pinChecks = 0;
    text('drw-dialog-title', 'Votre récompense'); text('drw-dialog-message', 'Chargement de votre PIN…');
    el('drw-redeem').hidden = true; el('drw-pin-refresh').hidden = false;
    showDialog(); fetchDelivery();
  }
  function openDelivery(delivery) {
    clearSecrets(); activeOrder = Number(delivery.orderId); pinChecks = 0;
    showDialog(); renderDelivery(delivery);
  }
  function renderDelivery(d) {
    if (!activeOrder || activeOrder !== Number(d.orderId) || !dialog.open) return;
    text('drw-dialog-title', d.amount ? number(d.amount) + ' 💎 réclamés' : 'Votre récompense');
    text('drw-dialog-message', d.message);
    var list = el('drw-pins'); list.replaceChildren();
    (d.pins || []).forEach(function (pin, index) {
      var row = node('div', 'drw-pin-row'), label = node('label', '', pin.label || 'PIN ' + (index + 1));
      var controls = node('div', 'drw-code-controls'), input = node('input');
      input.id = 'drw-code-' + index; input.value = pin.code; input.readOnly = true; input.autocomplete = 'off'; input.spellcheck = false;
      input.setAttribute('aria-label', 'PIN ' + (index + 1)); label.htmlFor = input.id;
      input.onclick = function () { input.select(); };
      var copy = node('button', 'drw-secondary', 'Copier'); copy.type = 'button';
      copy.onclick = function () {
        var value = input.value;
        function fallback() {
          input.focus(); input.select(); input.setSelectionRange(0, input.value.length);
          try { if (document.execCommand('copy')) { copied(); return; } } catch (_) {}
          text('drw-dialog-message', 'PIN sélectionné. Appuyez longuement puis choisissez « Copier ».');
        }
        function copied() { copy.textContent = 'Copié ✓'; text('drw-dialog-message', 'PIN copié. Cliquez sur « Utiliser mon PIN », puis collez votre code sur la page de validation.'); }
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(value).then(copied).catch(fallback);
        else fallback();
      };
      controls.append(input, copy); row.append(label, controls); list.appendChild(row);
    });
    // No PIN in URL/query string, analytics, localStorage or browser history.
    el('drw-redeem').hidden = !(d.pins && d.pins.length);
    el('drw-pin-refresh').hidden = d.status === 'ready' || d.status === 'unavailable';
    clearTimeout(pinTimer);
    if ((d.status === 'pending' || d.status === 'partial') && pinChecks < 12) pinTimer = setTimeout(function () {
      if (dialog.open && !document.hidden) fetchDelivery();
    }, 5000);
  }
  function fetchDelivery() {
    if (!activeOrder) return;
    var id = activeOrder, seq = ++pinSeq; clearTimeout(pinTimer); pinChecks++;
    var button = el('drw-pin-refresh'); button.disabled = true;
    return api('/reward-pins?order_id=' + id).then(function (d) { if (seq === pinSeq && id === activeOrder) renderDelivery(d); })
      .catch(function (e) { if (seq === pinSeq && dialog.open) text('drw-dialog-message', e.name === 'AbortError' ? 'Délai dépassé. Actualisez votre PIN ici.' : e.message); })
      .finally(function () { if (seq === pinSeq) button.disabled = false; });
  }
  dialog.addEventListener('close', function () {
    clearSecrets(); toastBox.hidden = true;
    if (returnFocus && el(returnFocus)) el(returnFocus).focus({preventScroll: true});
  });
  el('drw-close').onclick = function () { dialog.close(); };
  el('drw-pin-refresh').onclick = function () { pinChecks = 0; fetchDelivery(); };
  el('drw-history-refresh').onclick = function () {
    Promise.all([loadHistory(), refreshState()]).catch(function (e) { notice(e.message); });
  };
  el('drw-prev').onclick = function () { if (page > 1) page--; loadHistory().catch(function (e) { notice(e.message); }); };
  el('drw-next').onclick = function () { page++; loadHistory().catch(function (e) { notice(e.message); }); };
  text('drw-title', !T.pageTitle || T.pageTitle === 'Rewards Program' ? 'Mes récompenses' : T.pageTitle);
  text('drw-program', T.programName || 'BONUS FREE FIRE'); text('drw-note', T.note || '');
  if (C.brand && C.brand.logo) {
    var image = node('img'); image.src = C.brand.logo; image.alt = C.brand.name || 'Delicat Store'; image.decoding = 'async'; el('drw-brand').appendChild(image);
  } else text('drw-brand', C.brand && C.brand.name || 'DELICAT STORE HAITI');
  var interval = setInterval(function () { if (!document.hidden) tick(); }, 60000);
  window.addEventListener('pagehide', function () {
    suspended = true; clearSecrets(); clearTimeout(toastTimer); clearInterval(interval);
    if (dialog.open) dialog.close();
  });
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) { suspended = false; interval = setInterval(tick, 60000); renewSession().then(function () { return Promise.all([refreshState(), loadHistory()]); }).catch(function (err) { notice(err.message); }); }
  });
  document.addEventListener('visibilitychange', function () { if (document.hidden) clearTimeout(pinTimer); else if (dialog.open && activeOrder) fetchDelivery(); });
  Promise.all([refreshState(), loadHistory()]).catch(function (e) {
    notice(e.name === 'AbortError' ? 'Chargement trop long. Cliquez sur « Actualiser ».' : e.message);
    el('drw-tiers').setAttribute('aria-busy', 'false');
  });
})();
