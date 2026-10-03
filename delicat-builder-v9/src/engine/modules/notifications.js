/**
 * Notification bell ([data-dbv9-notifications][data-config]) and panel, with
 * Web Push subscription. Loaded as a lazy chunk the first time the bell is
 * tapped (see index.js), then owns the bell for the life of the document.
 * Refreshes on focus (30 s), on push messages from the service worker, and on
 * a slow visible-only poll instead of a blind 90 s interval.
 */
import { cookie, device, doc, el, html, on, qsa, sleep, win } from '../core/dom.js';
import { ajax, getJson, rest } from '../core/net.js';
import { registerOverlay, releaseOverlay } from '../core/overlay.js';

const q = (s, r = doc) => r.querySelector(s);
const parse = (root) => { try { return JSON.parse(root.dataset.config || '{}'); } catch (_) { return {}; } };
const ICONS = {
	orders: '<path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
	wallet: '<path d="M4 7h15a2 2 0 0 1 2 2v9H5a2 2 0 0 1-2-2V6a3 3 0 0 1 3-3h11"/><path d="M16 12h5v4h-5a2 2 0 0 1 0-4Z"/>',
	chat: '<path d="M21 12a8 8 0 0 1-8 8H6l-4 2 1.5-4A8 8 0 1 1 21 12Z"/>',
	promotions: '<path d="M3 10h18v4H3zM5 14h14v7H5zM12 10v11M12 10H7.5a2.5 2.5 0 1 1 2.1-3.9L12 10Zm0 0h4.5a2.5 2.5 0 1 0-2.1-3.9L12 10Z"/>',
	security: '<path d="M12 3 5 6v5c0 4.7 2.8 8.4 7 10 4.2-1.6 7-5.3 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
	general: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/>',
};
const kindOf = (value) => { const raw = String(value || '').replace(/[︎️]/g, ''); return raw === '📦' || raw === 'orders' ? 'orders' : raw === '💰' || raw === 'wallet' ? 'wallet' : raw === '💬' || raw === 'chat' ? 'chat' : raw === '🎁' || raw === 'promotions' ? 'promotions' : raw === '🛡' || raw === 'security' ? 'security' : 'general'; };
const notifIcon = (value, cls = 'dbv9-notifications__icon') => { const n = el('span', cls); n.setAttribute('aria-hidden', 'true'); n.innerHTML = '<svg viewBox="0 0 24 24" focusable="false">' + ICONS[kindOf(value)] + '</svg>'; return n; };
const panelFor = (root) => { const id = root.dataset.panelId; return id ? doc.getElementById(id) : null; };
const safeId = (x) => String(x).replace(/[^A-Za-z0-9_-]/g, '');

function ajaxRequest(cfg, kind, body) {
	const actions = { list: 'delicat_builder_v9_notifications_list', read: 'delicat_builder_v9_notifications_read', dismiss: 'delicat_builder_v9_notifications_dismiss' };
	const fields = { nonce: cfg.ajaxNonce || '' };
	if (body && Array.isArray(body.ids)) fields.ids = body.ids;
	return ajax(actions[kind], fields, { url: cfg.ajax });
}
async function request(kind, cfg, body) {
	let firstError = null;
	const cache = kind === 'list' && !cfg.user ? 'default' : 'no-store';
	if (cfg[kind]) { try { return await rest(cfg[kind], { method: body ? 'POST' : 'GET', body, nonce: cfg.nonce, cache }); } catch (error) { firstError = error; } }
	try { return await ajaxRequest(cfg, kind, body); } catch (error) { throw firstError || error; }
}
const guestDismissed = () => { const raw = cookie.read('dbv9_ndis'); return raw ? raw.split(',').map(safeId).filter(Boolean) : []; };
const setGuestDismissed = (ids) => cookie.write('dbv9_ndis', ids.map(safeId).filter(Boolean).slice(-40).join(','), 15552000);
const normalizeItems = (items, cfg) => {
	if (!Array.isArray(items)) return [];
	let out = items;
	if (!cfg.user) { const hidden = new Set(guestDismissed()); out = items.filter((x) => x && x.id && !hidden.has(safeId(x.id))); }
	return out.slice(0, 40);
};
const animateBell = (root) => { const bell = q('[data-dbv9-notification-open]', root); if (!bell) return; bell.classList.remove('is-ringing'); void bell.offsetWidth; bell.classList.add('is-ringing'); win.setTimeout(() => bell.classList.remove('is-ringing'), 900); };
const setCount = (root, unread, animate = false) => {
	const count = q('.dbv9-notifications__count', root), bell = q('[data-dbv9-notification-open]', root);
	unread = Math.max(0, Number(unread) || 0);
	if (count) { count.textContent = String(Math.min(99, unread)); count.hidden = !unread; }
	if (bell) bell.classList.toggle('has-unread', !!unread);
	for (const node of qsa('[data-dbp-alerts]')) { node.textContent = String(unread); node.hidden = unread < 1; }
	if (animate && unread) animateBell(root);
};

function itemNode(item, root, cfg) {
	const a = el('a', 'dbv9-notifications__item' + (item.read ? ' is-read' : ''));
	a.href = item.url || '#';
	a.dataset.id = item.id || '';
	const copy = el('span', 'dbv9-notifications__copy');
	copy.append(el('b', '', item.title || 'Delicat Store'), el('small', '', item.body || ''));
	if (item.code) copy.append(el('em', 'dbv9-notifications__code', 'Code: ' + item.code));
	const del = el('button', 'dbv9-notifications__delete', '×');
	del.type = 'button';
	del.setAttribute('aria-label', 'Supprimer');
	del.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); dismiss(item.id, a, root, cfg); });
	a.append(notifIcon(item.icon || item.category || 'general'), copy, del);
	return a;
}
const updateState = (root) => {
	const panel = panelFor(root);
	if (!panel) return;
	const unread = qsa('.dbv9-notifications__item:not(.is-read)', panel).length;
	const read = q('[data-dbv9-notification-read-all]', panel);
	setCount(root, unread);
	if (read) read.hidden = !unread;
};
function renderItems(root, cfg, list, items) {
	const safe = normalizeItems(items, cfg);
	list.replaceChildren();
	safe.forEach((x) => list.append(itemNode(x, root, cfg)));
	if (!safe.length) list.append(el('p', 'dbv9-notifications__empty', 'Aucune notification.'));
	updateState(root);
	return safe.length;
}
async function dismiss(id, node, root, cfg) {
	if (!id) return;
	if (cfg.user) { try { await request('dismiss', cfg, { ids: [id] }); } catch (_) { return; } }
	else { const ids = guestDismissed(); const sid = safeId(id); if (sid && !ids.includes(sid)) ids.push(sid); setGuestDismissed(ids); }
	node.remove();
	const panel = panelFor(root), list = panel && q('[data-dbv9-notification-list]', panel);
	if (list && !q('.dbv9-notifications__item', list)) list.replaceChildren(el('p', 'dbv9-notifications__empty', 'Aucune notification.'));
	updateState(root);
}
const viewportBox = () => { const v = win.visualViewport; return v ? { left: v.offsetLeft || 0, top: v.offsetTop || 0, width: v.width || innerWidth, height: v.height || innerHeight } : { left: 0, top: 0, width: innerWidth, height: innerHeight }; };
function position(root, panel) {
	const open = q('[data-dbv9-notification-open]', root);
	if (!open || !panel) return;
	const r = open.getBoundingClientRect(), v = viewportBox(), pad = v.width <= 420 ? 10 : 12, gap = 8, minH = 220, maxW = v.width <= 420 ? 350 : 380;
	const width = Math.max(0, Math.min(maxW, v.width - pad * 2));
	const rightEdge = Math.min(v.left + v.width - pad, Math.max(v.left + pad + width, r.right));
	const left = Math.min(Math.max(rightEdge - width, v.left + pad), v.left + v.width - width - pad);
	const belowTop = r.bottom + gap, below = Math.max(0, v.top + v.height - pad - belowTop), aboveBottom = r.top - gap, above = Math.max(0, aboveBottom - (v.top + pad));
	const useBelow = below >= minH || below >= above, available = useBelow ? below : above;
	const height = Math.max(0, Math.min(560, available > 0 ? available : v.height - pad * 2));
	const top = useBelow ? Math.min(Math.max(belowTop, v.top + pad), v.top + v.height - height - pad) : Math.max(v.top + pad, aboveBottom - height);
	panel.style.setProperty('--dbv9-notif-top', Math.round(top) + 'px');
	panel.style.setProperty('--dbv9-notif-left', Math.round(left) + 'px');
	panel.style.setProperty('--dbv9-notif-width', Math.round(width) + 'px');
	panel.style.setProperty('--dbv9-notif-max-height', Math.round(height) + 'px');
}

/* ---------------------------------------------------------------- push */

const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in win && 'Notification' in win;
const b64ToBytes = (value) => { const pad = '='.repeat((4 - value.length % 4) % 4), raw = atob((value + pad).replace(/-/g, '+').replace(/_/g, '/')), out = new Uint8Array(raw.length); for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i); return out; };
const exactScope = (push) => { try { return new URL(push && push.scope || '/delicat-push/', location.origin).href; } catch (_) { return location.origin + '/delicat-push/'; } };
async function readyServiceWorker(push) {
	if (!push || !push.serviceWorker || !('serviceWorker' in navigator)) throw new Error('SW_UNAVAILABLE');
	const target = exactScope(push);
	let reg = null;
	try { const regs = await navigator.serviceWorker.getRegistrations(); reg = regs.find((r) => r && r.scope === target) || null; } catch (_) {}
	if (!reg) reg = await navigator.serviceWorker.register(push.serviceWorker, { scope: push.scope || '/delicat-push/' });
	else { try { await reg.update(); } catch (_) {} }
	for (let i = 0; i < 40; i++) { if (reg && reg.active) return reg; await sleep(200); }
	throw new Error('SW_TIMEOUT');
}
async function saveSubscription(push, sub) {
	if (!push || !push.endpoint || !push.nonce || !sub) throw new Error('PUSH_CONFIG');
	const json = sub.toJSON ? sub.toJSON() : sub;
	return getJson(push.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Delicat-Push-Nonce': push.nonce }, body: JSON.stringify({ subscription: json, platform: device.ios ? 'ios-web' : 'web', nonce: push.nonce }), redirect: 'follow' }, 8000);
}
async function removeSubscription(push, sub) {
	if (!push || !push.endpoint || !push.nonce || !sub) return;
	const json = sub.toJSON ? sub.toJSON() : sub;
	try { await getJson(push.endpoint, { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-Delicat-Push-Nonce': push.nonce }, body: JSON.stringify({ endpoint: sub.endpoint || '', auth: json && json.keys && json.keys.auth || '', nonce: push.nonce }), redirect: 'follow' }, 8000); } catch (_) {}
	try { await sub.unsubscribe(); } catch (_) {}
}
function installHelp() {
	if (win.DelicatPWA && typeof win.DelicatPWA.open === 'function') { win.DelicatPWA.open(); return; }
	let modal = doc.getElementById('dbv9-push-ios-help');
	if (!modal) {
		modal = el('div', 'dbv9-push-help');
		modal.id = 'dbv9-push-ios-help';
		modal.innerHTML = '<div class="dbv9-push-help__backdrop" data-dbv9-push-help-close></div><section class="dbv9-push-help__card" role="dialog" aria-modal="true" aria-labelledby="dbv9-push-help-title"><button type="button" class="dbv9-push-help__close" data-dbv9-push-help-close aria-label="Fermer">×</button><div class="dbv9-push-help__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/></svg></div><h3 id="dbv9-push-help-title">Activer les notifications sur iPhone</h3><ol><li>Touchez <b>Partager</b> dans votre navigateur.</li><li>Choisissez <b>Sur l’écran d’accueil</b> puis confirmez.</li><li>Ouvrez <b>Delicat Store</b> depuis la nouvelle icône.</li><li>Ouvrez la cloche et touchez <b>Activer les notifications</b>.</li></ol><p>Une fois activées, les alertes peuvent arriver même quand Delicat n’est pas ouvert.</p><button type="button" class="dbv9-push-help__ok" data-dbv9-push-help-close>J’ai compris</button></section>';
		doc.body.appendChild(modal);
		modal.addEventListener('click', (event) => { if (event.target.closest('[data-dbv9-push-help-close]')) modal.classList.remove('is-open'); });
	}
	modal.classList.add('is-open');
}
async function pushState(push) {
	if (device.ios && !device.standalone) return { supported: true, needsInstall: true, sub: null, permission: ('Notification' in win ? Notification.permission : 'default') };
	if (!push || !push.enabled) return { supported: false, reason: 'disabled', sub: null, permission: 'unsupported' };
	if (!push.available) return { supported: false, reason: 'server', sub: null, permission: 'unsupported' };
	if (!pushSupported()) return { supported: false, reason: 'browser', sub: null, permission: 'unsupported' };
	let reg;
	try { reg = await readyServiceWorker(push); } catch (_) { return { supported: true, sub: null, permission: Notification.permission, waiting: true }; }
	let sub = null;
	try { sub = await reg.pushManager.getSubscription(); } catch (_) {}
	return { supported: true, sub, permission: Notification.permission, reg };
}
async function subscribePush(push, interactive = true) {
	if (device.ios && !device.standalone) throw new Error('IOS_INSTALL');
	if (!push || !push.enabled || !push.available) throw new Error('SERVER_UNAVAILABLE');
	if (!pushSupported()) throw new Error('UNSUPPORTED');
	let permission = Notification.permission;
	if (permission === 'default' && interactive) permission = await Notification.requestPermission();
	if (permission !== 'granted') throw new Error(permission === 'denied' ? 'DENIED' : 'NOT_GRANTED');
	const reg = await readyServiceWorker(push);
	let sub = await reg.pushManager.getSubscription();
	if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(push.publicKey) });
	await saveSubscription(push, sub);
	return sub;
}
async function renderPushControl(root, cfg, pushWrap) {
	const push = cfg.push || {}, btn = q('[data-dbv9-push-toggle]', pushWrap), status = q('[data-dbv9-push-status]', pushWrap);
	if (!btn || !status) return;
	pushWrap.hidden = !push.enabled;
	if (!push.enabled) return;
	const state = await pushState(push);
	btn.classList.remove('is-active'); btn.hidden = false; btn.disabled = false; btn.onclick = null;
	if (state.needsInstall) { btn.textContent = 'Ajouter à l’écran d’accueil'; status.textContent = 'Sur iPhone/iPad, les notifications en arrière-plan s’activent depuis Delicat ajouté à l’écran d’accueil.'; btn.onclick = installHelp; return; }
	if (!state.supported) { btn.hidden = true; status.textContent = state.reason === 'server' ? 'Le service Web Push est en cours d’initialisation. Rechargez la page après la mise à jour.' : 'Ce navigateur ne prend pas en charge les notifications Web Push.'; return; }
	if (state.permission === 'denied') { btn.textContent = 'Notifications bloquées'; btn.disabled = true; status.textContent = 'Autorisez les notifications dans les réglages du navigateur pour les réactiver.'; return; }
	if (state.sub) {
		btn.textContent = '✓ Notifications activées'; btn.classList.add('is-active'); status.textContent = 'Alertes en arrière-plan activées. Touchez pour désactiver.';
		btn.onclick = async () => { btn.disabled = true; await removeSubscription(push, state.sub); btn.classList.remove('is-active'); await renderPushControl(root, cfg, pushWrap); };
		return;
	}
	btn.textContent = 'Activer les notifications';
	status.textContent = state.waiting ? 'Initialisation sécurisée du service de notifications…' : 'Recevez les commandes, promotions et alertes même lorsque Delicat est fermé.';
	btn.onclick = async () => {
		btn.disabled = true; status.textContent = 'Activation…';
		try { await subscribePush(push, true); status.textContent = 'Notifications activées.'; animateBell(root); }
		catch (err) {
			const m = err && err.message;
			if (m === 'IOS_INSTALL') installHelp();
			else if (m === 'DENIED') status.textContent = 'Permission refusée. Vous pouvez la modifier dans les réglages du navigateur.';
			else if (m === 'SERVER_UNAVAILABLE') status.textContent = 'Le service Web Push n’est pas encore prêt sur le serveur.';
			else status.textContent = 'Impossible d’activer les notifications pour le moment.';
		} finally { btn.disabled = false; await renderPushControl(root, cfg, pushWrap); }
	};
}

/* --------------------------------------------------------------- panel */

function mountPanel(root, cfg) {
	let panel = panelFor(root);
	if (panel) return panel;
	panel = el('section', 'dbv9-notifications__panel');
	panel.id = cfg.panelId || ('dbv9-notification-panel-' + Math.random().toString(36).slice(2));
	root.dataset.panelId = panel.id;
	panel.dataset.dbv9NotificationPanel = '1';
	panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'false'); panel.setAttribute('aria-label', 'Notifications'); panel.setAttribute('aria-hidden', 'true');
	panel.hidden = true;
	const head = el('div', 'dbv9-notifications__head'), title = el('strong', '', 'Notifications'), close = el('button', '', '×');
	close.type = 'button'; close.dataset.dbv9NotificationClose = '1'; close.setAttribute('aria-label', 'Fermer');
	head.append(title, close);
	const list = el('div', 'dbv9-notifications__list'); list.dataset.dbv9NotificationList = '1';
	const pushWrap = el('div', 'dbv9-notifications__push'); pushWrap.hidden = true;
	const pushBtn = el('button', 'dbv9-notifications__push-button', 'Activer les notifications'); pushBtn.type = 'button'; pushBtn.dataset.dbv9PushToggle = '1';
	const pushStatus = el('small', 'dbv9-notifications__push-status', ''); pushStatus.dataset.dbv9PushStatus = '1';
	pushWrap.append(pushBtn, pushStatus);
	const read = el('button', 'dbv9-notifications__read', 'Tout marquer comme lu'); read.type = 'button'; read.dataset.dbv9NotificationReadAll = '1'; read.hidden = true;
	panel.append(head, list, pushWrap, read);
	(doc.body || html).appendChild(panel);
	if (Array.isArray(cfg.initial)) { renderItems(root, cfg, list, cfg.initial); root.dataset.snapshot = '1'; }
	else list.append(el('p', 'dbv9-notifications__loading', 'Chargement…'));
	position(root, panel);
	renderPushControl(root, cfg, pushWrap).catch(() => {});
	return panel;
}
function showLoadError(root, cfg, list) {
	const wrap = el('div', 'dbv9-notifications__error'), msg = el('p', '', 'Impossible de charger les notifications.'), retry = el('button', '', 'Réessayer');
	retry.type = 'button';
	retry.addEventListener('click', () => { root.dataset.loaded = ''; list.replaceChildren(el('p', 'dbv9-notifications__loading', 'Chargement…')); load(root, cfg, true); });
	wrap.append(msg, retry);
	list.replaceChildren(wrap);
}
async function load(root, cfg, force = false) {
	if ((root.dataset.loaded === '1' && !force) || root.dataset.loading === '1') return;
	const panel = panelFor(root), list = panel && q('[data-dbv9-notification-list]', panel);
	if (!list) return;
	root.dataset.loading = '1';
	try { const items = await request('list', cfg); cfg.initial = items; renderItems(root, cfg, list, items); root.dataset.loaded = '1'; delete root.dataset.transportError; }
	catch (_) { root.dataset.transportError = '1'; if (root.dataset.snapshot !== '1') { root.dataset.loaded = 'error'; showLoadError(root, cfg, list); } else root.dataset.loaded = 'snapshot'; }
	finally { root.dataset.loading = '0'; }
}
async function refreshSnapshot(root, cfg) {
	if (!root.isConnected || root.dataset.refreshing === '1' || doc.hidden || navigator.onLine === false) return;
	root.dataset.refreshing = '1';
	try {
		const old = normalizeItems(cfg.initial || [], cfg), items = normalizeItems(await request('list', cfg), cfg);
		cfg.initial = items;
		const oldFirst = old[0] && old[0].id || '', newFirst = items[0] && items[0].id || '';
		setCount(root, items.filter((x) => !x.read).length, !!newFirst && newFirst !== oldFirst);
		const panel = panelFor(root), list = panel && q('[data-dbv9-notification-list]', panel);
		if (list && !panel.hidden) renderItems(root, cfg, list, items);
	} catch (_) {} finally { root.dataset.refreshing = '0'; }
}
const removeLegacy = () => { for (const n of qsa('.dsb541-notifications,.dsb541-panel,.dsb541-panel-portal,[data-dsb-notifications]')) n.remove(); };

export function initRoot(root, signal) {
	if (root.dataset.ready === '1') return;
	root.dataset.ready = '1';
	const cfg = parse(root), open = q('[data-dbv9-notification-open]', root);
	if (!open) return;
	root._dbv9Cfg = cfg;
	root.dataset.panelId = cfg.panelId || '';
	let frame = 0, watching = false;
	const reposition = () => { const panel = panelFor(root); if (!panel || panel.hidden) return; if (frame) cancelAnimationFrame(frame); frame = requestAnimationFrame(() => { position(root, panel); frame = 0; }); };
	const watchers = new AbortController();
	const watch = () => { if (watching) return; watching = true; win.addEventListener('resize', reposition, { passive: true, signal: watchers.signal }); win.addEventListener('scroll', reposition, { passive: true, signal: watchers.signal }); if (win.visualViewport) { win.visualViewport.addEventListener('resize', reposition, { passive: true, signal: watchers.signal }); win.visualViewport.addEventListener('scroll', reposition, { passive: true, signal: watchers.signal }); } };
	const hide = () => { const panel = panelFor(root); if (!panel) return; panel.hidden = true; panel.setAttribute('aria-hidden', 'true'); open.setAttribute('aria-expanded', 'false'); html.classList.remove('dbv9-notification-open'); releaseOverlay('notifications'); };
	const toggle = async (event) => {
		if (event) { event.preventDefault(); event.stopPropagation(); }
		removeLegacy();
		const panel = mountPanel(root, cfg);
		if (!panel.hidden) { hide(); return; }
		position(root, panel);
		panel.hidden = false; panel.setAttribute('aria-hidden', 'false'); open.setAttribute('aria-expanded', 'true');
		html.classList.add('dbv9-notification-open');
		registerOverlay('notifications');
		watch(); reposition();
		const close = q('[data-dbv9-notification-close]', panel), read = q('[data-dbv9-notification-read-all]', panel), pushWrap = q('.dbv9-notifications__push', panel);
		if (close && !close.dataset.bound) { close.dataset.bound = '1'; close.addEventListener('click', hide); }
		if (read && !read.dataset.bound) {
			read.dataset.bound = '1';
			read.addEventListener('click', async () => {
				const nodes = qsa('.dbv9-notifications__item:not(.is-read)', panel), ids = nodes.map((n) => n.dataset.id).filter(Boolean);
				if (!ids.length) return;
				if (cfg.user) { try { await request('read', cfg, { ids }); } catch (_) { return; } }
				nodes.forEach((n) => n.classList.add('is-read'));
				updateState(root);
			});
		}
		if (pushWrap) renderPushControl(root, cfg, pushWrap).catch(() => {});
		await load(root, cfg, true);
	};
	on(open, 'click', toggle, { signal });
	on(doc, 'keydown', (event) => { if (event.key === 'Escape') hide(); }, { signal });
	on(doc, 'pointerdown', (event) => { const panel = panelFor(root); if (panel && !panel.hidden && !root.contains(event.target) && !panel.contains(event.target)) hide(); }, { passive: true, signal });
	on(doc, 'delicat:navigate', hide, { signal });
	const pushTimer = win.setTimeout(() => { if (root.isConnected && cfg.push && cfg.push.enabled && cfg.push.available && (!device.ios || device.standalone) && pushSupported() && Notification.permission === 'granted') subscribePush(cfg.push, false).catch(() => {}); }, 4500);
	let lastFocusRefresh = 0;
	on(win, 'focus', () => { const now = Date.now(); if (now - lastFocusRefresh > 30000) { lastFocusRefresh = now; refreshSnapshot(root, cfg); } }, { passive: true, signal });
	/* Visible-only slow refresh: no request while the tab is hidden. */
	const poll = win.setInterval(() => { if (!doc.hidden) refreshSnapshot(root, cfg); }, 120000);
	signal.addEventListener('abort', () => { win.clearTimeout(pushTimer); win.clearInterval(poll); watchers.abort(); hide(); const panel = panelFor(root); if (panel) panel.remove(); delete root.dataset.ready; }, { once: true });
	if (signal.aborted) return;
	return toggle;
}

let swBound = false;
function bindServiceWorkerMessages() {
	if (swBound || !('serviceWorker' in navigator)) return;
	swBound = true;
	navigator.serviceWorker.addEventListener('message', (event) => {
		const data = event.data || {};
		if (data.type !== 'dbv9-push') return;
		for (const root of qsa('[data-dbv9-notifications]')) {
			const cfg = root._dbv9Cfg || parse(root);
			const panel = panelFor(root), list = panel && q('[data-dbv9-notification-list]', panel);
			if (data.item && data.item.notification_id) {
				const item = { id: data.item.notification_id, title: data.item.title || 'Delicat Store', body: data.item.body || '', url: data.item.url || '#', icon: '🔔', read: 0, category: data.item.category || 'general' };
				cfg.initial = [item, ...normalizeItems(cfg.initial || [], cfg).filter((x) => x.id !== item.id)].slice(0, 40);
				if (list && !panel.hidden) renderItems(root, cfg, list, cfg.initial);
				else setCount(root, normalizeItems(cfg.initial, cfg).filter((x) => !x.read).length, true);
			} else refreshSnapshot(root, cfg);
		}
	});
}

export default function mount({ signal }) {
	removeLegacy();
	const toggles = new Map();
	for (const root of qsa('[data-dbv9-notifications]')) { const toggle = initRoot(root, signal); if (toggle) toggles.set(root, toggle); }
	bindServiceWorkerMessages();
	return { open: (root) => { const toggle = toggles.get(root); if (toggle) toggle(); } };
}
