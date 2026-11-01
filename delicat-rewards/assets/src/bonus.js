/* Delicat Rewards 3.4.0 — Bonus Free Fire page: « Classement » (Meilleur Client), « Mes bonus » and
 * « Parrainage » (invite a friend) on one page. One request (delicat_bonus_boot) brings the session and both tabs' data; claims keep
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
  var tabs = [].slice.call(root.querySelectorAll('[role="tab"]')), HASH = { bonus: 'mes-bonus', classement: 'classement', parrainage: 'parrainage' };
  function current() { return root.getAttribute('data-tab') || 'classement'; }
  function select(name, focus) {
    if (!$('dbf-panel-' + name)) return;
    tabs.forEach(function (t) { var on = t.id === 'dbf-tab-' + name; t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1; if (on && focus) t.focus(); });
    ['classement', 'bonus', 'parrainage'].forEach(function (n) { var p = $('dbf-panel-' + n); if (p) p.hidden = n !== name; });
    root.setAttribute('data-tab', name);
    if (tabs.length) try { history.replaceState(history.state, '', '#' + (HASH[name] || name)); } catch (e) { /* sandboxed */ }
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
  if (/^#mes-bonus\b/.test(location.hash)) select('bonus'); else if (/^#classement\b/.test(location.hash)) select('classement'); else if (/^#parrainage\b/.test(location.hash)) select('parrainage');

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

  /* ------------------------------------------------------------------ parrainage */
  // One point per invited friend whose first real purchase stays paid; milestones are claimed once, as a
  // PIN (diamonds) or store credit (HTG). The server decides everything; this only draws and asks.
  var BRANDS = __BRANDS__; // share logos, 24 x 24
  var R = { state: null, claiming: false, seq: 0, fp: null, invite: null, prefill: '', armed: '', armTimer: 0, visited: false, seen: false };
  var CODE_RE = /^DLC[A-HJ-NP-Z2-9]{6}$/;
  var FRIEND_ICON = { signed_up: 'schedule', qualified: 'hourglass_top', review: 'info', confirmed: 'verified', rejected: 'block' };
  function brand(name) {
    var svg = icon(''); svg.setAttribute('viewBox', '0 0 24 24'); svg.firstChild.setAttribute('d', BRANDS[name] || ''); return svg;
  }
  function refCode(v) { return String(v || '').toUpperCase().replace(/[^A-Z0-9]/g, ''); }
  function plural(n, one, many) { return num(n) + ' ' + (Math.abs(n) > 1 ? many : one); }
  function prizeText(m) { return [m.diamonds ? diamonds(m.diamonds) : '', m.htg ? num(m.htg) + ' HTG' : ''].filter(Boolean).join(' ou '); }
  /* A summary of this browser (no canvas, nothing stored): the server keeps only a keyed hash of it,
     to see one phone opening several accounts. */
  function fingerprint() {
    if (R.fp) return R.fp;
    var n = navigator, sc = w.screen || {}, tz = '', sw = sc.width || 0, sh = sc.height || 0;
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { /* old browsers */ }
    var raw = [n.userAgent, n.platform || '', [].concat(n.languages || n.language || []).join(','), tz,
      Math.min(sw, sh) + 'x' + Math.max(sw, sh) + 'x' + (sc.colorDepth || 0), w.devicePixelRatio || 1,
      n.hardwareConcurrency || 0, n.deviceMemory || 0, n.maxTouchPoints || 0].join('|');
    var subtle = w.crypto && w.crypto.subtle;
    R.fp = subtle && w.TextEncoder ? subtle.digest('SHA-256', new TextEncoder().encode(raw)).then(function (buf) {
      return [].map.call(new Uint8Array(buf), function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
    }).catch(function () { return ''; }) : Promise.resolve('');
    return R.fp;
  }
  function copyText(value) {
    function legacy() {
      var ta = node('textarea'); ta.value = value; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0';
      d.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, value.length);
      var ok = false; try { ok = d.execCommand('copy'); } catch (e) { /* not allowed */ }
      ta.remove(); return ok;
    }
    if (navigator.clipboard && w.isSecureContext) return navigator.clipboard.writeText(value).then(function () { return true; }, legacy);
    return Promise.resolve(legacy());
  }
  function copyButton(id, label, value) {
    var b = node('button', 'dbf-btn dbf-btn--ghost dbf-copy'); b.type = 'button'; b.id = id;
    b.appendChild(icon('content_copy')); b.appendChild(node('span', '', label));
    var timer = 0;
    b.addEventListener('click', function () {
      copyText(value).then(function (ok) {
        if (!ok) { toast('Appuyez longuement sur le texte pour le copier.'); return; }
        clearTimeout(timer); b.replaceChildren(icon('check_circle'), node('span', '', 'Copié'));
        timer = setTimeout(function () { b.replaceChildren(icon('content_copy'), node('span', '', label)); }, 2200);
      });
    });
    return b;
  }

  /* hero: points, bar, milestone marks */
  function refHero(s) {
    var pts = s ? Number(s.points || 0) : 0, max = Math.max(1, Number(s && s.max || 100)), miles = s && s.milestones || [];
    text('dbf-ref-points', s ? num(pts) : '—');
    var bar = $('dbf-ref-bar'), meter = $('dbf-ref-meter'), track = $('dbf-ref-track');
    if (bar) bar.style.width = Math.min(100, pts / max * 100) + '%';
    if (meter) meter.setAttribute('aria-valuenow', String(pts));
    if (track) {
      track.replaceChildren();
      // A number too close to the next one (or to the end) shows as a dot only, so labels never overlap.
      var at = miles.map(function (m) { return Math.min(100, m.points / max * 100); }), shown = -100;
      miles.forEach(function (m, i) {
        var end = m.points >= max, tight = !end && (at[i] - shown < 9 || (at[at.length - 1] >= 100 && 100 - at[i] < 9));
        if (!tight) shown = at[i];
        var mark = node('span', 'dbf-ref-mark' + (pts >= m.points ? ' is-on' : '') + (end ? ' is-end' : '') + (tight ? ' is-tight' : ''), m.points);
        mark.style.left = at[i] + '%'; track.appendChild(mark);
      });
    }
    var ready = miles.filter(function (m) { return m.status === 'claimable'; }).length;
    var next = miles.filter(function (m) { return m.points > pts; })[0];
    text('dbf-ref-next', !s ? 'Connectez-vous pour obtenir votre code et votre lien personnels.'
      : s.blocked ? 'Votre accès au parrainage est suspendu.'
      : ready ? (ready === 1 ? 'Une récompense vous attend ci-dessous.' : ready + ' récompenses vous attendent ci-dessous.')
      : next ? 'Encore ' + plural(next.points - pts, 'point', 'points') + ' pour le palier ' + next.points + ' : ' + prizeText(next) + '.'
      : 'Tous les paliers sont atteints. Merci pour vos invitations !');
  }
  function refBody() { var b = $('dbf-ref-body'); if (b) { b.replaceChildren(); b.setAttribute('aria-busy', 'false'); } return b; }
  function refError(msg) { var b = refBody(); if (b) b.appendChild(node('p', 'dbf-muted', msg)); }

  /* signed out: sign-in card, or the friend's invitation */
  function inviteCard(inv) {
    var c = $('dbf-invite').cloneNode(true); c.removeAttribute('id'); c.hidden = false;
    c.querySelector('[data-ref="name"]').textContent = clean(inv.inviter) || 'Un ami';
    c.querySelector('[data-ref="initials"]').textContent = initials(inv.inviter || 'A');
    fillIcons(c); return c;
  }
  function refGuest() {
    var b = refBody(); if (!b) return;
    refHero(null);
    b.appendChild(R.invite && $('dbf-invite') ? inviteCard(R.invite) : login());
  }

  /* signed in */
  function renderRef(s) {
    var b = $('dbf-ref-body'); if (!b) return;
    if (!s || typeof s !== 'object') { refError('Parrainage indisponible. Touchez Actualiser.'); return; }
    if (s.enabled === false) { refHero(null); refBody().appendChild(node('p', 'dbf-empty', 'Le parrainage est fermé pour le moment.')); return; }
    if (!Array.isArray(s.milestones)) { refError('Parrainage indisponible. Touchez Actualiser.'); return; }
    R.state = s; disarm();
    refHero(s); refBody();
    if (s.invitedBy) b.appendChild(invitedNote(s.invitedBy));
    else if (s.canEnterCode) b.appendChild(codeForm());
    if (s.blocked) b.appendChild(blockedCard());
    else if (s.code && /^https?:\/\//.test(s.link || '')) b.appendChild(shareCard(s));
    b.appendChild(milestoneList(s));
    b.appendChild(friendList(s));
    celebrateRef(s);
  }
  function invitedNote(by) {
    var p = node('p', 'dbf-card dbf-ref-note'); p.appendChild(icon('group_add'));
    var waiting = by.status === 'signed_up' || by.status === 'qualified';
    p.appendChild(node('span', '', 'Vous avez été invité par ' + (clean(by.name) || 'un ami') + '.' + (waiting ? ' Votre premier achat lui offre un point.' : '')));
    return p;
  }
  function blockedCard() {
    var c = node('div', 'dbf-card dbf-ref-blocked'); c.setAttribute('role', 'status');
    var i = node('span', 'dbf-tier-i'); i.appendChild(icon('block')); c.appendChild(i);
    var t = node('div'); t.appendChild(node('b', '', 'Accès au parrainage suspendu'));
    t.appendChild(node('p', 'dbf-muted', 'Un même téléphone, numéro ou adresse est utilisé par plusieurs comptes, ou les règles du programme n’ont pas été respectées. Contactez le support si vous pensez à une erreur.'));
    c.appendChild(t); return c;
  }
  function codeForm() {
    var f = node('form', 'dbf-card dbf-codeform'); f.noValidate = true;
    f.appendChild(node('h3', '', 'Un ami vous a invité ?'));
    f.appendChild(node('p', 'dbf-muted', 'Entrez son code avant votre premier achat : il gagne un point quand votre achat est validé.'));
    var row = node('div', 'dbf-code-row'), input = node('input'), btn = node('button', 'dbf-btn', 'Valider'), err = node('p', 'dbf-field-err');
    input.id = 'dbf-ref-code-in'; input.name = 'code'; input.autocomplete = 'off'; input.spellcheck = false; input.maxLength = 12;
    input.setAttribute('autocapitalize', 'characters'); input.setAttribute('aria-label', 'Code de parrainage'); input.placeholder = 'DLC…';
    input.value = R.prefill || ''; err.id = 'dbf-ref-code-err'; err.hidden = true; err.setAttribute('role', 'alert'); input.setAttribute('aria-describedby', err.id);
    btn.type = 'submit'; btn.id = 'dbf-ref-code-btn';
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var code = refCode(input.value); err.hidden = true;
      if (!CODE_RE.test(code)) { err.textContent = 'Le code commence par DLC et compte 9 caractères.'; err.hidden = false; input.focus(); return; }
      btn.disabled = true; btn.replaceChildren(node('span', 'dbf-spin'), d.createTextNode('Vérification…'));
      fingerprint().then(function (fp) { return api('/referral/code', { method: 'POST', body: JSON.stringify({ code: code, fp: fp }) }); })
        .then(function (st) {
          R.prefill = ''; renderRef(st);
          toast('Code accepté. Votre premier achat offre un point à ' + (st.invitedBy && clean(st.invitedBy.name) || 'votre ami') + '.');
        }).catch(function (e2) {
          err.textContent = e2.message || 'Code refusé.'; err.hidden = false;
          btn.disabled = false; btn.textContent = 'Valider';
        });
    });
    row.appendChild(input); row.appendChild(btn); f.appendChild(row); f.appendChild(err);
    return f;
  }
  function shareCard(s) {
    var card = node('section', 'dbf-card dbf-share'); card.setAttribute('aria-label', 'Votre invitation');
    var top = node('div', 'dbf-share-top'), code = node('div', 'dbf-code');
    code.appendChild(node('small', '', 'Votre code')); code.appendChild(node('b', '', s.code));
    top.appendChild(code); top.appendChild(copyButton('dbf-ref-copy-code', 'Copier', s.code)); card.appendChild(top);
    var link = node('div', 'dbf-link'), input = node('input');
    input.id = 'dbf-ref-link'; input.value = s.link; input.readOnly = true; input.spellcheck = false; input.setAttribute('aria-label', 'Votre lien personnel');
    input.addEventListener('click', function () { input.select(); });
    link.appendChild(icon('link')); link.appendChild(input); link.appendChild(copyButton('dbf-ref-copy-link', 'Copier le lien', s.link));
    card.appendChild(link);
    card.appendChild(node('p', 'dbf-share-l', 'Partager mon invitation'));
    var grid = node('div', 'dbf-share-grid'), msg = clean(s.shareText) || s.link;
    var bare = msg.split(s.link).join('').replace(/\s{2,}/g, ' ').replace(/\s*:\s*\(/, ' (').trim(), e = encodeURIComponent;
    [
      { k: 'whatsapp', label: 'Partager sur WhatsApp', href: 'https://wa.me/?text=' + e(msg) },
      { k: 'facebook', label: 'Facebook', href: 'https://www.facebook.com/sharer/sharer.php?u=' + e(s.link) },
      { k: 'telegram', label: 'Telegram', href: 'https://t.me/share/url?url=' + e(s.link) + '&text=' + e(bare) },
      { k: 'sms', label: 'SMS', href: 'sms:?&body=' + e(msg) }
    ].forEach(function (x) {
      var a = node('a', 'dbf-sh dbf-sh--' + x.k); a.href = x.href; a.setAttribute('data-no-shell', '');
      if (x.k !== 'sms') { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
      if (x.k !== 'whatsapp') a.setAttribute('aria-label', 'Partager sur ' + x.label);
      a.appendChild(x.k === 'sms' ? icon('sms') : brand(x.k)); a.appendChild(node('span', '', x.label));
      grid.appendChild(a);
    });
    if (navigator.share) {
      var native = node('button', 'dbf-sh dbf-sh--more'); native.type = 'button'; native.setAttribute('aria-label', 'Autres applications'); native.id = 'dbf-ref-share';
      native.appendChild(icon('share')); native.appendChild(node('span', '', 'Plus…'));
      native.addEventListener('click', function () {
        navigator.share({ title: 'Delicat Store', text: bare, url: s.link }).catch(function (err) { if (err && err.name !== 'AbortError') toast('Partage impossible ici : copiez votre lien.'); });
      });
      grid.appendChild(native);
    }
    card.appendChild(grid);
    return card;
  }
  function milestoneList(s) {
    var wrap = node('section', 'dbf-ref-miles'), pts = Number(s.points || 0), list = node('ol', 'dbf-miles');
    wrap.appendChild(node('h2', 'dbf-h', 'Vos récompenses'));
    if (!s.milestones.length) list.appendChild(node('li', 'dbf-muted', 'Aucun palier pour le moment.'));
    s.milestones.forEach(function (m) {
      var li = node('li', 'dbf-card dbf-mile is-' + m.status), badge = node('span', 'dbf-mile-pts'), info = node('div', 'dbf-mile-info');
      badge.appendChild(node('b', '', m.points)); badge.appendChild(node('small', '', m.points > 1 ? 'points' : 'point'));
      info.appendChild(node('h3', '', prizeText(m)));
      var c = m.claimed || {}, line;
      if (m.status === 'claimed') line = c.choice === 'htg' ? num(c.amount) + ' HTG ajoutés à votre portefeuille' : diamonds(c.amount) + ' reçus';
      else if (m.status === 'pending') line = 'Réclamation en cours…';
      else if (m.status === 'claimable') line = 'Palier atteint : choisissez votre récompense.';
      else line = s.blocked ? 'Indisponible' : 'Encore ' + plural(Math.max(0, m.points - pts), 'point', 'points');
      var p = node('p', 'dbf-mile-line'); if (m.status === 'claimed') p.appendChild(icon('check_circle')); p.appendChild(d.createTextNode(line)); info.appendChild(p);
      if (m.status === 'locked' && !s.blocked) {
        var bar = node('span', 'dbf-bar'), fill = node('i'); fill.style.width = Math.min(100, pts / m.points * 100) + '%'; bar.appendChild(fill); info.appendChild(bar);
      }
      li.appendChild(badge); li.appendChild(info);
      if (m.status === 'claimable') {
        var acts = node('div', 'dbf-mile-acts');
        if (m.diamonds) acts.appendChild(choiceButton(m, 'diamonds'));
        if (m.htg && s.wallet) acts.appendChild(choiceButton(m, 'htg'));
        acts.appendChild(node('small', 'dbf-muted', 'Touchez votre choix puis confirmez. Le choix est définitif.'));
        li.appendChild(acts);
      } else if (m.status === 'claimed' && c.choice === 'diamonds' && c.order > 0) {
        var see = node('button', 'dbf-btn dbf-btn--ghost dbf-mile-see', 'Voir mon PIN'); see.type = 'button'; see.id = 'dbf-ref-pin-' + m.points;
        see.addEventListener('click', function () { returnFocus = see.id; openOrder(c.order); });
        li.appendChild(see);
      }
      list.appendChild(li);
    });
    wrap.appendChild(list);
    return wrap;
  }
  function choiceButton(m, choice) {
    var label = choice === 'htg' ? num(m.htg) + ' HTG' : diamonds(m.diamonds);
    var b = node('button', 'dbf-btn dbf-choice dbf-choice--' + choice); b.type = 'button'; b.id = 'dbf-ref-claim-' + m.points + '-' + choice;
    b.setAttribute('data-label', label); b.disabled = R.claiming;
    b.appendChild(icon(choice === 'htg' ? 'account_balance_wallet' : 'diamond')); b.appendChild(node('span', '', label));
    b.addEventListener('click', function () { if (R.armed === b.id) refClaim(m.points, choice, b); else arm(b); });
    return b;
  }
  function arm(b) {
    disarm(); R.armed = b.id; b.classList.add('is-armed'); b.lastChild.textContent = 'Confirmer : ' + b.getAttribute('data-label');
    R.armTimer = setTimeout(disarm, 6000);
  }
  function disarm() {
    clearTimeout(R.armTimer);
    var b = R.armed ? $(R.armed) : null; R.armed = '';
    if (b && !R.claiming) { b.classList.remove('is-armed'); b.lastChild.textContent = b.getAttribute('data-label'); }
  }
  function friendList(s) {
    var wrap = node('section', 'dbf-ref-friends'), cn = s.counts || {}, friends = s.friends || [];
    var head = node('div', 'dbf-row-h'); head.appendChild(node('h2', 'dbf-h', 'Vos invitations'));
    var going = (cn.signed_up || 0) + (cn.qualified || 0) + (cn.review || 0);
    if (going) head.appendChild(node('span', 'dbf-chip dbf-chip--info', plural(going, 'en cours', 'en cours')));
    wrap.appendChild(head);
    if (!friends.length) { wrap.appendChild(node('p', 'dbf-card dbf-empty', 'Personne pour l’instant. Partagez votre lien : chaque ami qui fait son premier achat vous rapporte un point.')); return wrap; }
    var ul = node('ul', 'dbf-friends'), FIRST = 8;
    friends.forEach(function (f, i) {
      var li = node('li', 'dbf-friend'); li.hidden = i >= FIRST;
      var mid = node('div', 'dbf-friend-mid'), when = f.date ? new Date(f.date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : '';
      li.appendChild(node('span', 'dbf-av dbf-av--sm', initials(f.name)));
      mid.appendChild(node('b', '', clean(f.name) || 'Ami'));
      mid.appendChild(node('small', 'dbf-muted', [when, f.why].filter(Boolean).join(' · ')));
      var chip = node('span', 'dbf-st dbf-st--' + (FRIEND_ICON[f.status] ? f.status : 'signed_up')); chip.appendChild(icon(FRIEND_ICON[f.status] || 'schedule')); chip.appendChild(node('span', '', clean(f.label)));
      li.appendChild(mid); li.appendChild(chip); ul.appendChild(li);
    });
    wrap.appendChild(ul);
    if (friends.length > FIRST) { // the latest first; the rest on request
      var more = node('button', 'dbf-btn dbf-btn--ghost dbf-more', 'Voir les ' + (friends.length - FIRST) + ' autres'); more.type = 'button'; more.id = 'dbf-ref-more';
      more.addEventListener('click', function () { [].forEach.call(ul.children, function (li) { li.hidden = false; }); more.remove(); var next = ul.children[FIRST]; next.tabIndex = -1; next.focus(); });
      wrap.appendChild(more);
    }
    return wrap;
  }
  /* New points and newly reached milestones are celebrated once on this phone. */
  function celebrateRef(s) {
    var pts = Number(s.points || 0), ready = s.milestones.filter(function (m) { return m.status === 'claimable'; }).map(function (m) { return m.points; });
    var prev = store('dbf_ref'); store('dbf_ref', { u: S.userId, p: pts, r: ready });
    if (prev && prev.u !== S.userId) prev = null;
    var fresh = ready.filter(function (p) { return !prev || (prev.r || []).indexOf(p) < 0; });
    if (fresh.length) { confetti(false); toast('Palier ' + fresh[0] + ' atteint : réclamez votre récompense !'); }
    else if (prev && pts > prev.p) { confetti(false); toast('+' + plural(pts - prev.p, 'point', 'points') + ' de parrainage. Merci pour vos invitations !'); }
  }
  function refreshRef() {
    var seq = ++R.seq;
    return api('/referral').then(function (st) { if (seq === R.seq && !R.claiming) renderRef(st); });
  }
  function openNote(title, msg) {
    clearSecrets(); showDialog(true);
    text('dbf-dialog-title', title); text('dbf-dialog-msg', msg);
    $('dbf-redeem').hidden = true; $('dbf-pin-refresh').hidden = true;
  }
  function refClaim(points, choice, button) {
    if (R.claiming) return;
    clearTimeout(R.armTimer); R.armed = ''; R.claiming = true; R.seq++; returnFocus = button.id;
    [].forEach.call(root.querySelectorAll('[id^="dbf-ref-claim-"]'), function (x) { x.disabled = true; });
    button.replaceChildren(node('span', 'dbf-spin'), d.createTextNode('Envoi de votre récompense…')); button.setAttribute('aria-busy', 'true');
    api('/referral/claim', { method: 'POST', body: JSON.stringify({ milestone: points, choice: choice }) })
      .then(function (reply) {
        R.claiming = false; renderRef(reply); notice('');
        var c = reply.claimed || {};
        confetti(true);
        if (c.choice === 'htg') openNote('Bravo ! ' + num(c.amount) + ' HTG gagnés', 'Le montant est ajouté à votre portefeuille Delicat : utilisez-le pour vos prochains achats.');
        else if (c.delivery) openDelivery(c.delivery, true);
        else openNote('Bravo ! ' + diamonds(c.amount) + ' gagnés', 'Votre PIN est en préparation : touchez « Voir mon PIN » dans quelques instants.');
        if (S.historyAsked) { S.page = 1; loadHistory().catch(function () { /* the list refreshes with the next visit */ }); }
      }).catch(function (e) {
        R.claiming = false;
        var msg = e.name === 'AbortError' || e instanceof TypeError ? 'La réponse a été interrompue. Vérifiez vos récompenses avant de réclamer à nouveau.' : e.message;
        notice(msg);
        // Never repeat the POST: the state shows whether the reward was given.
        refreshRef().catch(function () { notice('Connexion interrompue. Rechargez la page pour vérifier votre récompense.'); });
      });
  }
  /* Arrival through a personal link: the browser remembers the inviter (signed cookie, server side). */
  function visit() {
    var code = '';
    try { code = refCode(new URLSearchParams(location.search).get('ref')); } catch (e) { /* old browsers */ }
    if (!code) return;
    select('parrainage');
    function drop() {
      try { var u = new URL(location.href); u.searchParams.delete('ref'); history.replaceState(history.state, '', u.pathname + u.search + u.hash); } catch (e) { /* sandboxed */ }
    }
    if (!CODE_RE.test(code)) { drop(); notice('Ce lien d’invitation n’est pas valable.'); return; }
    fingerprint().then(function (fp) { return post({ action: 'delicat_ref_visit', code: code, fp: fp }); }).then(function (j) {
      drop();
      if (!j.ok) {
        notice(j.message || (j.code === 'invalid' ? 'Ce lien d’invitation n’est plus valable.' : j.code === 'off' ? 'Le parrainage est fermé pour le moment.' : 'Invitation indisponible. Réessayez plus tard.'));
        return;
      }
      R.invite = { code: code, inviter: j.inviter };
      if (!j.signedIn) refGuest();
      else if (R.state && R.state.canEnterCode && !R.state.invitedBy && R.state.code !== code) {
        R.prefill = code; renderRef(R.state); toast('Code de ' + (clean(j.inviter) || 'votre ami') + ' prêt : touchez Valider.');
      }
    }).catch(function (e) { notice(e.message); });
  }
  /* The signed-in page reports this browser a few times an hour at most (duplicate-account checks). */
  function seen() {
    if (R.seen || !S.loggedIn || !S.nonce) return;
    R.seen = true;
    var last = store('dbf_ref_seen');
    if (last && last.u === S.userId && Date.now() - last.t < 1800000) return;
    fingerprint().then(function (fp) {
      if (!fp) return;
      store('dbf_ref_seen', { u: S.userId, t: Date.now() });
      return post({ action: 'delicat_ref_seen', nonce: S.nonce, fp: fp });
    }).catch(function () { /* best effort */ });
  }

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
      if ($('dbf-panel-parrainage')) {
        if (!S.loggedIn) { R.state = null; refGuest(); }
        else if (b.referral && b.referral.error) refError(b.referral.error);
        else if (b.referral) renderRef(b.referral);
        seen();
      }
      tick();
    }).catch(function (e) { notice(e.message || 'Chargement impossible. Touchez Actualiser.'); })
      .finally(function () {
        S.booting = null; spin.classList.remove('is-busy'); spin.disabled = false;
        // A friend's link is honoured even if the page's data could not load (busy server, slow network).
        if ($('dbf-panel-parrainage') && !R.visited) { R.visited = true; visit(); }
      });
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
