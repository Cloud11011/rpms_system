/* Presentation regression checks, invoked by ui-audit.cjs --batch1-only.
 * Uses the existing isolated templates/mocked APIs; never loads config.php. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const matrix = [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors,getRequests}) {
  const screens = [['dashboard.php','admin'],['research_adviser.php','adviser'],['student.php','student'],['calendar.php','admin'],['calendar.php','adviser'],['data_export.php','admin'],['reports.php','admin'],['admin_ai.php','admin'],['ierbprog.php','admin'],['admin_notifications.php','admin'],['admin_notifications.php','adviser'],['account_setup.php','student'],['complete_profile.php','student'],['complete_profile.php','adviser']];
  const shots = path.join(__dirname,'v10-batch1-results'); fs.mkdirSync(shots,{recursive:true});
  for (const [width,height] of matrix) for (const dark of [false,true]) for (const [file,viewer] of screens) {
    await navigate(file,width,dark,'populated',viewer);
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<500});
    await evaluate('new Promise(resolve=>setTimeout(resolve,220))');
    const label=`${file}/${viewer}/${width}x${height}/${dark?'dark':'light'}`;
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+': no page overflow');
    if (['calendar.php','data_export.php','reports.php','admin_ai.php','ierbprog.php'].includes(file))
      check(await evaluate('!document.querySelector(".topbar h1 + p")'),label+': heading description removed');
    if (viewer==='admin') {
      check(await evaluate('document.querySelectorAll("a[href=\\"logout.php\\"]").length===1 && document.querySelector("#prismAccountLinks a[href=\\"logout.php\\"]")?.textContent==="Log Out"'),label+': one shared profile-menu logout');
      await evaluate('document.querySelector("[data-prism-account-toggle]").click()');
      check(await evaluate('!document.getElementById("prismAccountLinks").hidden && document.querySelector("a[href=\\"logout.php\\"]").getClientRects().length>0'),label+': logout reachable');
      await evaluate('document.querySelector("[data-prism-account-toggle]").click()');
      check(await evaluate('getComputedStyle(document.querySelector(".prism-sidebar")).scrollbarWidth==="none" && getComputedStyle(document.querySelector(".prism-sidebar")).overflowY==="auto"'),label+': sidebar remains scrollable');
    }
    if (file==='dashboard.php') {
      check(await evaluate('!!document.querySelector("#dashboardSearch") && [...document.querySelectorAll(".step-item strong")].map(e=>e.id).join(",")==="initialStageCount,reviewStageCount,revisionStageCount,approvedStageCount"'),label+': working search and unchanged stage counters');
      check(await evaluate('getComputedStyle(document.querySelector(".pipeline-steps")).display==="grid" && [...document.querySelectorAll(".step-item")].every(e=>getComputedStyle(e).borderRadius==="12px")'),label+': stage summary cards');
      await evaluate('document.querySelectorAll(".prism-nav-group-toggle[aria-expanded=false]").forEach(e=>e.click());const s=document.querySelector(".prism-sidebar");s.style.height="240px";s.scrollTop=0;s.tabIndex=0;s.focus()');
      await keyPress('PageDown','PageDown',34);
      await waitFor('document.querySelector(".prism-sidebar").scrollTop>0');
      check(await evaluate('document.querySelector(".prism-sidebar").scrollTop>0'),label+': keyboard navigation scrolling');
      const point=await evaluate('(() => {const s=document.querySelector(".prism-sidebar");s.scrollTop=0;const r=s.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+120};})()');
      await command('Input.dispatchMouseEvent',{type:'mouseMoved',...point});
      await command('Input.dispatchMouseEvent',{type:'mouseWheel',...point,deltaY:120,deltaX:0});
      await waitFor('document.querySelector(".prism-sidebar").scrollTop>0');
      check(await evaluate('document.querySelector(".prism-sidebar").scrollTop>0'),label+': wheel navigation scrolling');
      if(width<500) {
        await evaluate('document.querySelector(".prism-sidebar").scrollTop=0');
        await command('Emulation.setTouchEmulationEnabled',{enabled:true});
        await command('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[point]});
        await command('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:point.x,y:point.y-80}]});
        await command('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
        await waitFor('document.querySelector(".prism-sidebar").scrollTop>0');
        check(await evaluate('document.querySelector(".prism-sidebar").scrollTop>0'),label+': touch navigation scrolling');
        await command('Emulation.setTouchEmulationEnabled',{enabled:false});
      }
      await evaluate('document.querySelector(".prism-sidebar").style.removeProperty("height")');
    }
    if (file==='research_adviser.php') {
      const metrics=await evaluate('(() => { const a=[...document.querySelectorAll(".adviser-document-actions .adviser-button")]; return a.length===2 && a.every(e=>e.classList.contains("adviser-button")) && a[1].classList.contains("prism-btn-primary") && Math.abs(a[0].getBoundingClientRect().height-a[1].getBoundingClientRect().height)<1 && getComputedStyle(a[0].parentElement).gap==="8px"; })()');
      check(metrics,label+': document primary/secondary actions retain equal height and gap',JSON.stringify(await evaluate('[...document.querySelectorAll(".adviser-document-actions .adviser-button")].map(e=>({cls:e.className,height:e.getBoundingClientRect().height,gap:getComputedStyle(e.parentElement).gap}))')));
      check(await evaluate('!!document.querySelector("#adviserQueueSearch")'),label+': working submission search remains');
    }
    if (file==='student.php') {
      check(await evaluate('document.querySelector(".welcome-card h2").textContent.startsWith("Welcome, ") && !!document.getElementById("welcomeName")'),label+': authoritative welcome name retained');
      for (const section of ['documents','progress','calendar']) {
        await evaluate(`document.querySelector('[data-page="${section}"]').click()`);
        await waitFor(`document.querySelector('.portal-page.active').dataset.section==='${section}'`);
        check(await evaluate('document.getElementById("pageSubtitle").hidden'),label+': '+section+' description absent');
      }
      await evaluate('document.querySelector("[data-page=documents]").click()');
      await waitFor('document.querySelector(".portal-page.active").dataset.section==="documents" && document.querySelector(".student-document-actions").getBoundingClientRect().width>0');
      await evaluate('document.querySelector(".student-document-actions .prism-action-trigger").click()');
      check(await evaluate('(() => {const a=[...document.querySelectorAll(".student-document-actions a")],r=a.map(e=>e.getBoundingClientRect());return a.length>=2 && r.every(e=>e.height>=(innerWidth<601?44:38)) && Math.abs(r[0].height-r[1].height)<1 && (r[1].left-r[0].right>=7 || r[1].top-r[0].bottom>=7) && a[0].href.includes("action=file") && a[1].href.includes("download=1");})()'),label+': distinct preview/download hit areas and endpoints');
      await evaluate('document.querySelector(".student-document-actions a").focus()');
      check(await evaluate('parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=2'),label+': visible document focus');
      await keyPress('Escape','Escape',27);
      await evaluate('document.querySelector("[data-page=dashboard]").click()');
      await waitFor('document.querySelector(".portal-page.active").dataset.section==="dashboard"');
    }
    if (['calendar.php','student.php'].includes(file)) check(await evaluate('!document.querySelector("#officialDeadlinePanel .panel-title p")'),label+': deadline description removed');
    if (file==='calendar.php') check(await evaluate('document.querySelector(".calendar-instructions").textContent.includes("stored only in this browser")'),label+': browser storage/workflow instruction retained');
    if (['dashboard.php','reports.php','data_export.php'].includes(file)) {
      const selector=file==='dashboard.php'?'.header-actions a[href*="export_csv"]':file==='reports.php'?'#exportExcel':'.data-export-card .prism-btn';
      check(await evaluate(`(() => {const lum=c=>{const rgb=c.match(/[0-9.]+/g).slice(0,3).map(v=>{v=Number(v)/255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4});return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2]};const contrast=(a,b)=>{a=lum(a);b=lum(b);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)};const a=[...document.querySelectorAll(${JSON.stringify(selector)})];return a.length>0 && a.every(e=>e.classList.contains('is-secondary') && !e.disabled && getComputedStyle(e).opacity==='1' && contrast(getComputedStyle(e).color,getComputedStyle(e).backgroundColor)>=4.5 && e.getBoundingClientRect().height>=42);})()`),label+': enabled theme-aware CSV controls');
    }
    if (file==='admin_notifications.php') {
      check(await evaluate('!document.body.textContent.includes("Gmail API") && [...document.querySelectorAll(".history-item small")].some(e=>e.textContent==="Sent")'),label+': provider diagnostics replaced by recorded status');
      await evaluate('document.querySelector("[data-notice-detail]").click()');
      check(await evaluate('document.getElementById("notificationDetailDelivery").textContent==="Sent"'),label+': provider-neutral detail');
      await keyPress('Escape','Escape',27);
    }
    if (file==='account_setup.php') {
      for (const id of ['setupPassword','setupConfirm']) {
        check(await evaluate(`document.getElementById('${id}').type==='password'`),label+': '+id+' initially hidden');
        await evaluate(`document.querySelector('[aria-controls="${id}"]').focus()`);
        await keyPress('Enter','Enter',13);
        check(await evaluate(`document.getElementById('${id}').type==='text' && document.querySelector('[aria-controls="${id}"]').getAttribute('aria-label').startsWith('Hide ') && document.querySelector('[aria-controls="${id}"]').getAttribute('aria-pressed')==='true'`),label+': keyboard reveal and accessible state');
        await keyPress('Space','Space',32);
        // Native Space activation is platform dependent in CDP; complete it with a click if needed.
        if(await evaluate(`document.getElementById('${id}').type==='text'`)) await evaluate(`document.querySelector('[aria-controls="${id}"]').click()`);
        check(await evaluate(`document.getElementById('${id}').type==='password' && document.querySelector('[aria-controls="${id}"]').getBoundingClientRect().width>=44`),label+': hide and mobile target');
      }
    }
    if (['dashboard.php','research_adviser.php','student.php'].includes(file)) {
      check(await evaluate('document.querySelectorAll("details.prism-resource").length===3 && !document.querySelector(".prism-resources-heading p")'),label+': three native resource disclosures');
      const posts=getRequests().filter(r=>r.method==='POST').length;
      await evaluate('document.querySelector("[data-resource=ierb] summary").focus()');
      await keyPress('Enter','Enter',13);
      check(await evaluate('document.querySelector("[data-resource=ierb]").open && document.querySelector("[data-resource=ierb] a").rel.includes("noopener")'),label+': keyboard IERB disclosure');
      await keyPress('Enter','Enter',13);
      check(getRequests().filter(r=>r.method==='POST').length===posts,label+': disclosure does not write data');
    }
    await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
    check(await evaluate('[...document.querySelectorAll(".student-document-actions .action-btn,.adviser-document-actions .adviser-button,.prism-resource summary,.onboarding-password button,#exportExcel,.header-actions a[href*=export_csv],.data-export-card .prism-btn")].every(e=>getComputedStyle(e).transitionDuration==="0s")'),label+': reduced motion on touched controls');
    await command('Emulation.setEmulatedMedia',{features:[]});
    if ([1920,390].includes(width) && ['dashboard.php','research_adviser.php','student.php','account_setup.php'].includes(file)) {
      await evaluate('scrollTo(0,0)');
      const shot=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
      fs.writeFileSync(path.join(shots,`${file}-${viewer}-${width}-${dark?'dark':'light'}.png`),Buffer.from(shot.data,'base64'));
    }
  }
  for (const [file,viewer,selector] of [['admin_students.php','admin','#recordSearch'],['admin_advisers.php','admin','#recordSearch'],['documents.php','admin','#documentSearch'],['ierbprog.php','admin','#ierbSearch']]) {
    await navigate(file,375,false,'populated',viewer);
    check(await evaluate(`!!document.querySelector('${selector}') && !document.querySelector('${selector}').disabled`),file+': management search retained');
  }
  check(errors.length===0,'No batch 1 browser exceptions',errors.join(' | '));
}
module.exports={run};
