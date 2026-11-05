// Delicat Builder V9 release build (9.4.3).
// Usage: ESBUILD_DIR=<dir containing node_modules/> node build.mjs <plugin-root> <edited-js-dir> <original-pretty-dir>
//  - rebuilds every CSS bundle in assets/dist from readable sources with a
//    conservative target (no media-query range syntax, prefixes for Safari),
//  - re-minifies the edited engine modules and renames every changed module
//    (and every module importing a renamed one) by content hash,
//  - rewrites manifest.json, asset-versions.php and integrity-manifest.json.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';

// esbuild: global install or local node_modules (npm i -g esbuild)
const require = createRequire(process.env.ESBUILD_DIR || (process.cwd() + '/'));
const esbuild = require('esbuild');

const ROOT = path.resolve(process.argv[2]);
const JS_SRC = path.resolve(process.argv[3]);
const DIST = path.join(ROOT, 'assets/dist');
const sha = (buf) => crypto.createHash('sha256').update(buf).digest('hex');
const CSS_TARGET = ['chrome64', 'edge79', 'firefox67', 'safari12', 'ios12', 'opera51'];

const old = JSON.parse(fs.readFileSync(path.join(DIST, 'manifest.json'), 'utf8'));

/* ------------------------------------------------------------- CSS */
const subst = {
  'src/styles/engine.css': ['assets/css/engine-chrome.css'],
  'src/styles/chrome-guard.css': [],
  'src/styles/fixes.css': ['assets/css/polish-fixes.css'],
};
const cssOut = {};
const written = new Map(); // file -> name
for (const [name, def] of Object.entries(old.css)) {
  let sources = [];
  for (const s of def.sources) sources.push(...(subst[s] ?? [s]));
  // Sources added since 9.4.0; a manifest written by this build already lists them (no duplicates).
  if (name === 'chrome') for (const extra of ['assets/css/surface-compatibility.css', 'assets/css/chrome-fixes.css', 'assets/css/dark-mode.css', 'assets/css/dark-glass.css', 'assets/css/motion.css']) { if (!sources.includes(extra) && fs.existsSync(path.join(ROOT, extra))) sources.push(extra); }
  if ((name === 'product' || name === 'product-design') && !sources.includes('assets/css/reviews-community.css')) sources.splice(sources.indexOf('assets/css/reviews.css') + 1, 0, 'assets/css/reviews-community.css');
  for (const s of sources) if (!fs.existsSync(path.join(ROOT, s))) throw new Error(`missing ${s} for ${name}`);
  const joined = sources.map((s) => `/* ${s} */\n` + fs.readFileSync(path.join(ROOT, s), 'utf8')).join('\n');
  const res = esbuild.transformSync(joined, { loader: 'css', minify: true, target: CSS_TARGET, legalComments: 'none' });
  const code = res.code;
  if (/@media[^{]*\((?:width|height)\s*[<>]/.test(code)) throw new Error(`range media query left in ${name}`);
  const hash = sha(code).slice(0, 12);
  const file = `${name.replace(/-lite$/, '-lite')}.${hash}.css`;
  // identical bundles (home-lite / builder-lite) share one file
  let target = file;
  for (const [f] of written) if (f.endsWith(`.${hash}.css`)) target = f;
  if (!written.has(target)) {
    fs.writeFileSync(path.join(DIST, target), code);
    written.set(target, name);
  }
  cssOut[name] = { file: target, bytes: Buffer.byteLength(code), sha256: sha(code), sources };
  const warn = res.warnings.filter((w) => !/Unexpected "\{"|"-webkit-text-fill-color"/.test(w.text));
  if (warn.length) console.log(`  ${name}: ${warn.length} warnings (first: ${warn[0].text})`);
  console.log(`css ${name.padEnd(20)} ${String(def.bytes).padStart(7)} -> ${String(Buffer.byteLength(code)).padStart(7)}  ${target}`);
}

/* -------------------------------------------------------------- JS */
// Current dist modules: logical path (relative to DIST) -> content
const mods = new Map();
mods.set(old.js.engine.file, fs.readFileSync(path.join(DIST, old.js.engine.file), 'utf8'));
for (const f of Object.keys(old.chunks)) mods.set(f, fs.readFileSync(path.join(DIST, f), 'utf8'));
// Edited sources (prettified copies named after their dist file)
const edited = new Set();
for (const f of [...mods.keys()]) {
  const src = path.join(JS_SRC, path.basename(f));
  if (!fs.existsSync(src)) continue;
  const pretty = fs.readFileSync(src, 'utf8');
  const origPretty = fs.readFileSync(path.join(path.resolve(process.argv[4]), path.basename(f)), 'utf8');
  if (pretty === origPretty) continue;
  const min = esbuild.transformSync(pretty, { loader: 'js', format: 'esm', minify: true, target: 'es2019', legalComments: 'none' }).code;
  mods.set(f, min);
  edited.add(f);
}
console.log('edited modules:', [...edited].join(', ') || '(none)');
// Rename until fixed point: a module is renamed when its content changed.
const renames = new Map(); // oldPath -> newPath
let changed = new Set(edited);
for (let guard = 0; guard < 10 && changed.size; guard++) {
  const next = new Set();
  for (const f0 of changed) {
    let f = f0;
    while (renames.has(f)) f = renames.get(f);
    const content = mods.get(f);
    if (content === undefined) throw new Error('lost module ' + f0);
    const dir = path.posix.dirname(f);
    const base = path.posix.basename(f);
    const stem = base.replace(/\.[A-Za-z0-9]{6,16}\.js$/, '');
    const nf = (dir === '.' ? '' : dir + '/') + `${stem}.${sha(content).slice(0, 12)}.js`;
    if (nf === f) continue;
    mods.delete(f);
    mods.set(nf, content);
    renames.set(f, nf);
    // rewrite references in every other module
    for (const [g, c] of mods) {
      const fromDir = path.posix.dirname(g);
      let rel = path.posix.relative(fromDir === '.' ? '' : fromDir, f);
      let nrel = path.posix.relative(fromDir === '.' ? '' : fromDir, nf);
      if (!rel.startsWith('.')) rel = './' + rel;
      if (!nrel.startsWith('.')) nrel = './' + nrel;
      if (c.includes(`"${rel}"`)) {
        mods.set(g, c.split(`"${rel}"`).join(`"${nrel}"`));
        next.add(g);
      }
    }
  }
  changed = next;
}
// resolve chains (a -> b -> c)
const finalName = (f) => { let n = f; while (renames.has(n)) n = renames.get(n); return n; };
for (const [f, c] of mods) fs.writeFileSync(path.join(DIST, f), c);

const engineFile = finalName(old.js.engine.file);
const importsOf = (c) => [...c.matchAll(/(?:from|import)\s*"(\.\/[^"]+)"/g)].map((m) => m[1]);
const engineStatic = [...mods.get(engineFile).matchAll(/from\s*"\.\/([^"]+)"/g)].map((m) => m[1]);
const manifest = {
  version: 2,
  builtAt: new Date().toISOString(),
  js: { engine: { file: engineFile, bytes: Buffer.byteLength(mods.get(engineFile)), sha256: sha(mods.get(engineFile)), imports: engineStatic } },
  chunks: {},
  css: cssOut,
};
for (const [f, meta] of Object.entries(old.chunks)) {
  const nf = finalName(f);
  manifest.chunks[nf] = { bytes: Buffer.byteLength(mods.get(nf)), sha256: sha(mods.get(nf)), name: meta.name };
}
fs.writeFileSync(path.join(DIST, 'manifest.json'), JSON.stringify(manifest, null, 1) + '\n');

// Delete dist files no longer referenced.
const keep = new Set(['manifest.json', '.htaccess', engineFile, ...Object.keys(manifest.chunks), ...Object.values(cssOut).map((b) => b.file)]);
const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]));
for (const abs of walk(DIST)) {
  const rel = path.relative(DIST, abs).split(path.sep).join('/');
  if (!keep.has(rel)) { fs.unlinkSync(abs); console.log('removed dist', rel); }
}
for (const [a, b] of renames) console.log(`renamed ${a} -> ${b}`);

/* ------------------------------------------------ inline twins */
{
  const src = path.join(ROOT, 'pro/assets/dbp-critical.css');
  const min = esbuild.transformSync(fs.readFileSync(src, 'utf8'), { loader: 'css', minify: true, target: CSS_TARGET, legalComments: 'none' }).code;
  fs.writeFileSync(path.join(ROOT, 'pro/assets/dbp-critical.min.css'), min);
}

/* ------------------------------------------------------ asset map */
const assetFiles = walk(ROOT)
  .map((a) => path.relative(ROOT, a).split(path.sep).join('/'))
  .filter((r) => /\.(css|js)$/.test(r) && !r.startsWith('assets/dist/'))
  .sort();
let php = "<?php\n// Generated by the release build: asset path => 12-hex sha256 of contents, applied as ?ver=.\nreturn array(\n";
for (const r of assetFiles) php += ` '${r}' => '${sha(fs.readFileSync(path.join(ROOT, r))).slice(0, 12)}',\n`;
php += ");\n";
fs.writeFileSync(path.join(ROOT, 'asset-versions.php'), php);

/* ------------------------------------------------------- integrity */
const files = {};
let bytes = 0;
const all = walk(ROOT).map((a) => path.relative(ROOT, a).split(path.sep).join('/')).filter((r) => r !== 'integrity-manifest.json').sort();
for (const r of all) {
  const buf = fs.readFileSync(path.join(ROOT, r));
  files[r] = { bytes: buf.length, sha256: sha(buf) };
  bytes += buf.length;
}
const version = (fs.readFileSync(path.join(ROOT, 'delicat-builder-v9.php'), 'utf8').match(/define\( 'DELICAT_BUILDER_V9_VERSION', '([^']+)'/) || [])[1];
fs.writeFileSync(path.join(ROOT, 'integrity-manifest.json'), JSON.stringify({ algorithm: 'sha256', built: new Date().toISOString(), version, bytes, count: all.length, files }, null, 1) + '\n');
console.log(`integrity: ${all.length} files, ${bytes} bytes, version ${version}`);
