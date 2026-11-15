import { a as d, g as h, o as S } from "./chunk.71647e346105.js";
var w = [], s = { controller: null, cleanups: [], generation: 0 }, p = { mounted: /* @__PURE__ */ new Set(), cleanups: [] }, v = new AbortController().signal, l = (() => {
  let n = window.DelicatEngine && typeof window.DelicatEngine == "object" ? window.DelicatEngine : {};
  return window.DelicatEngine = n, n;
})();
function x() {
  let n = window.DelicatEngine;
  if (!n || n === l || typeof n != "object") return l;
  for (let t of Object.keys(l)) delete l[t];
  return Object.assign(l, n), window.DelicatEngine = l, l;
}
function D(n) {
  if (!n || typeof n.name != "string") throw new Error("engine: module needs a name");
  return n.scope = n.scope === "document" ? "document" : "content", w.push(n), n;
}
function g(n, t) {
  let e = n.when;
  if (!e) return true;
  if (typeof e == "function") try {
    return !!e(t, l);
  } catch {
    return false;
  }
  try {
    return !!t.querySelector(e) || t !== d && t.matches && t.matches(e);
  } catch {
    return false;
  }
}
async function k(n) {
  if (typeof n.mount == "function") return n.mount;
  if (typeof n.load == "function") {
    let t = await n.load(), e = t && (typeof t.default == "function" ? t.default : t.mount);
    if (typeof e == "function") return n.mount = e, e;
  }
  return null;
}
async function m(n, t, e) {
  let a;
  try {
    a = await k(n);
  } catch (o) {
    y(n, o, "load");
    try {
      window.__dlcChunkFailed = 1, /^(native-product|express-checkout|purchase|purchase-native|design-product|calc)$/.test(n.name) && !sessionStorage.getItem("dlc-chunk-reload") && (sessionStorage.setItem("dlc-chunk-reload", "1"), location.reload());
    } catch {
    }
    return;
  }
  if (!(!a || t.signal.aborted)) try {
    let o = await a(t);
    typeof o == "function" && (t.signal.aborted ? o() : e.push(o));
  } catch (o) {
    y(n, o, "mount");
  }
}
function y(n, t, e) {
  try {
    l.debug && console.error(`[delicat-engine] ${n.name} failed to ${e}`, t), h("delicat:engine:error", { module: n.name, phase: e, message: String(t && t.message || t) });
  } catch {
  }
}
var u = {};
function A(n) {
  return u = Object.assign(u, n), u;
}
var T = u;
async function C(n = "load") {
  let t = [];
  for (let e of w) e.scope !== "document" || p.mounted.has(e) || g(e, d) && (p.mounted.add(e), t.push(m(e, { root: d, signal: v, config: l, engine: u, reason: n }, p.cleanups)));
  await Promise.all(t);
}
async function N(n, t = "load") {
  if (s.controller) {
    s.controller.abort();
    for (let r of s.cleanups.splice(0)) try {
      r();
    } catch {
    }
  }
  let e = new AbortController();
  s.controller = e;
  let a = ++s.generation, o = { root: n || d, signal: e.signal, config: l, engine: u, reason: t }, c = [];
  for (let r of w) r.scope === "content" && (!g(r, o.root) && !(r.always && g(r, d)) || c.push(m(r, o, s.cleanups)));
  await Promise.all(c), a === s.generation && h("delicat:engine:mounted", { root: o.root, reason: t });
}
function P(n, t) {
  let e = new AbortController(), a = false, o = window.setTimeout(() => (a = true, e.abort(new Error("timeout"))), n), c = () => window.clearTimeout(o);
  return t && (t.aborted ? e.abort(t.reason) : t.addEventListener("abort", () => e.abort(t.reason), { once: true })), e.signal.addEventListener("abort", c, { once: true }), { signal: e.signal, cleanup: c, controller: e, timedOut: () => a };
}
async function b(n, t = {}, e = 8e3) {
  let a = S(n, t.sameOrigin !== false);
  if (!a) throw new Error("unsafe-url");
  let { signal: o, cleanup: c, timedOut: r } = P(e, t.signal);
  try {
    let i = await fetch(a, { credentials: "same-origin", ...t, signal: o });
    try {
      window.DelicatBackoff && window.DelicatBackoff.trip(i.status);
    } catch {
    }
    return i;
  } catch (i) {
    throw r() ? new Error("timeout") : i;
  } finally {
    c();
  }
}
function E(n) {
  try {
    return JSON.parse(n);
  } catch {
  }
  let t = String(n || ""), e = t.indexOf('{"'), a = t.lastIndexOf("}");
  if (e !== -1 && a > e) try {
    return JSON.parse(t.slice(e, a + 1));
  } catch {
  }
  if (e = t.indexOf("["), e !== -1) {
    let o = t.lastIndexOf("]");
    if (o > e) try {
      return JSON.parse(t.slice(e, o + 1));
    } catch {
    }
  }
  return null;
}
async function j(n, t = {}, e = 8e3) {
  let a = await b(n, { headers: { Accept: "application/json", ...t.headers || {} }, cache: "no-store", ...t }, e), o = await a.text(), c = E(o);
  if (!a.ok) {
    let r = new Error("http-" + a.status);
    throw r.status = a.status, r.data = c, r;
  }
  if (c === null) throw new Error("invalid-json");
  return c;
}
var I = () => String(l.ajaxUrl || window.DelicaBuilderV9 && window.DelicaBuilderV9.ajaxUrl || "/wp-admin/admin-ajax.php");
async function J(n, t = {}, e = {}) {
  let a = new URLSearchParams();
  a.set("action", n);
  for (let [i, f] of Object.entries(t)) Array.isArray(f) ? f.forEach((O) => a.append(i + "[]", String(O))) : f != null && a.set(i, String(f));
  let o = await b(e.url || I(), { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8", Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }, body: a.toString(), cache: "no-store", signal: e.signal }, e.timeout || 8e3), c = await o.text(), r = E(c);
  if (!o.ok || !r || r.success !== true) {
    let i = new Error("ajax-failed");
    throw i.status = o.status, i.payload = r, i.data = r && r.data, i;
  }
  return r.data === void 0 ? {} : r.data;
}
async function U(n, { method: t = "GET", body: e, nonce: a, signal: o, timeout: c = 6500, cache: r = "no-store" } = {}) {
  let i = { Accept: "application/json" };
  return a && (i["X-WP-Nonce"] = a), e !== void 0 && (i["Content-Type"] = "application/json"), j(n, { method: t, headers: i, body: e === void 0 ? void 0 : JSON.stringify(e), cache: r, redirect: "follow", signal: o }, c);
}
export {
  l as a,
  x as b,
  D as c,
  A as d,
  T as e,
  C as f,
  N as g,
  j as h,
  J as i,
  U as j
};
