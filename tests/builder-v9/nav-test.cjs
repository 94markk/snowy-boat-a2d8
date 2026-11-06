// node nav-test.cjs <plugin-dir> <guest|member> <footer-part.html> [shotsPrefix]
const { chromium, devices } = require('playwright');
const fs = require('fs'), path = require('path');
const PLUGIN = path.resolve(process.argv[2]), MODE = process.argv[3] || 'guest', FOOT = fs.readFileSync(process.argv[4], 'utf8');
const SHOTS = process.argv[5] || '';
const man = JSON.parse(fs.readFileSync(PLUGIN + '/assets/dist/manifest.json', 'utf8'));
const BASE = 'https://shop.test', PURL = BASE + '/wp-content/plugins/delicat-builder-v9/';
const chunks = {}; for (const [f, m] of Object.entries(man.chunks)) if (m.name) chunks[m.name] = PURL + 'assets/dist/' + f;
const hard = ['/cart/', '/panier/', '/checkout/', '/commande/', '/my-account/', '/mon-compte/', '/order-pay/', '/order-received/', '/customer-logout/', '/lost-password/', '/my-wallet/', '/wallet/', '/mon-portefeuille/'];
const member = MODE === 'member';
const pages = {
  '/': { route: 'home', title: 'Accueil', key: 'home', css: 'home' },
  '/shop/': { route: 'shop', title: 'Boutique', key: 'shop', css: 'shop' },
  '/product/carte-a/': { route: 'product', title: 'Carte A', key: 'shop', css: 'product' },
  '/product/carte-b/': { route: 'product', title: 'Carte B', key: 'shop', css: 'product' },
  '/cart/': { route: 'page', title: 'Panier', key: 'cart', css: 'woo', hard: 1 },
  '/my-account/': { route: 'page', title: 'Mon compte', key: 'account', css: 'woo', hard: 1 },
  '/my-wallet/': { route: 'page', title: 'Wallet', key: 'wallet', css: 'purchase', hard: 1 },
};
const nav = { enabled: true, products: true, prefetch: true, transitions: true, budget: 8, cacheTtl: 120000, cacheMax: 24, generation: 'g1', home: BASE + '/', hardPaths: hard, productPaths: ['/product/', '/produit/'] };
const css = (n) => PURL + 'assets/dist/' + man.css[n].file;
function html(p) {
  const pg = pages[p];
  const cfg = { version: 'x', debug: true, ajaxUrl: BASE + '/wp-admin/admin-ajax.php', homeUrl: BASE + '/', route: pg.route, nav, session: { url: BASE + '/?wc-ajax=delicat_session', resetUrl: BASE + '/' }, pullRefresh: false, reviews: {}, globals: [], chunks };
  const tabs = [['home', '/', 'Accueil'], ['wallet', '/my-wallet/', 'Wallet'], ['shop', '/shop/', 'Boutique'], ['cart', '/cart/', 'Panier'], ['account', '/my-account/', 'Compte']]
    .map(([k, u, l]) => `<a class="dbn-item${k === pg.key ? ' is-active' : ''}" data-dbn-key="${k}" href="${BASE + u}"${k === pg.key ? ' aria-current="page"' : ''}><span class="dbn-label">${l}</span></a>`).join('');
  const cards = ['carte-a', 'carte-b'].map((s) => `<li class="product"><a class="woocommerce-LoopProduct-link" data-delicat-product-link href="${BASE}/product/${s}/"><img alt="" width="160" height="100" src="data:image/gif;base64,R0lGODlhAQABAAAAACw="><h2 class="woocommerce-loop-product__title">${s}</h2></a></li>`).join('');
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>${pg.title} – Delicat</title><meta name="delicat-generation" content="g1">
<script id="delicat-engine-js-before">window.DelicatEngine=${JSON.stringify(cfg)};</script>
<link rel="stylesheet" id="dlc-chrome-css" href="${css('chrome')}"><link rel="stylesheet" id="dlc-route-css" href="${css(pg.css)}">
<script type="module" src="${PURL}assets/dist/${man.js.engine.file}" id="delicat-engine-js"></script></head>
<body class="dbp-route-${pg.route} delicat-bottom-nav-active delicat-shell-mobile-nav-active dbv9-universal-footer-active${member ? ' logged-in' : ''}${pg.hard ? ' woocommerce-account' : ''}">
<header class="dsb8-header is-sticky" style="position:sticky;top:0;z-index:400;height:60px;background:#fff">Delicat</header>
<main data-delicat-native-document class="delicat-native-page-main"><h1 id="t">${pg.title}</h1>
<p><a id="to-shop" href="${BASE}/shop/">Boutique</a> · <a id="to-home" href="${BASE}/">Accueil</a> · <a id="to-cart" href="${BASE}/cart/">Panier</a></p>
<ul class="products">${cards}</ul><div style="height:900px"></div></main>
${FOOT}
<nav class="delicat-bottom-nav" aria-label="Navigation">${tabs}</nav></body></html>`;
}
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true, reducedMotion: process.env.MOTION === 'on' ? 'no-preference' : 'reduce' });
  await ctx.addInitScript(() => { try { Object.defineProperty(navigator, 'deviceMemory', { get: () => 8 }); } catch (e) {}
    window.__probe = function () { const t0 = performance.now(); const seen = {}; const mo = new MutationObserver(() => {
      if (!seen.overlay && document.querySelector('.dph-leave')) { seen.overlay = 1; console.log('[probe] overlay ' + Math.round(performance.now() - t0)); }
      const c = document.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="cart"]'); if (!seen.tab && c && c.classList.contains('is-active')) { seen.tab = 1; console.log('[probe] cart-tab ' + Math.round(performance.now() - t0)); }
      if (!seen.leaving && document.documentElement.classList.contains('delicat-leaving')) { seen.leaving = 1; console.log('[probe] leaving ' + Math.round(performance.now() - t0)); }
    }); mo.observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] }); };
  });
  const fetches = {}; const errs = [];
  await ctx.route('**/*', async (route) => {
    const u = new URL(route.request().url());
    if (u.origin !== BASE) return route.abort();
    if (u.pathname.startsWith('/wp-content/plugins/delicat-builder-v9/')) {
      const f = path.join(PLUGIN, u.pathname.replace('/wp-content/plugins/delicat-builder-v9/', ''));
      if (!fs.existsSync(f)) return route.fulfill({ status: 404, body: '' });
      const ct = f.endsWith('.js') ? 'text/javascript' : f.endsWith('.css') ? 'text/css' : 'application/octet-stream';
      return route.fulfill({ body: fs.readFileSync(f), contentType: ct, headers: { 'cache-control': 'public, max-age=31536000' } });
    }
    if (process.env.WALLET_204 && u.pathname === '/my-wallet/' && !u.search) { await new Promise((r) => setTimeout(r, 500)); return route.fulfill({ status: 204, body: '' }); }
    const pg = pages[u.pathname];
    if (!pg) return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    const kind = route.request().resourceType();
    fetches[u.pathname] = fetches[u.pathname] || []; fetches[u.pathname].push(kind + (route.request().headers()['x-delicat-prefetch'] ? ':prefetch' : ''));
    await new Promise((r) => setTimeout(r, pg.hard ? Number(process.env.HARD_DELAY || 1200) : 350));
    const cc = pg.hard ? 'no-store, no-cache, must-revalidate, private, max-age=0' : member ? 'private, no-cache, must-revalidate, max-age=0' : 'public, max-age=0';
    return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', headers: { 'cache-control': cc }, body: html(u.pathname) });
  });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  const probe = []; p.on('console', (m) => { const t = m.text(); if (/^\[probe\]/.test(t)) probe.push(t.slice(8)); else if (/fell back|engine\]/i.test(t)) console.log('  console:', t.slice(0, 200)); });
  const R = {};
  const title = () => p.evaluate(() => (document.getElementById('t') || {}).textContent || '');
  async function until(name, fn, ms) {
    const end = Date.now() + (ms || 10000);
    while (Date.now() < end) { try { if (await fn()) return true; } catch (e) {} await p.waitForTimeout(50); }
    throw new Error('timeout at ' + name + ' (url ' + p.url() + ', title ' + (await title().catch(() => '?')) + ')');
  }
  const tapSel = async (sel) => { const el = await p.$(sel); await el.scrollIntoViewIfNeeded(); const bx = await el.boundingBox(); await p.touchscreen.tap(bx.x + bx.width / 2, bx.y + bx.height / 2); };
  await p.goto(BASE + '/'); await p.waitForTimeout(1500);
  await p.evaluate(() => { window.__marker = 1; });
  // T1 soft navigation home -> shop
  await tapSel('#to-shop');
  await until('shop', async () => new URL(p.url()).pathname === '/shop/' && (await title()) === 'Boutique');
  R.softShop = await p.evaluate(() => window.__marker === 1);
  // T2 viewport prefetch of products, then open one
  await p.waitForTimeout(6500);
  R.productFetchesBeforeTap = (fetches['/product/carte-a/'] || []).join(',');
  const t0 = Date.now();
  await tapSel('a[href$="/product/carte-a/"]');
  await until('product', async () => new URL(p.url()).pathname === '/product/carte-a/' && (await title()) === 'Carte A');
  R.productOpenMs = Date.now() - t0;
  R.productFetchesTotal = (fetches['/product/carte-a/'] || []).join(',');
  R.productSoft = await p.evaluate(() => window.__marker === 1);
  // T3 back (soft)
  await p.goBack({ waitUntil: 'commit' }).catch(() => {});
  await until('back-shop', async () => new URL(p.url()).pathname === '/shop/' && (await title()) === 'Boutique');
  R.backSoft = await p.evaluate(() => window.__marker === 1);
  // T4 hard tab: Panier
  await p.evaluate(() => window.__probe());
  const cdpS = SHOTS ? await ctx.newCDPSession(p) : null;
  const cartEl = await p.$('.delicat-bottom-nav .dbn-item[data-dbn-key="cart"]'); const cbx = await cartEl.boundingBox();
  const tap = Date.now();
  const tapP = p.touchscreen.tap(cbx.x + cbx.width / 2, cbx.y + cbx.height / 2).catch(() => {});
  await new Promise((r) => setTimeout(r, 450));
  if (cdpS) { try { const shot = await cdpS.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(SHOTS + '-hard-pending.png', Buffer.from(shot.data, 'base64')); } catch (e) { console.log('  cdp shot failed', e.message); } }
  R.hardOverlayAt400ms = await p.evaluate(() => !!document.querySelector('.dph-leave')).catch(() => 'nav-already');
  R.hardTabActiveAt400ms = await p.evaluate(() => { const t = document.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="cart"]'); return !!(t && t.classList.contains('is-active')); }).catch(() => 'nav-already');
  await tapP;
  await until('cart', async () => new URL(p.url()).pathname === '/cart/' && (await title()) === 'Panier', 12000);
  R.hardLoadedMs = Date.now() - tap;
  R.hardProbe = probe.join(', ');
  R.hardWasFullLoad = await p.evaluate(() => window.__marker !== 1);
  // back from the hard page
  await p.goBack({ waitUntil: 'commit' }).catch(() => {});
  await until('back-from-cart', async () => new URL(p.url()).pathname === '/shop/' && (await title()) === 'Boutique', 12000);
  await p.waitForTimeout(600);
  R.afterBackOverlay = await p.evaluate(() => !!document.querySelector('.dph-leave'));
  R.afterBackLeavingClass = await p.evaluate(() => document.documentElement.classList.contains('delicat-leaving'));
  R.afterBackShopTabActive = await p.evaluate(() => { const t = document.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="shop"]'); return !!(t && t.classList.contains('is-active')); });
  if (process.env.WALLET_204) {
    const wEl = await p.$('.delicat-bottom-nav .dbn-item[data-dbn-key="wallet"]'); const wbx = await wEl.boundingBox();
    p.touchscreen.tap(wbx.x + wbx.width / 2, wbx.y + wbx.height / 2).catch(() => {});
    await new Promise((r) => setTimeout(r, 300));
    R.cancelOverlayAt300 = await p.evaluate(() => !!document.querySelector('.dph-leave')).catch((e) => 'err ' + e.message);
    await p.waitForTimeout(8500);
    R.cancelUrl = new URL(p.url()).pathname;
    R.cancelOverlayAfter = await p.evaluate(() => !!document.querySelector('.dph-leave'));
    R.cancelLeavingAfter = await p.evaluate(() => document.documentElement.classList.contains('delicat-leaving'));
    R.cancelWalletTabActive = await p.evaluate(() => document.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="wallet"]').classList.contains('is-active'));
    R.cancelShopTabActive = await p.evaluate(() => document.querySelector('.delicat-bottom-nav .dbn-item[data-dbn-key="shop"]').classList.contains('is-active'));
  }
  R.errors = errs;
  console.log(JSON.stringify({ mode: MODE, ...R }, null, 0));
  await b.close();
})().catch((e) => { console.error('HARNESS FAIL', e.message); process.exit(1); });
