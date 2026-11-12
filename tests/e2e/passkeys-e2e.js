// Passkeys in a real browser with Chromium's virtual authenticator (CDP):
// register from the security screen, sign in from the login screen without a
// password, remove, and see the removed passkey refused.
//
// WebAuthn needs a domain name, so the test site is temporarily served as
// http://gas.localhost:<port> (a secure context that resolves to loopback).
// Needs WP-CLI to switch the site URL:
//   BASE=http://127.0.0.1:8899 USER_NAME=admin PASS=... WP_CLI=/path/wp-cli.phar WP_PATH=/path/site node tests/e2e/passkeys-e2e.js
const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');
const { execFileSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const USER = process.env.USER_NAME || 'admin';
const PASS = process.env.PASS;
const WP_CLI = process.env.WP_CLI, WP_PATH = process.env.WP_PATH;

if (!WP_CLI || !WP_PATH) {
  console.log('SKIP passkeys-e2e: set WP_CLI and WP_PATH (the server side is covered by tests/integration/passkeys-test.php)');
  process.exit(0);
}
const port = new URL(BASE).port;
const SITE = 'http://gas.localhost' + (port ? ':' + port : '');
const wp = (...args) => execFileSync('php', [WP_CLI, '--allow-root', '--path=' + WP_PATH, ...args], { encoding: 'utf8' }).trim();
const setUrl = (u) => { wp('option', 'update', 'home', u); wp('option', 'update', 'siteurl', u); };

(async () => {
  let fail = 0;
  const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : ' — ' + extra)); if (!ok) fail++; };
  const original = wp('option', 'get', 'home');
  setUrl(SITE);
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ locale: 'he-IL' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('dialog', (d) => d.accept());
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });

    await page.goto(SITE + '/gas-app/#/login');
    await page.waitForSelector('#gsm-lg-user');
    check('login screen offers "כניסה עם Passkey"', await page.locator('#gsm-lg-passkey').count() === 1);
    await page.fill('#gsm-lg-user', USER);
    await page.fill('#gsm-lg-pass', PASS);
    await page.click('#gsm-lg-submit');
    await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
    // Start clean.
    await page.evaluate(async () => { const l = await GSM.api('/passkeys'); for (const p of l.items) await GSM.api('/passkeys/' + p.key, { method: 'DELETE' }); });

    await page.evaluate(() => GSM.navigate('/security'));
    await page.waitForSelector('#pk-add', { timeout: 10000 });
    let ax = await new AxeBuilder({ page }).include('#pk-host').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check('passkeys card (empty): 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));
    await page.fill('#pk-name', 'מחשב הבדיקות');
    await page.click('#pk-add');
    await page.waitForSelector('#pk-host [data-pk-del]', { timeout: 15000 });
    check('passkey registered and listed, focus on the card heading', /מחשב הבדיקות/.test(await page.textContent('#pk-host')) && await page.evaluate(() => document.activeElement.id === 'pk-title'));
    const creds = await cdp.send('WebAuthn.getCredentials', { authenticatorId });
    check('the authenticator holds one discoverable credential for this site', creds.credentials.length === 1 && creds.credentials[0].isResidentCredential && creds.credentials[0].rpId === 'gas.localhost', JSON.stringify(creds.credentials.map((c) => [c.rpId, c.isResidentCredential])));
    ax = await new AxeBuilder({ page }).include('#pk-host').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check('passkeys card (with a passkey): 0 axe violations', ax.violations.length === 0, ax.violations.map((v) => v.id + ':' + v.nodes[0].target.join(' ')).join(','));

    // Same device again: refused by the browser (excludeCredentials) with a clear message.
    await page.click('#pk-add');
    await page.waitForFunction(() => /כבר רשום|בוטל|✗/.test(document.getElementById('pk-st').textContent), null, { timeout: 15000 });
    check('registering the same device again is refused', await page.locator('#pk-host [data-pk-del]').count() === 1, await page.textContent('#pk-st'));

    // Sign out, sign in with the passkey only.
    await page.click('[data-gsm-action="logout"]');
    await page.waitForSelector('[data-gsm-nav="/login"]', { timeout: 10000 });
    await page.evaluate(() => GSM.navigate('/login'));
    await page.waitForSelector('#gsm-lg-passkey');
    await page.click('#gsm-lg-passkey');
    await page.waitForSelector('.gs-header__logout', { timeout: 15000 });
    const me = await page.evaluate(() => GSM.api('/auth/me'));
    check('signed in with the passkey alone (no password typed)', me.logged_in === true && (await page.inputValue('#gsm-lg-pass').catch(() => '')) === '', JSON.stringify({ in: me.logged_in }));
    await page.evaluate(() => GSM.navigate('/security'));
    await page.waitForSelector('#pk-host [data-pk-del]');
    check('"last used" is filled after the sign-in', !/—/.test(await page.locator('#pk-host tbody tr td').nth(2).textContent()));

    // Remove it; the credential still exists on the device but the server refuses it.
    await page.click('#pk-host [data-pk-del]');
    await page.waitForFunction(() => !document.querySelector('#pk-host [data-pk-del]'), null, { timeout: 8000 });
    check('passkey removed from the account', true);
    await page.click('[data-gsm-action="logout"]');
    await page.waitForSelector('[data-gsm-nav="/login"]', { timeout: 10000 });
    await page.evaluate(() => GSM.navigate('/login'));
    await page.waitForSelector('#gsm-lg-passkey');
    await page.click('#gsm-lg-passkey');
    await page.waitForSelector('#gsm-lg-err .gs-error', { timeout: 15000 });
    check('removed passkey is refused with a generic message, not signed in', /נכשלה/.test(await page.textContent('#gsm-lg-err')) && await page.locator('.gs-header__logout').count() === 0);
    check('no JS errors', errors.length === 0, errors.join(' | '));
  } catch (e) {
    console.error(e); fail++;
  } finally {
    await browser.close();
    setUrl(original);
    // The failed attempt above counted against the login limiter.
    try { wp('eval', 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'_transient%gsm_login_%\'");'); } catch (e) { /* ignore */ }
  }
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})();
