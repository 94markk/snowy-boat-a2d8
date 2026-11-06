// node shoot.cjs <html> <outprefix>
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const html = fs.readFileSync(process.argv[2], 'utf8');
  const out = process.argv[3];
  const b = await chromium.launch();
  for (const [w, h] of [[390, 844], [1280, 900], [320, 640]]) {
    for (const dark of [false, true]) {
      const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, colorScheme: dark ? 'dark' : 'light' });
      const p = await ctx.newPage();
      const errs = []; p.on('pageerror', e => errs.push(e.message));
      await p.route('**/*', r => r.request().url().startsWith('http://harness/') ? r.fulfill({ body: html, contentType: 'text/html' }) : r.abort());
      await p.goto('http://harness/');
      if (dark) await p.evaluate(() => { document.documentElement.classList.add('dlc-theme-dark'); document.documentElement.setAttribute('data-delicat-theme', 'dark'); });
      await p.waitForTimeout(150);
      const f = await p.$('footer');
      const info = await p.evaluate(() => ({ overflow: document.documentElement.scrollWidth > innerWidth, sw: document.documentElement.scrollWidth, fh: (document.querySelector('footer') || {}).offsetHeight }));
      if (f) await f.screenshot({ path: `${out}-${w}-${dark ? 'dark' : 'light'}.png` });
      console.log(w, dark ? 'dark' : 'light', JSON.stringify(info), errs.join('|'));
      await ctx.close();
    }
  }
  await b.close();
})();
