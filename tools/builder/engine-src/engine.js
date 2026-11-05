import { a as la, b as fe } from "./chunks/shared-d.js";
import { a as sa, b as _t, c as he, d as oa, e as ee } from "./chunks/shared-c.js";
import { a as da, b as Ke, d as Ze } from "./chunks/shared-b.js";
import { a as Z, c as z, d as ca, e as Qe, f as ua, g as Je, i as Ge } from "./chunks/shared-a.js";
import { a as n, b as D, c as l, d as W, e as u, g as G, h as K, i as U, j as ae, l as Pe, m as O, n as et, o as tt, q as at, s as Et, t as pa, u as ma, w as kt, x as rt, y as it } from "./chunks/shared-base.js";
var At = "dbv9_theme", nt = /* @__PURE__ */ new Set(["light", "dark", "system"]), de = l.matchMedia ? l.matchMedia("(prefers-color-scheme: dark)") : null, be = "light", Ct = 0, Oe = null, Tt = null, lt = false, ga = () => {
  let t = Pe.read(At);
  return nt.has(t) ? t : null;
}, Nt = (t) => Pe.write(At, t, 31536e3), fa = (t) => t === "system" ? de && de.matches ? "dark" : "light" : t === "dark" ? "dark" : "light";
function Dt(t) {
  for (let e of W("[data-delicat-theme-toggle]")) {
    let a = t === "dark";
    e.setAttribute("aria-pressed", a ? "true" : "false"), e.setAttribute("aria-label", a ? "Activer le mode clair" : "Activer le mode sombre"), e.dataset.delicatThemeState = t, e.title = a ? "Mode clair" : "Mode sombre";
  }
}
function qt(t) {
  for (let e of W("img[data-dsb8-logo-light]")) {
    let a = e.getAttribute("data-dsb8-logo-light") || "", r = e.getAttribute("data-dsb8-logo-dark") || "", i = t === "dark" && r ? r : a;
    !i || e.getAttribute("src") === i || (e.setAttribute("src", i), e.removeAttribute("srcset"), e.removeAttribute("sizes"));
  }
  for (let e of W(".dsb8-header__logo")) {
    let a = e.querySelector(".dsb8-header__logo-light"), r = e.querySelector(".dsb8-header__logo-dark");
    if (!a || !r) continue;
    let i = t === "dark";
    a.hidden = i, r.hidden = !i, a.setAttribute("aria-hidden", i ? "true" : "false"), r.setAttribute("aria-hidden", i ? "false" : "true"), a.style.setProperty("display", i ? "none" : "flex", "important"), r.style.setProperty("display", i ? "flex" : "none", "important");
  }
}
function It(t) {
  let e = t === "dark", a = "delicat-home-mode-light", r = "delicat-home-mode-dark";
  n.body && (n.body.classList.toggle("dlc-theme-dark", e), n.body.classList.toggle("dlc-theme-light", !e), (n.body.classList.contains(a) || n.body.classList.contains(r) || n.body.classList.contains("delicat-builder-homepage-managed")) && (n.body.classList.toggle(r, e), n.body.classList.toggle(a, !e)));
  for (let i of W('[data-delicat-home-mode], [data-delicat-homepage-managed="1"], .delicat-page-layout--homepage-managed')) i.classList.toggle(r, e), i.classList.toggle(a, !e), i.hasAttribute("data-delicat-home-mode") && i.setAttribute("data-delicat-home-mode", t);
}
function ve() {
  if (!n.body) return false;
  let t = n.querySelector(".dap-shell.dap-floating, .dap-shell .dap-floating"), e = n.querySelector('a[aria-label="WhatsApp"][href*="BXQXUCKDDI3GO1"][style*="position:fixed"]');
  if (e && e.remove(), !t) return n.body.classList.remove("dbv9-assistant-open"), false;
  let a = t.querySelector(".dap-launcher"), r = t.querySelector(".dap-unread"), i = a ? a.getAttribute("aria-expanded") : "", d = t.querySelector('[role="dialog"][aria-hidden="false"], .dap-panel.is-open, .dap-window.is-open, .dap-dialog.is-open, .dap-chat.is-open'), s = t.getBoundingClientRect(), c = i === "true" || t.classList.contains("is-open") || t.classList.contains("dap-open") || t.classList.contains("is-expanded") || !!d || s.width > 240 || s.height > 240;
  if (n.body.classList.toggle("dbv9-assistant-open", c), a && !a.getAttribute("aria-label") && a.setAttribute("aria-label", "Ouvrir l\u2019assistant Delicat"), r) {
    let f = (r.textContent || "").trim(), g = Number.parseInt(f, 10), m = f === "" || !Number.isNaN(g) && g <= 0;
    r.hidden !== m && (r.hidden = m), r.setAttribute("aria-hidden", m ? "true" : "false");
  }
  return Tt !== t && typeof MutationObserver == "function" && (Oe && Oe.disconnect(), Tt = t, Oe = new MutationObserver(() => {
    lt || (lt = true, U(() => {
      lt = false, ve();
    }));
  }), Oe.observe(t, { childList: true, subtree: true, attributes: true, attributeFilter: ["aria-expanded", "aria-hidden", "class"] })), true;
}
function ha() {
  if (ve() || typeof MutationObserver != "function") return;
  let t = new MutationObserver(() => {
    ve() && t.disconnect();
  });
  t.observe(n.body, { childList: true }), l.setTimeout(() => t.disconnect(), 3500);
}
function ba(t) {
  return !t || typeof n.startViewTransition != "function" || O.reducedMotion || O.veryLowPower || D.hasAttribute("data-dbp-transition") ? "" : typeof D.animate == "function" && !O.lowPower && !D.classList.contains("delicat-low-power") && (!O.memory || O.memory >= 4) ? "circle" : "fade";
}
function va(t) {
  let e = Number(t.clientX) || 0, a = Number(t.clientY) || 0;
  if (!e && !a) {
    let r = t.target instanceof Element ? t.target.closest("[data-delicat-theme-toggle]") : null, i = r && r.getBoundingClientRect();
    i && (e = i.left + i.width / 2, a = i.top + i.height / 2);
  }
  return { x: e, y: a };
}
function $e(t, e = false, a = null) {
  be = nt.has(t) ? t : "light";
  let r = fa(be), i = e && ba(a), d = () => {
    D.dataset.delicatThemePreference = be, D.dataset.delicatTheme = r, D.classList.toggle("dlc-theme-dark", r === "dark"), D.classList.toggle("dlc-theme-light", r === "light"), D.style.colorScheme = r, It(r), qt(r), Dt(r), Pt(r), G("delicat:themechange", { theme: r, preference: be, vt: !!i });
  };
  if (i) {
    let { x: s, y: c } = va(a), f = null;
    D.classList.add("dlc-theme-vt"), i === "fade" && D.classList.add("dlc-theme-vt--fade");
    try {
      f = n.startViewTransition(d);
    } catch {
      f = null;
    }
    if (!f) {
      D.classList.remove("dlc-theme-vt", "dlc-theme-vt--fade"), d();
      return;
    }
    let g = () => D.classList.remove("dlc-theme-vt", "dlc-theme-vt--fade");
    if (i === "fade") {
      f.finished.then(g, g);
      return;
    }
    f.finished.then(g, g), f.ready.then(() => {
      let m = l.innerWidth || D.clientWidth, b = l.innerHeight || D.clientHeight, y = Math.ceil(Math.hypot(Math.max(s, m - s), Math.max(c, b - c)));
      try {
        D.animate({ clipPath: [`circle(0px at ${s}px ${c}px)`, `circle(${y}px at ${s}px ${c}px)`] }, { duration: 480, easing: "cubic-bezier(0.22, 1, 0.36, 1)", pseudoElement: "::view-transition-new(root)" });
      } catch {
      }
    }, () => {
    });
    return;
  }
  e && !O.reducedMotion && !O.lowPower && (D.classList.add("dlc-theme-switching"), l.clearTimeout(Ct), Ct = l.setTimeout(() => D.classList.remove("dlc-theme-switching"), 220)), d();
}
function st() {
  let t = D.dataset.delicatTheme === "dark" ? "dark" : "light";
  It(t), qt(t), Dt(t), ha(), Pt(t);
}
function Pt(t) {
  let e = n.querySelectorAll('meta[name="theme-color"][media]');
  if (!e.length) return;
  let a = { light: "", dark: "" };
  for (let i of e) {
    i.hasAttribute("data-dlc-c") || i.setAttribute("data-dlc-c", i.getAttribute("content") || "");
    let d = i.getAttribute("media") || "";
    /dark/.test(d) ? a.dark = i.getAttribute("data-dlc-c") : /light/.test(d) && (a.light = i.getAttribute("data-dlc-c"));
  }
  let r = t === "dark" ? a.dark : a.light;
  if (r) for (let i of e) i.getAttribute("content") !== r && i.setAttribute("content", r);
}
var ot = "/*dk3*/", Be = "dbv9_dk_css", Ot = "dbv9_dk_rules", $t = ".dsb8-header,.dlx-drawer,.delicat-bottom-nav,.dsb8-cart-panel,.dbv9-notifications__panel,.dlc-review-overlay,[data-dlc-dark-skip]", dt = /* @__PURE__ */ new Set(["script", "style", "noscript", "template", "img", "video", "canvas", "iframe", "picture", "source", "link", "meta", "br", "hr", "object", "embed", "audio", "map", "area", "svg", "math", "head", "title"]), _e = /* @__PURE__ */ new WeakMap(), ce = /* @__PURE__ */ new Set(), ct = 0, ye = null, ue = () => D.dataset.delicatTheme === "dark";
function pe(t) {
  let e = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+)(%?))?\s*\)$/.exec(t || "");
  if (!e) return null;
  let a = e[4] == null ? 1 : parseFloat(e[4]) / (e[5] ? 100 : 1);
  return [+e[1], +e[2], +e[3], a];
}
function we(t) {
  let e = (a) => (a /= 255, a <= 0.03928 ? a / 12.92 : Math.pow((a + 0.055) / 1.055, 2.4));
  return 0.2126 * e(t[0]) + 0.7152 * e(t[1]) + 0.0722 * e(t[2]);
}
function ut(t) {
  let e = t[0] / 255, a = t[1] / 255, r = t[2] / 255, i = Math.max(e, a, r), d = Math.min(e, a, r), s = (i + d) / 2, c = 0, f = 0;
  if (i !== d) {
    let g = i - d;
    f = s > 0.5 ? g / (2 - i - d) : g / (i + d), c = i === e ? (a - r) / g + (a < r ? 6 : 0) : i === a ? (r - e) / g + 2 : (e - a) / g + 4, c /= 6;
  }
  return [c, f, s];
}
function pt(t, e, a) {
  if (!e) {
    let s = Math.round(a * 255);
    return "rgb(" + s + ", " + s + ", " + s + ")";
  }
  let r = a < 0.5 ? a * (1 + e) : a + e - a * e, i = 2 * a - r, d = (s) => (s < 0 && (s += 1), s > 1 && (s -= 1), s < 1 / 6 ? i + (r - i) * 6 * s : s < 0.5 ? r : s < 2 / 3 ? i + (r - i) * (2 / 3 - s) * 6 : i);
  return "rgb(" + Math.round(d(t + 1 / 3) * 255) + ", " + Math.round(d(t) * 255) + ", " + Math.round(d(t - 1 / 3) * 255) + ")";
}
function ya(t) {
  let [e, a, r] = ut(t);
  return a < 0.14 ? (e = 0.64, a = 0.4) : a = Math.min(a, 0.42), pt(e, a, Math.min(0.2, 0.115 + (1 - r) * 0.5));
}
function wa(t) {
  let [e, a, r] = ut(t);
  return a = a < 0.14 ? Math.min(a, 0.12) : Math.min(a, 0.65), pt(e, a, Math.min(0.95, Math.max(0.74, 1 - r * 0.6)));
}
function xa(t) {
  let [e, a] = ut(t);
  return a < 0.14 ? "rgba(255, 255, 255, 0.1)" : pt(e, Math.min(a, 0.45), 0.3);
}
const We = (t) => !!t && t[3] >= 0.5 && we(t) > 0.7;
function Bt(t) {
  let e = [], a = /rgba?\([^)]*\)/g, r;
  for (; r = a.exec(t); ) {
    let i = pe(r[0]);
    i && e.push(i);
  }
  return e;
}
function mt(t) {
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
const Sa = (t) => t.localName === "textarea" || t.localName === "select" || t.localName === "input" && !mt(t) && String(t.type || "").toLowerCase() !== "hidden";
function Wt(t) {
  let e = (typeof t.className == "string" ? t.className : "") + " " + (t.id || "");
  if (/(^|[\s_-])(qr|qrcode|logo|barcode|avatar|swatch)([\s_-]|$)/i.test(e)) return true;
  if (!t.children.length) return false;
  for (let a of t.children) if (!/^(img|svg|picture|canvas|video)$/.test(a.localName)) return false;
  for (let a of t.childNodes) if (a.nodeType === 3 && a.nodeValue.trim()) return false;
  return true;
}
function La(t) {
  for (let e of t.children) if (e.localName === "svg") return true;
  return false;
}
function Ma(t) {
  for (let e of t.childNodes) if (e.nodeType === 3 && e.nodeValue.trim()) return true;
  return false;
}
function _a(t, e) {
  let a = _e.get(t), r = t.style;
  if (!a && !r.length) {
    a = {};
    let i = "";
    for (let d in e) a[d] = ["", ""], i += d + ":" + e[d] + "!important;";
    _e.set(t, a), r.cssText = i, t.setAttribute("data-dlc-dk", "");
    return;
  }
  a || _e.set(t, a = {});
  for (let i in e) i in a || (a[i] = [t.style.getPropertyValue(i), t.style.getPropertyPriority(i)]), t.style.setProperty(i, e[i], "important");
  t.setAttribute("data-dlc-dk", "");
}
function Re(t) {
  let e = t ? [t, ...t.querySelectorAll("[data-dlc-dk]")].filter((a) => a.hasAttribute("data-dlc-dk")) : W("[data-dlc-dk]");
  for (let a of e) {
    let r = _e.get(a);
    if (r) for (let i in r) {
      let [d, s] = r[i];
      d ? a.style.setProperty(i, d, s) : a.style.removeProperty(i);
    }
    _e.delete(a), a.removeAttribute("data-dlc-dk");
  }
}
const je = /* @__PURE__ */ new Map();
function gt(t, e, a) {
  let r = t + e, i = je.get(r);
  return i === void 0 && (je.size > 400 && je.clear(), i = t === "s" ? ya(a) : t === "i" ? wa(a) : xa(a), je.set(r, i)), i;
}
function Ea(t) {
  for (let e = t; e && e.nodeType === 1; e = e.parentElement) {
    let a = l.getComputedStyle(e), r = a.backgroundImage || "none";
    if (r !== "none") {
      if (r.indexOf("url(") !== -1) return null;
      let d = Bt(r).filter((s) => s[3] >= 0.2);
      if (d.length) return d[0];
    }
    let i = pe(a.backgroundColor);
    if (i && i[3] >= 0.5) return i;
  }
  return [7, 9, 20, 1];
}
function ka(t) {
  let e = /* @__PURE__ */ new Map();
  if (!n.body || !t || !t.isConnected) return e;
  let a = new Set(n.querySelectorAll($t)), r = (s, c, f) => {
    let g = e.get(s);
    g || e.set(s, g = {}), g[c] = f;
  }, i = ["Top", "Right", "Bottom", "Left"], d = [[t, Ea(t.parentElement), false]];
  for (; d.length; ) {
    let [s, c, f] = d.pop();
    if (!s || s.nodeType !== 1 || dt.has(s.localName) || s.namespaceURI && s.namespaceURI !== "http://www.w3.org/1999/xhtml" || a.has(s)) continue;
    let g = l.getComputedStyle(s), m = g.display;
    if (m === "none") continue;
    let b = s.children;
    if (m === "contents") {
      for (let C = b.length - 1; C >= 0; C--) d.push([b[C], c, f]);
      continue;
    }
    let y = c;
    if (s !== n.body) {
      let C = g.backgroundColor, I = pe(C), T = g.backgroundImage || "none";
      if (Sa(s)) We(I) ? (r(s, "background-color", "rgba(5, 7, 20, 0.62)"), r(s, "color", "#f4f5fb"), r(s, "border-color", "rgba(255, 255, 255, 0.1)"), r(s, "caret-color", "#f4f5fb"), r(s, "box-shadow", "inset 3px 3px 8px rgba(0, 0, 0, 0.55), inset -2px -2px 6px rgba(255, 255, 255, 0.035)"), y = [8, 10, 26, 1]) : I && I[3] >= 0.5 && (y = I);
      else {
        let x = false, w = T !== "none" && T.indexOf("url(") === -1 ? Bt(T) : null, A = w ? w.filter((q) => q[3] >= 0.2) : null, _ = !!w && !A.length, R = !!A && A.length > 0 && A.every(We);
        if (T === "none" || _ ? We(I) : R) {
          let q = s.offsetWidth, p = s.offsetHeight;
          if (!(q * p < 900 || q < 28 && p < 28 || mt(s) || Wt(s))) {
            let o = T === "none" || _ || We(I) ? I : A[0], L = T === "none" || _ ? C : "g" + o.join(","), E = gt("s", L, o), M = pe(E);
            r(s, "background-image", "linear-gradient(155deg, rgba(255, 255, 255, 0.07), rgba(255, 255, 255, 0.02) 60%)"), r(s, "background-color", c && we(c) < 0.06 ? "rgba(" + M[0] + ", " + M[1] + ", " + M[2] + ", 0.84)" : E), (g.boxShadow === "none" || /rgba?\(\s*2[0-5]\d/.test(g.boxShadow)) && r(s, "box-shadow", f ? "inset 0 1px 0 rgba(255, 255, 255, 0.05)" : "inset 0 1px 0 rgba(255, 255, 255, 0.07), 0 10px 26px rgba(0, 0, 0, 0.34)"), y = M, x = true;
          }
        }
        x || (T !== "none" && !_ ? y = T.indexOf("url(") !== -1 ? null : A[0] : I && I[3] >= 0.5 && (y = I));
        for (let q of i) {
          if (!(parseFloat(g["border" + q + "Width"]) > 0)) continue;
          let p = g["border" + q + "Color"], o = pe(p);
          o && o[3] >= 0.3 && we(o) > 0.55 && r(s, "border-" + q.toLowerCase() + "-color", gt("e", p, o));
        }
        if (y && (Ma(s) || La(s))) {
          let q = g.color, p = pe(q);
          if (p && p[3] >= 0.3) {
            let o = we(y), L = we(p), E = (Math.max(o, L) + 0.05) / (Math.min(o, L) + 0.05);
            if (o <= 0.2 && E < 4.5) {
              let M = gt("i", q, p), H = pe(M);
              H && we(H) > L && r(s, "color", M);
            } else o > 0.6 && L > 0.45 && E < 2.2 && r(s, "color", "#14182d");
          }
        }
      }
    }
    let v = f || e.has(s) && "background-color" in e.get(s);
    for (let C = b.length - 1; C >= 0; C--) d.push([b[C], y, v]);
  }
  for (let [s, c] of e) _a(s, c);
  return e;
}
const Rt = /^(delicat|dsb|dsb8|dbv9|dnp|dlc|dlx|dbn|dbp|dni|dmc|ddsw)[-_]/i;
function jt(t) {
  for (let a of t.classList) if (Rt.test(a)) return "";
  if (t.id && Rt.test(t.id)) return "";
  if (t.id && /^[A-Za-z][\w-]{2,40}$/.test(t.id) && !/\d{3,}/.test(t.id)) return "#" + t.id;
  let e = [];
  for (let a of t.classList) /^[A-Za-z][\w-]{3,48}$/.test(a) && /[-_]/.test(a) && !/\d{3,}/.test(a) && !/^(is|has|js|dlc|wp|elementor-element|e-con)[-_]/i.test(a) && !/(active|open|show|hidden|selected|current|visible|focus|hover|loading|loaded|animated)/i.test(a) && e.push(a);
  return e.length ? t.localName + "." + e.slice(0, 3).join(".") : "";
}
function Aa(t) {
  t = t || /* @__PURE__ */ new Map();
  let e = {};
  try {
    e = JSON.parse(l.localStorage.getItem(Ot) || "{}") || {};
    let m = l.localStorage.getItem(Be) || "";
    m && m.indexOf(ot) !== 0 && (e = {});
  } catch {
    return;
  }
  let a = Math.floor(Date.now() / 1e3), r = (m) => Object.keys(m).sort().map((b) => b + ":" + m[b] + "!important").join(";"), i = /* @__PURE__ */ new Set(), d = false, s = !!n.getElementById("dlc-dk-cache"), c = /* @__PURE__ */ new Set();
  for (let [m, b] of t) if (!(!("color" in b) || jt(m))) for (let y = m.parentElement; y; y = y.parentElement) {
    let v = t.get(y);
    v && "background-color" in v && c.add(y);
  }
  for (let [m, b] of t) {
    if (!("background-color" in b) && !("color" in b) || c.has(m)) continue;
    let y = jt(m);
    if (!y || i.has(y)) continue;
    i.add(y);
    let v = r(b), C = true;
    try {
      for (let I of n.querySelectorAll(y)) {
        let T = t.get(I);
        if (T ? r(T) !== v : !(s && e[y] && e[y][0] === v)) {
          C = false;
          break;
        }
      }
    } catch {
      C = false;
    }
    C ? (!e[y] || e[y][0] !== v || a - e[y][1] > 86400) && (e[y] = [v, a], d = true) : e[y] && (delete e[y], d = true);
  }
  let f = Object.keys(e);
  for (let m of f) {
    if (i.has(m) || a - e[m][1] < 86400) continue;
    let b = null;
    try {
      b = n.querySelector(m);
    } catch {
    }
    b && !mt(b) && !Wt(b) ? (e[m][1] = a, d = true) : a - e[m][1] > 21 * 86400 && (delete e[m], d = true);
  }
  if (f = Object.keys(e), f.length > 240) {
    f.sort((m, b) => e[m][1] - e[b][1]);
    for (let m of f.slice(0, f.length - 240)) delete e[m];
    d = true;
  }
  if (!d) return;
  let g = ot + Object.keys(e).map((m) => "html.dlc-theme-dark " + m + "{" + e[m][0] + "}").join("");
  try {
    l.localStorage.setItem(Ot, JSON.stringify(e)), g.length < 6e4 ? l.localStorage.setItem(Be, g) : l.localStorage.removeItem(Be);
  } catch {
  }
}
var zt = -1;
function re(t, e) {
  if (!ue()) return;
  let a;
  (!t || t === n.body) && (zt = n.styleSheets.length);
  try {
    a = ka(t || n.body);
  } catch {
    return;
  }
  e && ae(() => {
    try {
      Aa(a);
    } catch {
    }
  }, 2500);
}
function Ca() {
  if (ct = 0, !ue()) {
    ce.clear();
    return;
  }
  let t = [...ce].filter((e) => e.isConnected);
  if (ce.clear(), t.length > 60) return re(n.body, true);
  for (let e of t) {
    let a = e.parentElement, r = false;
    for (; a; a = a.parentElement) if (t.indexOf(a) !== -1) {
      r = true;
      break;
    }
    r || e.closest && e.closest($t) || re(e, false);
  }
}
function Ta() {
  if (!n.getElementById("dlc-dk-cache")) try {
    let t = l.localStorage.getItem(Be) || "";
    if (!t || t.indexOf(ot) !== 0 || t.length >= 6e4) return;
    let e = n.createElement("style");
    e.id = "dlc-dk-cache", e.setAttribute("data-delicat-keep", ""), e.textContent = t, n.head.appendChild(e);
  } catch {
  }
}
function Na(t) {
  ye || typeof MutationObserver != "function" || !n.body || (ye = new MutationObserver((e) => {
    if (!ue()) {
      for (let a of e) for (let r of a.addedNodes) r.nodeType === 1 && (r.hasAttribute("data-dlc-dk") || r.firstElementChild && r.querySelector("[data-dlc-dk]")) && Re(r);
      return;
    }
    for (let a of e) {
      if (a.type === "attributes") {
        let r = a.target;
        if (r === n.body || r === D || r.nodeType !== 1 || dt.has(r.localName)) continue;
        if (ce.add(r), a.attributeName === "aria-expanded") for (let i of String(r.getAttribute("aria-controls") || "").split(/\s+/)) {
          let d = i && n.getElementById(i);
          d && ce.add(d);
        }
        continue;
      }
      for (let r of a.addedNodes) r.nodeType === 1 && !dt.has(r.localName) && ce.add(r);
    }
    ce.size && !ct && (ct = U(() => U(Ca)));
  }), ye.observe(n.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["class", "hidden", "open", "aria-hidden", "aria-expanded"] }), t.addEventListener("abort", () => {
    ye && ye.disconnect(), ye = null;
  }, { once: true }));
}
function Da({ signal: t }) {
  let e = () => ue() ? re(n.body, true) : Re();
  Na(t), ue() && (n.getElementById("dlc-dk-cache") ? ae(() => re(n.body, true), 300) : U(() => re(n.body, true)));
  let a = 0;
  u(n, "delicat:themechange", (r) => {
    l.clearTimeout(a), r.detail && r.detail.theme === "dark" ? r.detail.vt ? (Ta(), a = l.setTimeout(() => U(() => re(n.body, false)), 600)) : a = l.setTimeout(() => U(() => re(n.body, false)), O.reducedMotion ? 0 : 320) : Re();
  }, { signal: t }), u(n, "delicat:navigated", (r) => U(() => {
    if (!ue()) return Re();
    let i = r && r.detail && r.detail.main;
    i && i.isConnected ? re(i, false) : re(n.body, true);
  }), { signal: t }), u(l, "pageshow", (r) => r.persisted && e(), { passive: true, signal: t }), u(l, "load", () => ue() && n.styleSheets.length !== zt && ae(e, 800), { once: true, signal: t });
}
var Vt = "dbv9-speed";
function qa() {
  let t = null;
  try {
    t = l.sessionStorage.getItem(Vt);
  } catch {
  }
  let e = (a) => {
    a === "low" ? D.classList.add("delicat-low-power", "dsb8-low-power", "dsb-cheap-device") : a === "very-low" && D.classList.add("delicat-low-power", "delicat-very-low-power", "dsb8-low-power", "dsb-cheap-device");
  };
  if (t !== null) {
    e(t);
    return;
  }
  ae(() => {
    let a = 1 / 0;
    for (let i = 0; i < 2; i++) {
      let d = l.performance.now(), s = 0;
      for (let c = 0; c < 3e5; c++) s = (s * 31 + c) % 1000003;
      if (s < 0) return;
      a = Math.min(a, l.performance.now() - d);
    }
    let r = a >= 12 ? "very-low" : a >= 7 ? "low" : "ok";
    try {
      l.sessionStorage.setItem(Vt, r);
    } catch {
    }
    e(r);
  }, 4e3);
}
function Ia({ signal: t }) {
  if (O.saveData && D.classList.add("delicat-save-data"), O.lowPower ? D.classList.add("delicat-low-power", "dsb8-low-power", "dsb-cheap-device") : O.reducedMotion && D.classList.add("dsb-cheap-device"), !O.lowPower && (!O.memory || O.memory <= 4) && qa(), O.reducedMotion && D.classList.add("delicat-reduce-motion"), $e(ga() || D.dataset.delicatThemePreference || "light", false), u(n, "click", (e) => {
    if (!(e.target instanceof Element && e.target.closest("[data-delicat-theme-toggle]"))) return;
    e.preventDefault();
    let a = D.dataset.delicatTheme === "dark" ? "light" : "dark";
    Nt(a), $e(a, true, e);
  }, { capture: true, signal: t }), de) {
    let e = () => {
      be === "system" && $e("system", true);
    };
    de.addEventListener ? de.addEventListener("change", e) : de.addListener && de.addListener(e);
  }
  u(n, "delicat:navigated", st, { signal: t }), u(l, "pageshow", st, { passive: true, signal: t }), u(n, "click", (e) => {
    e.target instanceof Element && e.target.closest(".dap-shell") && (l.setTimeout(ve, 0), l.setTimeout(ve, 360));
  }, { capture: true, signal: t }), u(n, "keydown", (e) => {
    e.key === "Escape" && l.setTimeout(ve, 0);
  }, { capture: true, signal: t }), st(), l.DelicaTheme = Object.freeze({ get: () => D.dataset.delicatTheme || "light", getPreference: () => be, set: (e) => {
    let a = nt.has(e) ? e : "light";
    Nt(a), $e(a, true);
  } });
}
var Pa = 82;
function ft() {
  let t = _t();
  if (t !== null) return he(t), true;
  let e = n.querySelector(".dsb8-woo-cart-count-fragment");
  if (e) return he(e.getAttribute("data-count") || e.textContent), true;
  let a = n.querySelector(".cart-contents-count,.mini-cart-count,.widget_shopping_cart .count");
  return a ? (he(a.textContent), true) : false;
}
function Oa({ signal: t }) {
  if (u(n, "click", (p) => {
    let o = K(p.target, "[data-dsb8-menu-trigger]");
    if (!o || n.querySelector("[data-dlx-drawer]")) return;
    let L = n.querySelector(".dsb-menu-toggle,[data-dsb-menu-toggle],.dsb-modern-menu-toggle");
    if (L && L !== o) {
      L.click();
      return;
    }
    G("dsb8:overlay:open", { id: "modern-menu", trigger: o });
  }, { signal: t }), O.standalone) {
    let p = n.querySelector(".dsb8-header__row"), o = (n.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="home"]') || n.querySelector(".dsb8-header__brand") || {}).href || "/", L = "/";
    try {
      L = new URL(o, location.href).pathname.replace(/\/+$/, "") || "/";
    } catch {
    }
    let E = null, M = () => {
      let H = (location.pathname.replace(/\/+$/, "") || "/") !== L;
      H && !E && p && (E = n.createElement("button"), E.type = "button", E.className = "dsb8-header__action dsb8-header__back", E.setAttribute("aria-label", "Retour"), E.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>', u(E, "click", () => {
        history.length > 1 ? history.back() : location.assign(o);
      }, { signal: t }), p.insertBefore(E, p.firstChild)), E && (E.hidden = !H);
      let X = n.querySelector(".dsb8-header");
      X && X.classList.toggle("has-back", H && !!E);
    };
    M(), u(n, "delicat:navigated", M, { signal: t }), u(n, "delicat:placeholder", M, { signal: t }), u(l, "popstate", () => l.setTimeout(M, 0), { signal: t });
  }
  ft(), u(l, "pageshow", (p) => {
    if (ft(), p.persisted) {
      let o = rt();
      o && o(n.body).trigger("wc_fragment_refresh");
    }
  }, { signal: t }), it("added_to_cart removed_from_cart updated_wc_div updated_cart_totals wc_fragments_loaded wc_fragments_refreshed", () => l.setTimeout(ft, 0), t), it("added_to_cart", (p, o) => {
    if (o && o[".dsb8-woo-cart-count-fragment"]) {
      let L = n.createElement("div");
      L.innerHTML = o[".dsb8-woo-cart-count-fragment"];
      let E = L.firstElementChild;
      E && he(E.getAttribute("data-count") || E.textContent);
    }
  }, t), l.DSB8 && l.DSB8.emit && l.DSB8.emit("component:ready", { id: "header-beta2" });
  let e = false, a = 0, r = 0, i = 0, d = () => W("[data-dsb8-cart-root]"), dbvPlace = (p) => {
    let P = p && p.querySelector(".dsb8-cart-panel"), Tg = p && p.querySelector("[data-dsb8-cart-trigger]");
    if (!P || !Tg) return;
    let V = l.visualViewport, vw = V ? V.width : l.innerWidth, vh = V ? V.height : l.innerHeight;
    if (vw > 767) {
      P.removeAttribute("data-dsb8-placed");
      return;
    }
    let m = vw <= 420 ? 10 : 12, w2 = Math.min(390, vw - m * 2), R = Tg.getBoundingClientRect(), x2 = Math.min(vw - m, Math.max(m + w2, R.right + 6)) - w2, y2 = Math.max(8, Math.round(R.bottom + 10));
    P.style.setProperty("--dsb8-cart-top", y2 + "px"), P.style.setProperty("--dsb8-cart-left", Math.round(x2) + "px"), P.style.setProperty("--dsb8-cart-width", Math.round(w2) + "px"), P.style.setProperty("--dsb8-cart-max-height", Math.max(220, Math.round(vh - y2 - m)) + "px"), P.setAttribute("data-dsb8-placed", "");
  }, dbvSeq = 0, dbvRefresh = async (p) => {
    if (!p || !p.dataset.cartUrl) return;
    let seq = ++dbvSeq;
    try {
      let M = await Ge("delicat_builder_v9_header_cart", {}, { url: p.dataset.cartUrl, timeout: 8e3 });
      if (seq !== dbvSeq || !M || !p.isConnected) return;
      let Ls = p.querySelector(".dsb8-cart-list"), Pn = p.querySelector(".dsb8-cart-panel"), Ft = p.querySelector(".dsb8-cart-panel__foot");
      if (Ls && typeof M.list == "string") {
        let Dc = pa("<body>" + M.list);
        Ls.replaceChildren(...Dc ? Array.from(Dc.body.childNodes) : []);
      }
      if (typeof M.foot == "string") {
        let Dc = M.foot ? pa("<body>" + M.foot) : null, Nf = Dc && Dc.body.firstElementChild;
        Nf ? Ft ? Ft.replaceWith(Nf) : Pn && Pn.appendChild(Nf) : Ft && Ft.remove();
      }
      M.nonce && (p.dataset.nonce = M.nonce), he(parseInt(M.count, 10) || 0), w();
    } catch {
    }
  }, s = () => !!n.querySelector("[data-dsb8-cart-root].is-open"), c = () => {
    D.classList.remove("dsb8-cart-locked");
  }, f = (p, o) => {
    if (!p) return;
    o && dbvPlace(p), p.classList.toggle("is-open", !!o);
    let L = p.querySelector("[data-dsb8-cart-trigger]");
    L && L.setAttribute("aria-expanded", o ? "true" : "false"), e = !!o, o && (a = l.pageYOffset || 0, r = l.visualViewport && l.visualViewport.pageTop || 0), c();
  }, g = (p) => {
    for (let o of d()) o !== p && o.classList.contains("is-open") && o.classList.remove("is-open");
    for (let o of W("[data-dsb8-cart-trigger]")) {
      let L = o.closest("[data-dsb8-cart-root]");
      L && !L.classList.contains("is-open") && o.setAttribute("aria-expanded", "false");
    }
    s() || (e = false), c();
  }, m = (p) => {
    if (p) {
      delete p.dataset.busy, p.classList.remove("is-removing", "swipe-right"), p.style.setProperty("--dsb8-cart-dx", "0px");
      for (let o of ["opacity", "maxHeight", "marginTop", "marginBottom"]) p.style[o] = "";
    }
  }, b = (p, o) => {
    p.style.maxHeight = p.offsetHeight + "px", p.style.overflow = "hidden", p.classList.add("is-removed"), U(() => U(() => {
      p.style.maxHeight = "0px", p.style.marginTop = "0px", p.style.marginBottom = "0px", p.style.opacity = "0";
    })), l.setTimeout(o, 240);
  }, y = (p, o) => {
    let L = p.querySelector(".dsb8-cart-list");
    if (!L) return;
    let E = L.querySelector(".dsb8-cart-error");
    E && E.remove();
    let M = n.createElement("div");
    M.className = "dsb8-cart-error", M.textContent = o || "Impossible de supprimer cet article.", L.insertBefore(M, L.firstChild), l.setTimeout(() => M.remove(), 3200);
  }, v = (p, o) => {
    let L = parseInt(o && o.count, 10);
    (!isFinite(L) || L < 0) && (L = 0), he(L), o && o.nonce && (p.dataset.nonce = o.nonce);
    let E = p.querySelector(".dsb8-cart-panel__foot");
    if (L === 0) {
      let M = p.querySelector(".dsb8-cart-list");
      M && (M.innerHTML = '<div class="dsb8-cart-empty">Votre panier est vide.</div>'), E && E.remove();
      return;
    }
    if (E && o && o.subtotal) {
      let M = E.querySelector("strong");
      M && (M.innerHTML = o.subtotal, M.classList.add("is-updated"), l.setTimeout(() => M.classList.remove("is-updated"), 560));
    }
  }, C = (p, o) => {
    let L = rt();
    l.clearTimeout(i), i = l.setTimeout(() => {
      L && (L(n.body).trigger("removed_from_cart", [{}, "", null]), L(n.body).trigger("dsb8_cart_updated", [o || {}, p || null])), G("delicat:cart-changed", { source: "header-cart" });
    }, 40);
  }, I = (p) => {
    let o = rt();
    if (!o) {
      location.reload();
      return;
    }
    let L = () => {
      o(n.body).off("wc_fragments_refreshed wc_fragments_loaded", L), l.setTimeout(() => {
        let E = n.querySelector('.dsb8-cart-swipe[data-cart-key="' + p.replace(/"/g, '\\"') + '"]'), M = E && E.closest("[data-dsb8-cart-root]");
        M && T(M, E, true);
      }, 60);
    };
    o(n.body).on("wc_fragments_refreshed wc_fragments_loaded", L), o(n.body).trigger("wc_fragment_refresh");
  };
  async function T(p, o, L) {
    if (!p || !o || o.dataset.busy === "1") return;
    let E = o.getAttribute("data-cart-key");
    if (E) {
      o.dataset.busy = "1", o.classList.add("is-removing");
      try {
        let M = await Ge("delicat_builder_v9_header_remove_cart_item", { nonce: p.dataset.nonce || "", cart_item_key: E }, { url: p.dataset.ajax });
        b(o, () => {
          o.remove(), v(p, M || {}), C(p, M || {});
        });
      } catch (M) {
        if ((M && M.data && M.data.code) === "stale_nonce" && !L) {
          m(o), I(E);
          return;
        }
        m(o), y(p, M && M.data && M.data.message);
      }
    }
  }
  let x = (p, o) => {
    if (o.dataset.bound === "1") return;
    o.dataset.bound = "1";
    let L = 0, E = 0, M = 0, H = false, X = "", me = (Y, S) => {
      L = Y, E = S, M = 0, H = true, X = "", o.classList.add("is-dragging");
    }, le = (Y, S, N) => {
      if (!H) return;
      let j = Y - L, V = S - E;
      if (!X) {
        if (Math.abs(j) < 7 && Math.abs(V) < 7) return;
        if (X = Math.abs(j) > Math.abs(V) ? "x" : "y", X === "y") {
          H = false, o.classList.remove("is-dragging");
          return;
        }
      }
      M = Math.max(-150, Math.min(150, j)), N && N.cancelable && N.preventDefault(), o.style.setProperty("--dsb8-cart-dx", M + "px"), o.classList.toggle("is-swiping", Math.abs(M) > 2), o.classList.toggle("swipe-right", M > 0);
    }, se = () => {
      H && (H = false, o.classList.remove("is-dragging"), Math.abs(M) >= Pa ? (o.style.setProperty("--dsb8-cart-dx", M < 0 ? "-120%" : "120%"), l.setTimeout(() => T(p, o), 120)) : (o.style.setProperty("--dsb8-cart-dx", "0px"), o.classList.remove("is-swiping", "swipe-right")), M = 0);
    };
    l.PointerEvent ? (u(o, "pointerdown", (Y) => {
      K(Y.target, "button,a") || Y.pointerType === "mouse" && Y.button !== 0 || me(Y.clientX, Y.clientY);
    }, { signal: t }), u(o, "pointermove", (Y) => le(Y.clientX, Y.clientY, Y), { passive: false, signal: t }), u(o, "pointerup", se, { signal: t }), u(o, "pointercancel", se, { signal: t })) : (u(o, "touchstart", (Y) => {
      if (K(Y.target, "button,a")) return;
      let S = Y.touches[0];
      me(S.clientX, S.clientY);
    }, { passive: true, signal: t }), u(o, "touchmove", (Y) => {
      let S = Y.touches[0];
      le(S.clientX, S.clientY, Y);
    }, { passive: false, signal: t }), u(o, "touchend", se, { signal: t }));
  }, w = () => {
    for (let p of d()) for (let o of W(".dsb8-cart-swipe", p)) x(p, o);
    if (e && !s()) {
      let p = n.querySelector("[data-dsb8-cart-root]");
      p && f(p, true);
    }
    c();
  };
  u(n, "click", (p) => {
    let o = p.target;
    if (!o || !o.closest) return;
    let L = o.closest("[data-dsb8-cart-trigger]");
    if (L) {
      p.preventDefault(), p.stopPropagation();
      let M = L.closest("[data-dsb8-cart-root]");
      if (!M) return;
      let H = !M.classList.contains("is-open");
      g(M), f(M, H), H && dbvRefresh(M);
      let X = H && M.querySelector(".dsb8-cart-panel");
      X && (X.hasAttribute("tabindex") || X.setAttribute("tabindex", "-1"), l.requestAnimationFrame(() => X.focus({ preventScroll: true })));
      return;
    }
    if (o.closest(".dsb8-cart-panel__close")) {
      p.preventDefault(), f(o.closest("[data-dsb8-cart-root]"), false);
      return;
    }
    let E = o.closest(".dsb8-cart-item__remove");
    if (E) {
      p.preventDefault(), p.stopPropagation(), T(E.closest("[data-dsb8-cart-root]"), E.closest(".dsb8-cart-swipe"));
      return;
    }
    o.closest("[data-dsb8-cart-root]") || g();
  }, { signal: t }), u(n, "keydown", (p) => {
    if (p.key !== "Escape") return;
    let o = n.querySelector("[data-dsb8-cart-root].is-open"), L = o && o.contains(n.activeElement) && o.querySelector("[data-dsb8-cart-trigger]");
    g(), L && L.focus({ preventScroll: true });
  }, { signal: t }), u(l, "resize", c, { passive: true, signal: t }), u(l, "resize", () => {
    for (let P of W("[data-dsb8-cart-root].is-open")) dbvPlace(P);
  }, { passive: true, signal: t }), u(l, "pagehide", c, { signal: t });
  let A = 0;
  u(l, "scroll", () => {
    !e || A || (A = U(() => {
      if (A = 0, !e) return;
      let p = l.pageYOffset || 0;
      Math.abs(p - a) > 10 && g(), a = p;
    }));
  }, { passive: true, signal: t });
  let _ = null;
  u(n, "touchstart", (p) => {
    if (!e || !p.touches || !p.touches[0]) {
      _ = null;
      return;
    }
    if (K(p.target, ".dsb8-cart-list")) {
      _ = null;
      return;
    }
    _ = p.touches[0].clientY;
  }, { passive: true, capture: true, signal: t }), u(n, "touchmove", (p) => {
    _ === null || !e || !p.touches || !p.touches[0] || Math.abs(p.touches[0].clientY - _) > 8 && (g(), _ = null);
  }, { passive: true, capture: true, signal: t }), u(n, "touchend", () => {
    _ = null;
  }, { passive: true, capture: true, signal: t }), u(n, "touchcancel", () => {
    _ = null;
  }, { passive: true, capture: true, signal: t }), u(l, "wheel", (p) => {
    e && Math.abs(p.deltaY) > 2 && !K(p.target, ".dsb8-cart-list") && g();
  }, { passive: true, capture: true, signal: t }), l.visualViewport && u(l.visualViewport, "scroll", () => {
    let p = l.visualViewport.pageTop || 0;
    e && Math.abs(p - r) > 6 && g(), r = p;
  }, { passive: true, signal: t }), w(), it("wc_fragments_refreshed wc_fragments_loaded added_to_cart removed_from_cart", () => l.setTimeout(w, 0), t);
  let dbvChanged = (Ev) => {
    if (Ev && Ev.detail && Ev.detail.source === "header-cart") return;
    for (let P of W("[data-dsb8-cart-root].is-open")) dbvRefresh(P);
  };
  u(n, "delicat:cart-changed", dbvChanged, { signal: t }), u(n, "delicat:express-added", dbvChanged, { signal: t }), u(n, "wc-blocks_added_to_cart", dbvChanged, { signal: t }), it("added_to_cart", () => dbvChanged(), t);
  let R = n.querySelector("[data-dsb8-header]");
  if (R && l.MutationObserver) {
    let p = 0, o = new MutationObserver((L) => {
      for (let E of L) if (!(!E.addedNodes.length && !E.removedNodes.length)) {
        l.clearTimeout(p), p = l.setTimeout(w, 60);
        return;
      }
    });
    o.observe(R, { childList: true, subtree: true }), t.addEventListener("abort", () => o.disconnect(), { once: true });
  }
  let q = () => {
    for (let p of W("[data-dsb8-cart-root].is-open")) {
      p.classList.remove("is-open");
      let o = p.querySelector("[data-dsb8-cart-trigger]");
      o && o.setAttribute("aria-expanded", "false");
    }
    for (let p of W(".dsb541-notifications.is-open")) p.classList.remove("is-open");
  };
  u(n, "dsb8:overlay:open", (p) => {
    if (!p.detail || p.detail.id !== "modern-menu") return;
    q();
    let o = n.querySelector(".dsb-menu-toggle,[data-dsb-menu-toggle],.dsb-modern-menu-toggle");
    o && !o.matches("[data-dsb8-menu-trigger]") && !n.querySelector("[data-dlx-drawer]") && o.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, view: l }));
  }, { signal: t }), u(n, "click", (p) => {
    K(p.target, "[data-dsb8-menu-trigger]") && q();
  }, { capture: true, signal: t }), u(n, "delicat:navigate", () => g(), { signal: t });
}
function $a({ signal: t }) {
  let e = n.querySelector("[data-dlx-drawer]");
  if (!e) return;
  let a = e.querySelector("[data-dlx-panel]"), r = e.querySelector("[data-dlx-scroll]"), i = e.querySelector("[data-dlx-search]"), d = e.querySelector("[data-dlx-empty]"), s = false, c = null, f = 0, g = "", m = 0, b = 0, y = null, v = 0, C = 0, I = [];
  D.classList.add("dlx-ready");
  let T = (S) => {
    if (S) {
      if (I.length) return;
      for (let N of Array.from(n.body.children)) N === e || N.contains(e) || /^(SCRIPT|STYLE|LINK)$/.test(N.tagName) || (I.push({ node: N, inert: N.inert, aria: N.getAttribute("aria-hidden") }), N.inert = true, N.setAttribute("aria-hidden", "true"));
      return;
    }
    for (let N of I) N.node.inert = N.inert, N.aria === null ? N.node.removeAttribute("aria-hidden") : N.node.setAttribute("aria-hidden", N.aria);
    I = [];
  }, x = (S) => {
    for (let N of W("[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle")) N.setAttribute("aria-expanded", S ? "true" : "false"), N.setAttribute("aria-controls", e.id || "delicat-drawer");
  }, w = () => {
    f = l.pageYOffset || 0, g = n.body.style.top, D.classList.add("dlx-open"), n.body.style.top = -f + "px";
  }, A = () => {
    D.classList.remove("dlx-open"), n.body.style.top = g, l.scrollTo({ top: f, left: 0, behavior: "instant" });
  }, _ = () => {
    s || (e.classList.add("is-warm"), e.style.visibility = "hidden", e.style.pointerEvents = "none", e.hidden = false, e.setAttribute("aria-hidden", "true"));
  }, R = () => {
    e.classList.remove("is-warm"), e.style.visibility = "", e.style.pointerEvents = "";
  }, q = () => {
    b && (l.clearTimeout(b), b = 0), a && a.removeEventListener("transitionend", p), e.classList.remove("is-entering"), s && ee.refresh("drawer-open");
  };
  function p(S) {
    S.target === a && S.propertyName === "transform" && q();
  }
  let o = () => {
    m && (l.clearTimeout(m), m = 0), a && a.removeEventListener("transitionend", L), s || _();
  };
  function L(S) {
    S.target === a && S.propertyName === "transform" && o();
  }
  let E = () => {
    s || (s = true, x(true), c = n.activeElement, m && (l.clearTimeout(m), m = 0), a && a.removeEventListener("transitionend", L), e.hidden = false, R(), e.setAttribute("aria-hidden", "false"), e.classList.add("is-entering"), w(), r && (r.scrollTop = 0), U(() => {
      s && (e.classList.add("is-open"), b && l.clearTimeout(b), b = l.setTimeout(q, 240), a && a.addEventListener("transitionend", p), U(() => {
        s && (T(true), kt(a || e));
      }));
    }), G("dlx:open"));
  }, M = (S) => {
    s && (s = false, x(false), y = null, q(), e.classList.remove("is-open", "is-dragging"), a && (a.style.transform = ""), T(false), A(), m && l.clearTimeout(m), m = l.setTimeout(o, 240), a && a.addEventListener("transitionend", L), S !== false && c && kt(c), G("dlx:close"));
  }, H = (S) => {
    let N = String(S || "").trim().toLowerCase(), j = 0;
    for (let V of W(".dlx-item", e)) {
      let h = !N || (V.getAttribute("data-dlx-filter") || "").indexOf(N) !== -1;
      V.parentNode.hidden = !h, h && j++;
    }
    for (let V of W("[data-dlx-section]", e)) {
      let h = V.querySelectorAll("li:not([hidden]) > .dlx-item").length;
      V.hidden = !!N && !h, N && V.classList.remove("is-collapsed");
    }
    e.classList.toggle("is-filtering", !!N), d && (d.hidden = !N || j > 0);
  }, X = (S) => {
    if (!y) return;
    let N = y;
    if (y = null, e.classList.remove("is-dragging"), N.on && (C = Date.now()), S && N.on && a && Math.abs(N.dx) > Math.min(110, a.offsetWidth * 0.3)) {
      M();
      return;
    }
    a && (a.style.transform = "");
  }, me = (S) => {
    if (!(!s || S.pointerType === "mouse" || S.button || S.isPrimary === false) && !K(S.target, "input,textarea,select")) {
      y = { x: S.clientX, y: S.clientY, dx: 0, on: false, id: S.pointerId, left: e.classList.contains("is-left"), frame: false };
      try {
        a.setPointerCapture && a.setPointerCapture(S.pointerId);
      } catch {
      }
    }
  }, le = (S) => {
    if (!y || S.pointerId !== y.id) return;
    let N = S.clientX - y.x, j = S.clientY - y.y;
    if (!y.on && Math.abs(j) > 10 && Math.abs(j) > Math.abs(N)) {
      X(false);
      return;
    }
    if (!y.on) {
      if (Math.abs(N) < 10 || Math.abs(N) < Math.abs(j)) return;
      y.on = true, e.classList.add("is-dragging");
    }
    if (y.dx = y.left ? Math.min(0, N) : Math.max(0, N), !y.frame) {
      let V = y;
      y.frame = true, U(() => {
        V.frame = false, y === V && s && (a.style.transform = "translateX(" + V.dx + "px)");
      });
    }
    S.cancelable && S.preventDefault();
  }, se = (S) => {
    if (!(y && S && S.pointerId !== y.id) && (X(true), S && S.pointerId !== void 0)) try {
      a.hasPointerCapture && a.hasPointerCapture(S.pointerId) && a.releasePointerCapture(S.pointerId);
    } catch {
    }
  }, Y = (S) => {
    let N = S.getAttribute("data-dlx-copy") || "", j = S.querySelector("[data-dlx-copy-label]"), V = () => {
      if (!j) return;
      let h = j.textContent;
      j.textContent = "Copi\xE9 \u2713", S.classList.add("is-done"), l.setTimeout(() => {
        j.textContent = h, S.classList.remove("is-done");
      }, 1400);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(N).then(V, V);
    else {
      let h = n.createElement("textarea");
      h.value = N, h.setAttribute("readonly", ""), h.style.position = "fixed", h.style.opacity = "0", n.body.appendChild(h), h.select();
      try {
        n.execCommand("copy");
      } catch {
      }
      h.remove(), V();
    }
  };
  u(n, "pointerdown", (S) => {
    !K(S.target, "[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle") || s || S.button || S.isPrimary === false || (v = Date.now(), S.preventDefault(), E());
  }, { capture: true, signal: t }), u(n, "click", (S) => {
    let N = S.target;
    if (!N || N.nodeType !== 1) return;
    if (v) {
      let P = Date.now() - v;
      if (v = 0, P < 700) {
        S.preventDefault(), S.stopImmediatePropagation();
        return;
      }
    }
    if (C) {
      let P = Date.now() - C;
      if (C = 0, P < 700) {
        S.preventDefault(), S.stopImmediatePropagation();
        return;
      }
    }
    if (K(N, "[data-dsb8-menu-trigger],[data-dlx-open],.dsb-menu-toggle")) {
      S.preventDefault(), S.stopImmediatePropagation(), s ? M() : E();
      return;
    }
    if (!s) return;
    if (K(N, "[data-dlx-close]")) {
      S.preventDefault(), M();
      return;
    }
    let j = K(N, "[data-dlx-toggle]");
    if (j && e.contains(j)) {
      let P = K(j, "[data-dlx-section]");
      if (P) {
        let $ = P.classList.toggle("is-collapsed");
        j.setAttribute("aria-expanded", $ ? "false" : "true");
      }
      return;
    }
    let V = K(N, "[data-dlx-copy]");
    if (V && e.contains(V)) {
      S.preventDefault(), Y(V);
      return;
    }
    let h = K(N, "[data-dlx-install]");
    if (h && e.contains(h)) {
      S.preventDefault(), M(), G("delicat:install-app");
      return;
    }
    let k = K(N, "a[href]");
    k && e.contains(k) && !k.target && (k.getAttribute("href") || "").charAt(0) !== "#" && M();
  }, { capture: true, signal: t }), u(n, "dsb:close-menu", () => M(false), { signal: t }), u(n, "delicat:navigate", () => M(false), { signal: t }), u(l, "resize", () => X(false), { passive: true, signal: t }), u(n, "dsb8:overlay:open", (S) => {
    S.detail && S.detail.id === "modern-menu" && (S.stopImmediatePropagation(), E());
  }, { capture: true, signal: t }), u(n, "keydown", (S) => {
    if (s) {
      if (S.key === "Escape") {
        S.preventDefault(), M();
        return;
      }
      if (S.key === "Tab") {
        let N = ma(a || e);
        if (!N.length) return;
        let j = N[0], V = N[N.length - 1];
        S.shiftKey && n.activeElement === j ? (S.preventDefault(), V.focus()) : !S.shiftKey && n.activeElement === V && (S.preventDefault(), j.focus());
      }
    }
  }, { signal: t }), i && (u(i, "input", () => H(i.value), { signal: t }), u(i, "keydown", (S) => {
    S.key === "Enter" && !i.value.trim() && S.preventDefault();
  }, { signal: t })), l.PointerEvent && a && (u(a, "pointerdown", me, { passive: true, signal: t }), u(a, "pointermove", le, { passive: false, signal: t }), u(a, "pointerup", se, { passive: true, signal: t }), u(a, "pointercancel", () => X(false), { passive: true, signal: t }), u(a, "lostpointercapture", () => X(false), { passive: true, signal: t })), u(l, "pageshow", (S) => {
    S.persisted && s && M();
  }, { signal: t }), l.requestIdleCallback ? l.requestIdleCallback(_, { timeout: 2500 }) : l.setTimeout(_, 900), l.DelicatDrawer = { open: E, close: M, isOpen: () => s };
}
var ht = (t) => String(t || "/").replace(/\/+$/, "") || "/";
function Ba(t, e) {
  let a = ht(t.pathname).toLowerCase();
  for (let s of e) {
    let c = et(s.href);
    if (c && ht(c.pathname).toLowerCase() === a && c.search === t.search) return s.getAttribute("data-dbn-key") || "";
  }
  let r = e.find((s) => s.getAttribute("data-dbn-key") === "shop"), i = r ? et(r.href) : null, d = i ? ht(i.pathname).toLowerCase() : "/shop";
  return d !== "/" && a.indexOf(d + "/") === 0 || /\/(?:product|produit|product-category|categorie-produit|product-tag|etiquette-produit)(?:\/|$)/.test(a) || t.searchParams.has("s") && t.searchParams.get("post_type") === "product" ? "shop" : /\/(?:my-wallet|woo-wallet|wallet|portefeuille)(?:\/|$)/.test(a) ? "wallet" : /\/(?:cart|panier)(?:\/|$)/.test(a) ? "cart" : /\/(?:my-account|mon-compte)(?:\/|$)/.test(a) ? "account" : a === "/" ? "home" : "";
}
function Wa(t) {
  O.reducedMotion || !t || (t.classList.remove("is-popping"), t.offsetWidth, t.classList.add("is-popping"), l.setTimeout(() => t.classList.remove("is-popping"), 420));
}
function ze() {
  let t = W(".delicat-bottom-nav .dbn-item[data-dbn-key]");
  if (!t.length) return;
  let e = et(location.href), a = e ? Ba(e, t) : "";
  for (let r of t) {
    let i = a ? r.getAttribute("data-dbn-key") === a : r.classList.contains("is-active");
    i && !r.classList.contains("is-active") && Wa(r), r.classList.toggle("is-active", i), i ? r.setAttribute("aria-current", "page") : r.removeAttribute("aria-current");
  }
}
var Ra = ".delicat-product-card, .delicat-category-tile, .delicat-category-chip, .dsb8-icon-menu__item, .dnp-related-card";
function ja(t) {
  if (typeof Element == "undefined" || typeof Element.prototype.animate != "function") return;
  let e = null, a = 0, r = null, i = 0, d = 0, s = () => O.reducedMotion || D.classList.contains("delicat-low-power"), c = () => {
    if (a = 0, !(!e || !e.isConnected || r)) try {
      r = e.animate([{ transform: "none" }, { transform: "scale(0.97)" }], { duration: 110, easing: "cubic-bezier(0.22, 1, 0.36, 1)", fill: "forwards" });
    } catch {
      r = null;
    }
  }, f = () => {
    let m = e, b = r;
    if (e = null, r = null, a && (l.clearTimeout(a), a = 0), !(!m || !b)) try {
      let y = m.animate([{ transform: "scale(0.97)" }, { transform: "none" }], { duration: 280, easing: "cubic-bezier(0.34, 1.56, 0.64, 1)" });
      b.cancel(), y.onfinish = y.oncancel = () => y.cancel();
    } catch {
      b.cancel();
    }
  }, g = () => {
    a && (l.clearTimeout(a), a = 0), r ? f() : e = null;
  };
  u(n, "pointerdown", (m) => {
    if (s() || m.pointerType === "mouse" && m.button !== 0) return;
    let b = m.target instanceof Element ? m.target : null, y = b && b.closest(Ra);
    !y || b !== y && b.closest("[data-delicat-like], input, select, textarea, label") || (g(), e = y, i = m.clientX, d = m.clientY, a = l.setTimeout(c, 70));
  }, { passive: true, signal: t }), u(n, "pointermove", (m) => {
    e && (Math.abs(m.clientX - i) > 8 || Math.abs(m.clientY - d) > 8) && g();
  }, { passive: true, signal: t }), u(n, "pointerup", () => {
    e && (a ? (l.clearTimeout(a), a = 0, c(), l.setTimeout(f, 90)) : f());
  }, { passive: true, signal: t });
  for (let m of ["pointercancel", "dragstart"]) u(n, m, g, { passive: true, signal: t });
  u(n, "scroll", (m) => {
    let b = m.target;
    e && (b === n || b === D || b instanceof Node && b.contains(e)) && g();
  }, { passive: true, capture: true, signal: t }), u(l, "blur", g, { signal: t }), u(n, "delicat:navigated", g, { signal: t });
}
var Ht = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=reset]):not([type=range]):not([type=file]):not([type=color]):not([type=hidden]),textarea,[contenteditable="true"],[contenteditable=""]';
function za(t) {
  if (!(l.matchMedia && l.matchMedia("(hover: none) and (pointer: coarse)").matches)) return;
  let e = l.visualViewport, a = () => Math.min(l.innerHeight || 0, e ? e.height : 1e5), r = a(), i = 0, d = 0, s = () => {
    let f = n.activeElement;
    return f && f !== n.body && f.matches && f.matches(Ht) ? f : null;
  }, c = () => {
    let f = s(), g = a();
    if (!f) {
      r = g, D.classList.remove("dlc-kb");
      return;
    }
    D.classList.toggle("dlc-kb", r - g > 140);
  };
  e && u(e, "resize", c, { passive: true, signal: t }), u(l, "resize", c, { passive: true, signal: t }), u(n, "focusin", (f) => {
    let g = f.target;
    !(g instanceof Element) || !g.matches(Ht) || (l.clearTimeout(i), l.clearTimeout(d), l.setTimeout(c, 300), d = l.setTimeout(() => {
      if (n.activeElement !== g || !g.getBoundingClientRect) return;
      let m = g.getBoundingClientRect(), b = e ? e.height : l.innerHeight;
      if (m.bottom > b - 12 || m.top < 0) try {
        g.scrollIntoView({ block: "center", behavior: "smooth" });
      } catch {
        g.scrollIntoView(false);
      }
    }, 350));
  }, { signal: t }), u(n, "focusout", () => (l.clearTimeout(i), i = l.setTimeout(c, 120)), { signal: t }), u(n, "delicat:navigated", () => D.classList.remove("dlc-kb"), { signal: t });
}
var Va = "[data-dlx-close],.dlx-drawer__close,.dlx-drawer__backdrop,[data-dsb8-cart-close],.dsb8-cart-panel__close,.dsb541-close,.dsb-menu-close,[data-close],[data-dismiss],[data-dnp-express-close],[data-dpn-wallet-close],[aria-label^='Fermer'],[aria-label^='Close']";
function Ha(t) {
  let e = l.history, a = l.CloseWatcher, r = D.classList.contains("delicat-app"), i = r && e && typeof e.pushState == "function", d = !i && typeof a == "function";
  if (!i && !d) return;
  let s = false, c = null, f = false, g = "", m = 0, b = 0, y = 0, v = 0, C = () => !!(e.state && e.state.dlcOverlay), I = () => {
    if (!Ze.anyOpen()) return;
    let w = n.activeElement && n.activeElement !== n.body ? n.activeElement : n;
    try {
      w.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", code: "Escape", keyCode: 27, which: 27, bubbles: true, cancelable: true }));
    } catch {
    }
  }, T = () => {
    let w = Ze.anyOpen();
    if (w !== s) {
      if (s = w, d) {
        if (w) try {
          c = new a(), c.onclose = () => (c = null, s = false, I(), x());
        } catch {
          c = null;
        }
        else if (c) {
          let A = c;
          c = null;
          try {
            A.destroy();
          } catch {
          }
        }
        return;
      }
      if (w && !f) try {
        e.pushState(Object.assign({}, e.state || {}, { dlcOverlay: 1 }), "", l.location.href), f = true, g = l.location.href;
      } catch {
      }
      else !w && f && (f = false, Date.now() - b < 900 && Date.now() - m > 1500 && C() && l.location.href === g && e.back());
    }
  }, x = () => {
    l.clearTimeout(y), l.clearTimeout(v), y = l.setTimeout(T, 60), v = l.setTimeout(T, 450);
  };
  u(l, "click", (w) => {
    let A = w.target instanceof Element ? w.target : null;
    A && A.closest("a[href],button[type=submit],input[type=submit],form button:not([type=button])") && (m = Date.now()), A && !A.closest("a[href]") && (A.closest(Va) || /backdrop|overlay|scrim/.test(String(A.className || ""))) && (b = Date.now()), x();
  }, { passive: true, capture: true, signal: t }), u(l, "keyup", (w) => (w.key === "Escape" && (b = Date.now()), x()), { passive: true, capture: true, signal: t }), u(l, "pointerup", x, { passive: true, capture: true, signal: t }), u(n, "delicat:navigate", () => m = Date.now(), { signal: t }), i && u(l, "beforeunload", () => m = Date.now(), { signal: t }), u(n, "delicat:navigated", () => (f = false, l.setTimeout(T, 0)), { signal: t }), i && u(l, "popstate", () => {
    !f || C() || (f = false, s = false, I());
  }, { signal: t }), t.addEventListener("abort", () => {
    if (c) try {
      c.destroy();
    } catch {
    }
  }, { once: true });
}
function Ya(t) {
  let e = null, a = (r) => {
    r ? (e || (e = n.createElement("div"), e.className = "dlc-offline", e.setAttribute("role", "status"), e.textContent = "Hors ligne : les prix et le stock peuvent ne pas \xEAtre \xE0 jour."), e.isConnected || n.body.appendChild(e)) : e && e.remove();
  };
  navigator.onLine === false && a(true), u(l, "offline", () => a(true), { signal: t }), u(l, "online", () => a(false), { signal: t }), u(n, "delicat:navigated", () => a(navigator.onLine === false), { signal: t });
}
var Yt = "[data-dsb8-cart-count],[data-dbn-cart-count],[data-dbp-cart-count],[data-delicat-cart-count]";
function Fa(t) {
  let e = -1, a = 0;
  u(n, "pointerdown", () => a = Date.now(), { passive: true, capture: true, signal: t });
  for (let r of W(Yt)) {
    let i = parseInt(r.textContent, 10);
    e = Math.max(e, isFinite(i) ? i : 0);
  }
  u(n, "delicat:cart-count", (r) => {
    let i = parseInt(r.detail && r.detail.count, 10);
    if (!isFinite(i)) return;
    let d = e >= 0 && i > e;
    if (d && Date.now() - a < 8e3 && typeof navigator.vibrate == "function" && !n.hidden) try {
      navigator.vibrate(12);
    } catch {
    }
    if (d && Date.now() - a < 8e3) {
      let s = n.getElementById("dbp-live");
      s || (s = n.createElement("div"), s.id = "dbp-live", s.className = "dbp-sr-only dlc-sr-only", s.setAttribute("aria-live", "polite"), s.setAttribute("role", "status"), n.body.appendChild(s)), s.textContent = "Ajout\xE9 au panier : " + i + (i > 1 ? " articles." : " article.");
    }
    if (e = i, !(!d || O.reducedMotion)) for (let s of W(Yt)) s.classList.remove("is-bumping"), s.offsetWidth, s.classList.add("is-bumping"), l.setTimeout(() => s.classList.remove("is-bumping"), 450);
  }, { signal: t });
}
function Ua({ signal: t }) {
  let e = () => {
    let d = _t();
    d !== null && he(d);
  };
  e(), u(l, "pageshow", e, { passive: true, signal: t }), u(n, "wc-blocks_added_to_cart", () => l.setTimeout(e, 0), { signal: t }), u(n, "wc-blocks_removed_from_cart", () => l.setTimeout(e, 0), { signal: t }), u(n, "pointerdown", (d) => {
    let s = d.target instanceof Element ? d.target.closest(".delicat-bottom-nav .dbn-item, [data-delicat-mobile-dock] a") : null;
    if (!s) return;
    s.classList.add("is-pressed");
    let c = () => s.classList.remove("is-pressed");
    l.setTimeout(c, 420), u(n, "pointerup", c, { once: true, passive: true }), u(n, "pointercancel", c, { once: true, passive: true });
  }, { passive: true, signal: t }), ze(), u(n, "delicat:navigated", ze, { signal: t }), u(n, "delicat:placeholder", ze, { signal: t }), u(l, "popstate", () => l.setTimeout(ze, 0), { signal: t }), ja(t), Fa(t), za(t), Ha(t), Ya(t);
  let a = () => {
    let d = n.body;
    d.classList.contains("dnp-express-open") && !n.querySelector("[data-dnp-express].is-open") && d.classList.remove("dnp-express-open", "dnp-express-busy"), d.classList.contains("dpn-wallet-modal-open") && !n.querySelector("[data-dpn-wallet-modal]:not([hidden])") && d.classList.remove("dpn-wallet-modal-open"), d.classList.contains("dpn-checkout-dock-ready") && !d.classList.contains("dpn-checkout") && d.classList.remove("dpn-checkout-dock-ready"), D.classList.contains("dlx-open") && !n.querySelector(".dlx-drawer.is-open,.dlx-drawer.is-entering") && (D.classList.remove("dlx-open"), d.style.top = ""), D.classList.contains("dsb-menu-open") && !n.querySelector(".dlx-drawer.is-open") && D.classList.remove("dsb-menu-open"), d.classList.remove("dbn-hidden");
  }, r = 0, i = () => {
    l.clearTimeout(r), r = l.setTimeout(a, 400);
  };
  u(n, "delicat:navigated", i, { signal: t }), u(l, "pageshow", i, { passive: true, signal: t }), u(n, "visibilitychange", () => {
    n.hidden || i();
  }, { signal: t });
}
var Xa = "(max-width:960px) 41vw, 252px", Ka = "M12 20.6C11.4 20.2 3 14.9 3 9.1 3 6.4 5 4.4 7.5 4.4c1.9 0 3.6 1.1 4.5 2.7.9-1.6 2.6-2.7 4.5-2.7 2.5 0 4.5 2 4.5 4.7 0 5.8-8.4 11.1-9 11.5Z", Za = "M7.2 18.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Zm9.2 0a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6ZM6.1 5.3l.5 2.1h11.7l-1.3 5.4a1.4 1.4 0 0 1-1.4 1.1H8.2a1.4 1.4 0 0 1-1.4-1.1L4.9 4.9H2.8a1 1 0 1 1 0-2h2.9l.4 2.4Zm1 4.1.6 2.5h7.5l.6-2.5H7.1Z", Ee = "http://www.w3.org/2000/svg", Ve = (t, e) => String(t || "").toLowerCase().replace(/[^a-z0-9_-]/g, "") || e, Qa = (t) => {
  if (typeof t != "string" || !t) return "";
  let e = [];
  for (let a of t.split(",")) {
    let r = a.trim().match(/^(\S+)\s+(\d{1,4})w$/);
    if (!r) return "";
    let i = tt(r[1], false);
    if (!i) return "";
    e.push(i + " " + Number(r[2]) + "w");
  }
  return e.length > 1 ? e.join(", ") : "";
}, Ja = () => l.DelicatBadgeIcons || {};
function Ft(t, e) {
  if (t.textContent = "", !e || !e.t && !e.ic) return;
  let a = Ja()[String(e.ic || "")];
  if (a) {
    let r = n.createElementNS(Ee, "svg");
    for (let [i, d] of Object.entries({ class: "delicat-badge__icon", viewBox: "0 0 24 24", width: "14", height: "14", fill: "none", stroke: "currentColor", "stroke-width": "1.75", "stroke-linecap": "round", "stroke-linejoin": "round", "aria-hidden": "true", focusable: "false" })) r.setAttribute(i, d);
    r.innerHTML = a, t.appendChild(r);
  }
  if (e.t) {
    let r = n.createElement("span");
    r.className = "delicat-badge__text", r.textContent = String(e.t), t.appendChild(r);
  }
}
function Ga(t, e) {
  let a = l.DelicaBuilderV9 && l.DelicaBuilderV9.heart || {}, r = typeof a.path == "string" && /^[MmCcLlHhVvSsZz0-9.\s-]+$/.test(a.path) ? a.path : Ka, i = n.createElement("button");
  i.type = "button", i.className = "delicat-like delicat-product-card__like", i.dataset.delicatLike = "", i.dataset.productId = String(t), i.setAttribute("aria-pressed", e ? "true" : "false"), i.setAttribute("aria-label", String(e ? a.removeLabel || "Retirer des favoris" : a.addLabel || "Ajouter aux favoris"));
  let d = n.createElement("span");
  d.className = "delicat-like__chip", d.setAttribute("aria-hidden", "true");
  for (let s of ["delicat-like__outline", "delicat-like__fill"]) {
    let c = n.createElementNS(Ee, "svg");
    c.setAttribute("class", s), c.setAttribute("viewBox", "0 0 24 24"), c.setAttribute("width", "18"), c.setAttribute("height", "18"), c.setAttribute("focusable", "false");
    let f = n.createElementNS(Ee, "path");
    f.setAttribute("d", r), c.appendChild(f), d.appendChild(c);
  }
  return i.appendChild(d), i;
}
function er(t) {
  let e = n.createElementNS(Ee, "svg");
  e.setAttribute("class", "delicat-product-card__cta-svg"), e.setAttribute("viewBox", "0 0 24 24"), e.setAttribute("aria-hidden", "true"), e.setAttribute("focusable", "false");
  let a = n.createElementNS(Ee, "path");
  a.setAttribute("fill", "currentColor"), a.setAttribute("d", Za), e.appendChild(a), t.appendChild(e);
}
function tr(t, e, a) {
  if (!e || !e.p || !e.p.n) return;
  let r = n.createElement("span");
  r.className = e.sub ? "delicat-product-card__poster-price" : "delicat-product-card__price";
  let i = n.createElement("span");
  i.textContent = a, r.appendChild(i);
  let d = n.createElement("strong"), s = n.createElement("span");
  s.className = "delicat-price-symbol", s.textContent = String(e.p.s || "");
  let c = n.createElement("span");
  c.className = "delicat-price-number", c.textContent = String(e.p.n || ""), d.append(s, c), r.appendChild(d), t.appendChild(r);
}
function Ut(t, e) {
  if (!e || !Number.isInteger(Number(e.id))) return null;
  let a = Number(e.id), r = tt(e.u, true);
  if (!r) return null;
  let i = n.createElement("article");
  i.className = "delicat-product-card", i.setAttribute("role", "listitem"), i.dataset.delicatCard = "", i.dataset.productId = String(a), i.dataset.delicatCardIndex = String(Number(e.i || 0)), i.dataset.delicatCardStyle = String(t.dataset.delicatStyle || "delicat_jeux");
  let d = n.createElement("div");
  d.className = "delicat-product-card__media-wrap";
  let s = n.createElement("a");
  if (s.className = "delicat-product-card__media", s.href = r, s.dataset.delicatPrefetch = "", s.dataset.delicatProductLink = "", e.b && e.b.t) {
    let m = n.createElement("span");
    m.className = `delicat-badge delicat-badge--topic delicat-badge--${Ve(e.b.o, "topic")} delicat-badge--${Ve(e.b.k, "topic")}`, Ft(m, e.b), s.appendChild(m);
  }
  if (e.im && e.im.u) {
    let m = tt(e.im.u, false);
    if (m) {
      let b = n.createElement("img");
      b.className = "delicat-product-card__image", b.alt = at(e.n), b.loading = "lazy", b.decoding = "async", b.fetchPriority = "low", b.dataset.delicatCardImage = "1", Number(e.im.w) > 0 && (b.width = Number(e.im.w)), Number(e.im.h) > 0 && (b.height = Number(e.im.h));
      let y = Qa(e.im.ss);
      y && (b.sizes = Xa, b.srcset = y), b.src = m, s.appendChild(b);
    }
  }
  if (e.st && e.st.t) {
    let m = n.createElement("span");
    m.className = `delicat-badge delicat-badge--status delicat-badge--${Ve(e.st.o, "status")} delicat-badge--${Ve(e.st.k, "status")}`, Ft(m, e.st), s.appendChild(m);
  }
  d.appendChild(s), Number(e.he) === 1 && d.appendChild(Ga(a, Number(e.hf) === 1)), i.appendChild(d);
  let c = n.createElement("div");
  c.className = "delicat-product-card__body";
  let f = t.dataset.delicatManagedHomepage === "1", g = n.createElement(f ? "span" : "a");
  if (g.className = f ? "delicat-product-card__poster-title" : "delicat-product-card__name", f || (g.href = r, g.dataset.delicatPrefetch = "", g.dataset.delicatProductLink = ""), g.textContent = at(e.n), (f ? s : c).appendChild(g), tr(c, e, String(t.dataset.delicatStartingLabel || "\xC0 partir de")), Number(e.cta) === 1) {
    let m = n.createElement("a");
    m.className = "delicat-product-card__cta", m.href = r, m.dataset.delicatPrefetch = "", m.dataset.delicatProductLink = "", m.dataset.delicatInstantProduct = "", er(m);
    let b = n.createElement("span");
    b.textContent = String(t.dataset.delicatBuyLabel || "Acheter"), f || m.appendChild(b), m.setAttribute("aria-label", "Voir les options de " + at(e.n)), c.appendChild(m);
  }
  return i.appendChild(c), i;
}
var Xt = /* @__PURE__ */ new WeakMap();
function xe(t) {
  let e = Xt.get(t);
  return e || (e = { items: null, cursor: 0, metrics: null, dots: [], building: false, done: false }, Xt.set(t, e), e);
}
function Kt(t, e) {
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
function Zt(t, e) {
  let a = t.querySelector("template[data-delicat-carousel-deferred]");
  if (a && a.content && a.content.children.length) return true;
  let r = Kt(t, e);
  return e.cursor < r.length;
}
function Qt(t, e, a) {
  let r = t.querySelector("[data-delicat-track]");
  if (!r) return 0;
  let i = n.createDocumentFragment(), d = 0, s = Kt(t, e);
  for (; d < a && e.cursor < s.length; ) {
    let c = Ut(t, s[e.cursor++]);
    c && (i.appendChild(c), d++);
  }
  if (d < a) {
    let c = t.querySelector("template[data-delicat-carousel-deferred]");
    if (c && c.content) {
      for (; d < a && c.content.firstElementChild; ) i.appendChild(c.content.firstElementChild), d++;
      c.content.children.length || c.remove();
    }
  }
  return d ? (r.appendChild(i), e.metrics = null, G("delicat:carousel-materialized", { carousel: t }, t), d) : 0;
}
function ke(t, e) {
  if (e.building || e.done) return;
  if (!Zt(t, e)) {
    e.done = true;
    return;
  }
  e.building = true;
  let a = O.lowPower ? 3 : 6, r = () => {
    if (!t.isConnected) {
      e.building = false;
      return;
    }
    Qt(t, e, a), Zt(t, e) ? U(r) : (e.building = false, e.done = true);
  };
  U(r);
}
function He(t, e, a) {
  if (e.metrics) return e.metrics;
  let r = a.querySelector("[data-delicat-card]");
  if (!r) return { cardStep: Math.max(1, a.clientWidth), visible: 1, total: 1, mode: "pages" };
  let i = getComputedStyle(a), d = parseFloat(i.columnGap || i.gap || "0") || 0, s = Math.max(1, r.offsetWidth + d), c = Math.max(1, Math.floor((a.clientWidth + d) / s)), f = Math.max(1, Number(t.dataset.delicatTotal || a.querySelectorAll("[data-delicat-card]").length || 1)), g = t.dataset.delicatPaginationMode === "cards" ? "cards" : "pages";
  return e.metrics = { cardStep: s, visible: c, total: f, mode: g }, e.metrics;
}
var Ye = null, bt = /* @__PURE__ */ new WeakMap();
function ar(t, e) {
  if (t.dataset.delicatReady === "1") return;
  t.dataset.delicatReady = "1";
  let a = t.querySelector("[data-delicat-track]");
  if (!a) return;
  let r = xe(t), i = t.querySelector("[data-delicat-prev]"), d = t.querySelector("[data-delicat-next]"), s = t.querySelector("[data-delicat-pagination]"), c = O.reducedMotion ? "auto" : "smooth", f = () => {
    t.dataset.delicatActive !== "1" && (t.dataset.delicatActive = "1", t.classList.add("is-active"), ke(t, r));
  }, g = () => {
    let x = He(t, r, a), w = a.scrollLeft >= a.scrollWidth - a.clientWidth - 2;
    if (x.mode === "cards") {
      let A = Math.max(0, x.total - x.visible);
      return w ? A : Math.max(0, Math.min(A, Math.round(a.scrollLeft / x.cardStep)));
    }
    return w && r.dots.length ? r.dots.length - 1 : Math.max(0, Math.round(a.scrollLeft / Math.max(1, a.clientWidth)));
  }, m = () => {
    let x = He(t, r, a);
    return x.mode === "cards" ? Math.max(1, x.total - x.visible + 1) : Math.max(1, Math.ceil(Math.max(1, x.cardStep * x.total) / Math.max(1, a.clientWidth)));
  }, b = () => {
    if (!r.dots.length) return;
    let x = Math.max(0, Math.min(r.dots.length - 1, g()));
    r.dots.forEach((w, A) => {
      let _ = A === x;
      w.classList.toggle("is-active", _), w.setAttribute("aria-pressed", _ ? "true" : "false");
    });
  }, y = () => {
    if (!s) return;
    let x = m();
    if (r.dots.length === x) {
      b();
      return;
    }
    s.replaceChildren(), r.dots = Array.from({ length: x }, (w, A) => {
      let _ = n.createElement("button");
      return _.type = "button", _.className = "delicat-carousel__dot", _.dataset.delicatDot = String(A), _.setAttribute("aria-label", `Aller \xE0 la diapositive ${A + 1}`), u(_, "click", () => {
        f(), U(() => {
          let R = He(t, r, a);
          a.scrollTo({ left: R.mode === "cards" ? R.cardStep * A : a.clientWidth * A, behavior: c });
        });
      }, { signal: e }), s.appendChild(_), _;
    }), b();
  }, v = (x) => {
    let w = He(t, r, a);
    return x * (w.mode === "cards" ? w.cardStep : Math.max(180, Math.round(a.clientWidth * 0.82)));
  };
  i && u(i, "click", () => {
    f(), a.scrollBy({ left: v(-1), behavior: c });
  }, { signal: e }), d && u(d, "click", () => {
    f(), a.scrollBy({ left: v(1), behavior: c });
  }, { signal: e });
  let C = false;
  u(a, "scroll", () => {
    C || (C = true, f(), U(() => {
      C = false, b();
    }));
  }, { passive: true, signal: e });
  let I = (x) => {
    if (x && x.target && x.target.closest && x.target.closest("a[href],button,input,select,textarea,label")) {
      f();
      return;
    }
    f();
  };
  u(a, "PointerEvent" in l ? "pointerdown" : "touchstart", I, { passive: true, signal: e }), u(a, "focusin", I, { signal: e }), u(a, "keydown", (x) => {
    x.key === "ArrowRight" ? (x.preventDefault(), d ? d.click() : a.scrollBy({ left: v(1), behavior: c })) : x.key === "ArrowLeft" && (x.preventDefault(), i ? i.click() : a.scrollBy({ left: v(-1), behavior: c }));
  }, { signal: e }), y();
  let T = () => {
    r.metrics = null, U(y);
  };
  if ("ResizeObserver" in l ? (Ye || (Ye = new ResizeObserver((x) => {
    for (let w of x) {
      let A = bt.get(w.target);
      A && A();
    }
  })), bt.set(a, T), Ye.observe(a), e.addEventListener("abort", () => {
    Ye.unobserve(a), bt.delete(a);
  }, { once: true })) : u(l, "resize", T, { passive: true, signal: e }), "IntersectionObserver" in l) {
    let x = new IntersectionObserver((w) => {
      for (let A of w) A.isIntersecting && (f(), x.disconnect());
    }, { rootMargin: O.lowPower ? "160px 0px" : "420px 0px", threshold: 0.01 });
    x.observe(t), e.addEventListener("abort", () => x.disconnect(), { once: true });
  } else f();
}
var vt = { init(t = n, e) {
  for (let a of W("[data-delicat-carousel]", t)) ar(a, e || new AbortController().signal);
}, materialize(t) {
  let e = xe(t);
  return Qt(t, e, O.lowPower ? 3 : 6) > 0;
}, prefill(t) {
  ke(t, xe(t));
}, complete(t) {
  ke(t, xe(t));
}, buildCard: Ut };
function rr({ root: t, signal: e }) {
  l.DelicaBuilderV9Carousel = vt, vt.init(t, e), u(n, "visibilitychange", () => {
    if (!n.hidden) for (let a of W('[data-delicat-carousel][data-delicat-active="1"]', t)) ke(a, xe(a));
  }, { signal: e }), u(l, "pageshow", () => {
    for (let a of W('[data-delicat-carousel][data-delicat-active="1"]', t)) ke(a, xe(a));
  }, { passive: true, signal: e });
}
var Ae = "[data-delicat-like]", yt = 60, ir = 280;
function nr({ root: t, signal: e, config: a }) {
  let r = l.DelicaBuilderV9 && l.DelicaBuilderV9.heart || a.heart || {};
  if (!r.enabled) return;
  let i = /^[a-z0-9_]+$/i.test(String(r.cookie || "")) ? String(r.cookie) : "dbv9_fav", d = typeof Element != "undefined" && typeof Element.prototype.animate == "function", s = { add: String(r.addLabel || "Ajouter aux favoris"), remove: String(r.removeLabel || "Retirer des favoris") }, c = () => !d || O.reducedMotion ? 0 : D.classList.contains("delicat-low-power") ? 1 : 2, f = () => {
    if (!r.guest) return /* @__PURE__ */ new Set();
    let h = Pe.read(i);
    return new Set((h ? h.split(",") : []).map(Number).filter((k) => Number.isInteger(k) && k > 0).slice(-yt));
  }, g = f(), m = () => Pe.write(i, [...g].slice(-yt).join(","), 31536e3), b = /* @__PURE__ */ new Map(), y = /* @__PURE__ */ new Map(), v = /* @__PURE__ */ new Set(), C = /* @__PURE__ */ new Map(), I = /* @__PURE__ */ new WeakMap(), T = (h) => h.getAttribute("aria-pressed") === "true", x = (h, k) => {
    h.setAttribute("aria-pressed", k ? "true" : "false"), h.setAttribute("aria-label", k ? s.remove : s.add);
  }, w = (h) => W(`${Ae}[data-product-id="${h}"]`), A = (h, k) => G("delicat:favorite-change", { productId: h, favorite: k }), _ = (h, k) => {
    if (!k) return;
    let P = I.get(h) || [];
    P.push(k), I.set(h, P);
  }, R = (h) => {
    for (let k of I.get(h) || []) try {
      k.cancel();
    } catch {
    }
    I.delete(h);
  }, q = (h, k, P, $, B = 0) => {
    let F = 0.008333333333333333, Q = [], ie = h, oe = B;
    for (let ne = 0; ne < 180 && (Q.push(ie), oe += (-P * (ie - k) - $ * oe) * F, ie += oe * F, !(ne > 10 && Math.abs(ie - k) < 2e-3 && Math.abs(oe) < 0.05)); ne += 1) ;
    return Q.push(k), { keyframes: Q.filter((ne, Me) => Me % 2 === 0 || Me === Q.length - 1).map((ne) => ({ transform: `scale(${ne.toFixed(4)})` })), duration: Math.round(Q.length * F * 1e3) };
  }, p = (h) => ({ chip: h.querySelector(".delicat-like__chip") || h, fill: h.querySelector(".delicat-like__fill") }), o = (h, k) => {
    let { chip: P, fill: $ } = p(h), B = P.getBoundingClientRect();
    if (!B.width || !n.body) return;
    let F = n.createElement("div");
    F.className = "delicat-like-burst", F.setAttribute("aria-hidden", "true"), F.style.left = `${B.left + B.width / 2}px`, F.style.top = `${B.top + B.height / 2}px`, F.style.color = $ ? getComputedStyle($).color : "#ff4d91";
    let Q = B.width / 2, ie = [];
    if (k === 2) {
      let J = n.createElement("span");
      J.className = "delicat-like-burst__ring", J.style.width = `${B.width}px`, J.style.height = `${B.height}px`, J.style.margin = `${-Q}px 0 0 ${-Q}px`, F.appendChild(J), ie.push(J.animate([{ transform: "scale(1)", opacity: 0.75 }, { transform: "scale(1.8)", opacity: 0 }], { duration: 440, easing: "cubic-bezier(.2,.75,.3,1)" }));
    }
    let oe = k === 2 ? 7 : 5, ne = Math.random() * Math.PI * 2;
    for (let J of k === 2 ? [0, 1] : [0]) for (let Xe = 0; Xe < oe; Xe += 1) {
      let Lt = ne + Math.PI * 2 * (Xe + J * 0.5) / oe + (Math.random() - 0.5) * 0.22, Te = J === 0 ? 5 : 3.5, Ne = Q + 1, De = Q + (J === 0 ? 16 : 10) + Math.random() * 4, ge = n.createElement("span");
      ge.className = "delicat-like-burst__dot", ge.style.width = `${Te}px`, ge.style.height = `${Te}px`, ge.style.margin = `${-Te / 2}px 0 0 ${-Te / 2}px`, F.appendChild(ge);
      let qe = Math.cos(Lt), Ie = Math.sin(Lt), Mt = J === 0 ? 1 : 0.8;
      ie.push(ge.animate([{ transform: `translate(${qe * Ne}px, ${Ie * Ne}px) scale(.4)`, opacity: 0 }, { transform: `translate(${qe * (Ne + 4)}px, ${Ie * (Ne + 4)}px) scale(1)`, opacity: Mt, offset: 0.18 }, { transform: `translate(${qe * (De - 3)}px, ${Ie * (De - 3)}px) scale(.75)`, opacity: Mt, offset: 0.62 }, { transform: `translate(${qe * De}px, ${Ie * De}px) scale(.2)`, opacity: 0 }], { duration: 560 + Math.random() * 100, delay: J === 0 ? 50 : 95, easing: "cubic-bezier(.22,.7,.35,1)", fill: "backwards" }));
    }
    n.body.appendChild(F);
    let Me = false, Ue = () => {
      Me || (Me = true, F.remove());
    };
    Promise.all(ie.map((J) => J.finished)).then(Ue, Ue), l.setTimeout(Ue, 1200);
  }, L = (h, k) => {
    let { chip: P, fill: $ } = p(h);
    if (R(h), !d || !$) return;
    if (k === 0) {
      _(h, $.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 180, easing: "linear" }));
      return;
    }
    let B = q(0, 1, 340, 14);
    _(h, $.animate(B.keyframes, { duration: B.duration, easing: "linear" })), _(h, $.animate([{ opacity: 0 }, { opacity: 1, offset: 0.12 }, { opacity: 1 }], { duration: B.duration, easing: "linear" }));
    let F = q(0.86, 1, 520, 17);
    _(h, P.animate(F.keyframes, { duration: F.duration, easing: "linear" })), o(h, k);
  }, E = (h, k) => {
    let { chip: P, fill: $ } = p(h);
    if (R(h), !d || !$) return;
    if (k === 0) {
      _(h, $.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: "linear" }));
      return;
    }
    _(h, $.animate([{ transform: "scale(1)", opacity: 1 }, { transform: "scale(1.1)", opacity: 1, offset: 0.22 }, { transform: "scale(.2)", opacity: 0 }], { duration: 260, easing: "cubic-bezier(.45,0,.7,.4)" }));
    let B = q(0.9, 1, 460, 20);
    _(h, P.animate(B.keyframes, { duration: B.duration, easing: "linear" }));
  }, M = (h) => {
    if (c() === 0) return;
    let { chip: k } = p(h);
    R(k), _(k, k.animate([{ transform: "translateX(0) rotate(0)" }, { transform: "translateX(-4px) rotate(-7deg)" }, { transform: "translateX(4px) rotate(6deg)" }, { transform: "translateX(-3px) rotate(-4deg)" }, { transform: "translateX(2px) rotate(2deg)" }, { transform: "translateX(0) rotate(0)" }], { duration: 440, easing: "ease-out" }));
  }, H = () => {
    try {
      c() > 0 && typeof navigator.vibrate == "function" && navigator.vibrate(10);
    } catch {
    }
  }, X = (h, k, P = null) => {
    for (let $ of w(h)) $ === P || T($) === k || (R($), x($, k));
  }, me = async (h, k) => {
    try {
      let P = await Ge(String(r.action || "delicat_builder_v9_favorite"), { nonce: String(r.nonce), product_id: String(h), state: k ? "1" : "0" }, { url: r.ajaxUrl, timeout: 12e3 });
      return !!(P && P.favorite);
    } catch (P) {
      let $ = new Error("favorite_failed");
      throw $.status = P && P.status, $;
    }
  }, le = (h) => {
    if (!G("delicat:favorite-signin", { productId: h }, n, true)) return;
    let k = n.querySelector('[data-delicat-login], a[href$="#delicat-login"]');
    if (k) {
      k.click();
      return;
    }
    location.hash = "delicat-login";
  }, se = (h, k, P) => {
    let $ = c();
    for (let B of w(h)) {
      if (T(B) !== k) if (x(B, k), k) {
        let { fill: F } = p(B);
        R(B), d && F && _(B, F.animate([{ opacity: 0, transform: "scale(.6)" }, { opacity: 1, transform: "scale(1)" }], { duration: 220, easing: "ease-out" }));
      } else E(B, $);
      M(B);
    }
    A(h, k), P === 401 && le(h);
  }, Y = (h, k = ir) => {
    l.clearTimeout(C.get(h)), C.set(h, l.setTimeout(() => {
      C.delete(h), S(h);
    }, k));
  }, S = async (h) => {
    if (v.has(h)) return;
    let k = y.get(h);
    if (!(typeof k != "boolean" || k === b.get(h))) {
      v.add(h);
      try {
        b.set(h, await me(h, k));
      } catch (P) {
        let $ = b.get(h) === true;
        y.set(h, $), se(h, $, Number(P && P.status || 0));
      } finally {
        v.delete(h), y.get(h) !== b.get(h) && Y(h, 0);
      }
    }
  }, N = () => {
    for (let h of W(`${Ae}.is-pressed`)) h.classList.remove("is-pressed");
  };
  u(n, "pointerdown", (h) => {
    let k = h.target instanceof Element ? h.target.closest(Ae) : null;
    k && (h.button === 0 || h.pointerType !== "mouse") && k.classList.add("is-pressed");
  }, { passive: true, signal: e });
  for (let h of ["pointerup", "pointercancel", "dragstart"]) u(n, h, N, { passive: true, signal: e });
  u(n, "scroll", N, { passive: true, capture: true, signal: e }), u(l, "blur", N, { signal: e }), u(n, "click", (h) => {
    let k = h.target instanceof Element ? h.target.closest(Ae) : null;
    if (!k) return;
    h.preventDefault(), h.stopPropagation(), N();
    let P = Number(k.dataset.productId || 0);
    if (!Number.isInteger(P) || P <= 0) return;
    if (!r.loggedIn && !r.guest || r.loggedIn && (!r.ajaxUrl || !r.nonce)) {
      M(k), le(P);
      return;
    }
    let $ = T(k), B = !$, F = c();
    if (x(k, B), X(P, B, k), B ? (L(k, F), H()) : E(k, F), A(P, B), !r.loggedIn) {
      for (g.delete(P), B && g.add(P); g.size > yt; ) {
        let Q = g.values().next().value;
        g.delete(Q), X(Q, false), A(Q, false);
      }
      m();
      return;
    }
    b.has(P) || b.set(P, $), y.set(P, B), Y(P);
  }, { capture: true, signal: e });
  let j = (h = n) => {
    for (let k of W(Ae, h && h.querySelectorAll ? h : n)) {
      let P = Number(k.dataset.productId || 0);
      if (P <= 0) continue;
      let $ = null;
      r.loggedIn ? y.has(P) && ($ = y.get(P)) : r.guest && ($ = g.has(P)), $ !== null && T(k) !== $ && x(k, $);
    }
  }, V = () => {
    r.loggedIn || !r.guest || (g = f(), j(n));
  };
  u(n, "delicat:carousel-materialized", (h) => j(h.target instanceof Element ? h.target : n), { signal: e }), u(n, "visibilitychange", () => {
    n.hidden || V();
  }, { signal: e }), u(l, "pageshow", (h) => {
    h.persisted && V();
  }, { signal: e }), l.DelicaBuilderV9Heart = { hydrate: j, hydrateGuests: j }, j(t);
}
function lr(t, e, a) {
  if (!(t instanceof Element) || t.dataset.delicatSearchReady === "1") return;
  let r = t.querySelector("[data-delicat-hero-search-input]"), i = t.querySelector("[data-delicat-hero-search-results]"), d = t.querySelector(".delicat-builder-hero-search__data");
  if (!r || !i || !d) return;
  let s = [];
  try {
    let T = JSON.parse(d.textContent || "[]");
    s = Array.isArray(T) ? T : [];
  } catch {
    s = [];
  }
  let c = Math.max(1, Math.min(4, Number(t.dataset.minChars || 2))), f = Math.max(3, Math.min(8, Number(t.dataset.resultLimit || 5))), g = -1, m = [], b = 0, y = () => {
    l.clearTimeout(b), i.hidden = true, i.replaceChildren(), g = -1, m = [], r.removeAttribute("aria-activedescendant"), r.setAttribute("aria-expanded", "false");
  }, v = (T) => {
    let x = W('[role="option"]', i);
    if (!x.length) return;
    g = Math.max(0, Math.min(x.length - 1, T)), x.forEach((A, _) => {
      A.classList.toggle("is-active", _ === g), A.setAttribute("aria-selected", _ === g ? "true" : "false");
    });
    let w = x[g];
    w && w.id && r.setAttribute("aria-activedescendant", w.id), w && w.scrollIntoView({ block: "nearest" });
  }, C = (T, x) => {
    let w = Et(T && T.name);
    return w ? w === x ? 0 : w.startsWith(x) ? 1 : w.split(/\s+/).some((A) => A.startsWith(x)) ? 2 : w.includes(x) ? 3 : 99 : 99;
  }, I = (T) => {
    let x = Et(T);
    if (x.length < c || !s.length) {
      y();
      return;
    }
    if (m = s.map((w, A) => ({ item: w, position: A, score: C(w, x) })).filter((w) => w.score < 99).sort((w, A) => w.score - A.score || w.position - A.position).slice(0, f).map((w) => w.item), i.replaceChildren(), g = -1, !m.length) {
      let w = n.createElement("div");
      w.className = "delicat-builder-hero-search__empty", w.textContent = "Aucun r\xE9sultat rapide \u2014 appuyez sur Entr\xE9e pour rechercher toute la boutique.", i.append(w), i.hidden = false, r.setAttribute("aria-expanded", "true");
      return;
    }
    m.forEach((w, A) => {
      let _ = n.createElement("a");
      if (_.className = "delicat-builder-hero-search__result", _.href = String(w.url || "#"), _.id = `delicat-hero-search-result-${A}`, _.setAttribute("role", "option"), _.setAttribute("aria-selected", "false"), _.dataset.delicatPrefetch = "", _.dataset.delicatProductLink = "", w.image) {
        let o = n.createElement("img");
        o.src = String(w.image), o.alt = "", o.loading = "lazy", o.decoding = "async", o.width = 48, o.height = 48, _.append(o);
      } else {
        let o = n.createElement("span");
        o.className = "delicat-builder-hero-search__result-placeholder", o.textContent = "\u25EB", _.append(o);
      }
      let R = n.createElement("span");
      R.className = "delicat-builder-hero-search__result-copy";
      let q = n.createElement("strong");
      if (q.textContent = String(w.name || ""), R.append(q), w.price) {
        let o = n.createElement("small");
        o.textContent = String(w.price), R.append(o);
      }
      _.append(R);
      let p = n.createElement("span");
      p.className = "delicat-builder-hero-search__result-arrow", p.textContent = "\u2192", _.append(p), i.append(_);
    }), i.hidden = false, r.setAttribute("aria-expanded", "true");
  };
  r.setAttribute("role", "combobox"), r.setAttribute("aria-autocomplete", "list"), r.setAttribute("aria-expanded", "false"), i.id = i.id || `delicat-hero-search-results-${Math.random().toString(36).slice(2, 8)}`, r.setAttribute("aria-controls", i.id), u(r, "input", () => {
    l.clearTimeout(b), y(), b = l.setTimeout(() => I(r.value), 45);
  }, { passive: true, signal: e }), u(r, "keydown", (T) => {
    if (T.key === "Escape") {
      y();
      return;
    }
    i.hidden || !m.length || (T.key === "ArrowDown" ? (T.preventDefault(), v(g < 0 ? 0 : g + 1)) : T.key === "ArrowUp" ? (T.preventDefault(), v(g < 0 ? m.length - 1 : g - 1)) : T.key === "Enter" && g >= 0 && m[g] && m[g].url && (T.preventDefault(), a.nav ? a.nav.navigate(String(m[g].url)) : location.assign(String(m[g].url))));
  }, { signal: e }), u(t, "focusout", () => {
    l.setTimeout(() => {
      t.contains(n.activeElement) || y();
    }, 90);
  }, { signal: e }), t.dataset.delicatSearchReady = "1";
}
function sr({ root: t, signal: e, engine: a }) {
  for (let r of W("[data-delicat-hero-search]", t)) lr(r, e, a);
}
var or = /* @__PURE__ */ new Set(["fade-up", "fade", "scale", "slide-left", "slide-right", "soft-zoom"]), Se = {}, Jt = (t) => {
  let e = /^\s*(-?\d*\.?\d+)(ms|s|px)?\s*$/.exec(String(t));
  if (!e) return null;
  let a = parseFloat(e[1]), r = e[2] || "";
  return r === "s" && (a *= 1e3, r = "ms"), { n: a, unit: r };
}, dr = (t, e) => {
  let a = Jt(t), r = Jt(e);
  return !a || !r ? String(t).trim() === String(e).trim() : a.unit === r.unit && Math.abs(a.n - r.n) < 15e-4;
}, Fe = (t, e) => {
  if (Se[t] === void 0) try {
    Se[t] = l.getComputedStyle(D).getPropertyValue(t);
  } catch {
    Se[t] = "";
  }
  if (dr(Se[t], e)) {
    Se[t] = e;
    return;
  }
  Se[t] = e, D.style.setProperty(t, e);
};
function cr({ root: t, signal: e, config: a }) {
  let r = Object.assign({ enabled: true, effect: "fade-up", duration: 440, intensity: 45, stagger: 55, baseDelay: 0, threshold: 0.04, desktop: true, tablet: true, mobile: true, mobileIntensity: 70 }, l.DelicaBuilderV9MotionConfig || a.motion || {}), i = null, d = 0, s = 0, c = 0, f = "", g = () => Math.max(320, Number(l.innerWidth || D.clientWidth || 1024)), m = () => {
    let x = g();
    return x <= 640 ? "mobile" : x <= 960 ? "tablet" : "desktop";
  }, b = (x) => x === "mobile" ? !!r.mobile : x === "tablet" ? !!r.tablet : !!r.desktop, y = (x) => {
    let w = x === "mobile" ? Math.max(0.2, Math.min(1, Number(r.mobileIntensity || 70) / 100)) : 1, A = Math.max(180, Math.min(900, Number(r.duration || 440))), _ = Math.max(0, Math.min(150, Number(r.stagger || 55))), R = or.has(r.effect) ? r.effect : "fade-up", q = "full";
    return (O.lowPower || O.saveData) && (w *= 0.72, A = Math.min(A, 380), _ = Math.min(_, 28), q = "adaptive"), O.veryLowPower && (w *= 0.72, A = Math.min(A, 300), _ = Math.min(_, 16), R = "fade", q = "ultra-light"), { factor: w, duration: A, stagger: _, effect: R, label: q };
  }, v = (x, w = "") => {
    D.dataset.dbv9MotionStatus !== x && (D.dataset.dbv9MotionStatus = x), w ? D.dataset.dbv9MotionProfile !== w && (D.dataset.dbv9MotionProfile = w) : D.dataset.dbv9MotionProfile !== void 0 && delete D.dataset.dbv9MotionProfile;
  }, C = () => {
    d && l.clearTimeout(d), d = 0, i && i.disconnect(), i = null;
  }, I = (x = n) => {
    C();
    for (let w of W(".dbv9-reveal:not(.dbv9-reveal--in)", x)) w.style.transition = "none", w.style.removeProperty("--dbv9-reveal-delay"), w.classList.add("dbv9-reveal--in");
  }, T = (x = n) => {
    C(), c += 1;
    let w = c, A = m();
    if (f = A, !r.enabled) {
      v("disabled-setting");
      return;
    }
    if (!b(A)) {
      v("disabled-device");
      return;
    }
    if (O.reducedMotion) {
      v("reduced-motion"), I(x);
      return;
    }
    if (!("IntersectionObserver" in l)) {
      v("unsupported");
      return;
    }
    let _ = y(A);
    v("active", _.label);
    let R = Math.max(0, Math.min(100, Number(r.intensity || 45))) * _.factor;
    Fe("--dbv9-motion-duration", _.duration + "ms"), Fe("--dbv9-motion-distance", Math.round(3 + R / 100 * 26) + "px"), Fe("--dbv9-motion-scale-in", String(Math.max(0.95, 1 - (6e-3 + R / 100 * 0.028)))), Fe("--dbv9-motion-scale-out", String(Math.min(1.045, 1 + 6e-3 + R / 100 * 0.022)));
    let q = W(".delicat-page-layout[data-delicat-page-layout]", x);
    if (!q.length) {
      v("no-builder-layout", _.label);
      return;
    }
    let p = [];
    for (let L of q) for (let E of Array.from(L.children)) !E.classList || !E.classList.contains("delicat-section") || E.classList.contains("dbv9-reveal--in") || p.push(E);
    if (!p.length) return;
    let o = true;
    i = new IntersectionObserver((L) => {
      if (w === c) {
        if (o) {
          o = false;
          let E = Math.max(320, Number(l.innerHeight || D.clientHeight || 800));
          for (let M of L) {
            let H = M.boundingClientRect;
            if (H.top < E * 0.88 || H.height < 2) {
              i && i.unobserve(M.target);
              continue;
            }
            M.target.classList.add("dbv9-reveal", "dbv9-motion--" + _.effect);
          }
        }
        L.filter((E) => E.isIntersecting && E.target.classList.contains("dbv9-reveal") && !E.target.classList.contains("dbv9-reveal--in")).sort((E, M) => E.boundingClientRect.top - M.boundingClientRect.top).forEach((E, M) => {
          let H = E.target;
          H.style.setProperty("--dbv9-reveal-delay", Math.max(0, Math.min(300, Number(r.baseDelay || 0))) + Math.min(M, 4) * _.stagger + "ms"), H.classList.add("dbv9-reveal--in"), i && i.unobserve(H);
        });
      }
    }, { root: null, rootMargin: "0px 0px -3% 0px", threshold: Math.max(0.01, Math.min(0.3, Number(r.threshold || 0.04))) });
    for (let L of p) i.observe(L);
    d = l.setTimeout(() => I(x), 5500);
  };
  if (U(() => T(t)), u(l, "pageshow", () => U(() => T(t)), { passive: true, signal: e }), u(l, "resize", () => {
    s && l.clearTimeout(s), s = l.setTimeout(() => {
      m() !== f && (I(t), T(t));
    }, 180);
  }, { passive: true, signal: e }), O.reduceQuery && O.reduceQuery.addEventListener) {
    let x = () => {
      I(t), T(t);
    };
    O.reduceQuery.addEventListener("change", x), e.addEventListener("abort", () => O.reduceQuery.removeEventListener("change", x), { once: true });
  }
  e.addEventListener("abort", () => I(t), { once: true });
}
var Gt = (t) => {
  if (!/^[A-Z]{3}$/.test(t || "")) return;
  let e = new URL(location.href);
  e.searchParams.set("dmc_currency", t), e.searchParams.delete("add-to-cart"), sa(), G("delicat:currency-changed", { currency: t }), location.assign(e.toString());
};
function ur({ root: t, signal: e }) {
  for (let a of W("[data-dbv9-currency]", t)) {
    if (a.dataset.ready === "1") continue;
    a.dataset.ready = "1";
    let r = a.querySelector("[data-dbv9-currency-select]");
    r && u(r, "change", (i) => Gt(String(i.target.value || "").toUpperCase()), { signal: e }), u(a, "click", (i) => {
      let d = i.target instanceof Element ? i.target.closest("[data-dbv9-currency-value]") : null;
      d && Gt(String(d.dataset.dbv9CurrencyValue || "").toUpperCase());
    }, { signal: e });
  }
}
var pr = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="20" height="20"><path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>', ea = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="20" height="20"><rect x="3.5" y="3.5" width="17" height="17" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8v8M8 12h8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>', ta = { get() {
  try {
    return l.sessionStorage.getItem("dbv9-install-seen");
  } catch {
    return null;
  }
}, set() {
  try {
    l.sessionStorage.setItem("dbv9-install-seen", "1");
  } catch {
  }
} };
function mr({ signal: t, config: e }) {
  let a = Object.assign({}, l.DelicaPWARuntime || {}, e.pwa || {}), r = String(l.DelicatAndroidPage || e.androidPage || ""), i = String(a.serviceWorker || ""), d = String(a.scope || "/"), s = !!a.installUI, c = null, f = O.standalone;
  if (f && D.classList.add("delicat-standalone"), O.ios && D.classList.add("delicat-ios"), f) {
    try {
      n.cookie = "dbv9_app=1; path=/; max-age=31536000; SameSite=Lax" + (location.protocol === "https:" ? "; Secure" : "");
    } catch {
    }
    try {
      let C = new URL(location.href);
      C.searchParams.get("utm_source") === "pwa" && (C.searchParams.delete("utm_source"), history.replaceState(history.state, "", C.pathname + (C.search || "") + C.hash));
    } catch {
    }
    let v = 0;
    u(n, "visibilitychange", () => {
      if (n.hidden) {
        v = Date.now();
        return;
      }
      v && Date.now() - v > 9e4 && ee.refresh("resume"), v = 0;
    }, { signal: t });
  }
  u(l, "beforeinstallprompt", (v) => {
    v.preventDefault(), c = v, D.classList.add("delicat-pwa-installable");
  }, { signal: t });
  let g = () => {
    let v = n.getElementById("dbv9-pwa-modal");
    return v || (v = n.createElement("div"), v.id = "dbv9-pwa-modal", v.className = "dbv9-pwa-modal", v.innerHTML = '<div class="dbv9-pwa-backdrop" data-dbv9-pwa-close></div><section class="dbv9-pwa-card" role="dialog" aria-modal="true" aria-label="Installer Delicat Store"><button type="button" class="dbv9-pwa-close" data-dbv9-pwa-close aria-label="Fermer">\xD7</button><h3 class="dbv9-pwa-title">Installer Delicat Store</h3><p class="dbv9-pwa-copy"></p><ol class="dbv9-pwa-steps" hidden></ol><button type="button" class="dbv9-pwa-action">Installer</button></section>', n.body.appendChild(v), u(v, "click", (C) => {
      C.target.closest("[data-dbv9-pwa-close]") && (v.classList.remove("is-open"), Ke("pwa"));
    }, { signal: t }), v);
  }, m = async () => {
    if (!s || f) return;
    let v = g(), C = v.querySelector(".dbv9-pwa-copy"), I = v.querySelector(".dbv9-pwa-steps"), T = v.querySelector(".dbv9-pwa-action");
    I.hidden = true, c ? (C.textContent = "Ajoutez Delicat Store \xE0 votre \xE9cran d\u2019accueil pour un acc\xE8s plus rapide.", T.hidden = false, T.textContent = "Installer", T.onclick = async () => {
      try {
        c.prompt(), await c.userChoice;
      } catch {
      }
      c = null, v.classList.remove("is-open"), Ke("pwa");
    }) : O.ios ? (C.textContent = "Sur iPhone, l\u2019application s\u2019installe depuis Safari en deux gestes :", I.innerHTML = '<li><span class="dbv9-pwa-step-icon">' + pr + '</span><span class="dbv9-pwa-step-text">Touchez <strong>Partager</strong> en bas de Safari.</span></li><li><span class="dbv9-pwa-step-icon">' + ea + '</span><span class="dbv9-pwa-step-text">Choisissez <strong>\xAB Sur l\u2019\xE9cran d\u2019accueil \xBB</strong>, puis <strong>Ajouter</strong>.</span></li>', I.hidden = false, T.hidden = true) : (C.textContent = "Ouvrez le menu de votre navigateur puis choisissez \xAB Installer l\u2019application \xBB ou \xAB Ajouter \xE0 l\u2019\xE9cran d\u2019accueil \xBB.", T.hidden = true), v.classList.add("is-open"), da("pwa");
  }, b = (v) => !r || O.ios ? false : (v && (v.preventDefault(), v.stopImmediatePropagation()), location.assign(r), true);
  u(n, "click", (v) => {
    if (v.button !== 0 || v.ctrlKey || v.metaKey || v.shiftKey || v.altKey) return;
    let C = v.target instanceof Element ? v.target.closest('a[href="#install-app"],[data-dlx-install],[data-db-v9-pwa-open],[data-dbv9-pwa-open]') : null;
    C && (b(v) || C.matches('[data-db-v9-pwa-open],[data-dbv9-pwa-open],a[href="#install-app"]') && (v.preventDefault(), m()));
  }, { capture: true, signal: t }), u(n, "delicat:install-app", (v) => {
    b(v) || m();
  }, { capture: true, signal: t }), u(n, "keydown", (v) => {
    if (v.key === "Escape") {
      let C = n.getElementById("dbv9-pwa-modal");
      C && C.classList.contains("is-open") && (C.classList.remove("is-open"), Ke("pwa"));
    }
  }, { signal: t });
  let y = () => {
    if (!s || f || n.hidden || !O.ios || ta.get() || n.getElementById("dbv9-install-bar") || n.querySelector(".dlx-drawer.is-open, .dbv9-pwa-modal.is-open")) return;
    if (/^https?:\/\/([^/]+\.)?(google|bing|yahoo|duckduckgo|yandex|ecosia|qwant|baidu)\.[a-z.]+(\/|$)/i.test(n.referrer || "")) return;
    let v = n.createElement("div");
    v.id = "dbv9-install-bar", v.className = "dbv9-install-bar", v.setAttribute("role", "status"), v.innerHTML = '<span class="dbv9-install-bar__icon">' + ea + '</span><span class="dbv9-install-bar__copy"><strong>Delicat en application</strong><small>Ajoutez-la \xE0 l\u2019\xE9cran d\u2019accueil.</small></span><button type="button" class="dbv9-install-bar__go" data-dbv9-pwa-open>Voir</button><button type="button" class="dbv9-install-bar__close" aria-label="Fermer">\xD7</button>', n.body.appendChild(v);
    let dbvHeader = n.querySelector("[data-dsb8-header]"), dbvTop = () => {
      let hb = dbvHeader && dbvHeader.isConnected ? dbvHeader.getBoundingClientRect() : null;
      v.style.setProperty("--dbv9-install-top", Math.round(hb && hb.bottom > 0 ? hb.bottom + 8 : 0) + "px");
    };
    dbvTop(), u(l, "resize", dbvTop, { passive: true, signal: t }), u(l, "scroll", () => U(dbvTop), { passive: true, signal: t });
    let C = () => {
      ta.set(), v.classList.remove("is-in"), l.setTimeout(() => v.remove(), 260);
    };
    u(v, "click", (I) => {
      I.target.closest(".dbv9-install-bar__close, [data-dbv9-pwa-open]") && C();
    }, { signal: t }), l.setTimeout(() => v.classList.add("is-in"), 30);
  };
  if (!f && O.ios && ae(() => l.setTimeout(y, 2500), 4e3), l.DelicatPWA = { open: m }, r && !O.ios && (l.DelicatAndroidLinkBound = true), i && "serviceWorker" in navigator) {
    let v = () => navigator.serviceWorker.register(i, { scope: d }).catch(() => {
    });
    n.readyState === "complete" ? ae(v, 3500) : l.addEventListener("load", () => ae(v, 3500), { once: true });
  }
}
function gr({ root: t, signal: e, config: a }) {
  let r = l.delicatBuilderV9WalletLive || a.walletLive;
  if (!r || !r.url || !r.nonce) return;
  let i = () => W("[data-dsb-wallet]", n);
  if (!i().length) return;
  let d = parseInt(r.idle, 10) || 45e3, s = parseInt(r.watch, 10) || 5e3, c = 3e3, f = 0, g = false, m = 0, b = 0, y = false, v = false, C = 0, I = null;
  try {
    I = new BroadcastChannel("dsb-wallet"), I.onmessage = (q) => {
      q && q.data && A(q.data, false);
    };
  } catch {
  }
  let T = () => D.classList.contains("dsb-menu-open") || D.classList.contains("dlx-open"), x = () => !n.hidden && (T() || y || Date.now() < b), w = () => {
    l.clearTimeout(f), !v && x() && (f = l.setTimeout(R, Date.now() < b ? s : d));
  };
  function A(q, p) {
    let o = parseFloat(q.raw);
    if (!isNaN(o)) {
      for (let L of i()) {
        let E = L.querySelector(".dsb-wallet-amount");
        if (E && typeof q.html == "string" && q.html) {
          let M = pa("<body>" + q.html);
          E.replaceChildren(...M ? Array.from(M.body.childNodes) : [n.createTextNode(String(q.html))]);
        }
        L.setAttribute("data-balance-raw", String(o));
      }
      for (let L of W("[data-dlx-wallet-balance],[data-delicat-wallet-balance]")) typeof q.text == "string" && q.text && (L.textContent = q.text);
      if (p && I) try {
        I.postMessage({ raw: o, html: q.html, text: q.text });
      } catch {
      }
    }
  }
  async function _(q) {
    let p = Date.now();
    if (!(v || g || !i().length || p < C || !q && p - m < c)) {
      m = p, g = true;
      try {
        let o = await Ge("delicat_builder_v9_wallet_balance", { nonce: r.nonce }, { url: r.url, signal: e });
        o && A(o, true);
      } catch (o) {
        let L = o && o.status;
        L === 429 ? C = Date.now() + 12e4 : (L === 401 || L === 403) && (v = true);
      } finally {
        g = false;
      }
    }
  }
  function R() {
    _(false), w();
  }
  if ("IntersectionObserver" in l) {
    let q = new IntersectionObserver((p) => {
      for (let o of p) y = o.isIntersecting || y;
      y = i().some((o) => o.getBoundingClientRect().height > 0 && p.some((L) => L.target === o ? L.isIntersecting : false)) || p.some((o) => o.isIntersecting), y && _(true), w();
    }, { rootMargin: "80px" });
    for (let p of i()) q.observe(p);
    e.addEventListener("abort", () => q.disconnect(), { once: true });
  }
  u(n, "dsb:menu-opened", () => l.setTimeout(() => {
    _(true), w();
  }, 450), { signal: e }), u(n, "dlx:open", () => l.setTimeout(() => {
    _(true), w();
  }, 450), { signal: e }), u(n, "dsb:menu-closed", w, { signal: e }), u(n, "delicat:balance-changed", () => _(true), { signal: e }), u(n, "delicat:recharge-submitted", () => {
    b = Date.now() + 18e4, _(true), w();
  }, { signal: e }), u(n, "visibilitychange", () => {
    !n.hidden && x() && _(true), w();
  }, { signal: e }), e.addEventListener("abort", () => {
    if (l.clearTimeout(f), I) try {
      I.close();
    } catch {
    }
  }, { once: true }), x() && _(true), w();
}
var fr = "a,button,input,select,textarea,[tabindex]";
function hr({ root: t, signal: e }) {
  let a = W("[data-dbv9-marquee-clone]", t).filter((s) => s.dataset.dbv9LoopReady !== "1");
  if (!a.length) return;
  let r = !!(l.matchMedia && l.matchMedia("(max-width: 640px)").matches), i = (s) => {
    if (s.dataset.dbv9LoopReady === "1") return;
    s.dataset.dbv9LoopReady = "1";
    let c = s.parentElement;
    if (O.reducedMotion || r && s.closest('[data-dbv9-marquee-mobile="manual"]')) {
      s.classList.add("is-loop-ready", "is-manual"), c && c.classList.add("is-manual");
      return;
    }
    let f = Array.from(s.children);
    if (!f.length) {
      s.classList.add("is-loop-ready");
      return;
    }
    let g = n.createDocumentFragment();
    for (let m of f) {
      let b = m.cloneNode(true);
      b.setAttribute("aria-hidden", "true"), b.removeAttribute("role"), b.removeAttribute("id"), b.querySelectorAll(fr).forEach((y) => y.setAttribute("tabindex", "-1")), g.appendChild(b);
    }
    if (s.appendChild(g), c) {
      let m = (b) => () => s.classList.toggle("is-held", b);
      u(c, "pointerdown", m(true), { signal: e, passive: true });
      for (let b of ["pointerup", "pointercancel", "pointerleave"]) u(c, b, m(false), { signal: e, passive: true });
    }
    U(() => s.classList.add("is-loop-ready"));
  };
  if (!("IntersectionObserver" in l)) {
    a.forEach(i);
    return;
  }
  let d = new IntersectionObserver((s) => {
    for (let c of s) {
      let f = c.target.matches("[data-dbv9-marquee-clone]") ? c.target : c.target.querySelector("[data-dbv9-marquee-clone]");
      f && (c.isIntersecting ? (i(f), f.classList.remove("is-offscreen")) : f.dataset.dbv9LoopReady === "1" && f.classList.add("is-offscreen"));
    }
  }, { rootMargin: "320px 0px" });
  for (let s of a) d.observe(s.parentElement || s);
  e.addEventListener("abort", () => d.disconnect(), { once: true });
}
function br({ root: t, signal: e }) {
  for (let a of W("[data-dbv9-banner-rail]", t)) {
    let r = a.querySelector("[data-dbv9-banner-track]"), i = r ? Array.from(a.querySelectorAll(".delicat-banner-rail__dots i")) : [];
    if (!r || !i.length) continue;
    let d = 0, s = () => {
      if (!d) {
        let g = r.children;
        d = g.length > 1 ? g[1].offsetLeft - g[0].offsetLeft : g[0] ? g[0].offsetWidth : 0;
      }
      return d;
    };
    u(l, "resize", () => {
      d = 0;
    }, { passive: true, signal: e });
    let c = false, f = () => {
      c = false;
      let g = s(), m = Math.max(0, r.scrollWidth - r.clientWidth), b = g > 0 ? Math.round(r.scrollLeft / g) : 0;
      m > 0 && r.scrollLeft >= m - 2 && (b = i.length - 1), b = Math.max(0, Math.min(i.length - 1, b)), i.forEach((y, v) => y.classList.toggle("is-active", v === b));
    };
    u(r, "scroll", () => {
      c || (c = true, U(f));
    }, { passive: true, signal: e }), u(a, "click", (g) => {
      let m = g.target instanceof Element ? g.target.closest(".delicat-banner-rail__dots i") : null;
      if (m) try {
        r.scrollTo({ left: i.indexOf(m) * s(), behavior: O.reducedMotion ? "auto" : "smooth" });
      } catch {
        r.scrollLeft = i.indexOf(m) * s();
      }
    }, { signal: e });
  }
}
var aa = "9.3.4", Le = (t) => !!(n.body && n.body.classList.contains(t)), Ce = () => import("./chunks/product.js"), wt = () => import("./chunks/purchase.js"), xt = () => import("./chunks/widgets.js"), vr = () => import("./chunks/reviews.js"), te = (t, e) => () => t().then((a) => ({ default: a[e] }));
z({ name: "theme", scope: "document", mount: Ia }), z({ name: "dark-adapt", scope: "document", mount: Da }), z({ name: "header", scope: "document", when: "[data-dsb8-header]", mount: Oa }), z({ name: "drawer", scope: "document", when: "[data-dlx-drawer]", mount: $a }), z({ name: "bottom-nav", scope: "document", when: "[data-delicat-bottom-nav],[data-delicat-mobile-dock]", mount: Ua }), z({ name: "shell-header", scope: "document", when: "[data-delicat-shell-header]", load: () => import("./chunks/shell-header.js") }), z({ name: "pwa", scope: "document", when: () => !!(l.DelicaPWARuntime || Z.pwa || l.DelicatAndroidPage || Z.androidPage), mount: mr }), z({ name: "reviews", scope: "document", mount: wr }), z({ name: "announcement", scope: "document", when: "[data-dbv9-announcement]", load: te(xt, "announcement") }), z({ name: "live-selling", scope: "document", when: () => !!(l.DelicatLiveSelling || Z.liveSelling), load: te(xt, "liveSelling") }), z({ name: "pull-refresh", scope: "document", when: () => !!Z.pullRefresh, load: te(xt, "pullRefresh") }), z({ name: "notifications", scope: "document", when: "[data-dbv9-notifications]", mount: yr }), z({ name: "carousel", when: "[data-delicat-carousel]", mount: rr }), z({ name: "heart", when: "[data-delicat-like],[data-delicat-carousel],.delicat-product-card", mount: nr }), z({ name: "hero-search", when: "[data-delicat-hero-search]", mount: sr }), z({ name: "motion", when: ".delicat-page-layout[data-delicat-page-layout],.dbv9-reveal", mount: cr }), z({ name: "marquee", when: "[data-dbv9-marquee-clone]", mount: hr }), z({ name: "banner", when: "[data-dbv9-banner-rail]", mount: br }), z({ name: "currency", always: true, when: "[data-dbv9-currency]", mount: ur }), z({ name: "wallet-live", always: true, when: "[data-dsb-wallet]", mount: gr }), z({ name: "purchase", always: true, when: () => !!(l.DelicaPurchaseV9 || Z.purchase), load: te(wt, "purchase") }), z({ name: "wallet-guard", always: true, when: () => Le("dpn-checkout") || Le("dnp-express-enabled"), load: te(wt, "walletGuard") }), z({ name: "storefront-commerce", always: true, when: () => Le("delicat-native-checkout") || Le("delicat-native-cart") || !!n.querySelector("[data-dcn-clear-cart]"), load: te(wt, "storefrontCommerce") }), z({ name: "purchase-native", always: true, when: () => Le("dpn-cart") || Le("dpn-checkout") || !!l.DelicatPurchaseNative, load: () => import("./chunks/purchase-native.js") }), z({ name: "native-product", when: ".delicat-native-product", load: te(Ce, "nativeProduct") }), z({ name: "product-switcher", when: ".dpsr-switcher", load: te(Ce, "productSwitcher") }), z({ name: "design-product", always: true, when: (t) => !!(t.querySelector("[data-delicat-design-options]") || n.querySelector("[data-delicat-design-buybar]")), load: te(Ce, "designProduct") }), z({ name: "calc", when: ".dmc-calc", load: te(Ce, "calc") }), z({ name: "express-checkout", always: true, when: "[data-dnp-express]", load: te(Ce, "expressCheckout") });
var St = null, ra = () => St || (St = import("./chunks/notifications.js").catch((t) => {
  throw St = null, l.__dlcChunkFailed = 1, t;
}));
function yr({ signal: t }) {
  let e = new AbortController();
  t.addEventListener("abort", () => e.abort(), { once: true });
  let a = null;
  u(n, "click", (r) => {
    let i = K(r.target, "[data-dbv9-notification-open]");
    i && (r.preventDefault(), r.stopPropagation(), a || (a = ra().then((d) => d.default({ root: n, signal: t, config: Z, engine: Qe, reason: "click" })).catch(() => null)), a.then((d) => {
      if (!d) {
        a = null;
        try {
          l.__dlcChunkFailed && !sessionStorage.getItem("dlc-bell-reload") && (sessionStorage.setItem("dlc-bell-reload", "1"), l.location.reload());
        } catch {
        }
        return;
      }
      e.abort();
      let s = i.closest("[data-dbv9-notifications]");
      s && d.open(s);
    }));
  }, { capture: true, signal: e.signal }), !O.saveData && !O.slowNetwork && ae(() => {
    e.signal.aborted || ra().catch(() => {
    });
  }, 4e3);
}
function wr({ signal: t }) {
  let e = () => l.DelicatReviewsConfig || Z.reviews || null, a = new AbortController();
  t.addEventListener("abort", () => a.abort(), { once: true });
  let r = null, i = () => r || (r = vr().then((c) => c.default({ root: n, signal: t, config: Z, engine: Qe, reason: "lazy" })).then((c) => c ? (a.abort(), c) : (r = null, null)).catch(() => (r = null, null)));
  u(n, "click", (c) => {
    let f = K(c.target, "[data-dlc-review-open],[data-dlc-vote],[data-dlc-reviews-all]");
    f && (c.preventDefault(), c.stopPropagation(), i().then((g) => {
      g && (g.handle ? g.handle(f) : g.open(f));
    }));
  }, { capture: true, signal: a.signal });
  let d = () => {
    let c = n.querySelector("[data-dlc-reviews]"), f = e();
    if (!c || a.signal.aborted || !f || !f.loggedIn) return;
    if (typeof l.IntersectionObserver != "function") {
      ae(() => {
        a.signal.aborted || i();
      }, 2500);
      return;
    }
    let g = new l.IntersectionObserver((m) => {
      m.some((b) => b.isIntersecting) && (g.disconnect(), a.signal.aborted || i());
    }, { rootMargin: "400px 0px" });
    g.observe(c), a.signal.addEventListener("abort", () => g.disconnect(), { once: true });
  };
  d(), u(n, "delicat:navigated", d, { signal: a.signal });
  let s = e();
  s && s.loggedIn && ae(() => {
    a.signal.aborted || i();
  }, 3e3);
}
ca({ nav: fe, session: ee, overlay: Ze, config: Z, carousel: vt, mountContent: Je, version: aa }), l.DelicatBuilderEngine = Qe, l.DelicatSession = { get: ee.get, ready: ee.ready, refresh: ee.refresh, on: ee.on, subscribe: ee.subscribe }, l.DBPState = { get: ee.get, refresh: ee.refresh, subscribe: ee.subscribe }, l.DBPNav = { navigate: fe.navigate, prefetch: fe.prefetch, invalidate: fe.invalidate, stats: fe.stats };
var xr = () => Z.nav && Z.nav.main || "main[data-delicat-server-render],main[data-delicat-native-document],main", Sr = () => n.querySelector(xr()) || n.body, ia = new Set(Array.isArray(Z.globals) ? Z.globals : []);
function Lr() {
  let t = new Set(Array.isArray(Z.globals) ? Z.globals : []);
  for (let e of ia) if (!t.has(e)) try {
    delete l[e];
  } catch {
    l[e] = void 0;
  }
  ia = t;
}
function Mr() {
  let t = /* @__PURE__ */ new Set(), e = (a, r) => {
    let i = String(a).slice(0, 160);
    t.has(i) || t.size > 20 || (t.add(i), Z.debug && console.error("[delicat-engine]", r, a), G("delicat:engine:error", { module: r, phase: "runtime", message: i }));
  };
  u(l, "error", (a) => {
    let r = String(a && a.filename || "");
    r && !/\/assets\/dist\//.test(r) || e(a && a.message || "error", "window");
  }), u(l, "unhandledrejection", (a) => {
    let r = a && a.reason, i = String(r && r.stack || "");
    i && !/\/assets\/dist\//.test(i) || e(r && r.message || String(r || "rejection"), "promise");
  });
}
function na() {
  Mr(), oa(), la(), fe.on("swapped", (t) => {
    Lr(), Je(t.main, "navigate");
  }), D.classList.add("delicat-engine"), ua("load"), Je(Sr(), "load").then(() => G("delicat:engine:ready", { version: aa }));
}
n.readyState === "loading" ? u(n, "DOMContentLoaded", na, { once: true }) : na();
