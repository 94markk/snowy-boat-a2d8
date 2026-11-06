const { chromium } = require('playwright'); const fs = require('fs');
(async () => {
  const html = fs.readFileSync(process.argv[2], 'utf8'); const b = await chromium.launch();
  for (const w of [390, 1280]) {
    const p = await (await b.newContext({ viewport: { width: w, height: 900 }, hasTouch: w < 800 })).newPage();
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('**/*', r => r.request().url().startsWith('http://harness/') ? r.fulfill({ body: html, contentType: 'text/html' }) : r.abort());
    await p.goto('http://harness/');
    const before = await p.$$eval('[data-dbv9-ft-group]', g => g.map(x => x.open));
    const s = await p.$('.dbv9-ft__summary');
    if (w < 800) await s.tap(); else await s.click();
    await p.waitForTimeout(100);
    const after = await p.$$eval('[data-dbv9-ft-group]', g => g.map(x => x.open));
    const vis = await p.$eval('.dbv9-ft__links', el => el.getBoundingClientRect().height);
    const legalPad = await p.$eval('.dbv9-ft__legal li:first-child a', el => getComputedStyle(el).paddingLeft);
    console.log(w, 'before', JSON.stringify(before), 'after first tap/click', JSON.stringify(after), 'first list height', vis, 'legal pad', legalPad, errs.join('|'));
    if (w < 800) await p.screenshot({ path: process.argv[3], fullPage: true });
  }
  await b.close();
})();
