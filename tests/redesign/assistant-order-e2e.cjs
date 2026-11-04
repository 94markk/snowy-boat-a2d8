// Delicat Assistant 7.1 — the chat on a phone: an order looked up by its number, « Partager le reçu », the
// « Recharger un jeu » tile, and asking for a human from the Assistant tab (the Team tab opens).
// node assistant-order-e2e.cjs <base-url> <site-dir> <assistant-db.php> [out-dir]   (seed first: php assistant-setup.php <site-dir>)
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const [base, site, dbScript, out] = process.argv.slice(2);
const results = [];
const db = (...args) => execFileSync('php', [dbScript, site, ...args], { encoding: 'utf8' }).trim();
const ok = (cond, msg) => { if (!cond) throw new Error(msg); };
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); }
  catch (e) { results.push(['FAIL', name]); console.log('FAIL', name, '—', String(e && e.message || e).slice(0, 400)); }
}
const secret = /secret-mail|37001234|Rue Secr|987654321/i;

(async () => {
  if (out) fs.mkdirSync(out, { recursive: true });
  const order = JSON.parse(db('order', 'client1'));
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const ctx = await b.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', (e) => errors.push(String(e)));
  // Phones without a share sheet (this test browser) fall back to WhatsApp: record what would be sent.
  await ctx.addInitScript(() => { window.__opened = []; window.open = (u) => { window.__opened.push(String(u)); return { opener: null }; }; });
  try {
    await p.goto(base + '/wp-login.php');
    await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    await p.goto(base + '/', { waitUntil: 'load' });
    await p.evaluate(() => { const d = document.getElementById('dip-age'); if (d) d.remove(); });
    await p.click('#dsa7-launcher');
    await p.locator('.app.on').waitFor({ timeout: 8000 });

    await step('« Recharger un jeu » opens the game top-up answer', async () => {
      await p.locator('.home button', { hasText: 'Recharger un jeu' }).first().click();
      await p.waitForFunction(() => document.getElementById('dsa7-app').shadowRoot.querySelectorAll('.log .card').length > 0, null, { timeout: 15000 });
      const text = await p.locator('.log .card').last().innerText();
      ok(!/crédit boutique/i.test(text.split('\n')[0] || ''), 'opened the store-credit guide: ' + text.slice(0, 120));
    });
    await step('typing « #N » shows the order card with its status and items', async () => {
      const cardSel = '.card[data-type="order_card"]';
      const before = await p.locator(cardSel).count(); // the thread may hold cards from an earlier run
      await p.fill('.input', '#' + order.number);
      await p.click('.send');
      await p.waitForFunction(([sel, n]) => document.getElementById('dsa7-app').shadowRoot.querySelectorAll(sel).length > n, [cardSel, before], { timeout: 15000 });
      const text = await p.locator(cardSel).last().innerText();
      ok(text.includes('#' + order.number), 'number missing');
      ok(/PIN Free Fire 341/i.test(text) && /MonCash/.test(text), 'items or payment missing: ' + text.slice(0, 200));
      ok(!secret.test(text), 'private data shown');
      if (out) await p.screenshot({ path: out + '/chat-order-card.png' });
    });
    await step('« Partager le reçu » shares a receipt without private data', async () => {
      await p.locator('.card[data-type="order_card"] button', { hasText: 'Partager le reçu' }).last().click();
      await p.waitForFunction(() => window.__opened.length > 0, null, { timeout: 5000 });
      const url = decodeURIComponent(await p.evaluate(() => window.__opened[window.__opened.length - 1]));
      ok(url.startsWith('https://wa.me/?text='), 'not a WhatsApp share: ' + url.slice(0, 60));
      ok(url.includes('#' + order.number) && /Statut/.test(url) && /Total/.test(url), 'receipt incomplete: ' + url.slice(0, 200));
      ok(!secret.test(url), 'private data in the receipt');
    });
    await step('asking for a human in the Assistant tab opens the Team tab', async () => {
      await p.fill('.input', 'Je veux parler à un humain');
      await p.click('.send');
      await p.locator('.card[data-type="agent_card"], .card[data-type="contact_card"]').first().waitFor({ timeout: 15000 });
      ok(/Équipe/.test(await p.locator('.tab[aria-current="page"]').first().innerText()), 'still on the Assistant tab');
    });
    await step('no script errors', async () => ok(!errors.length, errors.join(' | ')));
  } finally {
    await b.close();
    db('order-delete', String(order.id));
  }
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
