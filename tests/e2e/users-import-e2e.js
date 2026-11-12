// Users import through the screen: upload a file, preview, create.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/users-import-e2e.js
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
  page.on('dialog', (d) => d.accept());
  const tag = Date.now().toString(36);
  const e1 = 'imp1.' + tag + '@example.test', e2 = 'imp2.' + tag + '@example.test';

  await page.goto(BASE + '/gas-app/#/login');
  await page.waitForSelector('#gsm-lg-user');
  await page.fill('#gsm-lg-user', USER);
  await page.fill('#gsm-lg-pass', PASS);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
  await page.evaluate(() => GSM.navigate('/users'));
  await page.waitForSelector('#gsm-user-import');
  await page.click('#gsm-user-import');
  await page.waitForSelector('#ui-file');
  check('panel opens: expanded state + focus on its heading', (await page.getAttribute('#gsm-user-import', 'aria-expanded')) === 'true' && await page.evaluate(() => document.activeElement.id === 'ui-title'));
  await page.click('#ui-check');
  check('no file: hint', /בחרו קובץ/.test(await page.textContent('#ui-st')));

  const json = JSON.stringify({ users: [
    { name: 'מיובא א ' + tag, email: e1, role: 'אחמ"ת', active: 'פעיל', pinHash: 'SECRET-HASH-' + tag },
    { name: 'מיובא ב ' + tag, email: e2, role: 'מנהל מעבדה', active: 'לא פעיל' },
    { name: 'שגוי', email: 'bad', role: 'מנהל' }
  ] });
  await page.setInputFiles('#ui-file', { name: 'users-2026-11-12.json', mimeType: 'application/json', buffer: Buffer.from(json, 'utf8') });
  await page.click('#ui-check');
  await page.waitForSelector('#ui-apply', { timeout: 10000 });
  const txt = await page.textContent('#ui-result');
  check('preview: 2 new, 1 invalid, roles shown in Hebrew', /2<\/b>|2 משתמשים חדשים/.test(await page.innerHTML('#ui-result')) && /1 לא תקינים/.test(txt) && /אחמ"ת/.test(txt) && /מנהל מעבדה/.test(txt));
  check('preview never shows the PIN hash', (await page.content()).indexOf('SECRET-HASH') === -1);
  check('preview created nothing', await page.evaluate(async (e) => !(await GSM.api('/users')).some((u) => u.email === e), e1));
  const ax = await new AxeBuilder({ page }).include('#gsm-user-import-panel').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('import panel + preview: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));

  await page.uncheck('#ui-emails');
  await page.click('#ui-apply');
  await page.waitForFunction(() => !document.getElementById('ui-apply'), null, { timeout: 15000 });
  const users = await page.evaluate(() => GSM.api('/users'));
  const a = users.filter((u) => u.email === e1)[0], b = users.filter((u) => u.email === e2)[0];
  check('created with role + status', a && a.role_key === 'ahmat' && a.active === true && b && b.role_key === 'lab_manager' && b.active === false, JSON.stringify([a, b]));
  check('listed on the users screen', (await page.textContent('.gs-main')).indexOf(e1) > -1);

  // Wrong file type.
  await page.click('#gsm-user-import');
  await page.waitForSelector('#ui-file');
  await page.setInputFiles('#ui-file', { name: 'x.php', mimeType: 'text/plain', buffer: Buffer.from('<?php echo 1;') });
  await page.click('#ui-check');
  await page.waitForSelector('#ui-result [role="alert"]', { timeout: 8000 });
  check('other file types refused', /JSON, CSV, XLSX/.test(await page.textContent('#ui-result')));

  await page.evaluate(async (em) => { const l = await GSM.api('/users'); for (const u of l) if (em.indexOf(u.email) > -1) await GSM.api('/users/' + u.id, { method: 'PUT', body: { active: false } }).catch(() => null); }, [e1, e2]);
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
