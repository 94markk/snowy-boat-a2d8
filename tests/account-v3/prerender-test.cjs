// node prerender-test.cjs <plugin-dir> — wallet prerendered on touch, engine on the activated page
const { chromium, devices } = require('playwright');
const fs = require('fs'), path = require('path');
const PLUGIN = path.resolve(process.argv[2]);
const man = JSON.parse(fs.readFileSync(PLUGIN + '/assets/dist/manifest.json', 'utf8'));
const BASE = 'https://shop.test', PURL = BASE + '/wp-content/plugins/delicat-builder-v9/';
const chunks = {}; for (const [f, m] of Object.entries(man.chunks)) if (m.name) chunks[m.name] = PURL + 'assets/dist/' + f;
const nav = { enabled: true, products: true, prefetch: true, transitions: true, budget: 8, cacheTtl: 120000, cacheMax: 24, generation: 'g1', home: BASE + '/', hardPaths: ['/my-account/', '/my-wallet/'], productPaths: ['/produit/'] };
const rules = { prerender: [{ source: 'document', where: { and: [{ href_matches: ['/my-account/', '/my-wallet/'] }, { not: { selector_matches: 'a[href*="?"]' } }] }, eagerness: 'moderate' }] };
function page(p) {
  const cfg = { version: 'x', debug: true, ajaxUrl: BASE + '/a', homeUrl: BASE + '/', route: 'page', nav, session: { url: BASE + '/?wc-ajax=delicat_session', resetUrl: BASE + '/' }, pullRefresh: false, reviews: {}, globals: [], chunks };
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>${p}</title><meta name="delicat-generation" content="g1"><script id="delicat-engine-js-before">window.DelicatEngine=${JSON.stringify(cfg)};</script>
<script type="module" src="${PURL}assets/dist/${man.js.engine.file}" id="delicat-engine-js"></script>${p === '/' ? `<script type="speculationrules">${JSON.stringify(rules)}</script>` : ''}</head>
<body class="logged-in${p !== '/' ? ' woocommerce-account' : ''}"><main data-delicat-native-document><h1 id="t">${p === '/' ? 'Accueil' : 'Wallet'}</h1><a id="w" href="${BASE}/my-wallet/" style="display:block;padding:30px">Wallet</a>
<script>window.__wasPrerendered = document.prerendering === true; document.addEventListener('prerenderingchange', function(){ window.__activated = true; });</script></main></body></html>`;
}
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ ...devices['Pixel 7'] });
  const hits = [];
  await ctx.route('**/*', async (route) => {
    const req = route.request(), u = new URL(req.url());
    if (u.origin !== BASE) return route.abort();
    if (u.pathname.startsWith('/wp-content/plugins/delicat-builder-v9/')) { const f = path.join(PLUGIN, u.pathname.replace('/wp-content/plugins/delicat-builder-v9/', '')); return route.fulfill({ body: fs.readFileSync(f), contentType: f.endsWith('.js') ? 'text/javascript' : 'text/css' }); }
    if (u.pathname !== '/' && u.pathname !== '/my-wallet/') return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    hits.push(u.pathname + ' ' + (req.headers()['sec-purpose'] || 'nav'));
    if (u.pathname === '/my-wallet/') await new Promise((r) => setTimeout(r, 600));
    return route.fulfill({ status: 200, contentType: 'text/html', headers: { 'cache-control': 'no-store, private' }, body: page(u.pathname) });
  });
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => { if (!/ViewTransition/.test(e.message)) errs.push(e.message); });
  await p.goto(BASE + '/'); await p.waitForTimeout(800);
  const el = await p.$('#w'); const bx = await el.boundingBox();
  await p.mouse.move(bx.x + 10, bx.y + 10); await p.waitForTimeout(1500); // pointer rests on the link: moderate prerender starts
  const t0 = Date.now();
  await p.mouse.down(); await p.mouse.up();
  await p.waitForFunction(() => (document.getElementById('t') || {}).textContent === 'Wallet', null, { timeout: 10000 });
  const ms = Date.now() - t0;
  await p.waitForTimeout(1500);
  const info = await p.evaluate(() => ({ wasPrerendered: window.__wasPrerendered, activationStart: Math.round((performance.getEntriesByType('navigation')[0] || {}).activationStart || 0), engine: document.documentElement.classList.contains('delicat-engine') }));
  console.log(JSON.stringify({ hits, tapToWalletMs: ms, ...info, errors: errs }));
  await b.close();
})().catch((e) => { console.error('HARNESS FAIL', e.message); process.exit(1); });
