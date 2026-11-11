// QR issuance screen: every record shows its scan link (the URL printed on
// the sticker) with a copy button; the link opens the right QR page.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/qrissue-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'he-IL' });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  const page = await ctx.newPage();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));

  await page.goto(BASE + '/gas-app/#/login');
  await page.waitForSelector('#gsm-lg-user');
  await page.fill('#gsm-lg-user', USER);
  await page.fill('#gsm-lg-pass', PASS);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });

  const tag = 'QRI-' + Date.now().toString(36);
  const made = await page.evaluate(async (t) => {
    const eq = await GSM.api('/equipment', { method: 'POST', body: { name: 'Gas ' + t, building: 'B', room: '1' } });
    const full = await GSM.api('/equipment/' + eq.id);
    const rec = await GSM.api('/qr-issuance', { method: 'POST', body: { equipment_id: eq.id, status: 'issued', issued_date: '2026-11-11' } });
    const loose = await GSM.api('/qr-issuance', { method: 'POST', body: { equipment_name: 'No equipment ' + t, status: 'pending_reissue' } }).catch(() => null);
    return { eq: eq.id, token: full.qr_token, rec: rec.id, loose: loose && loose.id };
  }, tag);
  check('setup: equipment with a QR token + issuance record', !!made.token && !!made.rec, JSON.stringify(made));

  const api = await page.evaluate(() => GSM.api('/qr-issuance'));
  const row = (api.items || []).filter((x) => String(x.id) === String(made.rec))[0];
  check('API: record carries the equipment QR token', row && row.qr_token === made.token);

  await page.evaluate(() => GSM.navigate('/qrissue'));
  await page.waitForSelector('#qri-search');
  await page.fill('#qri-search', tag);
  const card = page.locator('.gs-qri-card', { hasText: 'Gas ' + tag });
  await card.first().waitFor({ timeout: 8000 });
  const href = await card.first().locator('.gs-qri-link a').getAttribute('href');
  check('card shows the scan link with eq=<token>', !!href && href.indexOf('eq=' + encodeURIComponent(made.token)) > -1, href);
  check('link text is the full URL', (await card.first().locator('.gs-qri-link a').textContent()) === href);
  check('copy button has an accessible name', /העתק את קישור הסריקה של Gas/.test(await card.first().locator('[data-copy]').textContent()));
  await card.first().locator('[data-copy]').click();
  await page.waitForTimeout(300);
  check('copy puts the link on the clipboard', (await page.evaluate(() => navigator.clipboard.readText())) === href);
  check('button confirms "הועתק"', /הועתק/.test(await card.first().locator('[data-copy]').textContent()));

  if (made.loose) {
    const lc = page.locator('.gs-qri-card', { hasText: 'No equipment ' + tag });
    check('record without equipment: explains there is no link', /אין קישור סריקה/.test(await lc.first().textContent()));
  }
  const ax = await new AxeBuilder({ page }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('screen: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(', '));

  const bp = await ctx.newPage();
  await bp.goto(href);
  await bp.waitForSelector('#gsm-br-fault, .gs-br-name, h1', { timeout: 10000 }).catch(() => null);
  check('the link opens the QR page of that equipment', (await bp.content()).indexOf('Gas ' + tag) > -1);
  await bp.close();

  await page.evaluate(async (m) => {
    for (const id of [m.rec, m.loose]) { if (id) { await GSM.api('/qr-issuance/' + id + '/status', { method: 'POST', body: { status: 'archived' } }).catch(() => null); await GSM.api('/qr-issuance/' + id, { method: 'DELETE' }).catch(() => null); } }
    await GSM.api('/equipment/' + m.eq, { method: 'DELETE' }).catch(() => null);
  }, made);
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
