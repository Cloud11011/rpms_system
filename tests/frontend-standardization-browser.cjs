'use strict';
// Real Chromium, synthetic accounts, private HTTP source and owned frozen-v10 MariaDB only.
const fs = require('node:fs'), path = require('node:path'), os = require('node:os');
const assert = require('node:assert/strict'), crypto = require('node:crypto');
const {spawn} = require('node:child_process'), {once} = require('node:events');
const [origin, fixture] = process.argv.slice(2);
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(origin));
assert(path.resolve(fixture).startsWith(path.join(os.tmpdir(), 'prism-batch7-native-')));
const phase = process.env.PRISM_FRONTEND_PHASE || 'after';
const captureSet=process.env.PRISM_FRONTEND_CAPTURE_SET||phase;
assert(/^(before|after)(-(targeted|auth|calendar|polish|deadline|year|state))?$/.test(captureSet));
const out = path.join(process.env.PRISM_FRONTEND_EVIDENCE || path.join(__dirname, 'frontend-standardization-results'), captureSet);
fs.mkdirSync(out, {recursive:true});
const cache = path.join(__dirname, 'v10-batch1075-results/remediation/cdn-cache');
const assets = JSON.parse(fs.readFileSync(path.join(cache, 'assets.json'), 'utf8'));
for (const asset of Object.values(assets)) assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(cache, asset.file))).digest('hex'), asset.sha256);
const browser = ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
assert(browser, 'Installed Chromium required');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'prism-frontend-browser-'));
const matrix = [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,700]];
const allRoutes = [
 [0,'index.php'], [0,'login.php'], [0,'forgot_password.php'], [0,'reset_password.php'],
 [0,'reset_password.php?token=frontend-owned-reset'], [0,'register.php'], [0,'account_setup.php'], [0,'privacy.php'], [0,'terms.php'],
 [1001,'dashboard.php'], [1001,'admin_students.php'], [1001,'admin_advisers.php'], [1001,'documents.php'],
 [1001,'ierbprog.php'], [1001,'admin_archived_accounts.php'], [1001,'admin_notifications.php'],
 [1001,'admin_legal_policies.php'], [1001,'admin_ai.php'], [1001,'reports.php'], [1001,'calendar.php'],
 [1001,'account.php'], [1001,'data_export.php'], [1001,'staff_registration_manage.php'],
 [1002,'research_adviser.php'], [1002,'admin_students.php'], [1002,'documents.php'], [1002,'ierbprog.php'],
 [1002,'calendar.php'], [1002,'account.php'], [1002,'help_support.php'],
 [1003,'student.php'], [1003,'student.php#documents'], [1003,'student.php#progress'], [1003,'student.php#submit'],
 [1003,'student.php#calendar'], [1003,'student.php#notifications'], [1003,'student.php#profile'],
 [1003,'help_support.php'], [1006,'change_password_required.php','password'],
 [1006,'legal_consent.php','consent'], [1003,'complete_profile.php','profile']
];
const targets=process.env.PRISM_FRONTEND_TARGETS?.split(',');
const routes=targets?allRoutes.filter(([uid,route])=>targets.includes(uid+':'+route)):allRoutes;
assert(routes.length);
const results = [], captures = [], errors = [], samples = [], pending = new Map();
const canceledNetworks=new Set(), transportWarnings=[];
let child, socket, session, id = 0, version, injected = '', held = '', heldRequest;
function command(method, params={}, global=false) {
 return new Promise((resolve,reject) => { const request=++id;
  const timer=setTimeout(()=>{pending.delete(request);reject(Error('CDP timeout '+method));},30000);
  pending.set(request,{resolve:r=>{clearTimeout(timer);resolve(r);},reject:e=>{clearTimeout(timer);reject(e);}});
  socket.send(JSON.stringify({id:request,method,params,...(!global&&session?{sessionId:session}:{})}));
 });
}
async function ev(expression) {
 const r=await command('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
 if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);
 return r.result.value;
}
async function until(expression) {
 for(let i=0;i<200;i++){if(await ev(expression))return;await new Promise(r=>setTimeout(r,40));}
 throw Error('Readiness timeout '+expression+' at '+await ev('location.href'));
}
async function nav(route) {
 await command('Page.navigate',{url:origin+'/'+route});
 await until('document.readyState==="complete"&&location.origin==='+JSON.stringify(origin));
 await ev('document.fonts.ready.then(()=>true)');
}
async function auth(uid) {
 await nav('fixture-blank.php');
 await ev('fetch("fixture-auth.php",{method:"POST",body:new URLSearchParams({uid:'+uid+'})}).then(r=>r.text())');
}
async function state(mode) { await ev('fetch("fixture-frontend-state.php",{method:"POST",body:new URLSearchParams({mode:'+JSON.stringify(mode)+'})}).then(r=>r.text())'); }
function check(passed,label,detail) {results.push({label,passed:!!passed,...(detail===undefined?{}:{detail})});if(!passed)console.log('FAIL '+label);}
async function key(name,shift=false) {
 const p={key:name,code:name,windowsVirtualKeyCode:{Tab:9,Escape:27,Enter:13,ArrowDown:40,Home:36,End:35}[name],modifiers:shift?8:0};
 await command('Input.dispatchKeyEvent',{type:'keyDown',...p,...(name==='Enter'?{text:'\r'}:{})});await command('Input.dispatchKeyEvent',{type:'keyUp',...p});
}
async function capture(name,selector) {
 if(selector) await ev('(()=>{const n=document.querySelector('+JSON.stringify(selector)+');if(!n)return;const panel=n.closest(".full-calendar-card,.calendar-box,.prism-deadline-calendar,.dashboard-calendar-panel")||n;panel.scrollIntoView({block:"start"});const nav=document.querySelector(".portal-navbar");if(nav)scrollBy(0,-nav.getBoundingClientRect().height-16);})()');
 else await ev('scrollTo(0,0)');
 await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});
 await new Promise(r=>setTimeout(r,50));
 const result=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
 fs.writeFileSync(path.join(out,name+'.png'),Buffer.from(result.data,'base64'));
 captures.push({file:name+'.png',selector:selector||'viewport',width:await ev('innerWidth'),height:await ev('innerHeight')});
}
const slug = route => route.replace(/[?#=]/g,'-').replace(/\.php/g,'');
async function ready(route) {
 if(['admin_students.php','admin_advisers.php','admin_archived_accounts.php','documents.php','ierbprog.php'].includes(route))
  await until('!!document.querySelector(".prism-record-card,.empty-state,.prism-empty-state")');
 if(route.startsWith('student.php')) await until('!!document.querySelector("#documentRows .prism-record-card,#documentRows .empty-state")');
 if(route==='research_adviser.php') await until('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');
 if(await ev('!!document.getElementById("dashboardDeadlineState")'))await until('document.getElementById("dashboardDeadlineState").getAttribute("aria-busy")!=="true"');
 await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:500,y:200});
 await ev('(()=>{const b=document.getElementById("prismSidebarToggle");if(b?.getAttribute("aria-expanded")==="true")b.click();})()');
 await new Promise(r=>setTimeout(r,160));
}
async function menu(label) {
 if(!await ev('!![...document.querySelectorAll(".prism-action-trigger")].find(n=>n.getClientRects().length)'))return;
 await ev('(()=>{const n=[...document.querySelectorAll(".prism-action-trigger")].find(n=>n.getClientRects().length);n.scrollIntoView({block:"center"});n.focus();})()');
 await key('ArrowDown');
 check(await ev('document.activeElement.matches(".prism-action-item")'),label+' menu keyboard entry');
 check(await ev('(()=>{const n=document.querySelector(".prism-action-panel:not([hidden])");if(!n)return false;const r=n.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1&&n.contains(document.elementFromPoint(r.left+5,r.top+5));})()'),label+' menu visible inside viewport');
 await key('End');await key('Home');await key('Escape');
 check(await ev('!document.querySelector(".prism-action-panel:not([hidden])")&&document.activeElement.matches(".prism-action-trigger")'),label+' menu Escape focus return');
}
async function focused() {
 await command('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
 await auth(1001);await nav('documents.php');await ready('documents.php');
 await ev('(()=>{const b=document.querySelector("[data-override]");b.closest(".prism-record-actions").querySelector(".prism-action-trigger").click();b.focus();})()');await key('Enter');
 await until('!!document.querySelector(".prism-overlay")');
 check(await ev('document.activeElement.id==="prismReason"'),'Override reason focus preserved');
 await ev('document.querySelector(".prism-overlay [data-act=ok]").click()');
 check(await ev('!!document.querySelector(".prism-field-error").textContent.trim()'),'Override required reason validation');
 await ev('document.querySelector(".prism-overlay [data-act=cancel]").focus()');await key('Tab');
 check(await ev('document.querySelector(".prism-dialog").contains(document.activeElement)'),'Dialog Tab containment');
 await capture('override-validation-mobile','.prism-overlay');await key('Escape');
 check(await ev('document.activeElement.matches(".prism-action-trigger")'),'Override Escape focus return');
 await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
 await nav('dashboard.php');await ready('dashboard.php');
 check(await ev('getComputedStyle(document.querySelector(".ai-summary-banner")).animationName==="none"'),'Reduced motion decorative banner');
 await command('Emulation.setEmulatedMedia',{features:[]});
 held='calendar_deadlines_api.php?action=dashboard_day';
 await ev('document.querySelector("[data-official-deadline=true]").click()');
 await until('!!document.querySelector("dialog[open]")&&document.querySelector("dialog[open]").textContent.includes("Loading official deadlines")');
 await until('!!document.querySelector("dialog[open]")');
 for(let i=0;i<100&&!heldRequest;i++)await new Promise(r=>setTimeout(r,20));
 check(!!heldRequest,'Deadline loading state on pending authorized request');
 await capture('deadline-loading-mobile','dialog[open]');
 await command('Fetch.continueRequest',{requestId:heldRequest});held='';heldRequest=undefined;
 await until('!!document.querySelector(".prism-deadline-detail")');
 check(await ev('document.querySelector("dialog[open]").textContent.includes("Batch11 official deadline")'),'Scoped deadline details retain API content');
 await capture('deadline-details-mobile','dialog[open]');await key('Escape');
 injected='calendar_deadlines_api.php?action=dashboard_day';
 await ev('document.querySelector("[data-official-deadline=true]").click()');
 await until('!!document.querySelector("dialog[open] [role=alert]")');
 check(true,'Deadline error state after injected service failure');
 await capture('deadline-error-mobile','dialog[open]');await key('Escape');injected='';
 for(const uid of [1001,1002,1003]) {
  await auth(uid);await nav(uid===1001?'dashboard.php':uid===1002?'research_adviser.php':'student.php');await ready(uid===1001?'dashboard.php':uid===1002?'research_adviser.php':'student.php');
  const selector=uid===1003?'#portalProfileToggle':'[data-prism-account-toggle]';
  await ev('document.querySelector('+JSON.stringify(selector)+')?.focus()');await key('Enter');await key('Escape');
  check(await ev('document.activeElement.matches('+JSON.stringify(selector)+')'),uid+' profile Escape focus return');
  await ev('document.querySelector("[data-prism-top-tour]").focus()');await key('Enter');await until('!!document.querySelector("dialog[open]")');
  await key('Escape');check(await ev('document.activeElement.matches("[data-prism-top-tour]")'),uid+' tour Escape focus return');
  if(await ev('!!document.querySelector("#prismResourcesTitle")'))await capture(uid+'-resources-mobile','#prismResourcesTitle');
 }
 await auth(1001);await nav('admin_legal_policies.php');await ready('admin_legal_policies.php');
 await ev('document.querySelector("input[name=policy][value=privacy]").closest("form").requestSubmit()');
 await until('!!document.getElementById("policyTitle")');
 await ev('document.getElementById("policyTitle").value="Unsaved frontend test";document.getElementById("policyTitle").dispatchEvent(new Event("input"));document.querySelector("[data-legal-cancel]").click()');
 check(await ev('document.getElementById("legalDiscardDialog").open'),'Legal policy unsaved-change dialog preserved');
 await capture('legal-unsaved-mobile','#legalDiscardDialog');
 await ev('document.getElementById("legalContinueEditing").click()');
 check(await ev('document.getElementById("policyTitle").value==="Unsaved frontend test"'),'Legal Continue editing preserves unsaved form');
 await ev('document.querySelector("[data-legal-cancel]").click();document.getElementById("legalDiscardChanges").click()');
 await until('!document.getElementById("policyTitle")');
 await auth(1003);await nav('student.php#documents');await ready('student.php#documents');
 await ev('document.getElementById("studentDocumentSearch").value="no-matching-frontend-record";document.getElementById("studentDocumentSearch").dispatchEvent(new Event("input"))');
 await until('document.getElementById("documentRows").textContent.includes("No documents match")');
 await capture('student-documents-empty-mobile','#documentRows');check(true,'Student search empty result');
 await nav('student.php#calendar');await ready('student.php#calendar');
 await ev('document.getElementById("taskTitle").value="Synthetic browser reminder";document.getElementById("reminderForm").requestSubmit()');
 check(await ev('document.getElementById("taskList").textContent.includes("Synthetic browser reminder")'),'Personal reminder browser storage preserved');
 await ev('document.querySelector(".task-action").click()');
 check(await ev('!document.getElementById("cancelEdit").hidden'),'Reminder edit Save/Cancel visible');
 await capture('student-calendar-reminder-mobile','#monthGrid');await ev('document.getElementById("cancelEdit").click()');
 await nav('student.php#profile');await ready('student.php#profile');
 await ev('document.getElementById("previewProfileImage").focus()');await key('Tab');
 check(await ev('document.activeElement.id==="removeProfileImage"'),'Photo preview and Remove participate in native keyboard order');
}
async function run() {
 child=spawn(browser,['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','--disable-background-networking','--disable-sync','--disable-extensions','about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const url=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timeout')),15000);child.once('error',reject);child.stderr.on('data',b=>{log+=b;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});});
 socket=new WebSocket(url);await once(socket,'open');
 socket.addEventListener('message',e=>{const m=JSON.parse(e.data);
  if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}
  else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
  else if(m.method==='Network.loadingFailed'&&m.params.canceled)canceledNetworks.add(m.params.requestId);
  else if(m.method==='Fetch.requestPaused') {
   const {requestId,request,networkId}=m.params,asset=assets[request.url];
   if(held&&request.url.includes(held)){heldRequest=requestId;return;}
   const fulfill=injected&&request.url.includes(injected)?{requestId,responseCode:503,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify({ok:false,message:'Synthetic service failure'})).toString('base64')}:asset?{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:asset.mime},{name:'Access-Control-Allow-Origin',value:'*'}],body:fs.readFileSync(path.join(cache,asset.file)).toString('base64')}:!request.url.startsWith(origin)?{requestId,responseCode:200,body:''}:null;
   command(fulfill?'Fetch.fulfillRequest':'Fetch.continueRequest',fulfill||{requestId}).catch(async e=>{
    // A navigation can cancel a paused request before its fulfillment reaches CDP.
    // Suppress only when Network.loadingFailed independently confirms cancellation.
    await new Promise(r=>setTimeout(r,30));
    if(e.message==='Invalid InterceptionId.'&&networkId&&canceledNetworks.has(networkId))transportWarnings.push({url:request.url,reason:'Confirmed canceled navigation request'});
    else errors.push(e.message);
   });
  }
 });
 version=await command('Browser.getVersion',{},true);
 const target=await command('Target.createTarget',{url:'about:blank'},true);session=(await command('Target.attachToTarget',{targetId:target.targetId,flatten:true},true)).sessionId;
 await command('Runtime.enable');await command('Network.enable');
 // Ordinary loopback API traffic does not need interception. Limiting interception to
 // cached HTTPS assets avoids racing navigation against unrelated background API requests.
 await command('Fetch.enable',{patterns:[{urlPattern:'https://*'},{urlPattern:origin+'/calendar_deadlines_api.php*action=dashboard_day*'}]});
 await command('Emulation.setTimezoneOverride',{timezoneId:'Asia/Manila'});await nav('login.php');
 for(const [width,height] of matrix)for(const dark of [false,true]) {
  await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
  await ev('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');
  for(const [uid,route,mode] of routes) {
   if(uid)await auth(uid);else await nav('logout.php');
   if(mode)await state(mode);await nav(route);await ready(route);
   if(captureSet.endsWith('-year')) {
    await ev('document.getElementById("viewYear").click()');
    await until('document.querySelectorAll("#yearCalendarView .year-month-card").length===12&&document.getElementById("dashboardDeadlineState").getAttribute("aria-busy")==="false"');
    check(await ev('!!document.querySelector(".prism-calendar-legend.is-year")?.getClientRects().length'),uid+'/'+width+'/'+dark+' year legend visible');
    await capture('1001-dashboard-year-'+width+'-'+(dark?'dark':'light'),'#yearCalendarView');
   }
   const label=uid+'/'+route+'/'+width+'/'+dark;
   const data=await ev('(()=>{const visible=n=>n.getClientRects().length;const controls=[...document.querySelectorAll("button,a.prism-btn,input:not([type=hidden]),select,textarea")].filter(visible);const clipped=controls.filter(n=>{const r=n.getBoundingClientRect();return r.width>0&&(r.left< -1||r.right>innerWidth+1);}).map(n=>({tag:n.tagName,id:n.id,class:n.className}));const grid=[...document.querySelectorAll("#calendarDays,#adviserCalendarDays,#dashboardCalendarGrid,#monthGrid")].find(visible);const cell=grid?.querySelector("button");const s=cell?getComputedStyle(cell):null;return {href:location.href,title:document.title,scroll:document.documentElement.scrollWidth,width:innerWidth,dark:document.documentElement.classList.contains("dark-theme"),clipped,passwordIcons:document.body.classList.contains("unified-login")?controls.filter(n=>n.matches(".toggle-password[aria-controls]")).map(n=>{const input=document.getElementById(n.getAttribute("aria-controls")),a=n.getBoundingClientRect(),b=input.getBoundingClientRect();return {id:input.id,offset:Math.abs(a.top+a.height/2-b.top-b.height/2)};}):[],buttons:controls.filter(n=>n.matches(".management-primary,.doc-primary,.doc-secondary,.ierb-primary,.ierb-secondary,.upload-document-button,.generate-report-button,.primary-btn,.save-task,.cancel-edit,.prism-btn:not(.is-sm):not(.prism-btn-sm):not(.prism-action-trigger):not(.prism-context-action):not(.is-icon),.view-switch button")).map(n=>({id:n.id,class:n.className,height:n.getBoundingClientRect().height,font:getComputedStyle(n).fontSize})),icons:controls.filter(n=>n.tagName==="BUTTON"&&!n.textContent.trim()&&!n.getAttribute("aria-label")&&!n.title).map(n=>n.id||n.className),calendar:s?{cellWidth:cell.getBoundingClientRect().width,radius:s.borderRadius,font:s.fontSize,color:s.color,background:s.backgroundColor,border:s.borderColor,padding:s.padding,minHeight:s.minHeight}:null};})()');
   check(data.scroll<=width+1,label+' horizontal fit',{scroll:data.scroll,width});
   check(data.dark===dark,label+' theme persistence');check(!data.clipped.length,label+' controls fit horizontally',data.clipped);
   check(!data.icons.length,label+' icon controls named',data.icons);
   check(data.href.endsWith(route==='student.php'?'student.php#dashboard':route),label+' authorized destination reached',{href:data.href});
   samples.push({label,...data});
   if(captureSet==='after-state')await ev('Promise.all([...document.querySelectorAll("#calendarDays button,#adviserCalendarDays button,#dashboardCalendarGrid button,#monthGrid button")].flatMap(n=>n.getAnimations()).map(a=>a.finished.catch(()=>{}))).then(()=>true)');
   if(captureSet==='after-state') {
    const stateStyle=await ev('(()=>{const visible=n=>n.getClientRects().length;const grid=[...document.querySelectorAll("#calendarDays,#adviserCalendarDays,#dashboardCalendarGrid,#monthGrid")].find(visible);const cell=grid.querySelector("[data-today=true],.today,.active-day"),style=getComputedStyle(cell),probe=document.createElement("span");probe.style.backgroundColor=cell.dataset.officialDeadline==="true"?"var(--prism-pink-soft)":"var(--prism-primary)";document.body.append(probe);const bg=getComputedStyle(probe).backgroundColor;probe.remove();const marker=cell.querySelector(".prism-deadline-marker");return {actual:style.backgroundColor,expected:bg,radius:style.borderRadius,height:cell.getBoundingClientRect().height,marker:marker?getComputedStyle(marker).fontSize:null,official:cell.dataset.officialDeadline};})()');
    check(stateStyle.actual===stateStyle.expected&&stateStyle.radius==='8px'&&stateStyle.height>=36&&(!stateStyle.marker||stateStyle.marker==='0px'),label+' today/deadline/marker share Admin state styling',stateStyle);
   }
   if(captureSet==='after-deadline') {
    await until('!!document.querySelector(".official-deadline .cancel-edit")');
    check(await ev('(()=>{const b=document.querySelector(".official-deadline .cancel-edit"),s=document.createElement("span");s.style.color="var(--prism-danger-fg)";document.body.append(s);const expected=getComputedStyle(s).color;s.remove();return b.classList.contains("prism-btn-danger")&&getComputedStyle(b).color===expected;})()'),label+' authorized cancellation uses destructive tone');
   }
   if(route.includes('frontend-owned-reset'))check(await ev('document.getElementById("password").getClientRects().length>0'),label+' valid reset form visible');
   if(['login.php','register.php','forgot_password.php','reset_password.php','reset_password.php?token=frontend-owned-reset'].includes(route))check(await ev('document.querySelector(".login-card,.register-card,.forgot-card").getBoundingClientRect().width<=430.5'),label+' auth card follows Login width');
   if(phase==='after')check(data.passwordIcons.every(p=>p.offset<=2),label+' password icons centered',data.passwordIcons);
   if(phase==='after')check(data.buttons.every(b=>b.height>=43.9),label+' standard controls at least 44px',data.buttons.filter(b=>b.height<43.9));
   if(uid&&phase==='after')await menu(label);
   if(uid===1002||uid===1003)check(await ev('![...document.querySelectorAll(".prism-action-item")].some(n=>/admin override|permanent|purge|retention/i.test(n.textContent))'),label+' restricted actions absent');
   if(data.calendar) {
    check(await ev('!!document.querySelector(".prism-calendar-legend")'),label+' calendar legend');
    if(phase==='after') {
     check(data.calendar.radius==='8px'&&data.calendar.font==='13.5px',label+' Admin reference cell geometry',data.calendar);
     check(data.calendar.cellWidth>=23.9,label+' date target width at least 24px',data.calendar.cellWidth);
     check(await ev('document.querySelectorAll("#dashboardCalendarGrid .mini-day-name").length===0'),label+' no repeated weekday row');
     check(await ev('!!document.querySelector("[data-today=true],.calendar-day.today,.day.active-day,.mini-day.active-day")'),label+' today marked');
    }
   }
   if(width===1366||width===320||data.calendar)await capture(uid+'-'+slug(route)+'-'+width+'-'+(dark?'dark':'light'),data.calendar?'#'+(route==='dashboard.php'?'calendarDays':route==='research_adviser.php'?'adviserCalendarDays':route.includes('#calendar')||route==='calendar.php'?'monthGrid':'dashboardCalendarGrid'):undefined);
   if(mode)await state('restore-'+mode);
  }
  console.log('Completed '+phase+' '+width+' '+(dark?'dark':'light'));
 }
 if(phase==='after'&&!targets)await focused();
 check(!errors.length,'No browser exceptions',errors);
 fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({phase,version,matrix,routes,results,samples,errors,transportWarnings,captures},null,2));
 console.log('Frontend '+phase+': '+results.length+' assertions; '+results.filter(r=>!r.passed).length+' failures; '+captures.length+' screenshots.');
 if(phase==='after')process.exitCode=results.some(r=>!r.passed)?1:0;
}
run().catch(async error=>{console.error(error.stack);await capture('failure').catch(()=>{});fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:error.message,results,samples,errors,captures},null,2));process.exitCode=1;}).finally(async()=>{
 if(socket?.readyState===WebSocket.OPEN){await command('Browser.close',{},true).catch(()=>{});socket.close();}
 if(child&&child.exitCode===null){await Promise.race([once(child,'exit'),new Promise(r=>setTimeout(r,2000))]);if(child.exitCode===null)child.kill();}
 assert.equal(path.dirname(path.resolve(profile)),path.resolve(os.tmpdir()));assert(path.basename(profile).startsWith('prism-frontend-browser-'));
 try { fs.rmSync(profile,{recursive:true,force:true,maxRetries:15,retryDelay:500}); }
 catch(error) { fs.writeFileSync(path.join(out,'profile-cleanup.json'),JSON.stringify({removed:false,code:error.code,ownedProfile:profile},null,2)); }
});
