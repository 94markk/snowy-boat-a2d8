#!/usr/bin/env node
/**
 * Package the plugin as an installable WordPress zip: build/delicat-builder-v9.zip
 * Excludes development files (src/, tools/, node_modules/, package files).
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, existsSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { EXCLUDED } from './manifest.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '..');
const parent = resolve(plugin, '..');
const outDir = join(parent, 'build');
const out = join(outDir, 'delicat-builder-v9.zip');

if (!existsSync(join(plugin, 'assets', 'dist', 'manifest.json'))) {
	console.error('assets/dist/manifest.json missing — run `npm run build` first.');
	process.exit(1);
}
mkdirSync(outDir, { recursive: true });
if (existsSync(out)) rmSync(out);
const excludes = EXCLUDED.map((rule) => (rule.endsWith('/') ? `delicat-builder-v9/${rule}*` : (rule.startsWith('.') && !rule.includes('/') ? `*/${rule}` : `delicat-builder-v9/${rule}`)));
execFileSync('zip', ['-r', '-q', '-X', out, 'delicat-builder-v9', '-x', ...excludes], { cwd: parent, stdio: 'inherit' });
console.log('written ' + out);
