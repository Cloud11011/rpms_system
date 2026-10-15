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
    const uiPreview = (document.body.dataset.prismAllowDemo==='1') && new URLSearchParams(window.location.search).get('ui-preview') === '1';
    // This controller only initializes on the complete Adviser dashboard.
    if (![queue, search, filter, notices, dialog, form, reviewStatus, remarks, save, cancel, reviewError, status].every(Boolean)) return;
    let documents = [];
    let queueState = 'loading';
    let loadSequence = 0;
    let reviewDocument = null;
    let reviewOpener = null;
    let commentOnlyReview = false;
    let saving = false;
    const dirty = PrismUI.dirtyForm(form);
    const count = node('p', 'Loading records...', 'adviser-queue-count'); queue.insertAdjacentElement('beforebegin', count);
    const sort=byId('adviserQueueSort');
    const pager = PrismUI.recordPager(queue, count, [search, filter, sort], reload);
    queue.parentElement.querySelectorAll('button').forEach(button => {
        if (button.textContent.trim() === 'Clear Filters') button.remove();
    });
    byId('adviserQueueClear').addEventListener('click', () => {
        search.value = '';
        filter.value = '';
        sort.value = 'newest';
        pager.reset();
        reload();
    });
    const summaryCards=[...document.querySelectorAll('[data-queue-state]')];
    function syncFilters(counts) {
        summaryCards.forEach(card=>card.setAttribute('aria-pressed',String(card.dataset.queueState===filter.value)));
        PrismUI.statusPills(byId('adviserQueuePills'),[['','All'],['Pending Adviser Review','Pending review'],['Needs Revision','Needs revision'],['approved','Submitted to RPMS',['Ready for Formal RPMS Submission','Submitted to RPMS']]],filter,counts);
    }
    summaryCards.forEach(card=>card.addEventListener('click',()=>{filter.value=card.dataset.queueState;filter.dispatchEvent(new Event('change'));}));

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
        const answer=await PrismUI.confirm({title:'Delete document',icon:'fa-trash',tone:'danger',confirmText:'Delete',message:`Delete “${doc.originalName}”? This cannot be undone.`,reasonLabel:'',reasonRequired:false});
        if(!answer)return;
        try{const data=await PrismUI.postJson('documents_api.php?action=delete',{id:doc.id,reason:''});await reload();PrismUI.toast(data.message||'Document deleted.','success');}
        catch(error){PrismUI.toast(error.message,'error');}
    }
    function renderQueue() {
        if (queueState !== 'ready') return;
        const previewOnly = uiPreview && documents.length === 0 && !search.value.trim() && !filter.value;
        const selected = previewOnly ? [{
            id:'ui-preview-sample', preview:true, student:'Sample Student', originalName:'Research Proposal.pdf',
            documentType:'Research Proposal', stageLabel:'Stage 1', workflowState:'Pending Adviser Review',
            uploadedAt:new Date().toISOString(), versionNo:1, reviewStatus:'Under Review', reviewRemarks:'', actions:{review:false}
        }] : documents;
        if (previewOnly) count.textContent = 'UI preview: 1 sample result (not a real submission)';
        if (!selected.length) {
            const filtered = !!(search.value.trim() || filter.value);
            state(queue, filtered ? 'No matching submissions' : 'No documents currently waiting for review.', filtered ? 'Adjust your search or workflow status filter.' : 'Documents from your assigned students will appear here when they upload them.');
            return;
        }
        queue.replaceChildren();
        queue.replaceChildren();
        selected.forEach(doc => {
            const card = node('article', null, 'adviser-document-card');
            const main = node('div', null, 'adviser-document-main');
            const studentName = doc.student || 'Not recorded';
            const fileAvatar=node('span',null,'adviser-document-avatar');
            const fileMark=node('i',null,'fa-regular '+documentIcon(doc.originalName));fileMark.setAttribute('aria-hidden','true');fileAvatar.append(fileMark);
            main.append(fileAvatar);
            const identity = node('div', null, 'adviser-document-identity');
            identity.append(node('h3', doc.originalName || 'Untitled document'));
            identity.append(node('p', `${studentName} · ${doc.documentType || 'Document'} · ${doc.stageLabel || doc.stage || 'Stage not recorded'}`));
            if (doc.preview) identity.append(node('small', 'UI preview · sample data only', 'adviser-document-preview-note'));
            main.append(identity);
            const badge = PrismUI.badgeElement(doc.workflowState || 'Status unavailable');
            const statusDate = node('div', null, 'adviser-document-status');
            statusDate.append(badge, node('time', dateText(doc.uploadedAt)));
            const actions = node('div', null, 'adviser-document-actions');
            const fileLink = node(doc.preview ? 'span' : 'a', null, 'adviser-button adviser-document-view');
            const viewIcon=node('i',null,'fa-regular fa-eye');viewIcon.setAttribute('aria-hidden','true');
            fileLink.append(viewIcon);
            fileLink.title=doc.preview?'Sample document preview unavailable':'View document';
            if (!doc.preview) {
                fileLink.href = ['application/pdf','image/png','image/jpeg'].includes(doc.mime) ? 'document_preview.php?id=' + encodeURIComponent(String(doc.id)) : 'documents_api.php?action=file&download=1&id=' + encodeURIComponent(String(doc.id));
                fileLink.target = '_blank';
                fileLink.rel = 'noopener noreferrer';
                fileLink.setAttribute('aria-label','View '+(doc.originalName||'document'));
                const download=node('a',null,'adviser-button adviser-document-download');
                download.href='documents_api.php?action=file&download=1&id='+encodeURIComponent(String(doc.id));
                download.setAttribute('aria-label','Download '+(doc.originalName||'document'));
                download.title='Download document';
                const downloadIcon=node('i',null,'fa-solid fa-download');downloadIcon.setAttribute('aria-hidden','true');download.append(downloadIcon);
                actions.append(download);
            } else {
                fileLink.setAttribute('aria-label','Sample document preview unavailable');
                const download=node('button',null,'adviser-button adviser-document-download');
                download.type='button';download.disabled=true;
                download.setAttribute('aria-label','Download unavailable for sample document');
                download.title='Download unavailable for sample document';
                const downloadIcon=node('i',null,'fa-solid fa-download');downloadIcon.setAttribute('aria-hidden','true');download.append(downloadIcon);
                actions.append(download);
            }
            actions.append(fileLink);
            const top = node('div', null, 'adviser-document-top');
            top.append(main, statusDate, actions);
            card.append(top);
            const meta = node('dl', null, 'adviser-document-meta');
            field(meta, 'Student', studentName);
            field(meta, 'Submission date', dateText(doc.uploadedAt));
            field(meta, 'Document type', doc.documentType);
            field(meta, 'Stage', doc.stageLabel || doc.stage);
            field(meta, 'Version', doc.versionNo);
            field(meta, 'Review status', doc.workflowState);
            field(meta, 'RPMS submission', doc.rpmsSubmittedAt ? 'Submitted to RPMS · '+dateText(doc.rpmsSubmittedAt) : 'Not submitted to RPMS');
            field(meta, 'Adviser review decision', doc.reviewStatus || 'Pending review');
            const details = node('details', null, 'adviser-document-details');
            const summary = node('summary', null);
            summary.append(node('span','Show details and remarks'));
            const chevron=node('i',null,'fa-solid fa-chevron-down');chevron.setAttribute('aria-hidden','true');summary.append(chevron);
            details.append(summary, meta);
            const remark=node('p',null,'adviser-document-remarks');
            remark.append(node('strong','Reviewer remarks · '),document.createTextNode(doc.reviewRemarks||'No reviewer remarks yet.'));
            details.append(remark);
            card.append(details);
            const downloadLink=actions.querySelector('.adviser-document-download');
            downloadLink?.remove();
            if(!doc.preview){
                if(doc.actions?.review){
                    const review=node('button','Review Document','adviser-button');review.type='button';const reviewIcon=node('i',null,'fa-solid fa-file-pen');reviewIcon.setAttribute('aria-hidden','true');review.prepend(reviewIcon);review.addEventListener('click',()=>openReview(doc,review));actions.append(review);
                }
                const history=node('button','Version history','adviser-button');history.type='button';const historyIcon=node('i',null,'fa-solid fa-clock-rotate-left');historyIcon.setAttribute('aria-hidden','true');history.prepend(historyIcon);history.addEventListener('click',()=>PrismUI.showVersions(doc.id));actions.append(history);
                if(doc.actions?.review){
                    const approve=node('button','Approve','adviser-button');approve.type='button';const approveIcon=node('i',null,'fa-solid fa-check');approveIcon.setAttribute('aria-hidden','true');approve.prepend(approveIcon);approve.addEventListener('click',()=>saveQuickReview(doc,'Approved'));actions.append(approve);
                    const comment=node('button','Add comment','adviser-button');comment.type='button';const commentIcon=node('i',null,'fa-regular fa-comment');commentIcon.setAttribute('aria-hidden','true');comment.prepend(commentIcon);comment.addEventListener('click',()=>openReview(doc,comment,true));actions.append(comment);
                    const deny=node('button','Deny','adviser-button');deny.type='button';const denyIcon=node('i',null,'fa-solid fa-xmark');denyIcon.setAttribute('aria-hidden','true');deny.prepend(denyIcon);deny.addEventListener('click',()=>saveQuickReview(doc,'Denied'));actions.append(deny);
                }
                if(doc.actions?.delete){const remove=node('button','Delete','adviser-button');remove.type='button';remove.classList.add('prism-action-danger');const icon=node('i',null,'fa-solid fa-trash');icon.setAttribute('aria-hidden','true');remove.prepend(icon);remove.addEventListener('click',()=>deleteDocument(doc));actions.append(remove);}
            } else if(downloadLink) {
                actions.insertBefore(downloadLink,actions.querySelector('.document-preview-menu'));
            }
            if(!doc.preview) PrismUI.actionMenu(actions,{primary:fileLink,label:'Document actions'});
            if(downloadLink && !doc.preview) actions.insertBefore(downloadLink,actions.querySelector('.prism-action-menu'));
            queue.append(card);
        });
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
        const sequence = ++loadSequence;
        const keepQueueVisible = queueState === 'ready';
        queueState = 'loading';
        status.textContent = '';
        [queue, notices].forEach(panel => panel.setAttribute('aria-busy', 'true'));
        if (!keepQueueVisible) {
            state(queue, 'Loading submissions...', 'Checking current document versions.');
            state(notices, 'Loading notifications...', 'Checking recent messages for your account.');
        }
        const results = await Promise.allSettled([
            PrismUI.request('students_api.php?action=list&preview=1'),
            PrismUI.request('documents_api.php?' + new URLSearchParams({action:'list',page:pager.page,q:search.value.trim(),state:filter.value==='approved'?'':filter.value,stateGroup:filter.value==='approved'?'approved':'',sort:sort.value})),
            PrismUI.request('profile_api.php?action=me'),
            PrismUI.request('notifications_api.php?action=list&preview=5&personal=1')
        ]);
        if (sequence !== loadSequence) return;
        const [studentsResult, documentsResult, profileResult, noticesResult] = results;
        const students = studentsResult.status === 'fulfilled' && Array.isArray(studentsResult.value.students) ? studentsResult.value.students : null;
        byId('adviserStudentsCount').textContent = students ? String(studentsResult.value.total ?? students.length) : 'Unavailable';
        if (documentsResult.status === 'fulfilled' && Array.isArray(documentsResult.value.documents)) {
            documents = documentsResult.value.documents;
            queueState = 'ready';
            const data = documentsResult.value;
            pager.render({...data,total:data.total??documents.length});
            const counts = data.counts || {};
            syncFilters(counts);
            byId('adviserPendingCount').textContent = String(counts['Pending Adviser Review'] ?? documents.filter(doc => doc.workflowState === 'Pending Adviser Review').length);
            byId('adviserRevisionCount').textContent = String(counts['Needs Revision'] ?? documents.filter(doc => doc.workflowState === 'Needs Revision').length);
            byId('adviserApprovedCount').textContent = String(data.counts ? (Number(counts['Ready for Formal RPMS Submission']||0)+Number(counts['Submitted to RPMS']||0)) : documents.filter(doc => ['Ready for Formal RPMS Submission', 'Submitted to RPMS'].includes(doc.workflowState)).length);
            renderQueue();
        } else {
            queueState = 'error'; pager.error();
            ['adviserPendingCount', 'adviserRevisionCount', 'adviserApprovedCount'].forEach(id => { byId(id).textContent = 'Unavailable'; });
            state(queue, 'Could not load submissions', 'Use Refresh to try again. No document counts are available.');
        }
        const user = profileResult.status === 'fulfilled' && profileResult.value.user;
        if (user && typeof user.email === 'string' && user.email && noticesResult.status === 'fulfilled' && Array.isArray(noticesResult.value.notifications)) {
            renderNotices(noticesResult.value.notifications, user.email);
            byId('adviserUnreadCount').textContent=String(noticesResult.value.unread_count||0)+' unread of '+noticesResult.value.notifications.length+' recent messages';
            document.dispatchEvent(new CustomEvent('prism:notification-count',{detail:{unread:noticesResult.value.unread_count}}));
        } else state(notices, 'Could not load personal notifications', 'Your account and notification information must both be available. Use Refresh to try again.');
        [queue, notices].forEach(panel => panel.setAttribute('aria-busy', 'false'));
        if (!students || queueState === 'error' || !user || noticesResult.status === 'rejected') status.textContent = 'Some dashboard information is unavailable. Refresh to try again.';
    }
    search.addEventListener('input', () => { pager.reset(); reload(); });
    filter.addEventListener('change', () => { pager.reset(); reload(); });
    sort.addEventListener('change', () => { pager.reset(); reload(); });
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
        if(payload.status==='Approved' && !commentOnlyReview && !await PrismUI.confirm({title:'Approve and submit to RPMS',confirmText:'Approve & Submit',message:`Approve “${reviewDocument.originalName}” version ${reviewDocument.versionNo} and formally submit it to RPMS?`}))return;
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
