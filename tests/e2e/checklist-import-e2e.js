// Checklist upload on the inspection-settings screen: CSV with a type column,
// a plain list, append vs replace, the system's own Excel export uploaded
// back, wrong file type; nothing is saved until "שמור".
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/checklist-import-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ locale: 'he-IL', acceptDownloads: true })).newPage();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  const items = (key) => page.$$eval('#cl-' + key + ' .gsm-ci', (l) => l.map((i) => i.value));
  const done = () => page.waitForFunction(() => /✓|✗/.test(document.getElementById('clf-st').textContent), null, { timeout: 10000 });
  const csv = (s) => Buffer.from('﻿' + s, 'utf8');

  await page.goto(BASE + '/gas-app/#/login');
  await page.waitForSelector('#gsm-lg-user');
  await page.fill('#gsm-lg-user', USER);
  await page.fill('#gsm-lg-pass', PASS);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
  const original = await page.evaluate(() => GSM.api('/inspection-config'));

  try {
    await page.evaluate(() => GSM.navigate('/inspcfg'));
    await page.waitForSelector('#clf-load');
    const ax = await new AxeBuilder({ page }).include('.gs-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check('screen with the upload card: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
    await page.click('#clf-load');
    check('no file: hint', /בחרו קובץ/.test(await page.textContent('#clf-st')));

    // 1) CSV with a type column, replace.
    await page.setInputFiles('#clf-file', { name: 'checklist.csv', mimeType: 'text/csv', buffer: csv('סוג ביקורת,מס\',פריט בדיקה\r\nרבעונית,1,שלמות צנרת E2E\r\nרבעונית,2,"שילוט, תקין E2E"\r\nחודשית,1,בדיקת דליפות E2E\r\nשנתית,1,לא מוכר\r\n') });
    await page.click('#clf-load');
    await done();
    check('CSV with types: lists replaced, split by type', JSON.stringify(await items('quarterly')) === JSON.stringify(['שלמות צנרת E2E', 'שילוט, תקין E2E']) && JSON.stringify(await items('monthly')) === JSON.stringify(['בדיקת דליפות E2E']), JSON.stringify([await items('quarterly'), await items('monthly')]));
    check('status: counts + skipped row + reminder to save', /2 סעיפים לצ׳קליסט הרבעוני/.test(await page.textContent('#clf-st')) && /1 שורות דולגו/.test(await page.textContent('#clf-st')) && /שמור/.test(await page.textContent('#clf-st')));
    const stored = await page.evaluate(() => GSM.api('/inspection-config'));
    check('nothing saved yet', JSON.stringify(stored.checklists) === JSON.stringify(original.checklists));

    // 2) Plain list, append to the monthly checklist.
    await page.selectOption('#clf-default', 'monthly');
    await page.check('input[name="clf-mode"][value="append"]');
    await page.setInputFiles('#clf-file', { name: 'list.txt', mimeType: 'text/plain', buffer: csv('בדיקת ברזים E2E\r\nבדיקת דליפות E2E\r\n<b>ניקיון</b> E2E\r\n') });
    await page.click('#clf-load');
    await done();
    const m = await items('monthly');
    check('plain list appended: first line kept, duplicate not added, tags stripped', JSON.stringify(m) === JSON.stringify(['בדיקת דליפות E2E', 'בדיקת ברזים E2E', 'ניקיון E2E']), JSON.stringify(m));
    check('the other checklist is untouched by an append to monthly', (await items('quarterly')).length === 2);

    // 3) Save, then the system's own export uploaded back gives the same lists.
    await page.click('#cfg-save');
    await page.waitForFunction(() => /✓|נשמר/.test(document.getElementById('cfg-status').textContent), null, { timeout: 10000 });
    const saved = await page.evaluate(() => GSM.api('/inspection-config'));
    check('saved through the normal save', saved.checklists.quarterly.length === 2 && saved.checklists.monthly.length === 3);
    const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.click('[data-gsm-action="export"][data-type="checklists"]')]);
    const path = await dl.path();
    await page.check('input[name="clf-mode"][value="replace"]');
    await page.evaluate(() => { document.getElementById('cl-quarterly').innerHTML = ''; document.getElementById('cl-monthly').innerHTML = ''; });
    // The headless browser saves the blob download without a usable name: give it one.
    await page.setInputFiles('#clf-file', { name: 'checklists.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: require('fs').readFileSync(path) });
    await page.evaluate(() => { document.getElementById('clf-st').textContent = ''; });
    await page.click('#clf-load');
    await done();
    check('round trip: the exported Excel file loads back identically', JSON.stringify(await items('quarterly')) === JSON.stringify(saved.checklists.quarterly.map((i) => i.text)) && JSON.stringify(await items('monthly')) === JSON.stringify(saved.checklists.monthly.map((i) => i.text)), await page.textContent('#clf-st'));

    // 4) Wrong type.
    await page.setInputFiles('#clf-file', { name: 'x.php', mimeType: 'text/plain', buffer: Buffer.from('<?php echo 1;') });
    await page.evaluate(() => { document.getElementById('clf-st').textContent = ''; });
    await page.click('#clf-load');
    await done();
    check('other file types refused with a message', /✗/.test(await page.textContent('#clf-st')) && /XLSX/.test(await page.textContent('#clf-st')));
  } finally {
    await page.evaluate((cfg) => GSM.api('/inspection-config', { method: 'POST', body: cfg }), original);
  }
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
