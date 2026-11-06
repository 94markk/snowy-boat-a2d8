// node seo-990.cjs <base> <expected.json> <site-dir> — STAGING ONLY. Builder 9.9.0 (SEO-02 to SEO-06).
// Run `php seo-setup.php <site-dir> <expected.json>` first. Checks, the way a search engine reads the site:
//  1. SEO-02 the sitemap leaves out log-in / test / maintenance / empty pages, « Hello world! », the supplier's
//     categories, « Uncategorized », product tags and the duplicate page — and keeps real pages, categories, products;
//  2. those hidden pages answer normally but say noindex,follow; real pages do not;
//  3. SEO-03 the duplicate page names its original as canonical; the original is its own canonical;
//  4. SEO-05 breadcrumbs (data and the visible trail) never pass through a supplier category, and use real links;
//  5. SEO-06 one Product and one BreadcrumbList per product page, no empty description; titles do not repeat the
//     store name; profile links carry no tracking codes;
//  6. SEO-04 the product name, its price and category links to products are in the HTML as sent;
//  7. the owner's saved choice wins over the recommendation; the settings screen shows the recommended boxes ticked.
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
const norm = (u) => decodeURIComponent(String(u || '')).replace(/\/+$/, '').toLowerCase();

(async () => {
  const api = await request.newContext({ ignoreHTTPSErrors: true });
  const get = async (url) => { const r = await api.get(url, { maxRedirects: 0 }); return { status: r.status(), body: await r.text() }; };
  const page = async (url) => {
    const { status, body } = await get(url);
    const robots = ((body.match(/<meta name=['"]robots['"] content=['"]([^'"]*)/i) || [])[1] || '');
    const canonical = ((body.match(/<link rel=['"]canonical['"] href=['"]([^'"]*)/i) || [])[1] || '');
    const title = ((body.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [])[1] || '').replace(/&#8211;|&ndash;/g, '–').replace(/\s+/g, ' ').trim();
    const ld = [];
    for (const m of body.matchAll(/<script[^>]+application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi)) {
      try { const j = JSON.parse(m[1]); for (const it of (j['@graph'] || [j])) ld.push(it); } catch (e) { ld.push({ '@type': 'INVALID' }); }
    }
    return { status, body, robots, canonical, title, ld };
  };
  const sitemapUrls = async () => {
    const index = (await get(base + '/wp-sitemap.xml')).body;
    const subs = [...index.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
    const urls = [];
    for (const s of subs) urls.push(...[...(await get(s)).body.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => norm(m[1])));
    return { subs, urls: new Set(urls) };
  };

  // 1. sitemap
  const sm = await sitemapUrls();
  check('no product-tag sitemap', !sm.subs.some((s) => /taxonomies-product_tag/.test(s)), sm.subs.join(' '));
  const leaked = Object.entries(want.hidden).filter(([, u]) => u && sm.urls.has(norm(u))).map(([k]) => k);
  check('hidden pages and categories are out of the sitemap', !leaked.length, leaked.join(', '));
  check('the duplicate page is out of the sitemap', !sm.urls.has(norm(want.duplicate)));
  const missing = Object.entries(want.indexed).filter(([, u]) => !sm.urls.has(norm(u))).map(([k]) => k);
  check('real pages, categories and products stay in the sitemap', !missing.length, missing.join(', '));

  // 2. robots
  for (const [name, url] of Object.entries(want.hidden)) {
    if (!url) continue;
    const p = await page(url);
    check(`${name}: opens, noindex,follow`, p.status === 200 && /noindex/.test(p.robots) && /follow/.test(p.robots) && !/nofollow/.test(p.robots), `${p.status} [${p.robots}]`);
  }
  for (const [name, url] of Object.entries(want.indexed)) {
    const p = await page(url);
    check(`${name}: indexable`, p.status === 200 && !/noindex/.test(p.robots), `${p.status} [${p.robots}]`);
  }

  // 3. duplicates
  const dup = await page(want.duplicate);
  const orig = await page(want.original);
  check('the duplicate points to its original', norm(dup.canonical) === norm(want.original) && dup.status === 200 && !/noindex/.test(dup.robots), dup.canonical);
  check('the original is its own canonical', norm(orig.canonical) === norm(want.original), orig.canonical);

  // 4-6. product pages
  for (const key of ['only_supplier', 'both']) {
    const prod = want[key];
    const p = await page(prod.url);
    const crumbsLd = p.ld.filter((i) => i['@type'] === 'BreadcrumbList');
    const products = p.ld.filter((i) => i['@type'] === 'Product');
    // Delicat SEO writes ListItem.name; WooCommerce writes ListItem.item.name
    const names = crumbsLd.length ? crumbsLd[0].itemListElement.map((e) => e.name || (e.item && e.item.name) || '') : [];
    const visible = ((p.body.match(/<nav class="dnp-breadcrumb">([\s\S]*?)<\/nav>/) || [])[1] || '');
    const visibleText = visible.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#8211;/g, '–').replace(/\s+/g, ' ').trim();
    const supplier = /Delicat Digital|Free Fire \(LATAM\)/;
    check(`${key}: one Product and one BreadcrumbList`, products.length === 1 && crumbsLd.length === 1, `${products.length} / ${crumbsLd.length}`);
    check(`${key}: breadcrumb data skips the supplier categories`, names.length >= 2 && !names.some((n) => supplier.test(n)) && names[names.length - 1] === prod.name && (key === 'only_supplier' ? names.length === 2 : names.includes(prod.games)), names.join(' > '));
    check(`${key}: visible breadcrumb skips them too, with real links`, visibleText !== '' && !supplier.test(visibleText) && /<a [^>]*href="https?:\/\//.test(visible) && (key === 'only_supplier' || visibleText.includes(prod.games)), visibleText);
    check(`${key}: no empty description in the Product data`, products.length === 1 && !(('description' in products[0]) && String(products[0].description).trim() === ''), JSON.stringify(products[0] && products[0].description));
    const twice = p.title.split(want.site_name).length - 1;
    check(`${key}: title names the store once`, twice <= 1, p.title);
    check(`${key}: name and price in the HTML as sent`, new RegExp('<h1[^>]*>\\s*' + prod.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).test(p.body) && /woocommerce-Price-amount|dnp-price|data-base-price="[^"]*\d/.test(p.body));
  }
  const games = await page(want.indexed.games);
  check('category page links to its products in the HTML as sent', games.body.includes('href="' + want.both.url + '"'));

  // 5. store profile links
  const home = await page(base + '/');
  const store = home.ld.find((i) => i['@type'] === 'OnlineStore');
  const same = (store && store.sameAs) || [];
  check('profile links carry no tracking codes', same.length === 2 && same.every((u) => !/[?&](mibextid|_r|_t|utm_)/.test(u)), same.join(' '));

  // 7. the owner decides; the screen shows the recommendation
  const admin = JSON.parse(php('admin'));
  check('settings screen: section shown, recommended boxes ticked, real pages not', admin.section && admin.login && admin.supplier && !admin.tutoriels, JSON.stringify(admin));
  php('override');
  const after = await page(want.hidden.login);
  const sm2 = await sitemapUrls();
  check('a page the owner un-ticks comes back to Google', !/noindex/.test(after.robots) && sm2.urls.has(norm(want.hidden.login)), `[${after.robots}]`);

  php('restore');
  await api.dispose();
  const passed = results.filter(Boolean).length;
  console.log(`\n${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})();
