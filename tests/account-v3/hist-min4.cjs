const { chromium } = require('playwright');
const V = {
  replace: () => { history.pushState({ d: 1 }, '', '/y/'); location.replace('/y/'); },
  assign: () => { history.pushState({ d: 1 }, '', '/y/'); location.assign('/y/'); },
  restoreThenAssign: () => { history.pushState({ d: 1 }, '', '/y/'); history.replaceState({ d: 0 }, '', '/orders/'); location.assign('/y/'); },
  backThenAssign: () => { history.pushState({ d: 1 }, '', '/y/'); addEventListener('popstate', () => location.assign('/y/'), { once: true }); history.back(); },
  hrefReplaceNoPush: () => { location.replace('/y/'); },
};
(async () => {
  for (const [name, fn] of Object.entries(V)) {
    const b = await chromium.launch(); const ctx = await b.newContext(); const p = await ctx.newPage();
    await ctx.route('**/*', (route) => { const u = new URL(route.request().url()); return route.fulfill({ contentType: 'text/html', headers: { 'cache-control': 'no-store' }, body: `<!doctype html><body>${u.pathname}<script>window.__doc='${u.pathname}'</script></body>` }); });
    await p.goto('https://t.test/start/'); await p.goto('https://t.test/orders/');
    await p.evaluate(fn); await p.waitForTimeout(900);
    const entries = await p.evaluate(() => navigation.entries().map((e) => new URL(e.url).pathname + (e.sameDocument ? '*' : '')).join(' '));
    await p.goBack().catch(() => {}); await p.waitForTimeout(900);
    const a1 = new URL(p.url()).pathname + ' doc=' + await p.evaluate(() => window.__doc);
    console.log(name.padEnd(18), '|', entries, '| back ->', a1);
    await b.close();
  }
})();
