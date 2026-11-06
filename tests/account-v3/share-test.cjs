// node share-test.cjs <port> <plugin-dir> <shots-dir> — « Partager le reçu » on the order details page.
const { chromium, devices } = require('playwright');
const fs = require('fs'), path = require('path');
const [port, pluginDir, shots] = process.argv.slice(2);
const BASE = `http://localhost:${port}`, PURL = BASE + '/wp-content/plugins/delicat-builder-v9/';
const man = JSON.parse(fs.readFileSync(path.join(pluginDir, 'assets/dist/manifest.json'), 'utf8'));
const chunks = {}; for (const [f, m] of Object.entries(man.chunks)) if (m.name) chunks[m.name] = PURL + 'assets/dist/' + f;
const nav = { enabled: true, products: true, prefetch: true, transitions: true, budget: 8, cacheTtl: 120000, cacheMax: 24, generation: '', home: BASE + '/', hardPaths: ['/cart/', '/checkout/', '/my-account/', '/my-wallet/'], productPaths: ['/product/'], account: { base: '/my-account/', paths: ['/my-account/orders/', '/my-account/view-order/'] } };
const cfg = { version: 'x', debug: false, ajaxUrl: BASE + '/wp-admin/admin-ajax.php', homeUrl: BASE + '/', route: 'page', nav, session: { url: BASE + '/?wc-ajax=delicat_session', resetUrl: BASE + '/' }, pullRefresh: false, reviews: {}, globals: [], chunks };
(async () => {
  const b = await chromium.launch();
  const R = {};
  for (const scheme of ['light', 'dark']) {
    const ctx = await b.newContext({ ...devices['Pixel 7'], colorScheme: scheme, reducedMotion: 'reduce', acceptDownloads: true, permissions: ['clipboard-read', 'clipboard-write'] });
    await ctx.route(/\/my-account\//, async (route) => {
      const req = route.request();
      if (req.resourceType() !== 'document' && req.resourceType() !== 'fetch') return route.continue();
      const res = await route.fetch(); const ct = res.headers()['content-type'] || '';
      if (!/text\/html/.test(ct)) return route.fulfill({ response: res });
      const html = (await res.text()).replace('</head>', `<script id="delicat-engine-js-before">window.DelicatEngine=${JSON.stringify(cfg)};</script><script type="module" src="${PURL}assets/dist/${man.js.engine.file}" id="delicat-engine-js"></script></head>`).replace(/<body class="/, '<body class="woocommerce-account woocommerce-page ');
      return route.fulfill({ response: res, body: html });
    });
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', (e) => { if (!/Unexpected token '<'|wp is not defined/.test(e.message)) errs.push(e.message); });
    await p.goto(BASE + '/wp-login.php'); await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    const until = async (name, fn, ms) => { const end = Date.now() + (ms || 15000); while (Date.now() < end) { try { if (await fn()) return; } catch (e) {} await p.waitForTimeout(80); } throw new Error('timeout ' + name); };
    const tap = async (sel) => { const els = await p.$$(sel); for (const e of els) { if (await e.isVisible()) { await e.scrollIntoViewIfNeeded(); const bx = await e.boundingBox(); await p.touchscreen.tap(bx.x + bx.width / 2, bx.y + bx.height / 2); return; } } throw new Error('no visible ' + sel); };
    const painted = () => p.evaluate(() => { const c = document.querySelector('#dlc-oc-sheet canvas'); if (!c || c.width < 400) return false; const x = c.getContext('2d').getImageData(0, 0, c.width, Math.min(c.height, 600)).data; let n = 0; for (let i = 0; i < x.length; i += 400) if (x[i] < 200) n++; return n > 20; });
    async function pngName() { const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 15000 }), tap('#dlc-oc-sheet [data-oc-act="png"]')]); return dl.suggestedFilename(); }
    const S = R[scheme] = {};
    // 1. Delivered order with codes (full page)
    await p.goto(BASE + '/my-account/view-order/22447/', { waitUntil: 'networkidle' });
    await until('bound', async () => !!(await p.$('#dlc-vo[data-vo-bound]')));
    S.shareButtons = await p.$$eval('[data-vo-share]', (n) => n.length);
    await tap('.dlc-vo-actions [data-vo-share]');
    await until('open', async () => p.evaluate(() => document.getElementById('dlc-oc-sheet').open));
    await until('painted', painted);
    S.painted = true;
    await p.waitForTimeout(300);
    if (shots) await p.screenshot({ path: `${shots}/share-sheet-${scheme}.png` });
    if (scheme === 'light') {
      S.png = await pngName();
      await tap('#dlc-oc-sheet [data-oc-act="link"]'); await p.waitForTimeout(400);
      S.clipboard = await p.evaluate(() => navigator.clipboard.readText()).catch((e) => 'err ' + e.message);
      S.toast = await p.evaluate(() => (document.querySelector('.dlc-vo-toast') || {}).textContent);
      await p.evaluate(() => { window.__printed = ''; window.print = () => { const box = document.body.querySelector(':scope > .dlc-vo-print'); window.__printed = box ? (box.textContent.indexOf('X7KQ') === -1 ? 'receipt-without-codes' : 'LEAK') : 'none'; }; });
      await tap('#dlc-oc-sheet [data-oc-act="print"]'); await p.waitForTimeout(800);
      S.print = await p.evaluate(() => window.__printed);
      S.sheetClosedOnPrint = await p.evaluate(() => !document.getElementById('dlc-oc-sheet').open);
      await p.emulateMedia({ media: 'print' });
      if (shots) await p.screenshot({ path: `${shots}/share-print.png`, fullPage: true });
      S.printVisible = await p.evaluate(() => [...document.body.children].filter((e) => getComputedStyle(e).display !== 'none').map((e) => e.className).join(','));
      await p.emulateMedia({ media: 'screen' });
      await p.evaluate(() => window.dispatchEvent(new Event('afterprint')));
      S.printRemoved = await p.evaluate(() => !document.body.querySelector(':scope > .dlc-vo-print') && !document.body.classList.contains('dlc-vo-printing'));
      // 2. Unpaid order: no share
      await p.goto(BASE + '/my-account/view-order/22410/', { waitUntil: 'networkidle' });
      S.unpaidShare = await p.$$eval('[data-vo-share], #dlc-oc-receipt', (n) => n.length);
      // 3. In-place switch between two paid orders: the receipt follows the order
      await p.goto(BASE + '/my-account/orders/', { waitUntil: 'networkidle' });
      await p.evaluate(() => { window.__m = 1; });
      await tap('a[href$="/view-order/22431/"]');
      await until('o1', async () => !!(await p.$('#dlc-vo[data-vo-bound]')) && new URL(p.url()).pathname.endsWith('/22431/'));
      S.soft1 = await p.evaluate(() => window.__m === 1);
      await tap('.dlc-vo-sum [data-vo-share]');
      await until('open1', async () => p.evaluate(() => document.getElementById('dlc-oc-sheet').open)); await until('painted1', painted);
      S.png1 = await pngName();
      await p.keyboard.press('Escape'); await p.waitForTimeout(400);
      S.closedByEsc = await p.evaluate(() => !document.getElementById('dlc-oc-sheet').open);
      await tap('.dipapp-back');
      await until('orders', async () => new URL(p.url()).pathname === '/my-account/orders/' && !(await p.$('#dlc-vo')) && !!(await p.$('a[href$="/view-order/22420/"]')));
      await tap('a[href$="/view-order/22420/"]');
      await until('o2', async () => !!(await p.$('#dlc-vo[data-vo-bound]')) && new URL(p.url()).pathname.endsWith('/22420/'));
      S.soft2 = await p.evaluate(() => window.__m === 1);
      await tap('.dlc-vo-actions [data-vo-share]');
      await until('open2', async () => p.evaluate(() => document.getElementById('dlc-oc-sheet').open)); await until('painted2', painted);
      S.png2 = await pngName();
    }
    S.errors = errs;
    await ctx.close();
  }
  console.log(JSON.stringify(R, null, 1));
  await b.close();
})().catch((e) => { console.error('HARNESS FAIL', e.message); process.exit(1); });
