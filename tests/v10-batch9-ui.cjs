/* Browser checks only on the verified disposable HTTP server created by native Batch 9. */
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {spawn}=require('node:child_process'),{once}=require('node:events');
const origin=process.argv[2],fixture=process.argv[3];
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(origin));
assert(path.resolve(fixture).startsWith(path.join(os.tmpdir(),'prism-batch7-native-')));
const browser=['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe','C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(p=>fs.existsSync(p));
assert(browser,'Installed browser required');
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'prism-b9-ui-')),out=path.join(__dirname,'v10-batch9-results');fs.mkdirSync(out,{recursive:true});
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
    await navigate('login.php');await post('fixture-auth.php',{uid:90});
    const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
    for(const [width,height] of matrix)for(const dark of [false,true]){
        await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');
        for(const state of ['none','pending','acknowledged','expired','consumed','invalidated']){
            await post('fixture-state.php',{state});await navigate('staff_registration_manage.php');
            const label=state+'/'+width+'/'+dark;
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' no overflow');
            check(await evaluate('!document.querySelector("#staffPlaintext,[name=proof]")'),label+' no recoverable code or proof');
            check(await evaluate('!!document.querySelector("#staffStatus strong")&&!!document.querySelector("#staffCopyResult[aria-live=polite]")'),label+' labelled status and copy feedback');
            check(await evaluate('document.querySelector("#staffRegistrationLink").href==="https://www.rpmsceu.online/register.php"'),label+' separate code free link');
            await evaluate('document.querySelector("[data-staff-copy]").focus()');check(await evaluate('parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=3'),label+' visible keyboard focus');
        }
        await post('fixture-state.php',{state:'invalidated'});await navigate('staff_registration_manage.php');
        await evaluate('document.querySelector("form [name=action][value=generate]").form.requestSubmit()');await waitFor('!!document.getElementById("staffPlaintext")');
        check(await evaluate('!!document.querySelector("[name=confirmed][required]")&&!!document.querySelector("[name=proof]")'), 'native saved confirmation '+width+'/'+dark);
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'), 'one time display fits '+width+'/'+dark);
        check(await evaluate('(()=>{const code=document.getElementById("staffPlaintext").textContent;return !location.href.includes(code)&&[...document.querySelectorAll("a,form")].every(e=>!(e.getAttribute("href")||e.getAttribute("action")||"").includes(code));})()'),'one time code absent from URLs');
        await evaluate('Object.defineProperty(navigator,"clipboard",{configurable:true,value:{writeText:async value=>{window.__copied=value;}}});document.querySelector("[data-staff-copy=staffPlaintext]").click()');
        await waitFor('document.getElementById("staffCopyResult").textContent.includes("Code copied")');
        check(await evaluate('window.__copied===document.getElementById("staffPlaintext").textContent.trim()'),'copy code exact');
        await evaluate('document.querySelector("[data-staff-copy=staffRegistrationLink]").click()');await waitFor('document.getElementById("staffCopyResult").textContent.includes("link copied")');
        check(await evaluate('window.__copied==="https://www.rpmsceu.online/register.php"'),'copy link excludes code');
        if(width===375&&dark){
            // Screenshots must not become permanent recoverable copies even of isolated Staff Codes.
            await evaluate('document.getElementById("staffPlaintext").style.visibility="hidden"');
            const shot=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,'one-time-redacted-mobile.png'),Buffer.from(shot.data,'base64'));
            await evaluate('document.getElementById("staffPlaintext").style.visibility=""');
        }
        await evaluate('document.querySelector("[name=action][value=acknowledge]").form.querySelector("[name=confirmed]").checked=true;document.querySelector("[name=action][value=acknowledge]").form.requestSubmit()');
        await waitFor('!document.getElementById("staffPlaintext")&&document.getElementById("staffStatus")?.textContent.includes("Active")');
        check(await evaluate('!document.querySelector("[name=proof]")'),'acknowledged code and proof disappear');
        const before=await evaluate('document.documentElement.classList.contains("dark-theme")');await evaluate('document.getElementById("themeToggle").click()');
        check(await evaluate('document.documentElement.classList.contains("dark-theme")')!==before,'theme button works');
        await navigate('logout.php');await navigate('register.php');
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'registration fits '+width+'/'+dark);
        check(await evaluate('[...document.querySelectorAll("input")].every(i=>i.getAttribute("aria-label"))'),'registration inputs labelled');
        check(await evaluate(`!!document.querySelector('a[href="privacy.php"]')&&!!document.querySelector('a[href="terms.php"]')`),'registration legal links');
        await post('fixture-auth.php',{uid:90});
    }
    check(errors.length===0,'no browser exceptions');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({checks,errors,matrix},null,2));console.log('PASS: '+checks+' Batch 9 browser assertions; six viewports and both themes.');
}
run().catch(e=>{console.error(e.stack);process.exitCode=1;}).finally(async()=>{
    if(socket?.readyState===WebSocket.OPEN){await command('Browser.close',{},true).catch(()=>{});socket.close();}
    if(child&&child.exitCode===null){await Promise.race([once(child,'exit'),new Promise(r=>setTimeout(r,2000))]);if(child.exitCode===null)child.kill();}
    const resolved=path.resolve(profile);assert.equal(path.dirname(resolved),path.resolve(os.tmpdir()));assert(path.basename(resolved).startsWith('prism-b9-ui-'));fs.rmSync(resolved,{recursive:true,force:true,maxRetries:10,retryDelay:200});
});
