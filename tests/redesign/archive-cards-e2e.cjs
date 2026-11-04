// node archive-cards-e2e.cjs <base-url> <site-dir> [out-dir] — STAGING ONLY. Delicat Builder 9.7.4: the shop,
// category and search pages draw each product with the homepage card (picture with badges and the name over it,
// like button, dark footer with « À partir de » and the round cart button), on a phone, light and dark.
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const [base, site, out] = process.argv.slice(2);
const results = [];
const ok = (c, m) => { if (!c) throw new Error(m); };
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); }
  catch (e) { results.push(['FAIL', name]); console.log('FAIL', name, '—', String(e && e.message || e).slice(0, 600)); }
}

(async () => {
  if (out) fs.mkdirSync(out, { recursive: true });
  // The archive preset runs on an administrator's first wp-admin visit after the update; run it now.
  const setup = JSON.parse(execFileSync('php', [path.join(__dirname, 'archive-setup.php'), site], { encoding: 'utf8' }).replace(/^.*Deprecated.*$/gm, '').trim().split('\n').pop());
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const card = async (p) => p.evaluate(() => {
    const cards = [...document.querySelectorAll('.delicat-archive-grid .delicat-product-card')];
    const c = cards[0];
    const box = (sel) => { const e = c && c.querySelector(sel); if (!e) return null; const s = getComputedStyle(e); const r = e.getBoundingClientRect(); return { w: r.width, h: r.height, bg: s.backgroundColor, color: s.color }; };
    return {
      n: cards.length, home: cards.filter((x) => x.classList.contains('delicat-product-card--home')).length,
      media: box('.delicat-product-card__media'), title: box('.delicat-product-card__poster-title'), body: box('.delicat-product-card__body'),
      cta: box('.delicat-product-card__cta'), like: box('.delicat-like'),
      price: c ? (c.querySelector('.delicat-product-card__price') || { innerText: '' }).innerText : '',
      href: c ? c.querySelector('.delicat-product-card__cta').getAttribute('href') : '',
      pressed: [...document.querySelectorAll('.delicat-like')].filter((x) => x.getAttribute('aria-pressed') === 'true').length,
      sw: document.documentElement.scrollWidth, vw: innerWidth,
    };
  });
  for (const dark of [false, true]) {
    await step(`shop page (${dark ? 'dark' : 'light'}): every product uses the homepage card`, async () => {
      const ctx = await b.newContext({ ...devices['Pixel 7'], colorScheme: dark ? 'dark' : 'light', ignoreHTTPSErrors: true });
      const p = await ctx.newPage(); const errors = []; p.on('pageerror', (e) => errors.push(String(e)));
      await p.goto(setup.shop, { waitUntil: 'load' });
      const i = await card(p);
      ok(i.n > 0 && i.home === i.n, `cards ${i.home}/${i.n}`);
      ok(i.media && Math.abs(i.media.w - i.media.h) < 2, 'square picture');
      ok(i.title && i.title.color === 'rgb(255, 255, 255)', 'name over the picture');
      ok(i.body && i.body.bg === 'rgb(16, 19, 52)', 'dark footer ' + (i.body && i.body.bg));
      ok(/À partir de\s*G\s?[\d.,\s]+/.test(i.price), 'price ' + i.price);
      ok(i.cta && i.cta.w >= 44 && i.cta.h >= 44, 'cart button tap target');
      ok(i.href && i.href.indexOf(base) === 0, 'cart button opens the product');
      ok(i.like && i.like.w >= 44, 'like button');
      ok(i.pressed === 0, 'a visitor sees liked hearts');
      ok(i.sw <= i.vw, `horizontal scroll ${i.sw} > ${i.vw}`);
      ok(!errors.length, errors.join(' | '));
      if (out) await p.screenshot({ path: path.join(out, `archive-${dark ? 'dark' : 'light'}.png`) });
      await ctx.close();
    });
  }
  await step('category page and 320 px phone: same card, no horizontal scroll', async () => {
    const ctx = await b.newContext({ ...devices['Pixel 7'], viewport: { width: 320, height: 700 }, ignoreHTTPSErrors: true });
    const p = await ctx.newPage();
    await p.goto(setup.category, { waitUntil: 'load' });
    const i = await card(p);
    ok(i.n > 0 && i.home === i.n, `cards ${i.home}/${i.n}`);
    ok(i.sw <= i.vw, `horizontal scroll ${i.sw} > ${i.vw}`);
    await ctx.close();
  });
  await b.close();
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
