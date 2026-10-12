/* Browser checks only on the verified disposable HTTP server created by native Batch 9. */
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {spawn}=require('node:child_process'),{once}=require('node:events');
const origin=process.argv[2],fixture=process.argv[3];
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(origin));
assert(path.resolve(fixture).startsWith(path.join(os.tmpdir(),'prism-batch7-native-')));
const browser=['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe','C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(p=>fs.existsSync(p));
assert(browser,'Installed browser required');
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'prism-b10-ui-')),out=path.join(__dirname,'v10-batch1075-results',['focus','contrast'].includes(process.argv[5])?process.argv[5]:process.argv[4]||'after');fs.mkdirSync(out,{recursive:true});
let child,socket,sessionId,id=0,checks=0,injectedFailure='';const pending=new Map(),errors=[],failures=[];
function check(value,label){checks++;if(!value)failures.push(label);}
function command(method,params={},global=false){return new Promise((resolve,reject)=>{
    const request=++id,timer=setTimeout(()=>{pending.delete(request);reject(new Error('Browser command timeout '+method));},30000);
    pending.set(request,{resolve:v=>{clearTimeout(timer);resolve(v);},reject:e=>{clearTimeout(timer);reject(e);}});
    socket.send(JSON.stringify({id:request,method,params,...(!global&&sessionId?{sessionId}:{})}));
});}
async function evaluate(expression){const r=await command('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});assert(!r.exceptionDetails,(r.exceptionDetails?.exception?.description||r.exceptionDetails?.text)+' '+expression);return r.result.value;}
async function waitFor(expression){for(let n=0;n<400;n++){if(await evaluate(expression))return;await new Promise(r=>setTimeout(r,30));}throw new Error('Page readiness timeout '+expression+' '+await evaluate('location.href')+' '+JSON.stringify(await evaluate('document.querySelector("main")?.innerText.slice(0,900)')));}
async function navigate(file){const url=origin+'/'+file;await command('Page.navigate',{url});await waitFor('location.href==='+JSON.stringify(file==='logout.php'?origin+'/login.php':url)+'&&document.readyState==="complete"');}
async function post(file,fields){return evaluate('fetch('+JSON.stringify(file)+',{method:"POST",body:new URLSearchParams('+JSON.stringify(fields)+')}).then(r=>r.status)');}
async function shot(name,selector){
    const clip=selector?await evaluate('(()=>{const r=document.querySelector('+JSON.stringify(selector)+').getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};})()'):null;
    const result=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:!!clip,...(clip?{clip}:{})});fs.writeFileSync(path.join(out,name+'.png'),Buffer.from(result.data,'base64'));
}
async function run(){
    child=spawn(browser,['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--disable-extensions','--disable-component-update','--password-store=basic','about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
    const url=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(new Error('Browser startup timeout')),15000);child.once('error',reject);child.stderr.on('data',chunk=>{log+=chunk;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});});
    socket=new WebSocket(url);await once(socket,'open');socket.addEventListener('message',e=>{
        const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(new Error(m.error.message)):p.resolve(m.result);}
        else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);
        else if(m.method==='Fetch.requestPaused'){const {requestId,request}=m.params;if(injectedFailure&&request.url.includes(injectedFailure)){command('Fetch.fulfillRequest',{requestId,responseCode:503,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify({ok:false,message:'Injected fixture service failure'})).toString('base64')}).catch(()=>{});}else command(request.url.startsWith(origin+'/')?'Fetch.continueRequest':'Fetch.fulfillRequest',request.url.startsWith(origin+'/')?{requestId}:{requestId,responseCode:200,body:''}).catch(()=>{});}
    });
    const target=await command('Target.createTarget',{url:'about:blank'},true);sessionId=(await command('Target.attachToTarget',{targetId:target.targetId,flatten:true},true)).sessionId;
    await command('Runtime.enable');await command('Network.enable');await command('Network.setCacheDisabled',{cacheDisabled:true});await command('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    await navigate('login.php');await post('fixture-auth.php',{uid:1001});
    if(process.argv[5]==='contrast'){
        for(const [width,height] of [[1366,768],[390,844]])for(const dark of [false,true]){
            await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');await post('fixture-auth.php',{uid:1003});await navigate('student.php');await evaluate('document.querySelector("[data-page=documents]").click()');await waitFor('!!document.querySelector("#studentDocumentPills [aria-pressed=true]")');
            check(await evaluate(`(()=>{const lum=s=>{const c=s.match(/[\\d.]+/g).slice(0,3).map(Number).map(n=>{n/=255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;});return .2126*c[0]+.7152*c[1]+.0722*c[2];};const style=getComputedStyle(document.querySelector('#studentDocumentPills [aria-pressed=true]')),a=lum(style.color),b=lum(style.backgroundColor);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5;})()`),'Selected pill contrast '+width+'/'+dark);
            check(await evaluate('document.querySelector(".prism-initials").getClientRects().length>0'),'Mobile/desktop initials remain visible');await shot('Student-documents-'+width+'-'+dark,'[data-section="documents"] > article.panel');
            for(const [uid,page] of [[1001,'admin_students.php'],[1001,'documents.php'],[1001,'ierbprog.php'],[1002,'research_adviser.php']]){await post('fixture-auth.php',{uid});await navigate(page);await waitFor('!!document.querySelector("table.prism-compact-table")');check(await evaluate('(()=>{const t=document.querySelector("table.prism-compact-table");return t.getBoundingClientRect().width<=t.parentElement.clientWidth+1;})()'),page+' cards fit container '+width+'/'+dark);check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),page+' cards fit page');await shot('UI01-'+uid+'-'+page+'-'+width+'-'+dark,'table.prism-compact-table');if(uid===1002)await shot('Adviser-cards-'+width+'-'+dark,'.adviser-review-panel');}
            await post('fixture-auth.php',{uid:1001});await navigate('dashboard.php');await waitFor('document.querySelectorAll("#repositoryFilters button").length===4&&document.getElementById("ierbMonitorBody").getAttribute("aria-busy")==="false"&&!document.querySelector("[data-prism-needs-attention]").textContent.includes("Checking who")');await new Promise(r=>setTimeout(r,600));check(await evaluate('!document.getElementById("repositoryCount").textContent.includes(" ? ")'),'Repository separators render correctly');check(await evaluate('[...document.querySelectorAll(".repo-item > [aria-hidden=true]")].every(node=>node.textContent.charCodeAt(0)===8250)'),'Repository navigation chevrons');check(await evaluate('document.querySelector(".dashboard-repository h3").getClientRects().length>0'),'Repository heading visible on mobile/desktop');check(await evaluate('[...document.querySelectorAll(".repo-item")].every(row=>row.title&&row.querySelector("strong").textContent.startsWith(row.title))'),'Full filenames retained in accessible preview/title');await shot('UI07-'+width+'-'+(dark?'dark':'light'),'.dashboard-repository');await shot('UI09-'+width+'-'+(dark?'dark':'light'),'.dashboard-stage');for(const id of ['UI07','UI09'])fs.copyFileSync(path.join(out,id+'-'+width+'-'+(dark?'dark':'light')+'.png'),path.join(__dirname,'v10-batch1075-results','after',id+'-'+width+'-'+(dark?'dark':'light')+'.png'));
        }
        check(errors.length===0,'contrast browser has no exceptions');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({checks,errors,failures},null,2));console.log('Batch10.75 contrast/initials: '+checks+' assertions, '+failures.length+' failures.');if(failures.length){console.error(failures.join('\n'));process.exitCode=1;}return;
    }
    if(process.argv[5]==='focus'){
        for(const [width,height] of [[1366,768],[390,844]])for(const dark of [false,true])for(const [uid,page] of [[1001,'dashboard.php'],[1002,'research_adviser.php'],[1003,'student.php']]){
            await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');await post('fixture-auth.php',{uid});await navigate(page);
            await waitFor('!!document.querySelector("[data-official-deadline=true]")');
            await evaluate('document.querySelector("[data-official-deadline=true]").click()');await waitFor('!!document.querySelector("dialog[open]")&&document.querySelector("dialog[open]").textContent.includes("Batch1075 official deadline")');
            check(await evaluate('!!document.querySelector("[data-selected-day=true]")'),page+'/'+width+'/'+dark+' marked day selected');
            check(await evaluate('document.querySelector("dialog[open]").getBoundingClientRect().width<=innerWidth'),page+' deadline dialog fits');
            check(await evaluate('document.querySelector(".prism-calendar-detail").textContent.includes("Official deadline")'),page+' inline date detail');
            await shot('calendar-'+uid+'-'+width+'-'+dark,'dialog[open]');await evaluate('document.querySelector("dialog[open]").close()');
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),page+' calendar page fits');
            if(uid===1002){await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');await evaluate('document.getElementById("adviserQueueSort").value="oldest";document.getElementById("adviserQueueSort").dispatchEvent(new Event("change"))');await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');const first=await evaluate('fetch("documents_api.php?action=list&sort=oldest").then(r=>r.json()).then(d=>d.documents[0]?.originalName)');check(await evaluate('document.querySelector("#adviserQueue .prism-record-primary").textContent.includes('+JSON.stringify(first)+')'),'Adviser sort matches backend');const unread=await evaluate('fetch("notifications_api.php?action=list&preview=1").then(r=>r.json()).then(d=>d.unread_count)');check(await evaluate('Number(document.querySelector(".prism-topbar-group .nav-badge").textContent)')===unread,'Adviser topbar count follows inbox');await shot('UI04-calendar-'+width+'-'+dark,'.adviser-side-panels');}
        }
        for(const [width,height] of [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,700]])for(const dark of [false,true]){
            await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');await post('fixture-auth.php',{uid:1003});await navigate('student.php');await evaluate('document.querySelector("[data-page=documents]").click()');await waitFor('!!document.querySelector("#documentRows .prism-record-details")');
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Student tools fit '+width+'/'+dark);
            check(await evaluate('document.querySelector(".prism-initials").getClientRects().length>0&&getComputedStyle(document.querySelector(".prism-initials")).display!=="none"'),'Student initials visible '+width+'/'+dark);
            await evaluate('document.getElementById("studentDocumentSort").value="oldest";document.getElementById("studentDocumentSort").dispatchEvent(new Event("change"))');const first=await evaluate('fetch("documents_api.php?action=list&sort=oldest").then(r=>r.json()).then(d=>d.documents[0].originalName)');await waitFor('document.querySelector("#documentRows .prism-record-primary")?.textContent.includes('+JSON.stringify(first)+')');check(true,'Student sort scoped backend match');
            await evaluate('document.getElementById("studentDocumentSearch").value="__no_matching_fixture__";document.getElementById("studentDocumentSearch").dispatchEvent(new Event("input"))');await waitFor('document.getElementById("documentRows").textContent.includes("No documents match")');check(!await evaluate('!!document.querySelector("#documentRows .prism-record-details")'),'Student no-match state');
            await evaluate('document.getElementById("studentDocumentSearch").value="";document.getElementById("studentDocumentSearch").dispatchEvent(new Event("input"))');await waitFor('!!document.querySelector("#documentRows .prism-record-details")');
            await evaluate(`document.querySelector('#studentDocumentPills [data-filter-state="Pending Adviser Review"]').click()`);await waitFor('document.getElementById("documentFilter").value==="Pending Adviser Review"&&[...document.querySelectorAll("#documentRows .prism-record-status")].every(node=>node.textContent.includes("Pending Adviser Review"))');check(await evaluate('document.querySelector("#studentDocumentPills [aria-pressed=true]").dataset.filterState==="Pending Adviser Review"'),'Student pills synchronize');
            if(width===1366||width===390)await shot('UI01-Student-'+width+'-'+dark);
        }
        for(const [uid,page,state] of [[1001,'documents.php','document.getElementById("documentsTableBody").textContent.includes("Could not load records")'],[1002,'research_adviser.php','document.getElementById("adviserQueue").textContent.includes("Could not load submissions")'],[1003,'student.php','document.getElementById("documentRows").textContent.includes("Unable to load your documents")']]){
            await post('fixture-auth.php',{uid});injectedFailure='documents_api.php?action=list';await navigate(page);await waitFor(state);check(true,page+' recoverable service error');check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),page+' error state fits');injectedFailure='';await navigate(page);await waitFor('!!document.querySelector(".prism-record-details")');check(true,page+' reload recovers');
        }
        for(const [width,height] of [[1366,768],[390,844]])for(const dark of [false,true]){
            await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await navigate('logout.php');await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');await navigate('login.php');
            check(await evaluate(`(()=>{const channels=s=>s.match(/[\\d.]+/g).slice(0,3).map(Number).map(n=>{n/=255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;});const lum=s=>{const c=channels(s);return .2126*c[0]+.7152*c[1]+.0722*c[2];};const a=lum(getComputedStyle(document.querySelector('.prism-branding h1')).color),b=lum(getComputedStyle(document.querySelector('.login-card')).backgroundColor);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5;})()`),'Login heading contrast '+width+'/'+dark);await shot('UI02-'+width+'-'+(dark?'dark':'light'));
            fs.copyFileSync(path.join(out,'UI02-'+width+'-'+(dark?'dark':'light')+'.png'),path.join(__dirname,'v10-batch1075-results','after','UI02-'+width+'-'+(dark?'dark':'light')+'.png'));
            await post('fixture-auth.php',{uid:1001});for(const page of ['dashboard.php','admin_students.php','admin_advisers.php','documents.php','ierbprog.php','admin_legal_policies.php']){await navigate(page);await new Promise(r=>setTimeout(r,900));await evaluate('(()=>{const b=document.getElementById("prismSidebarToggle");if(b?.getAttribute("aria-expanded")==="true")b.click();document.activeElement?.blur();})()');await new Promise(r=>setTimeout(r,250));await shot('1001-'+page+'-'+width+'-'+(dark?'dark':'light'));fs.copyFileSync(path.join(out,'1001-'+page+'-'+width+'-'+(dark?'dark':'light')+'.png'),path.join(__dirname,'v10-batch1075-results','after','1001-'+page+'-'+width+'-'+(dark?'dark':'light')+'.png'));}
            if(width===390&&dark){await evaluate('document.querySelector("input[name=policy][value=privacy]").closest("form").requestSubmit()');await waitFor('!!document.getElementById("policyTitle")&&document.readyState==="complete"');await evaluate('document.getElementById("policyTitle").value="Unsaved browser edit";document.getElementById("policyTitle").dispatchEvent(new Event("input"));document.querySelector("[data-legal-cancel]").click()');check(await evaluate('document.getElementById("legalDiscardDialog").open'),'final mobile Cancel confirmation');await shot('UI06-cancel-390-true','#legalDiscardDialog');fs.copyFileSync(path.join(out,'UI06-cancel-390-true.png'),path.join(__dirname,'v10-batch1075-results','after','UI06-cancel-390-true.png'));await evaluate('document.getElementById("legalDiscardChanges").click()');await waitFor('location.search==="?policy=privacy"&&!document.getElementById("versionDetails")');check(true,'final mobile Cancel returns without write');}
        }
        check(errors.length===0,'focused browser has no exceptions');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({checks,errors,failures},null,2));console.log('Batch10.75 focused calendar/sort/search/empty/error: '+checks+' assertions, '+failures.length+' failures.');if(failures.length){console.error(failures.join('\n'));process.exitCode=1;}return;
    }
    const before=process.argv[4]==='before';
    const matrix=before?[[1366,768]]:[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,700]];
    for(const [width,height] of matrix)for(const dark of [false,true]){
        await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');
        for(const [uid,page] of [[1001,'dashboard.php'],[1001,'admin_students.php'],[1001,'admin_advisers.php'],[1001,'documents.php'],[1001,'ierbprog.php'],[1001,'admin_legal_policies.php'],[1002,'research_adviser.php'],[1002,'admin_students.php'],[1002,'documents.php'],[1002,'ierbprog.php'],[1003,'student.php'],[1003,'help_support.php']]){
            await post('fixture-auth.php',{uid});await navigate(page);await new Promise(r=>setTimeout(r,700));
            if(!before)await evaluate('(()=>{const toggle=document.getElementById("prismSidebarToggle");if(toggle?.getAttribute("aria-expanded")==="true")toggle.click();})()');
            const label=uid+'/'+page+'/'+width+'/'+dark;
            if(!before){
                check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' page fits');
                check(await evaluate('document.documentElement.classList.contains("dark-theme")==='+dark),label+' theme');
                if(page!=='help_support.php'){
                    check(await evaluate('!!document.querySelector(".prism-topbar-group .prism-initials")&&!document.querySelector(".portal-profile-btn img")'),label+' dynamic initials');
                    check(await evaluate('(()=>{const c=[...document.querySelector(".prism-topbar-group").children];return c[0].id==="themeToggle"&&!!c[1].querySelector("#notificationToggle, .nav-badge")||false;})()')||await evaluate('document.querySelector(".prism-topbar-group").children[1].classList.contains("portal-notification")'),label+' theme then notification');
                    check(await evaluate('(()=>{const c=[...document.querySelector(".prism-topbar-group").children];return c[2].hasAttribute("data-prism-top-tour")&&c[3].getAttribute("aria-disabled")==="true"&&!!c[4].querySelector(".portal-profile-btn");})()'),label+' tour unavailable persistence profile order');
                    check(await evaluate('[...document.querySelectorAll(".prism-topbar-group button")].every(b=>b.getAttribute("aria-label")||b.textContent.trim())'),label+' control names');
                }
                if(['admin_students.php','admin_advisers.php','documents.php','ierbprog.php'].includes(page)){
                    await waitFor('!!document.querySelector(".prism-record-details")');
                    check(await evaluate('!!document.querySelector(".prism-compact-table")'),label+' compact record view');
                    check(await evaluate('!!document.querySelector(".prism-record-actions .prism-action-trigger")'),label+' Actions preserved');
                    await evaluate('document.querySelector(".prism-record-details").open=true');
                    check(await evaluate('document.querySelector(".prism-record-details dl").textContent.length>10'),label+' details retain values');
                    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' expanded details fit');
                    if(page==='admin_students.php')check(await evaluate('document.querySelector(".prism-record-details").textContent.includes("Academic Year")&&document.querySelector(".prism-record-details").textContent.includes("Principal Investigator")'),label+' academic PI fields retained');
                    await evaluate('document.querySelector(".prism-action-trigger").click()');
                    check(await evaluate('!!document.querySelector(".prism-action-panel:not([hidden])")'),label+' Actions opens');
                    await evaluate('document.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}))');
                    check(await evaluate('!document.querySelector(".prism-action-panel:not([hidden])")'),label+' Escape closes');
                    await evaluate('document.querySelector(".prism-record-details").open=false');
                }
                if(page==='research_adviser.php'){
                    await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');
                    check(await evaluate(`!document.getElementById("adviserProfile")&&!!document.querySelector('#prismAccountLinks a[href="account.php"]')`),label+' account shell removed access retained');
                    const expected=await evaluate('fetch("documents_api.php?action=list").then(r=>r.json()).then(d=>d.counts)');
                    check(await evaluate('Number(document.getElementById("adviserPendingCount").textContent)')===Number(expected['Pending Adviser Review']||0),label+' pending backend count');
                    check(await evaluate('Number(document.getElementById("adviserApprovedCount").textContent)')===Number(expected['Ready for Formal RPMS Submission']||0)+Number(expected['Submitted to RPMS']||0),label+' approved distinct count');
                    for(const value of ['Pending Adviser Review','Needs Revision','approved','']){
                        await evaluate('(()=>{const s=document.getElementById("adviserQueueFilter");s.value='+JSON.stringify(value)+';s.dispatchEvent(new Event("change"));})()');
                        await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');
                        check(await evaluate('document.querySelector("#adviserQueuePills [aria-pressed=true]").dataset.filterState')===value,label+' filter pill sync '+value);
                        if(value)check(await evaluate('[...document.querySelectorAll("[data-queue-state]")].filter(c=>c.getAttribute("aria-pressed")==="true").length===1'),label+' summary selection '+value);
                    }
                    const widths=await evaluate('(()=>{const a=document.querySelector(".adviser-notifications-panel").getBoundingClientRect(),b=document.querySelector(".prism-deadline-calendar").getBoundingClientRect();return [a.width,b.width,a.y,b.y];})()');
                    check(Math.abs(widths[0]-widths[1])<2,label+' balanced panels');
                    check(width>700?Math.abs(widths[2]-widths[3])<2:widths[3]>widths[2],label+' panels responsive placement');
                    await evaluate('(()=>{const review=document.querySelector("[data-review-id]");if(review){review.closest(".prism-action-menu").querySelector(".prism-action-trigger").click();review.click();}})()');
                    check(await evaluate('document.getElementById("adviserReviewDialog").open'),label+' authorized review dialog');
                    check(await evaluate('document.getElementById("adviserReviewDialog").getBoundingClientRect().width<=innerWidth'),label+' review dialog fits');
                    await evaluate('document.getElementById("adviserReviewCancel").click()');
                }
                if(page==='dashboard.php'){
                    await waitFor('document.querySelectorAll("#repositoryFilters button").length===4&&!!document.querySelector("#initialStageCount b")');
                    check(await evaluate('parseFloat(getComputedStyle(document.querySelector("#initialStageCount b")).fontSize)>=40'),label+' large stage counts');
                    check(await evaluate('document.querySelectorAll(".repo-item").length<=5&&document.getElementById("repositoryCount").textContent.includes("Preview only")'),label+' upload preview limit explicit');
                    await evaluate(`document.querySelector('[data-repository-state="Needs Revision"]').click()`);
                    check(await evaluate('[...document.querySelectorAll(".repo-item")].every(a=>a.textContent.includes("Needs Revision"))'),label+' repository filter');
                    await evaluate(`document.querySelector('[data-repository-state=""]').click()`);
                    await evaluate('document.querySelector("[data-monitor-stage=revision]").click()');
                    await waitFor('document.getElementById("ierbMonitorBody").getAttribute("aria-busy")==="false"');
                    check(await evaluate('document.querySelector("[data-monitor-stage=revision]").getAttribute("aria-pressed")==="true"'),label+' stage selection');
                    await evaluate('document.querySelector("[data-monitor-stage=revision]").click()');
                    await waitFor('document.getElementById("ierbMonitorBody").getAttribute("aria-busy")==="false"');
                }
                if(page==='admin_legal_policies.php'){
                    await evaluate('document.querySelector("[data-policy-tab=terms]").click()');
                    check(await evaluate('[...document.querySelectorAll("[data-policy-panel]")].filter(p=>!p.hidden).every(p=>p.dataset.policyPanel==="terms")'),label+' Terms own history');
                    await evaluate('document.querySelector("[data-policy-tab=terms]").dispatchEvent(new KeyboardEvent("keydown",{key:"ArrowLeft",bubbles:true}))');
                    check(await evaluate('document.querySelector("[data-policy-tab=privacy]").getAttribute("aria-selected")==="true"'),label+' keyboard tabs');
                }
                if(page==='help_support.php')check(await evaluate(`!document.querySelector('.legal-header a[href="logout.php"]')&&!!document.querySelector(".legal-back[aria-label]")&&!!document.getElementById("legalTheme")`),label+' support header');
            }
            if(width===1366||width===390){
                if(page==='admin_students.php'&&uid===1001)fs.writeFileSync(path.join(out,'navigation-'+width+'-'+dark+'.json'),JSON.stringify(await evaluate('(()=>{const s=document.querySelector(".prism-sidebar"),b=document.querySelector(".prism-sidebar-backdrop"),c=document.querySelector(".prism-navigation-caption");return {sidebar:s.className,expanded:document.getElementById("prismSidebarToggle").getAttribute("aria-expanded"),caption:getComputedStyle(c).display,backdropHidden:b.hidden,backdrop:getComputedStyle(b).display};})()'),null,2));
                await new Promise(r=>setTimeout(r,250));await shot(uid+'-'+page+'-'+width+'-'+(dark?'dark':'light'));
            }
            if(page==='dashboard.php'&&!before&&(width===1366||width===390)){await shot('UI07-'+width+'-'+(dark?'dark':'light'),'.dashboard-repository');await shot('UI09-'+width+'-'+(dark?'dark':'light'),'.dashboard-stage');}
            if(page==='student.php'){
                await evaluate('document.querySelector("[data-page=progress]").click()');
                if(!before){check(await evaluate('document.querySelectorAll("#stageList .stage-card").length===6&&document.querySelectorAll("#stageList [aria-current=step]").length===1'),label+' real six-stage journey');check(await evaluate('!!document.getElementById("principalIndicator")&&!!document.getElementById("progressRing")&&!!document.getElementById("progressHistory")'),label+' official PI ring history retained');check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' progress fits');}
                if(width===1366||width===390)await shot('UI08-'+width+'-'+(dark?'dark':'light'));
                if(!before){await evaluate('document.querySelector("[data-page=documents]").click()');await waitFor('!!document.querySelector("#documentRows .prism-record-details")');check(await evaluate('!document.querySelector("[data-formal-submit],[data-submit]")&&!!document.querySelector("[data-version-history]")'),label+' Student document authority/history');check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' Student documents fit');}
            }
        }
    }
    await navigate('logout.php');await navigate('login.php');await shot('UI02-login');
    if(!before){
        for(const [width,height] of matrix)for(const dark of [false,true]){
            await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');await navigate('login.php');
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Login fits '+width+'/'+dark);
            check(await evaluate('document.documentElement.classList.contains("dark-theme")==='+dark),'Login inherited theme '+width+'/'+dark);
            check(await evaluate('getComputedStyle(document.body,"::before").backgroundImage.includes("ceu_bg.png")&&!!document.querySelector(".login-card .logo-main")'),'Login campus/card retained');
            check(await evaluate('!!document.querySelector("label[for=loginEmail]")&&!!document.querySelector("label[for=password]")'),'Login field labels');
            await evaluate('document.querySelector(".toggle-password").click()');check(await evaluate('document.getElementById("password").type==="text"'),'Login password visibility');await evaluate('document.querySelector(".toggle-password").click()');
            if(width===1366||width===390)await shot('UI02-'+width+'-'+(dark?'dark':'light'));
        }
        await post('fixture-auth.php',{uid:1001});await navigate('admin_legal_policies.php');
        await evaluate('document.querySelector("input[name=policy][value=privacy]").closest("form").requestSubmit()');
        await waitFor('!!document.querySelector("[data-legal-draft]")&&document.readyState==="complete"');
        const versionUrl=await evaluate('location.href'),saved=await evaluate('document.getElementById("policyTitle").value');
        for(const [width,height] of [[1366,768],[390,844]])for(const dark of [false,true]){
            await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');
            await command('Page.navigate',{url:versionUrl});await waitFor('!!document.querySelector("[data-legal-draft]")&&document.readyState==="complete"');
            await evaluate('document.getElementById("policyTitle").value="Unsaved browser edit";document.getElementById("policyTitle").dispatchEvent(new Event("input"));document.querySelector("[data-legal-cancel]").click()');
            check(await evaluate('document.getElementById("legalDiscardDialog").open'),'draft unsaved cancel confirmation '+width+'/'+dark);
            await shot('UI06-cancel-'+width+'-'+dark);
            await evaluate('document.getElementById("legalContinueEditing").click()');check(await evaluate('document.getElementById("policyTitle").value==="Unsaved browser edit"'),'continue keeps edits');
            await evaluate('document.querySelector("[data-legal-cancel]").click();document.getElementById("legalDiscardChanges").click()');
            await waitFor('!document.getElementById("versionDetails")&&location.search==="?policy=privacy"&&document.readyState==="complete"');
            check(await evaluate('document.querySelector("[data-policy-tab=privacy]").getAttribute("aria-selected")==="true"'),'cancel returns policy');
            await command('Page.navigate',{url:versionUrl});await waitFor('!!document.getElementById("policyTitle")&&document.readyState==="complete"');check(await evaluate('document.getElementById("policyTitle").value')===saved,'cancel never saved edited title');
        }
        await post('fixture-auth.php',{uid:1002});await navigate('research_adviser.php');await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"');
        await evaluate('document.querySelector("[data-prism-top-tour]").click()');check(await evaluate('!!document.querySelector("dialog[open]")'),'shared Quick Tour opens existing walkthrough');await evaluate('document.querySelector("dialog[open]").close()');
        await evaluate('document.querySelector("[data-prism-persistence]").click()');check(await evaluate('document.querySelector("dialog[open]").textContent.includes("unavailable")'),'persistence never claims activation');await evaluate('document.querySelector("dialog[open]").close()');
        await evaluate('document.getElementById("adviserMarkAllRead").click()');await waitFor('document.getElementById("adviserQueue").getAttribute("aria-busy")==="false"&&document.getElementById("adviserUnreadCount").textContent.startsWith("0 unread")');check(true,'personal mark all read via protected endpoint');
        await navigate('fixture-idle.php');const idle=await evaluate('fetch("documents_api.php?action=list").then(async r=>({status:r.status,body:await r.json()}))');fs.writeFileSync(path.join(out,'idle-evidence.json'),JSON.stringify(idle,null,2));check(idle.status===401&&idle.body.code==='session_expired','normal inactivity expiry retained');
        await post('fixture-auth.php',{uid:1003});check(await evaluate('fetch("students_api.php?action=list").then(r=>r.status===403)'),'Student staff-record access denied');check(await evaluate('fetch("documents_api.php?action=list&stateGroup=approved").then(r=>r.json()).then(d=>d.documents.every(row=>row.student==="Batch10 1003"))'),'grouped read filter retains Student ownership');
    }
    check(errors.length===0,'no browser exceptions');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({checks,errors,failures,matrix},null,2));console.log('Batch10.75 '+process.argv[4]+': '+checks+' browser assertions, '+failures.length+' failures, '+errors.length+' exceptions.');if(failures.length){console.error(failures.join('\n'));process.exitCode=1;}

}
run().catch(async e=>{console.error(e.stack);console.error('Failures so far: '+JSON.stringify(failures));console.error('Exceptions: '+JSON.stringify(errors));await shot('failure').catch(()=>{});process.exitCode=1;}).finally(async()=>{
    if(socket?.readyState===WebSocket.OPEN){await command('Browser.close',{},true).catch(()=>{});socket.close();}
    if(child&&child.exitCode===null){await Promise.race([once(child,'exit'),new Promise(r=>setTimeout(r,2000))]);if(child.exitCode===null)child.kill();}
    const resolved=path.resolve(profile);assert.equal(path.dirname(resolved),path.resolve(os.tmpdir()));assert(path.basename(resolved).startsWith('prism-b10-ui-'));fs.rmSync(resolved,{recursive:true,force:true,maxRetries:10,retryDelay:200});
});
