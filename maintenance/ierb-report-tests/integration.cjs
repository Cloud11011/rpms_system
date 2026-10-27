const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
function sourcePdf(text){
  const stream='BT /F1 10 Tf 30 750 Td '+text.split('\n').map((line,i)=>(i?'0 -14 Td ':'')+'('+line.replace(/[\\()]/g,c=>'\\'+c)+') Tj').join('\n')+' ET';
  const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '+stream.length+' >>\nstream\n'+stream+'\nendstream'];
  let data='%PDF-1.4\n';const offsets=[];objects.forEach((o,i)=>{offsets.push(Buffer.byteLength(data));data+=`${i+1} 0 obj\n${o}\nendobj\n`});const start=Buffer.byteLength(data);
  return Buffer.from(data+'xref\n0 6\n0000000000 65535 f \n'+offsets.map(o=>String(o).padStart(10,'0')+' 00000 n \n').join('')+`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`);
}
module.exports=async h=>{
  const {root,php,sql,post,expect,check,response,sessions:s,base,playwright,generation}=h;
  const start=h.checks.length,evidence=path.join(root,'PRISM_V14_BATCH_04_EVIDENCE');fs.mkdirSync(evidence,{recursive:true});
  const helper=path.join(__dirname,'fixture.php');
  const cli=data=>JSON.parse(cp.execFileSync(php,[helper],{input:JSON.stringify(data),encoding:'utf8',windowsHide:true})).result;
  const oldHelper=path.join(root,'maintenance/ierb-v14-tests/documents-fixture.php');
  let browser;
  try{
    check(cli({action:'install'}).dryRunUnchanged,'Schema 14 additive migration and read-only dry run pass exact contracts');
    if(process.env.PRISM_TEST_SKIP_MIGRATION_CASES!=='1'){
      const cases=cli({action:'migration-tests'});for(const label of cases)check(true,label);
      fs.writeFileSync(path.join(evidence,'summary-migration-tests.json'),JSON.stringify({status:'PASS',cases},null,2));
    }
    const uploaded=await expect(response(await s.admin.post('/documents_api.php?action=upload',{headers:{origin:base,'X-Prism-Generation':await generation(s.admin)},multipart:{studentDbId:'4',documentType:'Other Supporting Document',requestId:crypto.randomUUID(),document:{name:'Synthetic Batch4 summary.pdf',mimeType:'application/pdf',buffer:sourcePdf('Principal Investigator: Student Four\nPurpose: This synthetic research studies document retrieval.\n\nMethods: A descriptive quantitative review of 40 fictional transactions was used.\n\nFindings: Average retrieval time was 12 minutes manually and 4 minutes centrally.\n\nLimitations: The small nonrandom synthetic sample limits generalization.')}}})),200,'Actual multipart upload provides synthetic source for summary versions');
    const id=uploaded.body.document.id;
    sql('UPDATE documents SET ai_summary=? WHERE id=?',['Earlier historical summary retained exactly.',id]);
    const local=await expect(post(s.admin,'summarize',{id,mode:'local'},'documents_api.php'),200,'Local summary imports historical value before appending a new version');
    check(local.body.summaryVersion===2,'Imported history is version 1 and new Local Summary is version 2');
    await expect(post(s.admin,'summarize',{id,mode:'local'},'documents_api.php'),200,'Repeated Local Summary appends another independent version');
    const preview=await expect(post(s.admin,'summarize_preview',{id},'documents_api.php'),200,'Existing confirmed external excerpt boundary remains available');
    check(!preview.body.excerpt.includes('Student Four'),'Known investigator identity is removed before external AI processing');
    await expect(post(s.admin,'summarize',{id,mode:'ai',previewToken:preview.body.previewToken,confirmed:true},'documents_api.php'),200,'AI summary uses existing Liquid model with retained version metadata');
    const versions=(await response(await s.admin.get('/documents_api.php?action=summary_history&id='+id))).body.versions;
    check(versions.length===4 && versions.map(v=>Number(v.version_no)).join(',')==='4,3,2,1','History is durable and ordered newest first');
    check(versions[3].summary_content==='Earlier historical summary retained exactly.'&&!versions[3].generated_at&&versions[3].source_type==='unknown','Historical text remains exact and unrecorded generation metadata stays unknown');
    check(versions[0].source_type==='ai'&&versions[0].model_identifier==='liquid/lfm-2.5-2.6b:free'&&versions[0].generated_by_user_id===1&&versions[0].source_sha256.length===64,'AI model, responsible Admin, generation timestamp and source hash retained');
    check(versions.every(v=>Number(v.source_version_no)===1),'Summary versions remain bound to the actual source document version');
    check(/40/.test(versions[0].summary_content)&&/12 minutes/.test(versions[0].summary_content)&&/4 minutes/.test(versions[0].summary_content),'Synthetic AI response numerical claims match the supplied source text; this does not certify live-model accuracy');
    await expect(post(s.admin,'delete',{id,reason:'Synthetic retention safeguard verification'},'documents_api.php'),409,'Retained summary history prevents deleting its source document');
    for(const v of versions){const pdf=await s.admin.get(`/documents_api.php?action=summary_pdf&id=${id}&summaryId=${v.id}`);check(pdf.status()===200&&(await pdf.body()).subarray(0,4).toString()==='%PDF','Authorized historical summary PDF version '+v.version_no);if(Number(v.version_no)===1||Number(v.version_no)===4)fs.writeFileSync(path.join(evidence,'summary-v'+v.version_no+'.pdf'),await pdf.body());}
    const anonymous=await playwright.request.newContext({baseURL:base});
    try{await expect(anonymous.get(`/documents_api.php?action=summary_pdf&id=${id}&summaryId=${versions[0].id}`).then(response),401,'Unauthenticated requests cannot download summary PDFs');}finally{await anonymous.dispose();}
    for(const role of ['student','adviser']){await expect(s[role].get('/documents_api.php?action=summary_history&id='+id).then(response),403,role+' cannot read private summary history');await expect(s[role].get(`/documents_api.php?action=summary_pdf&id=${id}&summaryId=${versions[0].id}`).then(response),403,role+' cannot export RPMS-only summary versions');}
    const partialUpload=await expect(response(await s.admin.post('/documents_api.php?action=upload',{headers:{origin:base,'X-Prism-Generation':await generation(s.admin)},multipart:{studentDbId:'4',documentType:'Study Protocol',requestId:crypto.randomUUID(),document:{name:'Synthetic Batch4 partial.pdf',mimeType:'application/pdf',buffer:sourcePdf('Purpose: Synthetic bounded extraction verification.\n'+('Methods and ethics limitations are documented for this synthetic research protocol.\n').repeat(200))}}})),200,'Upload a separate long synthetic document for partial-extraction coverage');
    const partial=await expect(post(s.admin,'summarize',{id:partialUpload.body.document.id,mode:'local'},'documents_api.php'),200,'Long source produces an explicitly partial Local Summary');
    check(partial.body.partial===true&&partial.body.limitations.includes('Partial extraction'),'Partial-extraction flag and source limitations are retained with the version');
    const other=sql('SELECT id FROM documents WHERE id<>? LIMIT 1',[id])[0];
    await expect(s.admin.get(`/documents_api.php?action=summary_pdf&id=${other.id}&summaryId=${versions[0].id}`).then(response),404,'Summary version cannot be exported through a different source document');
    for(const scenario of ['rate','network','malformed','empty','incomplete','model']){
      fs.writeFileSync(path.join(h.review,'sandbox/provider-scenario.txt'),scenario);
      const p=await post(s.admin,'summarize_preview',{id},'documents_api.php');
      const r=await expect(post(s.admin,'summarize',{id,mode:'ai',previewToken:p.body.previewToken,confirmed:true},'documents_api.php'),200,'Provider '+scenario+' safely appends deterministic Local Summary fallback');
      check(r.body.source==='local'&&!!r.body.fallbackReason,'Provider '+scenario+' fallback source and limitation disclosed');
    }
    fs.writeFileSync(path.join(h.review,'sandbox/provider-scenario.txt'),'success');
    const countBefore=sql('SELECT COUNT(*) AS n FROM document_summary_versions')[0].n;
    sql("CREATE TRIGGER batch4_audit_failure BEFORE INSERT ON activity_logs FOR EACH ROW BEGIN IF NEW.action='document_summarized' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit fault'; END IF; END");
    try{await expect(post(s.admin,'summarize',{id,mode:'local'},'documents_api.php'),503,'Summary audit failure rolls back the new version and latest value atomically');}finally{sql('DROP TRIGGER batch4_audit_failure');}
    check(sql('SELECT COUNT(*) AS n FROM document_summary_versions')[0].n===countBefore,'Earlier summary history survives audit-failure rollback');
    const project=cli({action:'project'});const projectId=project.projectId;
    check(!!projectId,'Batch4 uses canonical project membership and immutable project identity');
    const terms=cli({action:'privacy',documentId:id});
    check(terms.includes(sql('SELECT full_name FROM students WHERE id=6')[0].full_name),'Canonical member identities remain de-identified even when a legacy group label is stale');
    const snap=cli({action:'snapshot',projectId});
    check(snap.identity['Principal Investigator']==='Not recorded'&&snap.identity['Co-Investigators']==='Not recorded'&&snap.identity['Research Category']==='Not recorded','Holder and other project members are never assigned invented investigator roles');
    check(snap.tracking.rows.length===4&&snap.tracking.rows.every(r=>!r.submittedAt),'Uploads and summary activity do not fabricate formal tracking dates');
    const counts=(await response(await s.admin.get('/reports_api.php?action=project_options'))).body.counts;
    check(counts.distinctResearchProjects===1&&counts.v14EnrolledProjects===1&&snap.members.length===2,'A two-member research group counts once as a canonical project');
    for(const role of ['student','adviser'])await expect(s[role].get('/reports_api.php?action=project_options').then(response),403,role+' cannot access administrative project aggregates');
    fs.writeFileSync(path.join(h.review,'sandbox/provider-scenario.txt'),'network');
    const report=await expect(post(s.admin,'project_report',{projectId},'reports_api.php'),200,'Canonical project report persists with deterministic fallback during provider failure');
    check(report.body.aiUsed===false,'Report discloses deterministic fallback');
    const rid=report.body.report.id;const stored=sql('SELECT * FROM ierb_report_snapshots WHERE report_id=?',[rid])[0];
    for(const role of ['student','adviser'])await expect(s[role].get('/reports_api.php?action=file&id='+rid).then(response),403,role+' cannot download confidential RPMS project reports');
    check(crypto.createHash('sha256').update(stored.snapshot_json).digest('hex')===stored.snapshot_sha256,'Immutable report evidence snapshot has a matching SHA256');
    const reportPdf=await s.admin.get('/reports_api.php?action=file&id='+rid);check(reportPdf.status()===200,'Existing private report download serves the new project PDF');
    fs.writeFileSync(path.join(evidence,'project-track-sheet.pdf'),await reportPdf.body());
    fs.writeFileSync(path.join(h.review,'sandbox/provider-scenario.txt'),'project-success');
    const aiReport=await expect(post(s.admin,'project_report',{projectId},'reports_api.php'),200,'AI may organize only the supplied verified narrative sentences');
    check(aiReport.body.aiUsed===true,'Valid provider sentence selection is marked AI-assisted');
    const request=JSON.parse(fs.readFileSync(path.join(h.review,'sandbox/project-ai-request.json'),'utf8'));
    check(!/Student Four|Student Six|protocol_code|adviser_id|research_title|full_name|documentId|studentId/.test(request.messages[1].content),'Actual outgoing progress request contains no investigator identities or project record identifiers');
    fs.writeFileSync(path.join(evidence,'progress-ai-request.json'),JSON.stringify(request,null,2));
    const reportCount=sql('SELECT COUNT(*) AS n FROM reports')[0].n;
    fs.writeFileSync(path.join(h.review,'sandbox/provider-scenario.txt'),'project-stale');
    await expect(post(s.admin,'project_report',{projectId},'reports_api.php'),409,'Changing project evidence during provider processing rejects a stale report');
    check(sql('SELECT COUNT(*) AS n FROM reports')[0].n===reportCount,'Stale report rejection leaves no report or snapshot row');
    fs.writeFileSync(path.join(h.review,'sandbox/provider-scenario.txt'),'network');
    cli({action:'layout',projectId});
    const aggregate=await expect(post(s.admin,'project_aggregate',{},'reports_api.php'),200,'Institutional aggregate keeps project and Student statistical units separate');
    const aggregatePdf=await s.admin.get('/reports_api.php?action=file&id='+aggregate.body.report.id);fs.writeFileSync(path.join(evidence,'institutional-projects.pdf'),await aggregatePdf.body());
    check(cli({action:'retained-rollback'}).refused,'Retained summary and report evidence block schema rollback');
    const noPii=require('node:child_process').execFileSync(php,['-r',`require '${root.replace(/\\/g,'/')}/includes/ierb_track_report.php'; echo json_encode(track_assessment_facts(json_decode(stream_get_contents(STDIN),true)));`],{input:JSON.stringify(snap),encoding:'utf8',windowsHide:true});
    check(!/research_title|protocol|adviser|full_name|reason|documentId|studentId|Synthetic study/.test(noPii),'External progress facts exclude identities, titles, reasons and record IDs');
    const oldReport=sql('SELECT * FROM reports WHERE id=?',[rid])[0];sql('UPDATE research_projects SET research_title=? WHERE id=?',['Synthetic changed current title',projectId]);
    check((await s.admin.get('/reports_api.php?action=file&id='+rid)).status()===200&&sql('SELECT * FROM ierb_report_snapshots WHERE report_id=?',[rid])[0].snapshot_sha256===stored.snapshot_sha256,'Later project edits do not rewrite historical report snapshots or files');
    sql('UPDATE research_projects SET research_title=? WHERE id=?',[snap.project.research_title,projectId]);
    browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    for(const width of [390,768,1440])for(const theme of ['light','dark']){
      const ctx=await browser.newContext({viewport:{width,height:1000},storageState:await s.admin.storageState(),reducedMotion:'reduce'});
      await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
      await ctx.addInitScript(({theme,tour})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(tour,'seen');}, {theme,tour:'prismTour.admin.'+crypto.createHash('sha256').update('admin|1').digest('hex')+'.v1'});
      const page=await ctx.newPage();await page.goto(base+'/documents.php');
      await page.mouse.move(width-20,60);await page.keyboard.press('Escape');
      const menu=page.locator('.prism-action-menu').filter({has:page.locator(`[data-summary="${id}"]`)}).first();
      await menu.locator('.prism-action-trigger').click();await page.locator(`.prism-action-panel:visible [data-summary="${id}"]`).click();await page.locator('#summaryVersion:not([disabled])').waitFor();
      check(await page.locator('#summaryVersion option').count()===10,'Version selector includes retained Local, AI and provider-fallback history at '+width+' '+theme);
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Summary page fits viewport '+width+' '+theme);
      await page.locator('#summaryModal .summary-dialog').screenshot({path:path.join(evidence,`summary-${width}-${theme}.png`)});
      await page.goto(base+'/reports.php');await page.getByRole('button',{name:'Generate IERB Project Track Sheet'}).click();await page.locator('#projectModal.show').waitFor();
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Project report page fits viewport '+width+' '+theme);
      await page.locator('#projectModal .report-modal-dialog').screenshot({path:path.join(evidence,`project-${width}-${theme}.png`)});await ctx.close();
    }
    // Real workflow writes, not manufactured dates: initial formal approval followed by four cases.
    const oldCli=data=>JSON.parse(cp.execFileSync(php,[oldHelper],{input:JSON.stringify(data),encoding:'utf8',windowsHide:true}));
    const revision=()=>Number(sql('SELECT revision FROM ierb_workflows WHERE student_id=4')[0].revision);
    let current=id;
    await expect(post(s.adviser,'review',{id:current,status:'Approved',remarks:'Synthetic Batch4 formal initial submission'},'documents_api.php'),200,'Adviser approval records actual formal initial submission');
    check(cli({action:'snapshot',projectId}).tracking.rows[0].submittedAt===sql('SELECT rpms_submitted_at FROM documents WHERE id=?',[current])[0].rpms_submitted_at,'Track sheet initial date equals authoritative formal submission stamp');
    for(let n=1;n<=4;n++){
      check(oldCli({action:'open',revision:revision(),documentId:current,amendment:false,key:crypto.randomUUID()}).ok,'Actual correction case '+n+' opens without enforcing a three-case workflow limit');
      const opened=cli({action:'snapshot',projectId});check(opened.tracking.officialResubmissionCount===n-1,'Opening case '+n+' alone never increases resubmission count');
      await expect(post(s.admin,'return_for_revision',{id:current,reason:'Synthetic Batch4 recorded return requiring a corrected supporting document.'},'documents_api.php'),200,'Separate authoritative return event recorded for case '+n);
      const affected=current;
      const next=await expect(response(await s.student.post('/documents_api.php?action=upload',{headers:{origin:base,'X-Prism-Generation':await generation(s.student)},multipart:{documentType:'Other Supporting Document',revisionOf:current,requestId:crypto.randomUUID(),document:{name:'Synthetic Batch4 summary.pdf',mimeType:'application/pdf',buffer:sourcePdf('Synthetic corrected supporting documentation for case '+n+'. Methods and limitations remain under review.')}}})),200,'Versioned successor uploaded for actual case '+n);
      current=next.body.document.id;
      await expect(post(s.adviser,'review',{id:current,status:'Approved',remarks:'Synthetic Batch4 corrected formal submission'},'documents_api.php'),200,'Adviser formally submits the actual correction '+n);
      await expect(post(s.admin,'accept_requirement',{studentId:4,requirementKey:'affected:'+affected,documentId:current,revision:revision(),reason:'Synthetic Batch4 accepted corrected supporting evidence',requestKey:crypto.randomUUID()},'documents_api.php'),200,'RPMS accepts case-bound corrected supporting evidence '+n);
      check(oldCli({action:'resolve',revision:revision(),key:crypto.randomUUID()}).ok,'Actual correction '+n+' resolves without invented progression');
      const now=cli({action:'snapshot',projectId});check(now.tracking.rows.length===4&&now.tracking.officialResubmissionCount===n,'Actual database track sheet counts '+n+' official cases with four fixed rows');
      if(n<=3)check(now.tracking.rows[n].submittedAt===sql('SELECT rpms_submitted_at FROM documents WHERE id=?',[current])[0].rpms_submitted_at,'Correction '+n+' uses actual formal submission timestamp');
      const capture=await expect(post(s.admin,'project_report',{projectId},'reports_api.php'),200,'Report exports actual database correction cycle '+n);
      fs.writeFileSync(path.join(evidence,'track-cycles-'+n+'.pdf'),await(await s.admin.get('/reports_api.php?action=file&id='+capture.body.report.id)).body());
      if(n===4){check(now.tracking.overflowEvidence.length===1&&now.tracking.notes.some(x=>x.includes('RPMS REVIEW REQUIRED')),'Fourth actual cycle remains preserved with an RPMS review exception and no extra tracking row');fs.writeFileSync(path.join(evidence,'fourth-cycle-exception.json'),JSON.stringify(now,null,2));}
    }
    check((await s.admin.get(`/documents_api.php?action=summary_pdf&id=${id}&summaryId=${versions[0].id}`)).status()===200,'Earlier summary remains independently exportable after its source document is superseded');
    fs.writeFileSync(path.join(evidence,'batch4-integration.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start)},null,2));
  }finally{
    if(browser)await browser.close();
    const schema=sql("SELECT v FROM schema_meta WHERE k='schema_version'")[0].v;
    if(schema==='14')cli({action:'cleanup'});
    cp.execFileSync(php,[oldHelper],{input:JSON.stringify({action:'cleanup'}),encoding:'utf8',windowsHide:true});
    sql("DELETE FROM documents WHERE original_name LIKE 'Synthetic Batch4 %' ORDER BY version_no DESC");
  }
};
