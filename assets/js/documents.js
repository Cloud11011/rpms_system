document.addEventListener('DOMContentLoaded',()=>{
const currentRole=document.body.dataset.role||'admin';const isStaff=currentRole==='admin'||currentRole==='adviser';const uiPreview=(document.body.dataset.prismAllowDemo==='1') && new URLSearchParams(window.location.search).get('ui-preview')==='1';
if(!isStaff){const studentField=document.getElementById('documentStudent');if(studentField){studentField.closest('div').style.display='none';studentField.required=false}}
let documents=[],listData={},loadSequence=0,loadError=false,view='table',previewOnly=false;const defaultTypes=PrismUI.documentTypes;const body=document.getElementById('documentsTableBody'),search=document.getElementById('documentSearch'),typeFilter=document.getElementById('documentTypeFilter'),courseFilter=document.getElementById('documentCourseFilter'),yearFilter=document.getElementById('documentYearFilter'),sortSelect=document.getElementById('documentSort'),tableView=document.getElementById('documentsTableView'),folderView=document.getElementById('documentsFolderView'),courseView=document.getElementById('documentsCourseView'),uploadModal=document.getElementById('uploadModal'),uploadForm=document.getElementById('uploadForm');
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'})[c]);
const size=v=>v<1024?`${v} B`:v<1048576?`${(v/1024).toFixed(1)} KB`:`${(v/1048576).toFixed(1)} MB`;const date=v=>new Date(v).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
async function api(action,options={}){return PrismUI.request(`documents_api.php?action=${encodeURIComponent(action)}`,options)}
const summaryModal=document.getElementById('summaryModal'),summaryResult=document.getElementById('summaryResult'),summarySource=document.getElementById('summarySource'),regenerateSummary=document.getElementById('regenerateSummary');
let summaryDocument=null,summarySequence=0,summaryReturnFocus=null,summaryBusy=false;
const summaryMode=document.getElementById('summaryMode'),summaryReview=document.getElementById('summaryReview'),summaryExcerpt=document.getElementById('summaryExcerpt'),summaryConfirmed=document.getElementById('summaryConfirmed');
let summaryPreviewToken=null;
const summaryVersion=document.getElementById('summaryVersion'),summaryPdf=document.getElementById('summaryPdf');
let summaryVersions=[];
function selectSummaryVersion(){
    const v=summaryVersions.find(row=>String(row.id)===summaryVersion.value);if(!v)return;
    summaryResult.replaceChildren();
    String(v.summary_content).split(/\n\s*\n/).forEach(text=>{
        const match=text.match(/^\s*(?:#{1,4}\s*)?(Source facts|Purpose|Methods|Methodology|Findings stated in source|Findings|Limitations and interpretation|Limitations)\s*:?\s*(?:\n|$)/i);
        if(match){const heading=document.createElement('h3');heading.textContent=match[1];summaryResult.append(heading);text=text.slice(match[0].length)}
        if(text){const p=document.createElement('p');p.textContent=text;summaryResult.append(p)}
    });
    summarySource.textContent=summarySourceLabel(v.source_type,!!Number(v.partial_extraction))+' · v'+v.version_no+' · '+(v.generated_at||'Generation time not recorded');
    document.getElementById('summaryLimitations').textContent=v.limitations;
    summaryPdf.href='documents_api.php?action=summary_pdf&id='+encodeURIComponent(summaryDocument.id)+'&summaryId='+encodeURIComponent(v.id);summaryPdf.hidden=false;
}
async function loadSummaryVersions(d){
    summaryVersion.disabled=true;summaryPdf.hidden=true;summaryVersion.replaceChildren(new Option('Loading versions...',''));
    try{const result=await PrismUI.request('documents_api.php?action=summary_history&id='+encodeURIComponent(d.id));if(summaryDocument!==d)return;
        summaryVersions=result.versions||[];summaryVersion.replaceChildren(...summaryVersions.map(v=>new Option('Version '+v.version_no+' · '+(v.generated_at||'Historical value')+' · '+v.source_type,String(v.id))));
        if(!summaryVersions.length)summaryVersion.add(new Option('No saved summaries',''));
        summaryVersion.disabled=!summaryVersions.length;selectSummaryVersion();
    }catch(e){if(summaryDocument===d){summaryVersion.replaceChildren(new Option('History unavailable',''));summarySource.textContent=e.message}}
}
summaryVersion?.addEventListener('change',selectSummaryVersion);
function summaryButton(d){return currentRole==='admin'&&d.actions?.summarize&&summaryModal?`<button type="button" class="document-action-button summary-action" data-summary="${esc(d.id)}"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>${d.aiSummary?'View Summary':'Generate Summary'}</button>`:''}
function bindSummaryButtons(host){host.querySelectorAll('[data-summary]').forEach(button=>button.addEventListener('click',()=>showSummary(documents.find(d=>String(d.id)===button.dataset.summary),button)))}
function showSummary(d,opener){
    if(!d||currentRole!=='admin'||!summaryModal||summaryBusy)return;
    summaryDocument=d;summaryReturnFocus=PrismUI.actionOrigin(opener||document.activeElement);
    document.getElementById('summaryFilename').textContent=d.originalName;
    summaryResult.textContent=d.aiSummary||'';
    summaryResult.classList.remove('document-error');summarySource.classList.remove('document-error');
    summaryMode.value=d.summarySource==='ai'?'ai':'local';clearSummaryPreview();
    summarySource.textContent=d.aiSummary?summarySourceLabel(d.summarySource,d.summaryPartial):'';
    openModal(summaryModal);summaryModal.querySelector('[data-close]').focus();
    document.getElementById('summaryLimitations').textContent='';loadSummaryVersions(d);
}
function summarySourceLabel(source,partial){return (source==='ai'?'AI-Powered Summary · Liquid AI':source==='local'||source==='local_fallback'?'Local Summary':'Previously saved summary')+(partial?' · Based on a bounded excerpt.':'')}
function clearSummaryPreview(){
    const token=summaryPreviewToken;summaryPreviewToken=null;
    if(token)api('summarize_cancel',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:summaryDocument?.id,previewToken:token})}).catch(()=>{});
    if(summaryReview)summaryReview.hidden=true;if(summaryExcerpt)summaryExcerpt.value='';if(summaryConfirmed)summaryConfirmed.checked=false;
    if(regenerateSummary){regenerateSummary.textContent='Generate Summary';regenerateSummary.disabled=summaryBusy}
    const note=document.getElementById('summaryModeNote');if(note)note.textContent=summaryMode?.value==='ai'?'Review the extracted excerpt before sending it to Liquid AI.':'Local Summary runs in PRISM without sending document contents externally.';
}
summaryMode?.addEventListener('change',clearSummaryPreview);
summaryConfirmed?.addEventListener('change',()=>{regenerateSummary.disabled=summaryBusy||!summaryConfirmed.checked});
async function generateSummary(){
    if(!summaryDocument||summaryBusy)return;
    if(summaryMode.value==='ai'&&summaryPreviewToken&&!summaryConfirmed.checked)return;
    const d=summaryDocument,sequence=++summarySequence;summaryBusy=true;regenerateSummary.disabled=true;
    summaryMode.disabled=true;summaryResult.setAttribute('aria-busy','true');summaryResult.classList.remove('document-error');summarySource.classList.remove('document-error');
    const preview=summaryMode.value==='ai'&&!summaryPreviewToken;
    summarySource.textContent=preview?'Extracting text for your review…':summaryMode.value==='ai'?'Liquid AI is generating the summary…':'Generating Local Summary…';
    try{
        const data=await api(preview?'summarize_preview':'summarize',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,mode:summaryMode.value,...(!preview&&summaryMode.value==='ai'?{previewToken:summaryPreviewToken,confirmed:true}:{})})});
        if(sequence!==summarySequence)return;
        if(preview){summaryPreviewToken=data.previewToken;summaryExcerpt.value=data.excerpt;summaryReview.hidden=false;summaryConfirmed.checked=false;regenerateSummary.textContent='Generate AI Summary';summarySource.textContent='Review and authorize this excerpt. No document text has been sent externally.';summaryConfirmed.focus();return}
        summaryPreviewToken=null;summaryReview.hidden=true;summaryExcerpt.value='';summaryConfirmed.checked=false;
        d.aiSummary=data.summary;summaryResult.textContent=data.summary;
        d.summarySource=data.source;d.summaryPartial=data.partial;summarySource.textContent=summarySourceLabel(data.source,data.partial);regenerateSummary.textContent='Generate Summary';await loadSummaryVersions(d);
        await load();
    }catch(error){if(sequence===summarySequence){clearSummaryPreview();summaryResult.textContent=d.aiSummary||'';summarySource.textContent=error.message+' Any existing saved summary is retained.';summarySource.classList.add('document-error')}}
    finally{summaryBusy=false;summaryMode.disabled=false;regenerateSummary.disabled=!!summaryPreviewToken&&!summaryConfirmed.checked;summaryResult.setAttribute('aria-busy','false')}
}
regenerateSummary?.addEventListener('click',generateSummary);
summaryModal?.addEventListener('keydown',event=>{
    if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closeModal(summaryModal);return}
    if(event.key!=='Tab')return;
    const buttons=[...summaryModal.querySelectorAll('button:not(:disabled),select:not(:disabled),input:not(:disabled),textarea')].filter(el=>el.getClientRects().length),first=buttons[0],last=buttons.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
});
// Selection keeps exact server identities across pages and presentation views.
const selectedDocuments=new Map();let matchingSelection=null,selectionFilterKey=null,selectionBusy=false,deleteBusy=false,deleteConfirmation=null,deleteReturnFocus=null;
const pageSelection=document.getElementById('selectDocumentPage'),allSelection=document.getElementById('selectAllDocuments'),clearSelectionButton=document.getElementById('clearDocumentSelection'),bulkDeleteButton=document.getElementById('bulkDeleteDocuments'),deleteModal=document.getElementById('bulkDeleteModal'),deleteReason=document.getElementById('bulkDeleteReason'),deleteConfirmButton=document.getElementById('confirmBulkDelete'),deleteStatus=document.getElementById('bulkDeleteStatus');
function isDocumentSelected(id){return matchingSelection?matchingSelection.ids.has(String(id))&&!matchingSelection.excluded.has(String(id)):selectedDocuments.has(String(id))}
function selectedDocumentCount(){return matchingSelection?matchingSelection.ids.size-matchingSelection.excluded.size:selectedDocuments.size}
function selectionControl(d){return currentRole==='admin'&&!d.preview?`<label class="document-record-select"><input type="checkbox" data-document-select="${esc(d.id)}" aria-label="Select ${esc(d.originalName)} version ${Number(d.versionNo||1)}" ${isDocumentSelected(d.id)?'checked':''}><span class="document-selection-label">Select</span></label>`:''}
function updateDocumentSelection(){
    if(!pageSelection)return;
    const visible=documents.filter(d=>!d.preview),count=selectedDocumentCount(),onPage=visible.filter(d=>isDocumentSelected(d.id)).length;
    pageSelection.checked=visible.length>0&&onPage===visible.length;pageSelection.indeterminate=onPage>0&&onPage<visible.length;pageSelection.disabled=selectionBusy||deleteBusy||loadError||!visible.length;
    allSelection.disabled=selectionBusy||deleteBusy||loadError||previewOnly||!listData.total;
    clearSelectionButton.disabled=selectionBusy||deleteBusy||(!count&&!matchingSelection);bulkDeleteButton.disabled=selectionBusy||deleteBusy||!count;
    document.getElementById('documentSelectedCount').textContent=`${count} selected${matchingSelection?' across all matching pages':''}`;
    document.querySelectorAll('[data-document-select]').forEach(input=>{input.checked=isDocumentSelected(input.dataset.documentSelect);input.disabled=selectionBusy||deleteBusy});
}
function clearDocumentSelection(){selectedDocuments.clear();matchingSelection=null;deleteConfirmation=null;if(deleteModal?.classList.contains('show')&&!deleteBusy)closeModal(deleteModal);updateDocumentSelection()}
function selectDocument(d,checked){
    if(!d||d.preview||selectionBusy||deleteBusy)return;
    const id=String(d.id);
    if(matchingSelection){if(!matchingSelection.ids.has(id)){toast('This document was added after Select All. Clear the selection to include it.','error');return}if(checked)matchingSelection.excluded.delete(id);else matchingSelection.excluded.add(id)}
    else if(checked)selectedDocuments.set(id,d.selectionVersion);else selectedDocuments.delete(id);
}
document.addEventListener('change',event=>{const input=event.target.closest('[data-document-select]');if(!input)return;selectDocument(documents.find(d=>String(d.id)===input.dataset.documentSelect),input.checked);updateDocumentSelection()});
pageSelection?.addEventListener('change',()=>{documents.forEach(d=>selectDocument(d,pageSelection.checked));updateDocumentSelection()});
clearSelectionButton?.addEventListener('click',clearDocumentSelection);
allSelection?.addEventListener('click',async()=>{
    if(selectionBusy||deleteBusy)return;selectionBusy=true;updateDocumentSelection();const filterKey=selectionFilterKey;
    try{const data=await api('selection_snapshot',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({filters:documentQuery()})});if(filterKey!==selectionFilterKey)return;selectedDocuments.clear();matchingSelection={token:data.selectionToken,ids:new Set(data.ids.map(String)),excluded:new Set()};toast(`${data.count} matching documents selected across all pages.`)}catch(error){toast(error.message,'error')}finally{selectionBusy=false;updateDocumentSelection()}
});
const uploaderRoles={student:'Student',adviser:'Research Adviser',admin:'RPMS Administrator'};
// Server-resolved targets only; every value is rendered as text.
function renderBulkDeleteRecords(records){
    const list=document.getElementById('bulkDeleteRecords');list.replaceChildren();
    document.getElementById('bulkDeleteRecordTotal').textContent=String(records.length);
    records.forEach(record=>{
        const item=document.createElement('li'),name=document.createElement('strong'),meta=document.createElement('span');
        if(record.missing){name.textContent=`Document ${record.id}`;meta.textContent='No longer available. It will not be deleted from this confirmation.';}
        else{
            name.textContent=record.originalName;
            const uploader=record.uploadedBy?record.uploadedBy+(uploaderRoles[record.uploaderRole]?` (${uploaderRoles[record.uploaderRole]})`:''):'Not recorded';
            meta.textContent=`${record.documentType||'Unclassified'} · Version ${record.versionNo}${record.isCurrent?' (current)':' (previous)'} · Uploaded by: ${uploader}${record.studentName?` · Student: ${record.studentName}`:''}${record.locked?' · Formally submitted':''}`;
        }
        item.append(name,meta);list.append(item);
    });
}
bulkDeleteButton?.addEventListener('click',async()=>{
    if(!selectedDocumentCount()||selectionBusy||deleteBusy)return;deleteReturnFocus=bulkDeleteButton;selectionBusy=true;updateDocumentSelection();const filterKey=selectionFilterKey;
    const selection=matchingSelection?{selectionToken:matchingSelection.token,excludedIds:[...matchingSelection.excluded]}:{ids:[...selectedDocuments.keys()],expectedVersions:Object.fromEntries(selectedDocuments)};
    try{
        const data=await api('bulk_delete_preview',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(selection)});if(filterKey!==selectionFilterKey)return;
        deleteConfirmation=data;deleteReason.value='';deleteStatus.textContent='';deleteStatus.classList.remove('document-error');
        const counts=document.getElementById('bulkDeleteCounts');counts.replaceChildren();
        [['Selected',data.count],['Current versions',data.currentVersions],['Historical versions',data.historicalVersions],['Formally submitted',data.submitted],['No longer eligible',(data.ineligible||[]).length]].forEach(([label,value])=>{const term=document.createElement('dt'),detail=document.createElement('dd');term.textContent=label;detail.textContent=value;counts.append(term,detail)});
        renderBulkDeleteRecords(data.records||[]);
        const ineligible=document.getElementById('bulkDeleteIneligible');ineligible.replaceChildren();(data.ineligible||[]).forEach(record=>{const item=document.createElement('li');item.textContent=`${record.id}: ${record.message}`;ineligible.append(item)});
        if(!data.canDelete)deleteStatus.textContent='Nothing can be deleted from this confirmation. Clear the selection and refresh the records.';
        deleteConfirmButton.disabled=true;openModal(deleteModal);deleteReason.focus();
    }catch(error){toast(error.message,'error')}finally{selectionBusy=false;updateDocumentSelection()}
});
deleteReason?.addEventListener('input',()=>{deleteConfirmButton.disabled=deleteBusy||!deleteConfirmation?.canDelete||[...deleteReason.value.trim()].length<Number(deleteReason.minLength)});
deleteConfirmButton?.addEventListener('click',async()=>{
    if(deleteBusy||deleteConfirmButton.disabled||!deleteConfirmation?.confirmationToken)return;
    deleteBusy=true;deleteConfirmButton.disabled=true;deleteReason.disabled=true;deleteStatus.textContent='Deleting the confirmed documents…';updateDocumentSelection();
    try{
        const data=await api('bulk_delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({confirmationToken:deleteConfirmation.confirmationToken,confirmed:true,reason:deleteReason.value.trim()})});
        deleteBusy=false;closeModal(deleteModal);clearDocumentSelection();await load();toast(data.message,data.cleanupFailures?.length?'error':'success');
    }catch(error){deleteConfirmation=null;deleteStatus.textContent=error.message+' Refresh and select the records again before another deletion attempt.';deleteStatus.classList.add('document-error')}
    finally{deleteBusy=false;deleteReason.disabled=false;deleteConfirmButton.disabled=true;updateDocumentSelection()}
});
deleteModal?.addEventListener('keydown',event=>{
    if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closeModal(deleteModal)}
    if(event.key!=='Tab')return;const controls=[...deleteModal.querySelectorAll('button:not(:disabled),textarea:not(:disabled)')],first=controls[0],last=controls.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus()}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus()}
});
let uploadStudents=[];
document.getElementById('viewProjectRequirements')?.addEventListener('click',async()=>{
    await loadUploadStudents();
    const select=document.getElementById('documentStudent').cloneNode(true);select.id='requirementStudent';
    const answer=await PrismUI.confirm({title:'Research project requirements',confirmText:'Open checklist',
        message:'Choose the designated submission holder to view current requirements, including missing documents.',
        extraHtml:'<label for="requirementStudent">Submission holder</label>'+select.outerHTML,
        collect:dialog=>({studentId:dialog.querySelector('#requirementStudent').value}),
        validate:values=>values.studentId?'':'Select a submission holder.'});
    if(answer)await PrismRequirements.open(answer.values.studentId);
});
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
        tr.innerHTML=`<td><div class="file-cell">${selectionControl(d)}<i class="fa-regular ${fileIcon(d.originalName)}"></i><div><strong>${esc(d.originalName)}</strong><span>${size(d.size)}${d.versionNo>1?' · v'+d.versionNo:''}</span></div></div></td>
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
            if(d.studentId){const requirements=document.createElement('button');requirements.className='document-action-button';requirements.textContent='Project requirements';requirements.onclick=()=>PrismRequirements.open(d.studentId);actionHost.append(requirements);}
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
function renderFolders(){folderView.replaceChildren();if(previewOnly){renderPreviewFolder(folderView,'Cyrus Salvador','fa-folder');return}const groups=new Map();filtered().forEach(d=>{if(!groups.has(d.student))groups.set(d.student,[]);groups.get(d.student).push(d)});groups.forEach((files,name)=>{const folder=document.createElement('div');folder.className='document-folder';folder.innerHTML=`<div class="folder-heading"><i class="fa-solid fa-folder"></i><strong>${esc(name)}</strong><span>${files.length}</span></div><div class="folder-files">${files.map(f=>`${selectionControl(f)}<a class="folder-file" href="${documentFileHref(f)}" target="_blank"><i class="fa-regular ${fileIcon(f.originalName)}"></i>${esc(f.originalName)}</a><span class="prism-document-quick-actions">${PrismUI.documentActionsHtml(f)}</span>${summaryButton(f)}`).join('')}</div>`;folderView.appendChild(folder)});bindSummaryButtons(folderView)}
function renderCourseView(){courseView.replaceChildren();if(previewOnly){renderPreviewFolder(courseView,'BSIT','fa-layer-group');return}const groups=new Map();filtered().forEach(d=>{const key=d.course||'Unassigned / No Course';if(!groups.has(key))groups.set(key,[]);groups.get(key).push(d)});[...groups.keys()].sort().forEach(course=>{const files=groups.get(course);const folder=document.createElement('div');folder.className='document-folder';folder.innerHTML=`<div class="folder-heading"><i class="fa-solid fa-layer-group"></i><strong>${esc(course)}</strong><span>${files.length}</span></div><div class="folder-files">${files.map(f=>`${selectionControl(f)}<a class="folder-file" href="${documentFileHref(f)}" target="_blank"><i class="fa-regular ${fileIcon(f.originalName)}"></i>${esc(f.originalName)} <small>(${esc(f.student)}, ${esc(f.year||'')})</small></a><span class="prism-document-quick-actions">${PrismUI.documentActionsHtml(f)}</span>${summaryButton(f)}`).join('')}</div>`;courseView.appendChild(folder)});bindSummaryButtons(courseView)}
function render(){renderFilters();renderTypeManager();renderTable();renderFolders();renderCourseView();updateDocumentSelection()}
function openModal(m){m.classList.add('show');m.setAttribute('aria-hidden','false')}function closeModal(m){
    if(m===summaryModal&&summaryBusy)return;
    if(m===deleteModal&&deleteBusy)return;
    m.classList.remove('show');m.setAttribute('aria-hidden','true');
    if(m===deleteModal){deleteConfirmation=null;PrismUI.actionOrigin(deleteReturnFocus)?.focus()}
    if(m===summaryModal){
        clearSummaryPreview();
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
const advancedFilters={};
if(currentRole==='admin'){
    const addSelect=(key,id,label,options)=>{const control=document.createElement('select');control.id=id;options.forEach(([value,text])=>control.add(new Option(text,value)));const wrapper=document.createElement('label');wrapper.textContent=label;wrapper.append(control);filterPanel.append(wrapper);advancedFilters[key]=control;return control};
    addSelect('stage','documentStageFilter','IERB stage',[['','All stages'],...(window.PRISM_STAGE_SEQUENCE||['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']).map(stage=>[stage,stage+' · '+((window.PRISM_STAGE_LABELS||{})[stage]||stage)])]);
    addSelect('adviser','documentAdviserFilter','Assigned adviser',[['','All advisers'],['unassigned','Unassigned']]);
    addSelect('review','documentReviewFilter','Review status',[['','All review statuses']]);
    addSelect('includeOld','documentVersionsFilter','Document versions',[['','Current versions'],['1','All versions (including historical)']]);
    [['fromDate','documentFromDate','From date'],['toDate','documentToDate','To date']].forEach(([key,id,label])=>{const control=document.createElement('input');control.type='date';control.id=id;const wrapper=document.createElement('label');wrapper.textContent=label;wrapper.append(control);filterPanel.append(wrapper);advancedFilters[key]=control});
    [['student-desc','Student (Z–A)'],['type-asc','Document type (A–Z)'],['adviser-asc','Assigned adviser (A–Z)'],['stage-asc','IERB stage (ascending)'],['stage-desc','IERB stage (descending)'],['review','Review status'],['reviewed-newest','Last reviewed (newest first)']].forEach(([value,label])=>sortSelect.add(new Option(label,value)));
    Object.values(advancedFilters).forEach(control=>control.addEventListener('change',()=>{pager.reset();load()}));
}
const pager=PrismUI.recordPager(document.getElementById('documentsCourseView'),document.getElementById('documentCount'),[search,typeFilter,courseFilter,yearFilter,sortSelect,stateFilter,...Object.values(advancedFilters)].filter(Boolean),load,
    {host:filterPanel,toolbar:document.querySelector('.document-actions')});
// Shared pager treats text inputs as search; these two controls retain native date validation.
if(advancedFilters.fromDate)advancedFilters.fromDate.type='date';if(advancedFilters.toDate)advancedFilters.toDate.type='date';
const statePills=document.createElement('div');document.querySelector('.document-controls').append(statePills);
const stateDefinitions=[['','All'],['Pending Adviser Review','Pending review'],['Needs Revision','Needs revision'],['Returned for Revision','Returned by RPMS'],['Submitted to RPMS','Submitted to RPMS']];
function documentFileHref(d){return ['application/pdf','image/png','image/jpeg'].includes(d.mime)?'document_preview.php?id='+encodeURIComponent(d.id):'documents_api.php?action=file&download=1&id='+encodeURIComponent(d.id)}
function adviserPreviewRecord(){return{id:'ui-preview-sample',preview:true,originalName:'Research Proposal.pdf',uploadedBy:'Sample Student',uploadedAt:new Date('2026-10-14T19:52:19+08:00').toISOString(),student:'Sample Student',course:'BS in Information Technology',year:'2026',documentType:'Research Proposal',stage:'Stage 1',stageLabel:'Stage 1',workflowState:'Pending Adviser Review',reviewStatus:'Under Review',reviewRemarks:'Sample reviewer remarks appear here.',size:38700,versionNo:1,actions:{}}}
stateFilter.addEventListener('change',()=>{pager.reset();load();});
document.addEventListener('click',event=>{if(event.target.closest('.prism-clear-filters')){stateFilter.value='';}});
function documentQuery(){return {q:search.value.trim(),type:typeFilter.value,course:courseFilter.value,year:yearFilter.value,sort:sortSelect?.value||'newest',state:stateFilter.value,...Object.fromEntries(Object.entries(advancedFilters).map(([key,control])=>[key,control.value]))}}
function renderAdvancedOptions(){
    const replace=(control,options)=>{if(!control)return;const selected=control.value;while(control.options.length>1)control.remove(1);options.forEach(([value,label])=>control.add(new Option(label,value)));if([...control.options].some(option=>option.value===selected))control.value=selected};
    replace(advancedFilters.adviser,[['unassigned','Unassigned'],...(listData.filterOptions?.advisers||[]).map(adviser=>[String(adviser.id),adviser.name])]);
    replace(advancedFilters.review,(listData.filterOptions?.reviews||[]).map(value=>[value,value]));
    if(listData.filterOptions?.stages)replace(advancedFilters.stage,listData.filterOptions.stages.map(stage=>[stage.key,stage.key+' · '+stage.label]));
}
async function load(){
    const sequence=++loadSequence;pager.loading();body.setAttribute('aria-busy','true');
    const query=documentQuery(),{sort,...filterQuery}=query,filterKey=JSON.stringify(filterQuery);if(selectionFilterKey!==filterKey){clearDocumentSelection();selectionFilterKey=filterKey}
    const qs=new URLSearchParams({action:'list',page:pager.page,perPage:pager.pageSize,...query});
    try{const data=await PrismUI.request('documents_api.php?'+qs);if(sequence!==loadSequence)return;listData=data;documents=data.documents;previewOnly=uiPreview&&!documents.length&&!Object.values(filterQuery).some(Boolean);if(previewOnly)documents=[adviserPreviewRecord()];loadError=false;renderAdvancedOptions();render();pager.render({...data,total:previewOnly?1:data.total??documents.length});if(previewOnly)document.getElementById('documentCount').textContent='UI preview · 1 sample record (not stored)';PrismUI.statusPills(statePills,stateDefinitions,stateFilter,previewOnly?{'Pending Adviser Review':1}:data.counts||{})}
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
    // The shared dialog loads the authoritative record (filename, recorded uploader, version) and reports failures in place.
    const data=await PrismUI.confirmDocumentDelete(d);
    if(!data)return;
    await load();toast(data.message||'Document deleted.')
}
async function reviewStatus(d,status){
    const corrective=['Denied','Resubmission Requested'].includes(status);
    const answer=await PrismUI.confirm({
        title:`${status} document`,icon:status==='Approved'?'fa-check':'fa-clipboard-check',
        tone:status==='Denied'?'danger':'primary',confirmText:status,
        message:corrective?'Explain what the student needs to correct before resubmitting.':status==='Approved'&&currentRole==='adviser'?`Approve “${d.originalName}” version ${d.versionNo} and formally submit it to RPMS?`:'You may add reviewer remarks before saving this decision.',
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
    const options=allowed.map(s=>`<option value="${esc(s)}"${s===d.reviewStatus?' selected':''}>${esc(s==='Resubmission Requested'?'Request Revision':s)}</option>`).join('');
    const answer=await PrismUI.confirm({
        title:'Review document',icon:'fa-clipboard-check',confirmText:'Save review',dialogClass:'document-review-dialog',
        message:`Update the review status for “${d.originalName}”. ${currentRole==='adviser'?'Approve also formally submits this current version to RPMS.':''}`,
        extraHtml:`<label for="prismReviewStatus">Review status</label><select id="prismReviewStatus">${options}</select>`,
        reasonLabel:'Reviewer remarks',reasonRequired:false,
        collect:dlg=>({status:dlg.querySelector('#prismReviewStatus').value}),
        validate:(v,remarks)=>['Denied','Resubmission Requested'].includes(v.status)&&!remarks?'Add remarks explaining what the Student needs to revise.':null
    });
    if(!answer)return;
    try{const data=await api('review',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,status:answer.values.status,remarks:answer.reason})});await load();toast(data.message||'Document review saved.')}
    catch(e){toast(e.message,'error')}
}
async function returnForRevision(d){
    const answer=await PrismUI.confirm({title:'Return to Student for Revision',icon:'fa-rotate-left',tone:'warning',confirmText:'Return for Revision',
        message:`Return “${d.originalName}” (${d.documentType}, version ${d.versionNo}) to ${d.student}? The original submission and approval will be preserved. The Student must upload a new revision for Adviser review.`,
        reasonLabel:'RPMS return reason (required)',reasonRequired:true,minReason:1,maxReason:5000});
    if(!answer)return;
    try{const data=await PrismUI.postJson('documents_api.php?action=return_for_revision',{id:d.id,reason:answer.reason});await load();toast(data.message);document.dispatchEvent(new CustomEvent('prism:documents-changed'));}
    catch(e){toast(e.message,'error');}
}
document.getElementById('uploadDocumentButton').addEventListener('click',async()=>{await loadUploadStudents();openModal(uploadModal)});document.getElementById('documentStudent')?.addEventListener('change',e=>{
    const s=uploadStudents.find(x=>String(x.id)===e.target.value);
    if(s&&s.stage)document.getElementById('documentStage').value=s.stage;
});
document.getElementById('manageDocumentTypes').addEventListener('click',()=>openModal(document.getElementById('documentTypesModal')));document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>closeModal(document.getElementById(b.dataset.close))));[uploadModal,summaryModal,deleteModal,document.getElementById('documentTypesModal')].filter(Boolean).forEach(m=>m.addEventListener('click',e=>{if(e.target===m)closeModal(m)}));search.addEventListener('input',()=>{pager.reset();load()});typeFilter.addEventListener('change',()=>{pager.reset();load()});courseFilter.addEventListener('change',()=>{pager.reset();load()});yearFilter.addEventListener('change',()=>{pager.reset();load()});if(sortSelect)sortSelect.addEventListener('change',()=>{pager.reset();load()});
function setView(next){view=next;tableView.style.display=next==='table'?'block':'none';folderView.classList.toggle('show',next==='folder');courseView.classList.toggle('show',next==='course');document.getElementById('tableViewButton').classList.toggle('active',next==='table');document.getElementById('folderViewButton').classList.toggle('active',next==='folder');document.getElementById('courseViewButton').classList.toggle('active',next==='course')}
document.getElementById('tableViewButton').addEventListener('click',()=>setView('table'));document.getElementById('folderViewButton').addEventListener('click',()=>setView('folder'));document.getElementById('courseViewButton').addEventListener('click',()=>setView('course'));
const theme=document.getElementById('themeToggle');theme.addEventListener('click',()=>{const dark=document.documentElement.classList.toggle('dark-theme');try{localStorage.setItem('prismTheme',dark?'dark':'light')}catch(_){}});document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(summaryModal?.classList.contains('show'))closeModal(summaryModal);closeModal(uploadModal);closeModal(document.getElementById('documentTypesModal'))}});load();
});
