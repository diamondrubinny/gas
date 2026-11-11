// Chat widget (Botwa) integration: settings validation, click-to-load,
// CSP scoping, placements, accessibility. The chat server is simulated
// (https://chat.example.test) — no real vendor is contacted.
// Run: BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... node tests/e2e/chat-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;
const CHAT = 'https://chat.example.test';
const KEY = 'wc_testkey_1234567';

(async () => {
  const browser = await browser_();
  async function browser_() { return chromium.launch(); }
  const ctx = await browser.newContext();
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };

  const chatReqs = [], otherThirdParty = [];
  ctx.on('request', (q) => {
    const u = q.url();
    if (/^(data|blob|about):/.test(u)) return;
    const o = new URL(u).origin;
    if (o !== new URL(BASE).origin && o !== CHAT) otherThirdParty.push(u);
  });
  const violations = [], errors = [];
  await ctx.route(CHAT + '/**', (route) => {
    const u = route.request().url();
    chatReqs.push(u);
    if (u.includes('/widget/config')) {
      return route.fulfill({ contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': '*' }, body: JSON.stringify({
        design: { position: 'left', language: 'he', title: 'צ׳אט בדיקה', color: '#002147', launcherLabel: 'צ׳אט', launcherIcon: 'chat' },
        textColor: '#ffffff', behavior: { popupText: '', popupDelaySec: 0, autoOpenSec: 0 } }) });
    }
    if (u.includes('/w/')) return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="he"><title>chat</title><body>chat frame</body></html>' });
    return route.fulfill({ status: 404, body: '' });
  });
  await ctx.exposeBinding('__cspReport', (_s, v) => violations.push(v));
  await ctx.addInitScript(() => document.addEventListener('securitypolicyviolation', (e) => window.__cspReport && window.__cspReport(e.violatedDirective + ' ' + e.blockedURI)));

  const page = await ctx.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(BASE + '/gas-app/#/login');
  await page.waitForSelector('#gsm-lg-user');
  await page.fill('#gsm-lg-user', USER);
  await page.fill('#gsm-lg-pass', PASS);
  await page.click('#gsm-lg-submit');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
  const api = (path, body) => page.evaluate(([p, b]) => GSM.api(p, b ? { method: 'POST', body: b } : undefined).then((r) => ({ ok: true, r }), (e) => ({ ok: false, msg: e.message })), [path, body]);

  // 0. Start from a clean state.
  await api('/chat', { clear: true, where: { bridge: false, app: false, site: false } });

  // 1. Snippet validation (server side).
  const bad = {
    'http (not https)': '<script src="http://chat.example.test/widget.js" data-key="' + KEY + '"></script>',
    'IPv4 host': '<script src="https://10.0.0.5/widget.js" data-key="' + KEY + '"></script>',
    'IPv6 host': '<script src="https://[::1]/widget.js" data-key="' + KEY + '"></script>',
    'single-label host': '<script src="https://intranet/widget.js" data-key="' + KEY + '"></script>',
    'numeric TLD': '<script src="https://chat.example.123/widget.js" data-key="' + KEY + '"></script>',
    'not widget.js': '<script src="https://chat.example.test/evil.js" data-key="' + KEY + '"></script>',
    'bad key format': '<script src="https://chat.example.test/widget.js" data-key="abc"></script>',
    'no key': '<script src="https://chat.example.test/widget.js"></script>'
  };
  for (const [name, sn] of Object.entries(bad)) {
    const r = await api('/chat', { snippet: sn, enabled: true });
    check('rejects snippet: ' + name, !r.ok, JSON.stringify(r));
  }
  let r = await api('/chat', { enabled: true });
  check('cannot enable without a snippet', !r.ok, JSON.stringify(r));

  // 2. Valid snippet, all placements.
  r = await api('/chat', { snippet: '<!-- Chat widget -->\n<script src="' + CHAT + '/widget.js" data-key="' + KEY + '" async></script>', enabled: true, label: 'צ׳אט', where: { bridge: true, app: true, site: true } });
  check('valid snippet saved and enabled', r.ok && r.r.enabled && r.r.origin === CHAT, JSON.stringify(r));
  check('key is masked in the API', r.ok && !JSON.stringify(r.r).includes(KEY));

  // 3. App page: CSP scoped, our button present, nothing requested yet.
  const resp = await page.goto(BASE + '/gas-app/?t=' + Date.now() + '#/dashboard');
  const csp = resp.headers()['content-security-policy'] || '';
  const dir = (n) => (csp.split(';').map((x) => x.trim()).find((x) => x.startsWith(n + ' ')) || '');
  check('CSP connect-src + frame-src allow the chat server', dir('connect-src').includes(CHAT) && dir('frame-src').includes(CHAT), csp);
  check("CSP script-src stays 'self' + nonce (no chat server)", !dir('script-src').includes(CHAT) && /'self' 'nonce-/.test(dir('script-src')), dir('script-src'));
  await page.waitForSelector('.gsm-chat-btn', { timeout: 8000 });
  check('chat button has an accessible name', (await page.getAttribute('.gsm-chat-btn', 'title')).length > 0 && /צ׳אט/.test(await page.textContent('.gsm-chat-btn')));
  await page.waitForTimeout(800);
  check('click-to-load: no request to the chat server before click', chatReqs.length === 0, chatReqs.join(' | '));

  const ax = await new AxeBuilder({ page }).include('.gsm-chat-btn').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check('chat button: 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id).join(','));

  // 4. Keyboard: Tab reaches the button, Enter loads + opens the chat.
  await page.focus('.gsm-chat-btn');
  const loaderReq = page.waitForRequest((q) => q.url().includes('/vendor/botwa-widget.js'), { timeout: 8000 }).catch(() => null);
  await page.keyboard.press('Enter');
  const lq = await loaderReq;
  check('loader served from the plugin (same origin)', !!lq && new URL(lq.url()).origin === new URL(BASE).origin, lq && lq.url());
  await page.waitForSelector('[data-botwa-widget]', { timeout: 8000 }).catch(() => null);
  const st = await page.evaluate((k) => {
    const f = document.querySelector('iframe[src*="/w/"]');
    const b = document.querySelector('.gsm-chat-btn');
    return { host: !!document.querySelector('[data-botwa-widget]'), frame: f && f.getAttribute('src'), frameVisible: f && f.parentElement.style.display === 'block', ourHidden: b && b.hidden, key: k };
  }, KEY);
  check('vendor launcher built after config fetch', st.host && chatReqs.some((u) => u.includes('/widget/config?key=' + KEY)), JSON.stringify(st));
  check('chat iframe from the chat server, opened', st.frame === CHAT + '/w/' + KEY && st.frameVisible, JSON.stringify(st));
  check('our button hands over to the vendor launcher', st.ourHidden === true);
  check('no identity sent (identify() never called)', await page.evaluate(() => !window.botwaChatIdentity));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(200);
  check('Escape closes the chat', await page.evaluate(() => { const f = document.querySelector('iframe[src*="/w/"]'); return f && f.parentElement.style.display === 'none'; }));

  // 5. Bridge + site pages.
  const eq = await page.evaluate(() => GSM.api('/equipment?per_page=1'));
  if (eq && eq[0]) {
    const bp = await ctx.newPage();
    await bp.goto(BASE + '/bridge/?eq=' + encodeURIComponent(eq[0].qr_token));
    check('button on the public QR page', !!(await bp.waitForSelector('.gsm-chat-btn', { timeout: 8000 }).catch(() => null)));
    await bp.close();
  }
  const sp = await ctx.newPage();
  await sp.goto(BASE + '/hello-world/');
  check('button on regular site pages', !!(await sp.waitForSelector('.gsm-chat-btn', { timeout: 8000 }).catch(() => null)));
  await sp.close();

  // 6. Placement off -> no button, no CSP allowance.
  await api('/chat', { where: { bridge: true, app: false, site: true } });
  const resp2 = await page.goto(BASE + '/gas-app/?t=' + Date.now() + '#/dashboard');
  await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
  await page.waitForTimeout(500);
  check('app placement off: no button', !(await page.$('.gsm-chat-btn')));
  check('app placement off: CSP has no chat server', !(resp2.headers()['content-security-policy'] || '').includes(CHAT));

  // 7. Disabled -> nowhere.
  await api('/chat', { enabled: false });
  const sp2 = await ctx.newPage();
  await sp2.goto(BASE + '/hello-world/');
  await sp2.waitForTimeout(500);
  check('disabled: no button on site pages', !(await sp2.$('.gsm-chat-btn')));
  await sp2.close();

  // Restore.
  await api('/chat', { clear: true, where: { bridge: false, app: false, site: false }, label: 'צ׳אט' });
  check('no CSP violations', violations.length === 0, violations.join(' | '));
  check('no third-party request except the chat server (e.g. no Gravatar for logged-in users)', otherThirdParty.length === 0, Array.from(new Set(otherThirdParty)).slice(0, 5).join(' | '));
  check('no JS errors', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
