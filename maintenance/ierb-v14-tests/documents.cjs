const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), crypto = require('node:crypto');
function pdf() {
  const text='Synthetic Batch2 research protocol and fictional study participants. '.repeat(8), stream=`BT /F1 10 Tf 30 750 Td (${text}) Tj ET`;
  const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`];
  let data='%PDF-1.4\n';const offsets=[];objects.forEach((o,i)=>{offsets.push(Buffer.byteLength(data));data+=`${i+1} 0 obj\n${o}\nendobj\n`;});
  const start=Buffer.byteLength(data);return Buffer.from(data+'xref\n0 6\n0000000000 65535 f \n'+offsets.map(o=>String(o).padStart(10,'0')+' 00000 n \n').join('')+`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`);
}
module.exports = async h => {
  const {sql,check,post,response,expect,generation,sessions:s,base,root,php,playwright}=h;
  const start=h.checks.length, helper=path.join(__dirname,'documents-fixture.php'), evidence=path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR||path.join(root,'PRISM_V14_BATCH_02_EVIDENCE'));fs.mkdirSync(evidence,{recursive:true});
  const guideKeys = ['admin.admin|1','student.Student|S4'].map(value=>{
    const dot=value.indexOf('.');return `prismTour.${value.slice(0,dot)}.${crypto.createHash('sha256').update(value.slice(dot+1)).digest('hex')}.v1`;
  });
  const cli=data=>JSON.parse(cp.execFileSync(php,[helper],{input:JSON.stringify(data),encoding:'utf8',windowsHide:true}));
  function worker(data) {const p=cp.spawn(php,[helper],{windowsHide:true});let out='',err='';const done=new Promise((resolve,reject)=>{p.stdout.on('data',d=>out+=d);p.stderr.on('data',d=>err+=d);p.on('error',reject);p.on('exit',code=>code?reject(Error(err)):resolve(out));});p.stdin.end(JSON.stringify(data));return{done,output:()=>out};}
  const catalog=cli({action:'catalog'}).result,initial=Object.keys(catalog).filter(k=>catalog[k][1]==='initial_submission');
  const state=()=>sql('SELECT * FROM ierb_workflows WHERE student_id=4')[0];
  const rev=()=>Number(state().revision);
  const checklist=async(ctx=s.admin)=>response(await ctx.get('/documents_api.php?action=requirements&studentId=4'));
  const upload=async(key,revisionOf='',requestId=crypto.randomUUID())=>response(await s.student.post('/documents_api.php?action=upload',{
    headers:{origin:base,'X-Prism-Generation':await generation(s.student)},multipart:{documentType:catalog[key][2][0],revisionOf,requestId,
      document:{name:`Synthetic Batch2 ${key}.pdf`,mimeType:'application/pdf',buffer:pdf()}}}));
  const sendBatch=async(ctx,sid,items,requestId)=>{
    const form=new FormData();if(sid)form.append('studentDbId',String(sid));form.append('documentType','Other Supporting Document');form.append('fileCount',String(items.length));form.append('requestId',requestId);
    for(const [type,name,bytes] of items){form.append('documentTypes[]',type);form.append('document[]',new Blob([bytes||pdf()],{type:'application/pdf'}),`Synthetic Batch2 ${name}.pdf`);}
    const cookies=(await ctx.storageState()).cookies.map(c=>`${c.name}=${c.value}`).join('; ');
    const r=await fetch(base+'/documents_api.php?action=upload',{method:'POST',headers:{origin:base,cookie:cookies,'X-Prism-Generation':await generation(ctx)},body:form});return{status:r.status,body:await r.json()};
  };
  const review=async id=>expect(post(s.adviser,'review',{id,status:'Approved',remarks:'Synthetic Batch2 Adviser approval'},'documents_api.php'),200,'Adviser approves and automatically submits current '+id);
  const accept=async(key,id,status=200,extra={})=>expect(post(s.admin,'accept_requirement',{studentId:4,requirementKey:key,documentId:id,revision:rev(),reason:'Synthetic RPMS requirement acceptance',requestKey:crypto.randomUUID(),...extra},'documents_api.php'),status,`RPMS acceptance ${key}: expected ${status}`);
  const projectTests=process.env.PRISM_TEST_PROJECT_GROUP==='1' ? require('../ierb-project-tests/projects.cjs')({...h,cli,worker,state,rev,checklist,pdf}) : async()=>{};
  let browser;
  try {
    check(cli({action:'enroll'}).ok,'Batch2 explicitly enrolls only the selected synthetic submission holder');
    await projectTests('migration');
    check(cli({action:'project'}).ok,'Batch3 explicitly maps the regression holder to a canonical singleton project');
    if(process.env.PRISM_TEST_REPORT_SCHEMA14==='1'){
      const r=JSON.parse(cp.execFileSync(php,[path.join(root,'maintenance/ierb-report-tests/fixture.php')],{input:JSON.stringify({action:'install'}),encoding:'utf8',windowsHide:true}));
      check(r.ok,'Existing V14 document suite runs on explicitly migrated synthetic schema 14');
    }
    if(process.env.PRISM_TEST_MONITOR_SCHEMA15==='1'){
      const r=JSON.parse(cp.execFileSync(php,[path.join(root,'maintenance/ierb-monitor-tests/fixture.php')],{input:JSON.stringify({action:'install'}),encoding:'utf8',windowsHide:true}));
      check(r.ok,'Existing document/project workflows run on explicitly migrated disposable schema 15');
    }
    await projectTests('setup');
    check(initial.length===13,'Exactly the approved 13 Initial Submission requirements');
    let list=await checklist();check(list.body.requirements.filter(r=>r.stage==='initial_submission').every(r=>r.state==='Missing'),'Missing requirements never count as accepted');
    check(list.body.uploadTypes.length===13&&!list.body.uploadTypes.includes('Form 33-0B - Final Report Form')
      && !list.body.uploadTypes.includes('Form 33 - Certificate of Approval'),
      'Initial Submission has exactly 13 eligible upload categories, excluding Form 33 and Final Report');
    check(!sql('SELECT id FROM ierb_workflows WHERE student_id=5').length,'Project partner is not implicitly enrolled');
    const other=await response(await s.student.get('/documents_api.php?action=requirements&studentId=5'));
    check(other.status===403,'Student cannot inspect another submission holder checklist');
    await expect(s.admin.get('/documents_api.php?action=accept_requirement').then(response),405,'Requirement acceptance requires POST');
    const docs={};
    const studentBatch=await sendBatch(s.student,null,[[catalog.ethics[2][0],'ethics'],[catalog.cv[2][0],'cv'],['Other Supporting Document','invalid-content',Buffer.from('This is not a PDF document.')]],crypto.randomUUID());
    check(studentBatch.status===200&&studentBatch.body.uploaded===2&&studentBatch.body.failed===1,'Student mixed-category upload preserves two valid requirements and reports invalid PDF content separately');
    for(const result of studentBatch.body.results.filter(r=>r.ok))docs[result.document.documentType===catalog.ethics[2][0]?'ethics':'cv']=result.document.id;
    for (const key of initial) {
      if(!docs[key]){const uploaded=await expect(upload(key),200,'Upload approved required category '+key);docs[key]=uploaded.body.document.id;}
      const pending=(await checklist()).body.requirements.find(r=>r.key===key);
      check(pending.state==='Uploaded / Pending Review'&&!pending.acceptanceEventId,'Uploaded '+key+' is pending and has no acceptance evidence');
      await accept(key,docs[key],409);
      if (['questionnaire','workflow'].includes(key)) {
        const decision = key === 'questionnaire' ? 'Denied' : 'Resubmission Requested';
        await expect(post(s.adviser,'review',{id:docs[key],status:decision,remarks:'Synthetic reviewer requires a corrected version.'},'documents_api.php'),200,'Adviser records '+decision+' without accepting a requirement');
        check((await checklist()).body.requirements.find(r=>r.key===key).state==='Returned for Revision',decision+' is a revision state in the shared checklist');
        const corrected=await expect(upload(key,docs[key]),200,'Upload successor after '+decision);docs[key]=corrected.body.document.id;
      }
      await review(docs[key]);
      const submitted=(await checklist()).body.requirements.find(r=>r.key===key);
      check(submitted.state==='Formally Submitted to RPMS','Adviser approval is formal submission, distinct from acceptance: '+key);
      if (key === 'ethics') {
        const beforeRevision=rev();
        sql("CREATE TRIGGER batch2_acceptance_fault BEFORE INSERT ON activity_logs FOR EACH ROW BEGIN IF NEW.action='ierb_v14_requirement_accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic acceptance audit failure'; END IF; END");
        try { await accept(key,docs[key],500); } finally { sql('DROP TRIGGER batch2_acceptance_fault'); }
        check(rev()===beforeRevision&&!sql("SELECT id FROM ierb_workflow_events WHERE event_key='requirement_accepted'").length,'RPMS acceptance audit failure rolls back its decision, evidence and revision');
      }
      if (key!=='protocol' && key!=='payment') await accept(key,docs[key]);
    }
    check(state().stage_key==='initial_submission','Eleven accepted requirements cannot advance Stage 1');
    if(process.env.PRISM_TEST_REPORT_SCHEMA14==='1'){
      const privacy=JSON.parse(cp.execFileSync(php,[path.join(root,'maintenance/ierb-report-tests/fixture.php')],{input:JSON.stringify({action:'privacy',documentId:docs.protocol}),encoding:'utf8',windowsHide:true}));
      check(privacy.ok&&privacy.result.includes(sql('SELECT full_name FROM students WHERE id=6')[0].full_name),'Canonical investigator identities remain de-identified even if a legacy group label is stale');
    }
    for(const role of ['adviser','student']) await expect(post(s[role],'accept_requirement',{studentId:4,requirementKey:'protocol',documentId:docs.protocol,revision:rev(),reason:'Synthetic unauthorized acceptance',requestKey:crypto.randomUUID()},'documents_api.php'),403,role+' cannot fabricate RPMS acceptance');
    await accept('protocol',docs.protocol,422,{accepted:true});
    await accept('protocol',docs.protocol,409,{documentId:docs.cv});
    const foreignId=crypto.randomBytes(12).toString('hex');
    sql("INSERT INTO documents(id,student_id,student_name,uploaded_by,uploaded_by_role,original_name,stored_name,mime,size,document_type,stage,review_status,version_no,is_current) VALUES (?,5,'Synthetic Student 5','Synthetic Student 5','student','Synthetic Batch2 foreign.pdf',?,'application/pdf',1,'Study Protocol','Stage 1','Approved',1,1)",[foreignId,foreignId+'.pdf']);
    await accept('protocol',foreignId,409);
    const finalBeforeStage=await upload('final_report');
    check(finalBeforeStage.status===409&&finalBeforeStage.body.uploaded===0
      && !(finalBeforeStage.body.results||[]).some(r=>r.ok)
      && !sql("SELECT id FROM documents WHERE original_name='Synthetic Batch2 final_report.pdf'").length,
      'Stage 1 rejects Final Report upload without creating a document or evidence record');
    const earlyCase=cli({action:'open',revision:rev(),documentId:docs.protocol,amendment:false,key:crypto.randomUUID()});
    check(earlyCase.ok&&earlyCase.result.progress===0&&earlyCase.result.correctionCase.interrupted_stage==='initial_submission','Correction during Initial Submission preserves its zero-percent interrupted context');
    await expect(post(s.admin,'return_for_revision',{id:docs.protocol,reason:'Synthetic initial review requires protocol correction.'},'documents_api.php'),200,'Initial correction uses the existing RPMS return workflow');
    const earlyRevision=await expect(upload('protocol',docs.protocol),200,'Upload corrected Initial Submission protocol');docs.protocol=earlyRevision.body.document.id;
    await review(docs.protocol);await accept('protocol',docs.protocol);
    check(state().stage_key==='resubmission_amendment','All 13 accepted during correction do not create forward progress');
    const earlyResolution=cli({action:'resolve',revision:rev(),key:crypto.randomUUID()});
    check(earlyResolution.ok&&earlyResolution.result.stage==='initial_submission'&&earlyResolution.result.progress===0,'Initial correction resumes Initial Submission before normal advancement');
    // The NEW bulk endpoint must accept the formally submitted Payment Receipt and
    // reconfirm current Study Protocol in the same transaction. The suite checks
    // role denial, atomic rollback, stale version, replay, and revision increments.
    const bulkItems=[{requirementKey:'payment',documentId:docs.payment},{requirementKey:'protocol',documentId:docs.protocol}];
    const bulkBase={studentId:4,items:bulkItems,revision:rev(),reason:'Synthetic RPMS verified two current Initial Submission requirements',requestKey:crypto.randomUUID()};
    const bulkPost=(actor,data)=>post(actor,'accept_requirements_bulk',data,'documents_api.php');
    for(const actor of [s.student,s.adviser])await expect(bulkPost(actor,bulkBase),403,'Only RPMS may bulk-accept document requirements');
    await expect(bulkPost(s.admin,{...bulkBase,items:[bulkItems[0],bulkItems[0]]}),422,'Bulk acceptance rejects duplicate requirement keys');
    await expect(bulkPost(s.admin,{...bulkBase,items:[{requirementKey:'form33',documentId:docs.payment}]}),422,'Bulk acceptance excludes institutional Form 33');
    const revisionBeforeBulk=rev();
    const acceptedBeforeBulk=sql("SELECT COUNT(*) AS n FROM ierb_workflow_events WHERE event_key IN ('requirement_accepted','requirement_reconfirmed')")[0].n;
    sql("CREATE TRIGGER batch2_bulk_fault BEFORE INSERT ON activity_logs FOR EACH ROW BEGIN IF NEW.action='ierb_v14_requirement_accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic bulk acceptance audit failure'; END IF; END");
    try { await expect(bulkPost(s.admin,bulkBase),500,'Injected RPMS bulk acceptance audit failure rejects entire transaction'); }
    finally {sql('DROP TRIGGER batch2_bulk_fault');}
    check(rev()===revisionBeforeBulk
      && sql("SELECT COUNT(*) AS n FROM ierb_workflow_events WHERE event_key IN ('requirement_accepted','requirement_reconfirmed')")[0].n===acceptedBeforeBulk,
      'Failed bulk acceptance rolls back both document events and workflow revision');
    const bulk=await expect(bulkPost(s.admin,bulkBase),200,'RPMS bulk-accepts two current Initial Submission requirements');
    check(bulk.body.acceptedCount===2 && rev()===revisionBeforeBulk+2
      && (await checklist()).body.requirements.find(x=>x.key==='payment').state==='Accepted',
      'Bulk acceptance stores two audited decisions and updates accepted checklist states atomically');
    const bulkReplay=await expect(bulkPost(s.admin,bulkBase),200,'Duplicate bulk request replays committed result');
    check(bulkReplay.body.acceptedCount===2&&rev()===revisionBeforeBulk+2,'Replayed bulk request creates no additional events');
    await expect(bulkPost(s.admin,{...bulkBase,requestKey:crypto.randomUUID()}),409,'Stale bulk revision cannot accept a changed project');
    // Independent connections accepting the last requirement serialize and persist one result.
    const payload={action:'accept',requirementKey:'protocol',documentId:docs.protocol,revision:rev(),key:'batch2-concurrent-acceptance-0001'};
    const hold=worker({action:'hold'});await h.locked(hold);
    const racers=await Promise.all([worker(payload).done,worker(payload).done]);await hold.done;
    const results=racers.map(JSON.parse);check(results.every(r=>r.ok)&&results.some(r=>r.result.replayed),'Concurrent identical RPMS acceptances replay one committed result');
    check(!cli({...payload,key:'batch2-stale-revision-request'}).ok,'Concurrent acceptance with a different request key rejects the stale revision');
    check(!cli({...payload,documentId:docs.cv}).ok,'Acceptance request key cannot be reused for a different document');
    check(state().stage_key==='initial_submission'&&sql("SELECT id FROM ierb_workflow_events WHERE event_key='initial_requirements_accepted'").length===0,'All 13 accepted requirements establish eligibility without automatic advancement');
    if(process.env.PRISM_TEST_PROJECT_GROUP==='1')await projectTests('ready',docs);
    else check(cli({action:'advance'}).ok && state().stage_key==='approval_data_gathering','Authorized RPMS preview and confirmation advance the eligible project once');
    list=await checklist();check(list.body.requirements.filter(r=>r.stage==='initial_submission').every(r=>r.state==='Accepted'&&r.acceptanceEventId),'Every Accepted checklist state has durable version-bound evidence');
    for(const role of ['student','adviser'])check(JSON.stringify((await checklist(s[role])).body.requirements.map(r=>[r.key,r.state,r.versionNo,r.acceptanceEventId]))===JSON.stringify(list.body.requirements.map(r=>[r.key,r.state,r.versionNo,r.acceptanceEventId])),role+' uses identical authoritative checklist states');
    check(!(await checklist(s.student)).body.requirements.some(r=>r.canAccept),'Student checklist has no acceptance controls');
    await expect(upload('final_report'),409,'Data Gathering rejects Final Report upload until Form 33 is accepted');
    const gatheringBefore=(await checklist()).body;
    check(gatheringBefore.uploadTypes.length===1&&gatheringBefore.uploadTypes[0]==='Form 33 - Certificate of Approval',
      'Data Gathering offers only Form 33 while institutional acceptance is missing');
    const approval=await expect(upload('form33'),200,'Upload Form 33 Certificate of Approval');docs.form33=approval.body.document.id;
    await review(docs.form33);await accept('form33',docs.form33,422);
    // Corrections retain their interrupted context and require accepted successor versions.
    for (const amendment of [false,true,true,false]) {
      const opened=cli({action:'open',revision:rev(),documentId:docs.protocol,amendment,key:crypto.randomUUID()});
      check(opened.ok&&opened.result.stage==='resubmission_amendment'&&opened.result.progress===50,'RPMS opens repeated correction; progress remains interrupted 50%, Amendment '+amendment);
      const caseTypes=(await checklist()).body.uploadTypes;
      check(caseTypes.includes('Study Protocol')&&caseTypes.includes('Amendment Form')===amendment
        && !caseTypes.includes('Curriculum Vitae')&&!caseTypes.includes('Form 33-0B - Final Report Form'),
        'Correction stage offers only affected Study Protocol and optional required Amendment Form');
      const caseId=Number(opened.result.correctionCase.id);
      const blocked=cli({action:'resolve',revision:rev(),key:crypto.randomUUID()});check(!blocked.ok&&blocked.type==='AccountLifecycleConflict','Uncorrected affected version blocks resolution');
      await expect(post(s.admin,'return_for_revision',{id:docs.protocol,reason:'Synthetic case requires corrected protocol version.'},'documents_api.php'),200,'RPMS returns exact affected version through existing workflow');
      check((await checklist()).body.requirements.find(r=>r.key==='protocol').state==='Returned for Revision','Returned accepted version immediately loses Accepted display');
      const old=docs.protocol,next=await expect(upload('protocol',old),200,'Student uploads versioned correction');docs.protocol=next.body.document.id;
      check(sql('SELECT is_current FROM documents WHERE id=?',[old])[0].is_current===0,'Previous version and decisions retained when replaced');
      await expect(post(s.adviser,'review',{id:old,status:'Approved',remarks:'Synthetic old version'},'documents_api.php'),409,'Superseded version cannot be reviewed as current');
      await review(docs.protocol);await accept('protocol',docs.protocol);
      if(amendment) {
        check(!cli({action:'resolve',revision:rev(),key:crypto.randomUUID()}).ok,'Mandatory Amendment absence blocks corrected case');
        if (docs.amendment) {
          await accept('amendment',docs.amendment,409);
          await expect(post(s.admin,'return_for_revision',{id:docs.amendment,reason:'Submit an Amendment Form specific to this new correction case.'},'documents_api.php'),200,'A new mandatory case requires a newly submitted Amendment Form version');
        }
        const amendmentDoc=await expect(upload('amendment',docs.amendment||''),200,'Upload conditional Amendment Form');docs.amendment=amendmentDoc.body.document.id;
        await review(docs.amendment);await accept('amendment',docs.amendment);
      } else check(!(await checklist()).body.requirements.some(r=>r.key==='amendment'),'Optional Amendment absence never blocks a case');
      const resolved=cli({action:'resolve',revision:rev(),key:crypto.randomUUID()});
      check(resolved.ok&&resolved.result.stage==='approval_data_gathering'&&resolved.result.progress===50,'Correction resolves from authoritative evidence and resumes interrupted context');
      const stored=sql('SELECT * FROM ierb_correction_cases WHERE id=?',[caseId])[0];check(stored.resolved_at&&stored.resolution_evidence_json,'Correction retains its exact version snapshots, resolution and administrator');
    }
    check(sql('SELECT id FROM ierb_correction_cases').length===5,'Initial plus four repeated gathering corrections retain five distinct histories');
    const supportingUpload=async revisionOf=>response(await s.student.post('/documents_api.php?action=upload',{headers:{origin:base,'X-Prism-Generation':await generation(s.student)},
      multipart:{documentType:'Other Supporting Document',revisionOf,requestId:crypto.randomUUID(),document:{name:'Synthetic Batch2 supporting.pdf',mimeType:'application/pdf',buffer:pdf()}}}));
    await expect(supportingUpload(''),409,'Unrequested supporting evidence is blocked in the active V14 stage');
    // Reconstruct an old supporting document from a pre-V14 record in the disposable
    // fixture. It must stay correctable ONLY through an explicit RPMS case.
    const supportingId=crypto.randomBytes(12).toString('hex');
    const supportingStored=supportingId+'.pdf';
    fs.writeFileSync(path.join(h.app,'storage/documents',supportingStored),pdf());
    sql("INSERT INTO documents(id,student_id,student_name,uploaded_by,uploaded_by_role,original_name,stored_name,mime,size,document_type,stage,review_status,version_no,is_current) VALUES (?,4,'Synthetic Student 4','Synthetic Student 4','student','Synthetic Batch2 supporting.pdf',?,'application/pdf',?,'Other Supporting Document','Stage 3','Submitted',1,1)",[supportingId,supportingStored,pdf().length]);
    await review(supportingId);
    check(cli({action:'open',revision:rev(),documentId:supportingId,amendment:false,key:crypto.randomUUID()}).ok,'RPMS can open a correction for a supporting category');
    await expect(post(s.admin,'return_for_revision',{id:supportingId,reason:'Synthetic supporting attachment requires a corrected version.'},'documents_api.php'),200,'Return the affected supporting version');
    const supportingRevision=await expect(supportingUpload(supportingId),200,'Upload successor in the existing supporting document chain');await review(supportingRevision.body.document.id);
    await accept('affected:'+supportingId,supportingRevision.body.document.id);
    check(cli({action:'resolve',revision:rev(),key:crypto.randomUUID()}).ok,'Supporting-category correction resolves from case-bound current acceptance evidence');
    check(!(await checklist()).body.requirements.some(r=>r.key.startsWith('affected:')),'Supporting-case evidence never becomes an additional permanent mandatory requirement');
    // Multi-file actual multipart upload: several categories plus one invalid per-file type.
    const batchId=crypto.randomUUID();
    const multipart=requestId=>sendBatch(s.admin,5,[['Curriculum Vitae','multi-cv'],['Payment Receipt','multi-payment'],['Fabricated acceptance','invalid-category']],requestId);
    const mixed=await multipart(batchId);check(mixed.status===200&&mixed.body.uploaded===2&&mixed.body.failed===1&&mixed.body.outcome==='partial_success','Mixed-category batch preserves independent per-file success and invalid-type failure');
    check(new Set(mixed.body.results.filter(r=>r.ok).map(r=>r.document.documentType)).size===2,'Each file stores its own validated category');
    const repeat=await multipart(batchId);check(JSON.stringify(repeat.body)===JSON.stringify(mixed.body),'Duplicate mixed-category request replays the original partial result');
    await expect(post(s.admin,'delete',{id:docs.cv,reason:'Synthetic attempt to delete retained V14 acceptance evidence.'},'documents_api.php'),409,'Single delete cannot destroy V14 acceptance evidence');
    const record=(await response(await s.admin.get('/documents_api.php?action=list&perPage=100'))).body.documents.find(d=>d.id===docs.cv);
    const deletion=await expect(post(s.admin,'bulk_delete_preview',{ids:[docs.cv],expectedVersions:{[docs.cv]:record.selectionVersion}},'documents_api.php'),200,'Bulk delete validates retained V14 evidence');check(!deletion.body.canDelete,'Bulk deletion cannot destroy retained V14 history');
    // Real browser controls and responsive shared checklist.
    browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    const errors=[];
    for(const theme of ['light','dark'])for(const width of [390,1440]){
      const ctx=await browser.newContext({viewport:{width,height:1000},storageState:await s.admin.storageState()});await ctx.addInitScript(theme=>localStorage.setItem('prismTheme',theme),theme);
      await ctx.addInitScript(keys=>keys.forEach(key=>localStorage.setItem(key,'seen')),guideKeys);
      await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
      const page=await ctx.newPage();page.on('pageerror',e=>errors.push(String(e)));await page.goto(base+'/documents.php');
      await page.waitForFunction(()=>!!window.PrismRequirements);
      await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
      if(theme==='light'&&width===390){
        await page.locator('#viewProjectRequirements').click();
        await page.locator('#requirementStudent').selectOption('4');
        await page.getByRole('button',{name:'Open checklist'}).click();
        check(true,'Staff checklist picker is available independently of document rows');
      }else await page.evaluate(()=>PrismRequirements.open(4));
      await page.getByRole('heading',{name:'Research project requirements'}).waitFor();
      if(theme==='light'&&width===390){
        const approvalRow=page.locator('.v14-requirements li').filter({hasText:'Form 33 - Certificate of Approval'});
        await approvalRow.getByRole('button',{name:'Accept requirement'}).click();
        await page.locator('#institutionalApprovalReference').fill('Synthetic institutional approval resolution IERB-TEST-33');
        await page.locator('#prismReason').fill('Synthetic RPMS accepts verified institutional approval');
        await page.getByRole('button',{name:'Accept current version'}).click();
        await page.waitForFunction(()=>[...document.querySelectorAll('.v14-requirements li')].some(li=>li.textContent.includes('Form 33 - Certificate of Approval: Accepted')));
        check((await checklist()).body.requirements.find(r=>r.key==='form33').state==='Accepted','RPMS browser acceptance records Form 33 with its institutional approval reference');
      }
      check(await page.locator('.v14-requirements li').count()===15,`${width} ${theme}: backend checklist renders 13 initial requirements, Form 33 and Final Report`);
      check(await page.locator('.v14-checklist-dialog').evaluate(d=>d.scrollWidth<=d.clientWidth+2),`${width} ${theme}: checklist remains within viewport`);
      await page.screenshot({path:path.join(evidence,`checklist-${width}-${theme}.png`)});await ctx.close();
    }
    const studentCtx=await browser.newContext({storageState:await s.student.storageState()});
    await studentCtx.addInitScript(keys=>keys.forEach(key=>localStorage.setItem(key,'seen')),guideKeys);
    const page=await studentCtx.newPage();page.on('pageerror',e=>errors.push(String(e)));
    await page.goto(base+'/role_portal.php#submit');
    await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
    await page.locator('#documentFile').setInputFiles([{name:'final-report.pdf',mimeType:'application/pdf',buffer:pdf()}]);
    const availableTypes=await page.locator('[data-upload-type]').first().locator('option').evaluateAll(options=>options.map(o=>o.value).filter(Boolean));
    check(availableTypes.length===1&&availableTypes[0]==='Form 33-0B - Final Report Form',
      'Data Gathering Student dropdown offers only the Final Report after Form 33 approval');
    check(!(await page.locator('[data-upload-type]').first().locator('option').evaluateAll(options=>options.some(o=>o.value==='Curriculum Vitae'||o.value==='Payment Receipt'))),
      'Initial Submission document types are absent from the Data Gathering dropdown');
    await page.locator('[data-upload-type]').first().selectOption('Form 33-0B - Final Report Form');
    await page.locator('#documentNotes').fill('Retain the current-stage document selection while editing notes');
    check(await page.locator('[data-upload-type]').first().inputValue()==='Form 33-0B - Final Report Form',
      'Final Report selection survives notes rerender without offering earlier-stage types');
    await page.locator('#documentFile').setInputFiles([{name:'redundant-report.pdf',mimeType:'application/pdf',buffer:pdf()}]);
    check(await page.locator('[data-upload-type]').count()===2,
      'V14 file queue supports multiple selections before category validation');
    await page.locator('[data-upload-type]').nth(1).selectOption('Form 33-0B - Final Report Form');
    check(await page.locator('#submissionForm [type=submit]').isDisabled()
      && (await page.locator('#submissionSubmitHint').textContent()).includes('only appear once'),
      'V14 duplicate required document types cannot be submitted together');
    await page.getByRole('button',{name:'Remove redundant-report.pdf',exact:true}).click();
    check(await page.locator('#submissionForm [type=submit]').isEnabled(),
      'Removing a duplicate V14 requirement restores valid upload readiness');
    check((await page.locator('#currentStage').textContent())==='Approval of Data Gathering','Student official stage uses its explicit V14 summary');
    check((await page.locator('.stage-card').nth(1).textContent()).includes('Conditional'),'Student view never presents conditional Stage 2 as a completed progress milestone');
    await studentCtx.close();
    const final=await expect(upload('final_report'),200,'Final Report is uploadable after RPMS Form 33 acceptance');
    docs.final_report=final.body.document.id;
    await require('../ierb-project-tests/uploads.cjs')({...h,browser,pdf,guideKeys});
    check(!errors.length,'New browser checklist and per-file category controls have no JavaScript errors');
    await projectTests('beforeCompletion',docs);
    // Fault injection must roll back approval, formal submission, stage, history and queued notices.
    const before=()=>JSON.stringify({workflow:state(),doc:sql('SELECT * FROM documents WHERE id=?',[docs.final_report]),events:sql('SELECT * FROM ierb_workflow_events'),notices:sql('SELECT COUNT(*) n FROM notifications'),history:sql('SELECT COUNT(*) n FROM ierb_history')});
    for(const [trigger,table,condition] of [['batch2_completion_fault','ierb_workflow_events',"NEW.event_key='final_report_submitted'"],['batch2_submission_fault','activity_logs',"NEW.action='document_submitted_to_rpms'"]]){
      const snapshot=before();sql(`CREATE TRIGGER ${trigger} BEFORE INSERT ON ${table} FOR EACH ROW BEGIN IF ${condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic Batch2 transaction failure'; END IF; END`);
      try{await expect(post(s.adviser,'review',{id:docs.final_report,status:'Approved',remarks:'Synthetic Batch2 Adviser approval'},'documents_api.php'),500,'Injected '+trigger+' rejects submission');}finally{sql('DROP TRIGGER '+trigger);}
      check(before()===snapshot,'Atomic rollback restores Final Report, workflow, events, history and notices after '+trigger);
    }
    // Notification provider failure occurs only after the durable decision.
    fs.writeFileSync(path.join(h.review,'sandbox/provider-scenario.txt'),'failure');
    const holder=worker({action:'hold'});await h.locked(holder);
    const submitted=await Promise.all([worker({action:'review',actor:2,documentId:docs.final_report,providerFailure:true}).done,worker({action:'review',actor:2,documentId:docs.final_report,providerFailure:true}).done]);await holder.done;
    check(submitted.map(JSON.parse).every(r=>r.ok),'Concurrent Final Report approval requests succeed once and replay safely');
    check(submitted.map(JSON.parse).some(r=>r.result.providerAttempts>0),'Notification provider failure is exercised after successful completion commits');
    check(state().stage_key==='ierb_completed'&&sql("SELECT id FROM ierb_workflow_events WHERE event_key='final_report_submitted'").length===1,'Successful authorized formal Final Report submission automatically completes exactly once');
    check((await checklist()).body.uploadTypes.length===0,
      'Completed V14 projects provide a read-only checklist with no available new upload types');
    await expect(upload('ethics'),409,'Completed V14 project blocks new Stage 1 uploads');
    check(!!sql('SELECT rpms_submitted_at FROM documents WHERE id=?',[docs.final_report])[0].rpms_submitted_at,'Completion retains successful formal-submission evidence');
    check(!sql('SELECT id FROM ierb_workflows WHERE student_id=5').length,'Automatic completion never declares the unenrolled partner completed');
    await review(docs.final_report);check(sql("SELECT id FROM ierb_workflow_events WHERE event_key='final_report_submitted'").length===1,'HTTP retry after successful completion creates no extra completion event');
    await expect(post(s.admin,'return_for_revision',{id:docs.form33,reason:'Synthetic attempt to invalidate terminal completion evidence.'},'documents_api.php'),409,'Terminal completion retains its accepted earlier-stage evidence');
    const legacy=sql('SELECT stage FROM students WHERE id=4')[0];check(legacy.stage!=='Completed','V14 completion preserves the legacy stage snapshot and separation');
    check(!sql("SELECT id FROM ierb_workflow_events WHERE event_key='requirement_accepted' AND JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.requirementKey'))='final_report'").length,'Final Report completion requires no additional RPMS acceptance event');
    await projectTests('completed',docs);
    if(process.env.PRISM_TEST_REPORT_SCHEMA14==='1'){
      const r=JSON.parse(cp.execFileSync(php,[path.join(root,'maintenance/ierb-report-tests/fixture.php')],{input:JSON.stringify({action:'completed-evidence'}),encoding:'utf8',windowsHide:true}));
      check(r.ok&&r.result.completed&&r.result.progress===100&&r.result.trackingRows===4,'Schema14 completed project report matches actual automatic Final Report completion and fixed tracking rows');
    }
    if(process.env.PRISM_TEST_MONITOR_SCHEMA15==='1'){
      const monitor=await expect(s.admin.get('/ierb_api.php?action=monitor').then(response),200,'Completed schema15 canonical project is visible in read-only monitor');
      const r=monitor.body.records.find(r=>r.projectId);
      const filtered=await expect(s.admin.get('/ierb_api.php?action=monitor&status=IERB%20Completed').then(response),200,'Completed semantic status is available as a server-side filter');
      check(filtered.body.total===1&&filtered.body.records[0].key===r.key,'Completed status filtering matches the displayed record status and exact count');
      check(r.progress===100&&r.stage==='ierb_completed'&&r.initialAcceptedCount===13,'Monitor shows authoritative 100% automatic completion and thirteen accepted Initial requirements');
      check(r.requirements.find(x=>x.key==='form33').state==='Accepted'&&r.requirements.find(x=>x.key==='final_report').state==='Formally Submitted to RPMS','Monitor separates accepted institutional Form 33 from formally submitted Final Report');
      check(!r.pending.length,'Completed project has no missing mandatory requirements');
      const history=await expect(s.admin.get('/ierb_api.php?action=monitor_detail&keys[]='+r.key).then(response),200,'Completed project history remains accessible');
      check(history.body.records[0].history.events.some(x=>x.event_type==='IERB completion'),'History shows recorded IERB completion timestamp');
      fs.writeFileSync(path.join(evidence,'schema15-completed-monitor.json'),JSON.stringify({record:r,history:history.body.records[0].history},null,2));
    }
    const completion=sql("SELECT workflow_id,revision,event_key,from_stage,to_stage,actor_user_id,evidence_json,request_key,created_at FROM ierb_workflow_events WHERE event_key='final_report_submitted'")[0];
    completion.evidence=JSON.parse(completion.evidence_json);delete completion.evidence_json;
    fs.writeFileSync(path.join(evidence,'final-report-completion.json'),JSON.stringify({scope:'Synthetic fixture only',workflow:state(),
      document:sql('SELECT id,student_id,document_type,version_no,is_current,review_status,reviewed_at,rpms_submitted_at FROM documents WHERE id=?',[docs.final_report])[0],
      completionEvent:completion,completionEventCount:1,additionalRpmsAcceptanceEvents:0,unenrolledPartnerCompleted:false,
      concurrentResults:submitted.map(JSON.parse)},null,2)+'\n');
    fs.writeFileSync(path.join(evidence,'document-tests.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start),scope:'Datadir-verified isolated MariaDB; real PHP HTTP sessions and Chromium; no external delivery'},null,2)+'\n');
  } finally {
    if(browser)await browser.close();fs.writeFileSync(path.join(h.review,'sandbox/provider-scenario.txt'),'success');
    if(process.env.PRISM_TEST_MONITOR_SCHEMA15==='1')cp.execFileSync(php,[path.join(root,'maintenance/ierb-monitor-tests/fixture.php')],{input:JSON.stringify({action:'cleanup',only15:true}),encoding:'utf8',windowsHide:true});
    if(process.env.PRISM_TEST_REPORT_SCHEMA14==='1')cp.execFileSync(php,[path.join(root,'maintenance/ierb-report-tests/fixture.php')],{input:JSON.stringify({action:'cleanup'}),encoding:'utf8',windowsHide:true});
    await projectTests('cleanup');
    check(cli({action:'cleanup'}).ok,'Remove only explicit Batch2 synthetic workflows and document rows after evidence capture');
  }
};
