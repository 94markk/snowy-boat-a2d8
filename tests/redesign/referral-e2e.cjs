// node referral-e2e.cjs <base-url> <site-dir> <out-dir> — STAGING ONLY. « Parrainage » (Delicat Rewards 3.4):
// personal code + link + share buttons, a friend's visit, sign-up, first order, point, milestone claims (HTG and PIN),
// and the fraud rules (same phone, same network, VPN, shared phone number, e-mail alias, wallet-paid order, refund).
// Each browser context plays one phone: its own cookies, and its own network address (X-Staging-IP, staging fixture).
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const [base, site, out] = process.argv.slice(2);
const here = __dirname;
const php = (script, ...args) => execFileSync('php', [path.join(here, script), site, ...args], { encoding: 'utf8' }).replace(/^.*Deprecated.*$/gm, '').trim();
const ref = (...args) => JSON.parse(php('referral-setup.php', ...args).split('\n').pop());
const results = [];
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); }
  catch (e) { results.push(['FAIL', name]); console.log('FAIL', name, '—', String(e && e.message || e).slice(0, 900)); }
}
const ok = (cond, msg) => { if (!cond) throw new Error(msg); };
const PAGE = '/bonus-free-fire/';
async function phone(b, ip, opts = {}) {
  const ctx = await b.newContext({ ...devices['Pixel 7'], ...(opts.viewport ? { viewport: opts.viewport } : {}), colorScheme: opts.dark ? 'dark' : 'light', ignoreHTTPSErrors: true, extraHTTPHeaders: { 'X-Staging-IP': ip } });
  await ctx.addInitScript(() => {
    document.addEventListener('DOMContentLoaded', () => {
      const s = document.createElement('style'); s.textContent = '.dlc-review-overlay{display:none!important}'; document.head.appendChild(s);
      const drop = () => { const a = document.getElementById('dip-age'); if (a) { if (a.open && a.close) a.close(); a.remove(); } };
      drop(); new MutationObserver(drop).observe(document.documentElement, { childList: true, subtree: true });
    });
  });
  if (opts.cookies) await ctx.addCookies(opts.cookies);
  const p = await ctx.newPage();
  p.errors = [];
  p.on('pageerror', (e) => p.errors.push(String(e)));
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|ERR_TUNNEL|gravatar/.test(m.text())) p.errors.push(m.text()); });
  return { ctx, p };
}
async function login(p, who) {
  await p.goto(base + '/wp-login.php');
  await p.fill('#user_login', who);
  await p.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
}
const refReady = (p) => p.waitForFunction(() => { const b = document.getElementById('dbf-ref-body'); return b && b.getAttribute('aria-busy') === 'false' && b.children.length > 0; }, null, { timeout: 15000 });
// A fresh load each time: going to the address already shown, with only a #hash, does not reload the page.
let loads = 0;
async function openRef(p) { await p.goto(base + PAGE + '?t=' + (++loads) + '#parrainage', { waitUntil: 'load' }); await refReady(p); }
// A sign-up request from this phone (the staging fixture creates the account with the browser's cookies, like a form).
async function signUp(p, login, extra = {}) {
  const r = await p.evaluate(async ([u, body]) => {
    const res = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString() });
    return res.json();
  }, [base + '/wp-admin/admin-ajax.php', { action: 'staging_ref_signup', login, ...extra }]);
  if (!r.ok) throw new Error('sign-up failed: ' + JSON.stringify(r));
  return r.id;
}
// REST call from the page with a fresh nonce (as the page itself does).
async function rest(p, route, method, body) {
  return p.evaluate(async ([b, route, method, body]) => {
    const boot = await (await fetch(b + '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=delicat_bonus_boot' })).json();
    const res = await fetch(b + '/wp-json/delicat/v1' + route, { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': boot.nonce || '', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
    return { status: res.status, json: await res.json().catch(() => null), nonce: boot.nonce };
  }, [base, route, method, body || null]);
}
async function shot(p, name) { if (out) await p.screenshot({ path: path.join(out, name + '.png'), fullPage: true }); }

(async () => {
  if (out) fs.mkdirSync(out, { recursive: true });
  php('rewards-setup.php');
  const setup = ref('setup');
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  let link = '', code = '';

  /* ------------------------------------------------------------ the inviter */
  const A = await phone(b, '10.1.0.1');
  await A.ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: base });
  await login(A.p, 'ref1');
  await step('inviter: three tabs, Parrainage opens from #parrainage', async () => {
    await openRef(A.p);
    const tabs = await A.p.$$eval('[role="tab"]', (t) => t.map((x) => [x.id, x.getAttribute('aria-selected')]));
    ok(tabs.length === 3, 'tabs: ' + JSON.stringify(tabs));
    ok(tabs.find((t) => t[0] === 'dbf-tab-parrainage')[1] === 'true', 'Parrainage not selected');
    ok(!(await A.p.isHidden('#dbf-panel-parrainage')), 'panel hidden');
  });
  await step('inviter: personal code, link and share buttons', async () => {
    code = (await A.p.textContent('.dbf-code b')).trim();
    ok(/^DLC[A-HJ-NP-Z2-9]{6}$/.test(code), 'code ' + code);
    link = await A.p.inputValue('#dbf-ref-link');
    ok(link.startsWith(base + PAGE) && link.includes('ref=' + code) && link.endsWith('#parrainage'), 'link ' + link);
    const hrefs = await A.p.$$eval('.dbf-sh[href]', (a) => a.map((x) => [x.className, x.getAttribute('href'), x.target, x.rel]));
    const wa = hrefs.find((h) => /whatsapp/.test(h[0])), fb = hrefs.find((h) => /facebook/.test(h[0])), tg = hrefs.find((h) => /telegram/.test(h[0])), sms = hrefs.find((h) => /sms/.test(h[0]));
    ok(wa && wa[1].startsWith('https://wa.me/?text=') && decodeURIComponent(wa[1]).includes(link) && wa[2] === '_blank' && /noopener/.test(wa[3]), 'whatsapp ' + JSON.stringify(wa));
    ok(fb && decodeURIComponent(fb[1]) === 'https://www.facebook.com/sharer/sharer.php?u=' + link, 'facebook ' + JSON.stringify(fb));
    ok(tg && decodeURIComponent(tg[1]).includes('url=' + link), 'telegram');
    ok(sms && sms[1].startsWith('sms:?&body=') && decodeURIComponent(sms[1]).includes(code), 'sms');
    const miles = await A.p.$$eval('.dbf-mile', (m) => m.map((x) => x.className));
    ok(miles.length === 4 && miles.every((c) => /is-locked/.test(c)), 'milestones ' + JSON.stringify(miles));
    ok(/Personne pour l’instant/.test(await A.p.textContent('.dbf-ref-friends')), 'friends empty text');
    ok((await A.p.textContent('#dbf-ref-points')).trim() === '0', 'points');
    await shot(A.p, 'ref-inviter-light');
  });
  await step('inviter: « Copier le lien » copies the personal link', async () => {
    await A.p.click('#dbf-ref-copy-link');
    await A.p.waitForFunction(() => /Copié/.test(document.getElementById('dbf-ref-copy-link').textContent));
    ok((await A.p.evaluate(() => navigator.clipboard.readText())) === link, 'clipboard');
  });

  /* ------------------------------------------------------------ friend 1: link → sign-up → first order → point */
  const B = await phone(b, '10.2.0.2');
  await step('friend (signed out): the link shows the invitation and remembers the inviter', async () => {
    await B.p.goto(link, { waitUntil: 'load' });
    await B.p.waitForSelector('.dbf-invite', { timeout: 15000 });
    ok(/Ralph/.test(await B.p.textContent('.dbf-invite h2')), 'inviter name');
    ok(!/Dorval/.test(await B.p.textContent('.dbf-invite')), 'full surname shown');
    ok(!new URL(B.p.url()).searchParams.has('ref'), 'ref stays in the address bar');
    const c = (await B.ctx.cookies()).find((x) => x.name === 'dlc_ref');
    ok(c && c.httpOnly && c.value.startsWith(code + '.'), 'dlc_ref cookie ' + JSON.stringify(c));
    ok(await B.p.isVisible('.dbf-invite a[data-dl-open-register]'), 'create-account button');
    await shot(B.p, 'ref-invite-guest');
  });
  await step('friend signs up: invitation recorded from the link', async () => {
    await signUp(B.p, 'rf1', { first: 'Nadia', phone: '+509 3711 2222' });
    const s = ref('state', 'rf1');
    ok(s.row && s.row.status === 'signed_up' && s.row.source === 'link' && s.row.referrer === setup.ref1, JSON.stringify(s.row));
    ok(s.signals.some((x) => x.startsWith('device:')) && s.signals.some((x) => x.startsWith('ip:')), 'signals ' + JSON.stringify(s.signals));
    ok(!s.signals.some((x) => /10\.2\.0\.2/.test(x)), 'raw address stored');
  });
  await step('friend: « invité par » note on their own Parrainage tab', async () => {
    await openRef(B.p);
    ok(/invité par Ralph/.test(await B.p.textContent('.dbf-ref-note')), 'note');
    ok(await B.p.isVisible('.dbf-share'), 'friend has their own share card');
    ok(!(await B.p.$('.dbf-codeform')), 'code form shown to an invited friend');
  });
  await step('a first order paid with store credit does not count', async () => {
    ref('order', 'rf1', '150', 'wallet');
    const s = ref('state', 'rf1');
    ok(s.row.status === 'signed_up' && !s.row.order, JSON.stringify(s.row));
  });
  await step('a real first order: point, milestone 1 ready, celebration', async () => {
    ref('order', 'rf1', '150', 'bacs', '+509 3711 2222');
    ok(ref('state', 'rf1').row.status === 'qualified', 'not qualified');
    await openRef(A.p);
    ok((await A.p.textContent('#dbf-ref-points')).trim() === '1', 'points ' + await A.p.textContent('#dbf-ref-points'));
    ok(/is-claimable/.test(await A.p.getAttribute('.dbf-mile:nth-child(1)', 'class')), 'milestone 1 not claimable');
    await A.p.waitForFunction(() => { const t = document.getElementById('dbf-toast'); return t && !t.hidden && /Palier 1/.test(t.textContent); }, null, { timeout: 5000 });
    ok(/verified|Point gagné/.test(await A.p.textContent('.dbf-friend')), 'friend chip');
    ok(ref('state', 'rf1').row.status === 'confirmed', 'row not confirmed');
  });
  await step('claim milestone 1 in HTG: confirm step, « Bravo », wallet credited once', async () => {
    const before = ref('state', 'ref1').wallet;
    await A.p.click('#dbf-ref-claim-1-htg');
    ok(/Confirmer/.test(await A.p.textContent('#dbf-ref-claim-1-htg')), 'no confirm step');
    await A.p.click('#dbf-ref-claim-1-htg');
    await A.p.waitForSelector('#dbf-dialog[open]', { timeout: 15000 });
    ok(/140 HTG/.test(await A.p.textContent('#dbf-dialog-title')), 'dialog ' + await A.p.textContent('#dbf-dialog-title'));
    const s = ref('state', 'ref1');
    ok(Math.round(s.wallet - before) === 140, 'wallet ' + before + ' → ' + s.wallet);
    ok(s.claims.length === 1 && s.claims[0].choice === 'htg' && s.claims[0].status === 'done', JSON.stringify(s.claims));
    await A.p.click('#dbf-dialog-close');
    ok(/is-claimed/.test(await A.p.getAttribute('.dbf-mile:nth-child(1)', 'class')), 'card not claimed');
  });
  await step('the same milestone cannot be claimed twice (409), nor one not reached (400)', async () => {
    const again = await rest(A.p, '/referral/claim', 'POST', { milestone: 1, choice: 'diamonds' });
    ok(again.status === 409, 'again ' + JSON.stringify(again));
    const early = await rest(A.p, '/referral/claim', 'POST', { milestone: 4, choice: 'htg' });
    ok(early.status === 400, 'early ' + JSON.stringify(early));
    const bad = await rest(A.p, '/referral/claim', 'POST', { milestone: 3, choice: 'htg' });
    ok(bad.status === 400, 'unknown milestone ' + JSON.stringify(bad));
    ok(ref('state', 'ref1').claims.length === 1, 'extra claim stored');
  });

  /* ------------------------------------------------------------ friend 2 → milestone 2 as a PIN */
  const C = await phone(b, '10.3.0.3');
  await step('second friend → milestone 2 claimed as diamonds: PIN dialog', async () => {
    await C.p.goto(link, { waitUntil: 'load' }); await C.p.waitForSelector('.dbf-invite', { timeout: 15000 });
    await signUp(C.p, 'rf2', { first: 'Kevin', phone: '+509 3722 3333' });
    ref('order', 'rf2', '200', 'bacs', '+509 3722 3333');
    await openRef(A.p);
    ok((await A.p.textContent('#dbf-ref-points')).trim() === '2', 'points');
    await A.p.click('#dbf-ref-claim-2-diamonds'); await A.p.click('#dbf-ref-claim-2-diamonds');
    await A.p.waitForSelector('#dbf-dialog[open]', { timeout: 15000 });
    await A.p.waitForFunction(() => { const i = document.querySelector('#dbf-pins input'); return i && /^TEST-/.test(i.value); }, null, { timeout: 20000 });
    ok(/341/.test(await A.p.textContent('#dbf-dialog-title')), 'title');
    await A.p.click('#dbf-dialog-close');
    ok(await A.p.isVisible('#dbf-ref-pin-2'), '« Voir mon PIN » on the card');
    const s = ref('state', 'ref1');
    ok(s.claims.some((c) => +c.milestone === 2 && c.choice === 'diamonds' && +c.ref_id > 0), JSON.stringify(s.claims));
  });
  await step('« Voir mon PIN » reopens the PIN later', async () => {
    await A.p.click('#dbf-ref-pin-2');
    await A.p.waitForFunction(() => { const i = document.querySelector('#dbf-pins input'); return i && /^TEST-/.test(i.value); }, null, { timeout: 15000 });
    await A.p.click('#dbf-dialog-close');
  });
  await step('refund after the point: the point is taken back, the reward already given stays', async () => {
    ref('refund', 'rf2');
    const s = ref('state', 'rf2');
    ok(s.row.status === 'rejected' && s.row.reason === 'refunded_after', JSON.stringify(s.row));
    ok(ref('state', 'ref1').points === 1, 'points');
  });

  /* ------------------------------------------------------------ fraud rules */
  const F = await phone(b, '10.9.9.9', { cookies: [{ name: 'staging_vpn', value: '1', url: base }] });
  await step('VPN: the invitation is refused and not remembered', async () => {
    await F.p.goto(link, { waitUntil: 'load' });
    await F.p.waitForFunction(() => { const n = document.getElementById('dbf-notice'); return n && !n.hidden && /VPN/.test(n.textContent); }, null, { timeout: 15000 });
    ok(!(await F.ctx.cookies()).some((c) => c.name === 'dlc_ref'), 'dlc_ref set behind a VPN');
    ok(!(await F.p.$('#dbf-ref-body .dbf-invite')), 'invitation shown');
  });
  const G = await phone(b, '10.4.0.4');
  await step('same payment phone number as another account: no point', async () => {
    await G.p.goto(link, { waitUntil: 'load' }); await G.p.waitForSelector('.dbf-invite', { timeout: 15000 });
    await signUp(G.p, 'rf5', { first: 'Marc', phone: '+50937112222' });
    ref('order', 'rf5', '150', 'bacs', '37112222');
    const s = ref('state', 'rf5');
    ok(s.row.status === 'rejected' && /phone_shared/.test(s.row.reason), JSON.stringify(s.row));
  });
  const H = await phone(b, '10.5.0.5');
  await step('e-mail alias of another account (+tag): refused at sign-up', async () => {
    await H.p.goto(link, { waitUntil: 'load' }); await H.p.waitForSelector('.dbf-invite', { timeout: 15000 });
    await signUp(H.p, 'rf6', { first: 'Lina', email: 'rf1+promo@example.test' });
    const s = ref('state', 'rf6');
    ok(s.row.status === 'rejected' && /email_alias/.test(s.row.reason), JSON.stringify(s.row));
  });
  const E = await phone(b, '10.1.0.1');
  await step('same network as the inviter (new phone): sent to review, no point yet', async () => {
    await E.p.goto(link, { waitUntil: 'load' }); await E.p.waitForSelector('.dbf-invite', { timeout: 15000 });
    await signUp(E.p, 'rf4', { first: 'Rose', phone: '+509 3744 5555' });
    const s = ref('state', 'rf4');
    ok(s.row.status === 'review' && /same_(browser_)?network/.test(s.row.reason), JSON.stringify(s.row));
    ok(!s.refBlock, 'blocked for a network match');
  });
  const I = await phone(b, '10.6.0.6');
  await step('code entry by a new account (no link): bad codes refused, the right one accepted', async () => {
    await I.p.goto(base + '/', { waitUntil: 'load' });
    await signUp(I.p, 'rf7', { first: 'Paul', phone: '+509 3766 7777' });
    await openRef(I.p);
    ok(await I.p.isVisible('.dbf-codeform'), 'no code form');
    await I.p.fill('#dbf-ref-code-in', 'DLC000000'); await I.p.click('#dbf-ref-code-btn');
    ok(/commence par DLC/.test(await I.p.textContent('#dbf-ref-code-err')), 'format message');
    await I.p.fill('#dbf-ref-code-in', 'DLCZZZZZZ'); await I.p.click('#dbf-ref-code-btn');
    await I.p.waitForFunction(() => /introuvable/.test(document.getElementById('dbf-ref-code-err').textContent), null, { timeout: 10000 });
    await I.p.fill('#dbf-ref-code-in', code.toLowerCase().replace(/(...)/, '$1 ')); await I.p.click('#dbf-ref-code-btn');
    await I.p.waitForSelector('.dbf-ref-note', { timeout: 10000 });
    const s = ref('state', 'rf7');
    ok(s.row && s.row.source === 'code' && s.row.status === 'signed_up', JSON.stringify(s.row));
  });
  await step('inviter sees each friend with a status (no detection details)', async () => {
    await openRef(A.p);
    const chips = await A.p.$$eval('.dbf-friend .dbf-st', (c) => c.map((x) => x.className.replace('dbf-st dbf-st--', '')));
    ok(chips.length === 6, 'friends ' + JSON.stringify(chips));
    ok(chips.includes('review') && chips.includes('rejected') && chips.includes('confirmed') && chips.includes('signed_up'), JSON.stringify(chips));
    const txt = await A.p.textContent('.dbf-ref-friends');
    ok(!/phone_shared|email_alias|same_network|VPN|téléphone/.test(txt), 'detection details shown: ' + txt.slice(0, 300));
  });
  await step('same phone as the inviter: refused, both accounts out of the rewards programme', async () => {
    const dev = (await A.ctx.cookies()).filter((c) => c.name === 'dip_device_id' || c.name === 'dlc_did');
    ok(dev.length, 'inviter has no device cookie');
    const D = await phone(b, '10.7.0.7', { cookies: dev });
    await D.p.goto(link, { waitUntil: 'load' }); await D.p.waitForSelector('.dbf-invite', { timeout: 15000 });
    await signUp(D.p, 'rf3', { first: 'Jo', phone: '+509 3733 4444' });
    const f = ref('state', 'rf3'), r = ref('state', 'ref1');
    ok(f.row.status === 'rejected' && /same_device/.test(f.row.reason), JSON.stringify(f.row));
    ok(f.refBlock && f.excluded && r.refBlock && r.excluded, 'not blocked: ' + JSON.stringify([f.refBlock, f.excluded, r.refBlock, r.excluded]));
    await openRef(A.p);
    ok(await A.p.isVisible('.dbf-ref-blocked'), 'no blocked card');
    ok(!(await A.p.$('.dbf-share')), 'share card still shown');
    const claim = await rest(A.p, '/referral/claim', 'POST', { milestone: 1, choice: 'htg' });
    ok(claim.status === 403, 'blocked claim ' + JSON.stringify(claim));
    await D.ctx.close();
    ref('unblock', 'ref1');
  });

  /* ------------------------------------------------------------ limits, transport, guests, looks */
  await step('points stop at 100', async () => {
    ok(ref('seed', 'ref1', '150').points === 100, 'cap');
  });
  await step('the AJAX fallback carries /referral', async () => {
    const r = await A.p.evaluate(async (b) => {
      const boot = await (await fetch(b + '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=delicat_bonus_boot' })).json();
      const res = await fetch(b + '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'delicat_rewards_transport', nonce: boot.nonce, path: '/referral', method: 'GET', params: '{}' }).toString() });
      return { status: res.status, json: await res.json().catch(() => null) };
    }, base);
    ok(r.status === 200 && r.json && r.json.code === code && r.json.points === 100, JSON.stringify(r).slice(0, 300));
  });
  await step('signed out: /referral answers 401, the page shows the sign-in card', async () => {
    const G2 = await phone(b, '10.8.0.8');
    const r = await G2.p.request.get(base + '/wp-json/delicat/v1/referral');
    ok(r.status() === 401, 'status ' + r.status());
    await openRef(G2.p);
    ok(await G2.p.isVisible('#dbf-ref-body .dbf-login'), 'no sign-in card');
    await G2.ctx.close();
  });
  await step('a long friend list shows the latest 8, the rest on request', async () => {
    await openRef(A.p);
    const n = await A.p.$$eval('.dbf-friend', (l) => [l.length, l.filter((x) => !x.hidden).length]);
    ok(n[0] > 8 && n[1] === 8, 'friends ' + JSON.stringify(n));
    await A.p.click('#dbf-ref-more');
    ok((await A.p.$$eval('.dbf-friend', (l) => l.filter((x) => !x.hidden).length)) === n[0], 'not all shown');
    ok(await A.p.evaluate(() => document.activeElement.classList.contains('dbf-friend')), 'focus not moved to the 9th friend');
  });
  await step('dark mode at 360 px: three tabs fit, no horizontal scroll', async () => {
    const K = await phone(b, '10.1.0.1', { dark: true, viewport: { width: 360, height: 780 }, cookies: (await A.ctx.cookies()) });
    await K.p.evaluate(() => document.documentElement.classList.add('dlc-theme-dark'));
    await openRef(K.p);
    await K.p.evaluate(() => document.documentElement.classList.add('dlc-theme-dark'));
    const m = await K.p.evaluate(() => ({ page: document.documentElement.scrollWidth, vw: innerWidth, tabs: [...document.querySelectorAll('.dbf-tab span')].map((s) => s.scrollWidth - s.clientWidth) }));
    ok(m.page <= m.vw && m.tabs.every((x) => x <= 0), JSON.stringify(m));
    if (out) await K.p.screenshot({ path: path.join(out, 'ref-inviter-dark-360.png') });
    await K.p.evaluate(() => document.querySelector('.dbf-ref-miles').scrollIntoView());
    if (out) await K.p.screenshot({ path: path.join(out, 'ref-inviter-dark-360-miles.png') });
    ok(!K.p.errors.length, K.p.errors.join(' | '));
    await K.ctx.close();
  });
  await step('no script errors', async () => {
    const all = [A, B, C, E, F, G, H, I].flatMap((x) => x.p.errors);
    ok(!all.length, all.join(' | ').slice(0, 800));
  });

  await b.close();
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
