'use strict';
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
const today=()=>{const d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;};
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors,getRequests,setManagement,setDelay}) {
  const dest=fs.mkdtempSync(path.join(os.tmpdir(),'prism-batch45-')),metrics=[];
  const shot=async label=>{await evaluate('new Promise(r=>setTimeout(r,240))');const s=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(dest,label+'.png'),Buffer.from(s.data,'base64'));};
  const geometry=()=>evaluate(`(() => {const rect=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height};};return [...document.querySelectorAll('main,h1,.management-controls,.management-card,table,.calendar-box,.prism-filter-disclosure')].map(rect);})()`);
  const same=(a,b)=>a.length===b.length&&a.every((r,i)=>['x','y','width','height'].every(k=>Math.abs(r[k]-b[i][k])<1));
  const lum=rgb=>rgb.match(/[\d.]+/g).slice(0,3).map(Number).map(x=>x/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((sum,x,i)=>sum+x*[.2126,.7152,.0722][i],0);
  const ratio=(a,b)=>(Math.max(lum(a),lum(b))+.05)/(Math.min(lum(a),lum(b))+.05);
  const popup=async(label,selector)=>{
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).focus()`);await keyPress('Enter','Enter',13);
    await waitFor('document.querySelectorAll(".prism-deadline-detail").length===12');
    check(await evaluate(`(() => {const d=document.querySelector('.prism-readonly-dialog'),r=d.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1&&d.scrollWidth<=d.clientWidth+1&&!d.querySelector('form,input,select,textarea')&&[...d.querySelectorAll('button')].every(b=>b.textContent==='Close')&&!d.querySelector('img');})()`),label+': read-only safe popup fits');
    await keyPress('Escape','Escape',27);await waitFor('!document.querySelector(".prism-readonly-dialog")');
    check(await evaluate('document.activeElement.matches("[data-calendar-date],[data-deadline-date]")'),label+': date focus restored');
  };
  const screens=[['admin_students.php','admin','student'],['admin_advisers.php','admin','adviser'],['admin_archived_accounts.php','admin','student'],['admin_archived_accounts.php','admin','adviser'],['research_adviser.php','adviser','student'],['student.php','student','student'],['calendar.php','adviser','student'],['dashboard.php','admin','student'],['calendar.php','admin','student']];
  for(const [width,height] of matrix)for(const dark of [false,true])for(const [file,role,type] of (process.argv.includes('--focus')?[]:screens)) {
    setManagement(type);await navigate(file,width,dark,'populated',role);await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<500});
    const label=`${file}-${type}-${width}x${height}-${dark?'dark':'light'}`;
    if(role==='admin')await evaluate("{const t=document.getElementById('prismSidebarToggle');if(t.getAttribute('aria-expanded')==='true')t.click()}");
    await evaluate('new Promise(r=>setTimeout(r,230))');
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+': no page horizontal overflow');
    if(file.includes('admin_')) {
      const archived=file==='admin_archived_accounts.php';
      await waitFor('document.querySelector("#recordRows [data-retention-id]")');
      check(await evaluate('document.getElementById("retentionSelectionMode").getAttribute("aria-pressed")==="false" && document.querySelector(".retention-toolbar").hidden && [...document.querySelectorAll(".retention-select")].every(c=>c.hidden)'),label+': selection OFF by default');
      check(getRequests().findLast(r=>r.file===(type==='adviser'?'advisers_api.php':'students_api.php')&&r.action==='list')?.query.lifecycle===(archived?'archived':'active'),label+': explicit correct population');
      check(await evaluate(`!document.getElementById('recordFilters_lifecycle') && ${archived?'!!document.querySelector(".retention-cleanup") && document.querySelectorAll(".archive-tabs a").length===2 && !document.getElementById("inviteAccount")':'!document.querySelector(".retention-cleanup") && !document.querySelector(".prism-action-panel .lifecycle-danger")'}`),label+': dedicated archive controls');
      await evaluate('document.getElementById("retentionSelectionMode").click()');
      check(await evaluate('!document.querySelector(".retention-toolbar").hidden && [...document.querySelectorAll(".retention-select")].every(c=>!c.hidden) && document.getElementById("retentionSelectionMode").getAttribute("aria-pressed")==="true"'),label+': selection ON shows controls');
      if([1366,375].includes(width))await shot(label+'-selection-on');
      await evaluate('document.getElementById("retentionSelectPage").click()');
      check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith(String(document.querySelectorAll("#recordRows [data-retention-id]").length)+" accounts selected")'),label+': current page selection count');
      await evaluate('document.getElementById("retentionSelectionMode").click();document.getElementById("retentionSelectionMode").click()');
      check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("0 accounts selected") && !document.getElementById("retentionSelectPage").checked && [...document.querySelectorAll("[data-retention-id]")].every(b=>!b.checked)'),label+': exit clears hidden IDs and page state');
      await evaluate('document.querySelector(".retention-toolbar-actions > button").click()');
      await waitFor('document.querySelector(".retention-toolbar p").textContent.includes("snapshot of all matching")');
      await evaluate('document.getElementById("retentionSelectionMode").click();document.getElementById("retentionSelectionMode").click()');
      check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("0 accounts selected") && !document.querySelector(".retention-toolbar p").textContent.includes("snapshot")'),label+': exit clears all-matching snapshot');
      await evaluate('document.getElementById("retentionSelectPage").click();document.getElementById("recordSearch").value="Record";document.getElementById("recordSearch").dispatchEvent(new Event("input",{bubbles:true}))');
      check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("0 accounts selected")'),label+': search immediately clears selection');
      // Await the debounced search response before testing a menu on a row it replaces.
      await evaluate('new Promise(r=>setTimeout(r,300))');
      await waitFor('document.querySelector(".prism-action-trigger") && !document.getElementById("recordCount").textContent.includes("Loading") && !document.querySelector("#recordRows [data-retention-id=\"3\"]")');
      await evaluate('document.getElementById("retentionSelectionMode").click()');
      await evaluate('new Promise(r=>setTimeout(r,250))');
      const before=await geometry();await evaluate('document.getElementById("prismSidebarToggle").click()');await evaluate('new Promise(r=>setTimeout(r,240))');const after=await geometry();
      check(same(before,after),label+': opening navigation leaves all main geometry unchanged');metrics.push({label,navigation:{before,after}});
      if([1366,375].includes(width))await shot(label+'-navigation-expanded');
      await evaluate('document.getElementById("prismSidebarToggle").focus()');await keyPress('Escape','Escape',27);
      check(await evaluate('document.querySelector(".prism-sidebar").classList.contains("is-collapsed") && document.activeElement.id==="prismSidebarToggle" && document.querySelector(".prism-sidebar-backdrop").hidden'),label+': Escape closes overlay and returns focus');
      await evaluate('document.getElementById("prismSidebarToggle").click();document.querySelector(".prism-sidebar-backdrop").click()');
      check(await evaluate('document.querySelector(".prism-sidebar").classList.contains("is-collapsed")'),label+': backdrop closes drawer');
      await evaluate('document.querySelector(".prism-filter-disclosure > button").click()');
      check(await evaluate('(() => {const p=document.querySelector(".prism-filter-panel"),r=p.getBoundingClientRect();return !p.hidden&&r.left>=0&&r.right<=innerWidth+1&&r.bottom<=innerHeight+1;})()'),label+': Filters & Sort fits');
      if([1366,375].includes(width))await shot(label+'-filters-open');
      await keyPress('Escape','Escape',27);
      await evaluate('document.querySelector(".prism-action-trigger").click()');
      await waitFor('document.querySelector(".prism-action-panel:not([hidden])")');
      check(await evaluate('(() => {const p=document.querySelector(".prism-action-panel:not([hidden])"),r=p.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.bottom<=innerHeight+1;})()'),label+': Actions fits');
      if([1366,375].includes(width))await shot(label+'-actions-open');
      await keyPress('Escape','Escape',27);
      const buttons=await evaluate(`[...document.querySelectorAll('.management-controls .prism-btn,.management-primary,.prism-action-trigger')].filter(e=>e.getClientRects().length).map(e=>({text:e.textContent.trim(),foreground:getComputedStyle(e).color,background:getComputedStyle(e).backgroundColor,border:getComputedStyle(e).borderTopColor,align:getComputedStyle(e).alignItems,height:e.getBoundingClientRect().height}))`);
      for(const b of buttons){check(ratio(b.foreground,b.background)>=4.5,label+': readable '+b.text);check(b.align==='center'&&b.height>=(width<601?44:38),label+': aligned '+b.text);}
      metrics.push({label,buttons});
    }
    if(file==='research_adviser.php') {
      check(await evaluate(`!document.body.textContent.includes('Recent Resubmissions') && !!document.querySelector('#adviserQueue') && !!document.querySelector('a[href="documents.php"]')`),label+': review path remains after note removal');
      await waitFor('document.querySelector("#adviserCalendarDays [data-official-deadline=true]")');await popup(label,'#adviserCalendarDays [data-official-deadline=true]');
    }
    if(file==='student.php') {
      await waitFor('document.querySelector("#dashboardCalendarGrid [data-official-deadline=true]")');await popup(label,'#dashboardCalendarGrid [data-official-deadline=true]');
      await evaluate('document.querySelector("[data-page=calendar]").click()');
      await waitFor('document.querySelector("#monthGrid .has-official-deadline")');await popup(label,'#monthGrid .has-official-deadline');
      check(await evaluate('!document.querySelector("#officialDeadlinePanel form,#officialDeadlinePanel #deadlineManage")'),label+': Student calendar has no mutations');
    }
    if(file==='calendar.php') {await waitFor('document.querySelector("#monthGrid .has-official-deadline")');await popup(label,'#monthGrid .has-official-deadline');}
    if(file==='dashboard.php') {
      await waitFor('document.querySelector("#calendarDays [data-official-deadline=true]")');await popup(label,'#calendarDays [data-official-deadline=true]');
      await evaluate('document.getElementById("viewYear").click()');await waitFor('document.querySelector("#yearCalendarView [data-official-deadline=true]")');
      const year=await evaluate(`(() => {const p=document.getElementById('yearCalendarView'),r=p.getBoundingClientRect(),shell=p.closest('.calendar-box').getBoundingClientRect();return {columns:getComputedStyle(p).gridTemplateColumns,cards:p.children.length,height:r.height,scroll:p.scrollHeight,client:p.clientHeight,fit:[...p.children].every(c=>{const b=c.getBoundingClientRect();return b.left>=r.left-1&&b.right<=r.right+1&&b.bottom<=shell.bottom+1;}),overflow:getComputedStyle(p).overflowY};})()`);
      check(year.cards===12&&year.fit&&year.scroll<=year.client+1&&year.overflow==='visible',label+': all year cards fit natural shell');metrics.push({label,year});
      await shot(label+'-year-view');
      await popup(label,'#yearCalendarView [data-official-deadline=true]');
      await evaluate('document.getElementById("nextBtn").click()');check(await evaluate('document.querySelectorAll(".year-month-card").length===12'),label+': next year works');
      await evaluate('document.getElementById("prevBtn").click();document.getElementById("viewMonth").click()');check(await evaluate('document.getElementById("standardCalendarView").style.display==="block"'),label+': Month toggle preserved');
    }
    check(!getRequests().some(r=>r.file==='calendar_deadlines_api.php'&&r.method!=='GET'),label+': popup/markers only read official deadlines');
    await shot(label);
  }
  // A pending select-all response cannot resurrect state after explicitly exiting.
  if(process.argv.includes('--focus'))for(const [width,height] of matrix)for(const dark of [false,true]) {
    await navigate('dashboard.php',width,dark,'populated','admin');await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<500});
    await evaluate("{const t=document.getElementById('prismSidebarToggle');if(t.getAttribute('aria-expanded')==='true')t.click();document.getElementById('viewYear').click()}");
    await waitFor('document.querySelector("#yearCalendarView [data-official-deadline=true]")');
    const year=await evaluate(`(() => {const p=document.getElementById('yearCalendarView'),r=p.getBoundingClientRect(),s=p.closest('.calendar-box').getBoundingClientRect();return {columns:getComputedStyle(p).gridTemplateColumns.split(' ').length,cards:p.children.length,fit:[...p.children].every(c=>{const b=c.getBoundingClientRect();return b.left>=r.left-1&&b.right<=r.right+1&&b.bottom<=s.bottom+1;}),scroll:p.scrollHeight,client:p.clientHeight};})()`);
    check(year.columns===(width>=1366?3:width>=768?2:1)&&year.cards===12&&year.fit&&year.scroll<=year.client+1,'Year/'+width+'/'+dark+': 3/2/1 columns, all months, natural flow');
    const before=await geometry();await evaluate('document.getElementById("prismSidebarToggle").click()');check(same(before,await geometry()),'Year/'+width+'/'+dark+': navigation overlays full year shell');
    await evaluate('document.getElementById("prismSidebarToggle").click();document.getElementById("yearCalendarView").scrollIntoView({block:"start"})');await shot('year-'+width+'-'+(dark?'dark':'light'));metrics.push({width,dark,year});
    await popup('Year/'+width+'/'+dark,'#yearCalendarView [data-official-deadline=true]');
  }
  setManagement('student');await navigate('admin_archived_accounts.php',375,false,'populated','admin');setDelay(300);
  await evaluate('document.getElementById("retentionSelectionMode").click();document.querySelector(".retention-toolbar-actions > button").click();document.getElementById("retentionSelectionMode").click()');
  await evaluate('new Promise(r=>setTimeout(r,500))');setDelay(0);await evaluate('document.getElementById("retentionSelectionMode").click()');
  check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("0 accounts selected")'),'Pending select-all discarded after selection OFF');
  // Official and browser-local reminders remain independently visible on a shared date.
  await navigate('student.php',390,true,'populated','student');
  await evaluate(`localStorage.setItem('prismReminders:'+document.body.dataset.portalKey,JSON.stringify({[${JSON.stringify(today())}]:[{id:'personal',title:'Personal only',time:'09:00',notes:'Local browser'}]}))`);
  await command('Page.reload');await waitFor('document.querySelector("#monthGrid .has-official-deadline .day-task:not(.official-marker)")');
  check(await evaluate('document.querySelector("#monthGrid .has-official-deadline .official-marker") && document.querySelector("#monthGrid .has-official-deadline .day-task:not(.official-marker)").textContent.includes("Personal only")'),'Personal plus official date uses separate markers');
  await evaluate('document.querySelector("[data-page=calendar]").click()');await popup('coexisting reminders','#monthGrid .has-official-deadline');
  check(!getRequests().some(r=>r.file==='calendar_deadlines_api.php'&&r.method!=='GET'),'Personal reminder never sent to deadline server');
  for(const type of ['student','adviser'])for(const dark of [false,true]) {
    setManagement(type);await navigate('admin_archived_accounts.php',390,dark,'verification-invalid','admin');
    await waitFor('document.getElementById("permanentDeleteAvailability").textContent.includes("Deployment verification required")');
    check(await evaluate('[...document.querySelectorAll(".prism-action-panel .lifecycle-danger")].every(b=>b.getAttribute("aria-disabled")==="true")'),'Invalid verification blocks all '+type+' purge/override row actions in '+dark);
    await evaluate('document.querySelector(".prism-action-panel .lifecycle-danger").click()');check(await evaluate('!document.getElementById("permanentDeleteDialog").open'),'Blocked purge click opens no destructive confirmation');
    await evaluate('document.getElementById("retentionSelectionMode").click();document.querySelector("[data-retention-id]").click();document.getElementById("retentionBulkAction").value="permanent_delete";document.querySelectorAll(".retention-toolbar-actions > button")[2].click()');
    await evaluate('new Promise(r=>setTimeout(r,100))');
    check(await evaluate('!document.getElementById("permanentDeleteDialog").open && document.querySelector(".prism-toast-wrap").textContent.includes("Deployment verification required")'),'Invalid verification blocks bulk purge preview for '+type+'/'+dark);
  }
  for(const [file,role,selector] of [['student.php','student','#dashboardCalendarGrid'],['research_adviser.php','adviser','#adviserCalendarDays'],['dashboard.php','admin','#calendarDays']])for(const scenario of ['empty','single']) {
    await navigate(file,375,true,scenario,role);
    await waitFor('document.getElementById("dashboardDeadlineState").getAttribute("aria-busy")==="false"');
    check(await evaluate(`document.querySelectorAll('${selector} [data-official-deadline=true]').length===${scenario==='empty'?0:1}`),role+': '+scenario+' deadline markers');
    if(scenario==='single') {
      await evaluate(`document.querySelector('${selector} [data-official-deadline=true]').click()`);await waitFor('document.querySelectorAll(".prism-deadline-detail").length===1');
      await keyPress('Escape','Escape',27);await waitFor('!document.querySelector(".prism-readonly-dialog")');
    }
  }
  // Measure semantic buttons on their actual toolbar/menu surfaces in both themes.
  for(const dark of [false,true]) {
    setManagement('student');await navigate('admin_students.php',1366,dark,'populated','admin');
    await evaluate("{const t=document.getElementById('prismSidebarToggle');if(t.getAttribute('aria-expanded')==='true')t.click()}");
    await evaluate('new Promise(r=>setTimeout(r,250))');
    for(const [variant,selector] of [['primary','#inviteAccount'],['secondary','.prism-filter-disclosure > button'],['neutral','.prism-action-trigger'],['danger','.prism-action-panel:not([hidden]) .prism-action-danger']]) {
      if(variant==='danger')await evaluate('document.querySelector(".prism-action-trigger").click()');
      const point=await evaluate(`(() => {const e=document.querySelector(${JSON.stringify(selector)});e.scrollIntoView({behavior:'instant',block:'center',inline:'center'});const r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2};})()`);
      const state=()=>evaluate(`(() => {const e=document.querySelector(${JSON.stringify(selector)}),s=getComputedStyle(e);const rgba=v=>v.match(/[\\d.]+/g).map(Number);function paint(n){if(!n)return [255,255,255];const b=rgba(getComputedStyle(n).backgroundColor),a=b[3]??1,p=paint(n.parentElement);return b.slice(0,3).map((v,i)=>v*a+p[i]*(1-a));}return {foreground:s.color,background:'rgb('+paint(e).join(',')+')',border:s.borderTopColor,outline:s.outlineStyle,outlineWidth:parseFloat(s.outlineWidth),opacity:+s.opacity,cursor:s.cursor,hover:e.matches(':hover'),active:e.matches(':active'),classes:e.className};})()`);
      const states={normal:await state()};
      await command('Input.dispatchMouseEvent',{type:'mouseMoved',...point});await evaluate('new Promise(r=>setTimeout(r,180))');states.hover=await state();
      check(states.hover.hover,variant+'/'+dark+': pointer reached actual hover state');
      await keyPress('Tab','Tab',9);await evaluate(`document.querySelector(${JSON.stringify(selector)}).focus()`);states.focus=await state();
      check(states.focus.outline!=='none'&&states.focus.outlineWidth>=2,variant+'/'+dark+': visible keyboard focus');
      await command('Input.dispatchMouseEvent',{type:'mousePressed',...point,button:'left',clickCount:1});states.active=await state();
      check(states.active.active,variant+'/'+dark+': pointer reached actual active state');
      await command('Input.dispatchMouseEvent',{type:'mouseReleased',x:1,y:1,button:'left',clickCount:1});
      // Some menus close on outside release; inspect disabled state on the same control by reopening.
      if(variant==='danger'&&await evaluate('!document.querySelector(".prism-action-panel:not([hidden])")'))await evaluate('document.querySelector(".prism-action-trigger").click()');
      await evaluate(`document.querySelector(${JSON.stringify(selector)}).disabled=true`);states.disabled=await state();
      check(states.disabled.opacity<1&&states.disabled.cursor==='not-allowed',variant+'/'+dark+': disabled state remains recognizable');
      await evaluate(`document.querySelector(${JSON.stringify(selector)}).disabled=false`);
      for(const [name,s] of Object.entries(states).filter(([name])=>name!=='disabled'))check(ratio(s.foreground,s.background)>=4.5,variant+'/'+dark+': '+name+' text contrast');
      if(variant==='danger')check(states.normal.classes.includes('danger'),dark+': destructive action retains danger semantics');
      metrics.push({theme:dark?'dark':'light',variant,states});
      if(variant==='danger')await keyPress('Escape','Escape',27);
    }
    await shot('button-states-'+(dark?'dark':'light'));
  }
  check(errors.length===0,'No Batch 4.5 browser runtime exceptions',errors.join(' | '));
  fs.writeFileSync(path.join(dest,'metrics.json'),JSON.stringify(metrics,null,2));console.log('Batch 4.5 artifacts: '+dest);
}
module.exports={run};
