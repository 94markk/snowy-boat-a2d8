const { chromium, devices } = require('playwright');
(async () => {
  const [port, out] = process.argv.slice(2);
  const b = await chromium.launch();
  for (const [name, opts] of [['dark-390', { ...devices['Pixel 7'], colorScheme: 'dark' }], ['light-1280', { viewport: { width: 1280, height: 900 }, colorScheme: 'light' }]]) {
    const ctx = await b.newContext({ ...opts, reducedMotion: 'reduce' }); const p = await ctx.newPage();
    await p.goto(`http://localhost:${port}/wp-login.php`); await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    await p.goto(`http://localhost:${port}/my-account/view-order/22431/`, { waitUntil: 'networkidle' });
    await p.click('.dlc-vo-actions [data-vo-share]');
    await p.waitForFunction(() => { const c = document.querySelector('#dlc-oc-sheet canvas'); return c && c.width > 400; });
    await p.waitForTimeout(500);
    await p.screenshot({ path: `${out}/share-sheet-${name}.png` });
    console.log(name, await p.evaluate(() => getComputedStyle(document.querySelector('.dlc-vo-sheet__panel')).backgroundColor));
    await ctx.close();
  }
  await b.close();
})();
