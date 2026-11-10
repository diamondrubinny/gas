// Public QR API (GET /qr/{code}, no login): data minimisation + rate limits.
// Run LAST — it deliberately trips the per-IP rate limit.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/bridge-privacy.js
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

(async () => {
  const browser = await chromium.launch();
  const admin = await (await browser.newContext()).newPage();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };

  await admin.goto(BASE + '/gas-app/#/login');
  await admin.waitForSelector('#gsm-lg-user');
  await admin.fill('#gsm-lg-user', USER);
  await admin.fill('#gsm-lg-pass', PASS);
  await admin.click('#gsm-lg-submit');
  await admin.waitForSelector('.gs-header__logout', { timeout: 15000 });

  // Equipment with contact data.
  const eq = await admin.evaluate(() => GSM.api('/equipment', { method: 'POST', body: {
    name: 'Privacy test', building: 'B9', pi_name: 'PI Person', lab_managers: 'Lab Person', maint_staff: 'Maint Person', phone: '050-0000000'
  } }));
  const full = await admin.evaluate((id) => GSM.api('/equipment/' + id), eq.id);
  const tok = full.qr_token;
  const original = await admin.evaluate(() => GSM.api('/bridge-config'));

  // Anonymous client (no cookies).
  const anon = await (await browser.newContext()).newPage();
  await anon.goto(BASE + '/wp-login.php');
  const pub = (code) => anon.evaluate(async (c) => {
    const r = await fetch('/wp-json/gas/v1/qr/' + c, { credentials: 'omit' });
    return { status: r.status, body: r.ok ? await r.json() : null };
  }, code);

  let r = await pub(tok);
  check('default config: contacts shown (unchanged behaviour)', r.status === 200 && r.body.pi_name === 'PI Person' && r.body.phone === '050-0000000', JSON.stringify(r));
  check('API never returns emails / free-text contacts', r.body && !('maint_emails' in r.body) && !('contacts' in r.body) && !('notes' in r.body), Object.keys(r.body || {}).join(','));

  // Manager hides PI + phone on the public page -> API must not return them.
  await admin.evaluate((c) => GSM.api('/bridge-config', { method: 'POST', body: Object.assign({}, c, { showPI: false, showPhone: false }) }), original);
  r = await pub(tok);
  check('hidden PI is not returned by the API', r.status === 200 && r.body.pi_name === '', JSON.stringify(r.body));
  check('hidden phone is not returned by the API', r.body && r.body.phone === '', JSON.stringify(r.body));
  check('fields still enabled are returned', r.body && r.body.lab_managers === 'Lab Person' && r.body.maint_staff === 'Maint Person', JSON.stringify(r.body));
  await admin.evaluate((c) => GSM.api('/bridge-config', { method: 'POST', body: c }), original);

  // Enumeration guard: 20 misses per IP / 10 min, then 429 even for valid codes.
  const statuses = [];
  for (let i = 0; i < 21; i++) statuses.push((await pub(String(9000000000 + i))).status);
  check('misses return 404 until the cap', statuses.slice(0, 20).every((s) => s === 404), statuses.join(','));
  check('21st miss is rate-limited (429)', statuses[20] === 429, statuses.join(','));
  check('after the miss cap, valid codes are blocked too (429)', (await pub(tok)).status === 429);

  await admin.evaluate((id) => GSM.api('/equipment/' + id, { method: 'DELETE' }), eq.id);
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
