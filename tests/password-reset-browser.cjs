/* Isolated HTTPS browser regression: every request is fulfilled through CDP.
 * No request reaches production, configuration, a real database or email.
 * Run: node tests/password-reset-browser.cjs
 * Reuses the UI audit's browser lifecycle and the auth-flow PHP fixtures.
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const harness = path.join(__dirname, 'ui-audit.cjs');
let source = fs.readFileSync(harness, 'utf8').replace(/\r\n/g, '\n');
const extension = String.raw`
const resetOrigin = 'https://rpmsceu.online';
const attackerOrigin = 'https://attacker.invalid';
// Only synthetic fixture credentials are used, and none are printed or saved.
const resetFixtureToken = 'fixture-token';
let resetBrowserMode = {};
const resetBrowserPosts = [];
let resetReferrerLeak = false;
function resetPhpFixture(input) {
  const result = spawnSync(php, [path.join(root, 'tests/auth-flows.php'), '--case', JSON.stringify(input)],
    {cwd:root, encoding:'utf8', windowsHide:true});
  assert.equal(result.status, 0, 'Isolated reset fixture failed');
  assert.equal(result.stderr, '', 'Isolated reset fixture emitted diagnostics');
  return JSON.parse(result.stdout);
}
async function fulfillResetBrowserRequest(paused) {
  const request = paused.request, url = new URL(request.url);
  const headers = Object.fromEntries(Object.entries(request.headers).map(([k,v])=>[k.toLowerCase(),v]));
  if ((headers.referer || '').includes(resetFixtureToken)) resetReferrerLeak = true;
  let body = '', status = 200;
  const responseHeaders = [{name:'Content-Type',value:'text/html; charset=utf-8'}];
  if ([resetOrigin,attackerOrigin].includes(url.origin) && url.pathname === '/reset_password.php') {
    const page = resetPhpFixture({file:'reset_password.php', setupAccount:!!resetBrowserMode.setup,
      usedToken:!!resetBrowserMode.used});
    body = page.rendered;
    if (url.origin === attackerOrigin) body = body.replace('action="update_password.php"','action="'+resetOrigin+'/update_password.php"');
    responseHeaders.push({name:'Referrer-Policy',value:resetBrowserMode.policy || page.referrerPolicy});
  } else if (url.origin === resetOrigin && url.pathname === '/update_password.php' && request.method === 'POST') {
    const post = Object.fromEntries(new URLSearchParams(request.postData || ''));
    const result = resetPhpFixture({file:'update_password.php', post,
      setupAccount:!!resetBrowserMode.setup, usedToken:!!resetBrowserMode.used,
      server:{REQUEST_METHOD:'POST',HTTP_HOST:'rpmsceu.online',
        HTTPS:resetBrowserMode.forwarded?'off':'on',
        HTTP_X_FORWARDED_PROTO:resetBrowserMode.forwarded?'https':null,
        HTTP_ORIGIN:headers.origin ?? null,HTTP_REFERER:headers.referer ?? null}});
    status = result.status;
    responseHeaders.push({name:'Referrer-Policy',value:result.referrerPolicy || 'no-referrer'});
    if (status !== 403 && result.location.startsWith('Location: ')) {
      status = 302;
      responseHeaders.push({name:'Location',value:result.location.slice('Location: '.length)});
    }
    body = status === 403 ? JSON.stringify(result.response) : '';
    resetBrowserPosts.push({origin:headers.origin ?? null,referer:headers.referer ?? null,
      result,status,tokenPosted:post.token===resetFixtureToken});
  } else if (url.origin === resetOrigin && /^\/assets\/(css|js|images)\/[A-Za-z0-9_.\/-]+$/.test(url.pathname)) {
    const asset = path.resolve(root,'.'+url.pathname);
    assert(asset.startsWith(path.join(root,'assets')+path.sep));
    const types={'.css':'text/css','.js':'application/javascript','.png':'image/png','.webp':'image/webp','.svg':'image/svg+xml'};
    responseHeaders[0].value=types[path.extname(asset)]||'application/octet-stream';
    if(fs.existsSync(asset))body=fs.readFileSync(asset);else status=404;
  } else if (url.origin === resetOrigin && ['/login.php','/forgot_password.php'].includes(url.pathname)) {
    body='<!doctype html><title>Isolated reset result</title><body>Fixture result</body>';
  }
  await command('Fetch.fulfillRequest',{requestId:paused.requestId,responseCode:status,responseHeaders,
    body:Buffer.from(body).toString('base64')});
}
async function waitResetBrowserPost(previous) {
  for(let i=0;i<200;i++) {
    if(resetBrowserPosts.length>previous)return resetBrowserPosts.at(-1);
    await new Promise(resolve=>setTimeout(resolve,25));
  }
  throw new Error('Isolated browser POST did not complete');
}
async function checkResetBrowser() {
  const summary=[];
  for(const mode of [
    {name:'Prior no-referrer policy',policy:'no-referrer',blocked:true},
    {name:'Same-origin password reset'},
    {name:'Same-origin account setup',setup:true},
    {name:'Forwarded HTTPS password reset',forwarded:true},
    {name:'Cross-origin password reset',crossSite:true,blocked:true},
  ]) {
    resetBrowserMode=mode;
    const start=resetBrowserPosts.length;
    await command('Page.navigate',{url:(mode.crossSite?attackerOrigin:resetOrigin)+'/reset_password.php?token='+resetFixtureToken});
    await waitFor('document.readyState==="complete"&&!!document.querySelector("form")');
    check(await evaluate('isSecureContext'),mode.name+': browser uses an HTTPS secure context');
    await evaluate("document.getElementById('password').value='Fixture-next-42!';document.getElementById('confirmPassword').value='Fixture-next-42!';document.querySelector('form').requestSubmit()");
    const post=await waitResetBrowserPost(start);
    check(post.tokenPosted,mode.name+': token remains in POST body');
    if(mode.blocked) {
      check(post.status===403&&!post.result.passwordChanged&&post.result.steps.length===0,mode.name+': rejected before database operations');
    } else {
      check(post.status===302&&post.result.passwordChanged&&post.result.commits===1&&post.result.tokenUsed,mode.name+': reset succeeds and consumes token');
      check(post.origin===resetOrigin&&post.referer===resetOrigin+'/',mode.name+': browser sends only canonical origin evidence');
      if(mode.setup)check(post.result.passwordChangeRequired===0,'Account setup clears initial password requirement');
      await waitFor('location.pathname==="/login.php"&&document.readyState==="complete"');
      resetBrowserMode={...mode,used:post.result.tokenUsed};
      const beforeReplay=resetBrowserPosts.length;
      await command('Page.navigate',{url:resetOrigin+'/reset_password.php?token='+resetFixtureToken});
      await waitFor('document.readyState==="complete"&&!!document.querySelector("form")');
      check(await evaluate('getComputedStyle(document.querySelector("form")).display==="none"'),mode.name+': consumed link hides reset form');
      await evaluate("fetch('update_password.php',{method:'POST',body:new URLSearchParams({token:'"+resetFixtureToken+"',password:'Fixture-next-42!',confirm_password:'Fixture-next-42!'})}).then(r=>r.text())");
      const replay=await waitResetBrowserPost(beforeReplay);
      check(!replay.result.passwordChanged&&replay.result.commits===0&&replay.result.invalidations===0&&replay.result.error.includes('invalid or has expired'),mode.name+': token remains single-use');
    }
    check(!resetReferrerLeak,mode.name+': reset token never appears in Referer');
    summary.push({name:mode.name,status:post.status,canonicalOrigin:post.origin===resetOrigin,
      originOnlyReferer:post.referer===resetOrigin+'/',tokenLeaked:false});
  }
  check(errors.length===0,'No reset browser/fixture errors');
  const resultDir=path.join(root,'tests/release-candidate-results');fs.mkdirSync(resultDir,{recursive:true});
  fs.writeFileSync(path.join(resultDir,'password-reset-browser.json'),JSON.stringify({checks,failures,summary},null,2));
  console.log(checks+' HTTPS reset/setup browser checks; '+failures.length+' failures.');
  console.log('Same-origin evidence: Origin https://rpmsceu.online; Referer https://rpmsceu.online/. No production requests or tokens logged.');
  if(failures.length)process.exitCode=1;
}
`;
source = source.replace('async function run() {',extension+'\nasync function run() {');
const from = source.indexOf("    } else if (message.method === 'Fetch.requestPaused') {");
const to = source.indexOf('\n    }\n  });',from);
if(from<0||to<0)throw new Error('UI audit interception boundary changed');
source=source.slice(0,from)+"    } else if (message.method === 'Fetch.requestPaused') {\n      void fulfillResetBrowserRequest(message.params).catch(()=>errors.push('Isolated request fulfillment failed'));"+source.slice(to);
source=source.replace("  if (process.argv.includes('--student-protocol-only')) {","  await checkResetBrowser(); return;\n  if (process.argv.includes('--student-protocol-only')) {");
const compiled=new Module(harness,module);compiled.filename=harness;
compiled.paths=Module._nodeModulePaths(path.dirname(harness));compiled._compile(source,harness);
