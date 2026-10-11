'use strict';
const fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors,getRequests,setDelay}) {
  const artifacts=fs.mkdtempSync(path.join(os.tmpdir(),'prism-batch5b-ui-')),metrics=[];
  for(const [width,height] of (process.argv.includes('--upload-only')?[]:matrix))for(const dark of [false,true]) {
    const label=`${width}x${height}/${dark?'dark':'light'}`;
    for(const file of ['login.php','privacy.php','terms.php','dashboard.php']) {
      await navigate(file,width,dark,'populated','admin');
      await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<500});
      check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),`${file}/${label}: no body horizontal overflow`);
      if(file==='login.php') {
        check(await evaluate("!!document.querySelector('.login-legal-footer a[href=\"privacy.php\"]')&&!!document.querySelector('.login-legal-footer a[href=\"terms.php\"]')"),label+': native Login legal links');
        check(await evaluate("document.querySelector('.login-legal-footer').getBoundingClientRect().bottom<=innerHeight"),label+': Login footer fits viewport');
      } else if(file!=='dashboard.php') {
        check(await evaluate("document.querySelectorAll('h1').length===1&&document.querySelectorAll('h2').length===1&&document.querySelectorAll('h3').length==="+(file==='privacy.php'?12:16)),file+'/'+label+': heading hierarchy');
        check(await evaluate("document.body.textContent.includes('Effective Date: October 2026')&&!document.querySelector('.prism-sidebar,.portal-navbar')"),file+'/'+label+': approved date and public presentation');
        check(await evaluate("getComputedStyle(document.body).overflowY!=='hidden'&&document.documentElement.scrollHeight>innerHeight"),file+'/'+label+': normal long-page scroll');
        await evaluate("document.querySelector('.legal-header a[href=\"login.php\"]').focus()");
        check(await evaluate("parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=3"),file+'/'+label+': visible keyboard focus');
        check(await evaluate("(()=>{const rgb=s=>s.match(/[0-9.]+/g).map(Number),lum=c=>{let v=c.slice(0,3).map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4});return v[0]*.2126+v[1]*.7152+v[2]*.0722};const a=lum(rgb(getComputedStyle(document.querySelector('.legal-shell')).backgroundColor)),b=lum(rgb(getComputedStyle(document.body).color));return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5})()"),file+'/'+label+': readable text contrast');
      } else {
        const geometry=async()=>evaluate("(()=>{const r=s=>document.querySelector(s).getBoundingClientRect().toJSON();return {content:r('.content'),attention:r('.dashboard-attention'),stage:r('.pipeline-card'),calendar:r('.calendar-box'),monitor:r('.dashboard-monitor')};})()");
        const month=await geometry();
        check(Math.abs(month.monitor.width-month.content.width)<1&&month.monitor.y>=Math.max(month.stage.bottom,month.calendar.bottom)+19,label+': IERB full width below preceding row');
        check(Math.abs(month.attention.width-month.content.width)<1,label+': attention full width');
        if(width>1200)check(Math.abs(month.stage.y-month.calendar.y)<1&&month.calendar.x>=month.stage.right+19,label+': Month stage and Calendar aligned');
        else check(month.calendar.y>=month.stage.bottom+19,label+': panels stack at narrow width');
        await evaluate("document.getElementById('prismSidebarToggle').click()");
        const expanded=await geometry();check(Math.abs(expanded.monitor.width-month.monitor.width)<1&&Math.abs(expanded.content.x-month.content.x)<1,label+': sidebar overlay stable');
        if([1366,375].includes(width)) {
          await evaluate("document.querySelector('.content').scrollIntoView({block:'start'})");
          const shot=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(artifacts,'dashboard-month-'+width+'-'+dark+'.png'),Buffer.from(shot.data,'base64'));
          await evaluate("document.querySelector('.dashboard-monitor').scrollIntoView({block:'start'})");
          const monitor=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(artifacts,'dashboard-monitor-'+width+'-'+dark+'.png'),Buffer.from(monitor.data,'base64'));
        }
        await evaluate("document.getElementById('viewYear').click()");
        await waitFor("document.querySelectorAll('.year-month-card').length===12");
        const year=await evaluate("(()=>{const p=document.getElementById('yearCalendarView'),r=p.getBoundingClientRect(),shell=p.closest('.calendar-box').getBoundingClientRect();return {columns:getComputedStyle(p).gridTemplateColumns.split(' ').length,fit:[...p.children].every(c=>{const x=c.getBoundingClientRect();return x.left>=r.left-1&&x.right<=r.right+1&&x.bottom<=shell.bottom+1}),scroll:p.scrollHeight,client:p.clientHeight};})()");
        check(year.columns===(width>=1366?3:width>=768?2:1)&&year.fit&&year.scroll<=year.client+1,label+': Year preserves 3/2/1 and natural height');
        const yearGeometry=await geometry();check(yearGeometry.monitor.y>=yearGeometry.calendar.bottom+19&&Math.abs(yearGeometry.monitor.width-yearGeometry.content.width)<1,label+': Year IERB full width below calendar');
        if([1366,375].includes(width)) {await evaluate("document.querySelector('.calendar-box').scrollIntoView({block:'start'})");const shot=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(artifacts,'dashboard-year-'+width+'-'+dark+'.png'),Buffer.from(shot.data,'base64'));}
        metrics.push({label,month,year,yearGeometry});
      }
      if([1366,375].includes(width)){await evaluate('scrollTo(0,0)');const shot=await command('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(artifacts,file+'-'+width+'-'+dark+'.png'),Buffer.from(shot.data,'base64'));}
    }
  }
  for(const file of ['documents.php','student.php']) {
    await navigate(file,390,false,'populated',file==='student.php'?'student':'admin');
    if(file==='documents.php') {await evaluate("document.getElementById('uploadDocumentButton').click()");await waitFor("document.getElementById('uploadModal').classList.contains('show')");await evaluate("document.getElementById('documentStudent').selectedIndex=1");}
    check(await evaluate("document.getElementById('documentFile').multiple&&PrismUI.documentTypes.length===14"),file+': native multiple input and PHP catalog');
    await evaluate("{const dt=new DataTransfer();dt.items.add(new File(['Presentation fixture'], 'valid.pdf',{type:'application/pdf'}));dt.items.add(new File(['Fixture'],'bad.exe',{type:'text/plain'}));document.getElementById('documentFile').files=dt.files;document.getElementById('documentFile').dispatchEvent(new Event('change'));document.getElementById('documentType').value='Study Protocol'}");
    check(await evaluate("document.getElementById('uploadSelection').textContent.includes('valid.pdf')&&document.getElementById('uploadSelection').textContent.includes('bad.exe')"),file+': selected file names/sizes available');
    const form=file==='documents.php'?'uploadForm':'submissionForm',before=getRequests().filter(r=>r.action==='upload').length;
    setDelay(150);await evaluate(`document.getElementById('${form}').requestSubmit();document.getElementById('${form}').requestSubmit()`);
    await waitFor("document.getElementById('uploadResults').textContent.includes('1 of 2')");setDelay(0);
    check(getRequests().filter(r=>r.action==='upload').length===before+1,file+': double submit sends one request');
    check(await evaluate("document.getElementById('uploadResults').textContent.includes('Uploaded')&&document.getElementById('uploadResults').textContent.includes('Failed')&&document.getElementById('uploadResults').getAttribute('aria-live')==='polite'"),file+': partial success accessible per-file status');
    check(getRequests().findLast(r=>r.action==='upload').headers['x-prism-generation']==='a'.repeat(32),file+': mutation carries rendered generation');
  }
  await navigate('dashboard.php',1366,false,'populated','admin');
  const before=getRequests().length;
  await evaluate("document.cookie='prism_generation='+ 'b'.repeat(32)+'; path=/';window.dispatchEvent(new Event('focus'))");
  check(await evaluate("document.getElementById('prismSessionChanged').open&&document.body.textContent.includes('Your PRISM session changed in another tab')"),'stale tab opens reload dialog');
  check(await evaluate("fetch('documents_api.php?action=upload',{method:'POST'}).then(()=>false,()=>true)"),'stale mutation blocked in browser');
  check(getRequests().length===before,'stale page sends no mutation request');
  await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  await navigate('privacy.php',375,true,'populated','admin');check(await evaluate("document.querySelectorAll('h1').length===1"),'legal page readable with reduced motion');
  await navigate('privacy.php',375,true,'populated','admin',true);
  check(await evaluate("!!document.querySelector('a[href=\"login.php\"]')&&document.querySelectorAll('h3').length===12"),'legal content and native navigation survive JavaScript disabled');
  await navigate('login.php',375,false,'populated','admin',true);
  check(await evaluate("!!document.querySelector('a[href=\"privacy.php\"]')&&!!document.querySelector('a[href=\"terms.php\"]')"),'Login legal links survive JavaScript disabled');
  await command('Emulation.setScriptExecutionDisabled',{value:false});
  check(errors.length===0,'No Batch 5B browser exceptions',errors.join(' | '));
  fs.writeFileSync(path.join(artifacts,'metrics.json'),JSON.stringify(metrics,null,2));console.log('Batch 5B browser artifacts: '+artifacts);
}
module.exports={run};
