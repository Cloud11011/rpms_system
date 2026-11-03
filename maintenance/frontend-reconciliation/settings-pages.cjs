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
 const schema=String(h.sql("SELECT v FROM schema_meta WHERE k='schema_version'")[0].v);
 const yearOptions=async()=>{
  const {ctx,page}=await open('admin','light',1440,'admin_students.php');
  try{await page.locator('.student-result-card').first().waitFor();await page.locator('#addRecord').click();
   return await page.locator('#studentAcademicFields [data-academic="academic-year"] option').allTextContents();}finally{await ctx.close();}
 };
 // Schema 16 with the Settings extension: the full ZIP Academic Year and Communication Settings behaviour.
 if(schema==='16'){
  const setting=key=>{const row=h.sql('SELECT setting_value FROM prism_settings WHERE setting_key=?',[key])[0];return row?JSON.parse(row.setting_value):null;};
  const yearRow=year=>h.sql('SELECT available_for_registration a,is_current c FROM academic_year_settings WHERE year_label=?',[year])[0];
  const {ctx,page}=await open('admin','light',1440,'admin_settings.php');
  try{
   await settingsReady(page);
   check((await page.locator('[data-settings-status]').innerText()).trim()==='Changes apply to new registrations. Historical academic years remain in the catalog.','schema16 Settings: full academic-year management is available');
   check(await page.locator('#notificationTemplateBody').isEnabled()&&/subsequent workflow notifications/.test(await page.locator('[data-communication-status]').innerText()),'schema16 Settings: communication settings are editable');
   for(const year of ['2035-2036','2036-2037']){
    await page.locator('#newAcademicYear').fill(year);await page.locator('[data-add-year] button').click();
    await page.locator('[data-year-rows] tr',{hasText:year}).waitFor();
   }
   const row=year=>page.locator('[data-year-rows] tr',{hasText:year});
   await row('2035-2036').locator('[data-action="set-availability"]').click();
   await row('2035-2036').getByText('Unavailable',{exact:true}).waitFor();
   check(Number(yearRow('2035-2036').a)===0,'schema16 Settings: Make unavailable is stored');
  }finally{await ctx.close();}
  check(!(await yearOptions()).some(y=>y.includes('2035-2036')),'schema16 Add Student dialog omits a year unavailable for registration');
  const {ctx:c2,page:p2}=await open('admin','light',1440,'admin_settings.php');
  try{
   await settingsReady(p2);
   const row=year=>p2.locator('[data-year-rows] tr',{hasText:year});
   await row('2035-2036').locator('[data-action="set-availability"]').click();
   await row('2035-2036').getByText('Available',{exact:true}).waitFor();
   await row('2035-2036').locator('[data-action="set-current"]').click();
   await p2.locator('[data-current-year]',{hasText:'2035-2036'}).waitFor();
   check(Number(yearRow('2035-2036').c)===1&&Number(yearRow('2035-2036').a)===1,'schema16 Settings: availability restored and current year set');
   check(await row('2035-2036').locator('[data-action="remove"]').isDisabled(),'schema16 Settings: the current year cannot be deleted');
   await row('2036-2037').locator('[data-action="remove"]').click();
   const dialog=p2.getByRole('dialog',{name:'Delete Academic Year?'});await dialog.waitFor();
   await dialog.getByRole('button',{name:'Delete Academic Year'}).click();
   await row('2036-2037').getByText('Removed',{exact:true}).waitFor();
   check(h.sql("SELECT year_label FROM academic_year_removals WHERE year_label='2036-2037'").length===1,'schema16 Settings: Delete Academic Year records the removal through the ZIP confirmation');
   await p2.locator('#deadlineReminderIntervals').fill('5, 2');
   await p2.locator('[data-reminder-form] [type=submit]').click();
   await p2.locator('[data-communication-status][data-state="success"]').waitFor();
   check(JSON.stringify(setting('communication.deadline_reminder_intervals'))==='[5,2]','schema16 Settings: reminder intervals are saved');
   const key=await p2.locator('#notificationTemplate option').first().getAttribute('value');
   await p2.locator('#notificationTemplate').selectOption(key);
   await p2.locator('#notificationTemplateBody').fill('Synthetic heading for {recipient}: {message}');
   await p2.locator('[data-template-form] [type=submit]').click();
   await p2.locator('[data-communication-status][data-state="success"]').waitFor();
   check((setting('communication.notification_templates')||{})[key]==='Synthetic heading for {recipient}: {message}','schema16 Settings: a notification template is saved');
   await p2.locator('#notificationTemplateBody').fill('A template without the required placeholder');
   await p2.locator('[data-template-form] [type=submit]').click();
   await p2.locator('[data-communication-status][data-state="error"]').waitFor();
   check((setting('communication.notification_templates')||{})[key]==='Synthetic heading for {recipient}: {message}','schema16 Settings: a template without {message} is refused and nothing changes');
   await p2.screenshot({path:path.join(out,'admin-settings-schema16-1440.png'),fullPage:true,animations:'disabled'});
  }finally{await c2.close();}
  check((await yearOptions()).some(y=>y.includes('2035-2036')),'schema16 Add Student dialog offers the restored current year');
 }
 // One functional pass: the existing schema-15 Add Academic Year operation through the ZIP Settings panel.
 if(schema==='15'){
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
 // ZIP Account and Legal pages (adopted verbatim): identity, password rule feedback, policy documents.
 for(const role of ['admin','adviser'])for(const theme of ['light','dark'])for(const width of [390,1440]){
  const {ctx,page}=await open(role,theme,width,'account.php');
  try{
   await page.waitForFunction(()=>/@/.test(document.getElementById('accountEmail')?.textContent||''));
   check(true,`${role} ${width} ${theme}: ZIP Account shows the read-only identity`);
   await page.locator('#accountNewPassword').fill('Synthetic-Long-Pass-1');
   await page.locator('#accountConfirmPassword').fill('Synthetic-Long-Pass-1');
   check(await page.locator('[data-password-rule="length"].is-met').count()===1&&await page.locator('[data-password-rule="match"].is-met').count()===1,`${role} ${width} ${theme}: password rules react to input`);
   check(await page.locator('#accountPasswordForm [type=submit]').isDisabled(),`${role} ${width} ${theme}: password change stays gated without the current password`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${role} ${width} ${theme}: account.php fits the viewport`);
   await page.screenshot({path:path.join(out,`${role}-account-${theme}-${width}.png`),fullPage:true,animations:'disabled'});
  }finally{await ctx.close();}
 }
 for(const theme of ['light','dark'])for(const width of [390,1440]){
  const {ctx,page}=await open('admin',theme,width,'admin_legal_policies.php');
  try{
   check(await page.locator('.legal-settings-breadcrumb a[href="admin_settings.php"]').count()===1,`admin ${width} ${theme}: Legal Policies breadcrumb returns to Settings`);
   check(await page.locator('#policy-tab-privacy[aria-selected="true"]').count()===1,`admin ${width} ${theme}: Privacy tab selected by default`);
   await page.locator('#policy-tab-terms').click();
   check(await page.locator('#policy-tab-terms[aria-selected="true"]').count()===1&&await page.locator('#history-terms').isVisible(),`admin ${width} ${theme}: Terms tab shows its version history`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`admin ${width} ${theme}: admin_legal_policies.php fits the viewport`);
   await page.screenshot({path:path.join(out,`admin-legal-${theme}-${width}.png`),fullPage:true,animations:'disabled'});
  }finally{await ctx.close();}
 }
 for(const route of ['privacy.php','terms.php']){
  const ctx=await browser.newContext({viewport:{width:1440,height:1000}});
  try{
   const page=await ctx.newPage();page.on('pageerror',e=>errors.push({role:'public',route,error:String(e)}));
   await page.goto(base+'/'+route);
   check(await page.locator('h1.prism-policy-title').count()===1&&await page.locator('.prism-policy-document').count()===1,`public ${route}: ZIP policy document renders`);
  }finally{await ctx.close();}
 }
 check(!errors.length,'Settings and Reports browser matrix has no JavaScript errors: '+JSON.stringify(errors));
 // The schema-16 pass deliberately submits a template without {message}; only that refusal is expected.
 const unexpected=failures.filter(f=>!(f.status===422&&/admin_settings_api\.php\?action=save-template$/.test(f.url)));
 check(!unexpected.length,'Settings and Reports have no failed local resource/API responses: '+JSON.stringify(unexpected));
 fs.writeFileSync(path.join(review,'settings-page-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start),errors,failures},null,2));
};
