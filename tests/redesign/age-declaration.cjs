// node age-declaration.cjs <base-url> <user> <password> [simple-product-id] — Identity 6.17.0 age declaration (18+):
// dialog shown once to a customer without an answer, "minor" saved with the parental notice,
// not shown again after answering, never shown on checkout. Staging uses a self-signed certificate.
const { chromium, devices } = require('playwright');

const [base, user, pass, productArg] = process.argv.slice(2);
if (!base || !user || !pass) {
  console.error('usage: node age-declaration.cjs <base-url> <user> <password> [simple-product-id]');
  process.exit(2);
}

(async () => {
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  const ctx = await browser.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const result = {};

  await page.goto(base + '/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

  // Put one product in the cart so /checkout/ is a real checkout page (an empty cart redirects to the cart).
  await page.goto(base + '/shop/');
  const addHref = productArg ? null : await page.locator('a[href*="add-to-cart="]').first().getAttribute('href').catch(() => null);
  const productId = productArg || (addHref ? new URL(addHref, base).searchParams.get('add-to-cart') : null);
  if (productId) {
    await page.goto(base + '/?add-to-cart=' + encodeURIComponent(productId));
  }
  await page.goto(base + '/checkout/');
  result.checkoutUrl = new URL(page.url()).pathname;
  result.checkoutReached = result.checkoutUrl.startsWith('/checkout');
  result.onCheckout = await page.locator('#dip-age').count();

  await page.goto(base + '/shop/');
  const dialog = page.locator('#dip-age');
  result.shownOnShop = await dialog.evaluate((d) => d.open).catch(() => false);
  result.title = await page.locator('#dip-age-title').textContent().catch(() => '');
  await page.keyboard.press('Escape');
  result.stillOpenAfterEscape = await dialog.evaluate((d) => d.open).catch(() => false);

  await page.click('[data-dip-age="minor"]');
  await page.locator('.dip-age__minor').waitFor({ state: 'visible', timeout: 8000 });
  result.minorNotice = (await page.locator('.dip-age__minor .dip-age__text').first().textContent()).trim();
  result.policyLink = await page.locator('.dip-age__minor a').getAttribute('href');
  await page.click('[data-dip-age-close]');
  result.closedAfterNotice = !(await dialog.evaluate((d) => d.open));

  await page.goto(base + '/shop/');
  result.shownAgain = await page.locator('#dip-age').count();

  console.log(JSON.stringify(result, null, 1));
  const ok = result.checkoutReached && result.onCheckout === 0 && result.shownOnShop && result.stillOpenAfterEscape && result.closedAfterNotice && result.shownAgain === 0;
  console.log(ok ? 'PASS' : 'FAIL');
  await browser.close();
  process.exit(ok ? 0 : 1);
})();
