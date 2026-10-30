// Real PHP HTTP APIs and Chromium, bound to the existing isolated synthetic harness.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
module.exports=async h=>{
 const {root,review,php,sql,post,response,expect,check,login,generation,base,sessions:s}=h;
 if(process.env.PRISM_TEST_ONBOARDING_UI_ONLY==='1'){await require('./browser.cjs')(h);return;}
 const start=h.checks.length,ev=path.join(review,'onboarding');fs.mkdirSync(ev,{recursive:true});
 const helper=path.join(__dirname,'fixture.php');
 const raw=data=>JSON.parse(cp.execFileSync(php,[helper],{input:JSON.stringify(data),encoding:'utf8',windowsHide:true}));
 const fx=data=>{const r=raw(data);if(!r.ok)throw Error(JSON.stringify(r));return r.result;};
 const worker=data=>new Promise((resolve,reject)=>{const p=cp.spawn(php,[helper],{windowsHide:true});let out='',err='';p.stdout.on('data',d=>out+=d);p.stderr.on('data',d=>err+=d);p.on('exit',c=>c?reject(Error(err)):resolve(JSON.parse(out)));p.stdin.end(JSON.stringify(data));});
 for(const label of fx({action:'extension'}))check(true,label);
 // Refresh the copied app with the newly captured reviewed schema contract.
 fs.copyFileSync(path.join(root,'includes/research_onboarding_schema.json'),path.join(h.app,'includes/research_onboarding_schema.json'));
 const flag=path.join(review,'sandbox/v14-operations.flag');fs.writeFileSync(flag,'Synthetic local activation only');
 const a={academicUnitKey:'amt',programKey:'bsit',yearLevel:'4th Year',academicYear:'2026-2027'};
 const tag=Date.now().toString().slice(-7),code1='CEU-IERB-2026-ONB001-'+tag,code2='CEU-IERB-2026-ONB002-'+tag;
 let serial=81000000+Number(tag)*100;
 const payload=(extra={})=>{const n=++serial;return {id:0,studentId:String(n),name:'Onboarding Student '+n,email:'onboarding'+n+'@example.test',research:'Onboarding Peñaflor: café, santé & community research '+tag,group:'__create__',adviserId:2,stage:'Stage 1',status:'On Track',...a,...extra};};
 const saved=async(p,label)=>{const r=await expect(post(s.admin,'save',p,'students_api.php'),200,label);return {id:r.body.id,p};};
 const ready=id=>fx({action:'ready',id});
 const activate=id=>fx({action:'activate',id});
 const project=id=>sql('SELECT p.*,w.stage_key,w.revision AS wf_revision FROM research_projects p JOIN research_project_members m ON m.project_id=p.id JOIN ierb_workflows w ON w.id=p.workflow_id WHERE m.student_id=?',[id])[0];
 const assign=id=>sql('SELECT * FROM research_onboarding_assignments WHERE student_id=?',[id])[0];
 const update=async(p,operation,value,ctx=s.admin)=>post(ctx,'project_update',{projectId:p.id,expectedRevision:sql('SELECT revision FROM research_projects WHERE id=?',[p.id])[0].revision,operation,reason:'Synthetic RPMS verified this official group correction.',...(operation==='protocol'?{protocolCode:value}:operation==='title'?{research:value}:operation==='pi'?{studentId:value}:{adviserId:value})},'students_api.php');
 const count=()=>sql('SELECT COUNT(*) n FROM research_projects')[0].n;
 const pdf=(()=>{
  const stream='BT /F1 12 Tf 40 700 Td (Synthetic onboarding workflow evidence only.) Tj ET';
  const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '+stream.length+' >>\nstream\n'+stream+'\nendstream'];
  let data='%PDF-1.4\n';const offsets=[];objects.forEach((o,i)=>{offsets.push(Buffer.byteLength(data));data+=`${i+1} 0 obj\n${o}\nendobj\n`;});
  const start=Buffer.byteLength(data);return Buffer.from(data+'xref\n0 6\n0000000000 65535 f \n'+offsets.map(n=>String(n).padStart(10,'0')+' 00000 n \n').join('')+`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`);
 })();
 const upload=async(ctx,type,revisionOf='')=>response(await ctx.post('/documents_api.php?action=upload',{headers:{origin:base,'X-Prism-Generation':await generation(ctx)},multipart:{documentType:type,revisionOf,requestId:crypto.randomUUID(),document:{name:'Synthetic onboarding '+crypto.randomUUID()+'.pdf',mimeType:'application/pdf',buffer:pdf}}}));
 const initialCount=count();
 await expect(post(s.admin,'save',payload({adviserId:null}),'students_api.php'),422,'New group refuses a missing required Adviser');
 await expect(post(s.admin,'save',payload({group:'project:999999'}),'students_api.php'),409,'Forged unknown project assignment is refused');
 await expect(post(s.adviser,'invite',{accountType:'student',email:'forged-assignment@example.test',group:'__create__',...a},'account_invitation_api.php'),422,'Adviser cannot approve a canonical research assignment');
 const first=await saved(payload({isPrincipalInvestigator:true}),'Add Student stores the first explicit new-group assignment');
 check(assign(first.id).state==='pending' && !project(first.id) && count()===initialCount,'Temporary-password account creates no membership, workflow or orphaned project');
 await expect(post(s.admin,'assign',{targetId:first.id,expectedRevision:1,group:'__create__',research:first.p.research,adviserId:2,isPrincipalInvestigator:true,...a},'account_invitation_api.php'),200,'Admin can correct the same pending assignment without falsely treating its own title as a duplicate');
 check(assign(first.id).revision===2&&!project(first.id),'Pending correction preserves one assignment and its intended PI until eligible activation');
 await expect(post(s.admin,'save',{...first.p,id:first.id,group:'__keep__',research:'Silent pending research replacement',isPrincipalInvestigator:false},'students_api.php'),422,'Ordinary profile editing cannot silently replace staff-approved pending research information');

 check(activate(first.id).state==='pending','Activation defers until password setup and legal acceptance');
 sql('UPDATE users SET must_change_password=0 WHERE email=?',[first.p.email]);
 check(activate(first.id).state==='pending'&&!project(first.id),'Legal acceptance independently defers membership after password setup');
 ready(first.id);
 const pendingGuard=raw({action:'legacy-guard',id:first.id});
 check(!pendingGuard.ok&&pendingGuard.type==='AccountLifecycleConflict','Eligible but pending research setup cannot bypass activation through a legacy progress writer');
 await expect(post(s.admin,'save',{...first.p,id:first.id,groupId:'',research:'Silent IERB pending replacement'},'ierb_api.php'),409,'IERB record editing cannot silently replace an approved pending research title');
 check(fx({action:'gated-activate',id:first.id}).state==='pending'&&!project(first.id),'An eligible account cannot bypass the existing institutional activation gate');
 sql("UPDATE users SET must_change_password=1 WHERE email='adviser2@example.test'");
 try{check(activate(first.id).state==='pending'&&!project(first.id),'Incomplete Adviser password setup defers project creation');}finally{sql("UPDATE users SET must_change_password=0 WHERE email='adviser2@example.test'");}
 const active=activate(first.id),p=project(first.id);
 check(active.state==='activated' && p.stage_key==='initial_submission','Eligible first Add Student automatically creates Initial Submission');
 check(p.public_identifier.startsWith('onboarding-penaflor') && !p.public_identifier.endsWith('-'),'Full accented title is preserved with a safe title-derived internal identifier');
 check(sql('SELECT research_title FROM research_projects WHERE id=?',[p.id])[0].research_title===first.p.research,'Punctuation and accents remain intact in the canonical title');
 check(activate(first.id).state==='activated' && count()===initialCount+1,'Repeated activation is idempotent');
 check(sql('SELECT COUNT(*) n FROM ierb_workflows WHERE student_id=?',[first.id])[0].n===1,'First Student has exactly one authoritative workflow');
 check(sql("SELECT COUNT(*) n FROM research_project_events WHERE project_id=? AND event_key='pi_designated'",[p.id])[0].n===1,'Initial PI designation has one audited event');
 await expect(post(s.admin,'save',payload({research:first.p.research}),'students_api.php'),422,'Identical title requires an explicit duplicate-project decision');
 const duplicate=await saved(payload({research:first.p.research,duplicateTitleConfirmed:true}),'Administrator may explicitly create a distinct project with an identical title');ready(duplicate.id);activate(duplicate.id);
 check(project(duplicate.id).id!==p.id && project(duplicate.id).public_identifier!==p.public_identifier,'Duplicate titles never infer identity or merge projects');
 const longTitle=('Éducation, santé et Peñaflor — ').repeat(8).slice(0,255).trim();
 const long=await saved(payload({research:longTitle,duplicateTitleConfirmed:true,protocolCode:'CEU-INITIAL-'+tag}),'Long accented title is accepted without display truncation');ready(long.id);activate(long.id);
 check(project(long.id).research_title===longTitle && project(long.id).public_identifier.length<=163,'Long full title and bounded collision-safe identifier coexist');
 check(sql('SELECT protocol_code FROM research_project_protocols WHERE project_id=?',[project(long.id).id])[0].protocol_code==='CEU-INITIAL-'+tag,'Optional official code supplied during new-group onboarding becomes authoritative atomically');
 const longSearch=await response(await s.admin.get('/students_api.php?action=list&q='+encodeURIComponent(longTitle)));check(longSearch.status===200&&longSearch.body.students.some(r=>r.id===long.id),'Student Records searches a complete long accented research title');
 const join={group:'project:'+p.id,research:'Client title is ignored',adviserId:3,yearLevel:'2nd Year'};
 const second=await saved(payload(join),'Second Student may join with a different year level');ready(second.id);
 const races=await Promise.all([worker({action:'activate',id:second.id}),worker({action:'activate',id:second.id})]);
 check(races.every(r=>r.ok&&r.result.state==='activated'),'Concurrent onboarding activations both resolve to the one durable membership');
 check(project(second.id).id===p.id && sql('SELECT adviser_id,research_title FROM students WHERE id=?',[second.id])[0].adviser_id===2,'Joining inherits the authoritative title and Adviser despite forged fields');
 check(sql('SELECT COUNT(*) n FROM ierb_workflows WHERE student_id=?',[second.id])[0].n===0,'Joining creates no independent member workflow');
 const third=await saved(payload({...join,yearLevel:'1st Year'}),'Third Student assignment uses the existing project');ready(third.id);activate(third.id);
 check(sql('SELECT COUNT(*) n FROM research_project_members WHERE project_id=?',[p.id])[0].n===3,'Three verified members share the same canonical project');
 await expect(post(s.admin,'save',payload({...join,academicYear:'2025-2026'}),'students_api.php'),422,'Joining a different academic year is rejected');
 await expect(post(s.admin,'save',payload({...join,programKey:'bsa'}),'students_api.php'),422,'Joining a different catalog program is rejected');
 const options=await response(await s.admin.get('/students_api.php?'+new URLSearchParams({action:'group_options',...a,yearLevel:'1st Year'})));
 check(options.body.canonical && options.body.groups.some(g=>g.id===p.id),'Group dropdown matches program/year while ignoring year level');
 const similarGroups=options.body.groups.filter(g=>g.research_title===first.p.research);
 check(similarGroups.length===2&&new Set(similarGroups.map(g=>g.submission_holder_number)).size===2,'Identical unassigned titles expose distinct submission-holder identities for a deliberate group choice');

 const outsiderAdviser=await login('adviser3@example.test');
 const outsiderGroups=await response(await outsiderAdviser.get('/students_api.php?'+new URLSearchParams({action:'group_options',...a})));
 check(!outsiderGroups.body.groups.some(g=>g.id===p.id),'Adviser group choices respect the assigned Adviser scope');
 const m1=await login(first.p.email),m2=await login(second.p.email),m3=await login(third.p.email);
 check(sql('SELECT protocol_code FROM research_project_protocols WHERE project_id=?',[p.id]).length===0,'Protocol code starts unassigned');
 await expect(update(p,'protocol',code1),200,'Eligible Administrator assigns the official project protocol code');
 check(sql('SELECT DISTINCT protocol_code FROM students WHERE id IN (?,?,?)',[first.id,second.id,third.id]).map(r=>r.protocol_code).join()===code1,'All verified members receive the same protocol compatibility value');
 await expect(update(project(duplicate.id),'protocol',code1.toLowerCase()),409,'Case-insensitive duplicate code across unrelated projects is rejected');
 await expect(post(s.admin,'save',payload({protocolCode:code1}),'students_api.php'),422,'New-group onboarding refuses an official code already assigned to another project');
 await expect(update(p,'protocol','FORGED-ADVISER',s.adviser),403,'Adviser cannot change the official protocol code');
 await expect(update(p,'protocol','FORGED-STUDENT',m2),403,'Student cannot change the official protocol code');
 const edit={...first.p,id:first.id,group:p.public_identifier,protocolCode:'FORGED-CODE',isPrincipalInvestigator:true};
 await expect(post(s.admin,'save',edit,'students_api.php'),422,'Ordinary Student edit cannot independently change a project protocol code');
 await expect(post(s.admin,'save',{...edit,protocolCode:code1,research:'Silent group rename'},'students_api.php'),422,'Ordinary Student edit cannot rename the shared project');
 await expect(post(s.admin,'save',{...edit,protocolCode:code1,adviserId:3},'students_api.php'),422,'Ordinary Student edit cannot change one member Adviser');
 const report=await expect(post(s.admin,'project_report',{projectId:p.id},'reports_api.php'),200,'New group produces an existing project PDF and immutable report snapshot');
 const snapshot=sql('SELECT * FROM ierb_report_snapshots WHERE report_id=?',[report.body.report.id])[0];
 const reportFile='project_'+report.body.report.id+'.pdf';
 const pdfText=fx({action:'pdf-text',filename:reportFile});check(pdfText.includes('ONB001')&&pdfText.includes('Onboarding'),'Generated PDF prints the full research title as primary and the official code as secondary information');
 fs.copyFileSync(path.join(h.app,'storage/reports',reportFile),path.join(ev,'project-report.pdf'));
 const reportHash=crypto.createHash('sha256').update(fs.readFileSync(path.join(h.app,'storage/reports',reportFile))).digest('hex');
 check(snapshot.snapshot_json.includes(first.p.research) && snapshot.snapshot_json.includes(code1),'Report snapshot uses the full research title and secondary official protocol code');
 await expect(update(p,'protocol',code2),200,'Protocol correction requires an audited group operation');
 check(sql('SELECT snapshot_sha256 FROM ierb_report_snapshots WHERE report_id=?',[report.body.report.id])[0].snapshot_sha256===snapshot.snapshot_sha256,'Protocol correction never rewrites a historical report snapshot');
 check(project(first.id).public_identifier===p.public_identifier,'Protocol correction preserves the permanent internal project identity');
 check(crypto.createHash('sha256').update(fs.readFileSync(path.join(h.app,'storage/reports',reportFile))).digest('hex')===reportHash,'Protocol correction leaves the historical PDF bytes unchanged');
 const events=sql("SELECT evidence_json FROM research_project_events WHERE project_id=? AND event_key='protocol_code_changed' ORDER BY id",[p.id]);
 check(events.length===2 && JSON.parse(events[1].evidence_json).previousCode===code1,'Audit history preserves both prior and corrected official codes');
 for(const q of [first.p.research,'Peñaflor','ONB002',code2]){
  const r=await response(await s.admin.get('/students_api.php?action=list&perPage=100&q='+encodeURIComponent(q)));
  check(r.status===200 && r.body.students.some(r=>r.id===first.id),'Student Records searches title/code: '+q);
 }
 for(const endpoint of ['ierb_api.php?action=list','ierb_api.php?action=monitor']){
  const r=await response(await s.adviser.get('/'+endpoint+'&q=ONB002'));check(r.status===200 && JSON.stringify(r.body).includes('ONB002'),'Assigned Adviser search finds the shared protocol in '+endpoint);
  const deny=await response(await outsiderAdviser.get('/'+endpoint+'&q=ONB002'));check(deny.status===200 && !JSON.stringify(deny.body).includes('ONB002'),'Unassigned Adviser search reveals no project in '+endpoint);
 }
 const adviserSearch=await response(await s.admin.get('/advisers_api.php?action=list&q='+encodeURIComponent(code2)));check(adviserSearch.status===200&&adviserSearch.body.advisers.some(r=>r.id===2),'Adviser Records searches assigned project protocol codes with independent bound parameters');
 const adviserTitle=await response(await s.admin.get('/advisers_api.php?action=list&group='+encodeURIComponent(longTitle)));check(adviserTitle.status===200&&adviserTitle.body.advisers.some(r=>r.id===2),'Adviser group filters display and match full long research titles');
 await expect(update(p,'pi',second.id),200,'RPMS may change the PI through a reasoned group operation');
 check(sql('SELECT id FROM students WHERE id IN (?,?,?) AND is_principal_investigator=1',[first.id,second.id,third.id]).map(r=>r.id).join()===String(second.id),'PI designation remains group-wide and synchronized');
 await expect(update(p,'adviser',3),200,'RPMS may change the authoritative Adviser group-wide');
 check(sql('SELECT DISTINCT adviser_id FROM students WHERE id IN (?,?,?)',[first.id,second.id,third.id]).map(r=>r.adviser_id).join()==='3','Every member receives the authoritative Adviser change');
 await expect(update(p,'adviser',2),200,'Audited Adviser correction restores the approved assignment for workflow testing');
 await expect(update(p,'title',first.p.research+' - reviewed'),200,'Official research title changes use the audited group operation');
 check(sql('SELECT DISTINCT research_title FROM students WHERE id IN (?,?,?)',[first.id,second.id,third.id]).map(r=>r.research_title).join()===first.p.research+' - reviewed','Shared research title changes reach every member');
 check(sql('SELECT snapshot_sha256 FROM ierb_report_snapshots WHERE report_id=?',[report.body.report.id])[0].snapshot_sha256===snapshot.snapshot_sha256,'Title and Adviser corrections preserve historical report snapshots');
 const invite=async(extra,label)=>{const n=++serial;const r=await expect(post(s.admin,'invite',{accountType:'student',email:'invited'+n+'@example.test',group:'__create__',research:'Invited research '+n,adviserId:2,...a,...extra},'account_invitation_api.php'),200,label);return {id:r.body.id,email:'invited'+n+'@example.test',studentId:String(n)};};
 const invited=await invite({},'Invite Student stores a new-group assignment with secure invitation');
 check(!project(invited.id) && sql('SELECT COUNT(*) n FROM research_onboarding_assignments WHERE student_id=?',[invited.id])[0].n===1,'An abandoned invitation owns no project and grants no shared access');
 const token=fx({action:'token',id:invited.id}).token;
 sql('UPDATE account_invitations SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE),last_sent_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE) WHERE user_id=(SELECT user_id FROM students WHERE id=?)',[invited.id]);
 check(!raw({action:'accept',token}).ok,'Expired invitation cannot complete secure password setup');
 await expect(post(s.admin,'resend',{accountType:'student',targetId:invited.id},'account_invitation_api.php'),200,'Invitation resend rotates the existing token');
 check(sql('SELECT COUNT(*) n FROM students WHERE email=?',[invited.email])[0].n===1 && !project(invited.id),'Resend duplicates neither the pending account nor a project');
 check(!raw({action:'accept',token}).ok,'A superseded invitation token cannot be reused');
 fx({action:'accept',token:fx({action:'token',id:invited.id}).token});ready(invited.id);
 const invitedCtx=await login(invited.email);
 await expect(post(invitedCtx,'complete',{name:'Invited Synthetic Student',studentId:invited.studentId,...a,research:'Untrusted replacement title'},'account_invitation_api.php'),200,'Invited Student completes their own profile after secure setup');
 check(!!project(invited.id),'Profile completion itself finalizes the eligible approved project without a separate enrollment request');
 check(activate(invited.id).state==='activated' && project(invited.id).research_title.startsWith('Invited research'),'Profile completion activates only the staff-approved title and project');
 const mismatch=await invite({group:'project:'+p.id},'Invite Student securely records an intended existing project');
 fx({action:'accept',token:fx({action:'token',id:mismatch.id}).token});ready(mismatch.id);const mismatchCtx=await login(mismatch.email);
 await expect(post(mismatchCtx,'complete',{name:'Mismatched Student',studentId:mismatch.studentId,...a,programKey:'bsa'},'account_invitation_api.php'),200,'Academic profile remains recoverable when it differs from the intended project');
 check(activate(mismatch.id).state==='review' && !project(mismatch.id),'Academic mismatch requires Admin review and creates no membership');
 await expect(post(mismatchCtx,'assign',{targetId:mismatch.id,group:'project:'+p.id,...a},'account_invitation_api.php'),403,'Student cannot repair or alter their own official research assignment');
 const repaired={targetId:mismatch.id,expectedRevision:assign(mismatch.id).revision,group:'__create__',research:'RPMS reviewed academic mismatch as a separate approved project '+tag,adviserId:2,...a,programKey:'bsa'};
 await expect(post(s.admin,'assign',repaired,'account_invitation_api.php'),200,'Administrator explicitly reviews and corrects a mismatched pending assignment');
 check(activate(mismatch.id).state==='activated'&&project(mismatch.id).id!==p.id,'Only explicit Admin review resolves the held academic conflict');
 const joinInvite=await invite({group:'project:'+p.id},'Invite Student may select an existing eligible group');
 fx({action:'accept',token:fx({action:'token',id:joinInvite.id}).token});ready(joinInvite.id);const joinCtx=await login(joinInvite.email);
 await expect(post(joinCtx,'complete',{name:'Invited Existing Group Member',studentId:joinInvite.studentId,...a,yearLevel:'1st Year',research:'Forged invitation title'},'account_invitation_api.php'),200,'Invited existing-group member completes a matching profile at a different year level');
 check(activate(joinInvite.id).state==='activated'&&project(joinInvite.id).id===p.id,'Existing-group invitation finalizes membership without another project or workflow');
 const adviserInvite=await expect(post(s.adviser,'invite',{accountType:'student',email:'adviser-invited-'+tag+'@example.test'},'account_invitation_api.php'),200,'Existing Adviser invitation capability is retained');
 check(!assign(adviserInvite.body.id)&&!project(adviserInvite.body.id),'Adviser invitation alone never approves official canonical project membership');
 fx({action:'accept',token:fx({action:'token',id:adviserInvite.body.id}).token});ready(adviserInvite.body.id);const adviserInvitedCtx=await login('adviser-invited-'+tag+'@example.test');
 await expect(post(adviserInvitedCtx,'complete',{name:'Adviser Invited Synthetic Student',studentId:String(++serial),...a,research:'Typed title cannot grant project membership'},'account_invitation_api.php'),200,'An Adviser-invited Student can complete the existing account workflow');
 check(!project(adviserInvite.body.id),'A completed profile and typed title never invent canonical membership');
 await expect(upload(adviserInvitedCtx,'Study Protocol'),409,'An Adviser invitation cannot start a competing individual submission before RPMS research approval');
 await expect(post(s.admin,'assign',{targetId:adviserInvite.body.id,group:'__create__',research:'RPMS reviewed Adviser invitation '+tag,adviserId:2,...a},'account_invitation_api.php'),200,'Admin may approve an eligible complete Student through the existing research-assignment controls');
 check(!!project(adviserInvite.body.id),'Saving an eligible reviewed assignment activates it internally without a separate technical operation');

 for(const number of [2,3]){
  sql("INSERT INTO users(username,password_hash,role,full_name,email,ref_id,status) SELECT ?,password_hash,'admin',?,?,?,'Active' FROM users WHERE id=1",['ONBOARD-ADMIN-'+number+'-'+tag,'Synthetic Additional Admin '+number,'onboard-admin'+number+'-'+tag+'@example.test','ONBOARD-ADMIN-'+number+'-'+tag]);
  const uid=sql('SELECT id FROM users WHERE email=?',['onboard-admin'+number+'-'+tag+'@example.test'])[0].id;
  sql('INSERT INTO user_policy_acceptances(user_id,policy_version_id) SELECT ?,policy_version_id FROM user_policy_acceptances WHERE user_id=1',[uid]);
  const adminCtx=await login('onboard-admin'+number+'-'+tag+'@example.test');
  const created=await expect(post(adminCtx,'save',payload({research:'Equal RPMS Administrator authority '+number+' '+tag}),'students_api.php'),200,'Active eligible RPMS Administrator '+number+' has the same onboarding authority');
  ready(created.body.id);
  if(number===2){sql("UPDATE users SET status='Inactive' WHERE id=?",[uid]);check(!raw({action:'activate',id:created.body.id}).ok&&!project(created.body.id),'Revoked approving Administrator authority cannot activate membership');sql("UPDATE users SET status='Active' WHERE id=?",[uid]);}
  check(activate(created.body.id).state==='activated','Equal Administrator approval activates after live authority revalidation '+number);
 }
 const cancelled=await invite({group:'project:'+p.id},'Pending existing-group invitation is recoverable in Student Records');
 await expect(post(s.admin,'assign',{targetId:cancelled.id,cancelAssignment:true,expectedRevision:1},'account_invitation_api.php'),200,'Administrator may cancel a pending assignment using existing controls');
 fx({action:'accept',token:fx({action:'token',id:cancelled.id}).token});ready(cancelled.id);
 check(activate(cancelled.id).state==='cancelled' && !project(cancelled.id),'Cancelled assignment cannot create membership after password acceptance');
 const retry=await saved(payload({research:'Interrupted atomic setup recovery '+tag}),'Interrupted setup has a durable approved assignment');ready(retry.id);
 const beforeFailure=count();sql("CREATE TRIGGER onboarding_fault BEFORE INSERT ON research_project_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit persistence fault'");
 try{check(!raw({action:'activate',id:retry.id}).ok,'Mandatory project-audit failure refuses activation');}finally{sql('DROP TRIGGER onboarding_fault');}
 check(count()===beforeFailure && !project(retry.id) && sql('SELECT COUNT(*) n FROM ierb_workflows WHERE student_id=?',[retry.id])[0].n===0,'Failed transaction leaves no project, workflow or membership');
 check(activate(retry.id).state==='activated','Retry safely recovers the interrupted assignment');
 check(!raw({action:'retained-rollback'}).ok,'Retained assignment and protocol evidence prevents extension rollback');
 await expect(post(s.admin,'save',payload(),'ierb_api.php'),422,'Add IERB Entry routes creation through Student Records');

 // All four stages on the automatically created project, through the unchanged document APIs.
 const checklist=await response(await m2.get('/documents_api.php?action=requirements&studentId='+second.id));
 const initial=checklist.body.requirements.filter(r=>r.stage==='initial_submission');check(initial.length===13,'Automatically created group retains all 13 mandatory Initial Submission requirements');
 const rev=()=>project(first.id).wf_revision;
 const accept=(key,id,extra={})=>post(s.admin,'accept_requirement',{studentId:first.id,requirementKey:key,documentId:id,revision:rev(),reason:'Synthetic institutional acceptance of reviewed evidence.',requestKey:crypto.randomUUID(),...extra},'documents_api.php');
 const docs={};
 for(const [i,r] of initial.entries()){
  const up=await expect(upload([m1,m2,m3][i%3],r.label),200,'Verified member uploads initial requirement '+r.key);docs[r.key]=up.body.document.id;
  await expect(post(s.adviser,'review',{id:docs[r.key],status:'Approved',remarks:'Synthetic Adviser review.'},'documents_api.php'),200,'Assigned Adviser reviews and formally submits '+r.key);
  await expect(accept(r.key,docs[r.key]),200,'RPMS accepts exact current evidence '+r.key);
 }
 check(project(first.id).stage_key==='initial_submission','Accepting all 13 documents alone does not advance the project');
 const late=payload({...join,membershipConfirmed:false});await expect(post(s.admin,'save',late,'students_api.php'),422,'Joining a project with evidence requires explicit reason and confirmation');
 const fourth=await saved(payload({...join,membershipConfirmed:true,membershipReason:'RPMS reviewed adding this member to existing submitted research evidence.'}),'Reasoned, confirmed membership may join without replacing shared evidence');ready(fourth.id);
 const membershipRace=await Promise.all([worker({action:'activate',id:fourth.id}),post(m3,'submit_to_rpms',{id:docs.protocol,versionNo:1},'documents_api.php')]);
 check(membershipRace[0].ok&&membershipRace[0].result.state==='activated'&&membershipRace[1].status===409,'Concurrent member activation and duplicate formal submission preserve one workflow and one membership');
 check(sql('SELECT COUNT(*) n FROM documents WHERE student_id=?',[first.id])[0].n===13 && project(fourth.id).stage_key==='initial_submission','Adding a later member preserves all documents and the current stage');
 const fourthCtx=await login(fourth.p.email);
 const archiveMember=(id,extra={})=>post(s.admin,'archive',{action:'archive',accountType:'student',targetId:id,expectedProjectRevision:project(first.id).revision,...extra},'account_lifecycle_api.php');
 await expect(archiveMember(fourth.id),409,'Canonical member archival requires a reason and explicit confirmation');
 await expect(archiveMember(second.id,{confirmed:true,reason:'Synthetic reviewed PI archival attempt.'}),409,'The designated PI requires a reviewed transition before archival');
 await expect(archiveMember(fourth.id,{confirmed:true,reason:'Synthetic RPMS reviewed ordinary member account archival; retain shared research evidence.'}),200,'Reviewed ordinary member archival preserves canonical membership and evidence');
 check(sql('SELECT COUNT(*) n FROM documents WHERE student_id=?',[first.id])[0].n===13&&project(first.id).stage_key==='initial_submission','Member archival neither deletes shared files nor resets the other members progress');
 await expect(response(await fourthCtx.get('/documents_api.php?action=file&id='+docs.protocol)),401,'The archived member loses authenticated shared-document access');
 const pendingCtx=await login(cancelled.email);
 await expect(response(await pendingCtx.get('/documents_api.php?action=file&id='+docs.protocol)),403,'An incomplete invitation cannot access shared project evidence');
 const outsider=await login(duplicate.p.email);
 await expect(response(await outsider.get('/documents_api.php?action=file&id='+docs.protocol)),403,'Cross-project shared-document download is denied');
 const preview=await expect(post(s.admin,'group_advance_preview',{groupKey:crypto.createHash('sha256').update('project:'+p.id).digest('hex')}),200,'RPMS reviews the one shared project before official advancement');check(preview.body.eligible,'All 13 accepted requirements satisfy the existing progression gate');
 await expect(post(s.admin,'group_advance',{confirmationToken:preview.body.confirmationToken,reason:'Synthetic RPMS confirmed every initial requirement.',confirmed:true}),200,'RPMS advances the entire project to Approval of Data Gathering');
 check(project(fourth.id).stage_key==='approval_data_gathering','Later members inherit the same authoritative project progression');
 const f33=(await expect(upload(m1,'Form 33 - Certificate of Approval'),200,'Form 33 remains required institutional evidence')).body.document.id;
 await expect(post(s.adviser,'review',{id:f33,status:'Approved',remarks:'Synthetic Adviser review.'},'documents_api.php'),200,'Adviser approves Form 33 through existing review');
 await expect(accept('form33',f33),422,'Form 33 requires the institutional approval reference');
 await expect(accept('form33',f33,{institutionalApprovalReference:'SYNTHETIC-ONBOARDING-APPROVAL'}),200,'RPMS records the institutional Form 33 approval');
 for(const amendment of [false,true]){
  const affected=docs.protocol;
  await expect(post(s.admin,'v14_correction_open',{studentId:first.id,revision:rev(),reason:'Synthetic institutional correction review.',documentIds:[affected],requiresAmendment:amendment,requestKey:crypto.randomUUID()}),200,'RPMS opens the conditional correction stage; Amendment required = '+amendment);
  check(project(first.id).stage_key==='resubmission_amendment','Correction case synchronizes the existing project stage');
  await expect(post(s.admin,'return_for_revision',{id:affected,reason:'Synthetic RPMS requested a corrected protocol.'},'documents_api.php'),200,'RPMS returns the affected current protocol version');
  const corrected=(await expect(upload(m2,'Study Protocol',affected),200,'Non-holder member uploads the corrected protocol')).body.document.id;docs.protocol=corrected;
  await expect(post(s.adviser,'review',{id:corrected,status:'Approved',remarks:'Synthetic corrected protocol review.'},'documents_api.php'),200,'Assigned Adviser reviews the corrected version');
  await expect(accept('protocol',corrected),200,'RPMS accepts the corrected protocol');
  if(amendment){
   await expect(post(s.admin,'v14_correction_resolve',{studentId:first.id,revision:rev(),reason:'Synthetic attempted resolution before amendment.',requestKey:crypto.randomUUID()}),409,'Required Amendment Form blocks premature correction resolution');
   const form=(await expect(upload(m3,'Amendment Form'),200,'Amendment is uploaded only when RPMS requires it')).body.document.id;
   await expect(post(s.adviser,'review',{id:form,status:'Approved',remarks:'Synthetic amendment review.'},'documents_api.php'),200,'Adviser reviews the conditional Amendment Form');
   await expect(accept('amendment',form),200,'RPMS accepts the required Amendment Form');
  }
  await expect(post(s.admin,'v14_correction_resolve',{studentId:first.id,revision:rev(),reason:'Synthetic RPMS verified the corrected evidence.',requestKey:crypto.randomUUID()}),200,'RPMS resolves the conditional case using its required evidence');
  check(project(first.id).stage_key==='approval_data_gathering','Correction resolution returns every member to the interrupted review stage');
 }
 const final=(await expect(upload(m3,'Form 33-0B - Final Report Form'),200,'Non-PI member uploads the final Form 33-0B report')).body.document.id;
 await expect(post(s.adviser,'review',{id:final,status:'Approved',remarks:'Synthetic final approval.'},'documents_api.php'),200,'Eligible Final Report formal submission completes the shared project');
 check(sql('SELECT stage_key FROM research_project_members WHERE project_id=?',[p.id]).every(r=>r.stage_key==='ierb_completed'),'Every verified member shares the one IERB completion');
 await expect(post(m3,'submit_to_rpms',{id:final,versionNo:1},'documents_api.php'),409,'Duplicate Final Report submission cannot complete the project twice');
 const completedRecords=await response(await s.admin.get('/students_api.php?action=list&q='+encodeURIComponent(code2)));
 check(completedRecords.status===200&&completedRecords.body.students.length>=4&&completedRecords.body.students.every(r=>r.projectStage==='ierb_completed'&&r.stageLabel==='IERB Completed'&&r.progress===100),'Student Records displays the authoritative completed stage and progress for every member without rewriting legacy stages');


 for(const [field,value] of [['stage','ierb_completed'],['status','IERB Completed']]){
  const filtered=await response(await s.admin.get('/students_api.php?action=list&'+new URLSearchParams({q:code2,[field]:value})));
  check(filtered.status===200&&filtered.body.students.length>=4,'Student Records '+field+' filter follows the canonical completion instead of the legacy snapshot');
 }
 const oldStage=await response(await s.admin.get('/students_api.php?action=list&'+new URLSearchParams({q:code2,stage:'Stage 1'})));
 check(oldStage.status===200&&oldStage.body.students.length===0,'Completed canonical members are not classified as legacy Stage 1 records');
 await expect(post(s.admin,'save',payload({...join,membershipConfirmed:true,membershipReason:'Synthetic attempted late addition to completed project.'}),'students_api.php'),409,'Completed project cannot automatically accept a new member');
 check(!raw({action:'archive',id:first.id}).ok && !raw({action:'archive',id:third.id}).ok,'Canonical holder and member archival require reviewed transitions');
 check(sql('SELECT COUNT(*) n FROM documents WHERE student_id=?',[first.id])[0].n>=15 && project(first.id).stage_key==='ierb_completed','Refused archival preserves shared documents and completed progress');

 await require('./browser.cjs')(h);
 fs.writeFileSync(path.join(ev,'focused-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start)},null,2));
 fs.rmSync(flag,{force:true});
};
