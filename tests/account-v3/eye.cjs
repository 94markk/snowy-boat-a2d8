const { chromium, devices } = require('playwright');
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ ...devices['Pixel 7'] }); const p = await ctx.newPage();
  await p.goto('http://localhost:8960/wp-login.php'); await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  await p.goto('http://localhost:8960/my-account/', { waitUntil: 'networkidle' });
  const s0 = await p.evaluate(() => ({ amountHidden: document.querySelector('[data-dipma-balance-amount]').hidden, maskHidden: document.querySelector('[data-dipma-balance-mask]').hidden }));
  await p.tap('.dipapp-eye'); await p.waitForTimeout(200);
  const s1 = await p.evaluate(() => ({ url: location.pathname, amountHidden: document.querySelector('[data-dipma-balance-amount]').hidden, maskHidden: document.querySelector('[data-dipma-balance-mask]').hidden, cookie: document.cookie.indexOf('dipma_balance_hidden=1') > -1 }));
  await p.reload({ waitUntil: 'networkidle' });
  const s2 = await p.evaluate(() => ({ amountHidden: document.querySelector('[data-dipma-balance-amount]').hidden, serverHidden: document.querySelector('.dipapp-stat--wallet').classList.contains('is-hidden') }));
  await p.tap('.dipapp-stat--wallet .dipapp-stat-link'); await p.waitForTimeout(800);
  console.log(JSON.stringify({ s0, s1, s2, afterCardTap: new URL(p.url()).pathname }));
  await b.close();
})();
