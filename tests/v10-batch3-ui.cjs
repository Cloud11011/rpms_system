'use strict';
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const retention=require('./account-retention-ui.cjs');
const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
let cancellationError=false;
function rows(viewer='admin') {
  return retention.mockList('students_api.php',new URLSearchParams()).students.map((row,i)=>({
    ...row,archivedAt:null,profileStatus:'Complete',academicUnitKey:i===0?'amt':i===1?'nursing':i===2&&viewer==='admin'?'dentistry':i===3||i===4||i===5?null:'amt',
    programKey:i===0?'bsit':i===1?'bsn':i===2&&viewer==='admin'?'ddm':i===3?null:i===4?'mba_thesis':i===5?'legacy_program':'bsit',
    yearLevel:i===2&&viewer==='admin'?'6th Year':i===3||i===4?null:'4th Year',
    course:i===0?'BS in Information Technology':i===1?'BS in Nursing':i===2&&viewer==='admin'?'Doctor of Dental Medicine':i===3?'Legacy course':'BS in Information Technology'
  }));
}
function mockApi(file,action,request,viewer) {
  if(file==='account_lifecycle_api.php') {
    const body=JSON.parse(request?.body||'{}');
    if((action||body.action)==='selection') {
      const ids=rows().filter(r=>Object.entries(body.filters||{}).every(([key,value])=>!value||key==='lifecycle'||key==='retention'||key==='profile'||key==='q'||String(r[key]??'')===value)).map(r=>r.id);
      return {ok:true,ids,total:ids.length,selectionToken:'batch3-selection'};
    }
    return retention.mockApi(file,action,request);
  }
  if(['students_api.php','ierb_api.php'].includes(file)&&action==='list')return {ok:true,[file==='students_api.php'?'students':'records']:rows(viewer)};
  if(file==='advisers_api.php'&&action==='list')return retention.mockList(file,new URLSearchParams());
  if(file==='account_invitation_api.php')return {ok:true,message:'Invitation sent.',emailSent:true};
  if(file==='calendar_deadlines_api.php'&&action==='cancel'&&cancellationError)return {ok:false,message:'The deadline changed or is outside your permitted scope.'};
  return null;
}
async function run(ctx) {
  const {check,evaluate,waitFor,navigate,command,keyPress,getRequests,errors,setDelay,setManagement}=ctx;
  const shots=fs.mkdtempSync(path.join(os.tmpdir(),'prism-batch3-ui-'));
  const snapshot=async()=>{await evaluate('new Promise(resolve=>setTimeout(resolve,400))');return command('Page.captureScreenshot',{format:'png'});};
  const calls=(file,action)=>getRequests().filter(r=>r.file===file&&r.action===action).length;
  const last=(file,action)=>getRequests().findLast(r=>r.file===file&&r.action===action);
  const set=async(id,value)=>evaluate(`{const e=document.getElementById(${JSON.stringify(id)});e.value=${JSON.stringify(value)};e.dispatchEvent(new Event('change',{bubbles:true}));}`);
  const bounds=()=>evaluate('document.documentElement.scrollWidth<=innerWidth+1');
  for(const [file,viewer,type,host,endpoint] of [
    ['admin_students.php','admin','student','recordFilters','students_api.php'],
    ['admin_students.php','adviser','student','recordFilters','students_api.php'],
    ['admin_advisers.php','admin','adviser','recordFilters','advisers_api.php'],
    ['ierbprog.php','admin','student','ierbMoreFilters','ierb_api.php'],
    ['ierbprog.php','adviser','student','ierbMoreFilters','ierb_api.php'],
    ['documents.php','admin','student','documentFilters','documents_api.php'],
    ['documents.php','adviser','student','documentFilters','documents_api.php']]) {
    for(const [width,height] of matrix)for(const dark of [false,true]) {
      setManagement(type);await navigate(file,width,dark,'populated',viewer);
      await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
      const prefix=`${file}/${viewer}/${width}/${dark?'dark':'light'}`;
      await waitFor(`document.getElementById('${host}')?.hidden===true`);
      check(await bounds(),prefix+': compact toolbar fits');
      check(await evaluate(`document.querySelector('[aria-controls="${host}"]').textContent==='Filters & Sort'`),prefix+': initially collapsed inactive disclosure');
      await evaluate(`document.querySelector('[aria-controls="${host}"]').focus()`);await keyPress('Enter','Enter',13);
      check(await evaluate('parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=2'),prefix+': visible keyboard focus');
      check(await evaluate(`!document.getElementById('${host}').hidden&&document.querySelector('[aria-controls="${host}"]').getAttribute('aria-expanded')==='true'`),prefix+': keyboard opens labeled panel');
      check(await bounds(),prefix+': expanded panel fits');
      check(await evaluate(`Array.from(document.getElementById('${host}').querySelectorAll('select')).every(e=>e.labels.length>0)`),prefix+': each secondary control labeled');
      check(await evaluate(`!document.getElementById('${host}').querySelector('#recordSearch,#ierbSearch,#documentSearch,#inviteAccount,#addRecord,#addIerbEntry,#uploadDocumentButton')`),prefix+': search and primary actions remain outside');
      if(type==='student' && file!=='documents.php') {
        await waitFor(`document.getElementById('${host}_programKey').options.length>2`);
        await set(host+'_programKey','bsit');await waitFor(`document.getElementById('${endpoint==='ierb_api.php'?'ierbRecordCount':'recordCount'}').textContent!=='Loading records...'`);
        const before=calls(endpoint,'list');await set(host+'_academicUnitKey','nursing');
        await waitFor(`document.getElementById('${host}_programKey').value===''&&document.querySelector('[aria-controls="${host}"]').textContent.includes('(1)')`);
        await evaluate('new Promise(resolve=>setTimeout(resolve,80))');
        check(calls(endpoint,'list')===before+1 && last(endpoint,'list').query.academicUnitKey==='nursing'&&last(endpoint,'list').query.programKey==='',prefix+': cascade precedes exactly one list request');
        check(await evaluate(`Array.from(document.getElementById('${host}_programKey').options).every(o=>!o.value||o.value==='bsn'||o.value==='__blank__')`),prefix+': excludes unrelated programs, retains blank');
        await set(host+'_academicUnitKey','');await waitFor(`Array.from(document.getElementById('${host}_programKey').options).some(o=>o.value==='bsit')`);
        check(await evaluate(`Array.from(document.getElementById('${host}_programKey').options).some(o=>o.value==='ddm')`)===(viewer==='admin'),prefix+': clearing unit restores only authorized programs');
        await set(host+'_programKey','bsit');
        check(await evaluate(`!Array.from(document.getElementById('${host}_yearLevel').options).some(o=>o.value==='6th Year')`),prefix+': year levels follow catalog duration');
        check(await evaluate(`document.getElementById('${host}_sortBy').options.length>4&&document.getElementById('${host}_academicYear').options.length>1`),prefix+': sorting and academic year remain independent');
        if(viewer==='admin'&&file==='admin_students.php') {
          await set(host+'_programKey','');await set(host+'_academicUnitKey','');
          await waitFor('document.querySelectorAll("[data-retention-id]").length>0');
          check(await evaluate('document.getElementById("retentionSelectionMode").getAttribute("aria-pressed")==="false"'),prefix+': selection initially off');
          await evaluate('document.getElementById("retentionSelectionMode").click()');
          await evaluate('document.querySelector("[data-retention-id]").click()');
          check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("1 accounts selected")'),prefix+': individual row selected');
          await set(host+'_academicUnitKey','nursing');
          check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("0 accounts selected")'),prefix+': material filter clears invisible selection immediately');
          await waitFor('!document.querySelector(".retention-toolbar-actions button").disabled');
          await evaluate('document.querySelector(".retention-toolbar-actions button").click()');
          await waitFor('document.querySelector(".retention-toolbar p").textContent.includes("snapshot of all matching")');
          check(JSON.parse(getRequests().findLast(r=>r.file==='account_lifecycle_api.php'&&JSON.parse(r.body||'{}').action==='selection').body).filters.academicUnitKey==='nursing',prefix+': select-all reconstructs final canonical filter');
          await set(host+'_academicUnitKey','amt');
          check(await evaluate('document.querySelector(".retention-toolbar p").textContent.startsWith("0 accounts selected")'),prefix+': changed unit clears select-all snapshot');
        }
      } else {
        await evaluate(`{const s=document.getElementById('${host}').querySelector('select');if(s.options.length>1){s.selectedIndex=1;s.dispatchEvent(new Event('change',{bubbles:true}));}}`);
      }
      await evaluate(`document.getElementById('${host}').querySelector('select').focus()`);await keyPress('Escape','Escape',27);
      check(await evaluate(`document.getElementById('${host}').hidden&&document.activeElement.getAttribute('aria-controls')==='${host}'`),prefix+': Escape closes and returns focus');
      await keyPress('Enter','Enter',13);
      await evaluate(`[...document.getElementById('${host}').querySelectorAll('button:not(:disabled),select,input,a[href]')].at(-1).focus()`);await keyPress('Tab','Tab',9);
      check(await evaluate(`!document.getElementById('${host}').contains(document.activeElement)`),prefix+': simple disclosure permits Tab to leave');
      await evaluate(`document.getElementById('${host}').querySelector('.prism-filter-clear').click()`);
      check(await evaluate(`document.querySelector('[aria-controls="${host}"]').textContent==='Filters & Sort'`),prefix+': Clear resets active indicator');
      if(width===375){const shot=await snapshot();fs.writeFileSync(path.join(shots,`${file}-${viewer}-${dark?'dark':'light'}-filters.png`),Buffer.from(shot.data,'base64'));}
    }
  }
  setManagement('student');
  await navigate('admin_students.php',375,true,'populated','admin',false,'&academicUnitKey=nursing&programKey=bsit&yearLevel=6th%20Year');
  check(last('students_api.php','list').query.academicUnitKey==='nursing' && last('students_api.php','list').query.programKey==='', 'Stale URL pair sanitized before first API request, parent retained');
  check(await evaluate('document.getElementById("recordFilters_academicUnitKey").value==="nursing"&&document.getElementById("recordFilters_programKey").value===""'),'URL dependency remains valid after scoped options load');
  await navigate('admin_students.php',375,true,'populated','admin',false,'&academicUnitKey=unavailable');
  check(last('students_api.php','list').query.academicUnitKey==='unavailable' && await evaluate('document.getElementById("recordFilters_academicUnitKey").selectedOptions[0].textContent.includes("unavailable")'),'Unavailable parent filter remains explicit instead of broadening results');
  for(const viewer of ['admin','adviser'])for(const [width,height] of matrix)for(const dark of [false,true]) {
    await navigate('admin_students.php',width,dark,'populated',viewer);await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    await evaluate('document.getElementById("inviteAccount").click()');
    check(await evaluate('document.getElementById("inviteDialog").getBoundingClientRect().width<=innerWidth&&document.getElementById("inviteDomainHint").textContent.includes("@example.test")'),'Invitation fits and shows configured domain hint '+viewer+width+dark);
    for(const email of ['new@fakeexample.test','new@example.test.attacker.com','new@dept.example.test','new@wrong.test','bad-email']) {
      const before=calls('account_invitation_api.php','invite');
      await evaluate(`{const e=document.getElementById('inviteEmail');e.value=${JSON.stringify(email)};e.dispatchEvent(new Event('input'));document.getElementById('inviteForm').requestSubmit();}`);
      check(await evaluate('document.getElementById("inviteEmail").getAttribute("aria-invalid")==="true"&&document.getElementById("inviteEmailError").textContent.length>0'),'Inline invalid state and accessible message '+email);
      check(calls('account_invitation_api.php','invite')===before,'Invalid domain/syntax prevents client submission '+email);
    }
    check(await evaluate('document.getElementById("inviteEmail").getAttribute("aria-describedby").split(" ").every(id=>document.getElementById(id))'),'Email error and configured hint associated with input');
    if(width===375){const shot=await snapshot();fs.writeFileSync(path.join(shots,`invite-${viewer}-${dark?'dark':'light'}.png`),Buffer.from(shot.data,'base64'));}
    await evaluate('document.getElementById("inviteEmail").value="  NEW@EXAMPLE.TEST  ";document.getElementById("inviteEmail").dispatchEvent(new Event("input"));');
    check(await evaluate('document.getElementById("inviteEmailError").textContent===""&&document.getElementById("inviteEmail").getAttribute("aria-invalid")==="false"'),'Correcting normalized domain clears error');
    await evaluate('document.getElementById("inviteForm").requestSubmit()');await waitFor('!document.getElementById("inviteDialog").open');
    const payload=JSON.parse(last('account_invitation_api.php','invite').body);
    check(payload.email==='NEW@EXAMPLE.TEST'&&payload.accountType==='student'&&(viewer!=='adviser'||!('adviserId'in payload)),'Normalized client payload preserves Adviser authority');
  }
  for(const viewer of ['admin','adviser'])for(const [width,height] of matrix)for(const dark of [false,true]) {
    await navigate('calendar.php',width,dark,'populated',viewer);await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    await waitFor('document.querySelector("#officialDeadlineList button")&&!document.querySelector("#deadlineForm [type=submit]").disabled');
    await evaluate('document.querySelector(".deadline-create").open=true;document.querySelector("#deadlineForm [name=target]").value="selected";document.querySelector("#deadlineForm [name=target]").dispatchEvent(new Event("change"));');
    check(await bounds(),'Deadline selector fits '+viewer+width+dark);
    check(await evaluate('Array.from(document.querySelectorAll("#deadlineGroupChoices input")).every(e=>e.labels.length===1)'),'Group checkbox labels and authorized-only source');
    check(await evaluate('Array.from(document.querySelectorAll("#deadlineGroupChoices label")).every(e=>getComputedStyle(e).display==="flex")'),'Group checkbox and label share a readable row');
    check(await evaluate('getComputedStyle(document.getElementById("deadlineGroupCount")).color===getComputedStyle(document.querySelector(".prism-group-selector legend")).color'),'Selected-count text uses the current theme foreground');
    check(await evaluate('document.querySelectorAll("#deadlineGroupChoices input").length')===(viewer==='admin'?2:1),'Management group options preserve role scope');
    await evaluate('document.querySelector("#deadlineGroupChoices input").click();document.getElementById("deadlineGroupSearch").value="G02";document.getElementById("deadlineGroupSearch").dispatchEvent(new Event("input"));');
    check(await evaluate('document.getElementById("deadlineGroupCount").textContent.startsWith("1 selected")&&document.querySelector("#deadlineForm [name=groups]").selectedOptions.length===1'),'Search retains hidden selections and announces count');
    await evaluate('document.getElementById("deadlineGroupClear").click()');
    check(await evaluate('document.querySelector("#deadlineForm [name=groups]").selectedOptions.length===0'),'Clear deselects all authorized choices including hidden matches');
    await evaluate('document.querySelector("#deadlineForm [name=title]").value="Batch 3";document.querySelector("#deadlineForm [name=date]").value="2026-10-10";document.querySelector("#deadlineForm").requestSubmit()');
    check(await evaluate('document.getElementById("deadlineGroupError").textContent.includes("Choose at least one")'),'Empty group selection has accessible error');
    await evaluate('document.getElementById("deadlineGroupSearch").value="";document.getElementById("deadlineGroupSearch").dispatchEvent(new Event("input"));document.querySelectorAll("#deadlineGroupChoices input").forEach(e=>e.click());');
    if(width===375){await evaluate('document.getElementById("deadlineGroupsLabel").scrollIntoView({block:"center"})');const shot=await snapshot();fs.writeFileSync(path.join(shots,`selector-${viewer}-${dark?'dark':'light'}.png`),Buffer.from(shot.data,'base64'));}
    const createBefore=calls('calendar_deadlines_api.php','create');setDelay(100);
    await evaluate('document.querySelector("#deadlineForm").requestSubmit();document.querySelector("#deadlineForm").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}));');
    await waitFor('!document.querySelector("#deadlineForm [type=submit]").disabled');setDelay(0);
    check(calls('calendar_deadlines_api.php','create')===createBefore+1,'Double submission produces one create request');
    check(JSON.parse(last('calendar_deadlines_api.php','create').body).groups.length===(viewer==='admin'?2:1),'Checkbox submission uses unchanged canonical group array');
    await waitFor('document.querySelector("#officialDeadlineList button")');
    for(const cancelVia of ['keep','Escape','backdrop','confirm']) {
      const before=calls('calendar_deadlines_api.php','cancel');
      await evaluate('document.querySelector("#officialDeadlineList button").focus();document.querySelector("#officialDeadlineList button").click();document.querySelector("#officialDeadlineList button").click();');
      await waitFor('document.querySelector(".prism-overlay")');
      check(calls('calendar_deadlines_api.php','cancel')===before,'First/double click only opens one dialog');
      check(await evaluate('document.querySelectorAll(".prism-overlay").length===1&&document.querySelector(".prism-dialog[role=dialog][aria-modal=true]")&&document.querySelector(".prism-cancel-summary").textContent.includes("AMT-BSIT")&&!document.querySelector(".prism-cancel-summary img")'),'Confirmation includes escaped title/date/groups and modal semantics');
      check(await evaluate('(()=>{const d=document.querySelector(".prism-overlay .prism-dialog"),r=d.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1&&d.scrollWidth<=d.clientWidth+1})()'),'Cancellation dialog contained');
      if(width===375&&cancelVia==='keep'){const shot=await snapshot();fs.writeFileSync(path.join(shots,`cancel-${viewer}-${dark?'dark':'light'}.png`),Buffer.from(shot.data,'base64'));}
      await evaluate('document.querySelector(".prism-overlay [data-act=ok]").focus()');await keyPress('Tab','Tab',9);
      check(await evaluate('document.querySelector(".prism-overlay").contains(document.activeElement)'),'Cancellation traps Tab focus');
      if(cancelVia==='keep')await evaluate('document.querySelector(".prism-overlay [data-act=cancel]").click()');
      if(cancelVia==='Escape')await keyPress('Escape','Escape',27);
      if(cancelVia==='backdrop')await evaluate('document.querySelector(".prism-overlay").dispatchEvent(new MouseEvent("mousedown",{bubbles:true}))');
      if(cancelVia==='confirm'){setDelay(100);await evaluate('document.querySelector(".prism-overlay [data-act=ok]").click()');}
      await waitFor('!document.querySelector(".prism-overlay")&&document.querySelector("#officialDeadlineList button")&&!document.querySelector("#officialDeadlineList button").disabled');setDelay(0);
      check(calls('calendar_deadlines_api.php','cancel')===before+(cancelVia==='confirm'?1:0),'Only explicit confirmation makes exactly one backend request');
      if(cancelVia!=='confirm')check(await evaluate('document.activeElement===document.querySelector("#officialDeadlineList button")'),'Dismissal returns focus to Cancel deadline');
    }
    if(width===375){const shot=await snapshot();fs.writeFileSync(path.join(shots,`deadline-${viewer}-${dark?'dark':'light'}.png`),Buffer.from(shot.data,'base64'));}
  }
  cancellationError=true;await evaluate('document.querySelector("#officialDeadlineList button").click()');await waitFor('document.querySelector(".prism-overlay")');await evaluate('document.querySelector(".prism-overlay [data-act=ok]").click()');
  await waitFor('document.querySelector(".prism-toast.is-error")');
  check(await evaluate('document.querySelector("#officialDeadlineList .official-deadline")&&document.querySelector(".prism-toast.is-error").textContent.includes("outside your permitted scope")'),'Backend failure leaves deadline visible and reports authoritative result');
  cancellationError=false;
  await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  check(await evaluate('getComputedStyle(document.getElementById("deadlineGroupClear")).transitionDuration==="0s"'),'New selector honors reduced motion');
  check(errors.length===0,'No browser or CSP errors: '+errors.join(' | '));
  console.log('Batch 3 screenshots: '+shots);
}
module.exports={run,mockApi};
