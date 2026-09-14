/* The scroll position a visitor comes back to.
   ---------------------------------------------------------------------------
   When someone presses Back, shell-nav puts them where they were. The page is
   often still growing at that moment - lazy images, web fonts - so the restore
   retries for about three quarters of a second.

   That retry window is the whole risk. A visitor who has already started
   reading must not be thrown somewhere else by it, and the guard that used to
   protect them - wheel and touchmove listeners - cannot see a scrollbar drag, a
   keyboard, or a finger that started scrolling before this script was
   listening. The last of those is the ordinary case on a slow connection.

   These cases use REAL back/forward navigation, because the restore only acts
   on a navigation Chromium reports as 'back_forward'.

   Run: NODE_PATH=/path/to/node_modules node tests/browser/shell-nav-test.cjs
*/
const { chromium } = require('playwright');
const fs = require('fs');

const JS = fs.readFileSync('/home/user/snowy-boat-a2d8/delicat-builder-v9/assets/js/shell-nav.js', 'utf8');

const HEAD = `<!doctype html><html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>*{box-sizing:border-box}body{margin:0;font-family:system-ui,Arial,sans-serif}
.row{padding:14px;border-bottom:1px solid #eee}.ph{height:180px;background:#eef;border-radius:10px}</style>
</head><body><main data-delicat-server-render><div id="top"></div></main><script>
var main=document.getElementById('top');
function add(n){var h='';for(var i=0;i<n;i++)h+='<section class="row"><h2>Bloc</h2><div class="ph"></div></section>';main.insertAdjacentHTML('beforeend',h);}
`;
const FOOT = `window.DelicatShellNavConfig={};</script><script>__JS__</script></body></html>`;

/* Tall from the first paint: the restore reaches its target immediately. */
const TALL = HEAD + 'add(40);' + FOOT;

/* Short at first, then growing the way a page does when lazy images and fonts
   land on a slow connection. This is the only state in which the restore keeps
   retrying, so it is the only state in which it can fight the visitor. */
const GROWING = HEAD + `add(3);
window.addEventListener('load', function(){
  setTimeout(function(){ add(10); }, 150);
  setTimeout(function(){ add(15); }, 330);
  setTimeout(function(){ add(20); }, 520);
});
` + FOOT;

/* Tall enough at first paint that a restore lands PART of the way - the state
   in which a visitor scrolling up to the top is distinguishable from a page
   that simply has not grown yet. */
const PARTIAL = HEAD + `add(7);
window.addEventListener('load', function(){
  setTimeout(function(){ add(12); }, 200);
  setTimeout(function(){ add(20); }, 420);
});
` + FOOT;

let pass = 0, fail = 0;
const ok = (w, c, d = '') => { c ? (pass++, console.log('  ok    ' + w)) : (fail++, console.log('  FAIL  ' + w + (d ? '  -- ' + d : ''))); };
const group = (n) => console.log('\n' + n + '\n' + '-'.repeat(n.length));

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  /**
   * Scroll down, leave, come back, and watch where the page ends up.
   * `moveTo` (optional) is where the visitor sends themselves mid-restore, by a
   * means that fires neither wheel nor touchmove.
   */
  async function backTo(body, { moveTo = null, moveAt = 160 } = {}) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
    const p = await ctx.newPage();
    await p.route('https://shop.test/**', (r) =>
      r.fulfill({ contentType: 'text/html', body: body.replace('__JS__', () => JS) }));

    await p.goto('https://shop.test/a/');
    await p.waitForTimeout(900);            // let a growing page finish growing
    await p.evaluate(() => window.scrollTo(0, 1400));
    await p.waitForTimeout(200);
    await p.goto('https://shop.test/b/');
    await p.waitForTimeout(200);

    const trail = [];
    await p.goBack({ waitUntil: 'commit' });
    const t0 = Date.now();
    let moved = false;
    while (Date.now() - t0 < 1200) {
      try { trail.push(await p.evaluate(() => Math.round(window.pageYOffset))); } catch (e) { /* mid-navigation */ }
      if (!moved && moveTo !== null && Date.now() - t0 >= moveAt) {
        try { await p.evaluate((y) => window.scrollTo(0, y), moveTo); moved = true; } catch (e) {}
      }
      await p.waitForTimeout(40);
    }
    await ctx.close();
    return { final: trail[trail.length - 1], trail: trail.join(' ') };
  }

  group('Coming back to where you were');

  {
    const r = await backTo(TALL);
    ok('a page that is already tall restores straight away', r.final === 1400, r.trail);
  }
  {
    const r = await backTo(GROWING);
    ok('and one still growing is restored once it can be', r.final === 1400, r.trail);
  }

  group('Unless the visitor has taken over');

  {
    /* The case that used to throw someone 1200px up the page while they read. */
    const r = await backTo(GROWING, { moveTo: 200, moveAt: 160 });
    ok('a visitor who scrolls while the page is still growing keeps their place',
      r.final === 200, r.trail);
  }
  {
    const r = await backTo(TALL, { moveTo: 300, moveAt: 250 });
    ok('and so does one on a page that had already settled', r.final === 300, r.trail);
  }
  {
    /*
     * Scrolling UP to the top, from the partial position a restore reached on a
     * page that had not finished growing.
     *
     * The limit worth stating: a visitor who is at the very top and stays there
     * cannot be told apart from an arrival nobody has touched - no event fires
     * and no position changes - and restoring that visitor is the feature
     * working, not failing. Only a move away from where the restore left the
     * page is detectable, which is what this covers.
     */
    const r = await backTo(PARTIAL, { moveTo: 0, moveAt: 230 });
    ok('scrolling up to the top from a partial restore counts as taking over',
      r.final === 0, r.trail);
  }

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  await b.close();
  process.exit(fail ? 1 : 0);
})();
