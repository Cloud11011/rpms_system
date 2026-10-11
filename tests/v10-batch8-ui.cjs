'use strict';
const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const attack='<img src=x onerror="window.__fixtureXss=1">';
let failed=false,created=false;
const request={id:10,campaign_id:1,kind:'support',type:'Support Request',support_category:'Account/Access',reply_to_campaign_id:null,
  subject:'Account access '+attack,message:'PRIVATE REQUEST '+attack,created_by:'Pending Requester',status:'Sent',created_at:'2026-10-11 12:00:00',read_at:null,archived_at:null,can_manage:true};
function mockApi(file,action,req,role) {
  if(file==='help_support_api.php') {
    if(action==='submit')return failed?{ok:false,message:'No active RPMS Administrator is currently available to receive this request. Please try again later.'}:{ok:true,message:'Your request is available in PRISM.',request_token:'fresh-token'};
    if(['responses','requests'].includes(action))return {ok:true,notifications:[action==='requests'?{...request,recipient_count:2}:{...request,id:11,type:'Support Response',reply_to_campaign_id:1,message:'PRIVATE RESPONSE '+attack}],total:1,page:1,pages:1,limit:10,unread_count:1};
    return {ok:true};
  }
  if(file==='notifications_api.php') {
    if(action==='support_context')return {ok:true,recipient_name:'Pending Requester',category:'Account/Access',subject:'Account access'};
    if(action==='support_respond'){created=true;return {ok:true,message:'Support Response sent in PRISM.',request_token:'response-fresh-token'};}
    if(action==='sent_history')return {ok:true,notifications:created?[{...request,id:12,type:'Support Response',reply_to_campaign_id:1,recipient_count:1}]:[],total:created?1:0,page:1,limit:10,unread_count:1};
    if(action==='list')return {ok:true,notifications:role==='admin'?[request]:[{...request,id:11,type:'Support Response',reply_to_campaign_id:1,message:'PRIVATE RESPONSE '+attack}],total:1,page:1,limit:10,unread_count:1};
    if(action==='recipients_preview')return {ok:true,recipients:[{name:'Student'}]};
    if(action==='group_options')return {ok:true,groups:[]};
    return {ok:true};
  }
  return null;
}
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors,getRequests,setDelay}) {
  const out=path.join(__dirname,'v10-batch8-results');fs.mkdirSync(out,{recursive:true});
  const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]],metrics=[];
  const baseline=spawnSync('git',['show','HEAD:assets/css/research-resources.css'],{cwd:path.resolve(__dirname,'..'),encoding:'utf8',windowsHide:true});
  if(baseline.status!==0)throw new Error('Cannot read baseline resources');
  const screen=async(file,width,height,dark,role='admin',scenario='populated')=>{
    await navigate(file,width,dark,scenario,role);await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
  };
  const overflow=async label=>check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' no horizontal overflow');
  const shot=async label=>{const s=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,label+'.png'),Buffer.from(s.data,'base64'));};
  for(const [width,height] of matrix)for(const dark of [false,true]) {
    const label=width+'x'+height+'-'+(dark?'dark':'light');
    for(const role of ['student','adviser']) {
      await screen('help_support.php',width,height,dark,role,role==='student'?'pending':'complete');
      await waitFor('document.querySelectorAll("#supportHistory .history-item").length===1');await overflow('Support '+role+' '+label);
      check(await evaluate('document.querySelectorAll("#supportCategory option").length===8&&!!document.querySelector("label[for=supportMessage]")&&!!document.querySelector("label[for=supportSubject]")&&!document.querySelector("input[type=file]")'),'Support fields and frozen categories '+label);
      check(await evaluate('!document.querySelector("#supportHistory img")&&!window.__fixtureXss&&!document.getElementById("supportHistory").textContent.includes("PRIVATE RESPONSE")'),'Support row compact and literal '+label);
      await evaluate('document.querySelector("#supportHistory [data-notice-detail]").focus();document.querySelector("#supportHistory [data-notice-detail]").click()');
      check(await evaluate('document.getElementById("supportDetail").open&&document.getElementById("supportDetailBody").textContent.includes("PRIVATE RESPONSE")&&!document.querySelector("#supportDetailBody img")'),'Own response detail literal '+label);await keyPress('Escape');
      await evaluate('document.querySelector("#supportHistory [data-notice-detail]").click()');
      check(await evaluate('document.getElementById("supportDetail").scrollWidth<=document.getElementById("supportDetail").clientWidth+1'),'Long subject fits Support dialog '+label);await keyPress('Escape');
      await waitFor('!document.getElementById("supportDetail").open');
      check(await evaluate('document.activeElement.matches("[data-notice-detail]")'),'Support detail restores keyboard focus '+label);
      check(getRequests().findLast(r=>r.file==='help_support_api.php'&&r.method==='GET').headers['x-prism-generation']==='a'.repeat(32),'Support reads carry the rendered generation '+label);
      await evaluate('document.getElementById("supportSubject").focus()');
      check(await evaluate('parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=3'),'Support visible focus '+label);
      failed=true;await evaluate('document.getElementById("supportCategory").value="Account/Access";document.getElementById("supportSubject").value="Keep my draft";document.getElementById("supportMessage").value="Retain this request";document.getElementById("supportForm").requestSubmit()');
      await waitFor('document.getElementById("supportResult").textContent.includes("No active RPMS")');
      check(await evaluate('document.getElementById("supportMessage").value==="Retain this request"&&document.getElementById("supportSubject").value==="Keep my draft"'),'Zero Admin preserves typed message '+label);
      failed=false;setDelay(150);const before=getRequests().filter(r=>r.file==='help_support_api.php'&&r.action==='submit').length;
      await evaluate('document.getElementById("supportForm").requestSubmit();document.getElementById("supportForm").requestSubmit()');
      await waitFor('document.getElementById("supportResult").textContent.includes("available in PRISM")');setDelay(0);
      check(getRequests().filter(r=>r.file==='help_support_api.php'&&r.action==='submit').length===before+1,'Support double-click creates one POST '+label);
      const payload=JSON.parse(getRequests().findLast(r=>r.file==='help_support_api.php'&&r.action==='submit').body);
      check(Object.keys(payload).sort().join(',')==='category,message,request_token,subject','Support sends no sender or recipient authority '+label);
      await waitFor('document.querySelector("[data-notification-filter=view]").value==="requests"');
      check(await evaluate('document.getElementById("supportToken").value==="fresh-token"'),'Support token rotates after success '+label);
      if(width===375&&role==='student')await shot('support-'+label);
    }
    for(const file of ['privacy.php','terms.php','legal_consent.php','login.php']) {
      await screen(file,width,height,dark);await overflow(file+' '+label);
      const bg=await evaluate('({position:getComputedStyle(document.body,"::before").position,image:getComputedStyle(document.body,"::before").backgroundImage,size:getComputedStyle(document.body,"::before").backgroundSize,height:document.body.scrollHeight})');
      check(bg.position==='fixed'&&bg.image.includes('ceu_bg.png')&&bg.image.includes('radial-gradient')&&bg.size.endsWith('cover'),file+' shared viewport campus framing and glow '+label);
      if(file!=='login.php') {
        check(await evaluate('document.querySelector(".legal-brand img").getBoundingClientRect().width>=190&&!document.querySelector(".legal-brand span")&&getComputedStyle(document.querySelector(".legal-back")).textDecorationLine==="none"&&document.getElementById("legalTheme").textContent.trim()===""'),file+' larger logo, native Back button and icon theme '+label);
        const was=await evaluate('document.documentElement.classList.contains("dark-theme")');
        await evaluate('document.getElementById("legalTheme").focus()');await keyPress('Enter');
        check(await evaluate(`document.documentElement.classList.contains('dark-theme')===${!was}&&document.getElementById('legalTheme').getAttribute('aria-label')==='Switch to ${was?'dark':'light'} mode'`),file+' keyboard theme and dynamic accessible name '+label);
        check(await evaluate('parseFloat(getComputedStyle(document.getElementById("legalTheme")).outlineWidth)>=3'),file+' theme focus '+label);
      }
      // The keyboard check above toggled the theme; label the captured resulting theme.
      if(width===375&&file==='privacy.php')await shot('privacy-'+width+'x'+height+'-'+(dark?'light':'dark'));
    }
    await screen('dashboard.php',width,height,dark);await overflow('Dashboard '+label);
    const geom=await evaluate('(()=>{const r=s=>document.querySelector(s).getBoundingClientRect().toJSON();return {stage:r(".pipeline-card"),calendar:r(".calendar-box"),monitor:r(".dashboard-monitor"),content:r(".content"),columns:getComputedStyle(document.querySelector(".pipeline-steps")).gridTemplateColumns.split(" ").length};})()');
    check(width>1200?Math.abs(geom.stage.bottom-geom.calendar.bottom)<1:geom.calendar.y>=geom.stage.bottom+19,'Stage/calendar stretch on desktop and natural stack '+label);
    check(Math.abs(geom.monitor.width-geom.content.width)<1&&geom.monitor.y>=geom.calendar.bottom+19,'IERB full width below stage/calendar '+label);
    const resources=await evaluate('document.querySelector(".prism-research-resources").getBoundingClientRect().height');
    await evaluate(`{const s=document.createElement('style');s.id='baselineResources';s.textContent=${JSON.stringify(baseline.stdout+spawnSync('git',['show','HEAD:assets/css/prism-workspace.css'],{cwd:path.resolve(__dirname,'..'),encoding:'utf8',windowsHide:true}).stdout)};document.head.append(s);}`);
    const previous=await evaluate('document.querySelector(".prism-research-resources").getBoundingClientRect().height');
    await evaluate('document.getElementById("baselineResources").remove()');
    const reduction=(previous-resources)/previous*100;
    check(reduction>=25&&reduction<=45,'Resources measured compact footprint '+label+' '+reduction.toFixed(1)+'%');
    check(await evaluate('[...document.querySelectorAll(".prism-resource > summary")].every(e=>e.getBoundingClientRect().height>=44)&&document.querySelectorAll(".prism-resource").length===3'),'Resources retain three readable touch targets '+label);
    await evaluate('document.querySelector(".prism-resource > summary").focus()');await keyPress('Enter');
    check(await evaluate('document.querySelector(".prism-resource").open&&[...document.querySelectorAll("a")].some(a=>a.href==="https://ceu-ierb.wixsite.com/ierb")'),'Resource keyboard accordion and existing portal '+label);
    await evaluate('document.getElementById("viewYear").click()');await waitFor('document.querySelectorAll(".year-month-card").length===12');
    check(await evaluate('document.getElementById("yearCalendarView").scrollHeight<=document.getElementById("yearCalendarView").clientHeight+1'),'Year Calendar remains naturally readable '+label);
    metrics.push({label,stageCalendar:geom,resources:{previous,final:resources,reduction}});
    if(width===1366) {await evaluate('document.getElementById("viewMonth").click();document.querySelector(".content").scrollIntoView()');await shot('stage-calendar-'+label);}
    await screen('research_adviser.php',width,height,dark,'adviser');await overflow('Adviser '+label);
    const notices=await evaluate('(()=>{const p=document.querySelector(".adviser-notifications-panel"),l=document.getElementById("adviserNotifications"),q=document.querySelector(".adviser-review-panel"),r=e=>e.getBoundingClientRect().toJSON();return {panel:r(p),list:r(l),queue:r(q),overflow:getComputedStyle(l).overflowY,max:getComputedStyle(l).maxHeight};})()');
    check(width>1100?Math.abs(notices.panel.height-notices.queue.height)<1&&Math.abs(notices.list.bottom-(notices.panel.bottom-21))<2:notices.max==='360px','Recent notifications use panel remainder / responsive cap '+label);
    check(await evaluate('document.querySelector(".portal-help").getAttribute("href")==="help_support.php"'),'Adviser Help entry '+label);metrics.push({label,notices});
    await screen('admin_notifications.php',width,height,dark,'admin');await waitFor('document.querySelector("#noticeHistory [data-notice-detail]")');await overflow('Admin Support '+label);
    await evaluate('document.querySelector("[data-notification-filter=kind]").value="support";document.querySelector("[data-notification-filter=kind]").dispatchEvent(new Event("change"))');
    await waitFor('document.getElementById("noticeHistory").getAttribute("aria-busy")==="false"');
    check(await evaluate('document.getElementById("noticeHistory").textContent.includes("Account/Access")&&document.getElementById("noticeHistory").textContent.includes("Pending Requester")&&!document.getElementById("noticeHistory").textContent.includes("PRIVATE REQUEST")'),'Admin compact support metadata '+label);
    await evaluate('document.querySelector("#noticeHistory [data-notice-detail]").click()');
    check(await evaluate('!document.getElementById("respondSupport").hidden&&document.getElementById("notificationDetailMessage").textContent.includes("PRIVATE REQUEST")&&!window.__fixtureXss'),'Admin own request details and Respond '+label);
    await evaluate('document.getElementById("respondSupport").click()');await waitFor('!document.getElementById("noticeSupportContext").hidden');
    check(await evaluate('document.getElementById("noticeAudienceLabel").hidden&&document.getElementById("noticeTypeLabel").hidden&&document.getElementById("noticeSupportSubject").required&&document.activeElement.id==="noticeSupportSubject"'),'Existing composer bound to server-resolved Pending requester '+label);
    const responseBefore=getRequests().filter(r=>r.action==='support_respond').length;setDelay(100);
    await evaluate('document.getElementById("noticeMessage").value="Helpful response";document.getElementById("notificationForm").requestSubmit();document.getElementById("notificationForm").requestSubmit()');
    await waitFor('document.querySelector("[data-notification-filter=view]").value==="sent_history"&&document.getElementById("noticeSupportContext").hidden');setDelay(0);
    const responseRequests=getRequests().filter(r=>r.action==='support_respond');
    check(responseRequests.length===responseBefore+1&&Object.keys(JSON.parse(responseRequests.at(-1).body)).sort().join(',')==='message,request_id,request_token,subject','Response single POST, no client recipient/category/correlation authority '+label);
    if(width===375)await shot('admin-support-'+label);
  }
  await navigate('privacy.php',375,false,'populated','admin',true);
  check(await evaluate('document.querySelector("a.legal-back").getAttribute("href")==="login.php"&&document.querySelectorAll("main h3").length>0'),'Public legal Back and content work without JavaScript');
  await command('Emulation.setScriptExecutionDisabled',{value:false});
  await navigate('student.php',375,false,'student-ready','student');
  await evaluate('document.querySelector("[data-go=notifications]").click()');
  await waitFor('document.querySelector("#notificationList [data-notice-detail]")');
  await evaluate('document.querySelector("#notificationList [data-notice-detail]").focus();document.querySelector("#notificationList [data-notice-detail]").click()');
  check(await evaluate('document.querySelector("dialog.notification-detail").open&&document.querySelector("dialog.notification-detail").textContent.includes("PRIVATE RESPONSE")&&!document.querySelector("dialog.notification-detail img")'),'Complete Student reads literal Support Response in normal center');
  check(await evaluate('(() => {const d=document.querySelector("dialog.notification-detail"),r=d.getBoundingClientRect();return d.scrollWidth<=d.clientWidth+1&&r.left>=0&&r.right<=innerWidth&&r.height<=innerHeight;})()'),'Normal Student Support detail fits phone viewport');
  await keyPress('Escape');await waitFor('!document.querySelector("dialog.notification-detail")');
  check(await evaluate('document.activeElement.matches("#notificationList [data-notice-detail]")'),'Normal Student Support detail returns focus');
  check(await evaluate('document.querySelector(".portal-help").getAttribute("href")==="help_support.php"&&!document.getElementById("supportModal")'),'Student Help uses internal route; old mail form removed');
  check(!getRequests().some(r=>r.file.includes('gmail')||r.file.includes('mail')),'No browser Support email path');
  check(errors.length===0,'No Batch 8 browser exceptions',errors.join(' | '));
  fs.writeFileSync(path.join(out,'metrics.json'),JSON.stringify(metrics,null,2));
}
module.exports={run,mockApi};
