/* Isolated checks of the actual forced-password form handler; no browser or network access. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.join(__dirname, '..', 'change_password_required.php'), 'utf8');
const scripts = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert.equal(scripts.length, 1, 'Expected exactly one inline form script');
const script = scripts[0][1].replace(/<\?php echo json_encode\(\$landing\); \?>/g, '"/fixture-dashboard"');
assert(!script.includes('<?'), 'Unexpected executable PHP in inline fixture');
class Element {
  constructor(value = '') { this.value = value; this.children = []; this.className = ''; this.textContent = ''; }
  set innerHTML(_) { throw new Error('Unsafe HTML sink used by password form'); }
  replaceChildren(...children) { this.children = children; }
  addEventListener(name, handler) { assert.equal(name, 'submit'); this.submit = handler; }
}
async function runCase(test) {
  const elements = Object.fromEntries([
    ['forcedPasswordForm', ''], ['formMessage', ''], ['currentPassword', 'fixture-old-password'],
    ['newPassword', 'fixture-new-password'], ['confirmPassword', test.mismatch ? 'different-password' : 'fixture-new-password'],
  ].map(([key, value]) => [key, new Element(value)]));
  elements.formMessage.children = [new Element('old message')];
  const requests = [];
  const window = { location: { href: '/fixture-change-password' } };
  const context = {
    togglePassword() {}, window,
    document: { getElementById(id) { assert(id in elements, id); return elements[id]; }, createElement(tag) { assert.equal(tag, 'div'); return new Element(); } },
    async fetch(url, options) {
      requests.push({ url, options });
      if (test.networkError) throw new Error('fixture network failure');
      return { async json() { return test.response || { ok: false }; } };
    },
  };
  vm.runInNewContext(script, context, { filename: 'change_password_required.php:inline' });
  let prevented = false;
  await elements.forcedPasswordForm.submit({ preventDefault() { prevented = true; } });
  assert(prevented);
  assert.equal(requests.length, test.mismatch ? 0 : 1);
  if (requests.length) {
    assert.equal(requests[0].url, 'profile_api.php?action=change_password');
    assert.equal(requests[0].options.method, 'POST');
    assert.deepEqual(JSON.parse(requests[0].options.body), { currentPassword: 'fixture-old-password', newPassword: 'fixture-new-password' });
  }
  if (test.response?.ok) {
    assert.equal(window.location.href, '/fixture-dashboard');
    assert.equal(elements.formMessage.children.length, 0);
  } else {
    assert.equal(window.location.href, '/fixture-change-password');
    assert.equal(elements.formMessage.children.length, 1);
    const message = elements.formMessage.children[0];
    assert.equal(message.className, 'error-message');
    assert.equal(message.textContent, test.expected);
    assert.equal(message.children.length, 0);
  }
  console.log('PASS: ' + test.name);
}
(async () => {
  const attack = '<img src=x onerror="alert(1)"> & <script>bad()</script>';
  const cases = [
    { name: 'Server message is literal text', response: { ok: false, message: attack }, expected: attack },
    { name: 'Empty server error has fallback text', response: { ok: false, message: '' }, expected: 'Password could not be changed.' },
    { name: 'Network error has safe text', networkError: true, expected: 'Could not reach the server. Please try again.' },
    { name: 'Password mismatch blocks the request', mismatch: true, expected: 'New passwords do not match.' },
    { name: 'Successful password change keeps payload and redirects', response: { ok: true } },
  ];
  for (const test of cases) await runCase(test);
  console.log(cases.length + ' forced-password UI cases passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
