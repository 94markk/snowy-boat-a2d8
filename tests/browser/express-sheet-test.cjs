/* The express sheet drives the money path, so it is tested against a stubbed
   server rather than reasoned about. Each case asserts one property that, if it
   broke, would either lose a sale or take a payment wrongly. */
const { chromium } = require('playwright');
const fs = require('fs');

const JS = fs.readFileSync('/home/user/snowy-boat-a2d8/delicat-builder-v9/assets/js/express-sheet.js', 'utf8');
const CSS = fs.readFileSync('/home/user/snowy-boat-a2d8/delicat-builder-v9/assets/css/express-sheet.css', 'utf8');

/* What WooCommerce replies with after a buy-now POST: the checkout page. */
const CHECKOUT_PAGE = `<!doctype html><html><body>
  <div class="woocommerce-message">Produit ajouté au panier.</div>

  <!-- what the checkout template prints and the express sheet does not want -->
  <div class="woocommerce-form-coupon-toggle">
    <div class="woocommerce-info">Avez-vous un code promo ?
      <a href="#" class="showcoupon">Cliquez ici pour saisir votre code</a></div>
  </div>

  <!-- what render_summary() prints when the request carries X-Delicat-Express -->
  <div class="dxs-summary" data-dxs-summary__SHORT__>
    <div class="dxs-card dxs-card--order">
      <div class="dxs-row"><span class="dxs-row__label">Produit</span>
        <span class="dxs-row__value dxs-row__value--strong">Free Fire 1080 Diamants</span></div>
      <div class="dxs-row"><span class="dxs-row__label">Player ID</span>
        <span class="dxs-row__value">PLAYER-999</span></div>
      <div class="dxs-row"><span class="dxs-row__label">Total</span>
        <span class="dxs-row__value dxs-row__value--strong"><span class="dxs-total">G450</span></span></div>
    </div>
    <div class="dxs-card dxs-card--wallet">
      <div class="dxs-row"><span class="dxs-row__label">Solde wallet</span>
        <span class="dxs-row__value dxs-row__value--strong">G1,250</span></div>
      <div class="dxs-row"><span class="dxs-row__label">Après paiement</span>
        <span class="dxs-row__value dxs-row__value--ok">G800</span></div>
    </div>
  </div>

  <form name="checkout" method="post" class="checkout woocommerce-checkout" action="/checkout/">
    <input type="hidden" name="woocommerce-process-checkout-nonce" value="REAL_NONCE_abc123">
    <h3>Coordonnées</h3>
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
      <div class="woocommerce-privacy-policy-text"><p>Vos données personnelles seront
        utilisées pour traiter votre commande, selon notre
        <a href="#">politique de confidentialité</a>.</p></div>
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

  <dialog class="dxs" id="dxs-sheet" aria-label="Vérifiez votre commande" style='--dxs-paying:"Paiement en cours…"'>
    <button type="button" class="dxs__close" data-dxs-close aria-label="Fermer">x</button>
    <div class="dxs__scroll" data-dxs-scroll tabindex="-1" autofocus>
      <div class="dxs__grip" aria-hidden="true"></div>
      <div class="dxs__head">
        <span class="dxs__mark" aria-hidden="true">v</span>
        <h2 class="dxs__title">Vérifiez votre commande</h2>
        <p class="dxs__sub">Vérifiez tous les articles de votre panier.</p>
      </div>
      <div class="dxs__body" data-dxs-body><div class="dxs__loading" data-dxs-loading><p>Préparation…</p></div></div>
    </div>
    <div class="dxs__trust" data-dxs-trust hidden>
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
    window.DelicatExpressSheet = {
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
        return route.fulfill({ contentType: 'text/html',
          body: CHECKOUT_PAGE.replace('__SHORT__', opts.short ? ' data-dxs-short="1"' : '') });
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
    ok('the sheet opens on the tap', await p.evaluate(() => document.getElementById('dxs-sheet').open));
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
      hasForm: !!document.querySelector('#dxs-sheet form.checkout'),
      nonce: (document.querySelector('#dxs-sheet [name="woocommerce-process-checkout-nonce"]') || {}).value || '',
      hasPayment: !!document.querySelector('#dxs-sheet #payment'),
      hasPlaceOrder: !!document.querySelector('#dxs-sheet #place_order'),
      notice: !!document.querySelector('#dxs-sheet .woocommerce-message'),
      scripts: document.querySelectorAll('#dxs-sheet script').length,
      evilRan: !!window.__EVIL_RAN,
      triggered: window.__triggered,
      loadingGone: !document.querySelector('#dxs-sheet [data-dxs-loading]')
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
      const sheet = document.getElementById('dxs-sheet');
      const body = sheet.querySelector('[data-dxs-body]');
      const summary = sheet.querySelector('[data-dxs-summary]');
      const kids = Array.from(body.children).map((n) => n.className.split(' ')[0]);
      return {
        mounted: !!summary,
        total: (sheet.querySelector('.dxs-total') || {}).textContent || '',
        rows: sheet.querySelectorAll('.dxs-row').length,
        wallet: !!sheet.querySelector('.dxs-card--wallet'),
        order: kids,
        trustShown: !sheet.querySelector('[data-dxs-trust]').hidden
      };
    });

    ok('the order and wallet cards are mounted', r.mounted && r.wallet);
    ok('the total is the server\'s, character for character', r.total === 'G450',
      'a total recomputed in the browser is a total that can disagree with the one being charged');
    ok('every summary row survives', r.rows === 5, r.rows + ' rows');
    ok('notices sit above the summary, and the form below it',
      r.order.indexOf('dxs__notices') < r.order.indexOf('dxs-summary') &&
      r.order.indexOf('dxs-summary') < r.order.indexOf('checkout'), r.order.join(','));
    ok('the trust line appears only once there is a form to trust', r.trustShown);
    await ctx.close();
  }

  group('What the express sheet leaves out');

  {
    /*
     * The checkout page carries things the express panel is not for: a coupon
     * prompt, WooCommerce's privacy paragraph, and a heading above the card
     * that already carries the same title. They are hidden in the sheet's own
     * stylesheet rather than unhooked, so the full checkout page keeps every
     * one of them - which is where a customer goes for a coupon.
     */
    const { ctx, p } = await page();
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(300);

    const r = await p.evaluate(() => {
      const sheet = document.getElementById('dxs-sheet');
      const shows = (sel) => {
        const el = sheet.querySelector(sel);
        return el ? el.getBoundingClientRect().height > 0 : false;
      };
      return {
        couponBlock: shows('.woocommerce-form-coupon-toggle'),
        /* The words, anywhere in the panel. The prompt does not arrive as the
           block the stylesheet hides - it arrives as the .woocommerce-info
           inside it, lifted out by extractNotices - so looking for the block is
           looking in the wrong place. */
        couponPrompt: /code promo/i.test(sheet.textContent || ''),
        showCoupon: !!sheet.querySelector('.showcoupon'),
        /* Visibility, not words: this block IS in the sheet - it sits inside
           .place-order, which is the pinned footer - and the stylesheet hides
           it. textContent reports hidden text too, so looking for the words
           would fail even when the rule is working. */
        privacy: shows('.woocommerce-privacy-policy-text'),
        privacyPresent: !!sheet.querySelector('.woocommerce-privacy-policy-text'),
        strayHeading: shows('form.checkout > h3'),
        cardTitle: shows('.woocommerce-billing-fields h3'),
        fields: sheet.querySelectorAll('form.checkout input[name^="billing_"]').length,
        terms: !!sheet.querySelector('input[name="terms"]')
      };
    });

    ok('the coupon block is not shown', !r.couponBlock);
    ok('and the prompt is nowhere in the panel', !r.couponPrompt && !r.showCoupon,
      'extractNotices lifts .woocommerce-info out of the block the CSS hides');
    ok('the privacy paragraph is mounted but not shown', r.privacyPresent && !r.privacy,
      'present=' + r.privacyPresent + ' visible=' + r.privacy);
    ok('nor the heading above the card of the same name', !r.strayHeading);
    ok('the card keeps its own title', r.cardTitle);
    ok('and every field is still there', r.fields === 3 && r.terms,
      r.fields + ' billing fields, terms=' + r.terms);
    await ctx.close();
  }

  group('While the payment is going through');

  {
    const { ctx, p } = await page();
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(300);

    const r = await p.evaluate(() => {
      const btn = document.getElementById('place_order');
      const before = { label: getComputedStyle(btn, '::before').content,
                       after: getComputedStyle(btn, '::after').content };

      /* The class WooCommerce puts on its form while it submits. */
      document.querySelector('#dxs-sheet form.checkout').classList.add('processing');

      const cs = getComputedStyle(btn);
      const ring = getComputedStyle(btn, '::after');
      return {
        before,
        says: getComputedStyle(btn, '::before').content,
        ownLabelHidden: parseFloat(cs.fontSize) === 0,
        untappable: cs.pointerEvents === 'none',
        ringRound: ring.borderTopLeftRadius,
        spinning: ring.animationName
      };
    });

    ok('the button says nothing extra while idle', r.before.label === 'none', r.before.label);
    ok('it says what is happening once submitting', /Paiement en cours/.test(r.says), r.says);
    ok('in the translated words, not a hard-coded default',
      r.says.indexOf('var(') === -1 && r.says !== 'none', r.says);
    ok('its own label is out of the way', r.ownLabelHidden);
    ok('a second tap cannot reach it', r.untappable);
    ok('and the arrow has become a spinner',
      r.ringRound === '50%' && r.spinning === 'dxs-spin', JSON.stringify(r));
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
      const sheet = document.getElementById('dxs-sheet');
      const btn = document.getElementById('place_order');
      const scroll = sheet.querySelector('[data-dxs-scroll]');
      const trust = sheet.querySelector('[data-dxs-trust]');
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
        padBottom: getComputedStyle(sheet).getPropertyValue('--dxs-pad-bottom').trim(),
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
      const sheet = document.getElementById('dxs-sheet');
      const scroll = sheet.querySelector('[data-dxs-scroll]');
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
      const sheet = document.getElementById('dxs-sheet');
      const before = getComputedStyle(sheet).getPropertyValue('--dxs-pad-bottom').trim();

      /* Stand in for WooCommerce's own replacement, then announce it the way it
         does. */
      const review = document.querySelector('#dxs-sheet .woocommerce-checkout-review-order');
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
      const scroll = sheet.querySelector('[data-dxs-scroll]');
      return {
        before,
        after: getComputedStyle(sheet).getPropertyValue('--dxs-pad-bottom').trim(),
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
      const sheet = document.getElementById('dxs-sheet');
      const scroll = sheet.querySelector('[data-dxs-scroll]');
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

  group('A wallet that will not cover the order');

  {
    /*
     * Announced with the summary, not after the customer has filled the form in
     * and pressed pay. The server compares the balance against the total and
     * says so on the summary wrapper; nothing here does the arithmetic.
     */
    const { ctx, p } = await page({ short: true });
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(400);

    const r = await p.evaluate(() => {
      const sheet = document.getElementById('dxs-sheet');
      const panel = sheet.querySelector('[data-dxs-low]');
      return {
        shown: !!panel,
        title: panel ? (panel.querySelector('h3') || {}).textContent : '',
        wallet: panel ? (panel.querySelector('a.dxs__btn--primary') || {}).href : '',
        formStillThere: !!sheet.querySelector('form.checkout'),
        gatewaysStillThere: sheet.querySelectorAll('#payment input[name="payment_method"]').length,
        payButtonUsable: !!document.getElementById('place_order') &&
          getComputedStyle(document.getElementById('place_order')).pointerEvents !== 'none',
        demoted: sheet.classList.contains('dxs--low')
      };
    });

    ok('the customer is told before they fill anything in', r.shown);
    ok('it says what happened', r.title === 'Solde insuffisant', r.title);
    ok('and gives them the way out', r.wallet === 'https://shop.test/my-wallet/', r.wallet);
    ok('WooCommerce\'s form is untouched', r.formStillThere && r.gatewaysStillThere === 2,
      r.gatewaysStillThere + ' gateways');
    ok('another payment method is still usable', r.payButtonUsable,
      'a short wallet must not block a card');
    ok('but the pay button stops competing with the top-up', r.demoted);
    await ctx.close();
  }

  {
    /* A wallet that covers it says nothing at all. */
    const { ctx, p } = await page();
    await p.click('.dnp-buy-now');
    await p.waitForTimeout(400);
    const shown = await p.evaluate(() => !!document.querySelector('#dxs-sheet [data-dxs-low]'));
    ok('a wallet with enough in it is not warned about', !shown);
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
      const low = document.querySelector('[data-dxs-low]');
      return {
        shown: !!low,
        title: low ? low.querySelector('h3').textContent : '',
        href: low ? low.querySelector('a').getAttribute('href') : '',
        formStillThere: !!document.querySelector('#dxs-sheet form.checkout')
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
      1 === (await p.evaluate(() => document.querySelectorAll('[data-dxs-low]').length)));

    /* Dismiss returns to the form. */
    await p.click('[data-dxs-dismiss-low]');
    ok('dismissing it returns to the form',
      0 === (await p.evaluate(() => document.querySelectorAll('[data-dxs-low]').length)));
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
      0 === (await p.evaluate(() => document.querySelectorAll('[data-dxs-low]').length)),
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
      0 === (await p.evaluate(() => document.querySelectorAll('[data-dxs-low]').length)));
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
    const navigated = await p.evaluate(() => !document.getElementById('dxs-sheet'));
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
    const wentToServer = await p.evaluate(() => !document.getElementById('dxs-sheet'));
    ok('a variable product with nothing chosen is left to WooCommerce',
      wentToServer, 'WooCommerce explains that better than a sheet could');
    await ctx.close();
  }

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  await b.close();
  process.exit(fail ? 1 : 0);
})();
