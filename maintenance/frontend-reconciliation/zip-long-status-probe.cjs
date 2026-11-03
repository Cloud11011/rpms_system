'use strict';
// Does the ORIGINAL ZIP show the same 390 px card collapse when a Student's status text is long?
// Restarts a finished disposable fixture database (never a production one), serves the restricted ZIP source on a spare
// port, sets one Student's status to long values, screenshots the ZIP's IERB Progress card at 390 px, then restores the value.
// Usage: node zip-long-status-probe.cjs <review dir> <mariadb bin dir> <db port> <app port>
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
const [review,bin,dbPort,appPort]=process.argv.slice(2);
if(!review||!bin||!dbPort||!appPort)throw new Error('Usage: zip-long-status-probe.cjs <review dir> <mariadb bin> <db port> <app port>');
const root=path.resolve(__dirname,'../..'),php=process.env.PRISM_TEST_PHP||'C:/xampp/php/php.exe';
const playwright=require(process.env.PRISM_TEST_PLAYWRIGHT||'C:/PRISM_Workspaces/prism-v13-release-clean/PRISM_V13_UI_REMEDIATION_AUDIT/browser-tools/node_modules/playwright-core');
const data=path.join(review,'sandbox/mysql'),app=path.join(review,'sandbox/reference-app'),out=path.join(review,'visual-parity','zip-long-status');
if(!fs.existsSync(data)||!fs.existsSync(app))throw new Error('The review folder has no disposable fixture and reference app.');
if(!path.resolve(review).startsWith(path.join(root,'tests')))throw new Error('Only a fixture under tests/ is authorized.');
fs.mkdirSync(out,{recursive:true});
const exe=n=>[path.join(bin,n+'.exe')].find(fs.existsSync);
const server=exe('mariadbd')||exe('mysqld'),client=exe('mariadb')||exe('mysql');
const env={...process.env,PRISM_TEST_REVIEW_DIR:review,PRISM_TEST_DB_PORT:dbPort,PRISM_TEST_APP_PORT:appPort,PRISM_TEST_DB_COLLATION:process.env.PRISM_TEST_DB_COLLATION||'utf8mb4_general_ci'};
const sql=q=>cp.execFileSync(client,['--protocol=TCP','--host=127.0.0.1','--port='+dbPort,'--user=root','--password=prism_synthetic_only','--batch','--database=prism_group_synthetic_v11','-e',q],{encoding:'utf8'});
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const db=cp.spawn(server,['--no-defaults','--datadir='+data,'--innodb-data-home-dir='+data,'--innodb-log-group-home-dir='+data,'--skip-innodb-use-native-aio','--skip-innodb-buffer-pool-load-at-startup',
  '--innodb-buffer-pool-size=64M','--port='+dbPort,'--bind-address=127.0.0.1','--log-error='+path.join(data,'probe-server.err')],{windowsHide:true,stdio:'ignore'});
 let web,browser;const before=()=>{};
 try{
  for(let i=0;i<100;i++){try{sql('SELECT 1');break;}catch{await sleep(200);if(i===99)throw new Error('Disposable database did not start.');}}
  const original=sql('SELECT status FROM students WHERE id=4').split('\n')[1];
  web=cp.spawn(php,['-d','extension=zip','-d','auto_prepend_file='+path.join(root,'maintenance/ierb-group-tests/runtime.php'),
   '-d','disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close',
   '-d','session.save_path='+path.join(app,'sessions'),'-d','upload_tmp_dir='+path.join(app,'uploads'),'-S','127.0.0.1:'+appPort,'-t',app],{windowsHide:true,env,stdio:'ignore'});
  const base='http://127.0.0.1:'+appPort;
  for(let i=0;i<80;i++){try{if((await fetch(base+'/login.php')).ok)break;}catch{}await sleep(100);}
  const session=await playwright.request.newContext({baseURL:base});
  const login=await session.post('/login_process.php',{form:{email:'admin@example.test',password:'SyntheticPass123!'},headers:{origin:base},maxRedirects:0});
  if(login.status()!==302)throw new Error('ZIP login failed: '+login.status());
  browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  const identity=crypto.createHash('sha256').update('admin|1').digest('hex');
  const results=[];
  for(const status of [original,'IERB Completed','Correction open']){
   sql("UPDATE students SET status='"+status.replace(/'/g,"''")+"' WHERE id=4");
   const ctx=await browser.newContext({viewport:{width:390,height:900},storageState:await session.storageState(),reducedMotion:'reduce'});
   await ctx.addInitScript(({identity})=>{try{localStorage.setItem('prismTheme','light');localStorage.setItem(`prismTour.admin.${identity}.v1`,'seen');}catch{}},{identity});
   await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
   const page=await ctx.newPage();await page.goto(base+'/ierbprog.php');
   await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
   if(await page.locator('.prism-sidebar-backdrop:not([hidden])').count())await page.locator('#prismSidebarToggle').click().catch(()=>{});
   await page.locator('.ierb-pc').first().waitFor();await page.waitForTimeout(500);
   const card=page.locator('.ierb-pc').first();
   const measure=await card.evaluate(c=>{const n=c.querySelector('.ierb-pc-identity strong'),b=c.querySelector('.ierb-pc-status .prism-badge, .ierb-pc-status span'),r=n.getBoundingClientRect();
    return {nameWidth:Math.round(r.width),nameHeight:Math.round(r.height),nameLines:Math.round(r.height/parseFloat(getComputedStyle(n).lineHeight||'16')),badgeText:b?.textContent.trim(),badgeWidth:Math.round(b?.getBoundingClientRect().width||0)};});
   const file=`zip-390-${status.replace(/[^a-z0-9]+/gi,'_')}.png`;
   await card.screenshot({path:path.join(out,file)});
   results.push({zipStatus:status,...measure,file});await ctx.close();
  }
  sql("UPDATE students SET status='"+original.replace(/'/g,"''")+"' WHERE id=4");
  fs.writeFileSync(path.join(out,'zip-long-status-probe.json'),JSON.stringify({scope:'Original ZIP source, 390px, IERB Progress card of one Student, status text varied in the disposable fixture only.',results},null,2));
  console.log(JSON.stringify(results,null,1));
  await session.dispose();
 }finally{
  if(browser)await browser.close();if(web)web.kill();
  try{cp.execFileSync(php,[path.join(root,'maintenance/ierb-group-tests/shutdown.php')],{env,stdio:'ignore'});}catch{}
  await sleep(1500);db.kill();
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
