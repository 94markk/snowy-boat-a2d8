const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext()).newPage();
  await p.goto('http://localhost:8960/wp-login.php'); await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  for (const path of ['/my-account/', '/my-account/orders/', '/my-account/view-order/22447/', '/my-account/edit-account/', '/my-account/woo-wallet/', '/my-wallet/']) {
    await p.goto('http://localhost:8960' + path, { waitUntil: 'domcontentloaded' });
    const r = await p.evaluate(() => ({ formCss: !!document.querySelector('link[href*="delicat-recharge/assets/css/form.css"]'), formJs: !!document.querySelector('script[src*="delicat-recharge/assets/js/form.js"]'), glass: !!document.getElementById('delicat-wallet-glass-override-v503') }));
    console.log(path.padEnd(32), JSON.stringify(r));
  }
  await b.close();
})();
