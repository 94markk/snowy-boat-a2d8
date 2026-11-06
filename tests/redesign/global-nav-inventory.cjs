// node global-nav-inventory.cjs <base> — what the global navigation actually is in the browser: header/drawer/bottom-bar/
// footer/search elements, dialogs, scripts and styles loaded, per page, guest, Pixel 7.
const { chromium, devices } = require('playwright');
const base = process.argv[2];
const pages = ['/', '/shop/', '/product/free-fire/', '/cart/', '/my-account/'];
(async () => {
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const ctx = await b.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true });
  const p = await ctx.newPage();
  for (const path of pages) {
    const reqs = [];
    p.on('request', (r) => { if (['script', 'stylesheet'].includes(r.resourceType())) reqs.push(r.url()); });
    await p.goto(base + path, { waitUntil: 'networkidle' });
    const info = await p.evaluate(() => {
      const count = (s) => document.querySelectorAll(s).length;
      const vis = (s) => [...document.querySelectorAll(s)].filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; }).length;
      return {
        headers: { 'header (all)': count('header'), '.dsb8-header': count('.dsb8-header'), 'visible headers': vis('header'), 'theme header (.wp-block-template-part header, #masthead)': count('header.wp-block-template-part, #masthead, .site-header') },
        drawers: { '[class*=drawer]': count('[class*="drawer"]'), '.dlx-drawer, .dsb-drawer, #dlx-drawer': count('.dlx-drawer, .dsb-drawer, #dlx-drawer'), 'dsb-menu (modern menu)': count('[class*="dsb-menu"]') },
        bottom: { '[class*=bottom-nav]': count('[class*="bottom-nav"], [class*="bottomnav"]'), visible: vis('[class*="bottom-nav"], [class*="bottomnav"]') },
        footers: { 'footer (all)': count('footer'), visible: vis('footer') },
        search: { 'input[type=search]': count('input[type=search]'), '[class*=search-overlay],[class*=search-sheet]': count('[class*="search-overlay"], [class*="search-sheet"], [class*="search-panel"]') },
        dialogs: { 'dialog': count('dialog'), '[role=dialog]': count('[role="dialog"]'), '[aria-modal=true]': count('[aria-modal="true"]') },
        nodes: document.getElementsByTagName('*').length,
      };
    });
    const scripts = reqs.filter((u) => /\.js/.test(u)).map((u) => u.replace(base, '').replace(/\?.*$/, ''));
    console.log('==', path, JSON.stringify(info));
    console.log('   scripts:', scripts.filter((s) => /delicat|dip|dbp|nav|prefetch|shell/i.test(s)).join(' '));
    p.removeAllListeners('request');
  }
  await b.close();
})();
