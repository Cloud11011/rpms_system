'use strict';
// Real Chromium against a verified disposable frozen-v10 HTTP fixture. No production URL accepted.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const {spawn}=require('node:child_process'),{once}=require('node:events');
const origin=process.argv[2],fixture=process.argv[3],phase=process.argv[4]||'after';
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(origin));assert(path.resolve(fixture).startsWith(path.join(os.tmpdir(),'prism-batch7-native-')));
const browser=['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);assert(browser);
const out=path.join(__dirname,'batch11-results',phase);fs.mkdirSync(out,{recursive:true});
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'prism-b11-browser-'));
const cache=path.join(__dirname,'v10-batch1075-results/remediation/cdn-cache'),assets=JSON.parse(fs.readFileSync(path.join(cache,'assets.json'),'utf8'));
for(const entry of Object.values(assets))assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(cache,entry.file))).digest('hex'),entry.sha256);
const results=[],errors=[],captures=[],requests=[],pending=new Map();let child,socket,session,id=0,injected='',held='',holdRequest,downloadRequests=0;
function check(value,label,detail){results.push({label,passed:!!value,...(detail?{detail}: {})});if(!value)console.log('FAIL '+label);}
function command(method,params={},global=false){return new Promise((resolve,reject)=>{const request=++id,timer=setTimeout(()=>{pending.delete(request);reject(Error('CDP timeout '+method));},30000);pending.set(request,{resolve:r=>{clearTimeout(timer);resolve(r);},reject:e=>{clearTimeout(timer);reject(e);}});socket.send(JSON.stringify({id:request,method,params,...(!global&&session?{sessionId:session}:{})}));});}
async function ev(expression){const r=await command('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;}
async function until(expression){for(let i=0;i<300;i++){if(await ev(expression))return;await new Promise(r=>setTimeout(r,40));}throw Error('Readiness timeout '+expression+' at '+await ev('location.href'));}
async function nav(route){const url=origin+'/'+route;await command('Page.navigate',{url});await until('location.href==='+JSON.stringify(route==='logout.php'?origin+'/login.php':url)+'&&document.readyState==="complete"');await ev('document.fonts.ready.then(()=>true)');}
async function auth(uid){await nav('fixture-blank.php');await ev('fetch("fixture-auth.php",{method:"POST",body:new URLSearchParams({uid:'+uid+'})}).then(r=>r.text())');}
async function key(name,shift=false){const codes={Tab:9,Escape:27,Enter:13,ArrowDown:40,ArrowUp:38,Home:36,End:35};const p={key:name,code:name,windowsVirtualKeyCode:codes[name],modifiers:shift?8:0};await command('Input.dispatchKeyEvent',{type:'keyDown',...p,...(name==='Enter'?{text:'\r'}:{})});await command('Input.dispatchKeyEvent',{type:'keyUp',...p});}
async function capture(name,selector){await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});await ev('(()=>{document.querySelector(".prism-control-tooltip")?.setAttribute("hidden","");})()');if(selector)await ev('(()=>{const n=document.querySelector('+JSON.stringify(selector)+');if(!n)return;n.scrollIntoView({block:"start"});const nav=document.querySelector(".portal-navbar");if(nav)scrollBy(0,-nav.getBoundingClientRect().height-16);})()');await new Promise(r=>setTimeout(r,80));const r=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(out,name+'.png'),Buffer.from(r.data,'base64'));captures.push({file:name+'.png',selector:selector||'viewport',width:await ev('innerWidth'),height:await ev('innerHeight')});}
async function collapse(){await ev('(()=>{const b=document.getElementById("prismSidebarToggle");if(b?.getAttribute("aria-expanded")==="true")b.click();})()');}
async function ready(route){
 if(route==='dashboard.php')await until('document.getElementById("repositoryCount").textContent!=="Loading recent uploads..."');
 if(['admin_students.php','admin_advisers.php','admin_archived_accounts.php','documents.php','ierbprog.php'].includes(route))await until('!!document.querySelector(".prism-record-card,.empty-state,.prism-empty-state")');
 if(route==='research_adviser.php')await until('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');
 if(route==='student.php')await until('!!document.getElementById("documentRows").children.length');
 await new Promise(r=>setTimeout(r,180));await collapse();
}
async function menu(label){
 const selector='.prism-action-trigger';if(!await ev('!!document.querySelector('+JSON.stringify(selector)+')'))return;
 await ev('document.querySelector('+JSON.stringify(selector)+').scrollIntoView({block:"center"});document.querySelector('+JSON.stringify(selector)+').focus()');await key('ArrowDown');
 check(await ev('document.activeElement.matches(".prism-action-item")'),label+' ArrowDown enters menu');
 check(await ev('(()=>{const p=document.querySelector(".prism-action-panel:not([hidden])"),r=p.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1&&p.contains(document.elementFromPoint(r.left+5,r.top+5));})()'),label+' menu in viewport and unobscured');
 if(phase!=='before')check(await ev('[...document.querySelectorAll(".prism-action-panel:not([hidden]) .prism-action-item")].every(n=>n.textContent.trim()&&n.querySelector("i,svg"))'),label+' icon plus label menu');
 await key('End');check(await ev('(()=>{const p=document.activeElement.closest(".prism-action-panel"),a=[...p.querySelectorAll("button:not(:disabled),a[href]")];return document.activeElement===a.at(-1);})()'),label+' End last action');
 await key('Home');await key('Tab');await key('Tab',true);await key('Escape');check(await ev('!document.querySelector(".prism-action-panel:not([hidden])")&&document.activeElement.matches(".prism-action-trigger")'),label+' Escape focus returns');
}
async function testProfile(label,uid){
 const selector=uid===1003?'#portalProfileToggle':'[data-prism-account-toggle]';
 if(!await ev('!!document.querySelector('+JSON.stringify(selector)+')'))return;
 await ev('document.querySelector('+JSON.stringify(selector)+').focus()');await key('Enter');
 if(uid!==1003){check(await ev('(()=>{const p=document.getElementById("prismAccountLinks"),s=p.textContent;return !p.hidden&&s.includes("My account")&&s.includes("Activity")&&s.includes("Help")&&!s.includes("Quick Tour")&&!s.includes("Navigation Guide");})()'),label+' consolidated profile links');}
 await key('Escape');
 await ev('document.querySelector("[data-prism-top-tour]").focus()');await key('Enter');await until('!!document.querySelector("dialog[open]")');
 check(await ev('document.querySelector("dialog[open]").textContent.includes("Navigation Guide")'),label+' Tour and Guide reachable from topbar');
 await key('Escape');check(await ev('document.activeElement.matches("[data-prism-top-tour]")'),label+' Tour Escape restores topbar focus');
}
async function overrideDialog(label,width,dark){
 const control=await ev('(()=>{const x=[...document.querySelectorAll("[data-override]")][0];if(!x)return false;x.closest(".prism-record-actions").querySelector(".prism-action-trigger").click();x.focus();return true;})()');
 if(!control){check(false,label+' eligible override control');return;}
 const mutations=requests.length;await key('Enter');await until('!!document.querySelector(".prism-overlay")');
 check(await ev('document.querySelector(".prism-overlay").textContent.includes("Current review / workflow")&&document.querySelector(".prism-overlay").textContent.includes("document version")'),label+' contextual override confirmation');
 check(await ev('document.activeElement.id==="prismReason"'),label+' reason focused');
 await ev('document.querySelector(".prism-overlay [data-act=ok]").click()');check(await ev('document.querySelector(".prism-field-error").textContent.includes("reason")'),label+' missing reason client validation');
 await capture('override-'+width+'-'+dark,'.prism-overlay');await key('Escape');
 check(requests.length===mutations,label+' cancel no mutation');check(await ev('document.activeElement.matches(".prism-action-trigger")'),label+' override cancel focus');
}
async function studentDocs(label,width,dark){
 await ev('document.querySelector("#portalNav [data-page=documents]").click()');await until('!!document.querySelector("#documentRows .prism-record-card")');
 const bounds=await ev('(()=>{const t=document.getElementById("documentRows").closest("table"),c=t.querySelector("td"),p=c.querySelector(".prism-record-primary"),r=p.getBoundingClientRect();return {table:t.getBoundingClientRect().width,cell:c.getBoundingClientRect().width,primary:r.width,scroll:document.documentElement.scrollWidth,viewport:innerWidth,text:p.textContent};})()');
 check(bounds.cell>=bounds.table*.9&&bounds.primary>100,label+' My Documents usable row width',bounds);check(bounds.scroll<=bounds.viewport+1,label+' My Documents fits');
 check(await ev('![...document.querySelectorAll("#documentRows button")].some(n=>/override|approve|deny|delete/i.test(n.textContent+" "+n.title))'),label+' Student forbidden actions absent');
 await menu(label+' documents');await capture('student-documents-'+width+'-'+dark,'#documentRows');
 await ev('document.querySelector("#documentRows .prism-record-details").open=true');check(await ev('document.querySelector("#documentRows .prism-record-details").textContent.includes("Adviser review decision")'),label+' details decision retained');check(await ev('document.documentElement.scrollWidth<=innerWidth+1'),label+' expanded document metadata fits');
 if(width===1366&&!dark){
  await ev('document.getElementById("studentDocumentSearch").value="no-such-fixture-document";document.getElementById("studentDocumentSearch").dispatchEvent(new Event("input"))');await until('document.getElementById("documentRows").textContent.includes("No documents match")');check(true,label+' search empty state');
  await ev('document.getElementById("studentDocumentSearch").value="";document.getElementById("studentDocumentSearch").dispatchEvent(new Event("input"))');await until('!!document.querySelector("#documentRows .prism-record-card")');
  for(const status of ['Needs Revision','Submitted to RPMS','']){await ev('document.getElementById("documentFilter").value='+JSON.stringify(status)+';document.getElementById("documentFilter").dispatchEvent(new Event("change"))');await new Promise(r=>setTimeout(r,500));check(await ev('document.querySelector("#studentDocumentPills [aria-pressed=true]").dataset.filterState')===status,label+' status filter '+status);}
  const version=await ev('(()=>{const b=document.querySelector("#documentRows [data-version-history]");if(!b)return false;b.closest(".prism-record-actions").querySelector(".prism-action-trigger").click();b.click();return true;})()');if(version){await until('!!document.querySelector(".prism-overlay")');check(true,label+' version history opens');await key('Escape');}
 }
 await ev('document.querySelector("#portalNav [data-page=progress]").click()');
 check(await ev('document.querySelectorAll(".stage-card").length===6'),label+' all six official stages');await capture('student-progress-'+width+'-'+dark,'#stageList');
}
async function focused(){
 await command('Emulation.setDeviceMetricsOverride',{width:1366,height:768,deviceScaleFactor:1,mobile:false});
 await auth(1001);await nav('dashboard.php');await ready('dashboard.php');
 await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:800,y:600});await collapse();
 const toggle=await ev('document.getElementById("prismSidebarToggle").getBoundingClientRect().toJSON()');await ev('document.activeElement.blur()');
 await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:toggle.x+10,y:toggle.y+10});await new Promise(r=>setTimeout(r,250));check(await ev('document.getElementById("prismSidebarToggle").getAttribute("aria-expanded")==="true"'),'B11-03 pointer hover expands');
 await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:800,y:600});await new Promise(r=>setTimeout(r,300));check(await ev('document.getElementById("prismSidebarToggle").getAttribute("aria-expanded")==="false"'),'B11-03 pointer leave collapses');
 await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});check(await ev('getComputedStyle(document.querySelector(".ai-summary-banner")).animationName==="none"'),'B11-08 reduced motion');await command('Emulation.setEmulatedMedia',{features:[]});
 const count=requests.length;await ev('document.getElementById("aiBannerGenerate").click()');await until('!!document.querySelector(".prism-overlay")');await key('Escape');check(requests.length===count,'B11-08 report modal cancellation no request');
 injected='reports_api.php?action=ai_report';await ev('document.getElementById("aiBannerGenerate").click()');await ev('document.querySelector(".prism-overlay [data-act=ok]").click()');await until('document.querySelector(".ai-summary-banner").dataset.reportState==="error"');check(await ev('!document.getElementById("aiBannerGenerate").disabled&&document.getElementById("aiBannerDownload").hidden'),'B11-08 error recovery');injected='';
 held='reports_api.php?action=ai_report';await ev('document.getElementById("aiBannerGenerate").click()');await ev('document.querySelector(".prism-overlay [data-act=ok]").click()');await until('document.querySelector(".ai-summary-banner").dataset.reportState==="busy"');await new Promise(r=>setTimeout(r,200));
 check(await ev('document.getElementById("aiBannerGenerate").disabled&&document.querySelector(".ai-summary-banner").getAttribute("aria-busy")==="true"'),'B11-08 busy real pending request');const n=requests.length;await ev('generateAIReport("full",document.getElementById("aiBannerGenerate"))');check(requests.length===n,'B11-08 repeated generation blocked');
 assert(holdRequest);await command('Fetch.continueRequest',{requestId:holdRequest});held='';holdRequest=null;await until('document.querySelector(".ai-summary-banner").dataset.reportState!=="busy"');
 check(await ev('document.querySelector(".ai-summary-banner").dataset.reportState==="ready"&&!document.getElementById("aiBannerDownload").hidden'),'B11-08 real report ready');
 const pdf=await ev('fetch(document.getElementById("aiBannerDownload").href).then(async r=>({status:r.status,type:r.headers.get("content-type"),prefix:(await r.text()).slice(0,5)}))');check(pdf.status===200&&pdf.prefix==='%PDF-'&&pdf.type.includes('pdf'),'B11-08 authorized real PDF download',pdf);await capture('ai-ready','.ai-summary-banner');
 await nav('documents.php');await ready('documents.php');
 check(await ev('!!document.querySelector("[data-formal-submit]")'),'OV existing ready-state formal submission entry reconnected');
 const beforeSubmit=requests.length;await ev('(()=>{const b=document.querySelector("[data-formal-submit]");b.closest(".prism-record-actions").querySelector(".prism-action-trigger").click();b.focus();})()');await key('Enter');await until('!!document.querySelector(".prism-overlay")');check(await ev('document.querySelector(".prism-overlay").textContent.includes("official submission")'),'OV formal submission explicit consequence');await key('Escape');check(requests.length===beforeSubmit,'OV formal submission cancel no mutation');
 for(const page of ['ierbprog.php','admin_ai.php','reports.php']){await nav(page);await ready(page);check(await ev('!!document.querySelector(".prism-row-size select")'),'B11-15 rows-per-page '+page);await ev('document.querySelector(".prism-row-size select").value="5";document.querySelector(".prism-row-size select").dispatchEvent(new Event("change"))');await new Promise(r=>setTimeout(r,400));const endpoint=page==='ierbprog.php'?'ierb_api.php?action=list&perPage=5':'reports_api.php?action=list&perPage=5';const data=await ev('fetch('+JSON.stringify(endpoint)+').then(r=>r.json())');check(data.limit===5&&(data.records||data.reports).length<=5,'B11-15 bounded server data '+page);}
 await nav('admin_legal_policies.php');await collapse();await ev('document.querySelector("input[name=policy][value=privacy]").closest("form").requestSubmit()');await until('!!document.getElementById("policyTitle")');await ev('document.getElementById("policyTitle").value="Unsaved Batch11 edit";document.getElementById("policyTitle").dispatchEvent(new Event("input"));document.querySelector("[data-legal-cancel]").click()');check(await ev('document.getElementById("legalDiscardDialog").open'),'B11-17 dirty cancel protection');await ev('document.getElementById("legalContinueEditing").click()');check(await ev('document.getElementById("policyTitle").value==="Unsaved Batch11 edit"'),'B11-17 keep editing preserves input');await ev('document.querySelector("[data-legal-cancel]").click();document.getElementById("legalDiscardChanges").click()');await until('!document.getElementById("policyTitle")');
}
async function run(){
 child=spawn(browser,['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','--disable-background-networking','--disable-sync','--disable-extensions','about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const url=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timeout')),15000);child.once('error',reject);child.stderr.on('data',b=>{log+=b;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});});
 socket=new WebSocket(url);await once(socket,'open');socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);else if(m.method==='Fetch.requestPaused'){const {requestId,request}=m.params;if(request.method==='POST'&&request.url.startsWith(origin)&&!request.url.includes('fixture-auth.php'))requests.push({url:request.url,body:request.postData});if(held&&request.url.includes(held)){holdRequest=requestId;return;}const asset=assets[request.url];const fulfill=injected&&request.url.includes(injected)?{requestId,responseCode:503,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify({ok:false,message:'Injected fixture service failure'})).toString('base64')}:asset?{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:asset.mime},{name:'Access-Control-Allow-Origin',value:'*'}],body:fs.readFileSync(path.join(cache,asset.file)).toString('base64')}:!request.url.startsWith(origin)?{requestId,responseCode:200,body:''}:null;command(fulfill?'Fetch.fulfillRequest':'Fetch.continueRequest',fulfill||{requestId}).catch(e=>errors.push(e.message));}});
 const target=await command('Target.createTarget',{url:'about:blank'},true);session=(await command('Target.attachToTarget',{targetId:target.targetId,flatten:true},true)).sessionId;
 await command('Runtime.enable');await command('Network.enable');await command('Fetch.enable',{patterns:[{urlPattern:'*'}]});await command('Emulation.setTimezoneOverride',{timezoneId:'Asia/Manila'});await nav('login.php');
 if(phase==='archive'){
  for(const [width,height] of [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,700]])for(const dark of [false,true]){
   await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await ev('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');await auth(1001);await nav('admin_archived_accounts.php');await ready('admin_archived_accounts.php');const label='archive/'+width+'/'+dark;
   const bounds=await ev('(()=>{const t=document.querySelector("table.prism-compact-table"),w=t.parentElement,c=document.querySelector(".prism-record-card");return {table:t.getBoundingClientRect().width,wrapper:w.clientWidth,scroll:t.scrollWidth,card:c.getBoundingClientRect().width};})()');
   check(bounds.table<=bounds.wrapper+2&&bounds.scroll<=bounds.wrapper+2,label+' table fits its scroll container',bounds);check(await ev('document.querySelector(".prism-record-status").textContent.includes("Retention Hold")'),label+' hold remains visible');
   await ev('document.querySelector(".prism-record-details").open=true');check(await ev('document.documentElement.scrollWidth<=innerWidth+1'),label+' expanded details fit');await ev('document.querySelector(".prism-record-details").open=false');await menu(label);await capture('archive-'+width+'-'+dark,'#recordRows');
  }
  check(errors.length===0,'Archive browser has no exceptions');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({results,errors,captures},null,2));console.log('Batch11 archive: '+results.length+' checks; '+results.filter(r=>!r.passed).length+' failures.');process.exitCode=results.some(r=>!r.passed)?1:0;return;
 }
 if(phase==='contrast'){
  const samples=[];const source=fs.readFileSync(path.join(__dirname,'batch11-contrast.js'),'utf8');
  for(const [width,height] of [[1920,1080],[768,1024],[320,700]])for(const dark of [false,true]){
   await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await ev('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');
   for(const [uid,route] of [[0,'index.php'],[1001,'admin_students.php'],[1001,'documents.php'],[1001,'admin_archived_accounts.php'],[1002,'research_adviser.php'],[1003,'student.php']]){
    if(uid)await auth(uid);else await nav('logout.php');await nav(route);await ready(route);if(uid===1003){await ev('document.querySelector("#portalNav [data-page=documents]").click()');await until('!!document.querySelector("#documentRows .prism-record-card")');}
    const contrast=await ev(source),label=uid+'/'+route+'/'+width+'/'+dark;samples.push({label,contrast});check(contrast.filter(c=>!c.excluded).every(c=>c.passed),label+' solid text contrast >=4.5');if(contrast.some(c=>c.passed===false))await capture('contrast-failure-'+uid+'-'+width+'-'+dark);
   }
  }
  check(errors.length===0,'Contrast browser has no exceptions');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({results,errors,samples},null,2));console.log('Batch11 contrast: '+results.length+' checks; '+results.filter(r=>!r.passed).length+' failures.');process.exitCode=results.some(r=>!r.passed)?1:0;return;
 }
 const routes=[[1001,'dashboard.php'],[1001,'admin_students.php'],[1001,'admin_advisers.php'],[1001,'admin_archived_accounts.php'],[1001,'documents.php'],[1001,'ierbprog.php'],[1001,'admin_ai.php'],[1001,'reports.php'],[1001,'admin_notifications.php'],[1001,'admin_legal_policies.php'],[1002,'research_adviser.php'],[1002,'admin_students.php'],[1002,'documents.php'],[1002,'ierbprog.php'],[1002,'calendar.php'],[1003,'student.php']];
 const matrix=phase==='before'?[[1366,768],[768,1024],[390,844],[320,700]]:[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,700]];
 for(const [width,height] of matrix)for(const dark of [false,true]){
  await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await ev('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');
  if(phase!=='before')for(const route of ['index.php','login.php']){await nav('logout.php');await nav(route);const label='public/'+route+'/'+width+'/'+dark;check(await ev('document.documentElement.scrollWidth<=innerWidth+1'),label+' fits');check(await ev('document.documentElement.classList.contains("dark-theme")==='+dark),label+' theme');if(route==='index.php')check(await ev('document.querySelectorAll(".public-stages li").length===6&&!document.querySelector(".prism-record-card")'),label+' six public stages no records');if(route==='login.php')check(await ev('document.querySelector("form").getAttribute("action")==="login_process.php"&&document.querySelector("#loginEmail").required&&document.querySelector("#password").required'),label+' original login fields');await capture(route+'-'+width+'-'+dark);}
  for(const [uid,route] of routes){if(phase==='before'&&!['dashboard.php','documents.php','student.php'].includes(route))continue;console.log('Browser '+uid+' '+route+' '+width+' '+dark);await auth(uid);await nav(route);await ready(route);const label=uid+'/'+route+'/'+width+'/'+dark;
   check(await ev('document.documentElement.scrollWidth<=innerWidth+1'),label+' viewport fits');check(await ev('document.documentElement.classList.contains("dark-theme")==='+dark),label+' theme');
   if(phase!=='before'){
    check(await ev('document.querySelectorAll(".ceu-footer").length===1'),label+' one shared institutional footer');
    check(await ev('[...document.querySelectorAll(".prism-action-trigger,[data-prism-top-tour],[data-prism-persistence],.portal-profile-btn")].every(n=>n.getAttribute("aria-label"))'),label+' interactive accessible names');
    check(await ev('(()=>{const n=[...document.querySelectorAll("main h1,.prism-topbar-group")].find(n=>n.getClientRects().length),s=getComputedStyle(n);return s.color!==s.backgroundColor;})()'),label+' foreground distinct from background');
   }
   if(route!=='student.php'&&await ev('!!document.querySelector(".prism-record-card")')){check(await ev('document.querySelector(".prism-record-primary").getBoundingClientRect().width>100'),label+' identity width');if(phase!=='before')check(await ev('[...document.querySelectorAll(".prism-compact-table")].every(t=>t.scrollWidth<=t.clientWidth+2)'),label+' compact rows have no internal horizontal clipping');await menu(label);}
   if(uid!==1001)check(await ev('![...document.querySelectorAll(".prism-action-item")].some(n=>/admin override|permanent|purge|retention/i.test(n.textContent))'),label+' role override exclusion');
   if(phase!=='before'&&['dashboard.php','research_adviser.php','student.php'].includes(route)){await testProfile(label,uid);check(await ev('document.querySelector("[data-prism-persistence]").getAttribute("aria-disabled")==="true"'),label+' persistence unavailable');check(await ev('!!document.querySelector(".prism-calendar-legend")'),label+' calendar legend');}
   if(phase!=='before'&&route==='dashboard.php'){
    check(await ev('getComputedStyle(document.getElementById("aiBannerDownload")).display==="none"&&!document.getElementById("aiBannerDownload").hasAttribute("href")'),label+' idle report has no download');
    check(await ev('document.querySelector(".dashboard-stage .step-item").getBoundingClientRect().height<=130'),label+' dense stage cards');
    await capture('stage-calendar-'+width+'-'+dark,'.dashboard-stage');await capture('monitor-'+width+'-'+dark,'#ierbMonitorBody');await capture('repository-'+width+'-'+dark,'.dashboard-repository');await capture('resources-'+width+'-'+dark,'#prismResourcesTitle');await ev('scrollTo(0,0)');
   }
   if(phase!=='before'&&uid===1001&&route==='documents.php')await overrideDialog(label,width,dark);
   if(route==='student.php')await studentDocs(label,width,dark);
   else await capture(uid+'-'+route+'-'+width+'-'+dark);
  }
 }
 if(phase!=='before')await focused();
 check(errors.length===0,'No browser exceptions',errors);fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({results,errors,captures,requests,matrix},null,2));console.log('Batch11 '+phase+': '+results.length+' checks; '+results.filter(r=>!r.passed).length+' failures; '+captures.length+' screenshots.');process.exitCode=results.some(r=>!r.passed)?1:0;
}
run().catch(async error=>{console.error(error.stack);await capture('failure').catch(()=>{});fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:error.message,results,errors,requests,captures},null,2));process.exitCode=1;}).finally(async()=>{if(socket?.readyState===WebSocket.OPEN){await command('Browser.close',{},true).catch(()=>{});socket.close();}if(child&&child.exitCode===null){await Promise.race([once(child,'exit'),new Promise(r=>setTimeout(r,2000))]);if(child.exitCode===null)child.kill();}assert.equal(path.dirname(path.resolve(profile)),path.resolve(os.tmpdir()));assert(path.basename(profile).startsWith('prism-b11-browser-'));fs.rmSync(profile,{recursive:true,force:true,maxRetries:10,retryDelay:200});});
