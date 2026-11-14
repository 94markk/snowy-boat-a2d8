/* Delicat Identity 6.12.2 — adapt the account to the site's light/dark switch.
   Read-only: never change the site's theme, preference, cookie, or UI state. */
(function () {
  'use strict';
  var root = document.documentElement;
  if (!root) return;
  var darkQuery = typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var preferenceKeys = [
    'delicat_theme', 'delicat-theme', 'delicatTheme', 'delicat_store_theme',
    'delicat_builder_theme', 'delicat_v9_theme', 'db9_theme', 'db9-theme',
    'ds_theme', 'ds-theme', 'dsTheme', 'dsb_theme', 'dsb9_theme',
    'theme', 'theme-mode', 'theme_mode', 'themeMode', 'color-mode',
    'color_mode', 'color-scheme', 'appearance', 'dark_mode', 'darkMode',
    'delicat_dark_mode', 'ds_dark_mode'
  ];
  var themeAttributes = [
    'data-theme', 'data-dip-theme', 'data-bs-theme', 'data-color-scheme',
    'data-color-mode', 'data-theme-mode', 'data-mode', 'data-appearance',
    'data-ds-theme', 'data-dsb-theme', 'data-dsb9-theme', 'data-delicat-theme'
  ];
  var darkClasses = ['dark', 'dark-mode', 'theme-dark', 'dark-theme', 'is-dark',
    'is-dark-theme', 'ds-dark', 'dsb-dark', 'dsb9-dark', 'delicat-dark', 'night-mode'];
  var lightClasses = ['light', 'light-mode', 'theme-light', 'light-theme', 'is-light',
    'is-light-theme', 'ds-light', 'dsb-light', 'dsb9-light', 'delicat-light', 'day-mode'];

  function parse(value, key) {
    if (typeof value !== 'string') return '';
    value = value.trim().toLowerCase();
    if (value === 'dark' || value === 'night' || value === 'sombre') return 'dark';
    if (value === 'light' || value === 'day' || value === 'clair') return 'light';
    if (/dark[_-]?mode|night[_-]?mode/.test(key || '')) {
      if (value === '1' || value === 'true' || value === 'on') return 'dark';
      if (value === '0' || value === 'false' || value === 'off') return 'light';
    }
    return '';
  }

  function fromElement(el) {
    if (!el) return '';
    for (var i = 0; i < themeAttributes.length; i++) {
      var attr = themeAttributes[i];
      var choice = parse(el.getAttribute(attr), attr);
      if (choice) return choice;
    }
    var classes = el.classList;
    for (var j = 0; j < darkClasses.length; j++) if (classes.contains(darkClasses[j])) return 'dark';
    for (var k = 0; k < lightClasses.length; k++) if (classes.contains(lightClasses[k])) return 'light';
    return '';
  }

  function fromStorage() {
    try {
      var storage = window.localStorage;
      if (!storage) return '';
      for (var i = 0; i < preferenceKeys.length; i++) {
        var key = preferenceKeys[i];
        var val = storage.getItem(key);
        if (val === null || val.length > 128) continue;
        var choice = parse(val, key);
        if (choice) return choice;
      }
    } catch (e) { /* Storage can be blocked in embedded/PWA contexts. */ }
    return '';
  }

  function resolve() {
    var body = document.body;
    // Explicit site-owner setting takes priority if dip_account_color_scheme is set.
    if (body && body.classList.contains('dipma-scheme-light')) return 'light';
    if (body && body.classList.contains('dipma-scheme-dark')) return 'dark';
    // The website's live theme control wins over the OS or cached preference.
    var explicit = fromElement(root) || fromElement(body);
    if (explicit) return explicit;
    var stored = fromStorage();
    if (stored) return stored;
    // Some themes express their preference solely through root color-scheme.
    try {
      var cs = window.getComputedStyle(root).colorScheme.trim();
      if (cs === 'light' || cs === 'dark') return cs;
    } catch (e) {}
    return darkQuery && darkQuery.matches ? 'dark' : 'light';
  }

  function update() {
    var mode = resolve();
    if (root.getAttribute('data-dip-account-theme') !== mode) {
      root.setAttribute('data-dip-account-theme', mode);
    }
  }

  // Called from the head: explicit site <html> flags/known storage work before paint.
  update();

  function observe() {
    update(); // body classes can be read now
    if (!window.MutationObserver) return;
    var observer = new MutationObserver(update);
    var watch = {attributes:true, attributeFilter: ['class', 'style'].concat(themeAttributes)};
    observer.observe(root, watch);
    if (document.body) observer.observe(document.body, watch);
    // Rerender after site theme button handlers write localStorage in this same tab.
    document.addEventListener('click', function (evt) {
      if (evt.target && evt.target.closest && evt.target.closest('button,[role="switch"],.theme-toggle,[data-theme-toggle]')) {
        window.requestAnimationFrame(update);
        window.setTimeout(update, 120);
      }
    }, true);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', observe, {once:true});
  else observe();
  if (darkQuery) {
    if (darkQuery.addEventListener) darkQuery.addEventListener('change', update);
    else if (darkQuery.addListener) darkQuery.addListener(update);
  }
  window.addEventListener('pageshow', update);
  window.addEventListener('storage', update);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) update(); });
})();
