'use strict';
// Export the exact accepted Git source; do not switch branches, touch installed config or use installed DB.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const root=path.join(__dirname,'..'),out=path.join(__dirname,'batch11-results'),snapshot=path.join(out,'baseline-source');fs.mkdirSync(snapshot,{recursive:true});
const head=cp.execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim();
const files=cp.execFileSync('git',['ls-files','-z'],{cwd:root,encoding:'utf8'}).split('\0').filter(f=>f&&!f.endsWith('.md')&&!f.endsWith('.patch')&&!f.endsWith('.txt')&&!f.includes('results')&&f!=='PRISM_BATCH10_75_DEPLOY_MANIFEST.json');
for(const file of files){const target=path.join(snapshot,file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,cp.execFileSync('git',['show',head+':'+file],{cwd:root,maxBuffer:16*1024*1024}));}
fs.cpSync(path.join(root,'vendor'),path.join(snapshot,'vendor'),{recursive:true});
const http=path.join(snapshot,'tests/v10-batch10-http.php');let s=fs.readFileSync(http,'utf8');
s=s.replace("if(in_array('--browser1075',$argv,true)){","if(in_array('--batch11',$argv,true)) require __DIR__.'/batch11-http.php';\n    if(in_array('--browser1075',$argv,true)){");
s=s.replace("__DIR__.'/v10-batch1075-ui.cjs'","__DIR__.'/batch11-browser.cjs'");fs.writeFileSync(http,s);
for(const file of ['batch11-browser.cjs','batch11-http.php'])fs.copyFileSync(path.join(__dirname,file),path.join(snapshot,'tests',file));
fs.cpSync(path.join(__dirname,'v10-batch1075-results/remediation/cdn-cache'),path.join(snapshot,'tests/v10-batch1075-results/remediation/cdn-cache'),{recursive:true});
const php='C:/xampp/php/php.exe';const run=cp.spawnSync(php,['-d','extension=zip',path.join(snapshot,'tests/v10-batch7-native.php'),'--only10','--batch10','--batch11','--browser1075','--before1075','C:/xampp/mysql/bin'],{windowsHide:true,encoding:'utf8',timeout:600000,maxBuffer:16*1024*1024});
fs.writeFileSync(path.join(out,'baseline-browser.log'),(run.stdout||'')+(run.stderr||''));
const evidence=path.join(snapshot,'tests/batch11-results/before');if(fs.existsSync(evidence))fs.cpSync(evidence,path.join(out,'before'),{recursive:true});
fs.writeFileSync(path.join(out,'baseline-source.json'),JSON.stringify({head,files:files.length,exit:run.status,source:'git show; frozen production files exported exactly; only test harness hooks added',snapshot},null,2));console.log(run.stdout+run.stderr);process.exitCode=run.status||0;
