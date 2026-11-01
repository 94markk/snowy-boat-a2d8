// node shots.cjs <base-url> <out-dir> [label] — screenshots of the main storefront and account pages
// (mobile 390 light + dark, desktop 1280 light), guest and signed-in, plus console errors and
// horizontal-overflow checks. Used for the redesign before/after baseline on the staging site.
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');

const [base, out, label = 'run'] = process.argv.slice(2);
if (!base || !out) {
  console.error('usage: node shots.cjs <base-url> <out-dir> [label]');
  process.exit(2);
}
fs.mkdirSync(out, { recursive: true });

const GUEST_PAGES = ['/', '/shop/', '/product-category/jeux-recharges/', '/product/free-fire/', '/cart/', '/my-account/'];
const MEMBER_PAGES = ['/my-account/', '/my-account/orders/'];
const VIEWS = [
  { name: 'm390-light', device: devices['Pixel 7'], scheme: 'light' },
  { name: 'm390-dark', device: devices['Pixel 7'], scheme: 'dark' },
  { name: 'd1280-light', device: { viewport: { width: 1280, height: 860 } }, scheme: 'light' },
];

function slug(p) {
  return p.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home';
}

async function signIn(page) {
  await page.goto(base + '/wp-login.php');
  await page.fill('#user_login', 'client1');
  await page.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
}

async function capture(ctx, pages, who, view, report) {
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message.slice(0, 160)));
  page.on('console', (m) => {
    // Outside hosts (fonts, avatars) are not reachable from the sandbox: not a page defect.
    if (m.type() === 'error' && !/ERR_TUNNEL_CONNECTION_FAILED/.test(m.text())) errors.push(m.text().slice(0, 160));
  });
  if (who === 'member') await signIn(page);
  for (const p of pages) {
    errors.length = 0;
    const t0 = Date.now();
    const res = await page.goto(base + p, { waitUntil: 'load' });
    await page.waitForTimeout(600);
    const metrics = await page.evaluate(() => ({
      overflowX: document.documentElement.scrollWidth - window.innerWidth,
      height: document.documentElement.scrollHeight,
      domNodes: document.getElementsByTagName('*').length,
      scripts: document.scripts.length,
      styles: document.querySelectorAll('link[rel="stylesheet"], style').length,
      h1: Array.from(document.querySelectorAll('h1')).map((h) => h.textContent.trim().slice(0, 60)),
      theme: document.documentElement.getAttribute('data-theme') || document.body.className.match(/dlc-theme-\w+/)?.[0] || '',
    }));
    const file = `${label}-${who}-${view.name}-${slug(p)}.png`;
    await page.screenshot({ path: path.join(out, file), fullPage: false });
    report.push({ who, view: view.name, path: p, status: res ? res.status() : 0, ms: Date.now() - t0, ...metrics, errors: [...new Set(errors)].slice(0, 5), file });
  }
  await page.close();
}

(async () => {
  // The service worker fetch ignores ignoreHTTPSErrors; accept the self-signed staging certificate.
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const report = [];
  for (const view of VIEWS) {
    for (const who of ['guest', 'member']) {
      // Staging uses a self-signed localhost certificate.
      const ctx = await browser.newContext({ ...view.device, colorScheme: view.scheme, ignoreHTTPSErrors: true });
      await capture(ctx, who === 'guest' ? GUEST_PAGES : MEMBER_PAGES, who, view, report);
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(out, `${label}-report.json`), JSON.stringify(report, null, 1));
  for (const r of report) {
    console.log(`${r.who.padEnd(6)} ${r.view.padEnd(11)} ${String(r.status)} ${String(r.ms).padStart(5)}ms dom=${String(r.domNodes).padStart(5)} js=${String(r.scripts).padStart(3)} css=${String(r.styles).padStart(3)} ovx=${r.overflowX} theme=${r.theme || '-'} ${r.path} ${r.errors.length ? 'ERR ' + r.errors.join(' | ') : ''}`);
  }
})();
