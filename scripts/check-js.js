// Syntax-check every first-party JS file (node --check). Vendor code excluded.
// Usage: node scripts/check-js.js
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOTS = ['plugins', 'themes', 'tests', 'scripts'];
const SKIP = /(^|\/)(vendor|node_modules|\.wp-test)(\/|$)/;
const files = [];
(function walk(dirs) {
  for (const d of dirs) {
    if (!fs.existsSync(d)) continue;
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (SKIP.test(p)) continue;
      if (e.isDirectory()) walk([p]);
      else if (p.endsWith('.js')) files.push(p);
    }
  }
})(ROOTS);

let failed = 0;
for (const f of files) {
  try { execFileSync(process.execPath, ['--check', f], { stdio: 'pipe' }); }
  catch (e) { failed++; console.error('FAIL ' + f + '\n' + e.stderr.toString()); }
}
console.log(files.length + ' files checked, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
