/* Delicat Assistant 7 — the chat app (help centre, private support chat, community groups).
 * Loaded on the first tap by boot.js; bundled with app.css and the icon set into one file.
 * Renders inside a shadow root. Every piece of server text goes through textContent; links and image
 * sources through safeUrl() (http/https only). Server endpoints: dsa7_boot / dsa7_ping (launcher
 * kernel), dsa2_* (support chat engine) and dsa2_groups_* (community). */
import { ICONS } from './icons.js';
import CSS from './app.css';

const W = window;
const D = document;
const C = W.DSA7_CONFIG || {};
const AJAX = (() => {
  try {
    const u = new URL(C.ajax || '/wp-admin/admin-ajax.php', location.href);
    return u.origin === location.origin ? u.href : location.origin + u.pathname;
  } catch (e) { return '/wp-admin/admin-ajax.php'; }
})();

/* ================================================================ text */
const STR = {
  fr: {
    close: 'Fermer', back: 'Retour', home: 'Accueil', bot: 'Assistant', team: 'Équipe', community: 'Communauté', more: 'Plus d’options',
    hello: 'Bonjour %s', helloAnon: 'Bonjour', helloSub: 'Comment pouvons-nous vous aider ?',
    instant: 'Réponse instantanée', instantSub: 'Commandes, crédit boutique, PIN et produits en quelques secondes.',
    talk: 'Parler à l’équipe', talkSub: 'Une vraie personne vous répond ici.', commTitle: 'Communauté Delicat', commSub: 'Discutez entre clients et posez vos questions à l’équipe.', commHead: 'Entre clients, avec l’équipe',
    topics: 'Sujets fréquents', tRecharge: 'Recharger un jeu', tOrder: 'Suivre ma commande', tPin: 'Code PIN', tWallet: 'Crédit boutique', tGuides: 'Tous les guides', tFaq: 'Questions fréquentes', tProduct: 'Question sur ce produit',
    secure: 'Conversation sécurisée par Delicat Store', online: 'Équipe disponible', away: 'Réponse bientôt', offTeam: 'Laissez un message', eta: 'Réponse en %s',
    botTitle: 'Assistant Delicat', botSub: 'Automatique · 24 h/24', teamTitle: 'Équipe support',
    inBot: 'Posez votre question…', inTeam: 'Écrivez à l’équipe…', inGroup: 'Écrire au groupe…', inAsk: 'Votre question pour l’équipe…',
    send: 'Envoyer', attach: 'Joindre une image', you: 'Vous', teamLabel: 'Équipe Delicat', staff: 'Équipe',
    welcome: 'Bonjour ! Je suis l’assistant Delicat. Posez votre question ou choisissez un sujet.',
    emptyTeam: 'Écrivez à l’équipe', emptyTeamSub: 'Décrivez votre besoin ci-dessous. Un membre de l’équipe vous répond dans cette conversation.',
    endChat: 'Clore la demande', whatsapp: 'Continuer sur WhatsApp', language: 'Langue',
    sending: 'Envoi…', sent: 'Envoyé', read: 'Lu', failed: 'Non envoyé', retry: 'Réessayer',
    error: 'Connexion momentanément indisponible.', offline: 'Pas de connexion internet. Réessayez quand le réseau revient.', tooBig: 'Image trop lourde (%s Mo maximum).', onlyImages: 'Seules les images sont acceptées.',
    copied: 'Copié', shared: 'Guide copié', open: 'Ouvrir', viewOrder: 'Voir la commande', secureTag: 'Sécurisé', accountTag: 'Compte requis', deleted: 'Message supprimé',
    typing: 'L’équipe écrit…', jump: 'Aller aux derniers messages', loading: 'Chargement…', today: 'Aujourd’hui', yesterday: 'Hier',
    signIn: 'Connectez-vous pour rejoindre la communauté et discuter avec les autres clients.', signInBtn: 'Se connecter', banned: 'Votre accès aux groupes a été suspendu.',
    members: '%s membres', join: 'Rejoindre le groupe', leave: 'Quitter le groupe', rules: 'Règles du groupe', noRooms: 'Aucun groupe pour le moment.',
    yourName: 'Vous apparaissez comme', change: 'Modifier', save: 'Enregistrer', nameTitle: 'Votre nom dans les groupes', nameHint: '2 à 24 caractères. Pas d’e-mail ni de numéro.',
    toGroup: 'Au groupe', askTeam: 'Question à l’équipe', askHint: 'Votre question reste visible dans le groupe et l’équipe y répond. Pour une commande ou un paiement, utilisez « Continuer en privé ».',
    question: 'Question à l’équipe', qOpen: 'En attente de l’équipe', qAnswered: 'Répondue', qPrivate: 'Continuée en privé',
    private: 'Continuer en privé', privateDone: 'Votre question a été envoyée à l’équipe en privé.', privateTitle: 'Continuer en privé ?', privateText: 'Votre question sera copiée dans votre conversation privée avec l’équipe Delicat. Personne d’autre ne la verra.',
    reply: 'Répondre', replyTo: 'Réponse à %s', report: 'Signaler', reportTitle: 'Pourquoi signaler ce message ?', rScam: 'Arnaque ou demande de paiement', rAbuse: 'Insulte ou harcèlement', rSpam: 'Spam', rOther: 'Autre raison', reported: 'Merci, le message a été signalé.',
    hide: 'Masquer', restore: 'Rétablir', del: 'Supprimer', pin: 'Épingler ou désépingler', mute: 'Sourdine 1 h', ban: 'Bannir du groupe', banAll: 'Bannir de tous les groupes', markAnswered: 'Marquer comme répondue', reopen: 'Rouvrir la question', lock: 'Verrouiller ou déverrouiller le groupe',
    removed: 'Message retiré par la modération.', earlier: 'Messages précédents', slow: 'Mode lent : attendez %s s.', filtered: 'Certaines informations ont été masquées pour votre sécurité.',
    safety: 'L’équipe Delicat ne demande jamais de paiement en message privé.', pinned: 'Épinglé', pending: '%s en attente', cancel: 'Annuler', confirm: 'Confirmer', done: 'C’est fait.', image: 'Image', locked: 'Groupe en lecture seule',
  },
  en: {
    close: 'Close', back: 'Back', home: 'Home', bot: 'Assistant', team: 'Team', community: 'Community', more: 'More options',
    hello: 'Hello %s', helloAnon: 'Hello', helloSub: 'How can we help you?',
    instant: 'Instant answer', instantSub: 'Orders, store credit, PINs and products in seconds.',
    talk: 'Talk to the team', talkSub: 'A real person replies here.', commTitle: 'Delicat Community', commSub: 'Chat with other customers and ask the team.', commHead: 'Customers together, with the team',
    topics: 'Popular topics', tRecharge: 'Top up a game', tOrder: 'Track my order', tPin: 'PIN code', tWallet: 'Store credit', tGuides: 'All guides', tFaq: 'FAQ', tProduct: 'Question about this product',
    secure: 'Conversation secured by Delicat Store', online: 'Team available', away: 'Replying soon', offTeam: 'Leave a message', eta: 'Replies in %s',
    botTitle: 'Delicat Assistant', botSub: 'Automated · 24/7', teamTitle: 'Support team',
    inBot: 'Ask your question…', inTeam: 'Write to the team…', inGroup: 'Write to the group…', inAsk: 'Your question for the team…',
    send: 'Send', attach: 'Attach an image', you: 'You', teamLabel: 'Delicat Team', staff: 'Team',
    welcome: 'Hello! I am the Delicat assistant. Ask a question or pick a topic.',
    emptyTeam: 'Write to the team', emptyTeamSub: 'Describe what you need below. A team member will reply in this conversation.',
    endChat: 'Close request', whatsapp: 'Continue on WhatsApp', language: 'Language',
    sending: 'Sending…', sent: 'Sent', read: 'Read', failed: 'Not sent', retry: 'Retry',
    error: 'Connection temporarily unavailable.', offline: 'No internet connection. Try again when the network is back.', tooBig: 'Image too large (%s MB maximum).', onlyImages: 'Only images are accepted.',
    copied: 'Copied', shared: 'Guide copied', open: 'Open', viewOrder: 'View order', secureTag: 'Secure', accountTag: 'Account required', deleted: 'Message deleted',
    typing: 'The team is typing…', jump: 'Jump to latest messages', loading: 'Loading…', today: 'Today', yesterday: 'Yesterday',
    signIn: 'Sign in to join the community and chat with other customers.', signInBtn: 'Sign in', banned: 'Your access to the groups has been suspended.',
    members: '%s members', join: 'Join the group', leave: 'Leave the group', rules: 'Group rules', noRooms: 'No groups yet.',
    yourName: 'You appear as', change: 'Change', save: 'Save', nameTitle: 'Your name in the groups', nameHint: '2 to 24 characters. No e-mail or phone number.',
    toGroup: 'To the group', askTeam: 'Ask the team', askHint: 'Your question stays visible in the group and the team answers there. For an order or a payment, use “Continue privately”.',
    question: 'Question for the team', qOpen: 'Waiting for the team', qAnswered: 'Answered', qPrivate: 'Continued privately',
    private: 'Continue privately', privateDone: 'Your question was sent privately to the team.', privateTitle: 'Continue privately?', privateText: 'Your question will be copied into your private conversation with the Delicat team. Nobody else will see it.',
    reply: 'Reply', replyTo: 'Reply to %s', report: 'Report', reportTitle: 'Why are you reporting this message?', rScam: 'Scam or payment request', rAbuse: 'Insult or harassment', rSpam: 'Spam', rOther: 'Other reason', reported: 'Thank you, the message was reported.',
    hide: 'Hide', restore: 'Restore', del: 'Delete', pin: 'Pin or unpin', mute: 'Mute for 1 h', ban: 'Ban from the group', banAll: 'Ban from all groups', markAnswered: 'Mark as answered', reopen: 'Reopen question', lock: 'Lock or unlock the group',
    removed: 'Message removed by moderation.', earlier: 'Earlier messages', slow: 'Slow mode: wait %s s.', filtered: 'Some details were masked for your safety.',
    safety: 'The Delicat team never asks for a payment in a private message.', pinned: 'Pinned', pending: '%s waiting', cancel: 'Cancel', confirm: 'Confirm', done: 'Done.', image: 'Image', locked: 'Read-only group',
  },
  ht: {
    close: 'Fèmen', back: 'Retounen', home: 'Akèy', bot: 'Asistan', team: 'Ekip', community: 'Kominote', more: 'Plis opsyon',
    hello: 'Bonjou %s', helloAnon: 'Bonjou', helloSub: 'Kijan nou ka ede w?',
    instant: 'Repons touswit', instantSub: 'Kòmand, kredi boutik, PIN ak pwodwi nan kèk segonn.',
    talk: 'Pale ak ekip la', talkSub: 'Yon moun tout bon ap reponn ou isit la.', commTitle: 'Kominote Delicat', commSub: 'Pale ak lòt kliyan epi poze ekip la kesyon.', commHead: 'Kliyan ansanm, ak ekip la',
    topics: 'Sijè ki pi popilè', tRecharge: 'Rechaje yon jwèt', tOrder: 'Swiv kòmand mwen', tPin: 'Kòd PIN', tWallet: 'Kredi boutik', tGuides: 'Tout gid yo', tFaq: 'Kesyon souvan', tProduct: 'Kesyon sou pwodwi sa a',
    secure: 'Delicat Store sekirize konvèsasyon an', online: 'Ekip la disponib', away: 'Ap reponn byento', offTeam: 'Kite yon mesaj', eta: 'Repons nan %s',
    botTitle: 'Asistan Delicat', botSub: 'Otomatik · 24 sou 24', teamTitle: 'Ekip sipò',
    inBot: 'Poze kesyon ou…', inTeam: 'Ekri ekip la…', inGroup: 'Ekri gwoup la…', inAsk: 'Kesyon ou pou ekip la…',
    send: 'Voye', attach: 'Mete yon imaj', you: 'Ou', teamLabel: 'Ekip Delicat', staff: 'Ekip',
    welcome: 'Bonjou! Mwen se asistan Delicat la. Poze kesyon ou oswa chwazi yon sijè.',
    emptyTeam: 'Ekri ekip la', emptyTeamSub: 'Eksplike bezwen ou anba a. Yon moun nan ekip la ap reponn ou nan konvèsasyon sa a.',
    endChat: 'Fèmen demann lan', whatsapp: 'Kontinye sou WhatsApp', language: 'Lang',
    sending: 'Ap voye…', sent: 'Voye', read: 'Li', failed: 'Pa voye', retry: 'Eseye ankò',
    error: 'Koneksyon an pa disponib pou kounye a.', offline: 'Pa gen entènèt. Eseye ankò lè rezo a tounen.', tooBig: 'Imaj la twò lou (%s Mo maksimòm).', onlyImages: 'Se sèlman imaj ki aksepte.',
    copied: 'Kopye', shared: 'Gid la kopye', open: 'Louvri', viewOrder: 'Gade kòmand lan', secureTag: 'Sekirize', accountTag: 'Bezwen yon kont', deleted: 'Mesaj efase',
    typing: 'Ekip la ap ekri…', jump: 'Ale nan dènye mesaj yo', loading: 'Ap chaje…', today: 'Jodi a', yesterday: 'Yè',
    signIn: 'Konekte pou antre nan kominote a epi pale ak lòt kliyan.', signInBtn: 'Konekte', banned: 'Aksè ou nan gwoup yo sispann.',
    members: '%s manm', join: 'Antre nan gwoup la', leave: 'Kite gwoup la', rules: 'Règ gwoup la', noRooms: 'Pa gen gwoup pou kounye a.',
    yourName: 'Non ou nan gwoup yo', change: 'Chanje', save: 'Anrejistre', nameTitle: 'Non ou nan gwoup yo', nameHint: '2 a 24 karaktè. Pa mete imel ni nimewo.',
    toGroup: 'Bay gwoup la', askTeam: 'Kesyon pou ekip la', askHint: 'Kesyon ou rete vizib nan gwoup la epi ekip la reponn la. Pou yon kòmand oswa yon peman, sèvi ak « Kontinye an prive ».',
    question: 'Kesyon pou ekip la', qOpen: 'Ap tann ekip la', qAnswered: 'Reponn', qPrivate: 'Kontinye an prive',
    private: 'Kontinye an prive', privateDone: 'Kesyon ou voye bay ekip la an prive.', privateTitle: 'Kontinye an prive?', privateText: 'Kesyon ou ap kopye nan konvèsasyon prive ou ak ekip Delicat. Pèsonn lòt p ap wè l.',
    reply: 'Reponn', replyTo: 'Repons pou %s', report: 'Siyale', reportTitle: 'Poukisa w ap siyale mesaj sa a?', rScam: 'Eskwokri oswa demann peman', rAbuse: 'Jouman oswa abi', rSpam: 'Spam', rOther: 'Lòt rezon', reported: 'Mèsi, mesaj la siyale.',
    hide: 'Kache', restore: 'Remete', del: 'Efase', pin: 'Kloure oswa dekloure', mute: 'Fè l pe pandan 1 è', ban: 'Mete l deyò gwoup la', banAll: 'Mete l deyò tout gwoup yo', markAnswered: 'Make l reponn', reopen: 'Relouvri kesyon an', lock: 'Fèmen oswa louvri gwoup la',
    removed: 'Moderasyon retire mesaj sa a.', earlier: 'Mesaj anvan yo', slow: 'Mòd dousman: tann %s s.', filtered: 'Nou kache kèk enfòmasyon pou sekirite ou.',
    safety: 'Ekip Delicat pa janm mande peman nan mesaj prive.', pinned: 'Kloure', pending: '%s ap tann', cancel: 'Anile', confirm: 'Konfime', done: 'Fini.', image: 'Imaj', locked: 'Gwoup la fèmen pou ekri',
  },
};
const LOCALE = { fr: 'fr-FR', en: 'en-US', ht: 'fr-FR' };
let lang = (() => {
  const q = /[?&]lang=(fr|en|ht)\b/.exec(location.search);
  if (q) return q[1];
  try { const s = localStorage.getItem('dap_language'); if (STR[s]) return s; } catch (e) { /* storage blocked */ } // same key as 6.x: the choice survives the update
  return STR[C.lang] ? C.lang : 'fr';
})();
const t = (key, arg) => {
  const s = (STR[lang] && STR[lang][key]) || STR.fr[key] || key;
  return arg === undefined ? s : s.replace('%s', String(arg));
};

/* ================================================================ helpers */
const SVGNS = 'http://www.w3.org/2000/svg';
function icon(name, cls) {
  const svg = D.createElementNS(SVGNS, 'svg');
  svg.setAttribute('viewBox', '0 -960 960 960');
  svg.setAttribute('class', 'i' + (cls ? ' ' + cls : ''));
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  const p = D.createElementNS(SVGNS, 'path');
  p.setAttribute('d', ICONS[name] || ICONS.info);
  svg.appendChild(p);
  return svg;
}
function waIcon() {
  const svg = D.createElementNS(SVGNS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  for (const d of ['M12 3a9 9 0 0 0-7.8 13.5L3 21l4.7-1.2A9 9 0 1 0 12 3z', 'M9.2 8.8c.2-.4.5-.5.8-.5h.5c.2 0 .4.1.5.4l.6 1.5c.1.2 0 .4-.1.6l-.5.6c.6 1 1.5 1.8 2.5 2.4l.6-.5c.2-.2.4-.2.6-.1l1.5.7c.2.1.4.3.3.6-.1.9-.7 1.5-1.6 1.6-2.9.1-6.2-3.2-6.1-6.1 0-.4.2-.8.4-1.2z']) {
    const p = D.createElementNS(SVGNS, 'path');
    p.setAttribute('d', d);
    svg.appendChild(p);
  }
  return svg;
}
function h(tag, props, ...kids) {
  const el = D.createElement(tag);
  if (props) {
    for (const k of Object.keys(props)) {
      const v = props[k];
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else if (k === 'data') Object.assign(el.dataset, v);
      else el.setAttribute(k, v === true ? '' : v);
    }
  }
  for (const kid of kids.flat()) {
    if (kid === null || kid === undefined || kid === false) continue;
    put(el, kid.nodeType ? kid : D.createTextNode(String(kid)));
  }
  return el;
}
function put(el, ...kids) {
  // Element.append(null) would print the word "null": optional parts are skipped here.
  for (const k of kids.flat()) if (k !== null && k !== undefined && k !== false) el.appendChild(k.nodeType ? k : D.createTextNode(String(k)));
  return el;
}
function safeUrl(raw) {
  try {
    const u = new URL(String(raw || ''), location.href);
    return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : '';
  } catch (e) { return ''; }
}
function sameSite(url) {
  try { return new URL(url, location.href).host === location.host; } catch (e) { return false; }
}
/* Automated answers come from a library that predates the Material-only rule: no pictographs in them. */
const PICTO = /[\p{Extended_Pictographic}\u{FE0F}\u{200D}\u{20E3}]/gu;
const clean = (s) => String(s == null ? '' : s).replace(PICTO, '').replace(/^[\s·:–-]+/, '').replace(/[ \t]{2,}/g, ' ').trim();
const URL_RE = /\bhttps?:\/\/[^\s<>"']+/g;
function richText(text) {
  const frag = D.createDocumentFragment();
  let last = 0;
  String(text).replace(URL_RE, (m, at) => {
    const url = m.replace(/[.,;:!?)]+$/, '');
    frag.append(D.createTextNode(text.slice(last, at)));
    const href = safeUrl(url);
    frag.append(href ? h('a', { href, target: sameSite(href) ? null : '_blank', rel: 'noopener noreferrer', text: url }) : D.createTextNode(url));
    last = at + url.length;
    return m;
  });
  frag.append(D.createTextNode(String(text).slice(last)));
  return frag;
}
const uid = () => (W.crypto && W.crypto.randomUUID ? W.crypto.randomUUID() : 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
const nowHM = () => new Date().toLocaleTimeString(LOCALE[lang], { hour: '2-digit', minute: '2-digit' });
function dayLabel(date) {
  const d0 = new Date(); d0.setHours(0, 0, 0, 0);
  const d1 = new Date(date); d1.setHours(0, 0, 0, 0);
  const diff = Math.round((d0 - d1) / 86400000);
  if (diff === 0) return t('today');
  if (diff === 1) return t('yesterday');
  return date.toLocaleDateString(LOCALE[lang], { day: 'numeric', month: 'long' });
}
function pageContext() {
  const p = C.page || {};
  return JSON.stringify({ page_type: p.page_type || 'page', product_id: p.product_id || 0, title: p.title || D.title || '', url: location.href.split('#')[0] });
}

/* ================================================================ server */
let boot = null;
let booting = null;
class Fail extends Error { constructor(message, code, status, data) { super(message); this.code = code; this.status = status; this.data = data || {}; } }
async function call(action, data, opt = {}) {
  const fd = data instanceof FormData ? data : new FormData();
  if (!(data instanceof FormData)) {
    for (const k of Object.keys(data || {})) {
      const v = data[k];
      if (v !== undefined && v !== null) fd.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
    }
  }
  fd.set('action', action);
  fd.set('language', lang);
  if (opt.nonce === 'chat') fd.set('nonce', boot ? boot.nonce : '');
  if (opt.nonce === 'groups') fd.set('nonce', boot && boot.groups ? boot.groups.nonce : '');
  const ctrl = W.AbortController ? new AbortController() : null;
  const timer = setTimeout(() => ctrl && ctrl.abort(), opt.timeout || 20000);
  let r = null;
  let j = null;
  try {
    r = await fetch(AJAX, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: fd, signal: ctrl ? ctrl.signal : undefined, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    j = await r.json().catch(() => null);
  } catch (e) {
    throw new Fail(navigator.onLine === false ? t('offline') : t('error'), 'network', 0);
  } finally { clearTimeout(timer); }
  if (j && j.success) return j.data || {};
  const d = (j && j.data) || {};
  // A nonce minted before a long pause (or a login) expires: fetch fresh ones once and replay.
  if (d.code === 'bad_nonce' && opt.nonce && !opt.retried) {
    boot = null;
    await ensureBoot();
    return call(action, data, Object.assign({}, opt, { retried: true }));
  }
  throw new Fail(d.message || t('error'), d.code || 'http', r ? r.status : 0, d);
}
function ensureBoot() {
  if (boot) return Promise.resolve(boot);
  if (!booting) {
    booting = call('dsa7_boot', {}).then((d) => { boot = d; booting = null; applyBoot(); return d; }, (e) => { booting = null; throw e; });
  }
  return booting;
}

/* ================================================================ state */
const S = { open: false, inline: false, view: 'home', sheet: null, lastActivity: Date.now() };
const chat = { items: [], last: 0, agentRead: 0, typing: false, status: '', unread: 0, loaded: false, thread: false, readSent: 0, pending: new Map() };
const G = { list: null, room: null, msgs: [], last: 0, more: false, rules: [], replyTo: null, ask: false, loading: false, totalUnread: 0, slowUntil: 0 };

let host = null;
let shadow = null;
let app = null;
let scrim = null;
let titleEl = null;
let subEl = null;
let dotEl = null;
let backBtn = null;
let menuBtn = null;
let bodyEl = null;
let tabsEl = null;
let returnFocus = null;
let pollTimer = 0;
const V = {}; // current view's live nodes (log, input, …)

/* ================================================================ shell */
function build(mountPoint) {
  host = mountPoint || h('div', { id: 'dsa7-app', 'data-delicat-keep': true });
  if (!mountPoint) D.body.appendChild(host);
  if (C.side === 'left') host.setAttribute('data-side', 'left');
  shadow = host.attachShadow({ mode: 'open' });
  shadow.appendChild(h('style', { text: CSS }));
  if (!S.inline) {
    scrim = h('div', { class: 'scrim', onclick: () => close() });
    shadow.appendChild(scrim);
  }
  titleEl = h('h2', { id: 'dsa7-title' });
  dotEl = h('i', { class: 'dot' });
  subEl = h('span');
  backBtn = h('button', { class: 'ib', type: 'button', 'aria-label': t('back'), hidden: true, onclick: () => goBack() }, icon('arrow_back'));
  menuBtn = h('button', { class: 'ib', type: 'button', 'aria-label': t('more'), hidden: true, onclick: () => viewMenu() }, icon('more_vert'));
  const top = h('header', { class: 'top' },
    backBtn,
    h('span', { class: 'mark', 'aria-hidden': 'true' }, icon('support_agent')),
    h('div', { class: 'ttl' }, titleEl, h('p', null, dotEl, subEl)),
    menuBtn,
    S.inline ? null : h('button', { class: 'ib', type: 'button', 'aria-label': t('close'), onclick: () => close() }, icon('close')),
  );
  bodyEl = h('div', { class: 'body' });
  tabsEl = h('nav', { class: 'tabs', 'aria-label': C.brand || 'Delicat' });
  app = h('div', S.inline
    ? { class: 'app inline', role: 'region', 'aria-labelledby': 'dsa7-title' }
    : { class: 'app', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'dsa7-title' }, top, bodyEl, tabsEl);
  if (C.still) app.classList.add('still');
  if (C.scale && C.scale !== 100) app.style.setProperty('--a-scale', String(C.scale / 100));
  shadow.appendChild(app);
  shadow.addEventListener('keydown', onKey);
  // Until boot answers, the Team tab shows the count the launcher badge already knows.
  const known = D.querySelector('#dsa7-launcher .dsa7-badge');
  if (known && !known.hidden) chat.unread = parseInt(known.textContent, 10) || 0;
  app.addEventListener('pointerdown', () => { S.lastActivity = Date.now(); }, { passive: true });
  renderTabs();
  if (W.visualViewport && !S.inline) {
    W.visualViewport.addEventListener('resize', fitViewport);
    W.visualViewport.addEventListener('scroll', fitViewport);
  }
  D.addEventListener('visibilitychange', () => { if (!D.hidden && (S.open || S.inline)) schedule(400); });
  // Escape also works when focus is still on the page (e.g. right after a mouse click on the launcher).
  D.addEventListener('keydown', (e) => { if (e.key === 'Escape' && S.open && !S.inline && !host.contains(e.target)) onKey(e); });
  W.addEventListener('online', () => { if (S.open || S.inline) schedule(200); });
}
function fitViewport() {
  if (!app || S.inline || !W.matchMedia('(max-width: 640px)').matches) { if (app) { app.style.removeProperty('--vvh'); app.style.top = ''; } return; }
  const v = W.visualViewport;
  app.style.setProperty('--vvh', v.height + 'px');
  app.style.top = v.offsetTop + 'px';
}
function renderTabs() {
  tabsEl.replaceChildren();
  const tabs = [['home', 'home', t('home')], ['bot', 'smart_toy', t('bot')], ['agent', 'support_agent', t('team')]];
  if (!boot || (boot.groups && boot.groups.enabled)) tabs.push(['community', 'groups', t('community')]);
  for (const [id, ic, label] of tabs) {
    const n = id === 'agent' ? chat.unread : (id === 'community' ? G.totalUnread : 0);
    tabsEl.append(h('button', { class: 'tab', type: 'button', 'aria-current': S.view === id ? 'page' : null, onclick: () => show(id) },
      h('span', { class: 'pill' }, icon(ic)), label, n > 0 ? h('span', { class: 'n', 'aria-label': String(n) }, n > 9 ? '9+' : String(n)) : null));
  }
}
function setHeader(title, sub, state) {
  titleEl.textContent = title;
  subEl.textContent = sub || '';
  dotEl.className = 'dot' + (state ? ' ' + state : '');
  dotEl.hidden = !state;
}
function teamStatusLine() {
  if (!boot) return [t('loading'), ''];
  const st = boot.status === 'online' ? 'online' : (boot.status === 'away' ? 'away' : '');
  const label = st === 'online' ? t('online') : (st === 'away' ? t('away') : t('offTeam'));
  return [boot.eta ? label + ' · ' + t('eta', boot.eta) : label, st || 'off'];
}
function applyBoot() {
  if (!app) return;
  if (boot.unread !== undefined) chat.unread = +boot.unread || 0;
  chat.thread = !!boot.thread || chat.thread;
  syncBadge();
  renderTabs();
}
function syncBadge() {
  if (W.DelicatAssistant && W.DelicatAssistant.badge) W.DelicatAssistant.badge(chat.unread);
}
function toast(msg) {
  const old = shadow.querySelector('.toast');
  if (old) old.remove();
  const el = h('div', { class: 'toast', role: 'status' }, msg);
  app.appendChild(el);
  setTimeout(() => el.remove(), 2600);
}
function errorBox(msg, retry) {
  return h('div', { class: 'err', role: 'alert' }, icon('error', 's'), h('span', null, msg), retry ? h('button', { type: 'button', onclick: retry }, t('retry')) : null);
}

/* ================================================================ open / close */
async function open(opts = {}) {
  if (S.inline) { host.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
  if (!host) build(null);
  returnFocus = D.activeElement;
  const target = opts.view === 'team' ? 'agent' : (opts.view === 'help' ? 'bot' : (['home', 'bot', 'agent', 'community'].includes(opts.view) ? opts.view : null));
  if (!S.open) {
    S.open = true;
    D.documentElement.classList.add('dsa7-open');
    D.body.classList.add('dbv9-assistant-open');
    const launcher = D.getElementById('dsa7-launcher');
    if (launcher) launcher.setAttribute('aria-expanded', 'true');
    fitViewport();
    requestAnimationFrame(() => { app.classList.add('on'); if (scrim) scrim.classList.add('on'); });
  }
  show(target || S.view || 'home', true);
  if (opts.guide) quick('guide_' + opts.guide);
  ensureBoot().then(afterBoot).catch(() => {});
  // Focus moves into the dialog: the composer if there is one, else the first visible control.
  setTimeout(() => {
    const f = shadow.querySelector('.input') || [...shadow.querySelectorAll('.top button')].find((b) => !b.hidden);
    if (f) f.focus({ preventScroll: true });
  }, 60);
}
function close() {
  if (!S.open) return;
  S.open = false;
  closeSheet();
  app.classList.remove('on');
  if (scrim) scrim.classList.remove('on');
  D.documentElement.classList.remove('dsa7-open');
  D.body.classList.remove('dbv9-assistant-open');
  const launcher = D.getElementById('dsa7-launcher');
  if (launcher) launcher.setAttribute('aria-expanded', 'false');
  clearTimeout(pollTimer);
  (returnFocus && returnFocus.isConnected ? returnFocus : launcher || D.body).focus({ preventScroll: true });
}
function mount(el) {
  if (host) return;
  S.inline = true;
  el.replaceChildren();
  build(el);
  S.open = true;
  const view = el.getAttribute('data-view');
  show(view === 'community' ? 'community' : (['bot', 'agent'].includes(view) ? view : 'home'), true);
  ensureBoot().then(() => { afterBoot(); if (view === 'community' && el.getAttribute('data-group')) openRoom(el.getAttribute('data-group')); }).catch(() => {});
}
/* The boot answer refreshes what depends on it without rebuilding a view the visitor may be typing in. */
function afterBoot() {
  if (S.view === 'home') renderHome();
  else if (S.view === 'agent') setHeader(t('teamTitle'), ...teamStatusLine());
  else if (S.view === 'community' && !G.room) renderCommunity();
  if ((S.view === 'bot' || S.view === 'agent') && !chat.loaded && (chat.thread || boot.thread)) loadChat();
  schedule(1500);
}
function onKey(e) {
  if (e.key === 'Escape') {
    e.stopPropagation();
    if (S.sheet) closeSheet();
    else if (S.view === 'community' && G.room) goBack();
    else if (!S.inline) close();
    return;
  }
  if (e.key !== 'Tab' || S.inline) return;
  const items = [...shadow.querySelectorAll('button:not([disabled]),[href],input:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')]
    .filter((el) => el.offsetParent !== null && !el.closest('[hidden]') && (!S.sheet || S.sheet.contains(el)));
  if (!items.length) return;
  const first = items[0];
  const last = items[items.length - 1];
  const active = shadow.activeElement;
  if (e.shiftKey && (active === first || !active)) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
}

/* ================================================================ routing */
function show(view, force) {
  if (!force && view === S.view) {
    if (view === 'community' && G.room) G.room = null; // the Community tab inside a room goes back to the list
    else return;
  }
  if (view !== 'community') G.room = null;
  S.view = view;
  closeSheet();
  for (const k of Object.keys(V)) delete V[k];
  backBtn.hidden = !(view === 'community' && G.room);
  menuBtn.hidden = view !== 'agent' && !(view === 'community' && G.room);
  renderTabs();
  if (view === 'home') renderHome();
  else if (view === 'bot' || view === 'agent') renderChat(view);
  else renderCommunity();
}
function goBack() {
  if (S.view === 'community' && G.room) { G.room = null; show('community', true); }
}

/* ================================================================ home */
function renderHome() {
  setHeader((boot && boot.brand) || C.brand || 'Delicat', ...teamStatusLine());
  const name = boot && boot.user && boot.user.name;
  const tiles = [['sports_esports', 'tRecharge', 'recharge'], ['receipt_long', 'tOrder', 'order_center'], ['key', 'tPin', 'pin'], ['account_balance_wallet', 'tWallet', 'balance'], ['menu_book', 'tGuides', 'guides'], ['help', 'tFaq', 'faq']];
  const isProduct = C.page && C.page.page_type === 'product' && C.page.product_id;
  const [statusText] = teamStatusLine();
  const view = h('div', { class: 'view' }, h('div', { class: 'scroll' }, h('div', { class: 'home' },
    h('div', { class: 'hello' }, h('h3', null, name ? t('hello', name) : t('helloAnon')), h('p', null, t('helloSub'))),
    isProduct ? h('button', { class: 'big', type: 'button', onclick: () => quick('product_context') },
      h('span', { class: 'ico' }, icon('sports_esports')),
      h('span', null, h('b', null, t('tProduct')), h('small', null, clean(C.page.title || ''))), icon('chevron_right', 'go')) : null,
    h('button', { class: 'big', type: 'button', onclick: () => show('bot') },
      h('span', { class: 'ico' }, icon('smart_toy')), h('span', null, h('b', null, t('instant')), h('small', null, t('instantSub'))), icon('chevron_right', 'go')),
    h('button', { class: 'big team', type: 'button', onclick: () => show('agent') },
      h('span', { class: 'ico' }, icon('support_agent')), h('span', null, h('b', null, t('talk')), h('small', null, boot ? statusText : t('talkSub'))), icon('chevron_right', 'go')),
    (!boot || (boot.groups && boot.groups.enabled)) ? h('button', { class: 'big', type: 'button', onclick: () => show('community') },
      h('span', { class: 'ico' }, icon('groups')), h('span', null, h('b', null, t('commTitle')), h('small', null, t('commSub'))), icon('chevron_right', 'go')) : null,
    h('p', { class: 'sec' }, t('topics')),
    h('div', { class: 'grid' }, tiles.map(([ic, key, action]) => h('button', { class: 'tile', type: 'button', onclick: () => quick(action) }, icon(ic), t(key)))),
    boot && boot.whatsapp ? h('a', { class: 'wa', href: waLink(boot.whatsapp), target: '_blank', rel: 'noopener noreferrer' }, waIcon(), t('whatsapp')) : null,
    h('div', { class: 'langs', role: 'group', 'aria-label': t('language') }, [['fr', 'FR'], ['en', 'EN'], ['ht', 'KR']].map(([code, label]) =>
      h('button', { type: 'button', 'aria-pressed': lang === code ? 'true' : 'false', lang: code, onclick: () => setLang(code) }, label))),
    h('p', { class: 'secure' }, icon('lock', 's'), t('secure')),
  )));
  bodyEl.replaceChildren(view);
}
function setLang(code) {
  lang = code;
  try { localStorage.setItem('dap_language', code); } catch (e) { /* storage blocked */ }
  backBtn.setAttribute('aria-label', t('back'));
  menuBtn.setAttribute('aria-label', t('more'));
  show(S.view, true);
}
function waLink(base) {
  const url = safeUrl(base);
  if (!url) return '#';
  const p = C.page || {};
  const intro = lang === 'en' ? 'Hello, I would like to talk to a Delicat Store agent' : (lang === 'ht' ? 'Bonjou, mwen ta renmen pale ak yon ajan Delicat Store' : 'Bonjour, je voudrais parler à un agent Delicat Store');
  const text = intro + (p.product_id && p.title ? ' (' + p.title + ')' : '') + ' : ' + location.href.split('#')[0];
  return url + (url.indexOf('?') > -1 ? '&' : '?') + 'text=' + encodeURIComponent(text);
}

/* ================================================================ support chat (assistant + team) */
function renderChat(kind) {
  if (kind === 'bot') setHeader(t('botTitle'), t('botSub'), 'online');
  else setHeader(t('teamTitle'), ...teamStatusLine());
  const log = h('div', { class: 'log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' });
  const scroll = h('div', { class: 'scroll' }, log);
  const jump = h('button', { class: 'jump', type: 'button', hidden: true, 'aria-label': t('jump'), onclick: () => toBottom(true) }, icon('keyboard_arrow_down'));
  const input = h('textarea', { class: 'input', rows: '1', maxlength: '1500', placeholder: kind === 'bot' ? t('inBot') : t('inTeam'), 'aria-label': kind === 'bot' ? t('inBot') : t('inTeam') });
  const send = h('button', { class: 'send', type: 'button', disabled: true, 'aria-label': t('send') }, icon('send'));
  const file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', hidden: true });
  const attach = kind === 'agent' ? h('button', { class: 'ib', type: 'button', 'aria-label': t('attach'), onclick: () => file.click() }, icon('image')) : null;
  const chips = kind === 'bot' ? h('div', { class: 'chips' }, [['tRecharge', 'recharge'], ['tOrder', 'order_center'], ['tPin', 'pin'], ['tWallet', 'balance'], ['tGuides', 'guides'], ['team', 'agent']].map(([k, a]) =>
    h('button', { type: 'button', onclick: () => quick(a) }, t(k)))) : null;
  const composer = h('div', { class: 'composer' }, chips, h('div', { class: 'row' }, attach, input, send));
  const view = h('div', { class: 'view' }, h('div', { class: 'body', style: 'flex:1;min-height:0' }, scroll, jump), composer, file);
  bodyEl.replaceChildren(view);
  Object.assign(V, { kind, log, scroll, input, send, jump });

  const resize = () => { input.style.height = 'auto'; input.style.height = Math.min(132, input.scrollHeight) + 'px'; };
  input.addEventListener('input', () => { resize(); send.disabled = !input.value.trim(); if (kind === 'agent') typingPing(); });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && W.matchMedia('(min-width: 641px)').matches) { e.preventDefault(); submit(); } });
  const submit = () => { const v = input.value.trim(); if (!v) return; input.value = ''; resize(); send.disabled = true; sendChat(kind, v); };
  send.addEventListener('click', submit);
  file.addEventListener('change', () => { if (file.files && file.files[0]) uploadChat(file.files[0]); file.value = ''; });
  scroll.addEventListener('scroll', () => { jump.hidden = nearBottom(); }, { passive: true });
  log.addEventListener('click', onCardClick);

  paintChat(true);
  if (!chat.loaded && (chat.thread || (boot && boot.thread))) loadChat();
}
/* Two workspaces over one thread: automated answers (channel bot) and the human conversation (channel
 * agent). System-channel rows are bookkeeping and stay hidden, as in every earlier version. */
function visibleIn(kind, m) {
  const ch = ['bot', 'agent', 'system'].includes(m.channel) ? m.channel : (m.sender === 'agent' ? 'agent' : 'bot');
  return ch === kind;
}
function paintChat(scroll) {
  if (!V.log) return;
  const kind = V.kind;
  let items = chat.items.filter((m) => visibleIn(kind, m));
  if (kind === 'bot' && items.length > 30) items = items.slice(-30); // years of automated history are not useful here
  const frag = D.createDocumentFragment();
  if (!items.length && !chat.pending.size) {
    if (kind === 'bot') frag.append(botBubble({ sender: 'assistant', type: 'text', body: t('welcome') }));
    else frag.append(h('div', { class: 'empty' }, h('div', { class: 'ico' }, icon('support_agent', 'l')), h('b', null, t('emptyTeam')), t('emptyTeamSub')));
  }
  for (const m of items) frag.append(messageEl(m, kind));
  for (const p of chat.pending.values()) if (p.kind === kind) frag.append(p.el);
  if (kind === 'agent' && chat.typing) frag.append(h('div', { class: 'msg' }, h('div', { class: 'bub typing', 'aria-label': t('typing') }, h('i'), h('i'), h('i'))));
  V.log.replaceChildren(frag);
  if (scroll) toBottom(false);
}
function appendChat(newItems) {
  if (!V.log) return;
  const kind = V.kind;
  const stick = nearBottom();
  const fresh = newItems.filter((m) => visibleIn(kind, m));
  if (!fresh.length) return;
  // Simplest correct update: repaint (bounded list), then keep the reader where they were.
  paintChat(false);
  if (stick) toBottom(true);
  else V.jump.hidden = false;
}
function nearBottom() {
  if (!V.scroll) return true;
  return V.scroll.scrollHeight - V.scroll.scrollTop - V.scroll.clientHeight < 120;
}
function toBottom(smooth) {
  if (!V.scroll) return;
  V.scroll.scrollTo({ top: V.scroll.scrollHeight, behavior: smooth && !C.still ? 'smooth' : 'auto' });
  if (V.jump) V.jump.hidden = true;
}
function ingest(list) {
  const fresh = [];
  for (const m of list || []) {
    const id = +m.id || 0;
    if (!id || id <= chat.last) continue;
    if (m.sender === 'system' && m.type === 'system' && m.meta && m.meta.order) continue; // old order receipts
    chat.items.push(m);
    chat.last = id;
    fresh.push(m);
  }
  if (chat.items.length > 400) chat.items = chat.items.slice(-300);
  if (!fresh.length) return;
  chat.thread = true;
  chat.loaded = true;
  if (V.log) appendChat(fresh);
  const fromTeam = fresh.filter((m) => m.sender === 'agent');
  if (fromTeam.length && S.view !== 'agent') { chat.unread += fromTeam.length; renderTabs(); syncBadge(); }
  if (S.view === 'agent' && S.open) markRead();
}
async function loadChat() {
  try {
    await ensureBoot();
    const d = await call('dsa2_load_messages', { after_id: chat.last, page_product: (C.page && C.page.product_id) || 0, page_title: (C.page && C.page.title) || '', context: pageContext() }, { nonce: 'chat' });
    chat.loaded = true;
    if (d.agent_read_id) chat.agentRead = Math.max(chat.agentRead, +d.agent_read_id);
    chat.typing = !!d.agent_typing;
    chat.status = d.status || chat.status;
    if (d.unread_count !== undefined) chat.unread = +d.unread_count || 0;
    ingest(d.messages);
    paintChat(true);
    renderTabs();
    syncBadge();
    if (S.view === 'agent') markRead();
  } catch (e) {
    if (V.log) V.log.append(errorBox(e.message, () => { V.log.lastChild.remove(); loadChat(); }));
  }
}
function pendingEl(kind, text, state, onRetry) {
  const meta = h('div', { class: 'meta' });
  const el = h('div', { class: 'msg me' }, h('div', { class: 'bub' }, text), meta);
  setDelivery(meta, state, onRetry);
  return el;
}
function setDelivery(meta, state, onRetry) {
  meta.replaceChildren();
  if (state === 'sending') put(meta, icon('schedule', 's'), t('sending'));
  else if (state === 'failed') put(meta, h('span', { class: 'fail' }, t('failed')), onRetry ? h('button', { type: 'button', onclick: onRetry }, t('retry')) : null);
  else if (state === 'read') put(meta, h('span', { class: 'read' }, icon('done_all', 's')), t('read'));
  else if (state) put(meta, icon('check', 's'), t('sent'));
}
async function sendChat(kind, text, clientId) {
  S.lastActivity = Date.now();
  const id = clientId || uid();
  const old = chat.pending.get(id);
  const el = pendingEl(kind, text, 'sending');
  chat.pending.set(id, { kind, el, text });
  if (old && old.el.isConnected) old.el.replaceWith(el); else if (V.log && V.kind === kind) { const em = V.log.querySelector('.empty'); if (em) em.remove(); V.log.append(el); toBottom(true); }
  try {
    await ensureBoot();
    const d = await call('dsa2_send_message', { message: text, intent: kind === 'agent' ? 'agent' : 'bot', after_id: chat.last, client_message_id: id, context: pageContext() }, { nonce: 'chat' });
    chat.pending.delete(id);
    if (d.agent_read_id) chat.agentRead = Math.max(chat.agentRead, +d.agent_read_id);
    el.remove();
    ingest(d.messages);
    paintChat(false);
    toBottom(true);
    schedule(2500);
  } catch (e) {
    setDelivery(el.querySelector('.meta'), 'failed', () => sendChat(kind, text, id));
    if (V.log) { V.log.append(errorBox(e.message)); toBottom(true); }
  }
}
async function quick(action) {
  S.lastActivity = Date.now();
  const target = action === 'agent' ? 'agent' : (action === 'end_chat' ? S.view : 'bot');
  if (S.view !== target) show(target);
  const typing = h('div', { class: 'msg' }, h('div', { class: 'bub typing' }, h('i'), h('i'), h('i')));
  if (V.log) { V.log.append(typing); toBottom(true); }
  try {
    await ensureBoot();
    const d = await call('dsa2_quick_action', { quick_action: action, after_id: chat.last, context: pageContext() }, { nonce: 'chat' });
    typing.remove();
    ingest(d.messages);
    paintChat(false);
    toBottom(true);
    schedule(2500);
  } catch (e) {
    typing.remove();
    if (V.log) V.log.append(errorBox(e.message, () => quick(action)));
  }
}
async function chatForm(action, data, button) {
  if (button) button.disabled = true;
  try {
    await ensureBoot();
    const d = await call(action, Object.assign({ after_id: chat.last }, data), { nonce: 'chat' });
    ingest(d.messages);
    paintChat(false);
    toBottom(true);
  } catch (e) {
    if (button) button.disabled = false;
    toast(e.message);
  }
}
async function shrinkImage(file, maxBytes) {
  if (file.size <= maxBytes || !/^image\/(jpeg|png|webp)$/.test(file.type) || !W.createImageBitmap) return file;
  try {
    const bmp = await createImageBitmap(file);
    const scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
    const canvas = D.createElement('canvas');
    canvas.width = Math.round(bmp.width * scale);
    canvas.height = Math.round(bmp.height * scale);
    canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', 0.82));
    return blob && blob.size < file.size ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file;
  } catch (e) { return file; }
}
async function uploadChat(original) {
  if (!/^image\//.test(original.type)) { toast(t('onlyImages')); return; }
  const file = await shrinkImage(original, 1.5 * 1024 * 1024);
  if (file.size > 5 * 1024 * 1024) { toast(t('tooBig', 5)); return; }
  const id = uid();
  const el = pendingEl('agent', t('image'), 'sending');
  chat.pending.set(id, { kind: 'agent', el });
  if (V.log) { V.log.append(el); toBottom(true); }
  try {
    await ensureBoot();
    const fd = new FormData();
    fd.append('file', file);
    fd.append('intent', 'agent');
    fd.append('after_id', String(chat.last));
    fd.append('context', pageContext());
    const d = await call('dsa2_upload', fd, { nonce: 'chat', timeout: 60000 });
    chat.pending.delete(id);
    el.remove();
    ingest(d.messages);
    paintChat(false);
    toBottom(true);
  } catch (e) {
    chat.pending.delete(id);
    el.remove();
    toast(e.message);
  }
}
let lastTypingPing = 0;
function typingPing() {
  if (!boot || !chat.thread || Date.now() - lastTypingPing < 6000) return;
  lastTypingPing = Date.now();
  call('dsa2_typing', { typing: 1 }, { nonce: 'chat' }).catch(() => {});
}
async function markRead() {
  const ids = chat.items.filter((m) => m.sender === 'agent' && m.channel === 'agent').map((m) => +m.id);
  const top = ids.length ? Math.max(...ids) : 0;
  if (!top || top <= chat.readSent || !boot) { if (chat.unread) { chat.unread = 0; renderTabs(); syncBadge(); } return; }
  chat.readSent = top;
  try {
    const d = await call('dsa2_mark_read', { last_id: top }, { nonce: 'chat' });
    chat.unread = +d.unread_count || 0;
  } catch (e) { chat.readSent = 0; }
  renderTabs();
  syncBadge();
}

/* ---------------------------------------------------------------- message + card rendering */
function botBubble(m) {
  return h('div', { class: 'msg' }, h('div', { class: 'bub' }, clean(m.body)));
}
function messageEl(m, kind) {
  const me = m.sender === 'customer';
  const type = m.type || 'text';
  if (type === 'image') {
    const url = safeUrl(m.meta && m.meta.url);
    return h('div', { class: 'msg img' + (me ? ' me' : '') },
      url ? h('a', { href: url, target: '_blank', rel: 'noopener noreferrer' }, h('img', { src: url, alt: t('image'), loading: 'lazy', decoding: 'async' })) : h('div', { class: 'bub' }, t('image')),
      metaLine(m, me, kind));
  }
  if (type === 'deleted') return h('div', { class: 'msg' + (me ? ' me' : '') }, h('div', { class: 'bub', style: 'font-style:italic;opacity:.7' }, t('deleted')));
  if (type === 'text' || type === 'rating') {
    const body = type === 'rating' ? ((m.meta && m.meta.title) || m.body) : m.body;
    const text = me || m.sender === 'agent' ? String(body || '') : clean(body);
    const who = m.sender === 'agent' ? h('div', { class: 'who' }, h('span', { class: 'staff' }, icon('verified', 's'), m.sender_name || t('teamLabel'))) : null;
    return h('div', { class: 'msg' + (me ? ' me' : '') + (m.sender === 'agent' ? ' staffmsg' : '') }, who, h('div', { class: 'bub' }, richText(text)), metaLine(m, me, kind));
  }
  if (m.sender === 'system' && type === 'system' && !(m.meta && (m.meta.buttons || m.meta.title))) return h('div', { class: 'sysline' }, clean(m.body));
  return card(m);
}
function metaLine(m, me, kind) {
  const meta = h('div', { class: 'meta' }, m.time || '');
  if (me && kind === 'agent' && m.id) {
    const read = +m.id <= chat.agentRead;
    put(meta, read ? h('span', { class: 'read', 'aria-label': t('read') }, icon('done_all', 's')) : h('span', { 'aria-label': t('sent') }, icon('check', 's')));
  }
  return meta;
}
const CARD_ICON = { account_card: 'lock', order_card: 'receipt_long', agent_card: 'support_agent', guide_card: 'menu_book', guide_menu: 'menu_book', contact_card: 'person', faq_menu: 'help', product_list: 'sports_esports', help_card: 'help', rate_card: 'verified', faq_card: 'info', link_card: 'open_in_new', system: 'info' };
function actionButtons(list, primaryFirst) {
  return (list || []).map((b, i) => {
    const a = String(b.action || '');
    const v = String(b.value || '');
    const label = clean(b.label) || t('open');
    const cls = 'btn' + (primaryFirst && i === 0 ? ' pri' : '');
    if (a === 'url') {
      const mapped = (boot && boot.urls && boot.urls[v]) || v;
      const href = safeUrl(mapped);
      return href ? h('a', { class: cls, href, target: sameSite(href) ? null : '_blank', rel: 'noopener noreferrer' }, label) : null;
    }
    if (a === 'whatsapp') {
      const wa = (boot && boot.whatsapp) || v;
      return safeUrl(wa) ? h('a', { class: 'btn', href: waLink(wa), target: '_blank', rel: 'noopener noreferrer' }, waIcon(), label) : null;
    }
    return h('button', { class: cls, type: 'button', data: { act: a, val: v } }, label);
  });
}
function chipButtons(list) {
  if (!list || !list.length) return null;
  return h('div', { class: 'chips' }, list.map((b) => h('button', { type: 'button', data: { act: String(b.action || 'quick'), val: String(b.value || '') } }, clean(b.label))));
}
function card(m) {
  const x = m.meta || {};
  const type = m.type;
  const head = (title, ic) => h('h4', null, icon(ic || CARD_ICON[type] || 'info'), clean(title));
  const intro = x.intro ? h('p', null, clean(x.intro)) : null;
  const el = h('div', { class: 'card', data: { type } });
  if (type === 'account_card') {
    put(el, h('span', { class: 'chip-s' + (x.status === 'secure' ? ' ok' : '') }, icon('lock', 's'), x.status === 'secure' ? t('secureTag') : t('accountTag')),
      head(x.title), h('div', { class: 'val' }, clean(x.value)), linkBtn(x.url, x.button));
  } else if (type === 'order_card' && x.orderLabel) {
    const num = h('input', { class: 'field', type: 'text', inputmode: 'numeric', maxlength: '20', placeholder: clean(x.orderLabel), 'aria-label': clean(x.orderLabel) });
    const proof = h('input', { class: 'field', type: 'text', maxlength: '190', placeholder: clean(x.proofLabel), 'aria-label': clean(x.proofLabel), autocomplete: 'email' });
    const go = h('button', { class: 'btn pri', type: 'button' }, clean(x.button) || t('open'));
    go.addEventListener('click', () => {
      if (!num.value.trim() || proof.value.trim().length < 5) { shake(el); return; }
      chatForm('dsa2_find_order', { order: num.value.trim(), proof: proof.value.trim() }, go);
    });
    put(el, head(x.title), intro, h('div', { class: 'form' }, num, proof, go), x.privacy ? h('p', { class: 'note' }, icon('lock', 's'), clean(x.privacy)) : null, actsRow(x.buttons));
  } else if (type === 'order_card') {
    put(el, head(x.title), h('p', null, x.empty ? clean(x.description) : [clean(x.status), clean(x.total), clean(x.date)].filter(Boolean).join(' · ')), linkBtn(x.url, x.button), chipButtons(x.chips));
  } else if (type === 'agent_card') {
    put(el, head(x.title), x.eta ? h('span', { class: 'chip-s' }, icon('schedule', 's'), clean(x.eta)) : null, x.description ? h('p', null, clean(x.description)) : null,
      x.order ? h('div', { class: 'order' }, h('b', null, clean(x.order.title)), h('span', null, [x.order.status, x.order.total, x.order.date].map(clean).filter(Boolean).join(' · ')), x.order.url ? linkBtn(x.order.url, t('viewOrder')) : null) : null,
      x.hint ? h('div', { class: 'tip' }, icon('lightbulb', 's'), clean(x.hint)) : null,
      x.whatsapp && safeUrl(x.whatsapp) ? h('div', { class: 'acts' }, h('a', { class: 'btn', href: waLink(x.whatsapp), target: '_blank', rel: 'noopener noreferrer' }, waIcon(), 'WhatsApp')) : null);
  } else if (type === 'guide_card') {
    const steps = h('ol', { class: 'steps' }, (x.steps || []).map((s, i) => {
      const img = safeUrl((x.images || [])[i]);
      return h('li', null, h('span', null, clean(s)), img ? h('img', { src: img, alt: '', loading: 'lazy', decoding: 'async' }) : null);
    }));
    const share = x.share && x.share.text ? h('button', { class: 'btn', type: 'button', data: { act: 'share' } }, icon('share', 's'), clean(x.share.label) || 'Partager') : null;
    if (share) share._share = x.share;
    put(el, head(x.title, 'menu_book'), intro, steps, x.tip ? h('div', { class: 'tip' }, icon('lightbulb', 's'), clean(x.tip)) : null,
      x.help ? h('p', { class: 'note' }, clean(x.help)) : null, h('div', { class: 'acts' }, actionButtons(x.buttons, true), share), chipButtons(x.chips));
  } else if (type === 'guide_menu' || type === 'faq_menu') {
    const act = type === 'guide_menu' ? 'guide' : 'faq';
    put(el, head(x.title), intro, h('div', { class: 'list' }, (x.items || []).map((it) =>
      h('button', { type: 'button', data: { act, val: String(it.id) } }, icon(act === 'guide' ? 'menu_book' : 'help', 's'), clean(it.title), icon('chevron_right', 's')))), actsRow(x.buttons));
  } else if (type === 'contact_card') {
    const name = h('input', { class: 'field', type: 'text', maxlength: '100', autocomplete: 'name', placeholder: clean(x.nameLabel), 'aria-label': clean(x.nameLabel) });
    const mail = h('input', { class: 'field', type: 'text', maxlength: '190', autocomplete: 'email', placeholder: clean(x.emailLabel), 'aria-label': clean(x.emailLabel) });
    const go = h('button', { class: 'btn pri', type: 'button' }, clean(x.button) || t('send'));
    go.addEventListener('click', () => {
      const n = name.value.trim();
      const em = mail.value.trim();
      const digits = em.replace(/\D+/g, '').length;
      if (n.length < 2 || !(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em) || (digits >= 8 && digits <= 15))) { shake(el); return; }
      chatForm('dsa2_set_contact', { name: n, email: em }, go);
    });
    put(el, head(x.title), intro, h('div', { class: 'form' }, name, mail, go), x.privacy ? h('p', { class: 'note' }, icon('lock', 's'), clean(x.privacy)) : null);
  } else if (type === 'product_list') {
    put(el, head(x.title), intro, h('div', { class: 'prods', role: 'list' }, (x.items || []).map((p) => {
      const href = safeUrl(p.url);
      const img = safeUrl(p.image);
      return h(href ? 'a' : 'div', { class: 'prod', role: 'listitem', href: href || null },
        img ? h('img', { src: img, alt: '', loading: 'lazy', decoding: 'async' }) : h('div', { class: 'ph' }, icon('sports_esports')),
        h('span', null, h('b', null, clean(p.name)), h('em', null, clean(p.price)), p.stock ? h('i', { class: p.inStock ? '' : 'out' }, clean(p.stock)) : null));
    })), x.help ? h('p', { class: 'note' }, clean(x.help)) : null, actsRow(x.buttons), chipButtons(x.chips));
  } else if (type === 'faq_card') {
    put(el, head(x.title || t('tFaq')), h('p', null, clean(m.body)), actsRow(x.buttons), chipButtons(x.chips));
  } else {
    // help_card, rate_card, link_card, system and anything added server-side later: render what it carries.
    const lead = x.description || x.intro || ((m.body || '').trim() === String(x.title || '').trim() ? '' : m.body);
    const steps = (x.steps || []).length ? h('ol', { class: 'steps' }, x.steps.map((s) => h('li', null, h('span', null, clean(s))))) : null;
    const items = (x.items || []).length ? h('div', { class: 'list' }, x.items.map((it) =>
      h('button', { type: 'button', data: { act: String(it.action || 'quick'), val: String(it.id || it.value || '') } }, icon('chevron_right', 's'), clean(it.title || it.label)))) : null;
    const buttons = (x.buttons || []).length ? actsRow(x.buttons) : (x.url ? h('div', { class: 'acts' }, linkBtn(x.url, x.button)) : null);
    put(el, head(x.title || ''), lead ? h('p', null, clean(lead)) : null, steps, items, buttons, chipButtons(x.chips));
  }
  return el;
}
function actsRow(list) {
  const btns = actionButtons(list, false).filter(Boolean);
  return btns.length ? h('div', { class: 'acts' }, btns) : null;
}
function linkBtn(url, label) {
  const href = safeUrl(url);
  return href ? h('a', { class: 'btn pri', href, target: sameSite(href) ? null : '_blank', rel: 'noopener noreferrer' }, clean(label) || t('open')) : null;
}
function shake(el) { el.classList.remove('shake'); void el.offsetWidth; el.classList.add('shake'); }
function onCardClick(e) {
  const b = e.target.closest('[data-act]');
  if (!b) return;
  e.preventDefault();
  const a = b.dataset.act;
  const v = b.dataset.val || '';
  if (a === 'agent') quick('agent');
  else if (a === 'guide') quick('guide_' + v);
  else if (a === 'faq') quick('faq_' + v);
  else if (a === 'rate') chatForm('dsa2_rate', { rating: v }, b);
  else if (a === 'quick') quick(v);
  else if (a === 'copy' && navigator.clipboard) navigator.clipboard.writeText(v).then(() => toast(t('copied')), () => {});
  else if (a === 'share') shareGuide(b._share || {});
  else if (v) quick(v);
}
async function shareGuide(s) {
  const url = safeUrl(s.url) || location.href;
  const text = String(s.text || '');
  if (navigator.share) {
    try { await navigator.share({ title: s.title || '', text, url }); return; } catch (e) { if (e && e.name === 'AbortError') return; }
  }
  // No 'noopener' feature here: with it window.open always returns null and the fallback would always run.
  const w = W.open('https://wa.me/?text=' + encodeURIComponent(text + '\n' + url), '_blank');
  if (w) { try { w.opener = null; } catch (e) { /* cross-origin already */ } }
  else if (navigator.clipboard) navigator.clipboard.writeText(text + '\n' + url).then(() => toast(t('shared')), () => {});
}

/* ================================================================ header menu (team / room) */
function viewMenu() {
  if (S.view === 'agent') {
    const opts = [{ icon: 'check', label: t('endChat'), run: () => quick('end_chat') }];
    if (boot && boot.whatsapp) opts.push({ icon: 'open_in_new', label: t('whatsapp'), run: () => W.open(waLink(boot.whatsapp), '_blank', 'noopener,noreferrer') });
    openSheet(t('teamTitle'), '', opts);
  } else if (S.view === 'community' && G.room) {
    roomMenu();
  }
}
function openSheet(title, text, options, extra) {
  closeSheet();
  const sheet = h('div', { class: 'sheet', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
    h('span', { class: 'grab', 'aria-hidden': 'true' }), title ? h('h5', null, title) : null, text ? h('p', null, text) : null, extra || null,
    (options || []).map((o) => h('button', { class: 'opt' + (o.bad ? ' bad' : ''), type: 'button', onclick: () => { closeSheet(); o.run(); } }, icon(o.icon), o.label)),
    h('button', { class: 'opt', type: 'button', onclick: () => closeSheet() }, icon('close'), t('cancel')));
  const wrap = h('div', { class: 'sheet-wrap', onclick: (e) => { if (e.target === wrap) closeSheet(); } }, sheet);
  app.appendChild(wrap);
  S.sheet = wrap;
  const first = sheet.querySelector('input,button.opt');
  if (first) first.focus();
  return sheet;
}
function closeSheet() {
  if (S.sheet) { S.sheet.remove(); S.sheet = null; }
}

/* ================================================================ community */
function gcall(action, data, opt) { return call(action, data, Object.assign({ nonce: 'groups' }, opt || {})); }
function renderCommunity() {
  if (G.room) { renderRoom(); return; }
  setHeader(t('commTitle'), t('commHead'));
  const wrap = h('div', { class: 'rooms' });
  bodyEl.replaceChildren(h('div', { class: 'view' }, h('div', { class: 'scroll' }, wrap)));
  V.rooms = wrap;
  if (!boot) { put(wrap, h('div', { class: 'loader', role: 'status', 'aria-label': t('loading') })); ensureBoot().then(() => { if (S.view === 'community' && !G.room) renderCommunity(); }, (e) => wrap.replaceChildren(errorBox(e.message, () => show('community', true)))); return; }
  const g = boot.groups || {};
  if (!g.enabled) { put(wrap, h('div', { class: 'empty' }, t('noRooms'))); return; }
  if (!boot.user || !boot.user.loggedIn) {
    const login = safeUrl(boot.urls && boot.urls.login);
    put(wrap, h('div', { class: 'empty' }, h('div', { class: 'ico' }, icon('groups', 'l')), h('b', null, t('commTitle')), h('p', null, t('signIn')),
      login ? h('a', { class: 'btn pri', href: login }, icon('login', 's'), t('signInBtn')) : null));
    return;
  }
  if (g.banned) { put(wrap, h('div', { class: 'banner warn' }, icon('block', 's'), t('banned'))); return; }
  put(wrap, h('div', { class: 'me-card' }, h('span', { class: 'av' + (g.isStaff ? ' staff' : '') }, initials(g.displayName)), h('span', null, t('yourName') + ' '), h('b', null, g.displayName),
    h('button', { class: 'btn', type: 'button', style: 'min-height:36px;padding:0 12px', onclick: () => editName() }, t('change'))));
  put(wrap, h('div', { class: 'banner' }, icon('shield', 's') , t('safety')));
  const list = h('div', { class: 'rooms', style: 'padding:0' }, h('div', { class: 'loader' }));
  put(wrap, list);
  loadRooms(list);
}
function initials(name) {
  const parts = String(name || '?').trim().split(/\s+/);
  return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}
async function loadRooms(list) {
  try {
    const d = await gcall('dsa2_groups_list', {});
    G.list = d.groups || [];
    G.totalUnread = G.list.reduce((s, r) => s + (+r.unread || 0), 0);
    renderTabs();
    if (!list.isConnected) return;
    list.replaceChildren();
    if (!G.list.length) { list.append(h('div', { class: 'empty' }, t('noRooms'))); return; }
    for (const r of G.list) {
      list.append(h('button', { class: 'room', type: 'button', onclick: () => openRoom(r.slug || r.id) },
        h('span', { class: 'ico' }, icon(r.private ? 'lock' : 'groups')),
        h('span', { style: 'min-width:0' }, h('b', null, r.name, r.locked ? icon('lock', 's') : null), h('small', null, r.description || t('members', r.members))),
        r.questions ? h('span', { class: 'q' }, icon('campaign', 's'), t('pending', r.questions)) : (r.unread ? h('span', { class: 'n' }, r.unread > 9 ? '9+' : String(r.unread)) : icon('chevron_right'))));
    }
  } catch (e) {
    if (list.isConnected) list.replaceChildren(errorBox(e.message, () => loadRooms(list)));
  }
}
function editName() {
  const input = h('input', { class: 'field', type: 'text', maxlength: '24', value: (boot.groups && boot.groups.displayName) || '', 'aria-label': t('nameTitle') });
  const save = h('button', { class: 'btn pri full', type: 'button' }, t('save'));
  const extra = h('div', { class: 'form', style: 'padding:0 6px 8px' }, input, h('span', { class: 'hint' }, t('nameHint')), save);
  openSheet(t('nameTitle'), '', [], extra);
  input.focus();
  save.addEventListener('click', async () => {
    save.disabled = true;
    try {
      const d = await gcall('dsa2_groups_profile', { display_name: input.value.trim() });
      boot.groups.displayName = d.displayName;
      closeSheet();
      show('community', true);
    } catch (e) { save.disabled = false; toast(e.message); }
  });
}
async function openRoom(id) {
  G.room = { slug: id, loading: true };
  G.msgs = [];
  G.last = 0;
  G.replyTo = null;
  G.ask = false;
  if (S.view !== 'community') S.view = 'community';
  show('community', true);
  try {
    await ensureBoot();
    const d = await gcall('dsa2_groups_open', { group: id });
    G.room = d.group;
    G.rules = d.rules || [];
    G.more = !!d.more;
    G.msgs = d.messages || [];
    G.last = G.msgs.length ? +G.msgs[G.msgs.length - 1].id : 0;
    if (S.view === 'community' && G.room) renderRoom();
    readRoom();
    schedule(3000);
  } catch (e) {
    G.room = null;
    show('community', true);
    toast(e.message);
  }
}
function renderRoom() {
  const r = G.room;
  backBtn.hidden = false;
  menuBtn.hidden = !r || r.loading;
  if (!r || r.loading) {
    setHeader(t('community'), t('loading'));
    bodyEl.replaceChildren(h('div', { class: 'view' }, h('div', { class: 'loader', role: 'status', 'aria-label': t('loading') })));
    return;
  }
  setHeader(r.name, r.locked ? t('locked') : t('members', r.members));
  const log = h('div', { class: 'log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' });
  const scroll = h('div', { class: 'scroll' }, log);
  const jump = h('button', { class: 'jump', type: 'button', hidden: true, 'aria-label': t('jump'), onclick: () => toBottom(true) }, icon('keyboard_arrow_down'));
  const pin = G.msgs.find((m) => +m.pinned && !+m.hidden);
  const pinbar = pin ? h('div', { class: 'pinbar' }, icon('keep', 's'), h('span', null, h('b', null, t('pinned') + ' · '), pin.body)) : null;
  const view = h('div', { class: 'view' }, pinbar, h('div', { class: 'body', style: 'flex:1;min-height:0' }, scroll, jump), roomComposer());
  bodyEl.replaceChildren(view);
  Object.assign(V, { kind: 'room', log, scroll, jump });
  scroll.addEventListener('scroll', () => { jump.hidden = nearBottom(); }, { passive: true });
  paintRoom();
  toBottom(false);
}
function roomComposer() {
  const r = G.room;
  const g = boot.groups || {};
  const box = h('div', { class: 'composer' });
  if (!+r.joined && !+r.archived && !+r.canPost) { // members join first; the team can answer anywhere
    put(box, h('button', { class: 'btn pri full', type: 'button', onclick: async (e) => {
      e.currentTarget.disabled = true;
      try { const d = await gcall('dsa2_groups_join', { group: r.id }); G.room = d.group; renderRoom(); } catch (err) { toast(err.message); e.currentTarget.disabled = false; }
    } }, icon('groups', 's'), t('join')));
    return box;
  }
  if (!+r.canPost) { put(box, h('div', { class: 'block' }, r.blockText || t('locked'))); return box; }
  const input = h('textarea', { class: 'input', rows: '1', maxlength: String(g.maxLength || 700), placeholder: G.ask ? t('inAsk') : t('inGroup'), 'aria-label': G.ask ? t('inAsk') : t('inGroup') });
  const send = h('button', { class: 'send', type: 'button', disabled: true, 'aria-label': t('send') }, icon('send'));
  const file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', hidden: true });
  const attach = g.images ? h('button', { class: 'ib', type: 'button', 'aria-label': t('attach'), onclick: () => file.click() }, icon('image')) : null;
  const staff = !!g.isStaff;
  const modes = staff ? null : h('div', { class: 'modes', role: 'group' },
    h('button', { type: 'button', 'aria-pressed': G.ask ? 'false' : 'true', onclick: () => { G.ask = false; renderRoomComposer(); } }, icon('groups', 's'), t('toGroup')),
    h('button', { type: 'button', 'aria-pressed': G.ask ? 'true' : 'false', onclick: () => { G.ask = true; G.replyTo = null; renderRoomComposer(); } }, icon('campaign', 's'), t('askTeam')));
  const ctx = G.replyTo ? h('div', { class: 'ctx' }, icon('reply', 's'), h('span', null, t('replyTo', G.replyTo.author) + ' — ' + G.replyTo.body),
    h('button', { class: 'ib', type: 'button', 'aria-label': t('cancel'), onclick: () => { G.replyTo = null; renderRoomComposer(); } }, icon('close', 's'))) : null;
  put(box, modes, G.ask ? h('p', { class: 'hint' }, t('askHint')) : null, ctx, h('div', { class: 'row' }, attach, input, send), file);
  const resize = () => { input.style.height = 'auto'; input.style.height = Math.min(132, input.scrollHeight) + 'px'; };
  input.addEventListener('input', () => { resize(); send.disabled = !input.value.trim() || Date.now() < G.slowUntil; });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && W.matchMedia('(min-width: 641px)').matches) { e.preventDefault(); send.click(); } });
  send.addEventListener('click', () => { const v = input.value.trim(); if (!v) return; postRoom(v, input, send); });
  file.addEventListener('change', () => { if (file.files && file.files[0]) uploadRoom(file.files[0]); file.value = ''; });
  V.roomInput = input;
  return box;
}
function renderRoomComposer() {
  const old = bodyEl.querySelector('.composer');
  const draft = V.roomInput ? V.roomInput.value : '';
  if (!old) return;
  const fresh = roomComposer();
  old.replaceWith(fresh);
  if (V.roomInput) { V.roomInput.value = draft; V.roomInput.dispatchEvent(new Event('input')); V.roomInput.focus(); }
}
function paintRoom() {
  if (!V.log || V.kind !== 'room') return;
  const frag = D.createDocumentFragment();
  if (G.more) {
    frag.append(h('button', { class: 'btn more', type: 'button', onclick: (e) => loadEarlier(e.currentTarget) }, t('earlier')));
  }
  if (G.rules.length && !G.more) {
    frag.append(h('div', { class: 'card' }, h('h4', null, icon('shield'), t('rules')), h('ul', { class: 'rules' }, G.rules.map((r) => h('li', null, r)))));
  }
  let lastDay = '';
  for (const m of G.msgs) {
    const at = m.at ? new Date(m.at) : null;
    const day = at ? at.toDateString() : '';
    if (day && day !== lastDay) { frag.append(h('div', { class: 'day' }, dayLabel(at))); lastDay = day; }
    frag.append(groupMessageEl(m));
  }
  V.log.replaceChildren(frag);
}
function groupMessageEl(m) {
  const staff = m.role === 'agent';
  const me = !!+m.mine;
  const at = m.at ? new Date(m.at).toLocaleTimeString(LOCALE[lang], { hour: '2-digit', minute: '2-digit' }) : '';
  const isQ = m.type === 'question';
  const who = h('div', { class: 'who' }, staff ? h('span', { class: 'staff' }, m.author, icon('verified', 's'), t('staff')) : m.author);
  const bub = h('div', { class: 'bub' },
    m.replyTo ? h('div', { class: 'quote' }, (m.replyTo.author || '') + ' : ' + (m.replyTo.excerpt || '')) : null,
    m.type === 'image' && safeUrl(m.url) ? h('a', { href: safeUrl(m.url), target: '_blank', rel: 'noopener noreferrer' }, h('img', { src: safeUrl(m.url), alt: t('image'), loading: 'lazy', style: 'max-width:220px;border-radius:12px;display:block' }))
      : richText(+m.hidden && !(boot.groups && boot.groups.canModerate) ? t('removed') : String(m.body || '')));
  const meta = h('div', { class: 'meta' }, at,
    isQ ? h('span', { class: 'qtag' + (m.question === 'answered' ? ' ok' : '') }, icon(m.question === 'answered' ? 'check' : (m.question === 'private' ? 'lock' : 'campaign'), 's'),
      m.question === 'answered' ? t('qAnswered') : (m.question === 'private' ? t('qPrivate') : t('qOpen'))) : null,
    +m.pinned ? h('span', null, icon('keep', 's')) : null);
  const acts = h('div', { class: 'gacts' });
  if (!+m.hidden) {
    if (G.room && +G.room.canPost) acts.append(h('button', { type: 'button', 'aria-label': t('reply'), title: t('reply'), onclick: () => { G.replyTo = { id: m.id, author: m.author, body: String(m.body || '').slice(0, 80) }; G.ask = false; renderRoomComposer(); if (V.roomInput) V.roomInput.focus(); } }, icon('reply', 's')));
    if (me && isQ && m.question === 'open') acts.append(h('button', { type: 'button', onclick: () => movePrivate(m) }, icon('lock', 's'), t('private')));
  }
  acts.append(h('button', { type: 'button', 'aria-label': t('more'), onclick: () => messageMenu(m) }, icon('more_vert', 's')));
  // Staff only (the server sends flags to moderators): which masking rules fired, and the report count.
  const flags = m.flags ? h('div', { class: 'flags' }, 'Filtres : ' + m.flags.replace(/,/g, ', ') + (m.reports ? ' · ' + m.reports + ' signalement(s)' : '')) : null;
  const col = h('div', { class: 'msg' + (me ? ' me' : '') + (staff ? ' staffmsg' : '') + (+m.hidden ? ' hiddenmsg' : '') }, who, isQ && !me ? h('span', { class: 'qtag' }, icon('campaign', 's'), t('question')) : null, bub, meta, flags, acts);
  return h('div', { class: 'gm' + (me ? ' me' : '') + (isQ ? ' q' : ''), data: { id: String(m.id) } }, h('span', { class: 'av' + (staff ? ' staff' : ''), 'aria-hidden': 'true' }, m.initials || initials(m.author)), col);
}
async function postRoom(text, input, send) {
  S.lastActivity = Date.now();
  send.disabled = true;
  const ask = G.ask;
  try {
    const d = await gcall(ask ? 'dsa2_groups_ask' : 'dsa2_groups_send', { group: G.room.id, message: text, after_id: G.last, reply_to: G.replyTo ? G.replyTo.id : 0 });
    input.value = '';
    input.dispatchEvent(new Event('input'));
    G.replyTo = null;
    if (ask) G.ask = false;
    mergeRoom(d.messages || []);
    if (d.group) G.room = d.group;
    if (d.filtered) toast(t('filtered'));
    renderRoomComposer();
    paintRoom();
    toBottom(true);
    schedule(2500);
  } catch (e) {
    send.disabled = false;
    if (e.code === 'slow_mode' && e.data.wait) { G.slowUntil = Date.now() + e.data.wait * 1000; setTimeout(() => { send.disabled = !input.value.trim(); }, e.data.wait * 1000); }
    toast(e.message);
  }
}
async function uploadRoom(file) {
  if (!/^image\//.test(file.type)) { toast(t('onlyImages')); return; }
  const small = await shrinkImage(file, 1.2 * 1024 * 1024);
  if (small.size > 4 * 1024 * 1024) { toast(t('tooBig', 4)); return; }
  try {
    const fd = new FormData();
    fd.append('group', String(G.room.id));
    fd.append('after_id', String(G.last));
    fd.append('file', small);
    const d = await gcall('dsa2_groups_upload', fd, { timeout: 60000 });
    mergeRoom(d.messages || []);
    paintRoom();
    toBottom(true);
  } catch (e) { toast(e.message); }
}
function mergeRoom(list) {
  for (const m of list) {
    // A team answer settles the question it quotes (the question row itself is older than after_id).
    if (m.type === 'answer' && m.replyTo) {
      const q = G.msgs.find((x) => +x.id === +m.replyTo.id && x.type === 'question');
      if (q) q.question = 'answered';
    }
    const id = +m.id;
    const i = G.msgs.findIndex((x) => +x.id === id);
    if (i > -1) G.msgs[i] = m; else G.msgs.push(m);
    if (id > G.last) G.last = id;
  }
  G.msgs.sort((a, b) => +a.id - +b.id);
  if (G.msgs.length > 300) { G.msgs = G.msgs.slice(-240); G.more = true; }
}
async function pollRoom() {
  if (!G.room || !G.room.id) return;
  const stick = nearBottom();
  const d = await gcall('dsa2_groups_poll', { group: G.room.id, after_id: G.last });
  const fresh = d.messages || [];
  if (d.group) G.room = d.group;
  if (!fresh.length) return;
  mergeRoom(fresh);
  if (S.view === 'community' && V.kind === 'room') {
    paintRoom();
    if (stick) toBottom(true); else if (V.jump) V.jump.hidden = false;
  }
  readRoom();
}
async function loadEarlier(btn) {
  btn.disabled = true;
  const first = G.msgs.length ? +G.msgs[0].id : 0;
  try {
    const d = await gcall('dsa2_groups_poll', { group: G.room.id, before_id: first });
    const older = d.messages || [];
    G.more = !!d.more;
    const before = V.scroll.scrollHeight;
    G.msgs = older.concat(G.msgs);
    paintRoom();
    V.scroll.scrollTop += V.scroll.scrollHeight - before;
  } catch (e) { btn.disabled = false; toast(e.message); }
}
function readRoom() {
  if (!G.room || !G.room.id || !G.last) return;
  gcall('dsa2_groups_read', { group: G.room.id, last_id: G.last }).catch(() => {});
  if (G.list) {
    const r = G.list.find((x) => +x.id === +G.room.id);
    if (r) { r.unread = 0; G.totalUnread = G.list.reduce((s, x) => s + (+x.unread || 0), 0); renderTabs(); }
  }
}
function messageMenu(m) {
  const g = boot.groups || {};
  const opts = [];
  if (!+m.mine && !+m.hidden) opts.push({ icon: 'flag', label: t('report'), run: () => reportMessage(m) });
  if (+m.mine && m.type === 'question' && m.question === 'open') opts.push({ icon: 'lock', label: t('private'), run: () => movePrivate(m) });
  if (g.canModerate) {
    if (m.type === 'question') opts.push(m.question === 'answered' ? { icon: 'refresh', label: t('reopen'), run: () => moderate('reopen', m) } : { icon: 'check', label: t('markAnswered'), run: () => moderate('answered', m) });
    opts.push({ icon: 'keep', label: t('pin'), run: () => moderate('pin', m) });
    opts.push(+m.hidden ? { icon: 'refresh', label: t('restore'), run: () => moderate('restore', m) } : { icon: 'visibility_off', label: t('hide'), run: () => moderate('hide', m) });
    opts.push({ icon: 'delete', label: t('del'), bad: true, run: () => moderate('delete', m) });
    if (!+m.mine && m.role !== 'agent') {
      opts.push({ icon: 'volume_off', label: t('mute'), run: () => moderate('mute', m, { minutes: 60 }) });
      opts.push({ icon: 'block', label: t('ban'), bad: true, run: () => moderate('ban', m) });
    }
  }
  openSheet(m.author, String(m.body || '').slice(0, 140), opts);
}
function roomMenu() {
  const g = boot.groups || {};
  const r = G.room;
  const opts = [{ icon: 'shield', label: t('rules'), run: () => openSheet(t('rules'), '', [], h('ul', { class: 'rules', style: 'margin:0 6px 10px' }, G.rules.map((x) => h('li', null, x)))) },
    { icon: 'edit', label: t('nameTitle'), run: () => editName() }];
  if (+r.joined) opts.push({ icon: 'logout', label: t('leave'), bad: true, run: async () => { try { const d = await gcall('dsa2_groups_leave', { group: r.id }); G.room = d.group; renderRoom(); } catch (e) { toast(e.message); } } });
  if (g.canModerate) opts.push({ icon: 'lock', label: t('lock'), run: () => moderate('lock', null, { group_id: r.id, locked: +r.locked ? 0 : 1 }) });
  openSheet(r.name, '', opts);
}
function reportMessage(m) {
  const reasons = [['scam', 'rScam'], ['abuse', 'rAbuse'], ['spam', 'rSpam'], ['other', 'rOther']];
  openSheet(t('reportTitle'), '', reasons.map(([code, key]) => ({ icon: 'flag', label: t(key), run: async () => {
    try { await gcall('dsa2_groups_report', { message_id: m.id, reason: code }); toast(t('reported')); } catch (e) { toast(e.message); }
  } })));
}
function movePrivate(m) {
  openSheet(t('privateTitle'), t('privateText'), [{ icon: 'lock', label: t('private'), run: async () => {
    try {
      await gcall('dsa2_groups_private', { message_id: m.id });
      m.question = 'private';
      toast(t('privateDone'));
      chat.thread = true;
      chat.loaded = false;
      G.room = null;
      show('agent', true);
      loadChat();
    } catch (e) { toast(e.message); }
  } }]);
}
async function moderate(op, m, extra) {
  try {
    const d = await gcall('dsa2_groups_moderate', Object.assign({ op, message_id: m ? m.id : 0, group_id: G.room ? G.room.id : 0 }, extra || {}));
    if (d.group) G.room = d.group;
    if (d.messages) { G.msgs = d.messages; G.last = G.msgs.length ? Math.max(G.last, +G.msgs[G.msgs.length - 1].id) : G.last; }
    renderRoom();
    toast(t('done'));
  } catch (e) { toast(e.message); }
}

/* ================================================================ polling */
function schedule(delay) {
  clearTimeout(pollTimer);
  if (!(S.open || S.inline) || D.hidden) return;
  pollTimer = setTimeout(tick, delay);
}
async function tick() {
  try {
    if (S.view === 'community' && G.room && G.room.id) await pollRoom();
    else await pollChat();
  } catch (e) { /* the next tick retries */ }
  const idle = Date.now() - S.lastActivity;
  const base = S.view === 'agent' || (S.view === 'community' && G.room) ? 3000 : 8000;
  schedule(idle < 30000 ? base : (idle < 120000 ? base * 2 : 15000));
}
async function pollChat() {
  if (!chat.thread && !(boot && boot.thread)) return;
  const p = await call('dsa7_ping', {});
  const read = Math.max(chat.agentRead, +p.agent_read_id || 0);
  const changed = read !== chat.agentRead || !!p.agent_typing !== chat.typing; // receipts or typing to redraw
  chat.agentRead = read;
  chat.typing = !!p.agent_typing;
  if ((+p.last_id || 0) > chat.last) {
    const d = await call('dsa2_load_messages', { after_id: chat.last, context: pageContext() }, { nonce: 'chat' });
    ingest(d.messages);
  } else if (changed && V.kind === 'agent') {
    const stick = nearBottom();
    paintChat(false);
    if (stick) toBottom(false);
  }
  if (S.view === 'agent') markRead();
  else if (chat.unread !== (+p.unread || 0)) { chat.unread = +p.unread || 0; renderTabs(); syncBadge(); }
}

/* ================================================================ public API */
W.DelicatAssistantApp = {
  open,
  close,
  mount,
  isOpen: () => S.open && !S.inline,
};
