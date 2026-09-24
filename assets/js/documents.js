document.addEventListener('DOMContentLoaded',()=>{
const currentRole=document.body.dataset.role||'admin';const isStaff=currentRole==='admin'||currentRole==='adviser';
if(!isStaff){const studentField=document.getElementById('documentStudent');if(studentField){studentField.closest('div').style.display='none';studentField.required=false}}
let documents=[],view='table';const defaultTypes=['Thesis','IMRAD','IERB Form','Consent','Ethics Application','Revision','Other'],customTypeKey=`prismDocumentTypes:${document.body.dataset.documentUser||'default'}`;let customTypes=[];try{const saved=JSON.parse(localStorage.getItem(customTypeKey));customTypes=Array.isArray(saved)?saved:[]}catch(_){}const body=document.getElementById('documentsTableBody'),search=document.getElementById('documentSearch'),typeFilter=document.getElementById('documentTypeFilter'),courseFilter=document.getElementById('documentCourseFilter'),yearFilter=document.getElementById('documentYearFilter'),sortSelect=document.getElementById('documentSort'),tableView=document.getElementById('documentsTableView'),folderView=document.getElementById('documentsFolderView'),courseView=document.getElementById('documentsCourseView'),uploadModal=document.getElementById('uploadModal'),summaryModal=document.getElementById('summaryModal'),uploadForm=document.getElementById('uploadForm');
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'})[c]);
const size=v=>v<1024?`${v} B`:v<1048576?`${(v/1024).toFixed(1)} KB`:`${(v/1048576).toFixed(1)} MB`;const date=v=>new Date(v).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
async function api(action,options={}){return PrismUI.request(`documents_api.php?action=${encodeURIComponent(action)}`,options)}
function filtered(){const q=search.value.trim().toLowerCase();let rows=documents.filter(d=>(!q||`${d.originalName} ${d.student} ${d.documentType}`.toLowerCase().includes(q))&&(!typeFilter.value||d.documentType===typeFilter.value)&&(!courseFilter.value||d.course===courseFilter.value)&&(!yearFilter.value||String(d.year)===yearFilter.value));const sort=sortSelect?sortSelect.value:'newest';rows=rows.slice().sort((a,b)=>{if(sort==='oldest')return new Date(a.uploadedAt)-new Date(b.uploadedAt);if(sort==='name-asc')return a.originalName.localeCompare(b.originalName);if(sort==='name-desc')return b.originalName.localeCompare(a.originalName);if(sort==='student-asc')return String(a.student).localeCompare(String(b.student));return new Date(b.uploadedAt)-new Date(a.uploadedAt)});return rows}
function fileIcon(name){const ext=name.split('.').pop().toLowerCase();return ext==='pdf'?'fa-file-pdf':['doc','docx','odt','rtf'].includes(ext)?'fa-file-word':'fa-file-lines'}
function allDocumentTypes(){return [...new Set([...defaultTypes,...customTypes,...documents.map(d=>d.documentType)])].filter(Boolean).sort()}function renderTypeManager(){const select=document.getElementById('documentType'),selected=select.value;select.replaceChildren();allDocumentTypes().forEach(t=>{const o=document.createElement('option');o.value=o.textContent=t;select.appendChild(o)});select.value=allDocumentTypes().includes(selected)?selected:allDocumentTypes()[0]||'';const list=document.getElementById('documentTypeList');list.replaceChildren();allDocumentTypes().forEach(t=>{const li=document.createElement('li'),label=document.createElement('span');label.textContent=t;li.appendChild(label);if(defaultTypes.includes(t)){const fixed=document.createElement('span');fixed.className='default-type';fixed.textContent='Default';li.appendChild(fixed)}else{const remove=document.createElement('button');remove.type='button';remove.title='Remove type';remove.innerHTML='<i class="fa-solid fa-trash"></i>';remove.addEventListener('click',()=>{customTypes=customTypes.filter(x=>x!==t);localStorage.setItem(customTypeKey,JSON.stringify(customTypes));renderTypeManager();renderFilters()});li.appendChild(remove)}list.appendChild(li)})}function renderFilters(){const selected=typeFilter.value;typeFilter.querySelectorAll('option:not(:first-child)').forEach(o=>o.remove());allDocumentTypes().forEach(t=>{const o=document.createElement('option');o.value=o.textContent=t;typeFilter.appendChild(o)});typeFilter.value=[...typeFilter.options].some(o=>o.value===selected)?selected:'';const courseSelected=courseFilter.value;courseFilter.querySelectorAll('option:not(:first-child)').forEach(o=>o.remove());[...new Set(documents.map(d=>d.course).filter(Boolean))].sort().forEach(c=>{const o=document.createElement('option');o.value=o.textContent=c;courseFilter.appendChild(o)});courseFilter.value=[...courseFilter.options].some(o=>o.value===courseSelected)?courseSelected:'';const yearSelected=yearFilter.value;yearFilter.querySelectorAll('option:not(:first-child)').forEach(o=>o.remove());[...new Set(documents.map(d=>d.year).filter(Boolean))].sort((a,b)=>b-a).forEach(y=>{const o=document.createElement('option');o.value=o.textContent=y;yearFilter.appendChild(o)});yearFilter.value=[...yearFilter.options].some(o=>o.value===yearSelected)?yearSelected:''}
function renderTable(){
    const rows=filtered();
    document.getElementById('documentCount').textContent=`${rows.length} ${rows.length===1?'document':'documents'}`;
    body.replaceChildren();
    if(!rows.length){
        const tr=document.createElement('tr');
        tr.innerHTML=`<td colspan="6">${PrismUI.emptyState({icon:'fa-folder-open',title:documents.length?'No matching documents':'No documents yet',text:documents.length?'Try a different search or filter.':'Uploads will appear here once a document is submitted.'})}</td>`;
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
            <td><div class="doc-category"><span>${esc(d.documentType)} · ${esc(d.stageLabel||d.stage)}</span><span>${PrismUI.badge(d.workflowState||d.reviewStatus,{small:true})}${d.adminOverride?' '+PrismUI.badge('Admin Override',{small:true}):''}</span>${d.reviewRemarks?`<small>${esc(d.reviewRemarks)}</small>`:''}</div></td>
            <td><div class="document-row-actions">
                <a class="document-action-button" href="documents_api.php?action=file&id=${encodeURIComponent(d.id)}" target="_blank" rel="noopener" title="View"><i class="fa-solid fa-eye"></i></a>
                <a class="document-action-button" href="documents_api.php?action=file&download=1&id=${encodeURIComponent(d.id)}" title="Download"><i class="fa-solid fa-download"></i></a>
                <button class="document-action-button" data-versions title="Version history"><i class="fa-solid fa-clock-rotate-left"></i></button>
                ${a.review?'<button class="document-action-button approve" data-approve title="Approve"><i class="fa-solid fa-check"></i></button><button class="document-action-button deny" data-deny title="Deny"><i class="fa-solid fa-xmark"></i></button><button class="document-action-button" data-comment title="Add comment"><i class="fa-regular fa-comment"></i></button><button class="document-action-button" data-review title="Full review / set status"><i class="fa-solid fa-clipboard-check"></i></button>':''}
                ${a.override?'<button class="document-action-button" data-override title="Admin Override"><i class="fa-solid fa-user-shield"></i></button>':''}
                <button class="document-action-button" data-summary title="AI summarize"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
                ${a.delete?'<button class="document-action-button delete" data-delete title="Delete"><i class="fa-solid fa-trash"></i></button>':''}
            </div></td>`;
        tr.querySelector('[data-versions]').addEventListener('click',()=>PrismUI.showVersions(d.id));
        if(a.review){
            tr.querySelector('[data-approve]').addEventListener('click',()=>reviewStatus(d,'Approved'));
            tr.querySelector('[data-deny]').addEventListener('click',()=>reviewStatus(d,'Denied'));
            tr.querySelector('[data-comment]').addEventListener('click',()=>comment(d));
            tr.querySelector('[data-review]').addEventListener('click',()=>review(d));
        }
        if(a.override) tr.querySelector('[data-override]').addEventListener('click',async()=>{if(await PrismUI.overrideDocument(d)) await load()});
        if(a.delete) tr.querySelector('[data-delete]').addEventListener('click',()=>remove(d));
        tr.querySelector('[data-summary]').addEventListener('click',()=>summarize(d));
        body.appendChild(tr);
    });
}
function renderFolders(){folderView.replaceChildren();const groups=new Map();filtered().forEach(d=>{if(!groups.has(d.student))groups.set(d.student,[]);groups.get(d.student).push(d)});groups.forEach((files,name)=>{const folder=document.createElement('div');folder.className='document-folder';folder.innerHTML=`<div class="folder-heading"><i class="fa-solid fa-folder"></i><strong>${esc(name)}</strong><span>${files.length}</span></div><div class="folder-files">${files.map(f=>`<a class="folder-file" href="documents_api.php?action=file&id=${encodeURIComponent(f.id)}" target="_blank"><i class="fa-regular ${fileIcon(f.originalName)}"></i>${esc(f.originalName)}</a>`).join('')}</div>`;folderView.appendChild(folder)})}
function renderCourseView(){courseView.replaceChildren();const groups=new Map();filtered().forEach(d=>{const key=d.course||'Unassigned / No Course';if(!groups.has(key))groups.set(key,[]);groups.get(key).push(d)});[...groups.keys()].sort().forEach(course=>{const files=groups.get(course);const folder=document.createElement('div');folder.className='document-folder';folder.innerHTML=`<div class="folder-heading"><i class="fa-solid fa-layer-group"></i><strong>${esc(course)}</strong><span>${files.length}</span></div><div class="folder-files">${files.map(f=>`<a class="folder-file" href="documents_api.php?action=file&id=${encodeURIComponent(f.id)}" target="_blank"><i class="fa-regular ${fileIcon(f.originalName)}"></i>${esc(f.originalName)} <small>(${esc(f.student)}, ${esc(f.year||'')})</small></a>`).join('')}</div>`;courseView.appendChild(folder)})}
function render(){renderFilters();renderTypeManager();renderTable();renderFolders();renderCourseView()}
function openModal(m){m.classList.add('show');m.setAttribute('aria-hidden','false')}function closeModal(m){m.classList.remove('show');m.setAttribute('aria-hidden','true')}
function toast(message,type='success'){PrismUI.toast(message,type)}
async function load(){try{documents=(await api('list')).documents;render()}catch(e){documents=[];render();toast(e.message,'error')}}
uploadForm.addEventListener('submit',async e=>{
    e.preventDefault();
    const file=document.getElementById('documentFile').files[0];
    if(!file){toast('Choose a document to upload.','error');return}
    if(file.size>20*1024*1024){toast('Files must be 20 MB or smaller.','error');return}
    const ext=(file.name.split('.').pop()||'').toLowerCase();
    const allowed=['pdf','doc','docx','txt','rtf','odt','png','jpg','jpeg'];
    if(!allowed.includes(ext)){toast('Use PDF, Word, text, RTF, ODT, PNG or JPG.','error');return}
    const button=uploadForm.querySelector('[type="submit"]');
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
async function summarize(d){document.getElementById('summaryTitle').textContent=d.originalName;const result=document.getElementById('summaryResult');result.innerHTML='<div class="summary-loading"><i class="fa-solid fa-spinner fa-spin"></i>Analyzing document content...</div>';openModal(summaryModal);try{const data=await api('summarize',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:d.id})});result.textContent=data.summary;const meta=document.createElement('span');meta.className='summary-meta';meta.textContent=`Extracted content: ${data.wordCount} words`;result.appendChild(meta)}catch(e){result.innerHTML=`<span class="document-error">${esc(e.message)}</span>`;toast(e.message,'error')}}
document.getElementById('uploadDocumentButton').addEventListener('click',()=>openModal(uploadModal));document.getElementById('manageDocumentTypes').addEventListener('click',()=>openModal(document.getElementById('documentTypesModal')));document.getElementById('documentTypeForm').addEventListener('submit',e=>{e.preventDefault();const input=document.getElementById('newDocumentType'),value=input.value.trim();if(!value)return;if(allDocumentTypes().some(t=>t.toLowerCase()===value.toLowerCase())){toast('That document type already exists.','error');return}customTypes.push(value);localStorage.setItem(customTypeKey,JSON.stringify(customTypes));input.value='';renderTypeManager();renderFilters()});document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>closeModal(document.getElementById(b.dataset.close))));[uploadModal,summaryModal,document.getElementById('documentTypesModal')].forEach(m=>m.addEventListener('click',e=>{if(e.target===m)closeModal(m)}));search.addEventListener('input',render);typeFilter.addEventListener('change',render);courseFilter.addEventListener('change',render);yearFilter.addEventListener('change',render);if(sortSelect)sortSelect.addEventListener('change',render);
function setView(next){view=next;tableView.style.display=next==='table'?'block':'none';folderView.classList.toggle('show',next==='folder');courseView.classList.toggle('show',next==='course');document.getElementById('tableViewButton').classList.toggle('active',next==='table');document.getElementById('folderViewButton').classList.toggle('active',next==='folder');document.getElementById('courseViewButton').classList.toggle('active',next==='course')}
document.getElementById('tableViewButton').addEventListener('click',()=>setView('table'));document.getElementById('folderViewButton').addEventListener('click',()=>setView('folder'));document.getElementById('courseViewButton').addEventListener('click',()=>setView('course'));
const theme=document.getElementById('themeToggle');theme.addEventListener('click',()=>{const dark=document.documentElement.classList.toggle('dark-theme');try{localStorage.setItem('prismTheme',dark?'dark':'light')}catch(_){}});const profile=document.getElementById('profileToggle'),menu=document.getElementById('profileMenu');profile.addEventListener('click',e=>{e.stopPropagation();menu.classList.toggle('show')});document.addEventListener('click',()=>menu.classList.remove('show'));document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal(uploadModal);closeModal(summaryModal);closeModal(document.getElementById('documentTypesModal'))}});load();
});
