const cp=require('node:child_process'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const php=process.env.PRISM_TEST_PHP,helper=path.join(__dirname,'fixture.php');
function worker(action){const p=cp.spawn(php,[helper],{windowsHide:true});let out='',err='';const done=new Promise((resolve,reject)=>{p.stdout.on('data',d=>out+=d);p.stderr.on('data',d=>err+=d);p.on('exit',c=>c?reject(Error(err)):resolve(out));});p.stdin.end(JSON.stringify({action}));return {done,output:()=>out};}
(async()=>{
 const writer=worker('hold-assignment');for(let n=0;!writer.output().includes('locked');n++){if(n>100)throw Error('Approval worker did not lock the marker.');await new Promise(r=>setTimeout(r,20));}
 const start=Date.now();const rollback=worker('retained-rollback');let result;
 try{
  const [written,refused]=await Promise.all([writer.done,rollback.done]);result=JSON.parse(refused);
  assert.ok(JSON.parse(written.split('\n').slice(1).join('\n')).ok,'Approval commits its pending assignment');
  assert.ok(!result.ok&&result.message.includes('Retained onboarding'),'A rollback racing the approval sees retained data and refuses');
  assert.ok(Date.now()-start>=1000,'Rollback waits for the independent approval transaction');
  const q=JSON.parse(cp.execFileSync(php,[path.join(__dirname,'../ierb-group-tests/fixture.php')],{input:JSON.stringify({action:'sql',sql:'SELECT COUNT(*) n FROM research_onboarding_assignments WHERE student_id=4'}),encoding:'utf8',windowsHide:true}));assert.equal(q[0].n,1,'The newly committed assignment survives rollback refusal');
  const evidence={status:'PASS',assertions:4,elapsedMs:Date.now()-start,cases:['Approval commits a pending assignment','Racing rollback refuses retained data','Rollback waits for the independent approval transaction','Committed assignment survives rollback refusal']};
  fs.writeFileSync(path.join(process.env.PRISM_TEST_EVIDENCE_DIR,'migration-race.json'),JSON.stringify(evidence,null,2));console.log(JSON.stringify(evidence));
 }finally{cp.execFileSync(php,[helper],{input:JSON.stringify({action:'cleanup-assignment-race'}),encoding:'utf8',windowsHide:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
