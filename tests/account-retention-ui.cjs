/* Fixture model + focused browser checks, invoked by ui-audit.cjs --retention-only. */
'use strict';
const stateOptions={lifecycle:[['active','Active'],['archived','Archived'],['all','All']],retention:[['grace','Within 7-day grace period'],['manual','Eligible for manual purge'],['approaching','Approaching 6-month retention'],['cleanup','Eligible for retention cleanup'],['hold','Retention Hold'],['postponed','Purge postponed / Admin review required']]};
const selections=new Map(),previews=new Map();let tokenId=0,gate=true,stale=false,recoveryMissing=false;
function records(type) {
  return Array.from({length:43},(_,i)=>{
    const id=i+1,active=id===1,held=id===4,open=id===5,grace=id===2;
    const lc={archivedAt:active?null:'2026-03-01 10:00:00',manualPurgeAt:'2026-10-16 10:00:00',retentionAt:'2026-09-01 10:00:00',retentionHold:held,unresolvedWorkflow:open,
      approachingRetention:id===7,manualEligible:!active&&!held&&!open&&!grace,cleanupEligible:id===6,overrideAvailable:grace,
      purgeBlockReason:active?'Archive first.':held?'Retention Hold.':open?'Purge postponed — Admin review required: unresolved workflow.':grace?'Permanent deletion available on October 16, 2026.':''};
    return {id,studentId:id===8?'S'.repeat(97)+'008':'S'+String(id).padStart(3,'0'),employeeId:id===8?'E'.repeat(97)+'008':'E'+String(id).padStart(3,'0'),name:id===3?'<img src=x onerror="window.__fixtureXss=1">'+'Long Name '.repeat(25):'Record '+id,
      email:'r'+id+'@example.invalid',research:'Historical research',course:'BS in Information Technology',academicUnitKey:'amt',programKey:'bsit',academicYear:'2026-2027',yearLevel:'2nd Year',
      group:'AMT-BSIT-Y2-2627-G01',department:'AMT',groups:[],stage:'Stage 1',status:type==='adviser'?(active?'Active':'Inactive'):'On Track',archivedAt:lc.archivedAt,lifecycle:lc,assignedStudents:active?3:0};
  });
}
function filtered(type,filters={}) {
  return records(type).filter(r=>{
    if(filters.lifecycle==='active'&&r.archivedAt)return false;if(filters.lifecycle==='archived'&&!r.archivedAt)return false;
    if(filters.q&&!JSON.stringify([r.studentId,r.employeeId,r.name,r.email]).toLowerCase().includes(filters.q.toLowerCase()))return false;
    const lc=r.lifecycle;
    if(filters.retention==='manual'&&!lc.manualEligible)return false;if(filters.retention==='cleanup'&&!lc.cleanupEligible)return false;
    if(filters.retention==='hold'&&!lc.retentionHold)return false;if(filters.retention==='postponed'&&!lc.unresolvedWorkflow)return false;
    if(filters.retention==='grace'&&!lc.overrideAvailable)return false;if(filters.retention==='approaching'&&!lc.approachingRetention)return false;return true;
  });
}
function mockList(file,query) {
  const type=file==='advisers_api.php'?'adviser':'student';let rows=filtered(type,Object.fromEntries(query));const total=rows.length;
  if(query.get('direction')==='DESC')rows.reverse();const page=Math.max(1,Math.min(Math.max(1,Math.ceil(total/10)),Number(query.get('page'))||1));
  const filterOptions=Object.fromEntries(Object.entries(stateOptions).map(([key,options])=>[key,options.map(([value,label])=>({value,label}))]));
  for(const [key,value] of Object.entries({academicUnitKey:'amt',programKey:'bsit',academicYear:'2026-2027',yearLevel:'2nd Year',group:'AMT-BSIT-Y2-2627-G01',stage:'Stage 1',status:type==='adviser'?'Inactive':'On Track',department:'AMT'}))filterOptions[key]=[{value,label:value}];
  filterOptions.adviserId=[{value:'__blank__',label:'Unassigned'}];filterOptions.protocol=[];
  return {ok:true,[type==='adviser'?'advisers':'students']:rows.slice((page-1)*10,page*10),total,page,limit:10,filterOptions};
}
function mockApi(file,action,request) {
  if(file!=='account_lifecycle_api.php')return null;
  let body={};try{body=JSON.parse(request?.body||'{}');}catch(_){}
  const command=action||body.action;
  if(command==='availability')return {ok:true,available:gate,message:gate?'Schema verification is valid.':'Schema verification expired.'};
  if(command==='cleanup_summary')return {ok:true,eligibleStudents:1,eligibleAdvisers:1,automaticDeletion:false};
  if(command==='recovery_jobs')return {ok:true,jobs:recoveryMissing?[{jobId:'a'.repeat(32),accountType:'student',targetId:3,manifestAvailable:false}]:[]};
  if(command==='recover'&&recoveryMissing)return {ok:false,message:'Recovery journal is unavailable; operator review required.'};
  if(command==='account_state'){const r=records(request.query.accountType).find(r=>r.id===Number(request.query.targetId));return {ok:true,lifecycle:r.lifecycle,assignedStudents:r.assignedStudents};}
  if(command==='selection'){const ids=filtered(body.accountType,body.filters).map(r=>r.id),token='selection-'+(++tokenId);selections.set(token,{type:body.accountType,ids});return {ok:true,ids,total:ids.length,selectionToken:token};}
  if(command==='bulk_preview'){
    const selection=selections.get(body.selectionToken);const ids=body.selectionMode==='individual'?body.ids:selection.ids;
    const rows=records(selection.type).filter(r=>ids.includes(r.id));const eligible=rows.filter(r=>body.bulkAction==='archive'?!r.archivedAt:['permanent_delete','retention_cleanup'].includes(body.bulkAction)?(body.bulkAction==='retention_cleanup'?r.lifecycle.cleanupEligible:r.lifecycle.manualEligible):!!r.archivedAt);
    const skipped=rows.filter(r=>!eligible.includes(r)).map(r=>({id:r.id,identifier:r.studentId,reason:r.lifecycle.purgeBlockReason || 'Archive first'}));const token='preview-'+(++tokenId);
    const phrase=(['permanent_delete','retention_cleanup'].includes(body.bulkAction)?'PURGE':'APPLY')+' '+eligible.length+' ACCOUNTS';
    previews.set(token,{ids,eligible:eligible.map(r=>r.id),action:body.bulkAction});
    return {ok:true,previewToken:token,selected:ids.length,eligible:eligible.length,skipped,phrase,unassignedStudents:body.bulkAction==='archive'&&selection.type==='adviser'?eligible.reduce((n,r)=>n+r.assignedStudents,0):0,batchSize:25};
  }
  if(command==='bulk_execute'){
    if(stale)return {ok:false,message:'Eligible population changed. Review a fresh preview.'};
    const p=previews.get(body.previewToken),next=Math.min(p.ids.length,body.cursor+25),part=p.ids.slice(body.cursor,next),done=p.ids.slice(0,next);
    return {ok:true,done:next===p.ids.length,nextCursor:next,selected:p.ids.length,completed:done.filter(id=>p.eligible.includes(id)).length,skipped:done.filter(id=>!p.eligible.includes(id)).length,recoveryRequired:0,
      results:part.map(id=>({id,identifier:'S'+String(id).padStart(3,'0'),outcome:p.eligible.includes(id)?'completed':'skipped',reason:p.eligible.includes(id)?'Committed':'Blocked by server'}))};
  }
  return {ok:true,message:'Lifecycle operation completed.'};
}
async function run(ctx) {
  const {check,evaluate,waitFor,navigate,command,keyPress,setManagement,getRequests,errors}=ctx;
  for(const type of ['student','adviser'])for(const [width,height] of [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]])for(const dark of [false,true]) {
    setManagement(type);await navigate(type==='student'?'admin_students.php':'admin_advisers.php',width,dark,'populated','admin');
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    await waitFor('document.querySelectorAll("[data-retention-id]").length===10 && document.getElementById("permanentDeleteAvailability").textContent.includes("is valid")');
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),`${type} ${width}×${height} ${dark?'dark':'light'} page fits`);
    check(await evaluate('document.getElementById("recordFilters_lifecycle").options.length===4 && document.getElementById("recordFilters_retention").options.length===7'),'All lifecycle filters available');
    check(await evaluate('[...document.querySelectorAll("[data-retention-id]")].every(b=>!!b.getAttribute("aria-label"))'),'Accessible individual checkboxes');
    check(await evaluate('!window.__fixtureXss && !document.querySelector("#recordRows img")'),'Long hostile identity stays literal');
    check(await evaluate('document.querySelector("#recordRows tr:first-child .lifecycle-danger").getAttribute("aria-disabled")==="true"'),'Active delete disabled');
    check(await evaluate('document.querySelectorAll("#recordRows tr:nth-child(2) .lifecycle-danger").length===2 && document.querySelector("#recordRows tr:nth-child(2) .retention-state").textContent.includes("October 16")'),'Grace date and single override visible');
    await evaluate(`document.querySelector('#recordRows tr:nth-child(3) button[aria-label^="Permanently Delete"]').focus();document.querySelector('#recordRows tr:nth-child(3) button[aria-label^="Permanently Delete"]').click()`);
    await waitFor('document.getElementById("permanentDeleteDialog").open');
    const geometry=await evaluate(`(()=>{const d=document.getElementById('permanentDeleteDialog'),r=d.getBoundingClientRect();return {w:r.width,h:r.height,left:r.left,right:r.right,top:r.top,bottom:r.bottom,scroll:getComputedStyle(d).overflowY,reason:document.getElementById('permanentDeleteReasonGroup').getBoundingClientRect().height};})()`);
    check(geometry.w<=760.1&&geometry.left>=0&&geometry.right<=width+1&&geometry.top>=0&&geometry.bottom<=height+1&&geometry.scroll==='auto',`${type} modal fits and scrolls`,JSON.stringify(geometry));
    check(geometry.reason===0,'Normal purge hides override reason');
    check(await evaluate('!document.getElementById("permanentDeleteForm").textContent.includes("unused test")'),'Obsolete test-record acknowledgement removed');
    await evaluate('document.querySelector("[data-lifecycle-password]").click()');check(await evaluate('document.getElementById("permanentDeletePassword").type==="text"'),'Password eye works');
    await evaluate('document.getElementById("cancelPermanentDelete").focus()');await keyPress('Tab','Tab',9);
    check(await evaluate('document.activeElement.type==="submit"'),'Native dialog keyboard order');
    await keyPress('Escape','Escape',27);check(await evaluate('!document.getElementById("permanentDeleteDialog").open && document.activeElement.getAttribute("aria-label").startsWith("Permanently Delete")'),'Escape restores trigger focus');
    await evaluate(`[...document.querySelectorAll("#recordRows tr:nth-child(2) button")].find(b=>b.title.includes("Override Grace Period")).click()`);await waitFor('document.getElementById("permanentDeleteDialog").open');
    check(await evaluate('document.getElementById("permanentDeleteForm").elements.reason.required && !document.getElementById("permanentDeleteReasonGroup").hidden'),'Single override requires visible reason');
    await keyPress('Escape','Escape',27);
  }
  setManagement('student');await navigate('admin_students.php',1366,false,'populated','admin');await waitFor('document.querySelectorAll("[data-retention-id]").length===10');
  await evaluate('document.querySelector("[data-retention-id]").focus()');await keyPress(' ','Space',32);check(await evaluate('document.querySelector("[data-retention-id]").checked'),'Space selects individual account');
  await evaluate('document.getElementById("retentionSelectPage").click()');check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("10 accounts")'),'Select current page');
  await evaluate('document.getElementById("recordFilters_direction").value="DESC";document.getElementById("recordFilters_direction").dispatchEvent(new Event("change"))');await waitFor('document.querySelector("[data-retention-id]").dataset.retentionId==="43"');
  check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("10 accounts")'),'Sort preserves target population');
  await evaluate('document.getElementById("recordFilters_lifecycle").value="archived";document.getElementById("recordFilters_lifecycle").dispatchEvent(new Event("change"))');await waitFor('document.querySelector(".retention-toolbar p").textContent.startsWith("0 accounts")');check(true,'Filter change clears selection');
  await evaluate('[...document.querySelectorAll(".retention-toolbar button")].find(b=>b.textContent.startsWith("Select all")).click()');await waitFor('document.querySelector(".retention-toolbar p").textContent.startsWith("42 accounts")');check(true,'All matching across pages selected');
  await evaluate('document.getElementById("recordSearch").value="S003";document.getElementById("recordSearch").dispatchEvent(new Event("input"))');await waitFor('document.querySelectorAll("[data-retention-id]").length===1');check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("0 accounts")'),'Search clears selection');
  await evaluate('document.getElementById("recordSearch").value="";document.getElementById("recordSearch").dispatchEvent(new Event("input"))');await waitFor('document.querySelectorAll("[data-retention-id]").length===10');
  await evaluate('[...document.querySelectorAll(".retention-toolbar button")].find(b=>b.textContent.startsWith("Select all")).click()');await waitFor('document.querySelector(".retention-toolbar p").textContent.startsWith("42 accounts")');
  await evaluate('document.getElementById("retentionBulkAction").value="permanent_delete";[...document.querySelectorAll(".retention-toolbar button")].find(b=>b.textContent.includes("Review selected")).click()');await waitFor('document.getElementById("permanentDeleteDialog").open');
  check(await evaluate('document.getElementById("permanentDeleteConfirmationLabel").textContent==="Type: PURGE 39 ACCOUNTS"'),'Bulk uses server phrase, no individual-ID requirement');
  stale=true;
  await evaluate('const f=document.getElementById("permanentDeleteForm");f.elements.currentPassword.value="fixture";f.elements.confirmation.value="PURGE 39 ACCOUNTS";f.elements.confirmed.checked=true;f.requestSubmit()');await waitFor('document.getElementById("permanentDeleteResult").textContent.includes("changed")');
  check(await evaluate('document.getElementById("permanentDeleteDialog").open && document.getElementById("permanentDeletePassword").value===""'),'Stale preview keeps modal open and clears password');stale=false;
  await waitFor('!document.querySelector("#permanentDeleteForm [type=submit]").disabled');
  await evaluate('document.getElementById("permanentDeleteForm").elements.currentPassword.value="fixture";document.getElementById("permanentDeleteForm").requestSubmit()');await waitFor('!document.getElementById("permanentDeleteDialog").open');
  check(await evaluate('document.querySelector(".retention-results").textContent.includes("Completed: 39; Skipped: 3")'),'Partial committed results rendered');
  const chunks=getRequests().filter(r=>r.file==='account_lifecycle_api.php'&&r.body.includes('bulk_execute')).map(r=>JSON.parse(r.body));
  check(chunks.some(r=>r.cursor===25),'Explicit bounded chunk continuation');
  check(await evaluate('![...document.getElementById("retentionBulkAction").options].some(o=>/override|force/i.test(o.textContent))'),'No bulk grace override');
  gate=false;await navigate('admin_students.php',390,true,'populated','admin');await waitFor('document.getElementById("permanentDeleteAvailability").textContent.includes("expired")');
  check(await evaluate('[...document.querySelectorAll(".lifecycle-danger")].filter(b=>b.closest(".row-actions")).every(b=>b.getAttribute("aria-disabled")==="true")'),'Expired schema evidence disables all purge controls');gate=true;
  recoveryMissing=true;await navigate('admin_students.php',390,false,'populated','admin');await waitFor('document.querySelector(".retention-recovery button")');
  check(await evaluate('document.querySelector(".retention-recovery button").textContent.includes("missing journal")'),'Missing committed journal remains visible for operator review');
  await evaluate('document.querySelector(".retention-recovery button").click()');await waitFor('document.getElementById("permanentDeleteDialog").open');
  check(await evaluate('document.getElementById("permanentDeleteConfirmationLabel").textContent.includes("RECOVER "+"a".repeat(32))'),'Recovery confirmation binds the exact random job');
  await evaluate('const f=document.getElementById("permanentDeleteForm");f.elements.currentPassword.value="fixture";f.elements.confirmation.value="RECOVER "+"a".repeat(32);f.elements.confirmed.checked=true;f.requestSubmit()');
  await waitFor('document.getElementById("permanentDeleteResult").textContent.includes("unavailable")');
  check(await evaluate('document.getElementById("permanentDeleteDialog").open&&document.getElementById("permanentDeletePassword").value===""'),'Unavailable recovery keeps review open and clears password');
  await keyPress('Escape');recoveryMissing=false;
  await navigate('admin_students.php',390,false,'populated','adviser');check(await evaluate('!document.querySelector(".retention-toolbar")&&!document.getElementById("permanentDeleteDialog")'),'No Admin lifecycle controls for Adviser viewer');
  check(errors.length===0,'No browser runtime/CSP exceptions',errors.join(' | '));
}
module.exports={mockList,mockApi,run};
