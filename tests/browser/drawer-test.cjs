/* The drawer is the only way into most of the shop on a phone, so it is tested
   against a real Chromium with real touch input rather than reasoned about.

   Two of these cases are here because the code shipped without them and the
   menu was visibly broken: one tap opened the drawer and shut it again in the
   same tap, and a second tap during the slide-in landed on a link the customer
   could not see yet. Both came from the drawer becoming hit-testable on
   pointerdown while it was still off screen.

   Real input matters. An earlier version of this file dispatched synthetic
   PointerEvents and "found" a stuck half-open panel that neither a real finger
   nor a real stylus can produce - touch pointers get implicit pointer capture.
   Use page.touchscreen and CDP, not new PointerEvent(). */
const { chromium } = require('playwright');
const fs = require('fs');

const ROOT = '/home/user/snowy-boat-a2d8/delicat-builder-v9/assets/';
const CSS = fs.readFileSync(ROOT + 'css/drawer.css', 'utf8');
const JS = fs.readFileSync(process.env.DRAWER_JS || (ROOT + 'js/drawer.js'), 'utf8');

const PAGE = "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<style>__CSS__</style>\n<style>\n *{box-sizing:border-box} body{margin:0;font-family:-apple-system,Roboto,Arial,sans-serif}\n header.site{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:12px;padding:12px 14px;background:#fff;border-bottom:1px solid #eee}\n .dsb-menu-toggle{width:44px;height:44px;border:0;border-radius:12px;background:#f2f3f7;display:grid;place-items:center}\n .row{padding:14px} .cards{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}\n .card{border:1px solid #eee;border-radius:14px;padding:10px} .ph{aspect-ratio:1/1;background:#eef;border-radius:10px}\n</style></head><body>\n<header class=\"site\">\n  <button class=\"dsb-menu-toggle\" data-dsb8-menu-trigger aria-label=\"Menu\">\n    <svg width=\"22\" height=\"22\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M4 7h16M4 12h16M4 17h16\"/></svg>\n  </button><strong>Delicat Store</strong>\n</header>\n<section class=\"row\"><h2>Section 0</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 1</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 2</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 3</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 4</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 5</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 6</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 7</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 8</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 9</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 10</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 11</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 12</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section><section class=\"row\"><h2>Section 13</h2><div class=\"cards\"><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article><article class=\"card\"><div class=\"ph\"></div><h3>Produit</h3></article></div></section>\n<div class=\"dlx-drawer is-left\" id=\"delicat-drawer\" data-dlx-drawer hidden aria-hidden=\"true\">\n  <div class=\"dlx-drawer__backdrop\" data-dlx-close></div>\n  <aside class=\"dlx-drawer__panel\" role=\"dialog\" aria-modal=\"true\" aria-label=\"Delicat\" tabindex=\"-1\" data-dlx-panel>\n    <header class=\"dlx-drawer__top\">\n      <a class=\"dlx-brand\" href=\"/\"><span class=\"dlx-brand__mark\">D</span>\n        <span class=\"dlx-brand__copy\"><strong>Delicat Store</strong><small>Recharges</small></span></a>\n      <button type=\"button\" class=\"dlx-drawer__close\" data-dlx-close aria-label=\"Fermer\">\n        <svg viewBox=\"0 0 24 24\"><path d=\"m6 6 12 12M18 6 6 18\"/></svg></button>\n    </header>\n    <div class=\"dlx-drawer__scroll\" data-dlx-scroll>\n      <div class=\"dlx-account\"><a class=\"dlx-account__row\" href=\"/compte\">\n        <span class=\"dlx-account__avatar\">M</span>\n        <span class=\"dlx-account__copy\"><strong>Mark</strong><small>client</small></span></a></div>\n      <div class=\"dlx-quick\"><a class=\"dlx-quick__tile\" href=\"#\"><span class=\"dlx-quick__icon\"><svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"8\"/></svg></span>Panier</a><a class=\"dlx-quick__tile\" href=\"#\"><span class=\"dlx-quick__icon\"><svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"8\"/></svg></span>Compte</a><a class=\"dlx-quick__tile\" href=\"#\"><span class=\"dlx-quick__icon\"><svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"8\"/></svg></span>Wallet</a><a class=\"dlx-quick__tile\" href=\"#\"><span class=\"dlx-quick__icon\"><svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"8\"/></svg></span>Aide</a></div>\n      <form class=\"dlx-search\" role=\"search\" method=\"get\" action=\"/\">\n        <span class=\"dlx-search__icon\"><svg viewBox=\"0 0 24 24\"><circle cx=\"11\" cy=\"11\" r=\"7\"/></svg></span>\n        <input id=\"dlx-search-input\" type=\"search\" name=\"s\" autocomplete=\"off\" placeholder=\"Rechercher\" data-dlx-search>\n      </form>\n      <p class=\"dlx-empty\" data-dlx-empty hidden>Aucun resultat.</p>\n      <section class=\"dlx-section\" data-dlx-section><h3 class=\"dlx-section__title\">Menu</h3>\n        <ul class=\"dlx-list\"><li><a class=\"dlx-item\" href=\"/c/jeux\" data-dlx-filter=\"jeux free fire, pubg\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Jeux</strong><small>Free Fire, PUBG</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/finance\" data-dlx-filter=\"finance moncash, natcash\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Finance</strong><small>MonCash, Natcash</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/gift-card\" data-dlx-filter=\"gift-card google play, steam\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Gift-Card</strong><small>Google Play, Steam</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/reseaux sociaux\" data-dlx-filter=\"reseaux sociaux tiktok, instagram\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Reseaux Sociaux</strong><small>TikTok, Instagram</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/streaming\" data-dlx-filter=\"streaming netflix, spotify\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Streaming</strong><small>Netflix, Spotify</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/mobile\" data-dlx-filter=\"mobile digicel, natcom\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Mobile</strong><small>Digicel, Natcom</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/promotions\" data-dlx-filter=\"promotions offres du moment\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Promotions</strong><small>Offres du moment</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/nouveautes\" data-dlx-filter=\"nouveautes derniers ajouts\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Nouveautes</strong><small>Derniers ajouts</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li></ul></section>\n      <section class=\"dlx-section\" data-dlx-section>\n        <button type=\"button\" class=\"dlx-section__toggle\" aria-expanded=\"true\" data-dlx-toggle>Bonus\n          <svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></button>\n        <ul class=\"dlx-list\"><li><a class=\"dlx-item\" href=\"/c/parrainage\" data-dlx-filter=\"parrainage invitez un ami\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Parrainage</strong><small>Invitez un ami</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/points\" data-dlx-filter=\"points vos recompenses\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Points</strong><small>Vos recompenses</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/support\" data-dlx-filter=\"support whatsapp\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Support</strong><small>WhatsApp</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li><li><a class=\"dlx-item\" href=\"/c/suivi\" data-dlx-filter=\"suivi ou en est ma commande\"><span class=\"dlx-item__icon\"><svg viewBox=\"0 0 24 24\"><path d=\"M4 7h16\"/></svg></span><span class=\"dlx-item__copy\"><strong>Suivi</strong><small>Ou en est ma commande</small></span><span class=\"dlx-item__chevron\"><svg viewBox=\"0 0 24 24\"><path d=\"m9 6 6 6-6 6\"/></svg></span></a></li></ul></section>\n    </div>\n  </aside>\n</div>\n<script>__JS__</script>\n</body></html>".replace('__CSS__', () => CSS).replace('__JS__', () => JS);

let pass = 0, fail = 0;
const ok = (w, c, d = '') => { c ? (pass++, console.log('  ok    ' + w)) : (fail++, console.log('  FAIL  ' + w + (d ? '  -- ' + d : ''))); };
const group = (n) => console.log('\n' + n + '\n' + '-'.repeat(n.length));

/* The three bars, in viewport coordinates. Raw taps rather than page.tap():
   once the panel is over the button Playwright refuses to tap it, but a finger
   is not stopped by that - and the sequence that used to break the menu is
   exactly a finger landing where the panel now is. */
const BARS = { x: 36, y: 34 };

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  async function open() {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
    const p = await ctx.newPage();
    await p.route('https://shop.test/**', (r) => r.fulfill({ contentType: 'text/html', body: PAGE }));
    await p.goto('https://shop.test/');
    /* requestIdleCallback parks the panel; the first tap behaves differently
       before that has happened, so wait for it. */
    await p.waitForTimeout(1300);
    return { ctx, p };
  }

  const state = (p) => p.evaluate(() => {
    const d = document.querySelector('[data-dlx-drawer]');
    if (!d) return { gone: true };
    const panel = d.querySelector('[data-dlx-panel]');
    return {
      isOpen: d.classList.contains('is-open'), entering: d.classList.contains('is-entering'),
      warm: d.classList.contains('is-warm'), vis: d.style.visibility || 'visible',
      pe: d.style.pointerEvents || 'auto', locked: document.documentElement.classList.contains('dlx-open'),
      left: Math.round(panel.getBoundingClientRect().left), bodyTop: document.body.style.top || '',
      scrollY: Math.round(window.scrollY), inertKids: document.querySelectorAll('body > [data-dlx-inert]').length
    };
  });


  /* ------------------------------------------------------------------ */
  group('One tap opens it, and leaves it open');

  {
    const { ctx, p } = await open();
    await p.evaluate(() => { window.__e = []; ['dlx:open', 'dlx:close'].forEach((n) =>
      document.addEventListener(n, () => window.__e.push(n))); });
    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(700);

    const e = await p.evaluate(() => window.__e);
    const s = await state(p);
    ok('one tap fires open, and nothing else', e.join(',') === 'dlx:open', e.join(',') || '(nothing)');
    ok('the panel is all the way on screen', s.isOpen && s.left === 0, JSON.stringify(s));
    ok('the page behind is locked', s.locked);
    ok('and hidden from assistive tech', s.inertKids > 0);
    ok('hit-testing is handed back once it has arrived', !s.entering, JSON.stringify(s));
    await ctx.close();
  }

  group('Nothing invisible is tappable');

  {
    const { ctx, p } = await open();

    /*
     * Sampled from inside the page, not by driving a second tap from out here:
     * the round trip to send one takes longer than the slide does, so a tap
     * scheduled "mid-slide" from the test lands after the panel has arrived and
     * proves nothing. elementFromPoint answers the question the tap was asking
     * anyway - what a finger would hit - and honours pointer-events exactly as
     * a real hit test does.
     */
    const r = await p.evaluate((bars) => new Promise((resolve) => {
      const drawer = document.querySelector('[data-dlx-drawer]');
      const btn = document.querySelector('.dsb-menu-toggle');
      const samples = [];
      const t0 = performance.now();

      const tick = () => {
        const el = document.elementFromPoint(bars.x, bars.y);
        const panel = drawer.querySelector('[data-dlx-panel]');
        samples.push({
          t: Math.round(performance.now() - t0),
          inDrawer: !!(el && drawer.contains(el)),
          left: Math.round(panel.getBoundingClientRect().left),
          entering: drawer.classList.contains('is-entering')
        });
        if (performance.now() - t0 < 700) requestAnimationFrame(tick);
        else resolve(samples);
      };

      btn.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch', pointerId: 1, isPrimary: true }));
      btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      requestAnimationFrame(tick);
    }), BARS);

    /* Frames where the panel is on screen but has not yet arrived. */
    const sliding = r.filter((s) => s.left > -357 && s.left < 0);
    const arrived = r.filter((s) => s.left === 0);

    ok('the slide-in was actually sampled', sliding.length > 0, r.length + ' frames');
    ok('nothing in the drawer can be hit while it slides',
      sliding.every((s) => !s.inDrawer),
      JSON.stringify(sliding.filter((s) => s.inDrawer).slice(0, 3)));
    ok('and it takes taps again once it has arrived',
      arrived.length > 0 && arrived[arrived.length - 1].inDrawer,
      JSON.stringify(arrived.slice(-1)));
    ok('the entering state does not outlast the slide',
      !r[r.length - 1].entering, JSON.stringify(r.slice(-1)));
    await ctx.close();
  }

  group('Closing');

  {
    const { ctx, p } = await open();
    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(600);
    /* The strip of backdrop the panel does not cover - where a customer taps
       to dismiss. Its centre is under the panel. */
    await p.touchscreen.tap(378, 420);
    await p.waitForTimeout(700);

    const s = await state(p);
    ok('a tap on the backdrop closes it', !s.isOpen && s.left < -300, JSON.stringify(s));
    ok('the scroll lock is released', !s.locked && s.bodyTop === '');
    ok('aria-hidden comes back off the page', s.inertKids === 0);
    ok('and it parks itself for the next tap', s.warm && s.vis === 'hidden' && s.pe === 'none', JSON.stringify(s));
    await ctx.close();
  }

  {
    const { ctx, p } = await open();
    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(600);
    await p.tap('.dlx-drawer__close');
    await p.waitForTimeout(700);
    ok('the x button closes it', !(await state(p)).isOpen);

    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(600);
    ok('and it opens again afterwards', (await state(p)).left === 0);

    await p.keyboard.press('Escape');
    await p.waitForTimeout(700);
    ok('Escape closes it', !(await state(p)).isOpen);
    await ctx.close();
  }

  group('The page underneath');

  {
    const { ctx, p } = await open();
    await p.evaluate(() => window.scrollTo(0, 2400));
    await p.waitForTimeout(150);
    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(600);
    const during = await state(p);
    await p.tap('.dlx-drawer__close');
    await p.waitForTimeout(700);
    const after = await state(p);

    ok('the page does not jump when the menu opens', during.bodyTop === '-2400px', during.bodyTop);
    ok('and is back where it was when it closes', Math.abs(after.scrollY - 2400) < 3, 'y=' + after.scrollY);
    await ctx.close();
  }

  group('Opened from the keyboard');

  {
    const { ctx, p } = await open();
    /* A tap that slides off the button: it opens on pointerdown and its click
       never reaches the trigger. The guard against that click has to expire, or
       it swallows the next genuine activation - which is how keyboard opening
       used to die for the rest of the page's life. */
    const cdp = await ctx.newCDPSession(p);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: BARS.x, y: BARS.y, id: 1 }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: 200, y: 300, id: 1 }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await p.waitForTimeout(700);
    await p.keyboard.press('Escape');
    await p.waitForTimeout(700);
    ok('closed again', !(await state(p)).isOpen);

    await p.evaluate(() => document.querySelector('.dsb-menu-toggle').click());
    await p.waitForTimeout(700);
    ok('the keyboard can still open the menu afterwards', (await state(p)).isOpen);
    await ctx.close();
  }

  group('The header can open it by event');

  {
    const { ctx, p } = await open();
    await p.evaluate(() => document.dispatchEvent(new CustomEvent('dsb8:overlay:open', { detail: { id: 'modern-menu' } })));
    await p.waitForTimeout(600);
    ok('dsb8:overlay:open opens it', (await state(p)).left === 0);
    await ctx.close();
  }

  group('Search');

  {
    const { ctx, p } = await open();
    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(600);

    await p.fill('#dlx-search-input', 'netflix');
    await p.waitForTimeout(200);
    let r = await p.evaluate(() => ({
      visible: Array.from(document.querySelectorAll('.dlx-list li')).filter((l) => !l.hidden).length,
      sections: Array.from(document.querySelectorAll('[data-dlx-section]')).filter((s) => !s.hidden).length }));
    ok('typing narrows the list', r.visible === 1, JSON.stringify(r));
    ok('and drops a section with nothing left in it', r.sections === 1, JSON.stringify(r));

    await p.fill('#dlx-search-input', 'zzzz');
    await p.waitForTimeout(200);
    r = await p.evaluate(() => ({
      visible: Array.from(document.querySelectorAll('.dlx-list li')).filter((l) => !l.hidden).length,
      empty: !document.querySelector('[data-dlx-empty]').hidden }));
    ok('nothing found says so', r.visible === 0 && r.empty, JSON.stringify(r));

    await p.fill('#dlx-search-input', '');
    await p.waitForTimeout(200);
    r = await p.evaluate(() => ({
      visible: Array.from(document.querySelectorAll('.dlx-list li')).filter((l) => !l.hidden).length,
      sections: Array.from(document.querySelectorAll('[data-dlx-section]')).filter((s) => !s.hidden).length }));
    ok('clearing it puts everything back', r.visible === 12 && r.sections === 2, JSON.stringify(r));
    await ctx.close();
  }

  group('A swipe to close');

  /*
   * Two halves, because two different things decide whether this gesture works.
   *
   * Whether the BROWSER lets the gesture reach us is decided by touch-action.
   * Without it Chromium claims sideways panning and fires pointercancel on the
   * first move - traced as pointerdown, pointermove, pointercancel, nothing
   * after. That is asserted as a declaration, because CDP's synthetic touch
   * does not run the real gesture pipeline and cannot demonstrate it either way.
   *
   * What WE do with the gesture once we have it - the 10px arming threshold,
   * the distance that commits, throwing away the click of a swipe - is exercised
   * with a pen, which goes through the pointer pipeline without touch gesture
   * arbitration in between.
   */
  {
    const { ctx, p } = await open();
    const r = await p.evaluate(() => {
      const cs = (sel) => getComputedStyle(document.querySelector(sel)).touchAction;
      return { panel: cs('[data-dlx-panel]'), input: cs('#dlx-search-input') };
    });
    ok('the panel keeps sideways gestures for itself', r.panel === 'pan-y', JSON.stringify(r));
    ok('and leaves the fields their own', r.input === 'auto', JSON.stringify(r));
    await ctx.close();
  }

  {
    const { ctx, p } = await open();
    const cdp = await ctx.newCDPSession(p);
    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(600);

    /* Starting ON a menu item, which is where a thumb actually lands - most of
       this panel is links, and the drag used to bail on every one of them. */
    const spot = await p.evaluate(() => {
      const box = document.querySelectorAll('.dlx-item')[2].getBoundingClientRect();
      return { x: Math.round(box.left + box.width * 0.75), y: Math.round(box.top + box.height / 2) };
    });
    const pen = (type, x, buttons) => cdp.send('Input.dispatchMouseEvent', {
      type, x, y: spot.y, button: type === 'mouseMoved' ? 'none' : 'left', buttons, pointerType: 'pen', force: 0.5 });

    let navigated = false;
    p.on('framenavigated', () => { navigated = true; });

    await pen('mousePressed', spot.x, 1);
    for (const x of [spot.x - 18, spot.x - 55, spot.x - 120, spot.x - 200, 10]) await pen('mouseMoved', x, 1);
    await pen('mouseReleased', 10, 0);
    await p.waitForTimeout(800);

    const r = await p.evaluate(() => {
      const d = document.querySelector('[data-dlx-drawer]');
      if (!d) return { gone: true };
      const panel = d.querySelector('[data-dlx-panel]');
      return { left: Math.round(panel.getBoundingClientRect().left),
               dragging: d.classList.contains('is-dragging'), inline: panel.style.transform || '' };
    });
    ok('a swipe that starts on a menu item closes the menu', !r.gone && r.left < -300, JSON.stringify(r));
    ok('it does not follow the link it started on', !navigated && !r.gone);
    ok('and leaves no drag state behind', !r.gone && !r.dragging && !r.inline, JSON.stringify(r));
    await ctx.close();
  }

  {
    /* A short drag is not a dismissal: the panel springs back. */
    const { ctx, p } = await open();
    const cdp = await ctx.newCDPSession(p);
    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(600);
    const pen = (type, x, buttons) => cdp.send('Input.dispatchMouseEvent', {
      type, x, y: 420, button: type === 'mouseMoved' ? 'none' : 'left', buttons, pointerType: 'pen', force: 0.5 });
    await pen('mousePressed', 300, 1);
    for (const x of [288, 270, 255]) await pen('mouseMoved', x, 1);
    await pen('mouseReleased', 255, 0);

    /*
     * The click goes in immediately, the way a browser dispatches it - a few
     * milliseconds after the finger lifts, not most of a second later.
     *
     * It is dispatched rather than waited for because after a PEN drag Chromium
     * fires click on the common ancestor of press and release, so none reaches
     * the link and there would be nothing to observe. On a phone one does reach
     * it, which is the whole point: a swipe that ends over a menu item would
     * otherwise open that item on its way out.
     */
    const swallowed = await p.evaluate(async () => {
      let reached = false;
      const link = document.querySelectorAll('.dlx-item')[2];
      /* preventDefault so a click that is NOT swallowed reports as a failed
         assertion rather than navigating away and destroying the context. */
      link.addEventListener('click', (e) => { reached = true; e.preventDefault(); });
      const box = link.getBoundingClientRect();
      link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true,
        clientX: Math.round(box.left + 40), clientY: Math.round(box.top + box.height / 2) }));
      await new Promise((r) => setTimeout(r, 120));
      return !reached;
    });
    ok('the click that ends a swipe is thrown away', swallowed,
      'the swipe would have opened whatever it ended on');

    await p.waitForTimeout(600);
    const r = await p.evaluate(() => {
      const d = document.querySelector('[data-dlx-drawer]');
      const panel = d.querySelector('[data-dlx-panel]');
      return { left: Math.round(panel.getBoundingClientRect().left), inline: panel.style.transform || '',
               open: d.classList.contains('is-open') };
    });
    ok('and a short drag springs back instead of closing', r.open && r.left === 0 && !r.inline, JSON.stringify(r));
    await ctx.close();
  }

  {
    /* The other half of the bargain: a tap on a link is still a tap. */
    const { ctx, p } = await open();
    await p.touchscreen.tap(BARS.x, BARS.y);
    await p.waitForTimeout(600);
    const followed = await p.evaluate(async () => {
      let hit = false;
      const link = document.querySelectorAll('.dlx-item')[2];
      link.addEventListener('click', (e) => { hit = true; e.preventDefault(); });
      const box = link.getBoundingClientRect();
      link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true,
        clientX: Math.round(box.left + 40), clientY: Math.round(box.top + box.height / 2) }));
      await new Promise((r) => setTimeout(r, 200));
      return hit;
    });
    ok('a plain tap on a menu item still follows it', followed);
    await ctx.close();
  }

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  await b.close();
  process.exit(fail ? 1 : 0);
})();
