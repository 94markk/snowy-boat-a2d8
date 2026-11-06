// node reseller-e2e.cjs <base-url> <site-dir> <out-dir> — STAGING ONLY. Delicat Resellers 1.0 in a phone browser:
// visitor view, application form, « en examen », deposit step, activation, badge on the account home, reseller price on
// the product page and in the cart, the automatic refusal after a wallet-to-wallet transfer, dark mode at 360 px.
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const [base, site, out] = process.argv.slice(2);
const php = (...args) => JSON.parse(execFileSync('php', [path.join(__dirname, 'reseller-setup.php'), site, ...args], { encoding: 'utf8' }).replace(/^.*Deprecated.*$/gm, '').trim().split('\n').pop());
const results = [];
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); }
  catch (e) { results.push(['FAIL', name]); console.log('FAIL', name, '—', String(e && e.message || e).slice(0, 700)); }
}
const ok = (c, m) => { if (!c) throw new Error(m); };
// An amount as the site prints it, whatever the language: 50 000, 50,000, 50 000 (non-breaking space)…
const amount = (n) => new RegExp(String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '[\\s,.\u00a0\u202f]?') + '\\s?HTG');
async function phone(b, opts = {}) {
  const ctx = await b.newContext({ ...devices['Pixel 7'], ...(opts.viewport ? { viewport: opts.viewport } : {}), colorScheme: opts.dark ? 'dark' : 'light', ignoreHTTPSErrors: true });
  await ctx.addInitScript(() => {
    document.addEventListener('DOMContentLoaded', () => {
      const s = document.createElement('style'); s.textContent = '.dlc-review-overlay{display:none!important}'; document.head.appendChild(s);
      const drop = () => { const a = document.getElementById('dip-age'); if (a) { if (a.open && a.close) a.close(); a.remove(); } };
      drop(); new MutationObserver(drop).observe(document.documentElement, { childList: true, subtree: true });
    });
  });
  const p = await ctx.newPage();
  p.errors = [];
  p.on('pageerror', (e) => p.errors.push(String(e)));
  if (opts.login) {
    await p.goto(base + '/wp-login.php');
    await p.fill('#user_login', opts.login); await p.fill('#user_pass', 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  }
  return { ctx, p };
}
const shot = async (p, name, full = true) => { if (out) await p.screenshot({ path: path.join(out, name + '.png'), fullPage: full }); };

(async () => {
  if (out) fs.mkdirSync(out, { recursive: true });
  const s = php('setup');
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });

  const G = await phone(b);
  await step('visitor: the levels and a sign-in card, no form', async () => {
    await G.p.goto(s.page, { waitUntil: 'load' });
    ok((await G.p.$$('.dlr-level')).length === 3, 'levels');
    ok(amount(100000).test(await G.p.textContent('.dlr-levels')), 'Premium minimum');
    ok(!/&nbsp;|&amp;/.test(await G.p.textContent('.dlr')), 'HTML entity shown as text');
    ok(await G.p.isVisible('.dlr-login a.dlr-btn'), 'sign-in button');
    ok(!(await G.p.$('form.dlr-form')), 'form shown to a visitor');
    await shot(G.p, 'reseller-guest');
  });

  const A = await phone(b, { login: 'rs1' });
  await step('customer: application form → « Examen par l’équipe »', async () => {
    await A.p.goto(s.page, { waitUntil: 'load' });
    await A.p.click('label.dlr-choice.dlr-t-gold');
    await A.p.fill('input[name="business"]', 'Boutique Rony');
    await A.p.fill('input[name="phone"]', '+509 3700 0001');
    await A.p.fill('input[name="city"]', 'Cap-Haïtien');
    await A.p.fill('textarea[name="channel"]', 'Je vends des diamants Free Fire à mon école et sur WhatsApp.');
    await A.p.selectOption('select[name="volume"]', '50_100');
    await A.p.check('input[name="terms"]');
    await Promise.all([A.p.waitForNavigation(), A.p.click('form.dlr-form button[type="submit"]')]);
    ok(/Demande envoyée/.test(await A.p.textContent('.dlr-flash')), 'confirmation');
    ok(/Examen/.test(await A.p.textContent('.dlr-steps li.is-now')), 'current step');
    ok(!(await A.p.$('form.dlr-form')), 'form still shown');
    ok(php('state', 'rs1').app.status === 'pending', 'not pending');
    await shot(A.p, 'reseller-pending');
  });
  await step('customer: a refused field keeps what was typed', async () => {
    const C = await phone(b, { login: 'rs3' });
    await C.p.goto(s.page, { waitUntil: 'load' });
    await C.p.fill('input[name="business"]', 'Boutique Rudy');
    await C.p.fill('input[name="phone"]', '12'); // too short: the browser accepts it, the server refuses it
    await C.p.fill('input[name="city"]', 'Jacmel');
    await C.p.fill('textarea[name="channel"]', 'Vente en ligne et en boutique.');
    await C.p.selectOption('select[name="volume"]', 'lt50');
    await C.p.check('input[name="terms"]');
    await Promise.all([C.p.waitForNavigation(), C.p.click('form.dlr-form button[type="submit"]')]);
    ok(/téléphone/.test(await C.p.textContent('.dlr-flash--err')), 'error message');
    ok((await C.p.inputValue('input[name="business"]')) === 'Boutique Rudy', 'business lost');
    await C.ctx.close();
  });
  await step('approved: deposit step with the amount, the deadline and the transfer warning', async () => {
    ok(php('approve', 'rs1').ok, 'approve');
    await A.p.goto(s.page, { waitUntil: 'load' });
    ok(/Dépôt/.test(await A.p.textContent('.dlr-steps li.is-now')), 'deposit step');
    ok(amount(50000).test(await A.p.textContent('.dlr-app')), 'amount: ' + (await A.p.textContent('.dlr-app')).replace(/\s+/g, ' ').slice(0, 200));
    ok(/autre portefeuille/.test(await A.p.textContent('.dlr-warn')), 'warning');
    ok((await A.p.getAttribute('.dlr-app a.dlr-btn', 'href')) === s.recharge, 'recharge link');
    await shot(A.p, 'reseller-deposit');
  });
  await step('deposit complete: Golden Reseller card with this month\'s deposits', async () => {
    php('deposit', 'rs1', '50000');
    await A.p.goto(s.page, { waitUntil: 'load' });
    ok(/Golden Reseller/.test(await A.p.textContent('.dlr-me .dlr-badge')), 'badge');
    ok(/activation compte pour ce mois/.test(await A.p.textContent('.dlr-me')), 'month message');
    ok(await A.p.isVisible('.dlr-level.dlr-t-gold.is-current'), 'current level');
    ok(await A.p.isVisible('form.dlr-form'), 'upgrade form (Premium) missing');
    ok((await A.p.$$('form.dlr-form input[name="tier"]')).length === 1, 'only Premium offered');
    await shot(A.p, 'reseller-member');
  });
  await step('account home: the Golden Reseller badge card', async () => {
    await A.p.goto(s.account, { waitUntil: 'load' });
    await A.p.waitForSelector('a.dlr-acc', { timeout: 10000 });
    ok(/Golden Reseller/.test(await A.p.textContent('a.dlr-acc')), 'badge text');
    const c = await A.p.$eval('a.dlr-acc b', (e) => getComputedStyle(e).color);
    ok(c === 'rgb(255, 255, 255)', 'badge text colour ' + c);
    await shot(A.p, 'reseller-account', false);
  });
  await step('account home: « Programme Revendeur » in « Mes services », with a shop icon, opens the account page', async () => {
    await A.p.goto(s.account, { waitUntil: 'load' });
    const row = A.p.locator('a.dipapp-row', { hasText: 'Programme Revendeur' });
    ok(await row.count() === 1, 'row missing');
    ok(await row.locator('.dipma-icon-store').count() === 1, 'shop icon missing');
    const mask = await row.locator('.dipma-icon-store').evaluate((e) => getComputedStyle(e).getPropertyValue('--dipma-icon'));
    ok(/svg/.test(mask), 'icon has no glyph: ' + mask.slice(0, 60));
    ok(!(await A.p.locator('a.dipapp-row .dipma-icon-dot').count()), 'a row still shows a plain dot');
    await Promise.all([A.p.waitForNavigation(), row.click()]);
    ok(A.p.url().startsWith(s.accountPage), 'opened ' + A.p.url());
    ok(/Programme Revendeur/.test(await A.p.textContent('.dipapp-topbar h1')), 'title');
    ok(/Golden Reseller/.test(await A.p.textContent('.dlr--account .dlr-me .dlr-badge')), 'member card');
    ok(!(await A.p.$('.dlr--account .dlr-hero')), 'big page header shown inside the account');
    await shot(A.p, 'reseller-account-page');
  });
  await step('account page: apply from My Account and come back to it', async () => {
    const C = await phone(b, { login: 'rs3' });
    await C.p.goto(s.accountPage, { waitUntil: 'load' });
    await C.p.click('label.dlr-choice.dlr-t-silver');
    await C.p.fill('input[name="business"]', 'Boutique Rudy'); await C.p.fill('input[name="phone"]', '+509 3700 0003');
    await C.p.fill('input[name="city"]', 'Jacmel'); await C.p.fill('textarea[name="channel"]', 'Vente en ligne et en boutique.');
    await C.p.selectOption('select[name="volume"]', 'lt50'); await C.p.check('input[name="terms"]');
    await Promise.all([C.p.waitForNavigation(), C.p.click('form.dlr-form button[type="submit"]')]);
    ok(C.p.url().startsWith(s.accountPage), 'came back to ' + C.p.url());
    ok(/Demande envoyée/.test(await C.p.textContent('.dlr-flash')), 'confirmation');
    ok(php('state', 'rs3').app.status === 'pending', 'not pending');
    await C.ctx.close();
  });
  await step('product page: 120 HTG « Prix Golden Reseller », 150 struck through', async () => {
    await A.p.goto(s.product, { waitUntil: 'load' });
    const html = await A.p.$eval('body', (b) => b.innerText);
    ok(/Prix Golden Reseller/.test(html), 'label');
    ok(/120/.test(html), 'reseller price');
    ok(await A.p.$('del'), 'struck regular price');
    await shot(A.p, 'reseller-product', false);
  });
  await step('cart: charged the reseller price (WooCommerce cart API)', async () => {
    await A.p.goto(base + '/?add-to-cart=' + s.products.p110, { waitUntil: 'load' });
    const cart = await A.p.evaluate(async (b) => (await fetch(b + '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' })).json(), base);
    const item = (cart.items || []).find((i) => i.id === s.products.p110);
    ok(item, 'item not in cart');
    const unit = Number(item.prices.price) / Math.pow(10, item.prices.currency_minor_unit);
    ok(unit === 120, 'unit price ' + unit);
  });

  const T = await phone(b, { login: 'rs2' });
  await step('transfer during the deposit step: the customer sees the refusal and may apply again', async () => {
    await T.p.goto(s.page, { waitUntil: 'load' });
    await T.p.click('label.dlr-choice.dlr-t-silver');
    await T.p.fill('input[name="business"]', 'Rita Shop'); await T.p.fill('input[name="phone"]', '+509 3700 0002');
    await T.p.fill('input[name="city"]', 'Gonaïves'); await T.p.fill('textarea[name="channel"]', 'Revente aux joueurs de mon quartier.');
    await T.p.selectOption('select[name="volume"]', 'lt50'); await T.p.check('input[name="terms"]');
    await Promise.all([T.p.waitForNavigation(), T.p.click('form.dlr-form button[type="submit"]')]);
    php('approve', 'rs2');
    php('transfer', 'rs4', 'rs2', '25000');
    await T.p.goto(s.page, { waitUntil: 'load' });
    ok(/autre portefeuille/.test(await T.p.textContent('.dlr-note')), 'refusal note');
    ok(await T.p.isVisible('form.dlr-form'), 'new application form');
    ok(php('state', 'rs2').app.status === 'declined', 'status');
  });

  await step('dark mode at 360 px: no horizontal scroll, readable badge', async () => {
    const D = await phone(b, { login: 'rs1', dark: true, viewport: { width: 360, height: 780 } });
    await D.p.goto(s.page, { waitUntil: 'load' });
    await D.p.evaluate(() => document.documentElement.classList.add('dlc-theme-dark'));
    const m = await D.p.evaluate(() => ({ w: document.documentElement.scrollWidth, vw: innerWidth, card: getComputedStyle(document.querySelector('.dlr-card')).backgroundColor }));
    ok(m.w <= m.vw, 'overflow ' + JSON.stringify(m));
    await shot(D.p, 'reseller-dark-360');
    await D.ctx.close();
  });
  await step('no script errors', async () => {
    const all = [G, A, T].flatMap((x) => x.p.errors);
    ok(!all.length, all.join(' | ').slice(0, 600));
  });
  await b.close();
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
