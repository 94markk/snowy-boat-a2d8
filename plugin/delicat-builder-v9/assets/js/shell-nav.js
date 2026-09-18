/* Delicat Shell Navigation (RC35). ES5, no jQuery required, memory only.
   Swaps <main> + page sheets under a View Transition; header, drawer, bottom
   bar, footer and launcher stay mounted. Follows WordPress's script contract
   for the next page (handle-js-extra/-before data, missing handle-js files).
   Anything unexpected → normal navigation. */
(function () {
  'use strict';
  if (window.DBPNavConfig || window.DBPNav) return;
  var cfg = window.DelicatShellNavConfig || {};
  if (!window.fetch || !window.DOMParser || !window.history || !window.history.pushState) return;
  if (window.DelicaBuilderV9Core && window.DelicaBuilderV9Core.cfg && window.DelicaBuilderV9Core.cfg.appNavigation) return; /* legacy app navigation owns this page */
  var doc = document, html = doc.documentElement;
  var MAIN = 'main[data-delicat-server-render],main[data-delicat-native-document]';
  var SHEETS = ['[data-dnp-express]', '[data-dpn-wallet-modal]'];
  var handleRe = new RegExp(cfg.handles || '^(jquery|woocommerce|wc-|delicat|dmc-)');
  var cache = {}; var cacheKeys = []; var prefetchPending = {}; var prefetchCount = 0; var CACHE_MAX = 12, CACHE_TTL = 60000;
  var loadedIds = {}; var inflight = null; var navId = 0; var hoverTimer = null; var progressTimer = null;
  var saveData = navigator.connection && navigator.connection.saveData;

  function currentMain() { return doc.querySelector(MAIN); }
  function blockedPath(path) {
    var list = cfg.blockedPaths || [];
    for (var i = 0; i < list.length; i++) if (path.indexOf(list[i]) !== -1) return true;
    return false;
  }
  function eligible(href) {
    var url; try { url = new URL(href, location.href); } catch (e) { return null; }
    if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return null;
    var path = url.pathname.toLowerCase();
    if (blockedPath(path)) return null;
    if (/\.(pdf|zip|jpe?g|png|gif|webp|svg|mp4|mp3|apk|ipa)$/i.test(path)) return null;
    var params = cfg.blockedParams || [];
    for (var i = 0; i < params.length; i++) if (url.searchParams.has(params[i])) return null;
    return url;
  }
  function sameDocument(url) { return url.pathname === location.pathname && url.search === location.search; }
  /* RC39: product pages carry WooCommerce's variation form, product fields, swatches, the ID
     verification and the express sheet — third-party scripts with their own lifecycle. They are
     always full document loads (still fast: prerender, private cache, service worker, cross-document
     View Transitions). The shell swaps only Builder-owned surfaces: home, shop, categories, information, search. */
  function productLike(url) {
    var path = (url && url.pathname ? url.pathname : String(url || '')).toLowerCase();
    var bases = cfg.productPaths || ['/product/', '/produit/'];
    for (var i = 0; i < bases.length; i++) if (bases[i] && path.indexOf(bases[i]) !== -1) return true;
    return false;
  }
  function formPage(node) { return !!(node && node.querySelector('form.cart, form.variations_form, form.checkout, form.woocommerce-cart-form, .woocommerce-account, .dmc-calc, .delicat-native-product')); }
  function shellActiveHere() { return !!currentMain() && !blockedPath(location.pathname.toLowerCase()) && !productLike(location) && !formPage(doc); }

  /* ---------- memory cache + prefetch ---------- */
  function remember(key, html) {
    cache[key] = { html: html, ts: Date.now() };
    var existingIndex = cacheKeys.indexOf(key);
    if (existingIndex !== -1) cacheKeys.splice(existingIndex, 1);
    cacheKeys.push(key);
    if (cacheKeys.length > CACHE_MAX) { var old = cacheKeys.shift(); if (old !== key) delete cache[old]; }
  }
  function recall(key) { var hit = cache[key]; if (hit && Date.now() - hit.ts < CACHE_TTL) return hit.html; if (hit) delete cache[key]; return null; }
  function fetchDoc(url, signal) {
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var abort = function () { if (controller) controller.abort(); };
    if (signal) { if (signal.aborted) abort(); else signal.addEventListener('abort', abort, { once: true }); }
    var timer;
    var deadline = new Promise(function (resolve, reject) {
      timer = setTimeout(function () { reject(new Error('document timeout')); abort(); }, 8000);
    });
    var request = fetch(url.href, { credentials: 'same-origin', signal: controller ? controller.signal : signal, headers: { 'X-Delicat-Shell': '1', 'Accept': 'text/html' } })
      .then(function (res) {
        var type = res.headers.get('content-type') || '';
        if (!res.ok || type.indexOf('text/html') === -1) throw new Error('bad response');
        if (res.redirected) { var final = eligible(res.url); if (!final || !sameDocument(final) && final.href !== url.href) { /* redirected elsewhere: keep the final URL */ url.href = res.url; } }
        return res.text();
      });
    var cleanup = function () { clearTimeout(timer); if (signal) signal.removeEventListener('abort', abort); };
    return Promise.race([request, deadline]).then(function (text) { cleanup(); return text; }, function (error) { cleanup(); throw error; });
  }
  /* RC45: product documents are deliberately never shell-swapped (variation form,
     product fields, swatches, express sheet — third-party lifecycles). They were also
     never prefetched, so every product tap — the most common tap on this storefront,
     since each card CTA is a product link — paid full network latency. Warm the
     document itself with a native <link rel=prefetch>: the browser fills its HTTP
     cache, no JS state, no custom header, nothing to fall out of sync. Capped and
     deduplicated so a long carousel scroll cannot flood a slow Haitian connection. */
  /* RC49: this budget is per PAGE, and in a shell the page changes without the
     script ever reloading — so it must be reset explicitly on every swap.
     Left at module scope it silently became a per-SESSION cap: after six warmed
     product links a visitor got no prefetching at all for the rest of their
     visit, however many category pages they browsed. The <link> nodes also
     accumulated in <head> for the whole session, since nothing ever removed
     them. resetDocWarm() is called from afterSwap(). */
  var docWarm = {}; var docWarmCount = 0; var DOC_WARM_MAX = 10;
  /* RC60: Safari (every iPhone) has never implemented <link rel=prefetch>, so
     the document and asset warm-ups below did nothing there — the platform this
     store's clients use most got a cold load on every product tap. When link
     prefetch is unsupported, warm through fetch(): the service worker stores a
     shell-header fetch as a document (its cache key is the URL, so the tap's
     navigation is served from it), and versioned assets land in its static
     cache. Without a controlling service worker, guest HTML carries no browser
     cache lifetime, so a document warm would be wasted — only assets are warmed. */
  var linkPrefetch = (function () { try { var l = doc.createElement('link'); return !!(l.relList && l.relList.supports && l.relList.supports('prefetch')); } catch (e) { return false; } })();
  function swControls() { try { return !!(navigator.serviceWorker && navigator.serviceWorker.controller); } catch (e) { return false; } }
  function warmViaFetch(href, asDocument) {
    try {
      var init = { credentials: 'same-origin', cache: 'default', redirect: 'follow' };
      if (asDocument) init.headers = { 'X-Delicat-Shell': '1', 'Accept': 'text/html' };
      try { init.priority = 'low'; } catch (e) {}
      var p = fetch(href, init);
      p = p.then(function (r) { if (!r || !r.ok) return; return r.arrayBuffer(); }).catch(function () {});
      return p;
    } catch (e) { return null; }
  }
  function warmAsset(href) {
    if (linkPrefetch) { var l = doc.createElement('link'); l.rel = 'prefetch'; l.as = /\.css(\?|$)/.test(href) ? 'style' : 'script'; l.href = href; doc.head.appendChild(l); return; }
    warmViaFetch(href, false);
  }
  var docWarmNodes = [];
  function resetDocWarm() {
    for (var i = 0; i < docWarmNodes.length; i++) {
      var n = docWarmNodes[i];
      if (n && n.parentNode) n.parentNode.removeChild(n);
    }
    docWarmNodes = []; docWarm = {}; docWarmCount = 0;
  }
  function slowNetwork() {
    var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (doc.hidden || navigator.onLine === false) return true;
    if (!c) return false;
    var t = String(c.effectiveType || '').toLowerCase();
    return !!c.saveData || t === 'slow-2g' || t === '2g' || t === '3g'
      || (typeof c.downlink === 'number' && c.downlink > 0 && c.downlink < 1.5);
  }
  function warmDocument(url) {
    if (docWarmCount >= DOC_WARM_MAX || docWarm[url.href] || slowNetwork()) return;
    if (linkPrefetch) {
      docWarm[url.href] = 1; docWarmCount++;
      var l = doc.createElement('link');
      l.rel = 'prefetch'; l.as = 'document'; l.href = url.href;
      doc.head.appendChild(l); docWarmNodes.push(l);
      return;
    }
    if (!swControls()) return;
    docWarm[url.href] = 1; docWarmCount++;
    warmViaFetch(url.href, true);
  }
  function prefetch(href) {
    if (slowNetwork()) return;
    var url = eligible(href); if (!url || sameDocument(url)) return;
    if (productLike(url)) { warmDocument(url); return; }
    if (recall(url.href) || prefetchPending[url.href] || prefetchCount >= 2) return;
    var key = url.href, controller = typeof AbortController === 'function' ? new AbortController() : null;
    prefetchCount++;
    var timeout;
    var deadline = new Promise(function (resolve, reject) {
      timeout = setTimeout(function () { if (controller) controller.abort(); reject(new Error('prefetch timeout')); }, 5000);
    });
    var pending = Promise.race([fetchDoc(url, controller ? controller.signal : undefined), deadline])
      .then(function (text) { remember(key, text); return text; });
    prefetchPending[key] = pending;
    var cleanup = function () { clearTimeout(timeout); delete prefetchPending[key]; prefetchCount--; };
    pending.then(cleanup, cleanup);
  }

  /* ---------- assets of the next page ---------- */
  function runInline(node) {
    if (!node) return;
    var s = doc.createElement('script');
    if (node.id) s.id = node.id;
    if (node.type && node.type !== 'text/javascript') s.type = node.type;
    s.text = node.textContent;
    doc.head.appendChild(s);
  }
  function loadExternal(node) {
    return new Promise(function (resolve, reject) {
      var s = doc.createElement('script');
      s.id = node.id; s.src = node.src; s.async = false;
      for (var i = 0; i < node.attributes.length; i++) { var a = node.attributes[i]; if (a.name.indexOf('data-') === 0) s.setAttribute(a.name, a.value); }
      s.onload = function () { resolve(); }; s.onerror = function () { reject(new Error('script ' + node.id)); };
      doc.head.appendChild(s);
    });
  }
  function ensureStyles(incoming) {
    var links = incoming.querySelectorAll('link[rel="stylesheet"][id]');
    var waits = [];
    for (var i = 0; i < links.length; i++) {
      var link = links[i], id = link.id.replace(/-css$/, '');
      if (!handleRe.test(id) || doc.getElementById(link.id)) continue;
      waits.push(new Promise(function (resolve) {
        var el = doc.createElement('link'); el.rel = 'stylesheet'; el.id = link.id; el.href = link.href; el.media = link.media || 'all';
        el.onload = resolve; el.onerror = resolve; doc.head.appendChild(el);
        setTimeout(resolve, 1500);
      }));
    }
    /* inline styles printed by the plugin for that page (e.g. presentation variables) */
    var styles = incoming.querySelectorAll('style[id]');
    for (var j = 0; j < styles.length; j++) {
      var st = styles[j]; if (!handleRe.test(st.id.replace(/-inline-css$/, ''))) continue;
      var existing = doc.getElementById(st.id);
      if (existing) { if (existing.textContent !== st.textContent) existing.textContent = st.textContent; }
      else { var copy = doc.createElement('style'); copy.id = st.id; copy.textContent = st.textContent; doc.head.appendChild(copy); }
    }
    return Promise.all(waits);
  }
  function ensureScripts(incoming) {
    var scripts = incoming.querySelectorAll('script[id]');
    var chain = Promise.resolve();
    for (var i = 0; i < scripts.length; i++) {
      (function (node) {
        var id = node.id; var m = /^(.*)-js(-extra|-before|-after|-translations)?$/.exec(id);
        if (!m || !handleRe.test(m[1])) return;
        var suffix = m[2] || '';
        if (node.src) {
          if (doc.getElementById(id) || loadedIds[id]) return;
          chain = chain.then(function () { loadedIds[id] = 1; return loadExternal(node); });
        } else if (suffix === '-extra' || suffix === '-before' || suffix === '-translations') {
          chain = chain.then(function () { runInline(node); });               /* page data: always fresh */
        } else if (suffix === '-after') {
          chain = chain.then(function () { runInline(node); });
        }
      }(scripts[i]));
    }
    return chain;
  }

  /* ---------- swap ---------- */
  function syncBody(incoming) {
    var keep = /^(dlx-|dnp-express-open|dnp-express-busy|dnp-express-ready|dpn-wallet-modal-open|delicat-navigating|dlc-session|admin-bar|logged-in)/;
    var next = (incoming.body.getAttribute('class') || '').split(/\s+/).filter(Boolean);
    var cur = (doc.body.getAttribute('class') || '').split(/\s+/).filter(Boolean);
    for (var i = 0; i < cur.length; i++) if (keep.test(cur[i]) && next.indexOf(cur[i]) === -1) next.push(cur[i]);
    doc.body.className = next.join(' ');
  }
  function syncHead(incoming) {
    doc.title = incoming.title || doc.title;
    var can = doc.querySelector('link[rel="canonical"]'), nc = incoming.querySelector('link[rel="canonical"]');
    if (can && nc) can.href = nc.href;
    var desc = doc.querySelector('meta[name="description"]'), nd = incoming.querySelector('meta[name="description"]');
    if (desc && nd) desc.content = nd.content;
  }
  function syncBottomNav(incoming) {
    var items = doc.querySelectorAll('.delicat-bottom-nav .dbn-item[data-dbn-key]');
    for (var i = 0; i < items.length; i++) {
      var key = items[i].getAttribute('data-dbn-key');
      var next = incoming.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="' + key + '"]');
      var active = !!(next && next.classList.contains('is-active'));
      items[i].classList.toggle('is-active', active);
      if (active) items[i].setAttribute('aria-current', 'page'); else items[i].removeAttribute('aria-current');
    }
  }
  /* RC50: syncHead only carried title, canonical and description across a swap.
     Stylesheets were never reconciled, so any page whose CSS is enqueued
     conditionally rendered unstyled after a shell navigation. native-archive.css
     (9.4 KB) is enqueued only when the archive builder is active and is in
     neither always-loaded bundle, so home -> "Voir tout" -> /product-category/...
     swapped in archive markup with no archive stylesheet. RC45 pointed the
     ranking rail at /shop/?orderby=popularity, another archive, which widened
     the exposure.

     Adopt any stylesheet the incoming document has that this one lacks, keyed by
     resolved href. Existing sheets are left alone: dropping them would restyle
     the page mid-swap and break the back navigation that needs them again. */
  function adoptStyles(incoming) {
    var have = {}, cur = doc.querySelectorAll('link[rel~="stylesheet"][href]');
    for (var i = 0; i < cur.length; i++) have[cur[i].href] = 1;
    var next = incoming.querySelectorAll('link[rel~="stylesheet"][href]');
    for (var j = 0; j < next.length; j++) {
      var href = next[j].href;
      if (!href || have[href]) continue;
      have[href] = 1;
      var l = doc.createElement('link');
      l.rel = 'stylesheet';
      l.href = href;
      var media = next[j].getAttribute('media');
      if (media) l.media = media;
      doc.head.appendChild(l);
    }
  }
  function swapSheets(incoming) {
    for (var i = 0; i < SHEETS.length; i++) {
      var cur = doc.querySelector(SHEETS[i]), next = incoming.querySelector(SHEETS[i]);
      if (cur) cur.parentNode.removeChild(cur);
      if (next) doc.body.appendChild(doc.importNode(next, true));
    }
  }
  function runMainScripts(main) {
    var inline = main.querySelectorAll('script');
    for (var i = 0; i < inline.length; i++) { var n = inline[i]; if (n.src) continue; var s = doc.createElement('script'); s.text = n.textContent; n.parentNode.replaceChild(s, n); }
  }
  function afterSwap(url, main) {
    /* New page: release the previous page's warm budget and its <link> nodes.
       First statement in the function so a throwing listener below cannot leave
       the budget exhausted for the rest of the session. */
    resetDocWarm();
    try { doc.dispatchEvent(new CustomEvent('dsb:content-updated', { detail: { source: 'shell-nav', url: url.href } })); } catch (e) {}
    try { doc.dispatchEvent(new CustomEvent('delicat:shell:navigated', { detail: { url: url.href } })); } catch (e) {}
    try { doc.dispatchEvent(new CustomEvent('delicat:navigation-complete', { detail: { url: url.href } })); } catch (e) {}
    if (window.jQuery) {
      try {
        var $ = window.jQuery;
        if ($.fn && $.fn.wc_variation_form) $(main).find('.variations_form').each(function () { $(this).wc_variation_form(); });
        $(doc.body).trigger('post-load');
      } catch (e) {}
    }
    if (typeof window.gtag === 'function') { try { window.gtag('event', 'page_view', { page_location: url.href, page_title: doc.title }); } catch (e) {} }
  }
  function performSwap(incoming, url, mode, scrollTop) {
    var current = currentMain(), next = incoming.querySelector(MAIN);
    var fresh = doc.importNode(next, true);
    var doSwap = function () {
      /* Before replaceChild: gives the browser a head start on any newly
         required stylesheet so the incoming markup is not painted bare. */
      adoptStyles(incoming);
      current.parentNode.replaceChild(fresh, current);
      swapSheets(incoming);
      syncBody(incoming);
      syncHead(incoming);
      syncBottomNav(incoming);
      runMainScripts(fresh);
      if (mode === 'push') { history.pushState({ dbv9: 1, scroll: 0 }, '', url.href); window.scrollTo(0, 0); }
      else { window.scrollTo(0, scrollTop || 0); }
    };
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (doc.startViewTransition && !reduced) {
      var t = doc.startViewTransition(doSwap);
      return t.finished.catch(function () {}).then(function () { afterSwap(url, fresh); });
    }
    doSwap(); afterSwap(url, fresh); return Promise.resolve();
  }

  function navigate(url, mode, scrollTop) {
    /* RC55: a Back/Forward traversal has already moved the history pointer, so a
       full-load fallback there must replace the current entry, not push a new one
       (location.href duplicated the entry and Back then went to the same page). */
    var fullLoad = function () { if (mode === 'pop') location.replace(url.href); else location.href = url.href; };
    if (!shellActiveHere()) { fullLoad(); return; }
    var id = ++navId;
    if (inflight) { try { inflight.abort(); } catch (e) {} }
    inflight = new AbortController();
    history.replaceState({ dbv9: 1, scroll: window.pageYOffset || 0 }, '', location.href);
    html.classList.add('delicat-navigating');
    clearTimeout(progressTimer);
    progressTimer = setTimeout(function () { html.classList.add('delicat-navigating-slow'); }, 120);
    var cached = recall(url.href);
    var htmlPromise = cached ? Promise.resolve(cached) : (prefetchPending[url.href] || fetchDoc(url, inflight.signal)).then(function (h) { remember(url.href, h); return h; });
    htmlPromise.then(function (htmlText) {
      if (id !== navId) return;
      var incoming = new DOMParser().parseFromString(htmlText, 'text/html');
      var next = incoming.querySelector(MAIN);
      if (!next || !currentMain() || incoming.querySelector('body.dpn-checkout,body.dpn-cart,body.woocommerce-account,body.single-product,body.delicat-native-product-document') || formPage(incoming)) { fullLoad(); return; }
      return ensureStyles(incoming).then(function () { return ensureScripts(incoming); }).then(function () {
        if (id !== navId) return;
        return performSwap(incoming, url, mode, scrollTop);
      });
    }).catch(function (err) {
      if (id !== navId) return;
      if (err && err.name === 'AbortError') return;
      fullLoad();
    }).then(function () {
      if (id !== navId) return;
      clearTimeout(progressTimer);
      html.classList.remove('delicat-navigating'); html.classList.remove('delicat-navigating-slow');
      inflight = null;
    });
  }

  /* ---------- bindings ---------- */
  doc.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var t = e.target; while (t && t.nodeType === 1 && t.tagName !== 'A') t = t.parentNode;
    if (!t || t.tagName !== 'A' || !t.getAttribute('href')) return;
    if (t.target || t.hasAttribute('download') || t.hasAttribute('data-no-shell') || t.hasAttribute('data-delicat-no-app') || /external|nofollow-app/.test(t.getAttribute('rel') || '')) return;
    var href = t.getAttribute('href'); if (href.charAt(0) === '#') return;
    var url = eligible(t.href); if (!url) return;
    if (sameDocument(url)) { if (url.hash) return; e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    if (productLike(url) || !shellActiveHere()) return;
    e.preventDefault();
    navigate(url, 'push', 0);
  });
  window.addEventListener('popstate', function (e) {
    if (!e.state || !e.state.dbv9) return;
    var url = eligible(location.href); if (!url) { location.reload(); return; }
    navigate(url, 'pop', e.state.scroll || 0);
  });
  doc.addEventListener('touchstart', function (e) { var t = e.target; while (t && t.nodeType === 1 && t.tagName !== 'A') t = t.parentNode; if (t && t.tagName === 'A' && t.href) prefetch(t.href); }, { passive: true, capture: true });
  doc.addEventListener('mouseover', function (e) { var t = e.target; while (t && t.nodeType === 1 && t.tagName !== 'A') t = t.parentNode; if (!t || t.tagName !== 'A' || !t.href) return; clearTimeout(hoverTimer); hoverTimer = setTimeout(function () { prefetch(t.href); }, 90); }, { passive: true });
  /* RC55: scrollRestoration is switched to 'manual' on every page so the shell can
     restore its own positions, but a full document load reached by Back/Forward
     (product page -> back to home/category: products are never shell-swapped) is
     a fresh document: no popstate fires, the browser will not restore because the
     entry is 'manual', and the old init line replaced the entry state with
     scroll:0 before anyone read it. The visitor landed at the top of a page they
     had scrolled halfway down. Read the state the previous visit left (pagehide
     writes it), keep it, and apply it once the document is laid out. */
  var arrivalScroll = 0;
  try {
    var arrivalState = history.state;
    var byHistory = false;
    var navEntries = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
    if (navEntries && navEntries[0]) byHistory = navEntries[0].type === 'back_forward';
    else if (window.performance && performance.navigation) byHistory = performance.navigation.type === 2;
    if (byHistory && arrivalState && arrivalState.dbv9 && arrivalState.scroll > 0) arrivalScroll = arrivalState.scroll;
  } catch (e) { arrivalScroll = 0; }
  if (history.scrollRestoration) history.scrollRestoration = 'manual';
  history.replaceState({ dbv9: 1, scroll: arrivalScroll || (window.pageYOffset || 0) }, '', location.href);
  if (arrivalScroll > 0) {
    var userScrolled = false;
    var cancelRestore = function () { userScrolled = true; };
    doc.addEventListener('wheel', cancelRestore, { passive: true, once: true });
    doc.addEventListener('touchmove', cancelRestore, { passive: true, once: true });
    var restoreAttempts = 0;
    var landedAt = -1;
    var restore = function () {
      if (userScrolled) return;

      /*
       * Has anything moved the page since our last attempt? If so it was the
       * visitor, because content growing BELOW the viewport does not move the
       * scroll position on its own.
       *
       * This is the check wheel and touchmove cannot make. Neither fires for a
       * scrollbar drag or a keyboard, and neither fires for a finger that
       * started scrolling before this script was listening - which is the
       * ordinary case on a slow connection, where the markup arrives seconds
       * before the script does. Measured without it: on a page still growing,
       * a visitor who scrolled to 200px was thrown to 1400px a moment later.
       *
       * Standing down wrongly costs a restore. Restoring wrongly throws the
       * page out from under someone who is reading it.
       */
      var y = (window.pageYOffset || 0);
      var expected = landedAt >= 0 ? landedAt : 0;
      if (Math.abs(y - expected) > 2 && Math.abs(y - arrivalScroll) > 2) {
        userScrolled = true;
        return;
      }

      window.scrollTo(0, arrivalScroll);
      landedAt = (window.pageYOffset || 0);

      /* Lazy images and web fonts can still grow the page after load; try a few
         more times until the position is reachable or the visitor takes over. */
      if (++restoreAttempts < 4 && Math.abs(landedAt - arrivalScroll) > 2) setTimeout(restore, 120 * restoreAttempts);
    };
    if (doc.readyState === 'complete') restore(); else window.addEventListener('load', restore, { once: true });
  }
  window.addEventListener('pagehide', function () { try { history.replaceState({ dbv9: 1, scroll: window.pageYOffset || 0 }, '', location.href); } catch (e) {} });

  /* RC71.4: warm only the small critical product subset and only after the
     current page is fully settled. The old 12-file burst competed with images,
     wasted mobile data and duplicated the PWA cache. */
  if (!saveData && cfg.warm && cfg.warm.length && shellActiveHere()) {
    var idle = window.requestIdleCallback || function (cb) { return setTimeout(cb, 1500); };
    window.addEventListener('load', function () {
      setTimeout(function () {
        idle(function () {
          if (slowNetwork() || doc.hidden) return;
          var memory = Number(navigator.deviceMemory || 8);
          var limit = Math.min(Number(cfg.warmLimit) || 4, memory <= 4 ? 2 : 4, cfg.warm.length);
          for (var i = 0; i < limit; i++) warmAsset(cfg.warm[i]);
        }, { timeout: 4000 });
      }, 1800);
    }, { once: true });
  }
  /* RC71.4: warm at most two leading product documents, and only at idle.
     Four eager HTML downloads were still competing with below-fold images on
     mobile even after the asset budget was reduced. Intent prefetch continues
     to cover whichever different card the shopper actually touches. */
  if (!saveData && shellActiveHere()) {
    window.addEventListener('load', function () {
      setTimeout(function () {
        var idleProducts = window.requestIdleCallback || function (cb) { return setTimeout(cb, 1200); };
        idleProducts(function () {
          if (slowNetwork() || (!linkPrefetch && !swControls()) || doc.hidden) return;
          var links = doc.querySelectorAll('a[href]'); var seen = {}; var warmed = 0;
          var productLimit = Number(navigator.deviceMemory || 8) <= 4 ? 1 : 2;
          for (var i = 0; i < links.length && warmed < productLimit; i++) {
            var url = eligible(links[i].href); if (!url || !productLike(url) || seen[url.href]) continue;
            seen[url.href] = 1; warmDocument(url); warmed++;
          }
        }, { timeout: 5000 });
      }, 3200);
    });
  }
  html.classList.add('delicat-shell-nav');
}());
