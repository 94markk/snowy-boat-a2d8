/**
 * Delicat Builder V9 Pro — Navigation engine.
 *
 * One engine owns link interception, prefetching, fetching, swapping and
 * script re-initialisation. Nothing else in the plugin may bind a navigation
 * click; the kernel removes the legacy handlers before this file parses.
 *
 * ES5 only. Memory-only caching (no localStorage/sessionStorage).
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
  var CACHE_TTL = cfg.cacheTtl || 120000;
  var CACHE_MAX = cfg.cacheMax || 24;
  var PREFETCH_BUDGET = typeof cfg.budget === 'number' ? cfg.budget : 6;

  var cacheEpoch = 0;
  var cache = {};
  var cacheOrder = [];
  var prefetching = {};
  /* pro.6: this was one counter that was incremented and never decremented,
     so it behaved as a lifetime cap of six per page rather than a limit on
     concurrent requests. Viewport prefetch spent all six on the first scroll,
     which meant that by the time a shopper actually tapped a product, intent
     prefetch was already disabled and the navigation paid for a full render
     with nothing warmed. Two separate limits now:
       prefetchOpen - how many prefetch requests may be open at once
       speculative - how many guesses one page view may make
     A prefetch the shopper has signalled intent for is never speculative and
     is limited only by concurrency. */
  var prefetchOpen = 0;
  var speculative = 0;
  var inflight = null;
  var navToken = 0;
  var scrollPositions = {};
  var loadedStyles = {};
  var loadedScripts = {};
  var loadedInline = {};
  var progressTimer = null;
  var hoverTimer = null;
  var observer = null;
  var hasSwapped = false;

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

  function prefetchAllowed() {
    // The current endpoint is private/no-store: speculative responses cannot
    // be retained for a later click. Fail closed even with old saved settings.
    if (cfg.fragmentPrefetch !== true) return false;
    if (!cfg.prefetch) return false;
    if (PREFETCH_BUDGET <= 0) return false;
    if (saveData()) return false;
    return true;
  }

  /* Prefetching on a 3G handset costs the very bandwidth the page needs. On a
     slow link we keep intent-based prefetch (pointerdown, which the user has
     already committed to) and drop speculative viewport prefetch. */
  function viewportPrefetchAllowed() {
    return prefetchAllowed() && !slowLink() && !lowMemoryDevice();
  }

  /* ----------------------------------------------------------------- cache */

  function remember(key, payload) {
    if (payload.noStore) return;
    if (cache[key]) {
      var at = cacheOrder.indexOf(key);
      if (at !== -1) cacheOrder.splice(at, 1);
    }
    cache[key] = { payload: payload, at: Date.now() };
    cacheOrder.push(key);
    while (cacheOrder.length > CACHE_MAX) {
      var oldest = cacheOrder.shift();
      if (oldest !== key) delete cache[oldest];
    }
  }

  function recall(key) {
    var hit = cache[key];
    if (!hit) return null;
    if (Date.now() - hit.at > CACHE_TTL) {
      delete cache[key];
      var at = cacheOrder.indexOf(key);
      if (at !== -1) cacheOrder.splice(at, 1);
      return null;
    }
    return hit.payload;
  }

  function forget() {
    cacheEpoch++;
    cache = {};
    cacheOrder = [];
  }

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

  function parsePayload(text) {
    // Only tolerate the exact LiteSpeed diagnostic footer observed on pro.7.
    // All other trailing data remains a parse error and uses native navigation.
    var prior;
    do {
      prior = text;
      text = text.replace(/\s*<!--(?: Page optimized by LiteSpeed Cache| Page (?:un)?cached by LiteSpeed Cache| Guest Mode)[^\r\n]*-->\s*$/, '');
    } while (text !== prior);
    return JSON.parse(text);
  }

  function key(url) {
    return url.pathname + url.search;
  }

  /* Private fragments use a separate URL, but must never enter a page cache.
     Navigation keys and redirect comparisons use the clean URL. */
  function fragmentUrl(url) {
    var frag = parseUrl(url.href);
    if (!frag) return url;
    frag.searchParams.set('dbp_nav', '1');
    return frag;
  }
  function stripFragmentFlag(url) {
    var clean = parseUrl(url.href);
    if (!clean) return url;
    clean.searchParams['delete']('dbp_nav');
    return clean;
  }

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

  function request(url, signal) {
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var abort = function () { if (controller) controller.abort(); };
    if (signal) { if (signal.aborted) abort(); else signal.addEventListener('abort', abort, { once: true }); }
    var timer;
    var deadline = new Promise(function (resolve, reject) {
      timer = setTimeout(function () { reject(new Error('navigation-timeout')); abort(); }, 8000);
    });
    var task = fetch(fragmentUrl(url).href, {
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'follow',
      signal: controller ? controller.signal : signal,
      headers: {
        'X-Delicat-Pro-Nav': '1',
        'Accept': 'application/json'
      }
    }).then(function (response) {
      if (!response.ok) throw new Error('status ' + response.status);
      /* A redirect out of the shell (login wall, checkout) must become a real
         navigation rather than a swap into the wrong page. */
      if (response.redirected) {
        var landed = parseUrl(response.url);
        if (landed) landed = stripFragmentFlag(landed);
        if (!landed || !eligible(landed.href) || key(landed) !== key(url)) {
          throw new Error('redirected');
        }
      }
      var type = response.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) throw new Error('not-json');
      return response.text().then(function (text) {
        var payload = parsePayload(text);
        /* Retain only what the server marked publicly cacheable. Anything
           private, no-cache, no-store or unlabelled is treated as
           non-retainable, so a fragment carrying nonces, a cart badge or a
           wallet figure can never be replayed from memory on a later click. */
        var cc = String(response.headers.get('cache-control') || '').toLowerCase();
        payload.noStore = cc.indexOf('public') === -1 || cc.indexOf('no-store') !== -1 || cc.indexOf('private') !== -1;
        return payload;
      });
    }).then(function (payload) {
      if (!payload || payload.full || !payload.main) throw new Error('full-required');
      /* A deploy happened mid-session: drop the cache so no stale markup is
         swapped in against new assets. */
      if (payload.generation && cfg.generation && payload.generation !== cfg.generation) {
        forget();
        cfg.generation = payload.generation;
      }
      return payload;
    });
    function cleanup() { clearTimeout(timer); if (signal) signal.removeEventListener('abort', abort); }
    return Promise.race([task, deadline]).then(function (value) { cleanup(); return value; }, function (error) { cleanup(); throw error; });
  }

  function prefetch(url, intent) {
    if (!prefetchAllowed() || nativeProduct(url)) return;
    var id = key(url);
    if (recall(id) || prefetching[id]) return;
    if (prefetchOpen >= Math.min(PREFETCH_BUDGET, 2)) return;
    if (!intent && speculative >= PREFETCH_BUDGET) return;
    prefetchOpen++;
    if (!intent) speculative++;
    var epoch = cacheEpoch;
    var pending = request(url, null).then(function (payload) {
      if (epoch !== cacheEpoch) throw new Error('session-changed');
      remember(id, payload);
      warmStyles(payload);
      return payload;
    });
    prefetching[id] = pending;
    function cleanup() { if (prefetching[id] === pending) delete prefetching[id]; prefetchOpen--; }
    pending.then(cleanup, cleanup);
  }

  /* A product page carries stylesheets the homepage never loaded. Without
     this the swap stalls on a render-blocking download at the exact moment
     the shopper is looking at a half-built screen. Warming them while the
     fragment is prefetched moves that cost off the critical path. */
  var warmed = {};
  function warmStyles(payload) {
    if (!payload || !payload.styles || !payload.styles.length) return;
    for (var i = 0; i < payload.styles.length; i++) {
      var href = payload.styles[i] && payload.styles[i].href;
      if (!href || warmed[href]) continue;
      if (doc.querySelector('link[href="' + href.replace(/"/g, '\\"') + '"]')) continue;
      warmed[href] = true;
      var link = doc.createElement('link');
      link.rel = 'preload';
      link.as = 'style';
      link.href = href;
      doc.head.appendChild(link);
    }
  }

  /* ------------------------------------------------------------- asset load */

  function indexExistingAssets() {
    var links = doc.querySelectorAll('link[rel="stylesheet"][href]');
    var i;
    for (i = 0; i < links.length; i++) loadedStyles[normalise(links[i].href)] = true;

    var scripts = doc.querySelectorAll('script[src]');
    for (i = 0; i < scripts.length; i++) loadedScripts[normalise(scripts[i].src)] = true;

    var inline = doc.querySelectorAll('script[id]');
    for (i = 0; i < inline.length; i++) {
      if (!inline[i].src) loadedInline[inline[i].id] = true;
    }
  }

  function normalise(href) {
    var url = parseUrl(href);
    return url ? url.origin + url.pathname + url.search : String(href);
  }

  function loadStyles(list) {
    var pending = [];
    for (var i = 0; i < list.length; i++) {
      var href = normalise(list[i].href);
      if (loadedStyles[href]) continue;
      loadedStyles[href] = true;
      pending.push(injectStyle(list[i].href, list[i].id));
    }
    if (!pending.length) return Promise.resolve();
    /* Never let a slow stylesheet hold the swap hostage: 900ms then proceed,
       the page paints and the sheet applies when it lands. */
    return Promise.race([
      Promise.all(pending),
      new Promise(function (resolve) { setTimeout(resolve, 900); })
    ]);
  }

  function injectStyle(href, id) {
    return new Promise(function (resolve) {
      var link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      if (id) link.id = id;
      link.onload = resolve;
      link.onerror = resolve;
      doc.head.appendChild(link);
    });
  }

  function loadScripts(list, inline) {
    var queue = [];
    var i;

    for (i = 0; i < inline.length; i++) {
      if (inline[i].id && loadedInline[inline[i].id]) continue;
      queue.push({ inline: inline[i] });
    }
    for (i = 0; i < list.length; i++) {
      var src = normalise(list[i].src);
      if (loadedScripts[src]) continue;
      queue.push({ external: list[i] });
    }

    /* Execution order matters: a `-js-extra` config block must run before the
       script that reads it. The queue preserves the document order the server
       captured. */
    return queue.reduce(function (chain, item) {
      return chain.then(function () {
        if (item.inline) return runInline(item.inline);
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
      node.onload = function () { clearTimeout(timer); resolve(); };
      node.onerror = function () { clearTimeout(timer); delete loadedScripts[src]; reject(new Error('script-failed')); };
      doc.body.appendChild(node);
    });
  }

  /* ------------------------------------------------------------------ swap */

  function currentMain() {
    return doc.querySelector(MAIN_SELECTOR);
  }

  function applySwap(payload, url, restoreTo) {
    var main = currentMain();
    if (!main) return false;

    var holder = doc.createElement('div');
    holder.innerHTML = payload.main;
    var next = holder.querySelector(MAIN_SELECTOR);
    if (!next) return false;

    main.parentNode.replaceChild(next, main);

    if (payload.title) doc.title = payload.title;
    if (payload.bodyClass) doc.body.className = payload.bodyClass;
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
      if (loadedScripts[src] || warmedScripts[src]) continue;
      warmedScripts[src] = true;
      var link = doc.createElement('link');
      link.rel = 'preload';
      link.as = 'script';
      link.href = url.href;
      doc.head.appendChild(link);
    }
  }

  function commit(payload, url, restoreTo, token) {
    if (token !== navToken) return Promise.resolve();
    warmScripts(payload.scripts || []);
    return loadStyles(payload.styles || []).then(function () {
      if (token !== navToken) return;
      var failed = false;
      var run = function () {
        if (token !== navToken) return;
        if (!applySwap(payload, url, restoreTo)) failed = true;
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
      return loadScripts(payload.scripts || [], payload.inline || []);
    }).then(function () {
      if (token !== navToken) return;
      emit('dsb:content-updated', { source: 'pro-nav', url: url.href });
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

  function hardNavigate(url) {
    /* Product links leave as real documents. Record where the shopper was, so a
       later engine-handled Back can put them back on the same row of the list. */
    try { scrollPositions[key(parseUrl(location.href))] = window.pageYOffset; } catch (e) { /* no-op */ }
    window.location.href = url.href;
  }

  function navigate(url, options) {
    options = options || {};
    if (nativeProduct(url)) { hardNavigate(url); return; }
    var id = key(url);
    var token = ++navToken;

    scrollPositions[key(parseUrl(location.href))] = window.pageYOffset;

    if (inflight && inflight.abort) inflight.abort();

    var cached = recall(id);
    var epoch = cacheEpoch;

    progressStart();
    if (cfg.skeletons) root.setAttribute('data-dbp-loading', '1');

    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    inflight = controller;

    (cached ? Promise.resolve(cached) : (prefetching[id] || request(url, controller ? controller.signal : null))).then(function (payload) {
      if (epoch !== cacheEpoch) throw new Error('session-changed');
      if (token !== navToken) return;
      remember(id, payload);
      /* Scroll restoration is taken over only once this document has an
         engine-made history entry to restore. Forcing 'manual' at start-up
         also switched it off for ordinary document navigations (product
         page -> back), so a shopper returning to the catalogue landed at
         the top of the list instead of where they left it. */
      if (options.push !== false) {
        if (history.scrollRestoration && history.scrollRestoration !== 'manual') history.scrollRestoration = 'manual';
        history.pushState({ dbp: 1 }, '', url.href);
      }
      return commit(payload, url, options.restoreTo, token);
    })['catch'](function (error) {
      if (error && error.name === 'AbortError') return;
      if (token !== navToken) return;
      hardNavigate(url);
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

  /* Intent prefetch. pointerdown fires roughly 80-120ms before click on
     touch, which on a slow connection is the difference between a visible
     wait and an instant switch. */
  function intent(event) {
    if (!prefetchAllowed() || doc.body.classList.contains('dbp-locked')) return;
    var link = linkFrom(event.target);
    if (!link || !interactive(link)) return;
    var url = eligible(link.href);
    if (!url || sameDocument(url) || doc.body.classList.contains('dbp-locked')) return;
    prefetch(url, true);
  }

  doc.addEventListener('pointerdown', intent, true);
  doc.addEventListener('touchstart', intent, { capture: true, passive: true });

  doc.addEventListener('mouseover', function (event) {
    if (!prefetchAllowed() || slowLink()) return;
    var link = linkFrom(event.target);
    if (!link || !interactive(link)) return;
    var url = eligible(link.href);
    if (!url || sameDocument(url)) return;
    if (hoverTimer) clearTimeout(hoverTimer);
    hoverTimer = setTimeout(function () { prefetch(url, true); }, 65);
  }, true);

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

  /* Viewport prefetch, budgeted and idle-scheduled so it never competes with
     the render of the page the shopper is actually looking at. */
  function observeLinks() {
    if (!viewportPrefetchAllowed() || !window.IntersectionObserver || doc.body.classList.contains('dbp-locked')) return;

    if (observer) observer.disconnect();
    observer = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (!entries[i].isIntersecting) continue;
        var link = entries[i].target;
        observer.unobserve(link);
        var url = eligible(link.href);
        if (!url || sameDocument(url)) continue;
        /* `var` is function-scoped, so the deferred callback must be bound to
           this iteration's URL rather than the loop variable. */
        (function (target) {
          schedule(function () { prefetch(target); });
        })(url);
      }
    }, { rootMargin: '200px' });

    var links = doc.querySelectorAll('main a[href]');
    /* Observing three times the budget meant the speculative allowance was
       always exhausted before the shopper chose anything. */
    var budget = Math.min(links.length, PREFETCH_BUDGET);
    for (var i = 0; i < budget; i++) {
      if (interactive(links[i])) observer.observe(links[i]);
    }
  }

  function schedule(fn) {
    if (window.requestIdleCallback) {
      window.requestIdleCallback(fn, { timeout: 1200 });
    } else {
      setTimeout(fn, 240);
    }
  }

  doc.addEventListener('delicat:pro:navigated', function () {
    speculative = 0;
    schedule(observeLinks);
  });

  /* Mutations to the cart or session invalidate cached markup that shows a
     count, a price or a wallet balance. */
  doc.addEventListener('delicat:pro:state', function (event) {
    if (event && event.detail && event.detail.changed) forget();
  });
  doc.addEventListener('added_to_cart', forget);
  doc.addEventListener('removed_from_cart', forget);

  /* WooCommerce dispatches its cart events through jQuery's event system,
     which never reaches a native listener. Bridge them when jQuery is present. */
  if (window.jQuery) {
    window.jQuery(document.body).on('added_to_cart removed_from_cart updated_cart_totals', forget);
  }

  /* --------------------------------------------------------------- start-up */

  indexExistingAssets();
  schedule(observeLinks);

  window.DBPNav = {
    navigate: function (href) {
      var url = eligible(href);
      if (url) navigate(url, {});
      else window.location.href = href;
    },
    prefetch: function (href) {
      var url = eligible(href);
      if (url) prefetch(url);
    },
    invalidate: forget,
    stats: function () {
      return { cached: cacheOrder.length, slow: slowLink(), prefetch: prefetchAllowed() };
    }
  };
})();
