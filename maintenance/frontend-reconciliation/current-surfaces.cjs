'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
module.exports=async h=>{
 const {check,sessions:s,post,response,sql,root,review,php}=h;
 const start=h.checks.length;
 const groups=await response(await s.admin.get('/ierb_api.php?action=group_advance_list'));
 check(groups.status===200&&groups.body.groups.length===2&&groups.body.groups.every(g=>g.identity.project_id),'Group Advancement offers canonical project identities exclusively');
 const retired=await post(s.admin,'v14_correction_decide',{});
 check(retired.status===410,'The obsolete Adviser-request decision route is retired');
 const distribution=await response(await s.admin.get('/ierb_api.php?action=stage_distribution'));
 check(distribution.status===200&&distribution.body.distribution.length===4&&distribution.body.distribution.reduce((n,r)=>n+Number(r.c),0)===2,'Stage distribution counts the two canonical projects in exactly four stages');
 const students=await response(await s.admin.get('/students_api.php?action=list&perPage=100'));
 check(students.status===200&&students.body.filterOptions.stage.length===4,'Student Records filters expose only the four current V14 stages');
 const unassigned=students.body.students?.find(r=>r.id===7)||students.body.records?.find(r=>r.id===7);
 check(unassigned?.stage===null&&unassigned.stageLabel==='Research setup pending'&&unassigned.progress===0,'Unassigned profiles cannot acquire a historical stage or percentage in Student Records');
 const documents=await response(await s.admin.get('/documents_api.php?action=list&perPage=100'));
 check(documents.status===200&&documents.body.filterOptions.stages.length===4,'Document stage filters contain exactly the four semantic V14 stages');
 const final=documents.body.documents.find(d=>d.documentType==='Form 33-0B - Final Report Form');
 check(final?.stageLabel==='IERB Completed','The Final Report document carries the current Stage 4 label');
 const filtered=await response(await s.admin.get('/documents_api.php?action=list&stage=ierb_completed&perPage=100'));
 check(filtered.status===200&&filtered.body.documents.length===1&&filtered.body.documents[0].id===final.id,'The semantic Stage 4 document filter resolves the retained numeric storage bucket correctly');
 check((await s.admin.get('/documents_api.php?action=list&stage=Stage%205')).status()===422,'Obsolete numeric stage filters are rejected');
 const preview=await response(await s.admin.get('/documents_api.php?action=delete_preview&id='+encodeURIComponent(final.id)));
 check(preview.status===200&&preview.body.document.stageLabel==='IERB Completed','Retained document confirmation details use current semantic labels');
 for(const role of ['adviser','student']){
  check((await s[role].get('/reports_api.php?action=project_options')).status()===403,role+' cannot read confidential RPMS report options');
 }
 const options=await response(await s.admin.get('/reports_api.php?action=project_options'));
 check(options.status===200&&options.body.projects.length===2,'Current project reports exclude unenrolled numeric-stage records');
 fs.writeFileSync(path.join(review,'sandbox/provider-scenario.txt'),'network');
 const projectId=sql('SELECT id FROM research_projects WHERE submission_holder_id=4')[0].id;
 for(const [action,body] of [['project_report',{projectId}],['project_aggregate',{}],['ai_report',{mode:'summary'}],['ai_report',{mode:'full'}]]){
  const generated=await post(s.admin,action,body,'reports_api.php');
  check(generated.status===200,'Current '+action+(body.mode?' '+body.mode:'')+' generates with the isolated provider fallback');
  const reportId=generated.body.report.id;
  const file=await s.admin.get('/reports_api.php?action=file&id='+reportId);
  check(file.status()===200&&(await file.body()).subarray(0,5).toString()==='%PDF-','Authorized download returns the generated '+action+' PDF');
  for(const role of ['adviser','student'])check((await s[role].get('/reports_api.php?action=file&id='+reportId)).status()===403,role+' cannot download the confidential '+action+' PDF');
  if(action==='project_aggregate'){
   const target=path.join(review,'current-institutional-report.pdf');fs.writeFileSync(target,await file.body());
   const script="require $argv[1].'/vendor/autoload.php'; echo (new Smalot\\PdfParser\\Parser())->parseFile($argv[2])->getText();";
   const content=cp.execFileSync(php,['-r',script,root,target],{encoding:'utf8',windowsHide:true});
   check(!/Legacy Student Stage Distribution|Stage 5|Historical Student Stage Records/.test(content)&&content.includes('IERB Completed'),'New institutional report contains current V14 stages without legacy distributions');
  }
  const snapshot=sql('SELECT snapshot_json,snapshot_sha256 FROM ierb_report_snapshots WHERE report_id=?',[reportId])[0];
  if(snapshot)check(crypto.createHash('sha256').update(snapshot.snapshot_json).digest('hex')===snapshot.snapshot_sha256,'Retained '+action+' snapshot has a matching SHA256');
 }
 check((await post(s.admin,'generate',{type:'progress'},'reports_api.php')).status===410,'The obsolete numeric-stage report generation route is retired');
 fs.writeFileSync(path.join(review,'current-surface-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start)},null,2));
};
