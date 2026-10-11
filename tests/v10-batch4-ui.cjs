'use strict';
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors,getRequests,setManagement}) {
  const before=process.argv.includes('--batch4-before');
  const dest=fs.mkdtempSync(path.join(os.tmpdir(),before?'prism-batch4-before-':'prism-batch4-after-'));
  const metrics=[];
  const screens=[['login.php','student'],['account_setup.php','student'],['complete_profile.php','student'],['complete_profile.php','adviser'],['dashboard.php','admin'],['admin_students.php','admin'],['admin_advisers.php','admin'],['admin_archived_accounts.php','admin','student'],['admin_archived_accounts.php','admin','adviser'],['admin_students.php','adviser'],['documents.php','admin'],['documents.php','adviser'],['ierbprog.php','admin'],['ierbprog.php','adviser'],['account.php','admin'],['account.php','adviser'],['student.php','student'],['research_adviser.php','adviser']];
  for(const [width,height] of (process.argv.includes('--smoke')?[[1366,768],[375,812]]:matrix))for(const dark of [false,true])for(const [file,role,type] of (process.argv.includes('--controls')?screens.filter(s=>['student.php','research_adviser.php'].includes(s[0])):screens)) {
    setManagement(type || (file==='admin_advisers.php'?'adviser':'student'));
    await navigate(file,width,dark,'populated',role);
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<500});
    await evaluate('new Promise(r=>setTimeout(r,180))');
    if(file==='student.php')await evaluate('document.querySelector("[data-page=documents]").click()');
    const label=`${file}-${role}-${width}x${height}-${dark?'dark':'light'}`;
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+': no page overflow');
    const info=await evaluate(`(() => {const rect=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom};}; const p=document.querySelector('.prism-filter-panel');const t=document.querySelector('.prism-filter-disclosure > button');return {background:getComputedStyle(document.body).backgroundImage,resources:[...document.querySelectorAll('.prism-resource')].map(e=>({name:e.dataset.resource,...rect(e)})),password:document.querySelector('#adminArchivePassword')?rect(document.querySelector('#adminArchivePassword')):null,eye:document.querySelector('#adminArchiveForm [data-lifecycle-password]')?rect(document.querySelector('#adminArchiveForm [data-lifecycle-password]')):null,buttons:[...document.querySelectorAll('.management-actions button,.document-actions > button,.student-document-actions > *, .adviser-document-actions > *')].map(e=>({text:e.textContent,...rect(e)})),filter:p?{panel:rect(p),trigger:rect(t)}:null};})()`);
    if(info.filter) {
      const anchor=await evaluate("document.querySelector('table').getBoundingClientRect().top");
      const mutations=getRequests().filter(r=>r.method==='POST').length;
      await evaluate("document.querySelector('.prism-filter-disclosure > button').click()");
      info.filter.open=await evaluate("(() => {const p=document.querySelector('.prism-filter-panel'),r=p.getBoundingClientRect();return {position:getComputedStyle(p).position,x:r.x,y:r.y,right:r.right,bottom:r.bottom,table:document.querySelector('table').getBoundingClientRect().top};})()");
      if(!before) {
      if(process.argv.includes('--controls')&&file==='research_adviser.php')console.log(label,await evaluate('[...document.querySelectorAll(".adviser-document-actions .adviser-button")].map(e=>({text:e.textContent,cls:e.className,height:e.getBoundingClientRect().height,gap:getComputedStyle(e.parentElement).gap,rect:e.getBoundingClientRect().toJSON()}))'));
        check(Math.abs(info.filter.open.table-anchor)<1,label+': opening filters causes zero table shift');
        check(info.filter.open.position==='fixed'&&info.filter.open.x>=0&&info.filter.open.right<=width+1&&info.filter.open.y>=0&&info.filter.open.bottom<=height+1,label+': floating panel fits viewport');
        check(getRequests().filter(r=>r.method==='POST').length===mutations,label+': opening filters causes zero mutation');
        check(await evaluate("(() => {const p=document.querySelector('.prism-filter-panel'),t=document.querySelector('.prism-filter-disclosure > button');return +getComputedStyle(p).zIndex===500 && t.getAttribute('aria-expanded')==='true' && t.getAttribute('aria-controls')===p.id;})()"),label+': deliberate layering and accessible state');
        if([1366,375].includes(width)) {
          const filterShot=await command('Page.captureScreenshot',{format:'png'});
          fs.writeFileSync(path.join(dest,label+'-filters-open.png'),Buffer.from(filterShot.data,'base64'));
        }
        await evaluate("document.querySelector('.prism-filter-panel select').focus()");
        await keyPress('Escape','Escape',27);
        check(await evaluate("document.querySelector('.prism-filter-panel').hidden && document.activeElement.matches('.prism-filter-disclosure > button')"),label+': Escape closes and restores focus');
        await keyPress('Enter','Enter',13);
        await evaluate('document.querySelector("h1").click()');
        check(await evaluate('document.querySelector(".prism-filter-panel").hidden'),label+': outside click closes');
      }
    }
    if(!before) {
      if(['account_setup.php','complete_profile.php'].includes(file)) {
        check(info.background.includes('ceu_bg.png'),label+': exact Login background asset');
        check(await evaluate("!![...document.styleSheets].find(s=>s.href?.includes('assets/css/style.css')) && document.querySelector('.background-overlay .login-card') && getComputedStyle(document.querySelector('.login-card')).borderRadius==='25px' && getComputedStyle(document.querySelector('.login-card')).boxShadow==='rgba(0, 0, 0, 0.25) 0px 15px 40px 0px' && getComputedStyle(document.querySelector('.login-card')).colorScheme==='light'"),label+': exact Login stylesheet, overlay, card, shadow and native control scheme');
        if(file==='account_setup.php') {
          await evaluate('document.querySelector("[aria-controls=setupPassword]").focus()');await keyPress('Enter','Enter',13);
          check(await evaluate('document.getElementById("setupPassword").type==="text" && document.querySelector("[aria-controls=setupPassword]").getAttribute("aria-pressed")==="true"'),label+': password eye works by keyboard');
        }
      }
      if(info.resources.length) {
        if(file==='student.php') {
          await evaluate('document.querySelector("[data-page=dashboard]").click()');
          info.resources=await evaluate('[...document.querySelectorAll(".prism-resource")].map(e=>{const r=e.getBoundingClientRect();return {name:e.dataset.resource,width:r.width,height:r.height,y:r.y,bottom:r.bottom};})');
        }
        check(info.resources.map(r=>r.name).join(',')==='ierb,sdg,agenda',label+': resource order');
        check(info.resources.every(r=>Math.abs(r.width-info.resources[0].width)<1 && Math.abs(r.height-info.resources[0].height)<1) && info.resources.every((r,i)=>!i||r.y>=info.resources[i-1].bottom+10),label+': three equal rows stacked');
        check(await evaluate('document.querySelector("[data-resource=agenda] img").getAttribute("src")==="assets/images/research-agenda-2023-2028.png"'),label+': official Agenda retained');
        await evaluate('document.querySelector("[data-resource=ierb] summary").focus()');await keyPress('Enter','Enter',13);
        check(await evaluate('document.querySelector("[data-resource=ierb]").open'),label+': native keyboard accordion expansion');await keyPress('Enter','Enter',13);
        if(file==='student.php')await evaluate('document.querySelector("[data-page=documents]").click()');
      }
      if(file==='account.php'&&role==='admin') {
        check(await evaluate(`(() => {const f=document.getElementById('adminArchiveForm'),r=e=>e.getBoundingClientRect(),input=r(f.querySelector('input[type=password]')),eye=r(f.querySelector('[data-lifecycle-password]')),heading=r(f.querySelector('h3')),button=r(f.querySelector('[type=submit]')),summary=r(document.querySelector('.lifecycle-destructive summary')),check=r(f.querySelector('.lifecycle-check input')),span=r(f.querySelector('.lifecycle-check span')); return input.width<=480&&input.width>200&&eye.right<=input.right&&eye.left>=input.left&&Math.abs(eye.y+eye.height/2-input.y-input.height/2)<1&&Math.abs(input.x-heading.x)<1&&Math.abs(button.x-input.x)<1&&Math.abs(summary.x-input.x)<1&&span.x>=check.right+9&&Math.abs(span.y-check.y)<2;})()`),label+': lifecycle column, attached eye, checkbox and action alignment');
        check(await evaluate('document.getElementById("adminArchivePassword").required && document.querySelector("#adminArchiveForm [name=confirmed]").required && document.querySelector("#adminDeleteForm [name=confirmation]").required'),label+': lifecycle required fields preserved');
      }
      if(info.filter) {
        check(await evaluate("(() => {const s=document.querySelector('.management-search,.ierb-search,.document-search');return s.getBoundingClientRect().width<=Math.max(320,innerWidth<601?innerWidth:320)+1&&s.getBoundingClientRect().height<=44;})()"),label+': compact search');
        if(role==='admin' && file.startsWith('admin_'))check(await evaluate(`!document.querySelector('.prism-filter-panel select[id*=lifecycle]') && !document.querySelector('.prism-filter-panel .retention-toolbar') && document.querySelector('.retention-toolbar').hidden && ${file==='admin_archived_accounts.php'?"document.querySelector('.prism-filter-panel .retention-cleanup') && document.querySelectorAll('.prism-filter-panel select[id*=retention],.prism-filter-panel select[id*=profile]').length===2":"!document.querySelector('.retention-cleanup') && document.querySelectorAll('.prism-filter-panel select[id*=profile]').length===1"}`),label+': retention maintenance confined to archive filters; bulk selection outside and initially hidden');
      }
      if(file==='student.php' || file==='documents.php' || file==='ierbprog.php' || file.startsWith('admin_')) {
        const hasMenu=await evaluate('!!document.querySelector(".prism-action-trigger")');check(hasMenu,label+': secondary actions consolidated');
        if(hasMenu) {
          await evaluate('document.querySelector(".prism-action-trigger").scrollIntoView({block:"center",inline:"center"});document.querySelector(".prism-action-trigger").focus()');await keyPress('Enter','Enter',13);
          check(await evaluate("(() => {const p=[...document.querySelectorAll('.prism-action-panel')].find(p=>!p.hidden),r=p.getBoundingClientRect();return r.x>=0&&r.y>=0&&r.right<=innerWidth+1&&r.bottom<=innerHeight+1&&p.querySelectorAll('a,button').length>=1;})()"),label+': actions fit viewport');
          if(process.argv.includes('--controls'))console.log(label,await evaluate('[...document.querySelectorAll(".student-document-actions a,.adviser-document-actions .adviser-button")].map(e=>({text:e.textContent,cls:e.className,height:e.getBoundingClientRect().height,gap:getComputedStyle(e.parentElement).gap,rect:e.getBoundingClientRect().toJSON()}))'));
          check(await evaluate('[...document.querySelector(".prism-action-panel:not([hidden])").querySelectorAll("button,a")].every(e=>e.getBoundingClientRect().height>=(innerWidth<601?44:38) && e.getBoundingClientRect().width>200 && e.scrollWidth<=e.clientWidth+1)'),label+': menu labels fit full-width desktop/touch targets');
          if([1366,375].includes(width)) {
            const menuShot=await command('Page.captureScreenshot',{format:'png'});
            fs.writeFileSync(path.join(dest,label+'-actions-open.png'),Buffer.from(menuShot.data,'base64'));
          }
          await keyPress('ArrowDown','ArrowDown',40);check(await evaluate('document.activeElement.classList.contains("prism-action-item")'),label+': keyboard item navigation');
          await keyPress('Escape','Escape',27);check(await evaluate('document.activeElement.classList.contains("prism-action-trigger") && [...document.querySelectorAll(".prism-action-panel")].every(p=>p.hidden)'),label+': action Escape/focus return');
          await keyPress('Enter','Enter',13);await evaluate('document.querySelector("h1").click()');check(await evaluate('[...document.querySelectorAll(".prism-action-panel")].every(p=>p.hidden)'),label+': action outside click');
        }
        if(role!=='admin')check(await evaluate('!document.querySelector(".lifecycle-danger,[data-override],[data-delete],[data-summary]")'),label+': Admin-only actions absent');
        if(role==='admin'&&file.startsWith('admin_'))check(await evaluate('!!document.querySelector(".prism-action-panel .prism-action-danger")'),label+': destructive menu items distinct');
      }
      if(file==='student.php'||file==='documents.php')check(await evaluate('(() => {const values=[...document.getElementById("documentType").options].map(o=>o.value).filter(Boolean);return JSON.stringify(values)===JSON.stringify(PrismUI.documentTypes)&&values.length===14;})()'),label+': one canonical new-upload catalog');
      if(file==='student.php') {
        await evaluate('document.querySelector("[data-go=submit]").click()');
        check(await evaluate('document.getElementById("submissionProtocol").readOnly && document.getElementById("submissionProtocol").value==="No protocol code yet."'),label+': authoritative missing protocol display');
        await evaluate('document.querySelector("[data-page=documents]").click()');
      }
      if(file==='login.php')check(await evaluate(`!document.querySelector('a[href="register.php"]')`),label+': Login has no Register CTA');
    }
    if(file==='account.php'&&role==='admin')await evaluate('document.getElementById("lifecycle").scrollIntoView({block:"start"})');
    if(file==='dashboard.php')await evaluate('document.querySelector("[data-prism-resources]").scrollIntoView({block:"start"})');
    const shot=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(dest,label+'.png'),Buffer.from(shot.data,'base64'));
    if(!before){
      await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
      check(await evaluate('[...document.querySelectorAll(".prism-action-trigger,.prism-action-item,.prism-resource summary,.onboarding-password button")].every(e=>getComputedStyle(e).transitionDuration==="0s")'),label+': reduced motion');
      await command('Emulation.setEmulatedMedia',{features:[]});
    }
    metrics.push({label,...info});
  }
  fs.writeFileSync(path.join(dest,'metrics.json'),JSON.stringify(metrics,null,2));
  check(errors.length===0,'No Batch 4 browser exceptions',errors.join(' | '));
  console.log('Batch 4 rendered audit artifacts: '+dest);
}
module.exports={run};
