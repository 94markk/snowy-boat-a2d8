// node vo-el.cjs <port> <outdir> <ids csv> <width> <scheme>  — screenshots of the order section only
const { chromium } = require('playwright');
(async () => {
  const [port, out, idsCsv, width, scheme] = process.argv.slice(2);
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: +width, height: 900 }, colorScheme: scheme, deviceScaleFactor: 2, reducedMotion: 'reduce' });
  const p = await ctx.newPage();
  await p.goto(`http://localhost:${port}/wp-login.php`);
  await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  for (const id of idsCsv.split(',')) {
    await p.goto(`http://localhost:${port}/my-account/view-order/${id}/`, { waitUntil: 'networkidle' });
    await p.addStyleTag({ content: '#wpadminbar,header.wp-block-template-part,.wp-block-post-title{display:none!important}' });
    const el = await p.$('.woocommerce-MyAccount-content') || await p.$('#dlc-vo');
    await el.screenshot({ path: `${out}/el-${id}-${width}-${scheme}.png` });
  }
  await b.close();
})();
