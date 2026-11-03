'use strict';
// Batch 8 security matrix on the completed V14 fixture (disposable database, loopback server only):
//  1. every root page/API: allowed roles are read from the file's own login guard; every other role and anonymous must be refused;
//  2. state-changing endpoints refuse a foreign Origin and anonymous callers, and change nothing;
//  3. direct requests to library files leak neither secrets nor stack traces.
const fs=require('node:fs'),path=require('node:path');
const LIBRARIES=new Set(['config.php','security.php','workflow.php','ai_helpers.php','auth_rate_limit.php','admin_people.php']);
// Intentionally reachable without a session: sign-in, registration, password reset, public policy and landing pages.
const PUBLIC=new Set(['index.php','login.php','login_admin.php','login_adviser.php','login_students.php','login_process.php','register.php','register_process.php',
 'forgot_password.php','forgot_password_process.php','reset_password.php','update_password.php','account_setup.php','privacy.php','terms.php','loading.php','logout.php']);
// Pages whose guard lives in an included file or in a session-flag redirect (each verified by reading the source).
const DELEGATED={'admin_students.php':['admin','adviser'],'admin_advisers.php':['admin'],'admin_archived_accounts.php':['admin'],'change_password_required.php':['admin','adviser','student']};
module.exports=async h=>{
 const {base,sessions:s,check,review,root,sql,playwright}=h,start=h.checks.length;
 const anon=await playwright.request.newContext({baseURL:base});
 const results={matrix:[],findings:[]};
 try{
  // ---- 1. access matrix
  const guardOf=source=>{
   const m=source.match(/(?:api_)?require_login\s*\(([^)]*)\)/);
   if(!m)return null;
   const roles=['admin','adviser','student'].filter(r=>new RegExp("['\"]"+r+"['\"]").test(m[1]));
   return roles.length?roles:['admin','adviser','student'];
  };
  const files=fs.readdirSync(root).filter(f=>f.endsWith('.php')&&!LIBRARIES.has(f)&&f!=='logout.php').sort();
  const ctxs={anonymous:anon,student:s.student,adviser:s.adviser,admin:s.admin};
  const violations=[],serverErrors=[],unguarded=[];
  for(const file of files){
   const allowed=PUBLIC.has(file)?null:(DELEGATED[file]||guardOf(fs.readFileSync(path.join(root,file),'utf8')));
   if(allowed===null&&!PUBLIC.has(file)){unguarded.push(file);}
   const row={file,allowed:allowed||(PUBLIC.has(file)?'public':'NONE'),status:{}};
   for(const [role,ctx] of Object.entries(ctxs)){
    const r=await ctx.get('/'+file,{maxRedirects:0,failOnStatusCode:false});
    const status=r.status();row.status[role]=status;
    const body=status===200?await r.text():'';
    const jsonRefusal=/^\s*\{\s*"ok"\s*:\s*false/.test(body);
    if(status>=500)serverErrors.push({file,role,status});
    if(allowed&&!allowed.includes(role)&&status===200&&!jsonRefusal)violations.push({file,role,status});
   }
   results.matrix.push(row);
  }
  check(!violations.length,'Access matrix: no page or API serves a role its own login guard excludes ('+results.matrix.length+' files x 4 identities): '+JSON.stringify(violations));
  check(!unguarded.length,'Access matrix: every non-public root file has a login guard: '+JSON.stringify(unguarded));
  check(!serverErrors.length,'Access matrix: no page or API fails with a server error on its default request: '+JSON.stringify(serverErrors));
  results.findings.push({unguarded,serverErrors,violations});

  // ---- 2. cross-origin and anonymous state changes
  const counts=()=>JSON.stringify([sql('SELECT COUNT(*) n FROM reports')[0].n,sql('SELECT COUNT(*) n FROM academic_years')[0].n,
   Number(sql("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prism_settings'")[0].n)===0?0:sql('SELECT COUNT(*) n FROM prism_settings')[0].n,
   sql('SELECT COUNT(*) n FROM students')[0].n,sql('SELECT COUNT(*) n FROM notifications')[0].n,sql('SELECT COUNT(*) n FROM documents')[0].n]);
  const mutating=[
   ['reports_api.php?action=generate',{type:'Student Report',studentId:4}],['reports_api.php?action=ai_report',{mode:'summarized'}],['reports_api.php?action=delete',{id:'x'}],
   ['admin_settings_api.php?action=save-reminders',{intervals:[3]}],['admin_settings_api.php?action=save-template',{templateKey:'x',template:'{message}'}],
   ['academic_years_api.php?action=add',{academicYear:'2090-2091'}],['academic_years_api.php?action=remove',{academicYear:'2025-2026'}],
   ['students_api.php?action=save',{name:'x'}],['students_api.php?action=delete',{id:4}],['advisers_api.php?action=save',{name:'x'}],
   ['documents_api.php?action=delete',{id:'x'}],['documents_api.php?action=review',{id:'x',status:'Approved'}],
   ['notifications_api.php?action=send',{}],['ierb_api.php?action=group_advance',{}],['profile_api.php?action=update_profile',{name:'x'}],
   ['account_invitation_api.php?action=resend',{accountType:'student',targetId:4}],
  ];
  const before=counts();const origin=[],anonymous=[];
  for(const [url,body] of mutating){
   const foreign=await s.admin.post('/'+url,{data:body,headers:{origin:'https://evil.example.test'},failOnStatusCode:false});
   if(foreign.status()!==403)origin.push([url,foreign.status()]);
   const noSession=await anon.post('/'+url,{data:body,headers:{origin:base},failOnStatusCode:false});
   if(![401,403].includes(noSession.status()))anonymous.push([url,noSession.status()]);
  }
  check(!origin.length,'Foreign-origin POST from an Admin session is refused (403) by every state-changing endpoint: '+JSON.stringify(origin));
  check(!anonymous.length,'Anonymous POST is refused (401/403) by every state-changing endpoint: '+JSON.stringify(anonymous));
  check(counts()===before,'Refused cross-origin and anonymous requests changed no report, year, setting, Student, notification or document row');
  // Role boundary on Admin-only mutations with a valid origin.
  const roleBreaks=[];
  for(const [role,ctx] of [['student',s.student],['adviser',s.adviser]]){
   for(const [url,body] of mutating.filter(([u])=>/^(reports_api|admin_settings_api|academic_years_api)\./.test(u))){
    const r=await ctx.post('/'+url,{data:body,headers:{origin:base},failOnStatusCode:false});
    if(![401,403].includes(r.status()))roleBreaks.push([role,url,r.status()]);
   }
  }
  check(!roleBreaks.length,'Students and Advisers are refused (401/403) by every Admin-only Reports, Settings and Academic Year mutation: '+JSON.stringify(roleBreaks));
  check(counts()===before,'Refused role-boundary requests changed nothing');
  results.findings.push({origin,anonymous,roleBreaks});

  // ---- 3. direct requests to library and include files
  const leaks=[];
  for(const file of [...LIBRARIES,'includes/notification_worker.php','includes/notification_delivery.php','includes/admin_settings_store.php','includes/runtime_preflight.php']){
   const r=await anon.get('/'+file,{maxRedirects:0,failOnStatusCode:false});const body=await r.text();
   if(/prism_synthetic_only|DB_PASS|GMAIL_REFRESH|Stack trace|Fatal error|Warning:|Notice:/.test(body))leaks.push([file,r.status()]);
  }
  check(!leaks.length,'Direct requests to library files leak no credentials, warnings or stack traces: '+JSON.stringify(leaks));
  results.findings.push({leaks});
 }finally{await anon.dispose();}
 fs.writeFileSync(path.join(review,'security-audit-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,matrix:results.matrix,findings:results.findings,cases:h.checks.slice(start)},null,2));
};
