const cp=require('node:child_process'),fs=require('node:fs'),path=require('node:path');
module.exports=h=>{
  const {sql,check,post,response,expect,sessions:s,cli,state,rev}=h;
  const helper=path.join(h.root,'maintenance/ierb-project-tests/fixture.php');
  const project=data=>JSON.parse(cp.execFileSync(h.php,[helper],{input:JSON.stringify(data),encoding:'utf8',windowsHide:true}));
  const mapping=ids=>project({action:'mapping',ids}).result;
  const p=()=>sql('SELECT * FROM research_projects WHERE submission_holder_id=4')[0];
  const key=()=>require('node:crypto').createHash('sha256').update('project:'+p().id).digest('hex');
  const payload=preview=>({confirmationToken:preview.confirmationToken,reason:'Synthetic RPMS reviewed all accepted project evidence.',confirmed:true});
  const preview=async()=> (await expect(post(s.admin,'group_advance_preview',{groupKey:key()}),200,'Canonical Admin group preview is available')).body;
  const snapshot=()=>{const result=project({action:'snapshot'});if(!result.ok)throw Error(JSON.stringify(result));return result.result;};
  const evidence=path.join(h.root,'PRISM_V14_BATCH_03_EVIDENCE');let partner,docBefore,noticeFloor=0;const ownCases=[];
  return async(phase,docs={})=>{
    const phaseStart=h.checks.length;
    if(phase==='migration'){
      const m=project({action:'migration-tests'});check(m.ok&&m.result.status==='PASS','Dedicated canonical migration suite passes: '+JSON.stringify(m));
      h.checks.push(...m.result.cases);fs.writeFileSync(path.join(evidence,'migration-tests.json'),JSON.stringify(m.result,null,2));
      check(project({action:'migrate'}).ok,'Apply schema 13 to the datadir-verified fixture');
      sql("UPDATE students SET research_group='AMT-BSIT-Y4-2627-G97',adviser_id=2 WHERE id IN (4,6)");
      const partial=project({action:'create',title:'PRISM: RPMS Workload Assistant',holderId:4,adviserId:2,mapping:mapping([4])});
      check(!partial.ok&&partial.message.includes('omits'),'Migration refuses a mapping that omits an associated legacy member');
      const old=mapping([4,6]);sql("UPDATE students SET research_title=CONCAT(COALESCE(research_title,''),' changed') WHERE id=6");
      check(!project({action:'create',title:'PRISM: RPMS Workload Assistant',holderId:4,adviserId:2,mapping:old}).ok,'Stale member snapshots cannot create a canonical project');
      sql('UPDATE students SET adviser_id=3 WHERE id=6');
      check(!project({action:'create',title:'PRISM: RPMS Workload Assistant',holderId:4,adviserId:2,mapping:mapping([4,6])}).ok,'Inconsistent Adviser assignments require explicit administrative reconciliation');
      sql('UPDATE students SET adviser_id=2 WHERE id=6');
      for(const actor of [2,4])check(!project({action:'create',actor,title:'Unauthorized title',holderId:4,adviserId:2,mapping:mapping([4,6])}).ok,'Non-Admin actor '+actor+' cannot reconcile canonical membership');
    }
    if(phase==='setup'){
      noticeFloor=Number(sql('SELECT COALESCE(MAX(id),0) id FROM notifications')[0].id);
      check(p().public_identifier==='prism-rpms-workload-assistant','Canonical public identifier derives from the research title');
      const members=sql('SELECT * FROM research_project_members WHERE project_id=? ORDER BY student_id',[p().id]);
      check(members.map(m=>m.student_id).join(',')==='4,6','Explicit mapping creates exactly the verified project roster');
      check(members.every(m=>JSON.parse(m.legacy_identity_json).research_group==='AMT-BSIT-Y4-2627-G97'),'Membership mapping retains every previous generated group identifier');
      check(!project({action:'migrate',mode:'rollback'}).ok,'Retained canonical membership prevents destructive rollback');
      // A separate, explicitly enrolled project with the same title must remain separate.
      sql("INSERT IGNORE INTO users(id,username,full_name,email,role,ref_id,password_hash,status,created_at) SELECT 7,'S7','Synthetic Student 7','student7@example.test','student','S7',password_hash,'Active',created_at FROM users WHERE id=4");
      sql("INSERT IGNORE INTO students(id,user_id,student_id,full_name,email,adviser_id,stage,status,research_group,research_title,course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at) SELECT 7,7,'S7','Synthetic Student 7','student7@example.test',2,'Stage 1','Pending',NULL,'PRISM: RPMS Workload Assistant',course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at FROM students WHERE id=4");
      sql('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT 7,policy_version_id FROM user_policy_acceptances WHERE user_id=4');
      check(cli({action:'enroll',studentId:7}).ok,'Duplicate-title project has its own explicit enrollment');
      const other=project({action:'create',title:p().research_title,holderId:7,adviserId:2,mapping:mapping([7])});
      check(other.ok&&other.result.identifier==='prism-rpms-workload-assistant-2'&&other.result.projectId!==p().id,'Duplicate research titles receive collision-safe identifiers without merging membership');
      const before=sql('SELECT id,student_id,adviser_id,stage,status,academic_unit_key,program_key,year_level,academic_year,protocol_code FROM students WHERE id IN (4,6) ORDER BY id');
      const id=p().id,previousRevision=p().revision;
      check(project({action:'rename',projectId:id,revision:previousRevision,title:'PRISM: Revised Workload Assistant'}).ok,'Authorized research title update succeeds');
      check(p().id===id&&sql('SELECT * FROM research_project_identifiers WHERE project_id=?',[id]).length===2,'Title updates preserve the immutable key and previous identifier aliases');
      check(!project({action:'rename',projectId:id,revision:previousRevision,title:'Stale title'}).ok,'Stale title updates are rejected');
      check(!project({action:'rename',actor:2,projectId:id,revision:p().revision,title:'Unauthorized Adviser title'}).ok,'Assigned Adviser cannot bypass title administration');
      check(project({action:'rename',projectId:id,revision:p().revision,title:'PRISM: RPMS Workload Assistant'}).ok,'A project can reuse its own reserved historical identifier');
      check(JSON.stringify(before)===JSON.stringify(sql('SELECT id,student_id,adviser_id,stage,status,academic_unit_key,program_key,year_level,academic_year,protocol_code FROM students WHERE id IN (4,6) ORDER BY id')),'Title changes preserve identities, academic records, protocol codes and legacy progress');
      check(project({action:'adviser',projectId:id,revision:p().revision,adviserId:3}).ok&&sql('SELECT adviser_id FROM students WHERE id IN (4,6)').every(r=>r.adviser_id===3),'Explicit project Adviser reassignment updates every member atomically');
      check(project({action:'adviser',projectId:id,revision:p().revision,adviserId:2}).ok,'Authorized Adviser restoration retains assignment history');
      partner=await h.login('student6@example.test');
      const own=await response(await partner.get('/documents_api.php?action=requirements&studentId=6'));
      check(own.status===200&&own.body.enrolled&&own.body.submissionScope.studentId===4&&own.body.requirements.filter(r=>r.stage==='initial_submission').length===13,'A canonical partner reads one project checklist of 13 requirements through their own identity');
      check(!cli({action:'enroll',studentId:6}).ok&&!sql('SELECT id FROM ierb_workflows WHERE student_id=6').length,'Project membership cannot create a parallel requirement ledger for each member');
      await expect(response(await partner.get('/documents_api.php?action=requirements&studentId=4')),403,'Membership grants no direct access to another Student endpoint');
      await expect(response(await s.student.get('/documents_api.php?action=requirements&studentId=7')),403,'Same-title separate projects remain isolated');
      const blocked=await preview();check(!blocked.eligible&&blocked.reasons.filter(r=>r.includes('RPMS acceptance')).length===13,'Administrator preview lists every missing mandatory acceptance');
      docBefore=sql('SELECT COUNT(*) n FROM documents WHERE student_id=6')[0].n;
    }
    if(phase==='ready'){
      check(state().stage_key==='initial_submission','Accepting all 13 requirements creates eligibility, with no automatic project advancement');
      const own=(await response(await partner.get('/documents_api.php?action=requirements&studentId=6'))).body;
      check(own.requirements.filter(r=>r.stage==='initial_submission').every(r=>r.state==='Accepted'&&r.documentId===null),'Partner sees shared accepted status without access to holder private document references');
      await expect(response(await partner.get('/documents_api.php?action=file&download=1&id='+docs.cv)),403,'Canonical membership never exposes another Student private file');
      await expect(response(await partner.get('/documents_api.php?action=versions&id='+docs.cv)),403,'Private document version history remains owner scoped');
      for(const role of ['student','adviser'])await expect(post(s[role],'group_advance_preview',{groupKey:key()}),403,role+' cannot preview or advance a canonical research group');
      const stale=await preview();check(stale.eligible,'All 13 current accepted requirements make the canonical group eligible');
      check(project({action:'rename',projectId:p().id,revision:p().revision,title:'PRISM: Administrator Updated Title'}).ok,'Title update retains project evidence while invalidating a previous preview');
      const before=snapshot();await expect(post(s.admin,'group_advance',payload(stale)),409,'Canonical title change rejects the stale session-bound confirmation');check(snapshot()===before,'Stale canonical preview changes no progress, histories or notices');
      let fresh=await preview();
      sql("CREATE TRIGGER batch3_advance_fault BEFORE INSERT ON research_project_member_history FOR EACH ROW BEGIN IF NEW.to_stage='approval_data_gathering' AND NEW.student_id=6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic member progress fault'; END IF; END");
      const beforeFault=snapshot();try{await expect(post(s.admin,'group_advance',payload(fresh)),500,'A member history failure rejects the entire canonical advancement');}finally{sql('DROP TRIGGER batch3_advance_fault');}
      check(snapshot()===beforeFault,'Failed group advancement rolls back every member, authoritative event, audit and queued notice');
      // Independent database connections race two separately previewed confirmations.
      const first=h.fixture({action:'prepare',key:key()}),second=h.fixture({action:'prepare',key:key()});
      const hold=h.child({action:'hold',sql:'SELECT id FROM students WHERE id=4 FOR UPDATE'});await h.locked(hold);
      const results=await Promise.all([h.child({action:'confirm',session:first.session,data:payload(first.preview)}).done,h.child({action:'confirm',session:second.session,data:payload(second.preview)}).done]);await hold.done;
      const parsed=results.map(JSON.parse);check(parsed.filter(r=>r.status===200).length===1&&parsed.filter(r=>r.status===409).length===1,'Concurrent canonical group confirmations commit once and reject the stale competitor: '+JSON.stringify(parsed.map(r=>r.status)));
      check(state().stage_key==='approval_data_gathering'&&sql('SELECT stage_key FROM research_project_members WHERE project_id=?',[p().id]).every(r=>r.stage_key==='approval_data_gathering'),'All canonical members reflect the authorized normal advancement consistently');
      const after=await preview();check(!after.eligible&&after.reasons.some(r=>r.includes('automatically')),'Normal group advancement cannot manually complete the Final Report stage');
      fs.writeFileSync(path.join(evidence,'group-advancement.json'),JSON.stringify({concurrentResults:parsed,memberProgress:sql('SELECT * FROM research_project_members WHERE project_id=?',[p().id])},null,2));
    }
    if(phase==='beforeCompletion'){
      const attempt=await response(await partner.post('/documents_api.php?action=upload',{headers:{origin:h.base,'X-Prism-Generation':await h.generation(partner)},multipart:{documentType:'Form 33-0B - Final Report Form',requestId:require('node:crypto').randomUUID(),document:{name:'Synthetic Batch2 partner-private-final.pdf',mimeType:'application/pdf',buffer:h.pdf()}}}));
      check(attempt.status===200,'Partner retains ownership of their independently uploaded private evidence');
      const privateId=attempt.body.document.id;
      await expect(post(s.adviser,'review',{id:privateId,status:'Approved',remarks:'Synthetic attempt to submit outside holder authority'},'documents_api.php'),409,'Only the designated submission holder can formally submit the project Final Report');
      check(!sql('SELECT rpms_submitted_at FROM documents WHERE id=?',[privateId])[0].rpms_submitted_at,'Unauthorized partner Final Report formal submission rolls back without partial completion');
      docBefore=sql('SELECT COUNT(*) n FROM documents WHERE student_id=6')[0].n;
      check(sql('SELECT stage_key FROM research_project_members WHERE project_id=?',[p().id]).every(r=>r.stage_key==='approval_data_gathering'),'Repeated conditional correction cases resume every member at the interrupted review stage');
      const corrections=sql("SELECT e.event_key,h.student_id,h.from_stage,h.to_stage FROM research_project_member_history h JOIN research_project_events e ON e.id=h.project_event_id WHERE h.project_id=? AND e.event_key IN ('open_correction','resolve_correction')",[p().id]);
      check(corrections.some(r=>r.student_id===6&&r.to_stage==='resubmission_amendment')&&corrections.some(r=>r.student_id===6&&r.from_stage==='resubmission_amendment'&&r.to_stage==='approval_data_gathering'),'Canonical member histories preserve individual correction interruption and return events');
      sql("CREATE TRIGGER batch3_completion_fault BEFORE INSERT ON research_project_member_history FOR EACH ROW BEGIN IF NEW.to_stage='ierb_completed' AND NEW.student_id=6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic completion fault'; END IF; END");
      const before=snapshot();try{await expect(post(s.adviser,'review',{id:docs.final_report,status:'Approved',remarks:'Synthetic Batch2 Adviser approval'},'documents_api.php'),500,'Failure completing the second member rolls back formal Final Report submission');}finally{sql('DROP TRIGGER batch3_completion_fault');}
      check(snapshot()===before,'Completion rollback preserves every document stamp, workflow, member, history, audit and notification atomically');
    }
    if(phase==='completed'){
      check(!!p().completed_at&&p().completion_document_id===docs.final_report,'Successful formal Final Report submission records canonical project completion exactly once');
      const members=sql('SELECT * FROM research_project_members WHERE project_id=?',[p().id]);
      check(members.length===2&&members.every(m=>m.stage_key==='ierb_completed'&&Number(m.workflow_revision)===rev()),'Every authoritative member progress record consistently reflects IERB Completed');
      const histories=sql("SELECT * FROM research_project_member_history WHERE project_id=? AND to_stage='ierb_completed'",[p().id]);
      check(histories.length===2&&histories.some(r=>r.student_id===4)&&histories.some(r=>r.student_id===6),'Completion retains one individual history entry for each canonical member');
      check(sql("SELECT * FROM research_project_events WHERE project_id=? AND event_key='final_report_submitted'",[p().id]).length===1,'Canonical project completion and its audit are recorded once across concurrent retries');
      check(sql('SELECT stage_key FROM ierb_workflows WHERE student_id=7')[0].stage_key==='initial_submission','An unrelated project with the same title remains uncompleted');
      check(sql('SELECT COUNT(*) n FROM documents WHERE student_id=6')[0].n===docBefore,'Project completion duplicates neither document evidence nor private files to partners');
      check(sql("SELECT id FROM notifications WHERE id>? AND recipient_type='student' AND recipient_id=6 AND message LIKE '%IERB Completed%'",[noticeFloor]).length===1,'Exactly one accurate project-completion notification is queued for the partner');
      check(sql("SELECT id FROM notifications WHERE id>? AND recipient_type='adviser' AND recipient_id=2 AND message LIKE '%IERB Completed%'",[noticeFloor]).length===1,'The assigned Adviser receives one project-wide completion notification');
      const own=(await response(await partner.get('/documents_api.php?action=requirements&studentId=6'))).body;
      check(own.workflow.stage==='ierb_completed'&&own.workflow.progress===100,'Partner checklist reflects automatic completion with no additional acceptance');
      const data=await response(await partner.get('/ierb_api.php?action=list'));check(data.body.records?.[0]?.v14?.stage==='ierb_completed'||data.body.students?.[0]?.v14?.stage==='ierb_completed','Partner progress API reads the canonical completed workflow');
      const timeline=(await response(await partner.get('/ierb_api.php?action=history&studentId=6&perPage=100'))).body;
      check(timeline.history.some(r=>r.stage==='ierb_completed'&&r.stageLabel==='IERB Completed')&&timeline.history.some(r=>r.stage.startsWith('Stage ')),'Authorized partner history combines canonical completion with preserved legacy observations');
      await expect(response(await partner.get('/ierb_api.php?action=history&studentId=7')),403,'Same-title projects grant no access to another Student history');
      const browser=await h.playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
      try{
        const staffCtx=await browser.newContext({viewport:{width:1440,height:1050},storageState:await s.admin.storageState()});
        await staffCtx.addInitScript(key=>localStorage.setItem(key,'seen'),'prismTour.admin.'+require('node:crypto').createHash('sha256').update('admin|1').digest('hex')+'.v1');
        await staffCtx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
        const staffPage=await staffCtx.newPage();await staffPage.goto(h.base+'/ierbprog.php');
        await staffPage.locator('#ierbSearch').fill('Synthetic Student 6');
        const memberRow=staffPage.locator('#ierbTableBody tr').filter({hasText:'Synthetic Student 6'});await memberRow.locator('.stage-tag').waitFor();
        check((await memberRow.locator('.stage-tag').textContent())==='IERB Completed','RPMS list shows canonical member completion while retaining stored legacy stages');
        check(await memberRow.locator('[role=progressbar]').getAttribute('aria-valuenow')==='100','RPMS member progress displays canonical 100 percent completion');
        check(await memberRow.locator('button[aria-label="Advance to Next Stage"]').isDisabled(),'RPMS list directs canonical projects to validated Group Advancement');
        check(await memberRow.locator('.ierb-stage-sequence li').count()===4,'RPMS canonical member journey uses exactly the four V14 stages');
        check(!(await memberRow.locator('.ierb-stage-sequence li[title="resubmission_amendment"]').evaluate(n=>n.classList.contains('is-complete'))),'Conditional correction is not a completed forward milestone in RPMS member history');
        await staffPage.screenshot({path:path.join(evidence,'admin-member-completed.png'),fullPage:true});await staffCtx.close();
        for(const theme of ['light','dark'])for(const width of [390,1440]){
          const ctx=await browser.newContext({viewport:{width,height:1050},storageState:await partner.storageState()});
          const identity=require('node:crypto').createHash('sha256').update('Student|S6').digest('hex');
          await ctx.addInitScript(({theme,identity})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(`prismTour.student.${identity}.v1`,'seen');},{theme,identity});
          await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
          const page=await ctx.newPage();await page.goto(h.base+'/role_portal.php#documents');
          await page.waitForFunction(()=>document.querySelector('#v14RequirementChecklist')?.textContent.includes('IERB Completed'));
          await page.locator('#v14RequirementChecklist').getByRole('button',{name:'Refresh requirements'}).click();
          await page.waitForFunction(()=>document.querySelector('#v14RequirementChecklist')?.textContent.includes('IERB Completed'));
          check((await page.locator('#v14RequirementChecklist .v14-requirements li').count())===15,`${width} ${theme}: partner browser refresh preserves its scoped shared checklist`);
          check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),`${width} ${theme}: canonical partner checklist remains within the viewport`);
          await page.screenshot({path:path.join(evidence,`member-completed-${width}-${theme}.png`),fullPage:true});await ctx.close();
        }
      }finally{await browser.close();}
      fs.writeFileSync(path.join(evidence,'group-completion.json'),JSON.stringify({project:p(),members,individualHistories:histories,
        events:sql('SELECT * FROM research_project_events WHERE project_id=?',[p().id]),privateFilesDuplicated:false},null,2));
      check(project({action:'cleanup'}).ok,'Dispose canonical fixture associations before explicit historical migration verification');
      const historical={action:'create',title:'PRISM Verified Historical Project',holderId:4,adviserId:2,mapping:mapping([4,6])};
      check(!project(historical).ok,'Historical completion cannot be inherited without explicit administrative attestation');
      const restored=project({...historical,historicalCompletionReference:'Synthetic institutional reconciliation of previously completed project'});
      check(restored.ok&&!!p().completed_at&&p().completion_document_id===docs.final_report,'Verified historical project mapping retains its original Final Report completion evidence: '+JSON.stringify(restored));
      check(sql("SELECT id FROM ierb_workflow_events WHERE event_key='final_report_submitted'").length===1,'Historical reconciliation creates no duplicate formal submission or completion event');
    }
    if(phase==='cleanup'){
      if(sql("SELECT v FROM schema_meta WHERE k='schema_version'")[0].v==='13')check(project({action:'cleanup'}).ok,'Dispose only the explicit synthetic canonical project records');
      for(const w of sql('SELECT id FROM ierb_workflows WHERE student_id=7')){
        sql('DELETE FROM ierb_workflow_events WHERE workflow_id=?',[w.id]);sql('DELETE FROM ierb_correction_cases WHERE workflow_id=?',[w.id]);sql('DELETE FROM ierb_workflows WHERE id=?',[w.id]);
      }
    }
    ownCases.push(...h.checks.slice(phaseStart));
    if(phase==='completed')fs.writeFileSync(path.join(evidence,'project-tests.json'),JSON.stringify({status:'PASS',assertions:ownCases.length,cases:ownCases},null,2));
  };
};
