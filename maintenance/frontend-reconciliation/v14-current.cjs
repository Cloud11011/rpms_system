'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
function pdf(){const stream='BT /F1 10 Tf 30 750 Td (Synthetic V14 research document. No real participants or institutional decision.) Tj ET';
 const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`];
 let text='%PDF-1.4\n';const offsets=[];objects.forEach((o,i)=>{offsets.push(Buffer.byteLength(text));text+=`${i+1} 0 obj\n${o}\nendobj\n`;});const start=Buffer.byteLength(text);
 return Buffer.from(text+'xref\n0 6\n0000000000 65535 f \n'+offsets.map(o=>String(o).padStart(10,'0')+' 00000 n \n').join('')+`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`);}
module.exports=async h=>{
 const {check,sql,sessions:s,post,response,expect,generation,base,root,review,php,playwright}=h;
 const start=h.checks.length,errors=[];
 const cli=data=>JSON.parse(cp.execFileSync(php,[path.join(__dirname,'v14-fixture.php')],{input:JSON.stringify(data),encoding:'utf8',windowsHide:true}));
 const catalog=cli({action:'catalog'}).result;
 const state=()=>sql('SELECT * FROM ierb_workflows WHERE student_id=4')[0];
 const rev=()=>Number(state().revision);
 const current=async(ctx=s.admin)=>response(await ctx.get('/documents_api.php?action=requirements&studentId=4'));
 const upload=async(key,revisionOf='',ctx=s.student)=>response(await ctx.post('/documents_api.php?action=upload',{
  headers:{origin:base,'X-Prism-Generation':await generation(ctx)},multipart:{documentType:catalog[key][0],revisionOf,requestId:crypto.randomUUID(),
   document:{name:`Synthetic current V14 ${key}.pdf`,mimeType:'application/pdf',buffer:pdf()}}}));
 const reviewDoc=async(id,status='Approved')=>expect(post(s.adviser,'review',{id,status,remarks:'Synthetic Adviser review with required feedback.'},'documents_api.php'),200,'Authorized Adviser review '+status);
 const accept=async(key,id,extra={})=>expect(post(s.admin,'accept_requirement',{studentId:4,requirementKey:key,documentId:id,revision:rev(),reason:'RPMS verified current synthetic evidence.',requestKey:crypto.randomUUID(),...extra},'documents_api.php'),200,'RPMS accepts '+key);
 const partner=await h.login('student6@example.test');
 const ids={};let browser;
 try{
  if(process.env.PRISM_TEST_V14_BROWSER_ONLY!=='1'){
  const dbBefore=JSON.stringify(sql('SELECT id,stage_key,revision FROM ierb_workflows ORDER BY id'));
  const auditBefore=sql('SELECT COUNT(*) AS n FROM activity_logs')[0].n;
  for(const action of ['advance','correct','status','override','save','note','delete']){
   await expect(post(s.admin,action,{id:4,stage:'Stage 5',reason:'Synthetic retired operation must fail.'}),410,'Obsolete '+action+' HTTP operation is retired');
  }
  check(JSON.stringify(sql('SELECT id,stage_key,revision FROM ierb_workflows ORDER BY id'))===dbBefore&&sql('SELECT COUNT(*) AS n FROM activity_logs')[0].n===auditBefore,'Retired operations mutate neither V14 state nor audit history');
  const monitor=await response(await s.admin.get('/ierb_api.php?action=monitor&layout=cards&perPage=5'));
  check(monitor.status===200&&monitor.body.records.length===2&&monitor.body.records.every(r=>r.model==='V14'),'The monitor contains canonical V14 projects only, excluding the unassigned raw-stage fixture');
  check(monitor.body.filterOptions.stage.length===4&&monitor.body.filterOptions.stage.every(o=>['initial_submission','resubmission_amendment','approval_data_gathering','ierb_completed'].includes(o.value)),'Active filters expose exactly four semantic stages');
  const scoped=await response(await s.adviser.get('/ierb_api.php?action=monitor_requirements&id=4'));
  check(scoped.status===200&&scoped.body.record.requirements.filter(r=>r.mandatory).length===13,'Assigned staff requirement projection exposes all 13 initial requirements');
  check((await s.adviser.get('/ierb_api.php?action=monitor_requirements&id=5')).status()===422,'Foreign Adviser requirements remain inaccessible');
  check((await s.student.get('/documents_api.php?action=requirements&studentId=5')).status()===403,'Students cannot inspect another project’s requirements');
  const guidance=await response(await s.admin.get('/stage_labels_api.php?action=list'));
  check(guidance.status===200&&guidance.body.model==='v14'&&guidance.body.fixedStageNames&&guidance.body.order.length===4,'Stage guidance exposes fixed V14 names with no historical stage mode');
  const rename=await post(s.admin,'save',{stageKey:'ierb_completed',label:'Certificate of Approval',expectedVersion:guidance.body.stages[3].version,guidance:'Synthetic invalid rename.'},'stage_labels_api.php');
  check(rename.status===422,'Stage 4 cannot be renamed to an obsolete Certificate of Approval stage');
  const initial=Object.keys(catalog).filter(k=>catalog[k][1]==='initial_submission');check(initial.length===13,'The authoritative initial catalog has exactly 13 required document types');
  const form=new FormData();form.append('fileCount','13');form.append('requestId',crypto.randomUUID());
  for(const key of initial){form.append('documentTypes[]',catalog[key][0]);form.append('document[]',new Blob([pdf()],{type:'application/pdf'}),`Synthetic current V14 ${key}.pdf`);}
  const cookies=(await partner.storageState()).cookies.map(c=>`${c.name}=${c.value}`).join('; ');
  const batchResponse=await fetch(base+'/documents_api.php?action=upload',{method:'POST',headers:{origin:base,cookie:cookies,'X-Prism-Generation':await generation(partner)},body:form});
  const batch=await batchResponse.json();
  check(batchResponse.status===200&&batch.uploaded===13&&batch.failed===0,'A verified non-holder member uploads thirteen individually typed files in one actual multipart request: '+JSON.stringify({status:batchResponse.status,uploaded:batch.uploaded,failed:batch.failed,message:batch.message}));
  for(const key of initial){const row=sql('SELECT id FROM documents WHERE student_id=4 AND document_type=? AND is_current=1',[catalog[key][0]])[0];check(!!row,'Current evidence exists for '+key);ids[key]=row.id;}
  const duplicate=await upload('protocol');check(duplicate.status===409,'A normal upload cannot silently replace a current canonical requirement');
  await reviewDoc(ids.protocol,'Resubmission Requested');
  check((await current()).body.workflow.officialResubmissionCount===0,'Ordinary Adviser revisions do not consume official resubmission cycles');
  const first=ids.protocol,successor=await upload('protocol',first);check(successor.status===200,'A returned document accepts an explicit successor version');ids.protocol=successor.body.document.id;
  check(sql('SELECT COUNT(*) AS n FROM documents WHERE id=? OR supersedes_id=?',[first,first])[0].n===2,'Earlier document versions remain retained');
  const original=await s.student.get('/documents_api.php?action=file&download=1&id='+first);check(original.status()===200&&(await original.body()).equals(pdf()),'An authorized member can download the retained original bytes');
  for(const key of initial) await reviewDoc(ids[key]);
  const bulk=await post(s.admin,'accept_requirements_bulk',{studentId:4,revision:rev(),reason:'RPMS verified all thirteen current initial requirements.',requestKey:crypto.randomUUID(),items:initial.map(key=>({requirementKey:key,documentId:ids[key]}))},'documents_api.php');
  check(bulk.status===200,'All thirteen initial requirements are accepted in one authoritative bulk transaction');
  const projectId=sql('SELECT id FROM research_projects WHERE submission_holder_id=4')[0].id;
  const preview=await post(s.admin,'group_advance_preview',{groupKey:crypto.createHash('sha256').update('project:'+projectId).digest('hex')});
  check(preview.status===200&&preview.body.eligible,'RPMS can preview canonical group advancement after all requirements are accepted');
  const advanced=await post(s.admin,'group_advance',{confirmationToken:preview.body.confirmationToken,reason:'RPMS verified all initial project evidence.',confirmed:true});
  check(advanced.status===200&&state().stage_key==='approval_data_gathering','An authorized group decision advances Initial Submission directly to Data Gathering');
  check((await current()).body.workflow.resolvedOfficialResubmissionCount===0,'Reaching Stage 3 alone does not establish a completed conditional Stage 2');
  browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  const conditionalContext=await browser.newContext({storageState:await s.student.storageState()});
  try{
   await conditionalContext.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,route=>route.fulfill({contentType:'text/css',body:''}));
   const page=await conditionalContext.newPage();await page.goto(base+'/role_portal.php#progress');
   await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
   await page.locator('#studentDashboardState[aria-busy="false"]').waitFor({state:'attached'});
   check(await page.locator('#stageList .stage-card').count()===4,'Stage 3 Student progress presents exactly the four approved V14 stages');
   check(!(await page.locator('#stageList .stage-card').nth(1).getAttribute('class')).includes('complete')&&(await page.locator('#stageList .stage-card').nth(1).innerText()).includes('Conditional'),'Student Stage 2 is conditional, with no completed marker, before any actual official case');
  }finally{await conditionalContext.close();}
  const finalEarly=await upload('final_report');check(finalEarly.status===409,'Final Report upload is rejected before institutional Form 33 acceptance');
  for(let cycle=1;cycle<=3;cycle++){
   const key=crypto.randomUUID();
   const denied=await post(s.adviser,'v14_correction_open',{studentId:4,revision:rev(),documentIds:[ids.protocol],requiresAmendment:cycle===1,reason:'Synthetic unauthorized Adviser case.',requestKey:key});
   check(denied.status===403,'Adviser cannot initiate official cycle '+cycle);
   const opened=await post(s.admin,'v14_correction_open',{studentId:4,revision:rev(),documentIds:[ids.protocol],requiresAmendment:cycle===1,reason:'RPMS opened a synthetic official correction.',requestKey:crypto.randomUUID()});
   check(opened.status===200&&state().stage_key==='resubmission_amendment','RPMS opens official cycle '+cycle+' at conditional Stage 2');
   const openCheck=(await current()).body;
   check(openCheck.workflow.officialResubmissionCount===cycle&&openCheck.workflow.resolvedOfficialResubmissionCount===cycle-1,'Official counters distinguish open and resolved cases for cycle '+cycle);
   await expect(post(s.admin,'return_for_revision',{id:ids.protocol,reason:'RPMS requires the corrected successor version.'},'documents_api.php'),200,'RPMS returns the affected evidence for correction');
   const revised=await upload('protocol',ids.protocol);check(revised.status===200,'Affected evidence receives a successor in cycle '+cycle);ids.protocol=revised.body.document.id;
   await reviewDoc(ids.protocol);await accept('protocol',ids.protocol);
   if(cycle===1){const amendment=await upload('amendment');check(amendment.status===200,'The case-required Amendment Form can be uploaded');await reviewDoc(amendment.body.document.id);await accept('amendment',amendment.body.document.id);}
   const resolved=await post(s.admin,'v14_correction_resolve',{studentId:4,revision:rev(),reason:'RPMS verified accepted corrected versions and the case policy.',requestKey:crypto.randomUUID()});
   check(resolved.status===200&&state().stage_key==='approval_data_gathering','Resolution resumes the interrupted Stage 3 for cycle '+cycle);
   check((await current()).body.workflow.resolvedOfficialResubmissionCount===cycle,'Only ledger-backed resolved official cases complete conditional Stage 2');
  }
  const fourth=await post(s.admin,'v14_correction_open',{studentId:4,revision:rev(),documentIds:[ids.protocol],requiresAmendment:false,reason:'Synthetic fourth cycle must be rejected.',requestKey:crypto.randomUUID()});
  check(fourth.status===409&&sql('SELECT id FROM ierb_correction_cases WHERE workflow_id=?',[state().id]).length===3,'The lifetime fourth official resubmission is rejected server-side without an extra case');
  const form33=await upload('form33');check(form33.status===200,'Form 33 uploads in Data Gathering');await reviewDoc(form33.body.document.id);
  const noReference=await post(s.admin,'accept_requirement',{studentId:4,requirementKey:'form33',documentId:form33.body.document.id,revision:rev(),reason:'Synthetic missing institutional reference.',requestKey:crypto.randomUUID()},'documents_api.php');
  check(noReference.status>=400&&noReference.status<500,'Form 33 acceptance requires the institutional approval reference');
  await accept('form33',form33.body.document.id,{institutionalApprovalReference:'Synthetic institutional approval reference; test only.'});
  const final=await upload('final_report','',partner);check(final.status===200,'A verified group member can upload Final Report after Form 33 acceptance');
  check(cli({action:'audit-fault',enabled:true}).ok,'Disposable rollback fault is installed against the verified synthetic datadir');
  try{const rejected=await post(s.adviser,'review',{id:final.body.document.id,status:'Approved',remarks:'Synthetic approval must roll back on audit failure.'},'documents_api.php');
   check(rejected.status>=500&&state().stage_key==='approval_data_gathering'&&sql('SELECT review_status,rpms_submitted_at FROM documents WHERE id=?',[final.body.document.id])[0].rpms_submitted_at===null,'Final Report, formal submission and completion roll back together when audit persistence fails');
  }finally{check(cli({action:'audit-fault',enabled:false}).ok,'The synthetic audit fault is removed after rollback verification');}
  await reviewDoc(final.body.document.id);
  check(state().stage_key==='ierb_completed','Authorized Final Report formal submission completes the V14 project');
  check(sql("SELECT id FROM research_project_member_history WHERE project_id=? AND to_stage='ierb_completed'",[projectId]).length===2,'Completion is synchronized to both verified project members');
  await post(s.adviser,'review',{id:final.body.document.id,status:'Approved',remarks:'Synthetic duplicate approval retry.'},'documents_api.php');
  check(sql("SELECT id FROM ierb_workflow_events WHERE workflow_id=? AND event_key='final_report_submitted'",[state().id]).length===1,'A retry creates no duplicate Final Report completion event');
  await require('./current-surfaces.cjs')(h);
  }
  browser ||= await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  for(const role of ['admin','student'])for(const theme of ['light','dark'])for(const width of [390,1440]){
   const ctx=await browser.newContext({viewport:{width,height:1000},storageState:await s[role].storageState(),reducedMotion:'reduce'});
   try{const identity=crypto.createHash('sha256').update(role==='student'?'Student|S4':'admin|1').digest('hex');
    await ctx.addInitScript(({theme,role,identity})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(`prismTour.${role}.${identity}.v1`,'seen');},{theme,role,identity});
    await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,route=>route.fulfill({contentType:'text/css',body:''}));
    const page=await ctx.newPage();page.on('pageerror',e=>errors.push(String(e)));
    await page.goto(base+(role==='admin'?'/ierbprog.php':'/role_portal.php'));
    await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
    if(role==='admin' && await page.locator('.prism-sidebar-backdrop:not([hidden])').count()) await page.locator('#prismSidebarToggle').click();
    if(role==='admin'){
     await page.locator('#ierbTableBody[aria-busy="false"]').waitFor();
     check(await page.locator('.ierb-pc').count()===2,'V14-only '+width+' '+theme+': ZIP IERB cards show canonical projects');
     const options=await page.locator('#stageFilter option').evaluateAll(nodes=>nodes.filter(n=>n.value).map(n=>n.value));
     check(options.length===4&&options.includes('ierb_completed'),'V14-only '+width+' '+theme+': current Stage 4 filter is IERB Completed');
     const meter=page.locator('.ierb-pc-meter').first();await meter.hover();
     await page.locator('.monitor-popover:not([hidden])').waitFor();
     await page.locator('.monitor-popover .monitor-checklist').getByText('Form 11 Review Checklist',{exact:true}).waitFor();
     check((await page.locator('.monitor-popover:not([hidden])').innerText()).includes('Form 11')&&await page.locator('.monitor-popover .dpm-requirement-badge').count()>=13,'V14-only '+width+' '+theme+': hovering IERB Progress reveals applicable requirement names and statuses');
    }else{
     await page.locator('#studentDashboardState[aria-busy="false"]').waitFor({state:'attached'});
     const stageButtons=page.getByRole('button',{name:/^Stage [1-4]:/});check(await stageButtons.count()===4,'V14-only '+width+' '+theme+': Student dashboard has four workflow stages');
     check((await page.locator('body').innerText()).includes('IERB Completed'),'V14-only '+width+' '+theme+': current completion is displayed as IERB Completed');
    }
    const visible=await page.locator('body').innerText();check(!/Stage 5|Historical Student Stage Records|Legacy Student Stage Distribution/.test(visible),'V14-only '+width+' '+theme+': obsolete workflow presentation is absent');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'V14-only '+width+' '+theme+': current interface fits the viewport');
    const out=path.join(review,'current-v14-screenshots');fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,`${role}-${theme}-${width}.png`),fullPage:true,animations:'disabled'});
   }finally{await ctx.close();}
  }
  check(!errors.length,'Current V14 browser matrix has no JavaScript errors: '+JSON.stringify(errors));
  await require('./current-pages.cjs')(h,browser);
  await require('./settings-pages.cjs')(h,browser);
  await require('./reports-pages.cjs')(h,browser);
  await require('./security-audit.cjs')(h);
  fs.writeFileSync(path.join(review,'v14-current-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start),scope:'Schema-'+process.env.PRISM_TEST_SCHEMA_VERSION+' synthetic canonical projects; actual PHP HTTP sessions and Chromium; no legacy HTTP workflow dependencies.'},null,2));
 }finally{if(browser)await browser.close();await partner.dispose();}
};
