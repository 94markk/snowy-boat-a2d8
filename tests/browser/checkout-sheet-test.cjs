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

  <!-- what render_summary() prints when the request carries X-Delicat-Sheet -->
  <div class="dcs-summary" data-dcs-summary>
    <div class="dcs-card dcs-card--order">
      <div class="dcs-row"><span class="dcs-row__label">Produit</span>
        <span class="dcs-row__value dcs-row__value--strong">Free Fire 1080 Diamants</span></div>
      <div class="dcs-row"><span class="dcs-row__label">Player ID</span>
        <span class="dcs-row__value">PLAYER-999</span></div>
      <div class="dcs-row"><span class="dcs-row__label">Total</span>
        <span class="dcs-row__value dcs-row__value--strong"><span class="dcs-total">G450</span></span></div>
    </div>
    <div class="dcs-card dcs-card--wallet">
      <div class="dcs-row"><span class="dcs-row__label">Solde wallet</span>
        <span class="dcs-row__value dcs-row__value--strong">G1,250</span></div>
      <div class="dcs-row"><span class="dcs-row__label">Après paiement</span>
        <span class="dcs-row__value dcs-row__value--ok">G800</span></div>
    </div>
  </div>

  <form name="checkout" method="post" class="checkout woocommerce-checkout" action="/checkout/">
    <input type="hidden" name="woocommerce-process-checkout-nonce" value="REAL_NONCE_abc123">
    <div class="woocommerce-billing-fields"><h3>Vos coordonnées</h3>
      <p class="form-row"><label for="billing_first_name">Prénom</label>
        <input id="billing_first_name" class="input-text" name="billing_first_name" type="text"></p>
      <p class="form-row"><label for="billing_email">E-mail</label>
        <input id="billing_email" class="input-text" name="billing_email" type="email"></p>
      <p class="form-row"><label for="billing_phone">Téléphone</label>
        <input id="billing_phone" class="input-text" name="billing_phone" type="tel"></p>
    </div>
    <div id="order_review" class="woocommerce-checkout-review-order">
    <div id="payment"><ul class="payment_methods">
      <li class="payment_method_wallet"><input type="radio" name="payment_method" value="wallet" checked>
        <label>Portefeuille Delicat</label></li>
      <li class="payment_method_moncash"><input type="radio" name="payment_method" value="moncash">
        <label>MonCash</label></li>
    </ul>
    <div class="form-row place-order">
      <div class="woocommerce-terms-and-conditions-wrapper"><p class="form-row">
        <label class="checkbox"><input type="checkbox" name="terms" id="terms">
        <span>J’ai lu et j’accepte les <a href="#">conditions générales</a></span></label></p></div>
      <button type="submit" id="place_order" name="woocommerce_checkout_place_order">Payer maintenant G450</button>
    </div></div>
    </div>
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

  <dialog class="dcs" id="dcs-sheet" aria-label="Vérifiez votre commande">
    <button type="button" class="dcs__close" data-dcs-close aria-label="Fermer">x</button>
    <div class="dcs__scroll" data-dcs-scroll tabindex="-1" autofocus>
      <div class="dcs__grip" aria-hidden="true"></div>
      <div class="dcs__head">
        <span class="dcs__mark" aria-hidden="true">v</span>
        <h2 class="dcs__title">Vérifiez votre commande</h2>
        <p class="dcs__sub">Vérifiez tous les articles de votre panier.</p>
      </div>
      <div class="dcs__body" data-dcs-body><div class="dcs__loading" data-dcs-loading><p>Préparation…</p></div></div>
    </div>
    <div class="dcs__trust" data-dcs-trust hidden>
      <span>Paiement sécurisé WooCommerce</span><span>Délai selon le produit</span>
    </div>
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
      i18n: { title: 'Vérifiez votre commande', subtitle: 'Vérifiez tous les articles de votre panier.',
        secure: 'Paiement sécurisé WooCommerce', delay: 'Délai selon le produit',
        loading: 'Préparation…', close: 'Fermer',
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
    /* A phone viewport when the case is about layout - the sheet is a bottom
       sheet below 760px and a centred dialog above it. */
    const ctx = await b.newContext(opts.viewport ? { viewport: opts.viewport } : {});
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

  group('The summary the server rendered');

  {
    const { ctx, p } = await page();
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(300);

    const r = await p.evaluate(() => {
      const sheet = document.getElementById('dcs-sheet');
      const body = sheet.querySelector('[data-dcs-body]');
      const summary = sheet.querySelector('[data-dcs-summary]');
      const kids = Array.from(body.children).map((n) => n.className.split(' ')[0]);
      return {
        mounted: !!summary,
        total: (sheet.querySelector('.dcs-total') || {}).textContent || '',
        rows: sheet.querySelectorAll('.dcs-row').length,
        wallet: !!sheet.querySelector('.dcs-card--wallet'),
        order: kids,
        trustShown: !sheet.querySelector('[data-dcs-trust]').hidden
      };
    });

    ok('the order and wallet cards are mounted', r.mounted && r.wallet);
    ok('the total is the server\'s, character for character', r.total === 'G450',
      'a total recomputed in the browser is a total that can disagree with the one being charged');
    ok('every summary row survives', r.rows === 5, r.rows + ' rows');
    ok('notices sit above the summary, and the form below it',
      r.order.indexOf('dcs__notices') < r.order.indexOf('dcs-summary') &&
      r.order.indexOf('dcs-summary') < r.order.indexOf('checkout'), r.order.join(','));
    ok('the trust line appears only once there is a form to trust', r.trustShown);
    await ctx.close();
  }

  group('The pay button is reachable without scrolling');

  {
    /* This is the case that caught a real defect: the button was position:sticky
       inside WooCommerce's #payment, which confines it to that element's box, so
       it clamped 220px below the fold and a customer opened the sheet to no call
       to action at all. */
    const { ctx, p } = await page({ viewport: { width: 390, height: 844 } });
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(400);

    const r = await p.evaluate(() => {
      const sheet = document.getElementById('dcs-sheet');
      const btn = document.getElementById('place_order');
      const scroll = sheet.querySelector('[data-dcs-scroll]');
      const trust = sheet.querySelector('[data-dcs-trust]');
      const s = sheet.getBoundingClientRect();
      const b = btn.getBoundingClientRect();
      const t = trust.getBoundingClientRect();
      return {
        scrollTop: scroll.scrollTop,
        scrollable: scroll.scrollHeight > scroll.clientHeight + 1,
        inSheet: b.top >= s.top && b.bottom <= s.bottom,
        inViewport: b.bottom <= window.innerHeight + .5 && b.top >= 0,
        width: Math.round(b.width),
        sheetWidth: Math.round(s.width),
        /* The footer block, not just the button inside it: the block is opaque
           and bordered, so an overlap paints over the trust line even when the
           button's own glyphs happen to clear it. */
        aboveTrust: btn.closest('.place-order').getBoundingClientRect().bottom <= t.top + .5,
        padBottom: getComputedStyle(sheet).getPropertyValue('--dcs-pad-bottom').trim(),
        footerHeight: Math.round(btn.closest('.place-order').getBoundingClientRect().height),
        scrollPadBottom: Math.round(parseFloat(getComputedStyle(scroll).paddingBottom)),
        position: getComputedStyle(btn.closest('.place-order')).position,
        stillInForm: !!btn.closest('form.checkout')
      };
    });

    ok('the form is long enough for this to mean something', r.scrollable && r.scrollTop === 0);
    ok('the pay button is on screen the moment the sheet opens', r.inSheet && r.inViewport,
      'pinned, not parked at the end of a form nobody scrolls');
    ok('it stays inside the sheet, not off its right edge', r.width <= r.sheetWidth,
      r.width + 'px button in a ' + r.sheetWidth + 'px sheet');
    ok('the footer sits above the trust line rather than over it', r.aboveTrust);
    ok('the scroller reserves exactly the footer that is on screen',
      r.scrollPadBottom === r.footerHeight + 24,
      'reserved ' + r.scrollPadBottom + 'px for a ' + r.footerHeight + 'px footer');
    ok('it is still a child of WooCommerce\'s form', r.stillInForm,
      'WooCommerce binds submit to the form - a button outside it stops submitting');
    await ctx.close();
  }

  {
    const { ctx, p } = await page({ viewport: { width: 390, height: 844 } });
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(400);

    /* Scrolled to the very end, the last field must not be stuck under the
       footer - which is what the measured padding is for. */
    const r = await p.evaluate(() => {
      const sheet = document.getElementById('dcs-sheet');
      const scroll = sheet.querySelector('[data-dcs-scroll]');
      scroll.scrollTop = scroll.scrollHeight;
      const last = document.querySelectorAll('#payment ul.payment_methods li');
      const l = last[last.length - 1].getBoundingClientRect();
      const f = document.querySelector('#payment .place-order').getBoundingClientRect();
      return { clear: l.bottom <= f.top + .5, gap: Math.round(f.top - l.bottom) };
    });

    ok('scrolled to the end, the last payment method is clear of the footer',
      r.clear, 'overlap of ' + -r.gap + 'px');
    await ctx.close();
  }

  {
    /* WooCommerce replaces .woocommerce-checkout-review-order wholesale on every
       update_checkout, taking the footer with it. The measurement has to follow
       the element that is actually on screen. */
    const { ctx, p } = await page({ viewport: { width: 390, height: 844 } });
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(400);

    const r = await p.evaluate(async () => {
      const sheet = document.getElementById('dcs-sheet');
      const before = getComputedStyle(sheet).getPropertyValue('--dcs-pad-bottom').trim();

      /* Stand in for WooCommerce's own replacement, then announce it the way it
         does. */
      const review = document.querySelector('#dcs-sheet .woocommerce-checkout-review-order');
      review.innerHTML = '<div id="payment"><div class="form-row place-order">' +
        '<div class="woocommerce-terms-and-conditions-wrapper"><p class="form-row">' +
        '<label class="checkbox"><input type="checkbox" name="terms"><span>' +
        'J’ai lu et j’accepte les conditions générales de vente, la politique ' +
        'de confidentialité et les délais de livraison indiqués sur cette page.' +
        '</span></label></p></div>' +
        '<button type="submit" id="place_order">Payer</button></div></div>';

      (window.__handlers.updated_checkout || []).forEach((fn) => fn());
      await new Promise((r) => setTimeout(r, 250));

      const btn = document.getElementById('place_order');
      const b = btn.getBoundingClientRect();
      const s = sheet.getBoundingClientRect();
      const scroll = sheet.querySelector('[data-dcs-scroll]');
      return {
        before,
        after: getComputedStyle(sheet).getPropertyValue('--dcs-pad-bottom').trim(),
        footerHeight: Math.round(btn.closest('.place-order').getBoundingClientRect().height),
        scrollPadBottom: Math.round(parseFloat(getComputedStyle(scroll).paddingBottom)),
        stillPinned: b.bottom <= s.bottom && b.top >= s.top && b.bottom <= window.innerHeight + .5
      };
    });

    ok('the reserved room follows the replacement footer, not the old one',
      r.scrollPadBottom === r.footerHeight + 24 && r.after !== r.before,
      r.before + ' -> ' + r.after + ' for a ' + r.footerHeight + 'px footer');
    ok('and the new pay button is pinned like the old one', r.stillPinned);

    /* Re-measuring once is not the same as watching. The footer changes height
       after the swap too - a longer terms line, a gateway that adds a note - and
       the replacement element has to be under observation, not just read once. */
    const after = await p.evaluate(async () => {
      const sheet = document.getElementById('dcs-sheet');
      const scroll = sheet.querySelector('[data-dcs-scroll]');
      const footer = document.querySelector('#payment .place-order');

      const note = document.createElement('p');
      note.textContent = 'Un délai de traitement de 5 à 10 minutes peut ' +
        's’appliquer sur les recharges effectuées en dehors des heures ouvrables.';
      footer.appendChild(note);

      await new Promise((r) => setTimeout(r, 250));
      return {
        footerHeight: Math.round(footer.getBoundingClientRect().height),
        reserved: Math.round(parseFloat(getComputedStyle(scroll).paddingBottom))
      };
    });

    ok('a footer that grows after the swap is still being watched',
      after.reserved === after.footerHeight + 24,
      'reserved ' + after.reserved + 'px for a ' + after.footerHeight + 'px footer');
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
