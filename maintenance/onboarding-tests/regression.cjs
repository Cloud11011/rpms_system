const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const root=path.resolve(__dirname,'../..'),review=process.env.PRISM_TEST_REVIEW_DIR;
if(!review || !path.resolve(review).startsWith(root+path.sep))throw Error('A dedicated workspace fixture is required.');
const evidence=path.join(review,'regression');fs.mkdirSync(evidence,{recursive:true});
const php=process.env.PRISM_TEST_PHP;
function run(label,file,flags={},input=null){
 let suffix=1,out=path.join(evidence,label);while(fs.existsSync(out))out=path.join(evidence,label+'-r'+(++suffix));fs.mkdirSync(out,{recursive:true});
 const result=cp.spawnSync(file.endsWith('.cjs')?'node':php,[file],{cwd:root,windowsHide:true,encoding:'utf8',maxBuffer:20*1024*1024,
  env:{...process.env,PRISM_TEST_EVIDENCE_DIR:out,...flags},input:input?JSON.stringify(input):undefined});
 fs.writeFileSync(path.join(out,'console.txt'),result.stdout+'\n'+result.stderr);
 for(const f of ['test-results.json','test-results-documents.json','test-failure.json'])if(fs.existsSync(path.join(review,f)))fs.copyFileSync(path.join(review,f),path.join(out,f));
 console.log(label, result.status, result.stdout.trim().split('\n').slice(-1)[0]);
 if(result.status!==0)throw Error(label+' failed; inspect '+out);
 if(file.endsWith('.php') && JSON.parse(result.stdout).ok===false)throw Error(label+' refused; inspect '+out);
}
const suite='maintenance/ierb-group-tests/run.cjs',documents={PRISM_TEST_PHASE:'documents'};
const phase=process.argv[2]||'first';
if(phase==='first'||phase==='resume12'){
 if(phase==='first'){
 run('01-schema11',suite,{PRISM_TEST_BATCH6:'1'});
 run('02-foundation','maintenance/ierb-v14-tests/run.cjs');
 const r=cp.spawnSync(php,['maintenance/ierb-v14-tests/cases.php','apply-for-regression'],{encoding:'utf8',windowsHide:true});if(r.status)throw Error(r.stderr);
 }
 run('03-schema12-all',suite,{PRISM_TEST_SCHEMA_VERSION:'12',PRISM_TEST_V14_DOCUMENTS:'1',PRISM_TEST_PROJECT_GROUP:'1',PRISM_TEST_REPORT_SCHEMA14:'1',PRISM_TEST_MONITOR_SCHEMA15:'1',PRISM_TEST_BATCH6:'1'});
 run('04-reports',suite,{...documents,PRISM_TEST_SCHEMA_VERSION:'12',PRISM_TEST_BATCH4:'1'});
 run('05-monitor',suite,{...documents,PRISM_TEST_SCHEMA_VERSION:'12',PRISM_TEST_BATCH5:'1'});
 run('install15','maintenance/ierb-monitor-tests/fixture.php',{}, {action:'install'});
 run('06-schema15-ui',suite,{...documents,PRISM_TEST_SCHEMA_VERSION:'15',PRISM_TEST_BATCH6:'1'});
 run('07-schema15-7r',suite,{...documents,PRISM_TEST_SCHEMA_VERSION:'15',PRISM_TEST_BATCH7R:'1'});
 run('08-schema15-closure',suite,{...documents,PRISM_TEST_SCHEMA_VERSION:'15',PRISM_TEST_CLOSURE:'1'});
}else if(phase==='extended'){
 run('11-extension-migration','maintenance/onboarding-tests/fixture.php',{}, {action:'extension'});
 run('11b-migration-approval-race','maintenance/onboarding-tests/migration-race.cjs');
 run('12-extended-schema15-7r',suite,{...documents,PRISM_TEST_SCHEMA_VERSION:'15',PRISM_TEST_BATCH7R:'1'});
 run('13-extended-schema15-closure',suite,{...documents,PRISM_TEST_SCHEMA_VERSION:'15',PRISM_TEST_CLOSURE:'1'});
 run('13b-historical-guards','maintenance/onboarding-tests/fixture.php',{}, {action:'historical-guards'});
 run('14-empty-extension-rollback','maintenance/onboarding-tests/fixture.php',{}, {action:'retained-rollback'});
}else if(phase==='last'){
 run('cleanup15','maintenance/ierb-monitor-tests/fixture.php',{}, {action:'cleanup'});
 run('cleanup13','maintenance/ierb-v14-tests/documents-fixture.php',{}, {action:'cleanup'});
 const r=cp.spawnSync(php,['maintenance/ierb-v14-tests/cases.php','rollback-empty'],{encoding:'utf8',windowsHide:true});if(r.status)throw Error(r.stderr);
 run('09-schema11-rerun',suite);
 run('10-schema11-ui',suite,{...documents,PRISM_TEST_BATCH6:'1'});
}
