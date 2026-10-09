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
const extraPages = ['dashboard.php', 'research_adviser.php', 'role_portal.php', 'admin_people.php', 'documents.php', 'reports.php', 'calendar.php', 'data_export.php'];
const pageWrappers = { 'admin_students.php': 'admin_people.php', 'admin_advisers.php': 'admin_people.php', 'student.php': 'role_portal.php' };
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
let paginatedFixture = false;
let readinessFixture = false;
let legacyAIFixture = false;
let managementFailure = '';
let academicRecord = null;
let lifecycleRecords = null;
let lifecycleGateAvailable = true;
let lifecycleDeleteFailure = 0;
let academicSaveError = false;
let setupPendingFixture = false;
let reviewFailure = 0;
let summaryProvider = 'ai';
let summaryStored = null;
let reviewedStatus = null;
let lifecycleStageValues = null;
let studentSubmitted = false;
const requests = [];
const errors = [];
const failures = [];
let scenario = 'empty';
let role = 'admin';
let deadlineFailure = false;
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
  const wrapperFile = Object.hasOwn(pageWrappers, file) ? file : null;
  if (wrapperFile) file = pageWrappers[wrapperFile];
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
    { include: "require __DIR__ . '/includes/ceu_footer.php';", path: 'includes/ceu_footer.php', pages: [...pages, ...extraPages] },
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
  if (wrapperFile) {
    const wrapper = fs.readFileSync(path.join(root, wrapperFile), 'utf8');
    const include = "require __DIR__ . '/" + file + "';";
    assert.equal(wrapper.split(include).length - 1, 1, wrapperFile + ': exact inherited template');
    assert(!/ceu.footer/.test(wrapper), wrapperFile + ': no duplicate footer');
    isolated = wrapper.replace("require __DIR__ . '/config.php';", '').replace(include, () => '?>' + isolated + '<?php ');
  }
  assert(!/\b(?:require|include)(?:_once)?\s*(?:\(|["'$])/i.test(isolated), `${file}: unexpected include in fixture`);
  const stub = `<?php
    require_once ${JSON.stringify(path.join(root, 'includes/assets.php'))};
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

const retentionUIAudit=require('./account-retention-ui.cjs');
function retentionMockApi(file,action) { return retentionUIAudit.mockApi(file,action,requests.findLast(r=>r.file===file)); }
function retentionMockList(file,query) { return retentionUIAudit.mockList(file,query); }
async function checkRetentionRedesign() {
  await retentionUIAudit.run({check,evaluate,waitFor,navigate,command,keyPress,setManagement:value=>{managementFixture=value;},getRequests:()=>requests,errors});
}
function fixtureApi(file, action) {
  if (process.argv.includes('--retention-only')) { const response=retentionMockApi(file,action);if(response)return response; }
  if (file === 'documents_api.php' && action === 'summarize') {
    if (summaryProvider === 'error') return {ok:false,message:'No extractable text was found. This appears to be a scanned/image-only PDF. '+attack};
    summaryStored='Purpose: University research review. '+attack+'\nEthics: Informed consent is planned.';
    return {ok:true,summary:summaryStored,source:summaryProvider,partial:true};
  }
  if (setupPendingFixture && file === 'ierb_api.php' && action === 'save') {
    return { ok: true, accountCreated: true, setupPending: true, setupMessage: 'Setup pending ' + attack };
  }
  if (file === 'documents_api.php' && action === 'review') {
    if (reviewFailure) return {ok:false,message:'Review rejected '+reviewFailure+' '+attack};
    const payload=JSON.parse(requests.findLast(r=>r.file===file && r.action===action).body);
    reviewedStatus=payload.status;
    return {ok:true,message:'Review saved.'};
  }
  if (file === 'calendar_deadlines_api.php') {
    const request = requests.findLast(r => r.file === file && r.action === action);
    const query = request?.query || {};
    if (action === 'group_options') return {ok:true,groups:role==='adviser'?['AMT-BSIT-Y2-2627-G01']:['AMT-BSIT-Y2-2627-G01','AMT-BSIT-Y2-2627-G02']};
    if (scenario === 'error' || deadlineFailure) return {ok:false,message:'Synthetic deadline failure'};
    if (action === 'create' || action === 'cancel') return {ok:true,id:1,message:action==='create'?'Official deadline created.':'Official deadline cancelled.',delivery:{notificationFailures:0,emailFailures:0}};
    if (action === 'dates') return {ok:true,dates:[{deadline_date:new Date().toLocaleDateString('en-CA'),total:1}]};
    return {ok:true,deadlines:scenario==='empty'?[]:[{id:1,title:attack,description:attack,deadline_date:query.from,target_scope:'groups',status:'Active',groups:role==='student'?[]:['AMT-BSIT-Y2-2627-G01'],canCancel:role!=='student'}],total:scenario==='empty'?0:1,page:1,limit:10};
  }
  if (scenario === 'error') return { ok: false, message: 'Fixture error ' + attack };
  const populated = scenario === 'populated' || scenario === 'long-labels' || scenario.startsWith('student-');
  if (file==='documents_api.php' && action==='submit_to_rpms') { studentSubmitted=true; return {ok:true,message:'Formally submitted to RPMS.'}; }
  if (file==='documents_api.php' && role==='student') {
    const state=studentSubmitted || scenario==='student-submitted'?'Submitted to RPMS':scenario==='student-ready'?'Ready for Formal RPMS Submission':scenario==='student-revision'?'Needs Revision':'Pending Adviser Review';
    return {ok:true,message:'Document uploaded.',documents:populated?[{id:'fixture-doc',originalName:attack+'LongFileName'.repeat(15)+'.pdf',student:'Fixture Student',studentId:1,documentType:'Research Protocol',stage:'Stage 1',stageLabel:labels['Stage 1'],uploadedAt:'2026-09-24',workflowState:state,reviewStatus:state==='Needs Revision'?'Resubmission Requested':state==='Pending Adviser Review'?'Submitted':'Approved',reviewRemarks:attack,versionNo:1,isCurrent:true,actions:{submit:state==='Ready for Formal RPMS Submission'}}]:[]};
  }
  if(file==='ierb_api.php' && role==='student') return action==='history'?{ok:true,history:[{stage:'Stage 1',status:'On Track',note:attack,actor:attack,created_at:'2026-09-24'}]}:{ok:true,records:populated?[academicRecord||{id:1,name:attack,research:attack,groupId:'Fixture Group',stage:'Stage 1',status:'On Track',progress:20,requirements:attack}]:[]};
  if (action === 'group_options' && ['students_api.php','notifications_api.php'].includes(file)) return {ok:true,groups:populated ? ['AMT-BSIT-Y2-2627-G01','AMT-BSIT-Y2-2627-G02'] : []};
  if (compactBellFixture && file === 'notifications_api.php' && action === 'list') return {ok:true,notifications:Array.from({length:9},(_,i)=>({subject:'Notice '+i+' '+('Long title '.repeat(20)),recipient_name:'Student '+i,status:'Sent',created_at:'2026-10-03 09:00:00',message:'LONG BODY MUST NOT APPEAR'}))};
  if (paginatedFixture && ['audit_api.php','notifications_api.php'].includes(file) && action === 'list') {
    const query = requests.findLast(r => r.file === file && r.action === 'list').query;
    const total = query.q === 'none' ? 0 : query.q || query.from || query.to || query.override ? 23 : 113;
    const page = Math.max(1, Math.min(Math.max(1, Math.ceil(total / 10)), Number(query.page) || 1));
    const rows = Array.from({length: Math.min(10, Math.max(0, total - (page - 1) * 10))}, (_, i) => ({
      id: (page - 1) * 10 + i + 1, subject: 'Notice ' + ((page - 1) * 10 + i + 1), type: 'Reminder',
      recipient_name: 'Student', message: 'Fixture notification', status: 'Sent', created_at: '2026-09-01 12:00:00',
      actionLabel: 'Fixture activity', details: 'Entry ' + ((page - 1) * 10 + i + 1), at: '2026-09-01 12:00:00'
    }));
    return {ok:true, total, page, pages: Math.max(1, Math.ceil(total / 10)), limit:10,
      [file === 'audit_api.php' ? 'entries' : 'notifications']:rows};
  }
  if (file === 'notifications_api.php' && action === 'recipients_preview') return {ok:true, recipients:populated ? [{id:1,name:attack,email:'fixture@example.test'}, {id:2,name:'Second recipient',email:'second@example.test'}] : []};
  if (file === 'notifications_api.php') return { ok: true, notifications: populated ? [{ id: 1, subject: attack, type: 'Reminder', recipient_name: attack, recipient_email: 'fixture@example.test', status: 'Sent', created_at: '2026-09-24 08:00:00', message: attack, delivery_info: 'Fixture only' }] : [], total: populated ? 1 : 0, page: 1, scheduled: false, sent: 1 };
  if (file === 'advisers_api.php' && lifecycleRecords && action==='list') return {ok:true,advisers:lifecycleRecords};
  if (file === 'advisers_api.php') return {ok:true,advisers:populated?[{id:1,name:attack,employeeId:'A-1',email:'fixture@example.test',department:'AMT',status:'Active',groups:['AMT-BSIT-Y2-2627-G01','AMT-BSIT-Y2-2627-G02']},{id:2,name:'New Adviser',employeeId:'A-2',email:'new@example.test',status:'Active',groups:[]}]:[]};
  if (file === 'students_api.php' && action === 'adviser_options') return {ok:true,advisers:[]};
  if (file === 'students_api.php' && action === 'save' && academicSaveError) return {ok:false,message:'Academic validation error '+attack};
  if (file === 'account_lifecycle_api.php') return action==='availability'
    ? {ok:true,available:lifecycleGateAvailable,verificationMode:'schema_scoped_shared_hosting',message:lifecycleGateAvailable?'Schema verification is valid. Each deletion still requires all account safety checks.':'Permanent deletion is disabled: schema verification evidence has expired. Repeat operator verification.'}
    : lifecycleDeleteFailure ? {ok:false,message:'Protected Student history exists. Retain the account using Archive.'} : {ok:true,message:'Account permanently deleted.'};
  if (file === 'students_api.php' && lifecycleRecords && action==='list') return {ok:true,students:lifecycleRecords};
  if (file === 'students_api.php' && academicRecord) return {ok:true,students:[academicRecord]};
  if (file === 'students_api.php') return {ok:true,students:populated?[{id:1,name:attack,research:attack,course:'Fixture Course',stage:'Stage 1',status:'On Track'}]:[]};
  if (file === 'documents_api.php' && role === 'adviser') return {ok:true,documents:populated?[{id:'fixture-doc',originalName:attack+'LongFileName'.repeat(15)+'.pdf',student:attack,studentId:1,documentType:'Research Protocol',stage:'Stage 1',uploadedAt:'2026-09-24',workflowState:reviewedStatus==='Approved'?'Ready for Formal RPMS Submission':(['Denied','Resubmission Requested'].includes(reviewedStatus)?'Needs Revision':'Pending Adviser Review'),reviewStatus:reviewedStatus||'Submitted',reviewRemarks:attack,versionNo:1,isCurrent:true,actions:{review:true}}]:[],counts:{}};
  if (file === 'documents_api.php') return { ok:true, documents:populated ? [{ id:'fixture-doc', originalName:attack, aiSummary:summaryStored, student:'Fixture Student', studentId:1, documentType:'Protocol', stage:'Stage 1', stageLabel:'Initial review', uploadedAt:'2026-09-24', workflowState:'Pending Adviser Review', reviewStatus:'Submitted', versionNo:1, isCurrent:true, actions:{review:true,summarize:role==='admin'} }] : [], counts:{} };
  if (file === 'ierb_api.php' && action === 'needs_attention') return {ok:true, students:[], total:0};
  if (file === 'reports_api.php') return { ok: true, reports: populated ? [{ id: 'fixture-report', title: attack, type: 'AI Summarized Report', generated_at: '2026-09-24 08:00:00', generated_by: attack, requiresRegeneration:legacyAIFixture }] : [], report: { id: 'fixture-report' }, aiUsed: false };
  if (file === 'stage_labels_api.php') return { ok: true, labels: labelsForScenario() };
  if (file === 'ierb_api.php' && action === 'save' && academicSaveError) return {ok:false,message:'Academic validation error '+attack};
  if (file === 'ierb_api.php' && action === 'list' && academicRecord) return {ok:true,records:[academicRecord]};
  if (file === 'ierb_api.php' && action === 'history') return {ok:true,history:populated?[{stage:'Stage 1',status:'On Track',note:'Fixture history',actor:'Fixture actor',created_at:'2026-09-24'}]:[]};
  if (file === 'ierb_api.php') return { ok: true, ...(lifecycleStageValues ? {overview:stages.map((stage,i)=>({stage,status:'On Track',c:lifecycleStageValues[i]}))} : {}), records: populated ? Array.from({ length: 6 }, (_, i) => ({ id: i + 1, name: i ? `Student ${i}` : attack, studentId: `S${i + 1}`, email: 'fixture@example.test', groupId: 'A', course:'Fixture Course', stage: i < 4 ? 'Stage 1' : 'Stage 2', status: 'On Track', progress: 20, research: 'Fixture research', requirements: attack, lastSubmissionDate: '2026-09-24' })) : [] };
  if (file === 'audit_api.php') return { ok: true, entries: populated ? [{ id: 1, action: 'document_override', actionLabel: 'Document override', at: '2026-09-24 08:00:00', actorName: attack, actorEmail: 'fixture@example.test', actorRole: role, studentName: attack, protocolCode: 'P-001', override: true, details: 'Fixture details ' + attack, reason: 'Fixture reason ' + attack }] : [] };
  if (file === 'profile_api.php') return { ok: true, user: { name: 'UI Audit Fixture', email: 'fixture@example.test', role, refId: 'FIXTURE' }, message: action === 'change_password' ? 'Password updated.' : 'Updated.' };
  if (file === 'send_followup.php') return { ok: true, message: 'Fixture follow-up sent.' };
  throw new Error(`Unexpected mock endpoint ${file}`);
}

function mockApi(file, action, query = new URLSearchParams()) {
  if (process.argv.includes('--retention-only') && ['students_api.php','advisers_api.php'].includes(file) && action==='list') return retentionMockList(file,query);
  const data = fixtureApi(file,action);
  if(!data.ok) return data;
  if(file==='ierb_api.php' && action==='history') {
    const history=readinessFixture?Array.from({length:113},(_,i)=>({...data.history?.[0],note:'History '+i})):(data.history || []);
    const total=history.length,page=Math.max(1,Math.min(Math.max(1,Math.ceil(total/10)),Number(query.get('page'))||1));
    return {...data,history:history.slice((page-1)*10,page*10),total,page,limit:10};
  }
  const key = {students_api:'students',advisers_api:'advisers',documents_api:'documents',ierb_api:'records',reports_api:'reports'}[file.replace('.php','')];
  if (!data.ok || action!=='list' || !key || !Array.isArray(data[key])) return data;
  let all = data[key];
  if(readinessFixture && all.length) all=Array.from({length:113},(_,i)=>({ ...all[0], id:file==='documents_api.php'?'d'+i:i+1, name:'Record '+String(i).padStart(3,'0'),studentId:'S'+i,employeeId:'A'+i,student:'Student '+i,originalName:'Document '+i+'.pdf',title:'Report '+i,academicUnitKey:'amt',programKey:'bsit',yearLevel:'2nd Year',academicYear:'2026-2027',research_group:'AMT-BSIT-Y2-2627-G01',group:'AMT-BSIT-Y2-2627-G01',groupId:'AMT-BSIT-Y2-2627-G01',email:'student@example.test',adviserId:1,adviserName:'Own Adviser',actions:{...all[0].actions,submitToRpms:file==='documents_api.php'&&role==='student'&&i===22&&!studentSubmitted},...(file==='documents_api.php'&&role==='student'&&i===22&&!studentSubmitted?{workflowState:'Ready for Formal RPMS Submission',reviewStatus:'Approved'}:{})}));
  if(query.get('actionable'))all=all.filter(r=>['Needs Revision','Ready for Formal RPMS Submission'].includes(r.workflowState) && r.isCurrent!==false);
  if(['students_api.php','ierb_api.php','advisers_api.php'].includes(file)) {
    const fields=file==='advisers_api.php'?['department','status','groups']:['academicUnitKey','programKey','academicYear','yearLevel','group','stage','status','adviserId'];
    data.filterOptions={protocol:[{value:'present',label:'Protocol assigned'},{value:'missing',label:'No protocol code'}]};
    for(const field of fields) {
      const values=[...new Set(all.flatMap(r=>field==='groups'?(r.groups||[]):[field==='group'?(r.research_group||r.group||r.groupId):r[field]]).map(v=>String(v??'')||'__blank__'))];
      data.filterOptions[field==='groups'?'group':field]=values.map(value=>({value,label:field==='stage'&&labels[value]?value+' - '+labels[value]:value==='__blank__'?'Not recorded':value}));
    }
  }
  if(file==='ierb_api.php') { data.overview=lifecycleStageValues ? stages.map((stage,i)=>({stage,status:'On Track',c:lifecycleStageValues[i]})) : all.map(r=>({stage:r.stage,status:r.status,c:1})); data.courses=[...new Set(all.map(r=>r.course).filter(Boolean))]; }
  if(file==='documents_api.php') { data.counts={}; all.forEach(r=>{data.counts[r.workflowState]=(data.counts[r.workflowState]||0)+1;});data.filterOptions={types:[...new Set(all.map(r=>r.documentType))],courses:[...new Set(all.map(r=>r.course).filter(Boolean))],years:[...new Set(all.map(r=>String(r.uploadedAt||'').slice(0,4)).filter(Boolean))]}; }
  let rows = all.filter(r => {
    const q=query.get('q')?.trim().toLowerCase();
    if(q && ![r.name,r.email,r.studentId,r.employeeId,r.research,r.research_group,r.group,r.groupId,r.adviserName,r.originalName,r.student,r.documentType,r.protocolCode,r.stage,r.stageLabel,r.groups].some(v=>String(v??'').toLowerCase().includes(q))) return false;
    for(const field of ['academicUnitKey','programKey','academicYear','yearLevel','adviserId','department'])if(query.get(field) && (query.get(field)==='__blank__'?String(r[field]??'').trim()!=='':String(r[field])!==query.get(field)))return false;
    if(query.get('group') && !(file==='advisers_api.php'?(r.groups||[]).includes(query.get('group')):(r.research_group||r.group||r.groupId)===query.get('group')))return false;
    if(query.get('protocol') && Boolean(r.protocolCode)!==(query.get('protocol')==='present'))return false;
    return ['stage','status','course'].every(k=>!query.get(k)||r[k]===query.get(k)) && (!query.get('state')||r.workflowState===query.get('state')) && (!query.get('review')||r.reviewStatus===query.get('review')) && (!query.get('type')||r.documentType===query.get('type')) && (!query.get('year')||String(r.uploadedAt||'').slice(0,4)===query.get('year'));
  });
  if(query.get('aiOnly'))rows=rows.filter(r=>r.type==='AI Summarized Report'||r.type==='AI Full Report');
  if(query.get('sortBy')) { const map={studentId:'studentId',employeeId:'employeeId',group:'group',adviser:'adviserName',academicYear:'academicYear',name:'name',email:'email',department:'department',stage:'stage',status:'status'};const field=map[query.get('sortBy')];if(field)rows.sort((a,b)=>{const x=String(a[field]??''),y=String(b[field]??'');return (x===y?0:x<y?-1:1)*(query.get('direction')==='DESC'?-1:1);}); }
  const total=rows.length,limit=query.has('preview')?Math.max(1,Math.min(10,Number(query.get('preview'))||1)):10;
  const page=query.has('preview')?1:Math.max(1,Math.min(Math.max(1,Math.ceil(total/limit)),Number(query.get('page'))||1));
  return {...data,[key]:rows.slice((page-1)*limit,page*limit),total,page,limit};
}

async function serve(req, res) {
  try {
    const url = new URL(req.url, origin);
    const file = decodeURIComponent(url.pathname.slice(1));
    if (pages.includes(file) || extraPages.includes(file) || Object.hasOwn(pageWrappers, file)) {
      const html = fixtureTemplate(file);
      res.writeHead(200, { ...securityHeaders, 'Content-Type': 'text/html; charset=utf-8' });
      res.end(html);
    } else if (/^(notifications|reports|stage_labels|ierb|audit|profile|documents|students|advisers|calendar_deadlines|account_lifecycle)_api\.php$/.test(file) || file === 'send_followup.php') {
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
      const json = JSON.stringify(mockApi(file, url.searchParams.get('action'), url.searchParams));
      const status=file==='account_lifecycle_api.php' && url.searchParams.get('action')!=='availability' && lifecycleDeleteFailure ? lifecycleDeleteFailure :
        (file==='documents_api.php' && url.searchParams.get('action')==='review' && reviewFailure ? reviewFailure : 200);
      res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(json);
    } else if (/^assets\/(css|js|images)\/[A-Za-z0-9_.\/-]+$/.test(file) || file === 'assets/images/CEU FOOTER LOGO.png') {
      const absolute = path.resolve(root, file);
      assert(absolute.startsWith(path.join(root, 'assets') + path.sep));
      const types = { '.css': 'text/css', '.js': 'application/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
      if (!fs.existsSync(absolute) || !fs.statSync(absolute).isFile()) { res.writeHead(404); res.end(); return; }
      res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
      res.end(fs.readFileSync(absolute));
    } else { res.writeHead(404); res.end('Only isolated fixtures are served.'); }
  } catch (e) { errors.push(String(e)); console.error('Fixture failed:',e.stack || String(e)); if (!res.headersSent) res.writeHead(500); if (!res.writableEnded) res.end('Fixture failed.'); }
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

async function waitFor(expression, attempts = 100) {
  for (let i = 0; i < attempts; i++) {
    if (await evaluate(expression)) return;
    await new Promise(resolve => setTimeout(resolve, 30));
  }
  throw new Error(`UI did not become ready: ${expression}`);
}

function check(ok, label, detail = '') {
  checks++;
  if (!ok) { failures.push(`${label}${detail ? ': ' + detail : ''}`); console.error('FAIL '+failures.at(-1)); }
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
  file = pageWrappers[file] || file;
  const ready = { 'admin_notifications.php': '#noticeHistory > *', 'admin_ai.php': '#aiHistory > *', 'ierbprog.php': '#stageChart > *', 'account.php': '#activityList > *', 'dashboard.php':'#ierbMonitorBody > *', 'research_adviser.php':'#adviserQueue > *', 'role_portal.php':'#studentDashboardState', 'admin_people.php':'#recordRows > *', 'documents.php':'#documentsTableBody > *', 'reports.php':'#reportTableBody > *', 'calendar.php':'#monthGrid > *', 'data_export.php':'.data-export-card' }[file];
  await waitFor(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(ready)})`,process.argv.includes('--visual-only')?300:100);
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
  if(layout.page>width+1) console.error('Overflow elements: '+JSON.stringify(await evaluateFunction(()=>[...document.querySelectorAll('body *')].filter(e=>e.getClientRects().length&&!e.closest('table')).map(e=>({tag:e.tagName,id:e.id,cls:e.className,right:e.getBoundingClientRect().right})).filter(e=>e.right>innerWidth+1).slice(0,15))));
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
  const expectedCount = notification ? 'Showing 1\u20131 of 1 notifications' : '6 students';
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
  if (await evaluate('document.body.classList.contains("adviser-page")')) {
    check(await evaluateFunction(file => {
      const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);
      return !document.querySelector('.prism-sidebar') && document.querySelectorAll('.portal-navbar').length===1
        && new Set(ids).size===ids.length && !!document.querySelector('a[aria-current="page"][href="'+file+'"]');
    },file),label+': Adviser portal shell, unique IDs and current route');
    if(width<=1120) {
      await evaluate('document.getElementById("adviserNavigationToggle").focus()');
      await keyPress('Enter','Enter',13);
      check(await evaluate('!document.getElementById("prismPrimaryNavigation").hidden'),label+': keyboard opens compact menu');
      await keyPress('Escape','Escape',27);
      check(await evaluate('document.getElementById("prismPrimaryNavigation").hidden && document.activeElement.id==="adviserNavigationToggle"'),label+': Escape closes menu and restores focus');
    }
    return;
  }
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

async function checkDuplicateLogoutControls() {
  const sharedPages = [...pages, ...extraPages].filter(file => file !== 'role_portal.php');
  for (const file of sharedPages) {
    const source = fs.readFileSync(path.join(root, file), 'utf8');
    check(!/href\s*=\s*["']logout\.php["']/i.test(source), file + ': no page-specific duplicate logout');
  }
  for (const file of ['change_password_required.php', 'role_portal.php']) {
    const source = fs.readFileSync(path.join(root, file), 'utf8');
    check((source.match(/href\s*=\s*["']logout\.php["']/gi) || []).length === 1,
      file + ': intentional logout retained without shared navigation');
  }
  const viewers = {
    admin: ['dashboard.php','admin_students.php','admin_advisers.php','ierbprog.php','documents.php','reports.php','account.php','calendar.php','admin_ai.php','admin_notifications.php'],
    adviser: ['research_adviser.php','admin_students.php','ierbprog.php','documents.php','account.php','calendar.php','admin_notifications.php'],
  };
  for (const [viewer, files] of Object.entries(viewers)) {
    for (const file of files) {
      managementFixture = file === 'admin_advisers.php' ? 'adviser' : 'student';
      for (const width of [320,375,1280]) {
        for (const dark of [false,true]) {
          await navigate(file,width,dark,'empty',viewer);
          const label = `${viewer} ${file} ${width}px ${dark ? 'dark' : 'light'} logout`;
          check(await evaluate(`document.querySelectorAll('a[href="logout.php"]').length === 1 && !!document.querySelector('.prism-sidebar a[href="logout.php"],.portal-navbar a[href="logout.php"]')`), label + ': exactly one canonical logout');
          for (const expanded of [false,true]) {
            await evaluateFunction(expanded => {
              const toggle = document.getElementById('prismSidebarToggle');
              if (!toggle) {
                const account=document.querySelector('[data-prism-account-toggle]');
                if(account?.getAttribute('aria-expanded')!=='true')account?.click();
                return;
              }
              if ((toggle.getAttribute('aria-expanded') === 'true') !== expanded) toggle.click();
              if (expanded) document.getElementById('profileToggle')?.click();
            }, expanded);
            check(await evaluate(`(() => {
              const links = [...document.querySelectorAll('a[href="logout.php"]')];
              const box = links[0]?.getBoundingClientRect();
              return links.length === 1 && box.width > 0 && box.height >= 40 && box.right <= innerWidth && !links[0].closest('[hidden],[inert]');
            })()`), label + `: visible accessible action with navigation ${expanded ? 'expanded' : 'collapsed'}`);
            check(await evaluate(`(() => {
              const menu = document.getElementById('profileMenu');
              return !menu || ['account.php#profile','account.php#security','account.php#activity'].every(href => menu.querySelector('a[href="'+href+'"]')) && !menu.querySelector('a[href="logout.php"]') && !menu.querySelector('hr');
            })()`), label + ': profile items preserved without duplicate/logout divider');
          }
          await evaluate(`(() => {
            const link = document.querySelector('a[href="logout.php"]');
            link.addEventListener('click', event => { event.preventDefault(); window.__logoutDestination = link.getAttribute('href'); }, {once:true});
            link.focus();
          })()`);
          await keyPress('Enter','Enter',13);
          check(await evaluate(`window.__logoutDestination === 'logout.php'`), label + ': Enter targets existing logout handler');
          check(await evaluateFunction(viewer => {
            const links = [...document.querySelectorAll('#prismPrimaryNavigation a,.prism-account-links a')].map(link => link.getAttribute('href'));
            return links.includes('admin_students.php') && links.includes('documents.php') && links.includes('account.php')
              && (viewer === 'admin' ? links.includes('admin_ai.php') && links.includes('reports.php') : !links.includes('admin_ai.php') && !links.includes('reports.php'));
          }, viewer), label + ': role navigation preserved');
        }
      }
    }
  }
  managementFixture = 'student';
}

async function checkOfficialDeadlines() {
  for (const viewer of ['admin','adviser','student']) {
    for (const width of [320,375,1280]) for (const dark of [false,true]) {
      const file=viewer==='student'?'role_portal.php':'calendar.php';
      await navigate(file,width,dark,'populated',viewer);
      if(viewer==='student')await evaluate("document.querySelector('[data-page=calendar]').click()");
      await waitFor("document.querySelector('#officialDeadlineList .official-deadline')");
      await waitFor("document.querySelector('#monthGrid .has-official-deadline')");
      if(width<600)check(await evaluate("getComputedStyle(document.querySelector('#monthGrid .has-official-deadline'),'::after').content !== 'none'"),'Official dates remain visually marked on mobile '+viewer);

      check(await evaluate("document.querySelector('#officialDeadlineList').textContent.includes('Official Deadline')"),'Official deadline distinguished '+viewer);
      check(await evaluate("!document.querySelector('#officialDeadlineList img') && !document.querySelector('#officialDeadlineList script')"),'Official title/description escaped '+viewer);
      check(await evaluate("!!document.querySelector('#deadlineForm')") === (viewer!=='student'),'Creation role UI '+viewer);
      check(await evaluate("!!document.querySelector('#officialDeadlineList button')") === (viewer!=='student'),'Cancellation role UI '+viewer);
      if(viewer!=='student') {
        await evaluate("document.querySelector('.deadline-create').open=true");
        await waitFor("!document.querySelector('#deadlineForm [type=submit]').disabled");
        await evaluate("{const t=document.querySelector('#deadlineForm [name=target]');t.value='selected';t.dispatchEvent(new Event('change',{bubbles:true}))}");
        check(await evaluate("[...document.querySelector('#deadlineForm [name=groups]').options].length") === (viewer==='adviser'?1:2),'Authorized controlled groups '+viewer);
      }
      await measure(file,width,dark,' official deadline form');
      await evaluate("document.querySelector('#taskTitle').value='Personal coexistence';document.querySelector('#reminderForm').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))");
      await waitFor("document.querySelector('#taskList').textContent.includes('Personal coexistence') && document.querySelector('#officialDeadlineList .official-deadline')");
      check(await evaluate("document.querySelector('#taskList').textContent.includes('Personal Reminder') && document.querySelector('#officialDeadlineList').textContent.includes('Official Deadline')"),'Personal/official coexist '+viewer);
      check(await evaluate("Object.keys(localStorage).filter(k=>k.startsWith('prismReminders:')).every(k=>!localStorage.getItem(k).includes('Official Deadline'))"),'Official records never stored as personal reminders');
      if(width===1280&&dark) {
        await evaluate("document.querySelector('#taskList [title=\"Edit reminder\"]').click();document.querySelector('#taskTitle').value='Personal edited';document.querySelector('#reminderForm').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))");
        await waitFor("document.querySelector('#taskList').textContent.includes('Personal edited') && document.querySelector('#officialDeadlineList .official-deadline')");
        check(await evaluate("document.querySelector('#taskList').textContent.includes('Personal edited')"),'Existing personal edit works '+viewer);
        await evaluate("document.querySelector('#taskList [title=\"Delete reminder\"]').click()");
        await waitFor("document.querySelector('.prism-dialog [data-act=ok]')");
        await evaluate("document.querySelector('.prism-dialog [data-act=ok]').click()");
        await waitFor("!document.querySelector('#taskList').textContent.includes('Personal edited') && document.querySelector('#officialDeadlineList .official-deadline')");
        check(await evaluate("document.querySelector('#officialDeadlineList .official-deadline')!==null"),'Personal deletion leaves official record intact '+viewer);
      }

    }
  }
  apiDelay=300;
  await navigate('calendar.php',375,true,'populated','adviser');
  await evaluate("document.querySelector('#deadlineForm [name=title]').value='Early input';document.querySelector('#deadlineForm [name=title]').dispatchEvent(new Event('input',{bubbles:true}))");
  await waitFor("!document.querySelector('#deadlineForm [type=submit]').disabled");
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented})()"),'Authorized group loading does not clear manual-input dirty state');
  apiDelay=0;
  await navigate('calendar.php',375,true,'populated','adviser');
  await waitFor("!document.querySelector('#deadlineForm [type=submit]').disabled");
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return !e.defaultPrevented})()"),'Untouched official form has no warning');
  await evaluate("{document.querySelector('.deadline-create').open=true;const f=document.querySelector('#deadlineForm');f.elements.title.value='New official';f.elements.description.value='Text';f.elements.date.value='2026-10-10'}");
  apiDelay=180; requests.length=0;
  await evaluate("{const f=document.querySelector('#deadlineForm');f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))}");
  check(await evaluate("document.querySelector('#deadlineForm [type=submit]').disabled"),'Deadline saving control disabled');
  await waitFor("!document.querySelector('#deadlineForm [type=submit]').disabled");
  check(requests.filter(r=>r.file==='calendar_deadlines_api.php'&&r.action==='create').length===1,'Repeated submit issues one request');
  checkPayload('calendar_deadlines_api.php','create',{title:'New official',date:'2026-10-10',description:'Text',target:'all',groups:[]},'Deadline create uses allowed fields');
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return !e.defaultPrevented})()"),'Saved official form clears unsaved warning');
  await evaluate("document.querySelector('#deadlineForm [name=title]').value='Changed';document.querySelector('#deadlineForm [name=title]').dispatchEvent(new Event('input',{bubbles:true}))");
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented})()"),'Actual official form edit warns');
  const cancelBefore=requests.filter(r=>r.file==='calendar_deadlines_api.php'&&r.action==='cancel').length;
  await waitFor("document.querySelector('#officialDeadlineList button')");
  await evaluate("document.querySelector('#officialDeadlineList button').click();document.querySelector('#officialDeadlineList button').click()");
  await waitFor("document.querySelector('.prism-dialog [data-act=ok]')");
  await evaluate("document.querySelector('.prism-dialog [data-act=ok]').click()");
  await waitFor("document.querySelector('#officialDeadlineList button') && !document.querySelector('#officialDeadlineList button').disabled && !document.querySelector('.prism-dialog')");
  check(requests.filter(r=>r.file==='calendar_deadlines_api.php'&&r.action==='cancel').length===cancelBefore+1,'Repeated cancel issues one request');
  apiDelay=0; deadlineFailure=true;
  await evaluate("{const f=document.querySelector('#deadlineForm');f.elements.title.value='Retry';f.elements.date.value='2026-10-10';f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))}");
  await waitFor("!document.querySelector('#deadlineForm [type=submit]').disabled");
  check(await evaluate("document.querySelector('#deadlineForm').elements.title.value==='Retry'"),'Failed save retains input and restores control');
  deadlineFailure=false;
  await navigate('calendar.php',375,false,'error');
  await waitFor("document.querySelector('#officialLoadState').textContent.includes('Could not load')");
  check(await evaluate("document.querySelectorAll('#monthGrid .calendar-day').length===42"),'API failure leaves personal calendar functional');
  await navigate('calendar.php',375,false,'empty');
  await waitFor("document.querySelector('#officialLoadState').textContent.includes('No official deadlines')");
  check(await evaluate("document.querySelector('#officialDeadlineCount').textContent.includes('0 of 0')"),'Official zero-result feedback');
}


async function checkWorkspaceConsistency() {
  const expected = {
    'admin_students.php':'Student Records', 'admin_advisers.php':'Research Advisers', 'ierbprog.php':'IERB Progress',
    'documents.php':'Document Submissions', 'admin_notifications.php':'Notifications', 'admin_ai.php':'AI Progress Reports',
    'reports.php':'Generated Reports', 'calendar.php':'Personal Calendar', 'account.php':'Account & Activity',
  };
  const viewers = {
    admin: ['dashboard.php','admin_students.php','admin_advisers.php','ierbprog.php','documents.php','reports.php','account.php','calendar.php','admin_ai.php','admin_notifications.php'],
    adviser: ['research_adviser.php','admin_students.php','ierbprog.php','documents.php','account.php','calendar.php','admin_notifications.php'],
    student: ['student.php'],
  };
  for (const [viewer, files] of Object.entries(viewers)) for (const file of files) {
    managementFixture = file === 'admin_advisers.php' ? 'adviser' : 'student';
    for (const width of [320,375,1280]) for (const dark of [false,true]) {
      await navigate(file,width,dark,'populated',viewer);
      const label = `${viewer} ${file} ${width}px ${dark ? 'dark' : 'light'} workspace`;
      await measure(pageWrappers[file] || file,width,dark,' A9');
      if (file === 'dashboard.php' && width < 480) {
        await evaluate('document.querySelector(".prism-tip").focus()');
        check(await evaluate('getComputedStyle(document.querySelector(".prism-tip"),"::after").display === "block" && document.documentElement.scrollWidth <= innerWidth + 1'),label + ': focused help tooltip fits narrow viewport');
      }
      check(await evaluate(`document.querySelectorAll('#themeToggle').length === 1 && document.querySelectorAll('a[href="logout.php"]').length === 1`),label + ': single theme/logout');
      check(await evaluateFunction(() => {
        const theme = document.getElementById('themeToggle');
        return theme.tagName === 'BUTTON' && theme.type === 'button' && theme.title === 'Toggle light or dark theme'
          && theme.getAttribute('aria-label') === theme.title && !!theme.closest('.top-controls,.portal-nav-right')
          && theme.querySelectorAll('.light-icon[aria-hidden="true"],.dark-icon[aria-hidden="true"]').length === 2;
      }),label + ': accessible shared theme button/header');
      if (viewer !== 'student') {
        check(await evaluate(`!document.querySelector('.sidebar-bottom,.profile-menu,#profileToggle') && document.querySelectorAll('#prismPrimaryNavigation').length === 1`),label + ': canonical sidebar without legacy profile navigation');
        if (viewer === 'admin' && expected[file]) check(await evaluateFunction(heading => document.querySelector('main h1').textContent.trim() === heading
          && document.querySelector('#prismPrimaryNavigation [aria-current="page"]').textContent.trim() === heading,expected[file]),label + ': heading matches navigation');
        check(await evaluate(`!document.querySelector('#prismPrimaryNavigation a[href="#"]')`),label + ': no placeholder navigation links');
      }
      await evaluateFunction(dark => {
        localStorage.setItem('prismTheme',dark ? 'dark' : 'light');
        document.getElementById('themeToggle').focus();
      },dark);
      await keyPress('Enter','Enter',13);
      check(await evaluateFunction(dark => document.documentElement.classList.contains('dark-theme') === !dark
        && localStorage.getItem('prismTheme') === (dark ? 'light' : 'dark'),dark),label + ': Enter toggles once and persists preference');
      await keyPress(' ','Space',32);
      check(await evaluateFunction(dark => document.documentElement.classList.contains('dark-theme') === dark
        && localStorage.getItem('prismTheme') === (dark ? 'dark' : 'light'),dark),label + ': Space toggles once');
      check(await evaluateFunction(dark => (getComputedStyle(document.querySelector('#themeToggle .light-icon')).display !== 'none') === !dark
        && (getComputedStyle(document.querySelector('#themeToggle .dark-icon')).display !== 'none') === dark,dark),label + ': existing sun/moon styling');
      await evaluate('window.__workspaceBeforeReload = true');
      await command('Page.reload');
      await waitFor('document.readyState === "complete" && !window.__workspaceBeforeReload && !!document.getElementById("themeToggle")');
      check(await evaluateFunction(dark => document.documentElement.classList.contains('dark-theme') === dark,dark),label + ': persisted theme restored after reload');
    }
  }
  managementFixture = 'student';
  await navigate('reports.php',1280,false,'populated');
  check(await evaluate(`!document.getElementById('generateSummarizedReport') && !document.getElementById('generateFullReport') && !!document.querySelector('.report-tools a[href="admin_ai.php"]')`),'Generated Reports links to AI creation without duplicate handlers');
  check(await evaluate(`!!document.querySelector('#reportTableBody a[href*="action=file"]') && !!document.getElementById('exportExcel') && !!document.querySelector('#reportTableBody button.delete[aria-label="Delete report"]')`),'Generated Reports retains view/download/export and visible destructive style');
  const calls = requests.filter(r => r.file === 'reports_api.php' && r.action === 'ai_report').length;
  await evaluate('document.getElementById("openStudentReport").click()');
  await waitFor('document.querySelector("#reportStudent option") && document.getElementById("studentModal").style.display === "flex"');
  await evaluate('document.getElementById("studentReportForm").requestSubmit()');
  await waitFor('document.getElementById("studentModal").style.display === "none"');
  checkPayload('reports_api.php','generate',{type:'Student Report',studentId:1},'Unique Student Report generation retains payload');
  check(requests.filter(r => r.file === 'reports_api.php' && r.action === 'ai_report').length === calls,'Student Report/browsing does not trigger AI generation');
  await evaluate('document.querySelector("#reportTableBody button.delete").click()');
  await waitFor('!!document.querySelector(".prism-dialog [data-act=ok]")');
  await evaluate('document.querySelector(".prism-dialog [data-act=ok]").click()');
  await waitFor('!document.querySelector(".prism-dialog")');
  await waitFor('[...document.querySelectorAll(".prism-toast")].some(toast => toast.textContent.includes("Report deleted"))');
  checkPayload('reports_api.php','delete',{id:'fixture-report'},'Report deletion keeps existing request/confirmation');
  await navigate('account.php',1280,false,'populated');
  await evaluate('document.getElementById("accountName").value="Updated Fixture";document.getElementById("accountProfileForm").requestSubmit()');
  await waitFor('!document.querySelector("#accountProfileForm [type=submit]").disabled');
  checkPayload('profile_api.php','update_profile',{name:'Updated Fixture'},'Profile save retains account functionality after legacy sidebar removal');
  check(await evaluate('!!document.querySelector(".prism-toast.is-success") && !document.getElementById("sideAccountName")'),'Profile save succeeds without removed legacy identity node');
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
  await waitFor("document.querySelectorAll('#ierbMonitorBody tr[data-course]').length===0");
  check(await evaluate(`[...document.querySelectorAll('#ierbMonitorBody tr[data-course]')].every(row=>row.style.display==='none')`), 'Dashboard search filters existing authorized records');
  const summariesBefore = requests.filter(r=>r.file==='documents_api.php' && r.action==='summarize').length;
  await evaluateFunction(()=>{
    const search=document.getElementById('dashboardSearch');search.value='';search.dispatchEvent(new Event('input'));
  });
  await waitFor(`!!document.querySelector('[data-monitor-action="documents"]')`);
  await evaluateFunction(()=>{
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
    check(await evaluate(file==='documents.php'?'!!document.querySelector("[data-summary]") && !!document.getElementById("summaryModal")':'!document.querySelector("[data-summary],#summaryModal,#openDocumentReport,#documentModal")'), file + ': summary controls scoped to Admin repository');
  }
  const reportCalls = requests.filter(r=>r.file==='reports_api.php' && r.action==='ai_report').length;
  check(await evaluateFunction(() => !!document.querySelector('.report-tools a[href="admin_ai.php"]') && !document.getElementById('generateSummarizedReport')), 'Generated Reports directs AI creation to the existing AI workspace');
  await navigate('admin_ai.php',1280,false,'populated');
  await evaluate('document.getElementById("generateSummarizedReport").click()');
  await waitFor('!document.getElementById("generateSummarizedReport").disabled');
  check(requests.filter(r=>r.file==='reports_api.php' && r.action==='ai_report').length===reportCalls+1, 'Reports aggregate summary still generates independently of repository summaries');
}

async function checkDocumentSummary() {
  summaryStored=null;summaryProvider='ai';
  for(const [width,dark] of [[375,false],[1280,true]]) {
    await navigate('documents.php',width,dark,'populated','admin');
    await waitFor('document.querySelector("[data-summary]")');
    const before=requests.filter(r=>r.file==='documents_api.php'&&r.action==='summarize').length;
    apiDelay=120;
    await evaluate('document.querySelector("[data-summary]").click()');
    if(!summaryStored)check(await evaluate('document.getElementById("summaryResult").getAttribute("aria-busy")==="true" && document.getElementById("regenerateSummary").disabled'),'Loading state disables duplicate regeneration');
    await waitFor('!document.getElementById("regenerateSummary").disabled');apiDelay=0;
    check(await evaluate('document.getElementById("summaryResult").textContent.includes("University research") && !document.querySelector("#summaryResult img,#summaryFilename img") && !window.__fixtureXss'),'Summary and filename render as safe text');
    check(await evaluate('document.querySelector("#summaryModal [role=dialog]").getAttribute("aria-modal")==="true" && document.getElementById("summaryHeading").textContent==="AI-Assisted Document Summary"'),'Accessible AI-assisted heading');
    check(await evaluate('document.querySelector(".summary-review-note").textContent.includes("Verify important information against the original document")'),'Human review notice present');
    check(await evaluateFunction(()=>{const box=document.querySelector('.summary-dialog').getBoundingClientRect();return box.left>=0&&box.right<=innerWidth&&box.bottom<=innerHeight}),`Summary fits ${width}px ${dark?'dark':'light'}`);
    if(width===375) {
      check(requests.filter(r=>r.file==='documents_api.php'&&r.action==='summarize').length===before+1,'Generate invokes exactly one selected document request');
      const sent=JSON.parse(requests.findLast(r=>r.file==='documents_api.php'&&r.action==='summarize').body);
      check(Object.keys(sent).join(',')==='id'&&sent.id==='fixture-doc','Client sends only selected document ID');
      check(await evaluate('document.getElementById("summarySource").textContent.includes("AI generated") && document.getElementById("summarySource").textContent.includes("bounded excerpt")'),'AI source and omitted coverage explicit');
    } else {
      check(requests.filter(r=>r.file==='documents_api.php'&&r.action==='summarize').length===before,'Reopened saved summary triggers no provider request');
      check(await evaluate('document.getElementById("summarySource").textContent==="Source not recorded"'),'Persisted summary source is not guessed');
    }
    await evaluate('document.querySelector("#summaryModal [data-close]").focus()');
    await command('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',modifiers:8});
    check(await evaluate('document.activeElement.id==="regenerateSummary"'),'Modal traps reverse-tab focus');
    await command('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
    check(await evaluate('!document.getElementById("summaryModal").classList.contains("show") && document.activeElement.hasAttribute("data-summary")'),'Escape closes and restores focus');
  }
  await evaluate('document.querySelector("[data-summary]").click()');
  summaryProvider='local_fallback';
  await evaluate('document.getElementById("regenerateSummary").click()');await waitFor('!document.getElementById("regenerateSummary").disabled');
  check(await evaluate('document.getElementById("summarySource").textContent.includes("Local extractive fallback")'),'Local fallback explicitly labeled');
  summaryProvider='error';
  await evaluate('document.getElementById("regenerateSummary").click()');await waitFor('!document.getElementById("regenerateSummary").disabled');
  check(await evaluate('document.getElementById("summaryResult").textContent.includes("scanned/image-only PDF") && !document.querySelector("#summaryResult img")'),'Specific extraction error is safe and useful');
  await evaluate('document.querySelector("#summaryModal [data-close]").click();document.querySelector("[data-summary]").click()');
  check(await evaluate('document.getElementById("summaryResult").textContent.includes("University research")'),'Failed regeneration retains saved summary');
  await evaluate('document.querySelector("#summaryModal [data-close]").click()');
  for(const id of ['folderViewButton','courseViewButton']) {
    await evaluateFunction(id=>document.getElementById(id).click(),id);
    check(await evaluateFunction(id=>!!document.querySelector(id==='folderViewButton'?'#documentsFolderView [data-summary]':'#documentsCourseView [data-summary]'),id),'Summary action available in '+id);
  }
  await navigate('documents.php',1280,false,'populated','adviser');
  check(await evaluate('!document.querySelector("[data-summary],#summaryModal")'),'Adviser repository has no summary controls');
  await navigate('role_portal.php',375,false,'student-ready','student');
  check(await evaluate('!document.querySelector("[data-summary],#summaryModal")'),'Student portal has no summary controls');
  summaryProvider='ai';summaryStored=null;
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

async function checkAdviserHeaderControls() {
  for(const width of [320,375,1280]) for(const dark of [false,true]) {
    await navigate('research_adviser.php',width,dark,'populated','adviser');
    await evaluateFunction(dark=>localStorage.setItem('prismTheme',dark?'dark':'light'),dark);
    await navigate('research_adviser.php',width,dark,'populated','adviser');
    check(await evaluateFunction(()=>{
      const refresh=document.getElementById('adviserRefresh'),theme=document.getElementById('themeToggle');
      return refresh.tagName==='BUTTON' && theme.tagName==='BUTTON' && refresh.classList.contains('icon-btn') && theme.classList.contains('theme-toggle')
        && refresh.title==='Refresh dashboard' && refresh.getAttribute('aria-label')==='Refresh dashboard'
        && !!refresh.querySelector('.fa-rotate-right[aria-hidden=true]') && !!theme.querySelector('.light-icon') && !!theme.querySelector('.dark-icon')
        && !refresh.textContent.trim() && !theme.textContent.trim() && document.querySelectorAll('#adviserRefresh').length===1;
    }),'Adviser header reuses compact labeled PRISM controls');
    check(await evaluateFunction(dark=>document.getElementById('themeToggle').getAttribute('aria-pressed')===String(dark),dark),'Adviser theme aria state matches persisted preference');
    check(await evaluateFunction(dark=>getComputedStyle(document.querySelector('#themeToggle .light-icon')).display!=='none'===!dark && getComputedStyle(document.querySelector('#themeToggle .dark-icon')).display!=='none'===dark,dark),'Standard shared CSS displays correct sun/moon icon');
    await evaluate('document.getElementById("themeToggle").focus()');await keyPress('Enter','Enter',13);
    check(await evaluateFunction(dark=>document.documentElement.classList.contains('dark-theme')===!dark && localStorage.getItem('prismTheme')===(dark?'light':'dark') && document.getElementById('themeToggle').getAttribute('aria-pressed')===String(!dark),dark),'Keyboard theme toggle changes and persists prismTheme');
    const token=Date.now();await evaluateFunction(token=>{window.__a8ReloadSentinel=token;document.getElementById('adviserQueueFilter').value='Pending Adviser Review';document.getElementById('adviserQueueFilter').dispatchEvent(new Event('change'));},token);
    await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');
    const before=requests.filter(r=>r.file==='students_api.php'&&r.action==='list').length;
    readinessFixture=true;
    try {
      await evaluate('document.getElementById("adviserRefresh").focus()');await keyPress('Enter','Enter',13);
      await waitFor('document.getElementById("adviserStudentsCount").textContent==="113" && document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');
      check(requests.filter(r=>r.file==='students_api.php'&&r.action==='list').length===before+1,'Keyboard refresh performs existing API reload once and updates data');
      check(await evaluateFunction((token,dark)=>window.__a8ReloadSentinel===token && document.getElementById('adviserQueueFilter').value==='Pending Adviser Review' && localStorage.getItem('prismTheme')===(dark?'light':'dark'),token,dark),'Refresh preserves current page, filter and theme preference');
    } finally {readinessFixture=false;}
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1 && document.getElementById("themeToggle").getBoundingClientRect().width>=42 && document.getElementById("adviserRefresh").getBoundingClientRect().width>=36'),'Compact adviser controls remain usable at mobile and desktop widths');
    await navigate('research_adviser.php',width,!dark,'populated','adviser');
    check(await evaluateFunction(dark=>document.documentElement.classList.contains('dark-theme')===!dark && document.getElementById('themeToggle').getAttribute('aria-pressed')===String(!dark),dark),'Adviser theme survives navigation through existing initialization');
  }
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
  await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")=="false" && !document.querySelector("[data-review-id]")');
  check(await evaluate('!document.querySelector("[data-review-id]")'), 'Adviser search filters current scoped results');
  await evaluateFunction(()=>{const e=document.getElementById('adviserQueueSearch');e.value='';e.dispatchEvent(new Event('input'));const f=document.getElementById('adviserQueueFilter');f.value='Needs Revision';f.dispatchEvent(new Event('change'));});
  await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")=="false" && !document.querySelector("[data-review-id]")');
  check(await evaluate('!document.querySelector("[data-review-id]")'), 'Adviser workflow filter uses existing stage-independent key');
  await evaluateFunction(()=>{const f=document.getElementById('adviserQueueFilter');f.value='';f.dispatchEvent(new Event('change'));});
  await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")=="false" && !!document.querySelector("[data-review-id]")');
  await evaluate("const b=document.querySelector('[data-review-id]');b.focus();b.click();");
  check(await evaluate('document.getElementById("adviserReviewDialog").open'),'Adviser review dialog opens');
  await keyPress('Escape','Escape',27);
  check(await evaluate('!document.getElementById("adviserReviewDialog").open && document.activeElement.matches("[data-review-id]")'), 'Adviser review Escape returns focus');
  for(const status of ['Approved','Resubmission Requested','Denied']) {
    await navigate('research_adviser.php',1280,false,'populated','adviser');
    apiDelay=150;
    await evaluateFunction(status=>{document.querySelector('[data-review-id]').click();document.getElementById('adviserReviewStatus').value=status;document.getElementById('adviserReviewStatus').dispatchEvent(new Event('change'));document.getElementById('adviserReviewRemarks').value='  Reviewed fixture  ';document.getElementById('adviserReviewForm').requestSubmit();},status);
    check(await evaluate('document.getElementById("adviserReviewSave").disabled && !document.getElementById("adviserReviewCancel").disabled'),'Adviser review disables only its save control');
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
  check(upload?.method==='POST' && ['name="document"','filename="fixture.txt"','name="documentType"','Research Protocol','name="stage"','Stage 1','name="notes"','Fixture notes'].every(part=>upload.body.includes(part)) && !upload.body.includes('Research title: Fixture title'),'Student upload sends editable file/type/notes, not forged institutional metadata');
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
    await waitFor('(() => {const i=document.querySelector(".ceu-footer-brand img");return i.complete && i.naturalWidth>0;})()');
    const footer=await evaluateFunction(()=>{
      const node=document.querySelector('.ceu-footer'),img=node.querySelector('.ceu-footer-brand img'),r=node.getBoundingClientRect(),im=img.getBoundingClientRect();
      return {
        flow:getComputedStyle(node).position==='static' && r.top>=node.previousElementSibling.getBoundingClientRect().bottom-1,
        fits:r.left>=-1 && r.right<=innerWidth+1 && node.scrollWidth<=node.clientWidth+1 &&
          [...node.querySelectorAll('a,p,h2,.ceu-footer-brand img')].every(e=>{const b=e.getBoundingClientRect();return b.left>=r.left-1 && b.right<=r.right+1;}),
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

async function checkStudentDashboardProtocol() {
  const resultDir=path.join(root,'tests','release-candidate-results');fs.mkdirSync(resultDir,{recursive:true});
  const base={id:1,studentId:'S1',name:'Alexandra Santos',research:'Community Information Technology Research',groupId:'AMT-BSIT-Y2-2627-G01',stage:'Stage 1',status:'On Track',progress:20,isPrincipalInvestigator:true};
  const cases=[['absent',undefined],['null',null],['empty',''],['assigned','CEU-IERB-2026-0001'],['long','CEU-IERB-'+'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.repeat(12)],['literal',attack]];
  try {
    for(const width of [1920,1366,1024,768,390,375,320])for(const dark of [false,true]) {
      let expectedRequests=null;
      for(const [kind,code] of cases) {
        academicRecord={...base,protocolCode:code};
        const start=requests.length;
        await navigate('student.php',width,dark,'populated','student');await evaluate('document.fonts.ready');
        const label=`Student protocol ${kind} ${width} ${dark}`;
        const result=await evaluateFunction(code=>{
          const badge=document.getElementById('dashboardProtocol'),value=document.getElementById('dashboardProtocolCode'),label=badge.querySelector('b');
          const range=document.createRange();range.selectNodeContents(label);
          const hero=badge.closest('.welcome-card').getBoundingClientRect();
          const codeRange=document.createRange();codeRange.selectNodeContents(value);
          return {visible:!badge.hidden&&badge.getClientRects().length>0,text:value.textContent,safe:!value.querySelector('*'),labelWhole:!code||new Set([...range.getClientRects()].map(r=>Math.round(r.top))).size===1,fits:!code||[...codeRange.getClientRects()].every(r=>r.left>=hero.left&&r.right<=hero.right),font:getComputedStyle(badge).fontSize,submit:document.getElementById('submissionProtocol').value,documents:document.getElementById('protocolCodeValue').textContent,documentsVisible:!document.getElementById('protocolCodeCard').hidden};
        },code||'');
        check(result.visible===!!code,label+': assigned metadata visible; unassigned hidden');
        check(result.text===(code||'')&&result.safe,label+': code rendered literally and safely');
        check(result.labelWhole&&result.fits,label+': label whole and long code contained');
        check(result.font==='11.5px',label+': secondary metadata typography');
        check(result.submit===(code||'Not assigned')&&result.documentsVisible===!!code&&(!code||result.documents===code),label+': Submit Document and My Documents preserve code');
        const signature=requests.slice(start).map(r=>`${r.method} ${r.file} ${r.action}`).sort().join('|');
        if(expectedRequests===null)expectedRequests=signature;
        check(signature===expectedRequests,label+': no additional requests for protocol code',signature);
        await measure('role_portal.php',width,dark,' protocol '+kind);
        if(code) {
          await evaluate("document.querySelector('#portalNav [data-page=progress]').click();document.getElementById('principalIndicator').click()");
          check(await evaluateFunction(code=>!document.getElementById('protocolCodeReveal').hidden&&document.getElementById('protocolCodeReveal').textContent==='Protocol Code: '+code,code),label+': IERB Progress retains its existing code reveal');
          await evaluate("document.querySelector('#portalNav [data-page=dashboard]').click()");
        }
        if(width===320&&['assigned','long'].includes(kind)) {
          const png=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(resultDir,`student-protocol-${kind}-${dark?'dark':'light'}.png`),Buffer.from(png.data,'base64'));
        }
      }
    }
    academicRecord={...base,protocolCode:'CEU-IERB-2026-0001',isPrincipalInvestigator:false};
    await navigate('student.php',320,false,'populated','student');
    check(await evaluate("!document.getElementById('dashboardProtocol').hidden&&document.getElementById('principalIndicator').hidden"),'Assigned Dashboard code is independent of Principal Investigator reveal');
    academicRecord=null;
    for(const state of ['empty','error']) {
      await navigate('student.php',320,false,state,'student');
      check(await evaluate("document.getElementById('dashboardProtocol').hidden&&!document.getElementById('dashboardProtocolCode').textContent"),'No record/load error hides Dashboard protocol '+state);
    }
  } finally {academicRecord=null;}
}

async function checkShellPolish() {
  const baseline=process.argv.includes('--shell-polish-baseline');
  const resultDir=path.join(root,'tests','release-candidate-results');fs.mkdirSync(resultDir,{recursive:true});
  const samples=[];
  const viewers={admin:['dashboard.php','admin_students.php','admin_advisers.php','ierbprog.php','documents.php','reports.php','admin_ai.php','admin_notifications.php','calendar.php','account.php','data_export.php'],adviser:['research_adviser.php','admin_students.php','documents.php','ierbprog.php','admin_notifications.php','calendar.php','account.php'],student:['student.php']};
  async function inspect(label) {
    const result=await evaluateFunction(()=>{
      const split=[],clipped=[],walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
      while(walker.nextNode()) {
        const n=walker.currentNode,e=n.parentElement;
        if(!n.textContent.trim()||!e.getClientRects().length||e.closest('script,style,.fa-solid,.fa-regular,.fa-brands')||getComputedStyle(e).visibility==='hidden'||(e.closest('details:not([open])')&&!e.closest('summary')))continue;
        // Filenames, email addresses and generated identifiers can wrap within tokens.
        for(const match of n.textContent.matchAll(/[A-Za-z]+/g)) {
          if(match[0].length<4||match[0].length>32||/[0-9_@./=<>-]/.test(n.textContent[match.index-1]||'')||/[0-9_@./=<>-]/.test(n.textContent[match.index+match[0].length]||''))continue;
          const range=document.createRange();range.setStart(n,match.index);range.setEnd(n,match.index+match[0].length);
          if(new Set([...range.getClientRects()].filter(r=>r.width>.5).map(r=>Math.round(r.top))).size>1)split.push({word:match[0],element:e.tagName+'.'+e.className,wrap:getComputedStyle(e).overflowWrap,width:e.getBoundingClientRect().width});
        }
        const button=e.closest('button');
        if(button&&!['absolute','fixed'].includes(getComputedStyle(e).position)) {
          const range=document.createRange();range.selectNodeContents(n);const r=range.getBoundingClientRect(),b=button.getBoundingClientRect();
          if(r.width&&(r.left<b.left-2||r.right>b.right+2||r.top<b.top-2||r.bottom>b.bottom+2))clipped.push(button.id||button.className);
        }
      }
      const table=document.querySelector('.dashboard-table-scroll .data-table');
      const columns=table?[...table.querySelectorAll('tbody tr:first-child td')].map(e=>({text:e.textContent,width:e.getBoundingClientRect().width,wrap:getComputedStyle(e).overflowWrap,wordBreak:getComputedStyle(e).wordBreak,font:getComputedStyle(e).fontSize})):[];
      return {split,clipped,columns,pageWidth:document.documentElement.scrollWidth,viewport:innerWidth,fontLoaded:[...document.fonts].some(f=>f.family.includes('Montserrat')&&f.status==='loaded')};
    });
    samples.push({label,...result});
    if(!baseline) {
      check(!result.split.length,label+': ordinary words wrap naturally',JSON.stringify(result.split));
      check(!result.clipped.length,label+': button labels fit',result.clipped.join(', '));
      check(result.pageWidth<=result.viewport+1,label+': no page horizontal overflow');
      check(result.fontLoaded,label+': actual Montserrat loaded');
    }
  }
  academicRecord={id:1,studentId:'S1',name:'Alexandra Christine Santos',email:'student@example.test',group:'AMT-BSIT-Y2-2627-G01',course:'BS in Information Technology',academicUnitKey:'amt',programKey:'bsit',yearLevel:'2nd Year',academicYear:'2026-2027',research:'Community Information Technology and Sustainable Research Development',requirements:'Application documentation and research methodology',stage:'Stage 1',status:'On Track'};
  for(const [viewer,files] of Object.entries(viewers))for(const file of files)for(const [width,height] of (baseline?[[1366,768],[320,844]]:[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,844],[320,844]]))for(const dark of (baseline?[false]:[false,true])) {
    managementFixture=file==='admin_advisers.php'?'adviser':'student';
    await navigate(file,width,dark,'populated',viewer);
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    await evaluate('document.fonts.ready');
    await evaluate("document.querySelectorAll('[data-prism-resources] details').forEach(e=>e.open=true)");
    await evaluate("document.querySelectorAll('.activity-audit-details,.deadline-create').forEach(e=>e.open=true)");
    const label=`${viewer} ${file} ${width} ${dark}`;
    await inspect(label);
    if(!baseline)await measure(pageWrappers[file]||file,width,dark,' shell polish '+viewer);
    if(viewer==='student')for(const section of ['progress','submit','documents','calendar','notifications','profile']) {
      await evaluateFunction(section=>{location.hash='#'+section},section);
      await waitFor(`document.querySelector('.portal-page.active').dataset.section===${JSON.stringify(section)}`);
      await inspect(label+' '+section);
    }
    if(!baseline&&viewer!=='student')await checkNavigation(file,width,dark);
    if(!baseline&&['dashboard.php','research_adviser.php','student.php'].includes(file)) {
      await evaluateFunction(viewer=>{
        if(viewer==='admin') {
          const toggle=document.getElementById('prismSidebarToggle');if(toggle.getAttribute('aria-expanded')!=='true')toggle.click();
          document.querySelectorAll('.prism-nav-group-toggle').forEach(e=>{if(e.getAttribute('aria-expanded')!=='true')e.click()});
        } else {
          const toggle=document.querySelector('.portal-navigation-toggle');if(getComputedStyle(toggle).display!=='none'&&toggle.getAttribute('aria-expanded')!=='true')toggle.click();
        }
      },viewer);
      await evaluate('new Promise(resolve=>setTimeout(resolve,400))');
      await inspect(label+' open navigation');
      await evaluateFunction(viewer=>{
        const toggle=document.querySelector(viewer==='admin'?'#prismSidebarToggle':'.portal-navigation-toggle');if(toggle.getAttribute('aria-expanded')==='true')toggle.click();
        document.querySelector('[data-prism-account-toggle],#portalProfileToggle')?.click();
      },viewer);
      await evaluate('new Promise(resolve=>setTimeout(resolve,400))');
      await inspect(label+' account dropdown');
      check(await evaluateFunction(()=>[...document.querySelectorAll('.portal-profile-dropdown:not([hidden]),.prism-account-links:not([hidden])')].every(e=>{const r=e.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1&&e.scrollWidth<=e.clientWidth+1})),label+': account dropdown fits');
      await evaluate("document.querySelector('[data-prism-account-toggle],#portalProfileToggle')?.click()");
      if(viewer==='admin') {
        await evaluate("document.getElementById('notificationToggle').click()");await inspect(label+' notification dropdown');
        await measure(file,width,dark,' notification dropdown');await evaluate("document.getElementById('notificationToggle').click()");
      }
      if(viewer==='adviser') {
        await evaluate("document.querySelector('[data-review-id]').click()");await inspect(label+' review dialog');
        await measure(file,width,dark,' review dialog');await evaluate("document.getElementById('adviserReviewCancel').click()");
      }
    }
    if(!baseline&&file==='documents.php'&&viewer==='admin') {
      await evaluate("document.querySelector('[data-summary]').click()");await waitFor('document.getElementById("summaryResult").getAttribute("aria-busy")!=="true"');
      await inspect(label+' document summary');await measure(file,width,dark,' document summary');
      await evaluate("document.querySelector('#summaryModal [data-close]').click()");
    }
    if(['dashboard.php','research_adviser.php','student.php'].includes(file)&&[1366,320].includes(width)&&!dark) {
      if(viewer==='student')await evaluate("location.hash='#dashboard'");
      const target=viewer==='admin'?'.dashboard-table-scroll':viewer==='adviser'?'.adviser-panel':'main';
      await evaluateFunction(target=>document.querySelector(target)?.scrollIntoView({block:'center'}),target);
      if(viewer==='admin')await evaluate("document.querySelector('.dashboard-table-scroll').scrollLeft=130");
      const png=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(resultDir,`shell-${baseline?'before':'after'}-${viewer}-${width}.png`),Buffer.from(png.data,'base64'));
    }
  }
  academicRecord=null;
  fs.writeFileSync(path.join(resultDir,`shell-polish-${baseline?'before':'after'}.json`),JSON.stringify({checks,failures,errors,samples},null,2));
  if(baseline)for(const sample of samples.filter(s=>s.split.length||s.clipped.length))console.log(JSON.stringify({label:sample.label,split:sample.split,clipped:sample.clipped,columns:sample.columns}));
}

async function checkReleaseTypography() {
  const resultDir=path.join(root,'tests','release-candidate-results');fs.mkdirSync(resultDir,{recursive:true});
  const sizes=[];
  const viewers={admin:['dashboard.php','admin_students.php','admin_advisers.php','ierbprog.php','documents.php','admin_notifications.php','calendar.php','reports.php','admin_ai.php','account.php','data_export.php'],adviser:['research_adviser.php','admin_students.php','documents.php','ierbprog.php','calendar.php','account.php'],student:['student.php']};
  for (const [viewer,files] of Object.entries(viewers)) for (const file of files) for (const [width,height] of [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812],[320,568]]) for (const dark of [false,true]) {
    managementFixture=file==='admin_advisers.php'?'adviser':'student';
    await navigate(file,width,dark,'populated',viewer);
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    await evaluate('new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))');
    await measure(file,width,dark,' release '+viewer);
    const measured=await evaluateFunction(()=>{
      const sample=selector=>{const e=document.querySelector(selector);return e?parseFloat(getComputedStyle(e).fontSize):null};
      const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT),undersized=[];
      while(walker.nextNode()) { const n=walker.currentNode,e=n.parentElement;if(!n.textContent.trim()||!e.getClientRects().length||e.closest('script,style,.fa-solid,.fa-regular,.fa-brands'))continue;
        if(parseFloat(getComputedStyle(e).fontSize)<10)undersized.push(e.className+' '+n.textContent.trim().slice(0,30));
      }
      const nav=document.querySelector('.portal-navbar');let navFits=true;
      if(nav){const r=nav.getBoundingClientRect();navFits=r.left>=-1&&r.right<=innerWidth+1&&r.bottom<=document.querySelector('main').getBoundingClientRect().top+1;}
      return {body:sample('body'),root:sample('html'),tableBody:sample('main td'),tableHead:sample('main th'),mainNav:sample('.prism-nav-group-toggle,.portal-nav-links a,.portal-nav-links button'),submenu:sample('.prism-nav-submenu a'),profileMeta:sample('.portal-profile-btn small'),input:sample('main input:not([type=hidden]):not([type=checkbox])'),button:sample('.management-primary,.upload-document-button,.prism-btn,.ai-action-btn'),summary:sample('.summary-result'),undersized,navFits};
    });
    sizes.push({viewer,file,width,height,dark,...measured});
    const label=viewer+' '+file+' '+width+' '+dark;
    check(measured.root===16&&measured.body===13.5,label+': unchanged root; body 13.5px');
    check(measured.undersized.length===0,label+': meaningful text at least 10px',measured.undersized.join(', '));
    check(measured.navFits,label+': navigation clears content');
    if(measured.tableBody!==null)check(measured.tableBody===13.5,label+': table body uses semantic size',String(measured.tableBody));
    if(measured.tableHead!==null)check(measured.tableHead===12.5,label+': table headings use semantic size',String(measured.tableHead));
    if(viewer==='admin') {
      await evaluate('document.getElementById("prismNavReportingToggle").click()');
      if(width>=1024){await evaluate('document.getElementById("prismSidebarToggle").click()');await measure(file,width,dark,' collapsed');await evaluate('document.getElementById("prismSidebarToggle").click()');}
    } else if(width<=1024) {
      await evaluate('document.querySelector(".portal-navigation-toggle").click()');
      check(await evaluateFunction(()=>document.querySelector('.portal-nav-links').scrollWidth<=document.querySelector('.portal-nav-links').clientWidth+1),label+': open mobile navigation fits');
      await evaluate('document.querySelector(".portal-navigation-toggle").click()');
    }
    if(file==='documents.php'&&viewer==='admin') {
      await evaluate('document.querySelector("[data-summary]").click()');await waitFor('document.getElementById("summaryResult").getAttribute("aria-busy")!=="true"');
      check(await evaluateFunction(()=>{const r=document.querySelector('.summary-dialog').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1&&getComputedStyle(document.querySelector('.summary-result')).fontSize==='14.5px';}),label+': summary readable and within viewport');
      await evaluate('document.querySelector("#summaryModal [data-close]").click()');
    }
    if(['dashboard.php','research_adviser.php','student.php','data_export.php'].includes(file)&&[1366,390].includes(width)&&!dark) {
      const png=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(resultDir,file.replace('.php','')+'-'+width+'.png'),Buffer.from(png.data,'base64'));
    }
  }
  fs.writeFileSync(path.join(resultDir,'typography-ui.json'),JSON.stringify({checks,failures,errors,sizes},null,2));
}

async function checkReleasePanels() {
  async function inspect(label) {
    const result=await evaluateFunction(()=>{
      const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT),small=[],clipped=[];
      while(walker.nextNode()) {const n=walker.currentNode,e=n.parentElement;
        if(!n.textContent.trim()||!e.getClientRects().length||e.closest('script,style,.fa-solid,.fa-regular,.fa-brands'))continue;
        if(parseFloat(getComputedStyle(e).fontSize)<10)small.push(n.textContent.trim().slice(0,40));
        const button=e.closest('button');
        if(button&&!['absolute','fixed'].includes(getComputedStyle(e).position)) {
          const range=document.createRange();range.selectNodeContents(n);const r=range.getBoundingClientRect(),b=button.getBoundingClientRect();
          if(r.width&&(r.left<b.left-2||r.right>b.right+2||r.top<b.top-2||r.bottom>b.bottom+2))clipped.push(button.id||button.className);
        }
      }
      return {small,clipped,page:document.documentElement.scrollWidth};
    });
    check(!result.small.length,label+': visible text at least 10px',result.small.join(', '));
    check(!result.clipped.length,label+': button text fits',result.clipped.join(', '));
    check(result.page<=await evaluate('innerWidth+1'),label+': page fits viewport');
  }
  for(const [viewer,file] of [['admin','dashboard.php'],['adviser','research_adviser.php'],['student','student.php']]) {
    for(const [width,height] of [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844]]) for(const dark of [false,true]) {
      await navigate(file,width,dark,'populated',viewer);
      await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
      await evaluate('document.fonts.ready');
      await evaluate("document.querySelectorAll('[data-prism-resources] details').forEach(e=>e.open=true)");
      await inspect(`${viewer} resources ${width} ${dark}`);
      if(viewer==='student') {
        for(const section of ['progress','submit','documents','calendar','notifications','profile']) {
          await evaluateFunction(section=>{location.hash='#'+section},section);
          await waitFor(`document.querySelector('.portal-page.active').dataset.section===${JSON.stringify(section)}`);
          await inspect(`${viewer} ${section} ${width} ${dark}`);
        }
      } else {
        await navigate('calendar.php',width,dark,'populated',viewer);
        await evaluate("document.querySelector('.deadline-create').open=true");
        await inspect(`${viewer} deadline form ${width} ${dark}`);
        await navigate('account.php',width,dark,'populated',viewer);
        await inspect(`${viewer} activity/account ${width} ${dark}`);
      }
    }
  }
}

async function checkDataExport() {
  for (const width of [1920,1366,1024,768,390]) for (const dark of [false,true]) {
    await navigate('data_export.php',width,dark,'populated','admin');
    await measure('data_export.php',width,dark);
    check(await evaluateFunction(()=>document.querySelectorAll('.data-export-card').length===4 && [...document.querySelectorAll('.data-export-card a')].every(a=>/^data_exports_api\.php\?action=(students|advisers|ierb|documents)$/.test(a.getAttribute('href')))), 'Four centralized export actions');
    check(await evaluateFunction(()=>document.querySelector('a[href="data_export.php"]').getAttribute('aria-current')==='page'),'Data Export has current Admin navigation');
    check(await evaluateFunction(()=>document.querySelector('.data-export-note').textContent.includes('backups')),'Export purpose includes backup boundary');
  }
  await navigate('research_adviser.php',1366,false,'populated','adviser');
  check(await evaluateFunction(()=>!document.querySelector('a[href="data_export.php"]')),'Adviser has no institution-wide export navigation');
  await navigate('student.php',390,false,'populated','student');
  check(await evaluateFunction(()=>!document.querySelector('a[href="data_export.php"]')),'Student has no institution-wide export navigation');
}

async function checkFooterCoverage() {
  const publicPages = ['login.php', 'forgot_password.php', 'reset_password.php', 'register.php',
    'change_password_required.php',  'login_admin.php', 'login_adviser.php', 'login_students.php'];
  for (const file of publicPages) {
    check(!/ceu.footer/.test(fs.readFileSync(path.join(root, file), 'utf8')), file + ': public/access page remains footer-free');
  }
  const screenshots = process.env.PRISM_TEST_SCREENSHOTS === '1'
    ? fs.mkdtempSync(path.join(os.tmpdir(), 'prism-footer-ui-')) : null;
  const footerMtime = Math.floor(fs.statSync(path.join(root, 'assets/css/ceu-footer.css')).mtimeMs / 1000);
  for (const file of [...pages, ...extraPages, ...Object.keys(pageWrappers)]) {
    const viewer = ['role_portal.php', 'student.php'].includes(file) ? 'student'
      : file === 'research_adviser.php' ? 'adviser' : 'admin';
    for (const width of [375, 1280]) for (const dark of [false, true]) {
      await navigate(file, width, dark, 'populated', viewer);
      await evaluateFunction(() => document.querySelector('.ceu-footer').scrollIntoView({block:'center'}));
      await waitFor('(() => {const img=document.querySelector(".ceu-footer-brand img");return img.complete && img.naturalWidth>0;})()');
      const result = await evaluateFunction(expectedMtime => {
        const footer = document.querySelector('.ceu-footer'), main = document.querySelector('main');
        const r = footer.getBoundingClientRect(), style = getComputedStyle(footer);
        const previous = [...main.children].filter(e => e !== footer && e.getClientRects().length
          && !['absolute','fixed'].includes(getComputedStyle(e).position));
        const links = [...document.querySelectorAll('link[rel="stylesheet"]')]
          .filter(e => new URL(e.href).pathname.endsWith('/assets/css/ceu-footer.css'));
        const sidebar = document.querySelector('.sidebar');
        const sidebarRect = sidebar?.getBoundingClientRect();
        return {
          exactlyOne: document.querySelectorAll('.ceu-footer').length === 1,
          placement: footer.parentElement === main && main.lastElementChild === footer
            && !footer.closest('form,dialog,.modal,.sidebar'),
          stylesheet: links.length === 1 && new URL(links[0].href).searchParams.get('v') === String(expectedMtime)
            && !!links[0].sheet,
          normalFlow: style.position === 'static' && previous.every(e => e.getBoundingClientRect().bottom <= r.top + 1),
          fits: r.left >= -1 && r.right <= innerWidth + 1 && footer.scrollWidth <= footer.clientWidth + 1
            && [...footer.querySelectorAll('a,p,h2,.ceu-footer-brand img')].every(e => {
              const b=e.getBoundingClientRect(); return b.left >= r.left-1 && b.right <= r.right+1;
            }),
          clearOfSidebar: !sidebarRect || sidebarRect.width === 0 || sidebarRect.right <= r.left + 1
            || sidebarRect.bottom <= r.top || sidebarRect.left >= r.right,
          colors: style.backgroundColor === 'rgb(12, 14, 63)' && getComputedStyle(footer.querySelector('h2')).color === 'rgb(255, 255, 255)',
          columns: getComputedStyle(footer.querySelector('.ceu-footer-details')).gridTemplateColumns.split(' ').length === (innerWidth <= 900 ? 1 : 3),
          logo: footer.querySelector('.ceu-footer-brand img').naturalWidth === 256,
        };
      }, footerMtime);
      for (const [name, ok] of Object.entries(result)) check(ok, `${file} ${width}px ${dark?'dark':'light'} footer: ${name}`);
      if (screenshots && ((file === 'account.php' && width === 375 && dark)
          || (file === 'reports.php' && width === 1280 && !dark)
          || (file === 'calendar.php' && width === 375 && !dark)
          || (file === 'admin_people.php' && width === 1280 && dark))) {
        await evaluate('new Promise(resolve=>setTimeout(resolve,400))');
        const png = await command('Page.captureScreenshot', {format:'png'});
        const destination = path.join(screenshots, file.replace('.php','') + '-' + width + '-' + (dark?'dark':'light') + '.png');
        fs.writeFileSync(destination, Buffer.from(png.data, 'base64'));
        console.log('Footer screenshot: ' + destination);
      }
    }
  }
}

async function checkAlignment() {
  paginatedFixture = true;
  try {
    for (const [file, pager, count, rows, viewer] of [
      ['account.php','activityPagination','activityCount','#activityList .activity-row','admin'],
      ['admin_notifications.php','noticePagination','noticeCount','#noticeHistory .history-item','adviser'],
      ['role_portal.php','studentNotificationPagination','studentNotificationCount','#notificationList .notice','student'],
    ]) {
      await navigate(file,375,true,'populated',viewer);
      if (viewer === 'student') await evaluate('document.querySelector(\'[data-go="notifications"]\').click()');
      await waitFor(`document.querySelectorAll(${JSON.stringify(rows)}).length === 10`);
      check(await evaluateFunction((pager,count) => {
        const nav=document.getElementById(pager);
        return document.getElementById(count).textContent.includes('Showing 1\u201310 of 113')
          && nav.querySelector('button').disabled && nav.textContent.includes('\u2026') && nav.querySelectorAll('button').length <= 8;
      },pager,count), file+': first page has ten rows, accurate range, compact controls and ellipsis');
      const click = async label => evaluateFunction((pager,label) => {
        const button=[...document.getElementById(pager).querySelectorAll('button')].find(b=>b.textContent===label);
        button.focus(); button.click();
      },pager,label);
      await click('Next');
      await waitFor(`document.getElementById(${JSON.stringify(count)}).textContent.includes('Showing 11\u201320 of 113')`);
      check(await evaluate(`document.querySelectorAll(${JSON.stringify(rows)}).length === 10`),file+': next page keeps page size');
      await click('12');
      await waitFor(`document.getElementById(${JSON.stringify(count)}).textContent.includes('Showing 111\u2013113 of 113')`);
      check(await evaluateFunction((pager,rows) => document.querySelectorAll(rows).length===3 && document.getElementById(pager).lastElementChild.disabled,pager,rows),file+': last page and Next boundary');
      await click('Previous');
      await waitFor(`document.getElementById(${JSON.stringify(count)}).textContent.includes('Showing 101\u2013110 of 113')`);
      await measure(file,375,true,' paginated history');
    }
    await navigate('account.php',1280,false,'populated');
    await evaluateFunction(() => {
      document.getElementById('activitySearch').value='matched';
      document.getElementById('activityFrom').value='2026-09-01';
      document.getElementById('activityTo').value='2026-09-30';
      document.getElementById('activityOverride').checked=true;
      document.getElementById('activityFilterForm').requestSubmit();
    });
    await waitFor('document.getElementById("activityCount").textContent.includes("Showing 1\u201310 of 23")');
    await evaluate('document.getElementById("activityPagination").lastElementChild.click()');
    await waitFor('document.getElementById("activityCount").textContent.includes("Showing 11\u201320 of 23")');
    const query=requests.findLast(r=>r.file==='audit_api.php').query;
    check(query.page==='2' && query.q==='matched' && query.from==='2026-09-01' && query.to==='2026-09-30' && query.override==='1','All activity filters survive page navigation');
    for (const [id,event] of [['activitySearch','input'],['activityFrom','change'],['activityTo','change'],['activityOverride','change']]) {
      await evaluateFunction((id,event)=>document.getElementById(id).dispatchEvent(new Event(event)),id,event);
      await waitFor('document.getElementById("activityCount").textContent.includes("Showing 1\u201310 of 23") && document.getElementById("activityList").getAttribute("aria-busy")==="false"');
      check(requests.findLast(r=>r.file==='audit_api.php').query.page==='1',id+': filter change resets page one');
      await evaluate('document.getElementById("activityPagination").lastElementChild.click()');
      await waitFor('document.getElementById("activityCount").textContent.includes("Showing 11\u201320 of 23")');
    }
    await evaluate('document.getElementById("activitySearch").value="none"; document.getElementById("activitySearch").dispatchEvent(new Event("input"))');
    await waitFor('document.getElementById("activityCount").textContent==="Showing 0\u20130 of 0 entries"');
    check(await evaluate('!document.querySelector("#activityList .activity-row") && document.getElementById("activityPagination").lastElementChild.disabled'),'Empty results retain truthful range and disabled boundaries');
  } finally { paginatedFixture=false; }
  managementFixture='adviser';
  try {
    await navigate('admin_people.php',375,true,'populated');
    check(await evaluate('document.querySelectorAll(".adviser-group-chip").length===2 && document.getElementById("recordRows").textContent.includes("No assigned research groups")'),'Adviser groups render as read-only chips and explicit empty state');
    await evaluate('document.querySelector(\'#recordRows button[title="Edit"]\').click()');
    check(await evaluate('!document.getElementById("groups") && document.getElementById("recordName").value'), 'Adviser edit has no free-text group field');
    await evaluate('document.getElementById("recordForm").requestSubmit()');
    await waitFor('document.getElementById("recordModal").style.display==="none"');
    check(!Object.hasOwn(JSON.parse(requests.findLast(r=>r.file==='advisers_api.php' && r.action==='save').body),'groups'),'Adviser save does not submit legacy group text');
  } finally { managementFixture='student'; }
  await navigate('admin_people.php',768,false,'populated','adviser');
  check(await evaluate('!document.querySelector(\'#prismPrimaryNavigation a[href="admin_ai.php"], #prismPrimaryNavigation a[href="reports.php"]\')'),'Adviser has no report navigation');
  await evaluate('document.getElementById("addRecord").click()');
  check(await evaluate('!!document.getElementById("studentAcademicFields") && document.getElementById("group").tagName==="SELECT"'),'Adviser Student Management retains academic and standardized group selectors');
  await navigate('reports.php',1280,false,'populated');
  check(await evaluate('!!document.querySelector(\'.report-tools a[href="admin_ai.php"]\') && !!document.getElementById("openStudentReport") && !!document.querySelector(\'#prismPrimaryNavigation a[href="admin_ai.php"]\') && !document.getElementById("generateSummarizedReport") && !document.getElementById("generateFullReport")'),'Admin retains Student Report and AI creation destination without duplicate generation controls');
}

async function checkFinalRegression() {
  readinessFixture=true;
  try {
    for(const [file,viewer,host,search,endpoint,more] of [
      ['admin_students.php','admin','recordFilters','recordSearch','students_api.php',true],
      ['admin_students.php','adviser','recordFilters','recordSearch','students_api.php',false],
      ['ierbprog.php','admin','ierbMoreFilters','ierbSearch','ierb_api.php',true],
      ['ierbprog.php','adviser','ierbMoreFilters','ierbSearch','ierb_api.php',false]]) {
      for(const [width,dark] of [[375,true],[1280,false]]) {
        managementFixture='student';await navigate(file,width,dark,'populated',viewer);
        check(await evaluateFunction(host=>!!document.getElementById(host+'_academicUnitKey') && !!document.getElementById(host+'_programKey') && !!document.getElementById(host+'_group') && !!document.getElementById(host+'_protocol'),host),file+' academic/group/protocol controls');
        check(await evaluateFunction((host,more)=>Boolean(document.getElementById(host+'_adviserId'))===more,host,more),file+' role-appropriate adviser filter');
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),file+' responsive added filters');
        const before=requests.filter(r=>r.file===endpoint&&r.action==='list').length;
        await evaluateFunction(id=>{const e=document.getElementById(id);for(const value of ['R','Re','Rec','Record']){e.value=value;e.dispatchEvent(new Event('input'));}},search);
        await evaluate('new Promise(resolve=>setTimeout(resolve,100))');
        check(requests.filter(r=>r.file===endpoint&&r.action==='list').length===before,file+' waits for search debounce');
        await waitFor(`document.querySelectorAll('.prism-pagination [aria-label="Page 2"]').length>0`);
        await evaluate('new Promise(resolve=>setTimeout(resolve,220))');
        check(requests.filter(r=>r.file===endpoint&&r.action==='list').length===before+1,file+' typing burst sends one search');
        await evaluateFunction(host=>{for(const [key,value] of [['academicUnitKey','amt'],['sortBy','studentId'],['direction','DESC']]){const e=document.getElementById(host+'_'+key);e.value=value;e.dispatchEvent(new Event('change'));}},host);
        await waitFor(`document.querySelector('.prism-pagination [aria-label="Page 2"]')!==null`);
        await evaluate('new Promise(resolve=>setTimeout(resolve,100));');
        await evaluate(`document.querySelector('.prism-pagination [aria-label="Page 2"]').click()`);
        await waitFor('document.querySelector(".prism-pagination [aria-current=page]")?.textContent==="2"');
        let latest=requests.findLast(r=>r.file===endpoint&&r.action==='list');
        check(latest.query.q==='Record'&&latest.query.academicUnitKey==='amt'&&latest.query.sortBy==='studentId'&&latest.query.direction==='DESC'&&latest.query.page==='2',file+' criteria retained across pages');
        await evaluateFunction(host=>{const e=document.getElementById(host+'_status') || document.getElementById('ierbStatusFilter');e.value=e.options[1].value;e.dispatchEvent(new Event('change'));},host);
        await waitFor('document.querySelector(".prism-pagination [aria-current=page]")?.textContent==="1"');
        check(requests.findLast(r=>r.file===endpoint&&r.action==='list').query.page==='1',file+' filter resets first page');
        await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="Clear Filters").click()');
        await waitFor(`document.getElementById(${JSON.stringify(search)}).value==='' && document.querySelector('.prism-pagination [aria-current=page]')?.textContent==='1'`);
        await waitFor(`document.getElementById(${JSON.stringify(endpoint==='ierb_api.php'?'ierbRecordCount':'recordCount')}).textContent.includes('of 113 records')`);
        latest=requests.findLast(r=>r.file===endpoint&&r.action==='list');
        check(['q','academicUnitKey','status','sortBy','direction'].every(k=>latest.query[k]==='')&&latest.query.page==='1',file+' Clear Filters resets all criteria');
      }
    }
    await navigate('role_portal.php',375,true,'populated','student');
    await waitFor('!!document.querySelector("[data-prism-student-workflow=compact] [data-submit=d22]")');
    check(requests.some(r=>r.file==='documents_api.php'&&r.query.actionable==='1'),'Student action panel uses bounded actionable endpoint');
    check(await evaluate("['submissionResearchTitle','submissionResearchGroup','submissionStudentId','submissionStage','submissionAdviser','submissionProtocol'].every(id=>document.getElementById(id)?.readOnly)"),'Institutional upload metadata read-only');
    check(await evaluate("!document.getElementById('documentNotes').readOnly && !document.getElementById('documentType').disabled && !document.getElementById('documentFile').disabled"),'File/type/notes remain editable');
  } finally { readinessFixture=false; }
  managementFixture='adviser';
  try {
    await navigate('admin_advisers.php',375,true,'populated');
    await evaluate('document.getElementById("addRecord").click()');
    check(await evaluate("document.getElementById('department').tagName==='SELECT' && document.getElementById('department').options.length===8 && [...document.getElementById('department').options].some(o=>o.value==='Nursing')"),'New adviser uses authoritative catalog dropdown');
    await evaluate('document.getElementById("cancelRecord").click();document.querySelector("#recordRows [title=Edit]").click()');
    check(await evaluate("document.getElementById('department').value==='AMT' && document.getElementById('department').selectedOptions[0].textContent.includes('legacy')"),'Legacy adviser department remains visible and selected');
  } finally {managementFixture='student';}
  await navigate('dashboard.php',1280,false,'populated');
  check(requests.findLast(r=>r.file==='reports_api.php'&&r.action==='list').query.aiOnly==='1','Recent AI Reports requests AI-only bounded results');
  await navigate('research_adviser.php',375,true,'populated','adviser');
  check(requests.findLast(r=>r.file==='students_api.php'&&r.action==='list').query.preview==='1','Adviser count loads only preview one');
  legacyAIFixture=true;
  try {
    for(const [file,selector] of [['admin_ai.php','#aiHistory'],['reports.php','#reportTableBody'],['dashboard.php','#recentAiReportList']]) {
      await navigate(file,375,true,'populated');
      await waitFor(`document.querySelector(${JSON.stringify(selector)}).textContent.includes('Regenerate with Student IDs')`);
      check(await evaluateFunction(selector=>!document.querySelector(selector).querySelector('a[href*="reports_api.php?action=file"]'),selector),file+' earlier AI report exposes regeneration instead of name-containing PDF');
    }
  } finally {legacyAIFixture=false;}
  check(await evaluateFunction(attack=>{
    const host=document.createElement('div');host.id='isolatedFilter';document.body.append(host);
    const filter=PrismUI.recordFilters(host,[['group','Groups']]);filter.update({group:[{value:attack,label:attack}]});
    const safe=!host.querySelector('img,script')&&host.querySelector('option:last-child').textContent===attack;host.remove();return safe;
  },attack),'Database filter labels remain literal text without HTML injection');
}

async function checkReadiness() {
  readinessFixture=true;
  const cases=[['admin_students.php','student','admin','#recordRows','#recordCount','#recordSearch'],['admin_advisers.php','adviser','admin','#recordRows','#recordCount','#recordSearch'],['documents.php','student','admin','#documentsTableBody','#documentCount','#documentSearch'],['ierbprog.php','student','admin','#ierbTableBody','#ierbRecordCount','#ierbSearch'],['reports.php','student','admin','#reportTableBody','#reportCount',null],['admin_ai.php','student','admin','#aiHistory','#aiHistoryCount',null],['research_adviser.php','student','adviser','#adviserQueue',null,'#adviserQueueSearch']];
  for(const [file,management,viewer,rows,count,search] of cases){
    managementFixture=management;await navigate(file,375,true,'populated',viewer);
    await waitFor(`document.querySelectorAll(${JSON.stringify(rows+(file==='research_adviser.php'?' tbody > tr':' > *'))}).length===10`);
    check(await evaluateFunction((count,rows)=>{const label=count?document.querySelector(count):document.querySelector(rows).previousElementSibling;return label.textContent.includes('Showing 1\u201310 of 113 records');},count,rows),file+' truthful page count');
    const pager=rows+' ~ .prism-pagination';
    // Tables have their pager after the scroll wrapper rather than after tbody.
    await evaluateFunction(rows=>{const list=document.querySelector(rows);const host=document.querySelector('.prism-pagination');host.querySelector('[aria-label="Page 2"]').click();},rows);
    await waitFor(`document.querySelectorAll(${JSON.stringify(rows+(file==='research_adviser.php'?' tbody > tr':' > *'))}).length===10 && [...document.querySelectorAll('.prism-pagination [aria-current=page]')].some(b=>b.textContent==='2')`);
    const request=requests.findLast(r=>r.action==='list'&&r.query.page==='2');check(!!request,file+' server page 2 request');
    await evaluateFunction(rows=>{const list=document.querySelector(rows),host=document.querySelector('.prism-pagination');host.querySelector('[aria-label="Page 12"]').click();},rows);
    await waitFor(`document.querySelectorAll(${JSON.stringify(rows+(file==='research_adviser.php'?' tbody > tr':' > *'))}).length===3`);
    check(await evaluateFunction(rows=>{const list=document.querySelector(rows),host=document.querySelector('.prism-pagination');return host.lastElementChild.disabled&&host.textContent.includes('\u2026');},rows),file+' last page / compact controls');
    if(search){await evaluateFunction(selector=>{const input=document.querySelector(selector);input.value='No matching record';input.dispatchEvent(new Event('input'));},search);await waitFor(`(document.querySelector(${JSON.stringify(count||rows)}).textContent.includes('0') || document.querySelector(${JSON.stringify(rows)}).textContent.includes('No matching')) && ![...document.querySelectorAll('.prism-pagination [aria-current=page]')].some(b=>b.textContent==='12')`);check(requests.findLast(r=>r.action==='list'&&(file!=='research_adviser.php'||r.file==='documents_api.php')).query.page==='1',file+' search resets page');await evaluateFunction(()=>[...document.querySelectorAll('button')].find(b=>b.textContent==='Clear Filters').click());await waitFor(`document.querySelectorAll(${JSON.stringify(rows+(file==='research_adviser.php'?' tbody > tr':' > *'))}).length===10`);check(requests.findLast(r=>r.action==='list'&&(file!=='research_adviser.php'||r.file==='documents_api.php')).query.q==='',file+' reset immediately reloads');}
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),file+' mobile layout');
  }
  managementFixture='student';await navigate('admin_students.php',1280,false,'populated','adviser');
  await evaluate("document.querySelector('#recordRows button[title=Edit]').click()");
  check(await evaluate("['recordName','accountId','recordEmail'].every(id=>document.getElementById(id)?.readOnly)"),'Adviser edit identity read-only');
  check(await evaluate("document.getElementById('stage').disabled && document.getElementById('recordStatus').disabled && document.getElementById('protocolCode').readOnly && document.getElementById('isPrincipal').disabled"),'Adviser administrative fields read-only');
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return !e.defaultPrevented})()"),'Untouched edit has no unload warning');
  await evaluate("document.getElementById('research').value='Changed';document.getElementById('research').dispatchEvent(new Event('input',{bubbles:true}))");
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented})()"),'Edited form warns');
  apiDelay=150;const before=requests.filter(r=>r.file==='students_api.php'&&r.action==='save').length;
  await evaluate("document.getElementById('recordForm').requestSubmit();document.getElementById('recordForm').requestSubmit()");
  check(await evaluate("document.querySelector('#recordForm [type=submit]').disabled&&document.querySelector('#recordForm [type=submit]').textContent==='Saving...'"),'Contextual save feedback');
  await waitFor('document.getElementById("recordModal").style.display==="none"');apiDelay=0;
  check(requests.filter(r=>r.file==='students_api.php'&&r.action==='save').length===before+1,'Repeated save emits one request');
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return !e.defaultPrevented})()"),'Saved form is clean');
  await evaluate("document.getElementById('addRecord').click()");
  check(await evaluate("['recordName','accountId','recordEmail'].every(id=>!document.getElementById(id)?.readOnly)"),'Adviser creation identity editable');
  await navigate('student.php',1280,false,'student-pending','student');
  await evaluate("location.hash='#progress'");
  await waitFor('document.querySelector(".portal-page.active").dataset.section==="progress"');
  check(await evaluate("document.getElementById('progressHistory').children.length===10"),'Progress history page has ten records');
  await evaluate("document.getElementById('progressHistory').nextElementSibling.querySelector('[aria-label=\"Page 2\"]').click()");
  await waitFor('document.getElementById("progressHistory").textContent.includes("History 10")');
  check(requests.findLast(r=>r.action==='history')?.query.page==='2','Progress history pages on the server');
  await evaluate("location.hash='#documents'");
  await waitFor('document.querySelector(".portal-page.active").dataset.section==="documents"');
  await evaluate("document.getElementById('documentRows').closest('table').parentElement.nextElementSibling.querySelector('[aria-label=\"Page 3\"]').click()");
  await waitFor('!!document.querySelector("#documentRows [data-formal-submit]")');
  check(await evaluate("document.querySelector('#documentRows [data-formal-submit]').dataset.formalSubmit==='d22'"),'Older approved document retains formal submission access');
  await evaluate("document.querySelector('#documentRows [data-formal-submit]').click()");
  await waitFor('!!document.querySelector(".prism-dialog [data-act=ok]")');
  await evaluate("document.querySelector('.prism-dialog [data-act=ok]').click()");
  await waitFor('!document.querySelector("#documentRows [data-formal-submit]")');
  check(requests.findLast(r=>r.action==='submit_to_rpms')?.query.id==='d22','Older document submits through original workflow API');
  await navigate('admin_notifications.php',1280,false,'populated','admin');
  await evaluate("document.getElementById('noticeMessage').value='Unsaved notification';document.getElementById('noticeMessage').dispatchEvent(new Event('input',{bubbles:true}))");
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented})()"),'Unsaved notification warns');
  await evaluate("document.getElementById('cancelNotification').click()");
  check(await evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return !e.defaultPrevented})()"),'Cleared notification form is clean');
  readinessFixture=false;
}

async function checkLifecyclePolish() {
  const fullName='Progress and Research Information System for Monitoring';
  const dest=path.join(root,'tests','lifecycle-results');fs.mkdirSync(dest,{recursive:true});
  const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812],[320,568]];
  const samples=[];
  for(const [viewer,file] of [['admin','dashboard.php'],['adviser','research_adviser.php'],['student','student.php']]) {
    for(const [width,height] of matrix) for(const dark of [false,true]) {
      await navigate(file,width,dark,'populated',viewer);
      await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
      await measure(file,width,dark,' lifecycle '+viewer);
      const sample=await evaluateFunction(()=>{
        const caption=document.querySelector('.prism-navigation-caption')||document.querySelector('.portal-brand'),nav=document.querySelector('.portal-navbar'),main=document.querySelector('main');
        const r=caption.getBoundingClientRect();
        return {caption:caption.classList.contains('portal-brand')?caption.title:caption.textContent,logoOnly:caption.classList.contains('portal-brand'),wordBreak:getComputedStyle(caption).wordBreak,overflowWrap:getComputedStyle(caption).overflowWrap,
          fits:!r.width||(r.left>=0&&r.right<=innerWidth),clears:!nav||nav.getBoundingClientRect().bottom<=main.getBoundingClientRect().top,
          footer:document.querySelector('.ceu-footer').textContent,
          portal:[...document.querySelectorAll('[data-prism-resources] a')].filter(a=>a.href==='https://ceu-ierb.wixsite.com/ierb').map(a=>({target:a.target,rel:a.rel}))};
      });
      samples.push({viewer,width,height,dark,...sample});
      check(sample.caption===fullName&&sample.fits&&(sample.logoOnly||(sample.wordBreak==='normal'&&sample.overflowWrap==='normal')),'Full PRISM name remains visible or accessible '+viewer+' '+width+' '+dark,JSON.stringify(sample));
      check(sample.clears,'Header clears content '+viewer+' '+width+' '+dark);
      check(sample.footer.includes('Contact Us')&&!sample.footer.includes('Contact CEU Malolos'),'Footer contact heading '+viewer+' '+width);
      if(viewer!=='admin')check(sample.portal.length===1&&sample.portal[0].target==='_blank'&&sample.portal[0].rel.includes('noopener')&&sample.portal[0].rel.includes('noreferrer'),'Canonical IERB portal '+viewer+' '+width);
      if(viewer==='admin') {
        await evaluate('document.getElementById("prismSidebarToggle").click()');await measure(file,width,dark,' lifecycle sidebar toggle');
        check(await evaluate('document.querySelector(".prism-sidebar-brand").title.includes("Progress and Research Information System for Monitoring")'),'Collapsed brand preserves full accessible name');
      }
      if(viewer==='student') {
        check(await evaluateFunction(()=>['View documents','Open calendar'].every(label=>{const button=document.querySelector('button[aria-label="'+label+'"]');return button&&button.title===label&&!button.textContent.trim();})),'Accessible dashboard icons '+width);
        const date=await evaluateFunction(()=>{const day=document.querySelector('#dashboardCalendarGrid .mini-day.active-day');const r=day.getBoundingClientRect();const range=document.createRange();range.selectNodeContents(day);const n=range.getBoundingClientRect();return {w:r.width,h:r.height,dx:Math.abs((r.left+r.right-n.left-n.right)/2),dy:Math.abs((r.top+r.bottom-n.top-n.bottom)/2)};});
        check(date.w===26&&date.h===26&&date.dx<1&&date.dy<3,'Compact centered current date '+width+' '+dark,JSON.stringify(date));
        check(await evaluateFunction(()=>!document.getElementById('studentReviewRemarks').hidden&&document.getElementById('studentReviewRemarks').textContent.includes('<img src=x onerror=')&&!document.getElementById('studentCurrentDocument').hidden),'Real review remarks and submission name remain visible '+width+' '+dark);
        await evaluateFunction(()=>document.querySelector('button[aria-label="Open calendar"]').click());
        check(await evaluateFunction(()=>document.getElementById('pageTitle').textContent==='Calendar'&&document.getElementById('pageSubtitle').hidden&&!document.querySelector('[data-section="calendar"]').textContent.includes('Research Calendar')&&!!document.getElementById('todayButton')),'Calendar heading cleanup '+width);
      }
      if(width===375&&!dark) {
        await evaluate('window.scrollTo(0,0)');const png=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(dest,'ui-'+viewer+'.png'),Buffer.from(png.data,'base64'));
      }
    }
  }
  for(const [width,height] of matrix) for(const dark of [false,true]) for(let rotation=0;rotation<6;rotation++) {
    const values=[0,1,5,10,15,16];lifecycleStageValues=values.slice(rotation).concat(values.slice(0,rotation));
    await navigate('ierbprog.php',width,dark,'populated','adviser');
    const result=await evaluateFunction(()=>{
      const chart=document.getElementById('stageChart'),r=chart.getBoundingClientRect();
      return [...chart.querySelectorAll('.stage-bar span')].map(e=>{const b=e.getBoundingClientRect();return {count:Number(e.textContent),size:parseFloat(getComputedStyle(e).fontSize),clear:b.top>=r.top+1&&b.bottom<=r.bottom,top:b.top-r.top};});
    });
    check(result.length===6&&result.every(e=>e.clear&&e.size===12.5)&&result.map(e=>e.count).join(',')===lifecycleStageValues.join(','),'Every stage count fully visible '+width+' '+dark+' rotation '+rotation,JSON.stringify(result));
    await measure('ierbprog.php',width,dark,' lifecycle counts');
  }
  lifecycleStageValues=null;
  await navigate('student.php',375,false,'empty','student');
  check(await evaluateFunction(()=>document.getElementById('dashboardSubmissionValue').textContent==='0 documents'&&document.getElementById('studentCurrentDocument').hidden&&document.getElementById('dashboardSubmissionStatus').hidden&&document.getElementById('studentReviewRemarks').hidden&&document.getElementById('studentReviewState').textContent==='No review yet'),'Empty cards retain primary states and hide redundant lines');
  await evaluateFunction(()=>{const key='prismReminders:'+document.body.dataset.portalKey;const d=new Date(),date=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');localStorage.setItem(key,JSON.stringify({[date]:[{id:'fixture',title:'Preserved real reminder',time:'23:59',notes:'Fixture'}]}));});
  await navigate('student.php',375,true,'empty','student');
  check(await evaluateFunction(()=>!!document.querySelector('#dashboardCalendarGrid .active-day.has-event')&&document.getElementById('dashboardDeadlineValue').textContent.includes('Preserved real reminder')),'Current-day event dot and browser-local reminder preserved');
  await navigate('account.php',320,true,'populated','admin');await measure('account.php',320,true,' lifecycle forms');
  check(await evaluateFunction(()=>!!document.getElementById('adminArchiveForm')&&!!document.querySelector('#adminDeleteForm input[name=confirmation]')),'Admin separate archive and typed-delete forms');
  await navigate('account.php',320,true,'populated','adviser');check(await evaluate('!document.getElementById("lifecycle")'),'Adviser has no Admin lifecycle controls');
  managementFixture='student';academicRecord={id:100,studentId:'S100',name:'Archived test',email:'test@example.invalid',archivedAt:'2026-10-01',stage:'Stage 1',status:'On Track'};
  await navigate('admin_students.php',320,true,'populated','admin');
  await evaluateFunction(()=>document.querySelector('button[title="Permanently Delete Student"]').click());await measure('admin_students.php',320,true,' destructive dialog');
  check(await evaluateFunction(()=>document.getElementById('permanentDeleteDialog').open&&document.querySelector('#permanentDeleteForm input[name=currentPassword]').required&&document.querySelector('#permanentDeleteForm input[name=confirmation]').required),'Student deletion uses explicit password and typed confirmation dialog');
  await evaluate('document.getElementById("cancelPermanentDelete").click()');academicRecord=null;
  check(errors.length===0,'No lifecycle UI exceptions',errors.join(' | '));
  fs.writeFileSync(path.join(dest,'ui-results.json'),JSON.stringify({checks,failures,errors,samples},null,2));
}

async function checkStudentLifecycle() {
  const dest=path.join(root,'tests','lifecycle-results');fs.mkdirSync(dest,{recursive:true});
  const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
  const record=(id,name,archived=false)=>({id,studentId:'S'+id,name,email:'student'+id+'@example.invalid',stage:'Stage 1',status:'On Track',archivedAt:archived?'2026-10-01':null});
  lifecycleRecords=[record(1,'Active normal Student'),record(2,'Active unused test Student'),record(3,'Archived unused test Student',true),record(4,'Archived protected Student',true),record(5,'Long Student Name '.repeat(10),true),{...record(6,'Long ID Student',true),studentId:'S'.repeat(100)}];
  managementFixture='student';lifecycleGateAvailable=true;
  const openRow=async index=>evaluate(`document.querySelectorAll('#recordRows tr')[${index}].querySelector('.lifecycle-danger').click()`);
  const cancel=async()=>evaluate('document.getElementById("cancelPermanentDelete").click()');
  for(const [width,height] of matrix) for(const dark of [false,true]) {
    const label=width+'x'+height+' '+(dark?'dark':'light');
    await navigate('admin_students.php',width,dark,'populated','admin');
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    await waitFor('document.getElementById("permanentDeleteAvailability").textContent.includes("is valid")');
    await measure('admin_students.php',width,dark,' Student lifecycle');
    const actions=await evaluateFunction(()=>[...document.querySelectorAll('#recordRows tr')].map(row=>[...row.querySelectorAll('.lifecycle-action')].map(b=>{
      const r=b.getBoundingClientRect(),s=getComputedStyle(b);return {w:r.width,h:r.height,x:r.x,y:r.y,title:b.title,label:b.getAttribute('aria-label'),disabled:b.disabled||b.getAttribute('aria-disabled')==='true',icon:!!b.querySelector('i[aria-hidden=true]'),fg:s.color,bg:s.backgroundColor};
    })));
    check(actions.length===6&&actions.every(row=>row.length===3&&row.every(b=>b.w===40&&b.h===40&&b.title&&b.label&&b.icon)),label+': equal labelled actions in every row',JSON.stringify(actions));
    check(actions.every(row=>!row[0].disabled&&row.every(b=>b.x===row[0].x)&&Math.abs(row[1].y-row[0].y-46)<1&&Math.abs(row[2].y-row[1].y-46)<1),label+': aligned actions and visible Edit');
    check(actions.slice(0,2).every(row=>!row[1].disabled&&row[2].disabled&&row[2].title.includes('Archive Student before'))&&actions.slice(2).every(row=>row[1].disabled&&!row[2].disabled),label+': archived-first states');
    const lum=rgb=>rgb.match(/[\d.]+/g).slice(0,3).map(Number).map(x=>x/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((sum,x,i)=>sum+x*[.2126,.7152,.0722][i],0);
    const contrast=(a,b)=>(Math.max(lum(a),lum(b))+.05)/(Math.min(lum(a),lum(b))+.05);
    check(actions.every(row=>contrast(row[2].fg,row[2].bg)>=4.5),label+': enabled and disabled trash contrast');
    const hoverPoint=await evaluateFunction(()=>{const b=document.querySelectorAll('#recordRows tr')[2].querySelector('.lifecycle-danger');b.scrollIntoView({behavior:'instant',block:'center',inline:'nearest'});const r=b.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};});
    await command('Input.dispatchMouseEvent',{type:'mouseMoved',...hoverPoint});
    await waitFor('getComputedStyle(document.querySelectorAll("#recordRows tr")[2].querySelector(".lifecycle-danger")).backgroundColor==="rgb(163, 29, 53)"');
    const hover=await evaluateFunction(()=>{const s=getComputedStyle(document.querySelectorAll('#recordRows tr')[2].querySelector('.lifecycle-danger'));return {fg:s.color,bg:s.backgroundColor};});
    check(hover.bg==='rgb(163, 29, 53)'&&contrast(hover.fg,hover.bg)>=4.5,label+': red hover state retains contrast',JSON.stringify(hover));
    await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});
    await evaluate('document.querySelectorAll("#recordRows tr")[2].querySelector(".lifecycle-action").focus()');
    for(const type of ['keyDown','keyUp'])await command('Input.dispatchKeyEvent',{type,key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    check(await evaluateFunction(()=>document.activeElement.matches('.lifecycle-danger')&&getComputedStyle(document.activeElement).outlineStyle==='solid'),label+': destructive action keyboard focus is visible');
    await openRow(0);check(await evaluate('!document.getElementById("permanentDeleteDialog").open'),label+': active Student cannot open deletion');
    await openRow(2);check(await evaluate('document.getElementById("permanentDeleteDialog").open'),label+': archived Student opens confirmation');
    const layout=await evaluateFunction(()=>{
      const d=document.getElementById('permanentDeleteDialog'),r=d.getBoundingClientRect(),f=document.getElementById('permanentDeleteForm');
      return {w:r.width,l:r.left,r:r.right,t:r.top,b:r.bottom,scroll:getComputedStyle(d).overflowY,
        fields:[...f.querySelectorAll('.lifecycle-form-group')].map(g=>{const l=g.querySelector('label').getBoundingClientRect(),i=g.querySelector('input').getBoundingClientRect();return {gap:i.top-l.bottom,w:i.width,l:i.left,r:i.right};}),
        checks:[...f.querySelectorAll('.lifecycle-check')].map(e=>({display:getComputedStyle(e).display,w:e.querySelector('input').getBoundingClientRect().width})),
        toggle:f.querySelector('[data-lifecycle-password]').type,bg:getComputedStyle(f.querySelector('[type=submit]')).backgroundColor};
    });
    check(layout.l>=0&&layout.r<=width&&layout.t>=0&&layout.b<=height+1&&layout.w<=760&&['auto','scroll'].includes(layout.scroll)&&(width<1024||layout.w>=700),label+': bounded fluid scrollable modal',JSON.stringify(layout));
    check(layout.fields.every(f=>f.gap>=8&&f.l>=layout.l&&f.r<=layout.r)&&Math.abs(layout.fields[0].w-layout.fields[1].w)<1&&layout.checks.every(c=>c.display==='flex'&&c.w===18),label+': stacked fields and aligned checks',JSON.stringify(layout));
    check(layout.toggle==='button'&&layout.bg==='rgb(168, 37, 64)',label+': non-submitting eye and red destructive button');
    const before=requests.filter(r=>r.file==='account_lifecycle_api.php'&&r.method==='POST').length;
    await evaluateFunction(()=>{const f=document.getElementById('permanentDeleteForm');f.elements.currentPassword.value='Synthetic browser passphrase';f.querySelector('[data-lifecycle-password]').click();});
    check(await evaluateFunction(()=>{const f=document.getElementById('permanentDeleteForm'),b=f.querySelector('[data-lifecycle-password]');return f.elements.currentPassword.type==='text'&&f.elements.currentPassword.value==='Synthetic browser passphrase'&&b.getAttribute('aria-label')==='Hide password'&&b.getAttribute('aria-pressed')==='true';}),label+': eye reveals without changing value');
    await evaluate('document.querySelector("#permanentDeleteForm [data-lifecycle-password]").click()');
    check(await evaluateFunction(()=>{const f=document.getElementById('permanentDeleteForm'),b=f.querySelector('[data-lifecycle-password]');return f.elements.currentPassword.type==='password'&&b.getAttribute('aria-label')==='Show password'&&b.getAttribute('aria-pressed')==='false';}),label+': eye hides reliably');
    check(requests.filter(r=>r.file==='account_lifecycle_api.php'&&r.method==='POST').length===before,label+': eye never submits');
    await evaluate('document.getElementById("permanentDeletePassword").focus()');
    for(const type of ['keyDown','keyUp'])await command('Input.dispatchKeyEvent',{type,key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    check(await evaluateFunction(()=>document.activeElement.matches('[data-lifecycle-password]')&&getComputedStyle(document.activeElement).outlineStyle==='solid'),label+': eye keyboard focus');
    for(const type of ['keyDown','keyUp'])await command('Input.dispatchKeyEvent',{type,key:' ',code:'Space',windowsVirtualKeyCode:32});
    check(await evaluate('document.getElementById("permanentDeletePassword").type==="text"'),label+': eye keyboard activation');
    await cancel();
    check(await evaluateFunction(()=>{const f=document.getElementById('permanentDeleteForm');return !document.getElementById('permanentDeleteDialog').open&&f.elements.currentPassword.value===''&&f.elements.currentPassword.type==='password'&&document.activeElement.matches('.lifecycle-danger');}),label+': Cancel clears password and restores focus');
    for(const row of [5,4]) {
      await openRow(row);
      check(await evaluateFunction(()=>{const e=document.getElementById('permanentDeleteIdentity');return e.scrollWidth<=e.clientWidth+1;}),label+': long identity wraps '+row);
      if(row===4&&!dark){const png=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(dest,'student-delete-'+width+'x'+height+'.png'),Buffer.from(png.data,'base64'));}
      await cancel();
    }
  }
  lifecycleDeleteFailure=409;await openRow(3);
  await evaluateFunction(()=>{const f=document.getElementById('permanentDeleteForm');f.elements.currentPassword.value='Synthetic browser passphrase';f.elements.confirmation.value='S4';f.elements.testRecord.checked=true;f.elements.confirmed.checked=true;f.querySelector('[type=submit]').click();});
  await waitFor('document.getElementById("permanentDeleteResult").textContent.includes("Protected Student history")');
  check(await evaluate('document.getElementById("permanentDeleteDialog").open&&document.getElementById("permanentDeletePassword").value===""'),'Backend protected-history 409 remains visible and clears password');
  checkPayload('account_lifecycle_api.php',null,{accountType:'student',action:'permanent_delete',targetId:4,currentPassword:'Synthetic browser passphrase',confirmation:'S4',confirmed:true,testRecord:true},'Exact Student targeting/password/ID/acknowledgements');
  await cancel();lifecycleDeleteFailure=0;await openRow(2);
  const before=requests.filter(r=>r.file==='account_lifecycle_api.php'&&r.method==='POST').length;
  await evaluateFunction(()=>{const f=document.getElementById('permanentDeleteForm');f.elements.currentPassword.value='Synthetic browser passphrase';f.elements.confirmation.value='S3';f.querySelector('[type=submit]').click();});
  check(requests.filter(r=>r.file==='account_lifecycle_api.php'&&r.method==='POST').length===before,'Unchecked destructive acknowledgements prevent submission');
  await evaluateFunction(()=>{const f=document.getElementById('permanentDeleteForm');f.elements.testRecord.checked=true;f.elements.confirmed.checked=true;f.querySelector('[type=submit]').click();});
  await waitFor('!document.getElementById("permanentDeleteDialog").open');
  checkPayload('account_lifecycle_api.php',null,{accountType:'student',action:'permanent_delete',targetId:3,currentPassword:'Synthetic browser passphrase',confirmation:'S3',confirmed:true,testRecord:true},'Successful destructive confirmation still submits');
  lifecycleGateAvailable=false;await navigate('admin_students.php',1366,false,'populated','admin');
  await waitFor('document.getElementById("permanentDeleteAvailability").textContent.includes("expired")');
  check(await evaluate('[...document.querySelectorAll("#recordRows .lifecycle-danger")].every(e=>e.getAttribute("aria-disabled")==="true")'),'Invalid evidence disables every delete control');
  await openRow(2);check(await evaluate('!document.getElementById("permanentDeleteDialog").open'),'Invalid gate cannot open deletion');
  lifecycleGateAvailable=true;managementFixture='adviser';lifecycleRecords=[{id:200,name:'Archived unused Adviser',employeeId:'E200',status:'Inactive',email:'adviser@example.invalid',groups:[]}];
  await navigate('admin_advisers.php',1024,false,'populated','admin');
  await waitFor('document.getElementById("permanentDeleteAvailability").textContent.includes("is valid")');await openRow(0);
  check(await evaluate('document.getElementById("permanentDeleteDialog").open&&document.getElementById("permanentDeleteConfirmationLabel").textContent.includes("Employee ID")'),'Adviser shares modal and keeps Employee ID confirmation');await cancel();
  await navigate('account.php',390,false,'populated','admin');
  await evaluate('document.querySelector(".lifecycle-destructive").open=true;document.querySelector("#adminDeleteForm [data-lifecycle-password]").click()');
  check(await evaluate('document.getElementById("adminDeletePassword").type==="text"&&document.getElementById("lifecycle").dataset.userId==="1"'),'Admin self-only form shares password eye');
  await navigate('admin_students.php',390,false,'populated','adviser');
  check(await evaluate('!document.getElementById("permanentDeleteDialog")&&!document.querySelector(".lifecycle-danger")'),'Adviser viewing Students has no Admin lifecycle actions');
  lifecycleRecords=null;managementFixture='student';
  check(errors.length===0,'No Student lifecycle browser exceptions',errors.join(' | '));
  fs.writeFileSync(path.join(dest,'student-delete-ui-results.json'),JSON.stringify({checks,failures,errors,viewports:matrix},null,2));
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
    } else if (message.method === 'Page.javascriptDialogOpening' && message.params.type === 'beforeunload') {
      command('Page.handleJavaScriptDialog',{accept:true}).catch(e=>errors.push(String(e)));
    } else if (message.method === 'Runtime.exceptionThrown') {
      errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    } else if (message.method === 'Log.entryAdded' && message.params.entry.source === 'security'
        && /content security policy|\bcsp\b|violat/i.test(message.params.entry.text)) {
      errors.push('CSP: ' + message.params.entry.text);
    } else if (message.method === 'Fetch.requestPaused') {
      const request = message.params;
        const visualAsset = process.argv.includes('--visual-only') && /^https:\/\/(fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//.test(request.request.url);
        const allowed = visualAsset || request.request.url.startsWith(origin + '/') || /^(data:|about:)/.test(request.request.url);
      void command(allowed ? 'Fetch.continueRequest' : 'Fetch.fulfillRequest', allowed ? { requestId: request.requestId } : { requestId: request.requestId, responseCode: 200, body: '' }).catch(e => {
        // Navigation can cancel a paused request before its interception command reaches Chrome.
        // Ignore only that exact protocol cancellation; retain application, CSP and other protocol errors.
        if (e.message !== 'Invalid InterceptionId.') errors.push(String(e));
      });
    }
  });
  const target = await command('Target.createTarget', { url: 'about:blank' }, true);
  sessionId = (await command('Target.attachToTarget', { targetId: target.targetId, flatten: true }, true)).sessionId;
  await command('Page.enable');
  await command('Runtime.enable');
  await command('Log.enable');
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  await command('Browser.setDownloadBehavior', { behavior: 'deny' }, true);
  if (process.argv.includes('--retention-only')) { await checkRetentionRedesign();console.log(checks+' retention browser checks; '+failures.length+' failures.');for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return; }
  if (process.argv.includes('--student-lifecycle-ui-only')) { await checkStudentLifecycle(); console.log(checks+' Student lifecycle UI checks; '+failures.length+' failures.'); for(const failure of failures)console.error('FAIL '+failure); if(failures.length)process.exitCode=1;return; }
  if (process.argv.includes('--lifecycle-ui-only')) { await checkLifecyclePolish(); await checkStudentLifecycle(); console.log(checks+' lifecycle UI checks; '+failures.length+' failures.'); for(const failure of failures)console.error('FAIL '+failure); if(failures.length)process.exitCode=1;return; }
  if (process.argv.includes('--student-protocol-only')) {
    await checkStudentDashboardProtocol();await checkStudentPortal();check(errors.length===0,'No Student protocol browser exceptions',errors.join(' | '));
    console.log(`${checks} Student protocol/portal UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--hardening-only')) {
    for (const [viewer,file] of [['admin','dashboard.php'],['adviser','research_adviser.php'],['student','student.php']]) {
      for (const width of [1440,1280,1024,768,390,375,320]) for (const dark of [false,true]) {
        await navigate(file,width,dark,'populated',viewer);
        await measure(pageWrappers[file]||file,width,dark,' final polish '+viewer);
        await evaluate('document.querySelector(".ceu-footer").scrollIntoView({block:"center"})');
        await waitFor('[...document.querySelectorAll(".ceu-footer-accreditation-art img")].every(i=>i.complete&&i.naturalWidth>0)');
        check(await evaluate(`[...document.querySelectorAll('.ceu-footer-socials a')].every(a=>!a.textContent.trim()&&a.getAttribute('aria-label'))`),viewer+width+': accessible icon-only social links');
        check(await evaluate(`[...document.querySelectorAll('.ceu-footer a')].every(a=>getComputedStyle(a).textDecorationLine==='none')`),viewer+width+': footer links have no underlines');
        if(width>=1280) check(await evaluate(`(()=>{const r=[...document.querySelectorAll('.ceu-footer-accreditation-art')].map(e=>e.getBoundingClientRect());return Math.abs(r[0].top-r[1].top)<2;})()`),viewer+width+': artwork forms one compact desktop row');
        if(viewer==='admin') {
          await evaluate(`document.getElementById('prismSidebarToggle').scrollIntoView();`);
          check(await evaluate(`(()=>{const s=document.querySelector('.prism-sidebar'),collapsed=s.classList.contains('is-collapsed');return (getComputedStyle(s.querySelector('.sidebar-brand-logo')).display==='none')===collapsed&&(getComputedStyle(s.querySelector('.sidebar-brand-icon')).display!=='none')===collapsed;})()`),'Exactly one correct sidebar logo');
          if(width>=1024) {
            await evaluate(`(()=>{const t=document.getElementById('prismSidebarToggle');if(t.getAttribute('aria-expanded')!=='true')t.click();const g=document.getElementById('prismNavResearchToggle');if(g.getAttribute('aria-expanded')!=='true')g.click();for(let i=0;i<8;i++)t.click();})()`);
            await waitFor(`document.querySelector('.prism-sidebar').getBoundingClientRect().right<=document.querySelector('.main-content').getBoundingClientRect().left+1`);
            check(await evaluate(`document.getElementById('prismSidebarToggle').getAttribute('aria-expanded')==='true'&&document.getElementById('prismNavResearchToggle').getAttribute('aria-expanded')==='true'&&!document.getElementById('prismNavResearch').hidden`),'Rapid collapse preserves submenu and canonical aria state');
            check(await evaluate(`parseFloat(getComputedStyle(document.querySelector('.prism-nav-group-toggle')).fontSize)>parseFloat(getComputedStyle(document.querySelector('.prism-nav-submenu a')).fontSize)`),'Admin heading remains larger than submenu',await evaluate(`JSON.stringify([getComputedStyle(document.querySelector('.prism-nav-group-toggle')).fontSize,getComputedStyle(document.querySelector('.prism-nav-submenu a')).fontSize])`));
            await evaluate(`document.getElementById('prismSidebarToggle').click()`);
            await navigate(file,width,dark,'populated',viewer);
            check(await evaluate(`document.querySelector('.prism-sidebar').classList.contains('is-collapsed')&&getComputedStyle(document.querySelector('.sidebar-brand-logo')).display==='none'`),'Reload restores collapsed icon state');
          }
        } else if(viewer==='student') {
          await evaluate(`document.querySelector('#portalNav [data-page="documents"]').click()`);
          check(await evaluate(`!!document.querySelector('[data-section="documents"] [data-go="submit"]')`),'My Documents includes existing upload action');
          await evaluate(`document.querySelector('[data-section="documents"] [data-go="submit"]').click()`);
          check(await evaluate(`document.querySelector('[data-section="submit"]').getBoundingClientRect().height>0`),'Upload action opens existing submission form');
        } else {
          await evaluate(`location.hash='prismResourcesTitle'`);
          await waitFor(`document.querySelector('.portal-nav-links a[href*="#prismResourcesTitle"]').getAttribute('aria-current')==='location'`);
          check(await evaluate(`document.querySelectorAll('.portal-nav-links [aria-current="page"]').length===1`),'Resources hash retains one authenticated current page');
        }
      }
    }
    managementFixture='student';
    academicRecord={id:1,studentId:'S1',name:'Synthetic Student',email:'student@example.test',group:'AMT-BSIT-Y2-2627-G01',course:'BS in Information Technology',academicUnitKey:'amt',programKey:'bsit',yearLevel:'2nd Year',academicYear:'2026-2027',adviserId:999,adviserName:'Retired Fixture',stage:'Stage 1',status:'On Track'};
    await navigate('admin_students.php',1280,false,'populated','admin');
    await waitFor('!!document.querySelector("#recordRows button[title=Edit]")');
    await evaluate('document.querySelector("#recordRows button[title=Edit]").click()');
    check(await evaluateFunction(()=>document.getElementById('adviser').value==='999'&&[...document.getElementById('adviser').options].some(option=>option.value==='999'&&option.textContent.includes('retained assignment'))),'Editing a Student preserves an existing inactive Adviser assignment');
    academicRecord=null;
    console.log(checks+' final polish browser checks; '+failures.length+' failures.');
    if(failures.length) throw new Error(failures.join('\n'));
    return;
  }
  if (process.argv.includes('--shell-polish-only')) {
    await checkShellPolish();check(errors.length===0,'No shell polish browser exceptions',errors.join(' | '));
    console.log(`${checks} shell polish UI checks; ${failures.length} failures.`);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--release-panels-only')) {
    await checkReleasePanels();check(errors.length===0,'No release panel browser exceptions',errors.join(' | '));
    const result={checks,failures,errors};fs.writeFileSync(path.join(root,'tests','release-candidate-results','panels-ui.json'),JSON.stringify(result,null,2));
    console.log(`${checks} release panel UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--restyle-only') || process.argv.includes('--visual-only')) {
    const visualOnly=process.argv.includes('--visual-only');
    const viewers = {
      admin:['dashboard.php','admin_students.php','admin_advisers.php','documents.php','ierbprog.php','admin_ai.php','reports.php','admin_notifications.php','calendar.php','account.php'],
      adviser:['research_adviser.php','admin_students.php','documents.php','ierbprog.php','calendar.php','admin_notifications.php','account.php'],
      student:['student.php']
    };
    const dest=path.join(root,'tests/ui-restyle-results');
    fs.mkdirSync(dest,{recursive:true});
    for(const [viewer,files] of Object.entries(viewers)) for(const file of files.filter(file=>!visualOnly || ['dashboard.php','research_adviser.php','student.php'].includes(file))) {
      managementFixture=file==='admin_advisers.php'?'adviser':'student';
      for(const width of (visualOnly?[1440,375,320]:[1440,1280,1024,768,375,320])) for(const dark of [false,true]) {
        await navigate(file,width,dark,'populated',viewer);
        if(visualOnly) {
          await evaluate('document.fonts.ready');
          check(await evaluateFunction(()=>[...document.fonts].some(f=>f.family.includes('Montserrat')&&f.status==='loaded')),file+': Montserrat font loaded');
          check(await evaluateFunction(()=>[...document.fonts].some(f=>f.family.includes('Font Awesome 6 Free')&&f.status==='loaded')),file+': utility icon font loaded');
        }
        await measure(pageWrappers[file]||file,width,dark,' restyle '+viewer);
        const label=viewer+' '+file+' '+width+' '+dark;
        check(await evaluateFunction(viewer=>{
          const shell=viewer==='admin'?'admin-shell':'portal-shell';
          const main=document.querySelector('main');
          const watermark=getComputedStyle(main,'::before');
          return document.body.classList.contains(shell) && document.querySelectorAll('#themeToggle').length===1
            && document.querySelectorAll('a[href="logout.php"]').length===1
            && (viewer==='admin'?!!document.querySelector('.prism-sidebar'):!document.querySelector('.prism-sidebar'))
            && watermark.pointerEvents==='none' && watermark.backgroundImage.includes('ceu-logo.webp');
        },viewer),label+': role shell, single controls and decorative watermark');
        const nav=await evaluateFunction(()=>{
          const e=document.querySelector('.portal-navbar');if(!e)return true;
          const r=e.getBoundingClientRect();
          return r.left>=0 && r.right<=innerWidth+1 && r.bottom<=document.querySelector('main').getBoundingClientRect().top
            && [...e.querySelectorAll('button,a')].filter(x=>x.getClientRects().length).every(x=>{const b=x.getBoundingClientRect();return b.left>=r.left-1&&b.right<=r.right+1;});
        });
        check(nav,label+': portal header and controls fit without overlap');
        if (viewer==='adviser') check(await evaluateFunction(()=>![...document.querySelectorAll('.portal-navbar a')].some(a=>['admin_advisers.php','admin_ai.php','reports.php'].includes(a.getAttribute('href')))),label+': no Admin-only navigation');
        if(['dashboard.php','research_adviser.php','student.php'].includes(file) && [1440,375,320].includes(width)) {
          await evaluate('new Promise(r=>setTimeout(r,300))');
          const png=await command('Page.captureScreenshot',{format:'png'});
          fs.writeFileSync(path.join(dest,(visualOnly?'visual-':'')+file.replace('.php','')+'-'+width+'-'+(dark?'dark':'light')+'.png'),Buffer.from(png.data,'base64'));
          if(file==='dashboard.php' && width===1440 && !dark) {
            await evaluate('document.querySelector(".ceu-footer").scrollIntoView({block:"center"})');
            await waitFor('document.querySelector(".ceu-footer-accreditation-art img").naturalWidth>0');
            const footer=await command('Page.captureScreenshot',{format:'png'});
            fs.writeFileSync(path.join(dest,(visualOnly?'visual-':'')+'footer-1440-light.png'),Buffer.from(footer.data,'base64'));
          }
        }
      }
      console.log('Validated '+viewer+' '+file+' at six widths, both themes.');
    }
    check(errors.length===0,'No browser runtime exceptions',errors.join(' | '));
    console.log(`${checks} restyle UI checks; ${failures.length} failures.`);
    fs.writeFileSync(path.join(dest,visualOnly?'visual-browser-results.json':'restyle-browser-results.json'),JSON.stringify({checks,failures,errors},null,2));
    if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--shell-debug')) {
    await navigate('research_adviser.php',320,false,'populated','adviser');
    await evaluate('document.querySelector("[data-prism-account-toggle]").click()');
    console.log(JSON.stringify(await evaluateFunction(()=>{
      const a=document.querySelector('a[href="logout.php"]'),p=a.parentElement,r=a.getBoundingClientRect();
      return {width:r.width,height:r.height,right:r.right,viewport:innerWidth,hidden:p.hidden,display:getComputedStyle(p).display,linkDisplay:getComputedStyle(a).display,minHeight:getComputedStyle(a).minHeight,expanded:document.querySelector('[data-prism-account-toggle]').getAttribute('aria-expanded')};
    })));
    await navigate('admin_notifications.php',320,false,'populated');
    console.log(JSON.stringify(await evaluateFunction(()=>{
      return ['notificationForm','noticeAudience','noticeType','noticeMessage','cancelNotification'].map(id=>{const e=document.getElementById(id),r=e.getBoundingClientRect();return {id,left:r.left,right:r.right,width:r.width,minWidth:getComputedStyle(e).minWidth};});
    })));
    return;
  }
  if (process.argv.includes('--workspace-layout-only')) {
    for (const file of ['dashboard.php','calendar.php']) {
      await navigate(file,320,false,'populated');
      console.log(file,JSON.stringify(await evaluate(`(() => [...document.querySelectorAll('main *')].filter(el => {
        const box = el.getBoundingClientRect();
        return box.width > 0 && box.right > innerWidth + 1 && !el.closest('table');
      }).map(el => ({tag:el.tagName,id:el.id,cls:el.className,right:el.getBoundingClientRect().right,width:el.getBoundingClientRect().width,text:el.textContent.slice(0,70)})))()`)));
    }
    return;
  }
  if (process.argv.includes('--deadlines-only')) {
    await checkOfficialDeadlines();check(errors.length===0,'No deadline browser exceptions',errors.join(' | '));
    console.log(`${checks} official deadline UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--release-typography-only')) {
    await checkReleaseTypography();check(errors.length===0,'No typography browser exceptions',errors.join(' | '));
    console.log(`${checks} release typography/UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--data-export-only')) {
    await checkDataExport();check(errors.length===0,'No Data Export browser exceptions',errors.join(' | '));
    console.log(`${checks} Data Export UI checks; ${failures.length} failures.`);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--document-summary-only')) {
    await checkDocumentSummary();check(errors.length===0,'No document summary browser exceptions',errors.join(' | '));
    console.log(`${checks} document summary UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--workspace-only')) {
    await checkWorkspaceConsistency();check(errors.length===0,'No workspace consistency browser exceptions',errors.join(' | '));
    console.log(`${checks} workspace consistency UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--logout-only')) {
    await checkDuplicateLogoutControls();check(errors.length===0,'No logout navigation browser exceptions',errors.join(' | '));
    console.log(`${checks} logout navigation UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--adviser-only')) {
    await checkAdviserDashboard();await checkAdviserHeaderControls();check(errors.length===0,'No adviser dashboard browser exceptions',errors.join(' | '));
    console.log(`${checks} adviser dashboard UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--adviser-header-only')) {
    await checkAdviserHeaderControls();check(errors.length===0,'No adviser header browser exceptions',errors.join(' | '));
    console.log(`${checks} adviser header UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--final-only')) {
    await checkFinalRegression();check(errors.length===0,'No final regression browser exceptions',errors.join(' | '));
    console.log(`${checks} final regression UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--readiness-only')) {
    await checkReadiness(); check(errors.length===0,'No readiness browser errors',errors.join(' | '));
    console.log(`${checks} readiness UI checks; ${failures.length} failures.`);for(const failure of failures)console.error('FAIL '+failure);if(failures.length)process.exitCode=1;return;
  }
  if (process.argv.includes('--alignment-only')) {
    await checkAlignment();
    check(errors.length === 0, 'No alignment browser exceptions', errors.join(' | '));
    console.log(`${checks} alignment UI checks; ${failures.length} failures.`);
    for (const failure of failures) console.error('FAIL ' + failure);
    if (failures.length) process.exitCode = 1;
    return;
  }
  if (process.argv.includes('--footer-only')) {
    checkInstitutionalPartialBoundary();
    await checkFooterCoverage();
    check(errors.length === 0, 'No footer browser/PHP runtime exceptions', errors.join(' | '));
    console.log(`${checks} focused footer UI checks; ${failures.length} failures.`);
    for (const failure of failures) console.error('FAIL ' + failure);
    if (failures.length) process.exitCode = 1;
    return;
  }
  if (process.argv.includes('--polish-only')) {
    await checkTonightPolish();
    check(errors.length===0, 'No focused browser runtime exceptions', errors.join(' | '));
    console.log(`${checks} focused polish UI checks; ${failures.length} failures.`);
    for (const failure of failures) console.error('FAIL '+failure);
    if (failures.length) process.exitCode=1;
    return;
  }


  await checkDuplicateLogoutControls();
  await checkWorkspaceConsistency();
  await checkOfficialDeadlines();
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
    check(await evaluate('!document.getElementById("cancelNotification").disabled'), 'Notification sending disables only triggering control');
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
    check(await evaluateFunction(mode=>document.getElementById(mode==='summary'?'generateSummarizedReport':'generateFullReport').disabled && !document.getElementById(mode==='summary'?'generateFullReport':'generateSummarizedReport').disabled,mode), 'Only triggering report control disabled during generation');
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
  check(activity.method === 'GET' && JSON.stringify(activity.query) === JSON.stringify({ action: 'list', page: '1', q: 'protocol fixture', from: '2026-09-01', to: '2026-09-25', override: '1' }), 'Activity preserves filters and requests page one', JSON.stringify(activity.query));

  await navigate('ierbprog.php', 1280, false, 'populated');
  await evaluateFunction(() => {
    document.getElementById('stageFilter').value = 'Stage 2';
    document.getElementById('stageFilter').dispatchEvent(new Event('change'));
  });
  await waitFor('document.querySelectorAll("#ierbTableBody tr").length === 2');
  check(await evaluate('document.querySelectorAll("#ierbTableBody tr").length === 2 && document.querySelectorAll("#stageChart .stage-bar").length === 6'), 'IERB filtering retains stage keys and the full overview chart');
  await evaluateFunction(() => {
    document.getElementById('ierbSearch').value = 'No fixture student matches';
    document.getElementById('ierbSearch').dispatchEvent(new Event('input'));
  });
  await waitFor('document.querySelector("#ierbTableBody .ierb-table-empty")?.textContent.includes("No matching")');
  check(await evaluate('document.querySelector("#ierbTableBody .ierb-table-empty").textContent.includes("No matching")'), 'IERB distinguishes filtered empty results');
  await evaluateFunction(() => {
    document.getElementById('ierbSearch').value = '';
    document.getElementById('stageFilter').value = '';
    document.getElementById('stageFilter').dispatchEvent(new Event('change'));
  });
  await waitFor(`!!document.querySelector('#ierbTableBody button[title="Add note"]')`);
  await evaluateFunction(() => {
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

  for (const file of pages.filter(file => file !== 'admin_ai.php')) {
    await navigate(file, 375, true, 'empty', 'adviser');
    await measure(file, 375, true, ' adviser');
    await checkNavigation(file, 375, true);
    check(await evaluateFunction(() => !document.querySelector('#prismPrimaryNavigation a[href="admin_advisers.php"]')), file + ': adviser navigation omits admin-only directory');
    check(await evaluateFunction(() => !document.querySelector('#prismPrimaryNavigation a[href="admin_ai.php"], #prismPrimaryNavigation a[href="reports.php"]')), 'Adviser navigation omits every report entry');
    if (file === 'ierbprog.php') check(await evaluate('!document.getElementById("addIerbEntry")'), 'Adviser fixture has no add entry action');
  }
  await checkAlignment();
  await checkDashboard();
  await checkNotificationComposer();
  await checkDocumentSummary();
  await checkTonightPolish();
  await checkAdviserDashboard();
  await checkAdviserHeaderControls();
  await checkStudentPortal();
  await checkAcademicManagement();
  await checkAcademicIerb();
  await checkAcademicDisplay();
  await checkRemediation();
  const institutionalStart = checks;
  checkInstitutionalPartialBoundary();
  await checkInstitutionalComponents();
  await checkFooterCoverage();
  await checkDataExport();
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
    fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
  }
});
