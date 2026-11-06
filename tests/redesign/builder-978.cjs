// node builder-978.cjs <base-url> <site-dir> — STAGING ONLY. Checks the Builder 9.7.8 audit fixes on a phone:
// prefetch (FE-02/03), offline tap (FE-05), service worker (FE-04/06/17), shared search cache (BLD-03),
// breadcrumbs (FE-10), Gravatar (FE-12), prerender (FE-19), My Account styles (FE-09), notice pages not cached
// (BLD-07), foreign errors and Safe Mode (BLD-04) and the paid-order payment lock (BLD-02).
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const [base, site] = process.argv.slice(2);
if (!base || !site) { console.error('usage: node builder-978.cjs <base-url> <site-dir>'); process.exit(2); }
const LOCAL = /^https:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(base);
const SOCK = '/tmp/mdb.sock';
const sql = (q) => execFileSync('mysql', ['-S', SOCK, '-N', '-B', 'staging', '-e', q], { encoding: 'utf8' }).trim();
const php = (code) => {
  const f = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'dbv9-978-')), 'run.php');
  fs.writeFileSync(f, `<?php\n$_SERVER['HTTP_HOST'] = parse_url(${JSON.stringify(base)}, PHP_URL_HOST) . ':' . parse_url(${JSON.stringify(base)}, PHP_URL_PORT); $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost';\nrequire ${JSON.stringify(path.join(site, 'wp-load.php'))};\n${code}\n`);
  return execFileSync('php', [f], { encoding: 'utf8' }).trim();
};
const PASS = { admin: 'AdminPass!2026', client1: 'ClientPass!2026' };
const results = [];
const ok = (c, m) => { if (!c) throw new Error(m); };
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); } catch (e) { results.push(['FAIL', name]); console.log('FAIL', name, '—', String(e.message).slice(0, 500)); }
}
async function phone(browser, who) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: false, hasTouch: true, ignoreHTTPSErrors: LOCAL });
  if (who) {
    const p = await ctx.newPage();
    await p.goto(base + '/wp-login.php', { waitUntil: 'load' });
    await p.fill('#user_login', who); await p.fill('#user_pass', PASS[who]);
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#wp-submit')]);
    await p.close();
  }
  return ctx;
}

(async () => {
  const browser = await chromium.launch();
  try {
    await step('guest shop visit: no page fetched twice, no login link or current page prefetched (FE-02)', async () => {
      const ctx = await phone(browser);
      const p = await ctx.newPage();
      const docs = [];
      p.on('request', (r) => { if (r.resourceType() === 'fetch' && /text\/html/.test(r.headers().accept || '')) docs.push(r.url()); });
      await p.goto(base + '/shop/', { waitUntil: 'load' });
      await p.waitForTimeout(12000);
      const keys = docs.map((u) => u.split('#')[0]);
      const twice = keys.filter((u, i) => keys.indexOf(u) !== i);
      ok(twice.length === 0, `fetched twice: ${twice.join(', ')} (all: ${docs.join(', ')})`);
      ok(!docs.some((u) => /#.*(login|connexion)/i.test(u)), `login link prefetched: ${docs.join(', ')}`);
      ok(!keys.includes(base + '/shop/'), `current page prefetched: ${docs.join(', ')}`);
      ok(docs.length <= 6, `${docs.length} background page fetches: ${docs.join(', ')}`);
      await ctx.close();
    });

    await step('signed-in shop visit: no background page prefetch (FE-03)', async () => {
      const ctx = await phone(browser, 'client1');
      const p = await ctx.newPage();
      const pre = [];
      p.on('request', (r) => { if (r.headers()['x-delicat-prefetch'] === '1') pre.push(r.url()); });
      await p.goto(base + '/shop/', { waitUntil: 'load' });
      await p.waitForTimeout(12000);
      ok(pre.length === 0, `prefetched while signed in: ${pre.join(', ')}`);
      const rules = await p.evaluate(() => [...document.querySelectorAll('script[type=speculationrules]')].map((s) => s.textContent).join('\n'));
      ok(!/"prerender"[^\]]*"eagerness":"moderate"/.test(rules), `prerender still moderate: ${rules.slice(0, 300)}`);
      await ctx.close();
    });

    await step('offline tap on a product: stays on the page with « Pa gen koneksyon · Réessayer » (FE-05)', async () => {
      const ctx = await phone(browser);
      const p = await ctx.newPage();
      const errors = [];
      p.on('pageerror', (e) => errors.push(e.message));
      await p.goto(base + '/shop/', { waitUntil: 'load' });
      await p.waitForTimeout(2500);
      await ctx.setOffline(true);
      const before = p.url();
      await p.locator('.delicat-product-card a[href*="/product/"]').first().tap();
      await p.waitForTimeout(1500);
      const toast = await p.evaluate(() => { const t = document.getElementById('dlc-offline'); return t ? t.textContent : ''; });
      ok(p.url() === before, `left the page: ${p.url()}`);
      ok(/Pa gen koneksyon/.test(toast) && /R[ée]essayer/.test(toast), `toast: "${toast}"`);
      ok(!errors.length, `page errors: ${errors.join(' | ')}`);
      await ctx.setOffline(false);
      await ctx.close();
    });

    await step('service worker: shell in its own cache, normal HTTP cache, no admin scripts, edge-cacheable (FE-04/06/17)', async () => {
      const ctx = await phone(browser);
      const p = await ctx.newPage();
      const r = await p.goto(base + '/delicat-v9-sw.js');
      const body = await r.text();
      const cc = r.headers()['cache-control'] || '';
      ok(/s-maxage=300/.test(cc) && !/no-store/.test(cc), `cache-control: ${cc}`);
      ok(body.includes('DBV9_SHELL') && !body.includes("cache:'reload'"), 'shell cache / reload not as expected');
      const pre = (/const DBV9_PRECACHE=(\[.*?\]);/.exec(body) || [])[1] || '[]';
      ok(!/admin-bar|hoverintent|wp-admin/.test(pre), `admin files precached: ${pre.slice(0, 300)}`);
      ok(body.includes('dbv9Network'), 'no offline page for storefront navigations');
      await ctx.close();
    });

    await step('search suggestions: a signed-in shopper never fills the shared cache (BLD-03)', async () => {
      // The endpoint is called inside WordPress as each shopper (the staging header shows no search box to read a
      // nonce from); answers are shared through transients exactly as on a site without an object cache.
      const q = 'zz' + Date.now().toString(36).slice(-4);
      const search = (user) => JSON.parse(php(`
        define( 'DOING_AJAX', true );
        $u = '${user}' ? get_user_by( 'login', '${user}' ) : null; wp_set_current_user( $u ? $u->ID : 0 );
        if ( ! class_exists( 'Delicat_Builder_V9_Shell' ) ) { require_once DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-shell.php'; }
        $_POST = $_REQUEST = array( 'nonce' => wp_create_nonce( 'delicat_builder_v9_shell_interaction' ), 'q' => '${q}' );
        add_filter( 'wp_die_ajax_handler', function () { return function () { exit; }; } );
        Delicat_Builder_V9_Shell::ajax_product_search();`).split('\n').pop());
      const m1 = search('client1'), m2 = search('client1');
      ok(m1.success && m1.data.cached === false && m2.data.cached === false, `signed-in answers cached: ${JSON.stringify([m1, m2]).slice(0, 300)}`);
      const g1 = search(''), g2 = search('');
      ok(g1.success && g1.data.cached === false, `guest got an answer cached by a signed-in shopper: ${JSON.stringify(g1).slice(0, 300)}`);
      ok(g2.data.cached === true, 'guest answers are not shared any more');
    });

    await step('Google breadcrumbs: names decoded, shop crumb « Boutique » (FE-10)', async () => {
      const ctx = await phone(browser);
      const p = await ctx.newPage();
      await p.goto(base + '/product/free-fire/', { waitUntil: 'load' });
      const ld = await p.evaluate(() => [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => s.textContent).join('\n'));
      ok(/BreadcrumbList/.test(ld), 'no breadcrumb data');
      ok(!/&amp;|&#0?38;/.test(ld), 'HTML codes left in the structured data');
      await p.goto(base + '/shop/', { waitUntil: 'load' });
      const shop = await p.evaluate(() => [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => s.textContent).join('\n'));
      ok(!/"name":"Shop"/.test(shop), 'shop crumb still "Shop"');
      await ctx.close();
    });

    await step('signed-in pages never ask Gravatar; My Account loads no select2 or dashicons (FE-12, FE-09)', async () => {
      const ctx = await phone(browser, 'client1');
      const p = await ctx.newPage();
      const third = [];
      p.on('request', (r) => { if (/gravatar\.com/.test(r.url())) third.push(r.url()); });
      await p.goto(base + '/', { waitUntil: 'load' });
      await p.goto(base + '/my-account/', { waitUntil: 'load' });
      const css = await p.evaluate(() => [...document.querySelectorAll('link[rel=stylesheet]')].map((l) => l.id).join(' '));
      ok(!third.length, `Gravatar requests: ${third.join(', ')}`);
      ok(!/select2-css|dashicons-css/.test(css), `still loaded: ${css}`);
      await ctx.close();
    });

    await step('a page carrying a WooCommerce notice is never cached (BLD-07)', async () => {
      const ctx = await phone(browser);
      const p = await ctx.newPage();
      const id = sql("select ID from wp_posts where post_type='product' and post_status='publish' and post_name='pin-free-110-diamants'");
      const r = await p.goto(base + '/shop/?add-to-cart=' + id, { waitUntil: 'load' });
      const h = r.headers();
      const notice = await p.evaluate(() => !!document.querySelector('.woocommerce-message,.woocommerce-notices-wrapper :is(.woocommerce-message,.woocommerce-info)'));
      ok(!notice || /no-store|private/.test(h['cache-control'] || ''), `notice page cache-control: ${h['cache-control']}`);
      await ctx.close();
    });

    await step('another plugin\'s exception whose trace passes through the Builder does not trip Safe Mode (BLD-04)', async () => {
      const mu = path.join(site, 'wp-content/mu-plugins/zz-bld04-test.php');
      fs.writeFileSync(mu, "<?php // STAGING ONLY: written and removed by tests/redesign/builder-978.cjs.\nif ( isset( $_GET['zz_bld04'] ) ) { add_action( 'wp', function () { throw new RuntimeException( \"gateway down\\nStack trace:\\n#0 Delicat_Builder_V9_Core->boot()\" ); } ); }\n");
      try {
        sql("update wp_options set option_value='0' where option_name='delicat_builder_v9_safe_mode'");
        const ctx = await phone(browser);
        const p = await ctx.newPage();
        const r = await p.goto(base + '/?zz_bld04=1');
        ok(r.status() >= 500, `the test fault did not fire: ${r.status()}`);
        ok(sql("select option_value from wp_options where option_name='delicat_builder_v9_safe_mode'") !== '1', 'Safe Mode tripped by a foreign exception');
        await ctx.close();
      } finally {
        fs.rmSync(mu, { force: true });
        sql("update wp_options set option_value='0' where option_name='delicat_builder_v9_safe_mode'");
      }
    });

    await step('a paid order whose request died releases its payment lock after 5 minutes (BLD-02)', async () => {
      const out = php(`
        $uid = (int) get_user_by( 'login', 'client1' )->ID;
        $order = wc_create_order( array( 'customer_id' => $uid ) );
        $order->set_total( 10 ); $order->set_status( 'processing' ); $order->save();
        $id = wp_generate_uuid4(); $active = 'delicat_payment_active_' . $uid;
        delete_option( $active );
        add_option( $active, $id, '', false );
        add_option( 'delicat_payment_' . $uid . '_' . $id, array( 'status' => 'pending', 'order_id' => $order->get_id(), 'created' => time() - 600, 'hash' => 'x', 'issued' => '1', 'creating' => true ), '', false );
        $m = new ReflectionMethod( 'Delicat_Builder_V9_Express_Payment', 'reclaim_abandoned' ); $m->setAccessible( true );
        $released = $m->invoke( null, $active, $uid );
        $stamp = (float) get_option( 'delicat_payment_completed_' . $uid );
        $other = wc_create_order( array( 'customer_id' => $uid + 1000 ) ); $other->set_status( 'processing' ); $other->save();
        $id2 = wp_generate_uuid4(); add_option( $active, $id2, '', false );
        add_option( 'delicat_payment_' . $uid . '_' . $id2, array( 'status' => 'pending', 'order_id' => $other->get_id(), 'created' => time() - 600, 'creating' => true ), '', false );
        $kept = ! $m->invoke( null, $active, $uid );
        delete_option( $active ); delete_option( 'delicat_payment_' . $uid . '_' . $id ); delete_option( 'delicat_payment_' . $uid . '_' . $id2 );
        $order->delete( true ); $other->delete( true );
        echo json_encode( array( 'released' => $released, 'stamped' => $stamp > time() - 60, 'other_customer_kept' => $kept ) );`);
      const r = JSON.parse(out.split('\n').pop());
      ok(r.released && r.stamped && r.other_customer_kept, out);
    });
  } finally {
    await browser.close();
  }
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})();
