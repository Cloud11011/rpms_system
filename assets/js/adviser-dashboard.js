document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const byId = id => document.getElementById(id);
    const queue = byId('adviserQueue');
    const search = byId('adviserQueueSearch');
    const filter = byId('adviserQueueFilter');
    const refresh = byId('adviserRefresh');
    const notices = byId('adviserNotifications');
    const profile = byId('adviserProfile');
    const dialog = byId('adviserReviewDialog');
    const form = byId('adviserReviewForm');
    const reviewStatus = byId('adviserReviewStatus');
    const remarks = byId('adviserReviewRemarks');
    const save = byId('adviserReviewSave');
    const cancel = byId('adviserReviewCancel');
    const reviewError = byId('adviserReviewError');
    const status = byId('adviserStatus');
    // This controller only initializes on the complete Adviser dashboard.
    if (![queue, search, filter, refresh, notices, profile, dialog, form, reviewStatus, remarks, save, cancel, reviewError, status].every(Boolean)) return;
    let documents = [];
    let queueState = 'loading';
    let loadSequence = 0;
    let reviewDocument = null;
    let reviewOpener = null;
    let saving = false;
    const dirty = PrismUI.dirtyForm(form);
    const count = node('p', 'Loading records...'); queue.insertAdjacentElement('beforebegin', count);
    const pager = PrismUI.recordPager(queue, count, [search, filter], reload);

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
    function field(list, label, value) {
        const entry = node('div');
        entry.append(node('dt', label), node('dd', value == null || value === '' ? 'Not recorded' : value));
        list.append(entry);
    }
    function reviewRequired() {
        remarks.required = ['Denied', 'Resubmission Requested'].includes(reviewStatus.value);
    }
    function setSaving(pending) {
        saving = pending;
        save.disabled = pending;
        form.setAttribute('aria-busy', String(pending));
        save.textContent = pending ? 'Saving review...' : 'Save review';
    }
    function openReview(doc, opener) {
        reviewDocument = doc;
        reviewOpener = PrismUI.actionOrigin(opener);
        byId('adviserReviewTitle').textContent = 'Review: ' + (doc.originalName || 'Document');
        reviewStatus.value = [...reviewStatus.options].some(option => option.value === doc.reviewStatus) ? doc.reviewStatus : 'Under Review';
        remarks.value = doc.reviewRemarks || '';
        reviewError.textContent = '';
        reviewRequired();
        dirty.clean();
        dialog.showModal();
        reviewStatus.focus();
    }
    function renderQueue() {
        if (queueState !== 'ready') return;
        const selected = documents;
        if (!selected.length) {
            const filtered = !!(search.value.trim() || filter.value);
            state(queue, filtered ? 'No matching submissions' : 'No documents currently waiting for review.', filtered ? 'Adjust your search or workflow status filter.' : 'Documents from your assigned students will appear here when they upload them.');
            return;
        }
        queue.replaceChildren();
        const scroll = node('div', null, 'adviser-table-scroll');
        scroll.tabIndex = 0;
        scroll.setAttribute('role', 'region');
        scroll.setAttribute('aria-label', 'Current document reviews');
        const table = node('table', null, 'adviser-review-table');
        const caption = node('caption', 'Current submissions for your assigned students');
        const head = node('thead');
        const heading = node('tr');
        ['Student', 'Document', 'Submission Date', 'Status', 'Actions'].forEach(label => {
            const cell = node('th', label); cell.scope = 'col'; heading.append(cell);
        });
        head.append(heading);
        const rows = node('tbody');
        table.append(caption, head, rows); scroll.append(table); queue.append(scroll);
        selected.forEach(doc => {
            const row = node('tr');
            const student = node('td', doc.student || 'Not recorded');
            const card = node('td', null, 'adviser-document');
            card.append(node('h3', doc.originalName || 'Untitled document'));
            const badge = PrismUI.badgeElement(doc.workflowState || 'Status unavailable');
            const statusCell = node('td'); statusCell.append(badge);
            const meta = node('dl', null, 'adviser-document-meta');
            field(meta, 'Document type', doc.documentType);
            field(meta, 'Stage', doc.stageLabel || doc.stage);
            field(meta, 'Version', doc.versionNo);
            field(meta, 'Review status', doc.reviewStatus);
            card.append(meta);
            if (doc.reviewRemarks) card.append(node('p', 'Reviewer remarks: ' + doc.reviewRemarks, 'adviser-document-remarks'));
            const actions = node('div', null, 'adviser-document-actions');
            const fileLink = node('a', 'Open document', 'adviser-button');
            fileLink.href = 'documents_api.php?action=file&id=' + encodeURIComponent(String(doc.id));
            fileLink.target = '_blank';
            fileLink.rel = 'noopener noreferrer';
            actions.append(fileLink);
            if (doc.actions && doc.actions.review === true) {
                const review = node('button', 'Review Document', 'adviser-button');
                review.type = 'button';
                review.dataset.reviewId = String(doc.id);
                review.addEventListener('click', () => openReview(doc, review));
                actions.append(review);
            }
            PrismUI.actionMenu(actions);
            const actionCell = node('td'); actionCell.append(actions);
            row.append(student, card, node('td', dateText(doc.uploadedAt)), statusCell, actionCell);
            rows.append(row);
        });
    }
    function renderProfile(user) {
        const details = node('dl', null, 'adviser-profile-details');
        field(details, 'Name', user.name);
        field(details, 'Email', user.email);
        field(details, 'Role', user.role);
        profile.replaceChildren(details);
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
            card.append(node('h3', item.subject || item.type || 'Notification'), node('p', item.message || ''), node('small', dateText(item.created_at) + ' | ' + (item.status || 'Status unavailable') + (item.read_at ? ' | Read' : ' | Unread')));
            notices.append(card);
        });
    }
    async function reload() {
        const sequence = ++loadSequence;
        queueState = 'loading';
        documents = [];
        status.textContent = '';
        [queue, notices, profile].forEach(panel => panel.setAttribute('aria-busy', 'true'));
        state(queue, 'Loading submissions...', 'Checking current document versions.');
        state(notices, 'Loading notifications...', 'Checking recent messages for your account.');
        state(profile, 'Loading account...', 'Checking your current account information.');
        ['adviserStudentsCount', 'adviserPendingCount', 'adviserRevisionCount', 'adviserApprovedCount'].forEach(id => { byId(id).textContent = 'Loading...'; });
        const results = await Promise.allSettled([
            PrismUI.request('students_api.php?action=list&preview=1'),
            PrismUI.request('documents_api.php?' + new URLSearchParams({action:'list',page:pager.page,q:search.value.trim(),state:filter.value})),
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
        if (user && typeof user.email === 'string' && user.email) renderProfile(user);
        else state(profile, 'Could not load account', 'Use Refresh to try again or open your account page.');
        if (user && typeof user.email === 'string' && user.email && noticesResult.status === 'fulfilled' && Array.isArray(noticesResult.value.notifications)) {
            renderNotices(noticesResult.value.notifications, user.email);
        } else state(notices, 'Could not load personal notifications', 'Your account and notification information must both be available. Use Refresh to try again.');
        [queue, notices, profile].forEach(panel => panel.setAttribute('aria-busy', 'false'));
        if (!students || queueState === 'error' || !user || noticesResult.status === 'rejected') status.textContent = 'Some dashboard information is unavailable. Refresh to try again.';
    }
    search.addEventListener('input', () => { pager.reset(); reload(); });
    filter.addEventListener('change', () => { pager.reset(); reload(); });
    refresh.addEventListener('click', reload);
    reviewStatus.addEventListener('change', reviewRequired);
    cancel.addEventListener('click', () => { if (!saving) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    dialog.addEventListener('close', () => { dirty.clean(); if (reviewOpener && reviewOpener.isConnected) reviewOpener.focus(); reviewDocument = null; });
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
            await PrismUI.postJson('documents_api.php?action=review', payload);
            dialog.close();
            await reload();
            search.focus();
            status.textContent = queueState === 'ready' ? 'Review saved. Current submissions have been refreshed.' : 'Review saved, but current submissions could not be refreshed. Use Refresh to try again.';
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
