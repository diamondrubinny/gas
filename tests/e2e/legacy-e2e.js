// "משיכה מהאתר הישן" screen: settings validation, token never shown back,
// the real old server rejecting a fake token (clear error), and rendering of
// a check report + pull summary (server answers routed to fixed data — the
// server-side logic is covered by tests/integration/legacy-pull-test.php).
// Includes axe on the rendered report.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/legacy-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;
const FAKE = 'E2E-fake-token-' + Date.now().toString(36);

const CHECK = {
  applied: false,
  sets: {
    equipment: { label: 'ציוד', fetched: 3, valid: 2, no_id: 1, would_create: 1, would_update: 1, unknown: ['עמודה מוזרה'],
      columns: [{ column: 'text_mm3r2tt6', field: 'סוג גז' }, { column: 'name', field: 'שם הציוד' }],
      sample: [{ 'מזהה Monday': '9100', 'שם הציוד': 'מנדף א', 'סוג גז': 'חנקן' }] },
    faults: { label: 'תקלות', fetched: 2, valid: 2, no_id: 0, would_create: 2, would_update: 0, unlinked: 1, unknown: [], columns: [], sample: [] },
    audit: { label: 'יומן בקרה', fetched: 4, columns: [], unknown: [], audit: { new: 1, duplicates: 1, too_old: 1, invalid: 1 } }
  }
};
const PULL = {
  applied: true, restore_point: 77, sets: CHECK.sets,
  counts: {
    equipment: { created: 1, updated: 1, skipped: 1, failed: 0, notes: [] },
    faults: { created: 1, updated: 0, skipped: 1, failed: 0, notes: ['לא נמצא ציוד תואם: ציוד שלא קיים'] },
    audit: { new: 1, duplicates: 1, too_old: 1, invalid: 1 }
  }
};

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ locale: 'he-IL' })).newPage();
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
  // Start disconnected.
  await page.evaluate(() => GSM.api('/legacy', { method: 'POST', body: { base: 'https://api.safety-tech.dev', clear_token: true, auto: false } }));

  await page.evaluate(() => GSM.navigate('/legacy'));
  await page.waitForSelector('#lg-save');
  check('screen title', /משיכה מהאתר הישן/.test(await page.title()), await page.title());
  check('listed in the menu', await page.locator('button[data-gsm-nav="/legacy"]').count() > 0);
  check('not connected: "בדוק" disabled, "משוך" disabled', await page.isDisabled('#lg-check') && await page.isDisabled('#lg-pull'));

  // Invalid base.
  await page.fill('#lg-base', 'http://api.safety-tech.dev');
  await page.click('#lg-save');
  await page.waitForFunction(() => /✗/.test(document.getElementById('lg-save-st').textContent));
  check('http base rejected with a message', /https/.test(await page.textContent('#lg-save-st')), await page.textContent('#lg-save-st'));

  // Valid base + token.
  await page.fill('#lg-base', 'https://api.safety-tech.dev');
  await page.fill('#lg-token', FAKE);
  await page.click('#lg-save');
  await page.waitForFunction(() => /✓/.test((document.getElementById('lg-save-st') || {}).textContent || ''));
  const html = await page.content();
  check('saved: shown as connected with a masked token', /מחובר/.test(await page.textContent('.gs-main')) && html.indexOf(FAKE) === -1);
  check('token field is empty after save (never sent back)', (await page.inputValue('#lg-token')) === '');
  check('token field is a password field', (await page.getAttribute('#lg-token', 'type')) === 'password');

  // Real old server: a fake token must give a clear error (or a network error offline).
  await page.click('#lg-check');
  const err = await page.waitForSelector('#lg-result .gs-error[role="alert"]', { timeout: 60000 }).catch(() => null);
  const errText = err ? await err.textContent() : '';
  check('real old server: fake token -> clear error, no token in it', !!err && errText.indexOf(FAKE) === -1 && /טוקן|רשת|תשובה/.test(errText), errText);
  check('pull stays disabled after a failed check', await page.isDisabled('#lg-pull'));

  // Report rendering (routed).
  await page.route('**/gas/v1/legacy/check*', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(CHECK) }));
  await page.route('**/legacy%2Fcheck*', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(CHECK) }));
  await page.click('#lg-check');
  await page.waitForFunction(() => !document.getElementById('lg-pull').disabled, null, { timeout: 15000 });
  const rep = await page.textContent('#lg-result');
  check('report: create / update counts', /1<\/b>|1 חדשות|חדשות ייווצרו/.test(rep) && /קיימות יעודכנו/.test(rep), rep.slice(0, 200));
  check('report: rows without id + unlinked + unknown columns', /בלי מזהה Monday/.test(rep) && /לא מקושרות/.test(rep) && /עמודה מוזרה/.test(rep));
  check('report: audit archive summary', /ייכנסו לארכיון/.test(rep) && /תקופת השמירה/.test(rep));
  const ax = await new AxeBuilder({ page }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('screen + report: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(', '));

  // Changing the selection requires a new check.
  await page.click('.lg-set[value="audit"]');
  check('changing what to pull disables "משוך" until a new check', await page.isDisabled('#lg-pull'));
  await page.click('.lg-set[value="audit"]');
  await page.click('#lg-check');
  await page.waitForFunction(() => !document.getElementById('lg-pull').disabled, null, { timeout: 15000 });

  // Pull summary (routed).
  await page.route('**/gas/v1/legacy/pull*', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(PULL) }));
  await page.route('**/legacy%2Fpull*', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(PULL) }));
  page.once('dialog', (d) => d.accept());
  await page.click('#lg-pull');
  await page.waitForSelector('#lg-done', { timeout: 15000 });
  const done = await page.textContent('#lg-result');
  check('pull summary: table + restore point link', /המשיכה הסתיימה/.test(done) && /נקודת שחזור #77/.test(done) && await page.locator('#lg-result a[href="#/restorepts"]').count() === 1);
  check('pull summary: skip details listed', /לא נמצא ציוד תואם/.test(done));
  check('focus moved to the summary heading', await page.evaluate(() => document.activeElement && document.activeElement.id === 'lg-done'));
  const ax2 = await new AxeBuilder({ page }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('pull summary: 0 axe violations', ax2.violations.length === 0, ax2.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(', '));
  await page.unrouteAll({ behavior: 'ignoreErrors' });

  // Disconnect.
  page.once('dialog', (d) => d.accept());
  await page.click('#lg-clear');
  await page.waitForFunction(() => /לא מחובר/.test(document.querySelector('.gs-main').textContent));
  check('disconnect: token removed', true);
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
