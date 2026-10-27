const cp = require('node:child_process'), fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..'), php = process.env.PRISM_TEST_PHP || 'C:/xampp/php/php.exe';
const evidence = path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR || path.join(root, 'PRISM_V14_BATCH_01_EVIDENCE')); fs.mkdirSync(evidence, { recursive: true });
const cases = [], file = path.join(__dirname, 'cases.php');
const run = phase => JSON.parse(cp.execFileSync(php, [file, phase], { encoding: 'utf8', windowsHide: true }));
function worker(phase, key) {
  const p = cp.spawn(php, [file, phase, ...(key ? [key] : [])], { windowsHide: true }); let output = '', errors = '';
  const done = new Promise((resolve, reject) => { p.stdout.on('data', d => output += d); p.stderr.on('data', d => errors += d); p.on('error', reject); p.on('exit', code => code ? reject(new Error(errors)) : resolve(output)); });
  return { done, output: () => output };
}
async function locked(w) { for (let i=0;i<100;i++) { if (w.output().includes('locked')) return; await new Promise(r=>setTimeout(r,20)); } throw new Error('Worker did not acquire lock'); }
const check = (value, label) => { assert.ok(value,label); cases.push(label); console.log(label); };
(async()=> {
  for (const phase of ['migration','workflow']) { const r=run(phase); assert.equal(r.status,'PASS'); cases.push(...r.cases); console.log(phase+': '+r.assertions+' assertions'); }
  const migrationLock=worker('hold-migration'); await locked(migrationLock); const start=Date.now();
  const dryRun=await worker('migration-probe').done; await migrationLock.done;
  check(JSON.parse(dryRun).mode==='dry-run' && Date.now()-start>=500,'Migration dry run waits for the independent schema lock');
  const hold=worker('hold'); await locked(hold);
  const first=worker('open','concurrent-correction-0001'), second=worker('open','concurrent-correction-0002');
  const outcomes=(await Promise.all([first.done,second.done])).map(JSON.parse); await hold.done;
  check(outcomes.filter(o=>o.status==='ok').length===1 && outcomes.filter(o=>o.type==='AccountLifecycleConflict').length===1,'Concurrent different requests serialize at the Student lock and reject the stale decision');
  const winner=outcomes[0].status==='ok'?'concurrent-correction-0001':'concurrent-correction-0002';
  const replay=JSON.parse(await worker('open',winner).done);
  check(replay.status==='ok' && replay.result.replayed,'Independent repeated request replays the committed result without duplicate history');
  const r=run('finish'); cases.push(...r.cases);
  const result={status:'PASS',assertions:cases.length,cases,scope:'Synthetic isolated MariaDB only; no production migration, delivery or deployment'};
  fs.writeFileSync(path.join(evidence,'foundation-tests.json'),JSON.stringify(result,null,2)+'\n'); console.log(JSON.stringify({status:'PASS',assertions:cases.length}));
})().catch(e=>{ fs.writeFileSync(path.join(evidence,'failure.json'),JSON.stringify({status:'FAIL',error:String(e),cases},null,2)); console.error(e); process.exitCode=1; });
