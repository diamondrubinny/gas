// Features that close the gaps with the old system:
// named public-dashboard links, personal dashboard layout, SAP export,
// scan-statistics reset, accessibility menu, menu icons.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/gaps-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'he-IL' });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  const page = await ctx.newPage();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => d.accept());
  const axe = async (sel) => { const r = await new AxeBuilder({ page }).include(sel).withTags(TAGS).analyze(); return r.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','); };
  const tag = 'GAP-' + Date.now().toString(36);

  // ---- Accessibility menu on a public page (logged out) --------------------
  await page.goto(BASE + '/gas-app/#/');
  await page.waitForSelector('#gsm-a11y-btn');
  check('a11y button: accessible name, collapsed', (await page.getAttribute('#gsm-a11y-btn', 'aria-label')) === 'תפריט נגישות' && (await page.getAttribute('#gsm-a11y-btn', 'aria-expanded')) === 'false');
  await page.focus('#gsm-a11y-btn');
  await page.keyboard.press('Enter');
  check('opens by keyboard, focus moves into the menu', (await page.getAttribute('#gsm-a11y-btn', 'aria-expanded')) === 'true' && await page.evaluate(() => document.activeElement.getAttribute('data-a11y') === 'text'));
  let v = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  check('page with the menu open: 0 axe violations', v.violations.length === 0, v.violations.map((x) => x.id + ':' + x.nodes[0].target.join(' ')).join(','));
  await page.keyboard.press('Enter');
  check('text size: one step larger', await page.evaluate(() => document.documentElement.classList.contains('gsm-a11y-text-1')));
  for (const k of ['contrast', 'links', 'font', 'motion']) await page.click('[data-a11y="' + k + '"]');
  check('toggles set classes + aria-pressed', await page.evaluate(() => ['contrast', 'links', 'font', 'motion'].every((k) => document.documentElement.classList.contains('gsm-a11y-' + k) && document.querySelector('[data-a11y="' + k + '"]').getAttribute('aria-pressed') === 'true')));
  v = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  check('all adjustments on: 0 axe violations', v.violations.length === 0, v.violations.map((x) => x.id + ':' + x.nodes[0].target.join(' ')).join(','));
  const pos = await page.evaluate(() => { const r = document.getElementById('gsm-a11y-btn').getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight + 1 && r.left >= 0 && r.right <= window.innerWidth + 1; });
  check('button stays inside the viewport with larger text', pos);
  await page.keyboard.press('Escape');
  check('Escape closes and returns focus to the button', await page.evaluate(() => document.getElementById('gsm-a11y-panel').hidden && document.activeElement.id === 'gsm-a11y-btn'));
  await page.reload();
  await page.waitForSelector('#gsm-a11y-btn');
  check('choices persist after reload (this browser only)', await page.evaluate(() => document.documentElement.classList.contains('gsm-a11y-contrast') && document.documentElement.classList.contains('gsm-a11y-text-1')));
  await page.click('#gsm-a11y-btn');
  await page.click('[data-a11y="reset"]');
  check('reset clears everything', await page.evaluate(() => !/gsm-a11y-(text|contrast|links|font|motion)/.test(document.documentElement.className) && JSON.parse(localStorage.getItem('gsm_a11y')).text === 0));
  await page.keyboard.press('Escape');
  const bp = await ctx.newPage();
  await bp.goto(BASE + '/hello-world/');
  check('menu is on regular site pages too', await bp.locator('#gsm-a11y-btn').count() === 1);
  await bp.close();

  // ---- Log in ----------------------------------------------------------------
  await page.goto(BASE + '/gas-app/?t=1#/login');
  await page.waitForSelector('#gsm-lg-user');
  await page.fill('#gsm-lg-user', USER);
  await page.fill('#gsm-lg-pass', PASS);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
  check('menu groups have decorative icons hidden from assistive tech', await page.locator('.gs-navgrp__btn svg.gs-navgrp__ico[aria-hidden="true"]').count() >= 4);

  // ---- Personal dashboard layout ------------------------------------------------
  await page.evaluate(() => GSM.api('/dashboard/layout', { method: 'POST', body: { order: [], hidden: [] } }));
  await page.evaluate(() => GSM.navigate('/dashboard'));
  await page.waitForSelector('#dash-custom');
  const before = await page.$$eval('section[data-dash]', (l) => l.map((x) => x.getAttribute('data-dash')));
  check('default order shows all 6 sections', before.join() === 'stats,coverage,messages,feedback,reports,deadlines', before.join());
  await page.click('#dash-custom');
  await page.waitForSelector('#dash-custom-list li');
  check('layout panel: expanded state + focus on its heading', (await page.getAttribute('#dash-custom', 'aria-expanded')) === 'true' && await page.evaluate(() => document.activeElement.id === 'dash-custom-title'));
  check('layout panel: 0 axe violations', (await axe('#dash-custom-panel')) === '', await axe('#dash-custom-panel'));
  await page.locator('#dash-custom-list li[data-id="deadlines"] .dash-up').click();
  await page.locator('#dash-custom-list li[data-id="deadlines"] .dash-up').click();
  check('move button keeps keyboard focus', await page.evaluate(() => document.activeElement.classList.contains('dash-up')));
  await page.locator('#dash-custom-list li[data-id="reports"] .dash-vis').uncheck();
  await page.click('#dash-custom-save');
  await page.waitForFunction(() => document.querySelectorAll('section[data-dash]').length === 5, null, { timeout: 8000 });
  const after = await page.$$eval('section[data-dash]', (l) => l.map((x) => x.getAttribute('data-dash')));
  check('saved: reports hidden, deadlines moved up', after.join() === 'stats,coverage,messages,deadlines,feedback', after.join());
  await page.reload();
  await page.waitForSelector('section[data-dash]');
  check('layout persists after reload', (await page.$$eval('section[data-dash]', (l) => l.map((x) => x.getAttribute('data-dash')))).join() === after.join());
  const bad = await page.evaluate(() => GSM.api('/dashboard/layout', { method: 'POST', body: { order: ['<script>', 'stats', 'stats', 'nope'], hidden: ['x', 'coverage'] } }));
  check('server keeps known sections only, each once', bad.order.length === 6 && bad.order[0] === 'stats' && bad.hidden.join() === 'coverage', JSON.stringify(bad));
  await page.click('#dash-custom');
  await page.click('#dash-custom-reset');
  await page.waitForFunction(() => document.querySelectorAll('section[data-dash]').length === 6, null, { timeout: 8000 });
  check('reset restores the default', (await page.$$eval('section[data-dash]', (l) => l.map((x) => x.getAttribute('data-dash')))).join() === before.join());

  // ---- Named public links ----------------------------------------------------------
  await page.evaluate(() => GSM.navigate('/comm'));
  await page.waitForSelector('#pl-add', { timeout: 10000 });
  await page.click('#pl-add');
  check('link without a name: hint', /תנו שם/.test(await page.textContent('#pl-st')));
  await page.fill('#pl-label', 'קישור ' + tag);
  await page.click('#pl-add');
  const row = page.locator('#pub-links tr', { hasText: tag });
  await row.first().waitFor({ timeout: 8000 });
  check('link created, focus on the section heading', await page.evaluate(() => document.activeElement.id === 'pl-title'));
  check('public links section: 0 axe violations', (await axe('#pub-links')) === '', await axe('#pub-links'));
  await row.first().locator('[data-pl-copy]').click();
  await page.waitForTimeout(300);
  const url = await page.evaluate(() => navigator.clipboard.readText());
  check('copy gives a dashboard URL with the token in the fragment', /gas-public-dashboard\.html\?api=.*#t=[a-f0-9]{32}$/.test(url), url);
  const dp = await ctx.newPage();
  await dp.goto(url);
  const tile = await dp.waitForSelector('.tile', { timeout: 10000 }).catch(() => null);
  check('the named link opens the public dashboard', !!tile);
  await dp.close();
  await page.evaluate(() => { GSM.navigate('/profile'); });
  await page.evaluate(() => { GSM.navigate('/comm'); });
  await page.locator('#pub-links tr', { hasText: tag }).first().waitFor({ timeout: 8000 });
  const cells = await page.locator('#pub-links tr', { hasText: tag }).first().locator('td').allTextContents();
  check('view counted for that link', parseInt(cells[1], 10) >= 1, cells.join(' | '));
  await page.locator('#pub-links tr', { hasText: tag }).first().locator('[data-pl-del]').click();
  await page.waitForFunction((t) => !Array.from(document.querySelectorAll('#pub-links tr')).some((x) => x.textContent.indexOf(t) > -1), tag, { timeout: 8000 });
  const st = await page.evaluate((u) => { const m = u.match(/api=([^#]+)#t=(.+)$/); return fetch(decodeURIComponent(m[1]) + '/bi/public', { headers: { 'X-GSM-Token': m[2] } }).then((r) => r.status); }, url);
  check('revoked link stops working (403)', st === 403, String(st));

  // ---- SAP export ---------------------------------------------------------------------
  await page.evaluate(() => GSM.navigate('/integrations'));
  await page.waitForSelector('.gs-main details');
  check('SAP links listed on the integrations screen', /format=sap/.test(await page.content()));
  const sap = await page.evaluate(async () => {
    const tok = (await GSM.api('/bi/token')).token;
    const r = await fetch(GSM.restUrl('/bi/data/equipment?format=sap'), { headers: { 'X-GSM-Token': tok } });
    const buf = new Uint8Array(await r.arrayBuffer());
    const text = new TextDecoder('utf-8', { ignoreBOM: true }).decode(buf);
    const lines = text.split('\r\n');
    return { status: r.status, bom: buf[0] === 0xEF && buf[1] === 0xBB && buf[2] === 0xBF, head: lines[0].slice(1, 120), crlf: lines.length > 1, ct: r.headers.get('content-type'), cd: r.headers.get('content-disposition') };
  });
  check('SAP export: UTF-8 BOM, CRLF, semicolon-separated header', sap.status === 200 && sap.bom && sap.crlf && /^"id";"name";/.test(sap.head) && !/^"id","name"/.test(sap.head), JSON.stringify(sap));
  check('SAP export: csv content type + -sap file name', /text\/csv/.test(sap.ct) && /gas-equipment-sap\.csv/.test(sap.cd));
  const csv = await page.evaluate(async () => { const tok = (await GSM.api('/bi/token')).token; const r = await fetch(GSM.restUrl('/bi/data/equipment?format=csv'), { headers: { 'X-GSM-Token': tok } }); return (await r.text()).slice(0, 40); });
  check('regular CSV still comma-separated', /"id","name"/.test(csv), csv);

  // ---- Scan statistics reset -------------------------------------------------------------
  const eqId = await page.evaluate(async (t) => (await GSM.api('/equipment', { method: 'POST', body: { name: 'Scan ' + t } })).id, tag);
  const tok = await page.evaluate(async (i) => (await GSM.api('/equipment/' + i)).qr_token, eqId);
  const sp = await ctx.newPage();
  await sp.goto(BASE + '/bridge/?eq=' + tok);
  await sp.waitForTimeout(1500);
  await sp.close();
  const scansBefore = await page.evaluate(async () => (await GSM.api('/dashboard')).coverage.scanned_30d);
  check('a scan was recorded', scansBefore >= 1, String(scansBefore));
  const noConfirm = await page.evaluate(() => GSM.api('/retention/reset-scans', { method: 'POST', body: {} }).then(() => 'ok', (e) => e.status));
  check('reset without the confirmation word is refused (400)', noConfirm === 400, String(noConfirm));
  await page.evaluate(() => GSM.navigate('/admin'));
  await page.waitForSelector('#scan-reset', { timeout: 10000 });
  await page.click('#scan-reset');
  await page.waitForFunction(() => /נמחקו \d+ רשומות/.test(document.getElementById('scan-reset-st').textContent), null, { timeout: 8000 });
  const cov = await page.evaluate(async () => (await GSM.api('/dashboard')).coverage);
  check('after reset: no scans in the dashboard', cov.scanned_30d === 0, JSON.stringify(cov));
  check('equipment untouched by the reset', await page.evaluate(async (i) => !!(await GSM.api('/equipment/' + i)).id, eqId));
  const aud = await page.evaluate(async () => { const a = await GSM.api('/audit'); const l = a.items || a; return (l[0] && l[0].summary) || ''; });
  check('reset written to the audit log', /אופסה סטטיסטיקת הסריקות/.test(aud), aud);
  await page.evaluate((i) => GSM.api('/equipment/' + i, { method: 'DELETE' }), eqId);

  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
