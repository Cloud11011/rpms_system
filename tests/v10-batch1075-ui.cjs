/* Browser checks only on the verified disposable HTTP server created by native Batch 9. */
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {spawn,spawnSync}=require('node:child_process'),{once}=require('node:events');
const origin=process.argv[2],fixture=process.argv[3];
assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(origin));
assert(path.resolve(fixture).startsWith(path.join(os.tmpdir(),'prism-batch7-native-')));
const browser=['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe','C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(p=>fs.existsSync(p));
assert(browser,'Installed browser required');
const acceptance=['acceptance','interaction'].includes(process.argv[5]);
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'prism-b10-ui-')),out=path.join(__dirname,'v10-batch1075-results',acceptance?'remediation/'+process.argv[5]:['focus','contrast'].includes(process.argv[5])?process.argv[5]:process.argv[4]||'after');fs.mkdirSync(out,{recursive:true});
// Follow-up modes also publish companion captures into the matrix directory on a clean checkout.
if(['focus','contrast'].includes(process.argv[5]))fs.mkdirSync(path.join(__dirname,'v10-batch1075-results','after'),{recursive:true});
let child,socket,sessionId,id=0,checks=0,injectedFailure='';const pending=new Map(),errors=[],failures=[];
const assertions=[],captures=[],interactions=[];
const cdnCache=path.join(__dirname,'v10-batch1075-results/remediation/cdn-cache');
const cdnAssets=acceptance&&fs.existsSync(path.join(cdnCache,'assets.json'))?JSON.parse(fs.readFileSync(path.join(cdnCache,'assets.json'),'utf8')):{};
function check(value,label){checks++;assertions.push({label,passed:!!value});if(!value)failures.push(label);}
function command(method,params={},global=false){return new Promise((resolve,reject)=>{
    const request=++id,timer=setTimeout(()=>{pending.delete(request);reject(new Error('Browser command timeout '+method));},30000);
    pending.set(request,{resolve:v=>{clearTimeout(timer);resolve(v);},reject:e=>{clearTimeout(timer);reject(e);}});
    socket.send(JSON.stringify({id:request,method,params,...(!global&&sessionId?{sessionId}:{})}));
});}
async function evaluate(expression){const r=await command('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});assert(!r.exceptionDetails,(r.exceptionDetails?.exception?.description||r.exceptionDetails?.text)+' '+expression);return r.result.value;}
async function waitFor(expression){for(let n=0;n<400;n++){if(await evaluate(expression))return;await new Promise(r=>setTimeout(r,30));}throw new Error('Page readiness timeout '+expression+' '+await evaluate('location.href')+' '+JSON.stringify(await evaluate('document.querySelector("main")?.innerText.slice(0,900)')));}
async function navigate(file){const url=origin+'/'+file;await command('Page.navigate',{url});await waitFor('location.href==='+JSON.stringify(file==='logout.php'?origin+'/login.php':url)+'&&document.readyState==="complete"');}
async function post(file,fields){
    // Stop the previous role's asynchronous page loads before rotating the private fixture session.
    if(acceptance&&file==='fixture-auth.php')await navigate('fixture-blank.php');
    return evaluate('fetch('+JSON.stringify(file)+',{method:"POST",body:new URLSearchParams('+JSON.stringify(fields)+')}).then(async r=>{await r.text();return r.status;})');
}
async function shot(name,selector,viewport=false){
    if(acceptance){
        await evaluate('(()=>{const b=document.getElementById("prismSidebarToggle");if(b?.getAttribute("aria-expanded")==="true")b.click();})()');
        await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});
        await evaluate('document.fonts.ready.then(()=>true)');
        await new Promise(r=>setTimeout(r,120));
        // Capture only the real viewport. Chrome's beyond-viewport capture can reflow earlier
        // panels and invalidate a component's measured coordinates; stitch unchanged pixels instead.
        viewport=viewport||await evaluate('!!document.querySelector("dialog[open],.prism-overlay")');
        let bounds;
        if(viewport){
            const result=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            fs.writeFileSync(path.join(out,name+'.png'),Buffer.from(result.data,'base64'));
        }else{
            const initialScroll=await evaluate('scrollY');
            bounds=await evaluate('(()=>{const r='+ (selector?'document.querySelector('+JSON.stringify(selector)+').getBoundingClientRect()':'null')+';const left=r?Math.max(0,Math.floor(r.left)-8):document.body.classList.contains("admin-shell")?Math.floor(document.querySelector("main").getBoundingClientRect().left):0;return {left,width:r?Math.min(innerWidth-left,Math.ceil(r.width)+16):innerWidth-left,viewportWidth:innerWidth,start:r?Math.max(0,Math.floor(r.top+scrollY)-8):0,end:r?Math.ceil(r.bottom+scrollY)+8:document.documentElement.scrollHeight};})()');
            const segments=[];
            for(let cursor=bounds.start;cursor<bounds.end;){
                const header=await evaluate('(()=>{const n=document.querySelector(".portal-navbar");return n&&["sticky","fixed"].includes(getComputedStyle(n).position)?Math.max(0,Math.ceil(n.getBoundingClientRect().bottom)+8):0;})()');
                await evaluate('window.scrollTo(0,'+Math.max(0,cursor-header)+')');await new Promise(r=>setTimeout(r,80));
                const offset=await evaluate('scrollY'),height=await evaluate('innerHeight'),top=cursor-offset;
                const take=Math.min(bounds.end-cursor,height-top);
                assert(take>0,'Screenshot strip must make progress');
                const result=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
                segments.push({png:result.data,top,height:take,y:cursor-bounds.start});cursor+=take;
            }
            const script=['import sys,json,base64,io','from PIL import Image','d=json.load(sys.stdin)',
                'out=Image.new("RGB",(d["width"],d["height"]),"white")',
                'for s in d["segments"]:',
                ' im=Image.open(io.BytesIO(base64.b64decode(s["png"])))',
                ' out.paste(im.crop((d["left"],s["top"],d["left"]+d["width"],s["top"]+s["height"])),(0,s["y"]))',
                'out.save(sys.stdout.buffer,format="PNG")'].join('\n');
            const rendered=spawnSync(process.env.PRISM_PYTHON_BINARY||'python',['-c',script],{input:JSON.stringify({left:bounds.left,width:bounds.width,height:bounds.end-bounds.start,segments}),windowsHide:true,maxBuffer:128*1024*1024});
            assert.equal(rendered.status,0,rendered.stderr?.toString());fs.writeFileSync(path.join(out,name+'.png'),rendered.stdout);
            bounds.strips=segments.length;await evaluate('window.scrollTo(0,'+initialScroll+')');
        }
        captures.push({file:name+'.png',selector:selector||'full page',viewport,bounds,method:viewport?'browser viewport':'stitched browser viewport pixels',sidebarCollapsed:await evaluate('document.getElementById("prismSidebarToggle")?.getAttribute("aria-expanded")!=="true"')});
        return;
    }
    const clip=selector?await evaluate('(()=>{const r=document.querySelector('+JSON.stringify(selector)+').getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};})()'):null;
    const result=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:!!clip,...(clip?{clip}:{})});fs.writeFileSync(path.join(out,name+'.png'),Buffer.from(result.data,'base64'));
}
// CDP input exercises the browser's native keyboard/default actions, rather than synthetic DOM events.
async function key(name,shift=false){
    const codes={Enter:13,Escape:27,Tab:9,ArrowDown:40,ArrowUp:38,Home:36,End:35};
    const params={key:name,code:name,windowsVirtualKeyCode:codes[name],nativeVirtualKeyCode:codes[name],modifiers:shift?8:0};
    await command('Input.dispatchKeyEvent',{type:'keyDown',...params,...(name==='Enter'?{text:'\r',unmodifiedText:'\r'}:{})});
    await command('Input.dispatchKeyEvent',{type:'keyUp',...params});
}
async function focus(selector){await evaluate('(()=>{const n=document.querySelector('+JSON.stringify(selector)+');n.scrollIntoView({block:"center"});n.focus();})()');}
const visibleDialogs='[...document.querySelectorAll("dialog,[role=dialog],.document-modal,.management-modal,.ierb-modal")].filter(n=>n.getClientRects().length&&getComputedStyle(n).display!=="none"&&(!n.matches("dialog")||n.open))';
async function menuKeyboard(trigger,label,filename){
    await evaluate('(()=>{const b=document.getElementById("prismSidebarToggle");if(b?.getAttribute("aria-expanded")==="true")b.click();})()');
    await focus(trigger);await key('ArrowDown');
    check(await evaluate('document.activeElement.matches(".prism-action-item")'),label+' ArrowDown enters Actions');
    await key('End');check(await evaluate('(()=>{const p=document.activeElement.closest(".prism-action-panel"),a=[...p.querySelectorAll("button:not(:disabled),a[href]")];return document.activeElement===a.at(-1);})()'),label+' End reaches last action');
    await key('Home');check(await evaluate('(()=>{const p=document.activeElement.closest(".prism-action-panel"),a=[...p.querySelectorAll("button:not(:disabled),a[href]")];return document.activeElement===a[0];})()'),label+' Home reaches first action');
    await key('Tab');await key('Tab',true);check(await evaluate('document.activeElement.matches(".prism-action-item")'),label+' Tab and Shift+Tab reach actions');
    check(await evaluate('(()=>{const p=document.querySelector(".prism-action-panel:not([hidden])"),r=p.getBoundingClientRect();return r.left>=0&&r.top>=0&&r.right<=innerWidth+1&&r.bottom<=innerHeight+1&&p.contains(document.elementFromPoint(r.left+5,r.top+5));})()'),label+' Actions positioned within viewport and unobscured');
    if(filename)await shot(filename,null,true);
    await key('Escape');
    check(await evaluate('!document.querySelector(".prism-action-panel:not([hidden])")&&document.activeElement===document.querySelector('+JSON.stringify(trigger)+')'),label+' Escape closes and restores trigger focus');
}
async function actionControls(uid,page,label,filename){
    const inventory=await evaluate(`(()=>{const found=new Map();let index=0;for(const p of document.querySelectorAll('.prism-action-panel'))for(const n of p.querySelectorAll('button,a')){n.dataset.acceptanceAction=String(index++);const label=n.textContent.trim()||n.title;if(!found.has(label))found.set(label,{label,index:n.dataset.acceptanceAction,tag:n.tagName,disabled:n.disabled||n.getAttribute('aria-disabled')==='true'});}return [...found.values()];})()`);
    check(inventory.length>0,label+' authorized action inventory exists');
    if(uid===1002&&page==='admin_students.php')check(inventory.every(item=>!/archive|delete|override/i.test(item.label)),label+' Adviser account actions respect role');
    if(uid===1002&&page==='documents.php')check(inventory.every(item=>!/admin override|return to student|ai summary/i.test(item.label)),label+' Adviser document actions exclude Admin operations');
    if(uid===1003)check(inventory.every(item=>!/approve|deny|delete|override|review document|submit to rpms/i.test(item.label)),label+' Student document actions respect role');
    for(const item of inventory){
        console.log('Action '+label+' '+item.label);
        if(item.disabled){interactions.push({uid,page,action:item.label,result:'disabled safeguard retained'});continue;}
        const selector='[data-acceptance-action="'+item.index+'"]';
        await evaluate('(()=>{const n=document.querySelector('+JSON.stringify(selector)+');n.closest(".prism-action-menu").querySelector(".prism-action-trigger").scrollIntoView({block:"center"});n.closest(".prism-action-menu").querySelector(".prism-action-trigger").click();n.focus();window.__acceptanceActivation=null;n.addEventListener("click",e=>{window.__acceptanceActivation={tag:n.tagName,href:n.href||null};if(n.tagName==="A")e.preventDefault();},{capture:true,once:true});})()');
        await key('Enter');await waitFor('!!window.__acceptanceActivation');
        const activation=await evaluate('window.__acceptanceActivation');
        check(true,label+' keyboard activates '+item.label);
        if(activation.tag==='A'){
            const response=await evaluate('fetch('+JSON.stringify(activation.href)+').then(r=>({status:r.status,type:r.headers.get("content-type"),disposition:r.headers.get("content-disposition")}))');
            check(response.status===200,label+' '+item.label+' authorized navigation/file endpoint');
            interactions.push({uid,page,action:item.label,result:'keyboard link activation and authenticated endpoint',response});
        }else if(uid===1003&&/upload revision/i.test(item.label)){
            await waitFor('location.hash==="#submit"&&document.getElementById("submissionForm").previousElementSibling.getClientRects().length>0');
            check(await evaluate('document.getElementById("submissionForm").previousElementSibling.previousElementSibling.textContent.includes("revision")'),label+' revision navigates to authorized upload form');
            await shot(filename+'-revision-form');
            await focus('button:has(+ #submissionForm)');await key('Enter');
            await evaluate('document.querySelector("[data-page=documents]").click()');await waitFor('!!document.querySelector("#documentRows .prism-action-trigger")');
            interactions.push({uid,page,action:item.label,result:'keyboard navigation to existing revision form; cancelled without upload'});
        }else{
            await waitFor(visibleDialogs+'.length>0');
            check(await evaluate(visibleDialogs+'.some(n=>n.contains(document.activeElement))'),label+' '+item.label+' moves focus into modal');
            check(await evaluate(visibleDialogs+'.every(n=>n.getBoundingClientRect().width<=innerWidth+1)'),label+' '+item.label+' modal fits');
            await evaluate('(()=>{const n='+visibleDialogs+'[0],a=[...n.querySelectorAll("button,input,select,textarea,a[href]")].filter(e=>!e.disabled&&e.tabIndex>=0&&e.getClientRects().length);window.__modalRoot=n;window.__modalFirst=a[0];window.__modalLast=a.at(-1);a.at(-1)?.focus();})()');
            // Native dialog Tab may visit browser chrome (body is then active); background app controls remain inert.
            await key('Tab');check(await evaluate('window.__modalRoot.matches("dialog")?(window.__modalRoot.contains(document.activeElement)||document.activeElement===document.body):document.activeElement===window.__modalFirst'),label+' '+item.label+' modal Tab excludes background controls');
            await key('Tab',true);check(await evaluate('window.__modalRoot.matches("dialog")?(window.__modalRoot.contains(document.activeElement)||document.activeElement===document.body):document.activeElement===window.__modalLast'),label+' '+item.label+' modal Shift+Tab excludes background controls');
            if(filename&&/review|version|edit|revision|override|return/i.test(item.label))await shot(filename+'-'+item.index,null,true);
            await key('Escape');
            await waitFor(visibleDialogs+'.length===0');
            check(await evaluate('document.activeElement.matches(".prism-action-trigger")'),label+' '+item.label+' cancellation restores Actions focus');
            interactions.push({uid,page,action:item.label,result:'keyboard modal activation, focus and Escape cancellation'});
        }
        await key('Escape');
    }
}
async function topbarKeyboard(uid,page,label,filename){
    await focus('#themeToggle');await key('Enter');
    const toggled=await evaluate('document.documentElement.classList.contains("dark-theme")');await key('Enter');
    check(await evaluate('document.documentElement.classList.contains("dark-theme")')!==toggled,label+' keyboard theme toggle');
    await focus('.portal-profile-btn');await key('Enter');
    const panelSelector=await evaluate('"#"+document.querySelector(".portal-profile-btn").getAttribute("aria-controls")');
    check(await evaluate('!document.querySelector('+JSON.stringify(panelSelector)+').hidden'),label+' keyboard profile opens');
    await key('Tab');check(await evaluate('document.querySelector('+JSON.stringify(panelSelector)+').contains(document.activeElement)'),label+' profile Tab enters menu');
    check(await evaluate('(()=>{const r=document.querySelector('+JSON.stringify(panelSelector)+').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1;})()'),label+' profile dropdown fits');
    await shot(filename+'-profile',null,true);await key('Escape');
    check(await evaluate('document.querySelector('+JSON.stringify(panelSelector)+').hidden&&document.activeElement.matches(".portal-profile-btn")'),label+' profile Escape restores focus');
    await focus('[data-prism-top-tour]');await key('Enter');await waitFor('!!document.querySelector("dialog[open]")');
    check(await evaluate('document.querySelector("dialog[open]").contains(document.activeElement)'),label+' Quick Tour keyboard focus');await shot(filename+'-tour',null,true);await key('Escape');
    check(await evaluate('document.activeElement.matches("[data-prism-top-tour]")'),label+' Quick Tour Escape restores focus');
    const cookieState=async()=> (await command('Network.getCookies')).cookies.map(c=>({name:c.name,expires:c.expires})).sort((a,b)=>a.name.localeCompare(b.name));
    const cookiesBefore=await cookieState();
    await focus('[data-prism-persistence]');await key('Enter');await waitFor('!!document.querySelector("dialog[open]")');
    check(await evaluate('document.querySelector("dialog[open]").textContent.includes("unavailable")&&document.querySelector("[data-prism-persistence]").getAttribute("aria-disabled")==="true"'),label+' persistence unavailable by keyboard');
    await shot(filename+'-persistence',null,true);await key('Escape');
    check(JSON.stringify(await cookieState())===JSON.stringify(cookiesBefore),label+' unavailable persistence issues no credential');
    const bell='.prism-topbar-group .notification-icon,.prism-topbar-group > .portal-notification';
    await focus(bell);await key('Enter');await new Promise(r=>setTimeout(r,300));
    check(await evaluate('location.pathname.endsWith("admin_notifications.php")||!!document.querySelector(".notification-dropdown:not([hidden])")||document.querySelector("[data-section=notifications]")?.classList.contains("active")'),label+' notification keyboard navigation/dropdown');
    if(await evaluate('!!document.querySelector(".notification-dropdown:not([hidden])")')){await shot(filename+'-notifications',null,true);await key('Escape');}
    await navigate(page);
}
async function acceptanceInteractions(){
    for(const [width,height] of [[1366,768],[768,1024],[390,844]])for(const dark of [false,true]){
        await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');
        for(const [uid,page] of [[1001,'admin_students.php'],[1001,'admin_advisers.php'],[1001,'ierbprog.php'],[1001,'documents.php'],[1002,'research_adviser.php'],[1002,'admin_students.php'],[1002,'documents.php'],[1002,'ierbprog.php'],[1003,'student.php']]){
            await post('fixture-auth.php',{uid});await navigate(page);
            if(uid===1003)await evaluate('document.querySelector("[data-page=documents]").click()');
            await waitFor('!!document.querySelector(".prism-action-trigger")');
            const label=uid+'/'+page+'/'+width+'/'+dark,prefix='UI01-actions-'+uid+'-'+page+'-'+width+'-'+(dark?'dark':'light');
            console.log('Interaction '+label);
            await menuKeyboard('.prism-action-trigger',label,prefix);
            const count=await evaluate('document.querySelectorAll(".prism-action-trigger").length');
            if(count>1)await menuKeyboard('.prism-compact-table tbody tr:last-child .prism-action-trigger',label+' last row');
            // All distinct rendered controls are exercised once per role/route; sizing and keyboard run at each size/theme.
            if(width===1366&&!dark)await actionControls(uid,page,label,prefix+'-modal');
            await evaluate('document.querySelector(".prism-record-details").open=true');
            await shot('UI01-expanded-'+uid+'-'+page+'-'+width+'-'+(dark?'dark':'light'));
            if(page==='research_adviser.php'||uid===1003||(page==='admin_students.php'&&uid===1001))await topbarKeyboard(uid,page,label,'UI03-'+uid+'-'+width+'-'+(dark?'dark':'light'));
        }
        await post('fixture-auth.php',{uid:1001});await navigate('dashboard.php');
        await waitFor('document.querySelectorAll("#repositoryFilters button").length===4&&!!document.querySelector("#initialStageCount b")');
        check(await evaluate('(()=>{const h=document.querySelector(".dashboard-repository h3"),r=h.getBoundingClientRect();return r.width>0&&r.height>0&&r.left>=0&&r.right<=innerWidth+1;})()'),'UI07 heading fully within viewport '+width+'/'+dark);
        await shot('UI07-'+width+'-'+(dark?'dark':'light'),'.dashboard-repository');
        await shot('UI09-'+width+'-'+(dark?'dark':'light'),'.dashboard-stage');
    }
    fs.writeFileSync(path.join(out,'interaction-results.json'),JSON.stringify({checks,errors,failures,assertions,interactions,captures},null,2));
}
async function run(){
    child=spawn(browser,['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--disable-extensions','--disable-component-update','--password-store=basic','about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
    const url=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(new Error('Browser startup timeout')),15000);child.once('error',reject);child.stderr.on('data',chunk=>{log+=chunk;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});});
    socket=new WebSocket(url);await once(socket,'open');socket.addEventListener('message',e=>{
        const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(new Error(m.error.message)):p.resolve(m.result);}
        else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);
        else if(m.method==='Fetch.requestPaused'){const {requestId,request}=m.params;if(injectedFailure&&request.url.includes(injectedFailure)){command('Fetch.fulfillRequest',{requestId,responseCode:503,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify({ok:false,message:'Injected fixture service failure'})).toString('base64')}).catch(()=>{});}else if(cdnAssets[request.url]){const asset=cdnAssets[request.url];command('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:asset.mime},{name:'Access-Control-Allow-Origin',value:'*'}],body:fs.readFileSync(path.join(cdnCache,asset.file)).toString('base64')}).catch(()=>{});}else command(request.url.startsWith(origin+'/')?'Fetch.continueRequest':'Fetch.fulfillRequest',request.url.startsWith(origin+'/')?{requestId}:{requestId,responseCode:200,body:''}).catch(()=>{});}
    });
    const target=await command('Target.createTarget',{url:'about:blank'},true);sessionId=(await command('Target.attachToTarget',{targetId:target.targetId,flatten:true},true)).sessionId;
    await command('Runtime.enable');await command('Network.enable');await command('Network.setCacheDisabled',{cacheDisabled:true});await command('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    await navigate('login.php');await post('fixture-auth.php',{uid:1001});
    if(process.env.PRISM_B1075_DIAGNOSTIC==='portal320'){
        const headings=[];
        await command('Emulation.setDeviceMetricsOverride',{width:320,height:700,deviceScaleFactor:1,mobile:false});
        for(const [uid,page] of [[1002,'research_adviser.php'],[1003,'student.php']])for(const dark of [false,true]){
            await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');await post('fixture-auth.php',{uid});await navigate(page);await evaluate('document.fonts.ready.then(()=>true)');await new Promise(r=>setTimeout(r,900));
            const bounds=await evaluate('(()=>{const h=[...document.querySelectorAll("main h1,main h2")].find(n=>n.getClientRects().length),v=document.querySelector(".portal-navbar");return {headingTop:h.getBoundingClientRect().top,navBottom:v.getBoundingClientRect().bottom};})()');
            check(bounds.headingTop>=bounds.navBottom,'portal heading visible '+uid+'/'+dark);headings.push({uid,page,dark,...bounds});await shot('diagnostic-portal320-'+uid+'-'+dark,null,true);
        }
        fs.writeFileSync(path.join(out,'portal320-diagnostics.json'),JSON.stringify({headings,checks,failures},null,2));if(failures.length)process.exitCode=1;return;
    }
    if(process.env.PRISM_B1075_DIAGNOSTIC==='student768'){
        await command('Emulation.setDeviceMetricsOverride',{width:768,height:1024,deviceScaleFactor:1,mobile:false});
        await post('fixture-auth.php',{uid:1003});await navigate('student.php');await evaluate('document.querySelector("[data-page=documents]").click()');await waitFor('!!document.querySelector("#documentRows .prism-record-card")');
        const sizes=await evaluate('(()=>{const t=document.getElementById("documentRows").closest("table"),c=t.querySelector("td");return {tableWidth:t.getBoundingClientRect().width,cellWidth:c.getBoundingClientRect().width,maxWidth:getComputedStyle(c).maxWidth};})()');
        check(sizes.cellWidth>=sizes.tableWidth*.8,'Student tablet compact cell fills available document width');
        fs.writeFileSync(path.join(out,'student768-diagnostics.json'),JSON.stringify({sizes,checks,failures},null,2));await shot('diagnostic-Student-768');if(failures.length)process.exitCode=1;return;
    }
    if(['dashboard320','adviser320'].includes(process.env.PRISM_B1075_DIAGNOSTIC)){
        const adviserDiagnostic=process.env.PRISM_B1075_DIAGNOSTIC==='adviser320';
        const diagnostics=[];
        for(const dark of [false,true]){
            await command('Emulation.setDeviceMetricsOverride',{width:320,height:700,deviceScaleFactor:1,mobile:false});
            await evaluate('localStorage.setItem("prismTheme",'+JSON.stringify(dark?'dark':'light')+')');if(adviserDiagnostic)await post('fixture-auth.php',{uid:1002});await navigate(adviserDiagnostic?'admin_students.php':'dashboard.php');await new Promise(r=>setTimeout(r,1800));
            diagnostics.push(await evaluate(`(()=>{const items=[...document.querySelectorAll('body *')].filter(n=>{const r=n.getBoundingClientRect();if(!r.width||!r.height||r.right<=innerWidth+1)return false;for(let p=n.parentElement;p&&p!==document.body;p=p.parentElement)if(['hidden','auto','scroll','clip'].includes(getComputedStyle(p).overflowX))return false;return true;}).map(n=>{const r=n.getBoundingClientRect(),s=getComputedStyle(n);return {tag:n.tagName,id:n.id,classes:n.className,left:r.left,right:r.right,width:r.width,minWidth:s.minWidth,whiteSpace:s.whiteSpace,text:n.textContent.trim().slice(0,120)};});return {dark:document.documentElement.classList.contains('dark-theme'),scrollWidth:document.documentElement.scrollWidth,items};})()`));
            if(adviserDiagnostic)diagnostics.at(-1).heading=await evaluate('(()=>{const h=document.querySelector("main h1"),n=document.querySelector(".portal-navbar");return {headingTop:h.getBoundingClientRect().top,navBottom:n.getBoundingClientRect().bottom};})()');
            await shot('diagnostic-'+(adviserDiagnostic?'adviser320':'dashboard320')+'-'+dark,null,true);
            if(!adviserDiagnostic)await shot('diagnostic-UI07-320-'+dark,'.dashboard-repository');
        }
        fs.writeFileSync(path.join(out,(adviserDiagnostic?'adviser320':'dashboard320')+'-diagnostics.json'),JSON.stringify(diagnostics,null,2));return;
    }
    if(process.argv[5]==='interaction'){
        await acceptanceInteractions();console.log('Batch10.75 interactions: '+checks+' assertions, '+failures.length+' failures.');if(failures.length)process.exitCode=1;return;
    }
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
                if(width<=360&&await evaluate('!!document.querySelector(".portal-navbar")'))check(await evaluate('(()=>{const h=[...document.querySelectorAll("main h1,main h2")].find(n=>n.getClientRects().length),v=document.querySelector(".portal-navbar");return h&&h.getBoundingClientRect().top>=v.getBoundingClientRect().bottom;})()'),label+' heading below fixed navigation');
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
            if(acceptance||width===1366||width===390){
                if(page==='admin_students.php'&&uid===1001)fs.writeFileSync(path.join(out,'navigation-'+width+'-'+dark+'.json'),JSON.stringify(await evaluate('(()=>{const s=document.querySelector(".prism-sidebar"),b=document.querySelector(".prism-sidebar-backdrop"),c=document.querySelector(".prism-navigation-caption");return {sidebar:s.className,expanded:document.getElementById("prismSidebarToggle").getAttribute("aria-expanded"),caption:getComputedStyle(c).display,backdropHidden:b.hidden,backdrop:getComputedStyle(b).display};})()'),null,2));
                await new Promise(r=>setTimeout(r,250));await shot(uid+'-'+page+'-'+width+'-'+(dark?'dark':'light'));
            }
            if(page==='dashboard.php'&&!before&&(acceptance||width===1366||width===390)){await shot('UI07-'+width+'-'+(dark?'dark':'light'),'.dashboard-repository');await shot('UI09-'+width+'-'+(dark?'dark':'light'),'.dashboard-stage');}
            if(page==='student.php'){
                await evaluate('document.querySelector("[data-page=progress]").click()');
                if(!before){check(await evaluate('document.querySelectorAll("#stageList .stage-card").length===6&&document.querySelectorAll("#stageList [aria-current=step]").length===1'),label+' real six-stage journey');check(await evaluate('!!document.getElementById("principalIndicator")&&!!document.getElementById("progressRing")&&!!document.getElementById("progressHistory")'),label+' official PI ring history retained');check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' progress fits');}
                if(acceptance||width===1366||width===390)await shot('UI08-'+width+'-'+(dark?'dark':'light'));
                if(!before){await evaluate('document.querySelector("[data-page=documents]").click()');await waitFor('!!document.querySelector("#documentRows .prism-record-details")');check(await evaluate('!document.querySelector("[data-formal-submit],[data-submit]")&&!!document.querySelector("[data-version-history]")'),label+' Student document authority/history');check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+' Student documents fit');check(await evaluate('(()=>{const t=document.getElementById("documentRows").closest("table"),c=t.querySelector("td");return c.getBoundingClientRect().width>=t.getBoundingClientRect().width*.8;})()'),label+' Student compact cards fill available document width');}
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
            if(acceptance||width===1366||width===390)await shot('UI02-'+width+'-'+(dark?'dark':'light'));
        }
        await post('fixture-auth.php',{uid:1001});await navigate('admin_legal_policies.php');
        await evaluate('document.querySelector("input[name=policy][value=privacy]").closest("form").requestSubmit()');
        await waitFor('!!document.querySelector("[data-legal-draft]")&&document.readyState==="complete"');
        const versionUrl=await evaluate('location.href'),saved=await evaluate('document.getElementById("policyTitle").value');
        for(const [width,height] of (acceptance?[[1366,768],[768,1024],[390,844]]:[[1366,768],[390,844]]))for(const dark of [false,true]){
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
    if(acceptance)await acceptanceInteractions();
    check(errors.length===0,'no browser exceptions');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({checks,errors,failures,matrix,assertions,captures,interactions},null,2));console.log('Batch10.75 '+process.argv[4]+': '+checks+' browser assertions, '+failures.length+' failures, '+errors.length+' exceptions.');if(failures.length){console.error(failures.join('\n'));process.exitCode=1;}

}
run().catch(async e=>{console.error(e.stack);console.error('Failures so far: '+JSON.stringify(failures));console.error('Exceptions: '+JSON.stringify(errors));await shot('failure').catch(()=>{});fs.writeFileSync(path.join(out,'failure-results.json'),JSON.stringify({error:e.message,checks,errors,failures,assertions,interactions,captures},null,2));process.exitCode=1;}).finally(async()=>{
    if(socket?.readyState===WebSocket.OPEN){await command('Browser.close',{},true).catch(()=>{});socket.close();}
    if(child&&child.exitCode===null){await Promise.race([once(child,'exit'),new Promise(r=>setTimeout(r,2000))]);if(child.exitCode===null)child.kill();}
    const resolved=path.resolve(profile);assert.equal(path.dirname(resolved),path.resolve(os.tmpdir()));assert(path.basename(resolved).startsWith('prism-b10-ui-'));fs.rmSync(resolved,{recursive:true,force:true,maxRetries:10,retryDelay:200});
});
