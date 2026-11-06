// node rewards-e2e.cjs <base-url> <site-dir> <out-dir> — STAGING ONLY. Bonus Free Fire page (Delicat Rewards 3.3):
// cache-safe HTML, guest view, ranking + your place, tabs (pointer, keyboard, deep link), the « bonus ready » and
// « claimed » celebrations, the PIN dialog, a replayed claim token, the old shortcode, dark mode, reduced motion.
// Runs rewards-setup.php first (fresh orders, a claimable « Mini » bonus for client1).
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const [base, site, out] = process.argv.slice(2);
const here = __dirname;
const php = (script, ...args) => execFileSync('php', [path.join(here, script), site, ...args], { encoding: 'utf8' }).replace(/^.*Deprecated.*$/gm, '').trim();
const results = [];
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); }
  catch (e) { results.push(['FAIL', name]); console.log('FAIL', name, '—', String(e && e.message || e).slice(0, 900)); }
}
const ok = (cond, msg) => { if (!cond) throw new Error(msg); };
const PAGE = '/bonus-free-fire/';
async function context(b, who, opts = {}) {
  const ctx = await b.newContext({ ...devices['Pixel 7'], colorScheme: opts.dark ? 'dark' : 'light', reducedMotion: opts.still ? 'reduce' : 'no-preference', ignoreHTTPSErrors: true });
  // Site prompts that are not part of this page (age check, « rate your order ») stay out of the way.
  // A modal dialog hidden with CSS still makes the page inert: the age check is closed and removed instead.
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
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|ERR_TUNNEL|gravatar/.test(m.text())) p.errors.push(m.text()); });
  if (who) {
    await p.goto(base + '/wp-login.php');
    await p.fill('#user_login', who);
    await p.fill('#user_pass', 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  }
  return { ctx, p };
}
const loaded = (p) => p.waitForFunction(() => document.querySelector('#dbf-board[aria-busy="false"]') || document.querySelector('#dbf-tiers[aria-busy="false"]'), null, { timeout: 10000 });

(async () => {
  php('rewards-setup.php');
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });

  /* ------------------------------------------------------------ guest */
  {
    const { ctx, p } = await context(b, null);
    await step('html: same for everyone (no REST nonce, no account id, no clock)', async () => {
      const html = await (await p.request.get(base + PAGE)).text();
      ok(html.includes('id="dbf"'), 'page not rendered');
      const cfg = ((/<section class="dbf"[^>]*data-config="([^"]*)"/.exec(html) || [])[1] || '').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&');
      ok(cfg.length > 50, 'no page config');
      ok(!/"nonce"\s*:/.test(cfg), 'nonce in the page config');
      ok(!/"userId"\s*:/.test(cfg), 'account id in the page config');
      ok(!/"serverNow"\s*:/.test(cfg), 'server clock in the page config');
    });
    await step('guest: sign-in card instead of any data', async () => {
      await p.goto(base + PAGE, { waitUntil: 'load' });
      await p.locator('#dbf-board .dbf-login').waitFor({ timeout: 10000 });
      await p.click('#dbf-tab-bonus');
      ok(await p.locator('#dbf-panel-bonus .dbf-login').isVisible(), 'no sign-in card on Mes bonus');
      ok(!(await p.locator('.dbf-tier').count()), 'tiers shown to a guest');
      ok(!p.errors.length, p.errors.join(' | '));
    });
    await ctx.close();
  }

  /* ------------------------------------------------------------ client1: ranking, tabs, claim */
  {
    const { ctx, p } = await context(b, 'client1');
    await step('classement: podium, ranking rows, countdown and your place', async () => {
      await p.goto(base + PAGE, { waitUntil: 'load' });
      await loaded(p);
      ok(await p.locator('.dbf-pod:not(.is-empty)').count() === 3, 'podium');
      ok(await p.locator('.dbf-rank-row').count() >= 3, 'ranking rows');
      ok(/\d+\s*(j|h|min|s)/.test(await p.locator('#dbf-board-clock').textContent()), 'countdown: ' + await p.locator('#dbf-board-clock').textContent());
      ok(await p.locator('#dbf-you').isVisible(), 'your place bar hidden');
      ok((await p.locator('#dbf-you-badge').textContent()).trim() === '#4', 'rank badge: ' + await p.locator('#dbf-you-badge').textContent());
      ok(!/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(await p.locator('#dbf').innerText()), 'emoji on the page');
      await p.screenshot({ path: `${out}/e2e-classement.png` });
    });
    await step('tabs: keyboard switch, deep link #mes-bonus', async () => {
      await p.focus('#dbf-tab-classement');
      await p.keyboard.press('ArrowRight');
      ok(await p.locator('#dbf-tab-bonus').getAttribute('aria-selected') === 'true', 'ArrowRight did not select Mes bonus');
      ok(new URL(p.url()).hash === '#mes-bonus', 'hash ' + new URL(p.url()).hash);
      ok(await p.locator('#dbf-panel-classement').isHidden() && !(await p.locator('#dbf-you').isVisible()), 'classement still shown');
      await p.goto(base + PAGE + '#mes-bonus', { waitUntil: 'load' });
      await loaded(p);
      ok(await p.locator('#dbf-panel-bonus').isVisible(), 'deep link did not open Mes bonus');
    });
    await step('mes bonus: the ready bonus is celebrated once', async () => {
      // This context already saw it on the first load: a fresh browser sees the celebration.
      const f = await context(b, 'client1');
      await f.p.goto(base + PAGE + '#mes-bonus', { waitUntil: 'load' });
      await f.p.locator('.dbf-tier.is-ready').waitFor({ timeout: 10000 });
      await f.p.locator('canvas.dbf-confetti').waitFor({ state: 'attached', timeout: 3000 });
      ok((await f.p.locator('#dbf-toast').textContent()).includes('Bonus débloqué'), 'no toast');
      await f.p.waitForTimeout(3400);
      ok(!(await f.p.locator('canvas.dbf-confetti').count()), 'confetti canvas left behind');
      await f.p.reload({ waitUntil: 'load' });
      await f.p.locator('.dbf-tier.is-ready').waitFor({ timeout: 10000 });
      await f.p.waitForTimeout(800);
      ok(!(await f.p.locator('canvas.dbf-confetti').count()), 'celebrated twice');
      await f.ctx.close();
    });
    let token = '';
    await step('claim: confetti, « Bravo » dialog with the PIN, history updated', async () => {
      token = await p.evaluate(async () => {
        const boot = await (await fetch('/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'delicat_bonus_boot' }) })).json();
        return boot.rewards.tiers.mini.token;
      });
      ok(token, 'no claim token');
      await p.click('#dbf-claim-mini');
      await p.locator('#dbf-dialog[open]').waitFor({ timeout: 15000 });
      await p.locator('canvas.dbf-confetti').waitFor({ state: 'attached', timeout: 3000 });
      ok(await p.locator('#dbf-win').isVisible(), 'no win badge');
      ok((await p.locator('#dbf-dialog-title').textContent()).startsWith('Bravo'), 'title: ' + await p.locator('#dbf-dialog-title').textContent());
      await p.locator('#dbf-pins input').first().waitFor({ timeout: 15000 });
      ok(/^TEST-/.test(await p.locator('#dbf-pins input').first().inputValue()), 'no PIN');
      await p.screenshot({ path: `${out}/e2e-claim.png` });
      await p.click('#dbf-dialog-close');
      await p.locator('#dbf-history .dbf-pin-item').first().waitFor({ timeout: 10000 });
      ok(!(await p.locator('.dbf-tier.is-ready').count()), 'Mini still claimable after the claim');
      ok(php('rewards-db.php', 'reward-orders', 'client1') === '1', 'reward orders: ' + php('rewards-db.php', 'reward-orders', 'client1'));
    });
    await step('security: the same claim token sent again issues nothing new', async () => {
      const r = await p.evaluate(async (tok) => {
        const boot = await (await fetch('/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'delicat_bonus_boot' }) })).json();
        const res = await fetch('/wp-json/delicat/v1/claim', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce }, body: JSON.stringify({ tier: 'mini', token: tok }) });
        return { status: res.status };
      }, token);
      ok(php('rewards-db.php', 'reward-orders', 'client1') === '1', 'a second reward order was created (status ' + r.status + ')');
      ok(!p.errors.length, p.errors.join(' | '));
    });
    await step('instant navigation: the page starts after a switch without reload, twice', async () => {
      // Builder's shell swaps the content between its own pages without reloading; each new copy of the page
      // must start (bonus.js is already loaded the second time). Both pages below are Builder pages.
      const hop = async (href) => {
        await p.evaluate((h) => { const old = document.getElementById('dbf'); if (old) old.__old = 1; const a = document.createElement('a'); a.href = h; a.id = 'hop'; a.textContent = 'hop'; document.getElementById('dbf').appendChild(a); }, href);
        await p.click('#hop');
        await p.waitForFunction(() => { const r = document.getElementById('dbf'); return r && !r.__old && r.dataset.ready === '1' && r.querySelector('#dbf-board .dbf-pod'); }, null, { timeout: 15000 });
      };
      await p.goto(base + '/meilleur-client/', { waitUntil: 'load' });
      await loaded(p);
      await p.evaluate(() => { window.__noReload = 1; });
      await hop(base + PAGE);
      await hop(base + '/meilleur-client/');
      await hop(base + PAGE);
      ok(await p.evaluate(() => window.__noReload === 1), 'a full reload happened (instant navigation not exercised)');
      ok(await p.evaluate(() => document.querySelectorAll('#dbf').length === 1), 'more than one page component');
      ok(!p.errors.length, p.errors.join(' | '));
    });
    await step('old shortcode: [delicat_meilleur_client] opens the same page on Classement', async () => {
      await p.goto(base + '/meilleur-client/', { waitUntil: 'load' });
      await loaded(p);
      ok(await p.locator('#dbf-tab-classement').getAttribute('aria-selected') === 'true', 'wrong tab');
      ok(await p.locator('#dbf-tab-bonus').count() === 1, 'no tab bar');
    });
    await ctx.close();
  }

  /* ------------------------------------------------------------ looks */
  {
    const { ctx, p } = await context(b, 'client2', { dark: true });
    await step('dark mode: both tabs render without errors', async () => {
      await p.goto(base + PAGE, { waitUntil: 'load' });
      await loaded(p);
      await p.waitForTimeout(600);
      await p.screenshot({ path: `${out}/e2e-dark-classement.png` });
      await p.click('#dbf-tab-bonus');
      await p.locator('#dbf-tiers[aria-busy="false"]').waitFor({ timeout: 10000 });
      await p.waitForTimeout(3200);
      await p.screenshot({ path: `${out}/e2e-dark-bonus.png` });
      ok(!p.errors.length, p.errors.join(' | '));
    });
    await ctx.close();
    const s = await context(b, 'client3', { still: true });
    await step('reduced motion: no confetti, the page still works', async () => {
      await s.p.goto(base + PAGE + '#mes-bonus', { waitUntil: 'load' });
      await loaded(s.p);
      await s.p.waitForTimeout(800);
      ok(!(await s.p.locator('canvas.dbf-confetti').count()), 'confetti with reduced motion');
    });
    await s.ctx.close();
  }

  await b.close();
  const failed = results.filter((r) => r[0] === 'FAIL');
  console.log(`\n${results.length - failed.length}/${results.length} passed`);
  process.exit(failed.length ? 1 : 0);
})();
