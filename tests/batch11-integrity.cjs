'use strict';
// Read-only integrity evidence. Never read or print installed configuration/credentials/database contents.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),cp=require('node:child_process');
const root=path.join(__dirname,'..'),out=path.join(__dirname,'batch11-results');fs.mkdirSync(out,{recursive:true});
const git=args=>cp.execFileSync('git',['-c','core.safecrlf=false',...args],{cwd:root,maxBuffer:16*1024*1024});
const protectedPaths=JSON.parse(fs.readFileSync(path.join(__dirname,'v10-batch1075-results/remediation/release-integrity.json'),'utf8')).protected_files.map(x=>x.path);
const protectedFiles=protectedPaths.map(file=>({file,unchanged:fs.readFileSync(path.join(root,file),'utf8').replace(/\r\n/g,'\n')===git(['show','HEAD:'+file]).toString().replace(/\r\n/g,'\n'),sha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex')}));
const files=git(['diff','--name-only']).toString().trim().split(/\r?\n/).filter(Boolean),untracked=git(['ls-files','--others','--exclude-standard']).toString().trim().split(/\r?\n/).filter(Boolean);
const hashes=files.concat(untracked.filter(f=>f.includes('batch11')||f.includes('BATCH11')||f==='includes/public_landing.php')).filter(f=>!f.endsWith('.md')).map(file=>({file,sha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex')}));
const diffCheck=cp.spawnSync('git',['-c','core.safecrlf=false','diff','--check'],{cwd:root,encoding:'utf8',windowsHide:true});
const result={head:git(['rev-parse','HEAD']).toString().trim(),branch:git(['branch','--show-current']).toString().trim(),protectedFiles,files,untracked,hashes,diffCheck:{exit:diffCheck.status,output:diffCheck.stdout+diffCheck.stderr},trackedDiffStat:git(['diff','--stat']).toString(),productionOperations:'No installed config, provider, schema or production database writes; no Git publication commands.'};
fs.writeFileSync(path.join(out,'integrity.json'),JSON.stringify(result,null,2));fs.writeFileSync(path.join(out,'diff-stat.txt'),result.trackedDiffStat);console.log('Protected '+protectedFiles.filter(f=>f.unchanged).length+'/'+protectedFiles.length+'; diff check '+diffCheck.status+'; HEAD '+result.head);process.exitCode=protectedFiles.every(f=>f.unchanged)&&diffCheck.status===0?0:1;
