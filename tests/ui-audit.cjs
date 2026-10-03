/* Isolated UI regression audit. Run: node tests/ui-audit.cjs
 * Requires PHP and Chrome/Edge, plus Node with built-in WebSocket (22+).
 * Templates are rendered with fixtures: config.php is never loaded. The
 * loopback server serves only explicitly listed templates/assets and mocked APIs.
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
const extraPages = ['dashboard.php', 'research_adviser.php', 'role_portal.php', 'admin_people.php', 'documents.php', 'reports.php'];
const php = process.env.PRISM_TEST_PHP || (process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : 'php');
const browser = process.env.PRISM_TEST_BROWSER || [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
].find(p => fs.existsSync(p));
const stages = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
const attack = '<img src=x onerror="window.__fixtureXss=1">';
const labels = Object.fromEntries(stages.map((s, i) => [s, ['Ethics application', 'Initial review', 'Revision and resubmission', 'Final review', 'Approval certificate', 'Completed'][i]]));
// Read only the pure security helper, never the application configuration.
const policy = spawnSync(php, ['-r', 'require "security.php"; echo json_encode(application_security_headers());'], {
  cwd: root, encoding: 'utf8', windowsHide: true,
});
assert.equal(policy.status, 0, policy.stderr);
assert.equal(policy.stderr, '');
const securityHeaders = Object.fromEntries(JSON.parse(policy.stdout).map(line => {
  const separator = line.indexOf(':');
  return [line.slice(0, separator), line.slice(separator + 1).trim()];
}));
let managementFixture = 'student';
let compactBellFixture = false;
let managementFailure = '';
let academicRecord = null;
let academicSaveError = false;
let setupPendingFixture = false;
let reviewFailure = 0;
let reviewedStatus = null;
let studentSubmitted = false;
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
  const requireConfig = file === 'role_portal.php' ? /require_once __DIR__ \. '\/config\.php';/g : /require __DIR__ \. '\/config\.php';/g;
  assert.equal([...source.matchAll(requireConfig)].length, 1, `${file}: expected one config include`);
  let isolated = source.replace(requireConfig, '/* Configuration replaced by isolated test fixture. */');
  // Exact reviewed path only. Every other include remains forbidden below.
  const navigationInclude = "require __DIR__ . '/includes/prism-navigation.php';";
  assert.equal(isolated.split(navigationInclude).length - 1, file === 'role_portal.php' ? 0 : 1, `${file}: expected one navigation partial`);
  const navigationSource = fs.readFileSync(path.join(root, 'includes/prism-navigation.php'), 'utf8');
  assert(!/\b(?:require|include)(?:_once)?\s*(?:\(|["'$])/i.test(navigationSource), 'Unexpected nested include in navigation partial');
  isolated = isolated.replace(navigationInclude, () => '?>' + navigationSource + '<?php ');
  const academicInclude = "require_once __DIR__ . '/includes/academic_catalog.php';";
  const academicAllowed = ['admin_people.php', 'ierbprog.php'].includes(file);
  assert.equal(isolated.split(academicInclude).length - 1, academicAllowed ? 1 : 0, `${file}: exact academic include count`);
  if (academicAllowed) {
    const academicSource = fs.readFileSync(path.join(root, 'includes/academic_catalog.php'), 'utf8');
    assert(!/\b(?:require|include)(?:_once)?\s*(?:\(|["'$])/i.test(academicSource), 'Unexpected nested academic include');
    isolated = isolated.replace(academicInclude, () => academicSource.replace(/^<\?php\s*/, ''));
  }
  // Only these reviewed presentation paths may be expanded, on these exact pages.
  for (const partial of [
    { include: "require __DIR__ . '/includes/ceu_footer.php';", path: 'includes/ceu_footer.php', pages: ['dashboard.php', 'research_adviser.php', 'role_portal.php'] },
    { include: "require __DIR__ . '/includes/research_resources.php';", path: 'includes/research_resources.php', pages: ['dashboard.php', 'research_adviser.php', 'role_portal.php'] },
  ]) {
    const allowed = partial.pages.includes(file);
    assert.equal(isolated.split(partial.include).length - 1, allowed ? 1 : 0, file + ': exact ' + partial.path + ' include count');
    if (allowed) {
      const partialSource = fs.readFileSync(path.join(root, partial.path), 'utf8');
      assert(!/\b(?:require|include)(?:_once)?\s*(?:\(|["'$])/i.test(partialSource), 'Unexpected nested include in ' + partial.path);
      isolated = isolated.replace(partial.include, () => '?>' + partialSource + '<?php ');
    }
  }
  assert(!/\b(?:require|include)(?:_once)?\s*(?:\(|["'$])/i.test(isolated), `${file}: unexpected include in fixture`);
  const stub = `<?php
    $managementType = '${managementFixture}';
    const STAGE_SEQUENCE = ['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed'];
    $_SESSION = ['account_type' => '${role}', 'user_role' => '${role === 'admin' ? 'RPMS Administrator' : 'Research Adviser'}'];
    function require_login($roles) { return ['id'=>1, 'email'=>'fixture@example.test', 'full_name'=>'UI Audit Fixture', 'role'=>'${role}', 'ref_id'=>'FIXTURE', 'username'=>'fixture']; }
    function db() { return new class { function query($sql) { return new class { function fetchColumn() { return 0; } }; } }; }
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
  if (setupPendingFixture && file === 'ierb_api.php' && action === 'save') {
    return { ok: true, accountCreated: true, setupPending: true, setupMessage: 'Setup pending ' + attack };
  }
  if (file === 'documents_api.php' && action === 'review') {
    if (reviewFailure) return {ok:false,message:'Review rejected '+reviewFailure+' '+attack};
    const payload=JSON.parse(requests.findLast(r=>r.file===file && r.action===action).body);
    reviewedStatus=payload.status;
    return {ok:true,message:'Review saved.'};
  }
  if (scenario === 'error') return { ok: false, message: 'Fixture error ' + attack };
  const populated = scenario === 'populated' || scenario === 'long-labels' || scenario.startsWith('student-');
  if (file==='documents_api.php' && action==='submit_to_rpms') { studentSubmitted=true; return {ok:true,message:'Formally submitted to RPMS.'}; }
  if (file==='documents_api.php' && role==='student') {
    const state=studentSubmitted || scenario==='student-submitted'?'Submitted to RPMS':scenario==='student-ready'?'Ready for Formal RPMS Submission':scenario==='student-revision'?'Needs Revision':'Pending Adviser Review';
    return {ok:true,message:'Document uploaded.',documents:populated?[{id:'fixture-doc',originalName:attack+'LongFileName'.repeat(15)+'.pdf',student:'Fixture Student',studentId:1,documentType:'Research Protocol',stage:'Stage 1',stageLabel:labels['Stage 1'],uploadedAt:'2026-09-24',workflowState:state,reviewStatus:state==='Needs Revision'?'Resubmission Requested':state==='Pending Adviser Review'?'Submitted':'Approved',reviewRemarks:attack,versionNo:1,isCurrent:true,actions:{submit:state==='Ready for Formal RPMS Submission'}}]:[]};
  }
  if(file==='ierb_api.php' && role==='student') return action==='history'?{ok:true,history:[{stage:'Stage 1',status:'On Track',note:attack,actor:attack,created_at:'2026-09-24'}]}:{ok:true,records:populated?[{id:1,name:attack,research:attack,groupId:'Fixture Group',stage:'Stage 1',status:'On Track',progress:20,requirements:attack}]:[]};
  if (action === 'group_options' && ['students_api.php','notifications_api.php'].includes(file)) return {ok:true,groups:populated ? ['AMT-BSIT-Y2-2627-G01','AMT-BSIT-Y2-2627-G02'] : []};
  if (compactBellFixture && file === 'notifications_api.php' && action === 'list') return {ok:true,notifications:Array.from({length:9},(_,i)=>({subject:'Notice '+i+' '+('Long title '.repeat(20)),recipient_name:'Student '+i,status:'Sent',created_at:'2026-10-03 09:00:00',message:'LONG BODY MUST NOT APPEAR'}))};
  if (file === 'notifications_api.php' && action === 'recipients_preview') return {ok:true, recipients:populated ? [{id:1,name:attack,email:'fixture@example.test'}, {id:2,name:'Second recipient',email:'second@example.test'}] : []};
  if (file === 'notifications_api.php') return { ok: true, notifications: populated ? [{ id: 1, subject: attack, type: 'Reminder', recipient_name: attack, recipient_email: 'fixture@example.test', status: 'Sent', created_at: '2026-09-24 08:00:00', message: attack, delivery_info: 'Fixture only' }] : [], total: 1, scheduled: false, sent: 1 };
  if (file === 'advisers_api.php') return {ok:true,advisers:populated?[{id:1,name:attack,employeeId:'A-1',email:'fixture@example.test',status:'Active'}]:[]};
  if (file === 'students_api.php' && action === 'adviser_options') return {ok:true,advisers:[]};
  if (file === 'students_api.php' && action === 'save' && academicSaveError) return {ok:false,message:'Academic validation error '+attack};
  if (file === 'students_api.php' && academicRecord) return {ok:true,students:[academicRecord]};
  if (file === 'students_api.php') return {ok:true,students:populated?[{id:1,name:attack,research:attack,course:'Fixture Course',stage:'Stage 1',status:'On Track'}]:[]};
  if (file === 'documents_api.php' && role === 'adviser') return {ok:true,documents:populated?[{id:'fixture-doc',originalName:attack+'LongFileName'.repeat(15)+'.pdf',student:attack,studentId:1,documentType:'Research Protocol',stage:'Stage 1',uploadedAt:'2026-09-24',workflowState:reviewedStatus==='Approved'?'Ready for Formal RPMS Submission':(['Denied','Resubmission Requested'].includes(reviewedStatus)?'Needs Revision':'Pending Adviser Review'),reviewStatus:reviewedStatus||'Submitted',reviewRemarks:attack,versionNo:1,isCurrent:true,actions:{review:true}}]:[],counts:{}};
  if (file === 'documents_api.php') return { ok:true, documents:populated ? [{ id:'fixture-doc', originalName:attack, student:'Fixture Student', studentId:1, documentType:'Protocol', stage:'Stage 1', stageLabel:'Initial review', uploadedAt:'2026-09-24', workflowState:'Pending Adviser Review', reviewStatus:'Submitted', versionNo:1, isCurrent:true, actions:{review:true} }] : [], counts:{} };
  if (file === 'ierb_api.php' && action === 'needs_attention') return {ok:true, students:[], total:0};
  if (file === 'reports_api.php') return { ok: true, reports: populated ? [{ id: 'fixture-report', title: attack, type: 'AI Summarized Report', generated_at: '2026-09-24 08:00:00', generated_by: attack }] : [], report: { id: 'fixture-report' }, aiUsed: false };
  if (file === 'stage_labels_api.php') return { ok: true, labels: labelsForScenario() };
  if (file === 'ierb_api.php' && action === 'save' && academicSaveError) return {ok:false,message:'Academic validation error '+attack};
  if (file === 'ierb_api.php' && action === 'list' && academicRecord) return {ok:true,records:[academicRecord]};
  if (file === 'ierb_api.php') return { ok: true, records: populated ? Array.from({ length: 6 }, (_, i) => ({ id: i + 1, name: i ? `Student ${i}` : attack, studentId: `S${i + 1}`, email: 'fixture@example.test', groupId: 'A', course:'Fixture Course', stage: i < 4 ? 'Stage 1' : 'Stage 2', status: 'On Track', progress: 20, research: 'Fixture research', requirements: attack, lastSubmissionDate: '2026-09-24' })) : [] };
  if (file === 'audit_api.php') return { ok: true, entries: populated ? [{ id: 1, action: 'document_override', actionLabel: 'Document override', at: '2026-09-24 08:00:00', actorName: attack, actorEmail: 'fixture@example.test', actorRole: role, studentName: attack, protocolCode: 'P-001', override: true, details: 'Fixture details ' + attack, reason: 'Fixture reason ' + attack }] : [] };
  if (file === 'profile_api.php') return { ok: true, user: { name: 'UI Audit Fixture', email: 'fixture@example.test', role, refId: 'FIXTURE' }, message: action === 'change_password' ? 'Password updated.' : 'Updated.' };
  if (file === 'send_followup.php') return { ok: true, message: 'Fixture follow-up sent.' };
  throw new Error(`Unexpected mock endpoint ${file}`);
}

async function serve(req, res) {
  try {
    const url = new URL(req.url, origin);
    const file = url.pathname.slice(1);
    if (pages.includes(file) || extraPages.includes(file)) {
      res.writeHead(200, { ...securityHeaders, 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      res.end(fixtureTemplate(file));
    } else if (/^(notifications|reports|stage_labels|ierb|audit|profile|documents|students|advisers)_api\.php$/.test(file) || file === 'send_followup.php') {
      let body = '';
      for await (const chunk of req) body += chunk;
      requests.push({ file, action: url.searchParams.get('action'), query: Object.fromEntries(url.searchParams), method: req.method, body });
      if (apiDelay) await new Promise(resolve => setTimeout(resolve, apiDelay));
      if (managementFailure && ['students_api.php','advisers_api.php'].includes(file) && url.searchParams.get('action') === 'list') {
        if (managementFailure === 'network') { res.destroy(); return; }
        res.writeHead(managementFailure === 'http' ? 503 : 200, {'Content-Type':'application/json'});
        res.end(managementFailure === 'json' ? '{invalid' : JSON.stringify({ok:true, students:[], advisers:[]}));
        return;
      }
      if (file === 'reports_api.php' && url.searchParams.get('action') === 'file') {
        res.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="fixture.pdf"' });
        res.end('%PDF-1.4\n% Isolated fixture only\n');
        return;
      }
      res.writeHead(file==='documents_api.php' && url.searchParams.get('action')==='review' && reviewFailure ? reviewFailure : 200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify(mockApi(file, url.searchParams.get('action'))));
    } else if (/^assets\/(css|js|images)\/[A-Za-z0-9_.\/-]+$/.test(file)) {
      const absolute = path.resolve(root, file);
      assert(absolute.startsWith(path.join(root, 'assets') + path.sep));
      const types = { '.css': 'text/css', '.js': 'application/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
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
  if(file==='research_adviser.php') reviewedStatus=null;
  if(file==='role_portal.php') studentSubmitted=false;
  await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });
  await command('Page.navigate', { url: `${origin}/${file}?fixture=${Date.now()}` });
  const ready = { 'admin_notifications.php': '#noticeHistory > *', 'admin_ai.php': '#aiHistory > *', 'ierbprog.php': '#stageChart > *', 'account.php': '#activityList > *', 'dashboard.php':'#ierbMonitorBody > *', 'research_adviser.php':'#adviserQueue > *', 'role_portal.php':'#studentDashboardState', 'admin_people.php':'#recordRows > *', 'documents.php':'#documentsTableBody > *', 'reports.php':'#reportTableBody > *' }[file];
  await waitFor(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(ready)})`);
  if (file === 'role_portal.php') await waitFor('document.getElementById("studentDashboardState").getAttribute("aria-busy")==="false"');
  if (file === 'research_adviser.php') await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');
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
              entryResearchTitle: 'Race Research' };
            for (const [id, value] of Object.entries(fields)) document.getElementById(id).value = value;
            for (const [id,value] of Object.entries({entryAcademicUnit:'amt',entryCourse:'bsit',entryYearLevel:'2nd Year',entryAcademicYear:'2026-2027'})) {
              const control=document.getElementById(id); control.value=value; control.dispatchEvent(new Event('change'));
            }
          }
        }, notification);
        if (!notification) {
          await waitFor('[...document.getElementById("entryGroupId").options].some(o=>o.value==="__create__")');
          await evaluate("document.getElementById('entryGroupId').value='__create__'; document.getElementById('ierbEntryForm').requestSubmit()");
        }
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

async function keyPress(key, code, virtualKey) {
  await command('Input.dispatchKeyEvent', { type:'keyDown', key, code, windowsVirtualKeyCode:virtualKey });
  // CDP needs the Enter character event for native button activation.
  if (key === 'Enter') await command('Input.dispatchKeyEvent', { type:'char', key, code, text:'\r', windowsVirtualKeyCode:virtualKey });
  await command('Input.dispatchKeyEvent', { type:'keyUp', key, code, windowsVirtualKeyCode:virtualKey });
}

async function checkNavigation(file, width, dark) {
  const label = `${file} ${width}px ${dark ? 'dark' : 'light'} navigation`;
  check(await evaluateFunction(file => {
    const links = [...document.querySelectorAll('#prismPrimaryNavigation [aria-current="page"]')];
    const ids = [...document.querySelectorAll('[id]')].map(e => e.id);
    return links.length === 1 && links[0].getAttribute('href') === file && new Set(ids).size === ids.length;
  }, file), label + ': unique IDs and correct aria-current');
  await evaluateFunction(() => {
    const toggle = document.getElementById('prismSidebarToggle');
    if (toggle.getAttribute('aria-expanded') === 'true') toggle.click();
    document.getElementById('prismNavResearchToggle').focus();
  });
  await keyPress('Enter', 'Enter', 13);
  check(await evaluate(`document.getElementById('prismSidebarToggle').getAttribute('aria-expanded') === 'true' && document.getElementById('prismNavResearchToggle').getAttribute('aria-expanded') === 'true' && !document.getElementById('prismNavResearch').hidden`), label + ': Enter expands collapsed navigation and nested group');
  await keyPress('Tab', 'Tab', 9);
  check(await evaluate(`document.activeElement === document.querySelector('#prismNavResearch a')`), label + ': nested link is reachable by keyboard');
  await keyPress('Escape', 'Escape', 27);
  check(await evaluate(`document.getElementById('prismNavResearch').hidden && document.getElementById('prismNavResearchToggle').getAttribute('aria-expanded') === 'false' && document.activeElement.id === 'prismNavResearchToggle'`), label + ': Escape closes group and restores trigger focus');
  await keyPress('Escape', 'Escape', 27);
  check(await evaluate(`document.getElementById('prismSidebarToggle').getAttribute('aria-expanded') === 'false' && document.activeElement.id === 'prismSidebarToggle'`), label + ': second Escape collapses sidebar and restores focus');
  await keyPress('Tab', 'Tab', 9);
  check(await evaluate(`!document.activeElement.closest('[hidden]') && document.activeElement.getBoundingClientRect().width > 0 && !!document.activeElement.closest('.prism-sidebar')`), label + ': collapsed rail retains visible keyboard targets');
  check(await evaluate(`document.querySelector('.prism-sidebar').getBoundingClientRect().right <= innerWidth && document.querySelectorAll('.prism-nav-submenu:not([hidden])').length === 0`), label + ': collapsed navigation fits and nested links are hidden');
  await evaluateFunction(() => {
    document.getElementById('prismNavResearchToggle').click();
    document.querySelector('#prismNavResearch a').focus();
    document.getElementById('prismSidebarToggle').click();
  });
  check(await evaluate(`document.activeElement.id === 'prismSidebarToggle' && document.getElementById('prismNavResearch').hidden`), label + ': collapse never strands focus in hidden content');
}

function checkPartialBoundary() {
  const denied = fs.readFileSync(path.join(root, 'includes/.htaccess'), 'utf8');
  check(denied.includes('Require all denied') && denied.includes('Deny from all'), 'Includes directory denies direct Apache access');
  for (const direct of [true, false]) {
    const code = `$_SERVER['SCRIPT_FILENAME'] = ${direct ? "realpath('includes/prism-navigation.php')" : "realpath('account.php')"};
      ${direct ? "$authUser=['role'=>'admin'];" : ''}
      register_shutdown_function(function(){ echo 'status=' . http_response_code(); });
      require 'includes/prism-navigation.php';`;
    const result = spawnSync(php, ['-r', code], { cwd:root, encoding:'utf8', windowsHide:true });
    check(result.status === 0 && result.stderr === '' && result.stdout === 'status=404', `Navigation partial rejects ${direct ? 'direct execution even with context' : 'missing authentication context'}`);
  }
}

async function checkDashboard() {
  for (const width of [375,768,1024,1280,1600]) {
    for (const data of ['empty','populated','error']) {
      await navigate('dashboard.php',width,false,data);
      for (const dark of [false,true]) {
        await evaluateFunction(dark=>document.documentElement.classList.toggle('dark-theme',dark),dark);
        await measure('dashboard.php',width,dark,data);
      }
      check(await evaluateFunction(data=>{
        const value=document.getElementById('totalResearchersMetric').textContent;
        return value === (data==='populated'?'6':data==='error'?'Unavailable':'0');
      },data), `Dashboard ${width}px ${data}: truthful server-backed count`);
    }
  }
  await navigate('dashboard.php',1280,false,'populated');
  check(await evaluate(`document.getElementById('ierbMonitorBody').textContent.includes(${JSON.stringify(attack)}) && !document.querySelector('#ierbMonitorBody img')`), 'Dashboard hostile research/student labels stay literal');
  await evaluateFunction(()=>{const search=document.getElementById('dashboardSearch');search.value='no matching fixture';search.dispatchEvent(new Event('input'));});
  check(await evaluate(`[...document.querySelectorAll('#ierbMonitorBody tr[data-course]')].every(row=>row.style.display==='none')`), 'Dashboard search filters existing authorized records');
  const summariesBefore = requests.filter(r=>r.file==='documents_api.php' && r.action==='summarize').length;
  await evaluateFunction(()=>{
    const search=document.getElementById('dashboardSearch');search.value='';search.dispatchEvent(new Event('input'));
    const link=document.querySelector('[data-monitor-action="documents"]');
    link.addEventListener('click', event=>{event.preventDefault();window.__openDocumentsActivated=true;},{once:true});link.focus();
  });
  await keyPress('Enter','Enter',13);
  await waitFor('window.__openDocumentsActivated === true');
  check(await evaluateFunction(()=>{
    const link=document.querySelector('[data-monitor-action="documents"]');
    return link.tagName==='A' && link.getAttribute('href')==='documents.php' && link.textContent==='Open Documents' && !link.hasAttribute('data-id');
  }) && requests.filter(r=>r.file==='documents_api.php' && r.action==='summarize').length===summariesBefore, 'Student-row Open Documents link is keyboard accessible and never substitutes a student ID for a document ID');
  check(await evaluate(`!!document.querySelector('#recentAiReportList a[rel*="noopener"]')`), 'Dashboard PDF history is a secure keyboard link');
}

async function checkTonightPolish() {
  compactBellFixture = true;
  for (const width of [375,1280]) {
    for (const dark of [false,true]) {
      await navigate('dashboard.php',width,dark,'populated');
      await waitFor('document.querySelectorAll("#notificationList li").length === 5');
      await evaluate('document.getElementById("notificationToggle").click()');
      check(await evaluateFunction(()=>{
        const menu=document.getElementById('notificationDropdown'), list=document.getElementById('notificationList');
        const box=menu.getBoundingClientRect();
        return menu.classList.contains('show') && box.left>=0 && box.right<=innerWidth
          && list.scrollWidth<=list.clientWidth+1 && getComputedStyle(list).overflowY==='auto'
          && list.querySelectorAll('time').length===5 && !list.textContent.includes('LONG BODY')
          && getComputedStyle(list.querySelector('strong')).textOverflow==='ellipsis'
          && document.querySelector('.notification-view-all').getAttribute('href')==='admin_notifications.php'
          && !document.getElementById('summaryModal') && !document.body.textContent.includes('Summarize Document');
      }), `Compact bell ${width}px ${dark?'dark':'light'}: five separated, truncated notices and View all`, JSON.stringify(await evaluateFunction(()=>{
        const menu=document.getElementById('notificationDropdown'), list=document.getElementById('notificationList');
        return {box:menu.getBoundingClientRect().toJSON(),viewport:innerWidth,scroll:list.scrollWidth,client:list.clientWidth,overflow:getComputedStyle(list).overflowY,ellipsis:getComputedStyle(list.querySelector('strong')).textOverflow};
      })));
    }
  }
  compactBellFixture = false;
  await navigate('admin_notifications.php',375,true,'empty');
  await waitFor('!document.getElementById("noticeGroup").textContent.includes("Loading")');
  check(await evaluate('document.getElementById("noticeGroup").tagName==="SELECT" && document.getElementById("noticeGroup").disabled && document.getElementById("noticeGroup").textContent.includes("No standardized")'), 'Empty group selector has no free-text fallback');
  academicRecord = {id:1,studentId:'S1',name:'Fixture Student',email:'fixture@example.test',group:'Legacy group',course:'Legacy course',stage:'Stage 1',status:'On Track'};
  await navigate('admin_people.php',1280,false,'populated');
  await evaluate('document.querySelector("#recordRows button[title=Edit]").click()');
  check(await evaluate('document.getElementById("group").tagName==="SELECT" && document.getElementById("group").value==="Legacy group"'), 'Legacy group is preserved in a selector');
  await evaluateFunction(()=>{
    for(const [id,value] of Object.entries({academicUnit:'amt',course:'bsit',yearLevel:'2nd Year',academicYear:'2026-2027'})) {
      const el=document.getElementById(id); el.value=value; el.dispatchEvent(new Event('change'));
    }
  });
  await waitFor('[...document.getElementById("group").options].some(o=>o.value==="__create__")');
  check(await evaluate('document.getElementById("group").value==="Legacy group" && [...document.getElementById("group").options].some(o=>o.value==="AMT-BSIT-Y2-2627-G01")'), 'Cohort options and no-text create preserve legacy selection');
  check(await evaluate('!document.getElementById("group").required'), 'Student Management group remains optional');
  academicRecord = {...academicRecord,group:'',groupId:''};
  await navigate('ierbprog.php',1280,false,'populated');
  await evaluate(`document.querySelector('#ierbTableBody button[title="Edit entry"]').click()`);
  check(await evaluate('!document.getElementById("entryGroupId").required && document.getElementById("entryGroupId").checkValidity()'), 'Legacy IERB records may retain an empty group');
  await evaluate('document.getElementById("cancelIerbEntry").click(); document.getElementById("addIerbEntry").click()');
  check(await evaluate('document.getElementById("entryGroupId").required && document.getElementById("entryGroupId").validity.valueMissing'), 'New IERB records require a group after switching from edit to create');
  await evaluateFunction(()=>{
    for(const [id,value] of Object.entries({entryStudentName:'New Student',entryStudentId:'NEW-1',entryEmail:'new@example.test',entryResearchTitle:'Research',entryAcademicUnit:'amt',entryCourse:'bsit',entryYearLevel:'2nd Year',entryAcademicYear:'2026-2027'})) {
      const el=document.getElementById(id); el.value=value; el.dispatchEvent(new Event('change'));
    }
  });
  await waitFor('[...document.getElementById("entryGroupId").options].some(o=>o.value==="__create__")');
  const savesBefore = requests.filter(r=>r.file==='ierb_api.php' && r.action==='save').length;
  await evaluate('document.getElementById("ierbEntryForm").requestSubmit()');
  check(requests.filter(r=>r.file==='ierb_api.php' && r.action==='save').length===savesBefore, 'Blank group blocks new IERB submission in the browser');
  for (const selection of ['AMT-BSIT-Y2-2627-G01','__create__']) {
    check(await evaluateFunction(selection=>{
      document.getElementById('entryGroupId').value=selection;
      return document.getElementById('ierbEntryForm').checkValidity();
    }, selection), 'Existing compatible group or Create New Group satisfies IERB requiredness');
  }
  academicRecord = null;
  for (const file of ['documents.php','reports.php']) {
    await navigate(file,1280,false,'populated');
    check(await evaluate('!document.querySelector("[data-summary],#summaryModal,#openDocumentReport,#documentModal")'), file + ': document-summary controls removed without runtime failure');
  }
  const reportCalls = requests.filter(r=>r.file==='reports_api.php' && r.action==='ai_report').length;
  await evaluate('document.getElementById("generateSummarizedReport").click()');
  await waitFor('!document.getElementById("generateSummarizedReport").disabled');
  check(requests.filter(r=>r.file==='reports_api.php' && r.action==='ai_report').length===reportCalls+1, 'Reports aggregate summary still generates after document-summary removal');
}

async function checkNotificationComposer() {
  for (const dark of [false, true]) {
    for (const width of [375,768,1024,1280,1600]) {
      await navigate('admin_notifications.php', width, dark, 'populated');
      await waitFor('document.getElementById("noticeRecipientPreview").textContent.includes("2 matching")');
      check(await evaluateFunction(() => {
        const trigger=document.querySelector('[data-notice-detail]'); trigger.focus(); trigger.click();
        return document.getElementById('notificationDetail').open;
      }), `Notifications ${width}px: native details opens`);
      check(await evaluateFunction(attack => {
        const d=document.getElementById('notificationDetail'), r=d.getBoundingClientRect();
        return r.left>=0 && r.right<=innerWidth && d.scrollWidth<=d.clientWidth+1 && !d.querySelector('img')
          && document.getElementById('notificationDetailMessage').textContent===attack
          && d.textContent.includes('fixture@example.test') && d.textContent.includes('Fixture only');
      }, attack), `Notifications ${width}px ${dark?'dark':'light'}: full details fit and remain text`);
      await keyPress('Escape','Escape',27);
      check(await evaluate('!document.getElementById("notificationDetail").open && document.activeElement.matches("[data-notice-detail]")'), 'Notification details Escape restores trigger focus');
    }
  }
  await evaluateFunction(() => {
    const m=document.getElementById('noticeMessage'); m.value='A'+String.fromCodePoint(0x1f642); m.dispatchEvent(new Event('input'));
  });
  check(await evaluate('document.getElementById("noticeMessageCount").textContent.startsWith("2 / 600")'), 'Notification counter counts Unicode codepoints');
  await evaluateFunction(() => {
    document.getElementById('noticeAudience').value='Specific Research Group'; document.getElementById('noticeAudience').dispatchEvent(new Event('change'));
    document.getElementById('noticeGroup').value='AMT-BSIT-Y2-2627-G01'; document.getElementById('noticeGroup').dispatchEvent(new Event('change'));
  });
  await waitFor('document.getElementById("noticeRecipientPreview").textContent.includes("2 matching")');
  checkPayload('notifications_api.php','recipients_preview',{audience:'Specific Research Group',group:'AMT-BSIT-Y2-2627-G01'},'Recipient preview uses existing POST contract');
  await evaluate('document.getElementById("cancelNotification").click()');
  await waitFor('document.getElementById("noticeMessageCount").textContent.startsWith("0 / 600")');
  check(await evaluate('document.getElementById("noticeMessage").value==="" && document.getElementById("groupLabel").hidden && document.getElementById("scheduleLabel").hidden'), 'Composer cancel resets fields and conditional controls');
  await navigate('admin_notifications.php',375,true,'populated','adviser');
  check(await evaluate('document.getElementById("noticeAudience").options[0].textContent==="My Assigned Students" && document.getElementById("noticeAudience").value==="All Students"'),'Adviser wording preserves scoped server audience key');
  await waitFor('document.getElementById("noticeRecipientPreview").getAttribute("aria-busy")==="false"');
  await navigate('admin_notifications.php',375,false,'error');
  await waitFor('document.getElementById("noticeRecipientPreview").getAttribute("aria-busy")==="false"');
  check(await evaluate('document.getElementById("noticeRecipientPreview").textContent.includes("Could not preview") && !document.getElementById("noticeRecipientPreview").querySelector("img")'), 'Recipient preview failure is safe and distinct from zero recipients');
  const hook=await command('Page.addScriptToEvaluateOnNewDocument',{source:`(() => {
    const original=window.fetch; window.__previewRace=[];
    window.fetch=function(input,options) {
      const u=new URL(typeof input==='string'?input:input.url,location.href);
      if(u.origin===location.origin && u.pathname==='/notifications_api.php' && u.searchParams.get('action')==='recipients_preview') return new Promise(resolve=>window.__previewRace.push(resolve));
      return original.call(this,input,options);
    };
  })();`});
  try {
    await navigate('admin_notifications.php',1280,false,'populated');
    await waitFor('window.__previewRace.length===1');
    await evaluateFunction(() => {
      const a=document.getElementById('noticeAudience'); a.value='Specific Research Group'; a.dispatchEvent(new Event('change'));
      const g=document.getElementById('noticeGroup'); g.value='AMT-BSIT-Y2-2627-G02'; g.dispatchEvent(new Event('change'));
      window.__previewRace[0](new Response(JSON.stringify({ok:true,recipients:Array(99).fill({})}),{headers:{'Content-Type':'application/json'}}));
      return new Promise(resolve=>requestAnimationFrame(resolve));
    });
    check(await evaluate('!document.getElementById("noticeRecipientPreview").textContent.includes("99 matching")'), 'Changed audience invalidates preview before debounce completes');
    await waitFor('window.__previewRace.length===2');
    await evaluateFunction(() => window.__previewRace[1](new Response(JSON.stringify({ok:true,recipients:[{}]}),{headers:{'Content-Type':'application/json'}})));
    await waitFor('document.getElementById("noticeRecipientPreview").textContent.includes("1 matching")');
    check(true,'Latest recipient preview replaces stale request');
  } finally { await command('Page.removeScriptToEvaluateOnNewDocument',{identifier:hook.identifier}); }
}

async function checkAdviserDashboard() {
  const source=fs.readFileSync(path.join(root,'research_adviser.php'),'utf8');
  check(source.indexOf("require_login('adviser')")>0 && source.indexOf("require_login('adviser')")<source.indexOf('<!DOCTYPE'), 'Adviser page applies existing role guard before HTML output');
  for(const width of [375,768,1024,1280,1600]) for(const dark of [false,true]) for(const state of ['empty','populated','error']) {
    await navigate('research_adviser.php',width,dark,state,'adviser');
    await waitFor('document.getElementById("adviserStudentsCount").textContent!=="..." && document.getElementById("adviserQueue").getAttribute("aria-busy")!=="true"');
    await measure('research_adviser.php',width,dark,' '+state);
    check(await evaluateFunction(() => { const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);return ids.length===new Set(ids).size; }), 'Adviser dashboard has unique IDs');
    if(state==='error') check(await evaluate('document.getElementById("adviserQueue").textContent.includes("Could not load submissions") && document.getElementById("adviserStudentsCount").textContent!=="0"'), 'Adviser failure is distinct from empty counts');
  }
  await navigate('research_adviser.php',375,true,'populated','adviser');
  await checkNavigation('research_adviser.php',375,true);
  check(await evaluate('document.querySelector(".prism-nav-home[aria-current=page]")?.getAttribute("href")==="research_adviser.php"'), 'Adviser dashboard link is current');
  check(await evaluateFunction(attack=>document.getElementById('adviserQueue').textContent.includes(attack) && !document.querySelector('#adviserQueue img'),attack),'Adviser queue hostile names remain literal');
  await evaluateFunction(()=>{const e=document.getElementById('adviserQueueSearch');e.value='No matching fixture';e.dispatchEvent(new Event('input'));});
  check(await evaluate('!document.querySelector("[data-review-id]")'), 'Adviser search filters current scoped results');
  await evaluateFunction(()=>{const e=document.getElementById('adviserQueueSearch');e.value='';e.dispatchEvent(new Event('input'));const f=document.getElementById('adviserQueueFilter');f.value='Needs Revision';f.dispatchEvent(new Event('change'));});
  check(await evaluate('!document.querySelector("[data-review-id]")'), 'Adviser workflow filter uses existing stage-independent key');
  await evaluateFunction(()=>{const f=document.getElementById('adviserQueueFilter');f.value='';f.dispatchEvent(new Event('change'));const b=document.querySelector('[data-review-id]');b.focus();b.click();});
  check(await evaluate('document.getElementById("adviserReviewDialog").open'),'Adviser review dialog opens');
  await keyPress('Escape','Escape',27);
  check(await evaluate('!document.getElementById("adviserReviewDialog").open && document.activeElement.matches("[data-review-id]")'), 'Adviser review Escape returns focus');
  for(const status of ['Approved','Resubmission Requested','Denied']) {
    await navigate('research_adviser.php',1280,false,'populated','adviser');
    apiDelay=150;
    await evaluateFunction(status=>{document.querySelector('[data-review-id]').click();document.getElementById('adviserReviewStatus').value=status;document.getElementById('adviserReviewStatus').dispatchEvent(new Event('change'));document.getElementById('adviserReviewRemarks').value='  Reviewed fixture  ';document.getElementById('adviserReviewForm').requestSubmit();},status);
    check(await evaluate('document.getElementById("adviserReviewSave").disabled && document.getElementById("adviserReviewCancel").disabled'),'Adviser review prevents duplicate writes and pending cancellation');
    await waitFor('!document.getElementById("adviserReviewDialog").open');apiDelay=0;
    checkPayload('documents_api.php','review',{id:'fixture-doc',status,remarks:'Reviewed fixture'},'Adviser '+status+' preserves review payload');
    await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")!=="true"');
    check(await evaluateFunction(status=>document.getElementById('adviserQueue').textContent.includes(status==='Approved'?'Ready for Formal RPMS Submission':'Needs Revision'),status),'Adviser queue reloads authoritative workflow state');
    check(await evaluate('document.activeElement.id==="adviserQueueSearch"'), 'Successful review keeps focus on a stable queue control');
  }
  for(const code of [403,404,409,500]) {
    await navigate('research_adviser.php',375,true,'populated','adviser'); reviewFailure=code;
    await evaluateFunction(()=>{document.querySelector('[data-review-id]').click();document.getElementById('adviserReviewStatus').value='Approved';document.getElementById('adviserReviewRemarks').value='Keep these remarks';document.getElementById('adviserReviewForm').requestSubmit();});
    await waitFor('!document.getElementById("adviserReviewSave").disabled');
    check(await evaluateFunction(code=>document.getElementById('adviserReviewDialog').open && document.getElementById('adviserReviewRemarks').value==='Keep these remarks' && document.getElementById('adviserReviewError').textContent.includes(String(code)) && !document.querySelector('#adviserReviewError img'),code),'Adviser '+code+' keeps remarks and safe error');
    reviewFailure=0;
  }
}

async function checkStudentPortal() {
  for(const file of ['student.php','role_portal.php']) {
    const source=fs.readFileSync(path.join(root,file),'utf8');
    check(source.includes("require_login('student')"),file+': student role guard remains');
  }
  const baseline=spawnSync('git',['show','HEAD:role_portal.php'],{cwd:root,encoding:'utf8',windowsHide:true});
  const idsOf=source=>[...source.matchAll(/(?<![-\w])id="([^"<]+)"/g)].map(m=>m[1]);
  const before=idsOf(baseline.stdout), after=idsOf(fs.readFileSync(path.join(root,'role_portal.php'),'utf8'));
  check(before.every(id=>after.includes(id)), 'Student portal retains every existing static ID');
  for(const width of [375,768,1024,1280,1600]) for(const dark of [false,true]) for(const state of ['empty','populated','error','student-ready','student-revision','student-submitted']) {
    await navigate('role_portal.php',width,dark,state,'student');
    await measure('role_portal.php',width,dark,' '+state);
    check(await evaluateFunction(()=>{const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);return ids.length===new Set(ids).size;}),'Student portal IDs are unique');
    if(state==='error') check(await evaluate('document.getElementById("dashboardSubmissionValue").textContent.includes("Unavailable") && document.getElementById("studentDashboardState").textContent.length>0'),'Student errors are distinct from no records');
    if(state==='student-ready') {
      await waitFor('!!document.querySelector("[data-prism-student-workflow=compact] [data-submit]")');
      check(await evaluate('!document.querySelector("[data-prism-student-workflow=compact] [data-submit]").disabled'),'Real formal-submission control stays available');
    }
  }
  for(const width of [375,1280]) for(const dark of [false,true]) {
    await navigate('role_portal.php',width,dark,'populated','student');
    for(const page of ['progress','submit','documents','notifications','calendar','profile']) {
      await evaluateFunction(page=>document.querySelector('[data-go="'+page+'"],[data-page="'+page+'"]').click(),page);
      await measure('role_portal.php',width,dark,' section '+page);
      check(await evaluateFunction(page=>document.querySelector('.portal-page.active')?.dataset.section===page,page),'Student '+page+' remains reachable');
    }
  }
  await navigate('role_portal.php',375,true,'populated','student');
  check(await evaluate('document.getElementById("portalNavigationToggle").getAttribute("aria-expanded")==="false" && document.getElementById("portalNav").hidden'),'Student mobile navigation starts collapsed');
  await evaluate('document.getElementById("portalNavigationToggle").click();document.querySelector("#portalNav [data-page=progress]").focus()');
  await keyPress('Enter','Enter',13);
  check(await evaluate('document.querySelector("#portalNav [data-page=progress]").getAttribute("aria-current")==="page"'),'Student keyboard navigation sets aria-current');
  await evaluate('document.getElementById("portalNavigationToggle").click()');
  // Ensure open before exercising Escape; selecting a page may close mobile navigation.
  await evaluate('if(document.getElementById("portalNav").hidden)document.getElementById("portalNavigationToggle").click();document.querySelector("#portalNav button").focus()');
  await keyPress('Escape','Escape',27);
  check(await evaluate('document.getElementById("portalNav").hidden && document.activeElement.id==="portalNavigationToggle"'),'Student Escape closes navigation and restores visible focus');
  await evaluate('document.getElementById("portalProfileToggle").click()');
  check(await evaluate('document.getElementById("portalProfileToggle").getAttribute("aria-expanded")==="true" && !document.getElementById("portalProfileLinks").hidden'),'Student profile opens with explicit expanded state');
  await keyPress('Escape','Escape',27);
  check(await evaluate('document.getElementById("portalProfileLinks").hidden && document.activeElement.id==="portalProfileToggle"'),'Student profile Escape restores focus');
  await navigate('role_portal.php',375,false,'student-ready','student');
  await waitFor('!!document.querySelector("[data-prism-student-workflow=compact] [data-submit]")');
  await evaluate('document.querySelector("[data-prism-student-workflow=compact] [data-submit]").click()');
  await waitFor('!!document.querySelector(".prism-dialog [data-act=ok]")');
  await evaluate('document.querySelector(".prism-dialog [data-act=ok]").click()');
  await waitFor('document.getElementById("dashboardSubmissionStatus").textContent.includes("Submitted to RPMS")');
  const submit=requests.findLast(r=>r.file==='documents_api.php' && r.action==='submit_to_rpms');
  check(submit?.method==='POST' && submit.query.id==='fixture-doc' && submit.body==='{}','Student formal submission preserves endpoint, document ID and payload');
  check(await evaluate('!document.querySelector("[data-prism-student-workflow=compact] [data-submit]")'),'Formal submission refresh removes completed action');
  await evaluateFunction(()=>{
    document.querySelector('[data-go="submit"]').click();
    document.getElementById('submissionResearchTitle').value='Fixture title';document.getElementById('submissionResearchGroup').value='Fixture group';
    document.getElementById('documentType').value='Research Protocol';document.getElementById('documentNotes').value='Fixture notes';
    const transfer=new DataTransfer();transfer.items.add(new File(['Fixture file'],'fixture.txt',{type:'text/plain'}));document.getElementById('documentFile').files=transfer.files;
    document.getElementById('submissionForm').requestSubmit();
  });
  await waitFor('document.querySelector(".portal-page.active").dataset.section==="documents" && !document.querySelector("#submissionForm button[type=submit]").disabled');
  const upload=requests.findLast(r=>r.file==='documents_api.php' && r.action==='upload');
  check(upload?.method==='POST' && ['name="document"','filename="fixture.txt"','name="documentType"','Research Protocol','name="stage"','Stage 1','name="notes"','Research title: Fixture title | Group: Fixture group | Fixture notes'].every(part=>upload.body.includes(part)),'Student upload keeps existing multipart names and notes composition');
}


async function checkRemediation() {
  for (const kind of ['student','adviser']) {
    managementFixture = kind;
    for (const failure of ['api','http','json','network']) {
      managementFailure = failure === 'api' ? '' : failure;
      await navigate('admin_people.php',375,false,failure === 'api' ? 'error' : 'populated');
      check(await evaluate('document.querySelector("#recordRows [role=alert]")?.textContent.includes("Could not load") && !document.getElementById("recordRows").textContent.includes("records found")'), kind+' '+failure+': persistent failure differs from empty');
      check(await evaluate('document.getElementById("recordCount").textContent === "Records unavailable"'), kind+' '+failure+': no false zero count');
      managementFailure = ''; scenario = 'populated';
      await evaluate('document.querySelector("#recordRows button").click()');
      await waitFor('!!document.querySelector("#recordRows .prism-badge")');
      check(await evaluate('!document.querySelector("#recordRows [role=alert]")'),kind+' '+failure+': retry restores actual records');
    }
  }
  managementFixture = 'student'; managementFailure = '';
  for (const width of [375,768,1024,1280,1600]) for (const dark of [false,true]) {
    for (const [file,viewer,selector] of [
      ['admin_people.php','admin','#recordRows .prism-badge'],
      ['ierbprog.php','admin','#ierbTableBody .prism-badge'],
      ['admin_notifications.php','admin','#noticeHistory .prism-badge'],
      ['account.php','admin','#activityList .prism-badge'],
      ['research_adviser.php','adviser','#adviserQueue .prism-badge'],
    ]) {
      await navigate(file,width,dark,'populated',viewer);
      check(await evaluateFunction(selector => {
        const pill=document.querySelector(selector); if(!pill) return false;
        const style=getComputedStyle(pill);
        // Flex/grid items blockify inline-flex to flex in computed style.
        return ['inline-flex','flex'].includes(style.display) && parseFloat(style.borderRadius)>=100 && !!pill.textContent.trim();
      },selector),file+' '+width+' '+dark+': shared readable status pill');
      await measure(file,width,dark,' status pills');
    }
    check(await evaluateFunction(value => {
      const host=document.createElement('div');host.style.cssText='width:180px;overflow-x:auto';document.body.append(host);
      for(const label of ['Active','Inactive','Pending Activation','Sent','Scheduled','Sending','Failed','Logged','Ready for Formal RPMS Submission',value]) host.append(PrismUI.badgeElement(label));
      const safe=!host.querySelector('img') && host.textContent.includes(value);
      const whole=[...host.children].every(p=>{
        const range=document.createRange();range.selectNodeContents(p.querySelector('span'));
        return range.getClientRects().length===1 && getComputedStyle(p).whiteSpace==='nowrap';
      });
      const fits=host.getBoundingClientRect().width<=181 && document.documentElement.scrollWidth<=innerWidth+1;
      host.remove();return safe && fits && whole;
    },attack),'Shared labels stay whole and escaped in a scroll container '+width+' '+dark);
    await navigate('dashboard.php',width,dark,'populated');
    check(await evaluateFunction(()=>{
      const row=document.querySelector('#ierbMonitorBody tr[data-course]');
      return row.cells[3].firstElementChild.classList.contains('stage-tag')
        && !row.cells[3].firstElementChild.classList.contains('prism-badge')
        && !!row.cells[6].querySelector('.prism-badge');
    }),'Dashboard stage tag remains separate from status pill '+width+' '+dark);
    const labelResults=await evaluateFunction(()=>{
      const row=document.querySelector('#ierbMonitorBody tr[data-course]');
      const stage=row.cells[3].querySelector('.stage-tag');stage.textContent='Protocol Submission';
      const host=row.cells[6];host.replaceChildren();
      const labels=[stage];
      for(const value of ['On Track','Pending Activation','Ready for Formal RPMS Submission']) {
        const pill=PrismUI.badgeElement(value);host.append(pill);labels.push(pill.querySelector('span'));
      }
      return labels.map(label=>{
        const range=document.createRange();range.selectNodeContents(label);
        return {text:label.textContent,lines:range.getClientRects().length,nowrap:getComputedStyle(label).whiteSpace==='nowrap'};
      });
    });
    for(const label of labelResults) check(label.lines===1 && label.nowrap,
      label.text+' has no internal wrapping '+width+' '+dark,JSON.stringify(label));
    check(await evaluateFunction(()=>{
      const scroller=document.querySelector('.dashboard-table-scroll'),table=scroller.querySelector('table');
      const overflow=getComputedStyle(scroller).overflowX;
      scroller.scrollLeft=scroller.scrollWidth;
      const action=table.querySelector('[data-monitor-action="documents"]');
      return ['auto','scroll'].includes(overflow) && getComputedStyle(table).display==='table'
        && parseFloat(getComputedStyle(table).minWidth)>=1100
        && (scroller.scrollWidth<=scroller.clientWidth || scroller.scrollLeft>0)
        && action.getBoundingClientRect().right<=scroller.getBoundingClientRect().right+1;
    }),'Progress table scrolls to accessible actions '+width+' '+dark);
    await measure('dashboard.php',width,dark,' whole-label scrolling');
  }
}

async function checkAcademicManagement() {
  const select = async (id, value) => evaluateFunction((id, value) => {
    const field = document.getElementById(id); field.value = value; field.dispatchEvent(new Event('change', {bubbles:true}));
  }, id, value);
  for (const width of [375,768,1024,1280,1600]) for (const dark of [false,true]) {
    await navigate('admin_people.php', width, dark, 'populated');
    await measure('admin_people.php', width, dark, ' academic form');
    await evaluate('document.getElementById("addRecord").focus(); document.getElementById("addRecord").click()');
    check(await evaluate('document.activeElement.id === "recordName"'), 'Academic add form receives focus');
    check(await evaluate('document.getElementById("academicYear").value === ""'), 'Academic Year has no inferred default');
    await select('academicYear','2026-2027');
    await select('academicUnit','dentistry');
    await select('course','ddm');
    await select('yearLevel','6th Year');
    check(await evaluate('document.getElementById("yearLevel").value === "6th Year"'), 'Six-year program accepts sixth year');
    await select('academicUnit','amt');
    check(await evaluate('document.getElementById("course").value === "" && document.getElementById("yearLevel").value === ""'), 'Unit change clears incompatible program and year');
    await select('course','bsit');
    check(await evaluate('[...document.getElementById("yearLevel").options].map(o=>o.value).join("|") === "|1st Year|2nd Year|3rd Year|4th Year"'), 'Four-year program offers only allowed years');
    await select('yearLevel','2nd Year');
    await select('course','bsa');
    check(await evaluate('document.getElementById("yearLevel").value === "2nd Year" && document.getElementById("academicYear").value === "2026-2027"'), 'Compatible year survives program change; Academic Year is independent');
    await select('academicUnit','ihtm'); await select('course','bsihm_hotel');
    check(await evaluate('document.getElementById("academicSummary").textContent.includes(window.PRISM_ACADEMIC_CATALOG.programs.bsihm_hotel.label)'), 'Full 102-character program label remains visible');
    const geometry = await evaluate(`(() => {
      const host=document.getElementById('recordModal');
      return {page:document.documentElement.scrollWidth, outside:[...host.querySelectorAll('input,select,button,p')].filter(e=>e.getClientRects().length && (e.getBoundingClientRect().left < -1 || e.getBoundingClientRect().right > innerWidth+1)).map(e=>e.id || e.tagName), ids:[...document.querySelectorAll('[id]')].map(e=>e.id), xss:!!window.__fixtureXss};
    })()`);
    check(geometry.page<=width+1 && !geometry.outside.length, `Academic form fits ${width}px ${dark?'dark':'light'}`, JSON.stringify(geometry.outside));
    check(new Set(geometry.ids).size===geometry.ids.length && !geometry.xss, 'Academic IDs unique and hostile values inert');
    await select('academicUnit','__graduate__'); await select('course','mba_thesis');
    check(await evaluate('document.getElementById("yearLevel").disabled && document.getElementById("yearLevel").value === ""'), 'Graduate program has no invented year level');
    await evaluate('document.getElementById("academicUnit").focus()');
    await keyPress('Tab','Tab',9);
    check(await evaluate('document.activeElement.id === "course"'), 'Native academic selects are keyboard reachable');
    await keyPress('Escape','Escape',27);
    check(await evaluate('document.getElementById("recordModal").style.display === "none" && document.activeElement.id === "addRecord"'), 'Escape closes form and returns focus');
  }
  const base={id:1,studentId:'S1',name:'Fixture Student',email:'fixture@example.test',research:'Research',group:'Group',stage:'Stage 1',status:'On Track'};
  for (const empty of [null,'']) {
    academicRecord={...base,course:attack,academicUnitKey:empty,programKey:empty,yearLevel:empty,academicYear:empty};
    await navigate('admin_people.php',375,true,'populated');
    await evaluate('document.querySelector("#recordRows button[title=Edit]").click()');
    check(await evaluateFunction(attack=>document.getElementById('academicSummary').textContent.includes(attack) && !document.getElementById('studentAcademicFields').querySelector('img,script'),attack),'Legacy course displayed literally');
    await evaluate('document.getElementById("recordForm").requestSubmit()');
    await waitFor('document.getElementById("recordModal").style.display === "none"');
    const payload=JSON.parse(requests.findLast(r=>r.file==='students_api.php'&&r.action==='save').body);
    check(['course','academicUnitKey','programKey','yearLevel','academicYear'].every(key=>!Object.hasOwn(payload,key)), 'Unrelated legacy edit omits academic values for locked server preservation');
  }
  academicRecord={...base,course:'BS in Information Technology',academicUnitKey:'amt',programKey:'bsit',yearLevel:'2nd Year',academicYear:'2026-2027'};
  await navigate('admin_people.php',768,false,'populated','adviser');
  await evaluate('document.querySelector("#recordRows button[title=Edit]").click()');
  check(await evaluate('["academicUnit","course","yearLevel","academicYear"].map(id=>document.getElementById(id).value).join("|") === "amt|bsit|2nd Year|2026-2027"'),'Edit preselects complete academic tuple');
  await select('academicUnit','nursing');
  check(await evaluate('!document.getElementById("recordForm").checkValidity()'), 'Changed incomplete tuple fails client validation');
  await evaluate('document.querySelector("[data-academic=reset]").click()');
  check(await evaluate('document.getElementById("course").value === "bsit" && document.activeElement.id === "academicUnit"'),'Keep existing restores academic controls and focus');
  await select('course','bsa');
  academicSaveError=true;
  await evaluate('document.getElementById("recordForm").requestSubmit()');
  await waitFor('document.querySelector(".prism-toast-wrap")?.textContent.includes("Academic validation error")');
  check(await evaluateFunction(attack=>document.getElementById('recordModal').style.display==='flex' && document.getElementById('course').value==='bsa' && document.querySelector('.prism-toast-wrap').textContent.includes(attack) && !document.querySelector('.prism-toast-wrap img'),attack),'Server validation error preserves selections and renders literally');
  academicSaveError=false; academicRecord=null;
}


async function checkAcademicIerb() {
  const select = async (id,value) => evaluateFunction((id,value)=>{
    const field=document.getElementById(id);field.value=value;field.dispatchEvent(new Event('change',{bubbles:true}));
  },id,value);
  for(const width of [375,768,1024,1280,1600]) for(const dark of [false,true]) {
    await navigate('ierbprog.php',width,dark,'populated');
    await evaluate('document.getElementById("addIerbEntry").focus();document.getElementById("addIerbEntry").click()');
    check(await evaluate('document.activeElement.id === "entryStudentName"'),'IERB academic modal initially focuses name');
    check(await evaluate('document.getElementById("entryAcademicYear").value === ""'),'IERB Academic Year has no automatic default');
    await select('entryAcademicYear','2027-2028'); await select('entryAcademicUnit','pmt');
    await select('entryCourse','bs_clinical_pharmacy'); await select('entryYearLevel','5th Year');
    check(await evaluate('document.getElementById("entryYearLevel").value === "5th Year"'),'IERB five-year program offers fifth year');
    await select('entryCourse','bs_pharmacy');
    check(await evaluate('document.getElementById("entryYearLevel").value === "" && document.getElementById("entryAcademicYear").value === "2027-2028"'),'IERB program change clears incompatible fifth year without clearing Academic Year');
    await select('entryAcademicUnit','ihtm'); await select('entryCourse','bsihm_hotel');
    check(await evaluate('document.getElementById("entryAcademicSummary").textContent.includes(window.PRISM_ACADEMIC_CATALOG.programs.bsihm_hotel.label)'),'IERB exposes full long program label');
    const geometry=await evaluate(`(() => {
      const host=document.getElementById('ierbEntryModal');
      return {page:document.documentElement.scrollWidth,outside:[...host.querySelectorAll('input,select,button,p')].filter(e=>e.getClientRects().length && (e.getBoundingClientRect().left < -1 || e.getBoundingClientRect().right > innerWidth+1)).map(e=>e.id||e.tagName),ids:[...document.querySelectorAll('[id]')].map(e=>e.id),xss:!!window.__fixtureXss};
    })()`);
    check(geometry.page<=width+1 && !geometry.outside.length,`IERB academic form fits ${width}px ${dark?'dark':'light'}`,JSON.stringify(geometry.outside));
    check(new Set(geometry.ids).size===geometry.ids.length && !geometry.xss,'IERB academic IDs unique and hostile values inert');
    await evaluate('document.getElementById("entryAcademicUnit").focus()'); await keyPress('Tab','Tab',9);
    check(await evaluate('document.activeElement.id === "entryCourse"'),'IERB academic controls have native keyboard order');
    await evaluate('document.querySelector("#ierbEntryForm [type=submit]").focus()');await keyPress('Tab','Tab',9);
    check(await evaluate('document.activeElement.id === "closeIerbEntry"'),'IERB Tab stays inside modal');
    await keyPress('Escape','Escape',27);
    check(await evaluate('document.getElementById("ierbEntryModal").getAttribute("aria-hidden") === "true" && document.activeElement.id === "addIerbEntry"'),'IERB Escape restores trigger focus');
  }
  const base={id:1,studentId:'S1',name:'Fixture Student',email:'fixture@example.test',research:'Research',groupId:'Group',stage:'Stage 1',status:'On Track',progress:20};
  for(const empty of [null,'']) {
    academicRecord={...base,course:attack,academicUnitKey:empty,programKey:empty,yearLevel:empty,academicYear:empty};
    await navigate('ierbprog.php',375,true,'populated');
    await evaluateFunction(() => document.querySelector('#ierbTableBody button[title="Edit entry"]').click());
    check(await evaluateFunction(attack=>document.getElementById('entryAcademicSummary').textContent.includes(attack) && !document.getElementById('entryAcademicFields').querySelector('img,script'),attack),'IERB legacy course is literal and preservable');
    await evaluate('document.getElementById("ierbEntryForm").requestSubmit()');
    await waitFor('document.getElementById("ierbEntryModal").getAttribute("aria-hidden") === "true"');
    const payload=JSON.parse(requests.findLast(r=>r.file==='ierb_api.php'&&r.action==='save').body);
    check(['course','academicUnitKey','programKey','yearLevel','academicYear'].every(key=>!Object.hasOwn(payload,key)),'IERB unrelated legacy edit omits academic tuple');
  }
  academicRecord={...base,course:'BS in Information Technology',academicUnitKey:'amt',programKey:'bsit',yearLevel:'2nd Year',academicYear:'2026-2027'};
  await navigate('ierbprog.php',768,false,'populated');
  await evaluateFunction(() => document.querySelector('#ierbTableBody button[title="Edit entry"]').click());
  check(await evaluate('["entryAcademicUnit","entryCourse","entryYearLevel","entryAcademicYear"].map(id=>document.getElementById(id).value).join("|") === "amt|bsit|2nd Year|2026-2027"'),'IERB edit preselects complete tuple');
  await select('entryAcademicUnit','__graduate__'); await select('entryCourse','mba_thesis');
  check(await evaluate('document.getElementById("entryYearLevel").disabled && document.getElementById("entryYearLevel").value === ""'),'IERB graduate unit/year remain unresolved');
  academicSaveError=true;
  await evaluate('document.getElementById("ierbEntryForm").requestSubmit()');
  await waitFor('document.querySelector(".prism-toast-wrap")?.textContent.includes("Academic validation error")');
  check(await evaluateFunction(attack=>document.getElementById('ierbEntryModal').getAttribute('aria-hidden')==='false' && document.getElementById('entryCourse').value==='mba_thesis' && document.querySelector('.prism-toast-wrap').textContent.includes(attack) && !document.querySelector('.prism-toast-wrap img'),attack),'IERB server validation error retains selections and safe message');
  const payload=JSON.parse(requests.findLast(r=>r.file==='ierb_api.php'&&r.action==='save').body);
  check(payload.academicUnitKey===null && payload.yearLevel===null && payload.programKey==='mba_thesis' && payload.academicYear==='2026-2027' && payload.stage==='Stage 1','IERB graduate payload uses null unit/year and preserves stage');
  academicSaveError=false; academicRecord=null;
}


async function checkAcademicDisplay() {
  const course = 'BS in International Hospitality Management Specialization in Hotel, Restaurant and Culinary Operations';
  const base={id:1,studentId:'S1',name:'Fixture Student',email:'fixture@example.test',research:'Research',group:'Group',groupId:'Group',stage:'Stage 1',status:'On Track',progress:20};
  for(const file of ['admin_people.php','ierbprog.php']) for(const viewer of ['admin','adviser']) {
    for(const width of [375,768,1024,1280,1600]) for(const dark of [false,true]) {
      academicRecord={...base,course,academicUnitKey:'ihtm',programKey:'bsihm_hotel',yearLevel:'3rd Year',academicYear:'2027-2028'};
      await navigate(file,width,dark,'populated',viewer);
      await measure(file,width,dark,' academic readonly');
      check(await evaluateFunction(course=>{
        const summary=document.querySelector('.academic-record-summary');
        return summary && summary.textContent.includes(course) && summary.textContent.includes('3rd Year') && summary.textContent.includes('2027-2028') && summary.textContent.includes(window.PRISM_ACADEMIC_CATALOG.units.ihtm.label) && !summary.querySelector('input,select,button,a');
      },course),`${file} ${viewer} ${width}px: complete academic summary is read-only and untruncated`);
      if(viewer==='adviser') check(await evaluateFunction(file=>file==='ierbprog.php'?!document.getElementById('addIerbEntry') && !document.querySelector('#ierbTableBody button[title="Edit entry"]'):!document.querySelector('#recordRows button[title="Delete"]'),file),`${file}: academic display grants no extra adviser action`);
    }
    academicRecord={...base,course:attack,academicUnitKey:attack,programKey:null,yearLevel:attack,academicYear:attack};
    await navigate(file,375,true,'populated',viewer);
    check(await evaluateFunction(attack=>{
      const host=document.querySelector('.academic-record-summary');
      return host.textContent.includes(attack) && !host.querySelector('img,script') && !window.__fixtureXss;
    },attack),`${file}: all legacy academic display values render literally`);
    for(const empty of [null,'']) {
      academicRecord={...base,course:empty,academicUnitKey:empty,programKey:empty,yearLevel:empty,academicYear:empty};
      await navigate(file,375,false,'populated',viewer);
      check(await evaluate('!document.querySelector(".academic-record-summary")'),`${file}: wholly empty legacy academic values add no misleading summary`);
    }
  }
  academicRecord=null;
}


function checkInstitutionalPartialBoundary() {
  for (const [partial, marker] of [
    ['includes/ceu_footer.php', 'class="ceu-footer"'],
    ['includes/research_resources.php', 'data-prism-resources'],
  ]) {
    const cases = [
      ['direct request', 'realpath(' + JSON.stringify(partial) + ')', "$authUser=['role'=>'admin'];", false],
      ['missing context', "realpath('dashboard.php')", '', false],
      ['invalid context type', "realpath('dashboard.php')", "$authUser='admin';", false],
      ['unknown role', "realpath('dashboard.php')", "$authUser=['role'=>'guest'];", false],
      ...['admin', 'adviser', 'student'].map(role => [role + ' authenticated caller', "realpath('dashboard.php')", "$authUser=['role'=>'" + role + "'];", true]),
    ];
    for (const [name, script, context, allowed] of cases) {
      const code = '$_SERVER["SCRIPT_FILENAME"]=' + script + ';' + context +
        'register_shutdown_function(function(){echo "status=".http_response_code();}); require ' + JSON.stringify(partial) + ';';
      const result = spawnSync(php, ['-r', code], {cwd:root, encoding:'utf8', windowsHide:true});
      check(result.status === 0 && result.stderr === '' &&
        (allowed ? result.stdout.includes(marker) && !result.stdout.includes('status=404') : result.stdout === 'status=404'),
      partial + ': ' + name, result.stderr);
    }
  }
}

async function checkInstitutionalComponents() {
  const viewers = [['dashboard.php','admin'], ['research_adviser.php','adviser'], ['role_portal.php','student']];
  const screenshotDirectory = process.env.PRISM_TEST_SCREENSHOTS === '1'
    ? fs.mkdtempSync(path.join(os.tmpdir(), 'prism-institutional-ui-')) : null;
  for (const width of [375,768,1024,1280,1600]) for (const dark of [false,true]) for (const [file,viewer] of viewers) {
    await navigate(file,width,dark,'populated',viewer);
    const label = file + ' ' + width + 'px ' + (dark ? 'dark' : 'light') + ' institutional';
    const initial = await evaluateFunction(() => {
      const footer=document.querySelector('.ceu-footer'), resources=document.querySelector('[data-prism-resources]');
      const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);
      return {
        one:document.querySelectorAll('.ceu-footer').length===1 && document.querySelectorAll('[data-prism-resources]').length===1,
        structure:footer?.parentElement===document.querySelector('main') && !footer.closest('.portal-page') &&
          !!resources?.closest('main') && !resources.closest('form,dialog') && !footer.closest('form,dialog') &&
          !!(resources.compareDocumentPosition(footer) & Node.DOCUMENT_POSITION_FOLLOWING),
        static:!document.querySelector('.ceu-footer :is(form,input,button,script),[data-prism-resources] :is(form,input,button,script,[data-go],[data-page])'),
        closed:[...resources.querySelectorAll('details.prism-resource')].length===2 && [...resources.querySelectorAll('details')].every(e=>!e.open),
        unique:ids.length===new Set(ids).size,
        contacts:footer.textContent.includes('Km. 44 McArthur Highway') && footer.textContent.includes('City of Malolos, Bulacan, Philippines') &&
          footer.querySelector('a[href="tel:+63447916359"]')?.textContent==='(044) 791-6359' &&
          footer.querySelector('a[href="tel:+63447919233"]')?.textContent==='(044) 791-9233',
        website:footer.querySelector('.ceu-footer-brand')?.getAttribute('href')==='https://www.ceu.edu.ph/' &&
          footer.querySelector('.ceu-footer-brand')?.getAttribute('aria-label')?.includes('opens in a new tab'),
        links:[...document.querySelectorAll('.ceu-footer a[target="_blank"],[data-prism-resources] a')].every(a=>
          a.target==='_blank' && a.relList.contains('noopener') && a.relList.contains('noreferrer')),
        student:!document.body.classList.contains('student-dashboard-page') || resources.closest('.portal-page')?.dataset.section==='dashboard',
      };
    });
    for (const [name,ok] of Object.entries(initial)) check(ok,label+': '+name);
    const writesBefore=requests.filter(r=>r.method==='POST').length;
    for (const [type,src,w,h,count] of [
      ['sdg','assets/images/sdg.webp',2048,1448,17],
      ['agenda','assets/images/research-matrix.webp',612,786,6],
    ]) {
      await evaluateFunction(type=>{
        const summary=document.querySelector('[data-resource="'+type+'"] > summary');
        summary.scrollIntoView({block:'center'}); summary.focus();
      },type);
      await keyPress('Enter','Enter',13);
      await waitFor('document.querySelector(' + JSON.stringify('[data-resource="'+type+'"]') + ').open');
      check(await evaluateFunction(type=>{
        const summary=document.querySelector('[data-resource="'+type+'"] > summary');
        const s=getComputedStyle(summary);
        return document.activeElement===summary && summary.matches(':focus-visible') && s.outlineStyle!=='none' && parseFloat(s.outlineWidth)>=2;
      },type),label+': '+type+' native keyboard expansion and visible focus');
      await evaluateFunction(type=>document.querySelector('[data-resource="'+type+'"] img').scrollIntoView({block:'center'}),type);
      await waitFor('(() => {const i=document.querySelector('+JSON.stringify('[data-resource="'+type+'"] img')+');return i.complete && i.naturalWidth>0;})()');
      const data=await evaluateFunction(type=>{
        const panel=document.querySelector('[data-resource="'+type+'"]'),img=panel.querySelector('img'),link=panel.querySelector('[data-resource-open]');
        const box=img.getBoundingClientRect(), surface=img.parentElement.getBoundingClientRect();
        const transcript=panel.querySelector('.prism-resource-transcript');
        return {
          src:img.getAttribute('src'), width:img.naturalWidth,height:img.naturalHeight,
          declared:[Number(img.getAttribute('width')),Number(img.getAttribute('height'))],
          alt:img.alt, ratio:box.width/box.height, fits:box.left>=surface.left-1 && box.right<=surface.right+1 && box.width>0,
          listCount:transcript.querySelectorAll('li').length,text:transcript.textContent,
          href:link.getAttribute('href'), tab:link.textContent.includes('new tab'),
        };
      },type);
      check(data.src===src && data.width===w && data.height===h && data.declared[0]===w && data.declared[1]===h,label+': '+type+' approved full-resolution asset loaded');
      check(data.alt.length>30 && data.listCount===count,label+': '+type+' meaningful alt and complete text alternative');
      check(data.fits && Math.abs(data.ratio-w/h)<0.01,label+': '+type+' responsive uncropped aspect ratio');
      check(data.href===src && data.tab,label+': '+type+' full-size image link and new-tab notice');
      if (type==='agenda') check(['Health Science','Social Science and Humanities','Education','Business and Hospitality Management','Environmental Research','Institutional Research','SDGs 6, 7, 12, 13, 14 and 15'].every(t=>data.text.includes(t)),
        label+': six agenda mappings match supplied reference');
      else check(data.text.includes('No Poverty') && data.text.includes('Partnerships for the Goals'),label+': SDG text includes first and last supplied goal');
      await evaluateFunction(type=>{
        const panel=document.querySelector('[data-resource="'+type+'"]');
        const link=panel.querySelector('[data-resource-open]');
        window.__resourceActivation=null;
        link.addEventListener('click',e=>{e.preventDefault();window.__resourceActivation={href:link.getAttribute('href'),trusted:e.isTrusted};},{once:true});
        panel.querySelector('summary').focus();
      },type);
      await keyPress('Tab','Tab',9);
      check(await evaluateFunction(type=>{
        const link=document.querySelector('[data-resource="'+type+'"] [data-resource-open]'),s=getComputedStyle(link);
        return document.activeElement===link && link.matches(':focus-visible') && s.outlineStyle!=='none' && parseFloat(s.outlineWidth)>=2;
      },type),label+': '+type+' image link reachable by Tab with visible focus');
      await keyPress('Enter','Enter',13);
      check(await evaluateFunction(src=>window.__resourceActivation?.href===src && window.__resourceActivation.trusted,src),label+': '+type+' link activates from keyboard without external fixture navigation');
      const fits=await evaluateFunction(()=>{
        const nodes=[...document.querySelectorAll('[data-prism-resources] summary,[data-prism-resources] a,[data-prism-resources] img,[data-prism-resources] ol')];
        return document.documentElement.scrollWidth<=innerWidth+1 && nodes.every(e=>{
          const details=e.closest('details');
          if(details && !details.open && e.tagName!=='SUMMARY') return true;
          const r=e.getBoundingClientRect();
          return r.left>=-1 && r.right<=innerWidth+1 && e.scrollWidth<=e.clientWidth+1;
        });
      });
      check(fits,label+': '+type+' expanded content fits viewport without clipping');
      await evaluateFunction(type=>document.querySelector('[data-resource="'+type+'"] > summary').focus(),type);
      await keyPress('Enter','Enter',13);
      check(await evaluateFunction(type=>!document.querySelector('[data-resource="'+type+'"]').open && document.activeElement===document.querySelector('[data-resource="'+type+'"] > summary'),type),
        label+': '+type+' keyboard collapse retains focus');
    }
    await evaluateFunction(()=>document.querySelector('.ceu-footer').scrollIntoView({block:'center'}));
    await waitFor('(() => {const i=document.querySelector(".ceu-footer img");return i.complete && i.naturalWidth>0;})()');
    const footer=await evaluateFunction(()=>{
      const node=document.querySelector('.ceu-footer'),img=node.querySelector('img'),r=node.getBoundingClientRect(),im=img.getBoundingClientRect();
      return {
        flow:getComputedStyle(node).position==='static' && r.top>=node.previousElementSibling.getBoundingClientRect().bottom-1,
        fits:r.left>=-1 && r.right<=innerWidth+1 && node.scrollWidth<=node.clientWidth+1 &&
          [...node.querySelectorAll('a,p,h2,img')].every(e=>{const b=e.getBoundingClientRect();return b.left>=r.left-1 && b.right<=r.right+1;}),
        logo:img.getAttribute('src')==='assets/images/ceu-logo.webp' && img.naturalWidth===256 && img.naturalHeight===307 &&
          img.alt==='Centro Escolar University logo' && Math.abs(im.width/im.height-256/307)<0.01,
        contactReadable:getComputedStyle(node.querySelector('address')).color!==getComputedStyle(node).backgroundColor,
      };
    });
    for (const [name,ok] of Object.entries(footer)) check(ok,label+': footer '+name);
    await evaluateFunction(()=>document.querySelector('.ceu-footer-brand').focus());
    await keyPress('Tab','Tab',9);
    check(await evaluateFunction(()=>{
      const a=document.activeElement,s=getComputedStyle(a);
      return a.getAttribute('href')==='tel:+63447916359' && a.matches(':focus-visible') && s.outlineStyle!=='none' && parseFloat(s.outlineWidth)>=2;
    }),label+': footer contact link keyboard focus');
    check(requests.filter(r=>r.method==='POST').length===writesBefore,label+': informational controls make no API writes');
    await measure(file,width,dark,' institutional resources');
    if (screenshotDirectory && ((width===375 && dark) || (width===1280 && !dark))) {
      await evaluateFunction(()=>document.querySelectorAll('details.prism-resource').forEach(d=>{d.open=true;}));
      await evaluate('new Promise(resolve=>setTimeout(resolve,400))');
      const clip=await evaluateFunction(()=>{
        const a=document.querySelector('[data-prism-resources]').getBoundingClientRect(),b=document.querySelector('.ceu-footer').getBoundingClientRect();
        return {x:a.x+scrollX,y:a.y+scrollY,width:a.width,height:b.bottom-a.top,scale:1};
      });
      const png=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});
      const destination=path.join(screenshotDirectory,file.replace('.php','')+'-'+width+'-'+(dark?'dark':'light')+'.png');
      fs.writeFileSync(destination,Buffer.from(png.data,'base64'));
      console.log('Institutional screenshot: '+destination);
    }
    if(file==='role_portal.php') {
      for(const section of ['progress','submit','documents','notifications','calendar','profile']) {
        await evaluateFunction(section=>document.querySelector('[data-go="'+section+'"],[data-page="'+section+'"]').click(),section);
        check(await evaluateFunction(section=>{
          const resources=document.querySelector('[data-prism-resources]'),footer=document.querySelector('.ceu-footer');
          return document.querySelector('.portal-page.active')?.dataset.section===section && resources.getClientRects().length===0 &&
            footer.getClientRects().length>0 && footer.parentElement===document.querySelector('main') && document.documentElement.scrollWidth<=innerWidth+1;
        },section),label+': '+section+' preserves footer and keeps resources separate');
      }
    }
  }
}

async function run() {
  checkPartialBoundary();
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
    } else if (message.method === 'Log.entryAdded' && message.params.entry.source === 'security'
        && /content security policy|\bcsp\b|violat/i.test(message.params.entry.text)) {
      errors.push('CSP: ' + message.params.entry.text);
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
  await command('Log.enable');
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  await command('Browser.setDownloadBehavior', { behavior: 'deny' }, true);
  if (process.argv.includes('--polish-only')) {
    await checkTonightPolish();
    check(errors.length===0, 'No focused browser runtime exceptions', errors.join(' | '));
    console.log(`${checks} focused polish UI checks; ${failures.length} failures.`);
    for (const failure of failures) console.error('FAIL '+failure);
    if (failures.length) process.exitCode=1;
    return;
  }


  for (const file of pages) {
    const response = await fetch(origin + '/' + file);
    check(Object.entries(securityHeaders).every(([name, value]) => response.headers.get(name) === value),
      file + ': actual application security headers served');
    await response.text();
  }

  for (const width of [375, 768, 1024, 1280, 1600]) {
    for (const file of pages) {
      await navigate(file, width, false);
      for (const dark of [false, true]) {
        await evaluate(`document.documentElement.classList.toggle('dark-theme', ${dark})`);
        await measure(file, width, dark);
        await checkNavigation(file, width, dark);
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

  await navigate('admin_notifications.php', 1280, false, 'populated');
  await waitFor('document.getElementById("noticeGroup").options.length > 1');
  for (const scheduled of [false, true]) {
    apiDelay = 150;
    await evaluateFunction(scheduled => {
      const set = (id, value) => { document.getElementById(id).value = value; };
      set('noticeAudience', 'Specific Research Group');
      document.getElementById('noticeAudience').dispatchEvent(new Event('change'));
      set('noticeGroup', 'AMT-BSIT-Y2-2627-G01');
      set('noticeType', 'Reminder');
      set('noticeMessage', '  Fixture notification  ');
      document.getElementById('automatedNotice').checked = scheduled;
      document.getElementById('automatedNotice').dispatchEvent(new Event('change'));
      if (scheduled) set('noticeSchedule', '2099-01-02T09:30');
      document.getElementById('notificationForm').requestSubmit();
    }, scheduled);
    check(await evaluate('document.querySelector("#notificationForm button[type=submit]").disabled'), 'Notification submit disabled while pending');
    check(await evaluate('document.getElementById("cancelNotification").disabled'), 'Notification reset disabled while sending');
    await waitFor('!document.querySelector("#notificationForm button[type=submit]").disabled');
    apiDelay = 0;
    checkPayload('notifications_api.php', 'send', {
      audience: 'Specific Research Group', group: 'AMT-BSIT-Y2-2627-G01', type: 'Reminder', message: 'Fixture notification', automated: scheduled, scheduleAt: scheduled ? '2099-01-02T09:30' : '',
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
  setupPendingFixture = true;
  await evaluateFunction(() => {
    document.getElementById('addIerbEntry').click();
    const fields = { entryStudentName: 'Fixture Student', entryStudentId: 'F-001', entryEmail: 'fixture@example.test', entryGroupId: 'Fixture Group', entryStage: 'Stage 1', entryResearchTitle: 'Fixture Research', entryRequirements: 'Fixture Requirements', entrySubmissionDate: '2026-09-25', entryStatus: 'Pending' };
    for (const [id, value] of Object.entries(fields)) document.getElementById(id).value = value;
    for (const [id,value] of Object.entries({entryAcademicUnit:'amt',entryCourse:'bsit',entryYearLevel:'2nd Year',entryAcademicYear:'2026-2027'})) {
      const control=document.getElementById(id); control.value=value; control.dispatchEvent(new Event('change'));
    }
  });
  await waitFor('[...document.getElementById("entryGroupId").options].some(o=>o.value==="__create__")');
  await evaluate("document.getElementById('entryGroupId').value='__create__'; document.getElementById('ierbEntryForm').requestSubmit()");
  await waitFor('document.getElementById("ierbEntryModal").getAttribute("aria-hidden") === "true"');
  checkPayload('ierb_api.php', 'save', { id: null, name: 'Fixture Student', studentId: 'F-001', email: 'fixture@example.test', groupId: '__create__', stage: 'Stage 1', research: 'Fixture Research', requirements: 'Fixture Requirements', submissionDate: '2026-09-25', status: 'Pending', academicUnitKey:'amt', programKey:'bsit', course:'BS in Information Technology', yearLevel:'2nd Year', academicYear:'2026-2027' }, 'IERB entry preserves API parameter names and stage keys');

  await waitFor('document.querySelector(".prism-toast-wrap")?.textContent.includes("Setup pending")');
  check(await evaluateFunction(attack => {
    const host = document.querySelector('.prism-toast-wrap');
    return host.textContent.includes('Setup pending ' + attack) && !host.querySelector('img, script');
  }, attack), 'IERB displays pending setup safely without a password');
  setupPendingFixture = false;

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
    await checkNavigation(file, 375, true);
    check(await evaluateFunction(() => !document.querySelector('#prismPrimaryNavigation a[href="admin_advisers.php"]')), file + ': adviser navigation omits admin-only directory');
    if (file === 'admin_ai.php') check(await evaluate('!document.getElementById("stageLabelEditor")'), 'Adviser fixture has no stage label settings');
    if (file === 'ierbprog.php') check(await evaluate('!document.getElementById("addIerbEntry")'), 'Adviser fixture has no add entry action');
  }
  await checkDashboard();
  await checkNotificationComposer();
  await checkTonightPolish();
  await checkAdviserDashboard();
  await checkStudentPortal();
  await checkAcademicManagement();
  await checkAcademicIerb();
  await checkAcademicDisplay();
  await checkRemediation();
  const institutionalStart = checks;
  checkInstitutionalPartialBoundary();
  await checkInstitutionalComponents();
  console.log((checks - institutionalStart) + ' focused institutional checks.');
  if (process.env.PRISM_TEST_SCREENSHOTS === '1') {
    const screenshots = fs.mkdtempSync(path.join(os.tmpdir(), 'prism-ui-audit-screenshots-'));
    for (const file of ['admin_notifications.php', 'ierbprog.php', 'account.php', 'dashboard.php', 'research_adviser.php', 'role_portal.php']) {
      const viewer=file==='role_portal.php'?'student':file==='research_adviser.php'?'adviser':'admin';
      const captureWidth=file==='role_portal.php'?375:1280;
      await navigate(file, captureWidth, file==='role_portal.php', file==='role_portal.php'?'student-ready':'populated', viewer);
      if (file === 'admin_notifications.php') {
        await evaluate('document.getElementById("automatedNotice").checked=true; document.getElementById("automatedNotice").dispatchEvent(new Event("change"))');
      }
      // Capture the settled theme rather than an intermediate CSS transition.
      await evaluate('new Promise(resolve => setTimeout(resolve, 400))');
      const dimensions = await command('Page.getLayoutMetrics');
      const png = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: captureWidth, height: dimensions.cssContentSize.height, scale: 1 } });
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
