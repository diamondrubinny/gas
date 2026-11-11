// Layout regressions axe cannot catch:
// - Header text on the navy gradient: axe reports gradient backgrounds as
//   "incomplete", so we compute contrast against both gradient stops (the theme
//   colours every h1 navy, which made the title invisible on the navy bar).
// - Dashboard 14-day scans chart: maintainAspectRatio:false needs a sized box,
//   otherwise the canvas grows to thousands of pixels.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/layout-e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;

(async () => {
  const browser = await chromium.launch();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };

  for (const vp of [{ width: 1280, height: 900, n: 'desktop' }, { width: 390, height: 844, n: 'mobile' }]) {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + '/gas-app/#/login');
    await page.waitForSelector('#gsm-lg-user');
    await page.fill('#gsm-lg-user', USER);
    await page.fill('#gsm-lg-pass', PASS);
    await page.click('#gsm-lg-submit');
    await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
    await page.evaluate(() => GSM.navigate('/dashboard'));
    await page.waitForSelector('#gsm-cov-trend', { timeout: 10000 });
    await page.waitForTimeout(800);

    const res = await page.evaluate(() => {
      const rgb = (s) => { const c = document.createElement('canvas').getContext('2d'); c.fillStyle = s; const h = c.fillStyle; return h[0] === '#' ? [1, 3, 5].map((i) => parseInt(h.substr(i, 2), 16)) : (h.match(/\d+/g) || []).slice(0, 3).map(Number); };
      const lum = (c) => { const a = c.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2]; };
      const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
      const hdr = document.querySelector('.gs-header');
      const stops = (getComputedStyle(hdr).backgroundImage.match(/rgba?\([^)]+\)/g) || []).map(rgb);
      const out = {};
      ['.gs-header__title', '.gs-header__logout'].forEach((sel) => {
        const el = document.querySelector(sel);
        if (!el) { out[sel] = null; return; }
        const fg = rgb(getComputedStyle(el).color);
        out[sel] = stops.length ? Math.min.apply(null, stops.map((s) => ratio(fg, s))) : 0;
      });
      const cv = document.getElementById('gsm-cov-trend');
      out.chartH = cv ? cv.getBoundingClientRect().height : -1;
      out.chartW = cv ? cv.getBoundingClientRect().width : -1;
      out.boxW = cv ? cv.parentElement.getBoundingClientRect().width : -1;
      out.stops = stops.length;
      out.label = cv ? !!(cv.getAttribute('aria-labelledby') && document.getElementById(cv.getAttribute('aria-describedby'))) : false;
      out.hscroll = document.documentElement.scrollWidth > window.innerWidth + 1;
      return out;
    });
    check(vp.n + ': header gradient found', res.stops >= 2, String(res.stops));
    check(vp.n + ': header title contrast ≥ 4.5 on both gradient stops', res['.gs-header__title'] >= 4.5, String(res['.gs-header__title']));
    check(vp.n + ': logout contrast ≥ 4.5 on both gradient stops', res['.gs-header__logout'] >= 4.5, String(res['.gs-header__logout']));
    check(vp.n + ': scans chart has a sane height (100–300px)', res.chartH >= 100 && res.chartH <= 300, String(res.chartH));
    check(vp.n + ': scans chart fits its box', res.chartW <= res.boxW + 1, res.chartW + ' > ' + res.boxW);
    check(vp.n + ': scans chart has an accessible name + text alternative', res.label);
    check(vp.n + ': no horizontal page scroll', !res.hscroll);
    check(vp.n + ': no JS errors', errors.length === 0, errors.join(' | '));
    await ctx.close();
  }
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
