const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..'), review = path.join(root, 'PRISM_V13_GROUP_ADVANCEMENT_REVIEW');
const php = process.env.PRISM_TEST_PHP || 'C:/xampp/php/php.exe', base = 'http://127.0.0.1:14527', app = path.join(review, 'sandbox/app');
const playwright = require(process.env.PRISM_TEST_PLAYWRIGHT || path.join(root, 'PRISM_V13_UI_REMEDIATION_AUDIT/browser-tools/node_modules/playwright-core'));
const checks = [], contexts = [];
const expectedSchema = process.env.PRISM_TEST_SCHEMA_VERSION || '11';
const check = (value, label) => { assert.ok(value, label); checks.push(label); console.log(label); };
const fixture = input => JSON.parse(cp.execFileSync(php, [path.join(__dirname, 'fixture.php')], { input: JSON.stringify(input), encoding: 'utf8', windowsHide: true }));
const sql = (query, params = []) => fixture({ action: 'sql', sql: query, params });
const sleep = ms => new Promise(r => setTimeout(r, ms));
function child(input) {
  const p = cp.spawn(php, [path.join(__dirname, 'fixture.php')], { windowsHide: true }); let output = '', errors = '';
  const done = new Promise((resolve, reject) => { p.stdout.on('data', d => output += d); p.stderr.on('data', d => errors += d); p.on('error', reject); p.on('exit', code => code ? reject(new Error(errors)) : resolve(output)); });
  p.stdin.end(JSON.stringify(input)); return { done, output: () => output };
}
async function locked(p) { for (let n = 0; n < 100; n++) { if (p.output().includes('locked')) return; await sleep(15); } throw new Error('Independent connection did not acquire lock'); }
const generation = async ctx => (await ctx.storageState()).cookies.find(c => c.name === 'prism_generation')?.value || '';
const response = async r => ({ status: r.status(), body: await r.json() });
async function post(ctx, action, data, endpoint = 'ierb_api.php') { return response(await ctx.post('/' + endpoint + '?action=' + action, { data, headers: { origin: base, 'X-Prism-Generation': await generation(ctx) } })); }
async function expect(promise, status, label) { const r = await promise; check(r.status === status, label + (r.status === status ? '' : ': ' + JSON.stringify(r))); return r; }
async function login(email) {
  const ctx = await playwright.request.newContext({ baseURL: base }); contexts.push(ctx);
  const r = await ctx.post('/login_process.php', { form: { email, password: 'SyntheticPass123!' }, headers: { origin: base }, maxRedirects: 0 });
  check(r.status() === 302, 'Actual authenticated login ' + email); return ctx;
}
function prepareApp() {
  fs.mkdirSync(app, { recursive: true });
  for (const file of cp.execFileSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean)) {
    if (!file.endsWith('.php') && !file.startsWith('assets/') && !file.startsWith('includes/')) continue;
    const target = path.join(app, file); fs.mkdirSync(path.dirname(target), { recursive: true }); fs.copyFileSync(path.join(root, file), target);
  }
  fs.copyFileSync(path.join(root, 'includes/ierb_group_advancement.php'), path.join(app, 'includes/ierb_group_advancement.php'));
  // Include additive foundation files before they are staged in Git.
  fs.cpSync(path.join(root, 'includes'), path.join(app, 'includes'), { recursive: true });
  fs.cpSync(path.join(root, 'vendor'), path.join(app, 'vendor'), { recursive: true });
  for (const folder of ['storage/documents', 'storage/reports', 'sessions', 'uploads']) fs.mkdirSync(path.join(app, folder), { recursive: true });
}
(async () => {
  let server, logs = '';
  try {
    check(fixture({ action: 'schema' }).schemaVersion === expectedSchema, 'Isolated database exactly matches schema v' + expectedSchema);
    prepareApp();
    fs.writeFileSync(path.join(review, 'sandbox/provider-scenario.txt'), 'success');
    server = cp.spawn(php, ['-d', 'extension=zip', '-d', 'auto_prepend_file=' + path.join(__dirname, 'runtime.php'), '-d', 'disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close', '-d', 'session.save_path=' + path.join(app, 'sessions'), '-d', 'upload_tmp_dir=' + path.join(app, 'uploads'), '-S', '127.0.0.1:14527', '-t', app], { windowsHide: true });
    server.stdout.on('data', d => logs += d); server.stderr.on('data', d => logs += d);
    let ready = false; for (let n = 0; n < 60; n++) { try { if ((await fetch(base + '/login.php')).ok) { ready = true; break; } } catch (_) {} await sleep(100); }
    check(ready, 'Isolated copied PHP application serves HTTP');
    const sessions = { admin: await login('admin@example.test'), adviser: await login('adviser2@example.test'), student: await login('student4@example.test') };
    for (const [role, ctx] of Object.entries(sessions)) {
      const contract = await response(await ctx.get('/ierb_api.php?action=v14_contract'));
      check(contract.status === 200 && contract.body.activeWorkflow === 'legacy' && contract.body.contract.normalPath.join(',') === 'initial_submission,approval_data_gathering,ierb_completed' && !contract.body.contract.httpActivationAvailable,
        role + ' can inspect the immutable V14 contract while the current workflow stays gated');
      await expect(post(ctx, 'v14_open_correction', {}), 400, role + ' has no V14 HTTP mutation bypass in Batch 1');
    }
    const phase = process.env.PRISM_TEST_PHASE || 'all';
    const h = { root, review, php, base, app, playwright, checks, check, fixture, sql, post, response, expect, generation, sessions, login, child, locked, sleep, phase };
    await require('./backend.cjs')(h);
    if (phase !== 'backend') { await require('./browser.cjs')(h); await require('./regression.cjs')(h); }
    check(fixture({ action: 'schema' }).schemaVersion === expectedSchema, 'Full schema v' + expectedSchema + ' and version remain unchanged after all tests');
    const result = { status: 'PASS', assertions: checks.length, cases: checks, scope: 'Isolated synthetic MariaDB 10.4, actual PHP HTTP sessions and Chromium; no production data or external delivery' };
    fs.writeFileSync(path.join(review, phase === 'all' ? 'test-results.json' : `test-results-${phase}.json`), JSON.stringify(result, null, 2)); console.log(JSON.stringify({ status: 'PASS', assertions: checks.length }));
  } catch (e) {
    fs.writeFileSync(path.join(review, 'test-failure.json'), JSON.stringify({ status: 'FAIL', assertions: checks.length, cases: checks, error: String(e), stack: e.stack }, null, 2)); throw e;
  } finally {
    for (const ctx of contexts) await ctx.dispose(); if (server) server.kill(); fs.writeFileSync(path.join(review, 'sandbox/php-server.log'), logs);
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
