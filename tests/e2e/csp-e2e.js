// End-to-end CSP + delegated-actions test against a running WordPress.
// Run: BASE=http://127.0.0.1:8899 USER=admin PASS=... node tests/e2e/csp-e2e.js
// Fails on: any CSP violation, any uncaught JS error, a missing CSP header,
// or a converted button (data-gsm-nav / data-gsm-action) that does nothing.
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;
const SCREENS = ['dashboard', 'bi', 'reports', 'audit', 'myequip', 'quality', 'equipment', 'contacts', 'panels', 'import',
  'faults', 'inspections', 'inspcfg', 'qr', 'qrissue', 'profile', 'security', 'users', 'brsetup', 'database',
  'restorepts', 'comm', 'integrations', 'admin', 'help'];

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const violations = [], errors = [];
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };

  await page.exposeFunction('__cspReport', (v) => violations.push(v));
  await ctx.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (e) => {
      window.__cspReport && window.__cspReport(e.violatedDirective + ' ' + (e.blockedURI || '') + ' @' + (e.sourceFile || '') + ':' + e.lineNumber);
    });
  });
  page.on('pageerror', (e) => errors.push(e.message));
  // Privacy: the app must not contact any third-party host (fonts, CDNs, ...).
  const baseOrigin = new URL(BASE).origin;
  const thirdParty = [];
  ctx.on('request', (r) => {
    const u = r.url();
    if (/^(data|blob|about):/.test(u)) return;
    try { if (new URL(u).origin !== baseOrigin) thirdParty.push(u); } catch (e) { /* ignore */ }
  });
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push('console: ' + m.text()); });

  // 1. Header present on the app page.
  const resp = await page.goto(BASE + '/gas-app/');
  const csp = resp.headers()['content-security-policy'] || '';
  check('CSP header on app page', /script-src 'self' 'nonce-/.test(csp) && !/unsafe-inline[^;]*;?\s*$/.test(csp.split(';').find((d) => d.trim().startsWith('script-src')) || ''), csp);
  check("script-src has no 'unsafe-inline'/'unsafe-eval'", !/script-src[^;]*unsafe/.test(csp));

  // 2. Home -> login via delegated nav buttons.
  await page.waitForSelector('[data-gsm-nav="/login"]', { timeout: 10000 });
  await page.click('[data-gsm-nav="/login"]');
  await page.waitForSelector('#gsm-lg-user');
  check('home -> login (data-gsm-nav)', page.url().includes('#/login'));
  await page.click('[data-gsm-nav="/"]');
  await page.waitForSelector('[data-gsm-nav="/login"]');
  check('login -> home (data-gsm-nav)', /#\/?$/.test(page.url()));
  await page.click('[data-gsm-nav="/login"]');
  await page.fill('#gsm-lg-user', USER);
  await page.fill('#gsm-lg-pass', PASS);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
  check('login works under CSP', true);

  // Bundled Rubik font is loaded and actually applied.
  const font = await page.evaluate(async () => {
    await document.fonts.ready;
    await document.fonts.load('600 16px Rubik', 'אבג abc');
    const faces = []; document.fonts.forEach((f) => faces.push(f.family.replace(/["']/g, '') + ':' + f.status));
    return { ok: document.fonts.check('600 16px Rubik', 'אבג'), faces: faces,
      family: getComputedStyle(document.querySelector('.gs-header__title') || document.body).fontFamily };
  });
  check('Rubik font loaded locally (Hebrew)', font.ok && font.faces.some((f) => f === 'Rubik:loaded'), JSON.stringify(font));
  check('UI uses Rubik', /Rubik/.test(font.family), font.family);

  // 3. Seed data through the app's own API (same nonce/session).
  const seeded = await page.evaluate(async () => {
    const eq = await GSM.api('/equipment', { method: 'POST', body: { name: 'E2E-1 <img src=x onerror="window.__pwned=1">', building: 'B1', room: '101'} });
    const rp = await GSM.api('/restore-points', { method: 'POST', body: { label: 'e2e' } });
    return { eq: eq && (eq.id || (eq.item && eq.item.id)), rp: !!rp };
  });
  check('seed equipment + restore point', !!seeded.eq, JSON.stringify(seeded));

  // 3b. Attachments: upload -> list -> download -> delete (file must leave disk).
  const att = await page.evaluate(async (eqId) => {
    const c = document.createElement('canvas'); c.width = 8; c.height = 8;
    c.getContext('2d').fillRect(0, 0, 8, 8);
    const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
    const nonce = (GSM.restNonce && GSM.restNonce()) || (window.GSM_CONFIG || {}).nonce;
    const root = GSM_CONFIG.restRoot;
    const url = (p) => root + (root.indexOf('?') > -1 ? p.replace('?', '&') : p);
    const fd = new FormData();
    fd.append('file', blob, 'e2e.png'); fd.append('entity', 'equipment'); fd.append('entity_id', String(eqId));
    const up = await fetch(url('/attachments'), { method: 'POST', body: fd, headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' });
    const upJ = await up.json();
    const list = await GSM.api('/attachments/list/equipment/' + eqId);
    const id = list && list[0] && list[0].id;
    const dl = id ? await fetch(url('/attachments/' + id + '?_wpnonce=' + encodeURIComponent(nonce)), { credentials: 'same-origin' }) : null;
    const res = { up: up.status, upMsg: upJ && upJ.message, count: (list || []).length, id: id,
      dl: dl ? dl.status : 0, type: dl ? dl.headers.get('content-type') : '', nosniff: dl ? dl.headers.get('x-content-type-options') : '' };
    // Hostile upload: HTML disguised as PNG must be rejected.
    const fd2 = new FormData();
    fd2.append('file', new Blob(['<script>alert(1)</script>'], { type: 'image/png' }), 'evil.png');
    fd2.append('entity', 'equipment'); fd2.append('entity_id', String(eqId));
    res.evil = (await fetch(url('/attachments'), { method: 'POST', body: fd2, headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' })).status;
    return res;
  }, seeded.eq);
  check('attachment upload', att.up >= 200 && att.up < 300 && att.count === 1, JSON.stringify(att));
  check('attachment download (image/png, nosniff)', att.dl === 200 && /image\/png/.test(att.type) && att.nosniff === 'nosniff', JSON.stringify(att));
  check('disguised HTML upload rejected', att.evil >= 400, 'status=' + att.evil);
  let diskBefore = null;
  const fs = require('fs'), pathMod = require('path');
  const privDir = process.env.WP_PATH ? pathMod.join(process.env.WP_PATH, 'wp-content/uploads/gas-private') : null;
  const countFiles = (d) => { let n = 0; if (!d || !fs.existsSync(d)) return 0; for (const e of fs.readdirSync(d, { withFileTypes: true })) { const f = pathMod.join(d, e.name); if (e.isDirectory()) n += countFiles(f); else if (!/^(index\.php|\.htaccess|web\.config)$/.test(e.name)) n++; } return n; };
  if (privDir) diskBefore = countFiles(privDir);
  const del = await page.evaluate(async (id) => { try { await GSM.api('/attachments/' + id, { method: 'DELETE' }); return 'ok'; } catch (e) { return e.message; } }, att.id);
  const after = await page.evaluate((eqId) => GSM.api('/attachments/list/equipment/' + eqId), seeded.eq);
  check('attachment delete', del === 'ok' && (after || []).length === 0, del);
  if (privDir) check('deleted attachment removed from disk', countFiles(privDir) === diskBefore - 1, 'before=' + diskBefore + ' after=' + countFiles(privDir));

  // 4. Visit every screen.
  for (const s of SCREENS) {
    await page.evaluate((r) => GSM.navigate('/' + r), s);
    await page.waitForTimeout(700);
    const err = await page.$('.gs-main .gs-error');
    const txt = err ? await err.textContent() : '';
    check('screen #/' + s + ' renders', !err, txt);
  }

  // 4b. Bundled libraries: Chart.js 4.6.1 draws the BI charts.
  await page.evaluate(() => GSM.navigate('/bi'));
  await page.waitForSelector('#bi-status', { timeout: 8000 }).catch(() => null);
  await page.waitForTimeout(600);
  const charts = await page.evaluate(() => ({ v: window.Chart && window.Chart.version, n: Array.from(document.querySelectorAll('canvas')).filter((c) => window.Chart && window.Chart.getChart(c)).length }));
  check('Chart.js 4.6.1 renders the BI charts', charts.v === '4.6.1' && charts.n >= 1, JSON.stringify(charts));

  // 5. Grouped nav: toggle + navigate through data attributes.
  await page.evaluate(() => GSM.navigate('/dashboard'));
  await page.waitForSelector('[data-gsm-action="navToggle"]');
  await page.click('.gs-navgrp:nth-child(2) [data-gsm-action="navToggle"]');
  check('navToggle opens group', await page.$eval('.gs-navgrp:nth-child(2)', (g) => g.classList.contains('open')));
  await page.click('.gs-navgrp:nth-child(2).open [data-gsm-nav="/equipment"]');
  await page.waitForTimeout(600);
  check('nav item navigates', page.url().includes('#/equipment'));

  // 6. XSS payload in equipment name is rendered as text.
  check('stored payload not executed', !(await page.evaluate(() => window.__pwned === 1)));

  // 7. Equipment detail + back button.
  await page.evaluate((id) => GSM.navigate('/equipment/' + id), seeded.eq);
  await page.waitForSelector('.gs-main [data-gsm-nav="/equipment"]');
  const qrImg = await page.$eval('#gsm-qr-canvas img', (i) => ({ src: i.src.slice(0, 22), alt: i.alt, w: i.naturalWidth })).catch(() => null);
  check('equipment QR = one PNG <img> with alt text (qrcode-generator)', !!qrImg && qrImg.src === 'data:image/png;base64,' && /קוד QR/.test(qrImg.alt) && qrImg.w > 100, JSON.stringify(qrImg));
  await page.click('.gs-main [data-gsm-nav="/equipment"]');
  await page.waitForTimeout(500);
  check('detail back button', /#\/equipment$/.test(page.url()));
  await page.evaluate(() => { location.hash = '/equipment/abc?x=1'; });
  await page.waitForTimeout(500);
  check('non-numeric equipment id rejected', /#\/equipment$/.test(page.url()));

  // 8. Export button (data-gsm-action="export") triggers a download.
  const [dl] = await Promise.all([
    page.waitForEvent('download', { timeout: 15000 }).catch(() => null),
    page.click('[data-gsm-action="export"][data-type="equipment"]')
  ]);
  check('export button downloads', !!dl);

  // 9. Readonly link inputs select on click.
  await page.evaluate(() => GSM.navigate('/integrations'));
  await page.waitForSelector('#tok-val');
  await page.click('#tok-val');
  check('data-gsm-action=select selects text', await page.$eval('#tok-val', (i) => i.selectionEnd - i.selectionStart === i.value.length && i.value.length > 0));

  // 10. Restore-point buttons.
  await page.evaluate(() => GSM.navigate('/restorepts'));
  await page.waitForSelector('[data-gsm-action="rpDownload"]');
  const [rpdl] = await Promise.all([
    page.waitForEvent('download', { timeout: 15000 }).catch(() => null),
    page.click('[data-gsm-action="rpDownload"]')
  ]);
  check('rpDownload works', !!rpdl);
  page.once('dialog', (d) => d.dismiss());
  await page.click('[data-gsm-action="rpDelete"]');
  check('rpDelete asks confirmation (dismissed)', (await page.$$('[data-gsm-action="rpDelete"]')).length > 0);

  // 11. Delete equipment -> audit undo button.
  await page.evaluate((id) => GSM.api('/equipment/' + id, { method: 'DELETE' }), seeded.eq);
  await page.evaluate(() => GSM.navigate('/audit'));
  await page.waitForSelector('[data-gsm-action="auditUndo"]', { timeout: 8000 }).catch(() => null);
  const undoBtn = await page.$('[data-gsm-action="auditUndo"]');
  check('audit undo button present', !!undoBtn);
  if (undoBtn) {
    page.once('dialog', (d) => d.accept());
    await undoBtn.click();
    await page.waitForTimeout(1200);
    check('audit undo restores', await page.evaluate(async () => (await GSM.api('/equipment?per_page=200')).length > 0));
  }

  // 12-13. Print popups inherit the CSP, so they must not rely on inline
  // script: the opener triggers print(). Instrument window.open to count calls.
  const instrument = () => page.evaluate(() => {
    if (window.__openWrapped) return;
    window.__openWrapped = true;
    const o = window.open;
    window.open = function () {
      const w = o.apply(this, arguments);
      if (w) { w.__printed = 0; w.print = function () { w.__printed++; }; window.__lastPopup = w; }
      return w;
    };
  });
  const printedCount = () => page.evaluate(() => (window.__lastPopup ? window.__lastPopup.__printed : -1));

  const eqList = await page.evaluate(() => GSM.api('/equipment?per_page=5'));
  if (eqList && eqList[0]) {
    await page.evaluate((id) => GSM.navigate('/equipment/' + id), eqList[0].id);
    await page.waitForSelector('#gsm-qr-print', { timeout: 8000 }).catch(() => null);
    const qrBtn = await page.$('#gsm-qr-print');
    if (qrBtn) {
      await instrument();
      const [popup] = await Promise.all([page.waitForEvent('popup'), qrBtn.click()]);
      await page.waitForTimeout(1500);
      check('QR print popup auto-prints via opener', (await printedCount()) === 1, 'printed=' + (await printedCount()));
      check('QR popup has no inline <script>', (await popup.$$('script')).length === 0);
      await popup.close();
    } else { check('QR print button present', false); }
  }

  await page.evaluate(() => GSM.navigate('/qr'));
  await page.waitForSelector('.gsm-qr-cb:not([disabled])', { timeout: 8000 }).catch(() => null);
  const cb = await page.$('.gsm-qr-cb:not([disabled])');
  if (cb) {
    await instrument();
    await cb.check();
    const [sheet] = await Promise.all([page.waitForEvent('popup'), page.click('#gsm-qr-make')]);
    await sheet.waitForSelector('#gsm-print-now');
    await page.waitForTimeout(1500);
    check('sticker sheet auto-prints via opener', (await printedCount()) === 1, 'printed=' + (await printedCount()));
    await sheet.click('#gsm-print-now');
    check('sticker sheet print button works', (await printedCount()) === 2, 'printed=' + (await printedCount()));
    check('sticker popup has no inline <script>', (await sheet.$$('script')).length === 0);
    check('sticker popup uses local Rubik', await sheet.evaluate(async () => { await document.fonts.load('800 16px Rubik', 'G-0001'); return document.fonts.check('800 16px Rubik', 'G-0001'); }));
    await sheet.close();
  } else { check('sticker checkbox present', false); }

  // 14. CSP actually blocks injected inline script / handlers.
  const before = violations.length, errBefore = errors.length;
  await page.evaluate(() => {
    const d = document.createElement('div');
    d.innerHTML = '<img src="data:," onerror="window.__inj=1"><button id="x-inj" onclick="window.__inj=2">x</button>';
    document.body.appendChild(d);
    const s = document.createElement('script'); s.textContent = 'window.__inj=3'; document.body.appendChild(s);
    document.getElementById('x-inj').click();
  });
  await page.waitForTimeout(400);
  check('injected inline handlers/scripts blocked', !(await page.evaluate(() => window.__inj)) && violations.length > before);
  const injected = violations.length - before;
  violations.splice(before, injected); // expected ones
  errors.splice(errBefore, errors.length - errBefore); // the matching "violates CSP" console lines

  // 14b. Public dashboard (static page, meta CSP) via the real share link.
  await page.evaluate(() => GSM.navigate('/comm'));
  await page.waitForSelector('#pub-url', { timeout: 8000 });
  const shareUrl = await page.$eval('#pub-url', (i) => i.value);
  const biTok = await page.evaluate(async () => (await GSM.api('/bi/token')).token);
  const share = new URL(shareUrl);
  const pubTok = new URLSearchParams(share.hash.replace(/^#/, '')).get('t') || '';
  check('share link: token in #fragment, not in ?query', !!pubTok && !share.searchParams.has('t') && !share.searchParams.has('token'), shareUrl);
  const dash = await ctx.newPage();
  const dashViol = [], dashReqs = [];
  dash.on('console', (m) => { if (/Content Security Policy/.test(m.text())) dashViol.push(m.text()); });
  dash.on('request', (r) => dashReqs.push({ url: r.url(), header: r.headers()['x-gsm-token'] || '' }));
  await dash.goto(shareUrl);
  await dash.waitForSelector('.tile', { timeout: 8000 }).catch(() => null);
  check('public dashboard renders from share link', !!(await dash.$('.tile')));
  check('public dashboard: no CSP violations', dashViol.length === 0, dashViol.join(' | '));
  check('share link does not carry the BI export token', !shareUrl.includes(biTok));
  check('no dashboard request has the token in its URL', !dashReqs.some((r) => r.url.includes(pubTok)), dashReqs.filter((r) => r.url.includes(pubTok)).map((r) => r.url).join(' | '));
  check('dashboard sends the token in X-GSM-Token', dashReqs.some((r) => /\/bi\/public/.test(r.url) && r.header === pubTok));
  const st = await dash.evaluate(async (a) => {
    const f = (url, tok) => fetch(url, tok ? { headers: { 'X-GSM-Token': tok } } : {}).then((r) => r.status);
    return {
      pubQuery: await f('/wp-json/gas/v1/bi/data/equipment?format=json&token=' + encodeURIComponent(a.pub)),
      pubHeader: await f('/wp-json/gas/v1/bi/data/equipment?format=json', a.pub),
      biHeader: await f('/wp-json/gas/v1/bi/data/equipment?format=json', a.bi),
      biQuery: await f('/wp-json/gas/v1/bi/data/equipment?format=json&token=' + encodeURIComponent(a.bi)),
      icalQuery: await f('/wp-json/gas/v1/bi/ical?token=' + encodeURIComponent(a.bi))
    };
  }, { pub: pubTok, bi: biTok });
  check('public token cannot read /bi/data (query 403, header 403)', st.pubQuery === 403 && st.pubHeader === 403, JSON.stringify(st));
  check('BI token works in X-GSM-Token header (200)', st.biHeader === 200, JSON.stringify(st));
  check('BI token still works in ?token= (back-compat) + iCal (200)', st.biQuery === 200 && st.icalQuery === 200, JSON.stringify(st));
  // Old-style link (?t=…) still opens and is rewritten to #t=… (no token in the address bar query).
  const legacy = share.origin + share.pathname + '?t=' + encodeURIComponent(pubTok) + '&api=' + encodeURIComponent(share.searchParams.get('api') || '');
  await dash.goto(legacy);
  await dash.waitForSelector('.tile', { timeout: 8000 }).catch(() => null);
  const legacyAfter = new URL(await dash.evaluate(() => location.href));
  check('legacy ?t= link works and is moved to #t=', !!(await dash.$('.tile')) && !legacyAfter.searchParams.has('t') && legacyAfter.hash.indexOf('t=') === 1, legacyAfter.href);
  await dash.close();

  // 14c. Data-retention card (הגדרות אתר): defaults shown, save round-trips.
  await page.evaluate(() => GSM.navigate('/admin'));
  await page.waitForSelector('#ret-scans', { timeout: 8000 }).catch(() => null);
  const retVals = await page.evaluate(() => ['scans', 'comm', 'audit', 'deletions'].map((k) => (document.getElementById('ret-' + k) || {}).value));
  check('retention card shows conservative defaults 24/24/84/24', retVals.join('/') === '24/24/84/24', retVals.join('/'));
  await page.fill('#ret-scans', '25');
  await page.click('#ret-save');
  await page.waitForFunction(() => /נשמר/.test((document.getElementById('ret-status') || {}).textContent || ''), null, { timeout: 8000 }).catch(() => null);
  const saved = await page.evaluate(() => GSM.api('/retention'));
  check('retention save persists', saved.config && saved.config.scans === 25, JSON.stringify(saved.config));
  await page.evaluate(() => GSM.api('/retention', { method: 'POST', body: { config: { scans: 24 } } }));
  check('retention grace period shown (not active yet, start date d/m/Y)', saved.active === false && /^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/.test(saved.active_from || ''), JSON.stringify({ active: saved.active, from: saved.active_from }));

  // 15. Logout via delegated action.
  await page.evaluate(() => GSM.navigate('/dashboard'));
  await page.waitForSelector('[data-gsm-action="logout"]');
  await page.click('[data-gsm-action="logout"]');
  await page.waitForSelector('[data-gsm-nav="/login"]', { timeout: 10000 }).catch(() => null);
  check('logout works', !!(await page.$('[data-gsm-nav="/login"]')));

  // 16. Public bridge page.
  const tok = eqList && eqList[0] && eqList[0].qr_token;
  const br = await page.goto(BASE + '/bridge/' + (tok ? '?eq=' + encodeURIComponent(tok) : ''));
  check('CSP header on bridge page', !!(br.headers()['content-security-policy']));
  await page.waitForTimeout(1500);
  if (tok) {
    const fb = await page.$('#gsm-br-fault');
    if (fb) {
      await fb.click();
      await page.waitForSelector('#br-desc');
      await page.fill('#br-desc', '=HYPERLINK("http://evil","x") e2e');
      await page.waitForTimeout(2300); // server time-trap rejects submits < 2s after opening
      const submit = await page.$('#br-send');
      if (submit) { await submit.click(); }
      const done = await page.waitForSelector('#br-done', { timeout: 8000 }).catch(() => null);
      const brErr = done ? '' : await page.evaluate(() => (document.getElementById('br-err') || {}).textContent || 'no #br-err');
      if (!done && /יותר מדי דיווחים/.test(brErr)) {
        console.log('SKIP bridge fault report — per-IP rate limit (5 / 10 min) reached by earlier runs');
      } else {
        check('bridge fault report + close button', !!done, brErr);
      }
      if (done) { await done.click(); check('bridge close removes modal', !(await page.$('.gs-modal-backdrop'))); }
    }
  }

  check('no CSP violations during normal use', violations.length === 0, violations.slice(0, 10).join(' | '));
  check('no third-party requests (privacy)', thirdParty.length === 0, Array.from(new Set(thirdParty)).slice(0, 10).join(' | '));
  check('no uncaught JS errors', errors.length === 0, errors.slice(0, 10).join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
