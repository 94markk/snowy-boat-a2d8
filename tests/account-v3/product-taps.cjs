// node product-taps.cjs <plugin-dir> <member|guest> <4g|3g> — time from tap to product screen / full page
const { chromium, devices } = require('playwright');
const fs = require('fs'), path = require('path');
const PLUGIN = path.resolve(process.argv[2]), MODE = process.argv[3] || 'member', NET = process.argv[4] || '4g';
const member = MODE === 'member';
const man = JSON.parse(fs.readFileSync(PLUGIN + '/assets/dist/manifest.json', 'utf8'));
const BASE = 'https://shop.test', PURL = BASE + '/wp-content/plugins/delicat-builder-v9/';
const chunks = {}; for (const [f, m] of Object.entries(man.chunks)) if (m.name) chunks[m.name] = PURL + 'assets/dist/' + f;
const css = (n) => PURL + 'assets/dist/' + man.css[n].file;
const nav = { enabled: true, products: true, prefetch: true, transitions: true, budget: 8, cacheTtl: 120000, cacheMax: 24, generation: 'g1', home: BASE + '/', hardPaths: ['/cart/', '/my-account/', '/my-wallet/'], productPaths: ['/produit/'] };
const N = 14, names = Array.from({ length: N }, (_, i) => 'Carte ' + (i + 1));
function card(i) { const slug = 'carte-' + (i + 1); return `<article class="delicat-product-card" data-delicat-card data-product-id="${i + 1}"><div class="delicat-product-card__media-wrap"><a class="delicat-product-card__media" href="${BASE}/produit/${slug}/" data-delicat-prefetch data-delicat-product-link><img alt="${names[i]}" width="160" height="160" src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs="></a></div><a class="delicat-product-card__name" href="${BASE}/produit/${slug}/" data-delicat-prefetch data-delicat-product-link>${names[i]}</a><span class="delicat-product-card__price">${(i + 1) * 100} HTG</span></article>`; }
function page(p) {
  const prod = p.startsWith('/produit/'); const i = prod ? +p.match(/carte-(\d+)/)[1] - 1 : -1;
  const cfg = { version: 'x', debug: true, ajaxUrl: BASE + '/wp-admin/admin-ajax.php', homeUrl: BASE + '/', route: prod ? 'product' : 'home', nav, session: { url: BASE + '/?wc-ajax=delicat_session', resetUrl: BASE + '/' }, pullRefresh: false, reviews: {}, globals: [], chunks };
  const rail = (a, b) => `<div class="rail" style="display:flex;gap:12px;overflow-x:auto">${names.slice(a, b).map((_, k) => `<div style="flex:0 0 150px">${card(a + k)}</div>`).join('')}</div>`;
  const body = prod ? `<h1 id="t">${names[i]}</h1><p>Produit complet</p><div style="height:900px"></div>`
    : `<h1 id="t">Accueil</h1><h2>Populaires</h2>${rail(0, 7)}<div style="height:500px"></div><h2>Nouveautés</h2>${rail(7, 14)}<div style="height:900px"></div>`;
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${prod ? names[i] : 'Accueil'}</title><meta name="delicat-generation" content="g1">
<script id="delicat-engine-js-before">window.DelicatEngine=${JSON.stringify(cfg)};</script><link rel="stylesheet" id="dlc-chrome-css" href="${css('chrome')}"><link rel="stylesheet" id="dlc-route-css" href="${css(prod ? 'product' : 'home')}">
<script type="module" src="${PURL}assets/dist/${man.js.engine.file}" id="delicat-engine-js"></script></head>
<body class="dbp-route-${prod ? 'product' : 'home'}${member ? ' logged-in' : ''}"><header class="dsb8-header" style="height:56px">Delicat</header><main data-delicat-native-document>${body}</main></body></html>`;
}
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ ...devices['Pixel 7'], reducedMotion: 'reduce' });
  const net = NET === '3g' ? { effectiveType: '3g', downlink: 0.9, rtt: 450 } : { effectiveType: '4g', downlink: 8, rtt: 80 };
  await ctx.addInitScript((n) => { Object.defineProperty(navigator, 'connection', { get: () => Object.assign({ saveData: false, addEventListener() {} }, n) }); Object.defineProperty(navigator, 'deviceMemory', { get: () => 4 }); }, net);
  const fetches = {};
  const TTFB = NET === '3g' ? 1200 : 700;
  await ctx.route('**/*', async (route) => {
    const req = route.request(), u = new URL(req.url());
    if (u.origin !== BASE) return route.abort();
    if (u.pathname.startsWith('/wp-content/plugins/delicat-builder-v9/')) { const f = path.join(PLUGIN, u.pathname.replace('/wp-content/plugins/delicat-builder-v9/', '')); return fs.existsSync(f) ? route.fulfill({ body: fs.readFileSync(f), contentType: f.endsWith('.js') ? 'text/javascript' : 'text/css' }) : route.fulfill({ status: 404, body: '' }); }
    if (u.pathname !== '/' && !u.pathname.startsWith('/produit/')) return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    (fetches[u.pathname] = fetches[u.pathname] || []).push(req.resourceType() + (req.headers()['x-delicat-prefetch'] ? ':pf' : ''));
    if (u.pathname !== '/') await new Promise((r) => setTimeout(r, TTFB));
    return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', headers: { 'cache-control': member ? 'private, no-cache' : 'public, max-age=0' }, body: page(u.pathname) });
  });
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', (e) => { if (!/ViewTransition/.test(e.message)) errs.push(e.message); });
  async function tapCard(i) {
    const sel = `a.delicat-product-card__name[href$="/carte-${i}/"]`;
    const el = await p.$(sel); await el.scrollIntoViewIfNeeded(); await p.waitForTimeout(150);
    const bx = await el.boundingBox();
    await p.evaluate((name) => { window.__t0 = performance.now(); window.__shell = 0; window.__full = 0; const chk = () => { const m = document.querySelector('main'); const t = m ? m.textContent : ''; if (!window.__shell && t.indexOf(name) !== -1 && !/Accueil/.test((document.getElementById('t') || {}).textContent || '')) window.__shell = performance.now() - window.__t0; const h = document.getElementById('t'); if (!window.__full && h && h.textContent === name) { window.__full = performance.now() - window.__t0; } if (!window.__full) requestAnimationFrame(chk); }; requestAnimationFrame(chk); }, names[i - 1]);
    await p.touchscreen.tap(bx.x + bx.width / 2, bx.y + bx.height / 2);
    await p.waitForFunction(() => window.__full > 0, null, { timeout: 15000 });
    const r = await p.evaluate(() => ({ shell: Math.round(window.__shell), full: Math.round(window.__full) }));
    await p.goBack(); await p.waitForFunction(() => (document.getElementById('t') || {}).textContent === 'Accueil', null, { timeout: 15000 });
    return r;
  }
  await p.goto(BASE + '/'); await p.waitForTimeout(5000);           // shopper looks at the first rail
  await p.evaluate(() => window.scrollTo(0, 650)); await p.waitForTimeout(2500); // scrolls to the second rail and looks
  const R = { prefetchedBeforeTaps: Object.keys(fetches).filter((k) => k !== '/').map((k) => k.replace('/produit/', '') + '=' + fetches[k].join('+')).join(' ') };
  R.card1 = await tapCard(1);
  R.card9 = await tapCard(9);
  R.card6 = await tapCard(6);   // first rail, off-screen to the right: never looked at
  R.card14 = await tapCard(14); // second rail, off-screen to the right
  R.errors = errs;
  console.log(JSON.stringify({ mode: MODE, net: NET, ...R }));
  await b.close();
})().catch((e) => { console.error('HARNESS FAIL', e.message); process.exit(1); });
