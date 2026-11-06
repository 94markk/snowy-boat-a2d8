const { chromium, devices } = require('playwright');
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext({ ...devices['Pixel 7'] })).newPage();
  await p.goto('http://localhost:8960/wp-login.php'); await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  await p.goto('http://localhost:8960/my-account/', { waitUntil: 'networkidle' });
  console.log(await p.evaluate(() => [...document.querySelectorAll('.dipapp-stat')].map((s) => s.className + ' | ' + s.innerText.replace(/\s+/g, ' ').trim()).join('\n')));
  const el = await p.$('.dipapp-summary'); await el.screenshot({ path: 'rewards-cards.png' });
  await b.close();
})();
