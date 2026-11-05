import { b as V } from "./shared-d.js";
import { e as J } from "./shared-c.js";
import { a as Q, b as Z, c as ee } from "./shared-b.js";
import "./shared-a.js";
import { a, b as P, c as o, d as Y, e as b, g as K, i as I, l as U, u as j, z as G } from "./shared-base.js";
var te = "dbv9_annc";
function oe() {
  let n = [], d;
  try {
    d = Y('[data-delicat-assistant],[class*="assistant"],[id*="assistant"],[class*="chat"],[id*="chat"],[class*="support-widget"],[id*="support-widget"],iframe[title*="chat" i],iframe[title*="assistant" i]');
  } catch {
    d = [];
  }
  for (let e of d) {
    if (!e || e.closest && e.closest("[data-dbv9-announcement]")) continue;
    let l;
    try {
      l = o.getComputedStyle(e);
    } catch {
      l = null;
    }
    !l || l.position !== "fixed" && l.position !== "sticky" || (n.push({ node: e, display: e.style.getPropertyValue("display"), priority: e.style.getPropertyPriority("display") }), e.style.setProperty("display", "none", "important"));
  }
  return n;
}
var ce = (n) => {
  for (let d of n) !d.node || !d.node.style || (d.display ? d.node.style.setProperty("display", d.display, d.priority || "") : d.node.style.removeProperty("display"));
};
function de(n, d, e) {
  let l = a.getElementById("delicat-builder-v9-announcement-css");
  if (l && String(l.media || "").toLowerCase() === "print" && (l.media = "all"), !n || n.getAttribute("data-dbv9-annc-ready") === "1") return;
  n.setAttribute("data-dbv9-annc-ready", "1");
  let m = n.querySelector(".dbv9-annc__dialog");
  if (!m) return;
  a.body && n.parentNode !== a.body && a.body.appendChild(n);
  let w = n.getAttribute("data-sig") || "", h = n.getAttribute("data-frequency") || "days", y = parseInt(n.getAttribute("data-days"), 10);
  (isNaN(y) || y < 1) && (y = 7);
  let v = parseInt(n.getAttribute("data-delay"), 10);
  (isNaN(v) || v < 0) && (v = 0);
  let c = n.getAttribute("data-audience") || "all", L = () => {
    let r = e.session && e.session.get ? e.session.get() : null;
    return r && typeof r.loggedIn == "boolean" ? r.loggedIn : G();
  };
  if (c === "guests" && L() || c === "members" && !L() || h !== "always" && U.read(te) === w) return;
  let g = null, S = false, x = 0, k = [], N = () => {
    h !== "always" && U.write(te, w, h === "session" ? 0 : y * 86400);
  }, M = (r) => {
    if (r.key === "Escape" || r.key === "Esc") {
      r.preventDefault(), q();
      return;
    }
    if (r.key !== "Tab") return;
    let _ = j(m);
    if (!_.length) return;
    let i = _[0], f = _[_.length - 1];
    r.shiftKey && a.activeElement === i ? (r.preventDefault(), f.focus()) : !r.shiftKey && a.activeElement === f && (r.preventDefault(), i.focus());
  };
  function E() {
    if (S) return;
    S = true, g = a.activeElement, n.hidden = false, n.classList.remove("is-closing"), P.classList.add("dbv9-annc-lock"), Q("announcement"), k = oe(), I(() => I(() => n.classList.add("is-open")));
    let r = j(m);
    r.length && r[0].focus(), a.addEventListener("keydown", M, true);
  }
  function q() {
    S && (S = false, N(), n.classList.remove("is-open"), n.classList.add("is-closing"), P.classList.remove("dbv9-annc-lock"), Z("announcement"), ce(k), k = [], a.removeEventListener("keydown", M, true), x && o.clearTimeout(x), x = o.setTimeout(() => {
      if (n.classList.remove("is-closing"), n.hidden = true, g && g.focus) try {
        g.focus();
      } catch {
      }
    }, 320));
  }
  for (let r of Y("[data-dbv9-annc-dismiss]", n)) b(r, "click", (_) => {
    _.preventDefault(), q();
  }, { signal: d });
  b(m, "click", (r) => r.stopPropagation(), { signal: d });
  let A = n.querySelector("[data-dbv9-annc-go]");
  A && b(A, "click", N, { signal: d });
  let R = () => {
    v > 0 ? o.setTimeout(E, v) : E();
  };
  a.prerendering ? a.addEventListener("prerenderingchange", R, { once: true }) : R(), d.addEventListener("abort", () => {
    a.removeEventListener("keydown", M, true);
  }, { once: true });
}
function se({ signal: n, engine: d }) {
  for (let e of Y("[data-dbv9-announcement]")) de(e, n, d);
}
function ie({ signal: n, config: d, engine: e }) {
  let l = o.DelicatLiveSelling || d.liveSelling;
  if (!l || !l.endpoint) return;
  let m = () => o.matchMedia ? o.matchMedia("(max-width:899px)").matches : innerWidth < 900;
  if (m() && l.showMobile === false || !m() && l.showDesktop === false) return;
  let w = "dbv9_live_sale_seen_v1", h = 0, y = 0, v = false, c = null, L = 0, g = null, S = false, x = (t) => String(t == null ? "" : t).toLowerCase(), k = (t) => Array.isArray(t) ? t.map(x).filter(Boolean) : [], N = k(l.blockedRoutes), M = k(l.blockedPaths).filter((t) => t !== "/"), E = Array.isArray(l.allowedRoutes) ? k(l.allowedRoutes) : null, q = (t) => {
    let s = "/";
    try {
      s = x(decodeURIComponent(new URL(String(t || ""), location.href).pathname));
    } catch {
      return "/";
    }
    let p = x(l.basePath).replace(/\/+$/, "");
    return p && s.indexOf(p + "/") === 0 && (s = s.slice(p.length)), s = s.replace(/^\/+|\/+$/g, ""), s ? "/" + s + "/" : "/";
  }, A = (t) => {
    let s = q(t);
    return !M.some((p) => s.indexOf(p) === 0) && !(e.nav && e.nav.isProductUrl && false);
  }, R = (t) => {
    let s = x(t);
    return s ? N.indexOf(s) !== -1 ? false : !E || E.indexOf(s) !== -1 : true;
  }, r = !A(location.href), _ = () => U.read(w), i = (t) => U.write(w, String(t || ""), 2592e3), f = (t) => {
    try {
      let s = new URL(String(t || ""), location.href);
      return s.origin === location.origin ? s.href : "#";
    } catch {
      return "#";
    }
  }, T = (t) => {
    let s = Math.max(0, Math.floor(Date.now() / 1e3 - (Number(t) || 0)));
    if (s < 60) return "\xC0 l'instant";
    let p = Math.floor(s / 60);
    if (p < 60) return `Il y a ${p} min`;
    let u = Math.floor(p / 60);
    return u < 24 ? `Il y a ${u} h` : Math.floor(u / 24) < 2 ? "Hier" : "R\xE9cemment";
  }, D = (t) => {
    let s = String(l.messageTemplate || "Un client vient d\u2019acheter {product}");
    return s.includes("{product}") ? s.replace("{product}", String(t || "")) : `Un client vient d\u2019acheter ${String(t || "")}`;
  }, $ = (t) => {
    a.body.classList.toggle("dbv9-live-sale-visible", !!t), K("delicat:live-sale", { visible: !!t });
  }, ae = () => g || (g = new Promise((t) => {
    let s = String(l.styleUrl || "");
    if (!s) return t(true);
    let p = a.querySelector("link[data-dbv9-live-selling-style]");
    if (p && p.sheet) return t(true);
    let u = a.createElement("link");
    u.rel = "stylesheet", u.href = s, u.dataset.dbv9LiveSellingStyle = "1";
    let C = false, O = (z) => {
      C || (C = true, o.clearTimeout(H), u.onload = null, u.onerror = null, z || u.remove(), t(z));
    }, H = o.setTimeout(() => O(false), 4e3);
    u.onload = () => O(true), u.onerror = () => O(false), a.head.appendChild(u);
  }).then((t) => (t || (g = null), t)), g), re = () => {
    if (c && c.isConnected) return c;
    c = a.createElement("aside"), c.className = "dbv9-live-sale " + (l.position === "bottom_right" ? "dbv9-live-sale--bottom-right" : "dbv9-live-sale--bottom-left"), c.hidden = true, c.setAttribute("aria-live", "polite"), c.innerHTML = '<button class="dbv9-live-sale__close" type="button" aria-label="Fermer">\xD7</button><a class="dbv9-live-sale__link" href="#"><span class="dbv9-live-sale__media"><img class="dbv9-live-sale__image" width="48" height="48" alt="" loading="lazy" decoding="async"></span><span class="dbv9-live-sale__copy"><small></small><b></b><em></em></span><span class="dbv9-live-sale__arrow" aria-hidden="true">\u2192</span></a>', a.body.appendChild(c);
    let t = c.querySelector(".dbv9-live-sale__close");
    return t.hidden = l.showClose === false, b(t, "click", () => B(true), { signal: n }), c;
  };
  function B(t = false) {
    if (L && (o.clearTimeout(L), L = 0), !c || !c.isConnected) {
      $(false);
      return;
    }
    let s = c;
    s.classList.remove("is-visible"), o.setTimeout(() => {
      s.classList.contains("is-visible") || (s.hidden = true, $(false));
    }, 180), t && (s.dataset.dismissed = "1", X());
  }
  async function le(t) {
    if (!t || !t.id || !t.title || !await ae() || a.hidden || r) return;
    let s = re();
    if (s.dataset.dismissed === "1") return;
    let p = s.querySelector(".dbv9-live-sale__link"), u = s.querySelector(".dbv9-live-sale__image"), C = s.querySelector("small"), O = s.querySelector("b"), H = s.querySelector("em");
    p.href = f(t.url), C.textContent = String(l.label || "\u{1F525} Vente en direct"), O.textContent = D(t.title), H.textContent = T(t.created), t.image ? (u.src = t.image, u.closest(".dbv9-live-sale__media").hidden = false) : (u.removeAttribute("src"), u.closest(".dbv9-live-sale__media").hidden = true), s.hidden = false, I(() => {
      s.classList.add("is-visible"), $(true);
    }), L && o.clearTimeout(L), L = o.setTimeout(() => B(false), Math.min(15e3, Math.max(3e3, Number(l.displayDuration) || 6200))), i(t.id);
  }
  async function F() {
    if (r || v || a.hidden || navigator.onLine === false || c && c.dataset.dismissed === "1") return;
    v = true;
    let t = 0;
    try {
      let s = new URL(l.endpoint, location.href), p = _();
      p && s.searchParams.set("after", p);
      let u = new AbortController();
      t = o.setTimeout(() => u.abort(), 5e3);
      let C = await fetch(s.href, { credentials: "omit", headers: { Accept: "application/json" }, signal: u.signal });
      if (!C.ok) return;
      let O = await C.json(), H = Array.isArray(O.items) ? O.items : [];
      H.length && await le(H[0]);
    } catch {
    } finally {
      t && o.clearTimeout(t), v = false;
    }
  }
  function X() {
    h && (o.clearTimeout(h), h = 0), y && (o.clearInterval(y), y = 0);
  }
  function W() {
    if (r || h || y) return;
    let t = Math.min(6e4, Math.max(4e3, Number(l.initialDelay) || 9e3)), s = Math.min(18e4, Math.max(3e4, Number(l.pollInterval) || 45e3));
    h = o.setTimeout(() => {
      h = 0, S = true, F(), y = o.setInterval(F, s);
    }, t);
  }
  (() => {
    a.prerendering ? a.addEventListener("prerenderingchange", W, { once: true }) : W();
  })(), b(a, "visibilitychange", () => {
    !a.hidden && S && !r && (!c || c.dataset.dismissed !== "1") && F();
  }, { passive: true, signal: n }), b(a, "delicat:navigated", (t) => {
    let s = t && t.detail || {};
    R(s.route) && A(s.url || location.href) ? r && (r = false, (!c || c.dataset.dismissed !== "1") && W()) : (r = true, X(), B(false));
  }, { signal: n }), n.addEventListener("abort", () => {
    X(), c && c.remove();
  }, { once: true });
}
var ue = 72, fe = 104;
function ne({ signal: n, config: d }) {
  if (!d.pullRefresh && !P.classList.contains("dlx-pull-refresh") || !("ontouchstart" in o) && !(navigator.maxTouchPoints > 0)) return;
  let e = null, l = null, m = null, w = false, h = false, y = false, v = false, c = 0, L = 0, g = () => {
    if (e) return e;
    e = a.createElement("div"), e.className = "dlx-ptr", e.setAttribute("aria-hidden", "true"), e.style.position = "fixed", e.style.left = "50%", e.style.pointerEvents = "none", e.style.opacity = "0", l = a.createElement("div"), l.className = "dlx-ptr__ring", l.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>', e.appendChild(l);
    let i = a.createElement("div");
    return i.className = "dlx-ptr__check", i.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>', e.appendChild(i), a.body.appendChild(e), e;
  }, S = (i) => {
    let f = 0;
    for (; i && i !== a.body && i.nodeType === 1 && f < 12; ) {
      if (f++, i.scrollHeight > i.clientHeight + 1 || i.scrollWidth > i.clientWidth + 1) {
        let T = "";
        try {
          let D = o.getComputedStyle(i);
          T = D.overflowY + " " + D.overflowX;
        } catch {
        }
        if (/auto|scroll/.test(T)) return i;
      }
      i = i.parentNode;
    }
    return null;
  }, x = () => y || ee(), k = () => {
    c = 0;
    let i = Math.min(fe, L * 0.5);
    g(), e.style.transform = "translate3d(-50%," + i + "px,0)", e.style.opacity = String(Math.min(1, i / 44)), l.style.transform = "rotate(" + i * 3 + "deg)";
    let f = i >= ue * 0.5;
    f !== h && (h = f, e.classList.toggle("is-armed", h));
  }, N = (i) => {
    L = i, c || (c = I(k));
  }, M = () => {
    e && (e.classList.remove("is-armed"), e.classList.add("is-settling"), e.style.transform = "", e.style.opacity = "", o.setTimeout(() => {
      e && e.classList.remove("is-settling");
    }, 260));
  }, E = () => {
    try {
      location.reload();
    } catch {
      location.href = location.href;
    }
  }, q = () => {
    y = true, g(), e.classList.add("is-loading"), e.classList.remove("is-armed"), e.style.transform = "translate3d(-50%,64px,0)", e.style.opacity = "1", K("delicat:pull-refresh");
    let i = () => {
      y = false, e && (e.classList.remove("is-loading"), e.classList.add("is-done"), o.setTimeout(() => {
        e && (e.classList.remove("is-done"), M());
      }, 560));
    }, f = null;
    try {
      V.invalidate(), J.refresh("pull-refresh"), f = V.navigate(location.href, { push: false, fresh: true });
    } catch {
      E();
      return;
    }
    if (!f || typeof f.then != "function") return;
    let T = o.setTimeout(E, 15e3);
    f.then(() => {
      o.clearTimeout(T), i();
    }, () => {
      o.clearTimeout(T), E();
    });
  }, A = (i) => {
    if (!m || i.touches.length !== 1) return;
    let f = i.touches[0].clientY - m.y, T = i.touches[0].clientX - m.x;
    if (!w) {
      if (f < 12 || Math.abs(T) > Math.abs(f) * 0.8) {
        (f < -4 || Math.abs(T) > 12) && (m = null, r());
        return;
      }
      if ((o.pageYOffset || P.scrollTop || 0) > 0) {
        m = null, r();
        return;
      }
      w = true, g(), e.classList.add("is-active"), e.classList.remove("is-settling");
    }
    N(f <= 0 ? 0 : f), i.cancelable && i.preventDefault();
  }, R = () => {
    v || (v = true, a.addEventListener("touchmove", A, { passive: false }));
  }, r = () => {
    v && (v = false, a.removeEventListener("touchmove", A, { passive: false }));
  }, _ = () => {
    if (r(), !m) return;
    let i = h;
    m = null, w && (w = false, e && e.classList.remove("is-active"), i ? q() : M(), h = false);
  };
  b(a, "touchstart", (i) => {
    m = null, w = false, h = false, r(), !(i.touches.length !== 1 || x()) && ((o.pageYOffset || P.scrollTop || 0) > 0 || S(i.target) || (m = { x: i.touches[0].clientX, y: i.touches[0].clientY }, R()));
  }, { passive: true, signal: n }), b(a, "touchend", _, { passive: true, signal: n }), b(a, "touchcancel", _, { passive: true, signal: n }), b(o, "pageshow", () => {
    y = false, r(), e && (e.classList.remove("is-loading"), M());
  }, { signal: n }), n.addEventListener("abort", r, { once: true });
}
export {
  se as announcement,
  ie as liveSelling,
  ne as pullRefresh
};
