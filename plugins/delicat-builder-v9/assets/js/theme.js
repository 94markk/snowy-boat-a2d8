(() => {
  'use strict';

  const root = document.documentElement;
  /* RC55: the preference lives in a first-party cookie, not localStorage —
     the storefront's standing storage rule. The cookie
     name is outside every pattern Security::has_private_cookie() treats as
     personal, so a guest page stays publicly cacheable. */
  const key = 'dbv9_theme';
  const valid = new Set(['light', 'dark', 'system']);
  const readCookie = (name) => {
    const parts = document.cookie ? document.cookie.split(';') : [];
    for (let i = 0; i < parts.length; i++) {
      const pair = parts[i].replace(/^\s+/, '');
      if (pair.indexOf(name + '=') === 0) {
        try { return decodeURIComponent(pair.slice(name.length + 1)); } catch (_) { return ''; }
      }
    }
    return '';
  };
  const writeCookie = (name, value, maxAge) => {
    let cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax; max-age=' + maxAge;
    if (location.protocol === 'https:') cookie += '; Secure';
    document.cookie = cookie;
  };
  const systemQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  const reducedMotionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
  const memory = Number(navigator.deviceMemory || 0);
  const cores = Number(navigator.hardwareConcurrency || 0);
  const saveData = !!(connection && connection.saveData);
  /* 9.4.2: memory, Save-Data and 2G decide (budget phones have eight slow cores); a slow phone
   * measured earlier in the session (engine speed probe) stays low-power from the first paint. */
  const effType = String((connection && connection.effectiveType) || '');
  let measured = '';
  try { measured = window.sessionStorage.getItem('dbv9-speed') || ''; } catch (error) { measured = ''; }
  const lowPower = (memory > 0 && memory <= 2) || (!memory && cores > 0 && cores <= 2) || effType === '2g' || effType === 'slow-2g' || measured === 'low' || measured === 'very-low';

  if (saveData) root.classList.add('delicat-save-data');
  if (saveData || lowPower) {
    root.classList.add('delicat-low-power', 'dsb8-low-power', 'dsb-cheap-device');
    if (measured === 'very-low') root.classList.add('delicat-very-low-power');
  } else if (reducedMotionQuery && reducedMotionQuery.matches) {
    root.classList.add('dsb-cheap-device');
  }

  let preference = 'light';
  let switchTimer = 0;
  let assistantObserver = null;
  let assistantShell = null;
  let assistantSyncQueued = false;

  const safeRead = () => {
    try {
      const value = readCookie(key);
      return valid.has(value) ? value : null;
    } catch (_) {
      return null;
    }
  };

  const safeWrite = (value) => {
    try { writeCookie(key, value, 31536000); } catch (_) {}
  };

  const effectiveTheme = (value) => value === 'system'
    ? (systemQuery && systemQuery.matches ? 'dark' : 'light')
    : (value === 'dark' ? 'dark' : 'light');

  const syncButtons = (theme) => {
    document.querySelectorAll('[data-delicat-theme-toggle]').forEach((button) => {
      const dark = theme === 'dark';
      button.setAttribute('aria-pressed', dark ? 'true' : 'false');
      button.setAttribute('aria-label', dark ? 'Activer le mode clair' : 'Activer le mode sombre');
      button.dataset.delicatThemeState = theme;
      button.title = dark ? 'Mode clair' : 'Mode sombre';
    });
  };

  const syncHeaderLogo = (theme) => {
    document.querySelectorAll('img[data-dsb8-logo-light]').forEach((image) => {
      const light = image.getAttribute('data-dsb8-logo-light') || '';
      const dark = image.getAttribute('data-dsb8-logo-dark') || '';
      const target = theme === 'dark' && dark ? dark : light;
      if (!target || image.getAttribute('src') === target) return;
      image.setAttribute('src', target);
      /* Attachment srcset still references the original asset; remove it when
         swapping so the browser cannot select the opposite logo candidate. */
      image.removeAttribute('srcset');
      image.removeAttribute('sizes');
    });

    /* Cached pre-RC71 header markup contains separate light/dark links. Some
       Android WebViews ignore the earlier cascade during theme restoration and
       briefly keep both links visible. Set the legacy pair explicitly while
       retaining both nodes for future theme changes. */
    document.querySelectorAll('.dsb8-header__logo').forEach((logo) => {
      const light = logo.querySelector('.dsb8-header__logo-light');
      const dark = logo.querySelector('.dsb8-header__logo-dark');
      if (!light || !dark) return;
      const showDark = theme === 'dark';
      light.hidden = showDark;
      dark.hidden = !showDark;
      light.setAttribute('aria-hidden', showDark ? 'true' : 'false');
      dark.setAttribute('aria-hidden', showDark ? 'false' : 'true');
      light.style.setProperty('display', showDark ? 'none' : 'flex', 'important');
      dark.style.setProperty('display', showDark ? 'flex' : 'none', 'important');
    });
  };

  const syncLegacyThemeBridges = (theme) => {
    const dark = theme === 'dark';
    const lightClass = 'delicat-home-mode-light';
    const darkClass = 'delicat-home-mode-dark';

    if (document.body) {
      document.body.classList.toggle('dlc-theme-dark', dark);
      document.body.classList.toggle('dlc-theme-light', !dark);

      if (
        document.body.classList.contains(lightClass) ||
        document.body.classList.contains(darkClass) ||
        document.body.classList.contains('delicat-builder-homepage-managed')
      ) {
        document.body.classList.toggle(darkClass, dark);
        document.body.classList.toggle(lightClass, !dark);
      }
    }

    document.querySelectorAll('[data-delicat-home-mode], [data-delicat-homepage-managed="1"], .delicat-page-layout--homepage-managed').forEach((node) => {
      node.classList.toggle(darkClass, dark);
      node.classList.toggle(lightClass, !dark);
      if (node.hasAttribute('data-delicat-home-mode')) {
        node.setAttribute('data-delicat-home-mode', theme);
      }
    });
  };

  const syncAssistantChrome = () => {
    if (!document.body) return false;

    const shell = document.querySelector('.dap-shell.dap-floating, .dap-shell .dap-floating');
    const retiredWhatsapp = document.querySelector(
      'a[aria-label="WhatsApp"][href*="BXQXUCKDDI3GO1"][style*="position:fixed"]'
    );

    /* The standalone floating WhatsApp plugin was retired in RC71.6. Remove
       its cached server/PWA markup without touching footer or menu links. */
    if (retiredWhatsapp) retiredWhatsapp.remove();

    if (!shell) {
      document.body.classList.remove('dbv9-assistant-open');
      return false;
    }

    const launcher = shell.querySelector('.dap-launcher');
    const unread = shell.querySelector('.dap-unread');
    const expanded = launcher ? launcher.getAttribute('aria-expanded') : '';
    const explicitDialog = shell.querySelector(
      '[role="dialog"][aria-hidden="false"], .dap-panel.is-open, .dap-window.is-open, .dap-dialog.is-open, .dap-chat.is-open'
    );
    const bounds = shell.getBoundingClientRect();
    const open = expanded === 'true'
      || shell.classList.contains('is-open')
      || shell.classList.contains('dap-open')
      || shell.classList.contains('is-expanded')
      || !!explicitDialog
      || bounds.width > 240
      || bounds.height > 240;

    document.body.classList.toggle('dbv9-assistant-open', open);

    if (launcher && !launcher.getAttribute('aria-label')) {
      launcher.setAttribute('aria-label', 'Ouvrir l\u2019assistant Delicat');
    }

    if (unread) {
      const value = (unread.textContent || '').trim();
      const count = Number.parseInt(value, 10);
      const empty = value === '' || (!Number.isNaN(count) && count <= 0);
      if (unread.hidden !== empty) unread.hidden = empty;
      const ariaHidden = empty ? 'true' : 'false';
      if (unread.getAttribute('aria-hidden') !== ariaHidden) {
        unread.setAttribute('aria-hidden', ariaHidden);
      }
    }

    if (assistantShell !== shell && typeof MutationObserver === 'function') {
      if (assistantObserver) assistantObserver.disconnect();
      assistantShell = shell;
      assistantObserver = new MutationObserver(() => {
        if (assistantSyncQueued) return;
        assistantSyncQueued = true;
        window.requestAnimationFrame(() => {
          assistantSyncQueued = false;
          syncAssistantChrome();
        });
      });
      assistantObserver.observe(shell, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-expanded', 'aria-hidden', 'class']
      });
    }

    return true;
  };

  const primeAssistantChrome = () => {
    if (syncAssistantChrome() || !document.body || typeof MutationObserver !== 'function') return;

    const discoveryObserver = new MutationObserver(() => {
      if (!syncAssistantChrome()) return;
      discoveryObserver.disconnect();
    });
    /* Floating assistant shells are mounted as body-level nodes. Watching the
       entire document subtree for eight seconds made every carousel/product DOM
       update feed an observer that was only looking for one launcher. */
    discoveryObserver.observe(document.body, { childList: true });
    window.setTimeout(() => discoveryObserver.disconnect(), 3500);
  };

  /* 9.4.1: from a toggle, the new theme grows as a circle from it (view transition).
   * 9.4.2: the circle (clip-path) only where the phone can afford it; elsewhere the new
   * theme fades in over the old snapshot (opacity, composited); instant with reduce motion. */
  const canReveal = (event) => {
    if (!event || typeof document.startViewTransition !== 'function' || (reducedMotionQuery && reducedMotionQuery.matches) || root.classList.contains('delicat-very-low-power')) return '';
    return typeof root.animate === 'function' && !root.classList.contains('delicat-low-power') && (!memory || memory >= 4) ? 'circle' : 'fade';
  };

  const apply = (value, animate = false, event = null) => {
    preference = valid.has(value) ? value : 'light';
    const theme = effectiveTheme(preference);
    const reveal = animate && canReveal(event);

    const commit = () => {
      root.dataset.delicatThemePreference = preference;
      root.dataset.delicatTheme = theme;
      root.classList.toggle('dlc-theme-dark', theme === 'dark');
      root.classList.toggle('dlc-theme-light', theme === 'light');
      root.style.colorScheme = theme;

      syncLegacyThemeBridges(theme);
      syncHeaderLogo(theme);
      syncButtons(theme);
      document.dispatchEvent(new CustomEvent('delicat:themechange', {
        detail: { theme, preference, vt: !!reveal }
      }));
    };

    if (reveal) {
      let x = Number(event.clientX) || 0;
      let y = Number(event.clientY) || 0;
      if (!x && !y && event.target instanceof Element) {
        const box = (event.target.closest('[data-delicat-theme-toggle]') || event.target).getBoundingClientRect();
        x = box.left + box.width / 2;
        y = box.top + box.height / 2;
      }
      root.classList.add('dlc-theme-vt');
      if (reveal === 'fade') root.classList.add('dlc-theme-vt--fade');
      let transition = null;
      try { transition = document.startViewTransition(commit); } catch (error) { transition = null; }
      if (transition) {
        const done = () => root.classList.remove('dlc-theme-vt', 'dlc-theme-vt--fade');
        transition.finished.then(done, done);
        if (reveal === 'fade') return;
        transition.ready.then(() => {
          const w = window.innerWidth || root.clientWidth;
          const h = window.innerHeight || root.clientHeight;
          const r = Math.ceil(Math.hypot(Math.max(x, w - x), Math.max(y, h - y)));
          try {
            root.animate(
              { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + r + 'px at ' + x + 'px ' + y + 'px)'] },
              { duration: 480, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', pseudoElement: '::view-transition-new(root)' }
            );
          } catch (error) { /* the plain switch already happened */ }
        }, () => {});
        return;
      }
      root.classList.remove('dlc-theme-vt', 'dlc-theme-vt--fade');
    }

    if (animate && !(reducedMotionQuery && reducedMotionQuery.matches) && !root.classList.contains('delicat-low-power')) {
      root.classList.add('dlc-theme-switching');
      window.clearTimeout(switchTimer);
      switchTimer = window.setTimeout(() => root.classList.remove('dlc-theme-switching'), 220);
    }
    commit();
  };

  const bootPreference = safeRead()
    || root.dataset.delicatThemePreference
    || 'light';
  apply(bootPreference, false);

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-delicat-theme-toggle]') : null;
    if (!target) return;
    event.preventDefault();
    const current = root.dataset.delicatTheme === 'dark' ? 'dark' : 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    safeWrite(next);
    apply(next, true, event);
  }, true);

  if (systemQuery) {
    const onSystemChange = () => {
      if (preference === 'system') apply('system', true);
    };
    if (typeof systemQuery.addEventListener === 'function') systemQuery.addEventListener('change', onSystemChange);
    else if (typeof systemQuery.addListener === 'function') systemQuery.addListener(onSystemChange);
  }

  const resyncCurrentTheme = () => {
    const theme = root.dataset.delicatTheme === 'dark' ? 'dark' : 'light';
    syncLegacyThemeBridges(theme);
    syncHeaderLogo(theme);
    syncButtons(theme);
    primeAssistantChrome();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', resyncCurrentTheme, { once: true });
  } else {
    resyncCurrentTheme();
  }

  document.addEventListener('delicat:navigation-complete', resyncCurrentTheme);
  window.addEventListener('pageshow', resyncCurrentTheme, { passive: true });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('.dap-shell') : null;
    if (!target) return;
    window.setTimeout(syncAssistantChrome, 0);
    window.setTimeout(syncAssistantChrome, 360);
  }, true);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') window.setTimeout(syncAssistantChrome, 0);
  }, true);

  window.DelicaTheme = Object.freeze({
    get: () => root.dataset.delicatTheme || 'light',
    getPreference: () => preference,
    set: (value) => {
      const safe = valid.has(value) ? value : 'light';
      safeWrite(safe);
      apply(safe, true);
    }
  });
})();
