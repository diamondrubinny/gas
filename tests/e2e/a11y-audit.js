// Accessibility audit (axe-core, WCAG 2.0/2.1 A + AA) against a running test site.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/a11y-audit.js [--report out.json]
// Exit code 1 when any violation is found (CI gate). Automated checks catch
// only part of WCAG — manual keyboard / screen-reader testing is still needed.
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const fs = require('fs');

const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;
const reportIdx = process.argv.indexOf('--report');
const REPORT = reportIdx > -1 ? process.argv[reportIdx + 1] : null;
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const SCREENS = ['dashboard', 'bi', 'reports', 'audit', 'myequip', 'quality', 'equipment', 'contacts', 'panels', 'import',
  'faults', 'inspections', 'inspcfg', 'qr', 'qrissue', 'profile', 'security', 'users', 'brsetup', 'database',
  'restorepts', 'legacy', 'privacy', 'comm', 'integrations', 'admin', 'help'];

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'he-IL' });
  const page = await ctx.newPage();
  const results = [];

  async function scan(name, p) {
    const target = p || page;
    await target.waitForTimeout(400);
    const r = await new AxeBuilder({ page: target }).withTags(TAGS).analyze();
    results.push({ name, violations: r.violations.map((v) => ({
      id: v.id, impact: v.impact, help: v.help, helpUrl: v.helpUrl,
      nodes: v.nodes.map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 200), summary: n.failureSummary }))
    })) });
  }

  // Public pages.
  await page.goto(BASE + '/gas-app/#/');
  await page.waitForSelector('.gs-home__title');
  await scan('home');
  await page.goto(BASE + '/gas-app/#/login');
  await page.waitForSelector('#gsm-lg-user');
  await scan('login');

  // Log in.
  await page.fill('#gsm-lg-user', USER);
  await page.fill('#gsm-lg-pass', PASS);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });

  // Make sure there is data to render.
  const eq = await page.evaluate(async () => {
    const list = await GSM.api('/equipment?per_page=5');
    if (list && list.length) return list[0];
    return GSM.api('/equipment', { method: 'POST', body: { name: 'A11y test', building: 'B1', room: '1' } });
  });

  for (const s of SCREENS) {
    await page.evaluate((r) => GSM.navigate('/' + r), s);
    await page.waitForTimeout(700);
    await scan('#/' + s);
  }
  if (eq && eq.id) {
    await page.evaluate((id) => GSM.navigate('/equipment/' + id), eq.id);
    await page.waitForTimeout(900);
    await scan('#/equipment/' + 'detail');
  }

  // Modals (forms).
  const modals = [
    ['equipment form', '/equipment', '#gsm-eq-add'],
    ['fault form', '/faults', '#gsm-ft-add'],
    ['inspection form', '/inspections', '#gsm-in-add']
  ];
  for (const [name, route, btn] of modals) {
    await page.evaluate((r) => GSM.navigate(r), route);
    await page.waitForSelector(btn, { timeout: 8000 }).catch(() => null);
    const b = await page.$(btn);
    if (!b) { results.push({ name, error: 'button not found' }); continue; }
    await b.click();
    await page.waitForSelector('#gsm-modal', { timeout: 8000 }).catch(() => null);
    await scan(name);
    await page.evaluate(() => { const m = document.getElementById('gsm-modal'); if (m) m.remove(); });
  }

  // Bridge (public QR page) + its fault modal.
  const tok = eq && eq.qr_token;
  if (tok) {
    const bp = await ctx.newPage();
    await bp.goto(BASE + '/bridge/?eq=' + encodeURIComponent(tok));
    await bp.waitForSelector('#gsm-br-fault', { timeout: 8000 }).catch(() => null);
    await scan('bridge', bp);
    const fb = await bp.$('#gsm-br-fault');
    if (fb) { await fb.click(); await bp.waitForSelector('#br-desc'); await scan('bridge fault form', bp); }
    await bp.close();
  }

  // Public dashboard (share link from the comm screen).
  await page.evaluate(() => GSM.navigate('/comm'));
  await page.waitForSelector('#pub-url', { timeout: 8000 }).catch(() => null);
  const share = await page.$eval('#pub-url', (i) => i.value).catch(() => '');
  if (share) {
    const dp = await ctx.newPage();
    await dp.goto(share);
    await dp.waitForSelector('.tile', { timeout: 8000 }).catch(() => null);
    await scan('public dashboard', dp);
    await dp.close();
  }

  // Theme landing / regular page.
  const lp = await ctx.newPage();
  await lp.goto(BASE + '/hello-world/');
  await scan('theme page', lp);
  await lp.close();

  await browser.close();

  // Summary.
  let total = 0;
  const byRule = {};
  for (const r of results) {
    for (const v of r.violations || []) {
      total += v.nodes.length;
      byRule[v.id] = byRule[v.id] || { impact: v.impact, help: v.help, count: 0, pages: new Set(), sample: v.nodes[0] };
      byRule[v.id].count += v.nodes.length;
      byRule[v.id].pages.add(r.name);
    }
    if (r.error) console.log('ERROR ' + r.name + ': ' + r.error);
  }
  const order = { critical: 0, serious: 1, moderate: 2, minor: 3 };
  Object.entries(byRule).sort((a, b) => order[a[1].impact] - order[b[1].impact] || b[1].count - a[1].count).forEach(([id, v]) => {
    console.log(`[${v.impact}] ${id} x${v.count} — ${v.help}\n    pages: ${Array.from(v.pages).join(', ')}\n    e.g. ${v.sample.target}  ${v.sample.html.replace(/\s+/g, ' ').slice(0, 140)}`);
  });
  console.log(`\n${results.length} views scanned, ${Object.keys(byRule).length} rules violated, ${total} elements`);
  if (REPORT) fs.writeFileSync(REPORT, JSON.stringify(results, null, 2));
  process.exit(total ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
