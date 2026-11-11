// QR helper (GSM.qrPng on qrcode-generator) produces codes that a
// standard decoder (jsQR) reads back exactly — incl. UTF-8 (Hebrew) URLs.
// Run: node tests/qr-test.js plugins/gas-safety-manager
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const plugin = process.argv[2] || 'plugins/gas-safety-manager';
const lib = fs.readFileSync(path.join(plugin, 'public/assets/vendor/qrcode-generator.js'), 'utf8');
const helper = fs.readFileSync(path.join(plugin, 'public/assets/js/qr.js'), 'utf8');
const jsqr = fs.readFileSync(require.resolve('jsqr/dist/jsQR.js'), 'utf8');

const CASES = [
  'https://safety.example.ac.il/bridge/?eq=0123456789abcdef0123456789abcdef',
  'https://safety.example.ac.il/?page_id=12&eq=fedcba9876543210fedcba9876543210',
  'https://בטיחות.example/גשר/?eq=0123456789abcdef0123456789abcdef', // non-Latin
  'https://safety.example.ac.il/bridge/?eq=' + 'a'.repeat(32) + '&x=' + 'b'.repeat(200) // larger version
];

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.addScriptTag({ content: lib });
  await page.addScriptTag({ content: helper });
  await page.addScriptTag({ content: jsqr });
  let fail = 0;
  for (const [i, text] of CASES.entries()) {
    for (const px of [180, 260]) {
      const res = await page.evaluate(async ([t, size]) => {
        const url = GSM.qrPng(t, size);
        const img = new Image();
        await new Promise((ok, bad) => { img.onload = ok; img.onerror = bad; img.src = url; });
        const c = document.createElement('canvas');
        c.width = img.width; c.height = img.height;
        const g = c.getContext('2d');
        g.drawImage(img, 0, 0);
        const d = g.getImageData(0, 0, c.width, c.height);
        const code = jsQR(d.data, d.width, d.height);
        return { png: url.startsWith('data:image/png'), w: img.width, decoded: code ? code.data : null };
      }, [text, px]);
      const ok = res.png && res.decoded === text && res.w <= px && res.w >= px * 0.6;
      console.log((ok ? 'PASS ' : 'FAIL ') + 'case ' + (i + 1) + ' @' + px + 'px (' + res.w + 'px)' + (ok ? '' : ' — got ' + JSON.stringify(res.decoded)));
      if (!ok) fail++;
    }
  }
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all passed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
