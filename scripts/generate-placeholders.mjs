/**
 * Generates placeholder product artwork so the site is complete before real
 * key art exists.
 *
 * These are deliberately plain: brand key art for Free Fire, Netflix and the
 * rest is licensed material, and shipping copies of it would put the store at
 * legal risk. Replace each file with artwork you have the right to use —
 * suppliers usually provide it — keeping the same filename.
 *
 * Run with: npm run placeholders
 */

import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");

/** slug, label drawn on the tile, and the two gradient stops. */
const tiles = [
  ["free-fire-latam", "Free Fire", "#f97316", "#b91c1c"],
  ["free-fire-pin", "FF PIN", "#fb923c", "#9a3412"],
  ["pubg-mobile", "PUBG", "#f5b90a", "#78350f"],
  ["blood-strike", "Blood Strike", "#64748b", "#0f172a"],
  ["dls-2026", "DLS 2026", "#22c55e", "#064e3b"],
  ["apple-gift-card", "Apple", "#a78bfa", "#312e81"],
  ["roblox", "Roblox", "#94a3b8", "#0b1120"],
  ["netflix", "Netflix", "#ef4444", "#450a0a"],
  ["crunchyroll", "Crunchyroll", "#f97316", "#7c2d12"],
  ["paypal", "PayPal", "#3b82f6", "#1e3a8a"],
  ["meru", "Meru", "#8b5cf6", "#2e1065"],
];

function escapeXml(value) {
  return value.replace(/[<>&"']/g, (char) => `&#${char.charCodeAt(0)};`);
}

function tile(slug, label, from, to, width = 800, height = 800) {
  const id = slug.replace(/[^a-z0-9]/g, "");
  const fontSize = Math.round(Math.min(width, height) * 0.1);

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" role="img" aria-label="${escapeXml(label)}">
  <defs>
    <linearGradient id="bg${id}" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="${from}"/>
      <stop offset="1" stop-color="${to}"/>
    </linearGradient>
    <radialGradient id="glow${id}" cx="0.75" cy="0.2" r="0.7">
      <stop offset="0" stop-color="#ffffff" stop-opacity="0.34"/>
      <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect width="${width}" height="${height}" fill="#0a0b24"/>
  <rect width="${width}" height="${height}" fill="url(#bg${id})" opacity="0.92"/>
  <rect width="${width}" height="${height}" fill="url(#glow${id})"/>
  <circle cx="${width * 0.18}" cy="${height * 0.84}" r="${width * 0.3}" fill="#000" opacity="0.16"/>
  <text x="50%" y="52%" text-anchor="middle" dominant-baseline="middle"
        font-family="system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
        font-size="${fontSize}" font-weight="800" fill="#ffffff" opacity="0.94">${escapeXml(label)}</text>
</svg>
`;
}

function write(relativePath, contents) {
  const target = resolve(root, relativePath);
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, contents, "utf8");
}

for (const [slug, label, from, to] of tiles) {
  write(`public/images/products/${slug}.svg`, tile(slug, label, from, to));
}

write("public/images/og.svg", tile("og", "Delicat Store Haiti", "#6d4aff", "#e7379c", 1200, 630));

console.log(`Wrote ${tiles.length + 1} placeholder images.`);
