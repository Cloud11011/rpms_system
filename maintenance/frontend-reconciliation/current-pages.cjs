'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
module.exports=async(h,browser)=>{
 const {base,sessions:s,check,review}=h,start=h.checks.length,errors=[],failures=[];
 for(const role of ['admin','adviser'])for(const theme of ['light','dark'])for(const width of [390,1440])for(const route of ['admin_students.php','documents.php']){
  const ctx=await browser.newContext({viewport:{width,height:1000},storageState:await s[role].storageState(),reducedMotion:'reduce'});
  try{
   const identity=crypto.createHash('sha256').update(role+'|'+(role==='admin'?1:2)).digest('hex');
   await ctx.addInitScript(({role,theme,identity})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(`prismTour.${role}.${identity}.v1`,'seen');},{role,theme,identity});
   await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
   const page=await ctx.newPage();page.on('pageerror',e=>errors.push({role,theme,width,route,error:String(e)}));
   page.on('response',r=>{if(r.status()>=400&&new URL(r.url()).hostname==='127.0.0.1')failures.push({role,route,status:r.status(),url:r.url()});});
   await page.goto(base+'/'+route);
   if(role==='admin'&&await page.locator('.prism-sidebar-backdrop:not([hidden])').count())await page.locator('#prismSidebarToggle').click();
   if(route==='admin_students.php'){
    await page.locator('.student-result-card').first().waitFor();
    check(await page.locator('.student-result-card').count()>0,`${role} ${width} ${theme}: ZIP Student Record cards load`);
    const stages=await page.locator('#recordFilters_stage option').evaluateAll(nodes=>nodes.filter(n=>n.value).map(n=>n.value));
    check(stages.length===4&&stages.includes('ierb_completed'),`${role} ${width} ${theme}: Student Records show four semantic stage filters`);
    await page.locator('#addRecord').click();
    check(await page.locator('#recordModal').isVisible(),`${role} ${width} ${theme}: approved Add Student dialog opens`);
    const choices=await page.locator('#stage option').allTextContents();
    check(!choices.some(x=>/Stage 5|Historical|Certificate of Approval/.test(x)),`${role} ${width} ${theme}: profile dialog has no obsolete workflow choices`);
    await page.locator('#cancelRecord').click();
   }else{
    await page.locator('#documentsTableBody[aria-busy="false"]').waitFor();
    check(await page.locator('#documentsTableBody .prism-record-card').count()>0,`${role} ${width} ${theme}: ZIP document cards load`);
    await page.locator('#uploadDocumentButton').click();
    await page.locator('#uploadModal.show').waitFor();
    check(await page.locator('#upcFileInput').count()===1&&await page.locator('#documentStage').count()===0,`${role} ${width} ${theme}: ZIP upload dialog uses the current V14 checklist`);
    if(role==='admin'){
     await page.locator('#documentStudent').selectOption('5');
     await page.getByText('Form 11 Review Checklist',{exact:true}).last().waitFor();
     check(await page.locator('#uploadModal [data-upc-req-list] > *').count()===13,`${role} ${width} ${theme}: Initial Submission upload lists thirteen requirements`);
    }
    await page.locator('#uploadModal [data-close="uploadModal"]').first().click();
   }
   check(!/Historical Student Stage Records|Stage 5|Historical record|Certificate of Approval \(Stage 4\)/.test(await page.locator('body').innerText()),`${role} ${width} ${theme}: current ${route} has no obsolete workflow presentation`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${role} ${width} ${theme}: current ${route} fits the viewport`);
   const out=path.join(review,'current-v14-screenshots');fs.mkdirSync(out,{recursive:true});
   await page.screenshot({path:path.join(out,`${role}-${route.replace('.php','')}-${theme}-${width}.png`),fullPage:true,animations:'disabled'});
  }finally{await ctx.close();}
 }
 check(!errors.length,'Student Records and Documents browser matrix has no JavaScript errors: '+JSON.stringify(errors));
 check(!failures.length,'Student Records and Documents have no failed local resource/API responses: '+JSON.stringify(failures));
 fs.writeFileSync(path.join(review,'current-page-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start),errors,failures},null,2));
};
