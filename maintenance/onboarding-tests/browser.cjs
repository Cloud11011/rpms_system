const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
module.exports=async h=>{
 const {sql,check,base,sessions:s}=h,start=h.checks.length;
 const ev=path.join(h.review,'onboarding');fs.mkdirSync(ev,{recursive:true});
 let serial=90000000+Number(Date.now().toString().slice(-7))*100;
 const eligible=sql('SELECT submission_holder_id AS id FROM research_projects WHERE completed_at IS NULL ORDER BY id LIMIT 1')[0];
 if(!eligible)throw Error('An isolated unfinished canonical group is required for browser matching checks.');
 const duplicate=eligible;
 const project=id=>sql('SELECT p.* FROM research_projects p JOIN research_project_members m ON m.project_id=p.id WHERE m.student_id=?',[id])[0];
 const assign=id=>sql('SELECT * FROM research_onboarding_assignments WHERE student_id=?',[id])[0];
 // Existing desktop/mobile forms, both themes, focus, group matching and a real browser save.
 const browser=await h.playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME,headless:true});
 try{for(const width of [1440,390])for(const theme of ['light','dark']){
  const ctx=await browser.newContext({viewport:{width,height:1000},storageState:await s.admin.storageState(),reducedMotion:'reduce'});const page=await ctx.newPage();
  await page.addInitScript(({theme,key})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(key,'seen');},{theme,key:'prismTour.admin.'+crypto.createHash('sha256').update('admin|1').digest('hex')+'.v1'});await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,route=>route.fulfill({contentType:'text/css',body:''}));await page.goto(base+'/admin_students.php');await page.mouse.move(width-20,60);await page.keyboard.press('Escape');await page.locator('#addRecord').click();
  check(await page.locator('#recordName').evaluate(el=>el===document.activeElement),'Add Student focuses its first field at '+width+' '+theme);
  await page.locator('#recordName').fill('Browser Onboarding '+width+' '+theme);await page.locator('#accountId').fill(String(++serial));await page.locator('#recordEmail').fill('browser'+serial+'@example.test');
  await page.locator('#research').fill('Browser research '+serial);await page.locator('#student-profile-tab-2').click();
  await page.locator('#academicUnit').selectOption('amt');await page.locator('#course').selectOption('bsit');await page.locator('#yearLevel').selectOption('3rd Year');await page.locator('#academicYear').selectOption('2026-2027');
  await page.locator('#student-profile-tab-3').click();await page.waitForFunction(()=>[...document.querySelector('#group').options].some(o=>o.value==='__create__'));
  await page.locator('#group').selectOption('__create__');await page.locator('#adviser').selectOption('2');
  check(await page.locator('#research').getAttribute('readonly')===null,'New group retains an editable full title at '+width+' '+theme);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Onboarding has no horizontal page overflow at '+width+' '+theme);
  await page.screenshot({path:path.join(ev,`add-student-${width}-${theme}.png`),fullPage:false});
  if(width===1440&&theme==='light'){
    const pendingResponse=page.waitForResponse(r=>r.url().includes('students_api.php?action=save')&&r.request().method()==='POST');
    await page.locator('#recordForm [type=submit]').click();const stored=await (await pendingResponse).json();check(stored.ok&&assign(stored.id).state==='pending','Real Chromium Add Student Save persists a pending approved assignment');
    const copied=page.getByRole('button',{name:'I copied it',exact:true});if(stored.temporaryPassword){await copied.waitFor({state:'visible'});await copied.click();}
  }else{await page.locator('#cancelRecord').click();check(await page.locator('#addRecord').evaluate(el=>el===document.activeElement),'Cancel restores Add Student focus at '+width+' '+theme);}
  await page.locator('#inviteAccount').click();await page.locator('#inviteEmail').fill('browser-invite'+serial+'@example.test');
  await page.locator('#inviteAcademicFields [data-academic="unit"]').selectOption('amt');await page.locator('#inviteAcademicFields [data-academic="program"]').selectOption('bsit');await page.locator('#inviteAcademicFields [data-academic="year"]').selectOption('2nd Year');await page.locator('#inviteAcademicFields [data-academic="academic-year"]').selectOption('2026-2027');
  await page.waitForFunction(()=>[...document.querySelector('#inviteGroup').options].some(o=>o.value==='__create__'));await page.locator('#inviteGroup').selectOption('__create__');await page.locator('#inviteResearch').fill('Browser invited research '+serial);await page.locator('#inviteAdviser').selectOption('2');
  await page.screenshot({path:path.join(ev,`invite-student-${width}-${theme}.png`),fullPage:false});
  await page.locator('#inviteGroup').selectOption('project:'+project(duplicate.id).id);
  check(await page.locator('#inviteResearch').evaluate(el=>el.readOnly)&&await page.locator('#inviteAdviser').isDisabled(),'Join Existing Group locks the authoritative title and Adviser at '+width+' '+theme);
  await page.screenshot({path:path.join(ev,`join-group-${width}-${theme}.png`),fullPage:false});
  await page.locator('#inviteGroup').selectOption('__create__');await page.locator('#inviteResearch').fill('Browser invited research '+serial);
  check(await page.locator('#inviteResearch').evaluate(el=>el.required),'Invite dialog exposes the required new-group title at '+width+' '+theme);
  if(width===1440&&theme==='light'){
    const sentResponse=page.waitForResponse(r=>r.url().includes('account_invitation_api.php?action=invite')&&r.request().method()==='POST');
    await page.locator('#inviteForm [type=submit]').click();const stored=await (await sentResponse).json();check(stored.ok&&!project(stored.id),'Real Chromium Invite Student submits a recoverable assignment without an active project');
  }
  await page.locator('#cancelInvite').click();check(await page.locator('#inviteAccount').evaluate(el=>el===document.activeElement),'Invite Cancel restores keyboard focus at '+width+' '+theme);
  await ctx.close();
 }}finally{await browser.close();}

 fs.writeFileSync(path.join(ev,'browser-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start)},null,2));
};
