/* Real Login/Register templates in an isolated PHP/browser fixture.
 * Run: node tools/verify-auth-visual.cjs
 * Never loads config.local.php, application bootstrap, or a real auth endpoint.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawn, spawnSync, execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const php = process.env.PRISM_TEST_PHP || 'C:\\xampp\\php\\php.exe';
const browser = process.env.PRISM_TEST_BROWSER || [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].find(file => fs.existsSync(file));
const output = path.join(root, 'tests', 'auth-visual-results');
const viewports = [[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812],[320,568]];
const baseline = file => execFileSync('git', ['show', 'HEAD:' + file], {cwd:root, encoding:'utf8', windowsHide:true});
const baselineCss = baseline('assets/css/style.css');
const samples = [], errors = [], posts = [], pending = new Map();
let socket, child, server, profile, sessionId, origin, nextId = 1, checks = 0;
let visit = 0;
function check(value, label) { checks++; assert(value, label); }

function render(file, options = {}) {
  if (file === 'reset_password.php') {
    const result = spawnSync(php, [path.join(root, 'tests/auth-flows.php'), '--case', JSON.stringify({file})],
      {cwd:root, encoding:'utf8', windowsHide:true});
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stderr, '');
    return JSON.parse(result.stdout).rendered;
  }
  let source = options.original ? baseline(file) : fs.readFileSync(path.join(root, file), 'utf8');
  const include = "require __DIR__ . '/config.php';";
  assert.equal(source.split(include).length - 1, 1, 'Expected one configuration include');
  source = source.replace(include, '/* Isolated presentation fixture. */');
  assert(!/\b(?:require|include)(?:_once)?\s*(?:\(|["'$])/i.test(source), 'Unexpected nested include');
  const flash = options.flash ? {error:'<script>window.fixtureXss=1</script> Server error & retry', success:'Signed out successfully.'} : {};
  if(file==='change_password_required.php')flash.must_change_password=1;
  const setup = `<?php
    require_once '${path.join(root,'includes/assets.php').replaceAll('\\','/')}';
    $_SESSION = json_decode(base64_decode('${Buffer.from(JSON.stringify(flash)).toString('base64')}'), true);
    $_GET = ${options.expired ? "['expired'=>1]" : '[]'};
    const ADMIN_REGISTRATION_CODE = '${options.disabled ? '' : 'isolated-fixture-code'}';
    function current_user() { return ${file === 'change_password_required.php' ? "['must_change_password'=>1,'role'=>'admin']" : 'null'}; }
    function allowed_email_domains_hint() { return '@ceu.edu.ph, @mls.ceu.edu.ph, @gmail.com'; }
    ?>`;
  const result = spawnSync(php, ['-d','display_errors=stderr'], {input:setup+source, cwd:root, encoding:'utf8', windowsHide:true});
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stderr, '');
  return result.stdout;
}

function serve(req, res) {
  try {
    const url = new URL(req.url, origin);
    const file = url.pathname.slice(1);
    if (['login_process.php','register_process.php'].includes(file)) {
      let body = '';
      req.on('data', chunk => body += chunk);
      req.on('end', () => { posts.push({file, method:req.method, fields:Object.fromEntries(new URLSearchParams(body))}); res.end('Isolated POST received'); });
      return;
    }
    if (['login.php','register.php','forgot_password.php','reset_password.php','change_password_required.php'].includes(file)) {
      let html = render(file, {disabled:url.searchParams.has('disabled'),flash:url.searchParams.has('flash'),expired:url.searchParams.has('expired'),original:url.searchParams.has('original')});
      if (url.searchParams.has('baseline')) html = html.replace(/href="assets\/css\/style\.css[^\"]*"/, 'href="assets/css/style.baseline.css"');
      res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}); res.end(html); return;
    }
    if (file === 'assets/css/style.baseline.css') { res.writeHead(200, {'Content-Type':'text/css'}); res.end(baselineCss); return; }
    const asset = path.resolve(root, file);
    if (asset.startsWith(path.join(root,'assets')+path.sep) && fs.existsSync(asset) && fs.statSync(asset).isFile()) {
      const types = {'.css':'text/css','.js':'application/javascript','.png':'image/png'};
      res.writeHead(200, {'Content-Type':types[path.extname(asset)] || 'application/octet-stream'});
      res.end(fs.readFileSync(asset)); return;
    }
    res.writeHead(404); res.end();
  } catch (error) { errors.push(String(error)); res.writeHead(500); res.end('Fixture failure'); }
}

function command(method, params = {}, browserCommand = false) {
  return new Promise((resolve, reject) => {
    const id = nextId++;
    const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: '+method)); }, 20000);
    pending.set(id, {resolve:value=>{clearTimeout(timer);resolve(value);},reject:error=>{clearTimeout(timer);reject(error);}});
    socket.send(JSON.stringify({id,method,params,...(!browserCommand && sessionId ? {sessionId} : {})}));
  });
}
async function evaluate(fn, ...args) {
  const expression = typeof fn === 'function' ? `(${fn.toString()})(${args.map(x=>JSON.stringify(x)).join(',')})` : fn;
  const result = await command('Runtime.evaluate', {expression,returnByValue:true,awaitPromise:true});
  assert(!result.exceptionDetails, result.exceptionDetails?.exception?.description);
  return result.result.value;
}
async function waitFor(expression) {
  for (let i=0;i<400;i++) { if(await evaluate(expression))return; await new Promise(resolve=>setTimeout(resolve,30)); }
  throw new Error('Page readiness timeout: '+expression);
}
async function navigate(file, width, height) {
  await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});
  await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
  const url=origin+'/'+file+(file.includes('?')?'&':'?')+'visit='+(++visit);
  await command('Page.navigate',{url});
  await waitFor('location.href === '+JSON.stringify(url)+' && document.readyState === "complete" && !!document.querySelector(".background-overlay")');
  await evaluate('document.fonts.ready');
  await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
}
async function screenshot(name, width) {
  const {cssContentSize:size} = await command('Page.getLayoutMetrics');
  const shot = await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width,height:size.height,scale:1}});
  fs.writeFileSync(path.join(output,name+'.png'),Buffer.from(shot.data,'base64'));
}
function measurements() {
  const card = document.querySelector('.login-card,.register-card');
  const rect = element => { const r=element.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}; };
  const style = (selector, property) => getComputedStyle(document.querySelector(selector))[property];
  const image = document.querySelector('.logo-main');
  const logo = rect(image);
  const heading = document.querySelector('h1,h2');
  const fields = [...document.querySelectorAll('input')];
  const submit = document.querySelector('button[type="submit"]');
  const brand = document.querySelector('h1 span');
  const range = document.createRange();
  if (heading?.lastChild) range.selectNodeContents(heading.lastChild);
  const punctuation = heading?.lastChild?.nodeType===Node.TEXT_NODE ? rect(range) : null;
  return {
    card:rect(card),viewport:{width:innerWidth,height:innerHeight,availableWidth:document.documentElement.clientWidth},scrollWidth:document.documentElement.scrollWidth,scrollHeight:document.documentElement.scrollHeight,
    surface:getComputedStyle(card).backgroundColor,radius:getComputedStyle(card).borderRadius,padding:getComputedStyle(card).padding,
    overlay:style('.background-overlay','backgroundImage'),logo,logoLoaded:image.complete&&image.naturalWidth>0,
    heading:rect(heading),headingColor:getComputedStyle(heading).color,punctuation,
    gradient:brand?getComputedStyle(brand).backgroundImage:null,gradientClip:brand?getComputedStyle(brand).backgroundClip:null,
    subtitle:document.querySelector('.prism-branding strong')?style('.prism-branding strong','color'):null,
    helper:document.querySelector('.register-text')?style('.register-text','color'):null,note:document.querySelector('.login-account-note')?style('.login-account-note','color'):null,
    button:submit?rect(submit):null,buttonColor:submit?getComputedStyle(submit).backgroundColor:null,
    fields:fields.map(input=>({rect:rect(input),height:getComputedStyle(input).height,background:getComputedStyle(input).backgroundColor,border:getComputedStyle(input).borderColor,active:input.matches(':focus, :hover')})),
    icons:[...document.querySelectorAll('.input-group > i,.toggle-password')].map(icon=>getComputedStyle(icon).color),
    toggles:[...document.querySelectorAll('.toggle-password')].map(rect),
    logoCanvasBottom:logo.y+logo.height*.64,
    fontLoaded:document.fonts.check('700 22px Montserrat'),iconsLoaded:document.fonts.check('900 15px "Font Awesome 6 Free"'),
  };
}

async function checkPage(file, width, height, disabled = false) {
  await navigate(file+(disabled?'?disabled=1':''),width,height);
  // Explicitly focus another control before blurring to cancel deferred autofocus.
  await evaluate('document.querySelector("button[type=submit],.register-text a").focus(); document.activeElement.blur()');
  await waitFor('[...document.querySelectorAll("input")].every(input=>getComputedStyle(input).borderColor===(input.matches(":focus,:hover")?"rgb(238, 18, 128)":"rgba(0, 0, 0, 0)"))');
  await evaluate('scrollTo(0,0)');
  const m=await evaluate(measurements),label=`${file}${disabled?' disabled':''} ${width}x${height}`;
  fs.writeFileSync(path.join(output,'latest-sample.json'),JSON.stringify(m,null,2));
  check(m.scrollWidth<=m.viewport.availableWidth,label+': no horizontal overflow');
  check(m.card.width===Math.min(430,m.viewport.availableWidth-32),label+': responsive 430px card');
  check(Math.abs(m.card.x-(m.viewport.availableWidth-m.card.width)/2)<1,label+': card centered');
  check(m.card.y>=24 && m.card.bottom<=m.scrollHeight-23,label+': card fully reachable');
  check(m.surface==='rgba(255, 255, 255, 0.96)' && m.radius==='25px',label+': card surface');
  check(m.overlay==='linear-gradient(rgba(12, 14, 63, 0.45), rgba(238, 18, 128, 0.2))',label+': shared overlay');
  check(m.logoLoaded && m.logo.width===220 && Math.abs(m.logo.x+m.logo.width/2-m.viewport.availableWidth/2)<1,label+': original centered logo');
  check(m.logoCanvasBottom<m.heading.y,label+': visible logo does not collide with title');
  check(m.heading.right<=m.card.right && m.heading.x>=m.card.x,label+': heading fits');
  check(m.headingColor==='rgb(34, 34, 34)' && (file==='login.php'?m.helper===null:m.helper==='rgb(85, 85, 85)'),label+': heading/helper colors');
  if(file==='login.php') {
    check(m.gradient==='linear-gradient(90deg, rgb(222, 75, 158) 0%, rgb(188, 101, 179) 50%, rgb(133, 133, 199) 100%)'&&m.gradientClip==='text',label+': pink-purple-lavender word gradient');
    check(m.punctuation.right<=m.card.right && m.subtitle==='rgb(12, 14, 63)' && m.note==='rgb(136, 136, 136)',label+': punctuation/subtitle/note');
    check(await evaluate('document.querySelector(".form-options a").getAttribute("href")==="forgot_password.php"'),label+': real password recovery link');
  }
  check(await evaluate('!document.querySelector(".back-to-roles") && !/Back/.test(document.querySelector(".register-text")?.textContent || "")'),label+': no Back action');
  if(file==='login.php')check(await evaluate('!document.querySelector("a[href=\\"register.php\\"]") && !document.querySelector(".register-text")'),label+': no public Register CTA');
  if(disabled) {
    check(await evaluate('!document.querySelector("form") && document.querySelector(".register-text a").textContent==="Login" && document.querySelector(".register-text a").getAttribute("href")==="login.php"'),label+': disabled registration Login navigation');
  } else {
    check(m.buttonColor==='rgb(12, 14, 63)' && m.button.height===52 && m.button.width===m.fields[0].rect.width,label+': full-width navy primary button');
    check(m.fields.every(f=>f.height==='50px'&&f.background==='rgb(243, 243, 243)'&&f.border===(f.active?'rgb(238, 18, 128)':'rgba(0, 0, 0, 0)')),label+': neutral pill inputs with pink active border '+JSON.stringify(m.fields));
    check(m.fields.every(f=>Math.abs(f.rect.x-m.fields[0].rect.x)<1 && f.rect.right<m.card.right),label+': aligned inputs');
    check(m.icons.every(color=>color==='rgb(136, 136, 136)'),label+': gray input icons');
    check(m.toggles.every(r=>r.width===44&&r.height===44),label+': usable password controls');
    await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:m.fields[0].rect.x+60,y:m.fields[0].rect.y+25});
    await waitFor('getComputedStyle(document.querySelector("input")).borderColor==="rgb(238, 18, 128)"');
    check(await evaluate('getComputedStyle(document.querySelector("input")).borderColor==="rgb(238, 18, 128)"'),label+': pink hover border');
    await evaluate('document.querySelector("input").focus()');
    const focused=await evaluate(()=>{const input=document.querySelector('input'),r=input.getBoundingClientRect(),s=getComputedStyle(input);return {width:r.width,height:r.height,border:s.borderColor,outline:s.outlineStyle};});
    check(focused.width===m.fields[0].rect.width&&focused.height===m.fields[0].rect.height&&focused.border==='rgb(238, 18, 128)'&&focused.outline==='solid',label+': visible pink focus with no size change');
    await evaluate('document.activeElement.blur()');
    const visibleButton=await evaluate(()=>{const button=document.querySelector('button[type="submit"]');button.scrollIntoView({block:'center'});const r=button.getBoundingClientRect();return {x:r.x,y:r.y};});
    await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:visibleButton.x+10,y:visibleButton.y+20});
    await waitFor('getComputedStyle(document.querySelector("button[type=submit]")).backgroundColor==="rgb(238, 18, 128)"');
    check(await evaluate('getComputedStyle(document.querySelector("button[type=submit]")).backgroundColor==="rgb(238, 18, 128)"'),label+': pink button hover');
    await command('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});
    await waitFor('getComputedStyle(document.querySelector("button[type=submit]")).backgroundColor==="rgb(12, 14, 63)"');
    const original=render(file,{original:true});
    check(await evaluate(html=>{
      const before=new DOMParser().parseFromString(html,'text/html');
      const signature=doc=>[...doc.querySelectorAll('input')].map(input=>Object.fromEntries([...input.attributes].filter(a=>a.name!=='placeholder').map(a=>[a.name,a.value])));
      return JSON.stringify(signature(before))===JSON.stringify(signature(document))&&document.querySelector('form').method===before.querySelector('form').method&&document.querySelector('form').getAttribute('action')===before.querySelector('form').getAttribute('action');
    },original),label+': POST target and all input/security attributes preserved');
    if(file==='register.php')check(await evaluate('document.querySelector("input[name=password]").placeholder==="Password (12+ characters)" && document.querySelector("input[name=password]").minLength===12'),label+': visible/actual password minimum');
  }
  samples.push({page:file,disabled,width,height,...m});
  await evaluate('scrollTo(0,0)');
  await screenshot(file.replace('.php','')+(disabled?'-disabled':'')+'-'+width+'x'+height,width);
}

async function checkControlsAndPost(file) {
  await navigate(file,390,844);
  check(await evaluate('typeof togglePassword === "function"'),file+': password toggle script loaded');
  for(const id of file==='login.php'?['password']:['password','confirmPassword']) {
    await evaluate(id=>document.querySelector(`[aria-controls="${id}"]`).focus(),id);
    await waitFor('document.activeElement.classList.contains("toggle-password")');
    await command('Input.dispatchKeyEvent',{type:'rawKeyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,nativeVirtualKeyCode:13});
    await command('Input.dispatchKeyEvent',{type:'char',key:'Enter',code:'Enter',text:'\r',windowsVirtualKeyCode:13});
    await command('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    await waitFor('document.querySelector("input[id='+id+']").type==="text"');
    const revealed=await evaluate(id=>({type:document.getElementById(id).type,pressed:document.querySelector(`[aria-controls="${id}"]`).getAttribute('aria-pressed'),active:document.activeElement.outerHTML}),id);
    check(revealed.type==='text'&&revealed.pressed==='true',file+': keyboard reveals '+id+' '+JSON.stringify(revealed));
    await evaluate(id=>document.querySelector(`[aria-controls="${id}"]`).click(),id);
    check(await evaluate(id=>document.getElementById(id).type==='password',id),file+': click hides '+id);
  }
  const previous=posts.length;
  await evaluate(()=>{
    const values={registration_code:'isolated-fixture-code',employee_id:'FIXTURE-01',fullname:'Isolated UI Fixture',email:'fixture@ceu.edu.ph',password:'Fixture-password-42!',confirm_password:'Fixture-password-42!'};
    for(const input of document.querySelectorAll('input'))input.value=values[input.name];
    document.querySelector('form').requestSubmit();
  });
  await waitFor('document.readyState==="complete" && document.body.textContent.includes("Isolated POST received")');
  check(posts.length===previous+1&&posts.at(-1).method==='POST'&&posts.at(-1).file===file.replace('.php','_process.php'),file+': browser submits real named fields through POST');
  const names=file==='login.php'?['email','password']:['registration_code','employee_id','fullname','email','password','confirm_password'];
  check(JSON.stringify(Object.keys(posts.at(-1).fields))===JSON.stringify(names),file+': correct backend field names in POST body');
}

function sharedStyleSnapshot() {
  return [...document.querySelectorAll('body,.background-overlay,.forgot-card,.logo-main,h2,.subtitle,input,button,.register-text,a')].map(el=>{
    const s=getComputedStyle(el),r=el.getBoundingClientRect();
    return {tag:el.tagName,classes:el.className,rect:{x:r.x,y:r.y,width:r.width,height:r.height},color:s.color,background:s.background,margin:s.margin,padding:s.padding,border:s.border,overflow:s.overflow,font:s.font};
  });
}

async function run() {
  assert(browser,'Chrome/Edge required');
  fs.mkdirSync(output,{recursive:true});
  server=http.createServer(serve);server.listen(0,'127.0.0.1');await once(server,'listening');
  origin='http://127.0.0.1:'+server.address().port;
  profile=fs.mkdtempSync(path.join(os.tmpdir(),'prism-auth-visual-'));
  child=spawn(browser,['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--disable-extensions','about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
  const debuggerUrl=await new Promise((resolve,reject)=>{
    const timer=setTimeout(()=>reject(new Error('Browser startup timeout')),15000);let log='';
    child.once('error',reject);child.stderr.on('data',chunk=>{log+=chunk;const match=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timer);resolve(match[1]);}});
  });
  socket=new WebSocket(debuggerUrl);await once(socket,'open');
  socket.addEventListener('message',event=>{
    const message=JSON.parse(event.data);
    if(message.id&&pending.has(message.id)){const p=pending.get(message.id);pending.delete(message.id);message.error?p.reject(new Error(message.error.message)):p.resolve(message.result);}
    else if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.text);
    else if(message.method==='Fetch.requestPaused'){
      const {requestId,request}=message.params;
      const allowed=request.url.startsWith(origin+'/')||/^https:\/\/(fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//.test(request.url);
      command(allowed?'Fetch.continueRequest':'Fetch.fulfillRequest',allowed?{requestId}:{requestId,responseCode:200,body:''}).catch(error=>{if(error.message!=='Invalid InterceptionId.')errors.push(String(error));});
    }
  });
  const target=await command('Target.createTarget',{url:'about:blank'},true);
  sessionId=(await command('Target.attachToTarget',{targetId:target.targetId,flatten:true},true)).sessionId;
  await command('Page.enable');await command('Page.bringToFront');await command('Runtime.enable');await command('Fetch.enable',{patterns:[{urlPattern:'*'}]});
  for(const file of ['login.php','register.php']) {
    for(const [width,height] of viewports){await checkPage(file,width,height);console.log('PASS '+file+' '+width+'x'+height);}
    await checkControlsAndPost(file);
  }
  for(const [width,height] of viewports)await checkPage('register.php',width,height,true);
  for(const file of ['login.php','register.php']) {
    await navigate(file+'?flash=1',320,568);
    check(await evaluate('!window.fixtureXss && document.querySelector(".error-message").textContent.includes("<script>") && document.documentElement.scrollWidth<=document.documentElement.clientWidth'),file+': escaped server error preserved and wraps');
    await screenshot(file.replace('.php','')+'-error-320x568',320);
  }
  for(const file of ['forgot_password.php','reset_password.php','change_password_required.php']) {
    await navigate(file+'?baseline=1',1366,768);const before=await evaluate(sharedStyleSnapshot);
    await navigate(file,1366,768);const after=await evaluate(sharedStyleSnapshot);
    fs.writeFileSync(path.join(output,file.replace('.php','')+'-style-comparison.json'),JSON.stringify({before,after},null,2));
    if(file==='forgot_password.php')check(JSON.stringify(before)===JSON.stringify(after),file+': computed shared styles unchanged');
    else {
      check(await evaluate('document.body.classList.contains("unified-login") && getComputedStyle(document.querySelector("button[type=submit]")).backgroundColor==="rgb(12, 14, 63)"'),file+': shared Login visual treatment');
      check(await evaluate('[...document.querySelectorAll(".toggle-password")].every(e=>e.tagName==="BUTTON" && e.type==="button" && !!e.getAttribute("aria-controls") && e.getAttribute("aria-label").startsWith("Show "))'),file+': accessible standard eye buttons');
    }
    if(file==='reset_password.php') {
      await evaluate('document.getElementById("togglePassword").click()');
      check(await evaluate('document.getElementById("password").type==="text"'),file+': legacy password toggle still works');
    }
  }
  for(const [width,height] of viewports) {
    await navigate('login.php?expired=1',width,height);
    const expired=await evaluate(()=>{const e=document.querySelector('.error-message'),s=getComputedStyle(e);return {text:e.textContent,font:parseFloat(s.fontSize),line:parseFloat(s.lineHeight)/parseFloat(s.fontSize),fits:e.scrollWidth<=e.clientWidth+1};});
    check(expired.text==='Your session expired due to inactivity. Please sign in again.' && expired.font>=12 && expired.font<=13 && expired.line>=1.4 && expired.line<=1.5 && expired.fits,'Expiry '+width+': exact wording, smaller type and wrapping');
    await navigate('login.php?flash=1',width,height);
    const normal=await evaluate(()=>{const e=document.querySelector('.error-message');return {font:getComputedStyle(e).fontSize,special:e.classList.contains('session-expiry-notice')};});
    await navigate('login.php?flash=1&baseline=1',width,height);
    check(normal.font===await evaluate('getComputedStyle(document.querySelector(".error-message")).fontSize') && !normal.special,'Normal Login errors unchanged at '+width);
    for(const file of ['reset_password.php','change_password_required.php']) {
      await navigate(file,width,height);
      check(await evaluate('document.documentElement.scrollWidth<=innerWidth && document.querySelector("form").getBoundingClientRect().right<=innerWidth'),file+'/'+width+': auth form fits');
      for(const button of await evaluate('[...document.querySelectorAll(".toggle-password")].map(e=>e.getAttribute("aria-controls"))')) {
        await evaluate(id=>document.querySelector('[aria-controls="'+id+'"]').focus(),button);
        await command('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
        await command('Input.dispatchKeyEvent',{type:'char',key:'Enter',code:'Enter',text:'\r',windowsVirtualKeyCode:13});
        await command('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
        check(await evaluate(id=>document.getElementById(id).type==='text' && document.querySelector('[aria-controls="'+id+'"]').getAttribute('aria-label').startsWith('Hide '),button),file+'/'+width+': keyboard eye '+button);
      }
    }
  }
  for(const file of ['login.php','register.php'])check(fs.readFileSync(path.join(root,file),'utf8').replaceAll('\r\n','\n').split('?>')[0]===baseline(file).replaceAll('\r\n','\n').split('?>')[0],file+': PHP bootstrap/session/redirect unchanged');
  for(const file of ['login_process.php','register_process.php','forgot_password_process.php','update_password.php','security.php'])check(fs.readFileSync(path.join(root,file),'utf8').replaceAll('\r\n','\n')===baseline(file).replaceAll('\r\n','\n'),file+': backend unchanged');
  check(errors.length===0,'No browser/PHP fixture errors: '+errors.join('; '));
  const fontsLoaded=samples.every(m=>m.fontLoaded&&(m.disabled||m.iconsLoaded));
  fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({checks,errors,samples,fontsLoaded},null,2));
  console.log(checks+' auth visual/browser checks passed. Screenshots: '+output);
  console.log('External font/icon loading: '+(fontsLoaded?'passed':'unavailable; fallback fonts used'));
}
run().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
  if(socket?.readyState===WebSocket.OPEN){await command('Browser.close',{},true).catch(()=>{});socket.close();}
  if(child&&child.exitCode===null){await Promise.race([once(child,'exit'),new Promise(resolve=>setTimeout(resolve,3000))]);if(child.exitCode===null)child.kill();}
  if(server){server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
  if(profile){const resolved=path.resolve(profile);assert.equal(path.dirname(resolved),path.resolve(os.tmpdir()));assert(path.basename(resolved).startsWith('prism-auth-visual-'));fs.rmSync(resolved,{recursive:true,force:true,maxRetries:10,retryDelay:200});}
});
