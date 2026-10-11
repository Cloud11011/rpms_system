'use strict';
// Real PHP cookies/multipart/filesystem with disposable SQLite, never config.php or external services.
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const php=process.env.PRISM_TEST_PHP||'C:/xampp/php/php.exe';
const root=path.resolve(__dirname,'..'),fixture=fs.mkdtempSync(path.join(os.tmpdir(),'prism-batch5b-'));
for(const dir of ['docs','sessions'])fs.mkdirSync(path.join(fixture,dir));
const env={...process.env,PRISM_BATCH5B_FIXTURE:fixture};
const router=path.join(__dirname,'v10-batch5b-router.php');
const seed=spawnSync(php,[router,'--seed'],{cwd:root,env,encoding:'utf8',windowsHide:true});assert.equal(seed.status,0,seed.stderr);
let server,secondServer,checks=0;
const check=(value,message)=>{checks++;assert.ok(value,message);};
(async()=>{
  const net=require('node:net'),probe=net.createServer();await new Promise(r=>probe.listen(0,'127.0.0.1',r));const port=probe.address().port;await new Promise(r=>probe.close(r));
  const base='http://127.0.0.1:'+port;server=spawn(php,['-d','display_errors=0','-d','upload_tmp_dir='+fixture,'-d','upload_max_filesize=25M','-d','post_max_size=30M','-S','127.0.0.1:'+port,router],{cwd:root,env,windowsHide:true,stdio:'ignore'});
  await new Promise(r=>setTimeout(r,500));
  class Browser {
    jar=new Map(); generation='';
    constructor(endpoint=base) {this.endpoint=endpoint;}
    async request(route,options={}) {
      const headers={Cookie:[...this.jar].map(([k,v])=>k+'='+v).join('; '),Origin:this.endpoint,...options.headers};
      const res=await fetch(this.endpoint+'/'+route,{...options,headers,redirect:'manual'});
      for(const cookie of res.headers.getSetCookie()) {const [name,value]=cookie.split(';')[0].split('=');if(value)this.jar.set(name,value);else this.jar.delete(name);}
      const text=await res.text();let data;try{data=JSON.parse(text);}catch{}
      return {status:res.status,location:res.headers.get('location'),text,data};
    }
    async login(id) {const r=await this.request('login_process.php',{method:'POST',body:new URLSearchParams({email:'u'+id+'@example.invalid',password:'fixture-password'})});this.generation=(await this.state()).generation;return r;}
    async state(){return (await this.request('state')).data;}
    async upload(files,fields={},headers={},scalar=false){
      const form=new FormData();files.forEach(f=>form.append(scalar?'document':'document[]',new Blob([f.body??'Fixture text'],{type:'text/plain'}),f.name));
      const values={documentType:'Study Protocol',studentDbId:'1',fileCount:String(files.length),requestId:crypto.randomUUID(),...fields};for(const [k,v]of Object.entries(values))form.set(k,v);
      return this.request('documents_api.php?action=upload',{method:'POST',body:form,headers:{'X-PRISM-Generation':this.generation,...headers}});
    }
  }
  const a=new Browser();check((await a.request('login.php')).status===200,'anonymous login allowed');
  check((await a.upload([{name:'a.txt'}])).status===401,'anonymous upload denied');
  const anonymousCookie=a.jar.get('PHPSESSID');check((await a.login(2)).status===302,'fresh Adviser login');check(a.jar.get('PHPSESSID')!==anonymousCookie,'real PHP session identifier rotates');
  const adviserGeneration=a.generation,adviserCookie=a.jar.get('PHPSESSID');
  check((await a.request('login.php')).location==='index.php','second tab Login redirects');check((await a.login(3)).status===409,'direct Admin login rejected');check((await a.state()).id===2&&a.jar.get('PHPSESSID')===adviserCookie,'Adviser identity/cookie preserved');
  check((await a.request('index.php')).location==='research_adviser.php','canonical Adviser landing');
  await a.request('logout.php');check((await a.state()).id===null,'logout invalidates authentication');
  check((await a.login(3)).status===302,'Admin login after explicit logout');check(a.generation!==adviserGeneration,'new account gets new generation');
  check((await a.upload([{name:'stale.txt'}],{}, {'X-PRISM-Generation':adviserGeneration})).status===409,'stale Adviser mutation rejected under current Admin session');
  const stolen=new Browser();stolen.jar.set('PHPSESSID',adviserCookie);check((await stolen.state()).id===null,'destroyed old PHP session cannot revive Adviser');
  await a.request('logout.php');check((await a.upload([{name:'stale.txt'}])).status===401,'stale protected action after logout denied');
  const s=new Browser();await s.login(1);
  for(const files of [[{name:'valid.txt'},{name:'bad.exe'}],[{name:'bad.exe'},{name:'valid.txt'}],[{name:'a.txt'},{name:'b.txt'},{name:'bad.exe'}],[{name:'a.txt'},{name:'b.txt'}],[{name:'bad.exe'},{name:'bad.html'}]]) {
    const before=await s.state(),r=await s.upload(files),after=await s.state();const expected=files.filter(f=>f.name.endsWith('.txt')).length;
    check(r.data?.results?.length===files.length&&r.data.uploaded===expected,'every mixed-batch file reported: '+r.text);check(after.documents.length-before.documents.length===expected,'partial-success records persist independently');check(after.files.length===after.documents.length,'filesystem matches committed metadata');
  }
  for(const type of ['Custom Document','Research Protocol','<script>','Protocol'])check((await s.upload([{name:'valid.txt'}],{documentType:type})).status===422,'crafted/legacy new type rejected: '+type);
  const traversal=await s.upload([{name:'../path.txt'}]);check(traversal.data.ok&&traversal.data.document.originalName==='path.txt','PHP strips traversal filename; storage destination stays server generated');
  for(const f of [{name:'script.php.txt'},{name:'empty.txt',body:''},{name:'bad.pdf',body:'plain text'},{name:'fake.docx',body:'plain text'}])check((await s.upload([f])).data.failed===1,'unsafe/invalid individual file rejected: '+f.name);
  const malicious=await s.upload([{name:'owned.txt'}],{studentDbId:'8',studentId:'8',group:'FORGED',protocolCode:'FORGED',stage:'Completed'});
  check(malicious.data.document.studentId===1&&malicious.data.document.stage==='Stage 1','student ownership/stage comes from server');
  const once=crypto.randomUUID(),first=await s.upload([{name:'receipt.txt'}],{requestId:once}),beforeRepeat=(await s.state()).documents.length;
  const repeated=await s.upload([{name:'receipt.txt'}],{requestId:once});check(repeated.data.document.id===first.data.document.id&&(await s.state()).documents.length===beforeRepeat,'duplicate completed request reuses receipt');
  check((await s.upload([{name:'other.txt'}],{requestId:once})).status===409,'request id cannot be reused for other contents');
  for(const failure of ['move','write','metadata','commit']) {
    const before=await s.state();const r=await s.upload([{name:'failure.txt'}],{}, {'X-Fixture-Failure':failure});const after=await s.state();
    check(r.data.failed===1&&after.documents.length===before.documents.length&&after.files.length===before.files.length,'controlled cleanup: '+failure);
    check(after.audit.length===before.audit.length&&after.notices===before.notices,'failed file creates no success effects: '+failure);
  }
  check((await s.upload([{name:'cross.txt'}],{}, {Origin:'http://evil.invalid'})).status===403,'cross-origin upload denied');
  check((await s.upload([{name:'missing.txt'}],{}, {'X-PRISM-Generation':''})).status===409,'missing generation rejected');
  check((await s.upload([{name:'count.txt'}],{fileCount:'2'})).status===400,'truncated file count rejected before writing');
  const single=await s.upload([{name:'single.txt'}],{requestId:crypto.randomUUID()},{},true);check(single.data.ok&&single.data.versionNo>1,'scalar single-file transport compatible');
  const admin=new Browser();await admin.login(3);const ad=new Browser();await ad.login(2);check((await ad.upload([{name:'unrelated.txt'}],{studentDbId:'8'})).status===422,'Adviser unrelated student upload denied');
  check((await admin.upload([{name:'archived.txt'}],{studentDbId:'6'})).status===422,'archived owner upload denied');
  for(const id of [4,5,6,7]) {const b=new Browser();await b.login(id);check([401,403,409].includes((await b.upload([{name:'restricted.txt'}])).status),'Pending/inactive/archived/password-change upload denied: '+id);}
  // Separate cookie jars model concurrent browser profiles. SQLite BEGIN IMMEDIATE substitutes
  // for the existing student-row MySQL mutex; actual MySQL lock behavior remains unclaimed.
  const secondProbe=net.createServer();await new Promise(r=>secondProbe.listen(0,'127.0.0.1',r));const secondPort=secondProbe.address().port;await new Promise(r=>secondProbe.close(r));
  secondServer=spawn(php,['-d','display_errors=0','-d','upload_tmp_dir='+fixture,'-S','127.0.0.1:'+secondPort,router],{cwd:root,env,windowsHide:true,stdio:'ignore'});
  await new Promise(r=>setTimeout(r,500));const concurrentAdmin=new Browser('http://127.0.0.1:'+secondPort);await concurrentAdmin.login(3);
  const parallel=await Promise.all([s.upload([{name:'parallel-a.txt'},{name:'parallel-b.txt'}],{}, {'X-Fixture-Pause':'1'}),concurrentAdmin.upload([{name:'parallel-c.txt'}],{}, {'X-Fixture-Pause':'1'})]);
  check(parallel.every(r=>r.data?.ok),'overlapping same-type requests succeed: '+JSON.stringify(parallel));
  const final=await s.state(),versions=final.documents.map(d=>d.version_no).sort((a,b)=>a-b);
  check(new Set(versions).size===versions.length&&versions.every((v,i)=>v===i+1),'same-type sequential history has no duplicate or skipped successful version');check(final.documents.filter(d=>d.is_current===1).length===1,'one current version');
  check(final.audit.length===final.documents.length&&final.files.length===final.documents.length,'per-file audit and storage match successes');
  const current=final.documents.find(d=>d.is_current===1);
  for(const suffix of ['', '&download=1'])check((await s.request('documents_api.php?action=file&id='+current.id+suffix)).text==='Fixture text','single-file preview/download authorized and readable');
  check((await ad.request('documents_api.php?action=file&id='+current.id)).status===200,'assigned Adviser can view uploaded document');
  const other=await admin.upload([{name:'other-owner.txt'}],{studentDbId:'8'});
  check((await s.request('documents_api.php?action=file&id='+other.data.document.id)).status===403,'Student cannot download another student document');
  check((await ad.request('documents_api.php?action=file&id='+other.data.document.id)).status===403,'Adviser cannot download unrelated student document');
  for(const browser of [new Browser(),s]) for(const route of ['privacy.php','terms.php']) {
    const before=[...browser.jar];const r=await browser.request(route+'?content=%3Cscript%3E&html=ATTACK');
    check(r.status===200&&r.text.includes('<h1>PRISM')&&!r.text.includes('ATTACK')&&!r.location,'public legal route readable and ignores query: '+route);
    check(JSON.stringify([...browser.jar])===JSON.stringify(before),'legal page does not touch cookies: '+route);
  }
  console.log(`PASS: ${checks} isolated HTTP/session/multipart/partial-success/version/rollback/receipt checks. Fixture: ${fixture}`);
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{server?.kill();secondServer?.kill();});
