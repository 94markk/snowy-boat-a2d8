// node global-panels-focus.cjs <base> — keyboard behaviour of the global panels: open with Enter on the trigger, where does
// focus go, does Escape close the panel and return focus to the trigger.
const { chromium, devices } = require('playwright');
const base = process.argv[2];
const cases = [
  { name: 'drawer', trigger: '.dsb8-header__menu', panel: '.dlx-drawer__panel' },
  { name: 'mini-cart', trigger: '.dsb8-header [data-dsb8-cart-toggle], .dsb8-header .dsb8-header__cart, .dsb8-header [aria-controls*="cart"]', panel: '.dsb8-cart-panel' },
  { name: 'notifications', trigger: '[data-dbv9-notification-open]', panel: '.dbv9-notifications__panel, [id^="dbv9-notifications-panel"]' },
];
(async () => {
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const ctx = await b.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true });
  const p = await ctx.newPage();
  for (const c of cases) {
    await p.goto(base + '/shop/', { waitUntil: 'networkidle' });
    const t = p.locator(c.trigger).first();
    if (!(await t.count())) { console.log(c.name, 'trigger not found'); continue; }
    await t.focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(700);
    const opened = await p.evaluate((sel) => { const e = document.querySelector(sel); if (!e) return 'no panel'; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' ? 'open' : 'closed'; }, c.panel);
    const focusIn = await p.evaluate((sel) => { const e = document.querySelector(sel); return !!(e && e.contains(document.activeElement)); }, c.panel);
    const active = await p.evaluate(() => document.activeElement ? document.activeElement.tagName.toLowerCase() + '.' + String(document.activeElement.className).split(' ')[0] : 'none');
    await p.keyboard.press('Escape'); await p.waitForTimeout(500);
    const back = await t.evaluate((el) => el === document.activeElement || el.contains(document.activeElement));
    const after = await p.evaluate((sel) => { const e = document.querySelector(sel); if (!e) return 'gone'; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' ? 'still open' : 'closed'; }, c.panel);
    console.log(`${c.name.padEnd(13)} after Enter: ${opened}, focus in panel: ${focusIn} (${active}); after Escape: ${after}, focus back on trigger: ${back}`);
  }
  await b.close();
})();
