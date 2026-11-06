// node vo-shots.cjs <port> <outdir> <ids csv> [widths csv] [schemes csv]
const { chromium } = require('playwright');
(async () => {
  const port = process.argv[2], out = process.argv[3], ids = process.argv[4].split(',');
  const widths = (process.argv[5] || '390').split(',').map(Number), schemes = (process.argv[6] || 'light').split(',');
  const b = await chromium.launch();
  for (const scheme of schemes) for (const w of widths) {
    const ctx = await b.newContext({ viewport: { width: w, height: 860 }, colorScheme: scheme, deviceScaleFactor: 2, reducedMotion: 'reduce' });
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', (e) => errs.push(e.message));
    p.on('console', (m) => { if (m.type() === 'error') errs.push('console:' + m.text()); });
    await p.goto(`http://localhost:${port}/wp-login.php`);
    await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    for (const id of ids) {
      const path = /^\d+$/.test(id) ? `/my-account/view-order/${id}/` : id;
      const resp = await p.goto(`http://localhost:${port}${path}`, { waitUntil: 'networkidle' });
      const info = await p.evaluate(() => {
        const r = document.getElementById('dlc-vo');
        return { status: 0, vo: !!r, phase: r && r.getAttribute('data-phase'), overflow: document.documentElement.scrollWidth > innerWidth,
          steps: [...document.querySelectorAll('.dlc-vo-step')].map((s) => s.getAttribute('data-state')[0]).join(''),
          title: (document.querySelector('.dipapp-topbar h1') || {}).textContent, hero: (document.querySelector('.dlc-vo-hero__title') || {}).textContent && document.querySelector('.dlc-vo-hero__title').textContent.trim(),
          copy: document.querySelectorAll('.dlc-vo-copy').length, navFull: document.querySelectorAll('[data-delicat-nav-full]').length,
          wcTable: !!document.querySelector('.woocommerce-order-details'), live: !!document.querySelector('[data-vo="live"]:not([hidden])'),
          denied: (document.querySelector('.woocommerce-error,.dlc-vo-denied') || {}).textContent || '' };
      });
      info.status = resp.status();
      await p.screenshot({ path: `${out}/vo-${id.replace(/[^a-z0-9]+/gi, '_')}-${w}-${scheme}.png`, fullPage: true });
      console.log(scheme, w, id, JSON.stringify(info), errs.splice(0).join('|').slice(0, 300));
    }
    await ctx.close();
  }
  await b.close();
})();
