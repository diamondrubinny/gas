// Keyboard + focus-management checks that automated scanners (axe) cannot make.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/keyboard-a11y.js
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const active = (p) => (p || page).evaluate(() => {
    const a = document.activeElement;
    return a ? { tag: a.tagName, id: a.id, cls: a.className, text: (a.textContent || '').trim().slice(0, 40), inDialog: !!a.closest('[role="dialog"]') } : null;
  });
  const tabUntil = async (pred, max, p) => {
    for (let i = 0; i < (max || 40); i++) {
      await (p || page).keyboard.press('Tab');
      if (await (p || page).evaluate(pred)) return true;
    }
    return false;
  };

  // Language (WCAG 3.1.1).
  await page.goto(BASE + '/gas-app/#/login');
  check('app <html lang="he-IL" dir="rtl">', await page.evaluate(() => document.documentElement.lang === 'he-IL' && document.documentElement.dir === 'rtl'),
    await page.evaluate(() => document.documentElement.outerHTML.slice(0, 80)));

  // Keyboard-only login.
  await page.waitForSelector('#gsm-lg-user');
  await page.focus('#gsm-lg-user');
  await page.keyboard.type(USER);
  await page.keyboard.press('Tab');
  await page.keyboard.type(PASS);
  check('login fields labelled', await page.evaluate(() => ['gsm-lg-user', 'gsm-lg-pass'].every((id) => document.getElementById(id).labels.length > 0)));
  await page.focus('#gsm-lg-submit');
  await page.keyboard.press('Enter');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
  check('keyboard login', true);

  // Skip link is the first Tab stop and moves focus to <main> (WCAG 2.4.1).
  await page.evaluate(() => GSM.navigate('/dashboard'));
  await page.waitForTimeout(800);
  await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
  await page.evaluate(() => document.body.focus());
  await page.keyboard.press('Tab');
  const first = await active();
  check('first Tab stop = skip link', first && /gs-skip/.test(first.cls), JSON.stringify(first));
  await page.keyboard.press('Enter');
  check('skip link focuses <main>', (await active()).id === 'gsm-main', JSON.stringify(await active()));

  // Screen change: title + focus on the new heading (WCAG 2.4.2 / 2.4.3).
  await page.evaluate(() => GSM.navigate('/faults'));
  await page.waitForFunction(() => /תקלות/.test(document.title), null, { timeout: 8000 }).catch(() => null);
  check('document.title follows the screen', /^תקלות \|/.test(await page.title()), await page.title());
  const h = await active();
  check('focus moves to the screen heading', h && /gs-h2/.test(h.cls) && /תקלות/.test(h.text), JSON.stringify(h));

  // Modal: opens with focus inside, Tab stays inside, Escape closes, focus returns.
  await page.focus('#gsm-ft-add');
  await page.keyboard.press('Enter');
  await page.waitForSelector('#gsm-modal [role="dialog"]', { timeout: 8000 });
  const dlg = await page.evaluate(() => {
    const d = document.querySelector('#gsm-modal [role="dialog"]');
    const t = d && document.getElementById(d.getAttribute('aria-labelledby'));
    return { modal: d && d.getAttribute('aria-modal'), title: t && t.textContent };
  });
  check('modal has role=dialog, aria-modal, aria-labelledby', dlg.modal === 'true' && !!dlg.title, JSON.stringify(dlg));
  check('focus moved into the modal', (await active()).inDialog);
  let trapped = true;
  for (let i = 0; i < 25; i++) { await page.keyboard.press('Tab'); if (!(await active()).inDialog) { trapped = false; break; } }
  for (let i = 0; i < 5 && trapped; i++) { await page.keyboard.press('Shift+Tab'); if (!(await active()).inDialog) trapped = false; }
  check('Tab / Shift+Tab stay inside the modal', trapped, JSON.stringify(await active()));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(200);
  check('Escape closes the modal', !(await page.$('#gsm-modal')));
  check('focus returns to the opening button', (await active()).id === 'gsm-ft-add', JSON.stringify(await active()));

  // Required fields are announced as required.
  await page.evaluate(() => GSM.navigate('/equipment'));
  await page.waitForSelector('#gsm-eq-add');
  await page.click('#gsm-eq-add');
  await page.waitForSelector('#eq-name');
  check('required field has aria-required', (await page.getAttribute('#eq-name', 'aria-required')) === 'true');
  await page.keyboard.press('Escape');

  // Attachment upload reachable by keyboard (was display:none).
  const faults = await page.evaluate(() => GSM.api('/faults?per_page=1'));
  if (faults && faults[0]) {
    await page.evaluate(() => GSM.navigate('/faults'));
    await page.waitForSelector('[data-fedit]');
    await page.click('[data-fedit]');
    await page.waitForSelector('.gsm-att-input', { timeout: 8000 });
    const reached = await tabUntil(() => document.activeElement && document.activeElement.classList.contains('gsm-att-input'), 60);
    check('attachment file input reachable with Tab', reached);
    await page.keyboard.press('Escape');
  } else {
    console.log('SKIP attachment keyboard check — no fault to edit');
  }

  // Status messages are live regions (WCAG 4.1.3).
  await page.evaluate(() => GSM.navigate('/comm'));
  await page.waitForSelector('#cm-status', { state: 'attached', timeout: 8000 });
  check('status message is a live region', await page.evaluate(() => {
    const e = document.getElementById('cm-status'); return e.getAttribute('role') === 'status' && e.getAttribute('aria-live') === 'polite';
  }));

  // Public QR page: language + keyboard-operable report dialog.
  const eq = await page.evaluate(() => GSM.api('/equipment?per_page=1'));
  const tok = eq && eq[0] && eq[0].qr_token;
  if (tok) {
    const bp = await ctx.newPage();
    await bp.goto(BASE + '/bridge/?eq=' + encodeURIComponent(tok));
    check('bridge <html lang="he-IL">', await bp.evaluate(() => document.documentElement.lang === 'he-IL'));
    await bp.waitForSelector('#gsm-br-fault', { timeout: 8000 });
    await bp.focus('#gsm-br-fault');
    await bp.keyboard.press('Enter');
    await bp.waitForSelector('[role="dialog"]');
    check('bridge dialog focuses the description', (await active(bp)).id === 'br-desc', JSON.stringify(await active(bp)));
    let inside = true;
    for (let i = 0; i < 12; i++) { await bp.keyboard.press('Tab'); const a = await active(bp); if (!a.inDialog || a.id === 'br-hp') { inside = false; break; } }
    check('bridge dialog traps Tab (and skips the honeypot)', inside, JSON.stringify(await active(bp)));
    await bp.keyboard.press('Escape');
    await bp.waitForTimeout(200);
    check('bridge Escape closes + returns focus', !(await bp.$('[role="dialog"]')) && (await active(bp)).id === 'gsm-br-fault', JSON.stringify(await active(bp)));
    await bp.close();
  }

  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
