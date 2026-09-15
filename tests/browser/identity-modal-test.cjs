/* The sign-in popup, in a real Chromium, over a deliberately slow admin-ajax.
 *
 * The complaint was "lagging very slow on cheap phones and very buggy". The
 * bug behind it was not a slow animation: v4 disabled every submit button until
 * a round trip to admin-ajax.php returned security nonces, and only started
 * that round trip once the dialog was open. So the customer looked at a
 * finished form whose button did nothing - and if the request failed, the
 * button never came back at all.
 *
 * Every nonce response here is delayed by 1.2s on purpose. That is an ordinary
 * admin-ajax round trip on a cheap phone on 3G, and it is the condition under
 * which the old build was broken. If these pass, the customer never waits on
 * anything except the sign-in they asked for. */
const { chromium } = require('playwright');
const fs = require('fs');

const ROOT = '/home/user/snowy-boat-a2d8/delicat-identity-pro/assets/';
const CSS = fs.readFileSync(ROOT + 'identity-modal-v3.css', 'utf8');
const JS = fs.readFileSync(process.env.MODAL_JS || (ROOT + 'identity-modal-v5.js'), 'utf8');

/* The markup below mirrors DIP_Native_Auth::modal_markup(). A PHP test asserts
   that every selector used here still exists there, so the two cannot drift. */
const MODAL = `
<div id="dip-identity-modal" class="dipx-root" data-dip-identity-modal hidden aria-hidden="true">
  <div class="dipx-backdrop" data-dip-auth-close></div>
  <section class="dipx-card" role="dialog" aria-modal="true" aria-labelledby="dipx-title" tabindex="-1">
    <button type="button" class="dipx-close" data-dip-auth-close aria-label="Fermer"><span aria-hidden="true"></span></button>
    <header class="dipx-header">
      <div class="dipx-brand-mark" aria-hidden="true"></div>
      <h2 id="dipx-title" class="dipx-brand"><span>DELICAT</span><strong>STORE</strong></h2>
      <p id="dipx-subtitle" class="dipx-subtitle">Connectez-vous pour continuer</p>
    </header>
    <div class="dipx-tabs" role="tablist" aria-label="Authentification">
      <button type="button" id="dipx-tab-login" class="dipx-tab is-active" data-dipx-tab="login" role="tab" aria-selected="true" tabindex="0"><span>Connexion</span></button>
      <button type="button" id="dipx-tab-register" class="dipx-tab" data-dipx-tab="register" role="tab" aria-selected="false" tabindex="-1"><span>Inscription</span></button>
    </div>
    <div class="dipx-message" role="alert" aria-live="polite" hidden></div>
    <button type="button" class="dipx-resend" data-dipx-resend hidden>Renvoyer</button>
    <form id="dipx-panel-login" class="dipx-form dipx-form-login" autocomplete="on" novalidate role="tabpanel">
      <label class="dipx-label" for="dipx-login-email"><span>Email</span></label>
      <div class="dipx-field"><input id="dipx-login-email" type="email" name="email" placeholder="Votre@email.com" autocomplete="username webauthn" inputmode="email" maxlength="254" required></div>
      <label class="dipx-label" for="dipx-login-pass"><span>Mot de passe</span></label>
      <div class="dipx-field dipx-password">
        <input id="dipx-login-pass" type="password" name="password" placeholder="Votre mot de passe" autocomplete="current-password" required>
        <button type="button" class="dipx-eye" data-dipx-eye aria-label="Afficher le mot de passe"><span aria-hidden="true"></span></button>
      </div>
      <div class="dipx-two-factor" data-dipx-2fa hidden>
        <div class="dipx-field"><input id="dipx-login-2fa" type="text" name="dip_2fa_code" autocomplete="one-time-code" maxlength="32"></div>
      </div>
      <div class="dipx-options">
        <label class="dipx-remember"><input type="checkbox" name="remember" value="1" checked><span>Rester connecté</span></label>
        <a href="/lost">Mot de passe oublié ?</a>
      </div>
      <input type="text" name="dl_hp" class="dipx-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <button type="submit" class="dipx-submit"><span class="dipx-submit-label">Connexion</span><span class="dipx-spinner" hidden aria-hidden="true"></span></button>
    </form>
    <form id="dipx-panel-register" class="dipx-form dipx-form-register" autocomplete="on" novalidate hidden role="tabpanel">
      <div class="dipx-field"><input id="dipx-reg-name" type="text" name="name" autocomplete="name" maxlength="80" required></div>
      <div class="dipx-field"><input id="dipx-reg-email" type="email" name="email" autocomplete="email" maxlength="254" required></div>
      <div class="dipx-field dipx-password"><input id="dipx-reg-pass" type="password" name="password" autocomplete="new-password" minlength="8" required></div>
      <input type="text" name="dl_hp" class="dipx-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <button type="submit" class="dipx-submit"><span class="dipx-submit-label">Créer mon compte</span><span class="dipx-spinner" hidden aria-hidden="true"></span></button>
    </form>
    <footer class="dipx-security"><span>Connexion sécurisée et protégée</span></footer>
  </section>
</div>`;

const PAGE = `<!doctype html><html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>${CSS}</style>
<style>*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Roboto,Arial,sans-serif}
header{position:sticky;top:0;display:flex;gap:12px;padding:12px 14px;background:#fff;border-bottom:1px solid #eee}
.trigger{min-height:44px;padding:0 16px;border:0;border-radius:12px;background:#2364ff;color:#fff;font-weight:700}
.row{padding:14px;height:600px}</style></head><body>
<header><button class="trigger" data-dip-auth-open>Connexion</button></header>
<section class="row"><h2>Boutique</h2></section>
${MODAL}
<script>window.DIPIdentityModal={ajaxUrl:'/wp-admin/admin-ajax.php',redirectUrl:'/mon-portefeuille/',nonceAction:'dip_native_fresh_nonces_v2',loginAction:'dip_native_do_login_v2',registerAction:'dip_native_do_register_v2',resendAction:'dip_native_resend_verification_v2',modalId:'dip-identity-modal',legacyDetected:0,registrationEnabled:1};</script>
<script>${JS}</script>
</body></html>`;

let pass = 0, fail = 0;
const ok = (w, c, d = '') => { c ? (pass++, console.log('  ok    ' + w)) : (fail++, console.log('  FAIL  ' + w + (d ? '  -- ' + d : ''))); };
const group = (n) => console.log('\n' + n + '\n' + '-'.repeat(n.length));

const NONCE_DELAY = 1200;   /* an ordinary admin-ajax round trip on a cheap phone */

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  /**
   * A page with a deliberately slow admin-ajax. `plan` decides what the login
   * endpoint answers; `log` records every call in order.
   */
  async function open(plan = {}) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
    const page = await ctx.newPage();
    const log = [];
    let nonceSerial = 0;
    let loginCalls = 0;

    /* Playwright tries handlers most-recently-added first, so the catch-all is
       registered here and the specific endpoints below override it. */
    await page.route('https://shop.test/**', (r) => r.fulfill({ contentType: 'text/html', body: PAGE }));

    await page.route('https://shop.test/wp-admin/admin-ajax.php', async (route) => {
      const body = route.request().postData() || '';
      const action = (body.match(/name="action"\r?\n\r?\n([^\r\n]+)/) || [])[1] || '';
      const nonce = (body.match(/name="nonce"\r?\n\r?\n([^\r\n]*)/) || [])[1] || '';
      log.push({ action, nonce, at: Date.now() });

      if (action === 'dip_native_fresh_nonces_v2') {
        nonceSerial++;
        await new Promise((r) => setTimeout(r, plan.nonceDelay === undefined ? NONCE_DELAY : plan.nonceDelay));
        if (plan.nonceFails) return route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify({ success: false, data: { code: 'browser_binding_failed', message: 'Cookies requis.' } }) });
        return route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify({ success: true, data: { login: 'N' + nonceSerial, register: 'R' + nonceSerial, resend: 'S' + nonceSerial } }) });
      }

      if (action === 'dip_native_do_login_v2') {
        loginCalls++;
        if (plan.staleFirst && loginCalls === 1) return route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify({ success: false, data: { code: 'bad_nonce', message: 'Session expirée.' } }) });
        if (plan.wrongPassword) return route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify({ success: false, data: { code: 'invalid_credentials', message: '❌ Identifiants incorrects.' } }) });
        return route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify({ success: true, data: { message: '✅ Connexion réussie.', redirect: 'https://shop.test/mon-portefeuille/' } }) });
      }

      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
    });

    await page.route('https://shop.test/mon-portefeuille/', (r) =>
      r.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Wallet</title><h1>Portefeuille</h1>' }));
    await page.goto('https://shop.test/');
    return { ctx, page, log };
  }

  const state = (page) => page.evaluate(() => {
    const root = document.getElementById('dip-identity-modal');
    const button = document.querySelector('.dipx-form-login .dipx-submit');
    const message = document.querySelector('.dipx-message');
    return {
      hidden: root.hidden,
      open: root.classList.contains('is-open'),
      submitDisabled: !!(button && button.disabled),
      message: message.hidden ? '' : message.textContent,
      focused: document.activeElement ? (document.activeElement.id || document.activeElement.className || document.activeElement.tagName) : '',
      domHasNonce: /\bN\d\b|\bR\d\b|\bS\d\b/.test(document.getElementById('dip-identity-modal').innerHTML),
    };
  });

  /* ------------------------------------------------------------------ */

  group('The submit button is never dead');
  {
    const { ctx, page, log } = await open();
    await page.tap('.trigger');
    await page.waitForTimeout(120);            /* well inside the 1.2s nonce round trip */
    let s = await state(page);
    ok('the dialog is open before the nonce has landed', s.open && !s.hidden);
    ok('and its button is enabled', !s.submitDisabled,
       'v4 disabled it here and the customer saw a form that did nothing');
    ok('no error is shown while it loads', s.message === '', s.message);

    await page.fill('#dipx-login-email', 'client@delicastoreha.com');
    await page.fill('#dipx-login-pass', 'motdepasse123');
    await page.tap('.dipx-form-login .dipx-submit');

    await page.waitForURL('**/mon-portefeuille/', { timeout: 8000 }).catch(() => {});
    ok('pressing it signs in anyway', page.url().includes('/mon-portefeuille/'), page.url());

    const login = log.find((e) => e.action === 'dip_native_do_login_v2');
    ok('and the sign-in carried a real nonce', !!login && /^N\d+$/.test(login.nonce), login ? login.nonce : 'no login call');
    await ctx.close();
  }

  group('The security session is fetched on intent, not on open');
  {
    const { ctx, page, log } = await open();
    await page.evaluate(() => {
      const b = document.querySelector('.trigger').getBoundingClientRect();
      window.__pt = { x: b.x + b.width / 2, y: b.y + b.height / 2 };
    });
    const pt = await page.evaluate(() => window.__pt);
    await page.touchscreen.tap(pt.x, pt.y);
    await page.waitForTimeout(60);
    ok('the nonce request is already in flight before the dialog settles',
       log.some((e) => e.action === 'dip_native_fresh_nonces_v2'),
       'pointerdown fires long before click; that head start is the whole point');
    await ctx.close();
  }

  group('Nonces stay out of the page');
  {
    const { ctx, page } = await open({ nonceDelay: 0 });
    await page.tap('.trigger');
    await page.waitForTimeout(350);
    const s = await state(page);
    ok('nothing in the dialog carries one', !s.domHasNonce,
       'v4 wrote them into hidden inputs, where any script on the page could read them');
    const inputs = await page.evaluate(() => document.querySelectorAll('#dip-identity-modal input[name="nonce"]').length);
    ok('and there is no nonce field at all', inputs === 0);
    await ctx.close();
  }

  group('A nonce a cache outlived repairs itself, once, silently');
  {
    const { ctx, page, log } = await open({ nonceDelay: 0, staleFirst: true });
    await page.tap('.trigger');
    await page.waitForTimeout(250);
    await page.fill('#dipx-login-email', 'client@delicastoreha.com');
    await page.fill('#dipx-login-pass', 'motdepasse123');
    await page.tap('.dipx-form-login .dipx-submit');
    await page.waitForURL('**/mon-portefeuille/', { timeout: 8000 }).catch(() => {});

    ok('the customer still ends up signed in', page.url().includes('/mon-portefeuille/'), page.url());
    const logins = log.filter((e) => e.action === 'dip_native_do_login_v2');
    ok('it was retried exactly once', logins.length === 2, 'attempts: ' + logins.length);
    ok('with a freshly issued nonce the second time',
       logins.length === 2 && logins[0].nonce !== logins[1].nonce,
       logins.map((l) => l.nonce).join(' -> '));
    await ctx.close();
  }

  group('A real failure is reported and the form stays usable');
  {
    const { ctx, page } = await open({ nonceDelay: 0, wrongPassword: true });
    await page.tap('.trigger');
    await page.waitForTimeout(250);
    await page.fill('#dipx-login-email', 'client@delicastoreha.com');
    await page.fill('#dipx-login-pass', 'faux');
    await page.tap('.dipx-form-login .dipx-submit');
    await page.waitForTimeout(500);
    const s = await state(page);
    ok('the message is the server\'s own', s.message.includes('Identifiants incorrects'), s.message);
    ok('and the button works again immediately', !s.submitDisabled,
       'a customer who mistypes must be able to try again without reloading');
    await ctx.close();
  }

  group('Even a broken security session leaves a usable dialog');
  {
    const { ctx, page } = await open({ nonceDelay: 0, nonceFails: true });
    await page.tap('.trigger');
    await page.waitForTimeout(400);
    const s = await state(page);
    ok('the dialog still opens', s.open);
    ok('and the button is not left permanently dead', !s.submitDisabled,
       'v4 disabled it and never re-enabled it, so the popup became a picture');
    await ctx.close();
  }

  group('Opening on a phone does not throw the keyboard at you');
  {
    const { ctx, page } = await open({ nonceDelay: 0 });
    await page.tap('.trigger');
    await page.waitForTimeout(450);
    const s = await state(page);
    ok('focus lands on the dialog, not in a text field', !/dipx-login-email|dipx-login-pass/.test(s.focused), s.focused);
    const tag = await page.evaluate(() => document.activeElement && document.activeElement.tagName);
    ok('so the viewport does not resize mid-animation', tag !== 'INPUT', String(tag));
    await ctx.close();
  }

  group('Nothing expensive is composited behind the card');
  {
    const { ctx, page } = await open({ nonceDelay: 0 });
    await page.tap('.trigger');
    await page.waitForTimeout(350);
    const filter = await page.evaluate(() => {
      const s = getComputedStyle(document.querySelector('.dipx-backdrop'));
      return (s.backdropFilter || s.webkitBackdropFilter || 'none');
    });
    ok('the backdrop is flat, not a full-viewport blur', filter === 'none' || filter === '',
       'backdrop-filter is the single most expensive thing a low-end GPU can be asked for here: ' + filter);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
    ok('and the card never overflows the viewport sideways', overflow);
    await ctx.close();
  }

  group('The card can be pushed away, the way a sheet closes');
  {
    const { ctx, page } = await open({ nonceDelay: 0 });
    await page.tap('.trigger');
    await page.waitForTimeout(350);
    /* The header is the handle: dragging anywhere else in the card has to stay
       available for scrolling a form that is taller than a small screen. */
    const box = await page.evaluate(() => {
      const r = document.querySelector('.dipx-header').getBoundingClientRect();
      return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });

    const cdp = await ctx.newCDPSession(page);
    async function drag(distance) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: box.x, y: box.y }] });
      for (let i = 1; i <= 6; i++) {
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: box.x, y: box.y + (distance * i) / 6 }] });
        await page.waitForTimeout(16);
      }
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await page.waitForTimeout(350);
    }

    await drag(40);
    ok('a short drag springs back', (await state(page)).open, 'a hesitant thumb must not close the form');

    await drag(220);
    ok('a long drag closes it', !(await state(page)).open);
    await page.waitForTimeout(300);
    ok('and the dialog is hidden afterwards', (await state(page)).hidden);
    await ctx.close();
  }

  group('The usual ways out still work');
  {
    const { ctx, page } = await open({ nonceDelay: 0 });
    await page.tap('.trigger');
    await page.waitForTimeout(300);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    ok('Escape closes', !(await state(page)).open);

    await page.tap('.trigger');
    await page.waitForTimeout(300);
    await page.tap('.dipx-backdrop', { position: { x: 20, y: 20 } }).catch(async () => {
      await page.evaluate(() => document.querySelector('.dipx-backdrop').click());
    });
    await page.waitForTimeout(300);
    ok('tapping outside closes', !(await state(page)).open);

    await page.tap('.trigger');
    await page.waitForTimeout(300);
    await page.tap('[data-dipx-tab="register"]');
    await page.waitForTimeout(120);
    const registering = await page.evaluate(() => !document.querySelector('.dipx-form-register').hidden);
    ok('the Inscription tab switches', registering);
    await ctx.close();
  }

  await browser.close();
  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail > 0 ? 1 : 0);
})();
