// node banner-979.cjs <base> — STAGING ONLY. Delicat Builder 9.7.9 banner rail (« Avec Délicat vous pouvez ! »).
// Run `php banner-setup.php <site-dir>` first. Checks, at phone size in light and dark mode:
//  1. each style renders its class, and a layout saved with the retired 'tint' style shows as Nuit (dark);
//  2. Android and WhatsApp cards are recognised (kind class + glyph), "none" hides the icon, a named icon shows;
//  3. a button left as "button" reads « Rejoindre » on the WhatsApp card; off-site links open in a new tab;
//  4. every button's text reads on its background (contrast >= 4.5:1), including a light custom colour;
//  5. Nuit card text stays white in dark mode (the engine's dark-mode pass must not darken it);
//  6. swiping moves the active dot, and the page never scrolls sideways;
//  7. a card without a button label is tappable over its whole area (picture, band, padding).
const { chromium } = require('playwright');
const base = process.argv[2] || 'https://localhost:8943';
if (!/localhost|127\.0\.0\.1/.test(base)) { console.error('refusing: not a staging address'); process.exit(3); }

const lum = (rgb) => {
  const c = rgb.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

const results = [];
const check = (name, ok, detail = '') => { results.push(!!ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`); };

(async () => {
  const browser = await chromium.launch();
  for (const scheme of ['light', 'dark']) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, colorScheme: scheme, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    const read = () => page.evaluate(() => {
      const rail = document.querySelector('.delicat-banner-rail');
      return {
        rail: rail ? rail.className : '',
        sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
        cards: [...document.querySelectorAll('.delicat-banner-card')].map((c) => {
          const btn = c.querySelector('.delicat-banner-card__button');
          const title = c.querySelector('.delicat-banner-card__title');
          return {
            cls: c.className,
            glyph: !!c.querySelector('.delicat-banner-card__icon svg'),
            title: title ? title.textContent.trim() : '',
            titleColor: title ? getComputedStyle(title).color : '',
            btn: btn ? { text: btn.textContent.trim(), bg: getComputedStyle(btn).backgroundColor, fg: getComputedStyle(btn).color, target: btn.getAttribute('target') || '', rel: btn.getAttribute('rel') || '' } : null,
          };
        }),
      };
    });

    // 1. styles
    for (const [slug, want] of [['banner-nuit', 'dark'], ['banner-epure', 'light'], ['banner-vitrine', 'vitrine'], ['banner-tint', 'dark']]) {
      await page.goto(`${base}/${slug}/`, { waitUntil: 'load' });
      const r = await read();
      check(`${scheme} ${slug} renders --${want}`, r.rail.includes(`delicat-banner-rail--${want}`), r.rail);
    }

    for (const style of ['dark', 'light', 'vitrine']) {
      await page.goto(`${base}/banner-extra-${style}/`, { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      await page.waitForTimeout(scheme === 'dark' ? 700 : 100); // the dark-mode pass runs after load
      const r = await read();
      const [android, whatsapp, gift, yellow, photo, link] = r.cards;
      const tag = `${scheme} ${style}`;
      // 2. kinds and icons
      check(`${tag} Android card recognised`, android.cls.includes('delicat-banner-card--android') && android.glyph);
      check(`${tag} WhatsApp card recognised`, whatsapp.cls.includes('delicat-banner-card--whatsapp') && whatsapp.glyph);
      check(`${tag} named icon shows, "none" hides it`, gift.glyph && !photo.glyph && photo.cls.includes('has-media'));
      check(`${tag} card without a button label is one link`, !link.btn);
      const hit = await page.evaluate(() => {
        const c = document.querySelectorAll('.delicat-banner-card')[5];
        c.parentElement.scrollLeft = c.offsetLeft - 16;
        c.scrollIntoView({ block: 'center' });
        const b = c.getBoundingClientRect();
        const at = document.elementFromPoint(b.left + 8, b.top + 8); // the padding, or the Vitrine band
        return !!(at && at.closest('a.delicat-banner-card__link'));
      });
      check(`${tag} the whole link card is tappable (top corner)`, hit);
      // 3. labels and links
      check(`${tag} "button" label reads Rejoindre`, whatsapp.btn && whatsapp.btn.text === 'Rejoindre', whatsapp.btn && whatsapp.btn.text);
      check(`${tag} WhatsApp opens in a new tab`, whatsapp.btn && whatsapp.btn.target === '_blank' && /noopener/.test(whatsapp.btn.rel));
      check(`${tag} store link stays in the app`, android.btn && android.btn.target === '');
      // 4. button contrast
      const low = r.cards.filter((c) => c.btn).map((c) => [c.title, ratio(c.btn.bg, c.btn.fg)]).filter(([, x]) => x < 4.5);
      check(`${tag} button text contrast >= 4.5`, !low.length, low.map(([t, x]) => `${t} ${x.toFixed(2)}`).join(', '));
      check(`${tag} light custom colour gets dark button text`, yellow.cls.includes('has-light-color') && yellow.btn && (style === 'dark' || lum(yellow.btn.fg) < 0.05));
      // 5. Nuit text stays white
      if (style === 'dark') {
        const dark = r.cards.filter((c) => c.titleColor && c.titleColor.replace(/\s/g, '') !== 'rgb(255,255,255)').map((c) => c.title);
        check(`${tag} Nuit titles stay white`, !dark.length, dark.join(', '));
      }
      // 6. dots and width
      check(`${tag} no sideways page scroll`, !r.sideways);
      const dot = await page.evaluate(async () => {
        const t = document.querySelector('.delicat-banner-rail__track');
        t.scrollTo({ left: t.children[2].offsetLeft - parseFloat(getComputedStyle(t).paddingLeft), behavior: 'instant' });
        await new Promise((ok) => setTimeout(ok, 250));
        return [...document.querySelectorAll('.delicat-banner-rail__dots i')].findIndex((i) => i.classList.contains('is-active'));
      });
      check(`${tag} swipe moves the active dot`, dot === 2, `active ${dot}`);
    }
    await ctx.close();
  }
  await browser.close();
  const passed = results.filter(Boolean).length;
  console.log(`\n${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})();
