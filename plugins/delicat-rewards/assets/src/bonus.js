/* Delicat Rewards 3.4.0 — Bonus Free Fire page: « Classement » (Meilleur Client), « Mes bonus » and
 * « Parrainage » (invite a friend) on one page. One request (delicat_bonus_boot) brings the session and both tabs' data; claims keep
 * the 3.2.4 protocol (signed token, session renewal, AJAX fallback when a host blocks REST, never an
 * automatic second POST). PINs never go into a URL, storage or analytics. */
(function (w, d) {
  'use strict';
  /* 4.1.0: Android WebViews that no longer update (Chrome before 86) lack replaceChildren() and, older still,
     Promise.prototype.finally: without them every panel stopped drawing. Same behaviour, only where missing. */
  if (w.Promise && !w.Promise.prototype.finally) {
    w.Promise.prototype.finally = function (f) {
      var P = this.constructor || w.Promise;
      return this.then(function (v) { return P.resolve(f()).then(function () { return v; }); }, function (e) { return P.resolve(f()).then(function () { throw e; }); });
    };
  }
  [w.Element, w.DocumentFragment].forEach(function (C) {
    if (C && C.prototype && !C.prototype.replaceChildren) {
      C.prototype.replaceChildren = function () {
        while (this.lastChild) this.removeChild(this.lastChild);
        for (var i = 0; i < arguments.length; i++) { var a = arguments[i]; this.appendChild(typeof a === 'string' ? d.createTextNode(a) : a); }
      };
    }
  });
  var ICONS = {workspace_premium:"m480-483-76 57q-5 3-9 0t-3-8l29-93-78-61q-5-3-2-8t7-5h94l31-96q2-5 7-5t7 5l30 96h95q5 0 8 5t-3 8l-78 61 28 93q2 5-2 8t-9 0zm0 364L284-53q-14 5-27-4t-13-25v-262q-45-47-64-103t-20-113q0-136 92-228t228-92 228 92 92 228q0 57-19 113t-65 103v262q0 15-13 25t-27 4zm185-256q75-76 75-185t-75-184-185-76-184 76-76 184 76 185 184 75 185-75M304-124l176-55 176 55v-171q-40 29-86 42t-90 13-90-13-86-42zm176-86",diamond:"M455-159q-12-5-21-16L105-570q-7-8-10-17t-4-21q0-7 2-13t5-14l85-172q8-15 23-24t31-9h486q17 0 32 9t22 24l85 172 5 14 2 13q0 11-3 21t-11 17L526-175q-9 11-21 16t-25 5-25-5m-87-471h224l-75-150h-74zm82 381v-321H183zm60 0 267-321H510zm149-381h136l-75-150H584zm-494 0h136l75-150H240z",trophy:"M450-180v-148q-54-11-96-46t-58-89q-74-8-125-60t-51-125v-44q0-25 18-42 17-18 42-18h104v-28q0-25 18-42 17-18 42-18h272q25 0 42 18 18 17 18 42v28h104q25 0 42 18 18 17 18 42v44q0 73-51 125t-125 60q-16 53-58 89t-96 46v148h122q13 0 21 9 9 8 9 21t-9 22-21 8H328q-13 0-21-9-9-8-9-21t9-22 21-8zM284-526v-166H180v44q0 45 30 79t74 43m293 101q39-40 39-97v-258H344v258q0 57 40 97 39 40 96 40t97-40m99-101q45-10 75-43t29-79v-44H676zm-196-57",redeem:"M140-277v97h680v-97zm0-443h125q-5-9-8-22t-3-25q0-47 33-80t79-33q31 0 57 16t41 39l16 26 17-26q16-25 41-40t54-15q48 0 81 33t33 80l-2 22q-2 11-8 25h124q24 0 42 18t18 42v480q0 24-18 42t-42 18H140q-24 0-42-18t-18-42v-480q0-24 18-42t42-18m0 336h680v-276H571l83 115q8 10 6 22t-13 20-22 6-20-13L480-683 355-510q-8 11-19 13t-23-6q-10-8-12-20t5-22l83-115H140zm265-345q15-16 15-38t-15-37-38-16-37 16-16 37 16 38 37 15 38-15m187 15q23 0 39-15t15-38-15-37-39-16q-21 0-36 16t-16 37 16 38 36 15",check_circle:"m421-389-98-98q-9-9-22-9t-23 10q-9 9-9 22t9 22l122 123q9 9 21 9t21-9l239-239q10-10 10-23t-10-23q-10-9-23-8t-23 9zm59 309q-82 0-155-31t-127-86-86-128-32-155q0-83 32-156t86-127 127-85 155-32q83 0 156 32t127 85 86 127 31 156q0 82-31 155t-86 128-127 86-156 31m0-60q142 0 241-99t99-241q0-142-99-241t-241-99q-141 0-240 99T140-480q0 141 100 241t240 99m0-340",hourglass_top:"M308-140h344v-127q0-72-50-121t-122-50-122 50-50 121zM190-80q-13 0-21-8t-9-22 9-21 21-9h58v-127q0-71 40-129t106-84q-66-27-106-85t-40-129v-126h-58q-13 0-21-8t-9-22 9-21 21-9h580q13 0 22 9t8 21-8 22-22 8h-58v126q0 71-40 129t-106 85q66 26 106 84t40 129v127h58q13 0 22 9t8 21-8 22-22 8z",content_copy:"M300-200q-24 0-42-18t-18-42v-560q0-24 18-42t42-18h440q24 0 42 18t18 42v560q0 24-18 42t-42 18zm0-60h440v-560H300zM180-80q-24 0-42-18t-18-42v-590q0-13 9-21 8-9 21-9t22 9 8 21v590h470q13 0 21 9 9 8 9 21t-9 22-21 8zm120-180v-560z",timer:"M390-860q-13 0-21-8t-9-22 9-21 21-9h180q13 0 22 9t8 21-8 22-22 8zm112 439q8-9 8-22v-170q0-13-8-21t-22-9-21 9-9 21v170q0 13 9 22t21 8 22-8M341-109q-66-29-115-78t-77-114-29-140 29-139 77-115 115-77 139-29q67 0 126 23t105 62l30-30q9-9 21-9t21 9 9 21-9 21l-30 30q36 40 62 97t25 136q0 74-28 140t-78 114-114 78-140 28-139-28m352-119q87-88 87-213t-87-212-213-88-212 88-88 212 88 213 212 87 213-87M480-440",flag:"M260-429v279q0 13-9 21-8 9-21 9t-22-9-8-21v-620q0-13 9-21 8-9 21-9h289q11 0 19 7t10 17l14 62h208q13 0 21 9 9 8 9 21v310q0 13-9 21-8 9-21 9H568q-10 0-19-6t-10-17l-14-62zm335 25h145v-250H535q-10 0-19-6t-10-18l-14-62H260v251h292q11 0 19 6t10 17zm-95-168",lock:"M220-80q-25 0-42-18-18-17-18-42v-434q0-25 18-42 17-18 42-18h70v-96q0-79 56-134 55-56 134-56t135 56 55 134v96h70q25 0 42 18 18 17 18 42v434q0 25-18 42-17 18-42 18zm0-60h520v-434H220zm315-162q22-22 22-53 0-30-23-54t-54-25-54 25-23 55 23 52 54 22 55-22M350-634h260v-96q0-54-38-92t-92-38-92 38-38 92zM220-140v-434z",close:"M480-438 270-228q-9 9-21 9t-21-9-9-21 9-21l210-210-210-210q-9-9-9-21t9-21 21-9 21 9l210 210 210-210q9-9 21-9t21 9 9 21-9 21L522-480l210 210q9 9 9 21t-9 21-21 9-21-9z",celebration:"m181-181 314-112-203-204zm744-505q-7 7-17 7t-17-7l-2-2q-19-19-44-19t-45 19L574-462q-7 7-17 7t-17-7-7-17 7-17l223-223q32-32 81-32t81 31q7 7 7 17t-7 17M383-811q7-7 17-7t17 7l9 9q35 35 35 88t-36 87l-10 10q-7 7-17 7t-17-7-7-17 7-17l13-13q23-23 22-52t-22-50l-11-11q-7-7-7-17t7-17m169-73q7-7 17-7t17 7l46 46q31 32 32 81t-31 80L496-540q-7 7-17 7t-17-7-7-17 7-17l135-135q19-19 19-48t-20-49l-44-44q-7-7-7-17t7-17m300 505q-7 7-17 7t-17-7l-35-35q-23-23-48-23t-48 23l-33 33q-7 7-17 7t-17-7-7-17 7-17l30-30q35-35 84-36t84 34l34 34q7 7 7 17t-7 17M101-140l149-416q4-10 12-15t16-5l11 2q6 2 10 7l270 266q5 5 7 11t2 11q0 9-5 17t-15 11L140-101q-9 3-17 1t-15-8-8-14 1-18",share:"M686-80q-47 0-81-33t-33-81q0-8 5-34L278-403q-16 17-38 27-21 10-46 10-47 0-81-33t-33-81 33-81 81-33q24 0 45 9 21 10 37 26l301-173-3-16-2-18q0-47 33-81t81-33 81 33 33 81-33 81-81 33q-23 0-44-9-20-9-36-24L302-516l5 18q2 10 1 18l-1 16-3 15 303 173q16-15 36-23 20-9 43-9 48 0 81 33t33 81-33 81-81 33m0-60q23 0 38-16 16-15 16-38t-16-38q-15-16-38-16t-38 16q-16 15-16 38t16 38q15 16 38 16M194-426q23 0 38-16 16-15 16-38t-16-39-38-15-39 16q-15 15-15 38t16 38q15 16 38 16m531-302q15-15 15-38t-16-38q-15-16-38-16t-38 16q-16 15-16 38t16 38q15 16 38 16t38-16m-39-38",link:"M280-280q-83 0-141-58T80-480t59-141 141-59h140q13 0 21 9 9 8 9 21t-9 21q-8 9-21 9H280q-58 0-99 41t-41 99 41 99 99 41h140q13 0 21 9 9 8 9 21t-9 22-21 8zm75-170q-13 0-21-9-9-8-9-21t9-22 21-8h250q13 0 21 9 9 8 9 21t-9 22-21 8zm185 170q-13 0-21-9-9-8-9-21t9-22 21-8h140q58 0 99-41t41-99-41-99-99-41H540q-13 0-21-9-9-8-9-21t9-21q8-9 21-9h140q83 0 142 59t58 141-58 142-142 58z",sms:"M240-240 131-131q-14 14-32 6-19-7-19-27v-668q0-24 18-42t42-18h680q24 0 42 18t18 42v520q0 24-18 42t-42 18zm-26-60h606v-520H140v600zm-74 0v-520zm195-234q11-12 11-29t-11-28-29-12-28 12-12 28 12 29 28 11 29-11m177 0q11-12 11-29t-11-28-29-12-28 12-12 28 12 29 28 11 29-11m170 0q11-12 11-29t-11-28-29-12-28 12-12 28 12 29 28 11 29-11",person_add:"M730-530H630q-13 0-21-9-9-8-9-21t9-21q8-9 21-9h100v-100q0-13 9-21 8-9 21-9t21 9q9 8 9 21v100h100q13 0 21 9 9 8 9 21t-9 21q-8 9-21 9H790v100q0 13-9 21-8 9-21 9t-21-9q-9-8-9-21zm-478 7q-42-42-42-108t42-108 108-42 108 42 42 108-42 108-108 42-108-42M40-220v-34q0-35 18-63t50-43q75-33 133-46t119-14 118 14 133 46q33 15 51 43t18 63v34q0 25-18 42-17 18-42 18H100q-25 0-42-18-18-17-18-42m60 0h520v-34q0-16-9-30t-24-22q-71-33-120-43t-107-11-107 11-121 43q-15 7-23 22t-9 30zm325-346q25-26 25-65t-25-64-65-26-64 26-26 64 26 65 64 25 65-25m-65 346",account_balance_wallet:"M180-233v53-600zm0 113q-25 0-42-18-18-17-18-42v-600q0-25 18-42 17-18 42-18h600q25 0 42 18 18 17 18 42v134h-60v-134H180v600h600v-133h60v133q0 25-18 42-17 18-42 18zm358-173q-31 0-52-21-22-22-22-52v-227q0-30 22-52 21-21 52-21h270q31 0 52 21 22 22 22 52v227q0 30-22 52-21 21-52 21zm284-60v-253H524v253zm-124-82q18-19 18-46 0-26-19-45-19-18-45-18t-45 18-19 45q0 27 19 46 18 19 45 19t46-19",schedule:"M513-492v-171q0-13-8-21t-22-9-21 9-9 21v183q0 6 2 11t6 10l144 149q9 10 23 10t22-10 9-22-9-22zM480-80q-82 0-155-31t-127-86-86-128-32-155 32-155 86-127 127-86 155-32 155 32 128 86 86 127 31 155-31 155-86 128-128 86-155 31m0-60q140 0 240-100t100-240-100-240-240-100-240 100-100 240 100 240 240 100",block:"M324-111q-73-32-127-86t-85-127-32-156 32-156 85-127 127-85 156-32 156 32 127 85 86 127 31 156-31 156-86 127-127 86-156 31-156-31m156-29q61 0 118-20 56-21 101-60L220-699q-38 46-59 102t-21 117q0 142 99 241t241 99m259-121q37-45 59-101 22-57 22-118 0-142-99-241t-241-99q-61 0-117 21t-102 60zM480-480",verified:"m437-433-73-76q-9-10-22-10t-23 9q-10 10-10 23t10 23l97 96q9 9 21 9t21-9l183-182q9-9 9-22t-10-22q-9-8-21-7t-21 8zM332-84l-62-106-124-25q-11-2-18-12t-6-21l14-120-79-92q-8-8-8-20t8-20l79-91-14-120q-2-11 6-21t18-12l124-25 62-107q6-10 17-14t22 1l109 51 109-51q11-5 22-1t17 13l63 108 123 25q11 2 19 12t5 21l-14 120 79 91q8 8 8 20t-8 20l-79 92 14 120q2 11-5 21t-19 12l-123 25-63 107q-6 10-17 14t-22-2l-109-51-109 51q-11 5-22 1t-17-14m41-55 107-45 110 45 67-100 117-30-12-119 81-92-81-94 12-119-117-28-69-100-108 45-110-45-67 100-117 28 12 119-81 94 81 92-12 121 117 28zm107-341",group_add:"M474-486q26-32 39-66t12-79-12-79-39-66q76-17 134 23t57 122-57 122-134 23m202 326 10-29q5-14 4-31v-34q0-51-26-95t-90-74q173 22 237 64t63 105v34q0 25-18 42-17 18-42 18zm124-389h-70q-13 0-21-9-9-8-9-21t9-21q8-9 21-9h70v-70q0-13 9-21 8-9 21-9t21 9q9 8 9 21v70h70q13 0 21 9 9 8 9 21t-9 21q-8 9-21 9h-70v70q0 13-9 21-8 9-21 9t-21-9q-9-8-9-21zm-593 26q-42-42-42-108t42-108 108-42 108 42 42 108-42 108-108 42-108-42M0-220v-34q0-35 19-63t49-43q72-32 129-46t118-14 118 14 128 46q31 14 50 43t19 63v34q0 25-18 42-17 18-42 18H60q-25 0-42-18-18-17-18-42m315-321q39 0 65-25t25-65-25-64-65-26-64 26-26 64 26 65 64 25M60-220h510v-34q0-16-8-30t-25-22q-69-32-117-43t-105-11-104 11-119 43q-15 7-23 22t-9 30zm255 0",info:"M505-289q8-8 8-21v-180q0-13-9-21-8-9-21-9t-22 9-8 21v180q0 13 9 21 8 9 21 9t22-9m-1-314q9-9 9-23t-9-24-24-10-24 10q-9 10-9 24t9 23 24 9 24-9M480-80q-82 0-155-31-73-32-127-86t-86-128-32-155 32-156 86-127 127-85 156-32 155 32 127 85 86 127 31 156q0 82-31 155-32 73-86 127-54 55-127 86-73 32-156 32m0-60q142 0 241-99t99-241-99-241-241-99-240 99-100 241q0 141 100 241t241 99m0-340"};
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
  function money(n) { return num(n, 2) + ' HTG'; }
  function tierMoney(t, st) { var v = st && st.creditAmount != null ? st.creditAmount : t.creditAmount; return Number(v) > 0 ? money(v) : 'Montant à configurer'; }
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
    if (dd) return dd + ' j ' + (h < 10 ? '0' : '') + h + ' h';
    if (h) return h + ' h ' + (m < 10 ? '0' : '') + m + ' min';
    if (m) return m + ' min';
    return s + ' s';
  }

  /* ------------------------------------------------------------------ celebration */
  function confetti(big) {
    // 4.1.0: none in the site's low-power mode; half as many pieces on phones with 2 GB of memory or less.
    if (reduced || !w.requestAnimationFrame || d.documentElement.classList.contains('delicat-low-power')) return;
    var cv = node('canvas', 'dbf-confetti'); cv.setAttribute('aria-hidden', 'true');
    var W = w.innerWidth, H = w.innerHeight, dpr = Math.min(2, w.devicePixelRatio || 1);
    cv.width = W * dpr; cv.height = H * dpr; d.body.appendChild(cv);
    var g = cv.getContext('2d'); if (!g) { cv.remove(); return; }
    var pri = getComputedStyle(root).getPropertyValue('--dbf-pri').trim() || '#2563eb';
    var colors = [pri, '#f59e0b', '#ec4899', '#22c55e', '#38bdf8', '#a855f7', '#facc15'];
    var parts = [], n = big ? 170 : 70;
    if ((navigator.deviceMemory || 4) <= 2) n = Math.round(n / 2);
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
      g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, W, H);
      g.globalAlpha = Math.max(0, Math.min(1, (3000 - age) / 700));
      for (var k = 0; k < parts.length; k++) {
        var p = parts[k];
        p.vy += 0.32; p.vx *= 0.985; p.x += p.vx; p.y += p.vy; p.r += p.vr;
        if (p.y < H + 30) alive = true;
        // One matrix per piece (no save/restore of the whole context): same drawing, less work per frame.
        var cos = Math.cos(p.r) * dpr, sin = Math.sin(p.r) * dpr;
        g.setTransform(cos, sin, -sin, cos, p.x * dpr, p.y * dpr); g.fillStyle = p.c;
        g.fillRect(-p.w / 2, -p.h / 2, p.w, Math.max(2, p.h * Math.abs(Math.cos(p.r * 1.7))));
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
    // Without AbortController (old WebViews) the request simply has no 30 s cut-off.
    var ctl = w.AbortController ? new w.AbortController() : null, timer = setTimeout(function () { if (ctl) ctl.abort(); }, 30000);
    var url = C.rest + path, req = { method: options.method || 'GET', body: options.body, credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined, headers: { 'X-WP-Nonce': S.nonce, 'Content-Type': 'application/json' } };
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
    if (name === 'classement' && S.loggedIn) loadWinnerRewards().catch(function (e) { notice(e.message); });
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
    var top = b.top || [], prizes = b.prizeCredits || C.prizeCredits || [];
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
        if (+prizes[idx] > 0) { var pr = node('span', 'dbf-pod-prize'); pr.appendChild(icon('diamond')); pr.appendChild(d.createTextNode(money(prizes[idx]))); col.appendChild(pr); }
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
      li.appendChild(node('b', '', clean(x.name))); li.appendChild(node('span', 'dbf-muted', [when, x.amountHtg != null ? money(x.amountHtg) : x.prize ? diamonds(x.prize) : ''].filter(Boolean).join(' · ')));
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }

  /* ------------------------------------------------------------------ mes bonus */
  var BLOCKED = { wallet: 'Crédit HTG temporairement indisponible', max: 'Limite mensuelle atteinte', cap: 'Plafond mensuel atteint', dry: 'Programme en mode test', frozen: 'Bonus temporairement bloqués' };
  function blocked(st) { return BLOCKED[st.blocked] || ''; }
  function render(s) {
    if (!s || !s.tiers || typeof s.tiers !== 'object') throw new Error('Progression indisponible. Actualisez la page.');
    S.state = s;
    text('dbf-spend', num(s.monthSpend, 2) + ' ' + (clean(T.currency) || 'G'));
    text('dbf-total', money(s.monthCredit));
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
      text('dbf-next-title', clean(t.label) + ' · ' + tierMoney(t, st));
      text('dbf-next-msg', blocked(st) || (st.claimable ? 'Votre bonus est prêt. Réclamez votre crédit HTG avant la fin du mois.' : 'Encore ' + num(Math.max(0, goal - progress) + owed) + ' achat(s) éligible(s) pour ce pack' + (owed ? ', dont ' + num(owed) + ' pour compenser un remboursement.' : '.')));
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
      var prize = node('div', 'dbf-tier-prize'); prize.appendChild(node('b', '', tierMoney(tier, st))); prize.appendChild(node('small', '', 'crédit Delicat Wallet'));
      head.appendChild(badge); head.appendChild(info); head.appendChild(prize); card.appendChild(head);
      var lab = node('div', 'dbf-tier-lab');
      lab.appendChild(node('span', 'dbf-tag', clean(tier.label))); lab.appendChild(node('span', '', num(Math.min(progress, goal)) + ' / ' + num(goal) + ' achats'));
      var bar = node('div', 'dbf-bar dbf-bar--lg'), fill = node('i'); fill.style.width = pct + '%'; bar.appendChild(fill);
      bar.setAttribute('role', 'progressbar'); bar.setAttribute('aria-label', 'Progression ' + clean(tier.label)); bar.setAttribute('aria-valuemin', '0'); bar.setAttribute('aria-valuemax', String(goal)); bar.setAttribute('aria-valuenow', String(Math.min(progress, goal)));
      var owed = Math.max(0, Number(st.owed || 0)), left = Math.max(0, goal - progress) + owed;
      var detail = why || (ok ? 'Objectif atteint : réclamez avant la fin du mois (heure du site).' : num(left) + ' achat(s) restant(s)' + (owed ? ', dont ' + num(owed) + ' pour compenser un remboursement.' : '.'));
      if (Number(st.claimed || 0)) detail += ' ' + num(st.claimed) + ' déjà réclamé(s) ce mois.';
      var btn = node('button', 'dbf-btn' + (ok ? ' dbf-btn--win' : ' dbf-btn--ghost'));
      btn.type = 'button'; btn.id = 'dbf-claim-' + key; btn.disabled = S.claiming || !ok;
      if (ok) btn.appendChild(icon('redeem'));
      btn.appendChild(d.createTextNode(ok ? 'Réclamer ' + tierMoney(tier, st) : (why || 'Continuez vos achats')));
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
      toast('Bonus débloqué : ' + clean(f.label) + ' · ' + tierMoney(f, s.tiers[fresh[0]]) + '. Réclamez-le !');
    }
  }
  function refreshState() {
    loadWinnerRewards().catch(function () {});
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
        if (reply.delivery && reply.delivery.status === 'credited') confetti(true);
        if (reply.delivery) { openDelivery(reply.delivery, reply.delivery.status === 'credited'); if (reply.delivery.status === 'credited') walletUpdated(reply.delivery); }
        else { notice(''); toast('Demande enregistrée. Vérifiez votre crédit dans « Mes récompenses ».'); }
        S.page = 1; return loadHistory().catch(function () { notice('Récompense enregistrée. L’historique n’a pas pu être actualisé : touchez « Actualiser ».'); });
      }).catch(function (e) {
        S.claiming = false;
        var msg = e.name === 'AbortError' || e instanceof TypeError ? 'La réponse a été interrompue. Vérifiez « Mes récompenses » avant de réclamer à nouveau.' : e.message;
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
        body.appendChild(node('b', '', item.amount ? (item.rewardType === 'wallet' ? money(item.amount) : diamonds(item.amount)) + ' · ' + (tier ? clean(tier.label) : item.kind === 'weekly' ? 'Meilleur Client' : 'Bonus') : 'Récompense Free Fire'));
        var when = item.date ? new Date(item.date).toLocaleDateString('fr-FR') : '';
        var status = { credited: 'Ajouté au portefeuille', claimable: 'À réclamer', expired: 'Expirée', review: 'Vérification en cours', ready: 'PIN disponible', pending: 'En préparation', partial: 'Livraison partielle', unavailable: 'Indisponible' }[item.status] || 'En préparation';
        body.appendChild(node('small', 'dbf-muted', [when, status].filter(Boolean).join(' · ')));
        var b = node('button', 'dbf-btn dbf-btn--ghost', item.rewardType === 'wallet' ? 'Détails' : item.status === 'ready' ? 'Voir mon PIN' : 'Vérifier'); b.type = 'button'; b.id = 'dbf-order-' + item.orderId;
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
    text('dbf-dialog-title', 'Votre récompense'); text('dbf-dialog-msg', 'Chargement de votre récompense…');
    $('dbf-redeem').hidden = true; $('dbf-pin-refresh').hidden = false;
    showDialog(false); fetchDelivery();
  }
  function openDelivery(delivery, win) { clearSecrets(); S.activeOrder = Number(delivery.orderId); S.pinChecks = 0; showDialog(win); renderDelivery(delivery, win); }
  function renderDelivery(r, win) {
    if (!S.activeOrder || S.activeOrder !== Number(r.orderId) || !dialog.open) return;
    if (r.rewardType === 'wallet') {
      text('dbf-dialog-title', r.status === 'credited' ? 'Félicitations ! ' + money(r.amount) + ' gagnés' : 'Votre récompense · ' + money(r.amount));
      text('dbf-dialog-msg', r.message); $('dbf-pins').replaceChildren();
      $('dbf-redeem').hidden = false; $('dbf-redeem').href = r.redeemUrl || C.walletUrl || '/my-wallet/'; $('dbf-redeem').textContent = 'Mon portefeuille';
      $('dbf-pin-refresh').hidden = r.status !== 'review'; clearTimeout(S.pinTimer);
      return;
    }
    $('dbf-redeem').href = C.redeemUrl; $('dbf-redeem').textContent = 'Utiliser mon PIN';
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
      .catch(function (e) { if (seq === S.pinSeq && dialog.open) text('dbf-dialog-msg', e.name === 'AbortError' ? 'Délai dépassé. Actualisez votre récompense ici.' : e.message); })
      .finally(function () { if (seq === S.pinSeq) b.disabled = false; });
  }
  dialog.addEventListener('close', function () {
    clearSecrets(); $('dbf-toast').hidden = true;
    if (returnFocus && $(returnFocus)) $(returnFocus).focus({ preventScroll: true });
  });
  $('dbf-dialog-close').addEventListener('click', function () { dialog.close(); });
  $('dbf-pin-refresh').addEventListener('click', function () { S.pinChecks = 0; fetchDelivery(); });

  function walletUpdated(delivery) {
    w.dispatchEvent(new CustomEvent('delicat:wallet-updated', { detail: { transactionId: delivery.transactionId, amount: delivery.amount, currency: 'HTG' } }));
    if (w.jQuery) w.jQuery(d.body).trigger('wc_fragment_refresh');
  }
  var winnerBusy = false, winnerState = null;
  function renderWinnerRewards(reply) {
    var box = $('dbf-winner-rewards'); if (!box) return;
    winnerState = reply; box.replaceChildren();
    (reply.items || []).forEach(function (r) {
      var card = node('article', 'dbf-card dbf-tier' + (r.status === 'claimable' ? ' is-ready' : ''));
      card.appendChild(node('h3', '', 'Meilleur Client · place #' + r.rank + ' · ' + money(r.amount)));
      card.appendChild(node('p', 'dbf-muted', r.message));
      var expired = r.expiresAt * 1000 <= Date.now() + S.skew;
      if (r.status === 'claimable' && r.token && !expired) {
        var b = node('button', 'dbf-btn dbf-btn--win', 'Réclamer ' + money(r.amount)); b.type = 'button'; b.disabled = winnerBusy; b.id = 'dbf-winner-' + r.orderId;
        b.addEventListener('click', function () { claimWinner(r, b); }); card.appendChild(b);
      } else if (r.status === 'credited') {
        var a = node('a', 'dbf-btn dbf-btn--ghost', 'Mon portefeuille'); a.href = C.walletUrl || '/my-wallet/'; card.appendChild(a);
      } else if (expired && r.status === 'claimable') card.appendChild(node('b', '', 'Récompense expirée'));
      box.appendChild(card);
    });
  }
  function loadWinnerRewards() { if (!$('dbf-winner-rewards') || !S.loggedIn) return Promise.resolve(); return api('/winner-rewards').then(renderWinnerRewards); }
  function claimWinner(r, b) {
    if (winnerBusy) return; winnerBusy = true; returnFocus = b.id;
    b.disabled = true; b.textContent = 'Crédit en cours…';
    api('/winner-claim', { method: 'POST', body: JSON.stringify({ order_id: r.orderId, token: r.token }) }).then(function (reply) {
      winnerBusy = false; renderWinnerRewards(reply.winners || { items: [] });
      if (reply.delivery) { var credited = reply.delivery.status === 'credited'; if (credited) { confetti(true); walletUpdated(reply.delivery); } openDelivery(reply.delivery, credited); }
    }).catch(function (e) { winnerBusy = false; notice(e.name === 'AbortError' ? 'Réponse interrompue. Vérifiez votre récompense et votre portefeuille avant de réessayer.' : e.message); loadWinnerRewards().catch(function () {}); });
  }

  /* ------------------------------------------------------------------ parrainage */
  // One point per invited friend whose first real purchase stays paid; milestones are claimed once, as a
  // PIN (diamonds) or store credit (HTG). The server decides everything; this only draws and asks.
  var BRANDS = {whatsapp:"m17.5 14.4-2-1q-.4-.1-.7.2l-1 1.1q-.2.3-.6.1-.6 0-2.4-1.5l-1.7-2q-.1-.4.2-.6l.4-.6q.2-.2.3-.5v-.5L9 7q-.3-.7-.6-.5h-.6a1 1 0 0 0-.8.4c-.2.3-1 1-1 2.5 0 1.4 1 2.8 1.2 3s2.1 3.2 5.1 4.5l1.7.6a4 4 0 0 0 1.9.2c.5-.1 1.7-.8 2-1.5q.3-1 .1-1.4zM12 21.8a10 10 0 0 1-5-1.4l-.3-.2-3.8 1 1-3.7-.2-.3a10 10 0 0 1-1.5-5.3 9.9 9.9 0 0 1 16.8-7 10 10 0 0 1 3 7c0 5.5-4.5 9.9-10 9.9m8.5-18.3A12 12 0 0 0 12 0 12 12 0 0 0 1.7 17.8L.1 24l6.3-1.7a12 12 0 0 0 5.6 1.5 12 12 0 0 0 12-11.9 12 12 0 0 0-3.5-8.4",facebook:"M9.1 23.7v-8H6.6V12h2.5v-1.5q-.1-6 5.9-6h1.4l1.2.3V8h-1.4q-1 0-1.7.3l-.7.6q-.3.6-.3 1.7V12h3.9l-.4 2.1-.3 1.6h-3.2V24a12 12 0 1 0-4.4-.3",telegram:"M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0m5 7.2.4.2.1.3v.5c-.1 1.9-.9 6.5-1.3 8.6q-.3 1.3-.8 1.2-1 0-2-.9l-2.6-1.8c-1.2-.8-.4-1.2.3-1.9l3.3-3.2V10H14q-.2 0-5.1 3.3-.8.5-1.3.5-.8-.1-1.9-.5-1.2-.2-1.3-.8 0-.3 1-.6z"}; // share logos, 24 x 24
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
      else if (m.status === 'review') line = 'En vérification par l’équipe : votre récompense est envoyée après validation.';
      else if (m.status === 'refused') line = 'Non accordée après vérification. Contactez le support si c’est une erreur.';
      else if (m.status === 'capped') line = 'Palier atteint. Il se débloque quand vos amis invités auront fait plus d’achats' + (s.rules && s.rules.capPct ? ' (récompenses limitées à ' + num(s.rules.capPct) + ' % de leurs achats).' : '.');
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
        acts.appendChild(node('small', 'dbf-muted', 'Touchez votre choix puis confirmez. Le choix est définitif.' + (m.manual ? ' L’équipe vérifie ce palier avant de l’envoyer.' : '')));
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
        if (c.review) { openNote('Demande envoyée', 'L’équipe vérifie vos invitations avant d’envoyer ce palier. Vous serez prévenu par e-mail.'); return; }
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
      if (S.loggedIn && b.winnerRewards && !b.winnerRewards.error) renderWinnerRewards(b.winnerRewards);
      else if (!S.loggedIn && $('dbf-winner-rewards')) $('dbf-winner-rewards').replaceChildren();
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
    if (winnerState) (winnerState.items || []).forEach(function (r) { var b = $('dbf-winner-' + r.orderId); if (b && r.expiresAt * 1000 <= Date.now() + S.skew) { b.disabled = true; b.textContent = 'Récompense expirée'; } });
    if (S.monthAt) {
      var m = S.monthLeft - (Date.now() - S.monthAt);
      // Once per state (4.1.0): the buttons were rewritten every second after the month's end.
      if (m <= 0 && S.state && !S.state.expiredShown) { S.state.expiredShown = true; Object.keys(S.state.tiers || {}).forEach(function (k) { S.state.tiers[k].claimable = false; var b = $('dbf-claim-' + k); if (b) { b.disabled = true; b.textContent = 'Bonus expiré · actualisez'; } }); }
      setOnce('dbf-month-clock', m > 0 ? 'Bonus à réclamer avant la fin du mois · ' + span(m) : 'Nouveau mois : actualisez votre progression.');
    }
  }
  function attached() { return d.documentElement.contains(root); } // root.isConnected, also on old WebViews
  function every() { if (!attached()) { clearInterval(clock); return; } if (!d.hidden) tick(); } // stops once the page is gone
  var clock = setInterval(every, 1000);
  $('dbf-refresh').addEventListener('click', function () { notice(''); boot(); });
  if ($('dbf-history-refresh')) {
    $('dbf-history-refresh').addEventListener('click', function () { Promise.all([loadHistory(), refreshState()]).catch(function (e) { notice(e.message); }); });
    $('dbf-prev').addEventListener('click', function () { if (S.page > 1) S.page--; loadHistory().catch(function (e) { notice(e.message); }); });
    $('dbf-next').addEventListener('click', function () { S.page++; loadHistory().catch(function (e) { notice(e.message); }); });
  }
  w.addEventListener('pagehide', function () { S.suspended = true; clearSecrets(); clearTimeout(toastTimer); clearInterval(clock); if (dialog.open) dialog.close(); });
  w.addEventListener('pageshow', function (e) { if (e.persisted && attached()) { S.suspended = false; clearInterval(clock); clock = setInterval(every, 1000); boot(); } });
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
