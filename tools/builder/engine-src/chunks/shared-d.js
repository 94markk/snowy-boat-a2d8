import { a as X, b as ye } from "./shared-a.js";
import { a as n, b as v, c, d as C, e as w, g as q, h as ae, i as re, j as ie, m as y, n as S, p as G, w as _e, x as oe } from "./shared-base.js";
var K = c.DBPNavConfig || c.DelicatShellNavConfig || {}, m = Object.assign({ enabled: true, products: true, transitions: true, prefetch: true, budget: 10, viewportBudget: 2, cacheTtl: 3e5, privateTtl: 9e4, cacheMax: 20, maxBytes: 600 * 1024, timeout: 8e3, main: "main[data-delicat-server-render],main[data-delicat-native-document],main", portals: ["[data-dnp-express]", "[data-dpn-wallet-modal]", "[data-dnp-dock]", "[data-delicat-mobile-purchase]", "[data-dpn-checkout-dock]"], hardClasses: ["dpn-checkout", "dpn-cart", "woocommerce-checkout", "woocommerce-cart", "woocommerce-account", "delicat-native-checkout", "delicat-native-cart"], locked: K.locked || ["/wp-admin", "/wp-login.php", "/wp-json", "/wc-api", "/webhook", "/oauth", "/callback"], hardPaths: ["/cart/", "/panier/", "/checkout/", "/commande/", "/my-account/", "/mon-compte/", "/order-pay/", "/order-received/", "/customer-logout/", "/lost-password/"], blockParams: K.blockParams || K.blockedParams || ["add-to-cart", "remove_item", "undo_item", "wc-ajax", "wc-api", "_wpnonce", "nonce", "action", "dip_action", "logout", "customer-logout", "key", "token", "code", "session", "payment_method", "dmc_currency", "delicat_maintenance_preview"], productPaths: K.productPaths || ["/product/", "/produit/"], generation: K.generation || "", forms: true, placeholder: true, tabWarm: true }, X.nav || {}), O = m.main, i = { token: 0, inflight: null, committing: false, swapped: false, scrolls: /* @__PURE__ */ new Map(), renderedKey: location.pathname + location.search, loadedScripts: /* @__PURE__ */ new Map(), loadedInline: /* @__PURE__ */ new Map(), loadedStyles: /* @__PURE__ */ new Set(), cache: /* @__PURE__ */ new Map(), pending: /* @__PURE__ */ new Map(), prefetched: 0, hoverTimer: 0, touchIntent: null, progressTimer: 0, progressEl: null, liveEl: null, lastCardImage: null, placeholder: null, leaving: 0 }, $ = {};
function J(e, a) {
  return ($[e] = $[e] || []).push(a), () => {
    $[e] = ($[e] || []).filter((t) => t !== a);
  };
}
var ke = (e, a) => {
  for (let t of $[e] || []) try {
    t(a);
  } catch {
  }
};
function z(e) {
  let a = v.style.scrollBehavior;
  v.style.scrollBehavior = "auto", e > 0 && v.classList.add("dlc-restoring");
  try {
    c.scrollTo({ top: e, left: 0, behavior: "instant" });
  } catch {
    c.scrollTo(0, e);
  }
  v.style.scrollBehavior = a, e > 0 && c.requestAnimationFrame(() => c.requestAnimationFrame(() => v.classList.remove("dlc-restoring")));
}
var E = (e) => {
  let a = S(e);
  return a ? a.origin + a.pathname + a.search : String(e || "");
}, N = (e) => e.pathname + e.search, hk = (e) => String(e && e.href || e || "").split("#")[0];
function ne(e) {
  let a = String(e || "").toLowerCase();
  return (m.locked || []).some((t) => t && (a === t || a.indexOf(t) === 0 || a.indexOf(t + "/") !== -1));
}
function U(e) {
  let a = String(e || "").toLowerCase();
  return (m.hardPaths || []).some((t) => t && a.indexOf(t) !== -1);
}
function le(e) {
  let a = m.account;
  if (!a || !a.base) return false;
  let t = String(e || "").toLowerCase(), r = String(a.base).toLowerCase();
  return t === r || (a.paths || []).some((o) => o && t.indexOf(String(o).toLowerCase()) === 0);
}
function se(e) {
  return !!n.body && n.body.classList.contains("woocommerce-account") && n.body.classList.contains("logged-in") && !n.querySelector("[data-delicat-nav-full]") && le(e) && le(location.pathname);
}
function D(e) {
  let a = (e && e.pathname ? e.pathname : String(e || "")).toLowerCase();
  return (m.productPaths || []).some((t) => t && a.indexOf(t.toLowerCase()) !== -1);
}
function I(e) {
  let a = S(e);
  if (!a || a.origin !== location.origin || a.protocol !== "http:" && a.protocol !== "https:") return null;
  let t = a.pathname.toLowerCase();
  if (ne(t) || U(t) && !se(t) || /\.(pdf|zip|jpe?g|png|gif|webp|avif|svg|mp4|mp3|apk|ipa|csv|xlsx?|docx?)$/i.test(t)) return null;
  for (let r of m.blockParams || []) if (a.searchParams.has(r)) return null;
  return !m.products && D(a) ? null : a;
}
function Q(e) {
  return !(!e || !e.href || !e.getAttribute("href") || e.getAttribute("href").charAt(0) === "#" || e.hasAttribute("download") || e.target && e.target !== "" && e.target !== "_self" || /\b(external|nofollow-app)\b/.test(e.getAttribute("rel") || "") || e.hasAttribute("data-no-shell") || e.hasAttribute("data-dbp-skip") || e.hasAttribute("data-no-turbo") || e.hasAttribute("data-delicat-no-app") || e.getAttribute("data-delicat-nav") === "hard" || e.closest('[data-no-turbo],[data-delicat-nav="hard"],.no-prerender'));
}
var B = () => n.querySelector(O);
function Le(e, a, t) {
  if (!e || !a) return true;
  for (let r of m.hardClasses || []) if (e.classList.contains(r) && !(t && r === "woocommerce-account")) return true;
  return !!a.querySelector(t ? "form.checkout, form.woocommerce-cart-form, .woocommerce-checkout, [data-delicat-nav-full]" : "form.checkout, form.woocommerce-cart-form, .woocommerce-account, .woocommerce-checkout, [data-delicat-nav-full]");
}
function Te() {
  if (i.progressEl && i.progressEl.isConnected) return i.progressEl;
  let e = n.getElementById("dbp-progress") || n.querySelector(".dlc-progress");
  return e || (e = n.createElement("div"), e.className = "dlc-progress dbp-progress", e.id = "dbp-progress", e.setAttribute("aria-hidden", "true"), n.body.appendChild(e)), i.progressEl = e, e;
}
function Ae(e) {
  if (!i.inflight || !v.classList.contains("delicat-navigating-slow")) return;
  let a = B();
  !a || !(e.target instanceof Node) || !a.contains(e.target) || ae(e.target, "a[href]") || (e.preventDefault(), e.stopPropagation());
}
function ce() {
  v.classList.add("delicat-navigating"), c.clearTimeout(i.progressTimer), i.progressTimer = c.setTimeout(() => v.classList.add("delicat-navigating-slow"), 150);
  let e = Te();
  e.classList.remove("is-done"), e.classList.add("is-active");
}
function Z() {
  c.clearTimeout(i.progressTimer), v.classList.remove("delicat-navigating", "delicat-navigating-slow", "delicat-placeholder");
  let e = i.progressEl;
  e && (e.classList.remove("is-active"), e.classList.add("is-done"), c.setTimeout(() => e.classList.remove("is-done"), 260));
}
function Ce(e) {
  let a = i.liveEl || n.getElementById("dbp-live");
  a || (a = n.createElement("div"), a.id = "dbp-live", a.className = "dbp-sr-only dlc-sr-only", a.setAttribute("aria-live", "polite"), a.setAttribute("role", "status"), n.body.appendChild(a)), i.liveEl = a, a.textContent = e;
}
function de(e, a) {
  let t = hk(e);
  for (i.cache.delete(t), i.cache.set(t, Object.assign({ ts: Date.now(), ttl: a && a.private ? Math.min(m.cacheTtl, m.privateTtl) : m.cacheTtl }, a)); i.cache.size > (y.lowPower ? Math.min(6, m.cacheMax) : m.cacheMax); ) i.cache.delete(i.cache.keys().next().value);
}
function pe(e) {
  if (e.searchParams.has("delicat_product")) return null;
  let a = i.cache.get(hk(e));
  return a ? Date.now() - a.ts > (a.ttl || m.cacheTtl) ? (i.cache.delete(hk(e)), null) : a : null;
}
function ee() {
  i.cache.clear();
  for (let [, e] of i.pending) try {
    e.abort();
  } catch {
  }
  i.pending.clear();
}
async function ue(e, { signal: a, prefetch: t = false, fresh: r = false } = {}) {
  let o = new AbortController(), d = false, p = () => (d = true, o.abort(new Error("navigation-timeout"))), l = c.setTimeout(p, m.timeout);
  a && (a.aborted ? o.abort(a.reason) : a.addEventListener("abort", () => o.abort(a.reason), { once: true }));
  try {
    let s = { credentials: "same-origin", cache: "default", redirect: "follow", signal: o.signal, headers: { Accept: "text/html" } };
    if (t) {
      s.headers["X-Delicat-Prefetch"] = "1";
      try {
        s.priority = "low";
      } catch {
      }
    }
    r && (s.cache = "reload"), e.searchParams.has("delicat_product") && (s.cache = "no-store"), U(e.pathname.toLowerCase()) && (s.headers["X-Delicat-Nav"] = "1");
    let u = await fetch(e.href, s), f = u.headers.get("content-type") || "";
    if ((u.headers.get("x-delicat-nav") || "") === "full") throw new Error("full-required");
    if (t || (c.clearTimeout(l), l = c.setTimeout(p, 2e4)), !u.ok || f.indexOf("text/html") === -1) {
      let A = new Error("status-" + u.status);
      throw A.status = u.status, A;
    }
    let g = e;
    if (u.redirected) {
      let A = I(u.url);
      if (!A) {
        let x = new Error("redirected");
        throw x.redirect = u.url, x;
      }
      g = A;
    }
    let h = String(u.headers.get("cache-control") || "").toLowerCase(), b = /(?:^|[,\s])no-store(?:[,\s]|$)/.test(h), T = /(?:^|[,\s])private(?:[,\s]|$)/.test(h), k = Number(u.headers.get("content-length") || 0);
    if (t && k > m.maxBytes) {
      try {
        await u.body.cancel();
      } catch {
      }
      throw new Error("too-large");
    }
    let _ = await u.text();
    if (t && _.length > m.maxBytes) throw new Error("too-large");
    return { html: _, url: g, noStore: b || e.searchParams.has("delicat_product"), private: T };
  } catch (s) {
    s instanceof TypeError && !d && (s.network = true);
    throw d && s && s.name === "AbortError" ? new Error("navigation-timeout") : s;
  } finally {
    c.clearTimeout(l);
  }
}
function dlcOff(e, a) {
  let t = n.getElementById("dlc-offline");
  t || (t = n.createElement("div"), t.id = "dlc-offline", t.setAttribute("role", "status"), t.style.cssText = "position:fixed;left:16px;right:16px;bottom:88px;bottom:calc(88px + env(safe-area-inset-bottom, 0px));z-index:2147483000;display:flex;align-items:center;justify-content:space-between;gap:12px;max-width:480px;margin:0 auto;padding:10px 10px 10px 16px;border-radius:14px;background:#15172a;color:#fff;font:600 14px/1.35 Inter,system-ui,-apple-system,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.28);opacity:0;transition:opacity .2s ease", n.body.appendChild(t));
  t.textContent = "";
  let r = n.createElement("span"), o = n.createElement("button");
  r.textContent = "Pa gen koneksyon. V\xE9rifiez votre connexion.", o.type = "button", o.textContent = "R\xE9essayer", o.style.cssText = "flex:none;min-height:40px;padding:0 14px;border:0;border-radius:10px;background:#2563eb;color:#fff;font:inherit;cursor:pointer";
  o.addEventListener("click", () => {
    t.remove(), W(e, a);
  }), t.append(r, o), c.requestAnimationFrame(() => {
    t.style.opacity = "1";
  }), c.clearTimeout(t.__h), t.__h = c.setTimeout(() => {
    t.style.opacity = "0", c.setTimeout(() => t.isConnected && t.style.opacity === "0" && t.remove(), 260);
  }, 7e3), Ce("Pas de connexion internet");
}
function he() {
  for (let e of C('link[rel="stylesheet"][href]')) i.loadedStyles.add(E(e.href));
  for (let e of C("script[src]")) i.loadedScripts.set(E(e.src), true);
  for (let e of C("script[id]:not([src])")) i.loadedInline.set(e.id, e.textContent || "");
  for (let e of C("style:not([id]):not([data-dlc-sk])")) F(e) || e.setAttribute("data-dlc-sk", fe(e.getAttribute("media") || "", e.textContent || ""));
}
var Se = "link[rel~=stylesheet][href],link[rel=preload][as=style][href],style", fe = (e, a) => {
  let t = 2166136261, r = e + "|" + a;
  for (let o = 0; o < r.length; o++) t = Math.imul(t ^ r.charCodeAt(o), 16777619) >>> 0;
  return "h" + t.toString(36) + "." + r.length.toString(36);
}, F = (e) => !!(e.closest(O) || e.closest("template,noscript,svg,[data-dnp-express],[data-dpn-wallet-modal],[data-dnp-dock],[data-delicat-mobile-purchase],[data-dpn-checkout-dock]")), Ee = (e) => {
  if (e.localName === "link") return "l:" + E(e.href);
  if (e.id) return "i:" + e.id;
  let a = e.getAttribute("data-dlc-sk");
  return a ? "a:" + a : "";
};
function me(e) {
  let a = [];
  for (let t = e.parentElement; t && t.tagName !== "BODY" && t.tagName !== "HTML"; t = t.parentElement) a.push(t.tagName + "#" + (t.id || ""));
  return a.join(">");
}
function xe(e) {
  let a = [];
  for (let t of e.querySelectorAll(Se)) {
    if (F(t) || t.hasAttribute("disabled")) continue;
    if (t.tagName === "LINK") {
      let d = (t.getAttribute("rel") || "").toLowerCase(), p = t.getAttribute("onload") || "";
      if (d.indexOf("stylesheet") === -1 && !/stylesheet/.test(p)) continue;
      let l = t.getAttribute("media") || "all";
      /this\.media|media\s*=\s*['"]all/.test(p) && (l = "all"), a.push({ href: t.href, media: l, id: t.id || "" });
      continue;
    }
    let r = t.textContent || "";
    if (!r.trim()) continue;
    let o = t.getAttribute("media") || "";
    a.push({ id: t.id || "", code: r, media: o, key: t.id ? "i:" + t.id : "a:" + fe(o, r) });
  }
  return a;
}
function Pe(e) {
  if (e.hasAttribute("data-delicat-keep")) return false;
  if (e.hasAttribute("data-delicat-managed")) return true;
  if (e.localName === "link") {
    let a = S(e.href);
    return !!a && a.origin === location.origin && (/-css$/.test(e.id || "") || e.hasAttribute("data-optimized") || /\/wp-content\/(?:litespeed|cache)\//.test(a.pathname));
  }
  return !!e.id && /(?:-inline-css|-css)$|^(?:delicat-|dbp-|dbv9-|dsb|litespeed-|wp-custom-css)/.test(e.id);
}
function Ne(e) {
  return /\/assets\/dist\/(engine|chunks\/)[^?]*\.js/.test(e) || false;
}
function Ie(e, a) {
  let t = e.querySelector(O), r = e.body;
  if (!t || !r) throw new Error("main-missing");
  if (r.classList.contains("logged-in") !== n.body.classList.contains("logged-in")) throw new Error("session-changed");
  let o = !!a && r.classList.contains("woocommerce-account") && se(a.pathname || "");
  if (Le(r, t, o)) throw new Error("full-required");
  let d = B();
  if (d && me(t) !== me(d)) throw new Error("layout-changed");
  let p = e.querySelector('meta[name="dbp-generation"],meta[name="delicat-generation"]');
  if (m.generation && (!p || p.content !== m.generation)) throw new Error("generation-changed");
  let l = xe(e), s = [];
  for (let h of e.querySelectorAll("script")) {
    let b = (h.getAttribute("type") || "").toLowerCase(), T = h.getAttribute("src") || h.getAttribute("data-src") || "", k = !!h.closest(O);
    if (!(b && !/^(text\/javascript|application\/javascript|litespeed\/javascript|module)$/.test(b))) if (T) {
      let _ = new URL(T, e.baseURI || location.href).href, A = E(_);
      if (Ne(A) || i.loadedScripts.get(A)) continue;
      if (b === "module") {
        s.push({ src: _, id: h.id || "", module: true });
        continue;
      }
      if (!h.id) throw new Error("unknown-script-bundle");
      let x = n.getElementById(h.id);
      if (x && x.src && E(x.src) !== A) throw new Error("script-version-changed");
      s.push({ src: _, id: h.id, defer: h.defer, async: h.async });
    } else h.id && /-js-(extra|before|after|translations)$/.test(h.id) ? s.push({ inline: true, id: h.id, code: h.textContent || "" }) : k && h.textContent.trim() && s.push({ inline: true, id: "", code: h.textContent || "" });
  }
  if (o && s.some((h) => !h.inline)) throw new Error("full-required");
  for (let h of t.querySelectorAll("script")) {
    let b = (h.getAttribute("type") || "").toLowerCase();
    (!b || /^(text\/javascript|application\/javascript|litespeed\/javascript|module)$/.test(b)) && h.remove();
  }
  let u = e.querySelector('link[rel="canonical"]'), f = e.querySelector('meta[name="description"]'), g = /(?:^|\s)dbp-route-([a-z]+)/.exec(r.className);
  return { main: t, body: r, title: e.title || "", bodyClass: r.className || "", canonical: u ? u.href : "", description: f ? f.content : "", route: g ? g[1] : "page", styles: l, scripts: s, portals: (m.portals || []).map((h) => ({ selector: h, nodes: Array.from(e.querySelectorAll(h)).filter((b) => !b.closest(O)) })), templates: Array.from(e.querySelectorAll('script[type="text/template"][id], script[type="text/html"][id]')).filter((h) => !h.closest(O)), bottomNav: e.querySelector(".delicat-bottom-nav") };
}
var H = /* @__PURE__ */ new Map(), qe = () => C("link[rel~=stylesheet][href],style").filter((e) => !F(e)), ge = (e, a) => {
  if (a && a.isConnected && !F(a)) {
    a.insertAdjacentElement("afterend", e);
    return;
  }
  let t = n.head.querySelector('link[rel~="stylesheet"],style');
  t ? n.head.insertBefore(e, t) : n.head.appendChild(e);
};
function Me(e, a) {
  let t = E(e.href);
  if (H.has(t)) return H.get(t);
  let r = n.createElement("link");
  r.rel = "stylesheet", r.href = e.href, r.media = "not all", r.setAttribute("data-delicat-media", e.media || "all"), r.setAttribute("data-delicat-managed", "1"), e.id && !n.getElementById(e.id) && (r.id = e.id);
  let o = new Promise((p, l) => {
    let s = false, u = (g) => {
      s || (s = true, c.clearTimeout(f), H.delete(t), g ? (i.loadedStyles.add(t), p(r)) : (r.remove(), l(new Error("style-failed"))));
    }, f = c.setTimeout(() => u(true), 4e3);
    r.onload = () => u(true), r.onerror = () => u(false);
  });
  ge(r, a);
  let d = { node: r, promise: o };
  return H.set(t, d), d;
}
async function Oe(e) {
  let a = /* @__PURE__ */ new Set(), t = [], r = null, o = [], d = /* @__PURE__ */ new Map();
  for (let l of qe()) {
    let s = Ee(l);
    s && (d.has(s) ? d.get(s).push(l) : d.set(s, [l]));
  }
  for (let l of e) {
    let s = l.href ? "l:" + E(l.href) : l.key;
    if (!s || a.has(s)) continue;
    a.add(s);
    let u = d.get(s) || [], f = u[0] || null;
    if (l.href) if (f && f.localName === "link") {
      for (let h of u.slice(1)) h.localName === "link" && o.push({ node: h, media: l.media || "all" });
      let g = H.get(E(l.href));
      g && t.push(g.promise.catch(() => null)), o.push({ node: f, media: l.media || "all" });
    } else {
      let g = Me(l, r);
      f = g.node, t.push(g.promise.catch(() => null)), o.push({ node: f, media: l.media || "all" });
    }
    else f && f.localName === "style" ? o.push({ node: f, media: l.media, code: l.code }) : (f = n.createElement("style"), l.id && !n.getElementById(l.id) ? f.id = l.id : f.setAttribute("data-dlc-sk", l.key.slice(2)), f.setAttribute("data-delicat-managed", "1"), f.media = "not all", f.textContent = l.code, ge(f, r), o.push({ node: f, media: l.media }));
    r = f;
  }
  let p = [];
  for (let [l, s] of d) if (!a.has(l)) for (let u of s) Pe(u) && p.push(u);
  return await Promise.all(t), () => {
    for (let l of o) {
      let s = l.node;
      s.isConnected && (l.code !== void 0 && s.textContent !== l.code && (s.textContent = l.code), s.localName === "link" ? s.media !== l.media && (s.media = l.media) : (s.getAttribute("media") || "") !== (l.media || "") && (l.media ? s.media = l.media : s.removeAttribute("media")), s.disabled && (s.disabled = false), s.removeAttribute("data-delicat-inactive"));
    }
    for (let l of p) l.disabled || (l.disabled = true, l.setAttribute("data-delicat-inactive", "1"));
  };
}
var ct = (e) => /\/(?:plugins|mu-plugins)\/delicat-builder-v9\//.test(e) || /\/uploads\/delicat-builder-v9\//.test(e);
function De(e) {
  if (e.id) {
    if (i.loadedInline.get(e.id) === e.code) return;
    i.loadedInline.set(e.id, e.code);
    let t = n.getElementById(e.id);
    t && t.tagName === "SCRIPT" && t.remove();
  }
  let a = n.createElement("script");
  e.id && (a.id = e.id), a.text = e.code + `
//# sourceURL=` + (e.id ? "inline-" + e.id : "inline-main") + ".js", (e.id ? n.head : n.body).appendChild(a);
}
function Be(e) {
  let a = E(e.src);
  return i.loadedScripts.set(a, true), new Promise((t, r) => {
    let o = n.createElement("script");
    o.src = e.src, e.id && (o.id = e.id), e.module && (o.type = "module"), o.async = false;
    let d = c.setTimeout(() => {
      i.loadedScripts.delete(a), r(new Error("script-timeout"));
    }, 1e4);
    o.onload = () => {
      c.clearTimeout(d), t();
    }, o.onerror = () => {
      c.clearTimeout(d), i.loadedScripts.delete(a), r(new Error("script-failed"));
    }, n.body.appendChild(o);
  });
}
async function je(e, a) {
  for (let t of e) {
    if (a !== i.token) return;
    t.inline ? De(t) : await Be(t).catch((r) => {
      X.debug && console.warn("[delicat-engine] script failed after swap", t.src, r && r.message);
    });
  }
}
function Re(e) {
  for (let a of e) {
    if (a.inline) continue;
    let t = S(a.src);
    if (!t || t.origin !== location.origin) continue;
    let r = n.createElement("link");
    r.rel = a.module ? "modulepreload" : "preload", r.as = "script", r.href = t.href, n.head.appendChild(r);
  }
}
function Ye(e) {
  let a = /^(dlx-|dlc-theme-|dlc-session-|delicat-home-mode-|dnp-express-|dpn-wallet-modal-open|delicat-navigating|dbv9-assistant-open|dbv9-notification-open|delicat-pwa-|admin-bar|logged-in|dsb8-overlay-open|dsb-menu-open|delicat-shell-modal-open|delicat-reduce-motion|delicat-low-power|dlc-session)/, t = String(e || "").split(/\s+/).filter(Boolean).filter((d) => !/^(dlc-theme-|delicat-home-mode-)/.test(d));
  for (let d of Array.from(n.body.classList)) a.test(d) && t.indexOf(d) === -1 && t.push(d);
  let r = n.body.className, o = t.join(" ");
  r !== o && (n.body.className = o);
}
function Ke(e) {
  e.title && (n.title = e.title);
  let a = n.querySelector('link[rel="canonical"]');
  a && e.canonical && (a.href = e.canonical);
  let t = n.querySelector('meta[name="description"]');
  t && e.description && (t.content = e.description);
}
function $e(e) {
  if (e) for (let a of C(".delicat-bottom-nav .dbn-item[data-dbn-key]")) {
    let t = e.querySelector('.dbn-item[data-dbn-key="' + a.getAttribute("data-dbn-key") + '"]'), r = !!(t && t.classList.contains("is-active"));
    r && !a.classList.contains("is-active") && !y.reducedMotion && (a.classList.remove("is-popping"), a.offsetWidth, a.classList.add("is-popping"), c.setTimeout(() => a.classList.remove("is-popping"), 420)), a.classList.toggle("is-active", r), r ? a.setAttribute("aria-current", "page") : a.removeAttribute("aria-current");
  }
}
function be(e) {
  let a = E(e.href);
  for (let t of C("[data-dlx-drawer] a.dlx-item[href], .dsb8-header a[href][aria-current], .dsb8-header a.is-current[href]")) {
    let r = E(t.href) === a;
    t.classList.toggle("is-current", r), r ? t.setAttribute("aria-current", "page") : t.removeAttribute("aria-current");
  }
}
function ze(e) {
  for (let a of e) {
    if (!a.id) continue;
    let t = n.getElementById(a.id);
    if (t) {
      t.textContent !== a.textContent && (t.textContent = a.textContent);
      continue;
    }
    n.body.appendChild(n.importNode(a, true));
  }
}
function Ue(e) {
  for (let { selector: a, nodes: t } of e) {
    for (let r of C(a)) r.closest(O) || r.remove();
    for (let r of t) n.body.appendChild(n.importNode(r, true));
  }
}
function ve(e) {
  return e.querySelector("[data-dnp-hero] img, .dnp-gallery img, .delicat-native-product .woocommerce-product-gallery__image img, .delicat-native-product img.wp-post-image, .woocommerce-product-gallery__image:first-child img, .dnp-media img");
}
function dbvWakeLazy(e) {
  if (!e || !e.querySelectorAll) return;
  for (let a of e.querySelectorAll("img[data-lazyloaded]")) {
    let t = a.getAttribute("data-src"), r = a.getAttribute("data-srcset"), o = a.getAttribute("data-sizes");
    if (!t && !r) continue;
    a.hasAttribute("loading") || a.setAttribute("loading", "lazy");
    o && (a.setAttribute("sizes", o), a.removeAttribute("data-sizes"));
    r && (a.setAttribute("srcset", r), a.removeAttribute("data-srcset"));
    t && (a.setAttribute("src", t), a.removeAttribute("data-src"));
    a.removeAttribute("data-lazyloaded");
  }
}
function He(e, a, { restoreTo: t, push: r }) {
  let o = B();
  if (!o) return null;
  let d = n.importNode(e.main, true);
  dbvWakeLazy(d);
  return r && (history.scrollRestoration && (history.scrollRestoration = "manual"), history.pushState({ delicat: 1, dbv9: 1, dbp: 1, key: N(a), scroll: 0 }, "", a.href)), !r && typeof t != "number" && a.href !== location.href && (() => {
    try {
      history.replaceState({ delicat: 1, dbv9: 1, dbp: 1, key: N(a), scroll: 0 }, "", a.href);
    } catch {
    }
  })(), o.replaceWith(d), Ue(e.portals), ze(e.templates), Ye(e.bodyClass), Ke(e), $e(e.bottomNav), be(a), i.renderedKey = N(a), typeof t == "number" ? z(t) : a.hash || z(0), d.setAttribute("tabindex", "-1"), re(() => {
    d.isConnected && _e(d);
  }), i.swapped = true, d;
}
function Fe(e) {
  if (!e.hash || e.hash === "#") return;
  let a;
  try {
    a = decodeURIComponent(e.hash.slice(1));
  } catch {
    return;
  }
  let t = n.getElementById(a) || (n.getElementsByName ? n.getElementsByName(a)[0] : null);
  if (t && t.scrollIntoView) try {
    t.scrollIntoView({ behavior: "instant", block: "start" });
  } catch {
    t.scrollIntoView();
  }
}
function We() {
  return !(!m.transitions || !n.startViewTransition || y.reducedMotion || y.veryLowPower || y.lowPower || y.memory > 0 && y.memory <= 3);
}
async function Ve(e, a, t, r) {
  Re(e.scripts);
  let o = await Oe(e.styles);
  if (r !== i.token) return;
  let d = null, p = () => {
    if (v.classList.toggle("dlc-nav-back", t.dir === "back"), o(), d = He(e, a, t), !d) throw new Error("swap-failed");
    let u = D(a) ? ve(d) : null;
    u && (u.style.viewTransitionName = "delicat-product-image");
  };
  i.committing = true;
  try {
    if (We() && !t.slow) {
      v.setAttribute("data-dbp-transition", "1"), v.setAttribute("data-delicat-transition", D(a) ? "product" : t.placeholder || t.dir === "settle" ? "settle" : t.dir === "back" ? "back" : "forward");
      let u = (t.placeholder ? n.querySelector("[data-delicat-placeholder] .dph__hero img") : null) || i.lastCardImage;
      u && u.isConnected && (u.style.viewTransitionName = "delicat-product-image");
      let f = null, g = n.startViewTransition(() => {
        try {
          p();
        } catch (b) {
          f = b;
        }
      });
      g.ready && g.ready.catch(() => {
      });
      let h = () => {
        v.removeAttribute("data-dbp-transition"), v.removeAttribute("data-delicat-transition"), u && (u.style.viewTransitionName = "");
        let b = d && ve(d);
        b && (b.style.viewTransitionName = ""), i.lastCardImage = null;
      };
      if (await g.updateCallbackDone.catch(() => {
      }), g.finished.then(h, h), f) throw f;
    } else v.setAttribute("data-dbp-fade", "1"), p(), c.setTimeout(() => v.removeAttribute("data-dbp-fade"), 200);
  } finally {
    i.committing = false;
  }
  if (r !== i.token || (await je(e.scripts, r), r !== i.token)) return;
  ye();
  let l = { url: a.href, route: e.route, main: d, source: "engine" };
  ke("swapped", l), q("dsb:content-updated", { source: "pro-nav", url: a.href, main: d }), q("delicat:shell:navigated", l), q("delicat:navigation-complete", l), q("delicat:pro:navigated", l), q("delicat:navigated", l);
  let s = oe();
  if (s && s.fn && s.fn.wc_variation_form && s(d).find(".variations_form").each(function() {
    let u = s(this);
    u.data("wc_variation_form") || u.wc_variation_form();
  }), s) try {
    s(n.body).trigger("post-load");
  } catch {
  }
  if (q("delicat:pro:ready", l), Ce(e.title), typeof t.restoreTo != "number" && Fe(a), typeof c.gtag == "function") try {
    c.gtag("event", "page_view", { page_location: a.href, page_title: n.title });
  } catch {
  }
}
var te = (e) => String(e || "").replace(/[&<>"]/g, (a) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[a]);
function Xe() {
  return m.placeholder === false || y.veryLowPower || v.classList.contains("delicat-very-low-power") ? -1 : y.lowPower ? 220 : 140;
}
function Ge(e) {
  return D(e) ? "product" : /\/(?:product-category|categorie-produit|product-tag|etiquette-produit|shop|boutique)(?:\/|$)/i.test(e.pathname) || e.searchParams.has("s") ? "shop" : e.pathname === "/" || /^\/index\.php\/?$/.test(e.pathname) ? "home" : "page";
}
function Je(e, a) {
  let t = Ge(e), r = n.createElement("main");
  r.className = "delicat-placeholder-main delicat-placeholder-main--" + t, r.setAttribute("data-delicat-native-document", ""), r.setAttribute("data-delicat-placeholder", t), r.setAttribute("aria-busy", "true");
  let o = a ? a.closest("[data-delicat-card],.delicat-product-card,.delicat-woo-product-card,li.product,.dpsr-switcher-option") : null, d = (f) => {
    let g = o ? o.querySelector(f) : null;
    return g ? g.textContent.trim() : "";
  }, p = o ? o.querySelector("img") : null, l = p && (p.currentSrc || p.src) || "", s = '<div class="dph__card"><span class="dph__thumb"></span><span class="dph__bar dph__bar--w80"></span><span class="dph__bar dph__bar--w40"></span></div>', u = "";
  if (t === "product") {
    let f = d(".delicat-product-card__title, .delicat-product-card__name, .delicat-product-card__poster-title, .woocommerce-loop-product__title, h2, h3") || (p && p.alt ? p.alt : "") || a && a.getAttribute("aria-label") || "", g = d(".delicat-product-card__price, .price");
    u = '<div class="dph__hero">' + (l ? '<img src="' + te(l) + '" alt="" decoding="async">' : "") + '</div><div class="dph__body"><h1 class="dph__title">' + (f ? te(f) : '<span class="dph__bar dph__bar--w60 dph__bar--title"></span>') + "</h1>" + (g ? '<p class="dph__price">' + te(g) + "</p>" : '<span class="dph__bar dph__bar--w30"></span>') + '<div class="dph__chips"><span class="dph__chip"></span><span class="dph__chip"></span><span class="dph__chip"></span><span class="dph__chip"></span></div><span class="dph__bar dph__bar--btn"></span><span class="dph__bar"></span><span class="dph__bar dph__bar--w80"></span><span class="dph__bar dph__bar--w60"></span></div>';
  } else t === "shop" ? u = '<div class="dph__body"><span class="dph__bar dph__bar--w40 dph__bar--title"></span><div class="dph__chips"><span class="dph__chip"></span><span class="dph__chip"></span><span class="dph__chip"></span></div><div class="dph__grid">' + s.repeat(6) + "</div></div>" : t === "home" ? u = '<div class="dph__body"><span class="dph__hero dph__hero--home"></span><span class="dph__bar dph__bar--w40 dph__bar--title"></span><div class="dph__rail">' + s.repeat(3) + '</div><span class="dph__bar dph__bar--w40 dph__bar--title"></span><div class="dph__rail">' + s.repeat(3) + "</div></div>" : u = '<div class="dph__body"><span class="dph__bar dph__bar--w60 dph__bar--title"></span><span class="dph__bar"></span><span class="dph__bar dph__bar--w80"></span><span class="dph__bar"></span><span class="dph__bar dph__bar--w40"></span></div>';
  return r.innerHTML = '<div class="dph dph--' + t + '">' + u + "</div>", { main: r, route: t };
}
function Qe(e, a) {
  let t = B();
  if (!t) return false;
  let r;
  try {
    r = Je(e, a);
  } catch {
    return false;
  }
  let { main: o, route: d } = r;
  try {
    history.scrollRestoration && (history.scrollRestoration = "manual"), history.pushState({ delicat: 1, dbv9: 1, dbp: 1, key: N(e), scroll: 0 }, "", e.href);
  } catch {
    return false;
  }
  t.replaceWith(o);
  let p = Array.from(n.body.classList).filter((l) => !/^dbp-route-/.test(l));
  return p.push("dbp-route-" + d, "delicat-placeholder-active"), n.body.className = p.join(" "), v.classList.add("delicat-placeholder"), be(e), i.renderedKey = N(e), i.swapped = true, i.placeholder = { url: e.href, route: d }, z(0), re(() => {
    i.placeholder && (c.pageYOffset || 0) > 0 && z(0);
  }), q("delicat:placeholder", { url: e.href, route: d }), true;
}
function R(e, a) {
  try {
    i.scrolls.set(N(S(location.href)), c.pageYOffset);
  } catch {
  }
  try {
    history.scrollRestoration && (history.scrollRestoration = "auto");
  } catch {
  }
  let t = typeof e == "string" ? e : e.href;
  a ? location.replace(t) : location.assign(t);
}
function Ze(e) {
  let a = typeof e == "string" ? e : e.href, t = false, r = () => {
    t || (t = true, c.removeEventListener("popstate", r), R(a, false));
  };
  i.leaving = Date.now(), c.addEventListener("pageshow", () => {
    i.leaving = 0;
  }, { once: true }), c.addEventListener("popstate", r), c.setTimeout(r, 450);
  try {
    history.back();
  } catch {
    r();
  }
}
async function W(e, a = {}) {
  if (e instanceof URL || (e = S(e)), !e) return;
  if (navigator.onLine === false && !pe(e) && a.push !== false) {
    dlcOff(e, a);
    return;
  }
  if (c.__dlcChunkFailed || n.body.classList.contains("dlc-oc-page") || n.getElementById("dlc-oc-print") || n.querySelector("[data-delicat-nav-full]")) {
    R(e, a.push === false);
    return;
  }
  if (i.committing) {
    if (i.token++, i.inflight) try {
      i.inflight.abort();
    } catch {
    }
    R(e, a.push === false);
    return;
  }
  let t = ++i.token, r = { url: e.href, push: a.push !== false }, o = c.performance && c.performance.now ? c.performance.now() : Date.now();
  if (!q("delicat:navigate", r, n, true)) {
    R(e, a.push === false);
    return;
  }
  i.scrolls.set(i.renderedKey, c.pageYOffset), i.scrolls.size > 50 && i.scrolls.delete(i.scrolls.keys().next().value);
  try {
    history.replaceState(Object.assign({}, history.state || {}, { delicat: 1, dbv9: 1, scroll: c.pageYOffset || 0 }), "", location.href);
  } catch {
  }
  if (i.inflight) try {
    i.inflight.abort();
  } catch {
  }
  ce();
  let d = new AbortController();
  i.inflight = d;
  let p = e, l = false;
  try {
    let s = a.fresh ? null : pe(e);
    if (!s && navigator.onLine === false) {
      dlcOff(e, a);
      return;
    }
    if (!s) {
      let b = a.fresh || e.searchParams.has("delicat_product") ? null : i.pending.get(hk(e)), T = b && U(e.pathname.toLowerCase()) ? i.pending.delete(hk(e)) : 0, k = () => ue(e, { signal: d.signal, fresh: !!a.fresh }).then((P) => (P.noStore || de(P.url, P), P)), _ = b && b.promise ? b.promise.catch(() => null).then((P) => P || (Date.now() - (i.slowAt || 0) < 15e3 ? Promise.reject(new Error("navigation-timeout")) : k())) : k(), A = Xe(), x = A > 0 && D(e) && (!b || Date.now() - (b.at || 0) < 400) ? 0 : A;
      if (x > 0 && (s = await Promise.race([_.then((P) => P, () => null), new Promise((P) => c.setTimeout(() => P(null), x))]), t !== i.token) || !s && (x >= 0 && a.push !== false && (l = Qe(e, a.link || null)), s = await _, t !== i.token)) return;
    }
    if (t !== i.token) return;
    p = s.url || e, he();
    let u = new DOMParser().parseFromString(s.html, "text/html"), f = Ie(u, p), g = (c.performance && c.performance.now ? c.performance.now() : Date.now()) - o, h = Object.assign({}, a, { dir: a.restoreTo !== void 0 ? "back" : a.fresh ? "settle" : "forward", slow: g > 1e3 });
    await Ve(f, p, l ? Object.assign(h, { push: false, placeholder: true }) : h, t);
  } catch (s) {
    if (s && s.name === "AbortError" || t !== i.token) return;
    if (!l && s && s.network) {
      dlcOff(e, a);
      return;
    }
    X.debug && console.warn("[delicat-engine] navigation fell back to a full load:", s && s.message);
    let u = s && s.redirect ? s.redirect : p;
    l && a.push !== false ? Ze(u) : R(u, l || a.push === false || N(S(location.href)) === N(p));
  } finally {
    t === i.token && (i.inflight = null, i.committing = false, Z());
  }
}
function j(e, { force: a = false, intent: t = false } = {}) {
  if (m.prefetch === false) return null;
  let r = I(e);
  if (!r || r.searchParams.has("delicat_product") || G(r) || pe(r)) return null;
  if (!t && (/login|connexion|signin|register|inscription/i.test(r.hash) || N(r) === location.pathname + location.search)) return null;
  let o = U(r.pathname.toLowerCase()), pk = hk(r);
  if (o && !t) return null;
  if (i.pending.has(pk)) return i.pending.get(pk).promise;
  if (!a && (y.slowNetwork || i.prefetched >= (y.lowPower ? Math.min(4, m.budget) : m.budget) || i.pending.size >= 2) || a && y.saveData) return null;
  i.prefetched++;
  let d = new AbortController(), p = ue(r, { signal: d.signal, prefetch: true }).then((l) => (l.noStore || de(l.url, l), l)).catch((l) => (l && l.message === "navigation-timeout" && (i.slowAt = Date.now()), null)).finally(() => {
    if (!o) {
      i.pending.delete(pk);
      return;
    }
    c.setTimeout(() => {
      let l = i.pending.get(pk);
      l && l.promise === p && i.pending.delete(pk);
    }, 3e3);
  });
  return i.pending.set(pk, { promise: p, controller: d, at: Date.now() }), p;
}
function we(e) {
  return ae(e, "a[href]");
}
function et() {
  let e = (t) => {
    let r = we(t);
    return r && Q(r) ? r : null;
  };
  w(n, "pointerover", (t) => {
    if (t.pointerType === "touch") return;
    let r = e(t.target);
    r && (c.clearTimeout(i.hoverTimer), i.hoverTimer = c.setTimeout(() => j(r.href), 80));
  }, { passive: true }), w(n, "pointerout", (t) => {
    let r = e(t.target);
    !r || t.relatedTarget && r.contains(t.relatedTarget) || c.clearTimeout(i.hoverTimer);
  }, { passive: true }), w(n, "focusin", (t) => {
    let r = e(t.target);
    r && j(r.href);
  }), w(n, "pointerdown", (t) => {
    if (t.button || t.isPrimary === false) return;
    let r = e(t.target);
    if (r) {
      if (t.pointerType === "touch") {
        let o = !!r.closest("[data-delicat-track]");
        i.touchIntent = { link: r, x: t.clientX, y: t.clientY, timer: c.setTimeout(() => {
          i.touchIntent = null, j(r.href, { force: true, intent: true });
        }, o ? 140 : 70) };
        return;
      }
      j(r.href, { force: true, intent: true });
    }
  }, { passive: true, capture: true });
  let a = () => {
    i.touchIntent && (c.clearTimeout(i.touchIntent.timer), i.touchIntent = null);
  };
  w(n, "pointermove", (t) => {
    !i.touchIntent || t.pointerType !== "touch" || (Math.abs(t.clientX - i.touchIntent.x) > 8 || Math.abs(t.clientY - i.touchIntent.y) > 8) && a();
  }, { passive: true, capture: true }), w(n, "pointercancel", a, { passive: true, capture: true }), w(n, "pointerup", a, { passive: true, capture: true });
}
var M = null;
function bt() {
  let e = navigator.connection || navigator.mozConnection || navigator.webkitConnection, a = e ? String(e.effectiveType || "").toLowerCase() : "";
  return !(y.saveData || e && e.saveData || a !== "" && a !== "4g" || navigator.onLine === false || m.viewportBudget <= 0 || n.body && n.body.classList.contains("logged-in"));
}
function V() {
  if (!("IntersectionObserver" in c)) return;
  if (n.prerendering) {
    n.addEventListener("prerenderingchange", V, { once: true });
    return;
  }
  M && M.__stop && M.__stop(), M = null;
  if (!bt()) return;
  let t = false, r = y.slowNetwork, o = r ? 1 : y.lowPower ? 2 : 4, d = r ? 700 : y.lowPower ? 600 : 450, p = 0, l = [], s = /* @__PURE__ */ new Set(), u = /* @__PURE__ */ new Map(), f = false, g = () => {
    if (f || !l.length || p >= o || i.pending.size > 0 || n.hidden) return;
    let T = l.shift(), k = j(T, { force: true });
    k ? (p++, k.then(() => c.setTimeout(g, 120))) : g();
  }, h = new IntersectionObserver((T) => {
    for (let k of T) {
      let _ = k.target, A = _.__dlcHref;
      k.isIntersecting && k.intersectionRatio >= 0.5 ? u.has(_) || u.set(_, c.setTimeout(() => {
        u.delete(_), f || s.has(A) || (s.add(A), h.unobserve(_), l.push(A), g(), p >= o && h.disconnect());
      }, d)) : u.has(_) && (c.clearTimeout(u.get(_)), u.delete(_));
    }
  }, { threshold: [0, 0.5] });
  M = { __stop: () => {
    f = true, h.disconnect();
    for (let [, T] of u) c.clearTimeout(T);
    u.clear();
  }, __pump: g };
  let b = /* @__PURE__ */ new Set();
  for (let T of C("a[data-delicat-product-link],a[data-delicat-instant-product],.delicat-product-card a[href],.delicat-woo-product-card a[href],li.product a.woocommerce-LoopProduct-link")) {
    if (!Q(T)) continue;
    let k = I(T.href);
    if (!k || !D(k) || b.has(k.href)) continue;
    b.add(k.href);
    let _ = T.closest("[data-delicat-card],.delicat-product-card,.delicat-woo-product-card,li.product") || T;
    _.__dlcHref = k.href, h.observe(_);
  }
  V.__vis || (V.__vis = 1, w(n, "visibilitychange", () => {
    !n.hidden && M && M.__pump && M.__pump();
  }, { passive: true }));
}
function tt() {
  let e = 0;
  try {
    let p = c.performance && performance.getEntriesByType ? performance.getEntriesByType("navigation") : [], l = p && p[0] ? p[0].type === "back_forward" : c.performance && performance.navigation && performance.navigation.type === 2, s = history.state;
    l && s && (s.delicat || s.dbv9) && s.scroll > 0 && (e = s.scroll);
  } catch {
    e = 0;
  }
  history.scrollRestoration && (history.scrollRestoration = "manual");
  try {
    history.replaceState(Object.assign({}, history.state || {}, { delicat: 1, dbv9: 1, key: i.renderedKey, scroll: e || c.pageYOffset || 0 }), "", location.href);
  } catch {
  }
  if (!(e > 0)) return;
  let a = false, t = () => {
    a = true;
  };
  w(n, "wheel", t, { passive: true, once: true }), w(n, "touchmove", t, { passive: true, once: true });
  let r = 0, o = -1, d = () => {
    if (a) return;
    let p = c.pageYOffset || 0, l = o >= 0 ? o : 0;
    if (Math.abs(p - l) > 2 && Math.abs(p - e) > 2) {
      a = true;
      return;
    }
    z(e), o = c.pageYOffset || 0, ++r < 4 && Math.abs(o - e) > 2 && c.setTimeout(d, 120 * r);
  };
  n.readyState === "complete" ? d() : c.addEventListener("load", d, { once: true });
}
var L = { el: null, timer: 0, guard: 0, at: 0, tabs: null };
function Y() {
  if (c.clearTimeout(L.timer), c.clearTimeout(L.guard), L.timer = L.guard = 0, L.el && (L.el.remove(), L.el = null), L.tabs) {
    for (let [e, a, t] of L.tabs) e.classList.toggle("is-active", a), t ? e.setAttribute("aria-current", t) : e.removeAttribute("aria-current");
    L.tabs = null;
  }
  v.classList.contains("delicat-leaving") && (v.classList.remove("delicat-leaving"), Z());
}
function at(e) {
  let a = C(".delicat-bottom-nav .dbn-item[href]"), t = null, r = -1;
  for (let o of a) {
    let d = S(o.href);
    if (!d || d.origin !== e.origin) continue;
    let p = d.pathname.toLowerCase();
    p !== "/" && e.pathname.toLowerCase().indexOf(p) === 0 && p.length > r && (t = o, r = p.length);
  }
  if (!(!t || t.classList.contains("is-active"))) {
    L.tabs = a.map((o) => [o, o.classList.contains("is-active"), o.getAttribute("aria-current")]);
    for (let o of a) {
      let d = o === t;
      o.classList.toggle("is-active", d), d ? o.setAttribute("aria-current", "page") : o.removeAttribute("aria-current");
    }
    y.reducedMotion || (t.classList.remove("is-popping"), t.offsetWidth, t.classList.add("is-popping"), c.setTimeout(() => t.classList.remove("is-popping"), 420));
  }
}
function rt(e) {
  Y(), at(e), ce(), v.classList.add("delicat-leaving"), L.at = Date.now();
  let a = /\/(?:cart|panier|checkout|commande)(?:\/|$)/i.test(e.pathname) ? "cart" : "account", t = '<span class="dph__row"><span class="dph__thumb"></span><span class="dph__lines"><span class="dph__bar dph__bar--w60"></span><span class="dph__bar dph__bar--w40"></span></span></span>';
  L.timer = c.setTimeout(() => {
    if (L.timer = 0, !v.classList.contains("delicat-leaving")) return;
    let r = n.createElement("div");
    r.className = "dph-leave dph-leave--" + a, r.setAttribute("aria-hidden", "true");
    let o = n.querySelector(".dsb8-header, .delicat-shell--header"), d = 0;
    if (o) {
      let p = o.getBoundingClientRect();
      p.bottom > 0 && p.bottom < c.innerHeight / 2 && (d = Math.round(p.bottom));
    }
    r.style.top = d + "px", r.innerHTML = '<div class="dph dph--list"><div class="dph__body">' + (a === "account" ? '<span class="dph__profile"><span class="dph__avatar"></span><span class="dph__lines"><span class="dph__bar dph__bar--w60 dph__bar--title"></span><span class="dph__bar dph__bar--w40"></span></span></span>' : '<span class="dph__bar dph__bar--w40 dph__bar--title"></span>') + t.repeat(4) + '<span class="dph__bar dph__bar--btn"></span></div></div>', w(r, "pointerdown", () => {
      Date.now() - L.at > 1500 && Y();
    }, { passive: true }), n.body.appendChild(r), L.el = r;
  }, y.lowPower ? 200 : 110), L.guard = c.setTimeout(Y, 8e3);
}
function it() {
  let e = c.navigation;
  !e || typeof e.addEventListener != "function" || (w(e, "navigate", (a) => {
    if ((L.timer || L.el) && (a.navigationType === "traverse" || a.navigationType === "reload") && Y(), a.navigationType !== "push" || a.hashChange || a.downloadRequest || a.formData || !a.destination || a.destination.sameDocument !== false) return;
    let t = S(a.destination.url);
    if (!t || t.origin !== location.origin || t.search) return;
    let r = t.pathname.toLowerCase();
    !U(r) || ne(r) || /logout|lost-password/.test(r + t.search.toLowerCase()) || rt(t);
  }), w(e, "navigateerror", Y), w(c, "pageshow", Y));
}
function ot() {
  if (!m.enabled || !c.fetch || !c.DOMParser || !history.pushState) return false;
  he(), w(n, "click", (t) => {
    if (t.defaultPrevented || t.button !== 0 || t.metaKey || t.ctrlKey || t.shiftKey || t.altKey) return;
    let r = we(t.target);
    if (!r || !Q(r)) return;
    let o = I(r.href);
    if (!o) return;
    if (G(o)) {
      if (o.hash) return;
      t.preventDefault(), c.scrollTo({ top: 0, behavior: y.reducedMotion ? "auto" : "smooth" });
      return;
    }
    if (!B()) return;
    t.preventDefault();
    let d = r.closest("[data-delicat-card],.delicat-product-card,.delicat-woo-product-card,li.product,.dpsr-switcher-option");
    i.lastCardImage = d ? d.querySelector("img") : null, W(o, { push: true, link: r });
  }), m.forms && w(n, "submit", (t) => {
    let r = t.target;
    if (!(r instanceof HTMLFormElement) || t.defaultPrevented || (r.method || "get").toLowerCase() !== "get" || r.hasAttribute("data-no-shell") || r.closest('[data-no-turbo],[data-delicat-nav="hard"]') || !r.matches('[role="search"],.woocommerce-product-search,[data-delicat-search-form],.search-form,[data-delicat-nav="soft"]')) return;
    let o = S(r.getAttribute("action") || location.href);
    if (!o) return;
    let d = new URLSearchParams(new FormData(r));
    o.search = d.toString();
    let p = I(o.href);
    !p || !B() || (t.preventDefault(), W(p, { push: true }));
  }), w(c, "popstate", (t) => {
    if (i.leaving && Date.now() - i.leaving < 5e3 || !i.swapped || !B()) return;
    let r = S(location.href);
    if (!r || N(r) === i.renderedKey) return;
    if (!I(r.href)) {
      R(r, true);
      return;
    }
    let o = i.scrolls.get(N(r)), d = typeof o == "number" ? o : t.state && typeof t.state.scroll == "number" ? t.state.scroll : 0;
    W(r, { push: false, restoreTo: d });
  }), w(c, "pagehide", () => {
    try {
      history.replaceState(Object.assign({}, history.state || {}, { delicat: 1, dbv9: 1, scroll: c.pageYOffset || 0 }), "", location.href);
    } catch {
    }
  }), w(c, "pageshow", Z), it(), w(c, "storage", (t) => {
    t && /^(?:dip-session-change|dlc-session)/.test(String(t.key || "")) && ee();
  }), tt();
  let e = () => ee();
  for (let t of ["delicat:cart-changed", "delicat:wallet-changed", "delicat:currency-changed", "delicat:auth-changed", "delicat:themechange", "delicat:express-added", "added_to_cart", "removed_from_cart", "wc-blocks_added_to_cart", "wc-blocks_removed_from_cart"]) w(n, t, e);
  w(n, "delicat:pro:state", (t) => {
    t.detail && t.detail.changed && e();
  });
  let a = oe();
  if (a) try {
    a(n.body).on("added_to_cart removed_from_cart updated_cart_totals", e);
  } catch {
  }
  if (m.prefetch !== false) {
    et();
    let t = () => ie(V, 2500);
    if (n.readyState === "complete" ? c.setTimeout(t, 1200) : c.addEventListener("load", () => c.setTimeout(t, 1200), { once: true }), J("swapped", () => {
      i.prefetched = 0, c.setTimeout(t, 600);
    }), m.tabWarm !== false) {
      let r = () => n.prerendering ? n.addEventListener("prerenderingchange", r, { once: true }) : ie(() => {
        if (!(y.slowNetwork || y.saveData || n.hidden) && bt()) for (let o of C(".delicat-bottom-nav .dbn-item[href]")) {
          let d = I(o.href);
          d && !G(d) && j(d.href);
        }
      }, 4e3);
      n.readyState === "complete" ? c.setTimeout(r, 3500) : c.addEventListener("load", () => c.setTimeout(r, 3500), { once: true }), J("swapped", () => c.setTimeout(r, 2500));
    }
  }
  return v.classList.add("delicat-shell-nav", "delicat-engine-nav"), w(n, "click", Ae, { capture: true }), true;
}
var nt = { navigate: (e, a) => {
  let t = I(e);
  if (t) return W(t, Object.assign({ push: true }, a || {}));
  location.assign(e);
}, prefetch: (e) => j(e), invalidate: ee, eligible: I, isProductUrl: D, on: J, stats: () => ({ cached: i.cache.size, pending: i.pending.size, prefetched: i.prefetched, slow: y.slowNetwork, transport: "document" }) };
export {
  ot as a,
  nt as b
};
