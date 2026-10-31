// node nav-account.cjs <plugin-dir> <member|guest>
// Account sections on static pages served from the plugin's real assets: dashboard, orders and
// order details switch in place; edit-account (new script), an order with codes (nav-full) and
// pages reached from outside the account load as full pages.
const { chromium, devices } = require('playwright');
const fs = require('fs'), path = require('path');
const PLUGIN = path.resolve(process.argv[2]), MODE = process.argv[3] || 'member';
const member = MODE === 'member';
const man = JSON.parse(fs.readFileSync(PLUGIN + '/assets/dist/manifest.json', 'utf8'));
const BASE = 'https://shop.test', PURL = BASE + '/wp-content/plugins/delicat-builder-v9/';
const chunks = {}; for (const [f, m] of Object.entries(man.chunks)) if (m.name) chunks[m.name] = PURL + 'assets/dist/' + f;
const hard = ['/cart/', '/panier/', '/checkout/', '/commande/', '/my-account/', '/mon-compte/', '/order-pay/', '/order-received/', '/customer-logout/', '/lost-password/', '/my-wallet/', '/wallet/', '/mon-portefeuille/'];
const nav = { enabled: true, products: true, prefetch: true, transitions: true, budget: 8, cacheTtl: 120000, cacheMax: 24, generation: 'g1', home: BASE + '/', hardPaths: hard, productPaths: ['/product/'],
  account: member ? { base: '/my-account/', paths: ['/my-account/orders/', '/my-account/view-order/'] } : [] };
const css = (n) => PURL + 'assets/dist/' + man.css[n].file;
const pages = {
  '/': { title: 'Accueil', key: 'home' },
  '/my-account/': { title: 'Compte', key: 'account', acc: 1 },
  '/my-account/orders/': { title: 'Mes commandes', key: 'account', acc: 1 },
  '/my-account/view-order/22431/': { title: 'Commande 22431', key: 'account', acc: 1, vo: 'sending' },
  '/my-account/view-order/22447/': { title: 'Commande 22447', key: 'account', acc: 1, vo: 'delivered', codes: 1 },
  '/my-account/edit-account/': { title: 'Profil', key: 'account', acc: 1, extra: 1 },
};
const links = `<p><a id="to-home" href="${BASE}/">Accueil</a> <a id="to-dash" href="${BASE}/my-account/">Compte</a> <a id="to-orders" href="${BASE}/my-account/orders/">Commandes</a>
 <a id="to-o1" href="${BASE}/my-account/view-order/22431/">22431</a> <a id="to-o2" href="${BASE}/my-account/view-order/22447/">22447</a> <a id="to-edit" href="${BASE}/my-account/edit-account/">Profil</a></p>`;
function html(p) {
  const pg = pages[p];
  const cfg = { version: 'x', debug: true, ajaxUrl: BASE + '/wp-admin/admin-ajax.php', homeUrl: BASE + '/', route: 'page', nav, session: { url: BASE + '/?wc-ajax=delicat_session', resetUrl: BASE + '/' }, pullRefresh: false, reviews: {}, globals: [], chunks };
  const vo = pg.vo ? `<section class="dlc-vo" id="dlc-vo" data-dlc-vo data-phase="${pg.vo}" data-tone="accent"><div class="dlc-vo-card dlc-vo-track"><p class="dlc-vo-live" data-vo="live" hidden><span data-vo="live-label">x</span></p></div>
    ${pg.codes ? '<section class="dlc-vo-card dlc-vo-codes" data-delicat-nav-full><div class="dlc-vo-codes__body"><code>AAAA-BBBB</code></div></section>' : ''}
    <script type="application/json" class="dlc-vo-config">{"live":${pg.vo === 'sending'},"endpoint":"${BASE}/wp-json/delicat-builder-v9/v1/order-status","token":"v1.1.x","order":22431,"fingerprint":"f1","maxMinutes":30,"i18n":{}}</script></section>` : '';
  const scripts = pg.acc ? `<script id="delicat-builder-v9-order-view-js" src="${PURL}assets/js/order-view.js" defer></script>` + (pg.extra ? `<script id="dip-passkeys-js" src="${BASE}/wp-content/plugins/delicat-identity-pro/assets/passkeys.js" defer></script>` : '') : '';
  const tabs = [['home', '/', 'Accueil'], ['account', '/my-account/', 'Compte']].map(([k, u, l]) => `<a class="dbn-item${k === pg.key ? ' is-active' : ''}" data-dbn-key="${k}" href="${BASE + u}">${l}</a>`).join('');
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${pg.title}</title><meta name="delicat-generation" content="g1">
<script id="delicat-engine-js-before">window.DelicatEngine=${JSON.stringify(cfg)};</script>
<link rel="stylesheet" id="dlc-chrome-css" href="${css('chrome')}">${pg.acc ? `<link rel="stylesheet" id="delicat-builder-v9-order-view-css" href="${PURL}assets/css/order-view.css">` : ''}
<script type="module" src="${PURL}assets/dist/${man.js.engine.file}" id="delicat-engine-js"></script></head>
<body class="dbp-route-page delicat-bottom-nav-active${member ? ' logged-in' : ''}${pg.acc ? ' woocommerce-account woocommerce-page dip-account-app' : ''}">
<header class="dsb8-header" style="height:56px">Delicat</header>
<main data-delicat-native-document class="delicat-native-page-main"><h1 id="t">${pg.title}</h1>${links}${pg.acc ? '<div class="woocommerce"><div class="woocommerce-MyAccount-content">' + vo + '</div></div>' : ''}<div style="height:700px"></div></main>
<nav class="delicat-bottom-nav">${tabs}</nav>${scripts}</body></html>`;
}
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ ...devices['Pixel 7'], reducedMotion: 'reduce' });
  const fetches = {}; const errs = []; let polls = 0;
  await ctx.route('**/*', async (route) => {
    const req = route.request(), u = new URL(req.url());
    if (u.origin !== BASE) return route.abort();
    if (u.pathname.startsWith('/wp-content/plugins/delicat-builder-v9/')) {
      const f = path.join(PLUGIN, u.pathname.replace('/wp-content/plugins/delicat-builder-v9/', ''));
      if (!fs.existsSync(f)) return route.fulfill({ status: 404, body: '' });
      return route.fulfill({ body: fs.readFileSync(f), contentType: f.endsWith('.js') ? 'text/javascript' : 'text/css' });
    }
    if (u.pathname.endsWith('passkeys.js')) return route.fulfill({ body: 'window.__passkeys=(window.__passkeys||0)+1;', contentType: 'text/javascript' });
    if (u.pathname.startsWith('/wp-json/')) { polls++; return route.fulfill({ status: 200, contentType: 'application/json', body: '{"phase":"sending","fingerprint":"f1","terminal":false}' }); }
    const pg = pages[u.pathname];
    if (!pg) return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    const key = u.pathname;
    if (pg.codes && req.headers()['x-delicat-nav'] === '1') { fetches[key] = fetches[key] || []; fetches[key].push('fetch:204'); return route.fulfill({ status: 204, headers: { 'x-delicat-nav': 'full', 'cache-control': 'no-store' }, body: '' }); }
    fetches[key] = fetches[key] || []; fetches[key].push(req.resourceType() + (req.headers()['x-delicat-prefetch'] ? ':prefetch' : ''));
    await new Promise((r) => setTimeout(r, 300));
    return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', headers: { 'cache-control': pg.acc ? 'no-store, no-cache, must-revalidate, private, max-age=0' : 'private, no-cache' }, body: html(u.pathname) });
  });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  p.on('console', (m) => { const t = m.text(); if (/fell back/.test(t)) console.log('  console:', t.slice(0, 160)); });
  const title = () => p.evaluate(() => (document.getElementById('t') || {}).textContent || '');
  async function until(name, fn, ms) { const end = Date.now() + (ms || 10000); while (Date.now() < end) { try { if (await fn()) return true; } catch (e) {} await p.waitForTimeout(50); } throw new Error('timeout at ' + name + ' url ' + p.url()); }
  const tap = async (sel) => { const el = await p.$(sel); await el.scrollIntoViewIfNeeded(); const bx = await el.boundingBox(); await p.touchscreen.tap(bx.x + bx.width / 2, bx.y + bx.height / 2); };
  const mark = () => p.evaluate(() => { window.__m = 1; });
  const kept = () => p.evaluate(() => window.__m === 1);
  async function go(sel, url, t) { await mark(); await tap(sel); await until(t, async () => new URL(p.url()).pathname === url && (await title()) === t); await p.waitForTimeout(250); return (await kept()) ? 'soft' : 'full'; }
  const R = {};
  await p.goto(BASE + '/'); await p.waitForTimeout(1200);
  R.homeToDash = await go('#to-dash', '/my-account/', 'Compte');
  await p.waitForTimeout(800);
  R.dashToOrders = await go('#to-orders', '/my-account/orders/', 'Mes commandes');
  R.ordersFetches = (fetches['/my-account/orders/'] || []).join(',');
  R.ordersToO1 = await go('#to-o1', '/my-account/view-order/22431/', 'Commande 22431');
  R.voBound = await p.evaluate(() => { const r = document.getElementById('dlc-vo'); return !!(r && r.getAttribute('data-vo-bound')); });
  await p.waitForTimeout(4500);
  R.livePolls = polls;
  await mark();
  await p.goBack({ waitUntil: 'commit' }).catch(() => {});
  await until('back-orders', async () => new URL(p.url()).pathname === '/my-account/orders/' && (await title()) === 'Mes commandes');
  await p.waitForTimeout(300);
  R.backToOrders = (await kept()) ? 'soft' : 'full';
  const pollsAfterLeave = polls; await p.waitForTimeout(6000); R.pollsStoppedAfterLeave = polls === pollsAfterLeave;
  R.ordersToO2codes = await go('#to-o2', '/my-account/view-order/22447/', 'Commande 22447');
  R.copyBtn = await p.evaluate(() => document.querySelectorAll('.dlc-vo-copy').length);
  R.o2ToOrders = await go('#to-orders', '/my-account/orders/', 'Mes commandes');
  R.ordersToEdit = await go('#to-edit', '/my-account/edit-account/', 'Profil');
  R.editToDash = await go('#to-dash', '/my-account/', 'Compte');
  R.dashToHome = await go('#to-home', '/', 'Accueil');
  // desktop-style hover on an account link must not fetch it ahead
  await p.goto(BASE + '/my-account/'); await p.waitForTimeout(1500);
  const before = (fetches['/my-account/orders/'] || []).length;
  const el = await p.$('#to-orders'); const bx = await el.boundingBox();
  await p.mouse.move(bx.x + 3, bx.y + 3); await p.waitForTimeout(600);
  R.hoverPrefetched = (fetches['/my-account/orders/'] || []).length > before;
  R.allFetches = Object.fromEntries(Object.entries(fetches).map(([k, v]) => [k, v.join(',')]));
  R.errors = errs;
  console.log(JSON.stringify({ mode: MODE, ...R }));
  await b.close();
})().catch((e) => { console.error('HARNESS FAIL', e.message); process.exit(1); });
