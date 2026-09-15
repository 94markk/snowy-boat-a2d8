/* The checkout sheet is not on the product page until someone reaches for it.
   ---------------------------------------------------------------------------
   Its CSS and JS are about 44 KB, and they existed on every product page to
   support one button most visits never press. On the connections this shop
   sells over that is a slower product page for everybody, bought for the few
   who buy.

   So they load on intent. Which means the loader is now standing between a
   customer and their purchase, and these cases are about that: the tap must
   still work, once, whether or not the download has arrived, and whether the
   customer used a finger or a keyboard.

   The loader under test is the REAL one, printed by the plugin through
   scripts/emit-checkout-loader.php - not a copy of it.

   Run: NODE_PATH=/path/to/node_modules node tests/browser/checkout-loader-test.cjs
*/
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');

const ROOT = '/home/user/snowy-boat-a2d8';
const BASE = 'https://shop.test/plugin/';
const LOADER = execFileSync('php', [ROOT + '/scripts/emit-checkout-loader.php', BASE], { encoding: 'utf8' });
const SHEET_JS = fs.readFileSync(ROOT + '/delicat-builder-v9/assets/js/checkout-sheet.js', 'utf8');
const SHEET_CSS = fs.readFileSync(ROOT + '/delicat-builder-v9/assets/css/checkout-sheet.css', 'utf8');

const CHECKOUT_PAGE = `<!doctype html><html><body>
  <div class="dcs-summary" data-dcs-summary>
    <div class="dcs-card dcs-card--order">
      <div class="dcs-row"><span class="dcs-row__label">Produit</span>
        <span class="dcs-row__value dcs-row__value--strong">Free Fire</span></div>
    </div>
  </div>
  <form name="checkout" method="post" class="checkout woocommerce-checkout" action="/commander/">
    <input type="hidden" name="woocommerce-process-checkout-nonce" value="REAL_NONCE_abc123">
    <div id="order_review" class="woocommerce-checkout-review-order"><div id="payment">
      <ul class="payment_methods"><li><input type="radio" name="payment_method" value="wallet" checked>
        <label>Portefeuille</label></li></ul>
      <div class="form-row place-order">
        <button type="submit" id="place_order" name="woocommerce_checkout_place_order">Payer</button>
      </div></div></div>
  </form>
</body></html>`;

const PRODUCT_PAGE = `<!doctype html><html><head><meta charset="utf-8"></head><body>
  <form class="cart" action="https://shop.test/produit/free-fire/" method="post">
    <input type="hidden" name="add-to-cart" value="7">
    <input type="text" name="dmc_player_id" value="PLAYER-999">
    <button type="submit" class="button alt dnp-buy-now" name="delicat_native_buy_now" value="1">Acheter maintenant</button>
  </form>

  <dialog class="dcs" id="dcs-sheet" aria-label="Vérifiez votre commande" style='--dcs-paying:"Paiement en cours…"'>
    <button type="button" class="dcs__close" data-dcs-close>x</button>
    <div class="dcs__scroll" data-dcs-scroll tabindex="-1" autofocus>
      <div class="dcs__body" data-dcs-body><div class="dcs__loading" data-dcs-loading><p>…</p></div></div>
    </div>
    <div class="dcs__trust" data-dcs-trust hidden><span>sécurisé</span></div>
  </dialog>

  <script>
    window.__triggered = []; window.__handlers = {};
    window.jQuery = function () { return {
      trigger: function (n) { window.__triggered.push(n); return this; },
      on: function (n, f) { (window.__handlers[n] = window.__handlers[n] || []).push(f); return this; } }; };
  </script>
  <script>${LOADER}</script>
</body></html>`;

let pass = 0, fail = 0;
const ok = (w, c, d = '') => { c ? (pass++, console.log('  ok    ' + w)) : (fail++, console.log('  FAIL  ' + w + (d ? '  -- ' + d : ''))); };
const group = (n) => console.log('\n' + n + '\n' + '-'.repeat(n.length));

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  async function open() {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
    const p = await ctx.newPage();
    const posts = [];
    await ctx.route('https://shop.test/**', async (route) => {
      const req = route.request();
      const u = new URL(req.url());
      if (u.pathname.endsWith('checkout-sheet.js')) return route.fulfill({ contentType: 'text/javascript', body: SHEET_JS });
      if (u.pathname.endsWith('checkout-sheet.css')) return route.fulfill({ contentType: 'text/css', body: SHEET_CSS });
      if (req.method() === 'POST') { posts.push(req.postData() || ''); return route.fulfill({ contentType: 'text/html', body: CHECKOUT_PAGE }); }
      if (u.pathname.startsWith('/commander')) return route.fulfill({ contentType: 'text/html', body: '<html><body id="real-checkout">checkout</body></html>' });
      return route.fulfill({ contentType: 'text/html', body: PRODUCT_PAGE });
    });
    await p.goto('https://shop.test/produit/free-fire/');
    await p.waitForTimeout(150);
    return { ctx, p, posts };
  }

  const loaded = (p) => p.evaluate(() => ({
    css: !!document.querySelector('link[href*="checkout-sheet.css"]'),
    js: !!document.querySelector('script[src*="checkout-sheet.js"]'),
    config: !!window.DelicatCheckoutSheet
  }));

  group('The product page does not carry the checkout');

  {
    const { ctx, p } = await open();
    const r = await loaded(p);
    ok('no sheet stylesheet is fetched', !r.css);
    ok('no sheet script is fetched', !r.js);
    ok('only the settings it will need are inline', r.config,
      'the config is bytes, the assets are kilobytes');
    await ctx.close();
  }

  group('Reaching for it loads it');

  {
    const { ctx, p } = await open();
    await p.dispatchEvent('.dnp-buy-now', 'pointerdown');
    await p.waitForTimeout(400);
    const r = await loaded(p);
    ok('a finger on the button starts the download', r.css && r.js,
      'css=' + r.css + ' js=' + r.js);
    await ctx.close();
  }

  group('And the purchase still goes through');

  {
    /* The ordinary tap: pointerdown warms it, the submit follows. */
    const { ctx, p, posts } = await open();
    await p.tap('.dnp-buy-now');
    await p.waitForTimeout(900);

    const r = await p.evaluate(() => ({
      open: document.getElementById('dcs-sheet').open,
      form: !!document.querySelector('#dcs-sheet form.checkout'),
      nonce: (document.querySelector('#dcs-sheet [name="woocommerce-process-checkout-nonce"]') || {}).value || '',
      onRealCheckout: !!document.getElementById('real-checkout')
    }));
    ok('the sheet opens', r.open && r.form, JSON.stringify(r));
    ok('with WooCommerce\'s own nonce', r.nonce === 'REAL_NONCE_abc123', r.nonce);
    ok('the cart was posted exactly once', posts.length === 1, posts.length + ' posts');
    ok('and the custom product field travelled with it',
      (posts[0] || '').includes('PLAYER-999') && (posts[0] || '').includes('delicat_native_buy_now'));
    await ctx.close();
  }

  {
    /* No pointerdown at all - a keyboard, or a tap the browser reported
       differently. The submit itself has to hold the purchase and resume it. */
    const { ctx, p, posts } = await open();
    await p.evaluate(() => {
      const f = document.querySelector('form.cart');
      f.requestSubmit(f.querySelector('[name="delicat_native_buy_now"]'));
    });
    await p.waitForTimeout(900);

    const r = await p.evaluate(() => ({
      open: document.getElementById('dcs-sheet').open,
      form: !!document.querySelector('#dcs-sheet form.checkout'),
      onRealCheckout: !!document.getElementById('real-checkout')
    }));
    ok('a submit with nothing warmed still opens the sheet', r.open && r.form, JSON.stringify(r));
    ok('and still posts exactly once', posts.length === 1, posts.length + ' posts');
    ok('the customer is not dumped on the checkout page instead', !r.onRealCheckout);
    await ctx.close();
  }

  group('If the sheet cannot be fetched, the sale is not lost');

  {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
    const p = await ctx.newPage();
    await ctx.route('https://shop.test/**', async (route) => {
      const u = new URL(route.request().url());
      if (u.pathname.endsWith('checkout-sheet.js')) return route.fulfill({ status: 500, body: 'boom' });
      if (u.pathname.endsWith('checkout-sheet.css')) return route.fulfill({ contentType: 'text/css', body: '' });
      if (u.pathname.startsWith('/commander')) return route.fulfill({ contentType: 'text/html', body: '<html><body id="real-checkout">checkout</body></html>' });
      if (route.request().method() === 'POST') return route.fulfill({ contentType: 'text/html', body: '<html><body id="real-checkout">checkout</body></html>' });
      return route.fulfill({ contentType: 'text/html', body: PRODUCT_PAGE });
    });
    await p.goto('https://shop.test/produit/free-fire/');
    await p.waitForTimeout(150);
    await p.tap('.dnp-buy-now');
    await p.waitForTimeout(1200);
    const landed = await p.evaluate(() => !!document.getElementById('real-checkout') || document.getElementById('dcs-sheet') === null);
    ok('a dead script sends the customer to the real checkout', landed,
      'the button must never simply do nothing');
    await ctx.close();
  }

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  await b.close();
  process.exit(fail ? 1 : 0);
})();
