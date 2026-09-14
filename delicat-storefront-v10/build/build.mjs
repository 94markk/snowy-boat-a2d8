/* =============================================================================
   build/build.mjs - the only thing that writes dist/.
   -----------------------------------------------------------------------------
   Zero dependencies, by design: a merchant cloning this repository can build it
   with the Node they already have, and there is no lockfile to drift.

   What it does, in order:
     1. concatenates the sources in a stated order
     2. asserts the invariants that V10's architecture depends on
     3. strips comments and collapses whitespace - and nothing else
     4. names each output after the hash of its own contents
     5. writes dist/manifest.json, which is the only map PHP reads

   -----------------------------------------------------------------------------
   Why the minification is deliberately weak
   -----------------------------------------------------------------------------
   V9's combined stylesheet had been through a minifier that removed the space
   in `body :where(...)`, turning "anything inside the page matching this" into
   "the page element itself, if it carries this class" - which matches nothing.
   Twenty-five rules were affected and they were most of the dark theme. Nobody
   noticed for months, because a CSS rule that matches nothing raises no error.

   Comment-stripping and whitespace-collapsing cannot change what a selector
   means. Everything beyond that can, and the saving it buys after Brotli is a
   fraction of a percent. So this build does not do it, and it asserts that the
   things a smarter minifier would have broken are still intact.
   ============================================================================= */

import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, mkdirSync, readdirSync, rmSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const DIST = join(ROOT, 'dist');

/* The order is the cascade-layer order, stated once, here. Because every rule
   is inside a layer this order is documentation rather than behaviour - but a
   reader should not have to work that out for themselves. */
const CSS_SOURCES = [
  'assets/css/00-layers.css',
  'assets/css/01-reset.css',
  'assets/css/02-base.css',
  'assets/css/03-app.css',
  'assets/css/04-components.css',
  'assets/css/05-nav.css',
  'assets/css/06-storefront.css',
];

const JS_SOURCES = ['assets/js/nav.js', 'assets/js/ui.js'];

const read = (rel) => readFileSync(join(ROOT, rel), 'utf8');

/* -------------------------------------------------------------------------- */

/**
 * Remove comments, and nothing else.
 *
 * Deliberately a single-pass scanner rather than a regular expression. The
 * obvious regex version - hide the strings, strip the comments, put the strings
 * back - is wrong in a way that is easy to miss and expensive to find: a
 * comment containing a quotation mark makes the string-hiding step swallow text
 * across the comment terminator, the terminator vanishes, and the comment strip
 * then runs on to the next one, deleting whatever real CSS lay between.
 *
 * It removed 60% of this stylesheet the first time it ran, including the single
 * rule in V10 that carries !important. The build's own invariants caught it
 * before anything was written. A scanner cannot make that mistake, because it
 * never looks at a character without knowing what context it is in.
 */
function stripCssComments(css) {
  let out = '';
  let i = 0;
  const n = css.length;

  while (i < n) {
    const c = css[i];

    /* A string runs to its matching quote; a backslash escapes the next
       character. Nothing inside it is CSS syntax. */
    if (c === '"' || c === "'") {
      const quote = c;
      let j = i + 1;
      while (j < n && css[j] !== quote) j += css[j] === '\\' ? 2 : 1;
      out += css.slice(i, Math.min(j + 1, n));
      i = j + 1;
      continue;
    }

    /* An unquoted url() is a token of its own and may contain anything except
       an unescaped closing paren. */
    if (c === 'u' && css.startsWith('url(', i)) {
      const end = css.indexOf(')', i);
      if (end !== -1) {
        out += css.slice(i, end + 1);
        i = end + 1;
        continue;
      }
    }

    if (c === '/' && css[i + 1] === '*') {
      const end = css.indexOf('*/', i + 2);
      /* An unterminated comment is a syntax error in the source, not licence
         to delete the rest of the file. */
      if (end === -1) {
        throw new Error('unterminated comment at offset ' + i);
      }
      /* Replaced by a space, so that two rules separated only by a comment do
         not become one token. */
      out += ' ';
      i = end + 2;
      continue;
    }

    out += c;
    i++;
  }

  return out;
}

function collapse(css) {
  /* Runs of whitespace become one space, and a space is dropped only where it
     borders a brace, a semicolon or a comma - none of which can be part of a
     selector's meaning. That is the entire transformation. */
  return css
    .replace(/\s+/g, ' ')
    .replace(/ ?([{};,]) ?/g, '$1')
    .trim();
}

/* -------------------------------------------------------------------------- */

const checks = [];
const failures = [];

function check(name, ok, detail) {
  checks.push({ name, ok, detail: detail || '' });
  if (!ok) failures.push(name + (detail ? ': ' + detail : ''));
}

function assertCss(source) {
  /*
   * The invariants are checked against a copy with the space after every colon
   * removed. `collapse` deliberately does not remove it - a space after a colon
   * is never meaning-bearing, but leaving it costs nothing and every removal is
   * a chance to be wrong - so without this normalisation each check would have
   * to spell ` ?` at every colon, and the first time one was forgotten the check
   * would silently pass on everything.
   *
   * That is not hypothetical: two of the checks below were written without it
   * and reported "ok" against stylesheets that violated them, including the
   * one written specifically to catch a bug that was in the file at the time.
   */
  const css = source.replace(/:\s+/g, ':');


  /* 1. The layer order must be the first thing the browser sees. A @layer
        statement that arrives after a rule has been parsed establishes the
        layers in the wrong order, silently. */
  check(
    'layer order is declared first',
    /^@layer reset,base,layout,components,state;/.test(css)
  );

  /* 2. clamp() and calc() need whitespace around + and -. Without it the
        declaration is invalid and is dropped with no error raised. */
  const fns = css.match(/(?:clamp|calc)\([^()]*(?:\([^()]*\)[^()]*)*\)/g) || [];
  const broken = fns.filter((fn) => /[\d%)a-z]\s*[+\-]\s*[\d.(]/.test(fn) && !/ [+\-] /.test(fn));
  check('calc/clamp keep the spaces their grammar requires', broken.length === 0, broken.slice(0, 3).join(' | '));

  /* 3. A descendant combinator in front of :where() or :is() must survive.
        This is the exact transformation that disabled V9's dark theme. */
  const glued = css.match(/[a-z0-9\])]:where\(/gi) || [];
  const suspicious = glued.filter((g) => /^(?:body|html):where\(/i.test(g));
  check('no element:where() produced by collapsing', suspicious.length === 0, suspicious.join(' '));

  /* 4. A view-transition-name must be unique per document, or the browser
        abandons the transition for the whole page. */
  for (const name of ['dlx-header', 'dlx-tabbar', 'dlx-content', 'dlx-dock']) {
    const found = (css.match(new RegExp('view-transition-name:' + name + '(?![\\w-])', 'g')) || []).length;
    check('view-transition-name ' + name + ' declared once', found === 1, 'found ' + found);
  }

  /* 5. Layers make !important unnecessary. [hidden] is the one exception, and
        it is an exception because a theme's own display rule lands in the
        anonymous layer, which the spec places after every named layer. */
  const importants = (css.match(/!important/g) || []).length;
  check('!important used once, for [hidden]', importants === 1, importants + ' occurrences');

  /* 6. Every colour comes from the token layer. A hex literal inside a
        component is a colour that will be wrong in dark mode. */
  const hexes = css.match(/#[0-9a-f]{3}(?:[0-9a-f]{3})?(?![\w-])/gi) || [];
  check('no colour literals outside the token layer', hexes.length === 0, hexes.slice(0, 6).join(' '));

  /* 7. The band is derived. It is written in the two body rules that state what
        chrome exists plus the desktop collapse; anything more means someone has
        started typing totals again. */
  const bandWrites = (css.match(/--dlx-band:/g) || []).length;
  check('the band is written in one place only', bandWrites <= 4, bandWrites + ' declarations');

  /* 8. Fixed chrome may not carry vertical margin the band does not know about.

        Twice while building V10 a purely cosmetic offset was added to a fixed
        element - 12px floating the tab bar on tablet, then 8px lifting the dock
        off it - and each time the derived band came up short by exactly that
        amount, so content ended up underneath. The band is a total; anything
        adding to the total has to be part of it. --dlx-tabbar-float is, which
        is why it is the one permitted value.

        The check has to be in two passes. A first attempt looked only inside
        rules that say position:fixed, and missed the very bug it was written
        for: the offending margin was in a media-query override of .dlx-dock,
        and that rule says nothing about position. So: collect the class names
        that are ever positioned fixed, then audit every rule mentioning one of
        them, wherever it lives. */
  const rules = css.match(/[^{}]*\{[^{}]*\}/g) || [];

  const fixedClasses = new Set();
  for (const rule of rules) {
    if (!/position:fixed/.test(rule)) continue;
    const selector = rule.slice(0, rule.indexOf('{'));
    for (const cls of selector.match(/\.[a-zA-Z][\w-]*/g) || []) fixedClasses.add(cls);
  }

  const strayMargin = [];
  for (const rule of rules) {
    const selector = rule.slice(0, rule.indexOf('{'));
    const classes = selector.match(/\.[a-zA-Z][\w-]*/g) || [];
    if (!classes.some((c) => fixedClasses.has(c))) continue;

    const body = rule.slice(rule.indexOf('{') + 1);
    for (const m of body.matchAll(/margin(?:-bottom|-top|-block|-block-end|-block-start)?:([^;}]+)/g)) {
      const value = m[1].trim();
      if (/var\(--dlx-tabbar-float\)/.test(value)) continue;
      /* A margin shorthand's vertical components are its first and third (or
         first, for a single value). `margin-inline` is horizontal and fine. */
      const vertical = m[0].startsWith('margin:') ? value.split(/\s+/)[0] : value;
      if (!/^0/.test(vertical)) strayMargin.push(selector.trim() + ' { ' + m[0] + ' }');
    }
  }

  check(
    'fixed chrome adds no vertical offset outside the band',
    strayMargin.length === 0,
    strayMargin.slice(0, 3).join(' | ')
  );

  /* 9. Breakpoints come from the token layer's three tiers. A fourth number in
        a media query is a tier nobody has designed. */
  const widths = [...new Set((css.match(/\(min-width: ?(\d+)px\)/g) || []).map((m) => m.replace(/\D/g, '')))];
  const allowed = ['560', '720', '1024'];
  const stray = widths.filter((w) => !allowed.includes(w));
  check('breakpoints are the three designed tiers', stray.length === 0, stray.join(' '));
}

function assertJs(js) {
  check('no eval', !/\beval\s*\(/.test(js));
  check('no document.write', !/document\.write\b/.test(js));
  /* V10's scripts are enhancements. None of them may be what renders anything,
     so that any of them failing leaves a page a customer can still use. */
  check('scripts do not render the page', !/\.innerHTML\s*=/.test(js));
}

/* -------------------------------------------------------------------------- */

const hash = (content) => createHash('sha256').update(content).digest('hex').slice(0, 12);
const kb = (n) => (n / 1024).toFixed(1) + 'k';

function build() {
  const missing = [...CSS_SOURCES, ...JS_SOURCES].filter((f) => !existsSync(join(ROOT, f)));
  if (missing.length) {
    console.error('missing sources:\n  ' + missing.join('\n  '));
    process.exit(1);
  }

  const rawCss = CSS_SOURCES.map(read).join('\n');
  const css = collapse(stripCssComments(rawCss));
  assertCss(css);

  const js = JS_SOURCES.map(read).join('\n');
  assertJs(js);

  for (const { name, ok, detail } of checks) {
    console.log('  ' + (ok ? 'ok  ' : 'FAIL') + '  ' + name + (!ok && detail ? '  -- ' + detail : ''));
  }

  if (failures.length) {
    console.error('\n' + failures.length + ' invariant(s) violated. Nothing written.');
    process.exit(1);
  }

  mkdirSync(DIST, { recursive: true });
  for (const file of readdirSync(DIST)) rmSync(join(DIST, file), { force: true });

  const manifest = {};

  const cssName = 'app.' + hash(css) + '.css';
  writeFileSync(join(DIST, cssName), css);
  manifest['app.css'] = cssName;

  for (const source of JS_SOURCES) {
    const body = read(source);
    const base = source.split('/').pop();
    const out = base.replace(/\.js$/, '.' + hash(body) + '.js');
    writeFileSync(join(DIST, out), body);
    manifest[base] = out;
  }

  writeFileSync(join(DIST, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');

  console.log('\n  ' + cssName + '  ' + kb(css.length) + '  (from ' + kb(rawCss.length) + ' of commented source)');
  for (const [from, to] of Object.entries(manifest)) {
    if (from !== 'app.css') console.log('  ' + to + '  ' + kb(read('assets/js/' + from).length));
  }
  console.log('  manifest.json  ' + Object.keys(manifest).length + ' entries');
}

build();
