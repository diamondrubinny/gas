// SSO (Microsoft Entra ID) in the browser: settings card, the login button,
// the redirect to Microsoft (host, tenant, PKCE, state cookie), and the
// message after a failed return. The full sign-in is covered by
// tests/integration/sso-test.php with a simulated identity provider; a real
// Entra tenant cannot be used here.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/sso-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;
const TENANT = '11111111-2222-4333-8444-555555555555', CLIENT = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', SECRET = 'e2e-sso-secret-0123456789';

(async () => {
  const browser = await chromium.launch();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const actx = await browser.newContext({ locale: 'he-IL' });
  const adm = await actx.newPage();
  const errors = [];
  adm.on('pageerror', (e) => errors.push(e.message));
  await adm.goto(BASE + '/gas-app/#/login');
  await adm.waitForSelector('#gsm-lg-user');
  check('no SSO button while SSO is off', await adm.locator('#gsm-lg-sso').count() === 0);
  await adm.fill('#gsm-lg-user', USER);
  await adm.fill('#gsm-lg-pass', PASS);
  await adm.click('#gsm-lg-submit');
  await adm.waitForSelector('.gs-header__logout', { timeout: 15000 });
  await adm.evaluate(() => GSM.navigate('/admin'));
  await adm.waitForSelector('#sso-save', { timeout: 10000 });
  let ax = await new AxeBuilder({ page: adm }).include('#gsm-sso-settings').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('settings card: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
  check('redirect URI shown for registration in Entra', /gas\/v1\/sso\/callback|sso%2Fcallback/.test(await adm.inputValue('#sso-redirect')));
  await adm.fill('#sso-tenant', 'common');
  await adm.click('#sso-save');
  await adm.waitForFunction(() => /✗/.test(document.getElementById('sso-st').textContent));
  check('tenant "common" refused with a message', /פורמט/.test(await adm.textContent('#sso-st')));
  await adm.fill('#sso-tenant', TENANT);
  await adm.fill('#sso-client', CLIENT);
  await adm.fill('#sso-secret', SECRET);
  await adm.fill('#sso-domains', 'corp.example.test');
  await adm.check('#sso-enabled');
  await adm.click('#sso-save');
  await adm.waitForFunction(() => /✓ נשמר/.test((document.getElementById('sso-st') || {}).textContent || ''), null, { timeout: 8000 });
  check('saved: shown as active, secret not echoed', /פעיל/.test(await adm.textContent('#gsm-sso-settings')) && (await adm.content()).indexOf(SECRET) === -1 && (await adm.inputValue('#sso-secret')) === '');

  try {
    const ctx = await browser.newContext({ locale: 'he-IL' });
    const page = await ctx.newPage();
    const seen = [];
    // Never talk to the real Microsoft from a test: answer its host with a stub.
    await ctx.route('https://login.microsoftonline.com/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>IdP stub</body></html>' }));
    ctx.on('request', (r) => { if (/login\.microsoftonline\.com/.test(r.url())) seen.push(r.url()); });
    await page.goto(BASE + '/gas-app/?t=' + Date.now() + '#/login');
    await page.waitForSelector('#gsm-lg-sso');
    check('login screen shows "כניסה עם החשבון הארגוני"', /החשבון הארגוני/.test(await page.textContent('#gsm-lg-sso')));
    ax = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check('login page with the SSO button: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
    check('nothing sent to Microsoft before the click', seen.length === 0);
    await page.click('#gsm-lg-sso');
    await page.waitForURL(/login\.microsoftonline\.com/, { timeout: 15000 });
    const target = page.url();
    const u = new URL(target);
    const q = u.searchParams;
    check('redirect goes to the tenant on login.microsoftonline.com', u.host === 'login.microsoftonline.com' && u.pathname === '/' + TENANT + '/oauth2/v2.0/authorize');
    check('code flow with PKCE S256, state and nonce', q.get('response_type') === 'code' && q.get('code_challenge_method') === 'S256' && (q.get('code_challenge') || '').length === 43 && /^[a-f0-9]{32}$/.test(q.get('state')) && /^[a-f0-9]{32}$/.test(q.get('nonce')) && q.get('client_id') === CLIENT);
    check('redirect_uri is this site\'s callback', /sso(\/|%2F)callback/.test(q.get('redirect_uri')) && q.get('redirect_uri').indexOf(new URL(BASE).host) > -1, q.get('redirect_uri'));
    check('the client secret is not in the URL', target.indexOf(SECRET) === -1);
    const cookies = await ctx.cookies(BASE);
    const ck = cookies.filter((c) => c.name === 'gsm_sso_state')[0];
    check('browser-binding cookie: HttpOnly, SameSite=Lax', !!ck && ck.httpOnly === true && ck.sameSite === 'Lax', JSON.stringify(ck));

    // Return with a forged code + state: refused, lands on the login screen with a message.
    const cb = decodeURIComponent(q.get('redirect_uri'));
    await page.goto(cb + (cb.indexOf('?') > -1 ? '&' : '?') + 'code=forged&state=' + 'a'.repeat(32));
    await page.waitForSelector('#gsm-lg-err .gs-error', { timeout: 15000 });
    check('forged return: back on the login screen with a generic message, not signed in', /הכניסה הארגונית נכשלה/.test(await page.textContent('#gsm-lg-err')) && await page.locator('.gs-header__logout').count() === 0);
    await page.goto(cb + (cb.indexOf('?') > -1 ? '&' : '?') + 'error=access_denied&state=' + q.get('state'));
    await page.waitForSelector('#gsm-lg-err .gs-error', { timeout: 15000 });
    check('cancelled at Microsoft: message shown, not signed in', await page.locator('.gs-header__logout').count() === 0);
    await page.goto(BASE + '/gas-app/?sso_error=%3Cscript%3Ealert(1)%3C/script%3E#/login');
    await page.waitForSelector('#gsm-lg-user');
    check('sso_error is a slug mapped to a fixed message (no reflection)', (await page.content()).indexOf('alert(1)') === -1);
    await ctx.close();
  } finally {
    await adm.evaluate(() => GSM.api('/sso', { method: 'POST', body: { clear: true, auto_create: false } }));
  }
  const off = await (await browser.newContext()).newPage();
  await off.goto(BASE + '/gas-app/?t=' + Date.now() + '#/login');
  await off.waitForSelector('#gsm-lg-user');
  check('switched off: the button is gone', await off.locator('#gsm-lg-sso').count() === 0);
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
