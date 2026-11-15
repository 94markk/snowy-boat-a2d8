// V10 surgical CSS release step (css-head). Run on a scratch copy of the plugin BEFORE build-v10.mjs with SKIP_CSS=1.
// Usage: ESBUILD_DIR=<dir containing node_modules/>/ node patch-css-v10.mjs <plugin-root>
//  1. every shipped CSS bundle: selectors that need a class no code can emit are dropped (DEAD below);
//  2. chrome: starts with pro/assets/dbp-critical.min.css (no longer printed inline while the engine
//     delivers the page) and ends with the V10 rules of assets/css/chrome-fixes.css (after MARKER);
//  3. changed bundles are renamed by content hash; manifest.json (file/bytes/sha256, chrome sources) updated.
// Idempotent: a second run changes nothing.
import fs from 'node:fs'; import path from 'node:path'; import crypto from 'node:crypto'; import { createRequire } from 'node:module';
import { editCss } from './cssedit.mjs';
const require = createRequire(process.env.ESBUILD_DIR || (process.cwd() + '/'));
const esbuild = require('esbuild');
const ROOT = path.resolve(process.argv[2]); const DIST = path.join(ROOT, 'assets/dist');
const sha = (b) => crypto.createHash('sha256').update(b).digest('hex');
const CSS_TARGET = ['chrome64', 'edge79', 'firefox67', 'safari12', 'ios12', 'opera51'];
// Verified: no PHP, template, JS (raw, legacy, engine) or JSON of the six plugins writes these names, literally or by prefix.
export const DEAD = new Set([
  'dbv9-ft__trust', 'dbv9-ft__trust-icon',                       // native-footer.css: footer trust strip, no longer rendered
  'delicat-product-card__overlay-title',                          // products.css: overlay title variant, no markup
  'delicat-testimonials__footer', 'delicat-testimonials__track',  // testimonials.css: pre-9.x markup; the renderer
  'delicat-testimonials__card', 'delicat-testimonials__quote',    // now prints __mcard/__mquote/__mstars/__mfoot
  'delicat-testimonials__copy', 'delicat-testimonials__stars',
]);
const MARKER = '/* V10 — Root cause';
const CRIT_SRC = 'pro/assets/dbp-critical.css';
const man = JSON.parse(fs.readFileSync(path.join(DIST, 'manifest.json'), 'utf8'));
const crit = fs.readFileSync(path.join(ROOT, 'pro/assets/dbp-critical.min.css'), 'utf8').trim();
const fixes = fs.readFileSync(path.join(ROOT, 'assets/css/chrome-fixes.css'), 'utf8');
const at = fixes.indexOf(MARKER);
const v10 = at < 0 ? '' : esbuild.transformSync(fixes.slice(at), { loader: 'css', minify: true, target: CSS_TARGET, legalComments: 'none' }).code.trim();
const renamed = new Map(); const report = [];
for (const file of [...new Set(Object.values(man.css).map((d) => d.file))]) {
  const old = fs.readFileSync(path.join(DIST, file), 'utf8');
  const log = [];
  let code = editCss(old, DEAD, log);
  const names = Object.entries(man.css).filter(([, d]) => d.file === file).map(([n]) => n);
  if (names.includes('chrome')) {
    if (!code.startsWith(crit)) code = crit + '\n' + code;
    if (v10 && !code.includes(v10)) code = code.replace(/\n?$/, '\n') + v10;
  }
  if (code === old) continue;
  const stem = file.replace(/\.[0-9a-f]{12}\.css$/, '');
  const nf = `${stem}.${sha(code).slice(0, 12)}.css`;
  fs.writeFileSync(path.join(DIST, nf), code);
  if (nf !== file) fs.unlinkSync(path.join(DIST, file));
  renamed.set(file, { nf, bytes: Buffer.byteLength(code), sha256: sha(code) });
  report.push(`${names.join('+').padEnd(26)} ${String(Buffer.byteLength(old)).padStart(7)} -> ${String(Buffer.byteLength(code)).padStart(7)}  ${nf}  (${log.length} edits)`);
}
for (const [name, def] of Object.entries(man.css)) {
  const r = renamed.get(def.file);
  if (r) Object.assign(def, { file: r.nf, bytes: r.bytes, sha256: r.sha256 });
  if (name === 'chrome' && !def.sources.includes(CRIT_SRC)) def.sources.unshift(CRIT_SRC);
}
if (renamed.size) { man.builtAt = new Date().toISOString(); fs.writeFileSync(path.join(DIST, 'manifest.json'), JSON.stringify(man, null, 1) + '\n'); }
console.log(report.join('\n') || 'nothing to change');
