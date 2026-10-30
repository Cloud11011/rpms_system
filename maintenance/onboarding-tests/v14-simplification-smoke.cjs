#!/usr/bin/env node
'use strict';
// Read-only local smoke checks for the V14 simplification branch.
// Run at the repository root: node maintenance/onboarding-tests/v14-simplification-smoke.cjs
const fs=require('node:fs');
const path=require('node:path');
const cp=require('node:child_process');
const root=path.resolve(__dirname,'../..');
const php=[
  'includes/research_onboarding.php','includes/research_project_advancement.php',
  'includes/ierb_v14_requirements.php','documents_api.php','ierb_api.php',
  'includes/stage_configuration.php','ierbprog.php','dashboard.php',
  'reports.php','reports_api.php','data_exports_api.php','role_portal.php',
  'includes/ierb_monitor_view.php'
];
const js=[
  'assets/js/ierb-requirements.js','assets/js/role-portal.js',
  'assets/js/document-upload.js','assets/js/ierbprog.js',
  'assets/js/reports.js','assets/js/prism-ui.js'
];
let passed=0;
function check(name,yes){if(!yes)throw new Error('FAIL: '+name);passed++;console.log('PASS: '+name)}
const src=p=>fs.readFileSync(path.join(root,p),'utf8');
for(const p of php)check('PHP file exists: '+p,fs.existsSync(path.join(root,p)));
for(const p of js){cp.execFileSync(process.execPath,['--check',path.join(root,p)],{stdio:'pipe'});check('JS syntax: '+p,true)}
try {
  cp.execFileSync('php',['-v'],{stdio:'ignore'});
  for(const p of php){cp.execFileSync('php',['-l',path.join(root,p)],{stdio:'pipe'});check('PHP syntax: '+p,true)}
} catch (e) {
  if(e.code==='ENOENT') console.log('NOTICE: PHP executable not found; run PHP lint on your XAMPP workstation before release.');
  else throw e;
}
const req=src('includes/ierb_v14_requirements.php');
const client=src('assets/js/role-portal.js');
const onboarding=src('includes/research_onboarding.php');
const guidance=src('includes/stage_configuration.php');
check('Server stage-aware type gate',req.includes('function ierb_v14_stage_upload_types')&&req.includes('This document type is not required'));
check('Initial 13 defined document requirements',(req.match(/IerbStage::INITIAL, \[/g)||[]).length===13);
check('Correction-case type filter',req.includes('affected_documents_json')&&req.includes('requires_amendment'));
check('Upload rejects superseding current version silently',src('documents_api.php').includes('Use Upload Revision'));
check('Student dropdown uses current checklist',client.includes('v14UploadTypes = checklist.requirements.filter'));
check('Student has stage checklist shortcut',src('assets/js/ierb-requirements.js').includes('prism:upload-requirement'));
check('Adviser password no longer blocks membership',!onboarding.includes("$adviserLogin=lifecycle_resolve_login"));
check('Single V14 bulk acceptance transaction',req.includes('function ierb_v14_accept_requirements_bulk')&&req.includes('Bulk requirement acceptance owns its transaction.'));
check('Historical IERB actions read-only',src('ierb_api.php').includes('Legacy IERB stages are read-only'));
check('V14 guidance has separate short storage keys',guidance.includes("'V14_INITIAL'")&&guidance.includes("'V14_COMPLETED'"));
check('No destructive schema operations in changed source',![...php,...js].some(p=>/\bDROP\s+(?:TABLE|DATABASE)\b/i.test(src(p))));
console.log('\nV14 simplification smoke checks: '+passed+' passed. No records were changed.');
console.log('NEXT: isolated MariaDB role workflow and browser regression are still required.');
