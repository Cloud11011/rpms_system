const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
module.exports=async h=>{
  const {root,php,post,response,expect,check,sessions:s,playwright,base}=h;
  const sql=(...args)=>{const value=h.sql(...args);if(value.status>=400)throw Error('Fixture SQL failed: '+JSON.stringify(value));return value;};
  const evidence=path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR||path.join(root,'PRISM_V14_BATCH_05_EVIDENCE'));fs.mkdirSync(evidence,{recursive:true});
  const start=h.checks.length,helper=path.join(__dirname,'fixture.php');
  const cli=data=>{const r=JSON.parse(cp.execFileSync(php,[helper],{input:JSON.stringify(data),encoding:'utf8',windowsHide:true}));if(!r.ok)throw Error(JSON.stringify(r));return r.result;};
  const reportCli=data=>JSON.parse(cp.execFileSync(php,[path.join(root,'maintenance/ierb-report-tests/fixture.php')],{input:JSON.stringify(data),encoding:'utf8',windowsHide:true})).result;
  const read=async query=>expect(s.admin.get('/ierb_api.php?'+new URLSearchParams({action:'monitor',perPage:'100',...query})).then(response),200,'Authoritative monitoring query '+JSON.stringify(query));
  let browser;
  const historical=sql('SELECT academic_year FROM students WHERE id=5')[0].academic_year;
  try {
    sql("UPDATE students SET academic_year='2001-2002' WHERE id=5");
    const install=cli({action:'install'});check(install.dryRunUnchanged&&install.studentsPreserved&&install.catalog.includes('2001-2002'),'Additive schema 15 dry run and migration preserve all Student data and historical years');
    if(process.env.PRISM_TEST_SKIP_BATCH5_MATRIX!=='1') for(const label of cli({action:'matrix'}))check(true,label);
    fs.writeFileSync(path.join(evidence,'academic-migration.json'),JSON.stringify({status:'PASS',install},null,2));
    const anonymous=await playwright.request.newContext({baseURL:base});
    try{await expect(anonymous.get('/ierb_api.php?action=monitor').then(response),401,'Anonymous monitoring read is denied');}finally{await anonymous.dispose();}
    await expect(s.student.get('/ierb_api.php?action=monitor').then(response),403,'Student cannot access staff monitoring or another Student checklist');
    for(const role of ['student','adviser']){
      await expect(s[role].get('/academic_years_api.php').then(response),403,role+' cannot manage academic-year catalog');
      await expect(post(s[role],'add',{academicYear:'2028-2029'},'academic_years_api.php'),403,role+' cannot add academic years');
      await expect(s[role].get('/ierb_api.php?action=monitor_compare&keys[]=student:4').then(response),403,role+' cannot compare private progress records');
    }
    await expect(s.admin.get('/academic_years_api.php?action=add').then(response),405,'Academic-year creation requires POST');
    await expect(s.admin.post('/academic_years_api.php?action=add',{data:{academicYear:'2028-2029'},headers:{origin:'https://elsewhere.test'}}).then(response),403,'Cross-origin academic-year creation denied');
    await expect(s.admin.post('/academic_years_api.php?action=add',{data:{academicYear:'2028-2029'},headers:{origin:base}}).then(response),409,'Missing current-session generation denied');
    for(const value of ['',null,42,[],{},'2028-2030','28-29','2028/2029',' 2028-2029','2028-2029 ','9999-10000'])await expect(post(s.admin,'add',{academicYear:value},'academic_years_api.php'),422,'Invalid academic-year input rejected: '+JSON.stringify(value));
    const assignments=JSON.stringify(sql('SELECT id,academic_year FROM students ORDER BY id'));
    const added=await expect(post(s.admin,'add',{academicYear:'2028-2029'},'academic_years_api.php'),200,'Authorized Admin creates valid consecutive academic year');
    check(added.body.academicYears.includes('2028-2029')&&cli({action:'catalog'}).academicYears.includes('2028-2029'),'New academic year reaches the single server catalog without source-code updates');
    check(JSON.stringify(sql('SELECT id,academic_year FROM students ORDER BY id'))===assignments,'New academic year never promotes or rewrites a Student');
    await expect(post(s.admin,'add',{academicYear:'2028-2029'},'academic_years_api.php'),409,'Duplicate year rejected');
    const workers=[1,2].map(()=>new Promise((resolve,reject)=>{const p=cp.spawn(php,[helper],{windowsHide:true});let out='';p.stdout.on('data',d=>out+=d);p.on('error',reject);p.on('exit',()=>resolve(JSON.parse(out)));p.stdin.end(JSON.stringify({action:'add',year:'2029-2030'}));}));
    const race=await Promise.all(workers);check(race.filter(r=>r.ok).length===1&&sql("SELECT * FROM academic_years WHERE year_label='2029-2030'").length===1,'Independent concurrent duplicate creation commits exactly one academic year');
    check(sql("SELECT * FROM activity_logs WHERE action='academic_year_added' AND entity_id='2029-2030'").length===1,'Concurrent creation writes one mandatory audit event');
    sql("CREATE TRIGGER batch5_year_audit_failure BEFORE INSERT ON activity_logs FOR EACH ROW BEGIN IF NEW.action='academic_year_added' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic catalog audit failure'; END IF; END");
    try{await expect(post(s.admin,'add',{academicYear:'2030-2031'},'academic_years_api.php'),500,'Year addition fails atomically if audit persistence fails');}finally{sql('DROP TRIGGER batch5_year_audit_failure');}
    check(!sql("SELECT * FROM academic_years WHERE year_label='2030-2031'").length,'Failed audit leaves no catalog addition');
    const original=sql('SELECT * FROM students WHERE id=5')[0];
    const saveYear=year=>post(s.admin,'save',{id:5,studentId:original.student_id,name:original.full_name,email:original.email,research:original.research_title||'',adviserId:original.adviser_id,stage:original.stage,status:original.status,academicUnitKey:original.academic_unit_key,programKey:original.program_key,yearLevel:original.year_level,academicYear:year,group:'__keep__'},'students_api.php');
    await expect(saveYear('2028-2029'),200,'Existing individual Student save accepts a newly configured academic year');
    check(sql("SELECT * FROM activity_logs WHERE action='student_academic_year_changed' AND student_id=5 AND before_value='2001-2002' AND after_value='2028-2029'").length>0,'Academic-year assignment change has mandatory before/after audit evidence');
    sql("CREATE TRIGGER batch5_assignment_audit_failure BEFORE INSERT ON activity_logs FOR EACH ROW BEGIN IF NEW.action='student_academic_year_changed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic assignment audit failure'; END IF; END");
    try{await expect(saveYear('2029-2030'),500,'Individual academic-year update rolls back when its audit fails');}finally{sql('DROP TRIGGER batch5_assignment_audit_failure');}
    check(sql('SELECT academic_year FROM students WHERE id=5')[0].academic_year==='2028-2029','Audit fault preserves the previous Student academic-year assignment');
    await expect(saveYear('2001-2002'),200,'Historical catalog year remains a valid explicit individual assignment');
    const project=reportCli({action:'project'}), key='project:'+project.projectId;
    // Audited synthetic formal events isolate ordering from uploaded_at/updated_at.
    // Synthetic audits left by an earlier run would make these fixture documents look returned; remove only them.
    sql("DELETE FROM activity_logs WHERE entity_id LIKE 'batch5-order-%'");
    for(const [id,sid,date]of [['batch5-order-a',4,'2026-10-01 09:00:00'],['batch5-order-b',5,'2026-10-02 10:00:00']]){
      sql("INSERT INTO documents(id,student_id,stage,document_type,original_name,stored_name,mime,size,version_no,is_current,review_status,rpms_submitted_at) VALUES (?,?,'Stage 1','Study Protocol','Synthetic Batch5 ordering.pdf','fixture.pdf','application/pdf',12,1,1,'Approved',?)",[id,sid,date]);
      sql("INSERT INTO activity_logs(user_email,action,details,actor_name,actor_role,entity_type,entity_id,student_id,after_value,created_at) VALUES ('adviser2@example.test','document_submitted_to_rpms','Synthetic Batch5 evidence','Synthetic Adviser','adviser','document',?,?,'Submitted to RPMS',?)",[id,sid,date]);
    }
    sql("INSERT INTO students(id,student_id,full_name,email,course,research_title,stage,status,profile_completed_at) VALUES (10019,'B5-UNDATED','Undated Student','batch5-undated@example.test','Undated course','Undated monitoring fixture','Stage 2','Pending',NOW())");
    const newest=(await read({})).body;check(newest.records[0].studentRecordId===5,'Default sorting orders the complete dataset by latest audited formal submission');
    sql("UPDATE students SET last_submission_date='2099-01-01',updated_at='2099-01-01 12:00:00' WHERE id=4");
    sql("UPDATE documents SET ai_summary='Synthetic unrelated summary update',uploaded_at='2099-01-01 12:00:00' WHERE id='batch5-order-a'");
    check((await read({})).body.records[0].studentRecordId===5,'Record modification, manual dates, academic-year changes and summary updates never become formal submission dates');
    check(newest.records.filter(r=>r.projectId===project.projectId).length===1&&!newest.records.some(r=>r.key==='student:6'),'Canonical two-member project is represented exactly once using immutable project identity');
    const canonical=newest.records.find(r=>r.key===key);check(canonical.research.startsWith('Synthetic study')&&canonical.groupId===project.identifier&&canonical.stage==='initial_submission'&&canonical.progress===0,'Canonical title, group identifier and semantic progress come from project/workflow authority');
    check(canonical.requirements.filter(r=>r.mandatory).length===13&&canonical.initialAcceptedCount===0,'Thirteen mandatory Initial requirements use acceptance evidence rather than uploaded-file counts');
    check(canonical.requirements.find(r=>r.key==='protocol').state==='Formally Submitted to RPMS','Formally submitted but unaccepted document is not counted as Accepted');
    check(newest.records.find(r=>r.studentRecordId===5).model==='Legacy','Legacy numerical stages remain explicitly separate from V14 semantic stages');
    check(!(await read({direction:'ASC'})).body.records.at(-1).latestSubmission,'Records without valid submission dates remain visible and consistently last');
    const oldest=(await read({sortBy:'submission',direction:'ASC'})).body;check(oldest.records[0].key===key,'Oldest submission first is applied before pagination');
    sql("UPDATE documents SET rpms_submitted_at='2026-10-01 09:00:00' WHERE id='batch5-order-b'");
    const tied=(await read({})).body.records.filter(r=>r.latestSubmission);check(tied.map(r=>r.studentRecordId).join(',')==='4,5','Identical formal timestamps resolve deterministically using stable record IDs');
    sql("UPDATE documents SET rpms_submitted_at=NULL WHERE id IN ('batch5-order-a','batch5-order-b')");
    const fallback=(await read({})).body;check(!fallback.records.find(r=>r.key===key).latestSubmission&&!!fallback.records.find(r=>r.studentRecordId===5).latestSubmission,'Canonical missing document stamp stays unknown; legacy uses verified formal audit timestamp fallback');
    sql("UPDATE documents SET rpms_submitted_at='2026-10-01 09:00:00' WHERE id='batch5-order-a'");
    const detail=(await expect(s.admin.get(`/ierb_api.php?action=monitor_detail&keys[]=${key}`).then(response),200,'Authorized paginated submission history')).body.records[0];
    check(detail.history.events.some(e=>e.event_type==='document_submitted_to_rpms')&&detail.firstSubmission===canonical.firstSubmission,'History includes formal category and authoritative initial/latest submission dates');
    check(detail.history.events.some(e=>e.event_type==='enrolled'),'History retains official semantic workflow events');
    await expect(s.admin.get('/ierb_api.php?action=monitor_compare&keys[]=bad:1').then(response),422,'Malformed selected identity rejected');
    await expect(s.admin.get('/ierb_api.php?action=monitor&sortBy=title;DROP').then(response),422,'Arbitrary SQL sort columns rejected');
    const compared=await expect(s.admin.get(`/ierb_api.php?action=monitor_compare&keys[]=${key}&keys[]=${key}&keys[]=student:6&keys[]=student:999999`).then(response),200,'Selected records are re-resolved against current authoritative identity');
    check(compared.body.records.length===1&&compared.body.unavailableKeys.length===2,'Duplicate projects count once and stale or unavailable Student aliases are disclosed');
    const observation=()=>JSON.stringify(Object.fromEntries(['students','documents','ierb_workflows','ierb_correction_cases','ierb_workflow_events','research_projects','research_project_members','research_project_events','research_project_member_history','activity_logs'].map(t=>[t,sql('SELECT * FROM '+t)])));
    const before=observation();
    await read({q:'Synthetic study'});await s.admin.get(`/ierb_api.php?action=monitor_compare&keys[]=${key}`);check(before===observation(),'Monitoring, history and comparison perform no workflow mutation or implicit enrollment');
    const assigned=(await expect(s.adviser.get('/ierb_api.php?action=monitor').then(response),200,'Assigned Adviser can read scoped monitor')).body;
    check(!assigned.records.some(r=>r.studentRecordId===5),'Adviser cannot see records assigned to another Adviser');
    const filter=(await read({academicYear:'2001-2002'})).body;check(filter.total===1&&filter.records[0].studentRecordId===5,'Filtered count matches actual query and recognizes historical years');
    for(let id=10020;id<10032;id++)sql("INSERT INTO students(id,student_id,full_name,email,course,research_title,stage,status,profile_completed_at) VALUES (?,?,?,?,'Synthetic Batch5 course',?,'Stage 2','Pending',NOW())",[id,'B5-'+id,'Synthetic Batch5 '+id,`batch5-${id}@example.test`,'Synthetic Batch5 title '+String(10052-id)]);
    const p1=(await read({q:'Synthetic Batch5 title',sortBy:'title',direction:'ASC',perPage:'10'})).body,p2=(await read({q:'Synthetic Batch5 title',sortBy:'title',direction:'ASC',perPage:'10',page:'2'})).body;
    check(p1.total===12&&p1.records.length===10&&p2.records.length===2&&!p1.records.some(a=>p2.records.some(b=>a.key===b.key)),'Server-side title ordering and filtered counts span genuinely distinct pages '+JSON.stringify({total1:p1.total,total2:p2.total,page1:p1.page,page2:p2.page,keys1:p1.records.map(r=>r.key),keys2:p2.records.map(r=>r.key)}));
    check(p1.records[0].research<p2.records[0].research,'Title ordering applies to all records before page slicing');
    const reversed=(await read({q:'Synthetic Batch5 title',sortBy:'title',direction:'DESC'})).body.records;
    check(reversed.map(r=>r.key).join(',')===[...p1.records,...p2.records].reverse().map(r=>r.key).join(','),'Research Title Z?A reverses the entire filtered dataset before pagination');
    browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    const errors=[],matrix=[];
    for(const theme of ['light','dark'])for(const width of [390,768,1024,1440,1920]){
      const ctx=await browser.newContext({viewport:{width,height:1000},storageState:await s.admin.storageState(),reducedMotion:'reduce',hasTouch:width===390});
      await ctx.addInitScript(({theme,key})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(key,'seen');},{theme,key:'prismTour.admin.'+crypto.createHash('sha256').update('admin|1').digest('hex')+'.v1'});
      await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
      const page=await ctx.newPage();page.on('pageerror',e=>errors.push(String(e)));await page.goto(base+'/ierbprog.php');
      await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
      const host=page.locator('[data-ierb-monitor]');await host.locator('[data-monitor-state]').filter({hasText:'progress records.'}).waitFor();
      check(await host.evaluate(e=>e.scrollWidth<=e.clientWidth+1)&&await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${width} ${theme}: monitor and page remain within viewport`);
      await page.mouse.move(width-20,60);await page.keyboard.press('Escape');
      const box=host.locator('[data-record-key]').first();await box.check();check((await host.locator('[data-monitor-selection-count]').textContent())==='1 selected',`${width} ${theme}: select one authoritative record`);
      await host.locator('[data-monitor-page]').check();check((await host.locator('[data-monitor-selection-count]').textContent())==='10 selected',`${width} ${theme}: select current page`);
      await host.locator('[data-monitor-next]').click();await page.waitForFunction(()=>document.querySelector('[data-monitor-page-label]').textContent.startsWith('Page 2'));
      await host.locator('[data-record-key]').first().check();check((await host.locator('[data-monitor-selection-count]').textContent())==='11 selected',`${width} ${theme}: selection survives pagination`);
      await host.locator('[data-monitor-search]').fill('Synthetic study');await page.waitForFunction(()=>document.querySelector('[data-monitor-rows]').querySelectorAll('tr').length===1&&document.querySelector('[data-monitor-state]').textContent.startsWith('1 progress record.'));
      check((await host.locator('[data-monitor-selection-count]').textContent())==='11 selected',`${width} ${theme}: changing filters preserves off-page selection`);
      const progress=host.locator('.monitor-progress').first();await progress.focus();await page.locator('.monitor-popover').waitFor();
      check(await page.locator('.monitor-checklist li').count()===15,`${width} ${theme}: keyboard focus exposes all 13 Initial labels plus Form 33 and Final Report`);
      const bounds=await page.locator('.monitor-popover').boundingBox();check(bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=width&&bounds.y+bounds.height<=1000,`${width} ${theme}: portalled checklist stays inside viewport`);
      await page.screenshot({path:path.join(evidence,`checklist-${width}-${theme}.png`)});await page.keyboard.press('Escape');
      if(width===390){await progress.tap();check(await page.locator('.monitor-popover').isVisible(),'Mobile tap opens document checklist');await page.keyboard.press('Escape');check(await progress.evaluate(e=>e===document.activeElement),'Closing the touch/keyboard checklist restores its initiating control');}
      else {await progress.hover();check(await page.locator('.monitor-popover').isVisible(),`${width} ${theme}: mouse hover opens checklist`);await page.keyboard.press('Escape');}
      await host.locator('[data-monitor-compare]').click();const dialog=page.getByRole('dialog',{name:'Read-only progress comparison'});await dialog.locator('[data-comparison-state]').filter({hasText:'current record(s).'}).waitFor();
      check(await dialog.locator('article').count()===11,`${width} ${theme}: readable multi-record comparison includes selected off-page records`);
      check(await dialog.evaluate(e=>e.scrollWidth<=e.clientWidth+1),`${width} ${theme}: comparison wraps within accessible modal`);
      await page.screenshot({path:path.join(evidence,`comparison-${width}-${theme}.png`)});await page.keyboard.press('Escape');
      await host.locator('[data-monitor-clear]').click();check((await host.locator('[data-monitor-selection-count]').textContent())==='0 selected',`${width} ${theme}: clear selection`);
      await host.locator('[data-history]').click();await page.getByRole('dialog',{name:'Submission history and requirements'}).getByText('Recorded submission and workflow history').waitFor();await page.screenshot({path:path.join(evidence,`history-${width}-${theme}.png`)});await page.keyboard.press('Escape');
      if(width===390&&theme==='light'){
        check(await page.locator('#entryAcademicYear option[value="2028-2029"]').count()===1,'Academic-year dropdown reads the durable shared catalog');
        const manager=page.locator('[data-academic-year-manager]');await manager.locator('input').fill('2031-2032');await manager.getByRole('button',{name:'Add academic year'}).click();
        await manager.locator('[role="status"]').filter({hasText:'2031-2032 added.'}).waitFor();
        check(await page.locator('#entryAcademicYear option[value="2031-2032"]').count()===1,'Admin form adds a year and updates mounted selectors without changing their selected values');
        await manager.screenshot({path:path.join(evidence,'academic-year-management-mobile.png')});
      }
      await page.goto(base+'/dashboard.php');await page.locator('[data-monitor-state]').filter({hasText:'progress records.'}).waitFor();
      const dashboardGeometry=await page.evaluate(()=>({pageWidth:document.documentElement.scrollWidth,viewport:innerWidth,monitorWidth:document.querySelector('[data-ierb-monitor]').clientWidth,monitorScroll:document.querySelector('[data-ierb-monitor]').scrollWidth,outside:[...document.querySelectorAll('body *')].filter(e=>e.getClientRects().length&&e.getBoundingClientRect().right>innerWidth+1&&!e.closest('.monitor-table-scroll')).slice(0,12).map(e=>({tag:e.tagName,id:e.id,cls:typeof e.className==='string'?e.className:'',right:e.getBoundingClientRect().right}))}));
      await page.screenshot({path:path.join(evidence,`dashboard-page-${width}-${theme}.png`)});
      check(dashboardGeometry.monitorScroll<=dashboardGeometry.monitorWidth+1&&dashboardGeometry.pageWidth<=dashboardGeometry.viewport+1,`${width} ${theme}: shared dashboard monitor remains within viewport `+JSON.stringify(dashboardGeometry));
      check((await page.locator('#monitorDistribution').textContent()).includes('Legacy Student records'),'Dashboard explicitly separates canonical and legacy stage distributions');
      await page.locator('[data-ierb-monitor]').screenshot({path:path.join(evidence,`dashboard-${width}-${theme}.png`)});
      matrix.push({width,theme,passed:true});await ctx.close();
    }
    // Real correction opening keeps its interrupted progress and conditionally adds Amendment.
    const old=path.join(root,'maintenance/ierb-v14-tests/documents-fixture.php');
    const revision=Number(sql('SELECT revision FROM ierb_workflows WHERE student_id=4')[0].revision);
    const correction=JSON.parse(cp.execFileSync(php,[old],{input:JSON.stringify({action:'open',revision,documentId:'batch5-order-a',amendment:true,key:crypto.randomUUID()}),encoding:'utf8',windowsHide:true}));
    check(correction.ok,'Synthetic RPMS explicitly opens an Amendment-required correction case');
    const corrected=(await read({q:'Synthetic study'})).body.records[0];
    const correctionFilter=(await read({status:'Correction open'})).body;
    check(correctionFilter.total===1&&correctionFilter.records[0].key===key&&corrected.status==='Correction open','Status filtering and counts use the same current correction status that the monitor displays');
    check(corrected.stage==='resubmission_amendment'&&corrected.progress===0&&corrected.correction.requiresAmendment,'Active correction retains interrupted Initial progress and explicit Amendment decision');
    check(corrected.requirements.some(x=>x.key==='amendment'&&x.mandatory&&x.state==='Missing'),'Conditional Amendment is mandatory only for the active required case');
    const history=(await expect(s.admin.get('/ierb_api.php?action=monitor_detail&keys[]='+key).then(response),200,'Correction history is visible')).body.records[0].history;
    check(history.events.some(x=>x.event_type==='Correction opened'&&x.detail.includes('Amendment required: Yes')),'History distinguishes case opening and Amendment decision from formal submissions');
    for(let n=0;n<12;n++)sql("INSERT INTO activity_logs(user_email,action,details,actor_name,actor_role,entity_type,entity_id,student_id,created_at) VALUES ('admin@example.test','document_returned_for_revision','Synthetic chronology fixture','Synthetic Admin','admin','document','batch5-order-a',4,NOW())");
    const hp1=(await expect(s.admin.get('/ierb_api.php?action=monitor_detail&keys[]='+key+'&page=1').then(response),200,'First server-side history page')).body.records[0].history;
    const hp2=(await expect(s.admin.get('/ierb_api.php?action=monitor_detail&keys[]='+key+'&page=2').then(response),200,'Second server-side history page')).body.records[0].history;
    check(hp1.total===hp2.total&&hp1.events.length===10&&hp2.events.length>0&&!hp1.events.some(a=>hp2.events.some(b=>a.event_id===b.event_id)),'History counts and stable event ordering span distinct pages without dropping recorded returns');
    sql('UPDATE students SET archived_at=NOW() WHERE id=5');
    const stale=await expect(s.admin.get('/ierb_api.php?action=monitor_compare&keys[]=student:5').then(response),200,'Comparison rechecks an archived selected record');
    check(!stale.body.records.length&&stale.body.unavailableKeys.includes('student:5'),'Archived selected records are disclosed as unavailable without returning retained metadata');
    sql('UPDATE students SET archived_at=NULL WHERE id=5');
    check(!errors.length,'Monitoring interactions have no JavaScript errors');
    fs.writeFileSync(path.join(evidence,'responsive.json'),JSON.stringify({status:'PASS',matrix,errors},null,2));
    fs.writeFileSync(path.join(evidence,'batch5-integration.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start)},null,2));
  } finally {
    if(browser)await browser.close();
    sql("DELETE FROM activity_logs WHERE action='academic_year_added'");
    sql("DELETE FROM students WHERE id BETWEEN 10019 AND 10031 AND student_id LIKE 'B5-%'");
    sql("DELETE FROM documents WHERE id LIKE 'batch5-order-%'");
    sql("DELETE FROM activity_logs WHERE entity_id LIKE 'batch5-order-%'");
    if(sql("SELECT v FROM schema_meta WHERE k='schema_version'")[0].v==='15')cli({action:'cleanup'});
    cp.execFileSync(php,[path.join(root,'maintenance/ierb-v14-tests/documents-fixture.php')],{input:JSON.stringify({action:'cleanup'}),encoding:'utf8',windowsHide:true});
    sql('UPDATE students SET academic_year=? WHERE id=5',[historical]);
  }
};
