// node acct-shots.cjs <port> <prefix> [paths csv]
const { chromium } = require('playwright');
(async () => {
  const port = process.argv[2], prefix = process.argv[3];
  const paths = (process.argv[4] || '/my-account/').split(',');
  const b = await chromium.launch();
  for (const scheme of ['light', 'dark']) for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 900 }, colorScheme: scheme, deviceScaleFactor: 1 });
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', (e) => errs.push(e.message));
    await p.goto(`http://localhost:${port}/wp-login.php`);
    await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    for (const path of paths) {
      await p.goto(`http://localhost:${port}${path}`, { waitUntil: 'networkidle' });
      const name = `${prefix}-${path.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home'}-${w}-${scheme}.png`;
      const info = await p.evaluate(() => ({ overflow: document.documentElement.scrollWidth > innerWidth, chips: document.querySelectorAll('.dipapp-status').length, favs: document.querySelectorAll('.dipapp-fav').length, orders: document.querySelectorAll('.dipapp-order').length, stats: document.querySelectorAll('.dipapp-stat').length, cc: document.querySelector('meta') && '' }));
      await p.screenshot({ path: name, fullPage: true });
      console.log(scheme, w, path, JSON.stringify(info), errs.join('|').slice(0, 200));
    }
    await ctx.close();
  }
  await b.close();
})();
