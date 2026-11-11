// Two-factor login in the browser: enroll from the security screen (QR +
// manual key), backup codes shown once, login asks for the code, wrong code
// is refused, mandatory-for-managers redirects to the security screen.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/twofa-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const crypto = require('crypto');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

function b32(s) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
  for (const c of s.replace(/\s+/g, '').toUpperCase()) bits += A.indexOf(c).toString(2).padStart(5, '0');
  const out = []; for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(out);
}
function totp(secret, offset) {
  const step = Math.floor(Date.now() / 30000) + (offset || 0);
  const buf = Buffer.alloc(8); buf.writeUInt32BE(step, 4);
  const h = crypto.createHmac('sha1', b32(secret)).update(buf).digest();
  const o = h[19] & 15;
  return String(((h[o] & 127) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1e6).padStart(6, '0');
}
async function login(page, u, p) {
  await page.goto(BASE + '/gas-app/?t=' + Date.now() + '#/login');
  await page.waitForSelector('#gsm-lg-user');
  await page.fill('#gsm-lg-user', u);
  await page.fill('#gsm-lg-pass', p);
  await page.click('#gsm-lg-submit');
}

(async () => {
  const browser = await chromium.launch();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const tag = Date.now().toString(36);
  const uEmail = 'tf.' + tag + '@example.test';
  const uPass = 'Pw-' + tag + '-Aa1!x';
  const errors = [];

  const actx = await browser.newContext({ locale: 'he-IL' });
  const adm = await actx.newPage();
  await login(adm, USER, PASS);
  await adm.waitForSelector('.gs-header__logout', { timeout: 15000 });
  const made = await adm.evaluate((b) => GSM.api('/users', { method: 'POST', body: b }).then((r) => r, (e) => ({ err: e.message })), { name: 'TF E2E ' + tag, email: uEmail, role: 'manager', password: uPass });
  check('setup: manager account created', made && !made.err, JSON.stringify(made));

  const ctx = await browser.newContext({ locale: 'he-IL' });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page, uEmail, uPass);
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
  await page.evaluate(() => GSM.navigate('/security'));
  await page.waitForSelector('#tf-start');
  let ax = await new AxeBuilder({ page }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('security screen (off): 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id).join(','));
  await page.click('#tf-start');
  await page.waitForSelector('#tf-secret');
  const secret = (await page.textContent('#tf-secret')).replace(/\s+/g, '');
  check('enrollment shows a QR image with alt text + manual key', /^[A-Z2-7]{32}$/.test(secret) && (await page.getAttribute('#tf-setup img', 'alt') || '').length > 10 && (await page.getAttribute('#tf-setup img', 'src')).startsWith('data:image/png'));
  ax = await new AxeBuilder({ page }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('enrollment step: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
  await page.fill('#tf-code', '12345');
  await page.click('#tf-confirm');
  await page.waitForFunction(() => /✗/.test(document.getElementById('tf-st').textContent));
  check('wrong enrollment code: message, still off', /שגוי/.test(await page.textContent('#tf-st')));
  await page.fill('#tf-code', totp(secret));
  await page.click('#tf-confirm');
  await page.waitForSelector('#tf-codes li', { timeout: 10000 });
  const codes = await page.$$eval('#tf-codes li', (l) => l.map((x) => x.textContent.trim()));
  check('enabled: 8 backup codes shown, focus on their heading', codes.length === 8 && /^[A-Z2-7]{5}-[A-Z2-7]{5}$/.test(codes[0]) && await page.evaluate(() => document.activeElement.id === 'tf-codes-title'));
  check('status shows "פעיל"', /פעיל/.test(await page.textContent('.gs-main')));
  ax = await new AxeBuilder({ page }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('enabled state + codes: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
  await page.reload();
  await page.waitForSelector('#tf-newcodes');
  check('backup codes are not shown again after reload', await page.locator('#tf-codes').count() === 0);
  await ctx.close();

  // Login now needs the code.
  const c2 = await browser.newContext({ locale: 'he-IL' });
  const p2 = await c2.newPage();
  p2.on('pageerror', (e) => errors.push(e.message));
  await login(p2, uEmail, uPass);
  await p2.waitForSelector('#gsm-lg-2fa:not([hidden])', { timeout: 10000 });
  check('login: password alone asks for the code, not logged in', await p2.locator('.gs-header__logout').count() === 0 && await p2.evaluate(() => document.activeElement.id === 'gsm-lg-code'));
  check('code field: one-time-code autocomplete + label', (await p2.getAttribute('#gsm-lg-code', 'autocomplete')) === 'one-time-code' && await p2.locator('label[for="gsm-lg-code"]').count() === 1);
  ax = await new AxeBuilder({ page: p2 }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('login second step: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
  await p2.fill('#gsm-lg-code', '000000' === totp(secret, 1) ? '111111' : '000000');
  await p2.click('#gsm-lg-submit');
  await p2.waitForSelector('#gsm-lg-err .gs-error');
  check('wrong code: error, still on login', /שגוי/.test(await p2.textContent('#gsm-lg-err')) && await p2.locator('.gs-header__logout').count() === 0);
  await p2.fill('#gsm-lg-code', totp(secret, 1));
  await p2.keyboard.press('Enter');
  await p2.waitForSelector('.gs-header__logout', { timeout: 15000 });
  check('right code (Enter key): logged in', true);

  // Mandatory policy: a second manager without 2FA is sent to the security screen.
  await p2.evaluate(() => GSM.navigate('/security'));
  await p2.waitForSelector('#tf-policy');
  await p2.check('#tf-policy');
  await p2.waitForFunction(() => /נשמר/.test((document.getElementById('tf-policy-st') || {}).textContent || ''));
  const m2 = 'tf2.' + tag + '@example.test';
  await p2.evaluate((b) => GSM.api('/users', { method: 'POST', body: b }), { name: 'TF2 E2E ' + tag, email: m2, role: 'manager', password: uPass });
  const c3 = await browser.newContext({ locale: 'he-IL' });
  const p3 = await c3.newPage();
  await login(p3, m2, uPass);
  await p3.waitForSelector('#tf-start', { timeout: 15000 });
  check('mandatory: un-enrolled manager lands on the security screen with an alert', /#\/security/.test(p3.url()) && /חובה למנהלים/.test(await p3.textContent('.gs-main [role="alert"]')));
  await p3.evaluate(() => GSM.navigate('/users'));
  await p3.waitForTimeout(600);
  check('mandatory: other screens redirect back', /#\/security/.test(p3.url()));
  await c3.close();

  // Cleanup: policy off, admin deactivates the test users (no delete route).
  await p2.uncheck('#tf-policy');
  await p2.waitForFunction(() => /נשמר/.test((document.getElementById('tf-policy-st') || {}).textContent || ''));
  await c2.close();
  await adm.evaluate(async (emails) => {
    const list = await GSM.api('/users');
    for (const u of list) if (emails.indexOf(u.email) > -1) await GSM.api('/users/' + u.id, { method: 'PUT', body: { active: false } }).catch(() => null);
  }, [uEmail, m2]);
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
