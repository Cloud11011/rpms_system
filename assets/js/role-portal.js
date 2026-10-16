document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const STAGES = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
    const progressStageNames = ['Protocol Submission', 'Initial Ethics Review', 'Revisions & Resubmission', 'Certificate of Approval', 'Continuing Review / Monitoring', 'IERB Process Completed'];
    const stageLabels = window.PRISM_STAGE_LABELS || {};
    const labelForStage = stageKey => stageLabels[stageKey] || stageKey;
    const $ = id => document.getElementById(id);
    const documentUiPreview = (document.body.dataset.prismAllowDemo==='1') && new URLSearchParams(window.location.search).get('ui-preview') === '1';
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
    const documentCount = $('studentDocumentCount');
    const documentSearch=$('studentDocumentSearch'),documentSort=$('studentDocumentSort');
    const docPager = PrismUI.recordPager($('studentDocumentList'),documentCount,[$('documentFilter'),documentSearch,documentSort],refreshDocuments);
    const clearDocumentFilters = document.querySelector('.student-document-tools > .prism-btn');
    if (clearDocumentFilters) {
        clearDocumentFilters.classList.add('student-document-clear-filters');
        clearDocumentFilters.setAttribute('aria-label','Clear document filters');
        clearDocumentFilters.title = 'Clear filters';
        clearDocumentFilters.innerHTML = '<i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>';
        $('documentFilter').insertAdjacentElement('afterend', clearDocumentFilters);
        clearDocumentFilters.addEventListener('click', syncDocumentStatusMenu);
    }
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

    function syncDocumentStatusMenu() {
        const filter = $('documentFilter');
        $('studentDocumentStatusToggle').setAttribute('aria-controls', 'studentDocumentStatusMenu');
        const selected = filter.options[filter.selectedIndex];
        $('studentDocumentStatusLabel').textContent = selected?.textContent || 'All statuses';
        document.querySelectorAll('[data-document-status-option]').forEach(option => {
            option.setAttribute('aria-selected', String(option.dataset.documentStatusOption === filter.value));
        });
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
    let selectedDashboardStage = 0;
    let selectedProgressStage = 0;
    let dashboardPercentFrame = 0;

    function animateDashboardPercent(target) {
        const output = $('studentProgressPercent');
        const startValue = Number(output.dataset.value || 0);
        output.dataset.value = String(target);
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            output.textContent = `${target}%`;
            return;
        }
        cancelAnimationFrame(dashboardPercentFrame);
        const startTime = performance.now();
        const duration = 850;
        function tick(now) {
            const ratio = Math.min(1, (now - startTime) / duration);
            const eased = 1 - Math.pow(1 - ratio, 3);
            output.textContent = `${Math.round(startValue + (target - startValue) * eased)}%`;
            if (ratio < 1) dashboardPercentFrame = requestAnimationFrame(tick);
        }
        dashboardPercentFrame = requestAnimationFrame(tick);
    }

    function renderDashboardStageDetail(index, stageIndex) {
        const host = $('studentProgressDetail');
        const stageNumber = index + 1;
        const stageName = labelForStage(STAGES[index]);
        const done = !loadErrors.progress && Boolean(myRecord) && (stageNumber < stageIndex || stageIndex === STAGES.length);
        const current = !loadErrors.progress && Boolean(myRecord) && stageNumber === stageIndex && stageIndex < STAGES.length;
        const state = loadErrors.progress ? 'Progress unavailable' : done ? 'Completed' : current ? 'In progress' : 'Coming up';
        const copy = loadErrors.progress ? 'Your IERB stage details could not be loaded. Please try again shortly.'
            : current && myRecord?.requirements && myRecord.requirements !== 'None'
                ? `Current requirement: ${myRecord.requirements}` : dashboardStageDescriptions[index];

        const text = document.createElement('div');
        const title = document.createElement('b');
        title.textContent = `Stage ${stageNumber}: ${stageName} · ${state}`;
        const description = document.createElement('p');
        description.textContent = copy;
        text.append(title, description);
        const button = document.createElement('button');
        button.className = 'student-ierb-details-button';
        button.type = 'button';
        button.innerHTML = 'View details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>';
        button.addEventListener('click', () => go('progress'));
        host.replaceChildren(text, button);
    }

    function renderDashboardProgress(stageIndex, progress, status) {
        const unavailable = loadErrors.progress;
        const title = $('studentProgressHeading');
        const chip = $('studentProgressStatus');
        title.textContent = unavailable ? 'Progress unavailable' : myRecord
            ? `${stageIndex ? `Stage ${stageIndex} of ${STAGES.length}: ` : ''}${labelForStage(myRecord.stage)}` : 'No IERB record yet';
        const statusText = unavailable ? 'Unavailable' : status;
        chip.querySelector('span').textContent = statusText;
        chip.classList.remove('is-on-track', 'is-pending', 'is-delayed', 'is-neutral');
        const statusClass = statusText.toLowerCase().replace(/\s+/g, '-');
        chip.classList.add(['on-track', 'pending', 'delayed'].includes(statusClass) ? `is-${statusClass}` : 'is-neutral');

        const track = $('studentProgressTrack');
        const fill = $('studentProgressFill');
        if (unavailable) {
            track.removeAttribute('aria-valuenow');
            track.setAttribute('aria-valuetext', 'Unavailable');
            fill.style.width = '0%';
            $('studentProgressPercent').textContent = '—';
        } else {
            track.setAttribute('aria-valuenow', String(progress));
            track.removeAttribute('aria-valuetext');
            fill.style.width = `${progress}%`;
            animateDashboardPercent(progress);
        }

        const list = $('studentProgressSteps');
        list.replaceChildren();
        const selectedIndex = Math.min(Math.max(selectedDashboardStage, 0), STAGES.length - 1);
        STAGES.forEach((stage, index) => {
            const number = index + 1;
            const done = !unavailable && Boolean(myRecord) && (number < stageIndex || stageIndex === STAGES.length);
            const current = !unavailable && Boolean(myRecord) && number === stageIndex && stageIndex < STAGES.length;
            const state = done ? 'completed' : current ? 'current' : 'upcoming';
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `student-ierb-step${done ? ' is-done' : ''}${current ? ' is-current' : ''}${index === selectedIndex ? ' is-selected' : ''}`;
            button.setAttribute('aria-label', `Stage ${number}: ${labelForStage(stage)} (${state})`);
            button.setAttribute('aria-pressed', String(index === selectedIndex));
            const dot = document.createElement('span');
            dot.className = 'student-ierb-step-dot';
            dot.textContent = done ? '✓' : String(number);
            const label = document.createElement('span');
            label.className = 'student-ierb-step-label';
            label.textContent = labelForStage(stage);
            button.append(dot, label);
            item.append(button);
            list.append(item);
        });
        renderDashboardStageDetail(selectedIndex, stageIndex);
    }

    $('studentProgressSteps').addEventListener('click', event => {
        const button = event.target.closest('.student-ierb-step');
        if (!button) return;
        selectedDashboardStage = [...$('studentProgressSteps').querySelectorAll('.student-ierb-step')].indexOf(button);
        const stageIndex = myRecord ? STAGES.indexOf(myRecord.stage) + 1 : 0;
        $('studentProgressSteps').querySelectorAll('.student-ierb-step').forEach((step, index) => {
            const selected = index === selectedDashboardStage;
            step.classList.toggle('is-selected', selected);
            step.setAttribute('aria-pressed', String(selected));
        });
        renderDashboardStageDetail(selectedDashboardStage, stageIndex);
    });

    function updateProgressStageDetail(index) {
        const target = $('progressStageDetail');
        const number = Math.max(0, Math.min(STAGES.length - 1, Number(index) || 0));
        const stageKey = STAGES[number];
        const stageIndex = myRecord ? STAGES.indexOf(myRecord.stage) + 1 : 0;
        const state = loadErrors.progress ? 'Unavailable' : myRecord && (number + 1 < stageIndex || stageIndex === STAGES.length)
            ? 'Completed' : myRecord && number + 1 === stageIndex ? 'In progress' : 'Upcoming';
        target.replaceChildren();
        const label = document.createElement('span'); label.textContent = `Stage ${number + 1}`;
        const name = document.createElement('strong'); name.textContent = progressStageNames[number];
        const description = document.createElement('span');
        description.textContent = loadErrors.progress ? 'Stage details are unavailable.'
            : state === 'In progress' ? number === 1 ? 'The IERB reviews your protocol for ethical soundness and may send comments.'
                : myRecord?.requirements && myRecord.requirements !== 'None' ? `Current requirement: ${myRecord.requirements}` : dashboardStageDescriptions[number]
                : dashboardStageDescriptions[number];
        target.append(label, name, description);
    }

    $('stageList').addEventListener('click', event => {
        const button = event.target.closest('.stage-card[data-progress-stage]');
        if (!button) return;
        selectedProgressStage = Number(button.dataset.progressStage);
        $('stageList').querySelectorAll('.stage-card').forEach((stage, index) => {
            const selected = index === selectedProgressStage;
            stage.classList.toggle('selected', selected);
            stage.setAttribute('aria-pressed', String(selected));
        });
        updateProgressStageDetail(selectedProgressStage);
    });
    $('stageList').addEventListener('keydown', event => {
        if (!['Enter', ' '].includes(event.key)) return;
        const card = event.target.closest('.stage-card[data-progress-stage]');
        if (!card) return;
        event.preventDefault(); card.click();
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
                const item = document.createElement('article'); item.className = 'student-activity-item';
                const heading = document.createElement('header');
                const title = document.createElement('strong'); title.textContent = entry.actionLabel || entry.action || 'Account activity';
                const time = document.createElement('time'); time.textContent = fmt(entry.at);
                heading.append(title, time); item.append(heading);
                if (entry.details) { const details = document.createElement('p'); details.textContent = entry.details; item.append(details); }
                if (entry.reason) { const reason = document.createElement('p'); reason.className = 'student-activity-reason'; reason.textContent = `Reason: ${entry.reason}`; item.append(reason); }
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
        renderDashboardProgress(stageIndex, progress, status);
        const pendingCard = $('dashboardPendingCard');
        const pendingIcon = $('dashboardPendingIcon');
        pendingCard.classList.toggle('is-clear', !loadErrors.progress && pendingCount === 0);
        pendingCard.classList.toggle('is-pending', !loadErrors.progress && pendingCount > 0);
        pendingCard.classList.toggle('is-unavailable', loadErrors.progress);
        pendingIcon.className = `dashboard-pending-icon fa-solid ${loadErrors.progress || pendingCount > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check'}`;
        $('dashboardPendingTitle').textContent = loadErrors.progress ? 'Pending requirements unavailable'
            : pendingCount === 0 ? 'You have no pending requirements'
                : `${pendingCount} pending requirement${pendingCount === 1 ? '' : 's'}`;
        $('dashboardPendingDescription').textContent = loadErrors.progress
            ? 'Your progress could not be loaded. Please try again shortly.'
            : pendingCount === 0 ? "You're all caught up. Tap to review past requirements."
                : 'Tap to review the requirements and actions still needed.';
        $('dashboardSubmissionValue').innerHTML = loadErrors.documents ? 'Unavailable' : dashboardDocumentTotal
            ? `${dashboardDocumentTotal} <small>${dashboardDocumentTotal === 1 ? 'document' : 'documents'}</small>` : 'Nothing uploaded yet';
        $('dashboardSubmissionAction').textContent = loadErrors.documents ? 'View document status' : dashboardDocumentTotal ? 'Open my documents' : 'Submit a document';
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
        const reviewTag = $('studentReviewTag');
        reviewTag.textContent = latestDoc && /pending adviser review|under review/i.test(reviewState) ? 'Waiting on adviser' : '';
        reviewTag.hidden = !reviewTag.textContent;

        const unread = unreadNotifications;
        $('studentNotificationValue').textContent = loadErrors.notifications ? 'Unavailable' : unread ? `${unread} new` : 'All caught up';
        $('studentNotificationSummary').textContent = loadErrors.notifications ? 'Notifications could not be loaded.' : unread
            ? (myNotifications[0]?.message || 'You have unread notifications.') : 'You have no new notifications.';
        $('studentNotificationTag').textContent = unread ? `${unread} unread` : '';
        $('studentNotificationTag').hidden = loadErrors.notifications || !unread;
        $('navBadge').textContent = loadErrors.notifications ? '!' : unread > 99 ? '99+' : unread;
        $('navBadge').hidden = !loadErrors.notifications && !unread;
        $('navBadge').title = loadErrors.notifications ? 'Notifications unavailable' : `${unread} unread notifications`;
        if(!loadErrors.notifications) document.dispatchEvent(new CustomEvent('prism:notification-count',{detail:{unread}}));

        $('currentStage').textContent = loadErrors.progress ? 'Unavailable' : myRecord ? labelForStage(myRecord.stage) : 'No record yet';
        $('progressStageNumber').textContent = loadErrors.progress ? 'Stage unavailable' : myRecord
            ? `Stage ${stageIndex} of ${STAGES.length}` : 'No IERB stage assigned yet';
        const hasPendingRequirement = Boolean(myRecord?.requirements && myRecord.requirements !== 'None');
        const actionStatus = $('progressActionStatus');
        actionStatus.classList.toggle('is-pending', hasPendingRequirement || myRecord?.status === 'Delayed');
        actionStatus.classList.toggle('is-unavailable', loadErrors.progress || !myRecord);
        actionStatus.querySelector('i').className = loadErrors.progress || !myRecord || hasPendingRequirement || myRecord?.status === 'Delayed'
            ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check';
        actionStatus.querySelector('span').textContent = loadErrors.progress ? 'Your current requirements could not be loaded.'
            : !myRecord ? 'No current IERB record is available.'
                : hasPendingRequirement ? `Pending requirement: ${myRecord.requirements}`
                    : myRecord.status === 'Delayed' ? 'Your progress is delayed. Please check with your adviser.'
                        : 'Nothing is needed from you right now.';
        $('progressResearchTitle').textContent = myRecord?.research || 'Not recorded';
        $('progressResearchTitle').classList.toggle('is-missing', !myRecord?.research);
        $('progressProtocolCode').textContent = myRecord?.protocolCode || 'Not recorded';
        $('progressProtocolCode').classList.toggle('is-missing', !myRecord?.protocolCode);
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

    function documentFileHref(d) { return ['application/pdf','image/png','image/jpeg'].includes(d.mime) ? 'document_preview.php?id='+encodeURIComponent(d.id) : 'documents_api.php?action=file&download=1&id='+encodeURIComponent(d.id); }

    function recentSubmissionItem(d) {
        const state = d.workflowState || d.reviewStatus || 'Submitted';
        const pending = /pending|review/i.test(state) && !/submitted to rpms/i.test(state);
        const needsAction = /needs revision|returned/i.test(state);
        const extension = (String(d.originalName || '').split('.').pop() || 'FILE').toUpperCase();
        const extensionClass = /^(DOC|DOCX|RTF|ODT)$/.test(extension) ? 'is-doc' : 'is-pdf';
        const version = Number(d.versionNo || 1) > 1 ? ` · v${Number(d.versionNo)}` : '';
        return `<div class="student-recent-item">
            <span class="student-recent-file ${extensionClass}">${esc(extension)}</span>
            <span class="student-recent-body"><strong>${esc(d.documentType || 'Document')}</strong><span>${esc(d.originalName || 'Uploaded file')}${version}</span></span>
            <span class="student-recent-side"><span class="student-recent-status ${pending ? 'is-pending' : needsAction ? 'is-action' : 'is-submitted'}"><i class="fa-regular ${pending ? 'fa-clock' : needsAction ? 'fa-circle-xmark' : 'fa-circle-check'}" aria-hidden="true"></i>${esc(state)}</span><time>${esc(recentAge(d.uploadedAt))}</time></span>
            <span class="prism-document-quick-actions">${PrismUI.documentActionsHtml(d)}</span>
        </div>`;
    }

    function renderHistory() {
        if(loadErrors.progress||loadErrors.history)historyPager.error();else historyPager.render(historyState);
        $('progressHistory').innerHTML = loadErrors.progress || loadErrors.history ? empty('Unable to load progress history. Please try again.') : myHistory.length
            ? myHistory.map(h => `<div class="list-item"><i class="fa-solid fa-circle" aria-hidden="true"></i><div><div class="progress-history-title"><strong>${esc(labelForStage(h.stage))}</strong><span class="progress-history-status ${/delay|pending|revision|return/i.test(h.status || '') ? 'is-attention' : ''}">${esc(h.status || 'On track')}</span></div><span class="progress-history-note">${esc(h.note || 'Updated by RPMS')}</span><span class="progress-history-meta">${esc(fmt(h.created_at))} · ${esc(h.actor || 'System')}</span></div></div>`).join('')
            : empty('No progress history yet.');
    }

    function renderDocs() {
        const filter = $('documentFilter').value;
        const docs = documentHistory.records;
        const previewOnly = documentUiPreview && !docs.length && !filter && !documentSearch.value.trim();
        const shownDocs = previewOnly ? [{preview:true,originalName:'Research Proposal.pdf',documentType:'Research Proposal',stageLabel:'Stage 1',uploadedAt:new Date().toISOString(),workflowState:'Pending Adviser Review',reviewStatus:'Under Review',versionNo:1}] : docs;
        if (!loadErrors.documents) docPager.render(documentHistory);
        if (previewOnly) documentCount.textContent = 'UI preview: 1 sample result (not uploaded)';
        if(!loadErrors.documents)PrismUI.statusPills($('studentDocumentPills'),[['','All'],['Pending Adviser Review','Pending review'],['Needs Revision','Needs revision'],['Returned for Revision','Returned by RPMS'],['Submitted to RPMS','Submitted to RPMS']],$('documentFilter'),documentHistory.counts||{});
        syncDocumentStatusMenu();
        const list = $('studentDocumentList');
        if (loadErrors.documents) {
            list.innerHTML = empty('Unable to load your documents. Please try again.');
            return;
        }
        list.innerHTML = shownDocs.length ? shownDocs.map(d => {
            if (d.preview) {
                const date = new Date(d.uploadedAt).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
                return `<article class="student-document-card pending student-document-preview-card"><div class="student-document-card-main"><div class="student-document-identity"><strong>${esc(d.originalName)} <small class="student-document-preview-label">UI preview · sample only</small></strong><span>${esc(d.documentType)} · ${esc(d.stageLabel)}</span><button type="button" class="student-document-details-toggle" aria-expanded="false"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i> Details &amp; remarks</button></div><div class="student-document-date"><span>Submitted</span><strong>${esc(date)}</strong></div><div class="student-document-state">${PrismUI.badge(d.workflowState,{small:true})}</div><div class="student-document-actions"><span class="action-btn" aria-disabled="true">Preview only</span></div></div><div class="student-document-details" hidden><div><span>Adviser remarks</span><strong>Sample feedback for layout preview.</strong></div><div><span>Version</span><strong>v1</strong></div><div><span>Document ID</span><strong>Sample only</strong></div></div></article>`;
            }
            const state = d.workflowState || d.reviewStatus || 'Submitted';
            const stateClass = /needs revision/i.test(state) ? 'needs' : /returned/i.test(state) ? 'returned' : /pending|review/i.test(state) ? 'pending' : /submitted to rpms|approved/i.test(state) ? 'submitted' : 'neutral';
            const remark = d.rpmsReturnReason ? `RPMS return reason: ${d.rpmsReturnReason}` : d.reviewRemarks || (state === 'Submitted to RPMS' ? 'Formally submitted to RPMS' : 'No reviewer remarks yet');
            const date = d.uploadedAt ? new Date(d.uploadedAt).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : 'Date unavailable';
            return `<article class="student-document-card ${stateClass}"><div class="student-document-card-main"><div class="student-document-identity"><strong>${esc(d.originalName)}${Number(d.versionNo || 1) > 1 ? ` <small>v${Number(d.versionNo)}</small>` : ''}</strong><span>${esc(d.documentType)} · ${esc(d.stageLabel || d.stage)}</span><button type="button" class="student-document-details-toggle" aria-expanded="false"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i> Details &amp; remarks</button></div><div class="student-document-date"><span>Submitted</span><strong>${esc(date)}</strong></div><div class="student-document-state">${PrismUI.badge(state,{small:true})}</div><div class="student-document-actions">${PrismUI.documentActionsHtml(d)}<div class="student-document-menu-wrap"><button type="button" class="action-btn student-document-menu-toggle" aria-label="More actions"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button><div class="student-document-menu"><button type="button" data-version-history="${esc(d.id)}"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Version history</button>${d.actions?.uploadRevision ? `<button type="button" data-upload-revision="${esc(d.id)}"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Upload revision</button>` : ''}</div></div></div></div><div class="student-document-details" hidden><div><span>Adviser remarks</span><strong>${esc(remark)}</strong></div><div><span>Version</span><strong>v${Number(d.versionNo || 1)}${Number(d.versionNo || 1)>1 ? ' · Earlier versions kept' : ''}</strong></div><div><span>Document ID</span><strong>${esc(d.id)}</strong></div>${d.rpmsSubmittedAt ? `<div><span>Submitted to RPMS</span><strong>${esc(fmt(d.rpmsSubmittedAt))}</strong></div>` : ''}</div></article>`;
        }).join('') : `<div class="student-documents-empty"><span><i class="fa-regular fa-file" aria-hidden="true"></i></span><strong>${filter ? 'No documents match this view' : "You haven't uploaded anything yet"}</strong><p>${filter ? 'Try a different status or clear your search.' : 'Upload your first requirement. Your adviser will review it, and the status will show here.'}</p></div>`;
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
        $('submissionResearchTitle').textContent = myRecord?.research || 'Not recorded';
        $('submissionResearchGroup').textContent = myRecord?.groupId || 'Not recorded';
        $('submissionStudentId').textContent = myRecord?.studentId || 'Not recorded';
        $('submissionStage').textContent = myRecord?.stageLabel || myRecord?.stage || 'Not recorded';
        $('submissionAdviser').textContent = myRecord?.adviser || 'Unassigned';
        $('submissionProtocol').textContent = myRecord?.protocolCode || 'No protocol code yet';
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
    const submissionDropzone = $('submissionDropzone');
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
                submissionDropzone.focus();
            });
            card.append(badge, meta, remove); fileSelection.append(card);
        });
        $('submissionTypeReady').classList.toggle('is-ready', hasType);
        $('submissionFileReady').classList.toggle('is-ready', hasFiles);
        $('submissionStepType').classList.toggle('is-done', hasType);
        $('submissionStepType').classList.toggle('is-active', !hasType);
        $('submissionStepFile').classList.toggle('is-done', hasFiles);
        $('submissionStepFile').classList.toggle('is-active', hasType && !hasFiles);
        $('submissionStepNotes').classList.toggle('is-active', hasType && hasFiles);
        submissionButton.disabled = !(hasType && hasFiles);

        const hint = $('submissionSubmitHint');
        if (invalid) {
            hint.textContent = invalid.size > maxUploadBytes ? `${invalid.name} is over the 20 MB file limit.` : `${invalid.name} is not a PDF or DOCX file.`;
            hint.classList.add('has-error');
            $('uploadResults').textContent = hint.textContent;
        } else {
            hint.classList.remove('has-error');
            hint.textContent = hasType && hasFiles ? 'Everything is ready. Submit your document for review.'
                : !hasType ? 'Choose a document type to continue.' : 'Upload a file to continue.';
        }

        const previous = myRecord && hasType ? myDocuments.filter(doc => doc.documentType === submissionType.value && doc.stage === myRecord.stage) : [];
        const latestVersion = previous.reduce((max, doc) => Math.max(max, Number(doc.versionNo) || 1), 0);
        const versionNote = $('submissionVersionNote');
        versionNote.hidden = !previous.length || Boolean(revisionDocument);
        if (!versionNote.hidden) versionNote.textContent = `You've already submitted this for the current stage. This upload will be saved as version ${latestVersion + 1}; earlier versions stay in your history.`;

        submissionDropzone.classList.toggle('has-file', hasFiles);
        submissionNotes && ($('submissionNotesCount').textContent = `${submissionNotes.value.length} / 500`);
    }

    function clearRevision() {
        submissionType.querySelectorAll('[data-legacy-revision]').forEach(option=>option.remove());
        revisionDocument=null; revisionNotice.hidden=true; cancelRevision.hidden=true;
        submissionType.disabled=false; submissionFile.multiple=true; updateSubmissionReadiness();
    }
    cancelRevision.addEventListener('click',clearRevision);
    submissionType.addEventListener('change', updateSubmissionReadiness);
    submissionFile.addEventListener('change', updateSubmissionReadiness);
    submissionNotes.addEventListener('input', updateSubmissionReadiness);
    submissionDropzone.addEventListener('click', () => submissionFile.click());
    submissionDropzone.addEventListener('dragover', event => { event.preventDefault(); submissionDropzone.classList.add('is-dragging'); });
    submissionDropzone.addEventListener('dragleave', () => submissionDropzone.classList.remove('is-dragging'));
    submissionDropzone.addEventListener('drop', event => {
        event.preventDefault(); submissionDropzone.classList.remove('is-dragging');
        if (!event.dataTransfer?.files?.length) return;
        submissionFile.files = event.dataTransfer.files;
        submissionFile.dispatchEvent(new Event('change', { bubbles: true }));
    });
    document.addEventListener('prism:upload-revision',event=>{
        const doc=event.detail; if(!doc?.actions?.uploadRevision)return;
        submissionType.querySelectorAll('[data-legacy-revision]').forEach(option=>option.remove());
        if(![...submissionType.options].some(option=>option.value===doc.documentType)){const option=new Option(doc.documentType,doc.documentType);option.dataset.legacyRevision='1';submissionType.add(option);}
        revisionDocument=doc; $('documentType').value=doc.documentType; $('documentType').disabled=true; $('documentFile').multiple=false;
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
    $('documentFilter').addEventListener('change', () => {syncDocumentStatusMenu();docPager.reset();refreshDocuments();});
    const documentSearchReload=PrismUI.debounce(()=>{docPager.reset();refreshDocuments();});
    documentSearch.addEventListener('input',documentSearchReload);
    documentSort.addEventListener('change',()=>{docPager.reset();refreshDocuments();});
    document.addEventListener('click', e => {
        const statusToggle = e.target.closest('#studentDocumentStatusToggle');
        if (statusToggle) {
            const menu = $('studentDocumentStatusMenu');
            const open = menu.hidden;
            menu.hidden = !open;
            statusToggle.setAttribute('aria-expanded', String(open));
            return;
        }
        const statusOption = e.target.closest('[data-document-status-option]');
        if (statusOption) {
            $('documentFilter').value = statusOption.dataset.documentStatusOption;
            $('documentFilter').dispatchEvent(new Event('change', { bubbles: true }));
            $('studentDocumentStatusMenu').hidden = true;
            $('studentDocumentStatusToggle').setAttribute('aria-expanded', 'false');
            $('studentDocumentStatusToggle').focus();
            return;
        }
        if (!e.target.closest('.student-document-status-select')) {
            $('studentDocumentStatusMenu').hidden = true;
            $('studentDocumentStatusToggle').setAttribute('aria-expanded', 'false');
        }
        const dismissClear = e.target.closest('[data-dismiss-clear]');
        if (dismissClear) { dismissClear.closest('.student-document-clear')?.remove(); return; }
        const goButton = e.target.closest('[data-go]');
        if (goButton && !goButton.hasAttribute('data-page')) { e.preventDefault(); go(goButton.dataset.go); return; }
        const howToggle = e.target.closest('#studentDocumentsHowToggle');
        if (howToggle) {
            const how = $('studentDocumentsHow');
            const collapsed = how.classList.toggle('is-collapsed');
            howToggle.textContent = collapsed ? 'Show' : 'Hide';
            howToggle.setAttribute('aria-expanded', String(!collapsed));
            return;
        }
        const detailToggle = e.target.closest('.student-document-details-toggle');
        if (detailToggle) {
            const card = detailToggle.closest('.student-document-card');
            const details = card.querySelector('.student-document-details');
            details.hidden = !details.hidden;
            detailToggle.setAttribute('aria-expanded', String(!details.hidden));
            detailToggle.classList.toggle('is-open', !details.hidden);
            return;
        }
        const menuToggle = e.target.closest('.student-document-menu-toggle');
        if (menuToggle) {
            const menu = menuToggle.nextElementSibling;
            document.querySelectorAll('.student-document-menu.is-open').forEach(item => { if (item !== menu) item.classList.remove('is-open'); });
            menu.classList.toggle('is-open');
            return;
        }
        if (!e.target.closest('.student-document-menu-wrap')) document.querySelectorAll('.student-document-menu.is-open').forEach(item => item.classList.remove('is-open'));
        if (e.target.closest('#copyProtocolCode')) {
            const code = $('protocolCodeValue').textContent;
            if (code && navigator.clipboard) navigator.clipboard.writeText(code).then(() => toast('Protocol code copied'));
            return;
        }
        const history=e.target.closest('[data-version-history]'); if(history){PrismUI.showVersions(history.dataset.versionHistory);return;}
        const button = e.target.closest('[data-upload-revision]');
        if(!button)return;
        const doc = [...documentHistory.records,...myDocuments].find(d=>String(d.id)===button.dataset.uploadRevision);
        if(doc)PrismUI.uploadRevision(doc);
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !$('studentDocumentStatusMenu').hidden) {
            $('studentDocumentStatusMenu').hidden = true;
            $('studentDocumentStatusToggle').setAttribute('aria-expanded', 'false');
            $('studentDocumentStatusToggle').focus();
        }
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

    $('themeToggle').addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark-theme');
        try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
    });

    $('dashboardCurrentDate').textContent = new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

    (async function init() {
        await refreshAll();
        await fillProfile();
        const initial = window.location.hash.replace('#', '');
        go(PAGES[initial] ? initial : 'dashboard');
    })();
});
