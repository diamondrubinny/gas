// reCAPTCHA in the browser (monitor mode, Google's script replaced by a stub):
// nothing is requested from Google until the protected button is pressed,
// the notice is shown, the CSP allows Google only while the feature is on,
// and the login still works. The decision logic is covered by
// tests/integration/captcha-test.php.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/captcha-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;
const SITE_KEY = 'E2E' + 'k'.repeat(37), SECRET = 'E2E' + 's'.repeat(37);
const STUB = "window.grecaptcha={ready:function(f){f();},execute:function(k,o){window.__capCalls=(window.__capCalls||[]).concat([o.action]);return Promise.resolve('stub-token-'+o.action);}};";

(async () => {
  const browser = await chromium.launch();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const newCtx = async () => {
    const ctx = await browser.newContext({ locale: 'he-IL' });
    const st = { google: [], csp: [], bodies: [] };
    await ctx.route(/https:\/\/www\.(google|gstatic)\.com\/.*/, (r) => { st.google.push(r.request().url()); r.fulfill({ status: 200, contentType: 'text/javascript', body: STUB }); });
    return { ctx, st };
  };
  const watch = (page, st) => {
    page.on('console', (m) => { if (/Content Security Policy/i.test(m.text())) st.csp.push(m.text()); });
    page.on('request', (r) => { if (/auth\/login|report-fault/.test(r.url()) && r.method() === 'POST') st.bodies.push(r.postData() || ''); });
  };
  const adminLogin = async (page) => {
    await page.goto(BASE + '/gas-app/?t=' + Date.now() + '#/login');
    await page.waitForSelector('#gsm-lg-user');
    await page.fill('#gsm-lg-user', USER);
    await page.fill('#gsm-lg-pass', PASS);
    await page.click('#gsm-lg-submit');
    await page.waitForSelector('.gs-header__logout', { timeout: 20000 });
  };

  // Enable (monitor mode) as the manager.
  const a = await newCtx();
  const adm = await a.ctx.newPage();
  await adminLogin(adm);
  await adm.evaluate(() => GSM.navigate('/admin'));
  await adm.waitForSelector('#cap-save', { timeout: 10000 });
  const ax = await new AxeBuilder({ page: adm }).include('#gsm-captcha-settings').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('settings card: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
  check('enforce checkbox is locked before a successful test', await adm.isDisabled('#cap-enforce'));
  const saved = await adm.evaluate((k) => GSM.api('/captcha', { method: 'POST', body: { site_key: k[0], secret: k[1], enabled: true, login: true, bridge: true } }), [SITE_KEY, SECRET]);
  check('enabled in monitor mode; secret not returned', saved.enabled && !saved.enforce && JSON.stringify(saved).indexOf(SECRET) === -1);
  const tok = await adm.evaluate(async () => { const l = await GSM.api('/equipment?per_page=1'); const e = (l.items || l)[0] || await GSM.api('/equipment', { method: 'POST', body: { name: 'Cap E2E' } }); return (await GSM.api('/equipment/' + e.id)).qr_token; });

  try {
    // ---- Login page, logged out --------------------------------------------
    const b = await newCtx();
    const page = await b.ctx.newPage();
    watch(page, b.st);
    const resp = await page.goto(BASE + '/gas-app/?t=' + Date.now() + '#/login');
    const csp = resp.headers()['content-security-policy'] || '';
    await page.waitForSelector('#gsm-lg-user');
    await page.waitForTimeout(1200);
    check('CSP allows the reCAPTCHA hosts on the login page', /script-src[^;]*https:\/\/www\.google\.com\/recaptcha\//.test(csp) && /frame-src[^;]*google\.com\/recaptcha/.test(csp), csp.slice(0, 200));
    check('script-src stays strict otherwise (no unsafe-inline / wildcards)', !/script-src[^;]*('unsafe-inline'|'unsafe-eval'|\*)/.test(csp));
    check('nothing requested from Google on page load', b.st.google.length === 0, b.st.google.join(','));
    check('notice next to the login button, with Google policy links', /reCAPTCHA/.test(await page.textContent('.gs-captcha-note')) && await page.locator('.gs-captcha-note a[href^="https://policies.google.com/"]').count() === 2);
    const ax2 = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check('login page with the notice: 0 axe violations', ax2.violations.length === 0, ax2.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
    await page.fill('#gsm-lg-user', USER);
    await page.fill('#gsm-lg-pass', PASS);
    await page.click('#gsm-lg-submit');
    await page.waitForSelector('.gs-header__logout', { timeout: 20000 });
    check('Google contacted only after pressing "התחבר"', b.st.google.length >= 1 && /recaptcha\/api\.js\?render=/.test(b.st.google[0]), b.st.google.join(','));
    check('token for action "login" sent with the login request', /"captcha":"stub-token-login"/.test(b.st.bodies.join(' ')));
    check('login works in monitor mode', await page.locator('.gs-header__logout').count() === 1);
    check('no CSP violations', b.st.csp.length === 0, b.st.csp.join(' | '));
    await b.ctx.close();

    // ---- QR page: fault report ---------------------------------------------------
    const c = await newCtx();
    const bp = await c.ctx.newPage();
    watch(bp, c.st);
    await bp.goto(BASE + '/bridge/?eq=' + tok);
    await bp.waitForSelector('#gsm-br-fault', { timeout: 10000 });
    await bp.waitForTimeout(800);
    check('QR page: nothing requested from Google while only viewing', c.st.google.length === 0, c.st.google.join(','));
    await bp.click('#gsm-br-fault');
    await bp.waitForSelector('#br-desc');
    check('fault form shows the notice', await bp.locator('.gs-captcha-note').count() === 1 && c.st.google.length === 0);
    await bp.fill('#br-desc', 'בדיקת reCAPTCHA');
    await bp.waitForTimeout(2200);
    await bp.click('.gs-modal .gs-btn:not(.gs-btn--ghost)');
    await bp.waitForSelector('#br-done-title', { timeout: 15000 }).catch(() => null);
    check('report sent with a token for action "report_fault"', /"captcha":"stub-token-report_fault"/.test(c.st.bodies.join(' ')) && await bp.locator('#br-done-title').count() === 1, c.st.bodies.join(' ').slice(0, 160));
    check('QR page: no CSP violations', c.st.csp.length === 0, c.st.csp.join(' | '));
    await c.ctx.close();
  } finally {
    // ---- Off again: no Google in the CSP, no notice ---------------------------------
    await adm.evaluate(async () => {
      await GSM.api('/captcha', { method: 'POST', body: { clear: true, enforce: false } });
      const f = await GSM.api('/faults?per_page=50'); for (const x of (f.items || f)) if (x.description === 'בדיקת reCAPTCHA') await GSM.api('/faults/' + x.id, { method: 'DELETE' }).catch(() => null);
    });
  }
  const d = await newCtx();
  const off = await d.ctx.newPage();
  const r2 = await off.goto(BASE + '/gas-app/?t=' + Date.now() + '#/login');
  await off.waitForSelector('#gsm-lg-user');
  check('when off: CSP has no Google hosts and there is no notice', !/google/.test(r2.headers()['content-security-policy'] || '') && await off.locator('.gs-captcha-note').count() === 0);
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
