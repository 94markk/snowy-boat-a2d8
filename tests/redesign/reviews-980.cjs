// node reviews-980.cjs <base> <expected.json> — STAGING ONLY. Delicat Builder 9.8.0 (TR-01): review figures.
// Run `php reviews-setup.php <site-dir> <expected.json>` first; it counts the reviews with plain WordPress calls.
// Checks, at phone and desktop size, light and dark:
//  1. the rating block shows the computed store average and count, never the hand-typed 4.8 / 219 « AVIS VÉRIFIÉS »;
//  2. product reviews are stated apart (average, count, verified buyers) with a link to the review policy;
//  3. the stars are filled to the real average;
//  4. the two sample lines the plugin used to ship are gone; a typed line stays, without the tick;
//  5. the « Achat vérifié » tick marks exactly the reviews of buyers (paid order / verified product review);
//  6. pending, unrated, draft-product reviews never show; the summary can be hidden; nothing scrolls sideways;
//  7. the summary text keeps WCAG AA contrast on its card;
//  8. the policy page no longer carries « 4,8 sur 81 »;
//  9. a customer with no paid order cannot post a review; a buyer can.
const fs = require('fs');
const { chromium } = require('playwright');
const base = process.argv[2] || 'https://localhost:8943';
const want = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
if (!/localhost|127\.0\.0\.1/.test(base)) { console.error('refusing: not a staging address'); process.exit(3); }

const results = [];
const check = (name, ok, detail = '') => { results.push(!!ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`); };
const TICKED = ['RV STORE BUYER FIVE', 'RV STORE BUYER TWO', 'RV PRODUCT VERIFIED FIVE', 'RV PRODUCT VERIFIED FOUR'];
const UNTICKED = ['RV STORE NOBUY FOUR', 'RV PRODUCT UNVERIFIED FOUR', 'RV TYPED LINE'];
const NEVER = ['JEAN-MARC', 'MIKAËLL', 'RV PRODUCT UNRATED', 'RV PRODUCT HELD ONE', 'RV PRODUCT ON DRAFT', 'RV STORE PENDING ONE', 'RV PRODUCT UNVERIFIED THREE'];

const contrastOf = (page, sel) => page.evaluate((sel) => {
  const parse = (c) => { const m = c && c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: v[0], g: v[1], b: v[2], a: v.length > 3 ? v[3] : 1 }; };
  const lum = (c) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const blend = (t, u) => ({ r: t.r * t.a + u.r * (1 - t.a), g: t.g * t.a + u.g * (1 - t.a), b: t.b * t.a + u.b * (1 - t.a), a: 1 });
  const el = document.querySelector(sel);
  if (!el) return null;
  // every candidate background under the text: solid colours, and each stop of a gradient (worst case wins)
  let unders = [{ r: 255, g: 255, b: 255, a: 1 }];
  const layers = [];
  for (let e = el; e; e = e.parentElement) {
    const cs = getComputedStyle(e);
    const stops = cs.backgroundImage.includes('gradient') ? (cs.backgroundImage.match(/rgba?\([^)]+\)/g) || []).map(parse).filter(Boolean) : [];
    const solid = parse(cs.backgroundColor);
    if (stops.length) layers.push(stops);
    if (solid && solid.a > 0) layers.push([solid]);
    if ((solid && solid.a >= 0.99) || (stops.length && stops.every((s) => s.a >= 0.99))) break;
  }
  for (const stops of layers.reverse()) { const next = []; for (const s of stops) for (const u of unders) next.push(blend(s, u)); unders = next; }
  const fg = parse(getComputedStyle(el).color);
  return Math.min(...unders.map((u) => ratio(fg.a < 1 ? blend(fg, u) : fg, u)));
}, sel);

async function login(browser, who) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
  const p = await ctx.newPage();
  await p.goto(base + '/wp-login.php');
  await p.fill('#user_login', who);
  await p.fill('#user_pass', want.users.pass);
  await p.click('#wp-submit');
  // signed in once WordPress sets its logged-in cookie (the redirect target varies with the Identity plugin's flow)
  for (let i = 0; i < 60 && !(await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in_')); i++) await p.waitForTimeout(250);
  await p.waitForLoadState('load').catch(() => {});
  if (!/localhost:\d+\/(?!wp-login)/.test(p.url())) await p.goto(base + '/', { waitUntil: 'load' });
  return { ctx, p };
}

const submit = (p, name) => p.evaluate(async ({ base, name }) => {
  const ajax = base + '/wp-admin/admin-ajax.php';
  const prompt = await (await fetch(ajax + '?action=delicat_builder_v9_review_prompt', { credentials: 'same-origin' })).json();
  const body = new URLSearchParams({ action: 'delicat_builder_v9_review_submit', nonce: prompt.data.nonce, rating: '4', quote: 'Test de l’envoi d’un avis depuis la fenêtre.', city: 'Jacmel', name });
  const r = await fetch(ajax, { method: 'POST', credentials: 'same-origin', body });
  return { status: r.status, body: await r.json() };
}, { base, name });

(async () => {
  const browser = await chromium.launch();
  for (const [vw, vh] of [[390, 844], [1280, 900]]) {
    for (const scheme of ['light', 'dark']) {
      const tag = `${vw}px ${scheme}`;
      const ctx = await browser.newContext({ viewport: { width: vw, height: vh }, isMobile: vw < 500, hasTouch: vw < 500, colorScheme: scheme, ignoreHTTPSErrors: true });
      const page = await ctx.newPage();
      await page.goto(`${base}/reviews-home/`, { waitUntil: 'load' });
      await page.waitForTimeout(scheme === 'dark' ? 700 : 150);
      const r = await page.evaluate(() => {
        const box = document.querySelector('.delicat-testimonials__rating');
        const stars = box && box.querySelector('.delicat-testimonials__rating-stars');
        const note = box && box.querySelector('.delicat-testimonials__rating-note');
        const link = note && note.querySelector('a');
        const fill = box && box.querySelector('.delicat-testimonials__rating-fill');
        const cards = [...document.querySelectorAll('.delicat-testimonials__mcard[role="listitem"]')].map((c) => ({ name: c.querySelector('.delicat-testimonials__mid strong').textContent.trim(), tick: !!c.querySelector('.delicat-testimonials__mcheck'), tickLabel: (c.querySelector('.delicat-testimonials__mcheck') || { getAttribute: () => '' }).getAttribute('aria-label') }));
        return {
          value: box ? box.querySelector('strong').textContent.trim() : null,
          aria: stars ? stars.getAttribute('aria-label') : '',
          fillRatio: stars && fill ? fill.getBoundingClientRect().width / stars.firstElementChild.getBoundingClientRect().width : -1,
          label: box ? box.querySelector('.delicat-testimonials__rating-label').textContent.trim() : '',
          note: note ? note.textContent.trim() : '',
          href: link ? link.href : '',
          text: document.querySelector('.delicat-testimonials').innerText,
          html: document.querySelector('.delicat-testimonials').innerHTML,
          cards,
          sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
          boxRight: box ? box.getBoundingClientRect().right : 0,
          vw: window.innerWidth,
        };
      });
      // 1. computed store figures, never the typed ones
      check(`${tag} average is the store reviews' mean`, r.value === want.store.average, `${r.value} vs ${want.store.average}`);
      check(`${tag} count is the published store reviews`, r.label === `${want.store.count} avis sur la boutique`, r.label);
      check(`${tag} no hand-typed 4.8 / 219 / « AVIS VÉRIFIÉS »`, !/219|AVIS VÉRIFIÉS|\b4\.8\b/i.test(r.text), r.text.slice(0, 120));
      // 2. product reviews apart, with the policy link
      check(`${tag} product reviews stated apart`, r.note.includes(`${want.products.average}/5 sur ${want.products.count} avis`) && r.note.includes(`dont ${want.products.verified} achats vérifiés`), r.note);
      check(`${tag} link to the review policy`, r.href === want.policy, r.href);
      // 3. stars filled to the real average
      check(`${tag} stars filled to the average`, Math.abs(r.fillRatio - want.store.raw / 5) < 0.03 && r.aria.includes(want.store.average), `${r.fillRatio.toFixed(3)} vs ${(want.store.raw / 5).toFixed(3)}`);
      // 4/5/6. cards
      const names = r.cards.map((c) => c.name);
      const never = NEVER.filter((n) => r.text.includes(n));
      check(`${tag} shipped samples and ineligible reviews never shown`, !never.length, never.join(', '));
      const wrongTick = r.cards.filter((c) => TICKED.includes(c.name) !== c.tick).map((c) => `${c.name}:${c.tick}`);
      const missing = TICKED.concat(UNTICKED).filter((n) => !names.includes(n));
      check(`${tag} « Achat vérifié » tick only on buyers`, !wrongTick.length && !missing.length && r.cards.filter((c) => c.tick).every((c) => c.tickLabel === 'Achat vérifié'), wrongTick.concat(missing.map((m) => 'missing ' + m)).join(', '));
      check(`${tag} no sideways scroll, summary inside the screen`, !r.sideways && r.boxRight <= r.vw + 1, `right ${r.boxRight} / ${r.vw}`);
      // 7. contrast
      const cNote = await contrastOf(page, '.delicat-testimonials__rating-note');
      const cLabel = await contrastOf(page, '.delicat-testimonials__rating-label');
      const cLink = await contrastOf(page, '.delicat-testimonials__rating-note a');
      check(`${tag} summary text contrast >= 4.5`, cNote >= 4.5 && cLabel >= 4.5 && cLink >= 4.5, `note ${cNote && cNote.toFixed(2)}, label ${cLabel && cLabel.toFixed(2)}, link ${cLink && cLink.toFixed(2)}`);
      if (vw === 390) await page.locator('.delicat-testimonials__rating').screenshot({ path: `${require('os').tmpdir()}/rating-${scheme}.png` }).catch(() => {});
      // 6. hidden summaries
      for (const slug of ['reviews-hidden', 'reviews-off']) {
        await page.goto(`${base}/${slug}/`, { waitUntil: 'load' });
        const has = await page.evaluate(() => [!!document.querySelector('.delicat-testimonials'), !!document.querySelector('.delicat-testimonials__rating')]);
        check(`${tag} ${slug}: rail shown, summary hidden`, has[0] && !has[1]);
      }
      await ctx.close();
    }
  }
  // 8. policy page
  {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    await page.goto(want.policy, { waitUntil: 'load' });
    const t = await page.evaluate(() => document.body.innerText);
    check('policy page has no hand-written figures', !/4,8 sur 81|81 avis/.test(t) && /comptés séparément/.test(t));
    await ctx.close();
  }
  // 9. who may post
  {
    const { ctx, p } = await login(browser, want.users.nobuy);
    const res = await submit(p, 'RV_NOBUY');
    check('customer without a paid order cannot post a review', res.status === 403 && /déjà passé une commande/.test(res.body.data.message), `${res.status} ${JSON.stringify(res.body)}`);
    await ctx.close();
  }
  {
    const { ctx, p } = await login(browser, want.users.buyer);
    const res = await submit(p, 'RV_BUYER2');
    check('buyer can post a review (held for moderation)', res.status === 200 && res.body.success === true, `${res.status} ${JSON.stringify(res.body)}`);
    await ctx.close();
  }
  await browser.close();
  const passed = results.filter(Boolean).length;
  console.log(`\n${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})();
