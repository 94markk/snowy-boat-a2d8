// Delicat Builder V9: engine build from the readable sources in engine-src/ (9.7.8).
//
// Usage (from tools/builder, after `npm ci`):
//   node build-engine.mjs <plugin-root>
// then the release build for CSS, asset versions and the integrity manifest:
//   node build.mjs <plugin-root> <empty-dir> <empty-dir>
//
// engine-src/ holds one readable ES module per shipped file, under a stable name
// (engine.js, chunks/purchase.js, chunks/shared-a.js ...). Imports between them
// use those stable names. modules.json maps each one to the content-hashed file
// shipped in assets/dist and records the sha256 of the source that file was
// built from.
//
//  - A module whose source is unchanged keeps its shipped bytes; only the names
//    of the modules it imports are rewritten when one of them was renamed.
//  - A module whose source changed is minified again (esbuild, es2019).
//  - Every module whose bytes change is renamed by content hash, dependencies
//    first, so a file name always identifies one exact content.
//  - assets/dist/manifest.json (js + chunks), modules.json and the old files
//    are updated. The CSS part of the manifest is left to build.mjs.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(process.env.ESBUILD_DIR ? path.join(process.env.ESBUILD_DIR, 'x.js') : path.join(HERE, 'package.json'));
const esbuild = require('esbuild');

const ROOT = path.resolve(process.argv[2] || '');
const DIST = path.join(ROOT, 'assets/dist');
const SRC = path.join(HERE, 'engine-src');
const MAP_FILE = path.join(HERE, 'modules.json');
if (!fs.existsSync(path.join(DIST, 'manifest.json'))) {
  console.error('usage: node build-engine.mjs <delicat-builder-v9 folder>');
  process.exit(2);
}

const sha = (text) => crypto.createHash('sha256').update(text).digest('hex');
const posix = path.posix;
const map = JSON.parse(fs.readFileSync(MAP_FILE, 'utf8'));
const modules = map.modules;
const SPEC = /(["'])(\.{1,2}\/[^"'\n]+?\.js)\1/g;

const rel = (fromFile, toFile) => {
  const r = posix.relative(posix.dirname(fromFile), toFile);
  return r.startsWith('.') ? r : './' + r;
};
const srcText = (logical) => fs.readFileSync(path.join(SRC, logical), 'utf8');

// Import graph from the sources (stable names).
const deps = {};
for (const logical of Object.keys(modules)) {
  deps[logical] = [];
  for (const m of srcText(logical).matchAll(SPEC)) {
    const target = posix.normalize(posix.join(posix.dirname(logical), m[2]));
    if (!modules[target]) throw new Error(`${logical} imports ${m[2]}, which is not in modules.json`);
    if (!deps[logical].includes(target)) deps[logical].push(target);
  }
}
// Dependencies first; a cycle cannot be content-hashed and is refused.
const order = [];
const state = {};
const visit = (n, trail) => {
  if (state[n] === 2) return;
  if (state[n] === 1) throw new Error('import cycle: ' + [...trail, n].join(' -> '));
  state[n] = 1;
  for (const d of deps[n]) visit(d, [...trail, n]);
  state[n] = 2;
  order.push(n);
};
Object.keys(modules).forEach((n) => visit(n, []));

const oldToLogical = Object.fromEntries(Object.entries(modules).map(([l, m]) => [m.dist, l]));
const finalDist = {};
const changed = [];
for (const logical of order) {
  const rec = modules[logical];
  const source = srcText(logical);
  const edited = sha(source) !== rec.src_sha;
  let code;
  if (edited) {
    const linked = source.replace(SPEC, (all, q, spec) => {
      const target = posix.normalize(posix.join(posix.dirname(logical), spec));
      return q + rel(rec.dist, finalDist[target]) + q;
    });
    code = esbuild.transformSync(linked, { loader: 'js', format: 'esm', minify: true, target: 'es2019', legalComments: 'none' }).code;
  } else {
    code = fs.readFileSync(path.join(DIST, rec.dist), 'utf8');
    code = code.replace(SPEC, (all, q, spec) => {
      const oldTarget = posix.normalize(posix.join(posix.dirname(rec.dist), spec));
      const target = oldToLogical[oldTarget];
      if (!target) throw new Error(`${rec.dist} imports ${spec}, which modules.json does not know`);
      return q + rel(rec.dist, finalDist[target]) + q;
    });
  }
  const stem = posix.basename(rec.dist).replace(/\.[a-f0-9]{12}\.js$/, '');
  const name = posix.join(posix.dirname(rec.dist), `${stem}.${sha(code).slice(0, 12)}.js`).replace(/^\.\//, '');
  finalDist[logical] = name;
  if (name !== rec.dist || edited) changed.push(`${logical}: ${rec.dist} -> ${name}${edited ? ' (rebuilt from source)' : ''}`);
  fs.writeFileSync(path.join(DIST, name), code);
  rec.next = { dist: name, src_sha: sha(source), bytes: Buffer.byteLength(code), sha256: sha(code) };
}

// Remove shipped JS files no module points at any more.
const keep = new Set(Object.values(finalDist));
for (const dir of ['', 'chunks']) {
  for (const f of fs.readdirSync(path.join(DIST, dir))) {
    const relName = dir ? `${dir}/${f}` : f;
    if (f.endsWith('.js') && !keep.has(relName)) {
      fs.unlinkSync(path.join(DIST, relName));
      console.log('removed', relName);
    }
  }
}

// manifest.json: engine + chunks; the CSS section stays as it is.
const manifestPath = path.join(DIST, 'manifest.json');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const engineLogical = Object.keys(modules).find((l) => modules[l].role === 'engine');
const engineFile = finalDist[engineLogical];
const engineCode = fs.readFileSync(path.join(DIST, engineFile), 'utf8');
manifest.builtAt = new Date().toISOString();
manifest.js = {
  engine: {
    file: engineFile,
    bytes: Buffer.byteLength(engineCode),
    sha256: sha(engineCode),
    imports: [...engineCode.matchAll(/from\s*"\.\/([^"]+)"/g)].map((m) => m[1]),
  },
};
manifest.chunks = {};
for (const [logical, rec] of Object.entries(modules)) {
  if (logical === engineLogical) continue;
  manifest.chunks[rec.next.dist] = { bytes: rec.next.bytes, sha256: rec.next.sha256, name: rec.name ?? null };
}
fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 1) + '\n');

for (const rec of Object.values(modules)) {
  rec.dist = rec.next.dist;
  rec.src_sha = rec.next.src_sha;
  delete rec.next;
}
fs.writeFileSync(MAP_FILE, JSON.stringify(map, null, 1) + '\n');
console.log(changed.length ? changed.join('\n') : 'engine: nothing changed');
