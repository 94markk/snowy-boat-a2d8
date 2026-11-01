/*!
 * Delicat Assistant Pro — Community Groups runtime.
 *
 * Mounts itself into the existing assistant shell without editing frontend.js: it appends
 * one nav button and one stage panel, and gets out of the way when the customer taps any
 * of the original tabs. Nothing here writes user text with innerHTML — every value coming
 * from the server goes through textContent, so a message can never become markup.
 */
(() => {
 const CFG = window.DAP_GROUPS || {};
 if (!CFG.ajax || !CFG.nonce) return;

 const T = {
  title: 'Groupes', rooms: 'Salons de la communauté', join: 'Rejoindre', joined: 'Membre',
  leave: 'Quitter le groupe', members: 'membres', empty: 'Aucun salon pour le moment.',
  emptyRoom: 'Aucun message. Lancez la conversation.', placeholder: 'Écrivez au groupe…',
  send: 'Envoyer', back: 'Retour', rules: 'Règles du groupe', accept: 'Rejoindre le groupe',
  readOnly: 'Lecture seule', slow: 'Mode lent', staff: 'Équipe', mod: 'Modérateur',
  report: 'Signaler', reported: 'Signalé. Merci.', reportAsk: 'Signaler ce message à la modération ?',
  removed: 'Message retiré', rename: 'Changer mon nom', renameAsk: 'Nom affiché dans les groupes (2 à 24 caractères)',
  filtered: 'Message publié : certaines informations ont été masquées pour votre sécurité.',
  loading: 'Chargement…', failed: 'Connexion indisponible.', retry: 'Réessayer',
  reload: 'Recharger la page', you: 'Vous', banned: 'Votre accès aux groupes est suspendu.',
  image: 'Image', modDelete: 'Supprimer', modPin: 'Épingler', modMute: 'Sourdine 1 h', modBan: 'Bannir',
  confirmBan: 'Bannir ce client de tous les groupes ?', pinned: 'Épinglé',
  safety: 'Les numéros de paiement, codes PIN et liens externes sont retirés automatiquement. L’équipe Delicat ne demande jamais de paiement en privé.'
 };

 const esc = v => String(v == null ? '' : v);
 const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };

 async function api(action, data, file) {
  const body = new FormData();
  body.append('action', 'dsa2_groups_' + action);
  body.append('nonce', CFG.nonce);
  Object.keys(data || {}).forEach(k => body.append(k, data[k]));
  if (file) body.append('file', file);
  const res = await fetch(CFG.ajax, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  let json = null;
  try { json = await res.json(); } catch (e) { json = null; }
  if (!res.ok || !json || !json.success) {
   const err = new Error((json && json.data && json.data.message) || T.failed);
   err.code = json && json.data && json.data.code;
   err.status = res.status;
   throw err;
  }
  return json.data;
 }

 /* ------------------------------------------------------------------ instance */

 function createBoard(host, opts) {
  const options = opts || {};
  const board = el('div', 'dapg-board');
  const listView = el('div', 'dapg-list');
  const roomView = el('div', 'dapg-room');
  roomView.hidden = true;
  board.append(listView, roomView);
  host.appendChild(board);

  let groups = [], current = null, lastId = 0, timer = 0, sending = false, renderedDay = '';
  let visible = false;

  /* ---------------------------------------------------------------- list view */

  function renderList() {
   listView.replaceChildren();
   const head = el('div', 'dapg-head');
   head.append(el('strong', null, T.rooms));
   const rename = el('button', 'dapg-link');
   rename.type = 'button';
   rename.textContent = (CFG.displayName || T.you) + ' · ' + T.rename;
   rename.addEventListener('click', changeName);
   head.appendChild(rename);
   listView.appendChild(head);

   const safety = el('p', 'dapg-safety', T.safety);
   listView.appendChild(safety);

   if (CFG.banned) { listView.appendChild(el('p', 'dapg-empty', T.banned)); return; }
   if (!groups.length) { listView.appendChild(el('p', 'dapg-empty', T.empty)); return; }

   groups.forEach(g => {
    const card = el('button', 'dapg-card');
    card.type = 'button';
    const icon = el('span', 'dapg-card-icon', g.icon || '💬');
    const main = el('span', 'dapg-card-main');
    const line = el('span', 'dapg-card-line');
    line.appendChild(el('strong', null, g.name));
    if (g.locked) line.appendChild(el('em', 'dapg-tag', T.readOnly));
    if (g.joined) line.appendChild(el('em', 'dapg-tag dapg-tag-ok', T.joined));
    main.appendChild(line);
    main.appendChild(el('small', null, g.description || ''));
    main.appendChild(el('small', 'dapg-card-meta', g.members + ' ' + T.members + (g.slow ? ' · ' + T.slow + ' ' + g.slow + 's' : '')));
    card.append(icon, main);
    if (g.unread) card.appendChild(el('b', 'dapg-unread', g.unread > 9 ? '9+' : String(g.unread)));
    card.addEventListener('click', () => openRoom(g.slug));
    listView.appendChild(card);
   });
  }

  async function loadList(silent) {
   try {
    const data = await api('list', {});
    groups = data.groups || [];
    CFG.displayName = data.displayName || CFG.displayName;
    CFG.canModerate = !!data.canModerate;
    if (!silent || listView.hidden === false) renderList();
    updateBadge();
   } catch (e) {
    if (!silent) showError(listView, e);
   }
  }

  function updateBadge() {
   const total = groups.reduce((n, g) => n + (g.joined ? (g.unread || 0) : 0), 0);
   if (typeof options.onUnread === 'function') options.onUnread(total);
  }

  function showError(target, e) {
   target.replaceChildren();
   const box = el('div', 'dapg-error');
   box.appendChild(el('span', null, (e && e.message) || T.failed));
   const btn = el('button', 'dapg-btn');
   btn.type = 'button';
   btn.textContent = e && e.code === 'bad_nonce' ? T.reload : T.retry;
   btn.addEventListener('click', () => {
    if (e && e.code === 'bad_nonce') { window.location.reload(); return; }
    target === listView ? loadList() : openRoom(current && current.slug);
   });
   box.appendChild(btn);
   target.appendChild(box);
  }

  async function changeName() {
   const next = window.prompt(T.renameAsk, CFG.displayName || '');
   if (next === null) return;
   try {
    const data = await api('profile', { display_name: next });
    CFG.displayName = data.displayName;
    renderList();
   } catch (e) { window.alert(e.message); }
  }

  /* ---------------------------------------------------------------- room view */

  let msgWrap, composer, input, sendBtn, notice, roomHead, joinBar, fileInput;

  function buildRoom() {
   roomView.replaceChildren();
   roomHead = el('div', 'dapg-room-head');
   const back = el('button', 'dapg-back', '‹');
   back.type = 'button';
   back.setAttribute('aria-label', T.back);
   back.addEventListener('click', backToList);
   const idn = el('span', 'dapg-room-icon', current.icon || '💬');
   const title = el('span', 'dapg-room-title');
   title.appendChild(el('strong', null, current.name));
   title.appendChild(el('small', null, current.members + ' ' + T.members + (current.slow ? ' · ' + T.slow + ' ' + current.slow + 's' : '')));
   roomHead.append(back, idn, title);
   if (current.joined) {
    const leave = el('button', 'dapg-link', T.leave);
    leave.type = 'button';
    leave.addEventListener('click', leaveRoom);
    roomHead.appendChild(leave);
   }
   roomView.appendChild(roomHead);

   notice = el('div', 'dapg-notice');
   notice.hidden = true;
   roomView.appendChild(notice);

   msgWrap = el('div', 'dapg-messages');
   msgWrap.setAttribute('aria-live', 'polite');
   roomView.appendChild(msgWrap);

   joinBar = el('div', 'dapg-join');
   joinBar.hidden = true;
   roomView.appendChild(joinBar);

   composer = el('form', 'dapg-composer');
   if (CFG.images) {
    const attach = el('button', 'dapg-attach', '＋');
    attach.type = 'button';
    fileInput = el('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
    fileInput.hidden = true;
    attach.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => { if (fileInput.files && fileInput.files[0]) upload(fileInput.files[0]); });
    composer.append(attach, fileInput);
   }
   input = el('textarea');
   input.rows = 1;
   input.maxLength = CFG.maxLength || 700;
   input.placeholder = T.placeholder;
   input.setAttribute('aria-label', T.placeholder);
   input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 96) + 'px';
    sendBtn.disabled = !input.value.trim() || sending;
   });
   input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); composer.requestSubmit ? composer.requestSubmit() : submit(); }
   });
   sendBtn = el('button', 'dapg-send', '➤');
   sendBtn.type = 'submit';
   sendBtn.disabled = true;
   sendBtn.setAttribute('aria-label', T.send);
   composer.append(input, sendBtn);
   composer.addEventListener('submit', e => { e.preventDefault(); submit(); });
   roomView.appendChild(composer);
  }

  function applyPostState() {
   const can = !!current.canPost;
   composer.hidden = !can;
   joinBar.hidden = can;
   if (can) return;
   joinBar.replaceChildren();
   joinBar.appendChild(el('span', null, current.blockText || ''));
   if (current.blockReason === 'join') {
    const rules = el('ul', 'dapg-rules');
    (CFG.rules || []).forEach(r => rules.appendChild(el('li', null, r)));
    if (rules.childNodes.length) joinBar.appendChild(rules);
    const btn = el('button', 'dapg-btn', T.accept);
    btn.type = 'button';
    btn.addEventListener('click', joinRoom);
    joinBar.appendChild(btn);
   }
  }

  function badgeFor(role) {
   if (role === 'agent') return { text: T.staff, cls: 'dapg-badge-staff' };
   if (role === 'moderator') return { text: T.mod, cls: 'dapg-badge-mod' };
   return null;
  }

  function buildMessage(m) {
   const row = el('div', 'dapg-msg' + (m.mine ? ' is-mine' : '') + (m.hidden ? ' is-hidden' : ''));
   row.dataset.id = m.id;
   if (m.pinned) row.classList.add('is-pinned');
   if (!m.mine) {
    const av = el('span', 'dapg-avatar', m.initials || '?');
    row.appendChild(av);
   }
   const bubble = el('div', 'dapg-bubble');
   const meta = el('div', 'dapg-meta');
   meta.appendChild(el('strong', null, m.mine ? T.you : m.author));
   const badge = badgeFor(m.role);
   if (badge) meta.appendChild(el('em', 'dapg-role ' + badge.cls, badge.text));
   if (m.pinned) meta.appendChild(el('em', 'dapg-role dapg-badge-pin', T.pinned));
   meta.appendChild(el('time', null, m.time));
   bubble.appendChild(meta);

   if (m.type === 'image' && m.url) {
    const img = el('img', 'dapg-image');
    img.src = m.url;
    img.alt = T.image;
    img.loading = 'lazy';
    bubble.appendChild(img);
   } else {
    bubble.appendChild(el('p', null, m.body));
   }

   const tools = el('div', 'dapg-tools');
   if (!m.mine) {
    const rep = el('button', 'dapg-tool', '⚑');
    rep.type = 'button';
    rep.title = T.report;
    rep.setAttribute('aria-label', T.report);
    rep.addEventListener('click', () => report(m.id, rep));
    tools.appendChild(rep);
   }
   if (CFG.canModerate) {
    [['delete', T.modDelete, '🗑'], ['pin', T.modPin, '📌'], ['mute', T.modMute, '🔇'], ['ban', T.modBan, '⛔']].forEach(([op, label, glyph]) => {
     const b = el('button', 'dapg-tool', glyph);
     b.type = 'button';
     b.title = label;
     b.setAttribute('aria-label', label);
     b.addEventListener('click', () => moderate(op, m));
     tools.appendChild(b);
    });
    if (m.flags) bubble.appendChild(el('small', 'dapg-flags', m.flags));
   }
   if (tools.childNodes.length) bubble.appendChild(tools);
   row.appendChild(bubble);
   return row;
  }

  function appendMessages(items, reset) {
   if (reset) { msgWrap.replaceChildren(); lastId = 0; renderedDay = ''; }
   const stick = nearBottom();
   let added = 0;
   (items || []).forEach(m => {
    if (m.id <= lastId) return;
    lastId = m.id;
    if (m.day && m.day !== renderedDay) { renderedDay = m.day; msgWrap.appendChild(el('div', 'dapg-day', m.day)); }
    msgWrap.appendChild(buildMessage(m));
    added++;
   });
   if (!msgWrap.childNodes.length) msgWrap.appendChild(el('p', 'dapg-empty', T.emptyRoom));
   if (added && (reset || stick)) msgWrap.scrollTop = msgWrap.scrollHeight;
   if (added) markRead();
  }

  function nearBottom() { return msgWrap.scrollHeight - msgWrap.scrollTop - msgWrap.clientHeight < 120; }

  function flash(text, kind) {
   if (!notice) return;
   notice.textContent = text;
   notice.className = 'dapg-notice' + (kind ? ' is-' + kind : '');
   notice.hidden = false;
   clearTimeout(flash._t);
   flash._t = setTimeout(() => { notice.hidden = true; }, 6000);
  }

  async function openRoom(slug) {
   if (!slug) return;
   stopPoll();
   listView.hidden = true;
   roomView.hidden = false;
   roomView.replaceChildren(el('p', 'dapg-empty', T.loading));
   try {
    const data = await api('open', { group: slug });
    current = data.group;
    CFG.rules = data.rules || CFG.rules;
    buildRoom();
    appendMessages(data.messages, true);
    applyPostState();
    try { localStorage.setItem('dapg_last', slug); } catch (e) {}
    startPoll();
   } catch (e) { showError(roomView, e); }
  }

  function backToList() {
   stopPoll();
   current = null;
   roomView.hidden = true;
   listView.hidden = false;
   loadList();
   startPoll();
  }

  async function joinRoom() {
   try {
    await api('join', { group: current.slug });
    const fresh = await api('open', { group: current.slug });
    current = fresh.group;
    buildRoom();
    appendMessages(fresh.messages, true);
    applyPostState();
    input && input.focus();
   } catch (e) { flash(e.message, 'error'); }
  }

  async function leaveRoom() {
   try {
    const data = await api('leave', { group: current.slug });
    current = data.group;
    backToList();
   } catch (e) { flash(e.message, 'error'); }
  }

  async function submit() {
   const value = input.value.trim();
   if (!value || sending) return;
   sending = true;
   sendBtn.disabled = true;
   try {
    const data = await api('send', { group: current.slug, message: value, after_id: lastId });
    input.value = '';
    input.style.height = 'auto';
    current = data.group || current;
    appendMessages(data.messages);
    msgWrap.scrollTop = msgWrap.scrollHeight;
    if (data.filtered) flash(T.filtered, 'warn');
   } catch (e) {
    flash(e.message, 'error');
    if (e.code === 'join' || e.code === 'muted' || e.code === 'banned' || e.code === 'locked') {
     current.canPost = 0; current.blockReason = e.code; current.blockText = e.message; applyPostState();
    }
   } finally {
    sending = false;
    sendBtn.disabled = !input.value.trim();
   }
  }

  async function upload(file) {
   if (!file) return;
   try {
    const data = await api('upload', { group: current.slug, after_id: lastId }, file);
    appendMessages(data.messages);
    msgWrap.scrollTop = msgWrap.scrollHeight;
   } catch (e) { flash(e.message, 'error'); }
   finally { if (fileInput) fileInput.value = ''; }
  }

  async function report(id, btn) {
   if (!window.confirm(T.reportAsk)) return;
   btn.disabled = true;
   try { await api('report', { message_id: id, reason: 'abuse' }); flash(T.reported, 'ok'); }
   catch (e) { btn.disabled = false; flash(e.message, 'error'); }
  }

  async function moderate(op, m) {
   if (op === 'ban' && !window.confirm(T.confirmBan)) return;
   try {
    const data = await api('moderate', {
     op, message_id: m.id, group_id: current ? current.id : 0, target_id: m.userId, minutes: 60
    });
    if (data.group) current = data.group;
    appendMessages(data.messages, true);
    applyPostState();
   } catch (e) { flash(e.message, 'error'); }
  }

  let readSent = 0;
  async function markRead() {
   if (!current || !lastId || lastId === readSent) return;
   readSent = lastId;
   try { await api('read', { group: current.slug, last_id: lastId }); } catch (e) { readSent = 0; }
  }

  async function poll() {
   if (document.hidden || !visible) return;
   if (current) {
    try {
     const data = await api('poll', { group: current.slug, after_id: lastId });
     if (data.group) current = data.group;
     appendMessages(data.messages);
    } catch (e) { /* transient: the next tick retries */ }
   } else {
    loadList(true);
   }
  }

  function startPoll() {
   stopPoll();
   timer = setInterval(poll, current ? 7000 : 45000);
  }
  function stopPoll() { if (timer) clearInterval(timer); timer = 0; }

  document.addEventListener('visibilitychange', () => { if (document.hidden) stopPoll(); else if (visible) startPoll(); });

  return {
   show() {
    visible = true;
    if (!current) { listView.hidden = false; roomView.hidden = true; }
    loadList();
    startPoll();
   },
   hide() { visible = false; stopPoll(); },
   refreshBadge() { loadList(true); },
   openRoom
  };
 }

 /* ------------------------------------------------------------ widget mounting */

 function mountWidget(root) {
  if (root.dataset.dapgReady === '1') return;
  const nav = root.querySelector('.dap-nav');
  const stage = root.querySelector('.dap-stage');
  if (!nav || !stage) return;
  root.dataset.dapgReady = '1';
  root.classList.add('dapg-has-groups');

  const panel = document.createElement('div');
  panel.className = 'dapg-view';
  panel.hidden = true;
  stage.appendChild(panel);

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'dapg-nav-btn';
  btn.innerHTML = '<span class="dapg-nav-icon" aria-hidden="true">👥</span><span>' + T.title + '</span><b class="dapg-nav-unread" hidden>0</b>';
  nav.appendChild(btn);
  const badge = btn.querySelector('.dapg-nav-unread');

  const board = createBoard(panel, {
   onUnread(n) {
    badge.hidden = !n;
    badge.textContent = n > 9 ? '9+' : String(n);
   }
  });

  function open() {
   root.classList.add('dapg-active');
   panel.hidden = false;
   nav.querySelectorAll('button').forEach(b => b.classList.remove('is-active'));
   btn.classList.add('is-active');
   board.show();
  }
  function close() {
   root.classList.remove('dapg-active');
   panel.hidden = true;
   btn.classList.remove('is-active');
   board.hide();
  }

  btn.addEventListener('click', open);
  // Any original tab wins: the assistant keeps full control of its own views.
  root.querySelectorAll('[data-tab],[data-action]').forEach(b => { if (b !== btn) b.addEventListener('click', close); });
  root.querySelector('.dap-close')?.addEventListener('click', close);
  root.querySelector('.dap-launcher')?.addEventListener('click', () => setTimeout(() => board.refreshBadge(), 400));
  board.refreshBadge();
 }

 function scanWidgets() {
  document.querySelectorAll('[data-dap-root]').forEach(root => {
   if (root.querySelector('.dap-nav') && root.querySelector('.dap-stage')) mountWidget(root);
  });
 }

 function mountStandalone() {
  document.querySelectorAll('[data-dapg-standalone]').forEach(host => {
   if (host.dataset.dapgReady === '1') return;
   host.dataset.dapgReady = '1';
   host.replaceChildren();
   const board = createBoard(host, {});
   board.show();
   const preset = host.getAttribute('data-dapg-group');
   if (preset) board.openRoom(preset);
  });
 }

 function boot() {
  mountStandalone();
  scanWidgets();
  // The assistant panel is lazy-loaded after the first interaction, so keep watching for it.
  const mo = new MutationObserver(() => { scanWidgets(); mountStandalone(); });
  mo.observe(document.documentElement, { childList: true, subtree: true });
  setTimeout(() => mo.disconnect(), 60000);
 }

 if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
 else boot();
})();
