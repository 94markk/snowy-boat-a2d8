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
    if (!/text\/html/.test(ct)) return route.fulfill({ response: res });
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
  await p.goto(BASE + '/my-account/', { waitUntil: 'networkidle' });
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
  const R = {}; global.__R = R; global.__log = log; global.__p = p;
  R.dashStats = await p.evaluate(() => document.querySelectorAll('.dipapp-stat').length);
  await mark(); await tapIn('a[href$="/my-account/orders/"]');
  await until('orders', async () => new URL(p.url()).pathname === '/my-account/orders/' && /ommande/.test(await h1()));
  await p.waitForTimeout(300); R.toOrders = (await kept()) ? 'soft' : 'full'; R.ordersH1 = await h1();
  R.ordersChips = await p.evaluate(() => document.querySelectorAll('.dipapp-status').length);
  await mark(); await tapIn('a[href$="/view-order/22431/"]');
  await until('o1', async () => new URL(p.url()).pathname === '/my-account/view-order/22431/' && !!(await p.$('#dlc-vo')));
  await p.waitForTimeout(400); R.toOrder = (await kept()) ? 'soft' : 'full';
  R.order = await p.evaluate(() => ({ h1: document.querySelector('.dipapp-topbar h1').textContent, hero: document.querySelector('.dlc-vo-hero__title').textContent.trim(), bound: !!document.querySelector('#dlc-vo[data-vo-bound]'), live: !document.querySelector('[data-vo="live"]').hidden, title: document.title, voCss: getComputedStyle(document.querySelector('.dlc-vo-card')).borderRadius }));
  await mark(); await tapIn('.dipapp-back');
  await until('back', async () => new URL(p.url()).pathname === '/my-account/orders/' && !(await p.$('#dlc-vo')));
  await p.waitForTimeout(300); R.backArrow = (await kept()) ? 'soft' : 'full';
  await mark(); await tapIn('a[href$="/view-order/22447/"]');
  await until('o2', async () => new URL(p.url()).pathname === '/my-account/view-order/22447/' && !!(await p.$('#dlc-vo')));
  await p.waitForTimeout(500); R.toCodesOrder = (await kept()) ? 'soft' : 'full';
  await until('vo-bound', async () => !!(await p.$('#dlc-vo[data-vo-bound]')));
  R.codesCopy = await p.evaluate(() => document.querySelectorAll('.dlc-vo-copy').length);
  await mark(); await p.goBack({ waitUntil: 'load' });
  await until('back2', async () => new URL(p.url()).pathname === '/my-account/orders/' && (await h1()) === 'Commandes' && await p.evaluate(() => document.documentElement.classList.contains('delicat-engine')));
  await p.waitForTimeout(400); R.backFromCodes = (await kept()) ? 'kept-doc' : 'reloaded';
  R.stateAfterBack = await p.evaluate(() => ({ leaving: document.documentElement.className, overlay: !!document.querySelector('.dph-leave'), placeholder: !!document.querySelector('[data-delicat-placeholder]'), navFull: !!document.querySelector('[data-delicat-nav-full]'), backHref: document.querySelector('.dipapp-back') && document.querySelector('.dipapp-back').href, elig: window.DelicatEngine && window.DelicatEngine.nav ? 'cfg' : 'nocfg' }));
  p.on('console', (m) => log.push('c: ' + m.text().slice(0, 140)));
  await mark(); await tapIn('.dipapp-back');
  await p.waitForTimeout(1500);
  R.afterTapUrl = p.url();
  R.stateAfterTap = await p.evaluate(() => ({ cls: document.documentElement.className, overlay: !!document.querySelector('.dph-leave') }));
  await until('dash', async () => new URL(p.url()).pathname === '/my-account/' && !!(await p.$('.dipapp-stat')));
  await p.waitForTimeout(400); R.toDash = (await kept()) ? 'soft' : 'full';
  // Identity's balance eye must still work after an in-place switch
  const before = await p.evaluate(() => (document.querySelector('[data-dipma-balance]') || {}).getAttribute && document.querySelector('[data-dipma-balance]').getAttribute('data-hidden'));
  await tapIn('[data-dipma-balance-toggle]'); await p.waitForTimeout(300);
  R.eyeToggled = await p.evaluate(() => /dipma_balance_hidden=1/.test(document.cookie));
  await tapIn('[data-dipma-balance-toggle]'); await p.waitForTimeout(200);
  R.log = log; R.errors = errs;
  console.log(JSON.stringify(R, null, 1));
  await b.close();
})().catch(async (e) => { console.error('HARNESS FAIL', e.message); console.log(JSON.stringify({ R: global.__R, log: global.__log })); try { console.log('backs:', await global.__p.evaluate(() => [...document.querySelectorAll('.dipapp-back')].map((a) => a.href + ' vis=' + !!a.offsetParent).join(' | '))); } catch (x) {} process.exit(1); });
