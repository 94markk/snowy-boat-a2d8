/**
 * Delicat Builder V9 Pro — Navigation engine.
 *
 * One engine owns link interception, document fetching, swapping and
 * script re-initialisation. Nothing else in the plugin may bind a navigation
 * click; the kernel removes the legacy handlers before this file parses.
 *
 * ES5 syntax. No HTML response cache in JS or browser storage.
 * Any unexpected condition falls through to a normal browser navigation.
 */
(function () {
  'use strict';

  var cfg = window.DBPNavConfig || {};
  var doc = document;
  var root = doc.documentElement;

  /* Capability gate. Without these the storefront still works — it just
     navigates the ordinary way. */
  if (!window.fetch || !window.DOMParser || !window.history || !history.pushState) return;
  if (window.DBPNav) return;

  var MAIN_SELECTOR = 'main';
  var cacheEpoch = 0;
  var inflight = null;
  var navToken = 0;
  var scrollPositions = {};
  var loadedStyles = {};
  var loadedScripts = {};
  var loadedInline = {};
  var progressTimer = null;
  var hasSwapped = false;
  var pageStyles = [];
  var scriptIds = {};
  var renderedKey = location.pathname + location.search;

  /* ---------------------------------------------------------------- device */

  var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;

  function saveData() {
    return !!(connection && connection.saveData);
  }

  function slowLink() {
    if (cfg.lowData) return true;
    if (saveData()) return true;
    if (!connection) return false;
    var type = connection.effectiveType || '';
    return type === 'slow-2g' || type === '2g' || type === '3g';
  }

  function lowMemoryDevice() {
    return typeof navigator.deviceMemory === 'number' && navigator.deviceMemory <= 2;
  }

  function forget() { cacheEpoch++; }

  /* ------------------------------------------------------------ eligibility */

  function parseUrl(href) {
    try {
      return new URL(href, location.href);
    } catch (e) {
      return null;
    }
  }

  function isLocked(pathname) {
    var list = cfg.locked || [];
    var path = String(pathname || '').toLowerCase();
    for (var i = 0; i < list.length; i++) {
      if (list[i] && path.indexOf(list[i]) === 0) return true;
      if (list[i] && path.indexOf(list[i] + '/') !== -1) return true;
    }
    return false;
  }

  function eligible(href) {
    var url = parseUrl(href);
    if (!url) return null;
    if (url.origin !== location.origin) return null;
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;

    var path = url.pathname.toLowerCase();
    if (isLocked(path)) return null;
    if (/\.(pdf|zip|jpe?g|png|gif|webp|avif|svg|mp4|mp3|apk|ipa|csv|xlsx?)$/.test(path)) return null;

    var blocked = cfg.blockParams || [];
    for (var i = 0; i < blocked.length; i++) {
      if (url.searchParams.has(blocked[i])) return null;
    }
    return url;
  }

  function nativeProduct(url) {
    if (!cfg.nativeProducts) return false;
    var paths = cfg.productPaths || ['/product/', '/produit/'];
    for (var i = 0; i < paths.length; i++) {
      if (paths[i] && url.pathname.toLowerCase().indexOf(paths[i].toLowerCase()) !== -1) return true;
    }
    return false;
  }

  function key(url) { return url.pathname + url.search; }

  function sameDocument(url) {
    return url.pathname === location.pathname && url.search === location.search;
  }

  /* --------------------------------------------------------------- progress */

  function progressStart() {
    var bar = doc.getElementById('dbp-progress');
    if (!bar) return;
    bar.className = 'dbp-progress is-active';
    if (progressTimer) clearTimeout(progressTimer);
  }

  function progressDone() {
    var bar = doc.getElementById('dbp-progress');
    if (!bar) return;
    bar.className = 'dbp-progress is-done';
    progressTimer = setTimeout(function () {
      bar.className = 'dbp-progress';
    }, 260);
  }

  /* ---------------------------------------------------------------- fetching */

  function documentPayload(text, response) {
    var incoming = new DOMParser().parseFromString(text, 'text/html');
    var main = incoming.querySelector('main[data-delicat-server-render],main[data-delicat-native-document]');
    var body = incoming.body;
    if (!main || !body || body.classList.contains('dbp-locked') ||
        body.classList.contains('logged-in') !== doc.body.classList.contains('logged-in') ||
        main.querySelector('form.cart,form.checkout,form.variations_form,.woocommerce-account')) {
      throw new Error('full-required');
    }
    // A deployment changes the entire shell, not just main. Reload it once.
    var version = incoming.querySelector('meta[name="dbp-generation"]');
    if (cfg.generation && (!version || version.content !== cfg.generation)) throw new Error('generation-changed');
    var payload = { main: main.outerHTML, title: incoming.title, bodyClass: body.className,
      styles: [], styleNodes: [], scripts: [], inline: [], sequence: [], noStore: true };
    var route = /(?:^|\s)dbp-route-([a-z]+)/.exec(body.className);
    payload.route = route ? route[1] : 'page';
    var canonical = incoming.querySelector('link[rel="canonical"]');
    payload.canonical = canonical ? canonical.href : '';
    // Retain no HTML in JS. LiteSpeed and the browser keep ownership of cache
    // policy; private/no-store documents are never replayed from our memory.
    var styles = incoming.querySelectorAll('head style,link[rel="stylesheet"][href]');
    for (var i = 0; i < styles.length; i++) {
      var node = styles[i];
      if (node.closest('main')) continue; // travels with main already
      if (node.tagName === 'LINK') {
        var item = { href: node.href, id: node.id, media: node.media || 'all' };
        payload.styles.push(item); payload.styleNodes.push({ external: item });
      } else payload.styleNodes.push({ id: node.id, code: node.textContent });
    }
    var scripts = incoming.querySelectorAll('script');
    for (var j = 0; j < scripts.length; j++) {
      var script = scripts[j], src = script.getAttribute('src') || script.getAttribute('data-src');
      var type = script.getAttribute('type') || '';
      if (type === 'module') throw new Error('module-lifecycle');
      if (type && !/^(text\/javascript|application\/javascript|litespeed\/javascript)$/.test(type)) continue;
      if (src) {
        var absolute = new URL(src, response.url || location.href).href;
        // Opaque combined bundles cannot be safely split or run twice.
        // A different bundle means the browser must initialize the full page.
        if (!script.id && !loadedScripts[normalise(absolute)]) throw new Error('unknown-script-bundle');
        var external = { id: script.id, src: absolute };
        payload.scripts.push(external); payload.sequence.push({ external: external });
      } else if (script.id && /-js-(extra|before|after|translations)$/.test(script.id)) {
        var block = { id: script.id, code: script.textContent };
        payload.inline.push(block); payload.sequence.push({ inline: block });
      } else if (script.closest('main') && script.textContent.trim()) {
        throw new Error('inline-lifecycle');
      }
    }
    // innerHTML makes scripts inert. Remove executable tags from main so
    // the asset index cannot mistake them for scripts that already ran.
    main.querySelectorAll('script').forEach(function (node) {
      var type = node.getAttribute('type') || '';
      if (!type || /^(text\/javascript|application\/javascript|litespeed\/javascript)$/.test(type)) node.remove();
    });
    payload.main = main.outerHTML;
    return payload;
  }

  function request(url, signal) {
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var abort = function () { if (controller) controller.abort(); };
    if (signal) { if (signal.aborted) abort(); else signal.addEventListener('abort', abort, { once: true }); }
    var timer;
    var deadline = new Promise(function (resolve, reject) {
      timer = setTimeout(function () { reject(new Error('navigation-timeout')); abort(); }, 8000);
    });
    // The normal document URL can hit LiteSpeed. dbp_nav=1 forced a complete
    // uncached WordPress render on every category tap in pro.30.
    var task = fetch(url.href, {
      credentials: 'same-origin', cache: 'default', redirect: 'follow',
      signal: controller ? controller.signal : signal,
      headers: { 'Accept': 'text/html' }
    }).then(function (response) {
      if (!response.ok) throw new Error('status ' + response.status);
      if (response.redirected) {
        var landed = eligible(response.url);
        if (!landed || key(landed) !== key(url)) throw new Error('redirected');
      }
      if ((response.headers.get('content-type') || '').indexOf('text/html') === -1) throw new Error('not-html');
      indexExistingAssets(); // include delayed scripts loaded since startup
      return response.text().then(function (text) { return documentPayload(text, response); });
    });
    function cleanup() { clearTimeout(timer); if (signal) signal.removeEventListener('abort', abort); }
    return Promise.race([task, deadline]).then(function (value) { cleanup(); return value; }, function (error) { cleanup(); throw error; });
  }

  /* ------------------------------------------------------------- asset load */

  function indexExistingAssets() {
    var links = doc.querySelectorAll('link[rel="stylesheet"][href]');
    var i;
    for (i = 0; i < links.length; i++) loadedStyles[normalise(links[i].href)] = true;

    var scripts = doc.querySelectorAll('script[src],script[data-src]');
    for (i = 0; i < scripts.length; i++) {
      loadedScripts[normalise(scripts[i].getAttribute('src') || scripts[i].getAttribute('data-src'))] = true;
      if (scripts[i].id) scriptIds[scripts[i].id] = true;
    }

    var inline = doc.querySelectorAll('script[id]');
    for (i = 0; i < inline.length; i++) {
      if (!inline[i].src) loadedInline[inline[i].id] = true;
    }
  }

  function normalise(href) {
    var url = parseUrl(href);
    return url ? url.origin + url.pathname + url.search : String(href);
  }

  var stylePromises = {};
  function loadStyles(list) {
    return Promise.all(list.map(function (item) {
      var href = normalise(item.href);
      if (loadedStyles[href]) return Promise.resolve();
      if (stylePromises[href]) return stylePromises[href];
      stylePromises[href] = injectStyle(item);
      return stylePromises[href];
    }));
  }

  function injectStyle(item) {
    return new Promise(function (resolve, reject) {
      var link = doc.createElement('link'), href = normalise(item.href);
      link.rel = 'stylesheet'; link.href = item.href; link.media = item.media || 'all';
      if (item.id) link.id = item.id;
      function finish(error) {
        clearTimeout(timer); delete stylePromises[href];
        link.onload = link.onerror = null;
        if (error) { link.remove(); reject(error); }
        else { loadedStyles[href] = true; resolve(); }
      }
      var timer = setTimeout(function () { finish(new Error('style-timeout')); }, 4000);
      link.onload = function () { finish(); };
      link.onerror = function () { finish(new Error('style-failed')); };
      doc.head.appendChild(link);
      pageStyles.push(link);
    });
  }

  function reconcileStyles(payload) {
    var next = [], links = doc.querySelectorAll('link[rel="stylesheet"][href]');
    (payload.styleNodes || []).forEach(function (item) {
      var node = null;
      if (item.external) {
        for (var i = 0; i < links.length; i++) {
          if (normalise(links[i].href) === normalise(item.external.href)) { node = links[i]; break; }
        }
        if (node) node.media = item.external.media || 'all';
      } else {
        node = item.id ? doc.getElementById(item.id) : null;
        if (!node || node.tagName !== 'STYLE') node = doc.createElement('style');
        if (item.id) node.id = item.id;
        if (node.textContent !== item.code) node.textContent = item.code;
      }
      if (node) { doc.head.appendChild(node); next.push(node); }
    });
    pageStyles.forEach(function (node) {
      if (next.indexOf(node) === -1 && node.parentNode) {
        if (node.href) delete loadedStyles[normalise(node.href)];
        node.parentNode.removeChild(node);
      }
    });
    pageStyles = next;
  }

  function loadScripts(list, inline, sequence) {
    indexExistingAssets();
    var queue = sequence || inline.map(function (block) { return { inline: block }; })
      .concat(list.map(function (item) { return { external: item }; }));
    return queue.reduce(function (chain, item) {
      return chain.then(function () {
        if (item.inline) {
          if (item.inline.id && loadedInline[item.inline.id]) return;
          return runInline(item.inline);
        }
        if ((item.external.id && scriptIds[item.external.id]) || loadedScripts[normalise(item.external.src)]) return;
        return injectScript(item.external);
      });
    }, Promise.resolve());
  }

  function runInline(block) {
    if (block.id) loadedInline[block.id] = true;
    var node = doc.createElement('script');
    if (block.id) {
      var previous = doc.getElementById(block.id);
      if (previous && previous.tagName === 'SCRIPT') previous.parentNode.removeChild(previous);
      node.id = block.id;
    }
    node.text = block.code;
    doc.head.appendChild(node);
    return Promise.resolve();
  }

  function injectScript(item) {
    var src = normalise(item.src);
    loadedScripts[src] = true;
    return new Promise(function (resolve, reject) {
      var timer = setTimeout(function () { delete loadedScripts[src]; reject(new Error('script-timeout')); }, 8000);
      var node = doc.createElement('script');
      node.src = item.src;
      if (item.id) node.id = item.id;
      node.async = false;
      node.onload = function () { clearTimeout(timer); if (item.id) scriptIds[item.id] = true; resolve(); };
      node.onerror = function () { clearTimeout(timer); delete loadedScripts[src]; reject(new Error('script-failed')); };
      doc.body.appendChild(node);
    });
  }

  /* ------------------------------------------------------------------ swap */

  function currentMain() {
    return doc.querySelector(MAIN_SELECTOR);
  }

  function applySwap(payload, url, restoreTo, push) {
    var main = currentMain();
    if (!main) return false;

    var holder = doc.createElement('div');
    holder.innerHTML = payload.main;
    var next = holder.querySelector(MAIN_SELECTOR);
    if (!next) return false;

    if (push) {
      if (history.scrollRestoration) history.scrollRestoration = 'manual';
      history.pushState({ dbp: 1 }, '', url.href);
    }
    reconcileStyles(payload);
    main.parentNode.replaceChild(next, main);
    renderedKey = key(url);

    if (payload.title) doc.title = payload.title;
    if (payload.bodyClass) {
      /* theme.js keeps the resolved theme on the BODY (dlc-theme-dark /
         dlc-theme-light, plus the header-studio pair). The server cannot know
         it, so replacing the class list wholesale flipped a dark-mode shopper
         to light on every switch. Carry the client-managed classes across the
         swap so there is no flash; theme.js's own resync then confirms them. */
      var carried = [];
      var previous = String(doc.body.className || '').split(/\s+/);
      for (var ci = 0; ci < previous.length; ci++) {
        if (/^[a-z0-9-]+-(theme|mode)-(dark|light)$/i.test(previous[ci])) carried.push(previous[ci]);
      }
      doc.body.className = payload.bodyClass;
      for (var cj = 0; cj < carried.length; cj++) doc.body.classList.add(carried[cj]);
    }
    if (payload.canonical) {
      var canonical = doc.querySelector('link[rel="canonical"]');
      if (canonical) canonical.href = payload.canonical;
    }

    if (typeof restoreTo === 'number') {
      window.scrollTo(0, restoreTo);
    } else {
      window.scrollTo(0, 0);
    }

    /* Move focus to the new content so keyboard and screen-reader users are
       not left at the end of the previous page. */
    next.setAttribute('tabindex', '-1');
    try { next.focus({ preventScroll: true }); } catch (e) { /* older engines */ }

    hasSwapped = true;
    announce(payload.title || '');
    emit('delicat:pro:navigated', { url: url.href, route: payload.route, main: next });

    return true;
  }

  function announce(title) {
    var live = doc.getElementById('dbp-live');
    if (!live) {
      live = doc.createElement('div');
      live.id = 'dbp-live';
      live.className = 'dbp-sr-only';
      live.setAttribute('aria-live', 'polite');
      live.setAttribute('role', 'status');
      doc.body.appendChild(live);
    }
    live.textContent = title;
  }

  function emit(name, detail) {
    var event;
    try {
      event = new CustomEvent(name, { detail: detail, bubbles: true });
    } catch (e) {
      event = doc.createEvent('CustomEvent');
      event.initCustomEvent(name, true, false, detail);
    }
    doc.dispatchEvent(event);
  }

  function transitionsAllowed() {
    if (!cfg.transitions) return false;
    if (!doc.startViewTransition) return false;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
    if (lowMemoryDevice()) return false;
    return true;
  }

  // Start static script downloads alongside CSS, without executing them early.
  // Only same-origin assets are hinted; inline data is never prefetched/stored.
  var warmedScripts = {};
  function warmScripts(list) {
    for (var i = 0; i < list.length; i++) {
      var url = parseUrl(list[i].src);
      if (!url || url.origin !== location.origin || !/^https?:$/.test(url.protocol)) continue;
      var src = normalise(url.href);
      if ((list[i].id && scriptIds[list[i].id]) || loadedScripts[src] || warmedScripts[src]) continue;
      warmedScripts[src] = true;
      var link = doc.createElement('link');
      link.rel = 'preload';
      link.as = 'script';
      link.href = url.href;
      doc.head.appendChild(link);
    }
  }

  function commit(payload, url, restoreTo, token, push) {
    if (token !== navToken) return Promise.resolve();
    warmScripts(payload.scripts || []);
    return loadStyles(payload.styles || []).then(function () {
      if (token !== navToken) return;
      var failed = false;
      var run = function () {
        if (token !== navToken) return;
        if (!applySwap(payload, url, restoreTo, push)) failed = true;
      };

      if (transitionsAllowed()) {
        root.setAttribute('data-dbp-transition', '1');
        var transition = doc.startViewTransition(run);
        return transition.updateCallbackDone.then(function () {
          root.removeAttribute('data-dbp-transition');
          /* The transition swallows exceptions thrown inside its callback, so
             the failure is reported here instead — otherwise a failed swap
             would leave the shopper on an unchanged page with no fallback. */
          if (failed) throw new Error('swap-failed');
        });
      }

      root.setAttribute('data-dbp-fade', '1');
      run();
      setTimeout(function () { root.removeAttribute('data-dbp-fade'); }, 200);
      if (failed) return Promise.reject(new Error('swap-failed'));
      return Promise.resolve();
    }).then(function () {
      if (token !== navToken) return;
      return loadScripts(payload.scripts || [], payload.inline || [], payload.sequence);
    }).then(function () {
      if (token !== navToken) return;
      emit('dsb:content-updated', { source: 'pro-nav', url: url.href });
      /*
       * V9's modules re-bind on two older event names. The only scripts that ever
       * dispatched them — navigation.js and shell-nav.js — are dequeued AND
       * deregistered by DBP_Kernel::quiet_legacy_layers(), so under the Pro engine
       * neither event could fire and twelve modules stayed dead on every swapped
       * page: carousels never initialised (and islands.js's hydration scan never
       * ran, so their images were never even requested), favourite hearts stayed
       * unfilled, the dock cart badge froze, scroll-reveal motion never ran so
       * revealed elements sat at opacity 0, and the hero search, currency switcher
       * and notification bell were all inert. The Pro engine owns these names now.
       *
       * Order matters: content-updated first (the Player-ID calculators re-boot),
       * then shell:navigated (express-checkout resets its arm/busy flags), then
       * navigation-complete last so theme.js's resync runs after every other
       * module has finished touching the DOM. Each handler was checked for
       * idempotence: they guard on a ready flag (carousel data-delicat-ready,
       * islands data-dlc-island-seen, hero-search, currency) or are pure re-reads.
       */
      emit('delicat:shell:navigated', { url: url.href, route: payload.route });
      emit('delicat:navigation-complete', { url: url.href, route: payload.route });
      if (window.jQuery && window.jQuery.fn.wc_variation_form) {
        window.jQuery(currentMain()).find('.variations_form').each(function () {
          var form = window.jQuery(this);
          if (!form.data('wc_variation_form')) form.wc_variation_form();
        });
      }
      emit('delicat:pro:ready', { url: url.href, route: payload.route });
    });
  }

  /* ---------------------------------------------------------------- navigate */

  function hardNavigate(url, replace) {
    /* Product links leave as real documents. Record where the shopper was, so a
       later engine-handled Back can put them back on the same row of the list. */
    try { scrollPositions[key(parseUrl(location.href))] = window.pageYOffset; } catch (e) { /* no-op */ }
    if (replace) window.location.replace(url.href); else window.location.href = url.href;
  }

  function navigate(url, options) {
    options = options || {};
    if (nativeProduct(url)) { hardNavigate(url); return; }
    var id = key(url);
    var token = ++navToken;

    scrollPositions[renderedKey] = window.pageYOffset;

    if (inflight && inflight.abort) inflight.abort();

    var epoch = cacheEpoch;

    progressStart();
    if (cfg.skeletons) root.setAttribute('data-dbp-loading', '1');

    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    inflight = controller;

    request(url, controller ? controller.signal : null).then(function (payload) {
      if (epoch !== cacheEpoch) throw new Error('session-changed');
      if (token !== navToken) return;
      return commit(payload, url, options.restoreTo, token, options.push !== false);
    })['catch'](function (error) {
      if (error && error.name === 'AbortError') return;
      if (token !== navToken) return;
      hardNavigate(url, options.push === false || key(parseUrl(location.href)) === key(url));
    }).then(function () {
      if (token !== navToken) return;
      inflight = null;
      root.removeAttribute('data-dbp-loading');
      progressDone();
    });
  }

  /* ----------------------------------------------------------------- events */

  function linkFrom(node) {
    while (node && node !== doc.body) {
      if (node.tagName === 'A' && node.href) return node;
      node = node.parentNode;
    }
    return null;
  }

  function interactive(link) {
    if (cfg.nativeProducts && (link.hasAttribute('data-delicat-product-link') || link.hasAttribute('data-delicat-instant-product') || link.closest('.product, .delicat-woo-product-card, [data-delicat-product-id]'))) return false;
    if (link.hasAttribute('download')) return false;
    if (link.target && link.target !== '' && link.target !== '_self') return false;
    if (link.getAttribute('rel') === 'external') return false;
    if (link.hasAttribute('data-dbp-skip') || link.hasAttribute('data-no-shell') || link.hasAttribute('data-no-turbo') || link.hasAttribute('data-delicat-no-app')) return false;
    return true;
  }

  doc.addEventListener('click', function (event) {
    if (event.defaultPrevented) return;
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    var link = linkFrom(event.target);
    if (!link || !interactive(link)) return;

    var url = eligible(link.href);
    if (!url || nativeProduct(url)) return;

    /* A hash on the same page is the browser's job, not ours. */
    if (sameDocument(url)) {
      if (url.hash) return;
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    if (!currentMain() || doc.body.classList.contains('dbp-locked')) return;

    event.preventDefault();
    navigate(url, {});
  }, false);

  window.addEventListener('popstate', function (event) {
    /* Until this document has swapped something, its DOM is whatever the browser
       gave us and already matches the address bar — a fresh load, or a bfcache
       restore coming back from a product page. Re-navigating there would refetch
       the page over the shopper's mobile data and then yank the scroll to the top,
       undoing the position the browser had just restored. */
    if (!hasSwapped) return;
    if (!currentMain() || doc.body.classList.contains('dbp-locked')) return;
    var url = parseUrl(location.href);
    if (!url) return;
    if (!eligible(location.href)) {
      hardNavigate(url);
      return;
    }
    var restore = scrollPositions[key(url)];
    navigate(url, { push: false, restoreTo: typeof restore === 'number' ? restore : 0 });
  });

  function schedule(fn) {
    if (window.requestIdleCallback) {
      window.requestIdleCallback(fn, { timeout: 1200 });
    } else {
      setTimeout(fn, 240);
    }
  }

  /* Mutations to the cart or session invalidate cached markup that shows a
     count, a price or a wallet balance. */
  doc.addEventListener('delicat:pro:state', function (event) {
    if (event && event.detail && event.detail.changed) forget();
  });
  doc.addEventListener('added_to_cart', forget);
  doc.addEventListener('removed_from_cart', forget);
  ['delicat:cart-changed', 'delicat:wallet-changed', 'delicat:currency-changed', 'delicat:auth-changed'].forEach(function (name) { doc.addEventListener(name, forget); });

  /* WooCommerce dispatches its cart events through jQuery's event system,
     which never reaches a native listener. Bridge them when jQuery is present. */
  if (window.jQuery) {
    window.jQuery(document.body).on('added_to_cart removed_from_cart updated_cart_totals', forget);
  }

  /* PRO21: native product navigation still gets its own complete document.
     Warm only immutable assets and browser-managed, public product documents;
     never put account/cart/checkout HTML in our JS cache. */
  var productWarmSeen = {}, productWarmCount = 0, productAssetsWarmed = false, productVisibleObserver = null;
  var nativePrefetch = (function () {
    var link = doc.createElement('link');
    return !!(link.relList && link.relList.supports && link.relList.supports('prefetch'));
  })();
  function productLink(node) {
    var link = linkFrom(node);
    if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.closest('[data-no-turbo],.no-prerender') || link.hasAttribute('data-dbp-skip')) return null;
    var url = eligible(link.href);
    if (!url || url.search || url.hash || sameDocument(url)) return null;
    return nativeProduct(url) || link.matches('[data-delicat-product-link],[data-delicat-instant-product]') ? url : null;
  }
  function warmProduct(url) {
    if (!url || cfg.prefetch === 0 || Number(cfg.budget) === 0 || saveData() || slowLink() || doc.hidden || doc.body.classList.contains('dbp-locked')) return;
    if (!productAssetsWarmed) {
      productAssetsWarmed = true;
      (cfg.productWarm || []).forEach(function (href) {
        var asset = parseUrl(href);
        if (!asset || asset.origin !== location.origin || !/\.(css|js)$/.test(asset.pathname)) return;
        if (nativePrefetch) {
          var hint = doc.createElement('link'); hint.rel = 'prefetch'; hint.href = asset.href;
          hint.as = /\.css$/.test(asset.pathname) ? 'style' : 'script'; doc.head.appendChild(hint);
        } else {
          fetch(asset.href, { credentials: 'same-origin', cache: 'default', priority: 'low' })
            .then(function (response) { if (response.ok) return response.arrayBuffer(); }).catch(function () {});
        }
      });
    }
    // Existing speculation rules already own document warming in Chromium.
    if (!cfg.publicProductWarm || !nativePrefetch || productWarmCount >= Math.min(Number(cfg.budget) || 3, 3) || productWarmSeen[url.href] || doc.querySelector('script[type="speculationrules"]')) return;
    productWarmSeen[url.href] = true; productWarmCount++;
    var hint = doc.createElement('link'); hint.rel = 'prefetch'; hint.setAttribute('data-dbp-product-warm', '1'); hint.href = url.href; doc.head.appendChild(hint);
  }
  doc.addEventListener('pointerover', function (event) { warmProduct(productLink(event.target)); }, { passive: true });
  doc.addEventListener('focusin', function (event) { warmProduct(productLink(event.target)); });
  doc.addEventListener('pointerdown', function (event) { if (event.isPrimary !== false && !event.button) warmProduct(productLink(event.target)); }, { passive: true });
  doc.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (productLink(event.target)) progressStart(); // No delay or click interception.
  });
  window.addEventListener('pageshow', progressDone);
  function warmVisibleProduct() {
    if (slowLink() || saveData() || !window.IntersectionObserver) return;
    if (productVisibleObserver) productVisibleObserver.disconnect();
    var visible = productVisibleObserver = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (!entries[i].isIntersecting) continue;
        var url = productLink(entries[i].target);
        if (url) { warmProduct(url); visible.disconnect(); break; }
      }
    }, { threshold: 0.2 });
    doc.querySelectorAll('a[data-delicat-product-link],a[data-delicat-instant-product],.product a[href],.delicat-woo-product-card a[href]').forEach(function (link) { visible.observe(link); });
  }
  if (doc.readyState === 'complete') schedule(warmVisibleProduct);
  else window.addEventListener('load', function () { schedule(warmVisibleProduct); }, { once: true });
  doc.addEventListener('delicat:pro:navigated', function () {
    productWarmCount = 0; productWarmSeen = {};
    doc.querySelectorAll('[data-dbp-product-warm]').forEach(function (node) { node.remove(); });
    schedule(warmVisibleProduct);
  });

  /* --------------------------------------------------------------- start-up */

  indexExistingAssets();
  pageStyles = Array.prototype.slice.call(doc.querySelectorAll('head style,link[rel="stylesheet"][href]'));

  window.DBPNav = {
    navigate: function (href) {
      var url = eligible(href);
      if (url) navigate(url, {});
      else window.location.href = href;
    },
    prefetch: function (href) {
      var url = eligible(href);
      if (url && nativeProduct(url)) warmProduct(url);
    },
    invalidate: forget,
    stats: function () {
      return { cached: 0, slow: slowLink(), transport: 'document', prefetch: false };
    }
  };
})();
