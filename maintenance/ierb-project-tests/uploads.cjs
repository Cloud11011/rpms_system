const fs=require('node:fs'),path=require('node:path');
module.exports=async h=>{
  const {browser,check,pdf,guideKeys,sessions:s,base}=h;
  const evidence=path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR||path.join(h.root,'PRISM_V14_BATCH_03_EVIDENCE'));fs.mkdirSync(evidence,{recursive:true});
  const start=h.checks.length,errors=[];
  const queueStudent=await h.login('student5@example.test');
  const queueGuideKeys=[...guideKeys,`prismTour.student.${require('node:crypto').createHash('sha256').update('Student|S5').digest('hex')}.v1`];
  for(const theme of ['light','dark'])for(const width of [390,768,1440]){
    const ctx=await browser.newContext({viewport:{width,height:1100},storageState:await queueStudent.storageState(),reducedMotion:'reduce'});
    try{
      await ctx.addInitScript(({theme,keys})=>{localStorage.setItem('prismTheme',theme);keys.forEach(k=>localStorage.setItem(k,'seen'));},{theme,keys:queueGuideKeys});
      await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//,r=>r.fulfill({contentType:'text/css',body:''}));
      const page=await ctx.newPage();page.on('pageerror',e=>errors.push(String(e)));
      await page.goto(base+'/role_portal.php#submit');await page.evaluate(()=>document.querySelectorAll('.prism-tour-dialog').forEach(d=>d.close()));
      const input=page.locator('#documentFile'),types=page.locator('[data-upload-type]'),button=page.locator('#submissionForm [type=submit]');
      await input.evaluate(node=>node.addEventListener('change',()=>{window.__batch3Files=[...node.files];},true));
      const file=name=>({name,mimeType:'application/pdf',buffer:pdf()});
      const pick=async name=>input.setInputFiles(file(name));
      check(await page.locator('#submissionForm .submission-step').count()===2 && await page.locator('#documentType').count()===0,`${width} ${theme}: exactly two steps with no obsolete global selector`);
      await pick('Synthetic Batch2 queue-cv.pdf');check(await types.count()===1,`${width} ${theme}: first browse queues one file`);
      check(await button.isDisabled(),`${width} ${theme}: missing per-file type prevents submission`);
      await types.nth(0).selectOption('Curriculum Vitae');await pick('Synthetic Batch2 queue-protocol.pdf');
      check(await types.count()===2 && await types.nth(0).inputValue()==='Curriculum Vitae',`${width} ${theme}: second browse appends and retains the first assignment`);
      await types.nth(1).selectOption('Study Protocol');await pick('Synthetic Batch2 queue-form11.pdf');
      await types.nth(2).selectOption('Form 11 Review Checklist');
      check(await types.count()===3,`${width} ${theme}: three individual selections retain all documents`);
      await page.locator('#documentNotes').fill('Optional reviewer notes');
      check((await types.evaluateAll(ns=>ns.map(n=>n.value))).join('|')==='Curriculum Vitae|Study Protocol|Form 11 Review Checklist',`${width} ${theme}: notes do not reset assignments`);
      await input.evaluate(node=>{const dt=new DataTransfer();window.__batch3Files.forEach(f=>dt.items.add(f));node.files=dt.files;node.dispatchEvent(new Event('change',{bubbles:true}));});check(await types.count()===3,`${width} ${theme}: duplicate selection is ignored`);
      await input.setInputFiles([file('Synthetic Batch2 queue-multi1.pdf'),file('Synthetic Batch2 queue-multi2.pdf')]);
      check(await types.count()===5,`${width} ${theme}: multiple selection appends both files`);
      await page.locator('#submissionDropzone').evaluate((drop,bytes)=>{
        const dt=new DataTransfer();dt.items.add(new File([new Uint8Array(bytes)],'Synthetic Batch2 queue-drop.pdf',{type:'application/pdf'}));
        drop.dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,dataTransfer:dt}));
      },Array.from(pdf()));
      check(await types.count()===6,`${width} ${theme}: drag-and-drop appends to the maintained queue`);
      await page.getByRole('button',{name:'Remove Synthetic Batch2 queue-protocol.pdf',exact:true}).click();
      check(await types.count()===5 && (await types.evaluateAll(ns=>ns.slice(0,2).map(n=>n.value))).join('|')==='Curriculum Vitae|Form 11 Review Checklist',`${width} ${theme}: removing a middle file preserves the others and their types`);
      await input.setInputFiles({name:'invalid.exe',mimeType:'application/octet-stream',buffer:Buffer.from('invalid')});
      check(await types.count()===6 && await button.isDisabled() && (await page.locator('#submissionSubmitHint').textContent()).includes('not a PDF'),`${width} ${theme}: invalid files report errors without losing valid files`);
      await page.getByRole('button',{name:'Remove invalid.exe',exact:true}).click();
      for(let i=0;i<await types.count();i++)await types.nth(i).selectOption('Other Supporting Document');
      check(await button.isEnabled() && (await button.textContent()).includes('5 documents'),`${width} ${theme}: readiness and upload count cover the complete queue`);
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),`${width} ${theme}: queued filenames and controls stay within the viewport`);
      await page.screenshot({path:path.join(evidence,`uploads-${width}-${theme}.png`),fullPage:true});
      if(width===390&&theme==='light'){
        // Real multipart partial failure: invalid PDF content passes extension validation,
        // while the server must retain every legitimate version and reject this entry.
        await input.setInputFiles({name:'Synthetic Batch2 queue-invalid-content.pdf',mimeType:'application/pdf',buffer:Buffer.from('not a PDF')});
        await types.last().selectOption('Other Supporting Document');
        let posted=[];page.on('request',req=>{if(req.url().includes('action=upload'))posted.push(req.postDataBuffer()?.toString('utf8')||'');});
        const done=page.waitForResponse(r=>r.url().includes('documents_api.php?action=upload'));
        await button.click();const data=await (await done).json();
        check(data.uploaded===5&&data.failed===1&&data.outcome==='partial_success','Real Student form submits every accumulated file and reports partial success accurately: '+JSON.stringify({uploaded:data.uploaded,failed:data.failed,results:data.results?.map(r=>[r.ok,r.message])}));
        await page.waitForFunction(()=>document.querySelectorAll('[data-upload-type]').length===1);
        check(await types.count()===1&&await types.first().inputValue()==='Other Supporting Document','Only the failed file remains after partial success, with its assignment intact');
        check((await page.locator('#uploadResults').textContent()).includes('5 of 6'),'Partial success result identifies the successful and failed file counts');
        check(posted.length===1&&(posted[0].match(/name="documentTypes\[\]"/g)||[]).length===6&&(posted[0].match(/name="document\[\]"/g)||[]).length===6,'Actual multipart payload keeps all six file/type pairs in matching order');
        const successful=data.results.filter(r=>r.ok);
        check(successful.every((r,i)=>i===0||Number(r.versionNo)===Number(successful[i-1].versionNo)+1),'Repeated categories in the cumulative queue preserve the existing version chain');
        const versions=await (await queueStudent.get('/documents_api.php?action=versions&id='+successful.at(-1).document.id+'&perPage=100')).json();
        check(successful.every(r=>versions.versions.some(v=>v.id===r.document.id)),'Authorized version history retains every file uploaded in the cumulative batch');
        const firstVersion=await queueStudent.get('/documents_api.php?action=file&download=1&id='+successful[0].document.id);
        check(firstVersion.status()===200&&(await firstVersion.body()).equals(pdf()),'An authorized owner can download the original historical file bytes after cumulative replacements');
        await page.screenshot({path:path.join(evidence,'uploads-partial-recovery.png'),fullPage:true});
        await page.getByRole('button',{name:'Remove Synthetic Batch2 queue-invalid-content.pdf',exact:true}).click();
        await pick('Synthetic Batch2 queue-network.pdf');await types.first().selectOption('Other Supporting Document');
        await page.route('**/documents_api.php?action=upload',r=>r.abort('failed'));
        await button.click();await page.getByRole('button',{name:'I checked My Documents; this file still needs uploading'}).waitFor();
        check(await types.count()===1&&await button.isDisabled(),'Unknown network outcome retains the queue and blocks accidental resubmission');
        await page.getByRole('button',{name:'I checked My Documents; this file still needs uploading'}).click();
        check(await button.isEnabled(),'Explicit reconciliation restores retry readiness');
        await page.unroute('**/documents_api.php?action=upload');
        const success=page.waitForResponse(r=>r.url().includes('documents_api.php?action=upload'));await button.click();
        check((await (await success).json()).outcome==='complete_success','An explicitly reconciled remaining file can be successfully submitted');
        await page.waitForFunction(()=>document.querySelectorAll('[data-upload-type]').length===0);
        check(await types.count()===0&&await page.locator('#documentNotes').inputValue()==='','Full success resets the completed queue and optional notes');
        const retained=await queueStudent.get('/documents_api.php?action=file&download=1&id='+successful[0].document.id);
        check(retained.status()===200&&(await retained.body()).equals(pdf()),'Later uploads never overwrite or delete the retained original version file');
        await page.evaluate(()=>document.dispatchEvent(new CustomEvent('prism:upload-revision',{detail:{id:'synthetic-original',originalName:'Original.pdf',documentType:'Legacy private category',versionNo:2,stage:'Stage 1',actions:{uploadRevision:true}}})));
        await pick('Synthetic Batch2 revision.pdf');check(await types.first().isDisabled()&&await types.first().inputValue()==='Legacy private category','Explicit revision locks the original category even for a historical label');
        await pick('Synthetic Batch2 second-revision.pdf');check(await types.count()===1,'Revision mode preserves its single-file restriction when browsing again');
        await page.getByRole('button',{name:'Cancel revision',exact:true}).click();check(await types.count()===0&&await input.getAttribute('multiple')!==null,'Explicit revision cancellation resets the queue and restores normal selection');
        await page.locator('#submissionDropzone').focus();await page.keyboard.press('Tab');check(await input.evaluate(n=>document.activeElement===n),'The native file picker remains keyboard accessible');
      }
    }finally{await ctx.close();}
  }
  check(!errors.length,'Cumulative upload browser matrix has no JavaScript errors');
  fs.writeFileSync(path.join(evidence,'upload-tests.json'),JSON.stringify({status:'PASS',assertions:h.checks.length-start,cases:h.checks.slice(start)},null,2));
};
