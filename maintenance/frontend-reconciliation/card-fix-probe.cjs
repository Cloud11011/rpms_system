'use strict';
// Proposal check for the 390 px IERB Progress card (never edits the repository): restart a finished disposable fixture,
// open the RECONCILED IERB Progress page, then measure every card before and after injecting candidate CSS in the browser only.
// "Pending" cards must be unchanged; a long-status card must regain a readable name column.
// Usage: node card-fix-probe.cjs <review dir> <mariadb bin dir> <db port> <app port>
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
const [review,bin,dbPort,appPort]=process.argv.slice(2);
if(!review||!bin||!dbPort||!appPort)throw new Error('Usage: card-fix-probe.cjs <review dir> <mariadb bin> <db port> <app port>');
const root=path.resolve(__dirname,'../..'),php=process.env.PRISM_TEST_PHP||'C:/xampp/php/php.exe';
const playwright=require(process.env.PRISM_TEST_PLAYWRIGHT||'C:/PRISM_Workspaces/prism-v13-release-clean/PRISM_V13_UI_REMEDIATION_AUDIT/browser-tools/node_modules/playwright-core');
const data=path.join(review,'sandbox/mysql'),app=path.join(review,'sandbox/app'),out=path.join(review,'visual-parity','card-fix');
if(!fs.existsSync(data)||!fs.existsSync(app))throw new Error('The review folder has no disposable fixture and application copy.');
if(!path.resolve(review).startsWith(path.join(root,'tests')))throw new Error('Only a fixture under tests/ is authorized.');
fs.mkdirSync(out,{recursive:true});
const exe=n=>[path.join(bin,n+'.exe')].find(fs.existsSync),server=exe('mariadbd')||exe('mysqld'),client=exe('mariadb')||exe('mysql');
const env={...process.env,PRISM_TEST_REVIEW_DIR:review,PRISM_TEST_DB_PORT:dbPort,PRISM_TEST_APP_PORT:appPort,PRISM_TEST_DB_COLLATION:process.env.PRISM_TEST_DB_COLLATION||'utf8mb4_general_ci'};
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const CANDIDATES={
 // The name keeps a usable column and the badge may wrap onto two lines instead of starving it. Only widths below 1000px are touched.
 'A: identity column minimum + wrapping badge':'@media(max-width:1000px){body.prism-workspace .ierb-pc-head{grid-template-columns:minmax(140px,1fr) minmax(0,auto)}body.prism-workspace .ierb-pc-status .prism-badge{white-space:normal;text-align:center;line-height:1.25}}',
 // Moves the status to its own row on narrow screens (changes the "Pending" layout too).
 'B: status on its own row below 520px':'@media(max-width:520px){body.prism-workspace .ierb-pc-head{grid-template-columns:minmax(0,1fr)}body.prism-workspace .ierb-pc-status{justify-self:start}}',
};
(async()=>{
 const db=cp.spawn(server,['--no-defaults','--datadir='+data,'--innodb-data-home-dir='+data,'--innodb-log-group-home-dir='+data,'--skip-innodb-use-native-aio','--skip-innodb-buffer-pool-load-at-startup','--innodb-buffer-pool-size=64M','--port='+dbPort,'--bind-address=127.0.0.1','--log-error='+path.join(data,'probe-server.err')],{windowsHide:true,stdio:'ignore'});
 let web,browser;
 try{
  for(let i=0;i<100;i++){try{cp.execFileSync(client,['--protocol=TCP','--host=127.0.0.1','--port='+dbPort,'--user=root','--password=prism_synthetic_only','-e','SELECT 1'],{stdio:'ignore'});break;}catch{await sleep(200);if(i===99)throw new Error('Disposable database did not start.');}}
  web=cp.spawn(php,['-d','extension=zip','-d','auto_prepend_file='+path.join(root,'maintenance/ierb-group-tests/runtime.php'),'-d','disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close',
   '-d','session.save_path='+path.join(app,'sessions'),'-d','upload_tmp_dir='+path.join(app,'uploads'),'-S','127.0.0.1:'+appPort,'-t',app],{windowsHide:true,env,stdio:'ignore'});
  const base='http://127.0.0.1:'+appPort;
  for(let i=0;i<80;i++){try{if((await fetch(base+'/login.php')).ok)break;}catch{}await sleep(100);}
  const session=await playwright.request.newContext({baseURL:base});
  const login=await session.post('/login_process.php',{form:{email:'admin@example.test',password:'SyntheticPass123!'},headers:{origin:base},maxRedirects:0});
  if(login.status()!==302)throw new Error('Login failed: '+login.status());
  browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  const identity=crypto.createHash('sha256').update('admin|1').digest('hex');
  const measure=async(width,css)=>{
   const ctx=await browser.newContext({viewport:{width,height:900},storageState:await session.storageState(),reducedMotion:'reduce'});
   await ctx.addInitScript(({identity})=>{try{localStorage.setItem('prismTheme','light');localStorage.setItem(`prismTour.admin.${identity}.v1`,'seen');}catch{}},{identity});
   await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
   const page=await ctx.newPage();await page.goto(base+'/ierbprog.php');
   await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
   if(await page.locator('.prism-sidebar-backdrop:not([hidden])').count())await page.locator('#prismSidebarToggle').click().catch(()=>{});
   await page.locator('.ierb-pc').first().waitFor();
   if(css)await page.addStyleTag({content:css});
   await page.waitForTimeout(400);
   const cards=await page.locator('.ierb-pc').evaluateAll(cs=>cs.map(c=>{const n=c.querySelector('.ierb-pc-identity strong'),b=c.querySelector('.ierb-pc-status .prism-badge, .ierb-pc-status span'),r=n.getBoundingClientRect(),lh=parseFloat(getComputedStyle(n).lineHeight)||17;
    return {name:n.textContent.trim(),badge:b?.textContent.trim(),nameWidth:Math.round(r.width),nameLines:Math.round(r.height/lh),headHeight:Math.round(c.querySelector('.ierb-pc-head').getBoundingClientRect().height),overflowX:c.scrollWidth>c.clientWidth+1};}));
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
   return {cards,pageOverflow:overflow,page,ctx};
  };
  const results=[];
  for(const width of [390,360,320])for(const [label,css] of [['current (reconciled = ZIP CSS)',null],...Object.entries(CANDIDATES)]){
   const m=await measure(width,css);
   results.push({width,css:label,pageOverflow:m.pageOverflow,cards:m.cards});
   if(width===390)await m.page.locator('.ierb-pc').first().screenshot({path:path.join(out,`card-390-${label.split(':')[0].replace(/[^a-z0-9]+/gi,'_')}.png`)});
   await m.ctx.close();
  }
  fs.writeFileSync(path.join(out,'card-fix-probe.json'),JSON.stringify({scope:'Reconciled IERB Progress page on a disposable fixture; candidate CSS injected in the browser only.',candidates:CANDIDATES,results},null,2));
  for(const r of results)console.log(r.width,r.css.padEnd(40),'overflow',r.pageOverflow,r.cards.map(c=>`${c.name.slice(-3)}|${c.badge}:${c.nameWidth}px/${c.nameLines}l/head${c.headHeight}`).join('  '));
  await session.dispose();
 }finally{
  if(browser)await browser.close();if(web)web.kill();
  try{cp.execFileSync(php,[path.join(root,'maintenance/ierb-group-tests/shutdown.php')],{env,stdio:'ignore'});}catch{}
  await sleep(1500);db.kill();
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
