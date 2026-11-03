'use strict';
// Validation of the approved single mobile rule for the IERB Progress card (ierbprog.css).
// Restarts a finished disposable fixture, serves the CURRENT working-tree stylesheet, and renders the real page at many widths
// in both themes twice: with the working-tree CSS ("new") and with the committed previous CSS served through a browser route ("old").
// Output: PNGs for a pixel comparison (card-validate-compare.py) and per-card measurements.
// Usage: node card-validate.cjs <review dir> <mariadb bin dir> <db port> <app port>
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
const [review,bin,dbPort,appPort]=process.argv.slice(2);
if(!review||!bin||!dbPort||!appPort)throw new Error('Usage: card-validate.cjs <review dir> <mariadb bin> <db port> <app port>');
const root=path.resolve(__dirname,'../..'),php=process.env.PRISM_TEST_PHP||'C:/xampp/php/php.exe';
const playwright=require(process.env.PRISM_TEST_PLAYWRIGHT||'C:/PRISM_Workspaces/prism-v13-release-clean/PRISM_V13_UI_REMEDIATION_AUDIT/browser-tools/node_modules/playwright-core');
const data=path.join(review,'sandbox/mysql'),app=path.join(review,'sandbox/app'),out=path.join(review,'visual-parity','card-validate');
if(!fs.existsSync(data)||!fs.existsSync(app))throw new Error('The review folder has no disposable fixture and application copy.');
if(!path.resolve(review).startsWith(path.join(root,'tests')))throw new Error('Only a fixture under tests/ is authorized.');
fs.mkdirSync(out,{recursive:true});
// The disposable application copy receives the working-tree stylesheet; the repository is never written.
fs.copyFileSync(path.join(root,'assets/css/ierbprog.css'),path.join(app,'assets/css/ierbprog.css'));
const oldCss=cp.execFileSync('git',['show','HEAD:assets/css/ierbprog.css'],{cwd:root,maxBuffer:1<<26});
const exe=n=>[path.join(bin,n+'.exe')].find(fs.existsSync),server=exe('mariadbd')||exe('mysqld'),client=exe('mariadb')||exe('mysql');
const env={...process.env,PRISM_TEST_REVIEW_DIR:review,PRISM_TEST_DB_PORT:dbPort,PRISM_TEST_APP_PORT:appPort,PRISM_TEST_DB_COLLATION:process.env.PRISM_TEST_DB_COLLATION||'utf8mb4_general_ci'};
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const WIDTHS=[320,360,390,520,521,700,1000,1024,1440];
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
  const served=await (await session.get('/assets/css/ierbprog.css')).text();
  if(!served.includes('max-width:520px) { body.prism-workspace .ierb-pc-head'))throw new Error('The application copy does not serve the new rule.');
  browser=await playwright.chromium.launch({executablePath:process.env.PRISM_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  const identity=crypto.createHash('sha256').update('admin|1').digest('hex');
  const results=[];
  for(const theme of ['light','dark'])for(const width of WIDTHS)for(const variant of ['old','new']){
   const ctx=await browser.newContext({viewport:{width,height:900},storageState:await session.storageState(),reducedMotion:'reduce'});
   await ctx.addInitScript(({identity,theme})=>{try{localStorage.setItem('prismTheme',theme);localStorage.setItem(`prismTour.admin.${identity}.v1`,'seen');}catch{}},{identity,theme});
   await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
   if(variant==='old')await ctx.route(/\/assets\/css\/ierbprog\.css(\?.*)?$/,r=>r.fulfill({contentType:'text/css',body:oldCss}));
   const page=await ctx.newPage();await page.clock.setFixedTime(new Date('2026-11-02T15:00:00Z'));
   await page.goto(base+'/ierbprog.php');
   await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
   if(await page.locator('.prism-sidebar-backdrop:not([hidden])').count())await page.locator('#prismSidebarToggle').click().catch(()=>{});
   await page.locator('.ierb-pc').first().waitFor();await page.waitForLoadState('networkidle').catch(()=>{});
   // Lazy images must be decoded before a pixel comparison, exactly as in visual-parity.cjs.
   await page.locator('img').evaluateAll(images=>Promise.all(images.map(image=>{image.loading='eager';return image.decode().catch(()=>{});})));await page.waitForTimeout(500);
   const cards=await page.locator('.ierb-pc').evaluateAll(cs=>cs.map(c=>{const n=c.querySelector('.ierb-pc-identity strong'),b=c.querySelector('.ierb-pc-status .prism-badge, .ierb-pc-status span'),r=n.getBoundingClientRect(),lh=parseFloat(getComputedStyle(n).lineHeight)||17;
    const nb=n.getBoundingClientRect(),bb=b?b.getBoundingClientRect():null,hb=c.querySelector('.ierb-pc-head').getBoundingClientRect();
    return {badge:b?.textContent.trim(),nameWidth:Math.round(r.width),nameLines:Math.round(r.height/lh),headHeight:Math.round(hb.height),
     badgeBelowName:bb?bb.top>=nb.bottom-1:null,badgeInsideCard:bb?(bb.right<=c.getBoundingClientRect().right+1&&bb.left>=c.getBoundingClientRect().left-1):null,cardOverflowX:c.scrollWidth>c.clientWidth+1};}));
   const pageOverflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
   const file=`ierbprog-${theme}-${width}-${variant}.png`;await page.screenshot({path:path.join(out,file),fullPage:true,animations:'disabled',caret:'hide'});
   results.push({theme,width,variant,pageOverflow,cards,file});await ctx.close();
  }
  fs.writeFileSync(path.join(out,'card-validate.json'),JSON.stringify({scope:'Reconciled IERB Progress page on a disposable fixture; old = committed CSS, new = working-tree CSS.',widths:WIDTHS,results},null,2));
  for(const r of results.filter(x=>x.variant==='new'))console.log(r.theme.padEnd(5),String(r.width).padEnd(5),'pageOverflow',r.pageOverflow,r.cards.map(c=>`${c.badge}:${c.nameWidth}px/${c.nameLines}l${c.badgeBelowName?' below':''}`).join('  '));
  await session.dispose();
 }finally{
  if(browser)await browser.close();if(web)web.kill();
  try{cp.execFileSync(php,[path.join(root,'maintenance/ierb-group-tests/shutdown.php')],{env,stdio:'ignore'});}catch{}
  await sleep(1500);db.kill();
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
