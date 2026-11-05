// node update-safety.cjs <base-url> <site-dir> <builder-copy-dir> — STAGING ONLY.
// Replays what a WordPress plugin update does to the visitors who arrive while it runs (a kernel file already
// deleted, a module half written), a fault that really repeats, re-activation and Safe Mode, against a throwaway copy
// of the Builder linked in place of the installed one. The installed plugin must be a symlink; it is put back after.
// Before 9.7.7 the first case removed the Builder from the active plugins and the store stayed on the bare theme.
const fs = require('fs');
const os = require('os');
const path = require('path');
const https = require('https');
const { execFileSync } = require('child_process');

const [base, site, copy] = process.argv.slice(2);
if (!base || !site || !copy) { console.error('usage: node update-safety.cjs <base-url> <site-dir> <builder-copy-dir>'); process.exit(2); }
const SOCK = process.env.MYSQL_SOCKET || '/tmp/mdb.sock';
const LOCAL = /^https:\/\/(localhost|127\.0\.0\.1)(:\d+)?(\/|$)/.test(base);
const cfg = fs.readFileSync(path.join(site, 'wp-config.php'), 'utf8');
const DB = /DB_NAME['"]\s*,\s*['"]([^'"]+)/.exec(cfg)[1];
const T = ((/\$table_prefix\s*=\s*['"]([^'"]+)/.exec(cfg) || [])[1] || 'wp_') + 'options';
const PLUGIN = 'delicat-builder-v9/delicat-builder-v9.php';
const link = path.join(site, 'wp-content/plugins/delicat-builder-v9');
const mu = path.join(site, 'wp-content/mu-plugins/zz-update-safety-log.php');
const log = path.join(site, 'wp-content/mu-plugins/zz-update-safety.log');
const version = /define\(\s*'DELICAT_BUILDER_V9_VERSION',\s*'([^']+)'/.exec(fs.readFileSync(path.join(copy, 'delicat-builder-v9.php'), 'utf8'))[1];

const sql = (q) => execFileSync('mysql', ['-S', SOCK, '-N', '-B', DB, '-e', q], { encoding: 'utf8' }).trim();
const esc = (v) => String(v).replace(/\\/g, '\\\\').replace(/'/g, "''");
const opt = (name) => sql(`select option_value from ${T} where option_name='${esc(name)}'`);
const setOpt = (name, value) => sql(`insert into ${T} (option_name, option_value, autoload) values ('${esc(name)}', '${esc(value)}', 'off') on duplicate key update option_value=values(option_value)`);
const delOpt = (...names) => sql(`delete from ${T} where option_name in (${names.map((n) => `'${esc(n)}'`).join(',')})`);
const ser = (o) => `a:${Object.keys(o).length}:{${Object.entries(o).map(([k, v]) => `s:${Buffer.byteLength(k)}:"${k}";s:${Buffer.byteLength(String(v))}:"${v}";`).join('')}}`;
const iso = (t) => new Date(t).toISOString().replace(/\.\d+Z$/, '+00:00');
const active = () => opt('active_plugins').includes(PLUGIN);
const streak = () => +((/"count";i:(\d+)/.exec(opt('delicat_builder_v9_fatal_streak')) || [])[1] || 0);
const readLog = () => (fs.existsSync(log) ? fs.readFileSync(log, 'utf8') : '');
const get = (p) => new Promise((resolve, reject) => {
  // The staging proxy on localhost uses a self-signed certificate; any other host is verified normally.
  https.get(base + p, { rejectUnauthorized: !LOCAL, headers: { 'User-Agent': 'delicat-update-safety' } }, (r) => {
    let body = ''; r.setEncoding('utf8'); r.on('data', (c) => { body += c; }); r.on('end', () => resolve({ status: r.statusCode, headers: r.headers, body }));
  }).on('error', reject);
});
const builderOn = (r) => r.body.includes('data-dsb8-header');
// Kept out of every shared cache: the browser-only (private) or no-store Cache-Control, and no-store for the CDN.
const noStore = (r) => /no-store|private/.test(r.headers['cache-control'] || '') && /no-store/.test(r.headers['cdn-cache-control'] || '');
const ok = (c, m) => { if (!c) throw new Error(m); };
const results = [];
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); } catch (e) { results.push(['FAIL', name]); console.log('FAIL', name, '—', e.message.slice(0, 400)); }
}
const age = (p) => { const t = new Date(Date.now() - 3600e3); fs.utimesSync(p, t, t); };
const ageTree = (dir) => { for (const e of fs.readdirSync(dir, { withFileTypes: true })) { const p = path.join(dir, e.name); if (e.isDirectory()) ageTree(p); age(p); } age(dir); };
const php = (code) => {
  const f = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'dbv9-us-')), 'run.php');
  fs.writeFileSync(f, `<?php\n$_SERVER['HTTP_HOST'] = parse_url(${JSON.stringify(base)}, PHP_URL_HOST) . ':' . parse_url(${JSON.stringify(base)}, PHP_URL_PORT); $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = 'localhost';\nrequire ${JSON.stringify(path.join(site, 'wp-load.php'))};\nrequire_once ABSPATH . 'wp-admin/includes/plugin.php';\nwp_set_current_user( 1 );\n${code}\n`);
  return execFileSync('php', [f], { encoding: 'utf8' }).trim();
};

(async () => {
  if (!fs.lstatSync(link).isSymbolicLink()) { console.error(`${link} is not a symlink: refusing to touch a real install`); process.exit(2); }
  const original = fs.readlinkSync(link);
  const savedActive = opt('active_plugins');
  ok(savedActive.includes(PLUGIN), 'the Builder must be active before the test');
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'dbv9-update-'));
  const dir = path.join(tmp, 'delicat-builder-v9');
  fs.cpSync(copy, dir, { recursive: true });
  ageTree(dir); // a copy installed an hour ago: nothing in it counts as freshly written
  const swap = (target) => { fs.unlinkSync(link); fs.symlinkSync(target, link); };
  const reset = () => { setOpt('active_plugins', savedActive); setOpt('delicat_builder_v9_safe_mode', '0'); delOpt('delicat_builder_v9_circuit_breaker_tripped', 'delicat_builder_v9_fatal_streak', 'delicat_builder_v9_purge_pending', 'delicat_builder_v9_incomplete_copy', 'delicat_builder_v9_routine_purge_at'); };
  fs.writeFileSync(mu, `<?php // STAGING ONLY: written and removed by tests/redesign/update-safety.cjs.\nadd_action( 'litespeed_purge_all', function () { file_put_contents( __DIR__ . '/zz-update-safety.log', "purge_all\\n", FILE_APPEND ); } );\nadd_action( 'litespeed_control_set_nocache', function ( $why = '' ) { file_put_contents( __DIR__ . '/zz-update-safety.log', 'nocache ' . $why . "\\n", FILE_APPEND ); } );\n`);
  swap(dir);
  reset();
  try {
    await step('a healthy page is served normally and stays cacheable', async () => {
      const r = await get('/');
      ok(r.status === 200 && builderOn(r), `status ${r.status}, Builder header ${builderOn(r)}`);
      ok(!/no-store/.test(r.headers['cache-control'] || ''), `cache-control: ${r.headers['cache-control']}`);
    });

    await step('a visitor arriving while the update has deleted a kernel file: page served, not cached, Builder stays on', async () => {
      const f = path.join(dir, 'includes/class-delicat-builder-transport.php');
      fs.renameSync(f, f + '.gone');
      fs.writeFileSync(log, '');
      let r;
      try { r = await get('/'); } finally { fs.renameSync(f + '.gone', f); age(f); }
      ok(r.status === 200, `status ${r.status} during the update`);
      ok(!builderOn(r), 'the Builder rendered without its kernel?');
      ok(noStore(r), `degraded page cacheable: cache-control=${r.headers['cache-control']} cdn=${r.headers['cdn-cache-control']}`);
      ok(readLog().includes('nocache'), 'LiteSpeed was not told to skip the page');
      ok(active(), 'the Builder switched itself off');
      ok(opt('delicat_builder_v9_safe_mode') !== '1', 'Safe Mode recorded for an incomplete copy');
      ok(!opt('delicat_builder_v9_circuit_breaker_tripped'), 'circuit breaker recorded');
      const after = await get('/');
      ok(builderOn(after), 'the Builder did not come back once the copy was complete');
    });

    await step('a module half written by the update trips nothing', async () => {
      const f = path.join(dir, 'includes/class-delicat-builder-core.php');
      const full = fs.readFileSync(f);
      fs.writeFileSync(f, full.subarray(0, Math.floor(full.length * 0.4)));
      let r;
      try { r = await get('/'); } finally { fs.writeFileSync(f, full); age(f); }
      ok(r.status === 200, `status ${r.status}`);
      ok(active() && opt('delicat_builder_v9_safe_mode') !== '1', `active=${active()} safe_mode=${opt('delicat_builder_v9_safe_mode')}`);
      ok(builderOn(await get('/')), 'the Builder did not come back');
    });

    await step('visitors arriving after the update has deleted other Builder files: nothing switches the storefront off', async () => {
      const gone = ['includes/class-delicat-builder-archive-builder.php', 'includes/class-delicat-builder-native-product.php', 'includes/class-delicat-builder-header-runtime.php', 'includes/class-delicat-builder-header-studio-8.php', 'includes/class-delicat-builder-footer.php']
        .map((f) => path.join(dir, f)).filter((f) => fs.existsSync(f));
      for (const f of gone) fs.renameSync(f, f + '.gone');
      const seen = [];
      try { for (const p of ['/', '/shop/', '/product/free-fire/']) { const r = await get(p); seen.push(`${p} ${r.status}`); } } finally { for (const f of gone) { fs.renameSync(f + '.gone', f); age(f); } }
      ok(active() && opt('delicat_builder_v9_safe_mode') !== '1' && !opt('delicat_builder_v9_circuit_breaker_tripped'), `active=${active()} safe=${opt('delicat_builder_v9_safe_mode')} breaker=${opt('delicat_builder_v9_circuit_breaker_tripped')} — ${seen.join(', ')}`);
      ok(builderOn(await get('/')), 'the Builder did not come back');
    });

    await step('a fault that really repeats still switches the Builder off, on the third time only', async () => {
      const f = path.join(dir, 'includes/class-delicat-builder-core.php');
      const full = fs.readFileSync(f);
      fs.writeFileSync(f, Buffer.concat([full, Buffer.from('\nclass Delicat_Builder_V9_Cache {}\n')]));
      age(f);
      const seen = [];
      try {
        for (let i = 1; i <= 3; i++) { const r = await get('/'); seen.push(`#${i}: ${r.status} active=${active()} streak=${streak()} safe=${opt('delicat_builder_v9_safe_mode')}`); }
      } finally { fs.writeFileSync(f, full); age(f); }
      // Every request that loads WordPress counts (WP-Cron's loopback too), so the switch-off is checked on the
      // count of fatals, not on the number of visits made here: never before the third, always once it is reached.
      ok(/#1: 500 active=true streak=[12] safe=1/.test(seen[0]), seen.join(' | '));
      ok(!active() && streak() >= 3 && !!opt('delicat_builder_v9_circuit_breaker_tripped'), seen.join(' | '));
    });
    reset();

    // 9.7.8 (BLD-01): the update window of 9.7.7 had no end and counted any shipped file.
    const fault = (f) => { const full = fs.readFileSync(f); fs.writeFileSync(f, Buffer.concat([full, Buffer.from('\nclass Delicat_Builder_V9_Cache {}\n')])); age(f); return full; };
    await step('a missing README or font never silences the safety net (9.7.8)', async () => {
      const readme = path.join(dir, 'README.txt');
      const core = path.join(dir, 'includes/class-delicat-builder-core.php');
      fs.renameSync(readme, readme + '.gone');
      const full = fault(core);
      let r;
      try { r = await get('/'); } finally { fs.writeFileSync(core, full); age(core); fs.renameSync(readme + '.gone', readme); }
      ok(r.status === 500 && opt('delicat_builder_v9_safe_mode') === '1', `status ${r.status} safe=${opt('delicat_builder_v9_safe_mode')}: the fault was taken for an update`);
    });
    reset();

    await step('a code file missing for more than 10 minutes: the safety net works again and wp-admin names the file (9.7.8)', async () => {
      const footer = path.join(dir, 'includes/class-delicat-builder-footer.php');
      const core = path.join(dir, 'includes/class-delicat-builder-core.php');
      const now = Math.floor(Date.now() / 1000);
      setOpt('delicat_builder_v9_incomplete_copy', `a:3:{s:5:"since";i:${now - 660};s:4:"seen";i:${now - 30};s:7:"missing";a:0:{}}`);
      fs.renameSync(footer, footer + '.gone');
      const full = fault(core);
      let r, notice = '';
      try {
        r = await get('/');
        fs.writeFileSync(core, full); age(core); // the notice is read by a healthy request
        notice = php(`ob_start(); delicat_builder_v9_incomplete_copy_notice(); echo ob_get_clean();`);
      } finally { fs.writeFileSync(core, full); age(core); fs.renameSync(footer + '.gone', footer); age(footer); }
      ok(r.status === 500 && opt('delicat_builder_v9_safe_mode') === '1', `status ${r.status} safe=${opt('delicat_builder_v9_safe_mode')}: still treated as an update after 11 minutes`);
      ok(/class-delicat-builder-footer\.php/.test(notice) && /Réinstallez/.test(notice), `admin notice: ${notice.slice(0, 200)}`);
    });
    reset();

    await step('activating the Builder again clears the breaker and empties the caches on the next request', async () => {
      setOpt('delicat_builder_v9_circuit_breaker_tripped', String(Math.floor(Date.now() / 1000)));
      const out = php(`deactivate_plugins( '${PLUGIN}', true ); $r = activate_plugin( '${PLUGIN}' ); echo is_wp_error( $r ) ? 'FAIL ' . $r->get_error_message() : 'ok';`);
      ok(out.endsWith('ok'), `activation: ${out}`);
      ok(active() && !opt('delicat_builder_v9_circuit_breaker_tripped') && !!opt('delicat_builder_v9_purge_pending'), `active=${active()} breaker=${opt('delicat_builder_v9_circuit_breaker_tripped')} pending=${opt('delicat_builder_v9_purge_pending')}`);
      fs.writeFileSync(log, '');
      const r = await get('/');
      ok(builderOn(r) && readLog().includes('purge_all') && !opt('delicat_builder_v9_purge_pending'), `header=${builderOn(r)} log=${JSON.stringify(readLog())} pending=${opt('delicat_builder_v9_purge_pending')}`);
    });

    await step('a Safe Mode page is never cached, and leaving Safe Mode empties the caches', async () => {
      setOpt('delicat_builder_v9_safe_mode', '1');
      setOpt('delicat_builder_v9_safe_mode_meta', ser({ version, tripped: iso(Date.now()), context: 'update_safety_test' }));
      fs.writeFileSync(log, '');
      const r = await get('/');
      ok(!builderOn(r) && noStore(r) && readLog().includes('nocache'), `header=${builderOn(r)} cache-control=${r.headers['cache-control']} log=${JSON.stringify(readLog())}`);
      setOpt('delicat_builder_v9_safe_mode_meta', ser({ version, tripped: iso(Date.now() - 11 * 60e3), context: 'update_safety_test' }));
      fs.writeFileSync(log, '');
      const r2 = await get('/');
      ok(builderOn(r2) && readLog().includes('purge_all'), `after the 10-minute breaker: header=${builderOn(r2)} log=${JSON.stringify(readLog())}`);
      ok(!/no-store/.test(r2.headers['cache-control'] || ''), `whole page still marked no-store: ${r2.headers['cache-control']}`);
    });
  } finally {
    swap(original);
    reset();
    fs.rmSync(mu, { force: true });
    fs.rmSync(log, { force: true });
    fs.rmSync(tmp, { recursive: true, force: true });
  }
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
