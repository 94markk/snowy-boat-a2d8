/*
 * Paste this into your browser's DevTools console WHILE ON YOUR OWN STORE.
 *
 *   1. Open https://delicastoreha.com in Chrome
 *   2. Press F12  (or Cmd+Option+I on a Mac) and click "Console"
 *   3. If asked, type: allow pasting
 *   4. Paste this whole file, press Enter, wait ~15s
 *   5. Copy the table it prints and send it back
 *
 * Same-origin, so it can read the response headers a cross-site tool cannot.
 * Read-only: every request is a GET. The one request carrying an action
 * parameter uses a deliberately non-existent product id, so nothing can ever
 * be added to a cart.
 */
(async () => {
  const origin = location.origin;
  const out = [];
  const add = (check, verdict, detail) => out.push({ check, verdict, detail });

  const headersOf = async (path, init) => {
    try {
      const r = await fetch(origin + path, Object.assign({ credentials: 'include' }, init || {}));
      const keep = {};
      r.headers.forEach((v, k) => {
        if (/^(x-delicat|x-litespeed|x-lsadc|cache-control|age)/i.test(k)) keep[k] = v;
      });
      return { status: r.status, headers: keep, text: await r.text() };
    } catch (e) {
      return { status: 0, headers: {}, text: '', error: String(e) };
    }
  };

  const fmt = (h) => Object.entries(h).map(([k, v]) => k + ': ' + v).join(' | ') || '(none)';
  const cacheable = (h) => /public|hit|miss/i.test(fmt(h));
  const priv = (h) => /private|no-cache|no-store|bypass/i.test(fmt(h));

  console.log('%cDelicat Builder V9 — live check', 'font-weight:bold;font-size:14px');
  console.log('origin:', origin, '| started', new Date().toISOString());

  /* ---------- should be publicly cacheable ---------- */
  for (const [label, path] of [
    ['homepage', '/'],
    ['shop archive', '/boutique/'],
    ['ad landing ?fbclid', '/?fbclid=verify123'],
    ['ad landing ?utm_source', '/?utm_source=verify'],
  ]) {
    const r = await headersOf(path);
    add(label, r.status === 200 ? (cacheable(r.headers) ? 'CACHEABLE' : 'not cacheable') : 'HTTP ' + r.status, fmt(r.headers));
  }

  /* ---------- must stay private ---------- */
  for (const [label, path] of [
    ['cart (must be private)', '/panier/'],
    ['account (must be private)', '/mon-compte/'],
    ['action ?add-to-cart (must bypass)', '/?add-to-cart=999999999'],
    ['unknown param (must bypass)', '/?zz_verify=1'],
  ]) {
    const r = await headersOf(path);
    add(label, priv(r.headers) ? 'PRIVATE (correct)' : 'NOT PRIVATE — investigate', fmt(r.headers));
  }

  /* ---------- the actual fixes, read out of the HTML ---------- */
  const shop = await headersOf('/boutique/');
  const crit = (shop.text.match(/<style id="delicat-builder-v9-critical">([\s\S]*?)<\/style>/) || [])[1] || '';
  add('critical CSS inlined on archive', crit ? 'YES (' + crit.length + ' B)' : 'MISSING', '');
  add('archive grid in critical CSS  <-- Phase 6 fix',
      /grid-template-columns/.test(crit) ? 'YES' : 'NO — woo-archive still dropped', '');
  add('image ratio reserved in critical CSS',
      /delicat-woo-ratio-/.test(crit) ? 'YES' : 'no', '');

  const sizes = (shop.text.match(/sizes="(\(max-width:640px\)[^"]*)"/) || [])[1] || '';
  const isizes = (shop.text.match(/imagesizes="([^"]*)"/) || [])[1] || '';
  add('archive img sizes', sizes || '(not found)', '');
  if (sizes && isizes) {
    add('LCP preload matches img sizes',
        sizes === isizes ? 'YES' : 'NO — hero image downloaded twice', isizes);
  }

  /* Phase 1 safety: a page served to a shopper who has a cart must not carry
     a server-rendered count. Uses whatever cart you currently have. */
  const badge = document.querySelector('[data-dsb8-cart-count], [data-dbn-cart-count]');
  const cookieCount = (document.cookie.match(/woocommerce_items_in_cart=(\d+)/) || [])[1] || '0';
  add('cart badge in DOM', badge ? badge.textContent.trim() || '(empty)' : '(not found)',
      'cookie says ' + cookieCount);

  /* ---------- timings ---------- */
  const time = async (path) => {
    const t0 = performance.now();
    await fetch(origin + path, { cache: 'reload', credentials: 'include' });
    const cold = Math.round(performance.now() - t0);
    const t1 = performance.now();
    await fetch(origin + path, { credentials: 'include' });
    return cold + ' ms cold / ' + Math.round(performance.now() - t1) + ' ms warm';
  };
  add('timing homepage', await time('/'), '');
  add('timing shop archive', await time('/boutique/'), '');

  console.table(out);
  console.log('%cCopy the table above (right-click > Copy table) and send it back.',
              'font-weight:bold');
  return out;
})();
