// node seo-990.cjs <base> <expected.json> <site-dir> — STAGING ONLY. Builder 9.9.0 (SEO-02 to SEO-06).
// Run `php seo-setup.php <site-dir> <expected.json>` first. Checks, the way Google and a visitor see the site:
//  1. SEO-02 the sitemap leaves out functional pages, fake pages, supplier categories (three suppliers, one with a
//     name the plugin does not know), copies, « Uncategorized », tags and the duplicate page; keeps the real ones;
//  2. functional pages open with noindex,follow; fake addresses answer 301 to the real page (home, shop, the product
//     a category repeats, the category it copies); an editor still opens them; real pages stay indexable, among them
//     an Elementor page and a block page with no text;
//  3. SEO-03 the duplicate page names its original as canonical;
//  4. SEO-05 breadcrumbs (data and visible trail) never pass through a supplier category or a copy;
//  5. SEO-06 one Product and one BreadcrumbList per product page, no empty description, the store named once in the
//     title, profile links without tracking codes;
//  6. the shop's category chips and the drawer leave out redirected addresses; « sondaj » gets its hero styles;
//     the terms page links to the real reseller page;
//  7. the settings screen shows the rules' choices; saving it the way WordPress does keeps the owner's choice
//     (also when the option is created); a choice can never redirect the home page or the shop.
// It puts the staging settings back at the end (seo-setup.php … restore).
const fs = require('fs');
const { execFileSync } = require('child_process');
const { request } = require('playwright');
const [base = 'https://localhost:8943', expectedFile, site] = process.argv.slice(2);
if (!/localhost|127\.0\.0\.1/.test(base)) { console.error('refusing: not a staging address'); process.exit(3); }
const want = JSON.parse(fs.readFileSync(expectedFile, 'utf8'));
const php = (...args) => execFileSync('php', ['-d', 'memory_limit=512M', __dirname + '/seo-setup.php', site, expectedFile, ...args], { encoding: 'utf8' }).trim().split('\n').pop();

const results = [];
const check = (name, ok, detail = '') => { results.push(!!ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`); };
const norm = (u) => { try { const x = new URL(u, base); return decodeURIComponent(x.pathname).replace(/\/+$/, '').toLowerCase() + x.search; } catch (e) { return String(u || ''); } };
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

(async () => {
  const api = await request.newContext({ ignoreHTTPSErrors: true });
  const editor = await request.newContext({ ignoreHTTPSErrors: true, extraHTTPHeaders: { cookie: `${want.editor_cookie.name}=${want.editor_cookie.value}` } });
  const get = async (url, ctx = api) => { const r = await ctx.get(url, { maxRedirects: 0 }); return { status: r.status(), body: await r.text(), location: r.headers()['location'] || '' }; };
  const page = async (url, ctx = api) => {
    const { status, body, location } = await get(url, ctx);
    const robots = ((body.match(/<meta name=['"]robots['"] content=['"]([^'"]*)/i) || [])[1] || '');
    const canonical = ((body.match(/<link rel=['"]canonical['"] href=['"]([^'"]*)/i) || [])[1] || '');
    const title = ((body.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [])[1] || '').replace(/&#8211;|&ndash;/g, '–').replace(/\s+/g, ' ').trim();
    const ld = [];
    for (const m of body.matchAll(/<script[^>]+application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi)) {
      try { const j = JSON.parse(m[1]); for (const it of (j['@graph'] || [j])) ld.push(it); } catch (e) { ld.push({ '@type': 'INVALID' }); }
    }
    return { status, body, robots, canonical, title, ld, location };
  };
  const sitemapUrls = async () => {
    const index = (await get(base + '/wp-sitemap.xml')).body;
    const subs = [...index.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
    const urls = [];
    for (const s of subs) urls.push(...[...(await get(s)).body.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => norm(m[1])));
    return { subs, urls: new Set(urls) };
  };
  const indexable = (p) => p.status === 200 && !/noindex/.test(p.robots);

  // 1. sitemap
  const sm = await sitemapUrls();
  check('no product-tag sitemap', !sm.subs.some((s) => /taxonomies-product_tag/.test(s)), sm.subs.join(' '));
  const out = { ...want.hidden, ...Object.fromEntries(Object.entries(want.redirected).map(([k, v]) => [k, v[0]])), duplicate: want.duplicate };
  const leaked = Object.entries(out).filter(([, u]) => u && sm.urls.has(norm(u))).map(([k]) => k);
  check('functional, fake, supplier and copy addresses are out of the sitemap', !leaked.length, leaked.join(', '));
  const missing = Object.entries(want.indexed).filter(([k, u]) => k !== 'home' && !sm.urls.has(norm(u))).map(([k]) => k);
  check('real pages, categories and products stay in the sitemap', !missing.length, missing.join(', '));

  // 2. robots and redirects
  for (const [name, url] of Object.entries(want.hidden)) {
    const p = await page(url);
    check(`${name}: opens, noindex,follow`, p.status === 200 && /noindex/.test(p.robots) && /follow/.test(p.robots) && !/nofollow/.test(p.robots), `${p.status} [${p.robots}]`);
  }
  for (const [name, [from, to]] of Object.entries(want.redirected)) {
    if (!from) { check(`${name}: present on the test site`, false); continue; }
    const r = await get(from);
    check(`${name}: 301 to the real page`, r.status === 301 && norm(r.location) === norm(to), `${r.status} ${r.location} (want ${to})`);
  }
  const asEditor = await page(want.redirected.test[0], editor);
  check('an editor still opens a redirected page (noindex)', asEditor.status === 200 && /noindex/.test(asEditor.robots), `${asEditor.status} [${asEditor.robots}]`);
  for (const [name, url] of Object.entries(want.indexed)) {
    const p = await page(url);
    check(`${name}: indexable`, indexable(p), `${p.status} [${p.robots}] ${p.location}`);
  }

  // 3. duplicates
  const dup = await page(want.duplicate);
  const orig = await page(want.original);
  check('the duplicate points to its original', norm(dup.canonical) === norm(want.original) && dup.status === 200 && !/noindex/.test(dup.robots), dup.canonical);
  check('the original is its own canonical', norm(orig.canonical) === norm(want.original), orig.canonical);

  // 4-5. product pages
  for (const key of ['only_supplier', 'both']) {
    const prod = want[key];
    const p = await page(prod.url);
    const crumbsLd = p.ld.filter((i) => i['@type'] === 'BreadcrumbList');
    const products = p.ld.filter((i) => i['@type'] === 'Product');
    const names = crumbsLd.length ? crumbsLd[0].itemListElement.map((e) => e.name || (e.item && e.item.name) || '') : [];
    const visible = ((p.body.match(/<nav class="dnp-breadcrumb">([\s\S]*?)<\/nav>/) || [])[1] || '');
    const visibleText = visible.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#8211;/g, '–').replace(/\s+/g, ' ').trim();
    const supplier = /Delicat Digital|Free Fire \(LATAM\)|GamePoint|Direct Topup/;
    check(`${key}: one Product and one BreadcrumbList`, products.length === 1 && crumbsLd.length === 1, `${products.length} / ${crumbsLd.length}`);
    check(`${key}: breadcrumb data skips supplier categories and copies`, names.length >= 2 && !names.some((n) => supplier.test(n)) && names[names.length - 1] === prod.name && (key === 'only_supplier' ? names.length === 2 : names.includes(prod.games)), names.join(' > '));
    check(`${key}: visible breadcrumb skips them too, with real links`, visibleText !== '' && !supplier.test(visibleText) && /<a [^>]*href="https?:\/\//.test(visible) && (key === 'only_supplier' ? visibleText.split(prod.name).length === 2 : visibleText.includes(prod.games)), visibleText);
    check(`${key}: no empty description in the Product data`, products.length === 1 && !(('description' in products[0]) && String(products[0].description).trim() === ''), JSON.stringify(products[0] && products[0].description));
    check(`${key}: title names the store once`, p.title.split(want.site_name).length - 1 <= 1, p.title);
    check(`${key}: name and price in the HTML as sent`, new RegExp('<h1[^>]*>\\s*' + esc(prod.name)).test(p.body) && /woocommerce-Price-amount|dnp-price|data-base-price="[^"]*\d/.test(p.body));
  }
  const games = await page(want.indexed.games);
  check('category page links to its products in the HTML as sent', games.body.includes('href="' + want.both.url + '"'));
  const home = await page(base + '/');
  const store = home.ld.find((i) => i['@type'] === 'OnlineStore');
  const same = (store && store.sameAs) || [];
  check('profile links carry no tracking codes', same.length === 2 && same.every((u) => !/[?&](mibextid|_r|_t|utm_)/.test(u)), same.join(' '));

  // 6. what visitors are offered
  const shop = await page(want.indexed.shop);
  const chips = ((shop.body.match(/<nav class="delicat-native-category-chips"[\s\S]*?<\/nav>/) || [])[0] || '');
  const chipLeak = want.chips_out.filter((u) => chips.includes('href="' + u + '"'));
  check('shop chips leave out supplier names and copies', chips !== '' && !chipLeak.length, chips ? chipLeak.join(' ') : 'no chips on the shop page');
  check('drawer leaves out entries to fake and supplier addresses, keeps real ones', home.body.includes('SEO entree tutos') && !home.body.includes('SEO entree test') && !home.body.includes('SEO entree fournisseur'));
  check('drawer entry typed without the final slash links to the real address (no 301 per click)', /href="(https?:\/\/[^"]+)?\/tutoriels\/"/.test(home.body) && !/href="(https?:\/\/[^"]+)?\/tutoriels"/.test(home.body));
  check('drawer entry to a copy category leads straight to its product', home.body.includes('SEO entree copie') && !home.body.includes('/product-category/copie-produit/') && home.body.includes('href="' + want.only_supplier.url + '"'));
  const sondaj = await page(want.sondaj);
  check('« sondaj » (Elementor shortcode widget) gets the hero module and its styles', indexable(sondaj) && /delicat-builder-v9-native-pages-css/.test(sondaj.body), `${sondaj.status}`);
  if (want.conditions) {
    const cgu = await page(want.conditions);
    check('terms page: the reseller link goes to the real page', cgu.status === 200 && !cgu.body.includes('href="/programme-revendeur/"') && cgu.body.includes('href="' + want.reseller + '"'), want.reseller);
  } else {
    check('terms page exists on the test site', false);
  }

  // 7. the owner decides
  const admin = JSON.parse(php('admin'));
  check('settings screen: rules shown, supplier categories summarised, orphans listed, home page not offered', Object.values(admin).every(Boolean), JSON.stringify(admin));
  let saved = JSON.parse(php('save', JSON.stringify({ post: { [want.ids.login]: 'show' } })));
  const loginBack = await page(want.hidden.login);
  const sm2 = await sitemapUrls();
  check('saving « proposée à Google » for the log-in page brings it back', saved.overrides && saved.overrides.post && saved.overrides.post[want.ids.login] === 'show' && indexable(loginBack) && sm2.urls.has(norm(want.hidden.login)), JSON.stringify(saved));
  saved = JSON.parse(php('save', JSON.stringify({ fresh: 1, post: { [want.ids.test]: 'show' } })));
  const testBack = await page(want.redirected.test[0]);
  check('first save of the option (sanitized twice) keeps the choice and the other settings', saved.overrides && saved.overrides.post && saved.overrides.post[want.ids.test] === 'show' && saved.enabled === 1 && saved.suffix === want.site_name && indexable(testBack), JSON.stringify(saved) + ` ${testBack.status}`);
  saved = JSON.parse(php('save', JSON.stringify({ post: { [want.ids.login]: 'default', [want.ids.test]: 'default' } })));
  const loginAgain = await page(want.hidden.login);
  const testAgain = await get(want.redirected.test[0]);
  check('« Recommandé » goes back to the rules', /noindex/.test(loginAgain.robots) && testAgain.status === 301, `[${loginAgain.robots}] ${testAgain.status}`);
  php('protect-front');
  const homeKept = await page(base + '/');
  const shopKept = await page(want.indexed.shop);
  check('a choice can never redirect or hide the home page or the shop', indexable(homeKept) && indexable(shopKept), `${homeKept.status} ${shopKept.status} ${homeKept.location}`);

  php('restore');
  await api.dispose();
  await editor.dispose();
  const passed = results.filter(Boolean).length;
  console.log(`\n${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})();
