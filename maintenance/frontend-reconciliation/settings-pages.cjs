'use strict';
// Settings and Generated Reports on the schema-15 fixture: ZIP pages render, existing Add Academic Year works,
// schema-16 policy actions stay read-only, Advisers cannot open Settings.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
module.exports=async(h,browser)=>{
 const {base,sessions:s,check,review}=h,start=h.checks.length,errors=[],failures=[];
 const out=path.join(review,'current-v14-screenshots');fs.mkdirSync(out,{recursive:true});
 const open=async(role,theme,width,route)=>{
  const ctx=await browser.newContext({viewport:{width,height:1000},storageState:await s[role].storageState(),reducedMotion:'reduce'});
  const identity=crypto.createHash('sha256').update(role+'|'+(role==='admin'?1:2)).digest('hex');
  await ctx.addInitScript(({role,theme,identity})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(`prismTour.${role}.${identity}.v1`,'seen');},{role,theme,identity});
  await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push({role,theme,width,route,error:String(e)}));
  page.on('response',r=>{if(r.status()>=400&&new URL(r.url()).hostname==='127.0.0.1')failures.push({role,route,status:r.status(),url:r.url()});});
  page.on('dialog',d=>d.accept());
  await page.goto(base+'/'+route);
  if(await page.locator('.prism-sidebar-backdrop:not([hidden])').count())await page.locator('#prismSidebarToggle').click();
  return {ctx,page};
 };
 const settingsReady=async page=>{
  await page.locator('[data-year-rows] tr').first().waitFor();
  await page.waitForFunction(()=>!/Loading/.test(document.querySelector('[data-communication-status]')?.textContent||'Loading'));
 };
 // One functional pass: the existing schema-15 Add Academic Year operation through the ZIP Settings panel.
 {
  const {ctx,page}=await open('admin','light',1440,'admin_settings.php');
  try{
   await settingsReady(page);
   check(await page.locator('#newAcademicYear').isEnabled(),'schema15 Settings: existing Add Academic Year stays enabled');
   check(await page.locator('.academic-year-actions button:not([disabled])').count()===0,'schema15 Settings: schema16 availability/current/removal actions stay read-only');
   check(await page.locator('#notificationTemplateBody').isDisabled(),'schema15 Settings: communication settings stay read-only');
   check(/approved settings migration/.test(await page.locator('[data-communication-status]').innerText()),'schema15 Settings: communication panel explains the pending migration');
   check((await page.locator('[data-settings-status]').innerText()).trim()==='You can add academic years now. Other academic-year settings will become available after the database update.','schema15 Settings: approved Academic Years status wording');
   await page.locator('#newAcademicYear').fill('2035-2036');
   await page.locator('[data-add-year] button').click();
   await page.locator('[data-year-rows] tr',{hasText:'2035-2036'}).waitFor();
   check(true,'schema15 Settings: Admin adds 2035-2036 to the shared catalog');
  }finally{await ctx.close();}
  const {ctx:c2,page:p2}=await open('admin','light',1440,'admin_students.php');
  try{
   await p2.locator('.student-result-card').first().waitFor();
   await p2.locator('#addRecord').click();
   const years=await p2.locator('#studentAcademicFields [data-academic="academic-year"] option').allTextContents();
   check(years.some(y=>y.includes('2035-2036')),'schema15 Add Student dialog offers the newly added academic year');
   await p2.locator('#cancelRecord').click();
  }finally{await c2.close();}
 }
 for(const theme of ['light','dark'])for(const width of [390,1440])for(const route of ['admin_settings.php','reports.php']){
  const {ctx,page}=await open('admin',theme,width,route);
  try{
   if(route==='admin_settings.php'){
    await settingsReady(page);
    check(await page.locator('[data-year-rows] tr').count()>0,`admin ${width} ${theme}: ZIP Settings lists catalog academic years`);
    check(await page.locator('a[href="admin_legal_policies.php"]').count()>0,`admin ${width} ${theme}: Settings links to Legal Policies`);
   }else{
    await page.waitForLoadState('networkidle');
    check(await page.locator('.report-history').isVisible(),`admin ${width} ${theme}: ZIP Report History renders`);
   }
   check(await page.locator('.prism-sidebar a[href="admin_settings.php"]').count()===1,`admin ${width} ${theme}: ${route} navigation offers Settings`);
   check(!/Historical Student Stage Records|Stage 5|Certificate of Approval \(Stage 4\)/.test(await page.locator('body').innerText()),`admin ${width} ${theme}: ${route} has no obsolete workflow presentation`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`admin ${width} ${theme}: ${route} fits the viewport`);
   await page.screenshot({path:path.join(out,`admin-${route.replace('.php','')}-${theme}-${width}.png`),fullPage:true,animations:'disabled'});
  }finally{await ctx.close();}
 }
 {
  const {ctx,page}=await open('adviser','light',1440,'dashboard.php');
  try{
   check(await page.locator('.prism-sidebar a[href="admin_settings.php"]').count()===0,'Adviser navigation has no Settings link');
   await page.goto(base+'/admin_settings.php');
   check(!page.url().endsWith('/admin_settings.php'),'Adviser cannot open Settings');
   const api=await s.adviser.get('/admin_settings_api.php?action=list');
   check(api.status()===403||api.status()===401,'Adviser Settings API is refused: '+api.status());
  }finally{await ctx.close();}
 }
 check(!errors.length,'Settings and Reports browser matrix has no JavaScript errors: '+JSON.stringify(errors));
 check(!failures.length,'Settings and Reports have no failed local resource/API responses: '+JSON.stringify(failures));
 fs.writeFileSync(path.join(review,'settings-page-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start),errors,failures},null,2));
};
