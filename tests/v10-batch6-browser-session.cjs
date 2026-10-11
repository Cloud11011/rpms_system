'use strict';
// Real fetch/Response semantics, isolated browser globals; no HTTP or application bootstrap.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const generation='a'.repeat(32),redirects=[],calls=[];
let reply=new Response('{}',{headers:{'content-type':'application/json'}});
const window={PRISM_SESSION_GENERATION:generation,addEventListener(){},fetch:async(input,options)=>{calls.push({input,options});return reply;}};
const document={cookie:'prism_generation='+generation,addEventListener(){},hidden:false};
const location={href:'http://prism.invalid/app/documents.php',origin:'http://prism.invalid',assign:route=>redirects.push(route)};
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/js/session-browser.js','utf8'),{window,document,location,URL,Request,Headers,Promise});
let checks=0;
const check=(actual,expected)=>{assert.deepEqual(actual,expected);checks++;};
(async()=>{
  reply=new Response(JSON.stringify({ok:false,code:'legal_acceptance_required',redirect:'https://evil.invalid'}),{status:403,headers:{'content-type':'application/json; charset=utf-8'}});
  const response=await window.fetch('documents_api.php?action=upload',{method:'POST'});
  check(redirects,['legal_consent.php']);
  check(calls[0].options.headers.get('X-PRISM-Generation'),generation);
  check(response.status,403);
  check((await response.json()).code,'legal_acceptance_required'); // Clone must not consume caller body.
  redirects.length=0;
  reply=new Response(JSON.stringify({code:'legal_acceptance_required'}),{status:403,headers:{'content-type':'application/json'}});
  await window.fetch('https://outside.invalid/api',{method:'POST'});
  check(redirects,[]);check(calls.at(-1).options.headers,undefined);
  for(const [status,type,body] of [[403,'application/json','{"code":"forbidden"}'],[200,'application/json','{"code":"legal_acceptance_required"}'],[403,'text/plain','legal_acceptance_required'],[403,'application/json','malformed JSON']]){
    reply=new Response(body,{status,headers:{'content-type':type}});
    await window.fetch('documents_api.php?action=list');check(redirects,[]);
  }
  check(calls.at(-1).options.headers,undefined); // Reads do not acquire mutation headers.
  console.log(`PASS: ${checks} legal-response redirect, body preservation, generation and origin/status/content-type assertions; no network.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
