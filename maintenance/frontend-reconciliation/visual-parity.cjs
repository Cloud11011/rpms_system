'use strict';
// Side-by-side capture of the original ZIP (restricted reference source, isolated PHP server) and the reconciled
// application on the same disposable database, for every role page at 390/1440 in light and dark themes.
// Pixel comparison and composites are produced afterwards by visual-diff.py; differences need human review.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
const PAGES={
 admin:['dashboard.php','ierbprog.php','admin_students.php','admin_advisers.php','admin_archived_accounts.php','documents.php','calendar.php',
  'admin_notifications.php','reports.php','admin_ai.php','data_export.php','admin_settings.php','admin_legal_policies.php','account.php','help_support.php'],
 adviser:['research_adviser.php','documents.php','calendar.php','admin_students.php','account.php','help_support.php'],
 student:['role_portal.php','role_portal.php#progress','role_portal.php#documents','role_portal.php#profile','help_support.php'],
 public:['login.php','forgot_password.php','privacy.php','terms.php'],
};
module.exports=async h=>{
 const {check,playwright,review,root,php,base,sessions,sleep}=h;
 const referenceApp=path.join(review,'sandbox/reference-app');
 const captures=path.join(review,'visual-parity');fs.mkdirSync(captures,{recursive:true});
 if(!fs.existsSync(referenceApp)){
  fs.cpSync(path.join(root,'tests/frontend-reconciliation/reference'),referenceApp,{recursive:true});
  fs.cpSync(path.join(root,'vendor'),path.join(referenceApp,'vendor'),{recursive:true});
 }
 for(const name of ['sessions','uploads','storage/documents','storage/reports'])fs.mkdirSync(path.join(referenceApp,name),{recursive:true});
 const refPort=String(Number(process.env.PRISM_TEST_APP_PORT)+1000),refBase='http://127.0.0.1:'+refPort;
 let logs='';
 const server=cp.spawn(php,['-d','extension=zip','-d','auto_prepend_file='+path.join(root,'maintenance/ierb-group-tests/runtime.php'),
  '-d','disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close',
  '-d','session.save_path='+path.join(referenceApp,'sessions'),'-d','upload_tmp_dir='+path.join(referenceApp,'uploads'),'-S','127.0.0.1:'+refPort,'-t',referenceApp],
  {windowsHide:true,env:{...process.env,PRISM_TEST_APP_PORT:refPort}});
 server.stdout.on('data',d=>logs+=d);server.stderr.on('data',d=>logs+=d);
 let browser;const refSessions=[],pairs=[],errors=[];
 let optionProbe=null;
 try{
  let ready=false;for(let i=0;i<80;i++){try{if((await fetch(refBase+'/login.php')).ok){ready=true;break;}}catch{}await sleep(100);}
  check(ready,'Visual parity: the original ZIP source renders in an isolated reference PHP server');
  const states={public:undefined};
  for(const role of ['admin','adviser','student']){
   const session=await playwright.request.newContext({baseURL:refBase});refSessions.push(session);
   const email=role==='admin'?'admin@example.test':role==='adviser'?'adviser2@example.test':'student4@example.test';
   const r=await session.post('/login_process.php',{form:{email,password:'SyntheticPass123!'},headers:{origin:refBase},maxRedirects:0});
   check(r.status()===302,'Visual parity: original ZIP authenticates the synthetic '+role);states[role]=await session.storageState();
  }
  // The archived list's server-provided status options, on the original ZIP and on the reconciled app (same database).
  optionProbe={};
  for(const [name,ctx,origin] of [['reference',refSessions[0],refBase],['reconciled',sessions.admin,base]]){
   for(const query of ['lifecycle=archived&preview=1','preview=1']){
    const r=await ctx.get('/students_api.php?action=list&'+query);let body=null;try{body=await r.json();}catch{}
    optionProbe[name+'|'+query]={status:r.status(),total:body?.total??null,statusOptions:(body?.filterOptions?.status||[]).map(o=>o.label)};
   }
  }
  browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  for(const [role,routes] of Object.entries(PAGES))for(const route of routes)for(const theme of ['light','dark'])for(const width of [390,1440]){
   const pair={role,route,theme,width,variants:{}};
   for(const variant of ['reference','reconciled']){
    const storageState=role==='public'?undefined:variant==='reference'?states[role]:await sessions[role].storageState();
    const ctx=await browser.newContext({viewport:{width,height:900},storageState,reducedMotion:'reduce'});
    try{
     const identity=crypto.createHash('sha256').update(role==='student'?'Student|S4':role+'|'+(role==='admin'?1:2)).digest('hex');
     await ctx.addInitScript(({role,theme,identity})=>{try{localStorage.setItem('prismTheme',theme);localStorage.setItem(`prismTour.${role}.${identity}.v1`,'seen');}catch{}},{role,theme,identity});
     await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
     const page=await ctx.newPage();page.on('pageerror',e=>errors.push({role,route,theme,width,variant,error:String(e)}));
     await page.clock.setFixedTime(new Date('2026-11-02T15:00:00Z'));
     await page.goto((variant==='reference'?refBase:base)+'/'+route);
     await page.waitForLoadState('networkidle',{timeout:8000}).catch(()=>{});
     await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
     // Same treatment for both variants: an overlay sidebar opened by default is closed before capture.
     if(await page.locator('.prism-sidebar-backdrop:not([hidden])').count())await page.locator('#prismSidebarToggle').click().catch(()=>{});
     await page.locator('img').evaluateAll(images=>Promise.all(images.map(image=>{image.loading='eager';return image.decode().catch(()=>{});})));
     await page.waitForTimeout(500);
     const geometry=await page.evaluate(()=>{
      const pick=['body','main','.main-content','.topbar','.topbar h1','.prism-sidebar','.sidebar','.portal-navbar','.panel','.cards','.card','.management-controls','.report-tools','.settings-grid','.account-layout'];
      return {url:location.pathname+location.hash,title:document.title,scrollWidth:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight,
       dark:document.documentElement.classList.contains('dark-theme'),
       nodes:pick.map(sel=>{const n=document.querySelector(sel);if(!n)return {sel,present:false};const b=n.getBoundingClientRect(),s=getComputedStyle(n);
        return {sel,present:true,x:Math.round(b.x),y:Math.round(b.y),w:Math.round(b.width),h:Math.round(b.height),font:s.fontFamily,size:s.fontSize,weight:s.fontWeight,
         color:s.color,bg:s.backgroundColor,radius:s.borderRadius,padding:s.padding};})};
     });
     // Inventory for classification: every visible control/heading/chip with its computed presentation, plus visible text.
     const inventory=await page.evaluate(()=>{
      const vis=n=>{const r=n.getBoundingClientRect(),st=getComputedStyle(n);return r.width>0&&r.height>0&&st.visibility!=='hidden'&&st.display!=='none';};
      const props=['fontFamily','fontSize','fontWeight','color','backgroundColor','borderRadius','paddingTop','paddingLeft','borderTopColor','borderTopWidth','boxShadow','textTransform','letterSpacing'];
      const items=[...document.querySelectorAll('h1,h2,h3,h4,button,a,input,select,textarea,label,th,.prism-badge,.badge,.stage-tag,[class*="chip"],[class*="pill"],[class*="card"] strong')].filter(vis).slice(0,600).map(n=>{
       const st=getComputedStyle(n),b=n.getBoundingClientRect();
       return {tag:n.tagName.toLowerCase(),text:(n.innerText||n.value||n.getAttribute('aria-label')||n.placeholder||'').replace(/\s+/g,' ').trim().slice(0,70),
        cls:[...n.classList].sort().join('.'),type:n.getAttribute('type')||'',placeholder:n.getAttribute('placeholder')||'',title:n.getAttribute('title')||'',ariaLabel:n.getAttribute('aria-label')||'',w:Math.round(b.width),h:Math.round(b.height),style:Object.fromEntries(props.map(k=>[k,st[k]]))};});
      // Wrap anomalies: any visible element with its own text rendered with very few characters per line (letter-by-letter wrapping).
      const blocks=[...document.querySelectorAll('body *')].filter(n=>vis(n)&&[...n.childNodes].some(c=>c.nodeType===3&&c.textContent.trim().length>=2)).slice(0,2500).map(n=>{
       const st=getComputedStyle(n),b=n.getBoundingClientRect(),own=[...n.childNodes].filter(c=>c.nodeType===3).map(c=>c.textContent).join(' ').replace(/\s+/g,' ').trim();
       const lh=parseFloat(st.lineHeight)||parseFloat(st.fontSize)*1.2,lines=Math.max(1,Math.round((b.height-parseFloat(st.paddingTop)-parseFloat(st.paddingBottom))/lh));
       return {tag:n.tagName.toLowerCase(),cls:[...n.classList].join('.').slice(0,50),text:own.slice(0,50),len:own.length,w:Math.round(b.width),h:Math.round(b.height),lines,cpl:Math.round(own.length/lines*10)/10};
      }).filter(x=>x.len>=8&&x.lines>=4&&x.cpl<5);
      return {items,wrapAnomalies:blocks,text:document.body.innerText.replace(/[ \t]+/g,' ').split(/\n+/).map(x=>x.trim()).filter(Boolean)};
     });
     const file=`${role}-${route.replace(/[^a-z0-9]+/gi,'_')}-${theme}-${width}-${variant}.png`;
     await page.screenshot({path:path.join(captures,file),fullPage:true,animations:'disabled',caret:'hide'});
     pair.variants[variant]={file,geometry,inventory};
    }finally{await ctx.close();}
   }
   pairs.push(pair);
  }
  const reconciledErrors=errors.filter(e=>e.variant==='reconciled');
  check(!reconciledErrors.length,'Visual parity: reconciled pages have no JavaScript errors: '+JSON.stringify(reconciledErrors));
  check(pairs.every(p=>p.variants.reconciled.geometry.scrollWidth<=Math.max(p.width,p.variants.reference.geometry.scrollWidth)),
   'Visual parity: no reconciled page overflows horizontally beyond the original ZIP: '+JSON.stringify(pairs.filter(p=>p.variants.reconciled.geometry.scrollWidth>Math.max(p.width,p.variants.reference.geometry.scrollWidth)).map(p=>[p.role,p.route,p.theme,p.width])));
 }finally{
  if(browser)await browser.close();for(const s of refSessions)await s.dispose();server.kill();
  fs.writeFileSync(path.join(review,'visual-parity.json'),JSON.stringify({pairs,errors,optionProbe,scope:'Same disposable database, viewport, theme and fixed clock; remote fonts/icons replaced by identical empty CSS on both sides. Data-dependent differences are expected where V14 behaviour is authoritative.'},null,2));
  fs.writeFileSync(path.join(review,'sandbox/reference-server.log'),logs);
 }
};
