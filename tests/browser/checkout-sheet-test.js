/* The checkout sheet drives the money path, so it is tested against a stubbed
   server rather than reasoned about. Each case asserts one property that, if it
   broke, would either lose a sale or take a payment wrongly. */
const { chromium } = require('playwright');
const fs = require('fs');

const JS = fs.readFileSync('/home/user/snowy-boat-a2d8/delicat-builder-v9/assets/js/checkout-sheet.js', 'utf8');
const CSS = fs.readFileSync('/home/user/snowy-boat-a2d8/delicat-builder-v9/assets/css/checkout-sheet.css', 'utf8');

/* What WooCommerce replies with after a buy-now POST: the checkout page. */
const CHECKOUT_PAGE = `<!doctype html><html><body>
  <div class="woocommerce-message">Produit ajouté au panier.</div>
  <form name="checkout" method="post" class="checkout woocommerce-checkout" action="/checkout/">
    <input type="hidden" name="woocommerce-process-checkout-nonce" value="REAL_NONCE_abc123">
    <p class="form-row"><label for="billing_first_name">Prénom</label>
      <input id="billing_first_name" class="input-text" name="billing_first_name" type="text"></p>
    <div id="payment"><ul class="payment_methods">
      <li class="payment_method_wallet"><input type="radio" name="payment_method" value="wallet" checked>
        <label>Portefeuille Delicat</label></li>
    </ul>
    <button type="submit" id="place_order" name="woocommerce_checkout_place_order">Commander</button></div>
  </form>
  <script id="evil">window.__EVIL_RAN = true;</script>
</body></html>`;

const PRODUCT_PAGE = (extra) => `<!doctype html><html><head><style>${CSS}</style></head><body>
  <form class="cart variations_form" action="https://shop.test/produit/free-fire/" method="post">
    <input type="hidden" name="variation_id" value="42">
    <input type="hidden" name="add-to-cart" value="7">
    <input type="text" name="dmc_player_id" value="PLAYER-999">
    <input type="number" name="quantity" value="2">
    <button type="submit" class="button alt" name="add-to-cart" value="7">Ajouter au panier</button>
    <button type="submit" class="button alt dnp-buy-now" name="delicat_native_buy_now" value="1">Acheter maintenant</button>
  </form>

  <dialog class="dcs" id="dcs-sheet" aria-label="Finaliser la commande">
    <div class="dcs__grip"></div>
    <div class="dcs__head"><h2 class="dcs__title">Finaliser la commande</h2>
      <button type="button" class="dcs__close" data-dcs-close>x</button></div>
    <div class="dcs__body" data-dcs-body><div class="dcs__loading" data-dcs-loading><p>Préparation…</p></div></div>
  </dialog>

  <script>
    /* A jQuery stub narrow enough to prove exactly what the file does with it:
       trigger events on document.body and listen for checkout_error. */
    window.__triggered = [];
    window.__handlers = {};
    window.jQuery = function (target) {
      return {
        trigger: function (name) { window.__triggered.push(name); return this; },
        on: function (name, fn) { (window.__handlers[name] = window.__handlers[name] || []).push(fn); return this; }
      };
    };
    window.DelicatCheckoutSheet = {
      checkoutUrl: 'https://shop.test/checkout/',
      walletUrl: ${extra.wallet === false ? "''" : "'https://shop.test/my-wallet/'"},
      lowFunds: ['insufficient', 'solde', 'insuffisant', 'fonds', 'not enough'],
      i18n: { title: 'Finaliser la commande', loading: 'Préparation…', close: 'Fermer',
        lowTitle: 'Solde insuffisant', lowBody: 'Rechargez votre compte.',
        lowAction: 'Recharger mon compte', lowSecondary: 'Retour' }
    };
  </script>
  <script>${JS}</script>
</body></html>`;

let pass = 0, fail = 0;
const ok = (what, cond, detail = '') => {
  if (cond) { pass++; console.log('  ok    ' + what); }
  else { fail++; console.log('  FAIL  ' + what + (detail ? '  -- ' + detail : '')); }
};
const group = (n) => console.log('\n' + n + '\n' + '-'.repeat(n.length));

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  async function page(opts = {}) {
    const ctx = await b.newContext();
    const p = await ctx.newPage();
    const posts = [];
    await ctx.route('https://shop.test/produit/**', async (route) => {
      const req = route.request();
      if (req.method() === 'POST') {
        posts.push(req.postData() || '');
        if (opts.serverFails) return route.fulfill({ status: 500, body: 'boom' });
        if (opts.noForm) return route.fulfill({ contentType: 'text/html', body: '<html><body>nope</body></html>' });
        return route.fulfill({ contentType: 'text/html', body: CHECKOUT_PAGE });
      }
      return route.fulfill({ contentType: 'text/html', body: PRODUCT_PAGE(opts) });
    });
    await ctx.route('https://shop.test/checkout/**', (r) =>
      r.fulfill({ contentType: 'text/html', body: '<html><body id="real-checkout-page">checkout</body></html>' }));
    await ctx.route('https://shop.test/my-wallet/**', (r) =>
      r.fulfill({ contentType: 'text/html', body: '<html><body id="wallet">wallet</body></html>' }));
    await p.goto('https://shop.test/produit/free-fire/');
    return { ctx, p, posts };
  }

  /* ------------------------------------------------------------------ */
  group('The request WooCommerce receives');

  {
    const { ctx, p, posts } = await page();
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(300);

    const body = posts[0] || '';
    ok('the sheet opens on the tap', await p.evaluate(() => document.getElementById('dcs-sheet').open));
    ok('the POST carries the Player ID field', body.includes('dmc_player_id') && body.includes('PLAYER-999'),
      'a custom product field lost here is an order the supplier cannot fulfil');
    ok('the POST carries the chosen variation', body.includes('variation_id') && body.includes('42'));
    ok('the POST carries the quantity', body.includes('quantity') && body.includes('2'));
    ok('the POST carries the buy-now button name', body.includes('delicat_native_buy_now'),
      'this is what tells WooCommerce to redirect to checkout rather than to the cart');
    ok('exactly one request was made', posts.length === 1, posts.length + ' made');
    await ctx.close();
  }

  group('What lands in the sheet');

  {
    const { ctx, p } = await page();
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(300);

    const r = await p.evaluate(() => ({
      hasForm: !!document.querySelector('#dcs-sheet form.checkout'),
      nonce: (document.querySelector('#dcs-sheet [name="woocommerce-process-checkout-nonce"]') || {}).value || '',
      hasPayment: !!document.querySelector('#dcs-sheet #payment'),
      hasPlaceOrder: !!document.querySelector('#dcs-sheet #place_order'),
      notice: !!document.querySelector('#dcs-sheet .woocommerce-message'),
      scripts: document.querySelectorAll('#dcs-sheet script').length,
      evilRan: !!window.__EVIL_RAN,
      triggered: window.__triggered,
      loadingGone: !document.querySelector('#dcs-sheet [data-dcs-loading]')
    }));

    ok('WooCommerce\'s own checkout form is mounted', r.hasForm);
    ok('its real nonce travels with it, unaltered', r.nonce === 'REAL_NONCE_abc123',
      'a nonce this file minted would be a nonce this file could get wrong');
    ok('the payment methods come too', r.hasPayment && r.hasPlaceOrder);
    ok('a notice WooCommerce attached is shown', r.notice);
    ok('no script from the response is mounted', r.scripts === 0);
    ok('and none of them executed', r.evilRan === false,
      'the response is parsed inert; only the form element is adopted');
    ok('WooCommerce is told its form has arrived', r.triggered.includes('init_checkout'),
      'this is the line that hands over control');
    ok('the loading state is replaced, not left behind', r.loadingGone);
    await ctx.close();
  }

  group('Insufficient funds');

  {
    const { ctx, p } = await page();
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(300);

    await p.evaluate(() => {
      (window.__handlers.checkout_error || []).forEach((fn) =>
        fn({}, '<ul class="woocommerce-error"><li>Solde insuffisant dans votre portefeuille.</li></ul>'));
    });

    const r = await p.evaluate(() => {
      const low = document.querySelector('[data-dcs-low]');
      return {
        shown: !!low,
        title: low ? low.querySelector('h3').textContent : '',
        href: low ? low.querySelector('a').getAttribute('href') : '',
        formStillThere: !!document.querySelector('#dcs-sheet form.checkout')
      };
    });

    ok('a low-balance decline shows the recharge panel', r.shown);
    ok('it says what happened', r.title === 'Solde insuffisant');
    ok('and gives a button to the wallet', r.href === 'https://shop.test/my-wallet/');
    ok('WooCommerce\'s form stays, so another method can be chosen', r.formStillThere,
      'the panel is added above the form, never instead of it');

    /* Twice must not stack. */
    await p.evaluate(() => {
      (window.__handlers.checkout_error || []).forEach((fn) => fn({}, 'solde insuffisant'));
    });
    ok('a second decline does not stack a second panel',
      1 === (await p.evaluate(() => document.querySelectorAll('[data-dcs-low]').length)));

    /* Dismiss returns to the form. */
    await p.click('[data-dcs-dismiss-low]');
    ok('dismissing it returns to the form',
      0 === (await p.evaluate(() => document.querySelectorAll('[data-dcs-low]').length)));
    await ctx.close();
  }

  {
    const { ctx, p } = await page();
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(300);
    await p.evaluate(() => {
      (window.__handlers.checkout_error || []).forEach((fn) =>
        fn({}, '<ul class="woocommerce-error"><li>Veuillez renseigner votre numéro de téléphone.</li></ul>'));
    });
    ok('an ordinary validation error does NOT offer a top-up',
      0 === (await p.evaluate(() => document.querySelectorAll('[data-dcs-low]').length)),
      'offering a top-up for a missing phone number would be nonsense');
    await ctx.close();
  }

  {
    const { ctx, p } = await page({ wallet: false });
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(300);
    await p.evaluate(() => {
      (window.__handlers.checkout_error || []).forEach((fn) => fn({}, 'solde insuffisant'));
    });
    ok('a store with no wallet page shows no dead button',
      0 === (await p.evaluate(() => document.querySelectorAll('[data-dcs-low]').length)));
    await ctx.close();
  }

  group('Every failure lands on the real checkout');

  {
    const { ctx, p } = await page({ serverFails: true });
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(600);
    ok('a server error sends the customer to the checkout page',
      !!(await p.$('#real-checkout-page')), 'url is ' + p.url());
    await ctx.close();
  }

  {
    const { ctx, p } = await page({ noForm: true });
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(600);
    ok('a reply with no checkout form does the same',
      !!(await p.$('#real-checkout-page')), 'url is ' + p.url());
    await ctx.close();
  }

  group('What it does not intercept');

  {
    const { ctx, p, posts } = await page();
    /* An un-intercepted submit NAVIGATES, which is the proof: the sheet is gone
       because the document is. Checking for the dialog would throw, which is how
       this assertion was written the first time. */
    await p.click('button[name="add-to-cart"]');
    await p.waitForTimeout(400);
    const navigated = await p.evaluate(() => !document.getElementById('dcs-sheet'));
    ok('plain "Ajouter au panier" is left alone and submits normally',
      navigated && posts.length === 1,
      'only the buy-now button opens the sheet');
    await ctx.close();
  }

  {
    const { ctx, p, posts } = await page();
    await p.evaluate(() => { document.querySelector('[name="variation_id"]').value = ''; });
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(400);
    const wentToServer = await p.evaluate(() => !document.getElementById('dcs-sheet'));
    ok('a variable product with nothing chosen is left to WooCommerce',
      wentToServer, 'WooCommerce explains that better than a sheet could');
    await ctx.close();
  }

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  await b.close();
  process.exit(fail ? 1 : 0);
})();
