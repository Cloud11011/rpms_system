/* Admin selection is scoped by filters/search; page/sort never changes its population. */
(function () {
    'use strict';
    window.PrismRetentionAdmin={mount(options) {
        const api='account_lifecycle_api.php';
        const selected=new Set(); let allIds=null, selectionToken=null, scopeKey='', total=0, bulkBusy=false;
        let selecting=false, selectionEpoch=0;
        const toggle=document.createElement('button');toggle.type='button';toggle.id='retentionSelectionMode';toggle.className='prism-btn prism-btn-secondary';toggle.textContent='Selection Mode: Off';toggle.setAttribute('aria-pressed','false');
        document.querySelector('.management-controls').append(toggle);
        const toolbar=document.createElement('section');toolbar.className='retention-toolbar';toolbar.setAttribute('aria-label','Bulk account lifecycle');
        const summary=document.createElement('p');summary.setAttribute('role','status');summary.setAttribute('aria-live','polite');
        const actions=document.createElement('div');actions.className='retention-toolbar-actions';
        const page=document.createElement('input');page.type='checkbox';page.id='retentionSelectPage';
        const pageLabel=document.createElement('label');pageLabel.className='retention-selection-label';pageLabel.htmlFor=page.id;pageLabel.append(page,document.createTextNode('Select current page'));
        const all=document.createElement('button');all.type='button';all.className='prism-btn';
        const clear=document.createElement('button');clear.type='button';clear.className='prism-btn';clear.textContent='Clear selection';
        const action=document.createElement('select');action.id='retentionBulkAction';action.setAttribute('aria-label','Bulk lifecycle action');
        (options.archived ? [['restore','Restore'],['hold','Place Retention Hold'],['remove_hold','Remove Retention Hold'],['permanent_delete','Permanent Purge'],['retention_cleanup','Run Retention Cleanup']] : [['archive','Archive']]).forEach(([v,l])=>action.add(new Option(l,v)));
        const run=document.createElement('button');run.type='button';run.className='prism-btn';run.textContent='Review selected accounts';
        const holdLabel=document.createElement('label');holdLabel.textContent='Hold reason (optional)';
        const holdReason=document.createElement('input');holdReason.type='text';holdReason.maxLength=500;holdReason.setAttribute('aria-label','Retention Hold reason');holdLabel.append(holdReason);holdLabel.hidden=true;
        action.addEventListener('change',()=>{holdLabel.hidden=action.value!=='hold';});
        actions.append(pageLabel,all,clear,action,run,holdLabel);toolbar.append(summary,actions);
        options.rows.closest('.management-card').before(toolbar);
        const results=document.createElement('div');results.className='retention-results';results.setAttribute('aria-live','polite');toolbar.after(results);
        const cleanup=document.createElement('section');cleanup.className='retention-cleanup';cleanup.setAttribute('aria-label','Retention cleanup and recovery');
        const cleanupCounts=document.createElement('p');cleanupCounts.textContent='Loading retention cleanup eligibility…';
        const review=document.createElement('button');review.type='button';review.className='prism-btn';review.textContent='Review eligible accounts';
        const runCleanup=document.createElement('button');runCleanup.type='button';runCleanup.className='prism-btn';runCleanup.textContent='Run Retention Cleanup';
        const recovery=document.createElement('div');recovery.className='retention-recovery';
        const maintenanceTitle=document.createElement('h3');maintenanceTitle.textContent='Retention Maintenance';
        runCleanup.classList.add('prism-btn-danger');
        cleanup.append(maintenanceTitle,cleanupCounts,review,runCleanup,recovery);if(options.archived)document.getElementById('recordFilters').append(cleanup);
        const header=document.createElement('th');header.scope='col';header.textContent='Select';options.rows.closest('table').querySelector('thead tr').prepend(header);
        const getIds=()=>allIds || selected;
        function scopeLabel() {
            const labels=[options.type==='student'?'Student':'Adviser'];
            Object.entries(options.getScope()).forEach(([key,value])=>{
                if(!value)return;
                const field=document.getElementById('recordFilters_'+key);
                labels.push(key==='q'?'Search: '+value:field?.selectedOptions[0]?.textContent || value);
            });
            return labels.join(' • ');
        }
        function update() {
            toolbar.hidden=!selecting;header.hidden=!selecting;results.hidden=!selecting;
            toggle.disabled=bulkBusy;toggle.textContent='Selection Mode: '+(selecting?'On':'Off');toggle.setAttribute('aria-pressed',String(selecting));
            options.rows.querySelectorAll('.retention-select').forEach(cell=>{cell.hidden=!selecting;});
            const ids=getIds();summary.textContent=`${ids.size} accounts selected • ${scopeLabel()}${allIds?' • snapshot of all matching accounts':''}`;
            const records=options.getRecords();const count=records.filter(r=>ids.has(r.id)).length;
            page.checked=records.length>0&&count===records.length;page.indeterminate=count>0&&count<records.length;
            all.textContent=`Select all ${total} accounts matching current filters`;all.disabled=bulkBusy||!total||total>10000;
            run.disabled=bulkBusy||!ids.size;clear.disabled=bulkBusy||!ids.size;page.disabled=bulkBusy||!records.length;
            action.disabled=bulkBusy;options.rows.querySelectorAll('[data-retention-id]').forEach(box=>{box.checked=ids.has(Number(box.dataset.retentionId));box.disabled=bulkBusy;});
        }
        function syncScope() {
            const next=JSON.stringify(options.getScope());
            if(next!==scopeKey) {selectionEpoch++;selected.clear();allIds=null;selectionToken=null;scopeKey=next;update();}
        }
        function clearSelection() {selectionEpoch++;selected.clear();allIds=null;selectionToken=null;page.checked=false;page.indeterminate=false;update();}
        toggle.addEventListener('click',()=>{if(bulkBusy)return;selecting=!selecting;if(!selecting){clearSelection();holdReason.value='';results.replaceChildren();}update();});
        clear.addEventListener('click',clearSelection);
        page.addEventListener('change',()=>{
            if(allIds){allIds.forEach(id=>selected.add(id));allIds=null;selectionToken=null;}
            options.getRecords().forEach(r=>{if(page.checked)selected.add(r.id);else selected.delete(r.id);});update();
        });
        async function createSelection() {
            const key=scopeKey, epoch=selectionEpoch;
            if(!selecting)throw new Error('Enable Selection Mode first.');
            const data=await PrismUI.postJson(api,{action:'selection',accountType:options.type,filters:options.getScope()});
            if(key!==scopeKey || epoch!==selectionEpoch || !selecting)throw new Error('Selection changed while resolving accounts. Select again.');
            selectionToken=data.selectionToken;return data;
        }
        all.addEventListener('click',()=>PrismUI.runAction(all,'Resolving selection…',async()=>{
            try {syncScope();const data=await createSelection();allIds=new Set(data.ids);selected.clear();update();}
            catch(e){PrismUI.toast(e.message,'error');}
        }));
        async function preview(button) {
            syncScope();if(!selecting)return;
            if(!getIds().size)return;
            if(['permanent_delete','retention_cleanup'].includes(action.value)&&!options.getAvailability().available)throw new Error(options.getAvailability().message);
            const key=scopeKey,epoch=selectionEpoch;const ids=[...getIds()];const mode=allIds?'all_matching':'individual';
            if(!selectionToken)await createSelection();
            if(key!==scopeKey || epoch!==selectionEpoch || !selecting)throw new Error('Selection changed. Select and preview again.');
            const data=await PrismUI.postJson(api,{action:'bulk_preview',selectionToken,selectionMode:mode,ids,bulkAction:action.value});
            if(key!==scopeKey || epoch!==selectionEpoch || !selecting)throw new Error('Selection changed while previewing. Select and preview again.');
            data.purge=['permanent_delete','retention_cleanup'].includes(action.value);data.cursor=0;data.reason=holdReason.value.trim();
            options.openBulk(data,button);
        }
        run.addEventListener('click',()=>PrismUI.runAction(run,'Checking eligibility…',async()=>{try{await preview(run);}catch(e){PrismUI.toast(e.message,'error');}}));
        review.addEventListener('click',()=>PrismUI.runAction(review,'Loading eligible accounts…',async()=>{await options.reviewCleanup();syncScope();}));
        runCleanup.addEventListener('click',()=>PrismUI.runAction(runCleanup,'Preparing cleanup…',async()=>{
            try {selecting=true;update();await options.reviewCleanup();syncScope();const data=await createSelection();allIds=new Set(data.ids);selected.clear();action.value='retention_cleanup';update();await preview(runCleanup);}
            catch(e){PrismUI.toast(e.message,'error');}
        }));
        async function refreshSummary() {
            if(!options.archived)return;
            try {
                const [counts,jobs]=await Promise.all([PrismUI.request(api+'?action=cleanup_summary'),PrismUI.request(api+'?action=recovery_jobs')]);
                cleanupCounts.textContent=`Retention Cleanup — Eligible Students: ${counts.eligibleStudents}; Eligible Advisers: ${counts.eligibleAdvisers}. Admin review and confirmation are required. No automatic deletion.`;
                recovery.replaceChildren();
                jobs.jobs.forEach(job=>{
                    const button=document.createElement('button');button.type='button';button.className='prism-btn';button.textContent='Review recovery '+job.jobId+(job.manifestAvailable===false?' — missing journal; operator review required':'');button.addEventListener('click',()=>options.openRecovery(job,button));recovery.append(button);
                });
            } catch(e){cleanupCounts.textContent='Retention cleanup/recovery state unavailable: '+e.message;}
        }
        async function showSetup(data) {
            if(data.setupLink)await PrismUI.confirm({title:'Restored account password setup',icon:'fa-key',confirmText:'I copied the link',
                message:'Give this one-time setup link to the account holder securely. Use it promptly; invitation links expire after one hour and recovery links after 24 hours.',
                extraHtml:'<label>Setup link<input readonly value="'+PrismUI.esc(data.setupLink)+'"></label>'});
        }
        async function singleAction(record,name,button) {
            return PrismUI.runAction(button,'Processing…',async()=>{
                try {
                    const label=record.name || record.email;
                    const answer=await PrismUI.confirm({title:name==='restore'?'Restore account':name==='hold'?'Place Retention Hold':'Remove Retention Hold',
                        message:name==='restore'?`Restore ${label}? This cancels the retention clock and starts fresh password setup. Adviser assignments remain Unassigned.`:name==='hold'?`Place ${label} on Retention Hold? All purge methods will be blocked.`:`Remove the Retention Hold for ${label}? Server eligibility rules still apply.`,
                        confirmText:'Confirm',...(name==='hold'?{reasonLabel:'Hold reason (optional)'}:{})});
                    if(!answer)return;
                    const data=await PrismUI.postJson(api,{action:name,accountType:options.type,targetId:record.id,reason:answer.reason || ''});
                    await options.refresh();await refreshSummary();PrismUI.toast(data.message,'success');await showSetup(data);
                }catch(e){PrismUI.toast(e.message,'error');}
            });
        }
        async function executeBulk(previewData,credentials,progress) {
            bulkBusy=true;update();const seen=new Set();
            try {
                let data;
                do {
                    data=await PrismUI.postJson(api,{action:'bulk_execute',previewToken:previewData.previewToken,cursor:previewData.cursor,...credentials,reason:previewData.reason});
                    if(data.nextCursor<=previewData.cursor&&!data.done)throw new Error('Batch cursor did not advance. Review before retrying.');
                    previewData.cursor=data.nextCursor;
                    progress(`${data.nextCursor}/${data.selected} processed — Completed: ${data.completed}; Skipped: ${data.skipped}; Recovery required: ${data.recoveryRequired}. Each request processes at most 25 accounts.`);
                    const heading=document.createElement('p');heading.textContent=`Completed: ${data.completed}; Skipped: ${data.skipped}; Recovery required: ${data.recoveryRequired}.`;
                    results.querySelector('[data-results-summary]')?.remove();heading.dataset.resultsSummary='1';results.prepend(heading);
                    data.results.forEach(result=>{
                        if(seen.has(result.id))return;seen.add(result.id);
                        const line=document.createElement('p');line.textContent=result.identifier+' — '+result.outcome+': '+result.reason;
                        if(result.setupLink){const link=document.createElement('input');link.readOnly=true;link.value=result.setupLink;link.setAttribute('aria-label','Restored account setup link for '+result.identifier);line.append(link);}
                        results.append(line);
                    });
                } while(!data.done);
                selected.clear();allIds=null;selectionToken=null;return data;
            }finally{bulkBusy=false;update();}
        }
        syncScope();refreshSummary();
        return {syncScope,onPage(count){total=count;syncScope();update();},refreshSummary,singleAction,executeBulk,
            attachRow(row,record) {
                const cell=document.createElement('td');cell.className='retention-select';
                cell.hidden=!selecting;
                const box=document.createElement('input');box.type='checkbox';box.dataset.retentionId=record.id;box.checked=getIds().has(record.id);
                box.setAttribute('aria-label','Select '+(record.studentId || record.employeeId || record.email)+' — '+(record.name || 'Profile incomplete'));
                box.addEventListener('change',()=>{if(allIds){allIds.forEach(id=>selected.add(id));allIds=null;selectionToken=null;}if(box.checked)selected.add(record.id);else selected.delete(record.id);update();});
                cell.append(box);row.prepend(cell);
            }};
    }};
})();
