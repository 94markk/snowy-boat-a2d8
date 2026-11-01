/* Delicat Rewards 3.3.0 — Bonus Free Fire page: « Classement » (Meilleur Client) and « Mes bonus »
 * on one page. One request (delicat_bonus_boot) brings the session and both tabs' data; claims keep
 * the 3.2.4 protocol (signed token, session renewal, AJAX fallback when a host blocks REST, never an
 * automatic second POST). PINs never go into a URL, storage or analytics. */
(function (w, d) {
  'use strict';
  var ICONS = __ICONS__;
  /* One run per page shown: at load, and again after Builder's instant navigation brings the page in. */
  function start(root) {
  root.dataset.ready = '1';
  var C = {};
  try { C = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { return; }
  var T = C.texts || {}, tiers = C.tiers || {};
  var S = {
    loggedIn: false, userId: 0, nonce: '', state: null, claiming: false, stateSeq: 0, historySeq: 0, page: 1,
    historyAsked: false, activeOrder: 0, pinSeq: 0, pinTimer: 0, pinChecks: 0, ajax: false, session: null,
    suspended: false, skew: 0, nextReset: 0, monthLeft: 0, monthAt: 0, board: null, booting: null
  };
  var dialog = d.getElementById('dbf-dialog'), returnFocus = null, toastTimer = 0;
  var reduced = w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------------ helpers */
  function $(id) { return d.getElementById(id); }
  function text(id, v) { var e = $(id); if (e) e.textContent = v == null ? '' : String(v); }
  function node(tag, cls, v) { var n = d.createElement(tag); if (cls) n.className = cls; if (v != null) n.textContent = String(v); return n; }
  function icon(name, cls) {
    var svg = d.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 -960 960 960'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false');
    svg.setAttribute('class', 'dbf-i' + (cls ? ' ' + cls : ''));
    var p = d.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', ICONS[name] || ''); svg.appendChild(p);
    return svg;
  }
  function fillIcons(scope) { [].forEach.call(scope.querySelectorAll('[data-icon]'), function (el) { if (!el.firstChild) el.appendChild(icon(el.getAttribute('data-icon'))); }); }
  // Texts typed in the dashboard may carry emoji: the page draws Material icons only.
  var PICTO = /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{FE0F}\u{200D}\u{20E3}]/gu;
  function clean(s) { return String(s == null ? '' : s).replace(PICTO, '').replace(/\s{2,}/g, ' ').trim(); }
  function txt(k, fallback) { var v = clean(T[k]); return v || fallback; }
  function num(n, dec) { return Number(n || 0).toLocaleString('fr-FR', { maximumFractionDigits: dec || 0 }); }
  function diamonds(n) { return num(n) + ' diamants'; }
  function initials(name) { var p = clean(name).split(/\s+/); return ((p[0] || '?').charAt(0) + (p[1] || '').charAt(0)).toUpperCase() || '?'; }
  function store(key, value) {
    try {
      if (value === undefined) return JSON.parse(w.localStorage.getItem(key) || 'null');
      w.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) { /* private mode */ }
    return null;
  }
  function notice(msg) { var n = $('dbf-notice'); n.textContent = msg || ''; n.hidden = !msg; }
  function toast(msg) {
    var t = $('dbf-toast'); clearTimeout(toastTimer); t.textContent = msg; t.hidden = false;
    toastTimer = setTimeout(function () { t.hidden = true; }, 4200);
  }
  function span(ms) { // "2 j 05 h", "3 h 12 min", "27 min", "45 s"
    var s = Math.max(0, Math.floor(ms / 1000)), dd = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
    if (dd) return dd + ' j ' + String(h).padStart(2, '0') + ' h';
    if (h) return h + ' h ' + String(m).padStart(2, '0') + ' min';
    if (m) return m + ' min';
    return s + ' s';
  }

  /* ------------------------------------------------------------------ celebration */
  function confetti(big) {
    if (reduced || !w.requestAnimationFrame) return;
    var cv = node('canvas', 'dbf-confetti'); cv.setAttribute('aria-hidden', 'true');
    var W = w.innerWidth, H = w.innerHeight, dpr = Math.min(2, w.devicePixelRatio || 1);
    cv.width = W * dpr; cv.height = H * dpr; d.body.appendChild(cv);
    var g = cv.getContext('2d'); if (!g) { cv.remove(); return; } g.scale(dpr, dpr);
    var pri = getComputedStyle(root).getPropertyValue('--dbf-pri').trim() || '#2563eb';
    var colors = [pri, '#f59e0b', '#ec4899', '#22c55e', '#38bdf8', '#a855f7', '#facc15'];
    var parts = [], n = big ? 170 : 70;
    for (var i = 0; i < n; i++) {
      var left = big ? i % 2 === 0 : true, x0 = big ? (left ? W * 0.12 : W * 0.88) : W / 2;
      parts.push({ x: x0, y: big ? H * 0.7 : H * 0.35, vx: big ? (left ? 1 : -1) * (3 + Math.random() * 7) : (Math.random() - 0.5) * 12,
        vy: -(big ? 12 + Math.random() * 9 : 4 + Math.random() * 9), r: Math.random() * 6, vr: (Math.random() - 0.5) * 0.35,
        w: 5 + Math.random() * 6, h: 8 + Math.random() * 8, c: colors[i % colors.length] });
    }
    var t0 = 0;
    function frame(t) {
      if (!t0) t0 = t;
      var age = t - t0, alive = false;
      g.clearRect(0, 0, W, H);
      g.globalAlpha = Math.max(0, Math.min(1, (3000 - age) / 700));
      for (var k = 0; k < parts.length; k++) {
        var p = parts[k];
        p.vy += 0.32; p.vx *= 0.985; p.x += p.vx; p.y += p.vy; p.r += p.vr;
        if (p.y < H + 30) alive = true;
        g.save(); g.translate(p.x, p.y); g.rotate(p.r); g.fillStyle = p.c;
        g.fillRect(-p.w / 2, -p.h / 2, p.w, Math.max(2, p.h * Math.abs(Math.cos(p.r * 1.7))));
        g.restore();
      }
      if (alive && age < 3000) w.requestAnimationFrame(frame); else cv.remove();
    }
    w.requestAnimationFrame(frame);
  }

  /* ------------------------------------------------------------------ server */
  function post(params) {
    return fetch(C.boot, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(params).toString() })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (j) {
        if (!r.ok || !j) { var e = new Error(j && j.message || 'Service indisponible. Réessayez.'); e.status = r.status; throw e; }
        return j;
      }); });
  }
  function renewSession() {
    if (S.session) return S.session;
    S.session = post({ action: 'delicat_rewards_session' }).then(function (j) {
      if (!j.success || !j.data || Number(j.data.userId) !== Number(S.userId)) {
        clearSecrets(); if (dialog.open) dialog.close(); S.state = null;
        throw new Error('Votre session a changé. Rechargez la page pour vous connecter.');
      }
      S.nonce = j.data.nonce;
    }).finally(function () { S.session = null; });
    return S.session;
  }
  function transport(path, options, useAjax) {
    var ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, 30000);
    var url = C.rest + path, req = { method: options.method || 'GET', body: options.body, credentials: 'same-origin', cache: 'no-store', signal: ctl.signal, headers: { 'X-WP-Nonce': S.nonce, 'Content-Type': 'application/json' } };
    if (useAjax) {
      var parts = path.split('?'), params = options.body ? JSON.parse(options.body) : {};
      new URLSearchParams(parts[1] || '').forEach(function (v, k) { params[k] = v; });
      url = C.boot; req.method = 'POST'; req.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
      req.body = new URLSearchParams({ action: 'delicat_rewards_transport', nonce: S.nonce, path: parts[0], method: options.method || 'GET', params: JSON.stringify(params) }).toString();
    }
    return fetch(url, req).then(function (r) {
      return r.text().then(function (body) {
        var data; try { data = JSON.parse(body); } catch (_) { data = null; }
        if (!r.ok || !data || typeof data !== 'object') {
          var err = new Error(data && data.message || (r.status === 403 ? 'Accès bloqué (403). Actualisez la page ou contactez le support.' : 'Réponse du serveur indisponible.'));
          err.status = r.status; err.code = data && data.code; err.nonJson = !data; throw err;
        }
        if (S.suspended) throw new Error('Page inactive.');
        return data;
      });
    }).finally(function () { clearTimeout(timer); });
  }
  function api(path, options, retried) {
    options = options || {};
    var usingAjax = S.ajax;
    return transport(path, options, usingAjax).catch(function (e) {
      if (!retried && e.code === 'rest_cookie_invalid_nonce') return renewSession().then(function () { return api(path, options, true); });
      // Retry only a rejected request, with the same signed claim token; no blanket 403 bypass.
      if (!usingAjax && e.status === 403 && (e.nonJson || ['rest_forbidden', 'rest_disabled', 'rest_cannot_access'].indexOf(e.code) >= 0 || (retried && e.code === 'rest_cookie_invalid_nonce'))) {
        return renewSession().then(function () { S.ajax = true; return transport(path, options, true); });
      }
      throw e;
    });
  }

  /* ------------------------------------------------------------------ tabs */
  var tabs = [].slice.call(root.querySelectorAll('[role="tab"]'));
  function current() { return root.getAttribute('data-tab') || 'classement'; }
  function select(name, focus) {
    if (!$('dbf-panel-' + name)) return;
    tabs.forEach(function (t) { var on = t.id === 'dbf-tab-' + name; t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1; if (on && focus) t.focus(); });
    ['classement', 'bonus'].forEach(function (n) { var p = $('dbf-panel-' + n); if (p) p.hidden = n !== name; });
    root.setAttribute('data-tab', name);
    if (tabs.length) try { history.replaceState(history.state, '', '#' + (name === 'bonus' ? 'mes-bonus' : 'classement')); } catch (e) { /* sandboxed */ }
    if (name === 'bonus' && S.loggedIn && !S.historyAsked) loadHistory().catch(function (e) { notice(e.message); });
    yourBar();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { select(t.id.replace('dbf-tab-', '')); });
    t.addEventListener('keydown', function (e) {
      var k = e.key, j = k === 'ArrowRight' ? i + 1 : k === 'ArrowLeft' ? i - 1 : k === 'Home' ? 0 : k === 'End' ? tabs.length - 1 : null;
      if (j === null) return;
      e.preventDefault(); select(tabs[(j + tabs.length) % tabs.length].id.replace('dbf-tab-', ''), true);
    });
  });
  if (/^#mes-bonus\b/.test(location.hash)) select('bonus'); else if (/^#classement\b/.test(location.hash)) select('classement');

  /* ------------------------------------------------------------------ classement */
  function login() { var c = $('dbf-login').cloneNode(true); c.removeAttribute('id'); c.hidden = false; fillIcons(c); return c; }
  function renderBoard(b) {
    var box = $('dbf-board'); if (!box) return;
    box.replaceChildren(); box.setAttribute('aria-busy', 'false');
    S.board = b;
    if (!b) { box.appendChild(login()); yourBar(); return; }
    if (b.error) { box.appendChild(node('p', 'dbf-muted', b.error)); yourBar(); return; }
    var top = b.top || [], prizes = C.prizes || [];
    if (!top.length) box.appendChild(node('p', 'dbf-empty', txt('boardEmptyText', 'Pas encore de commande cette semaine. Soyez le premier !')));
    else {
      var pod = node('div', 'dbf-podium');
      [1, 0, 2].forEach(function (idx, j) {
        var p = top[idx]; if (!p) { pod.appendChild(node('div', 'dbf-pod is-empty')); return; }
        var col = node('div', 'dbf-pod dbf-pod-' + (idx + 1)); col.style.setProperty('--d', j * 90 + 'ms');
        if (idx === 0) { var crown = node('span', 'dbf-crown'); crown.appendChild(icon('workspace_premium')); col.appendChild(crown); }
        col.appendChild(node('span', 'dbf-av', initials(p.name)));
        col.appendChild(node('b', 'dbf-pod-name', clean(p.name) || 'Client'));
        col.appendChild(node('span', 'dbf-pod-pct', (parseInt(p.pct, 10) || 0) + ' %'));
        if (+prizes[idx] > 0) { var pr = node('span', 'dbf-pod-prize'); pr.appendChild(icon('diamond')); pr.appendChild(d.createTextNode(diamonds(prizes[idx]))); col.appendChild(pr); }
        col.appendChild(node('span', 'dbf-step', String(idx + 1)));
        pod.appendChild(col);
      });
      box.appendChild(pod);
      if (top.length > 3) {
        var list = node('ol', 'dbf-rank'); list.start = 4;
        top.slice(3).forEach(function (p, k) {
          var li = node('li', 'dbf-rank-row'), pct = Math.max(4, Math.min(100, parseInt(p.pct, 10) || 0));
          li.appendChild(node('span', 'dbf-rank-n', '#' + (k + 4)));
          li.appendChild(node('span', 'dbf-av dbf-av--sm', initials(p.name)));
          var mid = node('div', 'dbf-rank-mid'); mid.appendChild(node('b', '', clean(p.name) || 'Client'));
          var bar = node('span', 'dbf-bar'), fill = node('i'); fill.style.width = pct + '%'; bar.appendChild(fill); mid.appendChild(bar);
          li.appendChild(mid); li.appendChild(node('span', 'dbf-rank-pct', pct + ' %'));
          list.appendChild(li);
        });
        box.appendChild(list);
      }
    }
    yourBar();
    var you = b.you;
    if (you && +you.rank === 1 && top.length) {
      var wk = 'dbf_first_' + S.nextReset;
      if (!store(wk)) { store(wk, 1); confetti(false); toast('Vous êtes 1er cette semaine. Gardez votre place jusqu’au reset !'); }
    }
  }
  function yourBar() {
    var bar = $('dbf-you'); if (!bar) return;
    var b = S.board, show = current() === 'classement' && S.loggedIn && !!b && !b.error;
    bar.hidden = !show; if (!show) return;
    var you = b.you, badge = $('dbf-you-badge');
    badge.classList.toggle('has-rank', !!you);
    badge.replaceChildren(you ? d.createTextNode('#' + you.rank) : icon('flag'));
    if (you) {
      var msg = +you.rank === 1 ? txt('boardFirstText', 'Vous êtes à la première place. Continuez !')
        : you.gap > 0 ? txt('boardGapText', 'Vous êtes #{rank} — {gap} point(s) derrière la place #{ahead}').replace('{rank}', you.rank).replace('{gap}', you.gap).replace('{ahead}', you.ahead)
        : txt('boardRankText', 'Vous êtes à la place #{rank}').replace('{rank}', you.rank);
      text('dbf-you-text', msg);
    } else text('dbf-you-text', txt('boardStartText', 'Faites votre premier top-up pour entrer dans le classement !'));
  }
  function renderWinners(list) {
    var box = $('dbf-winners'); if (!box) return;
    box.replaceChildren(); box.hidden = !list || !list.length; if (box.hidden) return;
    box.appendChild(node('h2', 'dbf-h', 'Les derniers gagnants'));
    var ul = node('ul', 'dbf-win-list');
    list.forEach(function (x) {
      var li = node('li'); li.appendChild(icon('trophy'));
      var when = x.end ? new Date(x.end * 1000).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : '';
      li.appendChild(node('b', '', clean(x.name))); li.appendChild(node('span', 'dbf-muted', [when, x.prize ? diamonds(x.prize) : ''].filter(Boolean).join(' · ')));
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }

  /* ------------------------------------------------------------------ mes bonus */
  var BLOCKED = { max: 'Limite mensuelle atteinte', cap: 'Plafond mensuel atteint', dry: 'Programme en mode test', frozen: 'Bonus temporairement bloqués' };
  function blocked(st) { return BLOCKED[st.blocked] || ''; }
  function render(s) {
    if (!s || !s.tiers || typeof s.tiers !== 'object') throw new Error('Progression indisponible. Actualisez la page.');
    S.state = s;
    text('dbf-spend', num(s.monthSpend, 2) + ' ' + (clean(T.currency) || 'G'));
    text('dbf-total', diamonds(s.monthReward));
    if (s.resetAt) { S.monthLeft = Math.max(0, Number(s.resetAt) - Number(s.serverTime || Date.now() / 1000)) * 1000; S.monthAt = Date.now(); }
    var keys = Object.keys(tiers), ready = keys.filter(function (k) { return s.tiers[k] && s.tiers[k].claimable; });
    var next = ready[0] || keys.find(function (k) { return s.tiers[k] && !blocked(s.tiers[k]); }) || keys[0];
    if (s.frozen) notice('Vos bonus sont temporairement bloqués. Contactez le support.');
    else if (s.dry) notice('Programme en mode test : aucune récompense ne sera délivrée.');
    var chip = $('dbf-ready'); chip.hidden = !ready.length; chip.textContent = ready.length + (ready.length === 1 ? ' bonus prêt' : ' bonus prêts');
    var ring = $('dbf-ring');
    if (next) {
      var t = tiers[next], st = s.tiers[next] || {}, goal = Math.max(1, Number(st.goal || t.goal || 1)), progress = Number(st.progress || 0), owed = Math.max(0, Number(st.owed || 0));
      var pct = Math.min(100, Math.floor(progress / goal * 100));
      ring.style.setProperty('--p', pct); text('dbf-ring-pct', pct + ' %');
      text('dbf-next-title', clean(t.label) + ' · ' + diamonds(t.reward));
      text('dbf-next-msg', blocked(st) || (st.claimable ? 'Votre bonus est prêt. Réclamez votre PIN ci-dessous.' : 'Encore ' + num(Math.max(0, goal - progress) + owed) + ' achat(s) éligible(s) pour ce pack' + (owed ? ', dont ' + num(owed) + ' pour compenser un remboursement.' : '.')));
    } else { text('dbf-ring-pct', '—'); text('dbf-next-title', 'Aucun bonus actif'); text('dbf-next-msg', 'Revenez bientôt pour découvrir les prochains bonus.'); }
    var list = $('dbf-tiers'); list.replaceChildren(); list.setAttribute('aria-busy', 'false');
    if (!keys.length) list.appendChild(node('p', 'dbf-muted', 'Aucun bonus disponible pour le moment.'));
    keys.forEach(function (key, index) {
      var tier = tiers[key], st = s.tiers[key] || {}, goal = Math.max(1, Number(st.goal || tier.goal || 1)), progress = Number(st.progress || 0);
      var pct = Math.min(100, Math.floor(progress / goal * 100)), ok = !!st.claimable, why = blocked(st);
      var card = node('article', 'dbf-card dbf-tier dbf-t' + (index % 4) + (ok ? ' is-ready' : ''));
      var head = node('div', 'dbf-tier-head'), badge = node('span', 'dbf-tier-i'); badge.appendChild(icon('diamond'));
      var info = node('div', 'dbf-tier-info');
      info.appendChild(node('h3', '', clean(tier.title) || 'Free Fire'));
      info.appendChild(node('p', 'dbf-muted', clean(tier.sub)));
      var prize = node('div', 'dbf-tier-prize'); prize.appendChild(node('b', '', num(tier.reward))); prize.appendChild(node('small', '', 'diamants offerts'));
      head.appendChild(badge); head.appendChild(info); head.appendChild(prize); card.appendChild(head);
      var lab = node('div', 'dbf-tier-lab');
      lab.appendChild(node('span', 'dbf-tag', clean(tier.label))); lab.appendChild(node('span', '', num(Math.min(progress, goal)) + ' / ' + num(goal) + ' achats'));
      var bar = node('div', 'dbf-bar dbf-bar--lg'), fill = node('i'); fill.style.width = pct + '%'; bar.appendChild(fill);
      bar.setAttribute('role', 'progressbar'); bar.setAttribute('aria-label', 'Progression ' + clean(tier.label)); bar.setAttribute('aria-valuemin', '0'); bar.setAttribute('aria-valuemax', String(goal)); bar.setAttribute('aria-valuenow', String(Math.min(progress, goal)));
      var owed = Math.max(0, Number(st.owed || 0)), left = Math.max(0, goal - progress) + owed;
      var detail = why || (ok ? 'Objectif atteint : votre bonus vous attend.' : num(left) + ' achat(s) restant(s)' + (owed ? ', dont ' + num(owed) + ' pour compenser un remboursement.' : '.'));
      if (Number(st.claimed || 0)) detail += ' ' + num(st.claimed) + ' déjà réclamé(s) ce mois.';
      var btn = node('button', 'dbf-btn' + (ok ? ' dbf-btn--win' : ' dbf-btn--ghost'));
      btn.type = 'button'; btn.id = 'dbf-claim-' + key; btn.disabled = S.claiming || !ok;
      if (ok) btn.appendChild(icon('redeem'));
      btn.appendChild(d.createTextNode(ok ? 'Réclamer ' + diamonds(tier.reward) : (why || 'Continuez vos achats')));
      btn.addEventListener('click', function () { claim(key, btn); });
      card.appendChild(lab); card.appendChild(bar); card.appendChild(node('p', 'dbf-tier-detail', detail)); card.appendChild(btn);
      list.appendChild(card);
    });
    // A bonus that just became ready is a win too: celebrated once per tier and claim number.
    var month = s.resetAt ? String(s.resetAt) : 'm', seen = store('dbf_ready') || {}, fresh = [];
    if (seen.m !== month || !Array.isArray(seen.k)) seen = { m: month, k: [] };
    ready.forEach(function (k) { var id = k + ':' + (s.tiers[k].claimed || 0); if (seen.k.indexOf(id) < 0) { seen.k.push(id); fresh.push(k); } });
    if (fresh.length) {
      store('dbf_ready', seen);
      var f = tiers[fresh[0]];
      confetti(false);
      toast('Bonus débloqué : ' + clean(f.label) + ' · ' + diamonds(f.reward) + '. Réclamez-le !');
    }
  }
  function refreshState() {
    var seq = ++S.stateSeq;
    return api('/rewards').then(function (s) { if (seq === S.stateSeq && !S.claiming) render(s); });
  }
  function claim(key, button) {
    var s = S.state;
    if (S.claiming || !s || !s.tiers[key] || !s.tiers[key].claimable) return;
    var token = s.tiers[key].token;
    if (!token) { notice('Actualisez votre progression avant de réclamer.'); return; }
    S.claiming = true; S.stateSeq++; returnFocus = button.id;
    [].forEach.call(root.querySelectorAll('[id^="dbf-claim-"]'), function (b) { b.disabled = true; });
    button.replaceChildren(node('span', 'dbf-spin'), d.createTextNode('Préparation de votre récompense…')); button.setAttribute('aria-busy', 'true');
    api('/claim', { method: 'POST', body: JSON.stringify({ tier: key, token: token }) })
      .then(function (reply) {
        S.claiming = false; render(reply);
        confetti(true);
        if (reply.delivery) openDelivery(reply.delivery, true);
        else { notice(''); toast('Récompense enregistrée. Son PIN arrive dans « Mes PINs récompenses ».'); }
        S.page = 1; return loadHistory().catch(function () { notice('Récompense enregistrée. L’historique n’a pas pu être actualisé : touchez « Actualiser ».'); });
      }).catch(function (e) {
        S.claiming = false;
        var msg = e.name === 'AbortError' || e instanceof TypeError ? 'La réponse a été interrompue. Vérifiez « Mes PINs récompenses » avant de réclamer à nouveau.' : e.message;
        notice(msg);
        // Never repeat a POST after a timeout: reconcile from read-only state and history.
        Promise.all([refreshState(), loadHistory()]).then(function () { notice(msg); }).catch(function () {
          notice('Connexion interrompue. Rechargez la page pour vérifier votre récompense.');
          [].forEach.call(root.querySelectorAll('[id^="dbf-claim-"]'), function (b) { b.disabled = true; b.textContent = 'Rechargez pour vérifier'; });
        });
      });
  }
  function loadHistory() {
    S.historyAsked = true;
    var seq = ++S.historySeq, button = $('dbf-history-refresh'); if (button) button.disabled = true;
    return api('/reward-pins?page=' + S.page).then(function (r) {
      if (seq !== S.historySeq) return;
      var list = $('dbf-history'); list.replaceChildren();
      var items = r.items || [];
      if (!items.length) list.appendChild(node('p', 'dbf-muted', 'Vos récompenses réclamées apparaîtront ici.'));
      items.forEach(function (item) {
        var row = node('div', 'dbf-pin-item'), ic = node('span', 'dbf-tier-i dbf-tier-i--sm'); ic.appendChild(icon(item.status === 'ready' ? 'check_circle' : 'hourglass_top'));
        var body = node('div'), tier = tiers[item.tier];
        body.appendChild(node('b', '', item.amount ? diamonds(item.amount) + ' · ' + (tier ? clean(tier.label) : 'Bonus') : 'Récompense Free Fire'));
        var when = item.date ? new Date(item.date).toLocaleDateString('fr-FR') : '';
        var status = { ready: 'PIN disponible', pending: 'En préparation', partial: 'Livraison partielle', unavailable: 'Indisponible' }[item.status] || 'En préparation';
        body.appendChild(node('small', 'dbf-muted', [when, status].filter(Boolean).join(' · ')));
        var b = node('button', 'dbf-btn dbf-btn--ghost', item.status === 'ready' ? 'Voir mon PIN' : 'Vérifier'); b.type = 'button'; b.id = 'dbf-order-' + item.orderId;
        b.addEventListener('click', function () { returnFocus = b.id; openOrder(item.orderId); });
        row.appendChild(ic); row.appendChild(body); row.appendChild(b); list.appendChild(row);
      });
      $('dbf-pages').hidden = (r.pages || 1) <= 1;
      $('dbf-prev').disabled = S.page <= 1; $('dbf-next').disabled = S.page >= (r.pages || 1);
      text('dbf-page', S.page + ' / ' + Math.max(1, r.pages || 1));
    }).finally(function () { if (seq === S.historySeq && button) button.disabled = false; });
  }

  /* ------------------------------------------------------------------ PIN dialog */
  function clearSecrets() { S.activeOrder = 0; S.pinSeq++; clearTimeout(S.pinTimer); $('dbf-pins').replaceChildren(); $('dbf-pin-refresh').disabled = false; }
  function showDialog(win) { $('dbf-win').hidden = !win; if (!dialog.open) dialog.showModal(); }
  function openOrder(id) {
    clearSecrets(); S.activeOrder = Number(id); S.pinChecks = 0;
    text('dbf-dialog-title', 'Votre récompense'); text('dbf-dialog-msg', 'Chargement de votre PIN…');
    $('dbf-redeem').hidden = true; $('dbf-pin-refresh').hidden = false;
    showDialog(false); fetchDelivery();
  }
  function openDelivery(delivery, win) { clearSecrets(); S.activeOrder = Number(delivery.orderId); S.pinChecks = 0; showDialog(win); renderDelivery(delivery, win); }
  function renderDelivery(r, win) {
    if (!S.activeOrder || S.activeOrder !== Number(r.orderId) || !dialog.open) return;
    text('dbf-dialog-title', win ? 'Bravo ! ' + (r.amount ? diamonds(r.amount) + ' gagnés' : 'Récompense gagnée') : (r.amount ? diamonds(r.amount) : 'Votre récompense'));
    text('dbf-dialog-msg', r.message);
    var list = $('dbf-pins'); list.replaceChildren();
    (r.pins || []).forEach(function (pin, i) {
      var row = node('div', 'dbf-pin'), lab = node('label', '', pin.label || 'PIN ' + (i + 1)), ctl = node('div', 'dbf-pin-ctl'), input = node('input');
      input.id = 'dbf-code-' + i; input.value = pin.code; input.readOnly = true; input.autocomplete = 'off'; input.spellcheck = false;
      input.setAttribute('aria-label', 'PIN ' + (i + 1)); lab.htmlFor = input.id;
      input.addEventListener('click', function () { input.select(); });
      var copy = node('button', 'dbf-btn dbf-btn--ghost'); copy.type = 'button'; copy.appendChild(icon('content_copy')); copy.appendChild(d.createTextNode('Copier'));
      copy.addEventListener('click', function () {
        function copied() { copy.replaceChildren(icon('check_circle'), d.createTextNode('Copié')); text('dbf-dialog-msg', 'PIN copié. Touchez « Utiliser mon PIN », puis collez votre code sur la page de validation.'); }
        function fallback() {
          input.focus(); input.select(); input.setSelectionRange(0, input.value.length);
          try { if (d.execCommand('copy')) { copied(); return; } } catch (_) { /* old browsers */ }
          text('dbf-dialog-msg', 'PIN sélectionné. Appuyez longuement puis choisissez « Copier ».');
        }
        if (navigator.clipboard && w.isSecureContext) navigator.clipboard.writeText(input.value).then(copied).catch(fallback); else fallback();
      });
      ctl.appendChild(input); ctl.appendChild(copy); row.appendChild(lab); row.appendChild(ctl); list.appendChild(row);
    });
    $('dbf-redeem').hidden = !(r.pins && r.pins.length);
    $('dbf-pin-refresh').hidden = r.status === 'ready' || r.status === 'unavailable';
    clearTimeout(S.pinTimer);
    if ((r.status === 'pending' || r.status === 'partial') && S.pinChecks < 12) S.pinTimer = setTimeout(function () { if (dialog.open && !d.hidden) fetchDelivery(); }, 5000);
  }
  function fetchDelivery() {
    if (!S.activeOrder) return;
    var id = S.activeOrder, seq = ++S.pinSeq, b = $('dbf-pin-refresh'); clearTimeout(S.pinTimer); S.pinChecks++; b.disabled = true;
    return api('/reward-pins?order_id=' + id).then(function (r) { if (seq === S.pinSeq && id === S.activeOrder) renderDelivery(r, !$('dbf-win').hidden); })
      .catch(function (e) { if (seq === S.pinSeq && dialog.open) text('dbf-dialog-msg', e.name === 'AbortError' ? 'Délai dépassé. Actualisez votre PIN ici.' : e.message); })
      .finally(function () { if (seq === S.pinSeq) b.disabled = false; });
  }
  dialog.addEventListener('close', function () {
    clearSecrets(); $('dbf-toast').hidden = true;
    if (returnFocus && $(returnFocus)) $(returnFocus).focus({ preventScroll: true });
  });
  $('dbf-dialog-close').addEventListener('click', function () { dialog.close(); });
  $('dbf-pin-refresh').addEventListener('click', function () { S.pinChecks = 0; fetchDelivery(); });

  /* ------------------------------------------------------------------ boot, clocks, refresh */
  function boot() {
    if (S.booting) return S.booting;
    var spin = $('dbf-refresh'); spin.classList.add('is-busy'); spin.disabled = true;
    // First load: the request the page started early (inline, before this script); afterwards a fresh one.
    var early = w.DBF_BOOT; w.DBF_BOOT = null;
    var request = early ? early.then(function (x) {
      if (!x || !x.ok || !x.j) throw new Error(x && x.j && x.j.message || 'Service indisponible. Réessayez.');
      return x.j;
    }, function () { return post({ action: 'delicat_bonus_boot' }); }) : post({ action: 'delicat_bonus_boot' });
    S.booting = request.then(function (b) {
      if (S.userId && +b.userId !== +S.userId) { clearSecrets(); if (dialog.open) dialog.close(); }
      S.loggedIn = !!b.loggedIn; S.userId = +b.userId || 0; S.nonce = b.nonce || '';
      S.skew = (+b.serverNow || 0) * 1000 - Date.now(); S.nextReset = +b.nextReset || 0;
      root.classList.toggle('is-guest', !S.loggedIn);
      if ($('dbf-panel-classement')) { renderBoard(b.board || null); renderWinners(b.winners); }
      var bonus = $('dbf-panel-bonus');
      if (bonus) {
        var gate = bonus.querySelector('.dbf-login');
        if (!S.loggedIn) { if (!gate) bonus.insertBefore(login(), bonus.firstChild); }
        else {
          if (gate) gate.remove();
          if (b.rewards && !b.rewards.error) render(b.rewards); else if (b.rewards) notice(b.rewards.error);
          if (current() === 'bonus' || S.historyAsked) loadHistory().catch(function (e) { notice(e.message); });
        }
      }
      tick();
    }).catch(function (e) { notice(e.message || 'Chargement impossible. Touchez Actualiser.'); })
      .finally(function () { S.booting = null; spin.classList.remove('is-busy'); spin.disabled = false; });
    return S.booting;
  }
  var last = {};
  function setOnce(id, v) { if (last[id] !== v) { last[id] = v; text(id, v); } }
  function tick() {
    if (S.nextReset) {
      var left = S.nextReset * 1000 - (Date.now() + S.skew);
      if (left <= 0) { S.nextReset = 0; boot(); } else setOnce('dbf-board-clock', span(left));
    }
    if (S.monthAt) {
      var m = S.monthLeft - (Date.now() - S.monthAt);
      setOnce('dbf-month-clock', m > 0 ? 'Nouveau mois dans ' + span(m) : 'Nouveau mois : actualisez votre progression.');
    }
  }
  function every() { if (!root.isConnected) { clearInterval(clock); return; } if (!d.hidden) tick(); } // stops once the page is gone
  var clock = setInterval(every, 1000);
  $('dbf-refresh').addEventListener('click', function () { notice(''); boot(); });
  if ($('dbf-history-refresh')) {
    $('dbf-history-refresh').addEventListener('click', function () { Promise.all([loadHistory(), refreshState()]).catch(function (e) { notice(e.message); }); });
    $('dbf-prev').addEventListener('click', function () { if (S.page > 1) S.page--; loadHistory().catch(function (e) { notice(e.message); }); });
    $('dbf-next').addEventListener('click', function () { S.page++; loadHistory().catch(function (e) { notice(e.message); }); });
  }
  w.addEventListener('pagehide', function () { S.suspended = true; clearSecrets(); clearTimeout(toastTimer); clearInterval(clock); if (dialog.open) dialog.close(); });
  w.addEventListener('pageshow', function (e) { if (e.persisted && root.isConnected) { S.suspended = false; clearInterval(clock); clock = setInterval(every, 1000); boot(); } });
  d.addEventListener('visibilitychange', function () { if (d.hidden) clearTimeout(S.pinTimer); else if (dialog.open && S.activeOrder) fetchDelivery(); });

  fillIcons(root); fillIcons(dialog);
  boot();
  }
  function scan() { var r = d.getElementById('dbf'); if (r && !r.dataset.ready) start(r); }
  scan();
  ['delicat:shell:navigated', 'delicat:navigation-complete', 'dsb:content-updated'].forEach(function (name) {
    d.addEventListener(name, scan); w.addEventListener(name, scan);
  });
})(window, document);
