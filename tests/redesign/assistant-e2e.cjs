// Delicat Assistant 7 — end-to-end checks on a staging site (never production).
// node assistant-e2e.cjs <base-url> <site-dir> <assistant-db.php> <out-dir>   (seed first: php assistant-setup.php <site-dir>)
// Users (password ClientPass!2026 / AdminPass!2026): client1 (30 days old), client2 (20 days), client3 (new),
// admin; seeded by asst-setup.php. asst-db.php plays the support agent and reads rows back.
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const [base, site, dbScript, out] = process.argv.slice(2);
const results = [];
const db = (...args) => execFileSync('php', [dbScript, site, ...args], { encoding: 'utf8' }).trim();
const dbj = (...args) => JSON.parse(db(...args));
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); }
  catch (e) { results.push(['FAIL', name, String(e && e.message || e).slice(0, 300)]); console.log('FAIL', name, '—', String(e && e.message || e).slice(0, 300)); }
}
const ok = (cond, msg) => { if (!cond) throw new Error(msg); };
async function context(b, who, opts = {}) {
  const ctx = await b.newContext({ ...(opts.desktop ? { viewport: { width: 1280, height: 860 } } : devices['Pixel 7']), colorScheme: opts.dark ? 'dark' : 'light', ignoreHTTPSErrors: true });
  const p = await ctx.newPage();
  p.errors = [];
  p.on('pageerror', (e) => p.errors.push(String(e)));
  p.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) p.errors.push(m.text()); });
  if (who) {
    await p.goto(base + '/wp-login.php');
    await p.fill('#user_login', who);
    await p.fill('#user_pass', who === 'admin' ? 'AdminPass!2026' : 'ClientPass!2026');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  }
  return { ctx, p };
}
const noAge = (p) => p.evaluate(() => { const d = document.getElementById('dip-age'); if (d) d.remove(); });
async function openChat(p) {
  await p.goto(base + '/', { waitUntil: 'load' });
  await noAge(p);
  await p.click('#dsa7-launcher');
  await p.locator('.app.on').waitFor({ timeout: 8000 });
  await p.waitForTimeout(600);
}
const tab = (p, label) => p.locator('.tab', { hasText: label }).click();
async function api(p, action, data) {
  return p.evaluate(async ([action, data]) => {
    const boot = await (await fetch('/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'dsa7_boot' }) })).json();
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', boot.data.groups.nonce);
    for (const k of Object.keys(data)) fd.append(k, data[k]);
    const r = await fetch('/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: fd });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, [action, data]);
}

(async () => {
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  db('reset-room', 'communaute-delicat'); // each run starts from the seeded room

  /* ------------------------------------------------------------ guest */
  {
    const { ctx, p } = await context(b, null);
    const before = +db('count-conv');
    await step('guest: launcher opens the app (shadow DOM)', async () => { await openChat(p); ok(await p.locator('.hello h3').isVisible(), 'no greeting'); });
    await step('guest: opening the chat creates no conversation row', async () => { await tab(p, 'Assistant'); await p.waitForTimeout(800); ok(+db('count-conv') === before, 'a conversation was created on open'); });
    await step('guest: local welcome in the assistant view', async () => { ok((await p.locator('.log .bub').first().textContent()).includes('assistant Delicat'), 'no welcome'); });
    await p.screenshot({ path: `${out}/guest-bot-empty.png` });
    await step('guest: automated answer to a question', async () => {
      await p.fill('.input', 'Comment recharger Free Fire ?');
      await p.click('.send');
      // Confirmed send: the customer's bubble is no longer "sending" and an answer (card or bubble) follows it.
      await p.waitForFunction(() => {
        const r = document.getElementById('dsa7-app').shadowRoot;
        const mine = r.querySelector('.log .msg.me');
        return mine && !/Envoi/.test(mine.textContent) && mine.nextElementSibling && !mine.nextElementSibling.classList.contains('me');
      }, null, { timeout: 15000 });
      const n = +db('count-conv');
      ok(n === before + 1, `first message should create exactly one conversation (before ${before}, after ${n})`);
    });
    await step('guest: topic chip returns a card', async () => {
      const cards = await p.locator('.log .card').count();
      await p.locator('.chips button', { hasText: 'Suivre ma commande' }).click();
      await p.waitForFunction((n) => document.getElementById('dsa7-app').shadowRoot.querySelectorAll('.log .card').length > n, cards, { timeout: 15000 });
    });
    await p.screenshot({ path: `${out}/guest-bot.png` });
    await step('guest: team view asks for contact details, then hands over', async () => {
      await tab(p, 'Équipe');
      await p.fill('.input', 'Bonjour, ma commande n’est pas arrivée');
      await p.click('.send');
      await p.locator('.card[data-type="contact_card"]').waitFor({ timeout: 15000 });
      await p.locator('.card[data-type="contact_card"] input').nth(0).fill('Invité Test');
      await p.locator('.card[data-type="contact_card"] input').nth(1).fill('invite@example.test');
      await p.locator('.card[data-type="contact_card"] .btn.pri').click();
      await p.locator('.card[data-type="agent_card"]').waitFor({ timeout: 15000 });
    });
    await p.screenshot({ path: `${out}/guest-team.png` });
    await step('guest: no page errors', async () => ok(!p.errors.length, p.errors.join(' | ')));
    await ctx.close();
  }

  /* ------------------------------------------------------------ client1: support chat */
  {
    // The launcher caches its count for 60 s across pages, so the reply is written before the visit starts.
    db('agent-reply', 'client1', 'Message test de l’équipe');
    const { ctx, p } = await context(b, 'client1');
    await step('client1: launcher badge shows the unread team message', async () => {
      await p.goto(base + '/', { waitUntil: 'load' });
      await noAge(p);
      await p.locator('#dsa7-launcher .dsa7-badge:not([hidden])').waitFor({ timeout: 8000 });
    });
    await step('client1: team tab shows history and clears the badge', async () => {
      await p.click('#dsa7-launcher');
      await p.locator('.app.on').waitFor();
      await p.locator('.tab .n').first().waitFor({ timeout: 8000 });
      await tab(p, 'Équipe');
      await p.locator('.log .bub', { hasText: 'Message test de l’équipe' }).last().waitFor({ timeout: 8000 });
      await p.waitForTimeout(1500);
      ok(await p.locator('.tab .n').count() === 0, 'tab badge not cleared');
    });
    await step('client1: send to the team (delivery mark)', async () => {
      await p.fill('.input', 'Merci beaucoup !');
      await p.click('.send');
      await p.locator('.msg.me .bub', { hasText: 'Merci beaucoup' }).last().waitFor({ timeout: 10000 });
      await p.locator('.msg.me:has-text("Merci beaucoup") .meta svg').last().waitFor({ timeout: 10000 });
    });
    await step('client1: live typing indicator and new team reply', async () => {
      db('agent-typing', 'client1');
      await p.locator('.bub.typing').waitFor({ timeout: 12000 });
      db('agent-reply', 'client1', 'Réponse en direct de l’équipe');
      await p.locator('.log .bub', { hasText: 'Réponse en direct' }).last().waitFor({ timeout: 12000 });
    });
    await p.screenshot({ path: `${out}/client-team.png` });

    /* ---------------------------------------------------------- community */
    await step('client1: community lists public rooms only (not the hidden VIP room)', async () => {
      await tab(p, 'Communauté');
      await p.locator('.room', { hasText: 'Communauté Delicat' }).waitFor({ timeout: 10000 });
      ok(await p.locator('.room', { hasText: 'VIP' }).count() === 0, 'hidden room listed');
    });
    await p.screenshot({ path: `${out}/client-rooms.png` });
    await step('client1: room opens with history', async () => {
      await p.locator('.room', { hasText: 'Communauté Delicat' }).click();
      await p.locator('.gm', { hasText: 'PUBG' }).first().waitFor({ timeout: 10000 });
    });
    await step('client1: payment number is masked before it reaches the room', async () => {
      await p.fill('.input', 'Mon MonCash c’est 3712 3456');
      await p.click('.send');
      await p.locator('.gm .bub', { hasText: '[numéro masqué]' }).last().waitFor({ timeout: 10000 });
      ok(!(await p.locator('.gm .bub', { hasText: '3712' }).count()), 'number visible');
    });
    await step('client1: ask the team from the room', async () => {
      await p.locator('.modes button', { hasText: 'Question à l’équipe' }).click();
      await p.fill('.input', 'Comment payer avec Natcash pour Free Fire ?');
      await p.click('.send');
      await p.locator('.gm.q', { hasText: 'Natcash' }).last().locator('.qtag', { hasText: 'En attente' }).waitFor({ timeout: 10000 });
    });
    await p.screenshot({ path: `${out}/client-room.png` });
    await step('security: hidden room unreadable by a non-member (open, poll, report)', async () => {
      const vip = dbj('group', 'vip-prive');
      const o = await api(p, 'dsa2_groups_open', { group: 'vip-prive' });
      const pl = await api(p, 'dsa2_groups_poll', { group: String(vip.group), after_id: '0' });
      const rp = await api(p, 'dsa2_groups_report', { message_id: String(vip.messages[0].id), reason: 'spam' });
      ok(o.status === 404 && pl.status === 404 && rp.status === 404, `open ${o.status} poll ${pl.status} report ${rp.status}`);
    });
    await step('client1: continue privately moves the question to the support chat', async () => {
      await p.locator('.gm.q', { hasText: 'Natcash' }).last().locator('.gacts button', { hasText: 'Continuer en privé' }).click();
      await p.locator('.sheet .opt', { hasText: 'Continuer en privé' }).click();
      await p.locator('.log .bub', { hasText: 'Question posée dans le groupe' }).last().waitFor({ timeout: 12000 });
      const c = dbj('conv', 'client1');
      ok(c.conv.status === 'waiting', 'status ' + c.conv.status);
      const g = dbj('group', 'communaute-delicat');
      const q = g.messages.find((m) => m.message_type === 'question' && m.body.includes('Natcash')); // newest first
      ok(q && q.flags.includes('private'), 'question not marked private');
    });
    await step('client1: no page errors', async () => ok(!p.errors.length, p.errors.join(' | ')));
    await ctx.close();
  }

  /* ------------------------------------------------------------ client2 asks, staff answers in the room */
  {
    const c2 = await context(b, 'client2');
    await step('client2: asks the team', async () => {
      await openChat(c2.p);
      await tab(c2.p, 'Communauté');
      await c2.p.locator('.room', { hasText: 'Communauté Delicat' }).click();
      await c2.p.locator('.modes button', { hasText: 'Question à l’équipe' }).waitFor({ timeout: 10000 });
      await c2.p.locator('.modes button', { hasText: 'Question à l’équipe' }).click();
      await c2.p.fill('.input', 'Le code PUBG met combien de temps ?');
      await c2.p.click('.send');
      await c2.p.locator('.gm.q', { hasText: 'PUBG met combien' }).last().waitFor({ timeout: 10000 });
    });
    // Staff = the "Agent support Delicat" role (Identity Pro requires 2FA before an administrator can use AJAX).
    const ad = await context(b, 'agent1');
    await step('staff: sees the open question and answers it in the room', async () => {
      await openChat(ad.p);
      await tab(ad.p, 'Communauté');
      await ad.p.locator('.room', { hasText: 'Communauté Delicat' }).locator('.q').waitFor({ timeout: 10000 });
      await ad.p.locator('.room', { hasText: 'Communauté Delicat' }).click();
      const q = ad.p.locator('.gm.q', { hasText: 'PUBG met combien' }).last();
      await q.waitFor({ timeout: 10000 });
      await q.locator('.gacts button[aria-label="Répondre"]').click();
      await ad.p.fill('.input', 'En général moins d’une minute après le paiement.');
      await ad.p.click('.send');
      await ad.p.locator('.gm .quote', { hasText: 'PUBG met combien' }).last().waitFor({ timeout: 10000 });
      const g = dbj('group', 'communaute-delicat');
      const question = g.messages.find((m) => m.body.includes('PUBG met combien'));
      const answer = g.messages.find((m) => m.message_type === 'answer');
      ok(question.flags.includes('answered') && answer, 'question not answered');
    });
    await ad.p.screenshot({ path: `${out}/staff-room.png` });
    await step('client2: sees the team answer with the staff badge and the question marked answered', async () => {
      await c2.p.locator('.gm .who .staff', { hasText: 'Équipe' }).last().waitFor({ timeout: 12000 });
      await c2.p.locator('.gm.q', { hasText: 'PUBG met combien' }).last().locator('.qtag.ok').waitFor({ timeout: 12000 });
    });
    await c2.p.screenshot({ path: `${out}/client2-answered.png` });

    /* ---------------------------------------------------------- report rules */
    await step('reports: new accounts do not hide a message; staff messages are never auto-hidden', async () => {
      db('reset-reports', 'communaute-delicat');
      const g = dbj('group', 'communaute-delicat');
      const target = g.messages.find((m) => m.body.includes('Oui, ça arrive'));     // client1's message
      const staffMsg = g.messages.find((m) => m.body.includes('Bienvenue dans la communauté'));
      const c3 = await context(b, 'client3');
      await c3.p.goto(base + '/');
      // client3 (new account) joins and reports; client2 reports: only one established reporter.
      await api(c3.p, 'dsa2_groups_join', { group: 'communaute-delicat' });
      const r1 = await api(c3.p, 'dsa2_groups_report', { message_id: String(target.id), reason: 'spam' });
      const r2 = await api(c2.p, 'dsa2_groups_report', { message_id: String(target.id), reason: 'spam' });
      ok(r1.json && r1.json.success && r2.json && r2.json.success, 'reports refused');
      const after = dbj('group', 'communaute-delicat').messages.find((m) => +m.id === +target.id);
      ok(after.status === 'visible' && +after.report_count === 2, `status ${after.status} count ${after.report_count}`);
      // Staff message reported by members: stays visible.
      await api(c3.p, 'dsa2_groups_report', { message_id: String(staffMsg.id), reason: 'abuse' });
      await api(c2.p, 'dsa2_groups_report', { message_id: String(staffMsg.id), reason: 'abuse' });
      const s2 = dbj('group', 'communaute-delicat').messages.find((m) => +m.id === +staffMsg.id);
      ok(s2.status === 'visible', 'staff message hidden');
      await c3.ctx.close();
    });
    await step('staff + client2: no page errors', async () => ok(!ad.p.errors.length && !c2.p.errors.length, ad.p.errors.concat(c2.p.errors).join(' | ')));
    await ad.ctx.close();
    await c2.ctx.close();
  }

  /* ------------------------------------------------------------ looks: dark mode, desktop */
  {
    const { ctx, p } = await context(b, 'client1', { dark: true });
    await step('dark mode: home and team views render', async () => {
      await openChat(p);
      await p.screenshot({ path: `${out}/dark-home.png` });
      await tab(p, 'Équipe');
      await p.waitForTimeout(1500);
      await p.screenshot({ path: `${out}/dark-team.png` });
      await tab(p, 'Communauté');
      await p.locator('.room').first().click();
      await p.waitForTimeout(1500);
      await p.screenshot({ path: `${out}/dark-room.png` });
    });
    await ctx.close();
    const d = await context(b, 'client1', { desktop: true });
    await step('desktop: floating panel', async () => {
      await openChat(d.p);
      await d.p.screenshot({ path: `${out}/desktop-home.png` });
      await d.p.keyboard.press('Escape');
      await d.p.waitForTimeout(400);
      ok(!(await d.p.locator('.app.on').count()), 'Escape did not close');
      ok(await d.p.evaluate(() => document.activeElement && document.activeElement.id === 'dsa7-launcher'), 'focus not returned to the launcher');
    });
    await d.ctx.close();
  }

  /* ------------------------------------------------------------ closing gives the page back (7.0.1) */
  {
    // What is at a point of the page: a closed chat must not be there (shadow content reports its host).
    const hitIsChat = (p, x, y) => p.evaluate(([x, y]) => { const el = document.elementFromPoint(x, y); return !!el && !!el.closest('#dsa7-app'); }, [x, y]);
    const { ctx, p } = await context(b, null);
    await step('phone: after closing, taps reach the page, it scrolls and the launcher reopens', async () => {
      await openChat(p);
      await p.locator('#dsa7-app').locator('button[aria-label="Fermer"]').click();
      await p.waitForTimeout(500);
      const vp = p.viewportSize();
      for (const [x, y] of [[vp.width / 2, vp.height / 2], [vp.width / 2, 120], [vp.width - 40, vp.height - 200]]) ok(!(await hitIsChat(p, x, y)), `closed chat still covers ${x},${y}`);
      const y0 = await p.evaluate(() => scrollY);
      await p.mouse.wheel(0, 600);
      await p.waitForTimeout(400);
      ok(await p.evaluate(() => scrollY) > y0, 'page does not scroll after closing');
      await p.click('#dsa7-launcher');
      await p.locator('.app.on').waitFor({ timeout: 4000 });
    });
    await ctx.close();
    const d = await context(b, null, { desktop: true });
    await step('desktop: after closing, the launcher under the old panel reopens the chat', async () => {
      await openChat(d.p);
      await d.p.keyboard.press('Escape');
      await d.p.waitForTimeout(500);
      const r = await d.p.locator('#dsa7-launcher').boundingBox();
      ok(!(await hitIsChat(d.p, r.x + r.width / 2, r.y + r.height / 2)), 'closed panel covers the launcher');
      await d.p.click('#dsa7-launcher');
      await d.p.locator('.app.on').waitFor({ timeout: 4000 });
    });
    await d.ctx.close();
    const pr = await context(b, null);
    await step('product page: the launcher stays above the buy bar', async () => {
      await pr.p.goto(base + '/product/free-fire/', { waitUntil: 'load' });
      await noAge(pr.p);
      await pr.p.mouse.wheel(0, 500);
      await pr.p.waitForFunction(() => document.body.classList.contains('dnp-dock-visible'), null, { timeout: 6000 });
      await pr.p.waitForTimeout(300);
      const l = await pr.p.locator('#dsa7-launcher').boundingBox();
      for (const sel of ['.dnp-mobile-dock', '.delicat-bottom-nav']) {
        const el = pr.p.locator(sel).first();
        if (!(await el.count()) || !(await el.isVisible())) continue;
        const r = await el.boundingBox();
        const overlap = l.x < r.x + r.width && l.x + l.width > r.x && l.y < r.y + r.height && l.y + l.height > r.y;
        ok(!overlap, `launcher overlaps ${sel}`);
      }
      await pr.p.screenshot({ path: `${out}/product-launcher.png` });
    });
    await pr.ctx.close();
  }

  /* ------------------------------------------------------------ shared group links, message list (7.0.1) */
  {
    const app = (p) => p.locator('#dsa7-app');
    const { ctx, p } = await context(b, 'client1');
    await step('shared link: ?dap_group= opens the chat in that room', async () => {
      await p.goto(base + '/?dap_group=communaute-delicat', { waitUntil: 'load' });
      await noAge(p);
      await p.locator('.app.on').waitFor({ timeout: 8000 });
      await app(p).locator('.gm').first().waitFor({ timeout: 8000 });
      ok((await app(p).locator('#dsa7-title').textContent()).includes('Communauté'), 'room title: ' + await app(p).locator('#dsa7-title').textContent());
    });
    await step('share button: the link points at this room', async () => {
      await p.evaluate(() => { try { delete Navigator.prototype.share; } catch (e) {} }); // the copy sheet, as on most desktops
      await app(p).locator('button[aria-label="Partager le groupe"]').click();
      const v = await app(p).locator('.sheet input.field').inputValue();
      ok(/[?&]dap_group=communaute-delicat\b/.test(v), 'link ' + v);
      await p.keyboard.press('Escape');
    });
    await step('room: a new message leaves the earlier messages in place, a run is grouped', async () => {
      await app(p).locator('.gm').first().evaluate((el) => { el.__kept = 1; });
      const input = app(p).locator('.composer textarea');
      for (const text of ['Premier message de la série', 'Deuxième message de la série']) {
        await input.fill(text);
        await app(p).locator('.composer .send').click();
        await app(p).locator('.gm.me:not(.pending)', { hasText: text }).waitFor({ timeout: 8000 });
        await p.waitForTimeout(5600); // slow mode
      }
      ok(await app(p).locator('.gm').first().evaluate((el) => el.__kept === 1), 'the list was rebuilt');
      ok(await app(p).locator('.gm.me.cont', { hasText: 'Deuxième message' }).count() === 1, 'second message of the run is not grouped');
      ok(!p.errors.length, p.errors.join(' | '));
    });
    await ctx.close();
    const g = await context(b, null);
    await step('shared link: a guest signs in and finds the room open', async () => {
      await g.p.goto(base + '/?dap_group=communaute-delicat', { waitUntil: 'load' });
      await noAge(g.p);
      await g.p.locator('.app.on').waitFor({ timeout: 8000 });
      await app(g.p).locator('.empty a.btn').waitFor({ timeout: 8000 }); // sign-in card, no error
      await g.p.goto(base + '/wp-login.php');
      await g.p.fill('#user_login', 'client2');
      await g.p.fill('#user_pass', 'ClientPass!2026');
      await Promise.all([g.p.waitForNavigation(), g.p.click('#wp-submit')]);
      await g.p.waitForLoadState('load'); // the page the login lands on (wallet, account…) opens the room
      await g.p.locator('.app.on').waitFor({ timeout: 8000 });
      await app(g.p).locator('.gm').first().waitFor({ timeout: 8000 });
      await g.p.goto(base + '/', { waitUntil: 'load' }); // only once
      await g.p.waitForTimeout(1500);
      ok(!(await g.p.locator('.app.on').count()), 'the room opened again on the next page');
    });
    await g.ctx.close();
  }

  /* ------------------------------------------------------------ Builder instant navigation */
  {
    const { ctx, p } = await context(b, null);
    await step('instant page switch keeps exactly one launcher and it still opens', async () => {
      await p.goto(base + '/', { waitUntil: 'load' });
      await noAge(p);
      const link = p.locator('a[href*="/shop"]:visible, a[href*="/product/"]:visible').first();
      if (await link.count()) { await link.click(); await p.waitForTimeout(2500); }
      ok(await p.locator('#dsa7-root').count() === 1, 'launcher count ' + await p.locator('#dsa7-root').count());
      await p.click('#dsa7-launcher');
      await p.locator('.app.on').waitFor({ timeout: 8000 });
    });
    await ctx.close();
  }

  await b.close();
  const failed = results.filter((r) => r[0] === 'FAIL');
  console.log(`\n${results.length - failed.length}/${results.length} passed`);
  process.exit(failed.length ? 1 : 0);
})();
