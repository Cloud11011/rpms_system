document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const STAGES = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
    const progressStageNames = ['Protocol Submission', 'Initial Ethics Review', 'Revisions & Resubmission', 'Certificate of Approval', 'Continuing Review / Monitoring', 'IERB Process Completed'];
    const stageLabels = window.PRISM_STAGE_LABELS || {};
    const labelForStage = stageKey => stageLabels[stageKey] || stageKey;
    const $ = id => document.getElementById(id);
    const documentUiPreview = new URLSearchParams(window.location.search).get('ui-preview') === '1';
    PrismUI.documentTypes.forEach(type => $('documentType').add(new Option(type, type)));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = value => {
        const date = value ? new Date(value) : null;
        return date && Number.isFinite(date.getTime())
            ? date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : 'N/A';
    };
    const recentAge = value => {
        const date = value ? new Date(value) : null;
        if (!date || !Number.isFinite(date.getTime())) return 'Date unavailable';
        const today = new Date();
        const dayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const uploadedDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const days = Math.max(0, Math.floor((dayStart - uploadedDay) / 86400000));
        return days === 0 ? 'Today' : days === 1 ? 'Yesterday' : `${days} days ago`;
    };

    let myRecord = null;   // this student's IERB record (or null if none yet)
    let myDocuments = [], dashboardDocumentTotal = 0, documentHistory = {records:[],total:0,page:1};
    const documentTable = $('documentRows').closest('.document-table-wrap');
    // The committed template has a table, not a separate document-card list/count.
    const documentCount = {set textContent(value) {documentTable.setAttribute('aria-label', 'My documents table. ' + value);}};
    const documentSearch=$('studentDocumentSearch'),documentSort=$('studentDocumentSort');
    const docPager = PrismUI.recordPager(documentTable,documentCount,[$('documentFilter'),documentSearch,documentSort],refreshDocuments);
    const profileDirty = PrismUI.dirtyForm($('profileForm'));
    const passwordDirty = PrismUI.dirtyForm($('passwordForm'));
    let myHistory = [], historyState = {total:0,page:1}, historySequence = 0;
    const historyCount = document.createElement('p'); $('progressHistory').insertAdjacentElement('beforebegin',historyCount);
    const historyPager = PrismUI.recordPager($('progressHistory'),historyCount,[],refreshHistory);
    let myNotifications = [], unreadNotifications = 0;
    let notificationHistory = { records: [], error: false, total: 0, page: 1 };
    let notificationSequence = 0;
    let loadErrors = { progress: false, history: false, documents: false, notifications: false };
    let refreshSequence = 0;
    const navigationMedia = window.matchMedia('(max-width: 1120px)');

    function setNavigation(open, restoreFocus = false) {
        const expanded = !navigationMedia.matches || open;
        const navigation = $('portalNav');
        if (!expanded && (restoreFocus || navigation.contains(document.activeElement))) $('portalNavigationToggle').focus();
        navigation.hidden = !expanded;
        $('portalNavigationToggle').setAttribute('aria-expanded', String(expanded));
    }

    function setProfileMenu(open, restoreFocus = false) {
        if (!open && (restoreFocus || $('portalProfileLinks').contains(document.activeElement))) $('portalProfileToggle').focus();
        $('portalProfileLinks').hidden = !open;
        $('portalProfileToggle').setAttribute('aria-expanded', String(open));
    }

    function toast(message) {
        $('toast').textContent = message;
        $('toast').classList.add('show');
        setTimeout(() => $('toast').classList.remove('show'), 2300);
    }

    function empty(text) {
        return `<div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>${esc(text)}</p></div>`;
    }

    const PAGES = {
        dashboard: ['Dashboard', 'Your research and IERB progress at a glance.'],
        progress: ['IERB Progress', ''],
        submit: ['Document Submission', 'Upload requirements for RPMS/IERB review.'],
        documents: ['My Documents', ''],
        notifications: ['Notifications', 'Stay updated on reviews, reminders, and follow-ups.'],
        calendar: ['Personal Calendar', ''],
        profile: ['Profile', 'Manage your permitted personal and account information.'],
        activity: ['Activity Logs', 'Review actions recorded for your student account.'],
    };

    const dashboardStageDescriptions = [
        'Submit your research proposal and required forms.',
        'Your adviser reviews and endorses your proposal.',
        'Revise your documents based on feedback, then upload the updated files.',
        'The ethics board reviews your submitted documents.',
        'Receive your ethics clearance.',
        'Complete final reporting and close your IERB requirements.',
    ];
    let selectedProgressStage = 0;
    function updateProgressStageDetail(index) {
        const stage = STAGES[Math.max(0, Math.min(STAGES.length - 1, index))];
        $('stageList').setAttribute('aria-label', `${labelForStage(stage)}: ${dashboardStageDescriptions[index] || ''}`);
    }
    $('stageList').addEventListener('click', event => {
        const card = event.target.closest('[data-progress-stage]');
        if (!card) return;
        selectedProgressStage = Number(card.dataset.progressStage);
        $('stageList').querySelectorAll('[data-progress-stage]').forEach((item,index) => {
            item.classList.toggle('selected', index === selectedProgressStage);
            item.setAttribute('aria-pressed', String(index === selectedProgressStage));
        });
        updateProgressStageDetail(selectedProgressStage);
    });

    function go(page) {
        const active = PAGES[page] ? page : 'dashboard';
        const meta = PAGES[active];
        document.querySelectorAll('.portal-page').forEach(x => x.classList.toggle('active', x.dataset.section === active));
        if (active === 'submit') {
            const submitPage = document.querySelector('.portal-page[data-section="submit"]');
            submitPage?.classList.remove('submit-page-entering');
            if (submitPage) {
                void submitPage.offsetWidth;
                submitPage.classList.add('submit-page-entering');
                window.setTimeout(() => submitPage.classList.remove('submit-page-entering'), 500);
            }
        }
        document.querySelectorAll('#portalNav [data-page]').forEach(button => {
            const current = button.dataset.page === active;
            button.closest('li').classList.toggle('active', current);
            if (current) button.setAttribute('aria-current', 'page');
            else button.removeAttribute('aria-current');
        });
        setNavigation(false);
        setProfileMenu(false);
        $('pageTitle').textContent = meta[0];
        $('pageSubtitle').textContent = meta[1];
        $('pageSubtitle').hidden = !meta[1];
        if (active === 'activity') loadStudentActivity();
        if (window.location.hash !== '#' + active) history.replaceState(null, '', '#' + active);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ------------------------------------------------------------------
    // Data loading
    // ------------------------------------------------------------------
    async function readList(url, key) {
        const data = await PrismUI.request(url);
        if (!Array.isArray(data[key])) throw new Error('Unable to load workspace data.');
        return data[key];
    }

    async function loadProgress() {
        let record;
        try {
            const records = await readList('ierb_api.php?action=list', 'records');
            record = records[0] || null;
        } catch (_) {
            return { record: null, history: [], error: true, historyError: false };
        }
        if (!record) return { record, history: [], error: false, historyError: false };
        try {
            const data = await PrismUI.request(`ierb_api.php?action=history&studentId=${encodeURIComponent(record.id)}`);
            return { record, history:data.history,total:data.total??data.history.length,page:data.page??1,error:false,historyError:false };
        } catch (_) {
            return { record, history: [], error: false, historyError: true };
        }
    }

    async function loadDocuments() {
        try {
            const data = await PrismUI.request('documents_api.php?action=list');
            return {records:data.documents,error:false,total:data.total??data.documents.length,page:data.page??1,counts:data.counts};
        } catch (_) {
            return { records: [], error: true };
        }
    }

    async function loadNotifications(page = 1) {
        try {
            const data = await PrismUI.request(`notifications_api.php?action=list&page=${page}`);
            if (!Array.isArray(data.notifications)) throw new Error('Notifications unavailable');
            return { records: data.notifications, error: false, total: data.total ?? data.notifications.length, page: data.page ?? 1, unread: data.unread_count ?? 0 };
        } catch (_) {
            return { records: [], error: true };
        }
    }

    async function loadProfile() {
        try {
            const data = await PrismUI.request('profile_api.php?action=me');
            return data.ok ? data.user : null;
        } catch (_) {
            return null;
        }
    }

    let activityRequest = 0;
    async function loadStudentActivity(page = 1) {
        const requestId = ++activityRequest;
        const host = $('studentActivityList');
        const count = $('studentActivityCount');
        const pager = $('studentActivityPagination');
        host.setAttribute('aria-busy', 'true');
        host.replaceChildren(Object.assign(document.createElement('p'), { className: 'student-activity-empty', textContent: 'Loading activity…' }));
        try {
            const data = await PrismUI.request(`audit_api.php?action=list&page=${page}`);
            if (requestId !== activityRequest) return;
            const entries = Array.isArray(data.entries) ? data.entries : [];
            PrismUI.pagination(pager, count, { ...data, total: data.total ?? entries.length }, loadStudentActivity, 'entries');
            if ((Number(data.total) || entries.length) <= Math.max(1, Number(data.limit) || 10)) pager.replaceChildren();
            host.replaceChildren();
            if (!entries.length) {
                host.append(Object.assign(document.createElement('p'), { className: 'student-activity-empty', textContent: 'No activity has been recorded for your account yet.' }));
                return;
            }
            entries.forEach(entry => {
                const item = document.createElement('article'); item.className = 'activity-row';
                const heading = document.createElement('header');
                const title = document.createElement('strong'); title.textContent = entry.actionLabel || entry.action || 'Account activity';
                const time = document.createElement('time'); time.textContent = fmt(entry.at);
                heading.append(title, time); item.append(heading);
                if (entry.details) { const details = document.createElement('p'); details.textContent = entry.details; item.append(details); }
                host.append(item);
            });
        } catch (error) {
            if (requestId !== activityRequest) return;
            count.textContent = 'Activity unavailable'; pager.replaceChildren();
            host.replaceChildren(Object.assign(document.createElement('p'), { className: 'student-activity-empty is-error', textContent: error.message || 'Could not load activity logs.' }));
        } finally { if (requestId === activityRequest) host.setAttribute('aria-busy', 'false'); }
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------
    function render() {
        const stageIndex = myRecord ? STAGES.indexOf(myRecord.stage) + 1 : 0;
        const rawProgress = Number(myRecord?.progress || 0);
        const progress = Number.isFinite(rawProgress) ? Math.max(0, Math.min(100, rawProgress)) : 0;
        const status = loadErrors.progress ? 'Unavailable' : myRecord?.status || 'No record yet';
        const stageText = loadErrors.progress ? 'Unavailable' : myRecord
            ? `${stageIndex ? `Stage ${stageIndex} of ${STAGES.length} - ` : ''}${labelForStage(myRecord.stage)}` : 'No IERB record yet';
        const pendingCount = myRecord && myRecord.requirements && myRecord.requirements !== 'None' ? 1 : 0;
        const latestDoc = myDocuments[0];

        $('welcomeName').textContent = body.dataset.name;
        $('researchTitle').textContent = loadErrors.progress ? 'Research details are temporarily unavailable.' : myRecord?.research || 'No research details yet.';
        const dashboardProtocolCode = myRecord?.protocolCode || '';
        $('dashboardProtocolCode').textContent = dashboardProtocolCode;
        $('dashboardProtocol').hidden = !dashboardProtocolCode;
        $('dashboardStageValue').textContent = stageText;
        $('dashboardStatusValue').textContent = status;
        const track = $('dashboardProgressTrack');
        $('dashboardProgressBar').style.width = `${progress}%`;
        $('dashboardProgressPercent').textContent = loadErrors.progress ? 'N/A' : `${progress}%`;
        if (loadErrors.progress) {track.removeAttribute('aria-valuenow');track.setAttribute('aria-valuetext','Unavailable');}
        else {track.setAttribute('aria-valuenow',String(progress));track.removeAttribute('aria-valuetext');}
        $('dashboardPendingValue').textContent = loadErrors.progress ? 'N/A' : String(pendingCount);
        $('dashboardSubmissionValue').innerHTML = loadErrors.documents ? 'Unavailable' : dashboardDocumentTotal
            ? `${dashboardDocumentTotal} <small>${dashboardDocumentTotal === 1 ? 'document' : 'documents'}</small>` : 'Nothing uploaded yet';
        $('studentCurrentDocument').textContent = loadErrors.documents ? 'Your submissions could not be loaded.' : latestDoc
            ? `${latestDoc.documentType || latestDoc.originalName} · ${latestDoc.stageLabel || latestDoc.stage || 'Research submission'}`
            : 'Submit your first requirement to start your review.';
        const submissionTag = $('dashboardSubmissionStatus');
        submissionTag.textContent = loadErrors.documents ? 'Status unavailable' : latestDoc?.workflowState || latestDoc?.reviewStatus || '';
        submissionTag.hidden = !loadErrors.documents && !latestDoc;
        const reviewState = latestDoc?.workflowState || latestDoc?.reviewStatus || '';
        $('studentReviewState').textContent = loadErrors.documents ? 'Unavailable' : !latestDoc ? 'No review yet'
            : /pending adviser review|under review/i.test(reviewState) ? 'In review' : reviewState;
        $('studentReviewRemarks').textContent = loadErrors.documents ? 'Reviewer updates could not be loaded.' : latestDoc
            ? latestDoc.rpmsReturnReason || latestDoc.reviewRemarks || ( /pending adviser review|under review/i.test(reviewState)
                ? 'Your adviser is reviewing your document.' : 'Your adviser will review any new submission.')
            : 'Your adviser will review once you submit a document.';
        $('studentRecentNotifications').innerHTML = loadErrors.notifications
            ? empty('Notifications could not be loaded.')
            : myNotifications.slice(0,3).map(n => noticeItem(n,false)).join('') || empty('No notifications yet.');
        const unread = unreadNotifications;
        $('navBadge').textContent = loadErrors.notifications ? '!' : unread;
        $('navBadge').hidden = !loadErrors.notifications && !unread;
        $('navBadge').title = loadErrors.notifications ? 'Notifications unavailable' : `${unread} unread notifications`;

        $('currentStage').textContent = loadErrors.progress ? 'Unavailable' : myRecord ? labelForStage(myRecord.stage) : 'No record yet';
        $('principalIndicator').hidden = !myRecord?.isPrincipalInvestigator;
        $('principalIndicator').setAttribute('aria-expanded', String(!$('protocolCodeReveal').hidden));
        $('protocolCodeReveal').textContent = myRecord?.protocolCode || 'No protocol code yet';
        $('progressRing').style.setProperty('--progress-value', `${progress}%`);
        const progressText = document.createElement('b');
        progressText.textContent = loadErrors.progress ? 'N/A' : `${progress}%`;
        const progressLabel = document.createElement('span');
        progressLabel.className = 'progress-ring-label';
        progressLabel.textContent = 'complete';
        $('progressRing').replaceChildren(progressText, progressLabel);

        selectedProgressStage = myRecord ? Math.max(0, stageIndex - 1) : 0;
        $('stageList').innerHTML = loadErrors.progress ? empty('Unable to load your IERB progress. Please try again.') : STAGES.map((s, i) => {
            const cls = i + 1 < stageIndex ? 'complete' : i + 1 === stageIndex ? 'current' : '';
            const icon = i + 1 < stageIndex ? 'fa-circle-check' : 'fa-circle';
            return `<button type="button" class="stage-card ${cls}" data-progress-stage="${i}"${cls==='current'?' aria-current="step"':''} aria-pressed="${i === selectedProgressStage ? 'true' : 'false'}"><i class="fa-solid ${icon}" aria-hidden="true"></i><strong>${esc(progressStageNames[i])}</strong><small>${cls==='complete'?'✓ Completed':cls==='current'?"You're here":'Upcoming'}</small><span>${esc(s)}</span></button>`;
        }).join('');
        $('stageList').querySelectorAll('.stage-card').forEach((card, index) => {
            const selected = index === selectedProgressStage;
            card.classList.toggle('selected', selected);
            card.setAttribute('aria-pressed', String(selected));
        });
        updateProgressStageDetail(selectedProgressStage);

        $('completedRequirements').innerHTML = loadErrors.progress ? empty('Completed requirements are unavailable.') : myRecord && stageIndex > 1
            ? `<div class="list-item"><i class="fa-solid fa-circle-check"></i><div><strong>${stageIndex - 1 === 1 ? 'Stage 1' : `Stages 1-${stageIndex - 1}`}</strong><span>Completed</span></div></div>`
            : empty('No completed requirements yet.');
        $('pendingRequirements').innerHTML = loadErrors.progress ? empty('Pending requirements are unavailable.') : myRecord && myRecord.requirements && myRecord.requirements !== 'None'
            ? `<div class="list-item"><i class="fa-regular fa-clock"></i><div><strong>${esc(myRecord.requirements)}</strong><span>Pending</span></div></div>`
            : empty('No pending requirements.');

        renderHistory();

        $('recentSubmissions').innerHTML = loadErrors.documents
            ? empty('Unable to load recent submissions. Please try again.')
            : myDocuments.slice(0, 4).map(recentSubmissionItem).join('') || `<div class="student-recent-empty">
                <span class="student-recent-empty-icon"><i class="fa-regular fa-file" aria-hidden="true"></i></span>
                <strong>No submissions yet</strong>
                <p>When you upload a document, it will show up here with its status.</p>
                <button type="button" class="student-recent-empty-action" data-go="submit"><i class="fa-solid fa-upload" aria-hidden="true"></i> Submit a document</button>
            </div>`;
        const protocolCard = $('protocolCodeCard');
        if (myRecord && myRecord.protocolCode) {
            $('protocolCodeValue').textContent = myRecord.protocolCode;
            protocolCard.hidden = false;
        } else {
            protocolCard.hidden = true;
        }

        renderDocs();
        renderNotifications();
    }

    function docItem(d) {
        const state = d.workflowState || d.reviewStatus || 'Submitted';
        const version = Number(d.versionNo || 1) > 1 ? ` &middot; v${Number(d.versionNo)}` : '';
        return `<div class="list-item"><i class="fa-solid fa-file-lines"></i><div><strong>${esc(d.documentType)}</strong><span>${esc(d.originalName)}${version} &middot; ${fmt(d.uploadedAt)} &middot; ${esc(state)}</span></div></div>`;
    }

    function recentSubmissionItem(d) {
        const state = d.workflowState || d.reviewStatus || 'Submitted';
        const pending = /pending|review/i.test(state) && !/submitted to rpms/i.test(state);
        const needsAction = /needs revision|returned/i.test(state);
        const extension = (String(d.originalName || '').split('.').pop() || 'FILE').toUpperCase();
        const extensionClass = /^(DOC|DOCX|RTF|ODT)$/.test(extension) ? 'is-doc' : 'is-pdf';
        const version = Number(d.versionNo || 1) > 1 ? ` · v${Number(d.versionNo)}` : '';
        return `<a class="student-recent-item" href="${documentHref(d)}" target="_blank" rel="noopener">
            <span class="student-recent-file ${extensionClass}">${esc(extension)}</span>
            <span class="student-recent-body"><strong>${esc(d.documentType || 'Document')}</strong><span>${esc(d.originalName || 'Uploaded file')}${version}</span></span>
            <span class="student-recent-side"><span class="student-recent-status ${pending ? 'is-pending' : needsAction ? 'is-action' : 'is-submitted'}"><i class="fa-regular ${pending ? 'fa-clock' : needsAction ? 'fa-circle-xmark' : 'fa-circle-check'}" aria-hidden="true"></i>${esc(state)}</span><time>${esc(recentAge(d.uploadedAt))}</time></span>
            <i class="fa-solid fa-chevron-right student-recent-arrow" aria-hidden="true"></i>
        </a>`;
    }

    function renderHistory() {
        if(loadErrors.progress||loadErrors.history)historyPager.error();else historyPager.render(historyState);
        $('progressHistory').innerHTML = loadErrors.progress || loadErrors.history ? empty('Unable to load progress history. Please try again.') : myHistory.length
            ? myHistory.map(h => `<div class="list-item"><i class="fa-solid fa-circle" aria-hidden="true"></i><div><div class="progress-history-title"><strong>${esc(labelForStage(h.stage))}</strong><span class="progress-history-status ${/delay|pending|revision|return/i.test(h.status || '') ? 'is-attention' : ''}">${esc(h.status || 'On track')}</span></div><span class="progress-history-note">${esc(h.note || 'Updated by RPMS')}</span><span class="progress-history-meta">${esc(fmt(h.created_at))}</span></div></div>`).join('')
            : empty('No progress history yet.');
    }

    function documentHref(doc) {
        return ['application/pdf','image/png','image/jpeg'].includes(doc.mime)
            ? 'document_preview.php?id=' + encodeURIComponent(doc.id)
            : 'documents_api.php?action=file&download=1&id=' + encodeURIComponent(doc.id);
    }
    function renderDocs() {
        const docs = documentHistory.records || [];
        if (!loadErrors.documents) docPager.render(documentHistory); else docPager.error();
        if (!loadErrors.documents) PrismUI.statusPills($('studentDocumentPills'),
            [['','All'],['Pending Adviser Review','Pending review'],['Needs Revision','Needs revision'],['Returned for Revision','Returned by RPMS'],['Submitted to RPMS','Submitted to RPMS']],
            $('documentFilter'),documentHistory.counts || {});
        const rows = $('documentRows');
        if (loadErrors.documents || !docs.length) {
            const message = loadErrors.documents ? 'Unable to load your documents. Please try again.'
                : $('documentFilter').value || documentSearch.value.trim() ? 'No documents match this view.' : "You haven't uploaded anything yet";
            rows.innerHTML = `<tr><td colspan="6">${empty(message)}</td></tr>`;
            return;
        }
        rows.innerHTML = docs.map(d => {
            const state = d.workflowState || d.reviewStatus || 'Submitted';
            const remarks = d.rpmsReturnReason || d.reviewRemarks || 'No reviewer remarks yet';
            const inline = ['application/pdf','image/png','image/jpeg'].includes(d.mime);
            return `<tr><td><strong>${esc(d.originalName)}</strong><br><small>v${Number(d.versionNo || 1)} · ${esc(d.stageLabel || d.stage)}</small></td>
                <td>${esc(d.documentType)}</td><td>${esc(fmt(d.uploadedAt))}</td><td>${PrismUI.badge(state,{small:true})}</td>
                <td>${esc(remarks)}</td><td><div class="student-document-actions">
                <a class="action-btn" href="${documentHref(d)}" target="_blank" rel="noopener" aria-label="${inline ? 'View' : 'Download'} ${esc(d.originalName)}"><i class="fa-regular fa-eye" aria-hidden="true"></i> ${inline ? 'View' : 'Download'}</a>
                <a class="action-btn" href="documents_api.php?action=file&download=1&id=${encodeURIComponent(d.id)}"><i class="fa-solid fa-download" aria-hidden="true"></i> Download</a>
                <button type="button" class="action-btn" data-version-history="${esc(d.id)}"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Version history</button>
                ${d.actions?.uploadRevision ? `<button type="button" class="action-btn" data-upload-revision="${esc(d.id)}"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Upload revision</button>` : ''}
                </div></td></tr>`;
        }).join('');
    }

    function noticeItem(n, includeAction = true) {
        const unread = !n.read_at;
        return `<div class="list-item notice ${unread ? 'unread' : ''}"><i class="fa-solid fa-bell"></i><div><strong>${esc(n.type)}</strong><span>${esc(n.message)}</span><time>${fmt(n.created_at)}</time></div>${unread && includeAction ? `<button data-read="${esc(n.id)}" title="Mark read"><i class="fa-solid fa-check"></i></button>` : ''}</div>`;
    }

    const notificationCenter = PrismNotifications.mount({ list: $('notificationList'), count: $('studentNotificationCount'),
        pager: $('studentNotificationPagination'), markAll: $('markAllRead'), studentCenter: true, onChange: refreshNotificationOverview });
    async function refreshNotificationOverview() {
        const data = await loadNotifications();
        myNotifications = data.records; unreadNotifications = data.unread ?? 0;
        loadErrors.notifications = data.error;
        render();
    }
    function renderNotifications() { notificationCenter.load(); }

    async function fillProfile() {
        const profile = await loadProfile();
        if (!profile) return;
        $('profileName').value = profile.name || '';
        $('profileEmail').value = profile.email || '';
        $('profileRole').value = profile.role || '';
        $('profileId').value = profile.refId || '';
        profileDirty.clean();
        fillSubmissionRecord();
    }

    function fillSubmissionRecord() {
        $('submissionResearchTitle').value = myRecord?.research || 'Not recorded';
        $('submissionResearchGroup').value = myRecord?.groupId || 'Not recorded';
        $('submissionStudentId').value = myRecord?.studentId || 'Not recorded';
        $('submissionStage').value = myRecord?.stageLabel || myRecord?.stage || 'Not recorded';
        $('submissionAdviser').value = myRecord?.adviser || 'Unassigned';
        $('submissionProtocol').value = myRecord?.protocolCode || 'No protocol code yet';
    }

    async function refreshAll() {
        const sequence = ++refreshSequence;
        ++notificationSequence;
        ++documentSequence; ++historySequence;
        $('studentDashboardState').setAttribute('aria-busy', 'true');
        $('studentDashboardState').textContent = 'Loading your workspace...';
        const [progress, documents, notifications] = await Promise.all([loadProgress(), loadDocuments(), loadNotifications()]);
        if (sequence !== refreshSequence) return;
        myRecord = progress.record;
        myHistory = progress.history;
        historyState = {total:progress.total??myHistory.length,page:progress.page??1};
        historyPager.reset();
        myDocuments = documents.records;
        documentHistory = documents;
        documentSearch.value='';documentSort.value='newest';$('documentFilter').value='';
        dashboardDocumentTotal = documents.total ?? documents.records.length;
        docPager.reset();
        myNotifications = notifications.records; unreadNotifications = notifications.unread ?? 0;
        notificationHistory = notifications;
        $('notificationList').setAttribute('aria-busy', 'false');
        loadErrors = { progress: progress.error, history: progress.historyError, documents: documents.error, notifications: notifications.error };
        render();
        fillSubmissionRecord();
        updateSubmissionReadiness();
        const unavailable = Object.entries(loadErrors).filter(([, failed]) => failed).map(([area]) => area === 'history' ? 'progress history' : area);
        $('studentDashboardState').textContent = unavailable.length ? `Unable to load ${unavailable.join(', ')}. Please refresh to try again.` : '';
        $('studentDashboardState').setAttribute('aria-busy', 'false');
    }

    // ------------------------------------------------------------------
    // Navigation
    // ------------------------------------------------------------------
    $('portalNavigationToggle').addEventListener('click', () => {
        setProfileMenu(false);
        setNavigation($('portalNavigationToggle').getAttribute('aria-expanded') !== 'true');
    });
    $('portalProfileToggle').addEventListener('click', () => {
        setNavigation(false);
        setProfileMenu($('portalProfileLinks').hidden);
    });
    navigationMedia.addEventListener('change', () => setNavigation(false));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (!$('portalProfileLinks').hidden) { event.preventDefault(); setProfileMenu(false, true); }
        else if (navigationMedia.matches && !$('portalNav').hidden) { event.preventDefault(); setNavigation(false, true); }
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.portal-profile-menu')) setProfileMenu(false);
    });
    window.addEventListener('hashchange', () => go(window.location.hash.slice(1)));
    document.addEventListener('prism:documents-changed', refreshAll);
    setNavigation(false);

    document.querySelectorAll('#portalNav button[data-page]').forEach(b => b.addEventListener('click', () => go(b.dataset.page)));

    // ------------------------------------------------------------------
    // Document submission
    // ------------------------------------------------------------------
    let revisionDocument = null;
    const revisionNotice = document.createElement('p'); revisionNotice.setAttribute('role','status'); revisionNotice.hidden = true;
    $('submissionForm').before(revisionNotice);
    const cancelRevision = document.createElement('button'); cancelRevision.type='button'; cancelRevision.textContent='Cancel revision'; cancelRevision.className='action-btn'; cancelRevision.hidden=true;
    revisionNotice.after(cancelRevision);
    const submissionForm = $('submissionForm');
    const submissionType = $('documentType');
    const submissionFile = $('documentFile');
    const submissionNotes = $('documentNotes');
    const submissionButton = submissionForm.querySelector('[type="submit"]');
    const maxUploadBytes = 20 * 1024 * 1024;

    function updateSubmissionReadiness() {
        const hasType = Boolean(submissionType.value);
        const files = [...submissionFile.files];
        const invalid = files.find(file => {
            const extension = file.name.split('.').pop().toLowerCase();
            return !['pdf', 'docx'].includes(extension) || file.size > maxUploadBytes;
        });
        const hasFiles = files.length > 0 && !invalid;
        const fileSelection = $('uploadSelection');
        fileSelection.replaceChildren();
        files.forEach((file, index) => {
            const card = document.createElement('div'); card.className = 'submission-file-card';
            const badge = document.createElement('span'); badge.className = 'submission-file-badge';
            badge.textContent = file.name.split('.').pop().toUpperCase();
            const meta = document.createElement('span'); meta.className = 'submission-file-meta';
            const name = document.createElement('strong'); name.textContent = file.name;
            const size = document.createElement('small'); size.textContent = file.size < 1024 * 1024
                ? `${Math.ceil(file.size / 1024)} KB` : `${(file.size / (1024 * 1024)).toFixed(1)} MB`;
            meta.append(name, size);
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'submission-file-remove';
            remove.setAttribute('aria-label', `Remove ${file.name}`); remove.textContent = 'Remove';
            remove.addEventListener('click', () => {
                const remaining = new DataTransfer();
                [...submissionFile.files].forEach((selected, selectedIndex) => { if (selectedIndex !== index) remaining.items.add(selected); });
                submissionFile.files = remaining.files;
                submissionFile.dispatchEvent(new Event('change', { bubbles: true }));
                submissionFile.focus();
            });
            card.append(badge, meta, remove); fileSelection.append(card);
        });
        submissionButton.disabled = !(hasType && hasFiles);
        const error = invalid ? invalid.size > maxUploadBytes ? `${invalid.name} is over the 20 MB file limit.` : `${invalid.name} is not a PDF or DOCX file.` : '';
        submissionFile.setCustomValidity(error);
        if (error) $('uploadResults').textContent = error;
    }

    function clearRevision() {
        revisionDocument=null; revisionNotice.hidden=true; cancelRevision.hidden=true;
        submissionType.querySelectorAll('[data-legacy-revision]').forEach(option => option.remove());
        submissionType.disabled=false; submissionFile.multiple=true; updateSubmissionReadiness();
    }
    cancelRevision.addEventListener('click',clearRevision);
    submissionType.addEventListener('change', updateSubmissionReadiness);
    submissionFile.addEventListener('change', updateSubmissionReadiness);
    submissionNotes.addEventListener('input', updateSubmissionReadiness);
    document.addEventListener('prism:upload-revision',event=>{
        const doc=event.detail; if(!doc?.actions?.uploadRevision)return;
        revisionDocument=doc;
        submissionType.querySelectorAll('[data-legacy-revision]').forEach(option => option.remove());
        if (![...submissionType.options].some(option => option.value === doc.documentType)) {
            const option = new Option(doc.documentType, doc.documentType); option.dataset.legacyRevision='1'; submissionType.add(option);
        }
        $('documentType').value=doc.documentType; $('documentType').disabled=true; $('documentFile').multiple=false;
        revisionNotice.textContent=`Upload a NEW revision of ${doc.originalName} (version ${doc.versionNo}, ${doc.stageLabel || doc.stage}). The submitted file and history will be kept. ${doc.rpmsReturnReason || doc.reviewRemarks || ''}`;
        revisionNotice.hidden=false; cancelRevision.hidden=false; updateSubmissionReadiness(); $('documentFile').focus();
    });
    updateSubmissionReadiness();
    $('submissionForm').addEventListener('submit', async event => {
        event.preventDefault();
        const files = $('documentFile').files;
        if (!files.length) { toast('Please choose files.'); return; }
        const formData = new FormData();
        Array.from(files).forEach(file => formData.append('document[]', file));
        PrismUpload.prepare(formData, files.length);
        formData.append('documentType', $('documentType').value);
        if(revisionDocument)formData.append('revisionOf',revisionDocument.id);
        formData.append('stage', myRecord?.stage || 'Stage 1');
        formData.append('notes', $('documentNotes').value.trim());

        const submitBtn = event.target.querySelector('[type="submit"]');
        const release = PrismUI.busy(submitBtn,'Uploading...');
        if(!release)return;
        try {
            const data = await PrismUI.request('documents_api.php?action=upload', { method: 'POST', body: formData });
            event.target.reset();
            clearRevision();
            await refreshAll();
            $('uploadSelection').textContent='';
            PrismUpload.render(data);
            toast(data.message);
        } catch (e) {
            $('documentFile').value='';$('uploadSelection').textContent='';
            PrismUpload.render(e.data||{message:e.message+' Check Documents before selecting files again; some uploads may have completed.'});
            await refreshAll();
            toast(e.message);
        } finally {
            release();
            updateSubmissionReadiness();
        }
    });

    async function refreshHistory() {
        if(!myRecord)return;
        const sequence = ++historySequence; historyPager.loading();
        try {
            const data = await PrismUI.request(`ierb_api.php?action=history&studentId=${encodeURIComponent(myRecord.id)}&page=${historyPager.page}`);
            if(sequence!==historySequence)return;
            myHistory=data.history;historyState={total:data.total??myHistory.length,page:data.page??1};loadErrors.history=false;renderHistory();
        } catch(e){if(sequence!==historySequence)return;loadErrors.history=true;renderHistory();}
    }
    let documentSequence = 0;
    async function refreshDocuments() {
        const sequence = ++documentSequence; docPager.loading();
        const filter = $('documentFilter').value;
        const qs = new URLSearchParams({action:'list',page:docPager.page,q:documentSearch.value.trim(),sort:documentSort.value});
        if (['Submitted','Under Review','Received','Verified','Approved','Denied','Resubmission Requested'].includes(filter)) qs.set('review',filter);
        else if(filter) qs.set('state',filter);
        try {
            const data = await PrismUI.request('documents_api.php?'+qs);
            if(sequence!==documentSequence)return;
            documentHistory = {records:data.documents,total:data.total??data.documents.length,page:data.page??1,counts:data.counts};
            loadErrors.documents = false; renderDocs();
        } catch(e) { if(sequence!==documentSequence)return; loadErrors.documents = true; renderDocs(); docPager.error(); }
    }
    $('documentFilter').addEventListener('change', () => {docPager.reset();refreshDocuments();});
    const documentSearchReload=PrismUI.debounce(()=>{docPager.reset();refreshDocuments();});
    documentSearch.addEventListener('input',documentSearchReload);
    documentSort.addEventListener('change',()=>{docPager.reset();refreshDocuments();});
    document.addEventListener('click', e => {
        const goButton = e.target.closest('[data-go]');
        if (goButton && !goButton.hasAttribute('data-page')) { e.preventDefault(); go(goButton.dataset.go); return; }
        if (e.target.closest('#copyProtocolCode')) {
            const code = $('protocolCodeValue').textContent;
            if (code && navigator.clipboard) navigator.clipboard.writeText(code).then(() => toast('Protocol code copied')).catch(() => toast('Copy unavailable.'));
            return;
        }
        const history=e.target.closest('[data-version-history]'); if(history){PrismUI.showVersions(history.dataset.versionHistory);return;}
        const button = e.target.closest('[data-upload-revision]');
        if(!button)return;
        const doc = [...documentHistory.records,...myDocuments].find(d=>String(d.id)===button.dataset.uploadRevision);
        if(doc)PrismUI.uploadRevision(doc);
    });
    // ------------------------------------------------------------------
    // Profile
    // ------------------------------------------------------------------
    $('profileForm').addEventListener('submit', async event => {
        event.preventDefault();
        const release = PrismUI.busy(event.currentTarget.querySelector('[type="submit"]'),'Saving...');
        if(!release)return;
        try {
            const data = await PrismUI.request('profile_api.php?action=update_profile', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: $('profileName').value.trim() }),
            });
            if (!data.ok) { toast(data.message || 'Profile could not be updated.'); return; }
            $('sideName').textContent = $('profileName').value.trim();
            $('welcomeName').textContent = $('profileName').value.trim();
            profileDirty.clean();
            toast('Profile updated.');
        } catch (error) {
            toast(error.message || 'Could not reach the server to update your profile.');
        } finally {release();}
    });

    $('passwordForm').addEventListener('submit', async event => {
        event.preventDefault();
        if ($('newPassword').value !== $('confirmPassword').value) { toast('New passwords do not match.'); return; }
        const release = PrismUI.busy(event.currentTarget.querySelector('[type="submit"]'),'Saving...');
        if(!release)return;
        try {
            const data = await PrismUI.request('profile_api.php?action=change_password', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ currentPassword: $('currentPassword').value, newPassword: $('newPassword').value }),
            });
            if (!data.ok) { toast(data.message || 'Password could not be changed.'); return; }
            event.target.reset();
            passwordDirty.clean();
            toast('Password changed successfully.');
        } catch (error) {
            toast(error.message || 'Could not reach the server to change your password.');
        } finally {release();}
    });

    $('principalIndicator').addEventListener('click', () => {
        $('protocolCodeReveal').hidden = !$('protocolCodeReveal').hidden;
        $('principalIndicator').setAttribute('aria-expanded', String(!$('protocolCodeReveal').hidden));
    });
    let photoUrl = null;
    $('previewProfileImage').addEventListener('click', () => $('profileImageInput').click());
    $('profileImageInput').addEventListener('change', () => {
        const file = $('profileImageInput').files[0];
        if (!file) return;
        if (!['image/png','image/jpeg','image/webp'].includes(file.type)) {toast('Choose a PNG, JPEG or WebP image.');return;}
        if (photoUrl) URL.revokeObjectURL(photoUrl);
        photoUrl = URL.createObjectURL(file);
        $('profileImagePreview').src = photoUrl; $('navProfileImage').src = photoUrl;
    });
    $('removeProfileImage').addEventListener('click', () => {
        if (photoUrl) URL.revokeObjectURL(photoUrl); photoUrl = null;
        $('profileImageInput').value = '';
        $('profileImagePreview').src = 'assets/images/default-avatar.svg'; $('navProfileImage').src = 'assets/images/default-avatar.svg';
    });
    window.addEventListener('pagehide', () => {if (photoUrl) URL.revokeObjectURL(photoUrl);});

    $('themeToggle').addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark-theme');
        try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
    });

    $('dashboardCurrentDate').textContent = new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

    (async function init() {
        const initial = window.location.hash.replace('#', '');
        go(PAGES[initial] ? initial : 'dashboard');
        await refreshAll();
        await fillProfile();
    })().catch(error => { $('studentDashboardState').setAttribute('aria-busy','false'); $('studentDashboardState').textContent = error.message || 'Workspace could not be initialized.'; });
});
