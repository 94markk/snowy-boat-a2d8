#!/usr/bin/env node
/**
 * Delicat Identity Pro — passkeys end-to-end suite (Playwright + CDP virtual authenticator).
 *
 *   node passkeys-e2e.mjs <baseUrl> <siteDir> [options]
 *
 * <baseUrl>  e.g. http://localhost:8190 (must be WP_HOME of the site)
 * <siteDir>  the WordPress root of that site (wp-load.php, debug.log, wp-content/test-mail/)
 * options    --list                 print the scenario ids and exit
 *            --only=A01,C*          run only these ids (a trailing * is a prefix match)
 *            --skip=I02             skip these ids (I02 waits for the 120 s WebAuthn timeout)
 *            --parallel-port=8191   also run the X* concurrency checks: the suite starts a
 *                                   multi-worker `php -S` on that free port for the same site
 *            --verbose              also print environment noise (missing core assets, gravatar)
 *            --headed               show the browser
 * Exit code: 0 all PASS (SKIP allowed), 1 any FAIL or new PHP notice, 2 bad arguments.
 *
 * Fixtures are created through fixtures.php (same directory). Every scenario prints
 * PASS / FAIL with the reason; the run fails (exit 1) on any FAIL, on plugin-related
 * browser console/page errors and on new PHP notices/warnings in <siteDir>/debug.log.
 * Requires a test site where passkeys are allowed on http://localhost (mu-plugin filter
 * dip_passkeys_allow_insecure_localhost) and outgoing mail is captured to wp-content/test-mail.
 *
 * Passkey autofill (conditional mediation): Chromium's CDP virtual authenticator answers a
 * conditional request at once with its first discoverable credential, i.e. as if the customer
 * had picked it in the autofill list. Sessions therefore report isConditionalMediationAvailable()
 * as false unless opened with { autofill: true } (D04-D06), so the button flows stay deterministic.
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import crypto from 'crypto';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

// ---------------------------------------------------------------- arguments
const argv = process.argv.slice(2);
const positional = argv.filter(a => !a.startsWith('--'));
const flags = Object.fromEntries(argv.filter(a => a.startsWith('--')).map(a => { const [k, v] = a.replace(/^--/, '').split('='); return [k, v === undefined ? true : v]; }));
const BASE = (positional[0] || '').replace(/\/$/, '');
const SITE = positional[1] || '';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const FIXTURES = path.join(HERE, 'fixtures.php');
const VERBOSE = !!flags.verbose;
if (!flags.list && (!/^https?:\/\//.test(BASE) || !SITE || !fs.existsSync(path.join(SITE, 'wp-load.php')))) {
  console.error('usage: node passkeys-e2e.mjs <baseUrl> <siteDir> [--list] [--only=ID,..] [--skip=ID,..] [--parallel-port=N] [--verbose] [--headed]');
  process.exit(2);
}
const ORIGIN = BASE ? new URL(BASE).origin : '';
const REST = BASE + '/wp-json/delicat-identity/v1';
const TMP = fs.mkdtempSync(path.join(process.env.DIP_E2E_TMP || os.tmpdir(), 'dip-pk-e2e-'));
process.on('exit', () => { try { fs.rmSync(TMP, { recursive: true, force: true }); } catch {} });

// ---------------------------------------------------------------- helpers
const b64u = buf => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const fromB64u = s => Buffer.from(String(s).replace(/-/g, '+').replace(/_/g, '/'), 'base64');
const sha256 = b => crypto.createHash('sha256').update(b).digest();
const sleep = ms => new Promise(r => setTimeout(r, ms));

function fx(cmd, ...args) {
  let out;
  try { out = execFileSync('php', [FIXTURES, SITE, BASE, cmd, ...args.map(String)], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); }
  catch (e) { out = String(e.stdout || ''); if (!out.includes('{')) throw new Error(`fixtures ${cmd} failed: ${e.stderr || e.message}`); }
  const json = JSON.parse(out.slice(out.indexOf('{')));
  if (json.error) throw new Error(`fixtures ${cmd}: ${json.error}`);
  return json;
}

/** Software P-256 credential usable both server-side (PEM) and in the virtual authenticator (PKCS#8). */
function makeCredential() {
  const { privateKey, publicKey } = crypto.generateKeyPairSync('ec', { namedCurve: 'P-256' });
  const id = crypto.randomBytes(32);
  const pemFile = path.join(TMP, b64u(id) + '.pem');
  fs.writeFileSync(pemFile, publicKey.export({ type: 'spki', format: 'pem' }));
  return { id, idB64u: b64u(id), privateKey, pkcs8: privateKey.export({ type: 'pkcs8', format: 'der' }).toString('base64'), pemFile };
}
function seedPasskey(login, cred, label = 'E2E key', signCount = 0) { return fx('add-passkey', login, cred.idB64u, cred.pemFile, label, signCount); }

/** Builds a WebAuthn assertion in Node (used for precise protocol-level negative tests). */
function signAssertion(cred, { challenge, origin = ORIGIN, rpId = 'localhost', type = 'webauthn.get', flags = 0x05, signCount = 1, crossOrigin = false, userHandle = '', id } = {}) {
  const clientData = Buffer.from(JSON.stringify({ type, challenge, origin, crossOrigin }));
  const count = Buffer.alloc(4); count.writeUInt32BE(signCount >>> 0);
  const authData = Buffer.concat([sha256(rpId), Buffer.from([flags]), count]);
  const signature = crypto.sign('sha256', Buffer.concat([authData, sha256(clientData)]), cred.privateKey);
  const credId = id || cred.idB64u;
  return { id: credId, rawId: credId, clientDataJSON: b64u(clientData), authenticatorData: b64u(authData), signature: b64u(signature), userHandle };
}

function readMissingCoreAssets() {
  const f = path.join(SITE, 'wordpress-remote-asset-paths');
  if (!fs.existsSync(f)) return new Set();
  return new Set(fs.readFileSync(f, 'utf8').split(/\r?\n/).filter(Boolean).map(p => '/' + p.replace(/^\//, '')));
}
const MISSING_CORE = BASE ? readMissingCoreAssets() : new Set();
function contentTypeFor(p) {
  if (p.endsWith('.js')) return 'application/javascript';
  if (p.endsWith('.css')) return 'text/css';
  if (p.endsWith('.svg')) return 'image/svg+xml';
  if (p.endsWith('.png')) return 'image/png';
  if (/\.woff2?$/.test(p)) return 'font/woff2';
  return 'application/octet-stream';
}

const DEBUG_LOG = SITE ? path.join(SITE, 'debug.log') : '';
function debugLogSize() { try { return fs.statSync(DEBUG_LOG).size; } catch { return 0; } }
function newPhpIssues(from) {
  let text = '';
  try { const fd = fs.openSync(DEBUG_LOG, 'r'); const size = fs.fstatSync(fd).size; if (size > from) { const buf = Buffer.alloc(size - from); fs.readSync(fd, buf, 0, buf.length, from); text = buf.toString('utf8'); } fs.closeSync(fd); } catch {}
  return text.split(/\r?\n/).filter(l => /PHP (Notice|Warning|Deprecated|Fatal error|Parse error|Recoverable)/.test(l))
    // Environment noise from the SQLite drop-in used by the test site, not the plugin.
    .filter(l => !/sqlite-database-integration/.test(l))
    // No network to WordPress.org in sandboxes: core update checks warn on every admin page view.
    .filter(l => !/wp_version_check\(\)|wp_update_plugins\(\)|wp_update_themes\(\)|could not establish a secure connection to WordPress\.org/.test(l));
}

// ---------------------------------------------------------------- browser plumbing
let browser;
const run = { contexts: [] };
const sessionsRec = []; // browser error records of every session opened by the current scenario

async function newSession({ authenticator = true, uv = true, url, autofill = false } = {}) {
  const ctx = await browser.newContext({ baseURL: BASE, ignoreHTTPSErrors: true });
  // Runs in every frame, the identity modal's about:blank frame included.
  await ctx.addInitScript(autofillOn => {
    try {
      if (!window.PublicKeyCredential) return;
      if (!autofillOn) Object.defineProperty(PublicKeyCredential, 'isConditionalMediationAvailable', { value: () => Promise.resolve(false), configurable: true });
      const top = (() => { try { return window.top.__dipSignals ? window.top : window; } catch { return window; } })();
      window.__dipSignals = top.__dipSignals || (top.__dipSignals = []);
      for (const name of ['signalUnknownCredential', 'signalAllAcceptedCredentials', 'signalCurrentUserDetails']) {
        const orig = PublicKeyCredential[name];
        if (typeof orig !== 'function') continue;
        Object.defineProperty(PublicKeyCredential, name, { configurable: true, value: function (opts) { window.__dipSignals.push({ name, opts: JSON.parse(JSON.stringify(opts || {})) }); return orig.call(this, opts); } });
      }
    } catch {}
  }, !!autofill);
  if (autofill === 'pending') {
    // A real browser keeps an autofill (conditional) request pending until the customer picks a
    // passkey; the virtual authenticator answers or refuses it at once. Emulate the real behaviour.
    await ctx.addInitScript(() => {
      try {
        const orig = navigator.credentials.get.bind(navigator.credentials);
        const log = k => { try { const v = JSON.parse(sessionStorage.getItem('__dipCond') || '{}'); v[k] = (v[k] || 0) + 1; sessionStorage.setItem('__dipCond', JSON.stringify(v)); } catch {} };
        navigator.credentials.get = function (opts) {
          if (!opts || opts.mediation !== 'conditional') return orig(opts);
          log('started');
          return new Promise((_, reject) => { if (opts.signal) opts.signal.addEventListener('abort', () => { log('aborted'); reject(new DOMException('aborted', 'AbortError')); }); });
        };
      } catch {}
    });
  }
  run.contexts.push(ctx);
  const page = await ctx.newPage();
  const rec = { console: [], pageErrors: [], requests: [], responses: [], asserted: 0, added: 0 };
  sessionsRec.push(rec);
  page.on('console', m => { if (m.type() === 'error') rec.console.push({ text: m.text(), url: (m.location() || {}).url || '' }); });
  page.on('pageerror', e => rec.pageErrors.push({ text: e.message, stack: e.stack || '' }));
  page.on('request', r => { if (r.url().includes('/wp-json/delicat-identity/')) rec.requests.push({ url: r.url(), method: r.method(), body: r.postData() || '' }); });
  page.on('response', r => { if (r.url().includes('/wp-json/delicat-identity/')) rec.responses.push({ url: r.url(), status: r.status() }); });
  page.on('dialog', d => d.accept().catch(() => {}));
  await ctx.route(/\/(wp-includes|wp-admin\/(css|js|images)|wp-content\/themes)\//, async route => {
    const p = new URL(route.request().url()).pathname;
    if (MISSING_CORE.has(p)) return route.fulfill({ status: 200, contentType: contentTypeFor(p), body: '' });
    return route.continue();
  });
  // Shim for core globals whose (missing) asset files were blanked above.
  await ctx.addInitScript(() => { window.wp = window.wp || {}; window.wp.i18n = window.wp.i18n || { setLocaleData() {}, __: s => s, _x: s => s, _n: s => s, sprintf: s => s }; });
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('WebAuthn.enable', { enableUI: false });
  cdp.on('WebAuthn.credentialAsserted', () => { rec.asserted++; });
  cdp.on('WebAuthn.credentialAdded', () => { rec.added++; });
  let authId = null;
  if (authenticator) authId = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: uv, automaticPresenceSimulation: true } })).authenticatorId;
  const s = {
    ctx, page, cdp, rec,
    get authId() { return authId; },
    async importCredential(cred, handleB64u, signCount = 0) {
      await cdp.send('WebAuthn.addCredential', { authenticatorId: authId, credential: { credentialId: cred.id.toString('base64'), isResidentCredential: true, rpId: 'localhost', privateKey: cred.pkcs8, userHandle: fromB64u(handleB64u).toString('base64'), signCount } });
    },
    async credentials() { return (await cdp.send('WebAuthn.getCredentials', { authenticatorId: authId })).credentials; },
    async setUV(v) { await cdp.send('WebAuthn.setUserVerified', { authenticatorId: authId, isUserVerified: v }); },
    async removeAuthenticator() { await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: authId }); authId = null; },
    async newAuthenticator() { if (authId) await this.removeAuthenticator(); authId = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId; },
    async loggedIn() { return (await ctx.cookies()).some(c => c.name.startsWith('wordpress_logged_in_')); },
    async signals() { return page.evaluate(() => window.__dipSignals || []).catch(() => []); },
    async cookie(prefix) { return (await ctx.cookies()).find(c => c.name.startsWith(prefix)); },
    async restNonce() {
      // A REST nonce for the logged-in session: printed by passkeys.js config on account pages.
      return page.evaluate(() => (window.DIPPasskeys && window.DIPPasskeys.nonce) || '');
    },
    async api(method, route, body, nonce) {
      const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
      if (nonce) headers['X-WP-Nonce'] = nonce;
      const res = await ctx.request.fetch(REST + route, { method, headers, data: body === undefined ? undefined : JSON.stringify(body), failOnStatusCode: false });
      let json = {}; try { json = await res.json(); } catch {}
      return { status: res.status(), json, headers: res.headers() };
    },
  };
  if (url) await page.goto(url);
  return s;
}

async function passwordLogin(s, login, password, redirectTo) {
  const url = BASE + '/wp-login.php' + (redirectTo ? '?redirect_to=' + encodeURIComponent(redirectTo) : '');
  await s.page.goto(url);
  await s.page.fill('#user_login', login);
  await s.page.fill('#user_pass', password);
  await Promise.all([s.page.waitForNavigation({ waitUntil: 'load' }), s.page.click('#wp-submit')]);
  if (!(await s.loggedIn())) throw new Error(`password login failed for ${login} (landed on ${s.page.url()})`);
}

async function statusText(scope, selector = '[data-dip-passkey-status]') {
  return ((await scope.locator(selector).first().textContent({ timeout: 2000 }).catch(() => '')) || '').trim();
}
async function waitForStatus(scope, { selector = '[data-dip-passkey-status]', timeout = 20000, not = '' } = {}) {
  const loc = scope.locator(selector).first();
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) {
    const visible = await loc.isVisible().catch(() => false);
    const txt = visible ? ((await loc.textContent({ timeout: 1000 }).catch(() => '')) || '').trim() : '';
    if (visible && txt && txt !== not && !/^Vérification de la nouvelle Passkey/.test(txt)) return { text: txt, ms: Date.now() - t0, cls: (await loc.getAttribute('class').catch(() => '')) || '' };
    await sleep(100);
  }
  return { text: '', ms: Date.now() - t0, cls: '' };
}
const isFriendlyFrench = t => !!t && !/[A-Z][a-z]+Error|\b(the|this|is|an|invalid|domain|operation|attempted|relying|allowed|timed|undefined|null)\b|HTTP \d{3}/i.test(t);

async function accountSecurity(s) {
  await s.page.goto(BASE + '/my-account/?dip_tab=security', { waitUntil: 'load' });
  return s.page.locator('[data-dip-passkeys-card]');
}

// ---------------------------------------------------------------- reporting
const results = [];
let current = null;
class Fail extends Error {}
class Skip extends Error {}
function skip(msg) { throw new Skip(msg); }
function check(cond, msg) { if (!cond) throw new Fail(msg); }
function note(msg) { if (current) current.notes.push(msg); }

function classifyBrowserErrors(rec, allowHttp) {
  const plugin = [], env = [];
  for (const c of rec.console) {
    const isRest = /\/wp-json\/delicat-identity\//.test(c.url);
    const http = /Failed to load resource: the server responded with a status of (\d{3})/.exec(c.text);
    if (http && isRest && allowHttp && allowHttp.test(http[1])) continue;
    const related = /delicat-identity-pro|wp-json\/delicat-identity|dip|passkey/i.test(c.text + ' ' + c.url);
    if (http && !isRest && !related && MISSING_CORE.has(safePath(c.url))) { env.push(c.text); continue; }
    (related || (http && isRest) ? plugin : env).push(`console: ${c.text} ${c.url}`);
  }
  for (const e of rec.pageErrors) {
    const related = /delicat-identity-pro|DIP|dipx|passkey/i.test(e.text + ' ' + e.stack);
    (related ? plugin : env).push(`pageerror: ${e.text} ${(e.stack.split('\n')[1] || '').trim()}`);
  }
  return { plugin, env };
}
const safePath = u => { try { return new URL(u).pathname; } catch { return ''; } };

const SCENARIOS = [];
function scenario(id, title, fn, opts = {}) { SCENARIOS.push({ id, title, fn, opts }); }

// ---------------------------------------------------------------- fixtures shared by scenarios
let F; // setup output
const U = () => F.users;

// ================================================================ SCENARIOS
// --- A: environment / REST surface ----------------------------------------
scenario('A01', 'wp-login.php shows the passkey button and the REST login options are sane', async () => {
  const s = await newSession({ url: BASE + '/wp-login.php' });
  check(await s.page.locator('[data-dip-passkey-login]').count() === 1, 'no passkey button on wp-login.php');
  const cfg = await s.page.evaluate(() => window.DIPPasskeys || null);
  check(cfg && Number(cfg.available) === 1, 'DIPPasskeys.available !== 1 on wp-login.php');
  const r = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
  check(r.status === 200 && r.json.publicKey, `login/options HTTP ${r.status} ${JSON.stringify(r.json).slice(0, 200)}`);
  check(r.json.publicKey.rpId === 'localhost' && r.json.publicKey.userVerification === 'required', 'rpId/userVerification mismatch');
  check(/no-store/.test(r.headers['cache-control'] || ''), 'login/options response is cacheable (no no-store)');
});

scenario('A02', 'login/options does not reveal whether an account has passkeys (padded descriptors; none without an e-mail)', async () => {
  const cred = makeCredential(); seedPasskey('pk_other', cred, 'enum');
  const s = await newSession();
  const ids = async ident => (await s.api('POST', '/passkeys/login/options', { identifier: ident })).json.publicKey.allowCredentials.map(c => c.id);
  const unknown = 'nobody-' + Date.now() + '@example.test';
  const k1 = await ids(U().pk_other.email), k2 = await ids(U().pk_other.email);
  const n1 = await ids(unknown), n2 = await ids(unknown);
  const stableKnown = k1.filter(i => k2.includes(i)).length, stableUnknown = n1.filter(i => n2.includes(i)).length;
  note(`known account: ${k1.length} descriptors, ${stableKnown} identical across two calls; unknown account: ${n1.length} descriptors, ${stableUnknown} identical`);
  check(k1.length === n1.length, 'descriptor count differs between known/unknown account');
  check(stableKnown === stableUnknown, `real credential IDs are distinguishable from padding: ${stableKnown} descriptor(s) repeat for the real account vs ${stableUnknown} for an unknown one (account + passkey-count enumeration)`);
  check(k1.indexOf(cred.idB64u) !== 0 || n1.indexOf(cred.idB64u) === -1, 'real credential always listed first');
  const lens = list => list.map(i => i.length).sort((a, b) => a - b).join(',');
  note(`descriptor lengths: known [${lens(k1)}], unknown [${lens(n1)}]`);
  check(new Set(n1.map(i => i.length)).size > 1, 'padding ids all have the same length (a real id of another length stands out)');
  const d = await s.api('POST', '/passkeys/login/options', {});
  check(d.status === 200 && Array.isArray(d.json.publicKey.allowCredentials) && d.json.publicKey.allowCredentials.length === 0, `options without an e-mail: HTTP ${d.status}, allowCredentials ${JSON.stringify((d.json.publicKey || {}).allowCredentials)}`);
  check(d.json.publicKey.timeout === 120000, `WebAuthn timeout ${d.json.publicKey.timeout} (want 120000, as the app)`);
  fx('clear-passkeys', 'pk_other');
}, { allowHttp: /4\d\d/ });

// --- B: registration ---------------------------------------------------------
scenario('B01', 'a fresh password sign-in counts as recent authentication (no extra confirmation before adding a passkey)', async () => {
  const s = await newSession();
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  const card = await accountSecurity(s);
  check(await card.count() === 1, 'passkey card missing on the account security tab');
  const reauthForm = await s.page.locator('.dip-reauth-card form').count();
  const reauthText = ((await s.page.locator('.dip-reauth-card').textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
  note('reauth card: ' + reauthText.slice(0, 140));
  check(reauthForm === 0, 'right after signing in, the Security tab still asks to "Confirmer mon identité" — DIP_Reauth::mark_recent() at login did not stick');
});

scenario('B02', 'register a passkey from the account Security tab (recent auth, ONE system prompt) and see it listed with its label', async () => {
  fx('reset-user', 'pk_user');
  const mails0 = fx('mail-count').count;
  const s = await newSession();
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  let card = await accountSecurity(s);
  const label = 'Portable E2E ' + Date.now().toString(36);
  await card.locator('[data-dip-passkey-label]').fill(label);
  const t0 = Date.now();
  await card.locator('[data-dip-passkey-register]').click();
  let st = await waitForStatus(card);
  if (/Confirmez d’abord|Confirmez d'abord|identité/i.test(st.text) && !/ajoutée/i.test(st.text)) {
    note(`first attempt answered "${st.text}" (HTTP 428) — confirming identity with the password form`);
    await s.page.fill('.dip-reauth-card input[name="current_password"]', U().pk_user.password);
    await Promise.all([s.page.waitForNavigation(), s.page.click('.dip-reauth-card button[type="submit"]')]);
    card = await accountSecurity(s);
    await card.locator('[data-dip-passkey-label]').fill(label);
    await card.locator('[data-dip-passkey-register]').click();
    st = await waitForStatus(card);
  }
  note(`register result after ${Date.now() - t0} ms: "${st.text}"; authenticator ops: ${s.rec.added} create + ${s.rec.asserted} get`);
  check(/ajoutée/i.test(st.text), `registration did not succeed: "${st.text}"`);
  await s.page.waitForLoadState('load'); await sleep(900); await s.page.waitForLoadState('load');
  card = await accountSecurity(s);
  const items = await card.locator('.dip-passkey-item strong').allTextContents();
  check(items.includes(label), `new passkey not listed with its label (list: ${JSON.stringify(items)})`);
  const stored = fx('passkeys', 'pk_user');
  check(stored.count === 1 && stored.passkeys[0].label === label, 'stored credential/label mismatch');
  const mails = fx('mail-count');
  check(mails.count > mails0 && mails.mails.some(m => /Passkey/i.test(m.subject)), 'no "Nouvelle Passkey" security email captured');
  // keep the browser credential for later scenarios
  const creds = await s.credentials();
  run.registered = { login: 'pk_user', credential: creds[0], id: stored.passkeys[0].id };
  check(s.rec.added === 1 && s.rec.asserted === 0, `enrolment used ${s.rec.added} create + ${s.rec.asserted} get (want one single prompt)`);
  const calls = s.rec.requests.filter(r => /\/passkeys\/register\//.test(r.url)).map(r => r.url.split('/passkeys/')[1]);
  check(!calls.includes('register/proof'), 'register/proof still called: ' + calls.join(', '));
}, { allowHttp: /428/ });

scenario('B03', 're-registering the same authenticator shows a clear French message', async () => {
  check(run.registered, 'needs B02');
  const s = await newSession();
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  await s.cdp.send('WebAuthn.addCredential', { authenticatorId: s.authId, credential: run.registered.credential });
  let card = await accountSecurity(s);
  if (await s.page.locator('.dip-reauth-card form').count()) {
    await s.page.fill('.dip-reauth-card input[name="current_password"]', U().pk_user.password);
    await Promise.all([s.page.waitForNavigation(), s.page.click('.dip-reauth-card button[type="submit"]')]);
    card = await accountSecurity(s);
  }
  await card.locator('[data-dip-passkey-register]').click();
  const st = await waitForStatus(card);
  note(`message: "${st.text}"`);
  check(st.text && !/ajoutée/i.test(st.text), 'duplicate registration was not refused');
  check(isFriendlyFrench(st.text), `raw browser error shown to the customer: "${st.text}"`);
  check(fx('passkeys', 'pk_user').count === 1, 'duplicate credential stored');
}, { allowHttp: /4\d\d/ });

scenario('B04', '[delicat_security_center] on a regular page (as advertised in wp-admin) can add a passkey', async () => {
  fx('expire-reauth');
  const s = await newSession();
  await passwordLogin(s, 'pk_other', U().pk_other.password);
  await s.page.goto(F.pages.security);
  const card = s.page.locator('[data-dip-passkeys-card]');
  check(await card.count() === 1, 'passkey card not rendered by [delicat_security_center]');
  const hasScript = await s.page.evaluate(() => !!window.__DIP_PASSKEYS__);
  note('passkeys.js loaded: ' + hasScript);
  await card.locator('[data-dip-passkey-register]').click();
  const st = await waitForStatus(card, { timeout: 5000 });
  note(`feedback after click: "${st.text}"`);
  check(hasScript, 'passkeys.js/passkeys.css are not enqueued on a page using [delicat_security_center]: "Ajouter une Passkey", "Supprimer" and "Confirmer avec une Passkey" do nothing');
  check(st.text, 'clicking "Ajouter une Passkey" gives no feedback at all');
}, { allowHttp: /428/ });

scenario('B05', 'cancelling the (single) enrolment prompt: clear French message, nothing stored or orphaned, retry works', async () => {
  fx('reset-user', 'pk_other');
  const s = await newSession();
  await passwordLogin(s, 'pk_other', U().pk_other.password);
  let card = await accountSecurity(s);
  if (await s.page.locator('.dip-reauth-card form').count()) {
    await s.page.fill('.dip-reauth-card input[name="current_password"]', U().pk_other.password);
    await Promise.all([s.page.waitForNavigation(), s.page.click('.dip-reauth-card button[type="submit"]')]);
    card = await accountSecurity(s);
  }
  await s.setUV(false); // Face ID / fingerprint refused or cancelled
  await card.locator('[data-dip-passkey-register]').click();
  const st = await waitForStatus(card);
  const onDevice = (await s.credentials()).length, onServer = fx('passkeys', 'pk_other').count;
  note(`prompt cancelled → "${st.text}"; credentials on device: ${onDevice}, on server: ${onServer}`);
  check(onServer === 0, 'credential stored although the prompt was cancelled');
  check(onDevice === 0, 'a cancelled enrolment left an orphan passkey on the device');
  check(isFriendlyFrench(st.text), `message after cancelled prompt: "${st.text}"`);
  await s.setUV(true);
  await card.locator('[data-dip-passkey-register]').click();
  const st2 = await waitForStatus(card, { not: st.text });
  note(`retry → "${st2.text}"`);
  check(/ajoutée/i.test(st2.text), `retry after a cancelled prompt failed: "${st2.text}"`);
  check((await s.credentials()).length === 1 && fx('passkeys', 'pk_other').count === 1, 'device and server disagree after the retry');
}, { allowHttp: /4\d\d/ });

scenario('B06', 'a Google-only customer (no password, no passkey yet) can add a first passkey', async () => {
  fx('reset-user', 'pk_google');
  const s = await newSession();
  // Stand-in for "Continuer avec Google" (OAuth cannot run offline): same session state, any sign-in.
  await passwordLogin(s, 'pk_google', U().pk_google.password);
  let card = await accountSecurity(s);
  await card.locator('[data-dip-passkey-register]').click();
  const st = await waitForStatus(card);
  const reauthForm = s.page.locator('.dip-reauth-card form');
  const methods = await reauthForm.locator('input').evaluateAll(els => els.filter(e => e.type !== 'hidden').map(e => e.name));
  note(`first click → "${st.text}"; confirmation form offers: ${methods.join(', ') || 'nothing'}`);
  check(/ajoutée/i.test(st.text) || methods.some(n => n !== 'current_password'), 'dead end: the passkey needs a recent confirmation, the sign-in did not count as one (B01) and the only way offered is "Mot de passe actuel", which a Google-only customer does not have');
}, { allowHttp: /428/ });

scenario('B07', 'a freshly added passkey is shown as "Ajoutée", not as already used', async () => {
  if (!run.registered) skip('needs B02 in the same run');
  const p = fx('passkeys', run.registered.login).passkeys.find(x => x.id === run.registered.id);
  if (!p) skip('B02 credential no longer stored');
  note(`stored last_used right after enrolment: ${p.last_used}`);
  check(!p.last_used, 'the proof assertion is saved as a use: the list says "Dernière utilisation : <now>" for a passkey never used to sign in (rest_register_proof keeps last_used from verify_assertion)');
  const s = await newSession();
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  const card = await accountSecurity(s);
  const meta = ((await card.locator('.dip-passkey-item').first().textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
  note('card entry: ' + meta);
  check(/Ajoutée le/.test(meta) && /Jamais utilisée/.test(meta), `card does not show "Ajoutée le … · Jamais utilisée": "${meta}"`);
});

scenario('B08', 'managed-password customer (Google / e-mail code, no password): adds a passkey right after signing in, and confirms by e-mail code later (C1)', async () => {
  fx('reset-user', 'pk_pwless');
  const s = await newSession();
  // Stand-in for « Continuer avec Google » / e-mail code (cannot run offline): a fresh sign-in of the same account.
  await passwordLogin(s, 'pk_pwless', U().pk_pwless.password);
  let card = await accountSecurity(s);
  await card.locator('[data-dip-passkey-register]').click();
  let st = await waitForStatus(card);
  note(`right after sign-in → "${st.text}"`);
  check(/ajoutée/i.test(st.text), `a fresh sign-in did not count as recent authentication (C1: session 'login' timestamp): "${st.text}"`);
  check(fx('passkeys', 'pk_pwless').count === 1, 'passkey not stored');
  // Later (window passed): a second device. The 428 answer offers an inline, actionable confirmation.
  fx('expire-reauth');
  card = await accountSecurity(s);
  await s.newAuthenticator();
  await card.locator('[data-dip-passkey-register]').click();
  st = await waitForStatus(card);
  const actions = await card.locator('.dip-passkey-status-actions button, .dip-passkey-status-actions a').allTextContents();
  note(`after the window → "${st.text}"; inline actions: ${actions.join(' | ') || 'none'}`);
  check(actions.length > 0, 'the 428 answer is a dead end (no inline confirmation)');
  // The account has a passkey, but on another device: use the other method → the reauth card (C1: e-mail code).
  const other = card.locator('.dip-passkey-status-actions button, .dip-passkey-status-actions a').last();
  await other.click();
  await sleep(500);
  const rc = s.page.locator('[data-dip-reauth-card], #dip-reauth, #dip-reauth-card, .dip-reauth-card').first();
  check(await rc.count() === 1, 'no identity confirmation card on the page');
  const rcText = ((await rc.textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
  const mailBtn = rc.locator('button, input[type="submit"]').filter({ hasText: /code|e-mail|email|courriel/i }).first();
  if (!(await mailBtn.count())) { note('reauth card: ' + rcText.slice(0, 200)); check(false, 'identity card offers no e-mail one-time code to a managed-password account (contract C1 not in place yet)'); }
  const mails0 = fx('mail-count').count;
  await Promise.all([s.page.waitForLoadState('load'), mailBtn.click()]);
  await sleep(1200); await s.page.waitForLoadState('load');
  const mail = fx('last-mail', U().pk_pwless.email);
  const mailText = mail.message.replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ');
  const code = (/(?:^|[^#\w])(\d{6,8})(?!\w)/.exec(mailText) || [])[1];
  note(`confirmation mail: "${mail.subject}" (${fx('mail-count').count - mails0} new), code ${code ? 'found' : 'missing'}`);
  check(code, 'no confirmation code e-mailed');
  const rc2 = s.page.locator('[data-dip-reauth-card], #dip-reauth, #dip-reauth-card, .dip-reauth-card').first();
  const codeInput = rc2.locator('input[type="text"], input[inputmode="numeric"], input[name*="code"]').first();
  await codeInput.fill(code);
  await Promise.all([s.page.waitForLoadState('load'), codeInput.locator('xpath=ancestor::form[1]').locator('button[type="submit"], input[type="submit"]').first().click()]);
  await sleep(1000); await s.page.waitForLoadState('load');
  card = await accountSecurity(s);
  await card.locator('[data-dip-passkey-register]').click();
  st = await waitForStatus(card);
  note(`after the e-mail confirmation → "${st.text}"`);
  check(/ajoutée/i.test(st.text) && fx('passkeys', 'pk_pwless').count === 2, `second passkey not added after the e-mail confirmation: "${st.text}"`);
}, { allowHttp: /4\d\d/ });

// --- C: passkey sign-in on every login surface --------------------------------------
async function seededUser(login, label = 'E2E key') {
  fx('clear-passkeys', login);
  const cred = makeCredential(); seedPasskey(login, cred, label);
  return cred;
}

scenario('C01', 'sign in from wp-login.php with e-mail + passkey lands logged in on redirect_to', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  const target = F.pages.landing;
  await s.page.goto(BASE + '/wp-login.php?redirect_to=' + encodeURIComponent(target));
  await s.page.fill('#user_login', U().pk_user.email);
  const t0 = Date.now();
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }), s.page.click('[data-dip-passkey-login]')]);
  await s.page.waitForLoadState('load');
  const ms = Date.now() - t0;
  note(`landed on ${s.page.url()} after ${ms} ms`);
  check(await s.loggedIn(), 'not logged in after passkey sign-in');
  check(await s.page.evaluate(() => document.body.classList.contains('logged-in')), 'landing page not rendered as logged-in');
  check(fx('passkeys', 'pk_user').passkeys[0].last_used > 0, 'last_used not updated');
  check(s.page.url().startsWith(target), `redirect_to was ignored: expected ${target}, landed on ${s.page.url()} (password sign-in on the same form honours it)`);
});

scenario('C02', 'wp-login.php "Se souvenir de moi" is honoured by passkey sign-in', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', U().pk_user.email);
  await s.page.check('#rememberme');
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }), s.page.click('[data-dip-passkey-login]')]);
  const c = await s.cookie('wordpress_logged_in_');
  check(c, 'not logged in');
  const days = c.expires > 0 ? (c.expires - Date.now() / 1000) / 86400 : 0;
  note(`logged_in cookie expires in ${days.toFixed(1)} days (session cookie = 0)`);
  check(days > 2, 'Remember me was ticked but the passkey session is a browser-session cookie (passkeys.js reads input[name="remember"], wp-login/Woo use name="rememberme")');
});

scenario('C03', 'sign in from the native identity modal (iframe) with e-mail + passkey', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  const navs = [];
  s.page.on('request', r => { if (r.isNavigationRequest() && r.frame() === s.page.mainFrame()) navs.push(r.url()); });
  await s.page.goto(F.pages.landing + '#delicat-login');
  const frame = s.page.frameLocator('#dip-auth-shell iframe');
  const btn = frame.locator('[data-dip-passkey-login]');
  await btn.waitFor({ state: 'visible', timeout: 15000 });
  await s.page.waitForFunction(() => { const f = document.querySelector('#dip-auth-shell iframe'); const b = f && f.contentDocument && f.contentDocument.querySelector('[data-dip-passkey-login]'); return b && !b.disabled; }, null, { timeout: 15000 });
  await frame.locator('#dipx-login-email').fill(U().pk_user.email);
  const t0 = Date.now(); const before = navs.length;
  await btn.click();
  const deadline = Date.now() + 20000;
  while (Date.now() < deadline && !(await s.loggedIn() && navs.length > before)) await sleep(100);
  await s.page.waitForLoadState('load'); await sleep(800); await s.page.waitForLoadState('load');
  const after = navs.slice(before);
  note(`modal sign-in: ${Date.now() - t0} ms, top-level navigations: ${after.join(' , ') || 'none'}; final ${s.page.url()}`);
  check(await s.loggedIn(), 'not logged in after modal passkey sign-in');
  check(await s.page.evaluate(() => document.body.classList.contains('logged-in')), 'page not shown logged-in after modal passkey sign-in');
  check(s.page.url().startsWith(F.pages.landing), 'modal sign-in did not return to the current page');
  check(after.some(u => /dip_auth_sync=/.test(u)), 'the server redirect (with its dip_auth_sync cache-buster) never happened: the parent page reloaded its own URL instead (session-router.js "storage" listener reacts to the iframe\'s localStorage write before DIPAuthNavigate runs)');
});

scenario('C04', 'sign in with the username (not e-mail) works', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', 'pk_user');
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }), s.page.click('[data-dip-passkey-login]')]);
  check(await s.loggedIn(), 'username + passkey sign-in failed');
});

scenario('C05', 'administrator passkey sign-in from wp-login.php?redirect_to=/wp-admin/ lands in wp-admin', async () => {
  const cred = await seededUser('admin');
  const s = await newSession();
  await s.importCredential(cred, U().admin.handle);
  await s.page.goto(BASE + '/wp-login.php?redirect_to=' + encodeURIComponent(BASE + '/wp-admin/'));
  await s.page.fill('#user_login', 'admin');
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }), s.page.click('[data-dip-passkey-login]')]);
  await s.page.waitForLoadState('load');
  note('landed on ' + s.page.url());
  check(await s.loggedIn(), 'admin passkey sign-in failed');
  check(/\/wp-admin\//.test(s.page.url()), `staff member sent to ${s.page.url()} instead of wp-admin (DIP_Session_Router::destination() rejects wp-admin/wp-login targets for every passkey sign-in)`);
  fx('clear-passkeys', 'admin');
}, { allowHttp: /4\d\d/ });

scenario('C06', 'every login surface that renders a password form offers the passkey button ([delicat_auth_panel])', async () => {
  const s = await newSession({ url: F.pages.authpanel });
  const hasForm = await s.page.locator('.dip-auth-card form#loginform, .dip-auth-card form').count();
  const btn = await s.page.locator('.dip-auth-card [data-dip-passkey-login]').count();
  note(`[delicat_auth_panel]: password form ${hasForm ? 'present' : 'absent'}, passkey button ${btn ? 'present' : 'absent'}`);
  check(!hasForm || btn > 0, '[delicat_auth_panel] renders wp_login_form() without the passkey button (login_form action is not fired by wp_login_form())');
  check(btn <= 1, `[delicat_auth_panel] shows ${btn} passkey buttons`);
  const loaded = await s.page.evaluate(() => !!window.__DIP_PASSKEYS__ && !!(window.DIPPasskeys && window.DIPPasskeys.rest));
  check(!btn || loaded, 'passkey button rendered without passkeys.js / its config');
});

// --- D: discoverable login / unknown or deleted credentials ------------------------------
scenario('D01', 'usernameless (discoverable) passkey sign-in without typing an e-mail', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.click('[data-dip-passkey-login]');
  const st = await waitForStatus(s.page.locator('#loginform'), { timeout: 8000 });
  await sleep(500);
  const logged = await s.loggedIn();
  note(`empty identifier → "${st.text}", logged in: ${logged}`);
  // Protocol level: discoverable assertion (userHandle) against options requested without identifier.
  const api = await newSession({ authenticator: false });
  const o = await api.api('POST', '/passkeys/login/options', {});
  const v = await api.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...signAssertion(cred, { challenge: o.json.publicKey.challenge, userHandle: U().pk_user.handle, signCount: 1000 }) });
  note(`REST: options without identifier → allowCredentials=${(o.json.publicKey.allowCredentials || []).length}, verify with userHandle → HTTP ${v.status} ${v.json.code || ''}`);
  check(logged, `the passkey button without an e-mail did not sign in: "${st.text}"`);
  check(v.status === 200 && (o.json.publicKey.allowCredentials || []).length === 0, `REST discoverable sign-in: HTTP ${v.status} ${v.json.code || ''}`);
  // The userHandle names the account; the credential must be one of ITS passkeys.
  const other = await seededUser('pk_other');
  const bad = [
    ['handle of another account', { userHandle: U().pk_other.handle }],
    ['no userHandle', { userHandle: '' }],
    ['unknown handle', { userHandle: b64u(crypto.randomBytes(32)) }],
  ];
  const outcomes = [];
  for (const [label, opt] of bad) {
    const a2 = await newSession({ authenticator: false });
    const o2 = await a2.api('POST', '/passkeys/login/options', {});
    const v2 = await a2.api('POST', '/passkeys/login/verify', { ceremony: o2.json.ceremony, ...signAssertion(cred, { challenge: o2.json.publicKey.challenge, signCount: 2000, ...opt }) });
    outcomes.push(`${label} → ${v2.status}`);
    check(v2.status === 401 && !(await a2.loggedIn()), `discoverable verify accepted with ${label}: HTTP ${v2.status}`);
  }
  note(outcomes.join('; '));
  void other;
}, { allowHttp: /4\d\d/ });

scenario('D08', 'identity modal: the passkey button works without an e-mail (discoverable) and keeps « Rester connecté »', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  const navs = [];
  s.page.on('request', r => { if (r.isNavigationRequest() && r.frame() === s.page.mainFrame()) navs.push(r.url()); });
  await s.page.goto(F.pages.landing + '#delicat-login');
  const frame = s.page.frameLocator('#dip-auth-shell iframe');
  const btn = frame.locator('[data-dip-passkey-login]');
  await btn.waitFor({ state: 'visible', timeout: 15000 });
  await s.page.waitForFunction(() => { const f = document.querySelector('#dip-auth-shell iframe'); const b = f && f.contentDocument && f.contentDocument.querySelector('[data-dip-passkey-login]'); return b && !b.disabled; }, null, { timeout: 15000 });
  const before = navs.length;
  await btn.click();
  const deadline = Date.now() + 20000;
  while (Date.now() < deadline && !(await s.loggedIn() && navs.length > before)) await sleep(100);
  await s.page.waitForLoadState('load');
  const opts = s.rec.requests.filter(r => /login\/options/.test(r.url)).map(r => JSON.parse(r.body || '{}'));
  const cookie = await s.cookie('wordpress_logged_in_');
  note(`options bodies: ${JSON.stringify(opts.map(o => ({ identifier: o.identifier, remember: o.remember })))}; navigations: ${navs.slice(before).join(' , ') || 'none'}; logged_in cookie expires ${cookie ? (cookie.expires > 0 ? ((cookie.expires - Date.now() / 1000) / 86400).toFixed(1) + ' days' : 'at browser close') : 'n/a'}`);
  check(await s.loggedIn(), 'modal passkey button without an e-mail did not sign in');
  check(opts.length >= 1 && opts.every(o => o.identifier === ''), 'the modal asked for an e-mail or sent one');
  check(navs.slice(before).some(u => /dip_auth_sync=/.test(u) && u.startsWith(F.pages.landing)), 'modal sign-in did not follow the server redirect to the current page');
  check(cookie && cookie.expires > 0, '« Rester connecté » (checked by default in the modal) was not honoured');
});

scenario('D02', 'sign-in with a passkey deleted from the account fails fast with a friendly message', async () => {
  const cred = await seededUser('pk_user', 'to-delete');
  const keep = makeCredential(); seedPasskey('pk_user', keep, 'kept');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle); // the browser only holds the deleted one
  // delete "to-delete" server side
  fx('clear-passkeys', 'pk_user'); seedPasskey('pk_user', keep, 'kept');
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', U().pk_user.email);
  await s.page.click('[data-dip-passkey-login]');
  const form = s.page.locator('#loginform');
  const st = await waitForStatus(form, { timeout: 65000 });
  const enabled = await s.page.locator('[data-dip-passkey-login]').isEnabled();
  note(`message after ${st.ms} ms: "${st.text}" (button re-enabled: ${enabled})`);
  check(!(await s.loggedIn()), 'logged in with a deleted passkey');
  check(st.text && isFriendlyFrench(st.text), `no friendly message (got "${st.text}")`);
  check(st.ms < 10000, `customer waits ${st.ms} ms before any feedback`);
  check(enabled, 'passkey button stays disabled');
  // Protocol level: the deleted credential's signature against a real ceremony
  const api = await newSession({ authenticator: false });
  const o = await api.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
  const v = await api.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...signAssertion(cred, { challenge: o.json.publicKey.challenge, userHandle: U().pk_user.handle }) });
  check(v.status === 401 && !(await api.loggedIn()), `REST verify with deleted credential: HTTP ${v.status}`);
}, { allowHttp: /4\d\d/ });

scenario('D03', 'a valid passkey of another account cannot open this account', async () => {
  const a = await seededUser('pk_user');
  const b = await seededUser('pk_other');
  const api = await newSession({ authenticator: false });
  const o = await api.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
  const v = await api.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...signAssertion(b, { challenge: o.json.publicKey.challenge, userHandle: U().pk_other.handle }) });
  check(v.status >= 400 && !(await api.loggedIn()), `foreign credential accepted: HTTP ${v.status}`);
  void a;
}, { allowHttp: /4\d\d/ });

scenario('D04', 'passkey autofill (conditional mediation) on wp-login.php: picking the passkey in the e-mail field signs in', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession({ autofill: true });
  const available = await s.page.goto(BASE + '/wp-login.php?redirect_to=' + encodeURIComponent(F.pages.landing)).then(() => s.page.evaluate(() => window.PublicKeyCredential && PublicKeyCredential.isConditionalMediationAvailable ? PublicKeyCredential.isConditionalMediationAvailable() : false));
  if (!available) skip('isConditionalMediationAvailable() is false in this browser');
  // No credential yet: the autofill request stays pending (nothing to offer).
  await s.page.waitForFunction(() => /webauthn/.test(document.getElementById('user_login').getAttribute('autocomplete') || ''), null, { timeout: 5000 }).catch(() => {});
  const ac = await s.page.locator('#user_login').getAttribute('autocomplete');
  note(`#user_login autocomplete="${ac}"`);
  check(/\bwebauthn\b/.test(ac || ''), 'the e-mail field is not marked for passkey autofill (autocomplete … webauthn)');
  const t0 = Date.now();
  while (Date.now() - t0 < 5000 && !s.rec.requests.some(r => /login\/options/.test(r.url))) await sleep(100);
  const opts = s.rec.requests.filter(r => /login\/options/.test(r.url));
  check(opts.length === 1 && JSON.parse(opts[0].body || '{}').identifier === '', `autofill did not start one discoverable ceremony (login/options calls: ${opts.length})`);
  // The virtual authenticator answers a pending conditional request as soon as it has a passkey: « the customer picks it ».
  await s.importCredential(cred, U().pk_user.handle);
  await s.page.reload();
  await s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }).catch(() => {});
  await s.page.waitForLoadState('load');
  note('landed on ' + s.page.url());
  check(await s.loggedIn(), 'autofill passkey did not sign in');
  check(s.page.url().startsWith(F.pages.landing), `autofill sign-in ignored redirect_to: ${s.page.url()}`);
}, { allowHttp: /4\d\d/ });

scenario('D05', 'a pending autofill request is aborted cleanly by the passkey button (with e-mail) and by a password sign-in', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession({ autofill: 'pending' });
  await s.importCredential(cred, U().pk_user.handle);
  await s.page.goto(BASE + '/wp-login.php');
  if (!(await s.page.evaluate(() => PublicKeyCredential.isConditionalMediationAvailable ? PublicKeyCredential.isConditionalMediationAvailable() : false))) skip('no conditional mediation');
  const cond = async page => page.evaluate(() => JSON.parse(sessionStorage.getItem('__dipCond') || '{}')).catch(() => ({}));
  const t0 = Date.now();
  while (Date.now() - t0 < 5000 && !(await cond(s.page)).started) await sleep(100);
  check((await cond(s.page)).started === 1, 'autofill request never started');
  // e-mail typed + button while the autofill request is pending
  await s.page.fill('#user_login', U().pk_user.email);
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }).catch(() => {}), s.page.click('[data-dip-passkey-login]')]);
  const bodies = s.rec.requests.filter(r => /login\/options/.test(r.url)).map(r => JSON.parse(r.body || '{}').identifier);
  const c1 = await cond(s.page);
  note(`login/options identifiers: ${JSON.stringify(bodies)}; conditional requests ${JSON.stringify(c1)}`);
  check(await s.loggedIn(), `button sign-in with a pending autofill request failed: "${await statusText(s.page.locator('#loginform'))}"`);
  check(bodies.includes(U().pk_user.email), 'the button did not run the e-mail sign-in');
  check(c1.aborted === c1.started, 'the pending autofill request was not aborted before the button prompt');
  // Without an e-mail the button reuses the prepared discoverable ceremony: no second options request.
  const n = await newSession({ autofill: 'pending' });
  await n.importCredential(cred, U().pk_user.handle, 100); // the first sign-in moved the stored counter
  await n.page.goto(BASE + '/wp-login.php');
  const t2 = Date.now();
  while (Date.now() - t2 < 5000 && !(await cond(n.page)).started) await sleep(100);
  await Promise.all([n.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }).catch(() => {}), n.page.click('[data-dip-passkey-login]')]);
  const nOpts = n.rec.requests.filter(r => /login\/options/.test(r.url)).length;
  note(`no e-mail: login/options calls ${nOpts}, logged in ${await n.loggedIn()}`);
  check(await n.loggedIn() && nOpts === 1, `discoverable button sign-in did not reuse the autofill ceremony (options calls: ${nOpts})`);
  // password sign-in while autofill is pending
  const p = await newSession({ autofill: 'pending' });
  await p.page.goto(BASE + '/wp-login.php');
  const t1 = Date.now();
  while (Date.now() - t1 < 5000 && !(await cond(p.page)).started) await sleep(100);
  await p.page.fill('#user_login', 'pk_other');
  await p.page.fill('#user_pass', U().pk_other.password);
  await Promise.all([p.page.waitForNavigation({ waitUntil: 'load' }), p.page.click('#wp-submit')]);
  const c3 = await cond(p.page);
  note(`password sign-in: conditional requests ${JSON.stringify(c3)}`);
  check(await p.loggedIn(), 'password sign-in failed while passkey autofill was pending');
  check(c3.started >= 1 && c3.aborted === c3.started, 'the pending autofill request was not aborted by the password sign-in');
  check(!p.rec.requests.some(r => /login\/verify/.test(r.url)), 'a passkey assertion was sent during a password sign-in');
}, { allowHttp: /4\d\d/ });

scenario('D06', 'passkey autofill in the native identity modal (iframe) signs in and follows the server redirect', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession({ autofill: true });
  await s.importCredential(cred, U().pk_user.handle);
  const navs = [];
  s.page.on('request', r => { if (r.isNavigationRequest() && r.frame() === s.page.mainFrame()) navs.push(r.url()); });
  await s.page.goto(F.pages.landing + '#delicat-login');
  const deadline = Date.now() + 20000;
  while (Date.now() < deadline && !(await s.loggedIn() && navs.some(u => /dip_auth_sync=/.test(u)))) await sleep(150);
  await s.page.waitForLoadState('load');
  const ac = await s.page.evaluate(() => { const f = document.querySelector('#dip-auth-shell iframe'); const i = f && f.contentDocument && f.contentDocument.getElementById('dipx-login-email'); return i ? i.getAttribute('autocomplete') : null; }).catch(() => null);
  note(`navigations: ${navs.slice(1).join(' , ') || 'none'}; final ${s.page.url()}`);
  if (!(await s.loggedIn()) && !s.rec.requests.some(r => /login\/options/.test(r.url))) skip('no conditional mediation in the modal frame');
  check(await s.loggedIn(), 'modal autofill did not sign in');
  check(navs.some(u => /dip_auth_sync=/.test(u) && u.startsWith(F.pages.landing)), 'modal autofill did not follow the server redirect to the current page');
  void ac;
}, { allowHttp: /4\d\d/ });

scenario('D07', 'discoverable sign-in with a passkey the site no longer knows: friendly message and Signal API cleanup', async () => {
  await seededUser('pk_user');
  const ghost = makeCredential();
  const s = await newSession();
  await s.importCredential(ghost, b64u(crypto.randomBytes(32))); // deleted account / passkey: unknown handle
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.click('[data-dip-passkey-login]');
  const st = await waitForStatus(s.page.locator('#loginform'));
  const sig = (await s.signals()).filter(x => x.name === 'signalUnknownCredential');
  note(`message: "${st.text}"; signalUnknownCredential calls: ${JSON.stringify(sig.map(x => x.opts))}`);
  check(!(await s.loggedIn()), 'logged in with an unknown passkey');
  check(st.text && isFriendlyFrench(st.text) && /plus reconnue/.test(st.text), `message: "${st.text}"`);
  const hasSignal = await s.page.evaluate(() => typeof PublicKeyCredential.signalUnknownCredential === 'function');
  if (hasSignal) check(sig.some(x => x.opts.credentialId === ghost.idB64u && x.opts.rpId === 'localhost'), 'signalUnknownCredential not called for the unknown passkey');
  // An e-mail-first refusal (passkey of ANOTHER account) must never be signalled as unknown.
  const other = await seededUser('pk_other');
  const s2 = await newSession();
  await s2.importCredential(other, U().pk_other.handle);
  await s2.page.goto(BASE + '/wp-login.php');
  await s2.page.fill('#user_login', U().pk_user.email);
  await s2.page.click('[data-dip-passkey-login]');
  await waitForStatus(s2.page.locator('#loginform'));
  check(!(await s2.signals()).some(x => x.name === 'signalUnknownCredential'), 'a valid passkey of another account was signalled as unknown');
}, { allowHttp: /4\d\d/ });

// --- E: re-authentication -----------------------------------------------------------
scenario('E01', 'confirm identity with a passkey, then add a second passkey without the password', async () => {
  const cred = await seededUser('pk_user', 'first');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  fx('expire-reauth');
  let card = await accountSecurity(s);
  const btn = card.locator('[data-dip-passkey-reauth]');
  check(await btn.count() === 1, 'no "Confirmer avec une Passkey" button although the account has a passkey');
  await btn.click();
  const st = await waitForStatus(card);
  note(`reauth: "${st.text}" after ${st.ms} ms`);
  check(/confirmée/i.test(st.text), `passkey reauth failed: "${st.text}"`);
  await sleep(1200); await s.page.waitForLoadState('load');
  card = await accountSecurity(s);
  check(await s.page.locator('.dip-reauth-card form').count() === 0, 'reauth card still asks for confirmation after a successful passkey confirmation');
  await s.newAuthenticator(); // a second device (one passkey per authenticator: excludeCredentials)
  await card.locator('[data-dip-passkey-label]').fill('second');
  await card.locator('[data-dip-passkey-register]').click();
  const st2 = await waitForStatus(card);
  check(/ajoutée/i.test(st2.text), `adding a second passkey after passkey reauth failed: "${st2.text}"`);
  check(fx('passkeys', 'pk_user').count === 2, 'second passkey not stored');
});

scenario('E02', 'reauth/register REST routes refuse cookie calls without a REST nonce, and other accounts’ ceremonies', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  const noNonce = await s.api('POST', '/passkeys/reauth/options', {});
  check(noNonce.status === 401 || noNonce.status === 403, `reauth/options without nonce → HTTP ${noNonce.status}`);
  await accountSecurity(s);
  const nonce = await s.restNonce();
  const o = await s.api('POST', '/passkeys/reauth/options', {}, nonce);
  check(o.status === 200, `reauth/options HTTP ${o.status}`);
  const other = await seededUser('pk_other');
  const bad = await s.api('POST', '/passkeys/reauth/verify', { ceremony: o.json.ceremony, ...signAssertion(other, { challenge: o.json.publicKey.challenge }) }, nonce);
  check(bad.status >= 400, `reauth accepted another account's credential: HTTP ${bad.status}`);
  void cred;
}, { allowHttp: /4\d\d/ });

scenario('E03', 'removal after the confirmation window: inline « Confirmer avec une Passkey », then the removal finishes by itself; the card is refreshed in place', async () => {
  fx('clear-passkeys', 'pk_user');
  const a = makeCredential(); seedPasskey('pk_user', a, 'Gardée');
  const b = makeCredential(); seedPasskey('pk_user', b, 'À retirer');
  const s = await newSession();
  await s.importCredential(a, U().pk_user.handle);
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  fx('expire-reauth');
  const card = await accountSecurity(s);
  let loads = 0; s.page.on('load', () => loads++);
  await card.locator(`[data-dip-passkey-remove="${b.idB64u}"]`).click();
  await card.locator('.dip-passkey-status-actions button').first().waitFor({ timeout: 8000 });
  const label = await card.locator('.dip-passkey-status-actions button').first().textContent();
  note(`inline action: "${label}"`);
  check(/Passkey/i.test(label), `first inline action is not the passkey confirmation: "${label}"`);
  const before = await statusText(card);
  await card.locator('.dip-passkey-status-actions button').first().click();
  const st = await waitForStatus(s.page.locator('[data-dip-passkeys-card]'), { not: before, timeout: 15000 });
  const t0 = Date.now(); let left = fx('passkeys', 'pk_user').count;
  while (left !== 1 && Date.now() - t0 < 8000) { await sleep(200); left = fx('passkeys', 'pk_user').count; }
  note(`after confirmation → "${st.text}"; stored: ${left}; page loads: ${loads}`);
  check(left === 1 && fx('passkeys', 'pk_user').passkeys[0].label === 'Gardée', 'removal did not resume after the inline confirmation');
  check(/supprimée/i.test(st.text), `no confirmation of the removal shown: "${st.text}"`);
  const items = await s.page.locator('[data-dip-passkeys-card] .dip-passkey-item strong').allTextContents();
  check(items.length === 1 && items[0] === 'Gardée', `card not refreshed: ${JSON.stringify(items)}`);
  check(loads === 0, 'the whole page was reloaded');
  const sig = (await s.signals()).filter(x => x.name === 'signalAllAcceptedCredentials').pop();
  const hasSignal = await s.page.evaluate(() => typeof PublicKeyCredential.signalAllAcceptedCredentials === 'function');
  if (hasSignal) check(sig && sig.opts.userId === U().pk_user.handle && JSON.stringify(sig.opts.allAcceptedCredentialIds) === JSON.stringify([a.idB64u]), 'signalAllAcceptedCredentials not sent with the remaining passkey: ' + JSON.stringify(sig && sig.opts));
}, { allowHttp: /4\d\d/ });

// --- F: removal + last-method guard ----------------------------------------------------
scenario('F01', 'remove one of two passkeys from the Security tab', async () => {
  fx('clear-passkeys', 'pk_user');
  const a = makeCredential(); seedPasskey('pk_user', a, 'Alpha');
  const b = makeCredential(); seedPasskey('pk_user', b, 'Bravo');
  const mails0 = fx('mail-count').count;
  const s = await newSession();
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  let card = await accountSecurity(s);
  if (await s.page.locator('.dip-reauth-card form').count()) { // see B01
    await s.page.fill('.dip-reauth-card input[name="current_password"]', U().pk_user.password);
    await Promise.all([s.page.waitForNavigation(), s.page.click('.dip-reauth-card button[type="submit"]')]);
    card = await accountSecurity(s);
  }
  await card.locator(`[data-dip-passkey-remove="${a.idB64u}"]`).click();
  const st = await waitForStatus(card);
  check(/supprimée/i.test(st.text), `remove failed: "${st.text}"`);
  const left = fx('passkeys', 'pk_user');
  check(left.count === 1 && left.passkeys[0].label === 'Bravo', 'wrong passkey removed');
  check(fx('mail-count').count > mails0, 'no removal security email');
  const api = await newSession({ authenticator: false });
  const o = await api.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
  const v = await api.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...signAssertion(a, { challenge: o.json.publicKey.challenge }) });
  check(v.status === 401, `removed passkey still signs in: HTTP ${v.status}`);
}, { allowHttp: /4\d\d/ });

scenario('F04', 'a passkey with a long credential id (130 bytes, allowed by WebAuthn) can be removed', async () => {
  fx('clear-passkeys', 'pk_user');
  const keep = makeCredential(); seedPasskey('pk_user', keep, 'Gardée');
  const long = makeCredential(); const longId = b64u(crypto.randomBytes(130)); seedPasskey('pk_user', { ...long, idB64u: longId }, 'Clé longue');
  const s = await newSession();
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  const card = await accountSecurity(s);
  await card.locator(`[data-dip-passkey-remove="${longId}"]`).click();
  const st = await waitForStatus(s.page.locator('[data-dip-passkeys-card]'));
  const left = fx('passkeys', 'pk_user');
  note(`id length ${longId.length} → "${st.text}"; stored: ${left.count}`);
  // « Route Delicat Identity non autorisée. » means the strict REST firewall (DIP_Security_Architecture,
  // pattern capped at 160 characters) did not get DIP_Passkeys' dip_customer_rest_rules entry.
  check(/supprimée/i.test(st.text) && left.count === 1 && left.passkeys[0].label === 'Gardée', `long-id passkey not removable: "${st.text}"`);
}, { allowHttp: /4\d\d/ });

scenario('F02', 'last-method guard: a password account (or an e-mail-code customer) may remove its last passkey; a customer with no other way in may not', async () => {
  // Password account
  fx('clear-passkeys', 'pk_user'); const a = makeCredential(); seedPasskey('pk_user', a, 'Only');
  const s = await newSession(); await s.importCredential(a, U().pk_user.handle);
  await s.page.goto(BASE + '/wp-login.php'); await s.page.fill('#user_login', U().pk_user.email);
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }), s.page.click('[data-dip-passkey-login]')]);
  let card = await accountSecurity(s);
  if (await s.page.locator('.dip-reauth-card form').count()) note('after a passkey sign-in the Security tab still asks for a confirmation (see B01)');
  if (await card.locator('[data-dip-passkey-reauth]').count()) { await card.locator('[data-dip-passkey-reauth]').click(); await waitForStatus(card); await sleep(1200); card = await accountSecurity(s); }
  await card.locator(`[data-dip-passkey-remove="${a.idB64u}"]`).click();
  const st = await waitForStatus(card);
  check(/supprimée/i.test(st.text), `password account could not remove its last passkey: "${st.text}"`);
  // Passwordless account (no password, no Google/Microsoft) and e-mail code / magic link switched off
  fx('set-auth-method', 'otp_enabled', 'no'); fx('set-auth-method', 'magic_enabled', 'no');
  try {
  fx('clear-passkeys', 'pk_pwless'); const p = makeCredential(); seedPasskey('pk_pwless', p, 'Only');
  const s2 = await newSession(); await s2.importCredential(p, U().pk_pwless.handle);
  await s2.page.goto(BASE + '/wp-login.php'); await s2.page.fill('#user_login', U().pk_pwless.email);
  await Promise.all([s2.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }), s2.page.click('[data-dip-passkey-login]')]);
  card = await accountSecurity(s2);
  if (await card.locator('[data-dip-passkey-reauth]').count() && await s2.page.locator('.dip-reauth-card form').count()) { await card.locator('[data-dip-passkey-reauth]').click(); await waitForStatus(card); await sleep(1200); card = await accountSecurity(s2); }
  await card.locator(`[data-dip-passkey-remove="${p.idB64u}"]`).click();
  const st2 = await waitForStatus(card);
  note(`passwordless last passkey: "${st2.text}"`);
  check(/Ajoutez d’abord|Ajoutez d'abord/i.test(st2.text), `last-method guard did not explain the refusal: "${st2.text}"`);
  check(fx('passkeys', 'pk_pwless').count === 1, 'passwordless account lost its only sign-in method');
  // E-mail code sign-in on: a lost phone's passkey can be removed (the customer still signs in by e-mail).
  fx('set-auth-method', 'otp_enabled', 'yes');
  card = await accountSecurity(s2);
  await card.locator(`[data-dip-passkey-remove="${p.idB64u}"]`).click();
  const st3 = await waitForStatus(card);
  note(`with e-mail code sign-in on: "${st3.text}"`);
  check(/supprimée/i.test(st3.text) && fx('passkeys', 'pk_pwless').count === 0, `an e-mail-code customer could not remove the passkey of a lost device: "${st3.text}"`);
  } finally { fx('set-auth-method', 'otp_enabled', 'yes'); fx('set-auth-method', 'magic_enabled', 'yes'); }
}, { allowHttp: /4\d\d/ });

scenario('F03', 'removal needs a recent confirmation; another account’s passkey id cannot be removed', async () => {
  fx('clear-passkeys', 'pk_user'); const a = makeCredential(); seedPasskey('pk_user', a, 'A'); const a2 = makeCredential(); seedPasskey('pk_user', a2, 'A2');
  fx('clear-passkeys', 'pk_other'); const b = makeCredential(); seedPasskey('pk_other', b, 'B');
  const s = await newSession();
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  await accountSecurity(s); const nonce = await s.restNonce();
  fx('expire-reauth');
  const r1 = await s.api('DELETE', '/passkeys/' + a.idB64u, {}, nonce);
  check(r1.status === 428, `DELETE without recent auth → HTTP ${r1.status}`);
  // confirm with password
  let card = await accountSecurity(s);
  await s.page.fill('.dip-reauth-card input[name="current_password"]', U().pk_user.password);
  await Promise.all([s.page.waitForNavigation(), s.page.click('.dip-reauth-card button[type="submit"]')]);
  const r2 = await s.api('DELETE', '/passkeys/' + b.idB64u, {}, nonce);
  check(r2.status === 404 && fx('passkeys', 'pk_other').count === 1, `deleting another account's passkey → HTTP ${r2.status}`);
  void card;
}, { allowHttp: /4\d\d/ });

// --- G: TOTP 2FA interaction ---------------------------------------------------------------
scenario('G01', 'TOTP-protected account: passkey sign-in satisfies the second factor (no code prompt)', async () => {
  const cred = await seededUser('pk_tfa');
  const t = fx('enable-2fa', 'pk_tfa'); check(t.enabled, '2FA not enabled by fixture');
  const s = await newSession();
  await s.importCredential(cred, U().pk_tfa.handle);
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', U().pk_tfa.email);
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }).catch(() => {}), s.page.click('[data-dip-passkey-login]')]);
  await s.page.waitForLoadState('load');
  note('landed on ' + s.page.url());
  check(await s.loggedIn(), 'passkey sign-in refused or auth cookie blocked for a 2FA account');
  check(!/dip_2fa_challenge/.test(s.page.url()), 'passkey sign-in was sent to the TOTP challenge');
  const ev = fx('audit', 'pk_tfa', 10).events.map(e => e.event_type);
  check(ev.includes('passkey_login_success') && !ev.includes('two_factor_auth_cookie_blocked'), 'audit: ' + ev.join(','));
  fx('disable-2fa', 'pk_tfa');
});

// --- H: replay / binding / protocol negative cases --------------------------------------
scenario('H01', 'replaying a captured successful assertion fails (same and other browser)', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', U().pk_user.email);
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }), s.page.click('[data-dip-passkey-login]')]);
  const verify = s.rec.requests.find(r => /login\/verify/.test(r.url));
  check(verify && verify.body, 'login/verify request not captured');
  const body = JSON.parse(verify.body);
  const same = await s.api('POST', '/passkeys/login/verify', body);
  check(same.status >= 400, `same-browser replay accepted: HTTP ${same.status}`);
  const other = await newSession({ authenticator: false });
  const r = await other.api('POST', '/passkeys/login/verify', body);
  check(r.status >= 400 && !(await other.loggedIn()), `cross-browser replay accepted: HTTP ${r.status}`);
  note(`replay → same browser HTTP ${same.status} (${same.json.code}), other browser HTTP ${r.status} (${r.json.code})`);
}, { allowHttp: /4\d\d/ });

scenario('H02', 'a ceremony started in browser A cannot be completed in browser B', async () => {
  const cred = await seededUser('pk_user');
  const A = await newSession({ authenticator: false });
  const B = await newSession({ authenticator: false });
  const o = await A.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
  const v = await B.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...signAssertion(cred, { challenge: o.json.publicKey.challenge, userHandle: U().pk_user.handle }) });
  check(v.status === 403 && !(await B.loggedIn()), `browser B completed A's ceremony: HTTP ${v.status} ${v.json.code}`);
  note(`browser B → HTTP ${v.status} ${v.json.code}`);
}, { allowHttp: /4\d\d/ });

scenario('H03', 'protocol checks: origin, RP ID, UV flag, type, crossOrigin, signature, signature counter', async () => {
  const cred = await seededUser('pk_user');
  fx('clear-passkeys', 'pk_user'); seedPasskey('pk_user', cred, 'counter', 10);
  const cases = [
    ['wrong origin', { origin: 'https://evil.example' }, 403],
    ['wrong RP ID hash', { rpId: 'evil.example' }, 403],
    ['UV flag missing', { flags: 0x01 }, 403],
    ['create type', { type: 'webauthn.create' }, 400],
    ['crossOrigin', { crossOrigin: true }, 403],
    ['counter not increasing', { signCount: 5 }, 403],
  ];
  const failures = [];
  for (const [name, opt, want] of cases) {
    fx('reset-rate');
    const s = await newSession({ authenticator: false });
    const o = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
    const v = await s.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...signAssertion(cred, { challenge: o.json.publicKey.challenge, signCount: 20, ...opt }) });
    if (v.status !== want || await s.loggedIn()) failures.push(`${name}: HTTP ${v.status} (want ${want})`);
    await s.ctx.close();
  }
  { // bad signature
    const s = await newSession({ authenticator: false });
    const o = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
    const a = signAssertion(cred, { challenge: o.json.publicKey.challenge, signCount: 21 });
    const sig = fromB64u(a.signature); sig[sig.length - 1] ^= 0x01; a.signature = b64u(sig);
    const v = await s.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...a });
    if (v.status !== 401) failures.push(`tampered signature: HTTP ${v.status}`);
  }
  { // good assertion still works afterwards
    const s = await newSession({ authenticator: false });
    const o = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
    const v = await s.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...signAssertion(cred, { challenge: o.json.publicKey.challenge, signCount: 30 }) });
    if (v.status !== 200 || !(await s.loggedIn())) failures.push(`valid assertion after negatives: HTTP ${v.status} ${v.json.code}`);
  }
  check(!failures.length, failures.join('; '));
}, { allowHttp: /4\d\d/ });

scenario('H04', 'ceremonies are purpose-bound (login ceremony cannot confirm identity or finish an enrolment)', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession({ authenticator: false });
  await passwordLogin(s, 'pk_user', U().pk_user.password);
  await accountSecurity(s); const nonce = await s.restNonce();
  const o = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
  const a = signAssertion(cred, { challenge: o.json.publicKey.challenge, signCount: 50 });
  const r1 = await s.api('POST', '/passkeys/reauth/verify', { ceremony: o.json.ceremony, ...a }, nonce);
  const o2 = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
  const r2 = await s.api('POST', '/passkeys/register/proof', { ceremony: o2.json.ceremony, ...signAssertion(cred, { challenge: o2.json.publicKey.challenge, signCount: 51 }) }, nonce);
  note(`login ceremony → reauth/verify HTTP ${r1.status} ${r1.json.code}; → register/proof HTTP ${r2.status} ${r2.json.code}`);
  check(r1.status >= 400 && r1.status !== 500, 'login ceremony accepted by reauth/verify');
  check(r2.status >= 400 && r2.status !== 500, 'login ceremony accepted by register/proof');
}, { allowHttp: /4\d\d/ });

scenario('H05', 'malformed JSON (arrays/objects instead of strings) is rejected without PHP warnings', async () => {
  const s = await newSession({ authenticator: false });
  const bodies = [
    ['/passkeys/login/options', { identifier: ['x'] }],
    ['/passkeys/login/options', { identifier: 'a@b.test', redirect: ['x'] }],
    ['/passkeys/login/options', { email: { a: 1 } }],
    ['/passkeys/login/verify', { ceremony: ['x'] }],
    ['/passkeys/login/verify', { ceremony: 'a'.repeat(32), id: ['x'], rawId: { a: 1 }, clientDataJSON: [1] }],
  ];
  const statuses = [];
  for (const [route, body] of bodies) { const r = await s.api('POST', route, body); statuses.push(r.status); check(r.status < 500, `${route} → HTTP ${r.status}`); }
  note('HTTP ' + statuses.join(', ') + ' (PHP warnings are checked by the runner)');
}, { allowHttp: /4\d\d/ });

// --- I: error UX ---------------------------------------------------------------------------
scenario('I01', 'user cancels / fails Face ID: friendly message, button usable, retry succeeds', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  await s.setUV(false);
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', U().pk_user.email);
  await s.page.click('[data-dip-passkey-login]');
  const st = await waitForStatus(s.page.locator('#loginform'), { timeout: 65000 });
  note(`cancel → "${st.text}" after ${st.ms} ms`);
  check(isFriendlyFrench(st.text), `unfriendly cancel message: "${st.text}"`);
  check(st.ms < 10000, `cancel feedback took ${st.ms} ms`);
  check(await s.page.locator('[data-dip-passkey-login]').isEnabled(), 'button stays disabled after cancel');
  // Same refusal without an e-mail (discoverable): a security key enrolled without a
  // discoverable passkey needs the e-mail, so the message says so.
  await s.page.fill('#user_login', '');
  await s.page.click('[data-dip-passkey-login]');
  const st2 = await waitForStatus(s.page.locator('#loginform'), { timeout: 15000, not: st.text });
  note(`cancel without e-mail → "${st2.text}"`);
  check(isFriendlyFrench(st2.text) && /e-mail/i.test(st2.text), `discoverable cancel gives no e-mail hint: "${st2.text}"`);
  await s.page.fill('#user_login', U().pk_user.email);
  await s.setUV(true);
  for (let i = 0; i < 3; i++) { // a few more cancels then success: never rate-limited
    await s.setUV(false); await s.page.click('[data-dip-passkey-login]'); await waitForStatus(s.page.locator('#loginform'), { timeout: 15000, not: '' }); await sleep(200);
  }
  await s.setUV(true);
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }).catch(() => {}), s.page.click('[data-dip-passkey-login]')]);
  check(await s.loggedIn(), `retry after cancel failed: "${await statusText(s.page.locator('#loginform'))}"`);
}, { allowHttp: /4\d\d/ });

scenario('I02', 'no authenticator at all: the customer gets feedback quickly', async () => {
  await seededUser('pk_user');
  const s = await newSession({ authenticator: false });
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', U().pk_user.email);
  await s.page.click('[data-dip-passkey-login]');
  const st = await waitForStatus(s.page.locator('#loginform'), { timeout: 140000 });
  note(`no authenticator → "${st.text}" after ${st.ms} ms (WebAuthn timeout 120 s, as the app; client deadline +15 s)`);
  check(st.text && isFriendlyFrench(st.text), `message: "${st.text}"`);
}, { allowHttp: /4\d\d/ });

scenario('I03', 'wrong origin (site opened on 127.0.0.1 instead of the canonical host) shows a helpful message', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  const alt = BASE.replace('localhost', '127.0.0.1');
  await s.page.goto(alt + '/wp-login.php');
  if (!(await s.page.locator('[data-dip-passkey-login]').count())) { note('no passkey button on the non-canonical host'); return; }
  await s.page.fill('#user_login', U().pk_user.email);
  await s.page.click('[data-dip-passkey-login]');
  const st = await waitForStatus(s.page.locator('#loginform'), { timeout: 20000 });
  note(`non-canonical host → "${st.text}"`);
  check(!(await s.loggedIn()), 'logged in on the wrong origin');
  check(isFriendlyFrench(st.text), `raw browser error shown: "${st.text}"`);
}, { allowHttp: /4\d\d/ });

scenario('I04', 'expired ceremony: friendly "expirée" message and an immediate successful retry', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  let expired = false;
  await s.page.route('**/wp-json/delicat-identity/v1/passkeys/login/verify', async route => {
    if (!expired) { expired = true; fx('expire-ceremonies'); }
    await route.continue();
  });
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', U().pk_user.email);
  await s.page.click('[data-dip-passkey-login]');
  const st = await waitForStatus(s.page.locator('#loginform'));
  note(`expired → "${st.text}"`);
  check(/expiré/i.test(st.text), `expired ceremony message: "${st.text}"`);
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }).catch(() => {}), s.page.click('[data-dip-passkey-login]')]);
  check(await s.loggedIn(), 'retry after expiry failed');
}, { allowHttp: /410/ });

scenario('I05', 'repeated passkey attempts by a legitimate customer are never rate-limited (owner rule: only failures count)', async () => {
  fx('reset-rate');
  const cred = await seededUser('pk_user');
  const s = await newSession({ authenticator: false });
  let first429 = 0;
  for (let i = 1; i <= 20; i++) {
    const o = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
    if (o.status === 429) { first429 = i; break; }
  }
  note(first429 ? `login/options answered 429 on call #${first429} for one customer (no failed assertion at all)` : '20 option requests served');
  // someone else behind the same address (shared NAT / Cloudflare edge without the opt-in) targeting this account
  fx('reset-rate');
  const attacker = await newSession({ authenticator: false });
  for (let i = 0; i < 12; i++) await attacker.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
  for (let i = 0; i < 6; i++) await attacker.api('POST', '/passkeys/login/options', { identifier: 'pk_user' });
  const victim = await newSession();
  await victim.importCredential(cred, U().pk_user.handle);
  await victim.page.goto(BASE + '/wp-login.php');
  await victim.page.fill('#user_login', U().pk_user.email);
  await victim.page.click('[data-dip-passkey-login]');
  const vs = await waitForStatus(victim.page.locator('#loginform'));
  note(`after 18 anonymous option requests naming the account, the real customer's passkey sign-in shows: "${vs.text}" (logged in: ${await victim.loggedIn()})`);
  fx('reset-rate');
  check(!first429, `option requests counted as attempts: the 13th "Se connecter avec une Passkey" within 10 min (cancelled prompts, retries, several tabs) answers "Trop de tentatives"; anyone can trigger it for any e-mail (targeted passkey lockout)`);
  void cred;
}, { allowHttp: /429/ });

// --- J: data safety ----------------------------------------------------------------------------
scenario('J01', 'lowering "Passkeys par compte" never deletes customers’ existing passkeys', async () => {
  fx('clear-passkeys', 'pk_user');
  const creds = [makeCredential(), makeCredential(), makeCredential()];
  creds.forEach((c, i) => seedPasskey('pk_user', c, 'K' + i));
  fx('set-setting', 'passkeys_max_per_user', 2);
  try {
    const s = await newSession({ authenticator: false });
    const o = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
    const v = await s.api('POST', '/passkeys/login/verify', { ceremony: o.json.ceremony, ...signAssertion(creds[0], { challenge: o.json.publicKey.challenge }) });
    check(v.status === 200, `sign-in with first passkey failed: HTTP ${v.status}`);
    const left = fx('passkeys', 'pk_user').count;
    note(`stored passkeys after one sign-in with max=2: ${left} (was 3)`);
    check(left === 3, `one ordinary sign-in permanently deleted ${3 - left} passkey(s) of the customer (save_credentials() rewrites the truncated credentials() list)`);
  } finally { fx('set-setting', 'passkeys_max_per_user', 8); }
});

scenario('J02', 'concurrent double click on "Se connecter avec une Passkey" starts a single ceremony', async () => {
  const cred = await seededUser('pk_user');
  const s = await newSession();
  await s.importCredential(cred, U().pk_user.handle);
  await s.page.goto(BASE + '/wp-login.php');
  await s.page.fill('#user_login', U().pk_user.email);
  await Promise.all([s.page.waitForURL(u => !/wp-login\.php/.test(u.toString()), { timeout: 20000 }).catch(() => {}), s.page.dblclick('[data-dip-passkey-login]')]);
  const opts = s.rec.requests.filter(r => /login\/options/.test(r.url)).length;
  note(`login/options calls: ${opts}`);
  check(await s.loggedIn(), 'double click broke the sign-in');
  check(opts === 1, `${opts} ceremonies started by a double click`);
}, { allowHttp: /4\d\d/ });

// --- K: admin switch --------------------------------------------------------------------------
scenario('K01', 'turning passkeys off hides every passkey button and refuses the REST sign-in', async () => {
  const cred = await seededUser('pk_user');
  fx('set-setting', 'passkeys_available', 'no');
  try {
    const s = await newSession({ url: BASE + '/wp-login.php' });
    const btn = await s.page.locator('[data-dip-passkey-login]').count();
    const o = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email });
    await s.page.goto(F.pages.landing + '#delicat-login');
    await s.page.waitForTimeout(1500);
    const modalBtn = await s.page.evaluate(() => { const f = document.querySelector('#dip-auth-shell iframe'); const b = f && f.contentDocument && f.contentDocument.querySelector('[data-dip-passkey-login]'); return b ? !b.hidden : false; });
    note(`disabled: wp-login button ${btn}, modal button visible ${modalBtn}, login/options HTTP ${o.status}`);
    check(btn === 0 && !modalBtn, 'a passkey button is still shown while passkeys are disabled');
    check(o.status === 503, `login/options with passkeys disabled → HTTP ${o.status}`);
    void cred;
  } finally { fx('set-setting', 'passkeys_available', 'yes'); }
}, { allowHttp: /503/ });

// --- P: performance -----------------------------------------------------------------------------
scenario('P01', 'anonymous storefront pages do not download passkey assets they never use', async () => {
  const s = await newSession({ authenticator: false });
  const assets = [];
  s.page.on('request', r => { if (/delicat-identity-pro\/assets\/passkeys\.(js|css)/.test(r.url()) && r.frame() === s.page.mainFrame()) assets.push(new URL(r.url()).pathname.split('/').pop()); });
  await s.page.goto(F.pages.landing, { waitUntil: 'load' });
  const parentButtons = await s.page.locator('[data-dip-passkey-login]').count();
  const blocking = await s.page.locator('head link#dip-passkeys-css').count();
  note(`top document loads ${assets.join(' + ') || 'nothing'} (render-blocking css in <head>: ${blocking ? 'yes' : 'no'}); passkey buttons in the top document: ${parentButtons} (the modal iframe loads its own copy)`);
  const t0 = Date.now(); const r = await s.api('POST', '/passkeys/login/options', { identifier: U().pk_user.email }); const ms = Date.now() - t0;
  note(`login/options round-trip ${ms} ms (HTTP ${r.status})`);
  check(!(assets.length && parentButtons === 0), `every anonymous page view downloads ${assets.join(' + ')} in the top document although no passkey button is rendered there`);
});

scenario('P02', 'passkey assets load on demand: security-center page, auth panel, wp-login; config protected from Rocket Loader-style deferral', async () => {
  const anon = await newSession({ authenticator: false });
  const html = await (await anon.ctx.request.get(BASE + '/wp-login.php')).text();
  const extra = /<script[^>]*id=["']dip-passkeys-js-extra["'][^>]*>/.exec(html);
  const tag = /<script[^>]*src=["'][^"']*passkeys\.js[^"']*["'][^>]*>/.exec(html);
  note(`wp-login config tag: ${extra ? extra[0] : 'missing'}`);
  check(extra && /data-cfasync=["']false["']/.test(extra[0]) && /data-no-optimize/.test(extra[0]), 'DIPPasskeys config script is not excluded from Rocket Loader / LiteSpeed deferral');
  check(tag && /data-cfasync=["']false["']/.test(tag[0]), 'passkeys.js tag not excluded from deferral');
  const panel = await (await anon.ctx.request.get(F.pages.authpanel)).text();
  check(/passkeys\.js/.test(panel) && /dip-passkeys-js-extra/.test(panel), '[delicat_auth_panel] page does not load passkeys.js + config');
  // Logged-in customer on a page with [delicat_security_center] only.
  const s = await newSession();
  await passwordLogin(s, 'pk_other', U().pk_other.password);
  await s.page.goto(F.pages.security);
  const ok = await s.page.evaluate(() => !!window.__DIP_PASSKEYS__ && !!(window.DIPPasskeys && window.DIPPasskeys.rest && window.DIPPasskeys.nonce));
  const headCss = await s.page.locator('head link#dip-passkeys-css').count();
  note(`[delicat_security_center]: runtime ${ok}, stylesheet in <head>: ${headCss}`);
  check(ok, 'passkeys.js/config not loaded on the [delicat_security_center] page');
  // Optimizer simulation: the config runs AFTER passkeys.js (deferred inline script); the buttons must still work.
  const d = await newSession();
  await passwordLogin(d, 'pk_other', U().pk_other.password);
  await d.page.route(u => u.pathname === new URL(F.pages.security).pathname, async route => {
    const res = await route.fetch(); let body = await res.text();
    const m = /<script[^>]*id=["']dip-passkeys-js-extra["'][^>]*>[\s\S]*?<\/script>/.exec(body);
    if (m) {
      const code = m[0].replace(/^<script[^>]*>|<\/script>$/g, '');
      body = body.replace(m[0], '').replace('</body>', '<script>window.addEventListener("load",function(){setTimeout(function(){var s=document.createElement("script");s.textContent=' + JSON.stringify(code).replace(/<\//g, '<\\/') + ';document.body.appendChild(s);},300);});</script></body>');
    }
    await route.fulfill({ response: res, body, headers: { ...res.headers(), 'content-length': String(Buffer.byteLength(body)) } });
  });
  await d.page.goto(F.pages.security, { waitUntil: 'load' });
  const early = await d.page.evaluate(() => !!window.DIPPasskeys);
  await sleep(1500);
  const label = await d.page.locator('[data-dip-passkey-label]').inputValue().catch(() => '');
  await d.page.locator('[data-dip-passkey-register]').click();
  const st = await waitForStatus(d.page.locator('[data-dip-passkeys-card]'), { timeout: 15000 });
  note(`deferred config (present at load: ${early}) → card label "${label}", register → "${st.text}"`);
  check(!early, 'the optimizer simulation did not defer the config');
  check(/ajoutée/i.test(st.text), `with the config deferred after passkeys.js, "Ajouter une Passkey" does not work: "${st.text}"`);
  check(label && label !== 'Mon appareil', 'the card was not initialised once the deferred config arrived (default label kept)');
  fx('clear-passkeys', 'pk_other');
}, { allowHttp: /4\d\d/ });

// --- X: concurrency (optional: --parallel-port=<free port> starts a multi-worker PHP server on the same site) ---
const ROUTER_PHP = `<?php
$root = $_SERVER['DOCUMENT_ROOT']; $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && file_exists($root . $path)) {
  if (is_dir($root . $path) && file_exists($root . rtrim($path, '/') . '/index.php')) { chdir($root . rtrim($path, '/')); require $root . rtrim($path, '/') . '/index.php'; return true; }
  if (substr($path, -4) === '.php') { chdir(dirname($root . $path)); require $root . $path; return true; }
  return false;
}
chdir($root); require $root . '/index.php';
`;
let parallel = null;
async function parallelServer() {
  if (parallel) return parallel;
  const port = Number(flags['parallel-port'] || 0);
  if (!port) skip('pass --parallel-port=<free port> to run concurrency checks against a multi-worker PHP server');
  const { spawn } = await import('child_process');
  const router = path.join(TMP, 'router.php'); fs.writeFileSync(router, ROUTER_PHP);
  const proc = spawn('php', ['-S', `localhost:${port}`, '-t', SITE, router], { env: { ...process.env, PHP_CLI_SERVER_WORKERS: '8' }, stdio: 'ignore' });
  process.on('exit', () => { try { proc.kill(); } catch {} });
  for (let i = 0; i < 50; i++) { try { await fetch(`http://localhost:${port}/wp-json/`); break; } catch { await sleep(100); } }
  parallel = { proc, rest: `http://localhost:${port}/wp-json/delicat-identity/v1` };
  return parallel;
}
async function rawPost(url, body, cookie = '') {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(cookie ? { Cookie: cookie } : {}) }, body: JSON.stringify(body) });
  let json = {}; try { json = await r.json(); } catch {}
  return { status: r.status, json, cookies: r.headers.getSetCookie() };
}

scenario('X01', 'parallel replay of one valid assertion opens exactly one session', async () => {
  const P = await parallelServer();
  const cred = await seededUser('pk_user');
  const o = await rawPost(P.rest + '/passkeys/login/options', { identifier: U().pk_user.email });
  const cookie = o.cookies.map(c => c.split(';')[0]).join('; ');
  const body = { ceremony: o.json.ceremony, ...signAssertion(cred, { challenge: o.json.publicKey.challenge }) };
  const rs = await Promise.all(Array.from({ length: 6 }, () => rawPost(P.rest + '/passkeys/login/verify', body, cookie)));
  const ok = rs.filter(r => r.status === 200).length;
  note('statuses: ' + rs.map(r => r.status + (r.json.code ? ':' + r.json.code : '')).join(', '));
  check(ok === 1, `${ok} sessions opened from one assertion`);
});

scenario('X02', 'simultaneous "Se connecter avec une Passkey" requests for one customer are all served', async () => {
  const P = await parallelServer();
  await seededUser('pk_user');
  const runs = 8; let spurious = 0;
  for (let i = 0; i < runs; i++) {
    fx('reset-rate');
    const rs = await Promise.all(Array.from({ length: 3 }, () => rawPost(P.rest + '/passkeys/login/options', { identifier: U().pk_user.email })));
    spurious += rs.filter(r => r.status === 429).length;
  }
  note(`${spurious} spurious 429 "Trop de tentatives" over ${runs}×3 simultaneous option requests (fresh buckets each time)`);
  check(spurious === 0, 'DIP_Passkeys::rate_allowed() answers 429 when its bucket lock is held by a concurrent request (lock contention reported as rate limiting)');
}, { allowHttp: /429/ });

// ================================================================ runner
async function main() {
  if (flags.list) { for (const s of SCENARIOS) console.log(`${s.id}  ${s.title}`); return 0; }
  const ids = v => typeof v === 'string' ? v.split(',').map(s => s.trim()).filter(Boolean) : [];
  const match = (list, id) => list.some(o => o.endsWith('*') ? id.startsWith(o.slice(0, -1)) : id === o);
  const only = ids(flags.only), skipIds = ids(flags.skip);
  const selected = SCENARIOS.filter(s => (!only.length || match(only, s.id)) && !match(skipIds, s.id));
  console.log(`Delicat Identity Pro passkeys E2E — ${BASE} — ${selected.length} scenario(s)`);
  F = fx('setup');
  if (!F.available) { console.log('FAIL setup: DIP_Passkeys::available() is false on this site (needs the localhost test filter or HTTPS)'); return 1; }
  if (F.woo_stub) console.log(`note: WooCommerce absent — account page emulated by ${F.woo_stub}`);
  browser = await chromium.launch({ headless: !flags.headed });
  const phpStart = debugLogSize();
  for (const sc of selected) {
    current = { id: sc.id, title: sc.title, notes: [], status: 'PASS', reason: '', ms: 0, env: [] };
    const t0 = Date.now();
    const logFrom = debugLogSize();
    run.contexts = [];
    fx('reset-rate');
    try {
      await sc.fn();
    } catch (e) {
      if (e instanceof Skip) { current.status = 'SKIP'; current.reason = e.message; }
      else { current.status = 'FAIL'; current.reason = e instanceof Fail ? e.message : `error: ${e.message.split('\n')[0]}`; }
    }
    // browser console / page errors of every session the scenario opened
    for (const ctx of run.contexts) { try { await ctx.close(); } catch {} }
    const recs = sessionsRec.splice(0);
    const plugin = [], env = [];
    for (const r of recs) { const c = classifyBrowserErrors(r, sc.opts.allowHttp); plugin.push(...c.plugin); env.push(...c.env); }
    if (plugin.length) { current.status = 'FAIL'; current.reason = (current.reason ? current.reason + ' | ' : '') + 'browser errors: ' + [...new Set(plugin)].slice(0, 4).join(' || '); }
    const php = newPhpIssues(logFrom);
    if (php.length) { current.status = 'FAIL'; current.reason = (current.reason ? current.reason + ' | ' : '') + 'PHP: ' + [...new Set(php.map(l => l.replace(/^\[[^\]]+\]\s*/, '')))].slice(0, 3).join(' || '); }
    current.env = [...new Set(env)];
    current.ms = Date.now() - t0;
    results.push(current);
    console.log(`${current.status} ${sc.id} ${sc.title} (${(current.ms / 1000).toFixed(1)}s)${current.reason ? '\n     -> ' + current.reason : ''}`);
    for (const n of current.notes) console.log('     · ' + n);
    if (VERBOSE && current.env.length) console.log('     env-noise: ' + current.env.slice(0, 5).join(' | '));
  }
  await browser.close();
  const phpAll = newPhpIssues(phpStart);
  const failed = results.filter(r => r.status === 'FAIL');
  const skipped = results.filter(r => r.status === 'SKIP');
  console.log('\n==== SUMMARY ====');
  console.log(`${results.length - failed.length - skipped.length} passed, ${failed.length} failed, ${skipped.length} skipped` + (phpAll.length ? `, ${phpAll.length} new PHP notice(s)/warning(s) in debug.log` : ', no new PHP notices'));
  for (const f of failed) console.log(`  FAIL ${f.id}: ${f.reason}`);
  fs.rmSync(TMP, { recursive: true, force: true });
  return failed.length || phpAll.length ? 1 : 0;
}

main().then(code => process.exit(code)).catch(e => { console.error(e); process.exit(1); });
