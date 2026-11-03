'use strict';
// Batch 5: ZIP Generated Reports, AI Progress Reports and Data Export interactions on the completed V14 fixture.
// The AI provider is the fixture's synthetic curl stub; no external service is contacted.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
module.exports=async(h,browser)=>{
 const {base,sessions:s,check,review,sql}=h,start=h.checks.length,errors=[],failures=[];
 const out=path.join(review,'current-v14-screenshots');fs.mkdirSync(out,{recursive:true});
 const open=async(route,{width=1440,theme='light'}={})=>{
  const ctx=await browser.newContext({viewport:{width,height:1000},storageState:await s.admin.storageState(),reducedMotion:'reduce',acceptDownloads:true});
  const identity=crypto.createHash('sha256').update('admin|1').digest('hex');
  await ctx.addInitScript(({theme,identity})=>{localStorage.setItem('prismTheme',theme);localStorage.setItem(`prismTour.admin.${identity}.v1`,'seen');},{theme,identity});
  await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push({route,error:String(e)}));
  page.on('response',r=>{if(r.status()>=400&&new URL(r.url()).hostname==='127.0.0.1')failures.push({route,status:r.status(),url:r.url()});});
  await page.goto(base+'/'+route);
  if(await page.locator('.prism-sidebar-backdrop:not([hidden])').count())await page.locator('#prismSidebarToggle').click();
  return {ctx,page};
 };
 const reportCount=()=>Number(sql('SELECT COUNT(*) n FROM reports')[0].n);
 const pdf=async id=>{const r=await s.admin.get('/reports_api.php?action=file&id='+encodeURIComponent(id));const b=await r.body();return r.status()===200&&/application\/pdf/.test(r.headers()['content-type']||'')&&b.subarray(0,5).toString()==='%PDF-';};
 const downloadBytes=async download=>fs.readFileSync(await download.path());
 const confirm=async(page,label)=>{const dialog=page.locator('.report-delete-confirmation');await dialog.waitFor();await dialog.getByRole('button',{name:label}).click();};

 // Generated Reports: the ZIP Student Progress Report, project track sheet and institutional report.
 {
  const {ctx,page}=await open('reports.php');
  try{
   await page.locator('#reportTableBody').waitFor();await page.waitForLoadState('networkidle');
   const before=reportCount();
   await page.locator('#openStudentReport').click();
   await page.locator('#reportStudent option').first().waitFor({state:'attached'});
   const holder=sql('SELECT submission_holder_id id FROM research_projects ORDER BY id LIMIT 1')[0].id;
   await page.locator('#reportStudent').selectOption(String(holder));
   let response=page.waitForResponse(r=>r.url().includes('reports_api.php?action=generate'));
   let download=page.waitForEvent('download');
   await page.locator('#studentReportForm .generate-report-button').click();
   const generated=await (await response).json();
   check(generated.ok===true&&generated.report&&await pdf(generated.report.id),'ZIP Student Progress Report generates the Student\'s V14 project track sheet as a PDF');
   check((await downloadBytes(await download)).subarray(0,5).toString()==='%PDF-','Student Progress Report downloads automatically');
   check(reportCount()===before+1&&await page.locator('#reportTableBody').getByText(generated.report.name||'',{exact:false}).count()>0,'Generated Student report appears in Report History');
   const noProject=sql("SELECT s.id FROM students s WHERE s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM research_project_members m WHERE m.student_id=s.id) LIMIT 1");
   if(noProject.length){
    const refused=await h.post(s.admin,'generate',{type:'Student Report',studentId:Number(noProject[0].id)},'reports_api.php');
    check(refused.status===409&&reportCount()===before+1,'A Student without a V14 project gets a clear refusal and no report');
   }
   const legacy=await h.post(s.admin,'generate',{type:'Progress Report'},'reports_api.php');
   check(legacy.status===410,'Legacy Student-stage progress report generation stays retired');
   await page.getByRole('button',{name:'Generate IERB Project Track Sheet'}).click();
   await page.locator('#projectModal').waitFor({state:'visible'});
   check(/distinct research projects/.test(await page.locator('#projectCounts').innerText()),'Project track sheet dialog reports institution counts');
   response=page.waitForResponse(r=>r.url().includes('reports_api.php?action=project_report'));download=page.waitForEvent('download');
   await page.locator('#projectReportForm button[type=submit], #projectReportForm button').last().click();
   const track=await (await response).json();
   check(track.ok===true&&await pdf(track.report.id)&&(await downloadBytes(await download)).subarray(0,5).toString()==='%PDF-','IERB Project Track Sheet generates, downloads and stores a PDF');
   response=page.waitForResponse(r=>r.url().includes('reports_api.php?action=project_aggregate'));download=page.waitForEvent('download');
   await page.getByRole('button',{name:'Generate Institutional Project Report'}).click();
   const aggregate=await (await response).json();
   check(aggregate.ok===true&&await pdf(aggregate.report.id)&&(await downloadBytes(await download)).subarray(0,5).toString()==='%PDF-','Institutional Project Report generates, downloads and stores a PDF');
   check(reportCount()===before+3,'Three V14 reports were recorded');
   // CSV export of report history, with spreadsheet-formula neutralisation.
   sql("INSERT INTO reports (id,title,type,filename,generated_by,generated_at) SELECT 'b5csv0000000000000000001','=HYPERLINK(\"x\")','Project Report','b5csv.pdf',generated_by,NOW() FROM reports LIMIT 1");
   download=page.waitForEvent('download');await page.locator('#exportExcel').click();
   const csv=(await downloadBytes(await download)).toString('utf8');fs.writeFileSync(path.join(review,'report-history-export.csv'),csv);
   // fputcsv encloses fields containing spaces or quotes; compare unquoted cells.
   const cells=line=>[...line.matchAll(/("(?:[^"]|"")*"|[^,]*)(?:,|$)/g)].map(m=>m[1].replace(/^"|"$/g,'').replace(/""/g,'"')).slice(0,-1);
   const lines=csv.replace(/^﻿/,'').split(/\r?\n/).filter(Boolean);
   check(csv.charCodeAt(0)===0xfeff&&cells(lines[0]).join('|')==='Report Name|Date Generated|Type','Report History CSV starts with a BOM and the header row: '+JSON.stringify(lines[0]));
   check(lines.slice(1).some(l=>cells(l)[0]===track.report.name),'Report History CSV lists the generated reports');
   check(lines.slice(1).some(l=>cells(l)[0]==="'=HYPERLINK(\"x\")"),'Report History CSV neutralises formula-like titles: '+JSON.stringify(lines.filter(l=>l.includes('HYPERLINK'))));
   sql("DELETE FROM reports WHERE id='b5csv0000000000000000001'");
   await page.reload();await page.waitForLoadState('networkidle');
   // Sorting, single delete and bulk delete through the ZIP confirmation dialog.
   await page.locator('#reportSort').selectOption('oldest');await page.waitForLoadState('networkidle');
   check(await page.locator('#reportTableBody tr').count()>=3,'Report History re-renders in another sort order');
   await page.locator('#reportSort').selectOption('newest');await page.waitForLoadState('networkidle');
   const firstRow=page.locator('#reportTableBody tr').filter({hasText:aggregate.report.name}).first();
   await firstRow.locator('[data-report-select]').check();
   check(/1 selected/.test(await page.locator('#reportSelectedCount').innerText())&&await page.locator('#reportDeleteSelected').isEnabled(),'Selecting one report enables Delete selected');
   await page.locator('#reportDeleteSelected').click();
   const dialog=page.locator('.report-delete-confirmation');await dialog.waitFor();
   await dialog.getByRole('button',{name:'No, keep report'}).click();
   check(reportCount()===before+3,'Keeping the report deletes nothing');
   await page.locator('#reportDeleteSelected').click();await confirm(page,'Yes, delete report');
   await page.waitForFunction(()=>/0 selected/.test(document.getElementById('reportSelectedCount').textContent));
   check(reportCount()===before+2&&!sql('SELECT id FROM reports WHERE id=?',[aggregate.report.id]).length,'Confirmed deletion removes exactly the selected report');
   await page.locator('#reportSelectPage').check();
   const selected=Number((await page.locator('#reportSelectedCount').innerText()).match(/\d+/)[0]);
   await page.locator('#reportDeleteSelected').click();await confirm(page,`Yes, delete ${selected} reports`);
   await page.waitForFunction(()=>/0 selected/.test(document.getElementById('reportSelectedCount').textContent));
   check(reportCount()===before+2-selected,'Bulk deletion removes every report on the page and nothing else');
   await page.screenshot({path:path.join(out,'admin-reports-interactions-1440.png'),fullPage:true,animations:'disabled'});
  }finally{await ctx.close();}
 }

 // AI Progress Reports: generation through the synthetic provider, history, view/download links and bulk deletion.
 {
  const {ctx,page}=await open('admin_ai.php');
  try{
   await page.waitForLoadState('networkidle');
   const ai=()=>Number(sql("SELECT COUNT(*) n FROM reports WHERE type IN ('AI Summarized Report','AI Full Report')")[0].n);
   const before=ai();
   for(const [button,label] of [['#generateSummarizedReport','summarized'],['#generateFullReport','full']]){
    const response=page.waitForResponse(r=>r.url().includes('reports_api.php?action=ai_report'));const download=page.waitForEvent('download');
    await page.locator(button).click();
    const body=await (await response).json();
    check(body.ok===true&&await pdf(body.report.id)&&(await downloadBytes(await download)).subarray(0,5).toString()==='%PDF-','AI '+label+' report generates and downloads a PDF');
   }
   await page.waitForLoadState('networkidle');
   check(ai()===before+2&&await page.locator('#aiHistory a[href*="action=file"]').count()>=4,'AI Report History lists both reports with view and download links');
   await page.locator('#aiSelectPage').check();
   const selected=Number((await page.locator('#aiSelectedCount').innerText()).match(/\d+/)[0]);
   await page.locator('#aiDeleteSelected').click();await confirm(page,selected===1?'Yes, delete report':`Yes, delete ${selected} reports`);
   await page.waitForFunction(()=>/0 selected/.test(document.getElementById('aiSelectedCount').textContent));
   check(ai()===before+2-selected&&selected>=2,'AI history bulk deletion removes the selected reports');
   await page.screenshot({path:path.join(out,'admin-ai-interactions-1440.png'),fullPage:true,animations:'disabled'});
  }finally{await ctx.close();}
 }

 // Data Export: every ZIP export card downloads CSV through the V14 exporters.
 {
  const {ctx,page}=await open('data_export.php');
  try{
   const links=await page.locator('[data-export-download]').evaluateAll(a=>a.map(x=>x.getAttribute('href')));
   check(links.length===4,'Data Export offers four exports');
   for(const href of links){
    const download=page.waitForEvent('download');
    await page.locator(`[data-export-download][href="${href}"]`).click();
    const text=(await downloadBytes(await download)).toString('utf8').replace(/^﻿/,'');
    const header=text.split(/\r?\n/)[0];
    check(header.split(',').length>=3&&!/(^|,)[=+@-]/.test(text.split(/\r?\n/).slice(1).join('\n').replace(/"[^"]*"/g,'')),'Export '+href.split('=').pop()+' downloads CSV with a header row and no unquoted formula cells');
    await page.waitForTimeout(150);
   }
  }finally{await ctx.close();}
 }

 // Report pages and APIs remain Admin-only.
 for(const route of ['reports.php','admin_ai.php','data_export.php']){
  const ctx=await browser.newContext({storageState:await s.adviser.storageState()});
  try{const page=await ctx.newPage();await page.goto(base+'/'+route);check(!page.url().endsWith('/'+route),'Adviser cannot open '+route);}finally{await ctx.close();}
 }
 for(const url of ['/reports_api.php?action=list','/data_exports_api.php?action=students']){
  const r=await s.adviser.get(url);check([401,403].includes(r.status()),'Adviser is refused '+url+': '+r.status());
 }
 check(!errors.length,'Reports and export pages have no JavaScript errors: '+JSON.stringify(errors));
 const unexpected=failures.filter(f=>!/action=generate/.test(f.url));
 check(!unexpected.length,'Reports and export pages have no failed local responses: '+JSON.stringify(unexpected));
 fs.writeFileSync(path.join(review,'reports-page-results.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start),errors,failures},null,2));
};
