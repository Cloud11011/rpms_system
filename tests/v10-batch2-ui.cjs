'use strict';
const fs = require('node:fs'), path = require('node:path'), os = require('node:os');
const matrix = [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
const attack = '<img src=x onerror="window.__fixtureXss=1">';
let detailError = false;
const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; };
function mockDeadlines(action, query, scenario, role) {
  if (scenario === 'error') return {ok:false,message:'Synthetic deadline failure'};
  if (action === 'dashboard_day' && detailError) return {ok:false,message:'Synthetic detail failure'};
  if (action === 'dates') return {ok:true,dates:scenario==='empty'||query.from>today()||query.to<today()?[]:[{deadline_date:today(),total:12}]};
  const page = Number(query.page||1);
  const rows = scenario==='empty'?[]:Array.from({length:12},(_,i)=>({id:i+1,title:i===0?attack:`Official deadline ${i+1}`,deadline_date:query.date,target_scope:i===1?'all':'groups',status:'Active',groups:i===1?[]:['AMT-BSIT-Y2-2627-G01']}));
  return {ok:true,deadlines:rows.slice((page-1)*10,page*10),total:rows.length,page,pages:2};
}
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors,getRequests,setDelay}) {
  const shots=fs.mkdtempSync(path.join(os.tmpdir(),'prism-batch2-ui-'));
  const clickText = text => evaluate(`(() => { const b=[...document.querySelectorAll('.prism-readonly-dialog button')].find(b=>b.textContent===${JSON.stringify(text)}); if(!b)throw Error('Missing control'); b.click(); })()`);
  const bounds = () => evaluate(`(() => {const d=document.querySelector('.prism-readonly-dialog'),r=d.getBoundingClientRect(); return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1&&d.scrollWidth<=d.clientWidth+1;})()`);
  for (const [width,height] of matrix) for (const dark of [false,true]) for (const [role,file] of [['admin','dashboard.php'],['adviser','research_adviser.php'],['student','student.php']]) {
    await navigate(file,width,dark,'populated',role);
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<500});
    const label=`${role}/${width}x${height}/${dark?'dark':'light'}`;
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+': no page overflow');
    check(await evaluate('!document.querySelector(".prism-readonly-dialog")'),label+': no automatic overlay flash');
    if(role!=='student') {
      await waitFor('document.querySelector("[data-official-deadline=true]")');
      check(await evaluate('[...document.querySelectorAll("[data-official-deadline=true]")].every(c=>c.querySelectorAll(".prism-deadline-marker:not([hidden])").length===1 && c.getAttribute("aria-label").includes("12 official deadlines"))'),label+': one labeled marker per dense date');
      await evaluate('document.querySelector("[data-official-deadline=true]").focus()');
      await keyPress('Enter','Enter',13);
      await waitFor('document.querySelectorAll(".prism-deadline-detail").length===12');
      check(await bounds(),label+': multi-deadline dialog fits viewport');
      check(await evaluate('document.querySelector(".prism-readonly-dialog").getBoundingClientRect().left>=15'),label+': dialog has viewport margin');
      check(await evaluate('document.querySelector(".prism-readonly-dialog").contains(document.activeElement)'),label+': focus moves into dialog');
      check(await evaluate(`document.querySelector('.prism-readonly-dialog').textContent.includes(${JSON.stringify(attack)}) && !document.querySelector('.prism-readonly-dialog img') && !window.__fixtureXss`),label+': deadline title stays literal');
      check(await evaluate('document.querySelectorAll(".prism-deadline-detail dd").length===36 && !document.querySelector(".prism-readonly-dialog :is(form,input,select,textarea)")'),label+': all details are view-only');
      check(await evaluate('[...document.querySelectorAll(".prism-readonly-dialog button")].every(b=>b.textContent==="Close")'),label+': no deadline mutation actions');
      await keyPress('Tab','Tab',9);
      check(await evaluate('document.querySelector(".prism-readonly-dialog").contains(document.activeElement)'),label+': dialog contains keyboard focus');
      if(width===375) {const png=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(shots,`${role}-${dark?'dark':'light'}-deadlines.png`),Buffer.from(png.data,'base64'));}
      await keyPress('Escape','Escape',27);
      await waitFor('!document.querySelector(".prism-readonly-dialog")');
      check(await evaluate('document.activeElement.dataset.officialDeadline==="true"'),label+': Escape restores date focus');
      await keyPress(' ','Space',32);
      await waitFor('document.querySelectorAll(".prism-deadline-detail").length===12');
      await clickText('Close');
      await waitFor('!document.querySelector(".prism-readonly-dialog")');
      check(!getRequests().some(r=>r.file==='calendar_deadlines_api.php'&&r.method!=='GET'),label+': every deadline request uses GET');
    }
    await evaluate(`document.querySelector(${JSON.stringify(role==='student'?'#portalProfileToggle':'[data-prism-account-toggle]')}).click(); document.querySelector('[data-prism-tour]').focus();`);
    await keyPress('Enter','Enter',13);
    await waitFor('document.querySelector(".prism-readonly-dialog")');
    await clickText('Start Tour');
    check(await bounds(),label+': tour fits collapsed/mobile viewport');
    const titles=[];
    for(let i=0;i<20;i++) {
      titles.push(await evaluate('document.querySelector(".prism-readonly-dialog h2").textContent'));
      check(await evaluate('document.querySelector(".prism-tour-progress").textContent.includes("Step") && document.querySelector(".prism-dialog-actions").getBoundingClientRect().bottom<=innerHeight+1'),label+': step and reachable controls');
      if(i===1) {await clickText('Back');check(await evaluate('document.querySelector(".prism-tour-progress").textContent.startsWith("Step 1")'),label+': Back works');await clickText('Next');}
      if(await evaluate('[...document.querySelectorAll(".prism-readonly-dialog button")].some(b=>b.textContent==="Finish")')) {await clickText('Finish');break;}
      await clickText('Next');
    }
    await waitFor('!document.querySelector(".prism-readonly-dialog")');
    check(await evaluate(`localStorage.getItem('prismTour.${role}.v1')==='finished'`),label+': role-specific completion recorded');
    check(!titles.some(t=>t.includes('Staff Registration')),label+': no future feature');
    if(role!=='admin')check(!titles.some(t=>['Research Advisers','Generated Reports','AI Progress Reports','Data Export'].includes(t)),label+': no Admin-only steps');
    await evaluate('document.querySelector("[data-prism-guide]").focus()');
    await keyPress('Enter','Enter',13);
    check(await bounds(),label+': navigation guide fits');
    const guideLabels=await evaluate('[...document.querySelectorAll(".prism-guide-list dt")].map(e=>e.textContent)');
    const currentLabels=await evaluate(`(() => {const nodes=document.querySelectorAll(${JSON.stringify(role==='student'?'#portalNav button[data-page], #portalNav a, .welcome-card [data-go=submit], .portal-nav-right [data-go=notifications], #helpButton, #portalProfileLinks [data-go=profile], #portalProfileLinks a[href="logout.php"]':'#prismPrimaryNavigation a, .prism-sidebar-utilities a, .portal-nav-right a, #prismAccountLinks a')});return [...nodes].map(e=>e.textContent.trim()||e.getAttribute('aria-label')||e.title);})()`);
    check(currentLabels.every(l=>guideLabels.includes(l)),label+': guide labels match every real navigation item');
    if(width===375){const png=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(shots,`${role}-${dark?'dark':'light'}-guide.png`),Buffer.from(png.data,'base64'));}
    await keyPress('Escape','Escape',27);
    await waitFor('!document.querySelector(".prism-readonly-dialog")');
    check(await evaluate('document.activeElement.hasAttribute("data-prism-guide")'),label+': guide restores trigger focus');
    await evaluate('document.querySelector("[data-prism-tour]").click()');
    await clickText('Maybe Later / Skip');
    await waitFor('!document.querySelector(".prism-readonly-dialog")');
    check(await evaluate(`localStorage.getItem('prismTour.${role}.v1')==='skipped'`),label+': Skip persists');
  }
  await navigate('dashboard.php',375,true,'populated','admin');
  await evaluate('document.getElementById("viewYear").click()');
  await waitFor('document.querySelector("#yearCalendarView [data-official-deadline=true]")');
  check(await evaluate('getComputedStyle(document.querySelector("#yearCalendarView .active-day")).backgroundColor!=="rgba(0, 0, 0, 0)"'),'Admin year-view today highlight preserved');
  await evaluate('document.querySelector("#yearCalendarView [data-official-deadline=true]").click()');
  await waitFor('document.querySelectorAll(".prism-deadline-detail").length===12');
  check(await bounds(),'Admin year-view deadline activation fits');
  await clickText('Close');
  await waitFor('!document.querySelector(".prism-readonly-dialog")');
  await navigate('research_adviser.php',375,true,'populated','adviser');
  await waitFor('document.querySelector("[data-official-deadline=true]")');
  detailError=true;
  await evaluate('document.querySelector("[data-official-deadline=true]").click()');
  await waitFor('document.querySelector(".prism-readonly-dialog [role=alert]")');
  check(await evaluate('document.querySelector(".prism-readonly-dialog").textContent.includes("Could not load") && !document.querySelector(".prism-deadline-detail")'),'Detail failure is visible without partial or empty result');
  await clickText('Close'); await waitFor('!document.querySelector(".prism-readonly-dialog")');
  detailError=false;
  await evaluate('document.querySelector("[data-official-deadline=true]").click()');
  await waitFor('document.querySelectorAll(".prism-deadline-detail").length===12');
  check(true,'Detail request can be retried after failure');
  await clickText('Close'); await waitFor('!document.querySelector(".prism-readonly-dialog")');
  setDelay(800);
  await navigate('dashboard.php',375,false,'populated','admin');
  check(await evaluate('document.getElementById("dashboardDeadlineState").textContent.includes("Loading") && !document.getElementById("dashboardDeadlineState").textContent.includes("No active")'),'Loading does not flash an empty deadline state');
  await evaluate('window.__markerRects=[...document.querySelectorAll("#calendarDays button")].map(b=>b.getBoundingClientRect().height)');
  await waitFor('document.querySelector("[data-official-deadline=true]")');
  check(await evaluate('[...document.querySelectorAll("#calendarDays button")].every((b,i)=>b.getBoundingClientRect().height===window.__markerRects[i])'),'Adding markers does not change calendar cell height');
  await evaluate('document.getElementById("nextBtn").click();document.getElementById("nextBtn").click()');
  await waitFor('document.getElementById("dashboardDeadlineState").getAttribute("aria-busy")==="false"');
  check(await evaluate('!document.querySelector("[data-official-deadline=true]")'),'Rapid month changes do not retain old markers');
  setDelay(0);
  for(const [role,file] of [['admin','dashboard.php'],['adviser','research_adviser.php']])for(const state of ['empty','error']) {
    setDelay(180);
    await navigate(file,375,false,state,role);
    await waitFor('document.getElementById("dashboardDeadlineState").getAttribute("aria-busy")==="false"');
    check(await evaluate(`document.getElementById('dashboardDeadlineState').textContent.includes(${JSON.stringify(state==='empty'?'No active':'Could not load')})`),role+': authoritative '+state+' state');
    check(await evaluate('!document.querySelector("[data-official-deadline=true]")'),role+': '+state+' has no stale markers');
    setDelay(0);
  }
  await navigate('student.php',375,true,'populated','student');
  await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  await evaluate('document.getElementById("portalProfileToggle").click(); document.querySelector("[data-prism-tour]").click()');
  check(await evaluate('getComputedStyle(document.querySelector(".prism-readonly-dialog")).animationName==="none"'),'Reduced motion has no dialog animation');
  await keyPress('Escape','Escape',27); await waitFor('!document.querySelector(".prism-readonly-dialog")');
  await evaluate('document.querySelector("[data-prism-guide]").click()');
  await clickText('Help and support');
  await waitFor('!document.querySelector(".prism-readonly-dialog") && !document.getElementById("supportModal").hidden');
  check(await evaluate('document.activeElement.id==="supportSubject"'),'Guide activation preserves existing Help form focus');
  await evaluate('document.getElementById("closeSupportModal").click()');
  check(!getRequests().some(r=>r.method!=='GET'),'All Batch 2 interactions introduce no state-changing requests');
  for(const role of ['student','adviser']) {
    await navigate('complete_profile.php',375,false,'populated',role);
    check(await evaluate('!document.querySelector("[data-prism-tour],[data-prism-guide],[data-prism-guide-role]")'),'Pending '+role+' onboarding has no normal guidance');
  }
  check(errors.length===0,'No browser runtime exceptions',errors.join(' | '));
  console.log('Batch 2 screenshots: '+shots);
}
module.exports={run,mockDeadlines};
