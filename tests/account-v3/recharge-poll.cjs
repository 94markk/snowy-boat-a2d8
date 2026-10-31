// node recharge-poll.cjs <port> — Recharge 6.5.2: balance polling after a submit (instant credit, pending, hidden tab).
const { chromium, devices } = require('playwright');
const [port] = process.argv.slice(2);
const BASE = `http://localhost:${port}`;
async function scenario(b, name, submitData, opts) {
  const ctx = await b.newContext({ ...devices['Pixel 7'] });
  const p = await ctx.newPage();
  await p.goto(BASE + '/wp-login.php'); await p.fill('#user_login', 'client1'); await p.fill('#user_pass', 'ClientPass!2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  let polls = 0, submits = 0, balance = opts.balanceAfter;
  // The Playground test build has no local copy of WordPress's jQuery: serve the system one.
  await p.route(/wp-includes\/js\/jquery\/jquery(-migrate)?\.min\.js/, (route) => /migrate/.test(route.request().url())
    ? route.fulfill({ contentType: 'application/javascript', body: '' })
    : route.fulfill({ contentType: 'application/javascript', body: require('fs').readFileSync('/usr/share/javascript/jquery/jquery.min.js', 'utf8') }));
  await p.route('**/admin-ajax.php', async (route) => {
    const body = route.request().postData() || '';
    if (/delicat_balance/.test(body)) { polls++; return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { balance: balance, balance_fmt: String(balance) } }) }); }
    if (/delicat_submit_recharge/.test(body)) { submits++; return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: submitData }) }); }
    return route.continue();
  });
  await p.goto(BASE + '/recharge-test/');
  const shown = await p.evaluate(() => { const el = document.getElementById('dw12-balance-amount'); return el ? el.getAttribute('data-balance') : null; });
  await p.fill('#dr-montant', '500'); await p.fill('#dr-trans', '1234567890123');
  if (opts.hide) {
    await p.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); });
  }
  await p.click('#dr-submit');
  await p.waitForTimeout(opts.wait || 7000);
  const after = await p.evaluate(() => { const el = document.getElementById('dw12-balance-amount'); return el ? el.getAttribute('data-balance') : null; });
  await ctx.close();
  return { name, shownBefore: shown, submits, polls, balanceAttrAfter: after };
}
(async () => {
  const b = await chromium.launch();
  const out = [];
  out.push(await scenario(b, 'instant credit (balance in response)', { auto_approved: true, message: 'ok', balance: 2950.75, balance_fmt: '2 950,75', currency: 'HTG' }, { balanceAfter: 2950.75 }));
  out.push(await scenario(b, 'pending, credit lands later', { auto_approved: false, message: 'en attente' }, { balanceAfter: 3450.75, wait: 4000 }));
  out.push(await scenario(b, 'pending, tab hidden', { auto_approved: false, message: 'en attente' }, { balanceAfter: 3450.75, hide: true, wait: 7000 }));
  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
