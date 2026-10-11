/* Browser checks only on the verified disposable HTTP server created by native Batch 9. */
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {spawn}=require('node:child_process'),{once}=require('node:events');
const origin=process.argv[2],fixture=process.argv[3];
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(origin));
assert(path.resolve(fixture).startsWith(path.join(os.tmpdir(),'prism-batch7-native-')));
const browser=['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe','C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(p=>fs.existsSync(p));
assert(browser,'Installed browser required');
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'prism-b10-ui-')),out=path.join(__dirname,'v10-batch10-results');fs.mkdirSync(out,{recursive:true});
let child,socket,sessionId,id=0,checks=0;const pending=new Map(),errors=[];
function check(value,label){checks++;assert(value,label);}
function command(method,params={},global=false){return new Promise((resolve,reject)=>{
    const request=++id,timer=setTimeout(()=>{pending.delete(request);reject(new Error('Browser command timeout '+method));},30000);
    pending.set(request,{resolve:v=>{clearTimeout(timer);resolve(v);},reject:e=>{clearTimeout(timer);reject(e);}});
    socket.send(JSON.stringify({id:request,method,params,...(!global&&sessionId?{sessionId}:{})}));
});}
async function evaluate(expression){const r=await command('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});assert(!r.exceptionDetails,r.exceptionDetails?.text);return r.result.value;}
async function waitFor(expression){for(let n=0;n<200;n++){if(await evaluate(expression))return;await new Promise(r=>setTimeout(r,30));}throw new Error('Page readiness timeout');}
async function navigate(file){const url=origin+'/'+file;await command('Page.navigate',{url});await waitFor('location.href==='+JSON.stringify(file==='logout.php'?origin+'/login.php':url)+'&&document.readyState==="complete"');}
async function post(file,fields){return evaluate('fetch('+JSON.stringify(file)+',{method:"POST",body:new URLSearchParams('+JSON.stringify(fields)+')}).then(r=>r.status)');}
async function run(){
    child=spawn(browser,['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--disable-extensions','--disable-component-update','--password-store=basic','about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
    const url=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(new Error('Browser startup timeout')),15000);child.once('error',reject);child.stderr.on('data',chunk=>{log+=chunk;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});});
    socket=new WebSocket(url);await once(socket,'open');socket.addEventListener('message',e=>{
        const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(new Error(m.error.message)):p.resolve(m.result);}
        else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);
        else if(m.method==='Fetch.requestPaused'){const {requestId,request}=m.params;command(request.url.startsWith(origin+'/')?'Fetch.continueRequest':'Fetch.fulfillRequest',request.url.startsWith(origin+'/')?{requestId}:{requestId,responseCode:200,body:''}).catch(()=>{});}
    });
    const target=await command('Target.createTarget',{url:'about:blank'},true);sessionId=(await command('Target.attachToTarget',{targetId:target.targetId,flatten:true},true)).sessionId;
    await command('Runtime.enable');await command('Network.enable');await command('Network.setCacheDisabled',{cacheDisabled:true});await command('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    await navigate('login.php');await post('fixture-auth.php',{uid:1001});
    const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
    for(const [width,height] of matrix)for(const dark of [false,true]){
        await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');
        for(const [uid,page] of [[1001,'admin_legal_policies.php'],[1001,'staff_registration_manage.php'],[1001,'documents.php'],[1002,'research_adviser.php'],[1002,'documents.php'],[1003,'student.php']]){
            await post('fixture-auth.php',{uid});await navigate(page);
            const label=page+'/'+width+'/'+dark;
            await waitFor('!!document.querySelector("main")');
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' body fits');
            check(await evaluate('document.documentElement.classList.contains("dark-theme")==='+dark),label+' theme');
            if(page==='admin_legal_policies.php'||page==='staff_registration_manage.php'){
                check(await evaluate('!!document.querySelector(".prism-sidebar,.sidebar")&&!!document.querySelector(".topbar")&&!!document.querySelector(".ceu-footer")'),label+' shared shell and footer');
                check(await evaluate('[...document.querySelectorAll("a")].filter(a=>a.getAttribute("href")==="logout.php").length===1&&document.querySelectorAll("#themeToggle").length===1'),label+' one Logout and theme control');
                check(await evaluate('!!document.querySelector("#prismNavAccount a[aria-current=page]")'),label+' Account context');
                if(page==='staff_registration_manage.php')check(await evaluate('(()=>{const card=document.querySelector(".staff-registration"),main=document.querySelector("main"),c=card.getBoundingClientRect(),m=getComputedStyle(main);return Math.abs(c.width-(main.clientWidth-parseFloat(m.paddingLeft)-parseFloat(m.paddingRight)))<3;})()'),label+' uses content width');
                else check(await evaluate('[...document.querySelectorAll("form button[type=submit]")].every(b=>b.classList.contains("prism-btn"))'),label+' standard management buttons');
            }
            if(page==='documents.php'){
                await waitFor('document.getElementById("documentsTableBody").textContent.includes("Fixture")');
                if(uid===1001){
                    await waitFor('!!document.querySelector("[data-return]")');
                    await evaluate('document.querySelector("[data-return]").closest(".prism-action-menu").querySelector(".prism-action-trigger").click()');
                    check(await evaluate('!document.querySelector("[data-return]").closest(".prism-action-panel").hidden'),label+' Return inside Actions');
                    await evaluate('document.querySelector("[data-return]").click()');await waitFor('!!document.querySelector(".prism-overlay")');
                    check(await evaluate('!!document.querySelector("label[for=prismReason]")&&document.querySelector(".prism-dialog").getAttribute("aria-labelledby")==="prismDlgTitle"'),label+' labelled Return dialog and reason');
                    await evaluate('document.querySelector("[data-act=ok]").click()');check(await evaluate('document.querySelector(".prism-field-error").textContent.includes("reason")'),label+' empty reason refused');
                    check(await evaluate('document.activeElement.id==="prismReason"'),label+' focus on required reason');
                    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' dialog fits');
                    await evaluate('document.querySelector("[data-act=cancel]").click()');
                }else{
                    check(await evaluate('!document.querySelector("[data-return]")'),label+' Adviser cannot Return');
                    await waitFor('!!document.querySelector("[data-review]")');
                    check(await evaluate('document.querySelector("[data-review]").closest(".prism-action-panel").hidden'),label+' Review inside Actions');
                }
            }
            if(page==='research_adviser.php'){
                await waitFor('!!document.querySelector("[data-review-id]")');
                await evaluate('document.querySelector("[data-review-id]").closest(".prism-action-menu").querySelector(".prism-action-trigger").click();document.querySelector("[data-review-id]").click()');
                await waitFor('document.getElementById("adviserReviewDialog").open');
                await evaluate('const s=document.getElementById("adviserReviewStatus");s.value="Approved";s.dispatchEvent(new Event("change"))');
                check(await evaluate('document.getElementById("adviserReviewHelp").textContent.includes("formally submit")'),label+' Approve explains submission');
                check(await evaluate('document.getElementById("adviserReviewDialog").getAttribute("aria-labelledby")==="adviserReviewTitle"&&!!document.querySelector("label[for=adviserReviewRemarks]")'),label+' review labels');
                await evaluate('document.getElementById("adviserReviewCancel").click()');
            }
            if(page==='student.php'){
                await waitFor('document.getElementById("documentRows").children.length>0');
                check(await evaluate('!document.querySelector("[data-formal-submit],[data-submit]")&&!document.body.textContent.includes("Submit to RPMS")'),label+' no manual submit');
                await evaluate('document.getElementById("documentFilter").value="Returned for Revision";document.getElementById("documentFilter").dispatchEvent(new Event("change"))');
                await waitFor('!!document.querySelector("#documentRows [data-upload-revision]")');
                await evaluate('document.querySelector("#documentRows [data-upload-revision]").click()');
                check(await evaluate('document.getElementById("documentType").disabled&&!document.getElementById("documentFile").multiple'),label+' specific single new revision');
                check(await evaluate('document.body.textContent.includes("Upload a NEW revision")'),label+' immutable revision instruction');
                check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' revision form fits');
            }
            if(width===375&&dark&&uid!==1002){const shot=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,page+'.png'),Buffer.from(shot.data,'base64'));}
        }
    }
    check(errors.length===0,'no browser exceptions');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({checks,errors,matrix},null,2));console.log('PASS: '+checks+' Batch10 browser assertions; six viewports and both themes.');
}
run().catch(e=>{console.error(e.stack);process.exitCode=1;}).finally(async()=>{
    if(socket?.readyState===WebSocket.OPEN){await command('Browser.close',{},true).catch(()=>{});socket.close();}
    if(child&&child.exitCode===null){await Promise.race([once(child,'exit'),new Promise(r=>setTimeout(r,2000))]);if(child.exitCode===null)child.kill();}
    const resolved=path.resolve(profile);assert.equal(path.dirname(resolved),path.resolve(os.tmpdir()));assert(path.basename(resolved).startsWith('prism-b10-ui-'));fs.rmSync(resolved,{recursive:true,force:true,maxRetries:10,retryDelay:200});
});
