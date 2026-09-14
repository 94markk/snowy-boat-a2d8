/* The gate decides, in the browser, which speculation rules get registered.
   Three outcomes must hold: fast keeps both, slow keeps prefetch only, and a
   corrupt rules blob registers nothing rather than something wrong. */
const { chromium } = require('playwright');

const RULES = JSON.stringify({
  prerender: [{ source: 'document', where: { and: [{ href_matches: '/produit/*' }] }, eagerness: 'moderate' }],
  prefetch:  [{ source: 'document', where: { and: [{ href_matches: '/produit/*' }] }, eagerness: 'moderate' }]
});

const GATE = '(function(){'
  + 'var s=document.getElementById("dbv9-speculation");if(!s)return;'
  + 'var rules=s.textContent;'
  + 'if(document.documentElement.className.indexOf("delicat-slow-net")>-1){'
  + 'try{var parsed=JSON.parse(rules);delete parsed.prerender;'
  + 'if(!parsed.prefetch)return;rules=JSON.stringify(parsed);}'
  + 'catch(e){return;}}'
  + 'var n=document.createElement("script");n.type="speculationrules";'
  + 'n.textContent=rules;'
  + 's.parentNode.replaceChild(n,s);'
  + '}());';

const page = (cls, rules) =>
  '<html class="' + cls + '"><body>'
  + '<script type="delicat/speculationrules" id="dbv9-speculation">' + rules + '</script>'
  + '<script>' + GATE + '</script></body></html>';

let pass = 0, fail = 0;
const ok = (w, c, d = '') => { if (c) { pass++; console.log('  ok    ' + w); } else { fail++; console.log('  FAIL  ' + w + (d ? '  -- ' + d : '')); } };

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  const read = async (cls, rules) => {
    const p = await b.newPage();
    await p.setContent(page(cls, rules));
    const r = await p.evaluate(() => {
      const s = document.querySelector('script[type="speculationrules"]');
      if (!s) return { registered: false };
      let parsed = null;
      try { parsed = JSON.parse(s.textContent); } catch (e) { return { registered: true, invalid: true }; }
      return { registered: true, prerender: !!parsed.prerender, prefetch: !!parsed.prefetch,
        eagerness: (parsed.prefetch && parsed.prefetch[0].eagerness) || '' };
    });
    await p.close();
    return r;
  };

  const fast = await read('', RULES);
  ok('a fast connection registers the rules', fast.registered);
  ok('  ...including prerender', fast.prerender === true);
  ok('  ...and prefetch', fast.prefetch === true);

  const slow = await read('delicat-slow-net delicat-low-power', RULES);
  ok('a slow connection still registers rules', slow.registered,
    'before pro.17 it registered nothing at all, so every tap was a cold fetch');
  ok('  ...with prerender dropped', slow.prerender === false,
    'running a whole second page on a narrow pipe is the part that costs');
  ok('  ...but prefetch kept', slow.prefetch === true,
    'one document, for the page they are already touching');
  ok('  ...at moderate eagerness, so it only fires on real intent', slow.eagerness === 'moderate');

  const broken = await read('delicat-slow-net', '{not json at all');
  ok('a corrupt rules blob registers nothing rather than something wrong', broken.registered === false);

  const noPrefetch = await read('delicat-slow-net', JSON.stringify({ prerender: [{ source: 'document' }] }));
  ok('rules with nothing left after the downgrade register nothing', noPrefetch.registered === false);

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  await b.close();
  process.exit(fail ? 1 : 0);
})();
