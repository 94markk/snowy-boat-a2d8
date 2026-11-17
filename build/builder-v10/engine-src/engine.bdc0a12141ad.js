import { a as oa, b as ge } from "./chunks/chunk.6ed550529e0e.js";
import { a as da, b as Ct, c as ce, d as ca, e as re } from "./chunks/chunk.5a740e779038.js";
import { a as ua, b as Ge, d as Je } from "./chunks/chunk.385b5371c4d9.js";
import { a as J, c as Y, d as pa, e as et, f as ma, g as tt, i as qe } from "./chunks/chunk.fefdb647cb09.js";
import { a as l, b as N, c as n, d as H, e as c, g as ae, h as G, i as U, j as le, l as $e, m as z, n as at, o as rt, q as Be, s as Et, t as it, u as ha, w as Nt, x as lt, y as Oe } from "./chunks/chunk.71647e346105.js";
var Tt = "dbv9_theme", nt = /* @__PURE__ */ new Set(["light", "dark", "system"]), ue = n.matchMedia ? n.matchMedia("(prefers-color-scheme: dark)") : null, be = "light", It = 0, ze = null, Dt = null, st = false, fa = () => {
  let t = $e.read(Tt);
  return nt.has(t) ? t : null;
}, Pt = (t) => $e.write(Tt, t, 31536e3), ga = (t) => t === "system" ? ue && ue.matches ? "dark" : "light" : t === "dark" ? "dark" : "light";
function qt(t) {
  for (let e of H("[data-delicat-theme-toggle]")) {
    let a = t === "dark";
    e.setAttribute("aria-pressed", a ? "true" : "false"), e.setAttribute("aria-label", a ? "Activer le mode clair" : "Activer le mode sombre"), e.dataset.delicatThemeState = t, e.title = a ? "Mode clair" : "Mode sombre";
  }
}
function $t(t) {
  for (let e of H("img[data-dsb8-logo-light]")) {
    let a = e.getAttribute("data-dsb8-logo-light") || "", r = e.getAttribute("data-dsb8-logo-dark") || "", i = t === "dark" && r ? r : a;
    !i || e.getAttribute("src") === i || (e.setAttribute("src", i), e.removeAttribute("srcset"), e.removeAttribute("sizes"));
  }
  for (let e of H(".dsb8-header__logo")) {
    let a = e.querySelector(".dsb8-header__logo-light"), r = e.querySelector(".dsb8-header__logo-dark");
    if (!a || !r) continue;
    let i = t === "dark";
    a.hidden = i, r.hidden = !i, a.setAttribute("aria-hidden", i ? "true" : "false"), r.setAttribute("aria-hidden", i ? "false" : "true"), a.style.setProperty("display", i ? "none" : "flex", "important"), r.style.setProperty("display", i ? "flex" : "none", "important");
  }
}
function Bt(t) {
  let e = t === "dark", a = "delicat-home-mode-light", r = "delicat-home-mode-dark";
  l.body && (l.body.classList.toggle("dlc-theme-dark", e), l.body.classList.toggle("dlc-theme-light", !e), (l.body.classList.contains(a) || l.body.classList.contains(r) || l.body.classList.contains("delicat-builder-homepage-managed")) && (l.body.classList.toggle(r, e), l.body.classList.toggle(a, !e)));
  for (let i of H('[data-delicat-home-mode], [data-delicat-homepage-managed="1"], .delicat-page-layout--homepage-managed')) i.classList.toggle(r, e), i.classList.toggle(a, !e), i.hasAttribute("data-delicat-home-mode") && i.setAttribute("data-delicat-home-mode", t);
}
function ve() {
  if (!l.body) return false;
  let t = l.querySelector(".dap-shell.dap-floating, .dap-shell .dap-floating"), e = l.querySelector('a[aria-label="WhatsApp"][href*="BXQXUCKDDI3GO1"][style*="position:fixed"]');
  if (e && e.remove(), !t) return l.body.classList.remove("dbv9-assistant-open"), false;
  let a = t.querySelector(".dap-launcher"), r = t.querySelector(".dap-unread"), i = a ? a.getAttribute("aria-expanded") : "", o = t.querySelector('[role="dialog"][aria-hidden="false"], .dap-panel.is-open, .dap-window.is-open, .dap-dialog.is-open, .dap-chat.is-open'), s = t.getBoundingClientRect(), u = i === "true" || t.classList.contains("is-open") || t.classList.contains("dap-open") || t.classList.contains("is-expanded") || !!o || s.width > 240 || s.height > 240;
  if (l.body.classList.toggle("dbv9-assistant-open", u), a && !a.getAttribute("aria-label") && a.setAttribute("aria-label", "Ouvrir l\u2019assistant Delicat"), r) {
    let v = (r.textContent || "").trim(), h = Number.parseInt(v, 10), p = v === "" || !Number.isNaN(h) && h <= 0;
    r.hidden !== p && (r.hidden = p), r.setAttribute("aria-hidden", p ? "true" : "false");
  }
  return Dt !== t && typeof MutationObserver == "function" && (ze && ze.disconnect(), Dt = t, ze = new MutationObserver(() => {
    st || (st = true, U(() => {
      st = false, ve();
    }));
  }), ze.observe(t, { childList: true, subtree: true, attributes: true, attributeFilter: ["aria-expanded", "aria-hidden", "class"] })), true;
}
function ba() {
  if (ve() || typeof MutationObserver != "function") return;
  let t = new MutationObserver(() => {
    ve() && t.disconnect();
  });
  t.observe(l.body, { childList: true }), n.setTimeout(() => t.disconnect(), 3500);
}
function va(t) {
  return !t || typeof l.startViewTransition != "function" || z.reducedMotion || z.veryLowPower || N.hasAttribute("data-dbp-transition") ? "" : typeof N.animate == "function" && !z.lowPower && !N.classList.contains("delicat-low-power") && (!z.memory || z.memory >= 4) ? "circle" : "fade";
}
function ya(t) {
  let e = Number(t.clientX) || 0, a = Number(t.clientY) || 0;
  if (!e && !a) {
    let r = t.target instanceof Element ? t.target.closest("[data-delicat-theme-toggle]") : null, i = r && r.getBoundingClientRect();
    i && (e = i.left + i.width / 2, a = i.top + i.height / 2);
  }
  return { x: e, y: a };
}
function je(t, e = false, a = null) {
  be = nt.has(t) ? t : "light";
  let r = ga(be), i = e && va(a), o = () => {
    N.dataset.delicatThemePreference = be, N.dataset.delicatTheme = r, N.classList.toggle("dlc-theme-dark", r === "dark"), N.classList.toggle("dlc-theme-light", r === "light"), N.style.colorScheme = r, Bt(r), $t(r), qt(r), Ot(r), ae("delicat:themechange", { theme: r, preference: be, vt: !!i });
  };
  if (i) {
    let { x: s, y: u } = ya(a), v = null;
    N.classList.add("dlc-theme-vt"), i === "fade" && N.classList.add("dlc-theme-vt--fade");
    try {
      v = l.startViewTransition(o);
    } catch {
      v = null;
    }
    if (!v) {
      N.classList.remove("dlc-theme-vt", "dlc-theme-vt--fade"), o();
      return;
    }
    let h = () => N.classList.remove("dlc-theme-vt", "dlc-theme-vt--fade");
    if (i === "fade") {
      v.finished.then(h, h);
      return;
    }
    v.finished.then(h, h), v.ready.then(() => {
      let p = n.innerWidth || N.clientWidth, b = n.innerHeight || N.clientHeight, w = Math.ceil(Math.hypot(Math.max(s, p - s), Math.max(u, b - u)));
      try {
        N.animate({ clipPath: [`circle(0px at ${s}px ${u}px)`, `circle(${w}px at ${s}px ${u}px)`] }, { duration: 480, easing: "cubic-bezier(0.22, 1, 0.36, 1)", pseudoElement: "::view-transition-new(root)" });
      } catch {
      }
    }, () => {
    });
    return;
  }
  e && !z.reducedMotion && !z.lowPower && (N.classList.add("dlc-theme-switching"), n.clearTimeout(It), It = n.setTimeout(() => N.classList.remove("dlc-theme-switching"), 220)), o();
}
function ot() {
  let t = N.dataset.delicatTheme === "dark" ? "dark" : "light";
  Bt(t), $t(t), qt(t), ba(), Ot(t);
}
function Ot(t) {
  let e = l.querySelectorAll('meta[name="theme-color"][media]');
  if (!e.length) return;
  let a = { light: "", dark: "" };
  for (let i of e) {
    i.hasAttribute("data-dlc-c") || i.setAttribute("data-dlc-c", i.getAttribute("content") || "");
    let o = i.getAttribute("media") || "";
    /dark/.test(o) ? a.dark = i.getAttribute("data-dlc-c") : /light/.test(o) && (a.light = i.getAttribute("data-dlc-c"));
  }
  let r = t === "dark" ? a.dark : a.light;
  if (r) for (let i of e) i.getAttribute("content") !== r && i.setAttribute("content", r);
}
var dt = "/*dk3*/", We = "dbv9_dk_css", zt = "dbv9_dk_rules", jt = ".dsb8-header,.dlx-drawer,.delicat-bottom-nav,.dsb8-cart-panel,.dbv9-notifications__panel,.dlc-review-overlay,[data-dlc-dark-skip]", ct = /* @__PURE__ */ new Set(["script", "style", "noscript", "template", "img", "video", "canvas", "iframe", "picture", "source", "link", "meta", "br", "hr", "object", "embed", "audio", "map", "area", "svg", "math", "head", "title"]), Me = /* @__PURE__ */ new WeakMap(), pe = /* @__PURE__ */ new Set(), ut = 0, ye = null, me = () => N.dataset.delicatTheme === "dark";
function he(t) {
  let e = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+)(%?))?\s*\)$/.exec(t || "");
  if (!e) return null;
  let a = e[4] == null ? 1 : parseFloat(e[4]) / (e[5] ? 100 : 1);
  return [+e[1], +e[2], +e[3], a];
}
function we(t) {
  let e = (a) => (a /= 255, a <= 0.03928 ? a / 12.92 : Math.pow((a + 0.055) / 1.055, 2.4));
  return 0.2126 * e(t[0]) + 0.7152 * e(t[1]) + 0.0722 * e(t[2]);
}
function pt(t) {
  let e = t[0] / 255, a = t[1] / 255, r = t[2] / 255, i = Math.max(e, a, r), o = Math.min(e, a, r), s = (i + o) / 2, u = 0, v = 0;
  if (i !== o) {
    let h = i - o;
    v = s > 0.5 ? h / (2 - i - o) : h / (i + o), u = i === e ? (a - r) / h + (a < r ? 6 : 0) : i === a ? (r - e) / h + 2 : (e - a) / h + 4, u /= 6;
  }
  return [u, v, s];
}
function mt(t, e, a) {
  if (!e) {
    let s = Math.round(a * 255);
    return "rgb(" + s + ", " + s + ", " + s + ")";
  }
  let r = a < 0.5 ? a * (1 + e) : a + e - a * e, i = 2 * a - r, o = (s) => (s < 0 && (s += 1), s > 1 && (s -= 1), s < 1 / 6 ? i + (r - i) * 6 * s : s < 0.5 ? r : s < 2 / 3 ? i + (r - i) * (2 / 3 - s) * 6 : i);
  return "rgb(" + Math.round(o(t + 1 / 3) * 255) + ", " + Math.round(o(t) * 255) + ", " + Math.round(o(t - 1 / 3) * 255) + ")";
}
function wa(t) {
  let [e, a, r] = pt(t);
  return a < 0.14 ? (e = 0.64, a = 0.4) : a = Math.min(a, 0.42), mt(e, a, Math.min(0.2, 0.115 + (1 - r) * 0.5));
}
function xa(t) {
  let [e, a, r] = pt(t);
  return a = a < 0.14 ? Math.min(a, 0.12) : Math.min(a, 0.65), mt(e, a, Math.min(0.95, Math.max(0.74, 1 - r * 0.6)));
}
function Sa(t) {
  let [e, a] = pt(t);
  return a < 0.14 ? "rgba(255, 255, 255, 0.1)" : mt(e, Math.min(a, 0.45), 0.3);
}
const Re = (t) => !!t && t[3] >= 0.5 && we(t) > 0.7;
function Wt(t) {
  let e = [], a = /rgba?\([^)]*\)/g, r;
  for (; r = a.exec(t); ) {
    let i = he(r[0]);
    i && e.push(i);
  }
  return e;
}
function ht(t) {
  let e = t.localName;
  if (e === "button") return true;
  if (e === "input") {
    let i = String(t.type || "").toLowerCase();
    return /^(button|submit|reset|checkbox|radio|range|color|file|image)$/.test(i);
  }
  let a = t.getAttribute("role");
  if (a === "button" || a === "switch" || a === "tab") return true;
  let r = typeof t.className == "string" ? t.className : "";
  return /(^|[\s_-])(btn|button)([\s_-]|$)/i.test(r);
}
const _a = (t) => t.localName === "textarea" || t.localName === "select" || t.localName === "input" && !ht(t) && String(t.type || "").toLowerCase() !== "hidden";
function Rt(t) {
  let e = (typeof t.className == "string" ? t.className : "") + " " + (t.id || "");
  if (/(^|[\s_-])(qr|qrcode|logo|barcode|avatar|swatch)([\s_-]|$)/i.test(e)) return true;
  if (!t.children.length) return false;
  for (let a of t.children) if (!/^(img|svg|picture|canvas|video)$/.test(a.localName)) return false;
  for (let a of t.childNodes) if (a.nodeType === 3 && a.nodeValue.trim()) return false;
  return true;
}
function ka(t) {
  for (let e of t.children) if (e.localName === "svg") return true;
  return false;
}
function Ma(t) {
  for (let e of t.childNodes) if (e.nodeType === 3 && e.nodeValue.trim()) return true;
  return false;
}
function La(t, e) {
  let a = Me.get(t), r = t.style;
  if (!a && !r.length) {
    a = {};
    let i = "";
    for (let o in e) a[o] = ["", ""], i += o + ":" + e[o] + "!important;";
    Me.set(t, a), r.cssText = i, t.setAttribute("data-dlc-dk", "");
    return;
  }
  a || Me.set(t, a = {});
  for (let i in e) i in a || (a[i] = [t.style.getPropertyValue(i), t.style.getPropertyPriority(i)]), t.style.setProperty(i, e[i], "important");
  t.setAttribute("data-dlc-dk", "");
}
function Ve(t) {
  let e = t ? [t, ...t.querySelectorAll("[data-dlc-dk]")].filter((a) => a.hasAttribute("data-dlc-dk")) : H("[data-dlc-dk]");
  for (let a of e) {
    let r = Me.get(a);
    if (r) for (let i in r) {
      let [o, s] = r[i];
      o ? a.style.setProperty(i, o, s) : a.style.removeProperty(i);
    }
    Me.delete(a), a.removeAttribute("data-dlc-dk");
  }
}
const He = /* @__PURE__ */ new Map();
function ft(t, e, a) {
  let r = t + e, i = He.get(r);
  return i === void 0 && (He.size > 400 && He.clear(), i = t === "s" ? wa(a) : t === "i" ? xa(a) : Sa(a), He.set(r, i)), i;
}
function Aa(t) {
  for (let e = t; e && e.nodeType === 1; e = e.parentElement) {
    let a = n.getComputedStyle(e), r = a.backgroundImage || "none";
    if (r !== "none") {
      if (r.indexOf("url(") !== -1) return null;
      let o = Wt(r).filter((s) => s[3] >= 0.2);
      if (o.length) return o[0];
    }
    let i = he(a.backgroundColor);
    if (i && i[3] >= 0.5) return i;
  }
  return [7, 9, 20, 1];
}
function Ca(t) {
  let e = /* @__PURE__ */ new Map();
  if (!l.body || !t || !t.isConnected) return e;
  let a = new Set(l.querySelectorAll(jt)), r = (s, u, v) => {
    let h = e.get(s);
    h || e.set(s, h = {}), h[u] = v;
  }, i = ["Top", "Right", "Bottom", "Left"], o = [[t, Aa(t.parentElement), false]];
  for (; o.length; ) {
    let [s, u, v] = o.pop();
    if (!s || s.nodeType !== 1 || ct.has(s.localName) || s.namespaceURI && s.namespaceURI !== "http://www.w3.org/1999/xhtml" || a.has(s)) continue;
    let h = n.getComputedStyle(s), p = h.display;
    if (p === "none") continue;
    let b = s.children;
    if (p === "contents") {
      for (let L = b.length - 1; L >= 0; L--) o.push([b[L], u, v]);
      continue;
    }
    let w = u;
    if (s !== l.body) {
      let L = h.backgroundColor, P = he(L), C = h.backgroundImage || "none";
      if (_a(s)) Re(P) ? (r(s, "background-color", "rgba(5, 7, 20, 0.62)"), r(s, "color", "#f4f5fb"), r(s, "border-color", "rgba(255, 255, 255, 0.1)"), r(s, "caret-color", "#f4f5fb"), r(s, "box-shadow", "inset 3px 3px 8px rgba(0, 0, 0, 0.55), inset -2px -2px 6px rgba(255, 255, 255, 0.035)"), w = [8, 10, 26, 1]) : P && P[3] >= 0.5 && (w = P);
      else {
        let E = false, g = C !== "none" && C.indexOf("url(") === -1 ? Wt(C) : null, M = g ? g.filter((D) => D[3] >= 0.2) : null, S = !!g && !M.length, W = !!M && M.length > 0 && M.every(Re);
        if (C === "none" || S ? Re(P) : W) {
          let D = s.offsetWidth, B = s.offsetHeight;
          if (!(D * B < 900 || D < 28 && B < 28 || ht(s) || Rt(s))) {
            let I = C === "none" || S || Re(P) ? P : M[0], R = C === "none" || S ? L : "g" + I.join(","), V = ft("s", R, I), d = he(V);
            r(s, "background-image", "linear-gradient(155deg, rgba(255, 255, 255, 0.07), rgba(255, 255, 255, 0.02) 60%)"), r(s, "background-color", u && we(u) < 0.06 ? "rgba(" + d[0] + ", " + d[1] + ", " + d[2] + ", 0.84)" : V), (h.boxShadow === "none" || /rgba?\(\s*2[0-5]\d/.test(h.boxShadow)) && r(s, "box-shadow", v ? "inset 0 1px 0 rgba(255, 255, 255, 0.05)" : "inset 0 1px 0 rgba(255, 255, 255, 0.07), 0 10px 26px rgba(0, 0, 0, 0.34)"), w = d, E = true;
          }
        }
        E || (C !== "none" && !S ? w = C.indexOf("url(") !== -1 ? null : M[0] : P && P[3] >= 0.5 && (w = P));
        for (let D of i) {
          if (!(parseFloat(h["border" + D + "Width"]) > 0)) continue;
          let B = h["border" + D + "Color"], I = he(B);
          I && I[3] >= 0.3 && we(I) > 0.55 && r(s, "border-" + D.toLowerCase() + "-color", ft("e", B, I));
        }
        if (w && (Ma(s) || ka(s))) {
          let D = h.color, B = he(D);
          if (B && B[3] >= 0.3) {
            let I = we(w), R = we(B), V = (Math.max(I, R) + 0.05) / (Math.min(I, R) + 0.05);
            if (I <= 0.2 && V < 4.5) {
              let d = ft("i", D, B), m = he(d);
              m && we(m) > R && r(s, "color", d);
            } else I > 0.6 && R > 0.45 && V < 2.2 && r(s, "color", "#14182d");
          }
        }
      }
    }
    let y = v || e.has(s) && "background-color" in e.get(s);
    for (let L = b.length - 1; L >= 0; L--) o.push([b[L], w, y]);
  }
  for (let [s, u] of e) La(s, u);
  return e;
}
const Vt = /^(delicat|dsb|dsb8|dbv9|dnp|dlc|dlx|dbn|dbp|dni|dmc|ddsw)[-_]/i;
function Ht(t) {
  for (let a of t.classList) if (Vt.test(a)) return "";
  if (t.id && Vt.test(t.id)) return "";
  if (t.id && /^[A-Za-z][\w-]{2,40}$/.test(t.id) && !/\d{3,}/.test(t.id)) return "#" + t.id;
  let e = [];
  for (let a of t.classList) /^[A-Za-z][\w-]{3,48}$/.test(a) && /[-_]/.test(a) && !/\d{3,}/.test(a) && !/^(is|has|js|dlc|wp|elementor-element|e-con)[-_]/i.test(a) && !/(active|open|show|hidden|selected|current|visible|focus|hover|loading|loaded|animated)/i.test(a) && e.push(a);
  return e.length ? t.localName + "." + e.slice(0, 3).join(".") : "";
}
function Ea(t) {
  t = t || /* @__PURE__ */ new Map();
  let e = {};
  try {
    e = JSON.parse(n.localStorage.getItem(zt) || "{}") || {};
    let p = n.localStorage.getItem(We) || "";
    p && p.indexOf(dt) !== 0 && (e = {});
  } catch {
    return;
  }
  let a = Math.floor(Date.now() / 1e3), r = (p) => Object.keys(p).sort().map((b) => b + ":" + p[b] + "!important").join(";"), i = /* @__PURE__ */ new Set(), o = false, s = !!l.getElementById("dlc-dk-cache"), u = /* @__PURE__ */ new Set();
  for (let [p, b] of t) if (!(!("color" in b) || Ht(p))) for (let w = p.parentElement; w; w = w.parentElement) {
    let y = t.get(w);
    y && "background-color" in y && u.add(w);
  }
  for (let [p, b] of t) {
    if (!("background-color" in b) && !("color" in b) || u.has(p)) continue;
    let w = Ht(p);
    if (!w || i.has(w)) continue;
    i.add(w);
    let y = r(b), L = true;
    try {
      for (let P of l.querySelectorAll(w)) {
        let C = t.get(P);
        if (C ? r(C) !== y : !(s && e[w] && e[w][0] === y)) {
          L = false;
          break;
        }
      }
    } catch {
      L = false;
    }
    L ? (!e[w] || e[w][0] !== y || a - e[w][1] > 86400) && (e[w] = [y, a], o = true) : e[w] && (delete e[w], o = true);
  }
  let v = Object.keys(e);
  for (let p of v) {
    if (i.has(p) || a - e[p][1] < 86400) continue;
    let b = null;
    try {
      b = l.querySelector(p);
    } catch {
    }
    b && !ht(b) && !Rt(b) ? (e[p][1] = a, o = true) : a - e[p][1] > 21 * 86400 && (delete e[p], o = true);
  }
  if (v = Object.keys(e), v.length > 240) {
    v.sort((p, b) => e[p][1] - e[b][1]);
    for (let p of v.slice(0, v.length - 240)) delete e[p];
    o = true;
  }
  if (!o) return;
  let h = dt + Object.keys(e).map((p) => "html.dlc-theme-dark " + p + "{" + e[p][0] + "}").join("");
  try {
    n.localStorage.setItem(zt, JSON.stringify(e)), h.length < 6e4 ? n.localStorage.setItem(We, h) : n.localStorage.removeItem(We);
  } catch {
  }
}
var Ft = -1;
function ne(t, e) {
  if (!me()) return;
  let a;
  (!t || t === l.body) && (Ft = l.styleSheets.length);
  try {
    a = Ca(t || l.body);
  } catch {
    return;
  }
  e && le(() => {
    try {
      Ea(a);
    } catch {
    }
  }, 2500);
}
function Na() {
  if (ut = 0, !me()) {
    pe.clear();
    return;
  }
  let t = [...pe].filter((e) => e.isConnected);
  if (pe.clear(), t.length > 60) return ne(l.body, true);
  for (let e of t) {
    let a = e.parentElement, r = false;
    for (; a; a = a.parentElement) if (t.indexOf(a) !== -1) {
      r = true;
      break;
    }
    r || e.closest && e.closest(jt) || ne(e, false);
  }
}
function Ta() {
  if (!l.getElementById("dlc-dk-cache")) try {
    let t = n.localStorage.getItem(We) || "";
    if (!t || t.indexOf(dt) !== 0 || t.length >= 6e4) return;
    let e = l.createElement("style");
    e.id = "dlc-dk-cache", e.setAttribute("data-delicat-keep", ""), e.textContent = t, l.head.appendChild(e);
  } catch {
  }
}
function Ia(t) {
  ye || typeof MutationObserver != "function" || !l.body || (ye = new MutationObserver((e) => {
    if (!me()) {
      for (let a of e) for (let r of a.addedNodes) r.nodeType === 1 && (r.hasAttribute("data-dlc-dk") || r.firstElementChild && r.querySelector("[data-dlc-dk]")) && Ve(r);
      return;
    }
    for (let a of e) {
      if (a.type === "attributes") {
        let r = a.target;
        if (r === l.body || r === N || r.nodeType !== 1 || ct.has(r.localName)) continue;
        if (pe.add(r), a.attributeName === "aria-expanded") for (let i of String(r.getAttribute("aria-controls") || "").split(/\s+/)) {
          let o = i && l.getElementById(i);
          o && pe.add(o);
        }
        continue;
      }
      for (let r of a.addedNodes) r.nodeType === 1 && !ct.has(r.localName) && pe.add(r);
    }
    pe.size && !ut && (ut = U(() => U(Na)));
  }), ye.observe(l.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["class", "hidden", "open", "aria-hidden", "aria-expanded"] }), t.addEventListener("abort", () => {
    ye && ye.disconnect(), ye = null;
  }, { once: true }));
}
function Da({ signal: t }) {
  let e = () => me() ? ne(l.body, true) : Ve();
  Ia(t), me() && (l.getElementById("dlc-dk-cache") ? le(() => ne(l.body, true), 300) : U(() => ne(l.body, true)));
  let a = 0;
  c(l, "delicat:themechange", (r) => {
    n.clearTimeout(a), r.detail && r.detail.theme === "dark" ? r.detail.vt ? (Ta(), a = n.setTimeout(() => U(() => ne(l.body, false)), 600)) : a = n.setTimeout(() => U(() => ne(l.body, false)), z.reducedMotion ? 0 : 320) : Ve();
  }, { signal: t }), c(l, "delicat:navigated", (r) => U(() => {
    if (!me()) return Ve();
    let i = r && r.detail && r.detail.main;
    i && i.isConnected ? ne(i, false) : ne(l.body, true);
  }), { signal: t }), c(n, "pageshow", (r) => r.persisted && e(), { passive: true, signal: t }), c(n, "load", () => me() && l.styleSheets.length !== Ft && le(e, 800), { once: true, signal: t });
}
var Xt = "dbv9-speed";
function Pa() {
  let t = null;
  try {
    t = n.sessionStorage.getItem(Xt);
  } catch {
  }
  let e = (a) => {
    a === "low" ? N.classList.add("delicat-low-power", "dsb8-low-power", "dsb-cheap-device") : a === "very-low" && N.classList.add("delicat-low-power", "delicat-very-low-power", "dsb8-low-power", "dsb-cheap-device");
  };
  if (t !== null) {
    e(t);
    return;
  }
  le(() => {
    let a = 1 / 0;
    for (let i = 0; i < 2; i++) {
      let o = n.performance.now(), s = 0;
      for (let u = 0; u < 3e5; u++) s = (s * 31 + u) % 1000003;
      if (s < 0) return;
      a = Math.min(a, n.performance.now() - o);
    }
    let r = a >= 12 ? "very-low" : a >= 7 ? "low" : "ok";
    try {
      n.sessionStorage.setItem(Xt, r);
    } catch {
    }
    e(r);
  }, 4e3);
}
function qa({ signal: t }) {
  if (z.saveData && N.classList.add("delicat-save-data"), z.lowPower ? N.classList.add("delicat-low-power", "dsb8-low-power", "dsb-cheap-device") : z.reducedMotion && N.classList.add("dsb-cheap-device"), !z.lowPower && (!z.memory || z.memory <= 4) && Pa(), z.reducedMotion && N.classList.add("delicat-reduce-motion"), je(fa() || N.dataset.delicatThemePreference || "light", false), c(l, "click", (e) => {
    if (!(e.target instanceof Element && e.target.closest("[data-delicat-theme-toggle]"))) return;
    e.preventDefault();
    let a = N.dataset.delicatTheme === "dark" ? "light" : "dark";
    Pt(a), je(a, true, e);
  }, { capture: true, signal: t }), ue) {
    let e = () => {
      be === "system" && je("system", true);
    };
    ue.addEventListener ? ue.addEventListener("change", e) : ue.addListener && ue.addListener(e);
  }
  c(l, "delicat:navigated", ot, { signal: t }), c(n, "pageshow", ot, { passive: true, signal: t }), c(l, "click", (e) => {
    e.target instanceof Element && e.target.closest(".dap-shell") && (n.setTimeout(ve, 0), n.setTimeout(ve, 360));
  }, { capture: true, signal: t }), c(l, "keydown", (e) => {
    e.key === "Escape" && n.setTimeout(ve, 0);
  }, { capture: true, signal: t }), ot(), n.DelicaTheme = Object.freeze({ get: () => N.dataset.delicatTheme || "light", getPreference: () => be, set: (e) => {
    let a = nt.has(e) ? e : "light";
    Pt(a), je(a, true);
  } });
}
var $a = 82;
function gt() {
  let t = Ct();
  if (t !== null) return ce(t), true;
  let e = l.querySelector(".dsb8-woo-cart-count-fragment");
  if (e) return ce(e.getAttribute("data-count") || e.textContent), true;
  let a = l.querySelector(".cart-contents-count,.mini-cart-count,.widget_shopping_cart .count");
  return a ? (ce(a.textContent), true) : false;
}
function Ba({ signal: t }) {
  if (c(l, "click", (d) => {
    let m = G(d.target, "[data-dsb8-menu-trigger]");
    if (!m || l.querySelector("[data-dlx-drawer]")) return;
    let k = l.querySelector(".dsb-menu-toggle,[data-dsb-menu-toggle],.dsb-modern-menu-toggle");
    if (k && k !== m) {
      k.click();
      return;
    }
    ae("dsb8:overlay:open", { id: "modern-menu", trigger: m });
  }, { signal: t }), z.standalone) {
    let d = l.querySelector(".dsb8-header__row"), m = (l.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="home"]') || l.querySelector(".dsb8-header__brand") || {}).href || "/", k = "/";
    try {
      k = new URL(m, location.href).pathname.replace(/\/+$/, "") || "/";
    } catch {
    }
    let q = null, T = () => {
      let Q = (location.pathname.replace(/\/+$/, "") || "/") !== k;
      Q && !q && d && (q = l.createElement("button"), q.type = "button", q.className = "dsb8-header__action dsb8-header__back", q.setAttribute("aria-label", "Retour"), q.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>', c(q, "click", () => {
        history.length > 1 ? history.back() : location.assign(m);
      }, { signal: t }), d.insertBefore(q, d.firstChild)), q && (q.hidden = !Q);
      let K = l.querySelector(".dsb8-header");
      K && K.classList.toggle("has-back", Q && !!q);
    };
    T(), c(l, "delicat:navigated", T, { signal: t }), c(l, "delicat:placeholder", T, { signal: t }), c(n, "popstate", () => n.setTimeout(T, 0), { signal: t });
  }
  gt(), c(n, "pageshow", (d) => {
    if (gt(), d.persisted) {
      let m = lt();
      m && m(l.body).trigger("wc_fragment_refresh");
    }
  }, { signal: t }), Oe("added_to_cart removed_from_cart updated_wc_div updated_cart_totals wc_fragments_loaded wc_fragments_refreshed", () => n.setTimeout(gt, 0), t), Oe("added_to_cart", (d, m) => {
    if (m && m[".dsb8-woo-cart-count-fragment"]) {
      let k = l.createElement("div");
      k.innerHTML = m[".dsb8-woo-cart-count-fragment"];
      let q = k.firstElementChild;
      q && ce(q.getAttribute("data-count") || q.textContent);
    }
  }, t), n.DSB8 && n.DSB8.emit && n.DSB8.emit("component:ready", { id: "header-beta2" });
  let e = false, a = 0, r = 0, i = 0, o = () => H("[data-dsb8-cart-root]"), s = (d) => {
    let m = d && d.querySelector(".dsb8-cart-panel"), k = d && d.querySelector("[data-dsb8-cart-trigger]");
    if (!m || !k) return;
    let q = n.visualViewport, T = q ? q.width : n.innerWidth, Q = q ? q.height : n.innerHeight;
    if (T > 767) {
      m.removeAttribute("data-dsb8-placed");
      return;
    }
    let K = T <= 420 ? 10 : 12, x = Math.min(390, T - K * 2), A = k.getBoundingClientRect(), X = Math.min(T - K, Math.max(K + x, A.right + 6)) - x, O = Math.max(8, Math.round(A.bottom + 10));
    m.style.setProperty("--dsb8-cart-top", O + "px"), m.style.setProperty("--dsb8-cart-left", Math.round(X) + "px"), m.style.setProperty("--dsb8-cart-width", Math.round(x) + "px"), m.style.setProperty("--dsb8-cart-max-height", Math.max(220, Math.round(Q - O - K)) + "px"), m.setAttribute("data-dsb8-placed", "");
  }, u = 0, v = async (d) => {
    if (!d || !d.dataset.cartUrl) return;
    let m = ++u;
    try {
      let k = await qe("delicat_builder_v9_header_cart", {}, { url: d.dataset.cartUrl, timeout: 8e3 });
      if (m !== u || !k || !d.isConnected) return;
      let q = d.querySelector(".dsb8-cart-list"), T = d.querySelector(".dsb8-cart-panel"), Q = d.querySelector(".dsb8-cart-panel__foot");
      if (q && typeof k.list == "string") {
        let K = it("<body>" + k.list);
        q.replaceChildren(...K ? Array.from(K.body.childNodes) : []);
      }
      if (typeof k.foot == "string") {
        let K = k.foot ? it("<body>" + k.foot) : null, x = K && K.body.firstElementChild;
        x ? Q ? Q.replaceWith(x) : T && T.appendChild(x) : Q && Q.remove();
      }
      k.nonce && (d.dataset.nonce = k.nonce), ce(parseInt(k.count, 10) || 0), W();
    } catch {
    }
  }, h = () => !!l.querySelector("[data-dsb8-cart-root].is-open"), p = () => {
    N.classList.remove("dsb8-cart-locked");
  }, b = (d, m) => {
    if (!d) return;
    m && s(d), d.classList.toggle("is-open", !!m);
    let k = d.querySelector("[data-dsb8-cart-trigger]");
    k && k.setAttribute("aria-expanded", m ? "true" : "false"), e = !!m, m && (a = n.pageYOffset || 0, r = n.visualViewport && n.visualViewport.pageTop || 0), p();
  }, w = (d) => {
    for (let m of o()) m !== d && m.classList.contains("is-open") && m.classList.remove("is-open");
    for (let m of H("[data-dsb8-cart-trigger]")) {
      let k = m.closest("[data-dsb8-cart-root]");
      k && !k.classList.contains("is-open") && m.setAttribute("aria-expanded", "false");
    }
    h() || (e = false), p();
  }, y = (d) => {
    if (d) {
      delete d.dataset.busy, d.classList.remove("is-removing", "swipe-right"), d.style.setProperty("--dsb8-cart-dx", "0px");
      for (let m of ["opacity", "maxHeight", "marginTop", "marginBottom"]) d.style[m] = "";
    }
  }, L = (d, m) => {
    d.style.maxHeight = d.offsetHeight + "px", d.style.overflow = "hidden", d.classList.add("is-removed"), U(() => U(() => {
      d.style.maxHeight = "0px", d.style.marginTop = "0px", d.style.marginBottom = "0px", d.style.opacity = "0";
    })), n.setTimeout(m, 240);
  }, P = (d, m) => {
    let k = d.querySelector(".dsb8-cart-list");
    if (!k) return;
    let q = k.querySelector(".dsb8-cart-error");
    q && q.remove();
    let T = l.createElement("div");
    T.className = "dsb8-cart-error", T.textContent = m || "Impossible de supprimer cet article.", k.insertBefore(T, k.firstChild), n.setTimeout(() => T.remove(), 3200);
  }, C = (d, m) => {
    let k = parseInt(m && m.count, 10);
    (!isFinite(k) || k < 0) && (k = 0), ce(k), m && m.nonce && (d.dataset.nonce = m.nonce);
    let q = d.querySelector(".dsb8-cart-panel__foot");
    if (k === 0) {
      let T = d.querySelector(".dsb8-cart-list");
      T && (T.innerHTML = '<div class="dsb8-cart-empty">Votre panier est vide.</div>'), q && q.remove();
      return;
    }
    if (q && m && m.subtotal) {
      let T = q.querySelector("strong");
      T && (T.innerHTML = m.subtotal, T.classList.add("is-updated"), n.setTimeout(() => T.classList.remove("is-updated"), 560));
    }
  }, E = (d, m) => {
    let k = lt();
    n.clearTimeout(i), i = n.setTimeout(() => {
      k && (k(l.body).trigger("removed_from_cart", [{}, "", null]), k(l.body).trigger("dsb8_cart_updated", [m || {}, d || null])), ae("delicat:cart-changed", { source: "header-cart" });
    }, 40);
  }, g = (d) => {
    let m = lt();
    if (!m) {
      location.reload();
      return;
    }
    let k = () => {
      m(l.body).off("wc_fragments_refreshed wc_fragments_loaded", k), n.setTimeout(() => {
        let q = l.querySelector('.dsb8-cart-swipe[data-cart-key="' + d.replace(/"/g, '\\"') + '"]'), T = q && q.closest("[data-dsb8-cart-root]");
        T && M(T, q, true);
      }, 60);
    };
    m(l.body).on("wc_fragments_refreshed wc_fragments_loaded", k), m(l.body).trigger("wc_fragment_refresh");
  };
  async function M(d, m, k) {
    if (!d || !m || m.dataset.busy === "1") return;
    let q = m.getAttribute("data-cart-key");
    if (q) {
      m.dataset.busy = "1", m.classList.add("is-removing");
      try {
        let T = await qe("delicat_builder_v9_header_remove_cart_item", { nonce: d.dataset.nonce || "", cart_item_key: q }, { url: d.dataset.ajax });
        L(m, () => {
          m.remove(), C(d, T || {}), E(d, T || {});
        });
      } catch (T) {
        if ((T && T.data && T.data.code) === "stale_nonce" && !k) {
          y(m), g(q);
          return;
        }
        y(m), P(d, T && T.data && T.data.message);
      }
    }
  }
  let S = (d, m) => {
    if (m.dataset.bound === "1") return;
    m.dataset.bound = "1";
    let k = 0, q = 0, T = 0, Q = false, K = "", x = (O, f) => {
      k = O, q = f, T = 0, Q = true, K = "", m.classList.add("is-dragging");
    }, A = (O, f, _) => {
      if (!Q) return;
      let $ = O - k, j = f - q;
      if (!K) {
        if (Math.abs($) < 7 && Math.abs(j) < 7) return;
        if (K = Math.abs($) > Math.abs(j) ? "x" : "y", K === "y") {
          Q = false, m.classList.remove("is-dragging");
          return;
        }
      }
      T = Math.max(-150, Math.min(150, $)), _ && _.cancelable && _.preventDefault(), m.style.setProperty("--dsb8-cart-dx", T + "px"), m.classList.toggle("is-swiping", Math.abs(T) > 2), m.classList.toggle("swipe-right", T > 0);
    }, X = () => {
      Q && (Q = false, m.classList.remove("is-dragging"), Math.abs(T) >= $a ? (m.style.setProperty("--dsb8-cart-dx", T < 0 ? "-120%" : "120%"), n.setTimeout(() => M(d, m), 120)) : (m.style.setProperty("--dsb8-cart-dx", "0px"), m.classList.remove("is-swiping", "swipe-right")), T = 0);
    };
    n.PointerEvent ? (c(m, "pointerdown", (O) => {
      G(O.target, "button,a") || O.pointerType === "mouse" && O.button !== 0 || x(O.clientX, O.clientY);
    }, { signal: t }), c(m, "pointermove", (O) => A(O.clientX, O.clientY, O), { passive: false, signal: t }), c(m, "pointerup", X, { signal: t }), c(m, "pointercancel", X, { signal: t })) : (c(m, "touchstart", (O) => {
      if (G(O.target, "button,a")) return;
      let f = O.touches[0];
      x(f.clientX, f.clientY);
    }, { passive: true, signal: t }), c(m, "touchmove", (O) => {
      let f = O.touches[0];
      A(f.clientX, f.clientY, O);
    }, { passive: false, signal: t }), c(m, "touchend", X, { signal: t }));
  }, W = () => {
    for (let d of o()) for (let m of H(".dsb8-cart-swipe", d)) S(d, m);
    if (e && !h()) {
      let d = l.querySelector("[data-dsb8-cart-root]");
      d && b(d, true);
    }
    p();
  };
  c(l, "click", (d) => {
    let m = d.target;
    if (!m || !m.closest) return;
    let k = m.closest("[data-dsb8-cart-trigger]");
    if (k) {
      d.preventDefault(), d.stopPropagation();
      let T = k.closest("[data-dsb8-cart-root]");
      if (!T) return;
      let Q = !T.classList.contains("is-open");
      w(T), b(T, Q), Q && v(T);
      let K = Q && T.querySelector(".dsb8-cart-panel");
      K && (K.hasAttribute("tabindex") || K.setAttribute("tabindex", "-1"), n.requestAnimationFrame(() => K.focus({ preventScroll: true })));
      return;
    }
    if (m.closest(".dsb8-cart-panel__close")) {
      d.preventDefault(), b(m.closest("[data-dsb8-cart-root]"), false);
      return;
    }
    let q = m.closest(".dsb8-cart-item__remove");
    if (q) {
      d.preventDefault(), d.stopPropagation(), M(q.closest("[data-dsb8-cart-root]"), q.closest(".dsb8-cart-swipe"));
      return;
    }
    m.closest("[data-dsb8-cart-root]") || w();
  }, { signal: t }), c(l, "keydown", (d) => {
    if (d.key !== "Escape") return;
    let m = l.querySelector("[data-dsb8-cart-root].is-open"), k = m && m.contains(l.activeElement) && m.querySelector("[data-dsb8-cart-trigger]");
    w(), k && k.focus({ preventScroll: true });
  }, { signal: t }), c(n, "resize", p, { passive: true, signal: t }), c(n, "resize", () => {
    for (let d of H("[data-dsb8-cart-root].is-open")) s(d);
  }, { passive: true, signal: t }), c(n, "pagehide", p, { signal: t });
  let D = 0;
  c(n, "scroll", () => {
    !e || D || (D = U(() => {
      if (D = 0, !e) return;
      let d = n.pageYOffset || 0;
      Math.abs(d - a) > 10 && w(), a = d;
    }));
  }, { passive: true, signal: t });
  let B = null;
  c(l, "touchstart", (d) => {
    if (!e || !d.touches || !d.touches[0]) {
      B = null;
      return;
    }
    if (G(d.target, ".dsb8-cart-list")) {
      B = null;
      return;
    }
    B = d.touches[0].clientY;
  }, { passive: true, capture: true, signal: t }), c(l, "touchmove", (d) => {
    B === null || !e || !d.touches || !d.touches[0] || Math.abs(d.touches[0].clientY - B) > 8 && (w(), B = null);
  }, { passive: true, capture: true, signal: t }), c(l, "touchend", () => {
    B = null;
  }, { passive: true, capture: true, signal: t }), c(l, "touchcancel", () => {
    B = null;
  }, { passive: true, capture: true, signal: t }), c(n, "wheel", (d) => {
    e && Math.abs(d.deltaY) > 2 && !G(d.target, ".dsb8-cart-list") && w();
  }, { passive: true, capture: true, signal: t }), n.visualViewport && c(n.visualViewport, "scroll", () => {
    let d = n.visualViewport.pageTop || 0;
    e && Math.abs(d - r) > 6 && w(), r = d;
  }, { passive: true, signal: t }), W(), Oe("wc_fragments_refreshed wc_fragments_loaded added_to_cart removed_from_cart", () => n.setTimeout(W, 0), t);
  let I = (d) => {
    if (!(d && d.detail && d.detail.source === "header-cart")) for (let m of H("[data-dsb8-cart-root].is-open")) v(m);
  };
  c(l, "delicat:cart-changed", I, { signal: t }), c(l, "delicat:express-added", I, { signal: t }), c(l, "wc-blocks_added_to_cart", I, { signal: t }), Oe("added_to_cart", () => I(), t);
  let R = l.querySelector("[data-dsb8-header]");
  if (R && n.MutationObserver) {
    let d = 0, m = new MutationObserver((k) => {
      for (let q of k) if (!(!q.addedNodes.length && !q.removedNodes.length)) {
        n.clearTimeout(d), d = n.setTimeout(W, 60);
        return;
      }
    });
    m.observe(R, { childList: true, subtree: true }), t.addEventListener("abort", () => m.disconnect(), { once: true });
  }
  let V = () => {
    for (let d of H("[data-dsb8-cart-root].is-open")) {
      d.classList.remove("is-open");
      let m = d.querySelector("[data-dsb8-cart-trigger]");
      m && m.setAttribute("aria-expanded", "false");
    }
    for (let d of H(".dsb541-notifications.is-open")) d.classList.remove("is-open");
  };
  c(l, "dsb8:overlay:open", (d) => {
    if (!d.detail || d.detail.id !== "modern-menu") return;
    V();
    let m = l.querySelector(".dsb-menu-toggle,[data-dsb-menu-toggle],.dsb-modern-menu-toggle");
    m && !m.matches("[data-dsb8-menu-trigger]") && !l.querySelector("[data-dlx-drawer]") && m.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, view: n }));
  }, { signal: t }), c(l, "click", (d) => {
    G(d.target, "[data-dsb8-menu-trigger]") && V();
  }, { capture: true, signal: t }), c(l, "delicat:navigate", () => w(), { signal: t });
}
function Oa({ signal: t }) {
  let e = l.querySelector("[data-dlx-drawer]");
  if (!e) return;
  let a = e.querySelector("[data-dlx-panel]"), r = e.querySelector("[data-dlx-scroll]"), i = e.querySelector("[data-dlx-search]"), o = e.querySelector("[data-dlx-empty]"), s = false, u = null, v = 0, h = "", p = 0, b = 0, w = null, y = 0, L = 0, P = [];
  N.classList.add("dlx-ready");
  let C = (x) => {
    if (x) {
      if (P.length) return;
      for (let A of Array.from(l.body.children)) A === e || A.contains(e) || /^(SCRIPT|STYLE|LINK)$/.test(A.tagName) || (P.push({ node: A, inert: A.inert, aria: A.getAttribute("aria-hidden") }), A.inert = true, A.setAttribute("aria-hidden", "true"));
      return;
    }
    for (let A of P) A.node.inert = A.inert, A.aria === null ? A.node.removeAttribute("aria-hidden") : A.node.setAttribute("aria-hidden", A.aria);
    P = [];
  }, E = (x) => {
    for (let A of H("[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle")) A.setAttribute("aria-expanded", x ? "true" : "false"), A.setAttribute("aria-controls", e.id || "delicat-drawer");
  }, g = () => {
    v = n.pageYOffset || 0, h = l.body.style.top, N.classList.add("dlx-open"), l.body.style.top = -v + "px";
  }, M = () => {
    N.classList.remove("dlx-open"), l.body.style.top = h, n.scrollTo({ top: v, left: 0, behavior: "instant" });
  }, S = () => {
    s || (e.classList.add("is-warm"), e.style.visibility = "hidden", e.style.pointerEvents = "none", e.hidden = false, e.setAttribute("aria-hidden", "true"));
  }, W = () => {
    e.classList.remove("is-warm"), e.style.visibility = "", e.style.pointerEvents = "";
  }, D = () => {
    b && (n.clearTimeout(b), b = 0), a && a.removeEventListener("transitionend", B), e.classList.remove("is-entering"), s && re.refresh("drawer-open");
  };
  function B(x) {
    x.target === a && x.propertyName === "transform" && D();
  }
  let I = () => {
    p && (n.clearTimeout(p), p = 0), a && a.removeEventListener("transitionend", R), s || S();
  };
  function R(x) {
    x.target === a && x.propertyName === "transform" && I();
  }
  let V = () => {
    s || (s = true, E(true), u = l.activeElement, p && (n.clearTimeout(p), p = 0), a && a.removeEventListener("transitionend", R), e.hidden = false, W(), e.setAttribute("aria-hidden", "false"), e.classList.add("is-entering"), g(), r && (r.scrollTop = 0), U(() => {
      s && (e.classList.add("is-open"), b && n.clearTimeout(b), b = n.setTimeout(D, 240), a && a.addEventListener("transitionend", B), U(() => {
        s && (C(true), Nt(a || e));
      }));
    }), ae("dlx:open"));
  }, d = (x) => {
    s && (s = false, E(false), w = null, D(), e.classList.remove("is-open", "is-dragging"), a && (a.style.transform = ""), C(false), M(), p && n.clearTimeout(p), p = n.setTimeout(I, 240), a && a.addEventListener("transitionend", R), x !== false && u && Nt(u), ae("dlx:close"));
  }, m = (x) => {
    let A = String(x || "").trim().toLowerCase(), X = 0;
    for (let O of H(".dlx-item", e)) {
      let f = !A || (O.getAttribute("data-dlx-filter") || "").indexOf(A) !== -1;
      O.parentNode.hidden = !f, f && X++;
    }
    for (let O of H("[data-dlx-section]", e)) {
      let f = O.querySelectorAll("li:not([hidden]) > .dlx-item").length;
      O.hidden = !!A && !f, A && O.classList.remove("is-collapsed");
    }
    e.classList.toggle("is-filtering", !!A), o && (o.hidden = !A || X > 0);
  }, k = (x) => {
    if (!w) return;
    let A = w;
    if (w = null, e.classList.remove("is-dragging"), A.on && (L = Date.now()), x && A.on && a && Math.abs(A.dx) > Math.min(110, a.offsetWidth * 0.3)) {
      d();
      return;
    }
    a && (a.style.transform = "");
  }, q = (x) => {
    !(!s || x.pointerType === "mouse" || x.button || x.isPrimary === false) && !G(x.target, "input,textarea,select") && (w = { x: x.clientX, y: x.clientY, dx: 0, on: false, id: x.pointerId, left: e.classList.contains("is-left"), frame: false });
  }, T = (x) => {
    if (!w || x.pointerId !== w.id) return;
    let A = x.clientX - w.x, X = x.clientY - w.y;
    if (!w.on && Math.abs(X) > 10 && Math.abs(X) > Math.abs(A)) {
      k(false);
      return;
    }
    if (!w.on) {
      if (Math.abs(A) < 10 || Math.abs(A) < Math.abs(X)) return;
      w.on = true, e.classList.add("is-dragging");
      try {
        a.setPointerCapture && a.setPointerCapture(x.pointerId);
      } catch {
      }
    }
    if (w.dx = w.left ? Math.min(0, A) : Math.max(0, A), !w.frame) {
      let O = w;
      w.frame = true, U(() => {
        O.frame = false, w === O && s && (a.style.transform = "translateX(" + O.dx + "px)");
      });
    }
    x.cancelable && x.preventDefault();
  }, Q = (x) => {
    if (!(w && x && x.pointerId !== w.id) && (k(true), x && x.pointerId !== void 0)) try {
      a.hasPointerCapture && a.hasPointerCapture(x.pointerId) && a.releasePointerCapture(x.pointerId);
    } catch {
    }
  }, K = (x) => {
    let A = x.getAttribute("data-dlx-copy") || "", X = x.querySelector("[data-dlx-copy-label]"), O = () => {
      if (!X) return;
      let f = X.textContent;
      X.textContent = "Copi\xE9 \u2713", x.classList.add("is-done"), n.setTimeout(() => {
        X.textContent = f, x.classList.remove("is-done");
      }, 1400);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(A).then(O, O);
    else {
      let f = l.createElement("textarea");
      f.value = A, f.setAttribute("readonly", ""), f.style.position = "fixed", f.style.opacity = "0", l.body.appendChild(f), f.select();
      try {
        l.execCommand("copy");
      } catch {
      }
      f.remove(), O();
    }
  };
  c(l, "pointerdown", (x) => {
    y = 0, L = 0, !G(x.target, "[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle") || s || x.button || x.isPrimary === false || (y = Date.now(), x.preventDefault(), V());
  }, { capture: true, signal: t }), c(l, "click", (x) => {
    let A = x.target;
    if (!A || A.nodeType !== 1) return;
    if (y) {
      let $ = Date.now() - y;
      if (y = 0, $ < 700 && x.detail !== 0) {
        x.preventDefault(), x.stopImmediatePropagation();
        return;
      }
    }
    if (L) {
      let $ = Date.now() - L;
      if (L = 0, $ < 700 && x.detail !== 0) {
        x.preventDefault(), x.stopImmediatePropagation();
        return;
      }
    }
    if (G(A, "[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle")) {
      x.preventDefault(), x.stopImmediatePropagation(), s ? d() : V();
      return;
    }
    if (!s) return;
    if (G(A, "[data-dlx-close]")) {
      x.preventDefault(), d();
      return;
    }
    let X = G(A, "[data-dlx-toggle]");
    if (X && e.contains(X)) {
      let $ = G(X, "[data-dlx-section]");
      if ($) {
        let j = $.classList.toggle("is-collapsed");
        X.setAttribute("aria-expanded", j ? "false" : "true");
      }
      return;
    }
    let O = G(A, "[data-dlx-copy]");
    if (O && e.contains(O)) {
      x.preventDefault(), K(O);
      return;
    }
    let f = G(A, "[data-dlx-install]");
    if (f && e.contains(f)) {
      x.preventDefault(), d(), ae("delicat:install-app");
      return;
    }
    let _ = G(A, "a[href]");
    _ && e.contains(_) && !_.target && (_.getAttribute("href") || "").charAt(0) !== "#" && d();
  }, { capture: true, signal: t }), c(l, "dsb:close-menu", () => d(false), { signal: t }), c(l, "delicat:navigate", () => d(false), { signal: t }), c(n, "resize", () => k(false), { passive: true, signal: t }), c(l, "dsb8:overlay:open", (x) => {
    x.detail && x.detail.id === "modern-menu" && (x.stopImmediatePropagation(), V());
  }, { capture: true, signal: t }), c(l, "keydown", (x) => {
    if (s) {
      if (x.key === "Escape") {
        x.preventDefault(), d();
        return;
      }
      if (x.key === "Tab") {
        let A = ha(a || e);
        if (!A.length) return;
        let X = A[0], O = A[A.length - 1];
        x.shiftKey && l.activeElement === X ? (x.preventDefault(), O.focus()) : !x.shiftKey && l.activeElement === O && (x.preventDefault(), X.focus());
      }
    }
  }, { signal: t }), i && (c(i, "input", () => m(i.value), { signal: t }), c(i, "keydown", (x) => {
    x.key === "Enter" && !i.value.trim() && x.preventDefault();
  }, { signal: t })), n.PointerEvent && a && (c(a, "pointerdown", q, { passive: true, signal: t }), c(a, "pointermove", T, { passive: false, signal: t }), c(a, "pointerup", Q, { passive: true, signal: t }), c(a, "pointercancel", () => k(false), { passive: true, signal: t }), c(a, "lostpointercapture", () => k(false), { passive: true, signal: t })), c(n, "pageshow", (x) => {
    x.persisted && s && d();
  }, { signal: t }), n.requestIdleCallback ? n.requestIdleCallback(S, { timeout: 2500 }) : n.setTimeout(S, 900), n.DelicatDrawer = { open: V, close: d, isOpen: () => s };
}
var bt = (t) => String(t || "/").replace(/\/+$/, "") || "/";
function za(t, e) {
  let a = bt(t.pathname).toLowerCase();
  for (let s of e) {
    let u = at(s.href);
    if (u && bt(u.pathname).toLowerCase() === a && u.search === t.search) return s.getAttribute("data-dbn-key") || "";
  }
  let r = e.find((s) => s.getAttribute("data-dbn-key") === "shop"), i = r ? at(r.href) : null, o = i ? bt(i.pathname).toLowerCase() : "/shop";
  return o !== "/" && a.indexOf(o + "/") === 0 || /\/(?:product|produit|product-category|categorie-produit|product-tag|etiquette-produit)(?:\/|$)/.test(a) || t.searchParams.has("s") && t.searchParams.get("post_type") === "product" ? "shop" : /\/(?:my-wallet|woo-wallet|wallet|portefeuille)(?:\/|$)/.test(a) ? "wallet" : /\/(?:cart|panier)(?:\/|$)/.test(a) ? "cart" : /\/(?:my-account|mon-compte)(?:\/|$)/.test(a) ? "account" : a === "/" ? "home" : "";
}
function ja(t) {
  z.reducedMotion || !t || (t.classList.remove("is-popping"), t.offsetWidth, t.classList.add("is-popping"), n.setTimeout(() => t.classList.remove("is-popping"), 420));
}
function Fe() {
  let t = H(".delicat-bottom-nav .dbn-item[data-dbn-key]"), e = H(".dsb8-header__nav [data-dbn-key]");
  if (!t.length && !e.length) return;
  let a = at(location.href), r = a ? za(a, t.length ? t : e) : "";
  for (let i of t) {
    let o = r ? i.getAttribute("data-dbn-key") === r : i.classList.contains("is-active");
    o && !i.classList.contains("is-active") && ja(i), i.classList.toggle("is-active", o), o ? i.setAttribute("aria-current", "page") : i.removeAttribute("aria-current");
  }
  for (let i of e) {
    let o = r ? i.getAttribute("data-dbn-key") === r : i.classList.contains("is-active");
    i.classList.toggle("is-active", o), o ? i.setAttribute("aria-current", "page") : i.removeAttribute("aria-current");
  }
}
var Wa = ".delicat-product-card, .delicat-category-tile, .delicat-category-chip, .dsb8-icon-menu__item, .dnp-related-card";
function Ra(t) {
  if (typeof Element == "undefined" || typeof Element.prototype.animate != "function") return;
  let e = null, a = 0, r = null, i = 0, o = 0, s = () => z.reducedMotion || N.classList.contains("delicat-low-power"), u = () => {
    if (a = 0, !(!e || !e.isConnected || r)) try {
      r = e.animate([{ transform: "none" }, { transform: "scale(0.97)" }], { duration: 110, easing: "cubic-bezier(0.22, 1, 0.36, 1)", fill: "forwards" });
    } catch {
      r = null;
    }
  }, v = () => {
    let p = e, b = r;
    if (e = null, r = null, a && (n.clearTimeout(a), a = 0), !(!p || !b)) try {
      let w = p.animate([{ transform: "scale(0.97)" }, { transform: "none" }], { duration: 280, easing: "cubic-bezier(0.34, 1.56, 0.64, 1)" });
      b.cancel(), w.onfinish = w.oncancel = () => w.cancel();
    } catch {
      b.cancel();
    }
  }, h = () => {
    a && (n.clearTimeout(a), a = 0), r ? v() : e = null;
  };
  c(l, "pointerdown", (p) => {
    if (s() || p.pointerType === "mouse" && p.button !== 0) return;
    let b = p.target instanceof Element ? p.target : null, w = b && b.closest(Wa);
    !w || b !== w && b.closest("[data-delicat-like], input, select, textarea, label") || (h(), e = w, i = p.clientX, o = p.clientY, a = n.setTimeout(u, 70));
  }, { passive: true, signal: t }), c(l, "pointermove", (p) => {
    e && (Math.abs(p.clientX - i) > 8 || Math.abs(p.clientY - o) > 8) && h();
  }, { passive: true, signal: t }), c(l, "pointerup", () => {
    e && (a ? (n.clearTimeout(a), a = 0, u(), n.setTimeout(v, 90)) : v());
  }, { passive: true, signal: t });
  for (let p of ["pointercancel", "dragstart"]) c(l, p, h, { passive: true, signal: t });
  c(l, "scroll", (p) => {
    let b = p.target;
    e && (b === l || b === N || b instanceof Node && b.contains(e)) && h();
  }, { passive: true, capture: true, signal: t }), c(n, "blur", h, { signal: t }), c(l, "delicat:navigated", h, { signal: t });
}
var Yt = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=reset]):not([type=range]):not([type=file]):not([type=color]):not([type=hidden]),textarea,[contenteditable="true"],[contenteditable=""]';
function Va(t) {
  if (!(n.matchMedia && n.matchMedia("(hover: none) and (pointer: coarse)").matches)) return;
  let e = n.visualViewport, a = () => Math.min(n.innerHeight || 0, e ? e.height : 1e5), r = a(), i = 0, o = 0, s = () => {
    let v = l.activeElement;
    return v && v !== l.body && v.matches && v.matches(Yt) ? v : null;
  }, u = () => {
    let v = s(), h = a();
    if (!v) {
      r = h, N.classList.remove("dlc-kb");
      return;
    }
    N.classList.toggle("dlc-kb", r - h > 140);
  };
  e && c(e, "resize", u, { passive: true, signal: t }), c(n, "resize", u, { passive: true, signal: t }), c(l, "focusin", (v) => {
    let h = v.target;
    !(h instanceof Element) || !h.matches(Yt) || (n.clearTimeout(i), n.clearTimeout(o), n.setTimeout(u, 300), o = n.setTimeout(() => {
      if (l.activeElement !== h || !h.getBoundingClientRect) return;
      let p = h.getBoundingClientRect(), b = e ? e.height : n.innerHeight;
      if (p.bottom > b - 12 || p.top < 0) try {
        h.scrollIntoView({ block: "center", behavior: "smooth" });
      } catch {
        h.scrollIntoView(false);
      }
    }, 350));
  }, { signal: t }), c(l, "focusout", () => (n.clearTimeout(i), i = n.setTimeout(u, 120)), { signal: t }), c(l, "delicat:navigated", () => N.classList.remove("dlc-kb"), { signal: t });
}
var Ha = "[data-dlx-close],.dlx-drawer__close,.dlx-drawer__backdrop,[data-dsb8-cart-close],.dsb8-cart-panel__close,.dsb541-close,.dsb-menu-close,[data-close],[data-dismiss],[data-dnp-express-close],[data-dpn-wallet-close],[aria-label^='Fermer'],[aria-label^='Close']";
function Fa(t) {
  let e = n.history, a = n.CloseWatcher, r = N.classList.contains("delicat-app"), i = r && e && typeof e.pushState == "function", o = !i && typeof a == "function";
  if (!i && !o) return;
  let s = false, u = null, v = false, h = "", p = 0, b = 0, w = 0, y = 0, L = () => !!(e.state && e.state.dlcOverlay), P = () => {
    if (!Je.anyOpen()) return;
    let g = l.activeElement && l.activeElement !== l.body ? l.activeElement : l;
    try {
      g.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", code: "Escape", keyCode: 27, which: 27, bubbles: true, cancelable: true }));
    } catch {
    }
  }, C = () => {
    let g = Je.anyOpen();
    if (g !== s) {
      if (s = g, o) {
        if (g) try {
          u = new a(), u.onclose = () => (u = null, s = false, P(), E());
        } catch {
          u = null;
        }
        else if (u) {
          let M = u;
          u = null;
          try {
            M.destroy();
          } catch {
          }
        }
        return;
      }
      if (g && !v) try {
        e.pushState(Object.assign({}, e.state || {}, { dlcOverlay: 1 }), "", n.location.href), v = true, h = n.location.href;
      } catch {
      }
      else !g && v && (v = false, Date.now() - b < 900 && Date.now() - p > 1500 && L() && n.location.href === h && e.back());
    }
  }, E = () => {
    n.clearTimeout(w), n.clearTimeout(y), w = n.setTimeout(C, 60), y = n.setTimeout(C, 450);
  };
  c(n, "click", (g) => {
    let M = g.target instanceof Element ? g.target : null;
    M && M.closest("a[href],button[type=submit],input[type=submit],form button:not([type=button])") && (p = Date.now()), M && !M.closest("a[href]") && (M.closest(Ha) || /backdrop|overlay|scrim/.test(String(M.className || ""))) && (b = Date.now()), E();
  }, { passive: true, capture: true, signal: t }), c(n, "keyup", (g) => (g.key === "Escape" && (b = Date.now()), E()), { passive: true, capture: true, signal: t }), c(n, "pointerup", E, { passive: true, capture: true, signal: t }), c(l, "delicat:navigate", () => p = Date.now(), { signal: t }), i && c(n, "beforeunload", () => p = Date.now(), { signal: t }), c(l, "delicat:navigated", () => (v = false, n.setTimeout(C, 0)), { signal: t }), i && c(n, "popstate", () => {
    !v || L() || (v = false, s = false, P());
  }, { signal: t }), t.addEventListener("abort", () => {
    if (u) try {
      u.destroy();
    } catch {
    }
  }, { once: true });
}
function Xa(t) {
  let e = null, a = (r) => {
    r ? (e || (e = l.createElement("div"), e.className = "dlc-offline", e.setAttribute("role", "status"), e.textContent = "Hors ligne : les prix et le stock peuvent ne pas \xEAtre \xE0 jour."), e.isConnected || l.body.appendChild(e)) : e && e.remove();
  };
  navigator.onLine === false && a(true), c(n, "offline", () => a(true), { signal: t }), c(n, "online", () => a(false), { signal: t }), c(l, "delicat:navigated", () => a(navigator.onLine === false), { signal: t });
}
var Kt = "[data-dsb8-cart-count],[data-dbn-cart-count],[data-dbp-cart-count],[data-delicat-cart-count]";
function Ya(t) {
  let e = -1, a = 0;
  c(l, "pointerdown", () => a = Date.now(), { passive: true, capture: true, signal: t });
  for (let r of H(Kt)) {
    let i = parseInt(r.textContent, 10);
    e = Math.max(e, isFinite(i) ? i : 0);
  }
  c(l, "delicat:cart-count", (r) => {
    let i = parseInt(r.detail && r.detail.count, 10);
    if (!isFinite(i)) return;
    let o = e >= 0 && i > e;
    if (o && Date.now() - a < 8e3 && typeof navigator.vibrate == "function" && !l.hidden) try {
      navigator.vibrate(12);
    } catch {
    }
    if (o && Date.now() - a < 8e3) {
      let s = l.getElementById("dbp-live");
      s || (s = l.createElement("div"), s.id = "dbp-live", s.className = "dbp-sr-only dlc-sr-only", s.setAttribute("aria-live", "polite"), s.setAttribute("role", "status"), l.body.appendChild(s)), s.textContent = "Ajout\xE9 au panier : " + i + (i > 1 ? " articles." : " article.");
    }
    if (e = i, !(!o || z.reducedMotion)) for (let s of H(Kt)) s.classList.remove("is-bumping"), s.offsetWidth, s.classList.add("is-bumping"), n.setTimeout(() => s.classList.remove("is-bumping"), 450);
  }, { signal: t });
}
function Ka({ signal: t }) {
  let e = () => {
    let o = Ct();
    o !== null && ce(o);
  };
  e(), c(n, "pageshow", e, { passive: true, signal: t }), c(l, "wc-blocks_added_to_cart", () => n.setTimeout(e, 0), { signal: t }), c(l, "wc-blocks_removed_from_cart", () => n.setTimeout(e, 0), { signal: t }), c(l, "pointerdown", (o) => {
    let s = o.target instanceof Element ? o.target.closest(".delicat-bottom-nav .dbn-item, [data-delicat-mobile-dock] a") : null;
    if (!s) return;
    s.classList.add("is-pressed");
    let u = () => s.classList.remove("is-pressed");
    n.setTimeout(u, 420), c(l, "pointerup", u, { once: true, passive: true }), c(l, "pointercancel", u, { once: true, passive: true });
  }, { passive: true, signal: t }), Fe(), c(l, "delicat:navigated", Fe, { signal: t }), c(l, "delicat:placeholder", Fe, { signal: t }), c(n, "popstate", () => n.setTimeout(Fe, 0), { signal: t }), Ra(t), Ya(t), Va(t), Fa(t), Xa(t);
  let a = () => {
    let o = l.body;
    o.classList.contains("dnp-express-open") && !l.querySelector("[data-dnp-express].is-open") && o.classList.remove("dnp-express-open", "dnp-express-busy"), o.classList.contains("dpn-wallet-modal-open") && !l.querySelector("[data-dpn-wallet-modal]:not([hidden])") && o.classList.remove("dpn-wallet-modal-open"), o.classList.contains("dpn-checkout-dock-ready") && !o.classList.contains("dpn-checkout") && o.classList.remove("dpn-checkout-dock-ready"), N.classList.contains("dlx-open") && !l.querySelector(".dlx-drawer.is-open,.dlx-drawer.is-entering") && (N.classList.remove("dlx-open"), o.style.top = ""), N.classList.contains("dsb-menu-open") && !l.querySelector(".dlx-drawer.is-open") && N.classList.remove("dsb-menu-open"), o.classList.remove("dbn-hidden");
  }, r = 0, i = () => {
    n.clearTimeout(r), r = n.setTimeout(a, 400);
  };
  c(l, "delicat:navigated", i, { signal: t }), c(n, "pageshow", i, { passive: true, signal: t }), c(l, "visibilitychange", () => {
    l.hidden || i();
  }, { signal: t });
}
var Ua = "(max-width:960px) 41vw, 252px", Qa = "M12 20.6C11.4 20.2 3 14.9 3 9.1 3 6.4 5 4.4 7.5 4.4c1.9 0 3.6 1.1 4.5 2.7.9-1.6 2.6-2.7 4.5-2.7 2.5 0 4.5 2 4.5 4.7 0 5.8-8.4 11.1-9 11.5Z", Za = "M7.2 18.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Zm9.2 0a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6ZM6.1 5.3l.5 2.1h11.7l-1.3 5.4a1.4 1.4 0 0 1-1.4 1.1H8.2a1.4 1.4 0 0 1-1.4-1.1L4.9 4.9H2.8a1 1 0 1 1 0-2h2.9l.4 2.4Zm1 4.1.6 2.5h7.5l.6-2.5H7.1Z", Le = "http://www.w3.org/2000/svg", Xe = (t, e) => String(t || "").toLowerCase().replace(/[^a-z0-9_-]/g, "") || e, Ga = (t) => {
  if (typeof t != "string" || !t) return "";
  let e = [];
  for (let a of t.split(",")) {
    let r = a.trim().match(/^(\S+)\s+(\d{1,4})w$/);
    if (!r) return "";
    let i = rt(r[1], false);
    if (!i) return "";
    e.push(i + " " + Number(r[2]) + "w");
  }
  return e.length > 1 ? e.join(", ") : "";
}, vt = () => n.DelicatBadgeIcons || {};
function Ut(t, e) {
  if (t.textContent = "", !e || !e.t && !e.ic) return;
  let a = vt()[String(e.ic || "")] || (typeof e.sv == "string" ? e.sv : "");
  if (e.a && (t.title = String(e.a), t.setAttribute("role", "img"), t.setAttribute("aria-label", String(e.a))), a) {
    let r = l.createElementNS(Le, "svg");
    for (let [i, o] of Object.entries({ class: "delicat-badge__icon", viewBox: "0 0 24 24", width: "14", height: "14", fill: "none", stroke: "currentColor", "stroke-width": "1.75", "stroke-linecap": "round", "stroke-linejoin": "round", "aria-hidden": "true", focusable: "false" })) r.setAttribute(i, o);
    r.innerHTML = a, t.appendChild(r);
  }
  if (e.t) {
    let r = l.createElement("span");
    r.className = "delicat-badge__text", r.textContent = String(e.t), t.appendChild(r);
  }
}
function Ja(t, e) {
  let a = n.DelicaBuilderV9 && n.DelicaBuilderV9.heart || {}, r = typeof a.path == "string" && /^[MmCcLlHhVvSsZz0-9.\s-]+$/.test(a.path) ? a.path : Qa, i = l.createElement("button");
  i.type = "button", i.className = "delicat-like delicat-product-card__like", i.dataset.delicatLike = "", i.dataset.productId = String(t), i.setAttribute("aria-pressed", e ? "true" : "false"), i.setAttribute("aria-label", String(e ? a.removeLabel || "Retirer des favoris" : a.addLabel || "Ajouter aux favoris"));
  let o = l.createElement("span");
  o.className = "delicat-like__chip", o.setAttribute("aria-hidden", "true");
  for (let s of ["delicat-like__outline", "delicat-like__fill"]) {
    let u = l.createElementNS(Le, "svg");
    u.setAttribute("class", s), u.setAttribute("viewBox", "0 0 24 24"), u.setAttribute("width", "18"), u.setAttribute("height", "18"), u.setAttribute("focusable", "false");
    let v = l.createElementNS(Le, "path");
    v.setAttribute("d", r), u.appendChild(v), o.appendChild(u);
  }
  return i.appendChild(o), i;
}
function er(t) {
  let e = l.createElementNS(Le, "svg");
  e.setAttribute("class", "delicat-product-card__cta-svg"), e.setAttribute("viewBox", "0 0 24 24"), e.setAttribute("aria-hidden", "true"), e.setAttribute("focusable", "false");
  let a = l.createElementNS(Le, "path");
  a.setAttribute("fill", "currentColor"), a.setAttribute("d", Za), e.appendChild(a), t.appendChild(e);
}
function tr(t, e, a) {
  if (!e || !e.p || !e.p.n) return;
  let r = l.createElement("span");
  r.className = e.sub ? "delicat-product-card__poster-price" : "delicat-product-card__price";
  let i = l.createElement("span");
  i.textContent = a, r.appendChild(i);
  let o = l.createElement("strong"), s = l.createElement("span");
  s.className = "delicat-price-symbol", s.textContent = String(e.p.s || "");
  let u = l.createElement("span");
  u.className = "delicat-price-number", u.textContent = String(e.p.n || ""), o.append(s, u), r.appendChild(o), t.appendChild(r);
}
function Qt(t, e) {
  if (!e || !Number.isInteger(Number(e.id))) return null;
  let a = Number(e.id), r = rt(e.u, true);
  if (!r) return null;
  let i = l.createElement("article");
  i.className = "delicat-product-card", i.setAttribute("role", "listitem"), i.dataset.delicatCard = "", i.dataset.productId = String(a), i.dataset.delicatCardIndex = String(Number(e.i || 0)), i.dataset.delicatCardStyle = String(t.dataset.delicatStyle || "delicat_jeux");
  let o = l.createElement("div");
  o.className = "delicat-product-card__media-wrap";
  let s = l.createElement("a");
  if (s.className = "delicat-product-card__media", s.href = r, s.dataset.delicatPrefetch = "", s.dataset.delicatProductLink = "", e.b && e.b.t && (vt()[String(e.b.ic || "")] || e.b.sv)) {
    let p = l.createElement("span");
    p.className = `delicat-badge delicat-badge--topic delicat-badge--${Xe(e.b.o, "topic")} delicat-badge--${Xe(e.b.k, "topic")}`, Ut(p, e.b), s.appendChild(p);
  }
  {
    let p = l.createElement("span"), b = String(Be(e.n) || "").trim();
    p.className = "delicat-product-card__ph", p.setAttribute("aria-hidden", "true"), p.textContent = b ? Array.from(b)[0] : "", s.appendChild(p);
  }
  if (e.im && e.im.u && !/woocommerce-placeholder/i.test(String(e.im.u))) {
    let p = rt(e.im.u, false);
    if (p) {
      let b = l.createElement("img");
      b.className = "delicat-product-card__image", b.alt = Be(e.n), b.loading = "lazy", b.decoding = "async", b.fetchPriority = "low", b.dataset.delicatCardImage = "1", Number(e.im.w) > 0 && (b.width = Number(e.im.w)), Number(e.im.h) > 0 && (b.height = Number(e.im.h));
      let w = Ga(e.im.ss);
      w && (b.sizes = Ua, b.srcset = w), b.src = p, s.appendChild(b);
    }
  }
  if (e.st && e.st.t && (vt()[String(e.st.ic || "")] || e.st.sv)) {
    let p = l.createElement("span");
    p.className = `delicat-badge delicat-badge--status delicat-badge--${Xe(e.st.o, "status")} delicat-badge--${Xe(e.st.k, "status")}`, Ut(p, e.st), s.appendChild(p);
  }
  o.appendChild(s), Number(e.he) === 1 && o.appendChild(Ja(a, Number(e.hf) === 1)), i.appendChild(o);
  let u = l.createElement("div");
  u.className = "delicat-product-card__body";
  let v = t.dataset.delicatManagedHomepage === "1", h = l.createElement(v ? "span" : "a");
  if (h.className = v ? "delicat-product-card__poster-title" : "delicat-product-card__name", v || (h.href = r, h.dataset.delicatPrefetch = "", h.dataset.delicatProductLink = ""), h.textContent = Be(e.n), (v ? s : u).appendChild(h), tr(u, e, String(t.dataset.delicatStartingLabel || "\xC0 partir de")), Number(e.cta) === 1) {
    let p = l.createElement("a");
    p.className = "delicat-product-card__cta", p.href = r, p.dataset.delicatPrefetch = "", p.dataset.delicatProductLink = "", p.dataset.delicatInstantProduct = "", er(p);
    let b = l.createElement("span");
    b.textContent = String(t.dataset.delicatBuyLabel || "Acheter"), v || p.appendChild(b), p.setAttribute("aria-label", "Voir les options de " + Be(e.n)), u.appendChild(p);
  }
  return i.appendChild(u), i;
}
var Zt = /* @__PURE__ */ new WeakMap();
function xe(t) {
  let e = Zt.get(t);
  return e || (e = { items: null, cursor: 0, metrics: null, dots: [], building: false, done: false }, Zt.set(t, e), e);
}
function Gt(t, e) {
  if (e.items) return e.items;
  let a = t.querySelector("script[data-delicat-carousel-deferred-json]"), r = [];
  if (a) {
    try {
      let i = JSON.parse(a.textContent || "[]");
      Array.isArray(i) && (r = i);
    } catch {
    }
    a.remove();
  }
  return e.items = r, r;
}
function Jt(t, e) {
  let a = t.querySelector("template[data-delicat-carousel-deferred]");
  if (a && a.content && a.content.children.length) return true;
  let r = Gt(t, e);
  return e.cursor < r.length;
}
function ea(t, e, a) {
  let r = t.querySelector("[data-delicat-track]");
  if (!r) return 0;
  let i = l.createDocumentFragment(), o = 0, s = Gt(t, e);
  for (; o < a && e.cursor < s.length; ) {
    let u = Qt(t, s[e.cursor++]);
    u && (i.appendChild(u), o++);
  }
  if (o < a) {
    let u = t.querySelector("template[data-delicat-carousel-deferred]");
    if (u && u.content) {
      for (; o < a && u.content.firstElementChild; ) i.appendChild(u.content.firstElementChild), o++;
      u.content.children.length || u.remove();
    }
  }
  return o ? (r.appendChild(i), e.metrics = null, ae("delicat:carousel-materialized", { carousel: t }, t), o) : 0;
}
function Ae(t, e) {
  if (e.building || e.done) return;
  if (!Jt(t, e)) {
    e.done = true;
    return;
  }
  e.building = true;
  let a = z.lowPower ? 3 : 6, r = () => {
    if (!t.isConnected) {
      e.building = false;
      return;
    }
    ea(t, e, a), Jt(t, e) ? U(r) : (e.building = false, e.done = true);
  };
  U(r);
}
function Ye(t, e, a) {
  if (e.metrics) return e.metrics;
  let r = a.querySelector("[data-delicat-card]");
  if (!r) return { cardStep: Math.max(1, a.clientWidth), visible: 1, total: 1, mode: "pages" };
  let i = getComputedStyle(a), o = parseFloat(i.columnGap || i.gap || "0") || 0, s = Math.max(1, r.offsetWidth + o), u = Math.max(1, Math.floor((a.clientWidth + o) / s)), v = Math.max(1, Number(t.dataset.delicatTotal || a.querySelectorAll("[data-delicat-card]").length || 1)), h = t.dataset.delicatPaginationMode === "cards" ? "cards" : "pages";
  return e.metrics = { cardStep: s, visible: u, total: v, mode: h }, e.metrics;
}
var Ke = null, yt = /* @__PURE__ */ new WeakMap();
function ar(t, e) {
  if (t.dataset.delicatReady === "1") return;
  t.dataset.delicatReady = "1";
  let a = t.querySelector("[data-delicat-track]");
  if (!a) return;
  let r = xe(t), i = t.querySelector("[data-delicat-prev]"), o = t.querySelector("[data-delicat-next]"), s = t.querySelector("[data-delicat-pagination]"), u = z.reducedMotion ? "auto" : "smooth", v = () => {
    t.dataset.delicatActive !== "1" && (t.dataset.delicatActive = "1", t.classList.add("is-active"), Ae(t, r));
  }, h = () => {
    let g = Ye(t, r, a), M = a.scrollLeft >= a.scrollWidth - a.clientWidth - 2;
    if (g.mode === "cards") {
      let S = Math.max(0, g.total - g.visible);
      return M ? S : Math.max(0, Math.min(S, Math.round(a.scrollLeft / g.cardStep)));
    }
    return M && r.dots.length ? r.dots.length - 1 : Math.max(0, Math.round(a.scrollLeft / Math.max(1, a.clientWidth)));
  }, p = () => {
    let g = Ye(t, r, a);
    return g.mode === "cards" ? Math.max(1, g.total - g.visible + 1) : Math.max(1, Math.ceil(Math.max(1, g.cardStep * g.total) / Math.max(1, a.clientWidth)));
  }, b = () => {
    if (!r.dots.length) return;
    let g = Math.max(0, Math.min(r.dots.length - 1, h()));
    r.dots.forEach((M, S) => {
      let W = S === g;
      M.classList.toggle("is-active", W), M.setAttribute("aria-pressed", W ? "true" : "false");
    });
  }, w = () => {
    if (!s) return;
    let g = p();
    if (r.dots.length === g) {
      b();
      return;
    }
    s.replaceChildren(), r.dots = Array.from({ length: g }, (M, S) => {
      let W = l.createElement("button");
      return W.type = "button", W.className = "delicat-carousel__dot", W.dataset.delicatDot = String(S), W.setAttribute("aria-label", `Aller \xE0 la diapositive ${S + 1}`), c(W, "click", () => {
        v(), U(() => {
          let D = Ye(t, r, a);
          a.scrollTo({ left: D.mode === "cards" ? D.cardStep * S : a.clientWidth * S, behavior: u });
        });
      }, { signal: e }), s.appendChild(W), W;
    }), b();
  }, y = (g) => {
    let M = Ye(t, r, a);
    return g * (M.mode === "cards" ? M.cardStep : Math.max(180, Math.round(a.clientWidth * 0.82)));
  }, L = () => {
    if (!i && !o) return;
    let g = !r.done && !!(t.querySelector("template[data-delicat-carousel-deferred],script[data-delicat-carousel-deferred-json]") || r.items && r.cursor < r.items.length);
    i && (i.disabled = a.scrollLeft <= 2), o && (o.disabled = !g && a.scrollLeft >= a.scrollWidth - a.clientWidth - 2);
  };
  i && c(i, "click", () => {
    v(), a.scrollBy({ left: y(-1), behavior: u });
  }, { signal: e }), o && c(o, "click", () => {
    v(), a.scrollBy({ left: y(1), behavior: u });
  }, { signal: e });
  let P = false;
  c(a, "scroll", () => {
    P || (P = true, v(), U(() => {
      P = false, b(), L();
    }));
  }, { passive: true, signal: e }), c(t, "delicat:carousel-materialized", () => U(L), { signal: e });
  let C = (g) => {
    if (g && g.target && g.target.closest && g.target.closest("a[href],button,input,select,textarea,label")) {
      v();
      return;
    }
    v();
  };
  c(a, "PointerEvent" in n ? "pointerdown" : "touchstart", C, { passive: true, signal: e }), c(a, "focusin", C, { signal: e }), c(a, "keydown", (g) => {
    g.key === "ArrowRight" ? (g.preventDefault(), o ? o.click() : a.scrollBy({ left: y(1), behavior: u })) : g.key === "ArrowLeft" && (g.preventDefault(), i ? i.click() : a.scrollBy({ left: y(-1), behavior: u }));
  }, { signal: e }), w(), L();
  let E = () => {
    r.metrics = null, U(w), U(L);
  };
  if ("ResizeObserver" in n ? (Ke || (Ke = new ResizeObserver((g) => {
    for (let M of g) {
      let S = yt.get(M.target);
      S && S();
    }
  })), yt.set(a, E), Ke.observe(a), e.addEventListener("abort", () => {
    Ke.unobserve(a), yt.delete(a);
  }, { once: true })) : c(n, "resize", E, { passive: true, signal: e }), "IntersectionObserver" in n) {
    let g = new IntersectionObserver((M) => {
      for (let S of M) S.isIntersecting && (v(), g.disconnect());
    }, { rootMargin: z.lowPower ? "160px 0px" : "420px 0px", threshold: 0.01 });
    g.observe(t), e.addEventListener("abort", () => g.disconnect(), { once: true });
  } else v();
}
var wt = { init(t = l, e) {
  for (let a of H("[data-delicat-carousel]", t)) ar(a, e || new AbortController().signal);
}, materialize(t) {
  let e = xe(t);
  return ea(t, e, z.lowPower ? 3 : 6) > 0;
}, prefill(t) {
  Ae(t, xe(t));
}, complete(t) {
  Ae(t, xe(t));
}, buildCard: Qt };
function rr({ root: t, signal: e }) {
  n.DelicaBuilderV9Carousel = wt, wt.init(t, e), c(l, "visibilitychange", () => {
    if (!l.hidden) for (let a of H('[data-delicat-carousel][data-delicat-active="1"]', t)) Ae(a, xe(a));
  }, { signal: e }), c(n, "pageshow", () => {
    for (let a of H('[data-delicat-carousel][data-delicat-active="1"]', t)) Ae(a, xe(a));
  }, { passive: true, signal: e });
}
var Ce = "[data-delicat-like]", xt = 60, ir = 280;
function lr({ root: t, signal: e, config: a }) {
  let r = n.DelicaBuilderV9 && n.DelicaBuilderV9.heart || a.heart || {};
  if (!r.enabled) return;
  let i = /^[a-z0-9_]+$/i.test(String(r.cookie || "")) ? String(r.cookie) : "dbv9_fav", o = typeof Element != "undefined" && typeof Element.prototype.animate == "function", s = { add: String(r.addLabel || "Ajouter aux favoris"), remove: String(r.removeLabel || "Retirer des favoris") }, u = () => !o || z.reducedMotion ? 0 : N.classList.contains("delicat-low-power") ? 1 : 2, v = () => {
    if (!r.guest) return /* @__PURE__ */ new Set();
    let f = $e.read(i);
    return new Set((f ? f.split(",") : []).map(Number).filter((_) => Number.isInteger(_) && _ > 0).slice(-xt));
  }, h = v(), p = () => $e.write(i, [...h].slice(-xt).join(","), 31536e3), b = /* @__PURE__ */ new Map(), w = /* @__PURE__ */ new Map(), y = /* @__PURE__ */ new Set(), L = /* @__PURE__ */ new Map(), P = /* @__PURE__ */ new WeakMap(), C = (f) => f.getAttribute("aria-pressed") === "true", E = (f, _) => {
    f.setAttribute("aria-pressed", _ ? "true" : "false"), f.setAttribute("aria-label", _ ? s.remove : s.add);
  }, g = (f) => H(`${Ce}[data-product-id="${f}"]`), M = (f, _) => ae("delicat:favorite-change", { productId: f, favorite: _ }), S = (f, _) => {
    if (!_) return;
    let $ = P.get(f) || [];
    $.push(_), P.set(f, $);
  }, W = (f) => {
    for (let _ of P.get(f) || []) try {
      _.cancel();
    } catch {
    }
    P.delete(f);
  }, D = (f, _, $, j, F = 0) => {
    let Z = 0.008333333333333333, ee = [], se = f, de = F;
    for (let oe = 0; oe < 180 && (ee.push(se), de += (-$ * (se - _) - j * de) * Z, se += de * Z, !(oe > 10 && Math.abs(se - _) < 2e-3 && Math.abs(de) < 0.05)); oe += 1) ;
    return ee.push(_), { keyframes: ee.filter((oe, ke) => ke % 2 === 0 || ke === ee.length - 1).map((oe) => ({ transform: `scale(${oe.toFixed(4)})` })), duration: Math.round(ee.length * Z * 1e3) };
  }, B = (f) => ({ chip: f.querySelector(".delicat-like__chip") || f, fill: f.querySelector(".delicat-like__fill") }), I = (f, _) => {
    let { chip: $, fill: j } = B(f), F = $.getBoundingClientRect();
    if (!F.width || !l.body) return;
    let Z = l.createElement("div");
    Z.className = "delicat-like-burst", Z.setAttribute("aria-hidden", "true"), Z.style.left = `${F.left + F.width / 2}px`, Z.style.top = `${F.top + F.height / 2}px`, Z.style.color = j ? getComputedStyle(j).color : "#ff4d91";
    let ee = F.width / 2, se = [];
    if (_ === 2) {
      let te = l.createElement("span");
      te.className = "delicat-like-burst__ring", te.style.width = `${F.width}px`, te.style.height = `${F.height}px`, te.style.margin = `${-ee}px 0 0 ${-ee}px`, Z.appendChild(te), se.push(te.animate([{ transform: "scale(1)", opacity: 0.75 }, { transform: "scale(1.8)", opacity: 0 }], { duration: 440, easing: "cubic-bezier(.2,.75,.3,1)" }));
    }
    let de = _ === 2 ? 7 : 5, oe = Math.random() * Math.PI * 2;
    for (let te of _ === 2 ? [0, 1] : [0]) for (let Ze = 0; Ze < de; Ze += 1) {
      let Lt = oe + Math.PI * 2 * (Ze + te * 0.5) / de + (Math.random() - 0.5) * 0.22, Ne = te === 0 ? 5 : 3.5, Te = ee + 1, Ie = ee + (te === 0 ? 16 : 10) + Math.random() * 4, fe = l.createElement("span");
      fe.className = "delicat-like-burst__dot", fe.style.width = `${Ne}px`, fe.style.height = `${Ne}px`, fe.style.margin = `${-Ne / 2}px 0 0 ${-Ne / 2}px`, Z.appendChild(fe);
      let De = Math.cos(Lt), Pe = Math.sin(Lt), At = te === 0 ? 1 : 0.8;
      se.push(fe.animate([{ transform: `translate(${De * Te}px, ${Pe * Te}px) scale(.4)`, opacity: 0 }, { transform: `translate(${De * (Te + 4)}px, ${Pe * (Te + 4)}px) scale(1)`, opacity: At, offset: 0.18 }, { transform: `translate(${De * (Ie - 3)}px, ${Pe * (Ie - 3)}px) scale(.75)`, opacity: At, offset: 0.62 }, { transform: `translate(${De * Ie}px, ${Pe * Ie}px) scale(.2)`, opacity: 0 }], { duration: 560 + Math.random() * 100, delay: te === 0 ? 50 : 95, easing: "cubic-bezier(.22,.7,.35,1)", fill: "backwards" }));
    }
    l.body.appendChild(Z);
    let ke = false, Qe = () => {
      ke || (ke = true, Z.remove());
    };
    Promise.all(se.map((te) => te.finished)).then(Qe, Qe), n.setTimeout(Qe, 1200);
  }, R = (f, _) => {
    let { chip: $, fill: j } = B(f);
    if (W(f), !o || !j) return;
    if (_ === 0) {
      S(f, j.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 180, easing: "linear" }));
      return;
    }
    let F = D(0, 1, 340, 14);
    S(f, j.animate(F.keyframes, { duration: F.duration, easing: "linear" })), S(f, j.animate([{ opacity: 0 }, { opacity: 1, offset: 0.12 }, { opacity: 1 }], { duration: F.duration, easing: "linear" }));
    let Z = D(0.86, 1, 520, 17);
    S(f, $.animate(Z.keyframes, { duration: Z.duration, easing: "linear" })), I(f, _);
  }, V = (f, _) => {
    let { chip: $, fill: j } = B(f);
    if (W(f), !o || !j) return;
    if (_ === 0) {
      S(f, j.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: "linear" }));
      return;
    }
    S(f, j.animate([{ transform: "scale(1)", opacity: 1 }, { transform: "scale(1.1)", opacity: 1, offset: 0.22 }, { transform: "scale(.2)", opacity: 0 }], { duration: 260, easing: "cubic-bezier(.45,0,.7,.4)" }));
    let F = D(0.9, 1, 460, 20);
    S(f, $.animate(F.keyframes, { duration: F.duration, easing: "linear" }));
  }, d = (f) => {
    if (u() === 0) return;
    let { chip: _ } = B(f);
    W(_), S(_, _.animate([{ transform: "translateX(0) rotate(0)" }, { transform: "translateX(-4px) rotate(-7deg)" }, { transform: "translateX(4px) rotate(6deg)" }, { transform: "translateX(-3px) rotate(-4deg)" }, { transform: "translateX(2px) rotate(2deg)" }, { transform: "translateX(0) rotate(0)" }], { duration: 440, easing: "ease-out" }));
  }, m = () => {
    try {
      u() > 0 && typeof navigator.vibrate == "function" && navigator.vibrate(10);
    } catch {
    }
  }, k = (f, _, $ = null) => {
    for (let j of g(f)) j === $ || C(j) === _ || (W(j), E(j, _));
  }, q = async (f, _) => {
    try {
      let $ = await qe(String(r.action || "delicat_builder_v9_favorite"), { nonce: String(r.nonce), product_id: String(f), state: _ ? "1" : "0" }, { url: r.ajaxUrl, timeout: 12e3 });
      return !!($ && $.favorite);
    } catch ($) {
      let j = new Error("favorite_failed");
      throw j.status = $ && $.status, j;
    }
  }, T = (f) => {
    if (!ae("delicat:favorite-signin", { productId: f }, l, true)) return;
    let _ = l.querySelector('[data-delicat-login], a[href$="#delicat-login"]');
    if (_) {
      _.click();
      return;
    }
    location.hash = "delicat-login";
  }, Q = (f, _, $) => {
    let j = u();
    for (let F of g(f)) {
      if (C(F) !== _) if (E(F, _), _) {
        let { fill: Z } = B(F);
        W(F), o && Z && S(F, Z.animate([{ opacity: 0, transform: "scale(.6)" }, { opacity: 1, transform: "scale(1)" }], { duration: 220, easing: "ease-out" }));
      } else V(F, j);
      d(F);
    }
    M(f, _), $ === 401 && T(f);
  }, K = (f, _ = ir) => {
    n.clearTimeout(L.get(f)), L.set(f, n.setTimeout(() => {
      L.delete(f), x(f);
    }, _));
  }, x = async (f) => {
    if (y.has(f)) return;
    let _ = w.get(f);
    if (!(typeof _ != "boolean" || _ === b.get(f))) {
      y.add(f);
      try {
        b.set(f, await q(f, _));
      } catch ($) {
        let j = b.get(f) === true;
        w.set(f, j), Q(f, j, Number($ && $.status || 0));
      } finally {
        y.delete(f), w.get(f) !== b.get(f) && K(f, 0);
      }
    }
  }, A = () => {
    for (let f of H(`${Ce}.is-pressed`)) f.classList.remove("is-pressed");
  };
  c(l, "pointerdown", (f) => {
    let _ = f.target instanceof Element ? f.target.closest(Ce) : null;
    _ && (f.button === 0 || f.pointerType !== "mouse") && _.classList.add("is-pressed");
  }, { passive: true, signal: e });
  for (let f of ["pointerup", "pointercancel", "dragstart"]) c(l, f, A, { passive: true, signal: e });
  c(l, "scroll", A, { passive: true, capture: true, signal: e }), c(n, "blur", A, { signal: e }), c(l, "click", (f) => {
    let _ = f.target instanceof Element ? f.target.closest(Ce) : null;
    if (!_) return;
    f.preventDefault(), f.stopPropagation(), A();
    let $ = Number(_.dataset.productId || 0);
    if (!Number.isInteger($) || $ <= 0) return;
    if (!r.loggedIn && !r.guest || r.loggedIn && (!r.ajaxUrl || !r.nonce)) {
      d(_), T($);
      return;
    }
    let j = C(_), F = !j, Z = u();
    if (E(_, F), k($, F, _), F ? (R(_, Z), m()) : V(_, Z), M($, F), !r.loggedIn) {
      for (h.delete($), F && h.add($); h.size > xt; ) {
        let ee = h.values().next().value;
        h.delete(ee), k(ee, false), M(ee, false);
      }
      p();
      return;
    }
    b.has($) || b.set($, j), w.set($, F), K($);
  }, { capture: true, signal: e });
  let X = (f = l) => {
    for (let _ of H(Ce, f && f.querySelectorAll ? f : l)) {
      let $ = Number(_.dataset.productId || 0);
      if ($ <= 0) continue;
      let j = null;
      r.loggedIn ? w.has($) && (j = w.get($)) : r.guest && (j = h.has($)), j !== null && C(_) !== j && E(_, j);
    }
  }, O = () => {
    r.loggedIn || !r.guest || (h = v(), X(l));
  };
  c(l, "delicat:carousel-materialized", (f) => X(f.target instanceof Element ? f.target : l), { signal: e }), c(l, "visibilitychange", () => {
    l.hidden || O();
  }, { signal: e }), c(n, "pageshow", (f) => {
    f.persisted && O();
  }, { signal: e }), n.DelicaBuilderV9Heart = { hydrate: X, hydrateGuests: X }, X(t);
}
function nr(t, e, a) {
  if (!(t instanceof Element) || t.dataset.delicatSearchReady === "1") return;
  let r = t.querySelector("[data-delicat-hero-search-input]"), i = t.querySelector("[data-delicat-hero-search-results]"), o = t.querySelector(".delicat-builder-hero-search__data");
  if (!r || !i || !o) return;
  let s = [];
  try {
    let C = JSON.parse(o.textContent || "[]");
    s = Array.isArray(C) ? C : [];
  } catch {
    s = [];
  }
  let u = Math.max(1, Math.min(4, Number(t.dataset.minChars || 2))), v = Math.max(3, Math.min(8, Number(t.dataset.resultLimit || 5))), h = -1, p = [], b = 0, w = () => {
    n.clearTimeout(b), i.hidden = true, i.replaceChildren(), h = -1, p = [], r.removeAttribute("aria-activedescendant"), r.setAttribute("aria-expanded", "false");
  }, y = (C) => {
    let E = H('[role="option"]', i);
    if (!E.length) return;
    h = Math.max(0, Math.min(E.length - 1, C)), E.forEach((M, S) => {
      M.classList.toggle("is-active", S === h), M.setAttribute("aria-selected", S === h ? "true" : "false");
    });
    let g = E[h];
    g && g.id && r.setAttribute("aria-activedescendant", g.id), g && g.scrollIntoView({ block: "nearest" });
  }, L = (C, E) => {
    let g = Et(C && C.name);
    return g ? g === E ? 0 : g.startsWith(E) ? 1 : g.split(/\s+/).some((M) => M.startsWith(E)) ? 2 : g.includes(E) ? 3 : 99 : 99;
  }, P = (C) => {
    let E = Et(C);
    if (E.length < u || !s.length) {
      w();
      return;
    }
    if (p = s.map((g, M) => ({ item: g, position: M, score: L(g, E) })).filter((g) => g.score < 99).sort((g, M) => g.score - M.score || g.position - M.position).slice(0, v).map((g) => g.item), i.replaceChildren(), h = -1, !p.length) {
      let g = l.createElement("div");
      g.className = "delicat-builder-hero-search__empty", g.textContent = "Aucun r\xE9sultat rapide \u2014 appuyez sur Entr\xE9e pour rechercher toute la boutique.", i.append(g), i.hidden = false, r.setAttribute("aria-expanded", "true");
      return;
    }
    p.forEach((g, M) => {
      let S = l.createElement("a");
      if (S.className = "delicat-builder-hero-search__result", S.href = String(g.url || "#"), S.id = `delicat-hero-search-result-${M}`, S.setAttribute("role", "option"), S.setAttribute("aria-selected", "false"), S.dataset.delicatPrefetch = "", S.dataset.delicatProductLink = "", g.image) {
        let I = l.createElement("img");
        I.src = String(g.image), I.alt = "", I.loading = "lazy", I.decoding = "async", I.width = 48, I.height = 48, S.append(I);
      } else {
        let I = l.createElement("span");
        I.className = "delicat-builder-hero-search__result-placeholder", I.textContent = "\u25EB", S.append(I);
      }
      let W = l.createElement("span");
      W.className = "delicat-builder-hero-search__result-copy";
      let D = l.createElement("strong");
      if (D.textContent = String(g.name || ""), W.append(D), g.price) {
        let I = l.createElement("small");
        I.textContent = String(g.price), W.append(I);
      }
      S.append(W);
      let B = l.createElement("span");
      B.className = "delicat-builder-hero-search__result-arrow", B.textContent = "\u2192", S.append(B), i.append(S);
    }), i.hidden = false, r.setAttribute("aria-expanded", "true");
  };
  r.setAttribute("role", "combobox"), r.setAttribute("aria-autocomplete", "list"), r.setAttribute("aria-expanded", "false"), i.id = i.id || `delicat-hero-search-results-${Math.random().toString(36).slice(2, 8)}`, r.setAttribute("aria-controls", i.id), c(r, "input", () => {
    n.clearTimeout(b), w(), b = n.setTimeout(() => P(r.value), 45);
  }, { passive: true, signal: e }), c(r, "keydown", (C) => {
    if (C.key === "Escape") {
      w();
      return;
    }
    i.hidden || !p.length || (C.key === "ArrowDown" ? (C.preventDefault(), y(h < 0 ? 0 : h + 1)) : C.key === "ArrowUp" ? (C.preventDefault(), y(h < 0 ? p.length - 1 : h - 1)) : C.key === "Enter" && h >= 0 && p[h] && p[h].url && (C.preventDefault(), a.nav ? a.nav.navigate(String(p[h].url)) : location.assign(String(p[h].url))));
  }, { signal: e }), c(t, "focusout", () => {
    n.setTimeout(() => {
      t.contains(l.activeElement) || w();
    }, 90);
  }, { signal: e }), t.dataset.delicatSearchReady = "1";
}
function sr({ root: t, signal: e, engine: a }) {
  for (let r of H("[data-delicat-hero-search]", t)) nr(r, e, a);
}
var or = /* @__PURE__ */ new Set(["fade-up", "fade", "scale", "slide-left", "slide-right", "soft-zoom"]), Se = {}, ta = (t) => {
  let e = /^\s*(-?\d*\.?\d+)(ms|s|px)?\s*$/.exec(String(t));
  if (!e) return null;
  let a = parseFloat(e[1]), r = e[2] || "";
  return r === "s" && (a *= 1e3, r = "ms"), { n: a, unit: r };
}, dr = (t, e) => {
  let a = ta(t), r = ta(e);
  return !a || !r ? String(t).trim() === String(e).trim() : a.unit === r.unit && Math.abs(a.n - r.n) < 15e-4;
}, Ue = (t, e) => {
  if (Se[t] === void 0) try {
    Se[t] = n.getComputedStyle(N).getPropertyValue(t);
  } catch {
    Se[t] = "";
  }
  if (dr(Se[t], e)) {
    Se[t] = e;
    return;
  }
  Se[t] = e, N.style.setProperty(t, e);
};
function cr({ root: t, signal: e, config: a }) {
  let r = Object.assign({ enabled: true, effect: "fade-up", duration: 440, intensity: 45, stagger: 55, baseDelay: 0, threshold: 0.04, desktop: true, tablet: true, mobile: true, mobileIntensity: 70 }, n.DelicaBuilderV9MotionConfig || a.motion || {}), i = null, o = 0, s = 0, u = 0, v = "", h = () => Math.max(320, Number(n.innerWidth || N.clientWidth || 1024)), p = () => {
    let E = h();
    return E <= 640 ? "mobile" : E <= 960 ? "tablet" : "desktop";
  }, b = (E) => E === "mobile" ? !!r.mobile : E === "tablet" ? !!r.tablet : !!r.desktop, w = (E) => {
    let g = E === "mobile" ? Math.max(0.2, Math.min(1, Number(r.mobileIntensity || 70) / 100)) : 1, M = Math.max(180, Math.min(900, Number(r.duration || 440))), S = Math.max(0, Math.min(150, Number(r.stagger || 55))), W = or.has(r.effect) ? r.effect : "fade-up", D = "full";
    return (z.lowPower || z.saveData) && (g *= 0.72, M = Math.min(M, 380), S = Math.min(S, 28), D = "adaptive"), z.veryLowPower && (g *= 0.72, M = Math.min(M, 300), S = Math.min(S, 16), W = "fade", D = "ultra-light"), { factor: g, duration: M, stagger: S, effect: W, label: D };
  }, y = (E, g = "") => {
    N.dataset.dbv9MotionStatus !== E && (N.dataset.dbv9MotionStatus = E), g ? N.dataset.dbv9MotionProfile !== g && (N.dataset.dbv9MotionProfile = g) : N.dataset.dbv9MotionProfile !== void 0 && delete N.dataset.dbv9MotionProfile;
  }, L = () => {
    o && n.clearTimeout(o), o = 0, i && i.disconnect(), i = null;
  }, P = (E = l) => {
    L();
    for (let g of H(".dbv9-reveal:not(.dbv9-reveal--in)", E)) g.style.transition = "none", g.style.removeProperty("--dbv9-reveal-delay"), g.classList.add("dbv9-reveal--in");
  }, C = (E = l) => {
    L(), u += 1;
    let g = u, M = p();
    if (v = M, !r.enabled) {
      y("disabled-setting");
      return;
    }
    if (!b(M)) {
      y("disabled-device");
      return;
    }
    if (z.reducedMotion) {
      y("reduced-motion"), P(E);
      return;
    }
    if (!("IntersectionObserver" in n)) {
      y("unsupported");
      return;
    }
    let S = w(M);
    y("active", S.label);
    let W = Math.max(0, Math.min(100, Number(r.intensity || 45))) * S.factor;
    Ue("--dbv9-motion-duration", S.duration + "ms"), Ue("--dbv9-motion-distance", Math.round(3 + W / 100 * 26) + "px"), Ue("--dbv9-motion-scale-in", String(Math.max(0.95, 1 - (6e-3 + W / 100 * 0.028)))), Ue("--dbv9-motion-scale-out", String(Math.min(1.045, 1 + 6e-3 + W / 100 * 0.022)));
    let D = H(".delicat-page-layout[data-delicat-page-layout]", E);
    if (!D.length) {
      y("no-builder-layout", S.label);
      return;
    }
    let B = [];
    for (let R of D) for (let V of Array.from(R.children)) !V.classList || !V.classList.contains("delicat-section") || V.classList.contains("dbv9-reveal--in") || B.push(V);
    if (!B.length) return;
    let I = true;
    i = new IntersectionObserver((R) => {
      if (g === u) {
        if (I) {
          I = false;
          let V = Math.max(320, Number(n.innerHeight || N.clientHeight || 800));
          for (let d of R) {
            let m = d.boundingClientRect;
            if (m.top < V * 0.88 || m.height < 2) {
              i && i.unobserve(d.target);
              continue;
            }
            d.target.classList.add("dbv9-reveal", "dbv9-motion--" + S.effect);
          }
        }
        R.filter((V) => V.isIntersecting && V.target.classList.contains("dbv9-reveal") && !V.target.classList.contains("dbv9-reveal--in")).sort((V, d) => V.boundingClientRect.top - d.boundingClientRect.top).forEach((V, d) => {
          let m = V.target;
          m.style.setProperty("--dbv9-reveal-delay", Math.max(0, Math.min(300, Number(r.baseDelay || 0))) + Math.min(d, 4) * S.stagger + "ms"), m.classList.add("dbv9-reveal--in"), i && i.unobserve(m);
        });
      }
    }, { root: null, rootMargin: "0px 0px -3% 0px", threshold: Math.max(0.01, Math.min(0.3, Number(r.threshold || 0.04))) });
    for (let R of B) i.observe(R);
    o = n.setTimeout(() => P(E), 5500);
  };
  if (U(() => C(t)), c(n, "pageshow", () => U(() => C(t)), { passive: true, signal: e }), c(n, "resize", () => {
    s && n.clearTimeout(s), s = n.setTimeout(() => {
      p() !== v && (P(t), C(t));
    }, 180);
  }, { passive: true, signal: e }), z.reduceQuery && z.reduceQuery.addEventListener) {
    let E = () => {
      P(t), C(t);
    };
    z.reduceQuery.addEventListener("change", E), e.addEventListener("abort", () => z.reduceQuery.removeEventListener("change", E), { once: true });
  }
  e.addEventListener("abort", () => P(t), { once: true });
}
var aa = (t) => {
  if (!/^[A-Z]{3}$/.test(t || "")) return;
  let e = new URL(location.href);
  e.searchParams.set("dmc_currency", t), e.searchParams.delete("add-to-cart"), da(), ae("delicat:currency-changed", { currency: t }), location.assign(e.toString());
};
function ur({ root: t, signal: e }) {
  for (let a of H("[data-dbv9-currency]", t)) {
    if (a.dataset.ready === "1") continue;
    a.dataset.ready = "1";
    let r = a.querySelector("[data-dbv9-currency-select]");
    r && c(r, "change", (i) => aa(String(i.target.value || "").toUpperCase()), { signal: e }), c(a, "click", (i) => {
      let o = i.target instanceof Element ? i.target.closest("[data-dbv9-currency-value]") : null;
      o && aa(String(o.dataset.dbv9CurrencyValue || "").toUpperCase());
    }, { signal: e });
  }
}
var pr = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="20" height="20"><path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>', ra = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="20" height="20"><rect x="3.5" y="3.5" width="17" height="17" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8v8M8 12h8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>', St = { get() {
  try {
    return n.sessionStorage.getItem("dbv9-install-seen");
  } catch {
    return null;
  }
}, set() {
  try {
    n.sessionStorage.setItem("dbv9-install-seen", "1");
  } catch {
  }
} };
/* V10: iPhone launch screen. The page head carries the full apple-touch-startup-image set on the
   home page only (the installed app's start page). On any other page Safari gets the single link
   that matches this iPhone, so "Add to Home Screen" from any page keeps its launch screen while
   no other phone carries the 15 links. */
function splashLink(t) {
  try {
    if (!t || !t.u || l.querySelector('link[rel="apple-touch-startup-image"]')) return;
    let e = n.screen || {}, a = Math.round(n.devicePixelRatio || 1), r = Math.min(e.width || 0, e.height || 0), i = Math.max(e.width || 0, e.height || 0);
    if (!r || (" " + String(t.d || "") + " ").indexOf(" " + r + "x" + i + "x" + a + " ") === -1) return;
    let o = l.createElement("link");
    o.rel = "apple-touch-startup-image", o.media = "screen and (device-width: " + r + "px) and (device-height: " + i + "px) and (-webkit-device-pixel-ratio: " + a + ") and (orientation: portrait)", o.href = String(t.u) + r * a + "x" + i * a + ".png", l.head.appendChild(o);
  } catch {
  }
}
function mr({ signal: t, config: e }) {
  let a = Object.assign({}, n.DelicaPWARuntime || {}, e.pwa || {}), r = String(n.DelicatAndroidPage || e.androidPage || ""), i = String(a.serviceWorker || ""), o = String(a.scope || "/"), s = !!a.installUI, u = null, v = z.standalone;
  if (v && N.classList.add("delicat-standalone"), z.ios && N.classList.add("delicat-ios"), v) {
    try {
      l.cookie = "dbv9_app=1; path=/; max-age=31536000; SameSite=Lax" + (location.protocol === "https:" ? "; Secure" : "");
    } catch {
    }
    try {
      let L = new URL(location.href);
      L.searchParams.get("utm_source") === "pwa" && (L.searchParams.delete("utm_source"), history.replaceState(history.state, "", L.pathname + (L.search || "") + L.hash));
    } catch {
    }
    let y = 0;
    c(l, "visibilitychange", () => {
      if (l.hidden) {
        y = Date.now();
        return;
      }
      y && Date.now() - y > 9e4 && re.refresh("resume"), y = 0;
    }, { signal: t });
  }
  c(n, "beforeinstallprompt", (y) => {
    y.preventDefault(), u = y, N.classList.add("delicat-pwa-installable");
  }, { signal: t });
  let h = () => {
    let y = l.getElementById("dbv9-pwa-modal");
    return y || (y = l.createElement("div"), y.id = "dbv9-pwa-modal", y.className = "dbv9-pwa-modal", y.innerHTML = '<div class="dbv9-pwa-backdrop" data-dbv9-pwa-close></div><section class="dbv9-pwa-card" role="dialog" aria-modal="true" aria-label="Installer Delicat Store"><button type="button" class="dbv9-pwa-close" data-dbv9-pwa-close aria-label="Fermer">\xD7</button><h3 class="dbv9-pwa-title">Installer Delicat Store</h3><p class="dbv9-pwa-copy"></p><ol class="dbv9-pwa-steps" hidden></ol><button type="button" class="dbv9-pwa-action">Installer</button></section>', l.body.appendChild(y), c(y, "click", (L) => {
      L.target.closest("[data-dbv9-pwa-close]") && (y.classList.remove("is-open"), Ge("pwa"));
    }, { signal: t }), y);
  }, p = async () => {
    if (!s || v) return;
    let y = h(), L = y.querySelector(".dbv9-pwa-copy"), P = y.querySelector(".dbv9-pwa-steps"), C = y.querySelector(".dbv9-pwa-action");
    P.hidden = true, u ? (L.textContent = "Ajoutez Delicat Store \xE0 votre \xE9cran d\u2019accueil pour un acc\xE8s plus rapide.", C.hidden = false, C.textContent = "Installer", C.onclick = async () => {
      try {
        u.prompt(), await u.userChoice;
      } catch {
      }
      u = null, y.classList.remove("is-open"), Ge("pwa");
    }) : z.ios ? (L.textContent = "Sur iPhone, l\u2019application s\u2019installe depuis Safari en deux gestes :", P.innerHTML = '<li><span class="dbv9-pwa-step-icon">' + pr + '</span><span class="dbv9-pwa-step-text">Touchez <strong>Partager</strong> en bas de Safari.</span></li><li><span class="dbv9-pwa-step-icon">' + ra + '</span><span class="dbv9-pwa-step-text">Choisissez <strong>\xAB Sur l\u2019\xE9cran d\u2019accueil \xBB</strong>, puis <strong>Ajouter</strong>.</span></li>', P.hidden = false, C.hidden = true) : (L.textContent = "Ouvrez le menu de votre navigateur puis choisissez \xAB Installer l\u2019application \xBB ou \xAB Ajouter \xE0 l\u2019\xE9cran d\u2019accueil \xBB.", C.hidden = true), y.classList.add("is-open"), ua("pwa");
  }, b = (y) => !r || z.ios ? false : (y && (y.preventDefault(), y.stopImmediatePropagation()), location.assign(r), true);
  c(l, "click", (y) => {
    if (y.button !== 0 || y.ctrlKey || y.metaKey || y.shiftKey || y.altKey) return;
    let L = y.target instanceof Element ? y.target.closest('a[href="#install-app"],[data-dlx-install],[data-db-v9-pwa-open],[data-dbv9-pwa-open]') : null;
    L && (b(y) || L.matches('[data-db-v9-pwa-open],[data-dbv9-pwa-open],a[href="#install-app"]') && (y.preventDefault(), p()));
  }, { capture: true, signal: t }), c(l, "delicat:install-app", (y) => {
    b(y) || p();
  }, { capture: true, signal: t }), c(l, "keydown", (y) => {
    if (y.key === "Escape") {
      let L = l.getElementById("dbv9-pwa-modal");
      L && L.classList.contains("is-open") && (L.classList.remove("is-open"), Ge("pwa"));
    }
  }, { signal: t });
  let w = () => {
    if (!s || v || l.hidden || !z.ios || St.get() || (n.pageYOffset || 0) > 240 || l.getElementById("dbv9-install-bar") || l.querySelector(".dlx-drawer.is-open, .dbv9-pwa-modal.is-open") || /^https?:\/\/([^/]+\.)?(google|bing|yahoo|duckduckgo|yandex|ecosia|qwant|baidu)\.[a-z.]+(\/|$)/i.test(l.referrer || "")) return;
    let y = l.createElement("div");
    y.id = "dbv9-install-bar", y.className = "dbv9-install-bar", y.setAttribute("role", "status"), y.innerHTML = '<span class="dbv9-install-bar__icon">' + ra + '</span><span class="dbv9-install-bar__copy"><strong>Delicat en application</strong><small>Ajoutez-la \xE0 l\u2019\xE9cran d\u2019accueil.</small></span><button type="button" class="dbv9-install-bar__go" data-dbv9-pwa-open>Voir</button><button type="button" class="dbv9-install-bar__close" aria-label="Fermer">\xD7</button>', l.body.appendChild(y);
    let L = l.querySelector("[data-dsb8-header]"), P = () => {
      let S = L && L.isConnected ? L.getBoundingClientRect() : null;
      y.style.setProperty("--dbv9-install-top", Math.round(S && S.bottom > 0 ? S.bottom + 8 : 0) + "px");
    }, C = n.pageYOffset || 0, E = false, g = () => {
      E || (E = true, St.set(), y.classList.remove("is-in"), n.setTimeout(() => y.remove(), 200));
    };
    P(), c(n, "resize", P, { passive: true, signal: t }), c(n, "scroll", () => Math.abs((n.pageYOffset || 0) - C) > 48 ? g() : U(P), { passive: true, signal: t });
    let M = () => {
      St.set(), y.classList.remove("is-in"), n.setTimeout(() => y.remove(), 260);
    };
    c(y, "click", (S) => {
      S.target.closest(".dbv9-install-bar__close, [data-dbv9-pwa-open]") && M();
    }, { signal: t }), n.setTimeout(() => y.classList.add("is-in"), 30);
  };
  if (!v && z.ios && (splashLink(a.splash), le(() => n.setTimeout(w, 2500), 4e3)), n.DelicatPWA = { open: p }, r && !z.ios && (n.DelicatAndroidLinkBound = true), i && "serviceWorker" in navigator) {
    let y = () => navigator.serviceWorker.register(i, { scope: o }).catch(() => {
    });
    l.readyState === "complete" ? le(y, 3500) : n.addEventListener("load", () => le(y, 3500), { once: true });
  }
}
function hr({ root: t, signal: e, config: a }) {
  let r = n.delicatBuilderV9WalletLive || a.walletLive;
  if (!r || !r.url || !r.nonce) return;
  let i = () => H("[data-dsb-wallet]", l);
  if (!i().length) return;
  let o = parseInt(r.idle, 10) || 45e3, s = parseInt(r.watch, 10) || 5e3, u = 3e3, v = 0, h = false, p = 0, b = 0, w = false, y = false, L = 0, P = null;
  try {
    P = new BroadcastChannel("dsb-wallet"), P.onmessage = (D) => {
      D && D.data && M(D.data, false);
    };
  } catch {
  }
  let C = () => N.classList.contains("dsb-menu-open") || N.classList.contains("dlx-open"), E = () => !l.hidden && (C() || w || Date.now() < b), g = () => {
    n.clearTimeout(v), !y && E() && (v = n.setTimeout(W, Date.now() < b ? s : o));
  };
  function M(D, B) {
    let I = parseFloat(D.raw);
    if (!isNaN(I)) {
      for (let R of i()) {
        let V = R.querySelector(".dsb-wallet-amount");
        if (V && typeof D.html == "string" && D.html) {
          let d = it("<body>" + D.html);
          V.replaceChildren(...d ? Array.from(d.body.childNodes) : [l.createTextNode(String(D.html))]);
        }
        R.setAttribute("data-balance-raw", String(I));
      }
      for (let R of H("[data-dlx-wallet-balance],[data-delicat-wallet-balance]")) typeof D.text == "string" && D.text && (R.textContent = D.text);
      if (B && P) try {
        P.postMessage({ raw: I, html: D.html, text: D.text });
      } catch {
      }
    }
  }
  async function S(D) {
    let B = Date.now();
    if (!(y || h || !i().length || B < L || !D && B - p < u)) {
      p = B, h = true;
      try {
        let I = await qe("delicat_builder_v9_wallet_balance", { nonce: r.nonce }, { url: r.url, signal: e });
        I && M(I, true);
      } catch (I) {
        let R = I && I.status;
        R === 429 ? L = Date.now() + 12e4 : (R === 401 || R === 403) && (y = true);
      } finally {
        h = false;
      }
    }
  }
  function W() {
    S(false), g();
  }
  if ("IntersectionObserver" in n) {
    let D = new IntersectionObserver((B) => {
      for (let I of B) w = I.isIntersecting || w;
      w = i().some((I) => I.getBoundingClientRect().height > 0 && B.some((R) => R.target === I ? R.isIntersecting : false)) || B.some((I) => I.isIntersecting), w && S(true), g();
    }, { rootMargin: "80px" });
    for (let B of i()) D.observe(B);
    e.addEventListener("abort", () => D.disconnect(), { once: true });
  }
  c(l, "dsb:menu-opened", () => n.setTimeout(() => {
    S(true), g();
  }, 450), { signal: e }), c(l, "dlx:open", () => n.setTimeout(() => {
    S(true), g();
  }, 450), { signal: e }), c(l, "dsb:menu-closed", g, { signal: e }), c(l, "delicat:balance-changed", () => S(true), { signal: e }), c(l, "delicat:recharge-submitted", () => {
    b = Date.now() + 18e4, S(true), g();
  }, { signal: e }), c(l, "visibilitychange", () => {
    !l.hidden && E() && S(true), g();
  }, { signal: e }), e.addEventListener("abort", () => {
    if (n.clearTimeout(v), P) try {
      P.close();
    } catch {
    }
  }, { once: true }), E() && S(true), g();
}
var fr = "a,button,input,select,textarea,[tabindex]";
function gr({ root: t, signal: e }) {
  let a = H("[data-dbv9-marquee-clone]", t).filter((s) => s.dataset.dbv9LoopReady !== "1");
  if (!a.length) return;
  let r = !!(n.matchMedia && n.matchMedia("(max-width: 640px)").matches), i = (s) => {
    if (s.dataset.dbv9LoopReady === "1") return;
    s.dataset.dbv9LoopReady = "1";
    let u = s.parentElement;
    if (z.reducedMotion || r && s.closest('[data-dbv9-marquee-mobile="manual"]')) {
      s.classList.add("is-loop-ready", "is-manual"), u && u.classList.add("is-manual");
      return;
    }
    let v = Array.from(s.children);
    if (!v.length) {
      s.classList.add("is-loop-ready");
      return;
    }
    let h = l.createDocumentFragment();
    for (let p of v) {
      let b = p.cloneNode(true);
      b.setAttribute("aria-hidden", "true"), b.removeAttribute("role"), b.removeAttribute("id"), b.querySelectorAll(fr).forEach((w) => w.setAttribute("tabindex", "-1")), h.appendChild(b);
    }
    if (s.appendChild(h), u) {
      let p = (b) => () => s.classList.toggle("is-held", b);
      c(u, "pointerdown", p(true), { signal: e, passive: true });
      for (let b of ["pointerup", "pointercancel", "pointerleave"]) c(u, b, p(false), { signal: e, passive: true });
    }
    U(() => s.classList.add("is-loop-ready"));
  };
  if (!("IntersectionObserver" in n)) {
    a.forEach(i);
    return;
  }
  let o = new IntersectionObserver((s) => {
    for (let u of s) {
      let v = u.target.matches("[data-dbv9-marquee-clone]") ? u.target : u.target.querySelector("[data-dbv9-marquee-clone]");
      v && (u.isIntersecting ? (i(v), v.classList.remove("is-offscreen")) : v.dataset.dbv9LoopReady === "1" && v.classList.add("is-offscreen"));
    }
  }, { rootMargin: "320px 0px" });
  for (let s of a) o.observe(s.parentElement || s);
  e.addEventListener("abort", () => o.disconnect(), { once: true });
}
function br({ root: t, signal: e }) {
  for (let a of H("[data-dbv9-banner-rail]", t)) {
    let r = a.querySelector("[data-dbv9-banner-track]"), i = r ? Array.from(a.querySelectorAll(".delicat-banner-rail__dots i")) : [];
    if (!r || !i.length) continue;
    let o = 0, s = () => {
      if (!o) {
        let h = r.children;
        o = h.length > 1 ? h[1].offsetLeft - h[0].offsetLeft : h[0] ? h[0].offsetWidth : 0;
      }
      return o;
    };
    c(n, "resize", () => {
      o = 0;
    }, { passive: true, signal: e });
    let u = false, v = () => {
      u = false;
      let h = s(), p = Math.max(0, r.scrollWidth - r.clientWidth), b = h > 0 ? Math.round(r.scrollLeft / h) : 0;
      p > 0 && r.scrollLeft >= p - 2 && (b = i.length - 1), b = Math.max(0, Math.min(i.length - 1, b)), i.forEach((w, y) => w.classList.toggle("is-active", y === b));
    };
    c(r, "scroll", () => {
      u || (u = true, U(v));
    }, { passive: true, signal: e }), c(a, "click", (h) => {
      let p = h.target instanceof Element ? h.target.closest(".delicat-banner-rail__dots i") : null;
      if (p) try {
        r.scrollTo({ left: i.indexOf(p) * s(), behavior: z.reducedMotion ? "auto" : "smooth" });
      } catch {
        r.scrollLeft = i.indexOf(p) * s();
      }
    }, { signal: e });
  }
}
var ia = "9.3.4", _e = (t) => !!(l.body && l.body.classList.contains(t)), Ee = () => import("./chunks/product.d1ebda486d07.js"), _t = () => import("./chunks/purchase.247925c50b46.js"), kt = () => import("./chunks/widgets.b5d67f2f8e06.js"), vr = () => import("./chunks/reviews.94cd6c57cd13.js"), ie = (t, e) => () => t().then((a) => ({ default: a[e] }));
Y({ name: "theme", scope: "document", mount: qa }), Y({ name: "dark-adapt", scope: "document", mount: Da }), Y({ name: "header", scope: "document", when: "[data-dsb8-header]", mount: Ba }), Y({ name: "drawer", scope: "document", when: "[data-dlx-drawer]", mount: Oa }), Y({ name: "bottom-nav", scope: "document", when: "[data-delicat-bottom-nav],[data-delicat-mobile-dock]", mount: Ka }), Y({ name: "shell-header", scope: "document", when: "[data-delicat-shell-header]", load: () => import("./chunks/shell-header.3b1da135f45a.js") }), Y({ name: "pwa", scope: "document", when: () => !!(n.DelicaPWARuntime || J.pwa || n.DelicatAndroidPage || J.androidPage), mount: mr }), Y({ name: "reviews", scope: "document", mount: wr }), Y({ name: "announcement", scope: "document", when: "[data-dbv9-announcement]", load: ie(kt, "announcement") }), Y({ name: "live-selling", scope: "document", when: () => !!(n.DelicatLiveSelling || J.liveSelling), load: ie(kt, "liveSelling") }), Y({ name: "pull-refresh", scope: "document", when: () => !!J.pullRefresh, load: ie(kt, "pullRefresh") }), Y({ name: "notifications", scope: "document", when: "[data-dbv9-notifications]", mount: yr }), Y({ name: "carousel", when: "[data-delicat-carousel]", mount: rr }), Y({ name: "heart", when: "[data-delicat-like],[data-delicat-carousel],.delicat-product-card", mount: lr }), Y({ name: "hero-search", when: "[data-delicat-hero-search]", mount: sr }), Y({ name: "motion", when: ".delicat-page-layout[data-delicat-page-layout],.dbv9-reveal", mount: cr }), Y({ name: "marquee", when: "[data-dbv9-marquee-clone]", mount: gr }), Y({ name: "banner", when: "[data-dbv9-banner-rail]", mount: br }), Y({ name: "currency", always: true, when: "[data-dbv9-currency]", mount: ur }), Y({ name: "wallet-live", always: true, when: "[data-dsb-wallet]", mount: hr }), Y({ name: "purchase", always: true, when: () => !!(n.DelicaPurchaseV9 || J.purchase), load: ie(_t, "purchase") }), Y({ name: "wallet-guard", always: true, when: () => _e("dpn-checkout") || _e("dnp-express-enabled"), load: ie(_t, "walletGuard") }), Y({ name: "storefront-commerce", always: true, when: () => _e("delicat-native-checkout") || _e("delicat-native-cart") || !!l.querySelector("[data-dcn-clear-cart]"), load: ie(_t, "storefrontCommerce") }), Y({ name: "purchase-native", always: true, when: () => _e("dpn-cart") || _e("dpn-checkout") || !!n.DelicatPurchaseNative, load: () => import("./chunks/purchase-native.451d1f377e58.js") }), Y({ name: "native-product", when: ".delicat-native-product", load: ie(Ee, "nativeProduct") }), Y({ name: "product-switcher", when: ".dpsr-switcher", load: ie(Ee, "productSwitcher") }), Y({ name: "design-product", always: true, when: (t) => !!(t.querySelector("[data-delicat-design-options]") || l.querySelector("[data-delicat-design-buybar]")), load: ie(Ee, "designProduct") }), Y({ name: "calc", when: ".dmc-calc", load: ie(Ee, "calc") }), Y({ name: "express-checkout", always: true, when: "[data-dnp-express]", load: ie(Ee, "expressCheckout") });
var Mt = null, la = () => Mt || (Mt = import("./chunks/notifications.1005df894340.js").catch((t) => {
  throw Mt = null, n.__dlcChunkFailed = 1, t;
}));
function yr({ signal: t }) {
  let e = new AbortController();
  t.addEventListener("abort", () => e.abort(), { once: true });
  let a = null;
  c(l, "click", (r) => {
    let i = G(r.target, "[data-dbv9-notification-open]");
    i && (r.preventDefault(), r.stopPropagation(), a || (a = la().then((o) => o.default({ root: l, signal: t, config: J, engine: et, reason: "click" })).catch(() => null)), a.then((o) => {
      if (!o) {
        a = null;
        try {
          n.__dlcChunkFailed && !sessionStorage.getItem("dlc-bell-reload") && (sessionStorage.setItem("dlc-bell-reload", "1"), n.location.reload());
        } catch {
        }
        return;
      }
      e.abort();
      let s = i.closest("[data-dbv9-notifications]");
      s && o.open(s);
    }));
  }, { capture: true, signal: e.signal }), !z.saveData && !z.slowNetwork && le(() => {
    e.signal.aborted || la().catch(() => {
    });
  }, 4e3);
}
function wr({ signal: t }) {
  let e = () => n.DelicatReviewsConfig || J.reviews || null, a = new AbortController();
  t.addEventListener("abort", () => a.abort(), { once: true });
  let r = null, i = () => r || (r = vr().then((u) => u.default({ root: l, signal: t, config: J, engine: et, reason: "lazy" })).then((u) => u ? (a.abort(), u) : (r = null, null)).catch(() => (r = null, null)));
  c(l, "click", (u) => {
    let v = G(u.target, "[data-dlc-review-open],[data-dlc-vote],[data-dlc-reviews-all]");
    v && (u.preventDefault(), u.stopPropagation(), i().then((h) => {
      h && (h.handle ? h.handle(v) : h.open(v));
    }));
  }, { capture: true, signal: a.signal });
  let o = () => {
    let u = l.querySelector("[data-dlc-reviews]"), v = e();
    if (!u || a.signal.aborted || !v || !v.loggedIn) return;
    if (typeof n.IntersectionObserver != "function") {
      le(() => {
        a.signal.aborted || i();
      }, 2500);
      return;
    }
    let h = new n.IntersectionObserver((p) => {
      p.some((b) => b.isIntersecting) && (h.disconnect(), a.signal.aborted || i());
    }, { rootMargin: "400px 0px" });
    h.observe(u), a.signal.addEventListener("abort", () => h.disconnect(), { once: true });
  };
  o(), c(l, "delicat:navigated", o, { signal: a.signal });
  let s = e();
  s && s.loggedIn && le(() => {
    a.signal.aborted || i();
  }, 3e3);
}
pa({ nav: ge, session: re, overlay: Je, config: J, carousel: wt, mountContent: tt, version: ia }), n.DelicatBuilderEngine = et, n.DelicatSession = { get: re.get, ready: re.ready, refresh: re.refresh, on: re.on, subscribe: re.subscribe }, n.DBPState = { get: re.get, refresh: re.refresh, subscribe: re.subscribe }, n.DBPNav = { navigate: ge.navigate, prefetch: ge.prefetch, invalidate: ge.invalidate, stats: ge.stats };
var xr = () => J.nav && J.nav.main || "main[data-delicat-server-render],main[data-delicat-native-document],main", Sr = () => l.querySelector(xr()) || l.body, na = new Set(Array.isArray(J.globals) ? J.globals : []);
function _r() {
  let t = new Set(Array.isArray(J.globals) ? J.globals : []);
  for (let e of na) if (!t.has(e)) try {
    delete n[e];
  } catch {
    n[e] = void 0;
  }
  na = t;
}
function kr() {
  let t = /* @__PURE__ */ new Set(), e = (a, r) => {
    let i = String(a).slice(0, 160);
    t.has(i) || t.size > 20 || (t.add(i), J.debug && console.error("[delicat-engine]", r, a), ae("delicat:engine:error", { module: r, phase: "runtime", message: i }));
  };
  c(n, "error", (a) => {
    let r = String(a && a.filename || "");
    r && !/\/assets\/dist\//.test(r) || e(a && a.message || "error", "window");
  }), c(n, "unhandledrejection", (a) => {
    let r = a && a.reason, i = String(r && r.stack || "");
    i && !/\/assets\/dist\//.test(i) || e(r && r.message || String(r || "rejection"), "promise");
  });
}
function sa() {
  kr(), ca(), oa(), ge.on("swapped", (t) => {
    _r(), tt(t.main, "navigate");
  }), N.classList.add("delicat-engine"), ma("load"), tt(Sr(), "load").then(() => ae("delicat:engine:ready", { version: ia }));
}
l.readyState === "loading" ? c(l, "DOMContentLoaded", sa, { once: true }) : sa();
