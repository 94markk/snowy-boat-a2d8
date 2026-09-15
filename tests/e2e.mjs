/**
 * End-to-end check of the whole money path against the dev server:
 * register -> request top-up -> forwarded SMS -> wallet credited -> buy.
 * Also collects every console error and CSP violation along the way.
 */

import { chromium } from "playwright";
import { createHmac } from "node:crypto";
import { existsSync } from "node:fs";

const BASE = process.env.E2E_BASE_URL ?? "http://127.0.0.1:4321";
const SECRET = "local-development-secret-do-not-use-in-production";

// A distinct payer number per run, so a pending top-up left by an earlier run
// cannot be the one this run's SMS matches. Matching is oldest-first by design.
const payerMsisdn = String(30_000_000 + (Date.now() % 9_000_000));

const problems = [];
const note = (page, message) => problems.push(`[${page}] ${message}`);

function signedSmsRequest(body) {
  const payload = JSON.stringify(body);
  const timestamp = Math.floor(Date.now() / 1000);
  const signature = createHmac("sha256", SECRET).update(`${timestamp}.${payload}`).digest("hex");
  return {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-DS-Timestamp": String(timestamp),
      "X-DS-Signature": signature,
      "X-DS-Device": "test-handset",
    },
    body: payload,
  };
}

// The sandbox ships a Chromium build that may not match this Playwright
// version's expected revision, so point at it explicitly when it is present.
const executablePath = process.env.CHROMIUM_PATH ?? "/opt/pw-browsers/chromium-1194/chrome-linux/chrome";
const browser = await chromium.launch(
  existsSync(executablePath) ? { executablePath, args: ["--no-sandbox"] } : {},
);
const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await context.newPage();

let currentLabel = "startup";
page.on("console", (message) => {
  if (message.type() === "error") note(currentLabel, `console: ${message.text()}`);
});
page.on("pageerror", (error) => note(currentLabel, `pageerror: ${error.message}`));

async function visit(label, path) {
  currentLabel = label;
  const response = await page.goto(`${BASE}${path}`, { waitUntil: "networkidle" });
  if (!response || response.status() >= 400) {
    note(label, `HTTP ${response?.status()}`);
  }
  return response;
}

/* 1. Public pages render without console or CSP errors. */
await visit("home", "/fr/");
await visit("shop", "/fr/shop");
await visit("category", "/fr/shop/c/jeux");
await visit("product", "/fr/shop/free-fire-latam");
await visit("search", "/fr/shop/search?q=free");
await visit("home-en", "/en/");
await visit("home-ht", "/ht/");

/* 2. The cart badge reacts to adding an item. */
currentLabel = "add-to-cart";
await visit("product", "/fr/shop/free-fire-latam");
await page.fill('input[name="player_id"]', "123456789");
await page.click('button[type="submit"]');
await page.waitForTimeout(400);
const badge = await page.locator("[data-cart-count]").first().textContent();
if (badge?.trim() !== "1") note("add-to-cart", `cart badge shows "${badge}", expected "1"`);

/* 3. Register. */
currentLabel = "register";
const email = `test${Date.now()}@example.com`;
await visit("account", "/fr/account");
await page.click("[data-auth-toggle]");
await page.fill('input[name="email"]', email);
await page.fill('input[name="password"]', "a-long-enough-passphrase");

// Wait for the API call itself: the page is already at /fr/account, so waiting
// on the URL would resolve before the session cookie is set.
const [registerResponse] = await Promise.all([
  page.waitForResponse((response) => response.url().includes("/api/auth/register")),
  page.click("[data-auth-submit]"),
]);
if (registerResponse.status() !== 201) note("register", `register returned ${registerResponse.status()}`);

// The page redirects itself after registering. Wait for the signed-in view to
// appear rather than racing it with another navigation.
currentLabel = "account-signed-in";
try {
  await page.waitForSelector("[data-sign-out]", { timeout: 15_000 });
} catch {
  note("register", "signed-in view never appeared after registering");
}

/* 4. Request a top-up of G500. */
currentLabel = "topup";
await visit("wallet", "/fr/wallet");
await page.fill('input[name="amount"]', "500");
await page.fill('input[name="payerMsisdn"]', payerMsisdn);
await page.click("[data-topup-submit]");
await page.waitForSelector("[data-instructions]:not([hidden])", { timeout: 10_000 });

/* 5. The forwarder delivers the operator's confirmation SMS. */
currentLabel = "sms-webhook";
// A fresh operator transaction id each run: the same one twice is rejected as a
// duplicate by design, which is the protection this endpoint exists to have.
const transactionId = `E2E${Date.now().toString(36).toUpperCase()}`;
const smsBody = {
  externalId: `e2e-${Date.now()}`,
  sender: "MonCash",
  body: `Ou resevwa 500.00 HTG nan men TEST USER (509${payerMsisdn}). Transaction ID: ${transactionId}`,
};
const smsResponse = await context.request.post(`${BASE}/api/webhooks/sms`, {
  headers: signedSmsRequest(smsBody).headers,
  data: smsBody,
});
const smsResult = await smsResponse.json();
if (smsResult.outcome !== "matched") note("sms-webhook", `outcome was "${smsResult.outcome}", expected "matched"`);

/* An unsigned delivery must be refused. */
const unsigned = await context.request.post(`${BASE}/api/webhooks/sms`, { data: smsBody });
if (unsigned.status() !== 401) note("sms-webhook", `unsigned delivery returned ${unsigned.status()}, expected 401`);

/* 6. The balance shows the credit. */
currentLabel = "wallet-after";
await visit("wallet-after", "/fr/wallet");
const balanceText = (await page.locator(".balance-card strong").textContent())?.trim();
if (balanceText !== "G500") note("wallet-after", `balance shows "${balanceText}", expected "G500"`);

/* 7. Pay for the cart from the wallet. */
currentLabel = "checkout";
await visit("cart", "/fr/cart");
await page.waitForSelector(".cart-line", { timeout: 10_000 });
const payButton = page.locator(".summary button.btn-primary");
if (!(await payButton.count())) {
  note("checkout", "pay button missing — balance may not cover the cart");
} else {
  const [orderResponse] = await Promise.all([
    page.waitForResponse((response) => response.url().includes("/api/orders")),
    payButton.click(),
  ]);
  if (orderResponse.status() !== 201) note("checkout", `order API returned ${orderResponse.status()}`);
  await page.waitForURL("**/account?order=**", { timeout: 15_000 });
}

/* 8. The order shows in the account.
 *
 * No real distributor is configured, so the stub adapter declines rather than
 * pretending to have delivered, and the order is refunded automatically. The
 * balance therefore returns to G500 — which is exactly the refund path we want
 * proven: the customer is never left having paid for nothing. Once a real
 * supplier is wired up, this expectation becomes G70 (G500 − G430). */
currentLabel = "after-order";
await visit("account-after", "/fr/account");

const orderCards = await page.locator(".order-card").count();
if (orderCards < 1) {
  note("after-order", "no order card rendered");
} else {
  const orderStatus = (await page.locator(".order-card .status-pill").first().textContent())?.trim();
  if (orderStatus !== "Remboursée") {
    note("after-order", `order status is "${orderStatus}", expected "Remboursée" with no supplier configured`);
  }

  const newBalance = (await page.locator(".balance-line strong").textContent())?.trim();
  if (newBalance !== "G500") {
    note("after-order", `balance after the refund is "${newBalance}", expected "G500"`);
  }
}

await browser.close();

if (problems.length === 0) {
  console.log("PASS — no console errors, no CSP violations, full money path works");
} else {
  console.log(`FAIL — ${problems.length} problem(s):`);
  for (const problem of problems) console.log("  -", problem);
  process.exitCode = 1;
}
