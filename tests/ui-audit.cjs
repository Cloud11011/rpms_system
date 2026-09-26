/* Isolated UI regression audit. Run: node tests/ui-audit.cjs
 * Requires PHP and Chrome/Edge, plus Node with built-in WebSocket (22+).
 * Templates are rendered with fixtures: config.php is never loaded. The
 * loopback server serves only four templates/assets and mock API responses.
 * Browser requests to every other origin are blocked. No database/mail/AI
 * services are contacted. The temporary browser profile is removed on exit.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { spawn, spawnSync } = require('node:child_process');
const { once } = require('node:events');

const root = path.resolve(__dirname, '..');
const pages = ['admin_notifications.php', 'admin_ai.php', 'ierbprog.php', 'account.php'];
const php = process.env.PRISM_TEST_PHP || (process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : 'php');
const browser = process.env.PRISM_TEST_BROWSER || [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
].find(p => fs.existsSync(p));
const stages = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
const attack = '<img src=x onerror="window.__fixtureXss=1">';
const labels = Object.fromEntries(stages.map((s, i) => [s, ['Ethics application', 'Initial review', 'Revision and resubmission', 'Final review', 'Approval certificate', 'Completed'][i]]));
const requests = [];
const errors = [];
const failures = [];
let scenario = 'empty';
let role = 'admin';
let apiDelay = 0;
let checks = 0;
let origin;
let child;
let profileDir;
let socket;
let server;
let sessionId;
let nextId = 1;
const pending = new Map();
const labelsForScenario = () => scenario === 'long-labels'
  ? Object.fromEntries(stages.map(key => [key, 'LongStageLabel'.repeat(13)]))
  : labels;

function fixtureTemplate(file) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  const requireConfig = /require __DIR__ \. '\/config\.php';/g;
  assert.equal([...source.matchAll(requireConfig)].length, 1, `${file}: expected one config include`);
  const isolated = source.replace(requireConfig, '/* Configuration replaced by isolated test fixture. */');
  assert(!/\b(?:require|include)(?:_once)?\s*(?:\(|["'$])/i.test(isolated), `${file}: unexpected include in fixture`);
  const stub = `<?php
    const STAGE_SEQUENCE = ['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed'];
    $_SESSION = ['account_type' => '${role}', 'user_role' => '${role === 'admin' ? 'RPMS Administrator' : 'Research Adviser'}'];
    function require_login($roles) { return ['id'=>1, 'email'=>'fixture@example.test', 'full_name'=>'UI Audit Fixture', 'role'=>'${role}']; }
    function stage_labels_map() { return json_decode('${JSON.stringify(labelsForScenario())}', true); }
    ?>`;
  const rendered = spawnSync(php, ['-d', 'display_errors=stderr'], {
    input: stub + isolated, cwd: root, encoding: 'utf8', windowsHide: true,
  });
  assert.equal(rendered.status, 0, `${file}: PHP fixture rendering failed: ${rendered.stderr}`);
  assert.equal(rendered.stderr.trim(), '', `${file}: PHP fixture warning`);
  return rendered.stdout;
}

function mockApi(file, action) {
  if (scenario === 'error') return { ok: false, message: 'Fixture error ' + attack };
  const populated = scenario === 'populated' || scenario === 'long-labels';
  if (file === 'notifications_api.php') return { ok: true, notifications: populated ? [{ id: 1, subject: attack, type: 'Reminder', recipient_name: attack, recipient_email: 'fixture@example.test', status: 'Sent', created_at: '2026-09-24 08:00:00', message: attack, delivery_info: 'Fixture only' }] : [], total: 1, scheduled: false, sent: 1 };
  if (file === 'reports_api.php') return { ok: true, reports: populated ? [{ id: 'fixture-report', title: attack, type: 'AI Summarized Report', generated_at: '2026-09-24 08:00:00', generated_by: attack }] : [], report: { id: 'fixture-report' }, aiUsed: false };
  if (file === 'stage_labels_api.php') return { ok: true, labels: labelsForScenario() };
  if (file === 'ierb_api.php') return { ok: true, records: populated ? Array.from({ length: 6 }, (_, i) => ({ id: i + 1, name: i ? `Student ${i}` : attack, studentId: `S${i + 1}`, email: 'fixture@example.test', groupId: 'A', stage: i < 4 ? 'Stage 1' : 'Stage 2', status: 'On Track', progress: 20, research: 'Fixture research', requirements: attack, lastSubmissionDate: '2026-09-24' })) : [] };
  if (file === 'audit_api.php') return { ok: true, entries: populated ? [{ id: 1, action: 'document_override', actionLabel: 'Document override', at: '2026-09-24 08:00:00', actorName: attack, actorEmail: 'fixture@example.test', actorRole: role, studentName: attack, protocolCode: 'P-001', override: true, details: 'Fixture details ' + attack, reason: 'Fixture reason ' + attack }] : [] };
  if (file === 'profile_api.php') return { ok: true, user: { name: 'UI Audit Fixture', email: 'fixture@example.test', role, refId: 'FIXTURE' }, message: action === 'change_password' ? 'Password updated.' : 'Updated.' };
  if (file === 'send_followup.php') return { ok: true, message: 'Fixture follow-up sent.' };
  throw new Error(`Unexpected mock endpoint ${file}`);
}

async function serve(req, res) {
  try {
    const url = new URL(req.url, origin);
    const file = url.pathname.slice(1);
    if (pages.includes(file)) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      res.end(fixtureTemplate(file));
    } else if (/^(notifications|reports|stage_labels|ierb|audit|profile)_api\.php$/.test(file) || file === 'send_followup.php') {
      let body = '';
      for await (const chunk of req) body += chunk;
      requests.push({ file, action: url.searchParams.get('action'), query: Object.fromEntries(url.searchParams), method: req.method, body });
      if (apiDelay) await new Promise(resolve => setTimeout(resolve, apiDelay));
      if (file === 'reports_api.php' && url.searchParams.get('action') === 'file') {
        res.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="fixture.pdf"' });
        res.end('%PDF-1.4\n% Isolated fixture only\n');
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify(mockApi(file, url.searchParams.get('action'))));
    } else if (/^assets\/(css|js|images)\/[A-Za-z0-9_.\/-]+$/.test(file)) {
      const absolute = path.resolve(root, file);
      assert(absolute.startsWith(path.join(root, 'assets') + path.sep));
      const types = { '.css': 'text/css', '.js': 'application/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.jpg': 'image/jpeg' };
      if (!fs.existsSync(absolute) || !fs.statSync(absolute).isFile()) { res.writeHead(404); res.end(); return; }
      res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
      res.end(fs.readFileSync(absolute));
    } else { res.writeHead(404); res.end('Only isolated fixtures are served.'); }
  } catch (e) { errors.push(String(e)); res.writeHead(500); res.end('Fixture failed.'); }
}

function command(method, params = {}, browserCommand = false) {
  return new Promise((resolve, reject) => {
    const id = nextId++;
    const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
    pending.set(id, { resolve: value => { clearTimeout(timer); resolve(value); }, reject: e => { clearTimeout(timer); reject(e); } });
    socket.send(JSON.stringify({ id, method, params, ...(!browserCommand && sessionId ? { sessionId } : {}) }));
  });
}

async function evaluate(expression) {
  const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
  return result.result.value;
}

async function evaluateFunction(fn, ...args) {
  return evaluate(`(${fn.toString()})(${args.map(value => JSON.stringify(value)).join(',')})`);
}

async function waitFor(expression) {
  for (let i = 0; i < 100; i++) {
    if (await evaluate(expression)) return;
    await new Promise(resolve => setTimeout(resolve, 30));
  }
  throw new Error(`UI did not become ready: ${expression}`);
}

function check(ok, label, detail = '') {
  checks++;
  if (!ok) failures.push(`${label}${detail ? ': ' + detail : ''}`);
}

function checkPayload(file, action, expected, label) {
  const request = requests.findLast(r => r.file === file && r.action === action);
  let payload;
  try { payload = JSON.parse(request?.body); } catch (_) {}
  check(request?.method === 'POST' && JSON.stringify(payload) === JSON.stringify(expected), label,
    JSON.stringify({ method: request?.method, payload }));
}

async function navigate(file, width, dark, data = 'empty', viewer = 'admin') {
  scenario = data;
  role = viewer;
  await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });
  await command('Page.navigate', { url: `${origin}/${file}?fixture=${Date.now()}` });
  const ready = { 'admin_notifications.php': '#noticeHistory > *', 'admin_ai.php': '#aiHistory > *', 'ierbprog.php': '#stageChart > *', 'account.php': '#activityList > *' }[file];
  await waitFor(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(ready)})`);
  if (file === 'admin_ai.php' && role === 'admin' && data !== 'error') await waitFor('document.querySelectorAll("#stageLabelEditor input").length === 6');
  await evaluate(`document.documentElement.classList.toggle('dark-theme', ${dark}); new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))`);
}

async function measure(file, width, dark, suffix = '') {
  const name = `${file} ${width}px ${dark ? 'dark' : 'light'}${suffix}`;
  const layout = await evaluate(`(() => {
    const rect = e => { const r=e.getBoundingClientRect(); return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height}; };
    const inScrollableTable = e => {
      if (!e.closest('table')) return false;
      for (let p=e.parentElement;p && p!==document.body;p=p.parentElement) {
        if (p.scrollWidth > p.clientWidth + 1 && /auto|scroll/.test(getComputedStyle(p).overflowX)) return true;
      }
      return false;
    };
    const controls = [...document.querySelectorAll('main input,main select,main textarea,main button')].filter(e => e.getClientRects().length && !e.closest('[hidden]') && !inScrollableTable(e));
    return { viewport:innerWidth, page:document.documentElement.scrollWidth,
      outside:controls.filter(e => {const r=rect(e);return r.x < -1 || r.right > innerWidth+1}).map(e => e.id || e.className),
      clipped:controls.filter(e => {const p=e.closest('form'); if(!p)return false;const a=rect(e),b=rect(p);return a.x < b.x-1 || a.right > b.right+1}).map(e => e.id || e.className),
      checkboxes:controls.filter(e=>e.type==='checkbox').map(e=>({id:e.id,...rect(e)})),
      xss:!!window.__fixtureXss
    };
  })()`);
  check(layout.page <= width + 1, `${name}: document fits viewport`, `${layout.page}px`);
  check(layout.outside.length === 0, `${name}: controls fit viewport`, layout.outside.join(', '));
  check(layout.clipped.length === 0, `${name}: controls fit forms`, layout.clipped.join(', '));
  check(!layout.xss, `${name}: malicious fixture is text`);
  for (const box of layout.checkboxes) check(box.width >= 14 && box.width <= 26 && box.height >= 14 && box.height <= 26, `${name}: ${box.id} checkbox size`, `${box.width}x${box.height}`);
  if (file === 'admin_ai.php') {
    const heights = await evaluate('[...document.querySelectorAll(".ai-report-tools .report-tool")].map(e=>e.getBoundingClientRect().height)');
    check(heights.length === 2 && Math.abs(heights[0] - heights[1]) < 1, `${name}: equal report card heights`, heights.join(', '));
  }
  if (file === 'account.php') {
    const filters = await evaluate(`(() => {
      const r=id=>document.getElementById(id).getBoundingClientRect();
      const search=r('activitySearch'), from=r('activityFrom'), to=r('activityTo');
      return { widest:search.width>=from.width && search.width>=to.width,
        labels:['activitySearch','activityFrom','activityTo'].every(id=>{
          const input=document.getElementById(id),label=input.closest('label').querySelector('span');
          return label.getBoundingClientRect().bottom <= input.getBoundingClientRect().top;
        }), checkbox:document.querySelector('label[for="activityOverride"]').textContent.includes('Overrides only') };
    })()`);
    check(filters.widest, `${name}: search is widest filter`);
    check(filters.labels && filters.checkbox, `${name}: activity labels are above fields and checkbox is labeled`);
  }
}

async function checkListRequestRaces(file, endpoint) {
  const notification = file === 'admin_notifications.php';
  const hostId = notification ? 'noticeHistory' : 'ierbTableBody';
  const countId = notification ? 'noticeCount' : 'overviewTotal';
  const expectedCount = notification ? '1 notification' : '6 students';
  const snapshot = () => evaluateFunction((hostId, countId) => {
    const host = document.getElementById(hostId);
    return {
      count: document.getElementById(countId).textContent, content: host.textContent,
      busy: host.getAttribute('aria-busy'),
      chart: document.getElementById('stageChart')?.textContent || '',
      chartBusy: document.getElementById('stageChart')?.getAttribute('aria-busy') || '',
    };
  }, hostId, countId);
  const resolveList = (index, payload) => evaluateFunction((index, payload) => {
    window.__listRace[index](new Response(JSON.stringify(payload), {
      status: 200, headers: { 'Content-Type': 'application/json' },
    }));
    return new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  }, index, payload);
  for (const obsoleteError of [false, true]) {
    for (const oldestFirst of [false, true]) {
      const name = file + ': obsolete ' + (obsoleteError ? 'error' : 'success') + ' finishes ' + (oldestFirst ? 'before' : 'after') + ' latest list';
      const hook = await command('Page.addScriptToEvaluateOnNewDocument', { source: '(() => {' +
        'const originalFetch = window.fetch; window.__listRace = [];' +
        'window.fetch = function(input, options) {' +
        'const url = new URL(typeof input === "string" ? input : input.url, location.href);' +
        'if (url.origin === location.origin && url.pathname === "/" + ' + JSON.stringify(endpoint) +
        ' && url.searchParams.get("action") === "list") return new Promise(resolve => window.__listRace.push(resolve));' +
        'return originalFetch.call(this, input, options); }; })();' });
      try {
        scenario = 'populated'; role = 'admin';
        await command('Page.navigate', { url: origin + '/' + file + '?race=' + Date.now() });
        await waitFor('document.readyState === "complete" && window.__listRace?.length === 1');
        await evaluateFunction(notification => {
          if (notification) {
            document.getElementById('noticeMessage').value = 'Race fixture notification';
            document.getElementById('notificationForm').requestSubmit();
          } else {
            document.getElementById('addIerbEntry').click();
            const fields = { entryStudentName: 'Race Fixture', entryStudentId: 'RACE-001',
              entryEmail: 'race@example.test', entryGroupId: 'Race Group',
              entryCourse: 'Fixture Course', entryResearchTitle: 'Race Research' };
            for (const [id, value] of Object.entries(fields)) document.getElementById(id).value = value;
            document.getElementById('ierbEntryForm').requestSubmit();
          }
        }, notification);
        await waitFor('window.__listRace.length === 2');
        const pendingState = await snapshot();
        check(pendingState.busy === 'true' && (notification || pendingState.chartBusy === 'true'), name + ': latest request stays busy');
        const obsolete = obsoleteError ? { ok: false, message: 'Obsolete list failure' } : { ok: true, notifications: [], records: [] };
        if (oldestFirst) {
          await resolveList(0, obsolete);
          check(JSON.stringify(await snapshot()) === JSON.stringify(pendingState), name + ': stale completion cannot change pending content or busy state');
        }
        await resolveList(1, mockApi(endpoint, 'list'));
        await waitFor('document.getElementById(' + JSON.stringify(countId) + ').textContent === ' + JSON.stringify(expectedCount) +
          ' && document.getElementById(' + JSON.stringify(hostId) + ').getAttribute("aria-busy") === "false"');
        const latestState = await snapshot();
        check(latestState.content.includes(attack) && (notification || latestState.chartBusy === 'false'), name + ': latest result renders and clears busy state');
        if (!oldestFirst) {
          await resolveList(0, obsolete);
          check(JSON.stringify(await snapshot()) === JSON.stringify(latestState), name + ': stale completion cannot replace the latest result');
        }
        check(await evaluate('!document.body.textContent.includes("Obsolete list failure")'), name + ': stale failures do not produce an error or toast');
      } finally {
        await command('Page.removeScriptToEvaluateOnNewDocument', { identifier: hook.identifier });
      }
    }
  }
}

async function run() {
  assert(browser, 'Set PRISM_TEST_BROWSER to an installed Chrome/Edge executable.');
  assert.equal(typeof WebSocket, 'function', 'Node with built-in WebSocket is required.');
  server = http.createServer((req, res) => { void serve(req, res); });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  profileDir = fs.mkdtempSync(path.join(os.tmpdir(), 'prism-ui-audit-'));
  child = spawn(browser, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profileDir}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', '--disable-component-update', '--password-store=basic', 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const debuggerUrl = await new Promise((resolve, reject) => {
    let output = '';
    const timer = setTimeout(() => reject(new Error('Chrome debugging endpoint did not start')), 15000);
    child.once('error', reject);
    child.stderr.on('data', chunk => { output += chunk; const m = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (m) { clearTimeout(timer); resolve(m[1]); } });
  });
  socket = new WebSocket(debuggerUrl);
  await once(socket, 'open');
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
      const p = pending.get(message.id); pending.delete(message.id);
      message.error ? p.reject(new Error(message.error.message)) : p.resolve(message.result);
    } else if (message.method === 'Runtime.exceptionThrown') {
      errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    } else if (message.method === 'Fetch.requestPaused') {
      const request = message.params;
      const allowed = request.request.url.startsWith(origin + '/') || /^(data:|about:)/.test(request.request.url);
      void command(allowed ? 'Fetch.continueRequest' : 'Fetch.fulfillRequest', allowed ? { requestId: request.requestId } : { requestId: request.requestId, responseCode: 200, body: '' }).catch(e => errors.push(String(e)));
    }
  });
  const target = await command('Target.createTarget', { url: 'about:blank' }, true);
  sessionId = (await command('Target.attachToTarget', { targetId: target.targetId, flatten: true }, true)).sessionId;
  await command('Page.enable');
  await command('Runtime.enable');
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  await command('Browser.setDownloadBehavior', { behavior: 'deny' }, true);

  for (const width of [375, 768, 1024, 1280, 1600]) {
    for (const file of pages) {
      await navigate(file, width, false);
      for (const dark of [false, true]) {
        await evaluate(`document.documentElement.classList.toggle('dark-theme', ${dark})`);
        await measure(file, width, dark);
        if (file === 'admin_notifications.php') {
          await evaluate('document.getElementById("automatedNotice").checked=true; document.getElementById("automatedNotice").dispatchEvent(new Event("change"))');
          await measure(file, width, dark, ' scheduled');
          await evaluate('document.getElementById("automatedNotice").checked=false; document.getElementById("automatedNotice").dispatchEvent(new Event("change"))');
        }
        if (file === 'ierbprog.php') {
          const empty = await evaluate('({height:document.getElementById("stageChart").getBoundingClientRect().height, state:!!document.querySelector("#stageChart .ierb-overview-empty"), table:!!document.querySelector("#ierbTableBody .ierb-table-empty")})');
          check(empty.state && empty.table && empty.height < 180, `ierbprog.php ${width}px: compact empty states`, JSON.stringify(empty));
        }
      }
    }
  }

  for (const file of pages) {
    await navigate(file, 1280, false, 'populated');
    await measure(file, 1280, false, ' populated');
    const hosts = { 'admin_notifications.php': '#noticeHistory', 'admin_ai.php': '#aiHistory', 'ierbprog.php': '#ierbTableBody', 'account.php': '#activityList' };
    check(await evaluate(`document.querySelector(${JSON.stringify(hosts[file])}).textContent.includes(${JSON.stringify(attack)}) && !document.querySelector(${JSON.stringify(hosts[file] + ' img')})`), `${file}: dynamic markup remains literal text`);
    if (file === 'ierbprog.php') {
      const bars = await evaluate('[...document.querySelectorAll("#stageChart .stage-bar")].map(e=>e.getBoundingClientRect().height)');
      check(bars.length === 6 && bars[0] > 40 && Math.abs(bars[0] / bars[1] - 2) < 0.08, 'IERB populated chart represents 4:2 counts', bars.join(', '));
    }
    if (file === 'admin_ai.php') {
      check(await evaluate(`!!document.querySelector(${JSON.stringify('#aiHistory a[href*="reports_api.php"],#aiHistory button,#aiHistory [tabindex="0"]')})`), 'AI report history is keyboard reachable');
      check(await evaluateFunction(() => {
        const link = document.querySelector('#aiHistory a');
        link.focus();
        return document.activeElement === link && link.target === '_blank' && link.rel.includes('noopener');
      }), 'AI report history receives keyboard focus and secures new tabs');
    }
    if (file === 'account.php') {
      check(await evaluateFunction(() => {
        const text = document.getElementById('activityList').textContent;
        return ['Document override', 'P-001', 'Admin Override', 'Fixture details', 'Fixture reason'].every(part => text.includes(part));
      }), 'Activity keeps action, student protocol, override, details, and reason visible');
      apiDelay = 150;
      await evaluate('document.getElementById("accountCurrentPassword").value="fixture-current"; document.getElementById("accountNewPassword").value="fixture-next"; document.getElementById("accountConfirmPassword").value="fixture-next"; document.getElementById("accountPasswordForm").requestSubmit()');
      check(await evaluate('document.querySelector("#accountPasswordForm button[type=submit]").disabled'), 'Password submit remains disabled while request is pending');
      await waitFor('!document.querySelector("#accountPasswordForm button[type=submit]").disabled');
      apiDelay = 0;
      check(await evaluate('document.getElementById("accountCurrentPassword").value === ""'), 'Password success resets form after async response');
      checkPayload('profile_api.php', 'change_password', { currentPassword: 'fixture-current', newPassword: 'fixture-next' }, 'Password API parameter names preserved');
    }
  }

  for (const width of [375, 768, 1280]) {
    for (const file of pages) {
      await navigate(file, width, true, 'long-labels');
      await measure(file, width, true, ' populated with long labels');
    }
  }

  await navigate('admin_notifications.php', 1280, false);
  for (const scheduled of [false, true]) {
    apiDelay = 150;
    await evaluateFunction(scheduled => {
      const set = (id, value) => { document.getElementById(id).value = value; };
      set('noticeAudience', 'Specific Research Group');
      document.getElementById('noticeAudience').dispatchEvent(new Event('change'));
      set('noticeGroup', '  Fixture Group  ');
      set('noticeType', 'Reminder');
      set('noticeMessage', '  Fixture notification  ');
      document.getElementById('automatedNotice').checked = scheduled;
      document.getElementById('automatedNotice').dispatchEvent(new Event('change'));
      if (scheduled) set('noticeSchedule', '2099-01-02T09:30');
      document.getElementById('notificationForm').requestSubmit();
    }, scheduled);
    check(await evaluate('document.querySelector("#notificationForm button[type=submit]").disabled'), 'Notification submit disabled while pending');
    await waitFor('!document.querySelector("#notificationForm button[type=submit]").disabled');
    apiDelay = 0;
    checkPayload('notifications_api.php', 'send', {
      audience: 'Specific Research Group', group: 'Fixture Group', type: 'Reminder', message: 'Fixture notification', automated: scheduled, scheduleAt: scheduled ? '2099-01-02T09:30' : '',
    }, `Notification ${scheduled ? 'schedule' : 'send'} preserves API payload`);
    check(await evaluate('document.getElementById("noticeMessage").value === "" && document.getElementById("groupLabel").hidden && document.getElementById("scheduleLabel").hidden'), 'Notification success resets conditional controls');
  }

  await navigate('admin_ai.php', 1280, false, 'populated');
  for (const [mode, id] of [['summary', 'generateSummarizedReport'], ['full', 'generateFullReport']]) {
    const previous = requests.filter(r => r.file === 'reports_api.php' && r.action === 'ai_report').length;
    apiDelay = 150;
    await evaluateFunction(id => document.getElementById(id).click(), id);
    check(await evaluate('[...document.querySelectorAll(".ai-report-tools button")].every(e=>e.disabled)'), 'Both report controls disabled during generation');
    await evaluateFunction(() => {
      document.getElementById('generateSummarizedReport').click();
      document.getElementById('generateFullReport').click();
    });
    await waitFor('[...document.querySelectorAll(".ai-report-tools button")].every(e=>!e.disabled)');
    apiDelay = 0;
    checkPayload('reports_api.php', 'ai_report', { mode }, `Report ${mode} preserves API payload`);
    check(requests.filter(r => r.file === 'reports_api.php' && r.action === 'ai_report').length === previous + 1, 'Repeated report clicks do not duplicate generation');
    check(await evaluate('document.getElementById("reportAiNote").classList.contains("warn") && document.getElementById("reportAiNote").textContent.includes("local summarizer")'), 'Local report fallback is explained');
  }
  await evaluateFunction(value => {
    const input = document.querySelector('#stageLabelEditor input[data-stage="Stage 1"]');
    input.value = value;
    document.querySelector('#stageLabelEditor button[data-save="Stage 1"]').click();
  }, '  ' + attack + '  ');
  await waitFor('[...document.querySelectorAll("#stageLabelEditor button")].every(e=>!e.disabled)');
  checkPayload('stage_labels_api.php', 'save', { stageKey: 'Stage 1', label: attack }, 'Stage label save preserves stage key and API names');

  await navigate('account.php', 1280, false, 'populated');
  await evaluateFunction(() => {
    document.getElementById('activitySearch').value = '  protocol fixture  ';
    document.getElementById('activityFrom').value = '2026-09-01';
    document.getElementById('activityTo').value = '2026-09-25';
    document.getElementById('activityOverride').checked = true;
    document.getElementById('activityFilterForm').requestSubmit();
  });
  await waitFor('document.getElementById("activityList").getAttribute("aria-busy") === "false" && !!document.querySelector("#activityList .activity-row")');
  const activity = requests.findLast(r => r.file === 'audit_api.php');
  check(activity.method === 'GET' && JSON.stringify(activity.query) === JSON.stringify({ action: 'list', limit: '100', q: 'protocol fixture', from: '2026-09-01', to: '2026-09-25', override: '1' }), 'Activity preserves all query parameter names and limit', JSON.stringify(activity.query));

  await navigate('ierbprog.php', 1280, false, 'populated');
  await evaluateFunction(() => {
    document.getElementById('stageFilter').value = 'Stage 2';
    document.getElementById('stageFilter').dispatchEvent(new Event('change'));
  });
  check(await evaluate('document.querySelectorAll("#ierbTableBody tr").length === 2 && document.querySelectorAll("#stageChart .stage-bar").length === 6'), 'IERB filtering retains stage keys and the full overview chart');
  await evaluateFunction(() => {
    document.getElementById('ierbSearch').value = 'No fixture student matches';
    document.getElementById('ierbSearch').dispatchEvent(new Event('input'));
  });
  check(await evaluate('document.querySelector("#ierbTableBody .ierb-table-empty").textContent.includes("No matching")'), 'IERB distinguishes filtered empty results');
  await evaluateFunction(() => {
    document.getElementById('ierbSearch').value = '';
    document.getElementById('stageFilter').value = '';
    document.getElementById('stageFilter').dispatchEvent(new Event('change'));
    document.querySelector('#ierbTableBody button[title="Add note"]').click();
    document.getElementById('ierbActionText').value = '  Fixture note  ';
    document.getElementById('ierbActionForm').requestSubmit();
  });
  await waitFor('document.getElementById("ierbActionModal").getAttribute("aria-hidden") === "true"');
  checkPayload('ierb_api.php', 'note', { studentId: 1, note: 'Fixture note' }, 'IERB note preserves API parameter names');
  await evaluateFunction(() => {
    document.getElementById('addIerbEntry').click();
    const fields = { entryStudentName: 'Fixture Student', entryStudentId: 'F-001', entryEmail: 'fixture@example.test', entryGroupId: 'Fixture Group', entryCourse: 'Fixture Course', entryStage: 'Stage 1', entryResearchTitle: 'Fixture Research', entryRequirements: 'Fixture Requirements', entrySubmissionDate: '2026-09-25', entryStatus: 'Pending' };
    for (const [id, value] of Object.entries(fields)) document.getElementById(id).value = value;
    document.getElementById('ierbEntryForm').requestSubmit();
  });
  await waitFor('document.getElementById("ierbEntryModal").getAttribute("aria-hidden") === "true"');
  checkPayload('ierb_api.php', 'save', { id: null, name: 'Fixture Student', studentId: 'F-001', email: 'fixture@example.test', groupId: 'Fixture Group', course: 'Fixture Course', stage: 'Stage 1', research: 'Fixture Research', requirements: 'Fixture Requirements', submissionDate: '2026-09-25', status: 'Pending' }, 'IERB entry preserves API parameter names and stage keys');

  await checkListRequestRaces('admin_notifications.php', 'notifications_api.php');
  await checkListRequestRaces('ierbprog.php', 'ierb_api.php');

  for (const file of pages) {
    await navigate(file, 375, true, 'error');
    await measure(file, 375, true, ' API error');
    const selectors = { 'admin_notifications.php': '#noticeHistory', 'admin_ai.php': '#aiHistory', 'ierbprog.php': '#ierbTableBody', 'account.php': '#activityList' };
    check(await evaluateFunction(selector => {
      const host = document.querySelector(selector);
      return host.textContent.includes('Fixture error') && !host.querySelector('img');
    }, selectors[file]), `${file}: API error is visible and escaped`);
    if (file === 'ierbprog.php') check(await evaluate('document.getElementById("overviewTotal").textContent === "Unavailable" && document.getElementById("ierbRecordCount").textContent === "Unavailable"'), 'IERB load failures are not presented as zero records');
  }

  for (const file of pages) {
    await navigate(file, 375, true, 'empty', 'adviser');
    await measure(file, 375, true, ' adviser');
    if (file === 'admin_ai.php') check(await evaluate('!document.getElementById("stageLabelEditor")'), 'Adviser fixture has no stage label settings');
    if (file === 'ierbprog.php') check(await evaluate('!document.getElementById("addIerbEntry")'), 'Adviser fixture has no add entry action');
  }
  if (process.env.PRISM_TEST_SCREENSHOTS === '1') {
    const screenshots = fs.mkdtempSync(path.join(os.tmpdir(), 'prism-ui-audit-screenshots-'));
    for (const file of ['admin_notifications.php', 'ierbprog.php', 'account.php']) {
      await navigate(file, 1280, false, 'populated');
      if (file === 'admin_notifications.php') {
        await evaluate('document.getElementById("automatedNotice").checked=true; document.getElementById("automatedNotice").dispatchEvent(new Event("change"))');
      }
      const dimensions = await command('Page.getLayoutMetrics');
      const png = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: 1280, height: dimensions.cssContentSize.height, scale: 1 } });
      const destination = path.join(screenshots, file.replace('.php', '.png'));
      fs.writeFileSync(destination, Buffer.from(png.data, 'base64'));
      console.log('Screenshot: ' + destination);
    }
  }
  check(errors.length === 0, 'No browser/PHP fixture runtime exceptions', errors.join(' | '));
  console.log(`${checks} isolated UI checks; ${failures.length} failures.`);
  for (const failure of failures) console.error('FAIL ' + failure);
  console.log('No production PHP, database, email, or AI requests were executed. External fonts/icons were blocked.');
  if (failures.length) process.exitCode = 1;
}

run().catch(e => { console.error(e.stack || e); process.exitCode = 1; }).finally(async () => {
  if (socket?.readyState === WebSocket.OPEN) {
    await command('Browser.close', {}, true).catch(() => {});
    socket.close();
  }
  if (child && child.exitCode === null) {
    await Promise.race([once(child, 'exit'), new Promise(resolve => setTimeout(resolve, 3000))]);
    if (child.exitCode === null) child.kill();
  }
  if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  if (profileDir) {
    const resolved = path.resolve(profileDir);
    assert.equal(path.dirname(resolved), path.resolve(os.tmpdir()));
    assert(path.basename(resolved).startsWith('prism-ui-audit-'));
    fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
  }
});
