/*
 * Runs the shared password examples against the browser copy of the rules.
 * Needs only Node:   node tests/js/password-policy.check.cjs
 * The PHP copy is checked against the same file by tests/Unit/PasswordPolicyTest.php.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

// The browser file is a plain script (it sets window.WeaverPassword), so run it the way a browser would.
const sandbox = {};
sandbox.self = sandbox;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/password-policy.js'), 'utf8'), sandbox);
const WeaverPassword = sandbox.WeaverPassword;

const examples = JSON.parse(fs.readFileSync(path.join(__dirname, '../fixtures/password-vectors.json'), 'utf8'));
let bad = 0;

for (const ex of examples) {
  const r = WeaverPassword.evaluate(ex.password, { email: ex.email || null, name: ex.name || null });
  const same = JSON.stringify(r.failed) === JSON.stringify(ex.failed) && r.level === ex.level;
  if (!same) {
    bad++;
    console.log('FAIL  ' + ex.note + '\n      expected ' + ex.level + ' ' + JSON.stringify(ex.failed) + '\n      got      ' + r.level + ' ' + JSON.stringify(r.failed));
  }
}

if (JSON.stringify(Object.keys(WeaverPassword.MESSAGES)) !== JSON.stringify(WeaverPassword.ORDER)) {
  bad++;
  console.log('FAIL  MESSAGES keys are not in the same order as ORDER');
}

console.log(examples.length + ' examples checked, ' + bad + ' failed.');
process.exit(bad ? 1 : 0);
