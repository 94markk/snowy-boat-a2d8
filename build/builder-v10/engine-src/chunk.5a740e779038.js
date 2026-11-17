import { a as z, h as G } from "./chunk.fefdb647cb09.js";
import { a as c, b as I, c as n, d as p, g as N, j as q, l as Y, x as $, y as V, z as b } from "./chunk.71647e346105.js";
var d = Object.assign({ minGap: 2500, stale: 6e4, navStale: 6e4, fresh: 6e4 }, z.session || {}), T = n.DelicatSessionConfig || {};
d.url || (d.url = T.url || location.pathname + "?wc-ajax=delicat_session"), !d.resetUrl && T.resetUrl && (d.resetUrl = T.resetUrl);
var a = { data: null, ts: 0, pending: null, queued: null, listeners: [], signature: "" }, L = 0, F = false, S = false, _ = false, x = false, g = null, GV = "dlc-gv-v1";
try {
  g = new URL(location.href), S = g.searchParams.get("delicat_auth_reset") === "1", _ = g.searchParams.get("delicat_auth_recover") === "1", x = !!g.searchParams.get("dip_auth_sync");
} catch {
  let e = (t) => new RegExp("(?:[?&])" + t + "=1(?:&|$)").test(location.search || "");
  /* V10: Identity Pro marks a sign-in/out landing with a random value (DIP_Session_Router::sync_marker), not "1". */
  S = e("delicat_auth_reset"), _ = e("delicat_auth_recover"), x = /(?:[?&])dip_auth_sync=[^&]+/.test(location.search || "");
}
function D() {
  /* V10: the service worker keeps no page copies since 9.12.1 (pages always come from the
     network), so the old "dbv9-clear-docs" message to it is gone. */
  K();
}
function Q() {
  if (!(!n.history || !n.history.replaceState)) try {
    g && (["delicat_auth_reset", "delicat_auth_recover", "dip_auth_sync"].forEach((e) => g.searchParams.delete(e)), n.history.replaceState(n.history.state, "", g.pathname + (g.search || "") + (g.hash || "")));
  } catch {
  }
}
(S || _ || x) && (D(), Q());
function X(e) {
  if (!e || typeof e != "object") return null;
  if ("loggedIn" in e) {
    let t = e;
    return t.cart = t.cart || { count: 0 }, t.wallet = t.wallet || { text: "", raw: null }, t.urls = t.urls || {}, t;
  }
  return e.ok && e.user ? { loggedIn: !!e.user.loggedIn, id: e.user.id || 0, name: e.user.firstName || "", initial: String(e.user.firstName || "").charAt(0).toUpperCase(), email: "", code: "", wallet: { text: e.wallet && e.wallet.enabled ? String(e.wallet.balance || "") : "", raw: e.wallet ? e.wallet.raw : null, enabled: !!(e.wallet && e.wallet.enabled) }, cart: { count: e.cart && Number(e.cart.count) || 0, total: e.cart ? e.cart.total : "" }, alerts: e.alerts || { count: 0 }, nonces: e.nonces || null, urls: {}, ts: Math.floor(Date.now() / 1e3) } : null;
}
function Z(e) {
  return e ? [e.loggedIn ? 1 : 0, e.id || 0, e.cart ? e.cart.count : 0, e.cart && e.cart.total || "", e.wallet ? e.wallet.raw : "", e.alerts ? e.alerts.count : 0].join("|") : "";
}
var m = (e, t) => {
  for (let o of p(e)) {
    let r = t == null ? "" : String(t);
    o.textContent !== r && (o.textContent = r);
  }
}, C = (e, t) => {
  for (let o of p(e)) {
    let r = String(t);
    o.textContent !== r && (o.textContent = r), o.hidden = !(t > 0), o.setAttribute("data-empty", t > 0 ? "0" : "1"), o.setAttribute("aria-label", t + " article" + (t === 1 ? "" : "s") + " dans le panier");
  }
};
function P() {
  let e = c.querySelector("[data-dsb8-cart-count],[data-dbn-cart-count],[data-dbp-cart-count],[data-delicat-cart-count]");
  return e && parseInt(e.textContent, 10) || 0;
}
function B() {
  let e = c.cookie.match(/(?:^|;\s*)woocommerce_items_in_cart=([^;]*)/);
  return e ? Number(decodeURIComponent(e[1] || "0")) === 0 ? 0 : null : ee() ? null : 0;
}
var ee = () => /(?:^|;\s*)(?:wc_cart_hash|woocommerce_cart_hash|wp_woocommerce_session_[^=]*)=([^;]+)/i.test(c.cookie || ""), M = () => Y.has(/(?:^|;\s*)(?:woocommerce_items_in_cart|wc_cart_hash|wp_woocommerce_session_[^=]*)=([^;]+)/i);
function E(e) {
  e = Math.max(0, Math.floor(Number(e) || 0)), C("[data-dsb8-cart-count]", e), C("[data-dbn-cart-count]", e), C("[data-dbp-cart-count]", e), C("[data-delicat-cart-count]", e), I.setAttribute("data-dbp-cart", e > 0 ? String(e) : "0"), N("delicat:cart-count", { count: e });
}
var W = "dlc-sup-nonces-v1", te = ".dfr-native-fields,.dfr-uid-verify,.topup-fields,.topup-field,.topup-verify-actions,[data-dfr-role],.dfr-recent", ae = ".dfr-uid-verify-btn,.topup-verify-button", s = { n: null, li: -1, p: null, pass: 0 };
function j() {
  return b() ? 1 : 0;
}
function J() {
  let e = j();
  if (s.n && s.li === e) return s.n;
  s.n = null;
  try {
    let t = JSON.parse(sessionStorage.getItem(W) || "null");
    t && t.n && t.li === e && Date.now() - t.ts < 18e5 && (s.n = t.n, s.li = e);
  } catch {
  }
  return s.n;
}
function K() {
  s && (s.n = null, s.li = -1);
  try {
    sessionStorage.removeItem("dlc-sup-nonces-v1"), sessionStorage.removeItem(GV);
  } catch {
  }
}
function y(e) {
  if (e && typeof e == "object") {
    s.n = e, s.li = j();
    try {
      sessionStorage.setItem(W, JSON.stringify({ ts: Date.now(), li: s.li, n: e }));
    } catch {
    }
  } else if (s.n && s.li !== j()) return;
  if (e = J(), !e) return;
  let t = (r, i) => {
    let f = n[r];
    f && typeof f == "object" && i && (f.nonce = i);
  };
  t("topup_verify", e.s2tVerify), t("dst2t_verify", e.s2tVerify), t("topup_history", e.s2tHistory), t("dst2t_history", e.s2tHistory), t("TopupFields", e.ftrFront);
  let o = n.BayConnectorFields;
  if (o && typeof o == "object" && String(o.action || "") === "topup_verify_player" && e.ftrFront && (o.nonce = e.ftrFront), t("DFR_SECURE_DELIVERY", e.dfrCodes), t("DFR_DELICA_HISTORY", e.dfrCodes), e.dfrUid) for (let r of p(".dfr-native-fields [data-nonce],.dfr-uid-verify[data-nonce]")) r.setAttribute("data-nonce", e.dfrUid);
  if (e.dfrRecent) for (let r of p(".dfr-recent[data-clear-nonce]")) r.setAttribute("data-clear-nonce", e.dfrRecent);
}
function re() {
  return J() ? (y(), Promise.resolve(s.n)) : s.p || (s.p = u("supplier", true).then(() => s.n, () => s.n).finally(() => {
    s.p = null;
  }));
}
function ne(e) {
  let t = e && e.nonces;
  if (!t) return;
  t.supplier && y(t.supplier);
  let o = { "woocommerce-add-to-cart": t.addToCart, "woocommerce-cart": t.wc, wp_rest: t.rest };
  for (let r of p('input[type="hidden"][data-dbp-nonce]')) {
    let i = r.getAttribute("data-dbp-nonce");
    o[i] && (r.value = o[i]);
  }
  if (n.wpApiSettings && t.rest && (n.wpApiSettings.nonce = t.rest), n.wc_add_to_cart_params && t.addToCart && (n.wc_add_to_cart_params.wc_ajax_nonce = t.addToCart), t.heart && n.DelicaBuilderV9 && n.DelicaBuilderV9.heart && (n.DelicaBuilderV9.heart.nonce = t.heart), t.walletLive) {
    n.delicatBuilderV9WalletLive && (n.delicatBuilderV9WalletLive.nonce = t.walletLive);
    for (let r of p("[data-dlx-drawer][data-wallet-nonce]")) r.setAttribute("data-wallet-nonce", t.walletLive);
  }
  if (t.headerCart) for (let r of p("[data-dsb8-cart-root][data-nonce]")) r.setAttribute("data-nonce", t.headerCart);
  t.clearCart && n.DelicatStorefrontCommerce && (n.DelicatStorefrontCommerce.clearNonce = t.clearCart), t.shell && n.DelicaShellV9 && (n.DelicaShellV9.nonce = t.shell), t.expressAdd && n.DelicatExpress && (n.DelicatExpress.addNonce = t.expressAdd);
  for (let r of p("[data-dbv9-notifications]")) {
    let i = r._dbv9Cfg, f = null;
    try {
      f = JSON.parse(r.getAttribute("data-config") || "{}");
    } catch {
      f = null;
    }
    for (let l of [i, f]) !l || typeof l != "object" || (t.rest && l.nonce && (l.nonce = t.rest), t.notifications && l.ajaxNonce && (l.ajaxNonce = t.notifications), t.push && l.push && typeof l.push == "object" && (l.push.nonce = t.push));
    if (f) try {
      r.setAttribute("data-config", JSON.stringify(f));
    } catch {
    }
  }
}
function R(e) {
  let t = a.data;
  if (!t) return;
  let o = b();
  if (!!t.loggedIn !== o && !n.__dbv9Reloaded) {
    let l = n.performance && performance.getEntriesByType ? performance.getEntriesByType("navigation") : [];
    if (!(l[0] && l[0].type === "reload")) {
      n.__dbv9Reloaded = true, D(), n.setTimeout(() => location.reload(), 60);
      return;
    }
  }
  e === "guest-verify" && !t.loggedIn && gvMark();
  if (I.classList.toggle("dlc-session-user", !!t.loggedIn), I.classList.toggle("dlc-session-guest", !t.loggedIn), I.setAttribute("data-dbp-auth", t.loggedIn ? "in" : "out"), t.loggedIn && t.urls && t.urls.logout) for (let l of p('.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]')) l.setAttribute("href", t.urls.logout);
  if (t.cart) {
    let l = c.querySelector("[data-dsb8-cart-count]"), h = l ? parseInt(l.textContent, 10) || 0 : null, v = parseInt(t.cart.count, 10) || 0;
    E(v), m("[data-dbp-cart-total]", t.cart.total || "");
    let O = $();
    if (l && h !== v && O && e !== "woo") try {
      O(c.body).trigger("wc_fragment_refresh");
    } catch {
    }
    if (v === 0) {
      let k = c.querySelector(".dsb8-cart-list"), U = c.querySelector(".dsb8-cart-panel__foot");
      k && !k.querySelector(".dsb8-cart-empty") && (k.innerHTML = '<div class="dsb8-cart-empty">Votre panier est vide.</div>'), U && (U.hidden = true);
    }
  }
  let r = t.loggedIn && t.wallet && t.wallet.text ? t.wallet.text : "\u2014";
  m("[data-dlx-wallet-balance]", r), m("[data-delicat-wallet-balance]", r), m("[data-dbp-wallet]", t.loggedIn && t.wallet && t.wallet.text ? t.wallet.text : ""), m("[data-dlx-name]", t.loggedIn ? t.name : ""), m("[data-dbp-user-name]", t.loggedIn ? t.name : ""), m("[data-dlx-email]", t.loggedIn ? t.email : ""), m("[data-dlx-initial]", t.loggedIn ? t.initial : "");
  for (let l of p("[data-dlx-code]")) {
    let h = t.loggedIn && t.code ? t.code : "";
    l.textContent = h;
    let v = l.closest ? l.closest("[data-dlx-copy]") : null;
    v && (h ? v.setAttribute("data-dlx-copy", h) : v.removeAttribute("data-dlx-copy"));
  }
  if (t.alerts) {
    let l = Number(t.alerts.count) || 0;
    for (let h of p("[data-dbp-alerts]")) h.textContent = String(l), h.hidden = l < 1;
  }
  ne(t);
  let i = Z(t), f = i !== a.signature;
  a.signature = i, N("delicat:session", t), N("delicat:pro:state", { state: t, changed: f, reason: e || "load" });
  for (let l of a.listeners) try {
    l(t);
  } catch {
  }
}
var H = "dlc-sess-v1", oe = { load: 1, "guest-verify": 1, visible: 1, focus: 1, nav: 1, bfcache: 1 };
function le() {
  try {
    let e = JSON.parse(sessionStorage.getItem(H) || "null");
    return e && e.d && typeof e.ts == "number" && Date.now() - e.ts < d.fresh && !!e.d.loggedIn === b() ? e : null;
  } catch {
    return null;
  }
}
function se(e) {
  try {
    e && sessionStorage.setItem(H, JSON.stringify({ ts: Date.now(), d: e }));
  } catch {
  }
}
function u(e = "manual", t = true) {
  if (oe[e] && n.DelicatBackoff && n.DelicatBackoff.on()) return Promise.resolve(a.data);
  if (a.pending) return t && !a.queued && (a.queued = a.pending.then(() => (a.queued = null, u(e, true)))), a.queued || a.pending;
  let o = Date.now();
  return !t && a.data && o - a.ts < d.minGap ? Promise.resolve(a.data) : (a.pending = G(d.url, {}, 8e3).then((r) => {
    let i = X(r);
    return i && (a.data = i, a.ts = Date.now(), se(i), R(e)), a.data;
  }).catch(() => a.data).then((r) => (a.pending = null, r)), a.pending);
}
function w(e, t = 90) {
  L && n.clearTimeout(L), L = n.setTimeout(() => {
    L = 0, u(e, true);
  }, t);
}
function ce() {
  var e;
  return { loggedIn: false, name: "", initial: "", email: "", code: "", wallet: { text: "", raw: null }, cart: { count: (e = B()) != null ? e : P() }, urls: {}, ts: Math.floor(Date.now() / 1e3) };
}
var A = () => b() || P() > 0 || M() || !!(a.data && a.data.loggedIn) || !!(a.data && a.data.cart && a.data.cart.count > 0);
/* V10: sign-in version as Identity Pro writes it (modal, passkeys, logout: localStorage
   "dip-session-change"; a dip_auth_sync landing in this tab: sessionStorage "dip-auth-sync-seen"). */
function gvVersion() {
  try {
    return String(localStorage.getItem("dip-session-change")) + "|" + String(sessionStorage.getItem("dip-auth-sync-seen"));
  } catch {
    return null;
  }
}
function gvMark() {
  let e = gvVersion();
  if (e !== null) try {
    sessionStorage.setItem(GV, JSON.stringify({ ts: Date.now(), v: e }));
  } catch {
  }
}
/* A guest copy already confirmed by the server in this tab, with no sign-in or sign-out since
   (30 minutes at most). */
function gvRecent() {
  try {
    let e = JSON.parse(sessionStorage.getItem(GV) || "null"), t = gvVersion();
    return !!e && t !== null && e.v === t && Date.now() - Number(e.ts || 0) < 18e5;
  } catch {
    return false;
  }
}
/* Should a guest page ask the server whether the shopper is really signed out?
   V10: no longer on every page view a service worker controls. Since 9.12.1 the worker never
   answers a page from a stored copy, and the check cost one uncached WordPress + WooCommerce
   request per page view of every returning guest and of the installed app. A history load
   (back/forward) is always checked. A browser-cache copy (no bytes transferred) and a page
   under the service worker (Chromium reports bytes for those, so they look like network
   pages, yet app/PWA users can still get a wrong-variant copy from LiteSpeed or the CDN)
   are checked once per tab, until the next sign-in or sign-out. */
function ie() {
  try {
    let e = n.performance && performance.getEntriesByType ? performance.getEntriesByType("navigation") : [], t = e && e[0];
    if (t && t.type === "back_forward") return true;
    if (gvRecent()) return false;
    let sw = false;
    try {
      sw = !!(navigator.serviceWorker && navigator.serviceWorker.controller);
    } catch {
    }
    if (sw) return true;
    return t ? Number(t.transferSize || 0) === 0 && Number(t.decodedBodySize || 0) > 0 : false;
  } catch {
    return false;
  }
}
var de = new Promise((e) => {
  if (!S && !_ && !x && !b() && P() === 0 && !M()) {
    a.data = ce(), a.ts = Date.now(), R("guest"), e(a.data), ie() && q(() => {
      u("guest-verify", true);
    }, 6e3);
    return;
  }
  let t = S || _ || x ? null : le();
  if (t) {
    a.data = t.d, a.ts = t.ts, R("cache"), e(a.data);
    return;
  }
  q(() => {
    u("load", true).then(e, e);
  }, 1200);
});
function ue() {
  let e = (t) => {
    t.target && t.target.closest && t.target.closest(te) && re();
  };
  c.addEventListener("focusin", e, true), c.addEventListener("pointerdown", e, { capture: true, passive: true }), c.addEventListener("click", (t) => {
    if (s.pass) return;
    let o = t.target && t.target.closest ? t.target.closest(ae) : null;
    if (o) {
      if (!s.p) {
        y();
        return;
      }
      t.preventDefault(), t.stopImmediatePropagation(), Promise.race([s.p, new Promise((r) => n.setTimeout(r, 4e3))]).then(() => {
        y(), s.pass = 1;
        try {
          o.click();
        } finally {
          s.pass = 0;
        }
      });
    }
  }, true);
  for (let t of ["DOMContentLiteSpeedLoaded", "delicat:navigated", "delicat:swapped", "dsb:content-updated"]) c.addEventListener(t, () => n.setTimeout(y, 0));
  n.addEventListener("DOMContentLiteSpeedLoaded", () => n.setTimeout(y, 0)), c.addEventListener("delicat:auth-changed", K);
}
function fe() {
  ue(), c.addEventListener("click", (e) => {
    let t = e.target && e.target.closest ? e.target.closest('[data-dip-auth-open],[data-dl-open],a[href*="#delicat-login"],.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]') : null;
    if (!t || (D(), !(t.matches && t.matches('.dsb-account-logout,.dlx-account__logout,a[href*="action=logout"],a[href*="customer-logout"]')) || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) || (e.preventDefault(), e.stopPropagation(), F)) return;
    F = true, t.setAttribute("aria-busy", "true");
    let o = t.getAttribute("href") || d.resetUrl || "/";
    u("logout", true).then((r) => {
      let i = r && r.loggedIn && r.urls && r.urls.logout ? r.urls.logout : r && !r.loggedIn && d.resetUrl || o;
      location.assign(i);
    }, () => location.assign(o));
  }, true), _ && n.setTimeout(() => {
    let e = c.querySelector('[data-dip-auth-open],[data-dl-open],a[href="#delicat-login"]');
    e && typeof e.click == "function" && e.click();
  }, 30), c.addEventListener("visibilitychange", () => {
    !c.hidden && A() && Date.now() - a.ts > d.stale && u("visible", true);
  }), n.addEventListener("focus", () => {
    A() && Date.now() - a.ts > d.stale && u("focus", true);
  }), n.addEventListener("pageshow", (e) => {
    e.persisted && w("bfcache", 40);
  });
  for (let e of ["delicat:cart-changed", "delicat:wallet-changed", "delicat:express-added", "delicat:auth-changed", "delicat:currency-changed", "delicat:balance-changed"]) c.addEventListener(e, () => w("event", 80));
  c.addEventListener("delicat:pro:refresh", () => u("manual", true)), c.addEventListener("delicat:session:refresh", () => u("manual", true)), c.addEventListener("dlx:open", () => {
    A() && w("drawer-open", 60);
  }), c.addEventListener("delicat:navigated", () => {
    a.data && A() && Date.now() - a.ts > d.navStale && u("nav", true);
  }), c.addEventListener("wc-blocks_added_to_cart", () => w("woo", 70)), c.addEventListener("wc-blocks_removed_from_cart", () => w("woo", 70)), V("added_to_cart removed_from_cart updated_wc_div updated_cart_totals", () => w("woo", 70)), V("wc_fragments_refreshed wc_fragments_loaded", () => {
    let e = B();
    e !== null && E(e);
  });
}
var pe = { get: () => a.data, ready: de, refresh: (e) => u(e || "manual", true), on(e) {
  if (typeof e == "function" && (a.listeners.push(e), a.data)) try {
    e(a.data);
  } catch {
  }
}, subscribe(e) {
  if (typeof e != "function") return () => {
  };
  if (a.listeners.push(e), a.data) try {
    e(a.data);
  } catch {
  }
  return () => {
    let t = a.listeners.indexOf(e);
    t !== -1 && a.listeners.splice(t, 1);
  };
}, paintCartCount: E };
export {
  D as a,
  B as b,
  E as c,
  fe as d,
  pe as e
};
