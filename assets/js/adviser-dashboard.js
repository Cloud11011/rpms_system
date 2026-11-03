document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const byId = id => document.getElementById(id);
    const queue = byId('adviserQueue');
    const search = byId('adviserQueueSearch');
    const filter = byId('adviserQueueFilter');
    const notices = byId('adviserNotifications');
    const dialog = byId('adviserReviewDialog');
    const form = byId('adviserReviewForm');
    const reviewStatus = byId('adviserReviewStatus');
    const remarks = byId('adviserReviewRemarks');
    const save = byId('adviserReviewSave');
    const cancel = byId('adviserReviewCancel');
    const reviewError = byId('adviserReviewError');
    const status = byId('adviserStatus');
    // This controller only initializes on the complete Adviser dashboard.
    if (![queue, search, filter, notices, dialog, form, reviewStatus, remarks, save, cancel, reviewError, status].every(Boolean)) return;
    let documents = [];
    let allDocuments = [];
    let approvals = new Set();
    let approvalHistoryAvailable = false;
    let queueState = 'loading';
    let loadSequence = 0;
    let reviewDocument = null;
    let reviewOpener = null;
    let commentOnlyReview = false;
    let saving = false;
    const dirty = PrismUI.dirtyForm(form);
    const count = node('p', 'Loading records...', 'adviser-queue-count'); queue.insertAdjacentElement('beforebegin', count);
    const sort=byId('adviserQueueSort');
    const pager = PrismUI.recordPager(queue, count, [search, filter, sort], renderFilteredQueue);
    queue.parentElement.querySelectorAll('button').forEach(button => {
        if (button.textContent.trim() === 'Clear Filters') button.remove();
    });
    byId('adviserQueueClear').addEventListener('click', () => {
        search.value = '';
        filter.value = 'Pending Adviser Review';
        sort.value = 'newest';
        pager.reset();
        renderFilteredQueue();
    });
    const summaryCards=[...document.querySelectorAll('[data-queue-state]')];
    function syncFilters() {
        summaryCards.forEach(card=>card.setAttribute('aria-pressed',String(card.dataset.queueState===filter.value)));
        byId('adviserQueueClear').hidden = !search.value.trim() && filter.value === 'Pending Adviser Review';
    }
    summaryCards.forEach(card=>card.addEventListener('click',()=>{filter.value=card.dataset.queueState;filter.dispatchEvent(new Event('change'));}));

    // Read the existing authorized pages so dashboard totals never count only the visible page.
    async function readAll(path, key) {
        const rows = [];
        let page = 1, total = null;
        do {
            const params = new URLSearchParams({action:'list',perPage:100,page,sort:'newest'});
            if (key === 'entries') params.set('action_code','document_approved');
            const data = await PrismUI.request(path + '?' + params);
            if (!Array.isArray(data[key]) || !Number.isSafeInteger(Number(data.total))) throw new Error('Record totals are unavailable.');
            total = Number(data.total);
            if (total < 0 || (rows.length < total && !data[key].length)) throw new Error('The record list changed. Refresh to try again.');
            rows.push(...data[key]);
            page++;
        } while (rows.length < total);
        return [...new Map(rows.map(row => [String(row.id),row])).values()];
    }
    const awaitingReview = doc => doc.workflowState === 'Pending Adviser Review' && doc.actions?.review === true;
    const returned = doc => ['Needs Revision','Returned for Revision'].includes(doc.workflowState);
    const approvedByYou = doc => approvals.has(String(doc.id)) && !doc.adminOverride && doc.reviewStatus === 'Approved'
        && ['Ready for Formal RPMS Submission','Submitted to RPMS'].includes(doc.workflowState);
    function renderFilteredQueue() {
        if (queueState !== 'ready') return;
        syncFilters();
        if (filter.value === 'approved' && !approvalHistoryAvailable) {
            pager.error();
            state(queue,'Approval history unavailable','Refresh the dashboard to load your recorded approvals.');
            return;
        }
        const term = search.value.trim().toLocaleLowerCase();
        const matches = allDocuments.filter(doc => {
            const included = filter.value === 'Pending Adviser Review' ? awaitingReview(doc)
                : filter.value === 'returned' ? returned(doc)
                : filter.value === 'approved' ? approvedByYou(doc)
                : !filter.value || doc.workflowState === filter.value;
            return included && (!term || [doc.student,doc.originalName,doc.documentType,doc.stageLabel,doc.stage]
                .some(value => String(value || '').toLocaleLowerCase().includes(term)));
        });
        if (sort.value === 'oldest') matches.reverse();
        if (sort.value === 'name-asc' || sort.value === 'student-asc') {
            const key = sort.value === 'name-asc' ? 'originalName' : 'student';
            matches.sort((a,b) => String(a[key] || '').localeCompare(String(b[key] || '')) || String(a.id).localeCompare(String(b.id)));
        }
        const page = Math.min(pager.page,Math.max(1,Math.ceil(matches.length / pager.pageSize)));
        documents = matches.slice((page-1)*pager.pageSize,page*pager.pageSize);
        pager.render({total:matches.length,page,limit:pager.pageSize});
        count.hidden = matches.length <= 10;
        const footer = queue.parentElement.querySelector('.prism-record-footer');
        if (footer) footer.hidden = matches.length <= 10;
        renderQueue();
    }

    function node(tag, text, className) {
        const item = document.createElement(tag);
        if (text != null) item.textContent = String(text);
        if (className) item.className = className;
        return item;
    }
    function state(container, title, detail) {
        const wrapper = node('div', null, 'adviser-panel-state');
        wrapper.append(node('strong', title), node('span', detail));
        container.replaceChildren(wrapper);
    }
    function dateText(value) {
        if (!value) return 'Not recorded';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString('en-PH');
    }
    function documentIcon(name) {
        const extension=String(name||'').split('.').pop().toLowerCase();
        return extension==='pdf'?'fa-file-pdf':['doc','docx','odt','rtf'].includes(extension)?'fa-file-word':'fa-file-lines';
    }
    function noticeDate(value) {
        if (!value) return 'Date not recorded';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'});
    }
    function field(list, label, value) {
        const entry = node('div');
        entry.append(node('dt', label), node('dd', value == null || value === '' ? 'Not recorded' : value));
        list.append(entry);
    }
    function reviewRequired() {
        remarks.required = commentOnlyReview || ['Denied', 'Resubmission Requested'].includes(reviewStatus.value);
        byId('adviserReviewHelp').textContent = commentOnlyReview
            ? 'Add a comment for the student. The current review status will stay the same.'
            : reviewStatus.value==='Approved'
            ? 'Approve this current version and formally submit it to RPMS. No additional Student submission is needed.'
            : 'Choose a review outcome and add feedback. Request Revision / Resubmission and Deny do not formally submit to RPMS.';
    }
    function setSaving(pending) {
        saving = pending;
        save.disabled = pending;
        form.setAttribute('aria-busy', String(pending));
        save.textContent = pending ? 'Saving review...' : 'Save review';
    }
    function openReview(doc, opener, commentOnly = false) {
        reviewDocument = doc;
        reviewOpener = PrismUI.actionOrigin(opener);
        commentOnlyReview = commentOnly;
        byId('adviserReviewTitle').textContent = 'Review: ' + (doc.originalName || 'Document');
        reviewStatus.value = [...reviewStatus.options].some(option => option.value === doc.reviewStatus) ? doc.reviewStatus : 'Under Review';
        reviewStatus.disabled = commentOnly;
        remarks.value = commentOnly ? '' : (doc.reviewRemarks || '');
        reviewError.textContent = '';
        reviewRequired();
        dirty.clean();
        dialog.showModal();
        reviewStatus.focus();
    }
    async function saveQuickReview(doc, reviewState) {
        const corrective=['Denied','Resubmission Requested'].includes(reviewState);
        const answer=await PrismUI.confirm({
            title:`${reviewState} document`,icon:reviewState==='Approved'?'fa-check':'fa-clipboard-check',
            tone:reviewState==='Denied'?'danger':'primary',confirmText:reviewState,
            message:corrective?'Explain what the student needs to correct before resubmitting.':reviewState==='Approved'?`Approve “${doc.originalName}” version ${doc.versionNo} and formally submit it to RPMS?`:'You may add reviewer remarks before saving this decision.',
            reasonLabel:corrective?'Reviewer remarks (required)':'Reviewer remarks (optional)',reasonRequired:corrective,minReason:1
        });
        if(!answer)return;
        try{const data=await PrismUI.postJson('documents_api.php?action=review',{id:doc.id,status:reviewState,remarks:answer.reason||''});await reload();PrismUI.toast(data.message||`Document marked as ${reviewState}.`,'success');}
        catch(error){PrismUI.toast(error.message,'error');}
    }
    async function deleteDocument(doc) {
        const data=await PrismUI.confirmDocumentDelete(doc);
        if(!data)return;
        await reload();PrismUI.toast(data.message||'Document deleted.','success');
    }
    function renderQueue() {
        if (queueState !== 'ready') return;
        if (!documents.length) {
            state(queue, search.value.trim() ? 'No matching submissions' : 'No documents in this review category',
                search.value.trim() ? 'Try another student name or document filename.' : 'Choose another summary card or open all documents.');
            return;
        }
        queue.replaceChildren();
        documents.forEach(doc => {
            const card = node('article',null,'adviser-document-card prism-record-shell');
            card.dataset.documentId = String(doc.id);
            const main = node('div',null,'adviser-document-main');
            const avatar = node('span',(String(doc.originalName || '').split('.').pop() || 'FILE').toUpperCase().slice(0,4),'adviser-document-avatar');
            avatar.setAttribute('aria-hidden','true');
            const identity = node('div',null,'adviser-document-identity');
            identity.append(node('h3',doc.student || 'Student name not recorded'));
            const filename = node('p',null);
            filename.append(node('span',(doc.originalName || 'Untitled document') + ' · v' + Number(doc.versionNo || 1)),document.createTextNode(' · '),node('time',noticeDate(doc.uploadedAt)));
            identity.append(filename);main.append(avatar,identity);
            const badge = PrismUI.badgeElement(doc.workflowState || 'Status unavailable',{
                small:true,text:awaitingReview(doc) ? 'Needs your review' : doc.workflowState || 'Status unavailable'
            });
            const statusDate = node('div',null,'adviser-document-status');statusDate.append(badge);
            const actions = node('div',null,'adviser-document-actions');
            const fileLink = node('a','View document','adviser-button adviser-document-view');
            fileLink.title='View document';
            fileLink.href = ['application/pdf','image/png','image/jpeg'].includes(doc.mime) ? 'document_preview.php?id=' + encodeURIComponent(doc.id) : 'documents_api.php?action=file&download=1&id=' + encodeURIComponent(doc.id);
            fileLink.target = '_blank';fileLink.rel = 'noopener noreferrer';actions.append(fileLink);
            const download = node('a','Download document','adviser-button adviser-document-download');
            download.title='Download document';
            download.href = 'documents_api.php?action=file&download=1&id=' + encodeURIComponent(doc.id);actions.append(download);
            const top = node('div',null,'adviser-document-top');top.append(main,statusDate,actions);card.append(top);
            const meta = node('dl',null,'adviser-document-meta prism-details-grid');
            field(meta,'Document type',doc.documentType);field(meta,'Document stage',doc.stageLabel || doc.stage);
            field(meta,'Document ID',doc.id);field(meta,'Uploaded by',doc.uploadedBy);
            field(meta,'Submitted',dateText(doc.uploadedAt));field(meta,'Version',Number(doc.versionNo || 1));
            field(meta,'RPMS submission',doc.rpmsSubmittedAt ? dateText(doc.rpmsSubmittedAt) : 'Not submitted to RPMS');
            if(doc.reviewStatus)field(meta,'Review decision',doc.reviewStatus==='Resubmission Requested'?'Revision Requested':doc.reviewStatus);
            const details = node('details',null,'adviser-document-details');details.id='adviser-document-details-'+doc.id;
            const summary = node('summary','Show details and remarks');details.append(summary,meta);
            const feedback=PrismUI.recordFeedback(doc);if(feedback)card.append(feedback);
            if(!feedback || feedback.classList.contains('prism-feedback-indicator'))details.append(node('p',doc.reviewRemarks || 'No reviewer remarks yet.','adviser-document-remarks'));
            PrismUI.recordDetails(details);card.append(details);
            const detailsButton=node('button','Document details','adviser-button');detailsButton.type='button';
            detailsButton.setAttribute('aria-controls',details.id);detailsButton.setAttribute('aria-expanded','false');
            detailsButton.addEventListener('click',()=>{details.open=!details.open;if(details.open)summary.focus();});
            details.addEventListener('toggle',()=>detailsButton.setAttribute('aria-expanded',String(details.open)));actions.append(detailsButton);
            let reviewButton=null;
            if(doc.actions?.review){
                reviewButton=node('button','Review','adviser-button is-primary');reviewButton.type='button';
                reviewButton.addEventListener('click',()=>openReview(doc,reviewButton));actions.append(reviewButton);
                const approve=node('button','Approve','adviser-button');approve.type='button';approve.addEventListener('click',()=>saveQuickReview(doc,'Approved'));actions.append(approve);
                const comment=node('button','Add comment','adviser-button');comment.type='button';comment.addEventListener('click',()=>openReview(doc,comment,true));actions.append(comment);
                const deny=node('button','Deny','adviser-button');deny.type='button';deny.addEventListener('click',()=>saveQuickReview(doc,'Denied'));actions.append(deny);
            }
            const history=node('button','Version history','adviser-button');history.type='button';history.addEventListener('click',()=>PrismUI.showVersions(doc.id));actions.append(history);
            if(doc.actions?.delete){const remove=node('button','Delete','adviser-button prism-action-danger');remove.type='button';remove.addEventListener('click',()=>deleteDocument(doc));actions.append(remove);}
            PrismUI.actionMenu(actions,{primary:reviewButton || fileLink,label:'Document actions'});
            queue.append(card);
        });
    }
    function renderStudents(data) {
        const host=byId('adviserStudents');
        byId('adviserStudentsCount').textContent=String(data.total);
        host.replaceChildren();
        if(!data.records.length){state(host,'No assigned research records','Students and projects assigned to you will appear here.');return;}
        const list=node('ul',null,'adviser-student-items');
        data.records.forEach(record=>{
            const row=node('li',null,'adviser-student-row');
            const link=node('a',null,'adviser-student-link');
            link.href='ierbprog.php?'+new URLSearchParams({q:record.studentId || record.name});
            link.title=record.projectId ? record.research : 'View research progress';
            const avatar=node('span',PrismUI.recordInitials(record.name),'adviser-student-avatar');avatar.setAttribute('aria-hidden','true');
            const copy=node('span',null,'adviser-student-copy');copy.append(node('strong',record.name || 'Name not recorded'));
            copy.append(node('small',record.stageLabel || 'Research setup pending'));
            if(record.projectId)copy.append(node('small',record.research || 'Shared research project','adviser-student-project'));
            const value=record.progress == null ? NaN : Number(record.progress);
            if(Number.isFinite(value)){
                const meter=node('span',null,'adviser-student-progress');
                meter.setAttribute('role','progressbar');meter.setAttribute('aria-label','IERB progress for '+(record.name || 'student'));
                meter.setAttribute('aria-valuemin','0');meter.setAttribute('aria-valuemax','100');meter.setAttribute('aria-valuenow',String(value));
                const fill=node('span');fill.style.width=Math.max(0,Math.min(100,value))+'%';meter.append(fill);copy.append(meter);
            }
            link.append(avatar,copy);
            const meta=node('span',null,'adviser-student-meta');
            const tone=record.status==='Delayed'?'delayed':/pending|correction/i.test(record.status)?'pending':/on track|completed/i.test(record.status)?'on-track':'neutral';
            meta.append(node('span',record.status || 'Not recorded','adviser-student-status is-'+tone));
            if(Number.isFinite(value))meta.append(node('small',value+'%'));
            const docs=node('a','Documents','adviser-student-documents');docs.href='documents.php?'+new URLSearchParams({q:record.name || record.studentId});
            docs.setAttribute('aria-label','Documents for '+(record.name || 'student'));
            row.append(link,meta,docs);list.append(row);
        });
        host.append(list);
    }
    function renderNotices(items, email) {
        const personal = items.filter(item => String(item.recipient_email || '').toLocaleLowerCase() === email.toLocaleLowerCase());
        if (!personal.length) {
            state(notices, 'No recent messages', 'Messages addressed to your account will appear here.');
            return;
        }
        notices.replaceChildren();
        personal.forEach(item => {
            const card = node('article', null, 'adviser-notice');
            if (!item.read_at) card.classList.add('is-unread');
            card.append(node('h3', item.subject || item.type || 'Notification'), node('p', item.message || ''), node('small', noticeDate(item.created_at)));
            notices.append(card);
        });
    }
    async function reload() {
        const sequence=++loadSequence;
        const keepQueueVisible=queueState==='ready';
        queueState='loading';status.textContent='';
        const studentsHost=byId('adviserStudents');
        [queue,notices,studentsHost].forEach(panel=>panel.setAttribute('aria-busy','true'));
        if(!keepQueueVisible){state(queue,'Loading submissions...','Checking current document versions.');state(notices,'Loading notifications...','Checking recent messages for your account.');}
        const results=await Promise.allSettled([
            PrismUI.request('ierb_api.php?action=monitor&layout=cards&perPage=5&sortBy=studentId&direction=ASC'),
            readAll('documents_api.php','documents'),
            PrismUI.request('profile_api.php?action=me'),
            PrismUI.request('notifications_api.php?action=list&preview=5&personal=1'),
            readAll('audit_api.php','entries')
        ]);
        if(sequence!==loadSequence)return;
        const [studentsResult,documentsResult,profileResult,noticesResult,approvalsResult]=results;
        const user=profileResult.status==='fulfilled'&&profileResult.value.user;
        const students=studentsResult.status==='fulfilled'&&Array.isArray(studentsResult.value.records)?studentsResult.value:null;
        if(students)renderStudents(students);
        else{byId('adviserStudentsCount').textContent='Unavailable';state(studentsHost,'Assigned progress unavailable','Open See All to view assigned student records, or refresh to try again.');}
        approvalHistoryAvailable=!!user&&approvalsResult.status==='fulfilled';
        approvals=new Set(approvalHistoryAvailable?approvalsResult.value.filter(entry=>entry.actorRole==='adviser'&&String(entry.actorEmail||'').toLowerCase()===String(user.email).toLowerCase()&&entry.entityType==='document').map(entry=>String(entry.entityId)):[]);
        if(documentsResult.status==='fulfilled'){
            allDocuments=documentsResult.value;queueState='ready';
            byId('adviserPendingCount').textContent=String(allDocuments.filter(awaitingReview).length);
            byId('adviserRevisionCount').textContent=String(allDocuments.filter(returned).length);
            byId('adviserApprovedCount').textContent=approvalHistoryAvailable?String(allDocuments.filter(approvedByYou).length):'Unavailable';
            renderFilteredQueue();
        }else{
            allDocuments=[];documents=[];queueState='error';pager.error();
            ['adviserPendingCount','adviserRevisionCount','adviserApprovedCount'].forEach(id=>byId(id).textContent='Unavailable');
            state(queue,'Could not load submissions','Refresh to try again. No document counts are available.');
        }
        if(user&&typeof user.email==='string'&&noticesResult.status==='fulfilled'&&Array.isArray(noticesResult.value.notifications)){
            renderNotices(noticesResult.value.notifications,user.email);
            byId('adviserUnreadCount').textContent=String(noticesResult.value.unread_count||0)+' unread of '+noticesResult.value.notifications.length+' recent messages';
            document.dispatchEvent(new CustomEvent('prism:notification-count',{detail:{unread:noticesResult.value.unread_count}}));
        }else state(notices,'Could not load personal notifications','Refresh to try again.');
        [queue,notices,studentsHost].forEach(panel=>panel.setAttribute('aria-busy','false'));
        if(!students||queueState==='error'||!approvalHistoryAvailable||noticesResult.status==='rejected')status.textContent='Some dashboard information is unavailable. Refresh to try again.';
    }
    search.addEventListener('input',()=>{pager.reset();renderFilteredQueue();});
    filter.addEventListener('change',()=>{pager.reset();renderFilteredQueue();});
    sort.addEventListener('change',()=>{pager.reset();renderFilteredQueue();});
    byId('adviserMarkAllRead').addEventListener('click',event=>PrismUI.runAction(event.currentTarget,'Saving...',async()=>{try{await PrismUI.postJson('notifications_api.php?action=mark_all_read',{});await reload();}catch(error){status.textContent=error.message;}}));
    reviewStatus.addEventListener('change', reviewRequired);
    cancel.addEventListener('click', () => { if (!saving) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    dialog.addEventListener('close', () => { dirty.clean(); reviewStatus.disabled=false; commentOnlyReview=false; if (reviewOpener && reviewOpener.isConnected) reviewOpener.focus(); reviewDocument = null; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving || !reviewDocument) return;
        reviewError.textContent = '';
        reviewRequired();
        if (!form.reportValidity()) return;
        const text = remarks.value.trim();
        if ((remarks.required && !text) || Array.from(text).length > 5000) {
            reviewError.textContent = 'Add reviewer remarks when requesting changes, using no more than 5,000 characters.';
            remarks.focus();
            return;
        }
        const payload = { id: reviewDocument.id, status: reviewStatus.value, remarks: text };
        setSaving(true);
        try {
            const result=await PrismUI.postJson('documents_api.php?action=review', payload);
            dialog.close();
            await reload();
            search.focus();
            status.textContent = queueState === 'ready' ? result.message || 'Review saved. Current submissions have been refreshed.' : 'Review saved, but current submissions could not be refreshed. Use Refresh to try again.';
        } catch (error) {
            reviewError.textContent = error.message || 'Review could not be saved. Your remarks have been kept; try again or refresh the queue.';
        } finally { setSaving(false); }
    });
    const theme = byId('themeToggle');
    if (theme) {
    theme.setAttribute('aria-pressed', String(document.documentElement.classList.contains('dark-theme')));
    theme.addEventListener('click', () => {
        const dark = document.documentElement.classList.toggle('dark-theme');
        theme.setAttribute('aria-pressed', String(dark));
        try { localStorage.setItem('prismTheme', dark ? 'dark' : 'light'); } catch (_) {}
    });
    }
    reload();
});
