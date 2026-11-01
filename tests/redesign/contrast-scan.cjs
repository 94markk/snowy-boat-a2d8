// node contrast-scan.cjs <base> <path>... — renders pages signed in as client1 (Pixel 7), light and dark, and reports
// visible text whose contrast against its effective background (solid colour or every gradient stop) is below WCAG AA.
const { chromium, devices } = require('playwright');
const [base, ...paths] = process.argv.slice(2);
(async () => {
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  for (const scheme of ['light', 'dark']) {
    const ctx = await b.newContext({ ...devices['Pixel 7'], colorScheme: scheme, ignoreHTTPSErrors: true });
    const p = await ctx.newPage();
    await p.goto(base + '/wp-login.php'); await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    for (const path of paths) {
      await p.goto(base + path, { waitUntil: 'networkidle' });
      await p.evaluate(() => { const d = document.getElementById('dip-age'); if (d) d.remove(); });
      const issues = await p.evaluate(() => {
        const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: v[0], g: v[1], b: v[2], a: v.length > 3 ? v[3] : 1 }; };
        const lum = (c) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
        const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
        const blend = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
        let owner = '';
        const bgsFor = (el) => {
          const layers = []; owner = '';
          for (let e = el; e; e = e.parentElement) {
            const cs = getComputedStyle(e);
            const img = cs.backgroundImage;
            if (img && img.includes('gradient')) { const stops = (img.match(/rgba?\([^)]+\)/g) || []).map(parse).filter(Boolean); if (stops.length) { layers.push({ stops }); if (stops.every((s) => s.a >= 0.99)) break; } }
            const c = parse(cs.backgroundColor);
            if ((c && c.a > 0) || (img && img.includes('gradient'))) owner = owner || (e.tagName.toLowerCase() + '.' + String(e.className && e.className.baseVal === undefined ? e.className : '').trim().split(/\s+/).slice(0, 3).join('.')).slice(0, 70) + ' bg=' + (img && img.includes('gradient') ? img.slice(0, 70) : cs.backgroundColor);
            if (c && c.a > 0) { layers.push({ solid: c }); if (c.a >= 0.99) break; }
          }
          let bases = [{ r: 255, g: 255, b: 255, a: 1 }];
          if (document.documentElement.dataset.delicatTheme === 'dark') bases = [parse(getComputedStyle(document.body).backgroundColor) || { r: 9, g: 15, b: 29, a: 1 }];
          for (let i = layers.length - 1; i >= 0; i--) {
            const L = layers[i];
            if (L.solid) bases = bases.map((u) => blend(L.solid, u));
            else bases = L.stops.flatMap((s) => bases.map((u) => blend(s, u)));
          }
          return bases;
        };
        const out = [];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const seen = new Set();
        while (walker.nextNode()) {
          const t = walker.currentNode; const el = t.parentElement;
          if (!el || seen.has(el) || !t.textContent.trim()) continue; seen.add(el);
          const cs = getComputedStyle(el); const r = el.getBoundingClientRect();
          if (r.width < 2 || r.height < 2 || cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) < 0.1) continue;
          if (el.closest('[hidden],[aria-hidden="true"],dialog:not([open]),.dlx-drawer:not(.is-open),.screen-reader-text,#wpadminbar,noscript,script,style')) continue;
          let hiddenAncestor = false; for (let e = el; e; e = e.parentElement) { const c2 = getComputedStyle(e); if (c2.display === 'none' || c2.visibility === 'hidden' || parseFloat(c2.opacity) < 0.1) { hiddenAncestor = true; break; } } if (hiddenAncestor) continue;
          const fg = parse(cs.color); if (!fg) continue;
          const size = parseFloat(cs.fontSize), bold = parseInt(cs.fontWeight, 10) >= 700;
          const need = (size >= 24 || (bold && size >= 18.66)) ? 3 : 4.5;
          const worst = Math.min(...bgsFor(el).map((bg) => ratio(blend(fg, bg), bg)));
          if (worst < need) out.push({ text: t.textContent.trim().slice(0, 38), cls: (el.className && el.className.baseVal === undefined ? String(el.className) : '').split(' ')[0].slice(0, 34) || el.tagName.toLowerCase(), ratio: Math.round(worst * 100) / 100, need, fg: cs.color, owner });
        }
        return out;
      });
      console.log(`[${scheme}] ${path} — ${issues.length} below AA`);
      for (const i of issues.slice(0, 10)) console.log(`     ${i.ratio}/${i.need}  .${i.cls}  "${i.text}"  fg ${i.fg}  <- ${process.env.OWNER ? i.owner : ''}`);
    }
    await ctx.close();
  }
  await b.close();
})();
