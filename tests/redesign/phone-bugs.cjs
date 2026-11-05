// node phone-bugs.cjs <base-url> [out-dir] — STAGING ONLY. iPhone-size checks for the bugs reported on 5 Nov 2026:
//  1. page wider than the screen (pushes fixed panels off the right edge),
//  2. the « Delicat en application » bar over the site header,
//  3. the « Installer Delicat Store » sheet and 4. the cart panel inside the screen,
//  5. the cart panel lists an item added without a page reload,
//  6. catalogue pictures after in-app navigation when LiteSpeed lazy-load placeholders are in the HTML.
// Guest, customer (client1) and administrator (admin bar shown), iPhone 13 (390 px).
const fs = require('fs');
const path = require('path');
const { chromium, devices } = require('playwright');

const [base, out] = process.argv.slice(2);
if (!base) { console.error('usage: node phone-bugs.cjs <base-url> [out-dir]'); process.exit(2); }
if (out) fs.mkdirSync(out, { recursive: true });
const results = [];
const ok = (c, m) => { if (!c) throw new Error(m); };
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); } catch (e) { results.push(['FAIL', name, e.message]); console.log('FAIL', name, '—', e.message.slice(0, 400)); }
}
const PASS = { admin: 'AdminPass!2026', client1: 'ClientPass!2026' };

async function context(b, who) {
  const ctx = await b.newContext({ ...devices['iPhone 13'], ignoreHTTPSErrors: true });
  if (who) {
    const p = await ctx.newPage();
    await p.goto(base + '/wp-login.php');
    await p.fill('#user_login', who);
    await p.fill('#user_pass', PASS[who]);
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    await p.close();
  }
  return ctx;
}

const layout = (p) => p.evaluate(() => {
  const vw = Math.round(window.visualViewport ? window.visualViewport.width : innerWidth);
  const wide = [];
  for (const el of document.body.querySelectorAll('*')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.right <= vw + 1) continue;
    let clipped = false;
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
      const s = getComputedStyle(a);
      if (/(hidden|clip|auto|scroll)/.test(s.overflowX) && a.getBoundingClientRect().right <= vw + 1) { clipped = true; break; }
    }
    if (clipped || getComputedStyle(el).position === 'fixed' && el.closest('[hidden],[aria-hidden="true"]')) continue;
    wide.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}.${String(el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className).trim().split(/\s+/).slice(0, 2).join('.')} right=${Math.round(r.right)}`);
  }
  return { vw, inner: innerWidth, sw: document.documentElement.scrollWidth, wide: wide.slice(0, 6) };
});
const inside = (r, vw) => r && r.left >= -1 && r.right <= vw + 1;
const rect = (p, sel) => p.evaluate((s) => { const e = [...document.querySelectorAll(s)].find((x) => x.getBoundingClientRect().width > 0); if (!e) return null; const r = e.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, w: r.width }; }, sel);

(async () => {
  const b = await chromium.launch();
  // One purchasable simple product, looked up as a guest (signed-in REST calls without a nonce answer differently).
  const g = await b.newContext({ ignoreHTTPSErrors: true });
  const gp = await g.newPage();
  await gp.goto(base + '/', { waitUntil: 'load' });
  const simpleId = await gp.evaluate(async () => { const r = await fetch('/wp-json/wc/store/v1/products?type=simple&per_page=20', { credentials: 'omit' }); const list = await r.json(); const x = Array.isArray(list) ? list.find((q) => q.is_purchasable && q.is_in_stock) : null; return x ? x.id : 0; });
  await g.close();
  for (const who of [null, 'client1', 'admin']) {
    const label = who || 'guest';
    const ctx = await context(b, who);
    const p = await ctx.newPage();
    for (const url of ['/', '/shop/', '/product/free-fire/']) {
      await step(`${label} ${url}: the page is not wider than the phone`, async () => {
        await p.goto(base + url, { waitUntil: 'load' });
        const l = await layout(p);
        ok(l.inner <= l.vw + 1 && l.sw <= l.vw + 1, `layout ${l.inner}px / scroll ${l.sw}px on a ${l.vw}px screen: ${l.wide.join(' | ')}`);
      });
    }
    await step(`${label}: the app bar does not cover the site header`, async () => {
      await p.goto(base + '/product/free-fire/', { waitUntil: 'load' });
      const bar = await p.waitForSelector('#dbv9-install-bar.is-in', { timeout: 12000 }).catch(() => null);
      ok(bar, 'the app bar never appeared (installUI off?)');
      await p.waitForTimeout(400);
      const h = await rect(p, '[data-dsb8-header] .dsb8-header__row, [data-dsb8-header]');
      const r = await rect(p, '#dbv9-install-bar');
      const vw = (await layout(p)).vw;
      if (out) await p.screenshot({ path: path.join(out, `bar-${label}.png`) });
      ok(h && r && (r.top >= h.bottom - 1 || r.bottom <= h.top + 1), `bar ${Math.round(r.top)}–${Math.round(r.bottom)} over header ${Math.round(h.top)}–${Math.round(h.bottom)}`);
      ok(inside(r, vw), `bar ${Math.round(r.left)}–${Math.round(r.right)} outside the ${vw}px screen`);
    });
    await step(`${label}: the install sheet fits the screen`, async () => {
      await p.evaluate(() => window.DelicatPWA && window.DelicatPWA.open());
      await p.waitForSelector('.dbv9-pwa-modal.is-open', { timeout: 4000 });
      await p.waitForTimeout(300);
      const r = await rect(p, '.dbv9-pwa-card');
      const c = await rect(p, '.dbv9-pwa-close');
      const vw = (await layout(p)).vw;
      if (out) await p.screenshot({ path: path.join(out, `sheet-${label}.png`) });
      ok(inside(r, vw) && inside(c, vw), `sheet ${Math.round(r.left)}–${Math.round(r.right)}, close ${Math.round(c.left)}–${Math.round(c.right)} on ${vw}px`);
      await p.keyboard.press('Escape');
    });
    await step(`${label}: the cart panel fits the screen and lists an item added without reloading`, async () => {
      await p.goto(base + '/', { waitUntil: 'load' });
      await p.waitForTimeout(600);
      const id = simpleId;
      ok(id, 'no simple product to add');
      const added = await p.evaluate(async (pid) => {
        const f = new FormData(); f.append('product_id', String(pid)); f.append('quantity', '1');
        const r = await fetch('/?wc-ajax=add_to_cart', { method: 'POST', body: f, credentials: 'same-origin' });
        const j = await r.json().catch(() => ({}));
        document.dispatchEvent(new CustomEvent('delicat:cart-changed', { detail: { source: 'test' } }));
        return !j.error;
      }, id);
      ok(added, 'WooCommerce refused the add');
      await p.waitForTimeout(500);
      await p.locator('[data-dsb8-cart-trigger]:visible').first().click();
      await p.waitForSelector('[data-dsb8-cart-root].is-open .dsb8-cart-panel', { timeout: 3000 });
      const listed = await p.waitForFunction(() => !!document.querySelector('[data-dsb8-cart-root].is-open .dsb8-cart-swipe'), null, { timeout: 4000 }).then(() => true).catch(() => false);
      const covered = await p.evaluate(() => {
        const h = document.querySelector('[data-dsb8-cart-root].is-open .dsb8-cart-panel__head');
        if (!h) return 'no head';
        const r = h.getBoundingClientRect();
        const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return el && h.closest('.dsb8-cart-panel').contains(el) ? '' : (el ? (el.id || el.className || el.tagName) : 'nothing');
      });
      ok(!covered, `the open panel's heading is covered by ${covered}`);
      const r = await rect(p, '[data-dsb8-cart-root].is-open .dsb8-cart-panel');
      const vw = (await layout(p)).vw;
      if (out) await p.screenshot({ path: path.join(out, `cart-${label}.png`) });
      await p.evaluate(async () => { const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' }); const n = r.headers.get('Nonce'); const c = await r.json(); for (const it of c.items || []) await fetch('/wp-json/wc/store/v1/cart/remove-item', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Nonce: n }, body: JSON.stringify({ key: it.key }) }); });
      ok(inside(r, vw), `panel ${Math.round(r.left)}–${Math.round(r.right)} on ${vw}px`);
      ok(listed, 'the panel still says the cart is empty');
    });
    await ctx.close();
  }

  await step('catalogue pictures load after in-app navigation even with LiteSpeed lazy placeholders in the HTML', async () => {
    const ctx = await context(b, null);
    const p = await ctx.newPage();
    // Rewrite category documents the way LiteSpeed's lazy-load does for images it does not exclude.
    await ctx.route((u) => u.pathname.startsWith('/product-category/'), async (route) => {
      const res = await route.fetch();
      let html = await res.text();
      html = html.replace(/<img\b([^>]*?)\ssrc="([^"]+)"([^>]*)>/g, (m, a, src, z) => {
        if (/data-no-lazy|fetchpriority="high"|loading="eager"/.test(a + z) || !/delicat-product-card__image/.test(a + z)) return m;
        const rest = (a + z).replace(/\ssrcset="([^"]*)"/, ' data-srcset="$1"');
        return `<img${rest} data-lazyloaded="1" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="${src}">`;
      });
      await route.fulfill({ response: res, body: html });
    });
    await p.goto(base + '/shop/', { waitUntil: 'load' });
    await p.waitForTimeout(800);
    const href = await p.evaluate(() => { const a = [...document.querySelectorAll('.delicat-native-category-chips a')].find((x) => !x.classList.contains('is-active') && x.href.indexOf('/product-category/') > 0); return a ? a.href : ''; });
    ok(href, 'no category chip to tap');
    await p.evaluate(() => { window.__soft = 1; });
    await p.locator(`.delicat-native-category-chips a[href="${href}"]`).first().click();
    await p.waitForURL((u) => u.href === href, { timeout: 8000 });
    await p.waitForSelector('.delicat-archive-grid .delicat-product-card__image', { timeout: 8000 });
    ok(await p.evaluate(() => window.__soft === 1), 'the chip reloaded the page: in-app navigation was not exercised');
    await p.evaluate(async () => { for (const img of document.querySelectorAll('.delicat-archive-grid .delicat-product-card__image')) { img.scrollIntoView({ block: 'center' }); await new Promise((r) => setTimeout(r, 120)); } });
    await p.waitForTimeout(1200);
    const imgs = await p.evaluate(() => [...document.querySelectorAll('.delicat-archive-grid .delicat-product-card__image')].map((i) => ({ src: (i.currentSrc || i.src).slice(0, 40), nw: i.naturalWidth })));
    if (out) await p.screenshot({ path: path.join(out, 'archive-softnav.png') });
    const bad = imgs.filter((i) => i.src.startsWith('data:') || i.nw < 50);
    ok(imgs.length > 0 && bad.length === 0, `${bad.length}/${imgs.length} pictures still placeholders: ${JSON.stringify(bad.slice(0, 3))}`);
    await ctx.close();
  });

  await b.close();
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
