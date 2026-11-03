'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
module.exports=async h=>{
 const {check,playwright,review,root,php,base,sessions,sleep}=h;
 const reference=path.join(root,'tests/frontend-reconciliation/reference');
 const referenceApp=path.join(review,'sandbox/reference-app');
 const captures=path.join(review,'paired-screenshots');fs.mkdirSync(captures,{recursive:true});
 fs.cpSync(reference,referenceApp,{recursive:true});
 fs.cpSync(path.join(root,'vendor'),path.join(referenceApp,'vendor'),{recursive:true});
 for(const name of ['sessions','uploads','storage/documents','storage/reports'])fs.mkdirSync(path.join(referenceApp,name),{recursive:true});
 const refPort=String(Number(process.env.PRISM_TEST_APP_PORT)+1000),refBase='http://127.0.0.1:'+refPort;
 let logs='';
 const server=cp.spawn(php,['-d','extension=zip','-d','auto_prepend_file='+path.join(root,'maintenance/ierb-group-tests/runtime.php'),
  '-d','disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close',
  '-d','session.save_path='+path.join(referenceApp,'sessions'),'-d','upload_tmp_dir='+path.join(referenceApp,'uploads'),'-S','127.0.0.1:'+refPort,'-t',referenceApp],
  {windowsHide:true,env:{...process.env,PRISM_TEST_APP_PORT:refPort}});
 server.stdout.on('data',d=>logs+=d);server.stderr.on('data',d=>logs+=d);
 let browser;const refSessions=[];const comparisons=[],errors=[],failedResponses=[];
 try{
  let ready=false;for(let i=0;i<60;i++){try{if((await fetch(refBase+'/login.php')).ok){ready=true;break;}}catch{}await sleep(100);}
  check(ready,'The restricted original ZIP source renders in an isolated reference PHP server');
  const states={};
  for(const role of ['admin','adviser','student']){
   const session=await playwright.request.newContext({baseURL:refBase});refSessions.push(session);
   const email=role==='admin'?'admin@example.test':role==='adviser'?'adviser2@example.test':'student4@example.test';
   const response=await session.post('/login_process.php',{form:{email,password:'SyntheticPass123!'},headers:{origin:refBase},maxRedirects:0});
   check(response.status()===302,'Original ZIP authenticates the matching synthetic '+role+' fixture');states[role]=await session.storageState();
  }
  browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  for(const role of ['admin','adviser','student'])for(const theme of ['light','dark'])for(const width of [390,1440]){
   const records=[];
   for(const variant of ['reference','reconciled']){
    const ctx=await browser.newContext({viewport:{width,height:1000},storageState:variant==='reference'?states[role]:await sessions[role].storageState(),reducedMotion:'reduce'});
    try{
     const identity=crypto.createHash('sha256').update(role==='student'?'Student|S4':role+'|'+(role==='admin'?1:2)).digest('hex');
     await ctx.addInitScript(({role,theme,identity})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(`prismTour.${role}.${identity}.v1`,'seen');},{role,theme,identity});
     await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,route=>route.fulfill({contentType:'text/css',body:''}));
     const page=await ctx.newPage();page.on('pageerror',e=>errors.push({role,theme,width,variant,error:String(e)}));
     await page.clock.setFixedTime(new Date('2026-11-02T15:00:00Z'));
     page.on('response',r=>{if(r.status()>=400&&new URL(r.url()).hostname==='127.0.0.1')failedResponses.push({role,variant,status:r.status(),url:r.url()});});
     await page.goto((variant==='reference'?refBase:base)+(role==='admin'?'/dashboard.php':role==='adviser'?'/research_adviser.php':'/role_portal.php'));
     await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
     if(role==='admin')await page.locator('[data-ierb-monitor][aria-busy="false"]').waitFor();
     else if(role==='adviser') await page.waitForFunction(()=>!['Loading...','…'].includes(document.querySelector('#adviserPendingCount')?.textContent));
     else await page.locator('#studentDashboardState[aria-busy="false"]').waitFor({state:'attached'});
     await page.locator('img').evaluateAll(images=>Promise.all(images.map(image=>{image.loading='eager';return image.decode().catch(()=>{});})));
     await page.waitForTimeout(300);
     const geometry=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,dark:document.documentElement.classList.contains('dark-theme'),
       elements:[...document.querySelectorAll('main,.sidebar,.portal-navbar,.topbar,.cards,.adviser-content-grid,.dashboard-progress')].map(n=>{const b=n.getBoundingClientRect(),s=getComputedStyle(n);return {tag:n.tagName,class:n.className,x:b.x,y:b.y,width:b.width,height:b.height,font:s.fontFamily,fontSize:s.fontSize,color:s.color,background:s.backgroundColor};})}));
     const file=`${role}-${theme}-${width}-${variant}.png`;await page.screenshot({path:path.join(captures,file),fullPage:true,animations:'disabled',caret:'hide'});
     if(variant==='reconciled'){
      check(geometry.dark===(theme==='dark'),role+' '+width+' '+theme+': requested theme renders');
      check(geometry.scrollWidth<=Math.max(width,records[0].geometry.scrollWidth),role+' '+width+' '+theme+': no page overflow beyond the original ZIP');
      if(role==='admin')check(await page.locator('.dashboard-stage-card').count()===4&&await page.locator('[data-monitor-layout="cards"]').count()===1,'RPMS '+width+' '+theme+': four-stage distribution and approved card monitor render');
      else if(role==='adviser') check((await page.locator('#adviserStatus').textContent()).trim()===''&&await page.locator('.adviser-summary-card').count()===3,'Adviser '+width+' '+theme+': actual data loads into the three ZIP review cards');
      else check((await page.locator('#studentDashboardState').getAttribute('aria-busy'))==='false'&&!!(await page.locator('#dashboardPendingTitle').textContent()),'Student '+width+' '+theme+': authorized dashboard data finishes loading');
     }
     records.push({variant,file,geometry});
    }finally{await ctx.close();}
   }
   comparisons.push({role,theme,width,records});
  }
  check(!errors.filter(e=>e.variant==='reconciled').length,'Reconciled dashboard browser matrix has no JavaScript errors: '+JSON.stringify(errors.filter(e=>e.variant==='reconciled')));
  check(!failedResponses.filter(e=>e.variant==='reconciled').length,'Reconciled dashboard matrix has no failed local asset/API responses: '+JSON.stringify(failedResponses.filter(e=>e.variant==='reconciled')));
 }finally{
  if(browser)await browser.close();for(const session of refSessions)await session.dispose();server.kill();
  fs.writeFileSync(path.join(review,'dashboard-comparison.json'),JSON.stringify({comparisons,errors,failedResponses,scope:'Actual PHP, matching synthetic schema-11 database, equivalent viewport/theme; remote decorative fonts/icons replaced by identical empty CSS on both sides. Pixel differences require independent analysis.'},null,2));
  fs.writeFileSync(path.join(review,'sandbox/reference-server.log'),logs);
 }
};
