// node login-button.cjs <base> <site-dir> <old-auth-shell.js> — STAGING ONLY. Identity 6.17.7.
// « Se connecter » (side menu, bottom bar) did nothing for many returning customers: LiteSpeed's « Remove Query
// Strings » printed auth-shell.js without ?ver=, served immutable for 30 days, so phones kept the pre-6.17.5 shell,
// which cannot load the sign-in window 6.17.5 no longer prints in the page — and its presence switched the page's
// fallback off. Checks, on a phone (Pixel 7 emulation), with LiteSpeed's query-string removal simulated:
//  1. the page still asks for auth-shell.js?ver=<version> (re-added after LiteSpeed);
//  2. a fresh phone: the side-menu « Se connecter » opens the sign-in window with its form (the Google button too
//     where Google is configured; staging has no Google client);
//  3. a phone running the old cached shell: the tap goes to the My Account sign-in page instead of nothing.
const fs = require('fs');
const path = require('path');
const { chromium, devices, request } = require('playwright');
const [base = 'https://localhost:8943', site, oldShell] = process.argv.slice(2);
if (!/^https?:\/\/(localhost|127\.0\.0\.1)[:/]/.test(base + '/')) { console.error('refusing: not a staging address'); process.exit(3); }
if (!site || !fs.existsSync(path.join(site, 'wp-load.php')) || !oldShell || !fs.existsSync(oldShell)) { console.error('usage: node login-button.cjs <base> <site-dir> <old-auth-shell.js>'); process.exit(2); }
const mu = path.join(site, 'wp-content/mu-plugins/dip-test-litespeed-qs.php');
const results = [];
const check = (name, ok, detail = '') => { results.push(!!ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`); };

// What LiteSpeed « Remove Query Strings » does: drops the query string of .js/.css at priority 999.
const LITESPEED_STAND_IN = [
  '<?php',
  '// STAGING TEST ONLY (tests/identity-pro/login-button.cjs): LiteSpeed « Remove Query Strings ».',
  "foreach (array('script_loader_src', 'style_loader_src') as $dip_test_hook) {",
  '    add_filter($dip_test_hook, static function ($src) {',
  "        return is_string($src) && preg_match('#\\.(js|css)\\?#', $src) ? preg_replace('#\\?.*$#', '', $src) : $src;",
  '    }, 999);',
  '}',
  '',
].join('\n');

async function tapSignIn(browser, staleShell) {
  const ctx = await browser.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  if (staleShell) {
    const body = fs.readFileSync(oldShell, 'utf8');
    await page.route(/\/delicat-identity-pro\/assets\/auth-shell\.js/, (route) => route.fulfill({ status: 200, contentType: 'application/javascript', body }));
  }
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.waitForTimeout(1500);
  const later = page.getByRole('button', { name: /Plus tard/i }).first();
  if (await later.isVisible().catch(() => false)) { await later.tap(); await page.waitForTimeout(500); }
  const menu = page.locator('[data-dsb8-menu-trigger]').first();
  if (await menu.isVisible().catch(() => false)) { await menu.tap(); await page.waitForTimeout(800); }
  let trigger = page.locator('.dlx-account__login').first();
  if (!(await trigger.isVisible().catch(() => false))) trigger = page.locator('[data-dip-auth-open]:visible').first();
  const where = await trigger.evaluate((el) => el.className || el.tagName).catch(() => 'none');
  const navigated = page.waitForURL(/\/my-account\//, { timeout: 8000 }).then(() => true).catch(() => false);
  await trigger.tap();
  const moved = staleShell ? await navigated : false;
  if (!staleShell) await page.waitForTimeout(7000);
  const state = await page.evaluate(() => {
    const shell = document.getElementById('dip-auth-shell');
    const frame = shell && shell.querySelector('iframe');
    let text = '';
    try { text = frame && frame.contentDocument ? frame.contentDocument.body.innerText : ''; } catch (e) { text = ''; }
    const inline = document.getElementById('dip-identity-modal');
    return {
      url: location.href,
      open: !!(shell && (shell.open || shell.hasAttribute('open'))),
      form: /Connectez-vous pour continuer/i.test(text + ' ' + (inline ? inline.innerText || '' : '')) && /E-mail et mot de passe|Continuer avec Google/i.test(text + ' ' + (inline ? inline.innerText || '' : '')),
      protocol: window.DIPAuthShellProtocol || 0,
    };
  }).catch((e) => ({ url: page.url(), error: String(e) }));
  await ctx.close();
  return { where, moved, ...state };
}

(async () => {
  fs.mkdirSync(path.dirname(mu), { recursive: true });
  fs.writeFileSync(mu, LITESPEED_STAND_IN);
  const browser = await chromium.launch();
  try {
    const api = await request.newContext({ ignoreHTTPSErrors: true });
    const html = await (await api.get(base + '/?dip_lb=' + Date.now())).text();
    await api.dispose();
    const shellSrc = ((html.match(/<script[^>]+id=["']dip-auth-shell-js["'][^>]*>/) || [''])[0].match(/src=["']([^"']+)["']/) || [])[1] || '';
    const other = (html.match(/<script[^>]+src=["']([^"']*\/plugins\/woocommerce\/[^"']+\.js[^"']*)["']/) || [])[1] || '';
    check('stand-in for LiteSpeed active (a WooCommerce script lost its ?ver)', other !== '' && !other.includes('?'), other || 'no WooCommerce script on the page');
    check('auth-shell.js still carries its version', /auth-shell\.js\?ver=\d/.test(shellSrc), shellSrc || 'auth-shell.js not on the page');

    const fresh = await tapSignIn(browser, false);
    check('fresh phone: side-menu « Se connecter » opens the sign-in window with its form', fresh.open && fresh.form, JSON.stringify(fresh));
    const stale = await tapSignIn(browser, true);
    check('phone with the old cached shell: the tap opens the My Account sign-in page (no dead button)', stale.moved || /\/my-account\//.test(stale.url), JSON.stringify(stale));
  } finally {
    await browser.close();
    fs.rmSync(mu, { force: true });
  }
  const passed = results.filter(Boolean).length;
  console.log(`\n${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})();
