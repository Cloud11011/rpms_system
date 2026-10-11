document.addEventListener('DOMContentLoaded',()=>{
const currentRole=document.body.dataset.role||'admin';const isStaff=currentRole==='admin'||currentRole==='adviser';
if(!isStaff){const studentField=document.getElementById('documentStudent');if(studentField){studentField.closest('div').style.display='none';studentField.required=false}}
let documents=[],listData={},loadSequence=0,loadError=false,view='table';const defaultTypes=PrismUI.documentTypes;const body=document.getElementById('documentsTableBody'),search=document.getElementById('documentSearch'),typeFilter=document.getElementById('documentTypeFilter'),courseFilter=document.getElementById('documentCourseFilter'),yearFilter=document.getElementById('documentYearFilter'),sortSelect=document.getElementById('documentSort'),tableView=document.getElementById('documentsTableView'),folderView=document.getElementById('documentsFolderView'),courseView=document.getElementById('documentsCourseView'),uploadModal=document.getElementById('uploadModal'),uploadForm=document.getElementById('uploadForm');
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'})[c]);
const size=v=>v<1024?`${v} B`:v<1048576?`${(v/1024).toFixed(1)} KB`:`${(v/1048576).toFixed(1)} MB`;const date=v=>new Date(v).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
async function api(action,options={}){return PrismUI.request(`documents_api.php?action=${encodeURIComponent(action)}`,options)}
const summaryModal=document.getElementById('summaryModal'),summaryResult=document.getElementById('summaryResult'),summarySource=document.getElementById('summarySource'),regenerateSummary=document.getElementById('regenerateSummary');
let summaryDocument=null,summarySequence=0,summaryReturnFocus=null,summaryBusy=false;
const summarySources=new Map();
function summaryButton(d){return currentRole==='admin'&&d.actions?.summarize&&summaryModal?`<button type="button" class="document-action-button summary-action" data-summary="${esc(d.id)}"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>${d.aiSummary?'View AI Summary':'Generate AI Summary'}</button>`:''}
function bindSummaryButtons(host){host.querySelectorAll('[data-summary]').forEach(button=>button.addEventListener('click',()=>showSummary(documents.find(d=>String(d.id)===button.dataset.summary),button)))}
function showSummary(d,opener){
    if(!d||currentRole!=='admin'||!summaryModal||summaryBusy)return;
    summaryDocument=d;summaryReturnFocus=PrismUI.actionOrigin(opener||document.activeElement);
    document.getElementById('summaryFilename').textContent=d.originalName;
    summaryResult.textContent=d.aiSummary||'';
    summaryResult.classList.remove('document-error');
    const recordedSource=summarySources.get(d.id);
    summarySource.textContent=d.aiSummary?(recordedSource?.summary===d.aiSummary?recordedSource.label:'Source not recorded'):'';
    openModal(summaryModal);summaryModal.querySelector('[data-close]').focus();
    if(!d.aiSummary)generateSummary();
}
async function generateSummary(){
    if(!summaryDocument||summaryBusy)return;
    const d=summaryDocument,sequence=++summarySequence;summaryBusy=true;regenerateSummary.disabled=true;
    summaryResult.textContent='Extracting document text and preparing the summary…';summaryResult.setAttribute('aria-busy','true');summaryResult.classList.remove('document-error');summarySource.textContent='';
    try{
        const data=await api('summarize',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id})});
        if(sequence!==summarySequence)return;
        d.aiSummary=data.summary;summaryResult.textContent=data.summary;
        const source=data.source==='ai'?'AI generated':data.source==='local_fallback'?'Local extractive fallback — AI unavailable or response rejected':'Source not recorded';
        summarySource.textContent=source+(data.partial?' · Based on a bounded excerpt; some document content was omitted.':'');summarySources.set(d.id,{summary:data.summary,label:summarySource.textContent});
        renderTable();renderFolders();renderCourseView();
    }catch(error){if(sequence===summarySequence){summaryResult.textContent=error.message;summaryResult.classList.add('document-error');summarySource.textContent='Summary was not generated. Any existing saved summary is retained.'}}
    finally{summaryBusy=false;regenerateSummary.disabled=false;summaryResult.setAttribute('aria-busy','false')}
}
regenerateSummary?.addEventListener('click',generateSummary);
summaryModal?.addEventListener('keydown',event=>{
    if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closeModal(summaryModal);return}
    if(event.key!=='Tab')return;
    const buttons=[...summaryModal.querySelectorAll('button:not(:disabled)')],first=buttons[0],last=buttons.at(-1);
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
                <a class="document-action-button" href="documents_api.php?action=file&id=${encodeURIComponent(d.id)}" target="_blank" rel="noopener" title="View"><i class="fa-solid fa-eye"></i></a>
                <a class="document-action-button" href="documents_api.php?action=file&download=1&id=${encodeURIComponent(d.id)}" title="Download"><i class="fa-solid fa-download"></i></a>
                ${a.review?'<button class="document-action-button" data-review title="Full review / set status"><i class="fa-solid fa-clipboard-check"></i> Review Document</button>':''}
                <button class="document-action-button" data-versions title="Version history"><i class="fa-solid fa-clock-rotate-left"></i></button>
                ${summaryButton(d)}
                ${a.review?'<button class="document-action-button approve" data-approve title="Approve"><i class="fa-solid fa-check"></i></button><button class="document-action-button" data-comment title="Add comment"><i class="fa-regular fa-comment"></i></button><button class="document-action-button deny" data-deny title="Deny"><i class="fa-solid fa-xmark"></i></button>':''}
                ${a.override?'<button class="document-action-button" data-override title="Admin Override"><i class="fa-solid fa-user-shield"></i></button>':''}

                ${a.delete?'<button class="document-action-button delete" data-delete title="Delete"><i class="fa-solid fa-trash"></i></button>':''}
            </div></td>`;
        tr.querySelector('[data-versions]').addEventListener('click',()=>PrismUI.showVersions(d.id));
        if(a.review){
            tr.querySelector('[data-approve]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>reviewStatus(d,'Approved')));
            tr.querySelector('[data-deny]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>reviewStatus(d,'Denied')));
            tr.querySelector('[data-comment]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>comment(d)));
            tr.querySelector('[data-review]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>review(d)));
        }
        if(a.override) tr.querySelector('[data-override]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',async()=>{if(await PrismUI.overrideDocument(d)) await load()}));
        if(a.delete) tr.querySelector('[data-delete]').addEventListener('click',e=>PrismUI.runAction(e.currentTarget,'Processing...',()=>remove(d)));

        PrismUI.actionMenu(tr.querySelector('.document-row-actions'));
        body.appendChild(tr);
    });
    bindSummaryButtons(body);
}
function renderFolders(){folderView.replaceChildren();const groups=new Map();filtered().forEach(d=>{if(!groups.has(d.student))groups.set(d.student,[]);groups.get(d.student).push(d)});groups.forEach((files,name)=>{const folder=document.createElement('div');folder.className='document-folder';folder.innerHTML=`<div class="folder-heading"><i class="fa-solid fa-folder"></i><strong>${esc(name)}</strong><span>${files.length}</span></div><div class="folder-files">${files.map(f=>`<a class="folder-file" href="documents_api.php?action=file&id=${encodeURIComponent(f.id)}" target="_blank"><i class="fa-regular ${fileIcon(f.originalName)}"></i>${esc(f.originalName)}</a>${summaryButton(f)}`).join('')}</div>`;folderView.appendChild(folder)});bindSummaryButtons(folderView)}
function renderCourseView(){courseView.replaceChildren();const groups=new Map();filtered().forEach(d=>{const key=d.course||'Unassigned / No Course';if(!groups.has(key))groups.set(key,[]);groups.get(key).push(d)});[...groups.keys()].sort().forEach(course=>{const files=groups.get(course);const folder=document.createElement('div');folder.className='document-folder';folder.innerHTML=`<div class="folder-heading"><i class="fa-solid fa-layer-group"></i><strong>${esc(course)}</strong><span>${files.length}</span></div><div class="folder-files">${files.map(f=>`<a class="folder-file" href="documents_api.php?action=file&id=${encodeURIComponent(f.id)}" target="_blank"><i class="fa-regular ${fileIcon(f.originalName)}"></i>${esc(f.originalName)} <small>(${esc(f.student)}, ${esc(f.year||'')})</small></a>${summaryButton(f)}`).join('')}</div>`;courseView.appendChild(folder)});bindSummaryButtons(courseView)}
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
const pager=PrismUI.recordPager(document.getElementById('documentsCourseView'),document.getElementById('documentCount'),[search,typeFilter,courseFilter,yearFilter,sortSelect].filter(Boolean),load,
    {host:filterPanel,toolbar:document.querySelector('.document-actions')});
async function load(){
    const sequence=++loadSequence;pager.loading();body.setAttribute('aria-busy','true');
    const qs=new URLSearchParams({action:'list',page:pager.page,q:search.value.trim(),type:typeFilter.value,course:courseFilter.value,year:yearFilter.value,sort:sortSelect?.value||'newest'});
    try{const data=await PrismUI.request('documents_api.php?'+qs);if(sequence!==loadSequence)return;listData=data;documents=data.documents;loadError=false;render();pager.render({...data,total:data.total??documents.length})}
    catch(e){if(sequence!==loadSequence)return;documents=[];loadError=true;render();pager.error();toast(e.message,'error')}
    finally{if(sequence===loadSequence)body.setAttribute('aria-busy','false')}
}
uploadForm.addEventListener('submit',async e=>{
    e.preventDefault();
    const file=document.getElementById('documentFile').files[0];
    if(!file){toast('Choose a document to upload.','error');return}
    if(file.size>20*1024*1024){toast('Files must be 20 MB or smaller.','error');return}
    const ext=(file.name.split('.').pop()||'').toLowerCase();
    const allowed=['pdf','doc','docx','txt','rtf','odt','png','jpg','jpeg'];
    if(!allowed.includes(ext)){toast('Use PDF, Word, text, RTF, ODT, PNG or JPG.','error');return}
    const button=uploadForm.querySelector('[type="submit"]');
    if(button.disabled)return;
    const original=button.innerHTML;
    button.disabled=true;
    button.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';
    try{
        const data=await api('upload',{method:'POST',body:new FormData(uploadForm)});
        closeModal(uploadModal);uploadForm.reset();await load();toast(data.message||'Document uploaded successfully.')
    }catch(err){toast(err.message,'error')}
    finally{button.disabled=false;button.innerHTML=original}
});
async function remove(d){
    const needsReason=!!d.locked;
    const answer=await PrismUI.confirm({
        title:'Delete document',icon:'fa-trash',tone:'danger',confirmText:'Delete',
        message:`Delete “${d.originalName}”? ${needsReason?'This formally submitted document is locked, so the reason will be recorded in the audit trail.':'This cannot be undone.'}`,
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
        message:corrective?'Explain what the student needs to correct before resubmitting.':'You may add reviewer remarks before saving this decision.',
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
    try{const data=await api('review',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,status:d.reviewStatus||'Under Review',remarks:answer.reason})});await load();toast(data.message||'Comment saved and sent to the student.')}
    catch(e){toast(e.message,'error')}
}
async function review(d){
    const allowed=['Under Review','Received','Verified','Resubmission Requested','Approved','Denied'];
    const options=allowed.map(s=>`<option${s===d.reviewStatus?' selected':''}>${esc(s)}</option>`).join('');
    const answer=await PrismUI.confirm({
        title:'Review document',icon:'fa-clipboard-check',confirmText:'Save review',
        message:`Update the review status for “${d.originalName}”.`,
        extraHtml:`<label for="prismReviewStatus">Review status</label><select id="prismReviewStatus">${options}</select>`,
        reasonLabel:'Reviewer remarks',reasonRequired:false,
        collect:dlg=>({status:dlg.querySelector('#prismReviewStatus').value}),
        validate:(v,remarks)=>['Denied','Resubmission Requested'].includes(v.status)&&!remarks?'Add remarks explaining what the student needs to correct or resubmit.':null
    });
    if(!answer)return;
    try{const data=await api('review',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id,status:answer.values.status,remarks:answer.reason})});await load();toast(data.message||'Document review saved.')}
    catch(e){toast(e.message,'error')}
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
