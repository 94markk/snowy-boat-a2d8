/* Node DOM-contract regressions for the PRO40 navigation fixes. */
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = process.env.DBV9_TEST_ROOT || path.resolve(__dirname, '..');
const nav = fs.readFileSync(path.join(root, 'pro/assets/dbp-nav.js'), 'utf8');
const slice = (from, to) => nav.slice(nav.indexOf(from), nav.indexOf(to));
const tick = () => new Promise(r => setImmediate(r));

/*
 * 1. Inline payloads must follow the page, not the first page.
 *
 * loadedInline used to store `true` per script id and was never invalidated, so
 * every later soft navigation skipped the destination's -js-extra / -js-before
 * blocks -- the wp_localize_script payloads carrying nonces, per-page config,
 * product ids and currency -- and ran on the FIRST page's data for the whole
 * session. It now stores the content and skips only a byte-identical block.
 */
async function inlinePayloadFollowsThePage() {
  const ctx = {
    Promise, console,
    loadedInline: { 'x-js-extra': 'var CFG={page:1};' },
    loadedScripts: {}, scriptIds: {}, navToken: 1,
    ran: [],
    indexExistingAssets() {},
    normalise: s => s,
    injectScript() { return Promise.resolve(); },
    runInline(block) { ctx.ran.push(block.code); ctx.loadedInline[block.id] = block.code; return Promise.resolve(); },
  };
  vm.createContext(ctx);
  vm.runInContext(slice('  function loadScripts(', '  function runInline('), ctx);

  await ctx.loadScripts([], [{ id: 'x-js-extra', code: 'var CFG={page:1};' }], null, 1);
  assert.deepEqual(ctx.ran, [], 'an identical inline block must not re-run');

  await ctx.loadScripts([], [{ id: 'x-js-extra', code: 'var CFG={page:2};' }], null, 1);
  assert.deepEqual(ctx.ran, ['var CFG={page:2};'], 'changed inline data MUST run on the new page');

  await ctx.loadScripts([], [{ id: 'x-js-extra', code: 'var CFG={page:2};' }], null, 1);
  assert.equal(ctx.ran.length, 1, 'and must not run twice once it matches');
}

/*
 * 2. Leaving as a real document must hand scroll restoration back to the browser.
 *
 * applySwap() sets history.scrollRestoration='manual' on the first soft swap and
 * nothing set it back, but product links always hard-navigate. Scroll a listing,
 * open a product, press Back -- the browser was forbidden from restoring and the
 * engine was no longer in the document to do it, so the shopper landed at the top.
 */
function hardNavigateReleasesScrollRestoration() {
  const ctx = {
    console,
    scrollPositions: {},
    history: { scrollRestoration: 'manual' },
    window: { pageYOffset: 2400, location: { href: '', replace(h) { ctx.window.location.href = h; } } },
    key: () => '/boutique/',
    parseUrl: h => ({ href: h }),
  };
  ctx.location = ctx.window.location;
  vm.createContext(ctx);
  vm.runInContext(slice('  function hardNavigate(', '  function navigate('), ctx);

  ctx.hardNavigate({ href: 'https://example.test/produit/x/' }, false);
  assert.equal(ctx.history.scrollRestoration, 'auto', 'browser must own scroll restoration across a real document navigation');
  assert.equal(ctx.scrollPositions['/boutique/'], 2400, 'listing position still recorded for an engine-handled Back');
  assert.equal(ctx.window.location.href, 'https://example.test/produit/x/');
}

/*
 * 3. An already-loaded module must not abort the soft swap.
 *
 * documentPayload() threw for ANY type="module" tag before checking whether that
 * module was already running. WordPress 6.5+ emits module scripts on nearly every
 * page via the Interactivity API, so one tag in the destination aborted EVERY soft
 * navigation and the fallback re-downloaded the whole document.
 *
 * documentPayload() needs far more DOM than this harness fakes, so this asserts
 * the guard structurally: the loadedScripts check must exist and must come before
 * the throw.
 */
function knownModuleDoesNotAbortSoftNav() {
  const branch = nav.slice(nav.indexOf("if (type === 'module')"), nav.indexOf("if (type && !/^(text\\/javascript"));
  assert.ok(branch.includes('loadedScripts['), 'module branch must consult loadedScripts');
  assert.ok(
    branch.indexOf('loadedScripts[') < branch.indexOf("throw new Error('module-lifecycle')"),
    'the already-loaded check must run BEFORE the abort'
  );
  assert.ok(branch.includes('continue'), 'a known module must be skipped, not thrown on');
}

(async () => {
  await inlinePayloadFollowsThePage();
  hardNavigateReleasesScrollRestoration();
  knownModuleDoesNotAbortSoftNav();
  console.log('PASS: inline payloads follow the page, hard navigation releases scroll restoration, a known module no longer aborts the soft swap.');
})().catch(e => { console.error('FAIL:', e.message); process.exit(1); });
