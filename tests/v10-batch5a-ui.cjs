'use strict';
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors,getRequests}) {
  const artifacts=fs.mkdtempSync(path.join(os.tmpdir(),'prism-batch5a-'));
  const metrics=[];
  const screens=[['research_adviser.php','adviser'],['documents.php','adviser'],['documents.php','admin'],['ierbprog.php','admin'],['ierbprog.php','adviser'],['dashboard.php','admin'],['admin_students.php','admin']];
  for(const [width,height] of matrix)for(const dark of [false,true])for(const [file,role] of screens) {
    await navigate(file,width,dark,'populated',role);
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<500});
    await evaluate('new Promise(r=>setTimeout(r,150))');
    const label=`${file}-${role}-${width}x${height}-${dark?'dark':'light'}`;
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),label+': no horizontal page overflow');
    if(file==='research_adviser.php') {
      const geometry=await evaluate(`(() => {const r=s=>document.querySelector(s).getBoundingClientRect().toJSON();return {reviews:r('.adviser-review-panel'),notifications:r('.adviser-notifications-panel'),scroll:r('#adviserNotifications')};})()`);
      if(width>1100)check(Math.abs(geometry.reviews.y-geometry.notifications.y)<1&&Math.abs(geometry.reviews.height-geometry.notifications.height)<1&&geometry.notifications.x>=geometry.reviews.right+19,label+': panels share top and row height');
      else check(geometry.notifications.y>=geometry.reviews.bottom+19,label+': natural review then notification stacking');
      check(await evaluate("getComputedStyle(document.querySelector('#adviserNotifications')).overflowY==='auto' && document.querySelector('#adviserNotifications').tabIndex===0"),label+': independent keyboard-reachable notification scroll');
      // Stress presentation only; does not change notification API or ownership.
      await evaluate("const n=document.querySelector('#adviserNotifications');for(let i=0;i<30;i++)n.append(n.firstElementChild.cloneNode(true));n.scrollTop=100");
      check(await evaluate("(() => {const n=document.querySelector('#adviserNotifications');return n.scrollHeight>n.clientHeight&&n.scrollTop>0&&n.clientHeight<=640;})()"),label+': long notification history stays internally scrollable');
      metrics.push({label,...geometry});
    }
    if(file==='ierbprog.php') {
      const controls=await evaluate(`(() => {const r=e=>e.getBoundingClientRect().toJSON();return {search:r(document.querySelector('.ierb-search')),add:document.querySelector('#addIerbEntry')?r(document.querySelector('#addIerbEntry')):null,filters:r(document.querySelector('.prism-filter-disclosure>button'))};})()`);
      const adjacent=[controls.add,controls.filters].filter(Boolean);
      check(controls.search.width<= (width>600?320:width)+1&&controls.search.height===(width>600?38:44),label+': constrained management search');
      check(adjacent.every(r=>Math.abs(r.height-controls.search.height)<1),label+': matching control heights');
      if(width>600)check(adjacent.every(r=>Math.abs(r.y-controls.search.y)<1),label+': common desktop baseline');
      else check(controls.filters.y>=controls.search.bottom+7,label+': full-width mobile search above buttons');
      check((role==='admin')===!!controls.add,label+': unchanged Add authorization');
      const before=await evaluate("document.querySelector('.ierb-controls').getBoundingClientRect().toJSON()");
      await evaluate("document.querySelector('.prism-filter-disclosure>button').click()");
      check(await evaluate(`(() => {const r=document.querySelector('.ierb-controls').getBoundingClientRect();return Math.abs(r.height-${before.height})<1&&Math.abs(r.y-${before.y})<1;})()`),label+': filter opening preserves toolbar geometry');
      check(await evaluate("(() => {const r=document.querySelector('.prism-filter-panel').getBoundingClientRect();return r.x>=0&&r.right<=innerWidth+1&&r.y>=0&&r.bottom<=innerHeight+1;})()"),label+': filter viewport containment');
      await keyPress('Escape','Escape',27);
      check(await evaluate("document.activeElement.matches('.prism-filter-disclosure>button')"),label+': filter Escape returns focus');
      metrics.push({label,...controls});
    }
    if(['research_adviser.php','documents.php'].includes(file)) {
      const reviewSelector=file==='research_adviser.php'?'[data-review-id]':'[data-review]';
      check(await evaluate(`document.querySelectorAll(${JSON.stringify(reviewSelector)}).length>0 && [...document.querySelectorAll(${JSON.stringify(reviewSelector)})].every(e=>e.closest('.prism-action-panel')&&e.textContent.trim()==='Review Document')`),label+': authorized review only inside Actions');
      check(await evaluate(`!document.querySelector('.document-row-actions > [data-review],.adviser-document-actions > [data-review-id]')`),label+': no standalone or duplicate review');
      const trigger=file==='research_adviser.php'?'.adviser-document-actions .prism-action-trigger':'.document-row-actions .prism-action-trigger';
      await evaluate(`document.querySelector(${JSON.stringify(trigger)}).scrollIntoView({block:'center'});document.querySelector(${JSON.stringify(trigger)}).focus()`);
      await keyPress('Enter','Enter',13);
      check(await evaluate("(() => {const p=document.querySelector('.prism-action-panel:not([hidden])'),r=p.getBoundingClientRect();return r.x>=0&&r.y>=0&&r.right<=innerWidth+1&&r.bottom<=innerHeight+1;})()"),label+': keyboard-opened Actions contained in viewport');
      await keyPress('ArrowDown','ArrowDown',40);
      check(await evaluate("document.activeElement.classList.contains('prism-action-item') && parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=2"),label+': keyboard navigation and visible focus');
      await keyPress('Escape','Escape',27);
      check(await evaluate("document.activeElement.classList.contains('prism-action-trigger') && document.activeElement.getAttribute('aria-expanded')==='false'"),label+': Escape state/focus return');
      await keyPress('Enter','Enter',13);
      await evaluate("document.querySelector('h1').click()");
      check(await evaluate("[...document.querySelectorAll('.prism-action-panel')].every(p=>p.hidden)"),label+': outside click closes');
      await evaluate(`document.querySelector(${JSON.stringify(trigger)}).click();document.querySelector(${JSON.stringify(reviewSelector)}).focus()`);
      await keyPress('Enter','Enter',13);
      const dialogSelector=file==='research_adviser.php'?'#adviserReviewDialog[open]':'.prism-dialog';
      check(await evaluate(`!!document.querySelector(${JSON.stringify(dialogSelector)})`),label+': keyboard review invokes existing dialog');
      await keyPress('Escape','Escape',27);
      await evaluate('new Promise(r=>setTimeout(r,30))');
      check(await evaluate("document.activeElement.classList.contains('prism-action-trigger')"),label+': review dialog returns focus to visible Actions trigger');
    }
    // Compare actual shared rendering for stage, positive, pending, revision, protocol and PI.
    const badgeMetrics=await evaluate(`(() => {
      const host=document.createElement('div');host.className='prism-pill-row';host.style.width='280px';
      host.innerHTML='<span class="stage-tag">Protocol Submission</span>'+PrismUI.badge('On Track')+PrismUI.badge('Pending',{small:true})+PrismUI.badge('Needs Revision')+'<span class="protocol-badge">2026-001</span><span class="pi-badge">PI</span>';
      document.querySelector('main').append(host);
      const result=[...host.children].map(e=>{const s=getComputedStyle(e),r=e.getBoundingClientRect();return {height:r.height,x:r.x,y:r.y,bottom:r.bottom,right:r.right,padding:s.padding,radius:s.borderRadius,font:s.fontSize,weight:s.fontWeight,line:s.lineHeight,color:s.color,bg:s.backgroundColor};});
      const gap=getComputedStyle(host).gap;host.remove();return {result,gap};})()`);
    check(badgeMetrics.result.every(r=>r.height===28&&r.padding===badgeMetrics.result[0].padding&&r.radius==='999px'&&r.font==='12.5px'&&r.weight==='600'&&r.line==='18px'),label+': shared sizing for six semantic pills');
    check(badgeMetrics.gap==='8px'&&badgeMetrics.result.some(r=>r.y>badgeMetrics.result[0].y)&&badgeMetrics.result.every((r,i)=>!i||r.y>=badgeMetrics.result[i-1].bottom+7||r.x>=badgeMetrics.result[i-1].right+7),label+': 8px horizontal and wrapped vertical spacing');
    const contrast=await evaluate(`(() => {const blend=(a,b)=>a.map((v,i)=>i<3?v*(a[3]??1)+b[i]*(1-(a[3]??1)):1);const rgb=s=>s.match(/[0-9.]+/g).map(Number);const lum=c=>{const v=c.slice(0,3).map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4;});return v[0]*.2126+v[1]*.7152+v[2]*.0722;};const surface=rgb(getComputedStyle(document.querySelector('main')).backgroundColor);const base=surface[3]===0?rgb(getComputedStyle(document.body).backgroundColor):surface;return ${JSON.stringify(badgeMetrics.result)}.map(r=>{const a=lum(rgb(r.color)),b=lum(blend(rgb(r.bg),base));return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);});})()`);
    check(contrast.every(r=>r>=4.5),label+': readable pill contrast in current theme',JSON.stringify(contrast));
    check(new Set(badgeMetrics.result.map(r=>r.color)).size>=5,label+': stage/success/warning/danger/identifier colors differ');
    if(file==='dashboard.php') {
      check(await evaluate("!!document.querySelector('#themeToggle')&&!!document.querySelector('#notificationToggle')&&!!document.querySelector('[data-prism-account-toggle]')"),label+': dashboard utilities preserved');
      check(await evaluate("!document.querySelector('#dashboardSearch,#quickActionsToggle,.quick-actions-menu-wrap,.search-box')&&!!document.querySelector('.topbar h1')"),label+': Search/Quick Actions and empty wrappers removed');
      check(await evaluate("!!document.querySelector('.ai-action-btn')&&!!document.querySelector('a[href=\"reports.php\"]')"),label+': report generation/history alternatives retained');
    }
    if([1366,390].includes(width)) {
      await evaluate('scrollTo(0,0)');
      const shot=await command('Page.captureScreenshot',{format:'png'});
      fs.writeFileSync(path.join(artifacts,label+'.png'),Buffer.from(shot.data,'base64'));
    }
  }
  for(const file of ['research_adviser.php','documents.php']) {
    await navigate(file,1366,false,'review-denied','adviser');
    // Render controller against a record whose authoritative action flags deny review.
    check(await evaluate(`!document.querySelector('[data-review],[data-review-id]')`),file+': no review for locked/unauthorized fixture');
    check(await evaluate("!!document.querySelector('.prism-action-trigger')"),file+': denied review fixture retains legitimate file Actions');
  }
  for(const [file,role] of [['research_adviser.php','adviser'],['documents.php','adviser'],['documents.php','admin']]) {
    await navigate(file,1366,false,'populated',role);
    const dashboard=file==='research_adviser.php';
    await evaluate(`document.querySelector('.prism-action-trigger').click();document.querySelector('${dashboard?'[data-review-id]':'[data-review]'}').click()`);
    await evaluate(dashboard?"document.querySelector('#adviserReviewStatus').value='Under Review';document.querySelector('#adviserReviewRemarks').value='Batch 5A fixture review';document.querySelector('#adviserReviewForm').requestSubmit()":"document.querySelector('#prismReviewStatus').value='Under Review';document.querySelector('#prismReason').value='Batch 5A fixture review';document.querySelector('.prism-dialog [data-act=ok]').click()");
    await waitFor(`!document.querySelector('${dashboard?'#adviserReviewDialog[open]':'.prism-dialog'}')`);
    await waitFor("document.querySelector('[aria-busy=true]')===null");
    const request=getRequests().findLast(r=>r.file==='documents_api.php'&&r.action==='review');
    check(!!request&&request.method==='POST'&&JSON.stringify(JSON.parse(request.body))===JSON.stringify({id:'fixture-doc',status:'Under Review',remarks:'Batch 5A fixture review'}),`${file}/${role}: existing review endpoint and payload preserved`);
  }
  check(errors.length===0,'No Batch 5A browser exceptions',errors.join(' | '));
  fs.writeFileSync(path.join(artifacts,'metrics.json'),JSON.stringify(metrics,null,2));
  console.log('Batch 5A responsive artifacts: '+artifacts);
}
module.exports={run};
