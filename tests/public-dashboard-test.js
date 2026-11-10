// Run: node tests/public-dashboard-test.js plugins/gas-safety-manager/public/gas-public-dashboard.html
// Serves the page from a fake origin and checks that ?api= cannot point the
// page at another origin, and that hostile values render as text (no XSS).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const html = fs.readFileSync(process.argv[2], 'utf8');
// The page loads its script from assets/js/ (CSP: no inline script). Older
// single-file versions have no such file; serve nothing in that case.
const jsPath = path.join(path.dirname(process.argv[2]), 'assets/js/public-dashboard.js');
const js = fs.existsSync(jsPath) ? fs.readFileSync(jsPath, 'utf8') : '';
const ORIGIN = 'https://site.test';
const PAYLOAD = '<img src=x onerror="window.__pwned=1">';
(async () => {
  const browser = await chromium.launch();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  async function run(query) {
    const page = await browser.newPage();
    const requests = [], violations = [];
    page.on('console', (m) => { if (/Content Security Policy/.test(m.text())) violations.push(m.text()); });
    await page.route('**/*', (route) => {
      const u = route.request().url();
      requests.push(u);
      if (u.startsWith(ORIGIN + '/dash.html')) return route.fulfill({ contentType: 'text/html', body: html });
      if (u.startsWith(ORIGIN + '/assets/js/public-dashboard.js')) return route.fulfill({ contentType: 'application/javascript', body: js });
      if (u.startsWith(ORIGIN) && u.includes('/bi/public')) {
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({
          org: PAYLOAD, generated: PAYLOAD, total_equipment: PAYLOAD, scan_coverage_pct: 50,
          faults_open: 1, quarterly_overdue: 0, by_status: [{ label: PAYLOAD, count: PAYLOAD }] }) });
      }
      if (u.startsWith('https://evil.test')) {
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ org: 'FAKE', total_equipment: 999 }) });
      }
      return route.abort();
    });
    await page.goto(ORIGIN + '/dash.html' + query);
    await page.waitForTimeout(500);
    const res = { requests, violations, pwned: await page.evaluate(() => window.__pwned === 1), text: await page.textContent('body') };
    await page.close();
    return res;
  }
  let r = await run('?t=abc&api=' + encodeURIComponent('https://evil.test/x'));
  check('cross-origin api ignored (no request to evil.test)', !r.requests.some((u) => u.startsWith('https://evil.test')));
  check('falls back to same-origin /wp-json', r.requests.some((u) => u.startsWith(ORIGIN + '/wp-json/gas/v1/bi/public')));
  check('hostile JSON values not executed', !r.pwned);
  check('hostile JSON rendered as text', r.text.includes('<img src=x'));
  check('no CSP violations', r.violations.length === 0, r.violations.join(' | '));
  r = await run('?t=abc&api=' + encodeURIComponent('//evil.test/x'));
  check('protocol-relative api ignored', !r.requests.some((u) => u.startsWith('https://evil.test')));
  r = await run('?t=abc&api=' + encodeURIComponent('javascript:alert(1)'));
  check('javascript: api ignored', !r.pwned && r.requests.some((u) => u.startsWith(ORIGIN + '/wp-json/')));
  r = await run('?t=abc&api=' + encodeURIComponent(ORIGIN + '/custom/gas/v1'));
  check('same-origin custom api honoured', r.requests.some((u) => u.startsWith(ORIGIN + '/custom/gas/v1/bi/public')));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})();
