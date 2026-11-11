// Privacy requests UI: a user files a deletion request from their profile;
// a manager opens "בקשות פרטיות", scans, sees the results, then rejects it
// (nothing is changed on the test site). Includes an axe scan of the panel.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/privacy-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

async function login(page, u, p) {
  await page.goto(BASE + '/gas-app/#/login');
  await page.waitForSelector('#gsm-lg-user');
  await page.fill('#gsm-lg-user', u);
  await page.fill('#gsm-lg-pass', p);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
}

(async () => {
  const browser = await chromium.launch();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const tag = Date.now().toString(36);
  const uName = 'בודקת פרטיות ' + tag;
  const uEmail = 'privacy.' + tag + '@example.test';
  const uPass = 'Pw-' + tag + '-Aa1!';

  // Manager creates a lab-manager account.
  const mctx = await browser.newContext();
  const mgr = await mctx.newPage();
  const errors = [];
  mgr.on('pageerror', (e) => errors.push(e.message));
  await login(mgr, USER, PASS);
  const created = await mgr.evaluate((b) => GSM.api('/users', { method: 'POST', body: b }).then((r) => ({ ok: true, r }), (e) => ({ ok: false, m: e.message })), { name: uName, email: uEmail, role: 'lab_manager', password: uPass });
  check('manager creates a user', created.ok, JSON.stringify(created));

  // The user files a deletion request from the profile screen (UI).
  const uctx = await browser.newContext();
  const up = await uctx.newPage();
  await login(up, uEmail, uPass);
  await up.evaluate(() => GSM.navigate('/profile'));
  await up.waitForSelector('#pf-del');
  up.once('dialog', (d) => d.accept());
  await up.fill('#pf-del-reason', 'e2e ' + tag);
  await up.click('#pf-del');
  await up.waitForFunction(() => /✓|נשלח|התקבל/.test((document.getElementById('pf-del-status') || {}).textContent || ''), null, { timeout: 8000 }).catch(() => null);
  check('user files a request from the profile', /✓|נשלח|התקבל/.test(await up.textContent('#pf-del-status')), await up.textContent('#pf-del-status'));
  await uctx.close();

  // Manager handles it.
  await mgr.evaluate(() => GSM.navigate('/privacy'));
  await mgr.waitForSelector('.gs-main h2');
  check('privacy screen title', /בקשות פרטיות/.test(await mgr.title()), await mgr.title());
  const btn = await mgr.waitForSelector('tr:has-text("' + uEmail + '") [data-pr]', { timeout: 8000 }).catch(() => null);
  check('request listed as open with a "טפל" button', !!btn);
  if (btn) {
    await btn.click();
    await mgr.waitForSelector('#pr-ids');
    const ids = await mgr.inputValue('#pr-ids');
    check('identifiers prefilled with name + email', ids.includes(uName) && ids.includes(uEmail), ids);
    check('focus moved to the panel heading', await mgr.evaluate(() => document.activeElement && document.activeElement.id === 'pr-title'));
    await mgr.click('#pr-scan');
    await mgr.waitForSelector('#pr-apply', { timeout: 8000 });
    check('scan shows results + actions', /נמצאו \d+ הופעות/.test(await mgr.textContent('#pr-status')), await mgr.textContent('#pr-status'));
    const ax = await new AxeBuilder({ page: mgr }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check('privacy screen + panel: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(', '));
    mgr.once('dialog', (d) => d.accept());
    await mgr.click('#pr-reject');
    await mgr.waitForSelector('tr:has-text("' + uEmail + '") .gs-pill--danger', { timeout: 8000 }).catch(() => null);
    check('reject closes the request (נדחתה)', /נדחתה/.test(await mgr.textContent('tr:has-text("' + uEmail + '")')));
  }
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
