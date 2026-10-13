'use strict';
// Source-copy harness: installed private configuration is never copied or loaded.
const fs=require('node:fs'),path=require('node:path'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),out=path.join(root,'tests/frontend-standardization-results');
const phase=process.argv[2]||'after';if(!['before','after'].includes(phase))throw Error('Invalid evidence phase');
const source=phase==='before'?path.join(out,'baseline-source'):path.join(out,process.env.PRISM_FRONTEND_SOURCE || 'after-source-2');
if(phase==='after'){
 if(fs.existsSync(source))throw Error('After source exists; use a distinct evidence directory for a justified rerun.');
 fs.mkdirSync(source,{recursive:true});
 for(const file of fs.readdirSync(root))if(file.endsWith('.php')&&file!=='config.local.php')fs.copyFileSync(path.join(root,file),path.join(source,file));
 for(const folder of ['assets','includes','vendor'])fs.cpSync(path.join(root,folder),path.join(source,folder),{recursive:true});
 fs.mkdirSync(path.join(source,'tests'));
 for(const name of fs.readdirSync(path.join(root,'tests'))){if(/results|__pycache__|composer-cache|ui-restyle-reference/.test(name))continue;fs.cpSync(path.join(root,'tests',name),path.join(source,'tests',name),{recursive:true});}
 const cache='tests/v10-batch1075-results/remediation/cdn-cache';fs.cpSync(path.join(root,cache),path.join(source,cache),{recursive:true});
 fs.mkdirSync(path.join(source,'tools'));
 fs.copyFileSync(path.join(root,'tools/verify-account-lifecycle-schema.php'),path.join(source,'tools/verify-account-lifecycle-schema.php'));
}
fs.copyFileSync(path.join(root,'tests/frontend-standardization-browser.cjs'),path.join(source,'tests/frontend-standardization-browser.cjs'));
const http=path.join(source,'tests/v10-batch10-http.php');let text=fs.readFileSync(path.join(root,'tests/v10-batch10-http.php'),'utf8');
// Old concurrent workers omitted STORAGE_DIR. Explicitly bind it to the verified owned datadir.
const worker=path.join(source,'tests/v10-batch10-worker.php');let workerSource=fs.readFileSync(worker,'utf8');
if(!workerSource.includes("define('STORAGE_DIR'"))workerSource=workerSource.replace("date_default_timezone_set('Asia/Manila');", "define('STORAGE_DIR',dirname(realpath($case['datadir'])).'/storage');\ndate_default_timezone_set('Asia/Manila');");
fs.writeFileSync(worker,workerSource);
text=text.replace("'/batch11-browser.cjs'","'/frontend-standardization-browser.cjs'");
const hook=`
        // Browser-only fixture state, installed exclusively into this owned temporary source root.
        file_put_contents($httpRoot.'/fixture-frontend-state.php', <<<'PHP'
<?php
require __DIR__.'/config.php';
$pdo=db(); $mode=$_POST['mode']??'';
if($mode==='password'){$pdo->exec('UPDATE users SET must_change_password=1 WHERE id=1006');$_SESSION['must_change_password']=true;}
if($mode==='restore-password'){$pdo->exec('UPDATE users SET must_change_password=0 WHERE id=1006');unset($_SESSION['must_change_password']);}
if($mode==='consent'){$pdo->exec('DELETE FROM user_policy_acceptances WHERE user_id=1006');}
if($mode==='restore-consent'){$q=$pdo->query('SELECT * FROM users WHERE id=1006');legal_accept($pdo,$q->fetch(),['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);}
if($mode==='profile'){$pdo->exec('UPDATE students SET profile_completed_at=NULL WHERE id=1003');}
if($mode==='restore-profile'){$pdo->exec('UPDATE students SET profile_completed_at=NOW() WHERE id=1003');}
echo 'owned fixture state';
PHP
        );
        $pdo->prepare('INSERT INTO password_resets(user_id,token,expires_at,used) VALUES(1003,?,DATE_ADD(NOW(),INTERVAL 1 HOUR),0)')->execute(['sha256:'.hash('sha256','frontend-owned-reset')]);
`;
text=text.replace("        $legalBaseline=legal_current($pdo);",hook+"        $legalBaseline=legal_current($pdo);");
fs.writeFileSync(http,text);
const args=['-d','extension=zip',path.join(source,'tests/v10-batch7-native.php'),'--only10','--batch10','--batch11','--browser1075','--acceptance1075',...(phase==='before'||process.env.PRISM_FRONTEND_UI_ONLY==='1'?['--before1075','--uionly1075']:[]),'C:/xampp/mysql/bin'];
const run=spawn('C:/xampp/php/php.exe',args,{windowsHide:true,env:{...process.env,PRISM_FRONTEND_PHASE:phase,PRISM_FRONTEND_EVIDENCE:out},stdio:['ignore','pipe','pipe']});
const logName=process.env.PRISM_FRONTEND_CAPTURE_SET||phase;
if(!/^(before|after)(-[a-z]+)?$/.test(logName))throw Error('Invalid evidence log name');
const log=fs.createWriteStream(path.join(out,logName+'-run.log'));
run.stdout.on('data',b=>{process.stdout.write(b);log.write(b);});run.stderr.on('data',b=>{process.stderr.write(b);log.write(b);});
run.on('error',e=>{console.error(e);log.end();process.exitCode=1;});
run.on('close',code=>{log.end();process.exitCode=code??1;});
