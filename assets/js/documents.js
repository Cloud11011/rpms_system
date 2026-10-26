document.addEventListener('DOMContentLoaded',()=>{
const currentRole=document.body.dataset.role||'admin';const isStaff=currentRole==='admin'||currentRole==='adviser';const uiPreview=(document.body.dataset.prismAllowDemo==='1') && new URLSearchParams(window.location.search).get('ui-preview')==='1';
if(!isStaff){const studentField=document.getElementById('documentStudent');if(studentField){studentField.closest('div').style.display='none';studentField.required=false}}
let documents=[],listData={},loadSequence=0,loadError=false,view='table',previewOnly=false;const defaultTypes=PrismUI.documentTypes;const body=document.getElementById('documentsTableBody'),search=document.getElementById('documentSearch'),typeFilter=document.getElementById('documentTypeFilter'),courseFilter=document.getElementById('documentCourseFilter'),yearFilter=document.getElementById('documentYearFilter'),sortSelect=document.getElementById('documentSort'),tableView=document.getElementById('documentsTableView'),folderView=document.getElementById('documentsFolderView'),courseView=document.getElementById('documentsCourseView'),uploadModal=document.getElementById('uploadModal'),uploadForm=document.getElementById('uploadForm');
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'})[c]);
const size=v=>v<1024?`${v} B`:v<1048576?`${(v/1024).toFixed(1)} KB`:`${(v/1048576).toFixed(1)} MB`;const date=v=>new Date(v).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
async function api(action,options={}){return PrismUI.request(`documents_api.php?action=${encodeURIComponent(action)}`,options)}
const summaryModal=document.getElementById('summaryModal'),summaryResult=document.getElementById('summaryResult'),summarySource=document.getElementById('summarySource'),regenerateSummary=document.getElementById('regenerateSummary');
const summaryStatus=document.getElementById('summaryStatus'),summaryPanel=document.getElementById('summaryPreviewPanel'),summarySend=document.getElementById('sendSummary'),summaryAuthorization=document.getElementById('summaryAuthorization'),summaryLocal=document.getElementById('localSummary');
let summaryDocument=null,summarySequence=0,summaryReturnFocus=null,summaryBusy=false,summaryPreview=null,summaryRetryAt=0,summaryRetryTimer;
const summaryMode=()=>document.getElementById('summaryModeLocal')?.checked?'local':'external';
function summaryButton(d){return currentRole==='admin'&&d.actions?.summarize&&summaryModal?`<button type="button" class="document-action-button summary-action" data-summary="${esc(d.id)}"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>${d.aiSummary?'View Summary':'Summarize'}</button>`:''}
function bindSummaryButtons(host){host.querySelectorAll('[data-summary]').forEach(button=>button.addEventListener('click',()=>showSummary(documents.find(d=>String(d.id)===button.dataset.summary),button)))}
function summarySourceLabel(d){return !d.aiSummary?'':(d.summarySource==='openrouter_ai'?'Generated with OpenRouter AI':d.summarySource==='local_fallback'?'Generated locally with PHP':'Previously saved summary · source not recorded')+(d.summaryPartial?' · Based on selected text; some document content was omitted.':'')}
function summaryControls(){
    if(!summaryModal)return;
    const waiting=Date.now()<summaryRetryAt;
    summaryModal.querySelectorAll('button,input[type=radio]').forEach(el=>el.disabled=summaryBusy);
    regenerateSummary.disabled=summaryBusy||(summaryMode()==='external'&&waiting);
    regenerateSummary.textContent=summaryMode()==='local'?'Generate Local Summary':'Review AI Excerpt';
    summaryAuthorization.disabled=summaryBusy;
    summarySend.disabled=summaryBusy||!summaryPreview||!summaryAuthorization.checked||waiting;
    summaryStatus.setAttribute('aria-busy',String(summaryBusy));
}
function clearSummaryPreview(){
    summaryPreview=null;summaryPanel.hidden=true;summaryAuthorization.checked=false;
    document.getElementById('summaryExcerpt').textContent='';summaryControls();
}
function cancelSummaryPreview(){
    const pending=summaryPreview,d=summaryDocument;
    clearSummaryPreview();
    if(pending&&d)api('summarize_cancel',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,previewToken:pending.previewToken})}).catch(()=>{});
}
function showSummary(d,opener){
    if(!d||currentRole!=='admin'||!summaryModal||summaryBusy)return;
    cancelSummaryPreview();summarySequence++;summaryDocument=d;summaryReturnFocus=PrismUI.actionOrigin(opener||document.activeElement);
    document.getElementById('summaryFilename').textContent=d.originalName;
    summaryResult.textContent=d.aiSummary||'No saved summary yet. Choose a mode to generate one.';
    summarySource.textContent=summarySourceLabel(d);
    summaryStatus.textContent='';summaryStatus.classList.remove('document-error');summaryLocal.hidden=true;
    summaryControls();openModal(summaryModal);summaryModal.querySelector('[data-close]').focus();
}
function summaryFailure(error){
    summaryStatus.textContent=error.message+' Any previously saved summary is retained.';
    summaryStatus.classList.add('document-error');summaryLocal.hidden=false;
    const seconds=error.data?.retryAfter;
    if(Number.isFinite(seconds)&&seconds>0){
        summaryRetryAt=Date.now()+seconds*1000;clearTimeout(summaryRetryTimer);
        summaryRetryTimer=setTimeout(summaryControls,Math.min(seconds*1000+50,2147483647));
    }
}
async function generateSummary(){
    if(!summaryDocument||summaryBusy||summaryMode()==='external'&&Date.now()<summaryRetryAt)return;
    const d=summaryDocument,sequence=++summarySequence,mode=summaryMode();cancelSummaryPreview();
    summaryBusy=true;summaryLocal.hidden=true;summaryControls();summaryStatus.classList.remove('document-error');
    summaryStatus.textContent=mode==='local'?'Preparing Local Summary…':'Preparing an excerpt for review. No information has been sent to OpenRouter.';
    try{
        const data=await api(mode==='local'?'summarize':'summarize_preview',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,mode})});
        if(sequence!==summarySequence)return;
        if(mode==='local'){saveSummaryResult(d,data);return}
        summaryPreview=data;document.getElementById('summaryExcerpt').textContent=data.excerpt;
        document.getElementById('summaryOmitted').textContent=data.partial?'This excerpt selects research sections. Some document content was omitted.':'';
        summaryPanel.hidden=false;summaryStatus.textContent='Review the exact excerpt below. It will be sent only after you confirm and select Generate AI Summary.';
    }catch(error){if(sequence===summarySequence)summaryFailure(error)}
    finally{summaryBusy=false;summaryControls()}
}
function saveSummaryResult(d,data){
    d.aiSummary=data.summary;d.summarySource=data.source;d.summaryPartial=data.partial;
    summaryResult.textContent=data.summary;summarySource.textContent=summarySourceLabel(d);
    summaryStatus.textContent=data.source==='openrouter_ai'?'AI-Powered Summary saved.':'Local Summary saved.';
    summaryLocal.hidden=true;renderTable();renderFolders();renderCourseView();
}
async function sendSummary(){
    if(!summaryDocument||summaryBusy||!summaryPreview||!summaryAuthorization.checked||Date.now()<summaryRetryAt)return;
    const d=summaryDocument,pending=summaryPreview,sequence=++summarySequence;
    summaryBusy=true;summaryControls();summaryStatus.classList.remove('document-error');summaryStatus.textContent='Generating AI summary with OpenRouter…';
    try{
        const data=await api('summarize',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,mode:'external',confirmed:true,previewToken:pending.previewToken})});
        if(sequence===summarySequence)saveSummaryResult(d,data);
    }catch(error){if(sequence===summarySequence)summaryFailure(error)}
    finally{clearSummaryPreview();summaryBusy=false;summaryControls()}
}
regenerateSummary?.addEventListener('click',generateSummary);
summarySend?.addEventListener('click',sendSummary);
summaryAuthorization?.addEventListener('change',summaryControls);
document.getElementById('cancelSummaryPreview')?.addEventListener('click',()=>{cancelSummaryPreview();summaryStatus.textContent='Excerpt canceled. No information was sent to OpenRouter.'});
summaryModal?.querySelectorAll('input[name=summaryMode]').forEach(input=>input.addEventListener('change',()=>{cancelSummaryPreview();summaryStatus.textContent='';summaryStatus.classList.remove('document-error');summaryLocal.hidden=true;summaryControls()}));
summaryLocal?.addEventListener('click',()=>{document.getElementById('summaryModeLocal').checked=true;generateSummary()});
summaryModal?.addEventListener('keydown',event=>{
    if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closeModal(summaryModal);return}
    if(event.key!=='Tab')return;
    const controls=[...summaryModal.querySelectorAll('button:not(:disabled),input:not(:disabled)')].filter(el=>el.getClientRects().length),first=controls[0],last=controls.at(-1);
    if(!first){event.preventDefault();return}
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
});
let uploadStudents=[];
async function loadUploadStudents(){
    if(!isStaff)return;
    const select=document.getElementById('documentStudent');
    if(!select)return;
    try{
        const data=await PrismUI.request('students_api.php?action=options&activeOnly=1');
        uploadStudents=data.students||[];
        const selected=select.value;
        select.replaceChildren();
        const blank=document.createElement('option');
        blank.value='';blank.textContent=uploadStudents.length?'Select a student':'No students available';
        select.appendChild(blank);
        uploadStudents.forEach(s=>{
            const o=document.createElement('option');
            o.value=String(s.id);
            o.textContent=`${s.name} (${s.studentId})${s.protocolCode?' · '+s.protocolCode:''}`;
            select.appendChild(o);
        });
        if([...select.options].some(o=>o.value===selected))select.value=selected;
    }catch(e){
        select.replaceChildren();
        const o=document.createElement('option');o.value='';o.textContent='Could not load students';select.appendChild(o);
        toast(e.message,'error');
    }
}
function filtered(){return documents}
function fileIcon(name){const ext=name.split('.').pop().toLowerCase();return ext==='pdf'?'fa-file-pdf':['doc','docx','odt','rtf'].includes(ext)?'fa-file-word':'fa-file-lines'}
function allDocumentTypes(){return [...new Set([...defaultTypes,...(listData.filterOptions?.types||documents.map(d=>d.documentType))])].filter(Boolean).sort()}
function renderTypeManager(){
    const select=document.getElementById('documentType'),selected=select.value;
    select.replaceChildren(new Option('Select a document type',''));
    defaultTypes.forEach(t=>select.add(new Option(t,t)));
    select.value=defaultTypes.includes(selected)?selected:'';
    const list=document.getElementById('documentTypeList');list.replaceChildren();
    defaultTypes.forEach(t=>{const li=document.createElement('li');li.textContent=t;list.append(li)});
}
function renderFilters(){const selected=typeFilter.value;typeFilter.querySelectorAll('option:not(:first-child)').forEach(o=>o.remove());allDocumentTypes().forEach(t=>{const o=document.createElement('option');o.value=o.textContent=t;typeFilter.appendChild(o)});typeFilter.value=[...typeFilter.options].some(o=>o.value===selected)?selected:'';const courseSelected=courseFilter.value;courseFilter.querySelectorAll('option:not(:first-child)').forEach(o=>o.remove());[...new Set((listData.filterOptions?.courses||documents.map(d=>d.course)).filter(Boolean))].sort().forEach(c=>{const o=document.createElement('option');o.value=o.textContent=c;courseFilter.appendChild(o)});courseFilter.value=[...courseFilter.options].some(o=>o.value===courseSelected)?courseSelected:'';const yearSelected=yearFilter.value;yearFilter.querySelectorAll('option:not(:first-child)').forEach(o=>o.remove());[...new Set((listData.filterOptions?.years||documents.map(d=>d.year)).filter(Boolean))].sort((a,b)=>b-a).forEach(y=>{const o=document.createElement('option');o.value=o.textContent=y;yearFilter.appendChild(o)});yearFilter.value=[...yearFilter.options].some(o=>o.value===yearSelected)?yearSelected:''}
function renderTable(){
    const rows=filtered();

    body.replaceChildren();
    if(!rows.length){
        const tr=document.createElement('tr');
        tr.innerHTML=`<td colspan="6">${PrismUI.emptyState({icon:'fa-folder-open',title:loadError?'Could not load records':(search.value||typeFilter.value||courseFilter.value||yearFilter.value)?'No matching documents':'No documents yet',text:loadError?'Please try again.':documents.length?'Try a different search or filter.':'Uploads will appear here once a document is submitted.'})}</td>`;
        body.appendChild(tr);
        return;
    }
    rows.forEach(d=>{
        const a=d.actions||{};
        const tr=document.createElement('tr');
        tr.innerHTML=`<td><div class="file-cell"><i class="fa-regular ${fileIcon(d.originalName)}"></i><div><strong>${esc(d.originalName)}</strong><span>${size(d.size)}${d.versionNo>1?' · v'+d.versionNo:''}</span></div></div></td>
            <td>${esc(d.uploadedBy)}</td>
            <td>${esc(date(d.uploadedAt))}</td>
            <td>${esc(d.student)}${d.course?` <small>(${esc(d.course)})</small>`:''}</td>
            <td><div class="doc-category"><span>${esc(d.documentType)} · ${esc(d.stageLabel||d.stage)}</span><span class="prism-pill-row">${PrismUI.badge(d.workflowState||d.reviewStatus,{small:true})}${d.adminOverride?PrismUI.badge('Admin Override',{small:true}):''}</span>${d.reviewRemarks?`<small>${esc(d.reviewRemarks)}</small>`:''}</div></td>
            <td><div class="document-row-actions">
                <a class="document-action-button" href="${documentFileHref(d)}" target="_blank" rel="noopener" title="View"><i class="fa-solid fa-eye"></i></a>
                <a class="document-action-button" href="documents_api.php?action=file&download=1&id=${encodeURIComponent(d.id)}" title="Download"><i class="fa-solid fa-download"></i></a>
                ${a.review?'<button class="document-action-button" data-review title="Full review / set status"><i class="fa-solid fa-file-pen" aria-hidden="true"></i> Review Document</button>':''}
                <button class="document-action-button" data-versions title="Version history"><i class="fa-solid fa-clock-rotate-left"></i></button>
                ${summaryButton(d)}
                ${a.review?'<button class="document-action-button approve" data-approve title="Approve"><i class="fa-solid fa-check"></i></button><button class="document-action-button deny" data-deny title="Deny"><i class="fa-solid fa-xmark"></i></button>':''}
                ${a.comment?'<button class="document-action-button" data-comment title="Add public comment"><i class="fa-regular fa-comment"></i> Add comment</button>':''}
                ${a.override?'<button class="document-action-button" data-override title="Admin Override"><i class="fa-solid fa-user-shield"></i></button>':''}
                ${a.returnForRevision?'<button class="document-action-button" data-return title="Return to Student for Revision"><i class="fa-solid fa-rotate-left"></i> Return to Student for Revision</button>':''}
                ${a.submitToRpms?'<button type="button" class="document-action-button" data-formal-submit title="Submit to RPMS"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Submit to RPMS</button>':''}

                ${a.delete?'<button class="document-action-button delete" data-delete title="Delete"><i class="fa-solid fa-trash"></i></button>':''}
            </div></td>`;
        if(d.preview){const preview=tr.querySelector('.document-row-actions');preview.replaceChildren(Object.assign(document.createElement('span'),{className:'document-preview-only',textContent:'Sample preview'}));const note=document.createElement('small');note.className='document-preview-label';note.textContent='UI preview · sample data only';tr.querySelector('.file-cell strong').append(note);}
        tr.querySelector('[data-versions]')?.addEventListener('click',()=>PrismUI.showVersions(d.id));
        if(a.review){
            tr.querySelector('[data-approve]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>reviewStatus(d,'Approved')));
            tr.querySelector('[data-deny]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>reviewStatus(d,'Denied')));
            tr.querySelector('[data-review]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>review(d)));
        }
        if(a.comment) tr.querySelector('[data-comment]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>comment(d)));
        if(a.override) tr.querySelector('[data-override]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',async()=>{if(await PrismUI.overrideDocument(d)) await load()}));
        if(a.returnForRevision) tr.querySelector('[data-return]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>returnForRevision(d)));
        if(a.submitToRpms) tr.querySelector('[data-formal-submit]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Submitting...',async()=>{if(await PrismUI.submitToRpms(d))await load();}));
        if(a.delete) tr.querySelector('[data-delete]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>remove(d)));

        let adviserViewAction=null,adviserDownloadAction=null;
        if(!d.preview){
            const actionHost=tr.querySelector('.document-row-actions');
            const fileActions=actionHost.querySelectorAll('a');
            adviserViewAction=fileActions[0];adviserDownloadAction=fileActions[1];
            if(adviserViewAction)PrismUI.documentAction(adviserViewAction,'view','View '+(d.originalName||'document'));
            if(adviserDownloadAction)PrismUI.documentAction(adviserDownloadAction,'download','Download '+(d.originalName||'document'));
            PrismUI.actionMenu(actionHost,{primary:adviserViewAction,label:'Document actions'});
        }
        body.appendChild(tr);
        PrismUI.compactRecord(tr,0,4,5,[],[2,3]);
        const card=tr.querySelector('.prism-record-card');
        card.classList.add('document-result-card');
        if(currentRole==='adviser')card.classList.add('adviser-document-result-card');
        const fileCell=card.querySelector('.file-cell');
        fileCell?.querySelector(':scope > i')?.classList.add('document-file-avatar');
        fileCell?.querySelector(':scope > i')?.setAttribute('aria-hidden','true');
        const caption=fileCell?.querySelector(':scope > div > span');
        if(caption)caption.textContent=`${d.documentType||'Document'} · ${d.stageLabel||d.stage||'Stage not recorded'} · Version v${Number(d.versionNo||1)}`;
        card.querySelector('.doc-category > span:first-child')?.remove();
        card.querySelector('.doc-category > small')?.remove();
        const summaryFields=card.querySelectorAll('.prism-record-summary > div');
        summaryFields[0].querySelector('dt').textContent='Submitted';
        summaryFields[1].querySelector('dt').textContent='Student';
        summaryFields[1].querySelector('dd').textContent=d.student||'Not recorded';
        const statusLabel=document.createElement('span');statusLabel.className='prism-record-label';statusLabel.textContent='Document status';card.querySelector('.prism-record-status').prepend(statusLabel);
        const details=card.querySelector('.prism-record-details'),fields=details.querySelector('dl');
        fields.replaceChildren(...[['Uploaded by',d.uploadedBy],['File size',size(d.size)],['Program',d.course],['Protocol code',d.protocolCode],['RPMS submission',d.rpmsSubmittedAt?date(d.rpmsSubmittedAt):'Not submitted to RPMS']].map(([label,value])=>PrismUI.recordField(label,value)));
        if(d.reviewStatus&&d.reviewStatus!==d.workflowState)fields.append(PrismUI.recordField(d.adminOverride?'RPMS override status':'Adviser review decision',d.reviewStatus));
        const feedback=PrismUI.recordFeedback(d);
        if(feedback){card.insertBefore(feedback,details);if(feedback.classList.contains('prism-feedback-indicator'))fields.append(PrismUI.recordField('Reviewer remarks',d.reviewRemarks));}
    });
    body.querySelectorAll('.prism-document-action').forEach(control=>PrismUI.documentAction(control,control.dataset.documentAction,control.getAttribute('aria-label')));
    bindSummaryButtons(body);
}
function renderPreviewFolder(host,title,icon){const folder=document.createElement('div');folder.className='document-folder';const heading=document.createElement('div');heading.className='folder-heading';const mark=document.createElement('i');mark.className='fa-solid '+icon;const label=document.createElement('strong');label.textContent=title;const total=document.createElement('span');total.textContent='1';heading.append(mark,label,total);const files=document.createElement('div');files.className='folder-files';const sample=document.createElement('span');sample.className='folder-file document-preview-folder-item';sample.textContent='Screenshot 2025-05-24 104329.png · UI preview sample only';files.append(sample);folder.append(heading,files);host.replaceChildren(folder)}
function renderFolders(){folderView.replaceChildren();if(previewOnly){renderPreviewFolder(folderView,'Cyrus Salvador','fa-folder');return}const groups=new Map();filtered().forEach(d=>{if(!groups.has(d.student))groups.set(d.student,[]);groups.get(d.student).push(d)});groups.forEach((files,name)=>{const folder=document.createElement('div');folder.className='document-folder';folder.innerHTML=`<div class="folder-heading"><i class="fa-solid fa-folder"></i><strong>${esc(name)}</strong><span>${files.length}</span></div><div class="folder-files">${files.map(f=>`<a class="folder-file" href="${documentFileHref(f)}" target="_blank"><i class="fa-regular ${fileIcon(f.originalName)}"></i>${esc(f.originalName)}</a><span class="prism-document-quick-actions">${PrismUI.documentActionsHtml(f)}</span>${summaryButton(f)}`).join('')}</div>`;folderView.appendChild(folder)});bindSummaryButtons(folderView)}
function renderCourseView(){courseView.replaceChildren();if(previewOnly){renderPreviewFolder(courseView,'BSIT','fa-layer-group');return}const groups=new Map();filtered().forEach(d=>{const key=d.course||'Unassigned / No Course';if(!groups.has(key))groups.set(key,[]);groups.get(key).push(d)});[...groups.keys()].sort().forEach(course=>{const files=groups.get(course);const folder=document.createElement('div');folder.className='document-folder';folder.innerHTML=`<div class="folder-heading"><i class="fa-solid fa-layer-group"></i><strong>${esc(course)}</strong><span>${files.length}</span></div><div class="folder-files">${files.map(f=>`<a class="folder-file" href="${documentFileHref(f)}" target="_blank"><i class="fa-regular ${fileIcon(f.originalName)}"></i>${esc(f.originalName)} <small>(${esc(f.student)}, ${esc(f.year||'')})</small></a><span class="prism-document-quick-actions">${PrismUI.documentActionsHtml(f)}</span>${summaryButton(f)}`).join('')}</div>`;courseView.appendChild(folder)});bindSummaryButtons(courseView)}
function render(){renderFilters();renderTypeManager();renderTable();renderFolders();renderCourseView()}
function openModal(m){m.classList.add('show');m.setAttribute('aria-hidden','false')}function closeModal(m){
    m.classList.remove('show');m.setAttribute('aria-hidden','true');
    if(m===summaryModal){
        const host=view==='folder'?folderView:view==='course'?courseView:body;
        const current=[...host.querySelectorAll('[data-summary]')].find(button=>button.dataset.summary===String(summaryDocument?.id));
        const focus=summaryReturnFocus?.isConnected?summaryReturnFocus:current;
        PrismUI.actionOrigin(focus)?.focus();
    }
}
function toast(message,type='success'){PrismUI.toast(message,type)}
const filterPanel=document.createElement('div');filterPanel.id='documentFilters';filterPanel.className='prism-record-filters';
document.querySelector('.document-actions').prepend(filterPanel);
[[typeFilter,'Document type'],[courseFilter,'Course'],[yearFilter,'Upload year'],[sortSelect,'Sort by']].forEach(([control,text])=>{if(!control)return;const label=document.createElement('label');label.textContent=text;label.append(control);filterPanel.append(label);});
const stateFilter=document.createElement('select');stateFilter.id='documentStateFilter';stateFilter.setAttribute('aria-label','Workflow status');
[['','All'],['Pending Adviser Review','Pending adviser review'],['Needs Revision','Needs revision'],['Returned for Revision','Returned by RPMS'],['Ready for Formal RPMS Submission','Ready for formal submission'],['Submitted to RPMS','Submitted to RPMS'],['Superseded','Superseded']].forEach(([value,label])=>stateFilter.add(new Option(label,value)));
const stateLabel=document.createElement('label');stateLabel.textContent='Workflow status';stateLabel.append(stateFilter);filterPanel.append(stateLabel);
const pager=PrismUI.recordPager(document.getElementById('documentsCourseView'),document.getElementById('documentCount'),[search,typeFilter,courseFilter,yearFilter,sortSelect,stateFilter].filter(Boolean),load,
    {host:filterPanel,toolbar:document.querySelector('.document-actions')});
const statePills=document.createElement('div');document.querySelector('.document-controls').append(statePills);
const stateDefinitions=[['','All'],['Pending Adviser Review','Pending review'],['Needs Revision','Needs revision'],['Returned for Revision','Returned by RPMS'],['Submitted to RPMS','Submitted to RPMS']];
function documentFileHref(d){return ['application/pdf','image/png','image/jpeg'].includes(d.mime)?'document_preview.php?id='+encodeURIComponent(d.id):'documents_api.php?action=file&download=1&id='+encodeURIComponent(d.id)}
function adviserPreviewRecord(){return{id:'ui-preview-sample',preview:true,originalName:'Research Proposal.pdf',uploadedBy:'Sample Student',uploadedAt:new Date('2026-10-14T19:52:19+08:00').toISOString(),student:'Sample Student',course:'BS in Information Technology',year:'2026',documentType:'Research Proposal',stage:'Stage 1',stageLabel:'Stage 1',workflowState:'Pending Adviser Review',reviewStatus:'Under Review',reviewRemarks:'Sample reviewer remarks appear here.',size:38700,versionNo:1,actions:{}}}
stateFilter.addEventListener('change',()=>{pager.reset();load();});
document.addEventListener('click',event=>{if(event.target.closest('.prism-clear-filters')){stateFilter.value='';}});
async function load(){
    const sequence=++loadSequence;pager.loading();body.setAttribute('aria-busy','true');
    const qs=new URLSearchParams({action:'list',page:pager.page,perPage:pager.pageSize,q:search.value.trim(),type:typeFilter.value,course:courseFilter.value,year:yearFilter.value,sort:sortSelect?.value||'newest',state:stateFilter.value});
    try{const data=await PrismUI.request('documents_api.php?'+qs);if(sequence!==loadSequence)return;listData=data;documents=data.documents;previewOnly=uiPreview&&!documents.length&&!search.value.trim()&&!typeFilter.value&&!courseFilter.value&&!yearFilter.value&&!stateFilter.value;if(previewOnly)documents=[adviserPreviewRecord()];loadError=false;render();pager.render({...data,total:previewOnly?1:data.total??documents.length});if(previewOnly)document.getElementById('documentCount').textContent='UI preview · 1 sample record (not stored)';PrismUI.statusPills(statePills,stateDefinitions,stateFilter,previewOnly?{'Pending Adviser Review':1}:data.counts||{})}
    catch(e){if(sequence!==loadSequence)return;loadError=true;documents=[];previewOnly=false;render();pager.error();toast(e.message,'error')}
    finally{if(sequence===loadSequence)body.setAttribute('aria-busy','false')}
}
uploadForm.addEventListener('submit',async e=>{
    e.preventDefault();
    const files=document.getElementById('documentFile').files;
    if(!files.length){toast('Choose documents to upload.','error');return}
    const button=uploadForm.querySelector('[type="submit"]');
    if(button.disabled)return;
    const original=button.innerHTML;
    button.disabled=true;
    button.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';
    try{
        const data=await api('upload',{method:'POST',body:PrismUpload.prepare(new FormData(uploadForm),files.length)});
        PrismUpload.render(data);uploadForm.reset();document.getElementById('uploadSelection').textContent='';await load();toast(data.message,data.failed?'error':'success')
    }catch(err){
        PrismUpload.render(err.data||{message:err.message+' Check Documents before selecting files again; some uploads may have completed.'});
        document.getElementById('documentFile').value='';document.getElementById('uploadSelection').textContent='';await load();toast(err.message,'error')
    }
    finally{button.disabled=false;button.innerHTML=original}
});
async function remove(d){
    const needsReason=!!d.locked;
    const answer=await PrismUI.confirm({
        title:'Delete document',icon:'fa-trash',tone:'danger',confirmText:'Delete',
        message:`Delete â€œ${d.originalName}â€? ${needsReason?'This formally submitted document is locked, so the reason will be recorded in the audit trail.':'This cannot be undone.'}`,
        reasonLabel:needsReason?'Reason for deleting this submitted document':'',
        reasonRequired:needsReason
    });
    if(!answer)return;
    try{const data=await api('delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,reason:answer.reason||''})});await load();toast(data.message||'Document deleted.')}
    catch(e){toast(e.message,'error')}
}
async function reviewStatus(d,status){
    const corrective=['Denied','Resubmission Requested'].includes(status);
    const answer=await PrismUI.confirm({
        title:`${status} document`,icon:status==='Approved'?'fa-check':'fa-clipboard-check',
        tone:status==='Denied'?'danger':'primary',confirmText:status,
        message:corrective?'Explain what the student needs to correct before resubmitting.':status==='Approved'&&currentRole==='adviser'?`Approve â€œ${d.originalName}â€ version ${d.versionNo} and formally submit it to RPMS?`:'You may add reviewer remarks before saving this decision.',
        reasonLabel:corrective?'Reviewer remarks (required)':'Reviewer remarks (optional)',
        reasonRequired:corrective,minReason:1
    });
    if(!answer)return;
    try{const data=await api('review',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,status,remarks:answer.reason})});await load();toast(data.message||`Document marked as ${status}.`)}
    catch(e){toast(e.message,'error')}
}
async function comment(d){
    const answer=await PrismUI.confirm({title:'Add reviewer comment',icon:'fa-comment',confirmText:'Save comment',
        message:'The review status will stay the same. The student will receive this comment.',
        reasonLabel:'Comment',reasonRequired:true,minReason:1});
    if(!answer)return;
    try{const data=await api('comment',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,remarks:answer.reason})});await load();toast(data.message||'Comment saved and sent to the student.')}
    catch(e){toast(e.message,'error')}
}
async function review(d){
    const allowed=['Under Review','Received','Verified','Resubmission Requested','Approved','Denied'];
    const options=allowed.map(s=>`<option${s===d.reviewStatus?' selected':''}>${esc(s)}</option>`).join('');
    const answer=await PrismUI.confirm({
        title:'Review document',icon:'fa-clipboard-check',confirmText:'Save review',dialogClass:'document-review-dialog',
        message:`Update the review status for â€œ${d.originalName}â€. ${currentRole==='adviser'?'Approve also formally submits this current version to RPMS.':''}`,
        extraHtml:`<label for="prismReviewStatus">Review status</label><select id="prismReviewStatus">${options}</select>`,
        reasonLabel:'Reviewer remarks',reasonRequired:false,
        collect:dlg=>({status:dlg.querySelector('#prismReviewStatus').value}),
        validate:(v,remarks)=>['Denied','Resubmission Requested'].includes(v.status)&&!remarks?'Add remarks explaining what the student needs to correct or resubmit.':null
    });
    if(!answer)return;
    try{const data=await api('review',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,status:answer.values.status,remarks:answer.reason})});await load();toast(data.message||'Document review saved.')}
    catch(e){toast(e.message,'error')}
}
async function returnForRevision(d){
    const answer=await PrismUI.confirm({title:'Return to Student for Revision',icon:'fa-rotate-left',tone:'warning',confirmText:'Return for Revision',
        message:`Return â€œ${d.originalName}â€ (${d.documentType}, version ${d.versionNo}) to ${d.student}? The original submission and approval will be preserved. The Student must upload a new revision for Adviser review.`,
        reasonLabel:'RPMS return reason (required)',reasonRequired:true,minReason:1,maxReason:5000});
    if(!answer)return;
    try{const data=await PrismUI.postJson('documents_api.php?action=return_for_revision',{id:d.id,reason:answer.reason});await load();toast(data.message);document.dispatchEvent(new CustomEvent('prism:documents-changed'));}
    catch(e){toast(e.message,'error');}
}
document.getElementById('uploadDocumentButton').addEventListener('click',async()=>{await loadUploadStudents();openModal(uploadModal)});document.getElementById('documentStudent')?.addEventListener('change',e=>{
    const s=uploadStudents.find(x=>String(x.id)===e.target.value);
    if(s&&s.stage)document.getElementById('documentStage').value=s.stage;
});
document.getElementById('manageDocumentTypes').addEventListener('click',()=>openModal(document.getElementById('documentTypesModal')));document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>closeModal(document.getElementById(b.dataset.close))));[uploadModal,summaryModal,document.getElementById('documentTypesModal')].filter(Boolean).forEach(m=>m.addEventListener('click',e=>{if(e.target===m)closeModal(m)}));search.addEventListener('input',()=>{pager.reset();load()});typeFilter.addEventListener('change',()=>{pager.reset();load()});courseFilter.addEventListener('change',()=>{pager.reset();load()});yearFilter.addEventListener('change',()=>{pager.reset();load()});if(sortSelect)sortSelect.addEventListener('change',()=>{pager.reset();load()});
function setView(next){view=next;tableView.style.display=next==='table'?'block':'none';folderView.classList.toggle('show',next==='folder');courseView.classList.toggle('show',next==='course');document.getElementById('tableViewButton').classList.toggle('active',next==='table');document.getElementById('folderViewButton').classList.toggle('active',next==='folder');document.getElementById('courseViewButton').classList.toggle('active',next==='course')}
document.getElementById('tableViewButton').addEventListener('click',()=>setView('table'));document.getElementById('folderViewButton').addEventListener('click',()=>setView('folder'));document.getElementById('courseViewButton').addEventListener('click',()=>setView('course'));
const theme=document.getElementById('themeToggle');theme.addEventListener('click',()=>{const dark=document.documentElement.classList.toggle('dark-theme');try{localStorage.setItem('prismTheme',dark?'dark':'light')}catch(_){}});document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(summaryModal?.classList.contains('show'))closeModal(summaryModal);closeModal(uploadModal);closeModal(document.getElementById('documentTypesModal'))}});load();
});
