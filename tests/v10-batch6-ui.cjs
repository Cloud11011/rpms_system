'use strict';
const fs=require('node:fs'),path=require('node:path');
async function run({check,evaluate,waitFor,navigate,command,keyPress,errors}) {
  const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]],metrics=[];
  const states=[['admin_legal_policies.php','draft'],['admin_legal_policies.php','pending'],['admin_legal_policies.php','creator'],['admin_legal_policies.php','sole'],['admin_legal_policies.php','history'],['legal_consent.php','both'],['legal_consent.php','partial'],['privacy.php','public'],['terms.php','public']];
  const out=path.join(__dirname,'v10-batch6-results');fs.mkdirSync(out,{recursive:true});
  for (const [width,height] of matrix) for (const dark of [false,true]) for (const [file,state] of states) {
    await navigate(file,width,dark,state,'admin');
    await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    const data=await evaluate(`({overflow:document.documentElement.scrollWidth>innerWidth+1,h1:document.querySelectorAll('h1').length,xss:!!window.__fixtureXss,unlabelled:[...document.querySelectorAll('input:not([type=hidden]),textarea')].some(i=>!i.closest('label')&&!document.querySelector('label[for="'+i.id+'"]')),unusable:[...document.querySelectorAll('button')].some(b=>b.getBoundingClientRect().height<43)})`);
    check(!data.overflow,file+': no body overflow '+width+' '+state+' '+dark);check(data.h1===1,file+': one primary heading');check(!data.xss,file+': markup never executes');check(!data.unlabelled,file+': all controls labelled');check(!data.unusable,file+': mobile button targets');
    if (file==='legal_consent.php') {
      check(await evaluate(`document.querySelectorAll('input[type=checkbox]').length===${state==='partial'?1:2}&&![...document.querySelectorAll('input[type=checkbox]')].some(i=>i.checked)`),'Only outstanding policy checkboxes, never prechecked');
      check(await evaluate(`!!document.querySelector('a[href="logout.php"]')`),'Decline/logout available');
    }
    if (file==='admin_legal_policies.php') {
      if(state==='creator')check(await evaluate(`!document.querySelector('[name=password]')&&document.body.textContent.includes('different active Admin')`),'Multiple Admin creator cannot self-publish in UI');
      if(state==='pending')check(await evaluate(`!!document.querySelector('[name=password]')&&!!document.querySelector('[name=reason]')`),'Independent approval and reason-labelled rejection controls');
      if(state==='sole')check(await evaluate(`!!document.querySelector('[name=soleAcknowledged]')&&document.body.textContent.includes('only active RPMS Administrator')`),'Sole Admin notice and acknowledgment');
      if(state==='history')check(await evaluate(`!document.querySelector('[name=content],[name=password]')`),'Historical text has no edit/publication controls');
    }
    await keyPress('Tab');
    check(await evaluate(`document.activeElement!==document.body&&getComputedStyle(document.activeElement).outlineStyle!=='none'`),'Keyboard focus is visible');
    metrics.push({file,state,width,height,dark,...data});
    if (width===375 && dark) {const shot=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(out,file+'-'+state+'.png'),Buffer.from(shot.data,'base64'));}
  }
  await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  await navigate('legal_consent.php',375,true,'both','admin',true);
  check(await evaluate(`!!document.querySelector('form[method=post]')&&document.querySelectorAll('input[type=checkbox]').length===2`),'Acceptance works with native forms and JavaScript disabled');
  await navigate('privacy.php',375,true,'public','admin',true);check(await evaluate(`document.querySelector('main').textContent.includes('Version 1')`),'Public policy readable without JavaScript under reduced motion');
  await command('Emulation.setScriptExecutionDisabled',{value:false});
  check(errors.length===0,'No B6 browser exceptions',errors.join(' | '));fs.writeFileSync(path.join(out,'ui-metrics.json'),JSON.stringify(metrics,null,2));
}
module.exports={run};
