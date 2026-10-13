'use strict';
// Screenshot review artifacts assembled by a local headless browser; no application access.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {spawnSync}=require('node:child_process'),{pathToFileURL}=require('node:url');
const root=path.resolve(__dirname,'..'),out=path.join(root,'tests/frontend-standardization-results');
const data=JSON.parse(fs.readFileSync(path.join(out,'after/browser-results.json'),'utf8'));
const browser=['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
const escape=s=>s.replace(/[&<>\"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const manifest=[];
for(const theme of ['light','dark'])for(const viewport of [1366,320]){
 const captures=data.captures.filter(c=>c.width===viewport&&c.file.endsWith('-'+theme+'.png'));
 for(let i=0;i<captures.length;i+=8){
  const group=captures.slice(i,i+8),name='review-'+viewport+'-'+theme+'-'+(1+i/8);
  const file=path.join(out,name+'.html'),image=path.join(out,name+'.png');
  const width=viewport===320?1280:1600,height=viewport===320?1490:650;
  fs.writeFileSync(file,'<!doctype html><meta charset="utf-8"><style>*{box-sizing:border-box}body{margin:0;padding:8px;background:#e5e7eb;font:11px Arial;color:#111}main{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}figure{margin:0}figcaption{height:24px;overflow-wrap:anywhere}img{display:block;width:100%;height:auto;border:1px solid #888}</style><main>'+group.map(c=>'<figure><figcaption>'+escape(c.file)+'</figcaption><img src="'+pathToFileURL(path.join(out,['after-state','after','after-deadline','after-auth','after-targeted'].find(folder=>fs.existsSync(path.join(out,folder,c.file))),c.file)).href+'"></figure>').join('')+'</main>');
  const profile=fs.mkdtempSync(path.join(os.tmpdir(),'prism-frontend-review-'));
  const result=spawnSync(browser,['--headless=new','--disable-gpu','--no-sandbox','--no-first-run','--disable-background-networking','--user-data-dir='+profile,'--window-size='+width+','+height,'--screenshot='+image,'--hide-scrollbars',pathToFileURL(file).href],{windowsHide:true,encoding:'utf8',timeout:30000});
  if(result.status!==0||!fs.existsSync(image))throw Error('Contact sheet screenshot failed: '+name);
  // Each resolved profile is freshly owned and confined to the OS temporary directory.
  if(path.dirname(path.resolve(profile))!==path.resolve(os.tmpdir())||!path.basename(profile).startsWith('prism-frontend-review-'))throw Error('Unsafe profile path');
  try{fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});}catch{}
  manifest.push({file:name+'.png',screens:group.map(c=>c.file)});console.log(name);
 }
}
fs.writeFileSync(path.join(out,'contact-sheets.json'),JSON.stringify(manifest,null,2));
