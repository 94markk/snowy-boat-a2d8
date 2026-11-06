// node nav-real.cjs <port> <plugin-dir> — real account pages (Identity + order module) with the 9.5.1 engine injected.
const { chromium, devices } = require('playwright');
const fs = require('fs'), path = require('path');
const [port, pluginDir] = process.argv.slice(2);
const BASE = `http://localhost:${port}`, PURL = BASE + '/wp-content/plugins/delicat-builder-v9/';
const man = JSON.parse(fs.readFileSync(path.join(pluginDir, 'assets/dist/manifest.json'), 'utf8'));
const chunks = {}; for (const [f, m] of Object.entries(man.chunks)) if (m.name) chunks[m.name] = PURL + 'assets/dist/' + f;
const hard = ['/cart/', '/checkout/', '/my-account/', '/order-pay/', '/order-received/', '/customer-logout/', '/lost-password/', '/my-wallet/'];
const nav = { enabled: true, products: true, prefetch: true, transitions: true, budget: 8, cacheTtl: 120000, cacheMax: 24, generation: '', home: BASE + '/', hardPaths: hard, productPaths: ['/product/'], account: { base: '/my-account/', paths: ['/my-account/orders/', '/my-account/view-order/'] } };
const cfg = { version: 'x', debug: true, ajaxUrl: BASE + '/wp-admin/admin-ajax.php', homeUrl: BASE + '/', route: 'page', nav, session: { url: BASE + '/?wc-ajax=delicat_session', resetUrl: BASE + '/' }, pullRefresh: false, reviews: {}, globals: [], chunks };
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ ...devices['Pixel 7'], reducedMotion: 'reduce' });
  const log = [];
  await ctx.route(/\/my-account\//, async (route) => {
    const req = route.request();
    if (req.resourceType() !== 'document' && req.resourceType() !== 'fetch') return route.continue();
    const res = await route.fetch();
    log.push(new URL(req.url()).pathname + ' ' + req.resourceType() + ' ' + res.status() + (req.headers()['x-delicat-nav'] ? ' nav' : ''));
    const ct = res.headers()['content-type'] || '';
    if (!/text\/html/.test(ct) || process.env.MODE === 'noengine') return route.fulfill({ response: res });
    let html = await res.text();
    html = html.replace('</head>', `<script id="delicat-engine-js-before">window.DelicatEngine=${JSON.stringify(cfg)};</script><script type="module" src="${PURL}assets/dist/${man.js.engine.file}" id="delicat-engine-js"></script></head>`)
      .replace(/<body class="/, '<body class="woocommerce-account woocommerce-page ');
    return route.fulfill({ response: res, body: html });
  });
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', (e) => { if (!/Unexpected token '<'|wp is not defined/.test(e.message)) errs.push(e.message); });
  p.on('console', (m) => { if (/fell back/.test(m.text())) log.push('engine: ' + m.text().slice(30, 120)); });
  await p.goto(BASE + '/wp-login.php');
  await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  const mark = () => p.evaluate(() => { window.__m = 1; });
  const kept = () => p.evaluate(() => window.__m === 1);
  async function until(name, fn, ms) { const end = Date.now() + (ms || 12000); while (Date.now() < end) { try { if (await fn()) return; } catch (e) {} await p.waitForTimeout(60); } throw new Error('timeout ' + name + ' ' + p.url()); }
  const h1 = () => p.evaluate(() => (document.querySelector('.dipapp-topbar h1') || {}).textContent || '');
  async function tapIn(sel) {
    const els = await p.$$(sel); let el = null;
    for (const e of els) { if (await e.isVisible()) { el = e; break; } }
    if (!el) throw new Error('missing visible ' + sel);
    await el.scrollIntoViewIfNeeded(); const bx = await el.boundingBox(); await p.touchscreen.tap(bx.x + bx.width / 2, bx.y + bx.height / 2);
  }
  const R = {};
  const MODE = process.env.MODE || 'engine';
  await p.goto(BASE + '/my-account/orders/', { waitUntil: 'networkidle' });
  if (MODE === 'pushfirst') { await p.evaluate(() => history.pushState({ delicat: 1 }, '', location.href)); }
  R.originA = await p.evaluate(() => performance.timeOrigin);
  if (MODE === 'native') { await p.evaluate(() => { location.assign('/my-account/view-order/22447/'); }); }
  else { await tapIn('a[href$="/view-order/22447/"]'); }
  await until('o2', async () => new URL(p.url()).pathname === '/my-account/view-order/22447/' && !!(await p.$('#dlc-vo[data-vo-bound]')));
  R.originB = await p.evaluate(() => performance.timeOrigin);
  R.entries = await p.evaluate(() => navigation.entries().map((e) => new URL(e.url).pathname + (e.sameDocument ? '[same]' : '')));
  await p.evaluate(() => { window.__docB = 1; });
  await p.goBack({ waitUntil: 'load' }).catch(() => {});
  await p.waitForTimeout(2500);
  R.after = await p.evaluate(() => ({ u: location.pathname, docB: window.__docB === 1, h1: (document.querySelector('.dipapp-topbar h1') || {}).textContent }));
  R.log = log; R.errors = errs;
  console.log(JSON.stringify(R, null, 1));
  await b.close();
})().catch(async (e) => { console.error('HARNESS FAIL', e.message); console.log(JSON.stringify({ R: global.__R, log: global.__log })); try { console.log('backs:', await global.__p.evaluate(() => [...document.querySelectorAll('.dipapp-back')].map((a) => a.href + ' vis=' + !!a.offsetParent).join(' | '))); } catch (x) {} process.exit(1); });
