'use strict';
const fs=require('node:fs'),path=require('node:path');
let copies=[],campaigns=[];
function reset() {
  copies=Array.from({length:12},(_,i)=>({id:i+1,recipient_user_id:3,campaign_id:1,recipient_name:'Fixture',recipient_email:'fixture@example.test',
    type:'Status Update',kind:['staff','workflow','system','support'][i%4],subject:'<img src=x onerror="window.__fixtureXss=1">',
    message:'Personal body '+i+' '+('Readable notification text. '.repeat(25)),status:'Sent',created_at:'2026-10-11 08:00:00',read_at:i<2?null:'2026-10-11 09:00:00',archived_at:null,can_manage:true}));
  campaigns=['Sent','Scheduled','Sending','Failed','Cancelled'].map((status,i)=>({id:i+1,kind:'staff',recipient_count:50,subject:'Logical send '+i,message:'Sender body '+i,
    status,created_at:'2026-10-11 08:00:00',scheduled_at:'2099-01-01 09:00:00',archived_at:null,can_manage:true}));
}
reset();
function mockApi(file,action,request,role) {
  if(file!=='notifications_api.php')return null;
  const query=request?.query||{};
  if(action==='recipients_preview')return {ok:true,recipients:[{id:1,name:'Student',email:'fixture@example.test'}]};
  if(action==='group_options')return {ok:true,groups:['AMT-BSIT-Y2-2627-G01']};
  if(action==='send')return {ok:true,campaign_id:1,total:50,sent:0,logged:0,scheduled:true,queued:true};
  if(['list','sent_history','legacy_history'].includes(action)) {
    let rows=action==='sent_history'?campaigns:action==='legacy_history'?[{id:100,legacy:true,can_manage:false,kind:'system',message:'Legacy personal history',status:'Sent',created_at:'2026-01-01'}]:copies;
    rows=rows.filter(r=>!r.deleted_at && (query.status==='Archived'?!!r.archived_at:!r.archived_at));
    if(query.status==='Unread')rows=rows.filter(r=>!r.read_at);if(query.status==='Read')rows=rows.filter(r=>r.read_at);
    if(query.kind&&query.kind!=='All')rows=rows.filter(r=>r.kind===query.kind);
    if(query.sort==='Oldest')rows=rows.slice().reverse();
    const limit=query.preview?5:10,total=rows.length,page=Math.max(1,Math.min(Math.ceil(total/limit)||1,Number(query.page)||1));
    return {ok:true,notifications:rows.slice((page-1)*limit,page*limit),total,page,limit,unread_count:copies.filter(r=>!r.read_at&&!r.archived_at&&!r.deleted_at).length};
  }
  const data=JSON.parse(request?.body||'{}');
  if(action==='mark_all_read'){copies.forEach(r=>{if(!r.deleted_at&&!r.archived_at)r.read_at='2026-10-11 10:00:00';});return {ok:true};}
  const sender=action.startsWith('campaign_'),operation=sender?action.slice(9):action;
  const row=(sender?campaigns:copies).find(r=>r.id===data.id);if(!row)return {ok:false,message:'Not found'};
  if(operation==='archive')row.archived_at='2026-10-11 10:00:00';
  if(operation==='restore')row.archived_at=null;
  if(operation==='delete'){row.deleted_at='2026-10-11 10:00:00';row.subject=null;row.message='';}
  if(operation==='cancel')row.status='Cancelled';
  if(operation==='mark_read')row.read_at='2026-10-11 10:00:00';
  return {ok:true};
}
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors,getRequests}) {
  const out=path.join(__dirname,'v10-batch7-results');fs.mkdirSync(out,{recursive:true});
  const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]],metrics=[];
  for(const [width,height] of matrix)for(const dark of [false,true])for(const role of ['admin','adviser','student']) {
    reset();const file=role==='student'?'role_portal.php':'admin_notifications.php';
    await navigate(file,width,dark,role==='student'?'student-ready':'populated',role);
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    if(role==='student')await evaluate('document.querySelector("[data-go=notifications]").click()');
    const host=role==='student'?'notificationList':'noticeHistory';
    await waitFor(`document.querySelectorAll('#${host} .history-item').length===10`);
    const geometry=await evaluate(`({overflow:document.documentElement.scrollWidth>innerWidth+1,scroll:getComputedStyle(document.getElementById('${host}')).overflowY,
      maxHeight:getComputedStyle(document.getElementById('${host}')).maxHeight,unlabelled:[...document.querySelectorAll('.notification-filters select')].some(s=>!s.closest('label')),
      iconOnly:[...document.querySelectorAll('.notification-copy-actions button')].some(b=>!b.textContent.trim()),xss:!!window.__fixtureXss})`);
    const label=role+' '+width+' '+dark;metrics.push({role,width,height,dark,...geometry});
    check(!geometry.overflow,label+' no body overflow');check(!geometry.unlabelled&&!geometry.iconOnly&&!geometry.xss,label+' labelled controls, literal content and text actions');
    check(width>768?geometry.scroll==='auto':geometry.scroll==='visible'&&geometry.maxHeight==='none',label+' desktop independent scroll / mobile normal flow');
    await evaluate('document.querySelector("[data-notification-filter=kind]").value="workflow";document.querySelector("[data-notification-filter=kind]").dispatchEvent(new Event("change"))');
    await waitFor(`document.querySelectorAll('#${host} .history-item').length===3`);
    check(await evaluate(`document.getElementById('${host}').textContent.includes('workflow')`),label+' kind filter');
    await evaluate('document.querySelector("[data-notification-filter=kind]").value="All";document.querySelector("[data-notification-filter=kind]").dispatchEvent(new Event("change"))');
    await waitFor(`document.querySelectorAll('#${host} .history-item').length===10`);
    await evaluate('document.querySelector("[data-notification-action=archive]").click()');
    await waitFor(`document.querySelector('.notification-result').textContent.includes('archived')&&!document.getElementById('${host}').getAttribute('aria-busy').includes('true')`);
    await evaluate('document.querySelector("[data-notification-filter=status]").value="Archived";document.querySelector("[data-notification-filter=status]").dispatchEvent(new Event("change"))');
    await waitFor(`document.querySelectorAll('#${host} [data-notification-action=delete]').length===1`);
    const before=getRequests().filter(r=>r.method==='POST'&&r.action==='delete').length;
    await evaluate('document.querySelector("[data-notification-action=delete]").focus();document.querySelector("[data-notification-action=delete]").click()');
    await waitFor('!!document.querySelector(".prism-dialog")');
    check(await evaluate('document.querySelector(".prism-dialog").textContent.includes("This permanently removes your copy of this notification.")&&document.activeElement.matches("[data-act=cancel]")'),label+' second confirmation text and safe initial focus');
    await keyPress('Tab');await keyPress('Tab');
    check(await evaluate('document.activeElement.matches("[data-act=cancel]")'),label+' confirmation focus trap');
    await keyPress('Escape');
    check(await evaluate('!document.querySelector(".prism-dialog")&&document.activeElement.matches("[data-notification-action=delete]")'),label+' Escape returns focus');
    check(getRequests().filter(r=>r.method==='POST'&&r.action==='delete').length===before,label+' cancellation has no destructive request');
    await evaluate('document.querySelector("[data-notification-action=delete]").click()');await waitFor('!!document.querySelector(".prism-dialog")');
    await evaluate('document.querySelector(".prism-dialog [data-act=ok]").click()');
    await waitFor(`document.querySelector('.notification-result').textContent.includes('permanently deleted')&&!document.getElementById('${host}').getAttribute('aria-busy').includes('true')`);
    check(await evaluate(`document.activeElement===document.getElementById('${host}')`),label+' deletion returns focus to stable history');
    if(role!=='student') {
      await evaluate('document.querySelector("[data-notification-filter=view]").value="sent_history";document.querySelector("[data-notification-filter=view]").dispatchEvent(new Event("change"))');
      await evaluate('document.querySelector("[data-notification-filter=status]").value="All";document.querySelector("[data-notification-filter=status]").dispatchEvent(new Event("change"))');
      await waitFor(`document.querySelectorAll('#${host} .history-item').length===5`);
      check(await evaluate(`document.getElementById('${host}').textContent.includes('50 recipient copies')`),label+' campaign count displayed');
      check(await evaluate(`(()=>{const rows=[...document.querySelectorAll('#${host} .history-item')];return !rows[1].querySelector('[data-notification-action=archive],[data-notification-action=delete]')&&!rows[2].querySelector('[data-notification-action=archive],[data-notification-action=delete]');})()`),label+' Scheduled/Sending destructive controls omitted');
      await evaluate('document.querySelector("[data-notification-action=cancel]").click()');await waitFor('!!document.querySelector(".prism-dialog")');
      await evaluate('document.querySelector(".prism-dialog [data-act=ok]").click()');await waitFor('document.querySelector(".notification-result").textContent.includes("cancelled")');
      await evaluate('document.querySelector("[data-notification-action=archive]").click()');await waitFor('document.querySelector(".notification-result").textContent.includes("archived")');
      await evaluate('document.querySelector("[data-notification-filter=status]").value="Archived";document.querySelector("[data-notification-filter=status]").dispatchEvent(new Event("change"))');
      await waitFor('!!document.querySelector("[data-notification-action=delete]")');
      await evaluate('document.querySelector("[data-notification-action=delete]").click()');await waitFor('!!document.querySelector(".prism-dialog")');
      check(await evaluate('document.querySelector(".prism-dialog").textContent.includes("Recipient copies are not deleted.")'),label+' sender-specific permanent deletion confirmation');await keyPress('Escape');
    }
    if(width===375&&dark){const shot=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,role+'-archive-dark.png'),Buffer.from(shot.data,'base64'));}
  }
  for(const role of ['admin','adviser']) {
    reset();await navigate(role==='admin'?'dashboard.php':'research_adviser.php',375,true,'populated',role);
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),role+' compact dashboard has no overflow');
    if(role==='admin')check(await evaluate('document.querySelectorAll("#notificationList li").length<=5&&!!document.querySelector(".notification-view-all")'), 'Compact bell stays at five with View All');
  }
  fs.writeFileSync(path.join(out,'ui-metrics.json'),JSON.stringify(metrics,null,2));
  check(errors.length===0,'No Batch 7 browser exceptions',errors.join(' | '));
}
module.exports={run,mockApi,reset};
