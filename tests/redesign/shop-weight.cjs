// node shop-weight.cjs <base-url> <site-dir> — STAGING ONLY. What the shop page downloads on a phone: the HTML, every
// stylesheet and script (bytes as served, before compression), the number of requests, and the size of the
// product-card markup. Prints one JSON line; run it before and after a change to compare.
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium, devices } = require('playwright');

const [base, site] = process.argv.slice(2);
const setup = JSON.parse(execFileSync('php', [path.join(__dirname, 'archive-setup.php'), site], { encoding: 'utf8' }).replace(/^.*Deprecated.*$/gm, '').trim().split('\n').pop());

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const seen = [];
  page.on('response', async (res) => {
    const type = res.request().resourceType();
    if (!['document', 'stylesheet', 'script'].includes(type)) return;
    let bytes = 0;
    try { bytes = (await res.body()).length; } catch (e) { bytes = 0; }
    seen.push({ type, bytes, url: res.url().replace(base, '') });
  });
  const shop = setup.shop || (base + '/shop/');
  await page.goto(shop, { waitUntil: 'networkidle' });
  const cards = await page.evaluate(() => {
    const list = document.querySelectorAll('.delicat-product-card');
    return { count: list.length, home: document.querySelectorAll('.delicat-product-card--home').length, markup: [...list].reduce((n, el) => n + el.outerHTML.length, 0) };
  });
  const sum = (t) => seen.filter((s) => s.type === t).reduce((n, s) => n + s.bytes, 0);
  const out = {
    html: sum('document'), css: sum('stylesheet'), js: sum('script'),
    css_files: seen.filter((s) => s.type === 'stylesheet').length, js_files: seen.filter((s) => s.type === 'script').length,
    cards, css_list: seen.filter((s) => s.type === 'stylesheet').map((s) => `${s.bytes} ${s.url.split('?')[0]}`),
  };
  console.log(JSON.stringify(out));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
