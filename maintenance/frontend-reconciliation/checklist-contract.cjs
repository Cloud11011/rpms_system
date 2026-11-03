'use strict';
// Browser contract tests for the reconciled ZIP renderer. Requests are synthetic;
// actual HTTP authorization and transaction tests remain in the V14 suites.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const source=process.env.PRISM_RECONCILIATION_SOURCE||root;
const output=process.env.PRISM_RECONCILIATION_OUTPUT||path.join(root,'PRISM_V14_FRONTEND_RECONCILIATION/checklist-contract.json');
const playwright=require(process.env.PRISM_TEST_PLAYWRIGHT||'C:/PRISM_Workspaces/prism-v13-release-clean/PRISM_V13_UI_REMEDIATION_AUDIT/browser-tools/node_modules/playwright-core');
const cases=[];
const check=(value,label)=>{assert.ok(value,label);cases.push(label);};
function fixture(role='admin',used=0){
  return {ok:true,enrolled:true,viewerRole:role,operationsActivated:true,submissionScope:{studentId:55,projectId:2,researchTitle:'Synthetic reconciliation project'},
    workflow:{stage:'initial_submission',stageLabel:'Initial Submission',revision:7,officialResubmissionCount:used,officialResubmissionLimit:3,correctionCase:null},
    requirements:[['form11','Form 11 Review Checklist'],['protocol','Study Protocol']].map(([key,label],i)=>({key,label,stage:'initial_submission',state:'Formally Submitted to RPMS',documentId:'synthetic-'+i,documentType:label,versionNo:1,canAccept:role==='admin'})),
    uploadTypes:['Form 11 Review Checklist','Study Protocol'],pendingCorrectionRequest:null};
}
(async()=>{
 const browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try{
  const page=await browser.newPage();
  await page.route('http://127.0.0.1:16981/**',route=>route.fulfill({contentType:'text/html',body:'<html><body></body></html>'}));
  await page.goto('http://127.0.0.1:16981/');
  async function render(data,portal=false){
    await page.setContent(`<section id="${portal?'v14RequirementChecklist':'checklist'}"></section>`);
    await page.evaluate(data=>{
      window.__data=data;window.__calls=[];
      window.PrismUI={request:async(url,opts={})=>{
        window.__calls.push({url,body:opts.body?JSON.parse(opts.body):null});
        return structuredClone(window.__data);
      },toast:()=>{},busy:button=>{button.disabled=true;return()=>{button.disabled=false;}},esc:v=>String(v)};
    },data);
    await page.addScriptTag({path:path.join(source,'assets/js/ierb-requirements.js')});
    await page.evaluate(async portal=>{await PrismRequirements.load(99,document.querySelector('section'));},portal);
  }
  await render(fixture());
  check(await page.locator('.prq-title').textContent()==='Synthetic reconciliation project','ZIP checklist uses the authoritative project title');
  check((await page.locator('.prq-res').textContent()).includes('0 of 3 used'),'V14 official cycle count maps to the ZIP counter');
  for(const box of await page.locator('.prq-check').all()) await box.check();
  await page.locator('#prqBulkReason').fill('Reviewed current document versions.');
  await page.locator('.prq-bulk button.is-primary').click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.url.includes('accept_requirements_bulk')));
  const calls=await page.evaluate(()=>window.__calls.filter(c=>c.body));
  check(calls.length===1&&calls[0].url.endsWith('action=accept_requirements_bulk'),'Selected requirements use exactly one atomic bulk operation');
  check(calls[0].body.studentId===55&&calls[0].body.revision===7,'Acceptance binds the authoritative submission holder and workflow revision');
  check(calls[0].body.items.length===2&&calls[0].body.items.every(i=>Object.keys(i).sort().join(',')==='documentId,requirementKey'),'Bulk request carries only the selected current evidence identities');
  check(/^[0-9a-f-]{36}$/.test(calls[0].body.requestKey),'Bulk request includes an idempotency key');
  await render(fixture('admin',3));
  check(await page.getByRole('button',{name:'Open official resubmission',exact:true}).count()===0,'The exhausted three-cycle limit has no new-case control');
  check((await page.locator('.prq-res').textContent()).includes('3 of 3 used'),'Exhausted cycle usage remains visible');
  await render(fixture('adviser'));
  check(await page.getByRole('button',{name:/Request a resubmission|Open official resubmission/}).count()===0,'Advisers have no official-case initiation control (approved exception)');
  check(await page.locator('.prq-check').count()===0,'Advisers cannot select requirements for RPMS acceptance');
  const correction=fixture('student',1);
  correction.workflow.stage='resubmission_amendment';correction.workflow.stageLabel='Resubmission / Amendment';
  correction.workflow.correctionCase={id:1,interruptedStage:'initial_submission',requiresAmendment:true};
  correction.requirements=[{key:'protocol',label:'Study Protocol',documentType:'Study Protocol',stage:'initial_submission',state:'Returned for Revision',documentId:'old-protocol',versionNo:1},
    {key:'amendment',label:'Amendment Form',stage:'resubmission_amendment',state:'Missing'}];
  correction.uploadTypes=['Study Protocol','Amendment Form'];
  await render(correction,true);
  check(await page.locator('.prq-row').count()===2,'The Student correction checklist includes affected evidence and its required Amendment');
  check((await page.locator('.prq-bar-text').textContent()).includes('0 of 2'),'Correction progress counts the required Amendment');
  await page.locator('[aria-controls="prqStage1"]').click();
  check(await page.getByRole('button',{name:'Upload a revision of Study Protocol'}).count()===1,'Returned current evidence retains the successor-version action');
  check(await page.getByRole('button',{name:'Upload Amendment Form',exact:true}).count()===1,'The required missing Amendment retains its upload shortcut');
  const gathering=fixture('admin');gathering.workflow.stage='approval_data_gathering';
  gathering.workflow.stageLabel='Approval of Data Gathering';gathering.workflow.resolvedOfficialResubmissionCount=0;
  await render(gathering);
  check(!(await page.locator('[aria-controls="prqStage2"] .prq-num').getAttribute('class')).includes('is-done')
    && (await page.locator('[aria-controls="prqStage2"] .prq-num').textContent())==='2','Stage 3 alone gives conditional Stage 2 neither a completed class nor a checkmark');
  gathering.workflow.resolvedOfficialResubmissionCount=1;await render(gathering);
  check((await page.locator('[aria-controls="prqStage2"] .prq-num').getAttribute('class')).includes('is-done'),'A resolved official case gives conditional Stage 2 its completed marker');
  gathering.workflow.stage='resubmission_amendment';await render(gathering);
  check(!(await page.locator('[aria-controls="prqStage2"] .prq-num').getAttribute('class')).includes('is-done'),'A reopened official case presents Stage 2 as current even after an earlier resolved case');
  check(!(await page.locator('.prq-chips').innerText()).includes('null'),'Absent status filters produce no literal null chips');
  const report={status:'PASS',assertions:cases.length,cases,scope:'Chromium with synthetic request contracts; no live database or provider requests.'};
  fs.mkdirSync(path.dirname(output),{recursive:true});fs.writeFileSync(output,JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify({status:report.status,assertions:report.assertions}));
 }finally{await browser.close();}
})().catch(e=>{fs.mkdirSync(path.dirname(output),{recursive:true});fs.writeFileSync(output,JSON.stringify({status:'FAIL',assertions:cases.length,cases,error:e.stack},null,2));console.error(e);process.exitCode=1;});
