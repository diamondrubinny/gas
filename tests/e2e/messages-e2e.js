// Dashboard cards: field messages + feedback. A user sends a message and
// opens a feedback request; the manager sees the badges, marks the message
// read and moves the request; the user sees the new status.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/messages-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

async function login(page, u, p) {
  await page.goto(BASE + '/gas-app/?t=' + Date.now() + '#/login');
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
  const errors = [];
  const tag = 'MSG-' + Date.now().toString(36);
  const uEmail = 'msg.' + tag.toLowerCase() + '@example.test';
  const uPass = 'Pw-' + tag + '-Aa1!x';

  const mctx = await browser.newContext({ locale: 'he-IL' });
  const mgr = await mctx.newPage();
  mgr.on('pageerror', (e) => errors.push(e.message));
  await login(mgr, USER, PASS);
  const made = await mgr.evaluate((b) => GSM.api('/users', { method: 'POST', body: b }).then((r) => r, (e) => ({ err: e.message })), { name: 'Field ' + tag, email: uEmail, role: 'ahmat', password: uPass });
  check('setup: field user created', made && !made.err, JSON.stringify(made));

  const uctx = await browser.newContext({ locale: 'he-IL' });
  const up = await uctx.newPage();
  up.on('pageerror', (e) => errors.push(e.message));
  await login(up, uEmail, uPass);
  await up.evaluate(() => GSM.navigate('/dashboard'));
  await up.waitForSelector('#fm-send');
  await up.click('#fm-send');
  check('empty message: hint, nothing sent', /כתבו הודעה/.test(await up.textContent('#fm-st')));
  await up.fill('#fm-text', 'ריח גז במעבדה ' + tag + ' <b>x</b>');
  await up.click('#fm-send');
  await up.waitForSelector('.gs-msglist .gs-msg', { timeout: 8000 });
  check('user: message appears in own list as unread, HTML shown as text', /ריח גז במעבדה/.test(await up.textContent('#gsm-fm')) && /לא נקראה/.test(await up.textContent('#gsm-fm')) && await up.locator('#gsm-fm .gs-msg__tx b').count() === 0);
  check('user: no manager buttons', await up.locator('[data-fm-read], [data-fm-del], #fm-clear-all').count() === 0);

  await up.click('#fb-toggle');
  check('feedback form opens, toggle reports expanded, focus in the form', (await up.getAttribute('#fb-toggle', 'aria-expanded')) === 'true' && await up.evaluate(() => document.activeElement.id === 'fb-ttl'));
  let ax = await new AxeBuilder({ page: up }).include('#gsm-fm').include('#gsm-fb').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('user cards + open form: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
  await up.click('#fb-send');
  check('feedback without details: hint', /פירוט/.test(await up.textContent('#fb-st')));
  await up.fill('#fb-ttl', 'ייצוא ' + tag);
  await up.selectOption('#fb-type', 'feature');
  await up.selectOption('#fb-prio', 'high');
  await up.fill('#fb-text', 'להוסיף עמודה לדוח');
  await up.click('#fb-send');
  await up.waitForSelector('#gsm-fb .gs-msglist .gs-msg', { timeout: 8000 });
  check('user: feedback listed as open', /ייצוא/.test(await up.textContent('#gsm-fb')) && /פתוח/.test(await up.textContent('#gsm-fb')) && /בקשת שיפור/.test(await up.textContent('#gsm-fb')));

  // Manager.
  await mgr.evaluate(() => { GSM.navigate('/equipment'); });
  await mgr.evaluate(() => { GSM.navigate('/dashboard'); });
  const card = mgr.locator('#gsm-fm .gs-msg', { hasText: tag });
  await card.first().waitFor({ timeout: 8000 });
  check('manager: sees the message + "חדשות" badge', /חדשות/.test(await mgr.textContent('#fm-title')));
  ax = await new AxeBuilder({ page: mgr }).include('#gsm-fm').include('#gsm-fb').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('manager cards: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
  await card.first().locator('[data-fm-read]').click();
  await mgr.waitForFunction((t) => { const li = Array.from(document.querySelectorAll('#gsm-fm .gs-msg')).filter((x) => x.textContent.indexOf(t) > -1)[0]; return li && !li.querySelector('[data-fm-read]'); }, tag, { timeout: 8000 });
  check('manager: marked read, focus back on the card heading', await mgr.evaluate(() => document.activeElement.id === 'fm-title'));

  const fb = mgr.locator('#gsm-fb .gs-msg', { hasText: tag });
  await fb.first().waitFor({ timeout: 8000 });
  await fb.first().locator('select[data-fb-status]').selectOption('done');
  await mgr.waitForFunction(() => /הסטטוס עודכן/.test((document.getElementById('fb-st') || {}).textContent || ''), null, { timeout: 8000 });
  check('manager: status set to done; request leaves the "active" filter', await mgr.locator('#gsm-fb .gs-msg', { hasText: tag }).count() === 0);
  await mgr.click('[data-fb-filter="all"]');
  await mgr.locator('#gsm-fb .gs-msg', { hasText: tag }).first().waitFor({ timeout: 8000 });
  check('manager: "הכול" shows it as בוצע', /בוצע/.test(await mgr.locator('#gsm-fb .gs-msg', { hasText: tag }).first().textContent()));

  // User sees the result.
  await up.evaluate(() => { GSM.navigate('/profile'); });
  await up.evaluate(() => { GSM.navigate('/dashboard'); });
  await up.waitForSelector('#fb-toggle');
  await up.click('[data-fb-filter="all"]');
  await up.locator('#gsm-fb .gs-msg', { hasText: tag }).first().waitFor({ timeout: 8000 });
  check('user: sees "בוצע" and that the message was read', /בוצע/.test(await up.textContent('#gsm-fb')) && /נקראה/.test(await up.textContent('#gsm-fm')) && !/לא נקראה/.test(await up.textContent('#gsm-fm')));

  // Cleanup.
  mgr.on('dialog', (d) => d.accept());
  await mgr.locator('#gsm-fb .gs-msg', { hasText: tag }).first().locator('[data-fb-del]').click();
  await mgr.waitForFunction((t) => !Array.from(document.querySelectorAll('#gsm-fb .gs-msg')).some((x) => x.textContent.indexOf(t) > -1), tag, { timeout: 8000 });
  await mgr.locator('#gsm-fm .gs-msg', { hasText: tag }).first().locator('[data-fm-del]').click();
  await mgr.waitForFunction((t) => !Array.from(document.querySelectorAll('#gsm-fm .gs-msg')).some((x) => x.textContent.indexOf(t) > -1), tag, { timeout: 8000 });
  check('manager: deleted both', true);
  await mgr.evaluate(async (em) => { const l = await GSM.api('/users'); for (const u of l) if (u.email === em) await GSM.api('/users/' + u.id, { method: 'PUT', body: { active: false } }).catch(() => null); }, uEmail);
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
