// Forms right after an in-app login (no page reload): the save must use the
// session's current nonce, a validation error must leave "שמור" usable, and
// the equipment fields added in DB v9 round-trip through the form.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/forms-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ locale: 'he-IL' })).newPage();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  const tag = 'FRM-' + Date.now().toString(36);

  // Logged-out page load, then login inside the app (the nonce rotates).
  await page.goto(BASE + '/gas-app/#/login');
  await page.waitForSelector('#gsm-lg-user');
  await page.fill('#gsm-lg-user', USER);
  await page.fill('#gsm-lg-pass', PASS);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });

  await page.evaluate(() => GSM.navigate('/equipment'));
  await page.click('#gsm-eq-add');
  await page.waitForSelector('#eq-manual_url');
  const ax = await new AxeBuilder({ page }).include('#gsm-modal').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('equipment form: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));

  await page.click('#gsm-modal-save');
  await page.waitForSelector('#gsm-modal-err .gs-error, #gsm-modal-err [role="alert"]');
  check('empty name: error shown, "שמור" usable again', /חובה/.test(await page.textContent('#gsm-modal-err')) && !(await page.isDisabled('#gsm-modal-save')));

  await page.fill('#eq-name', 'Gas ' + tag);
  await page.fill('#eq-purchase_date', '2022-02-02');
  await page.fill('#eq-manual_url', 'http://x.example/m.pdf');
  await page.click('#gsm-modal-save');
  await page.waitForFunction(() => /https/.test(document.getElementById('gsm-modal-err').textContent));
  check('http manual link rejected in the form', !(await page.isDisabled('#gsm-modal-save')));

  await page.fill('#eq-manual_url', 'https://x.example/m.pdf');
  await page.click('#gsm-modal-save');
  await page.waitForSelector('#gsm-modal', { state: 'detached', timeout: 10000 }).catch(() => null);
  const errText = await page.evaluate(() => (document.getElementById('gsm-modal-err') || {}).textContent || '');
  check('save right after login works (no "Cookie check failed")', await page.locator('#gsm-modal').count() === 0, errText);

  const id = await page.evaluate(async (n) => { const l = await GSM.api('/equipment?search=' + encodeURIComponent(n)); const a = l.items || l; return a[0] && a[0].id; }, 'Gas ' + tag);
  check('equipment was created', !!id);
  if (id) {
    await page.evaluate((i) => GSM.navigate('/equipment/' + i), id);
    await page.waitForSelector('.gs-detail__row a[href="https://x.example/m.pdf"]', { timeout: 8000 }).catch(() => null);
    const link = page.locator('.gs-detail__row a[href="https://x.example/m.pdf"]');
    check('equipment page: manual link (new tab, noopener) + purchase date', await link.count() === 1 && /noopener/.test(await link.getAttribute('rel')) && /2022-02-02/.test(await page.textContent('.gs-main')));
    const ax2 = await new AxeBuilder({ page }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check('equipment page: 0 axe violations', ax2.violations.length === 0, ax2.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));

    // Edit through the form: values are prefilled and the update saves.
    await page.click('#gsm-eq-edit');
    await page.waitForSelector('#eq-manual_url');
    check('edit form prefilled with the new fields', (await page.inputValue('#eq-purchase_date')) === '2022-02-02' && (await page.inputValue('#eq-manual_url')) === 'https://x.example/m.pdf');
    await page.fill('#eq-room', '77');
    await page.click('#gsm-modal-save');
    await page.waitForSelector('#gsm-modal', { state: 'detached', timeout: 10000 }).catch(() => null);
    const got = await page.evaluate((i) => GSM.api('/equipment/' + i), id);
    check('edit saved, new fields kept', got.room === '77' && got.manual_url === 'https://x.example/m.pdf' && got.purchase_date === '2022-02-02', JSON.stringify([got.room, got.manual_url, got.purchase_date]));

    // Attachment upload + its file link, still without a page reload.
    const fid = await page.evaluate((i) => GSM.api('/faults', { method: 'POST', body: { equipment_id: i, description: 'forms e2e', priority: 'low' } }).then((r) => r.id), id);
    await page.evaluate(async (f) => { const row = await GSM.api('/faults/' + f); GSM_Forms.openFault(row, function () {}); }, fid);
    await page.waitForSelector('#ft-att .gsm-att-input', { state: 'attached', timeout: 8000 });
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
    await page.setInputFiles('#ft-att .gsm-att-input', { name: 'dot.png', mimeType: 'image/png', buffer: png });
    const img = await page.waitForSelector('#ft-att .gsm-att-list img', { timeout: 10000 }).catch(() => null);
    check('attachment upload right after login works', !!img, await page.evaluate(() => (document.querySelector('#ft-att') || {}).textContent || ''));
    if (img) {
      const st = await page.evaluate((u) => fetch(u, { credentials: 'same-origin' }).then((r) => r.status), await img.getAttribute('src'));
      check('attachment link is served (current nonce)', st === 200, String(st));
    }
    await page.evaluate(() => { const m = document.getElementById('gsm-modal'); if (m) m.remove(); });
    await page.evaluate((f) => GSM.api('/faults/' + f, { method: 'DELETE' }), fid);
    await page.evaluate((i) => GSM.api('/equipment/' + i, { method: 'DELETE' }), id);
  }
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
