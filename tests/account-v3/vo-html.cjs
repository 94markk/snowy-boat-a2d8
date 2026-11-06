const { chromium } = require('playwright');
(async () => {
  const port = process.argv[2], path = process.argv[3];
  const b = await chromium.launch(); const ctx = await b.newContext(); const p = await ctx.newPage();
  await p.goto(`http://localhost:${port}/wp-login.php`);
  await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  const r = await ctx.request.get(`http://localhost:${port}${path}`);
  process.stdout.write(await r.text());
  await b.close();
})();
