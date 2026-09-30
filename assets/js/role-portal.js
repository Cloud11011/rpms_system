document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const STAGES = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
    const stageLabels = window.PRISM_STAGE_LABELS || {};
    const labelForStage = stageKey => stageLabels[stageKey] || stageKey;
    const $ = id => document.getElementById(id);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = value => {
        const date = value ? new Date(value) : null;
        return date && Number.isFinite(date.getTime())
            ? date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : 'N/A';
    };

    let myRecord = null;   // this student's IERB record (or null if none yet)
    let myDocuments = [];
    let myHistory = [];
    let myNotifications = [];
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
        progress: ['IERB Progress', 'View official stages, requirements, deadlines, and remarks.'],
        submit: ['Document Submission', 'Upload requirements for RPMS/IERB review.'],
        documents: ['My Documents', 'View and manage your submission history.'],
        notifications: ['Notifications', 'Stay updated on reviews, reminders, and follow-ups.'],
        calendar: ['Calendar', 'Manage personal reminders stored in this browser.'],
        profile: ['Profile', 'Manage your permitted personal and account information.'],
    };

    function go(page) {
        const active = PAGES[page] ? page : 'dashboard';
        const meta = PAGES[active];
        document.querySelectorAll('.portal-page').forEach(x => x.classList.toggle('active', x.dataset.section === active));
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
        if (window.location.hash !== '#' + active) history.replaceState(null, '', '#' + active);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ------------------------------------------------------------------
    // Data loading
    // ------------------------------------------------------------------
    async function readList(url, key) {
        const response = await fetch(url);
        const data = await response.json();
        if (!response.ok || !data.ok || !Array.isArray(data[key])) throw new Error('Unable to load workspace data.');
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
            const history = await readList(`ierb_api.php?action=history&studentId=${encodeURIComponent(record.id)}`, 'history');
            return { record, history, error: false, historyError: false };
        } catch (_) {
            return { record, history: [], error: false, historyError: true };
        }
    }

    async function loadDocuments() {
        try {
            return { records: await readList('documents_api.php?action=list', 'documents'), error: false };
        } catch (_) {
            return { records: [], error: true };
        }
    }

    async function loadNotifications() {
        try {
            return { records: await readList('notifications_api.php?action=list', 'notifications'), error: false };
        } catch (_) {
            return { records: [], error: true };
        }
    }

    async function loadProfile() {
        try {
            const res = await fetch('profile_api.php?action=me');
            const data = await res.json();
            return data.ok ? data.user : null;
        } catch (_) {
            return null;
        }
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
        $('dashboardStageValue').textContent = stageText;
        $('dashboardProgressPercent').textContent = loadErrors.progress ? 'Unavailable' : `${progress}%`;
        $('dashboardProgressBar').style.width = loadErrors.progress ? '0%' : `${progress}%`;
        if (loadErrors.progress) {
            $('dashboardProgressTrack').removeAttribute('aria-valuenow');
            $('dashboardProgressTrack').setAttribute('aria-valuetext', 'Unavailable');
        } else {
            $('dashboardProgressTrack').setAttribute('aria-valuenow', progress);
            $('dashboardProgressTrack').removeAttribute('aria-valuetext');
        }
        $('dashboardStatusValue').textContent = status;
        $('dashboardPendingValue').textContent = loadErrors.progress ? 'Unavailable' : pendingCount;
        $('dashboardSubmissionValue').textContent = loadErrors.documents ? 'Unavailable' : `${myDocuments.length} ${myDocuments.length === 1 ? 'document' : 'documents'}`;
        $('dashboardSubmissionStatus').textContent = loadErrors.documents ? 'Document status could not be loaded.' : latestDoc ? `Latest: ${latestDoc.workflowState || latestDoc.reviewStatus}` : 'Upload a requirement when you are ready.';
        $('studentCurrentDocument').textContent = loadErrors.documents ? 'Unable to load your latest submission.' : latestDoc?.originalName || 'No submissions yet.';
        $('studentReviewState').textContent = loadErrors.documents ? 'Unavailable' : latestDoc?.reviewStatus || 'No review yet';
        $('studentReviewRemarks').textContent = loadErrors.documents ? 'Reviewer remarks could not be loaded.' : latestDoc?.reviewRemarks || 'No reviewer remarks yet.';

        const unread = myNotifications.filter(n => !n.read_at).length;
        $('navBadge').textContent = loadErrors.notifications ? '!' : unread;
        $('navBadge').hidden = !loadErrors.notifications && !unread;
        $('navBadge').title = loadErrors.notifications ? 'Notifications unavailable' : `${unread} unread notifications`;

        $('currentStage').textContent = loadErrors.progress ? 'Unavailable' : myRecord ? labelForStage(myRecord.stage) : 'No record yet';
        $('progressRing').style.background = `conic-gradient(var(--accent-pink) ${progress}%, var(--bg-color) 0)`;
        const progressText = document.createElement('b');
        progressText.textContent = loadErrors.progress ? 'N/A' : `${progress}%`;
        $('progressRing').replaceChildren(progressText);

        $('stageList').innerHTML = loadErrors.progress ? empty('Unable to load your IERB progress. Please try again.') : STAGES.map((s, i) => {
            const cls = i + 1 < stageIndex ? 'complete' : i + 1 === stageIndex ? 'current' : '';
            const icon = i + 1 < stageIndex ? 'fa-circle-check' : 'fa-circle';
            return `<div class="stage-card ${cls}"><i class="fa-solid ${icon}"></i><strong>Stage ${i + 1}</strong><span>${esc(labelForStage(s))}</span></div>`;
        }).join('');

        $('completedRequirements').innerHTML = loadErrors.progress ? empty('Completed requirements are unavailable.') : myRecord && stageIndex > 1
            ? `<div class="list-item"><i class="fa-solid fa-circle-check"></i><div><strong>Stages 1-${stageIndex - 1}</strong><span>Completed</span></div></div>`
            : empty('No completed requirements yet.');
        $('pendingRequirements').innerHTML = loadErrors.progress ? empty('Pending requirements are unavailable.') : myRecord && myRecord.requirements && myRecord.requirements !== 'None'
            ? `<div class="list-item"><i class="fa-regular fa-clock"></i><div><strong>${esc(myRecord.requirements)}</strong><span>Pending</span></div></div>`
            : empty('No pending requirements.');

        $('progressHistory').innerHTML = loadErrors.progress || loadErrors.history ? empty('Unable to load progress history. Please try again.') : myHistory.length
            ? myHistory.map(h => `<div class="list-item"><i class="fa-solid fa-clock-rotate-left"></i><div><strong>${esc(h.stage)} - ${esc(h.status)}</strong><span>${esc(h.note || 'Updated by RPMS')} &bull; ${esc(fmt(h.created_at))} &bull; ${esc(h.actor || 'System')}</span></div></div>`).join('')
            : empty('No progress history yet.');

        $('recentSubmissions').innerHTML = loadErrors.documents ? empty('Unable to load recent submissions. Please try again.') : myDocuments.slice(0, 4).map(docItem).join('') || empty('No submissions yet.');
        $('studentRecentNotifications').innerHTML = loadErrors.notifications ? empty('Unable to load notifications. Please try again.') : myNotifications.slice(0, 3).map(n => noticeItem(n, false)).join('') || empty('You have no notifications.');

        // Principal Investigator indicator + Protocol Code (feature requests
        // 10-11). Kept intentionally simple: a small badge that reveals the
        // code on click, plus a prominent card shown ahead of the raw file
        // list once a protocol code has actually been assigned.
        const piIndicator = $('principalIndicator');
        const protocolReveal = $('protocolCodeReveal');
        if (myRecord && myRecord.isPrincipalInvestigator) {
            piIndicator.hidden = false;
        } else {
            piIndicator.hidden = true;
            protocolReveal.hidden = true;
        }

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

    function renderDocs() {
        const filter = $('documentFilter').value;
        const docs = myDocuments.filter(d => !filter || d.reviewStatus === filter || d.workflowState === filter);
        $('documentRows').innerHTML = loadErrors.documents ? `<tr><td colspan="6">${empty('Unable to load your documents. Please try again.')}</td></tr>` : docs.map(d => `<tr>
            <td><strong>${esc(d.originalName)}</strong>${Number(d.versionNo || 1) > 1 ? `<small> v${Number(d.versionNo)}</small>` : ''}</td>
            <td>${esc(d.documentType)}</td>
            <td>${fmt(d.uploadedAt)}</td>
            <td>${PrismUI.badge(d.workflowState || d.reviewStatus, { small: true })}</td>
            <td>${esc(d.reviewRemarks || (d.workflowState === 'Submitted to RPMS' ? 'Formally submitted to RPMS' : 'No reviewer remarks yet'))}</td>
            <td><a class="action-btn" href="documents_api.php?action=file&id=${encodeURIComponent(d.id)}" target="_blank" rel="noopener">Preview</a>
                <a class="action-btn" href="documents_api.php?action=file&download=1&id=${encodeURIComponent(d.id)}">Download</a></td>
        </tr>`).join('') || `<tr><td colspan="6">${empty('No documents match this view.')}</td></tr>`;
    }

    function noticeItem(n, includeAction = true) {
        const unread = !n.read_at;
        return `<div class="list-item notice ${unread ? 'unread' : ''}"><i class="fa-solid fa-bell"></i><div><strong>${esc(n.type)}</strong><span>${esc(n.message)}</span><time>${fmt(n.created_at)}</time></div>${unread && includeAction ? `<button data-read="${esc(n.id)}" title="Mark read"><i class="fa-solid fa-check"></i></button>` : ''}</div>`;
    }

    function renderNotifications() {
        $('notificationList').innerHTML = loadErrors.notifications ? empty('Unable to load notifications. Please try again.') : myNotifications.map(n => noticeItem(n)).join('') || empty('You have no notifications.');
        $('markAllRead').disabled = loadErrors.notifications || !myNotifications.some(n => !n.read_at);
    }

    async function fillProfile() {
        const profile = await loadProfile();
        if (!profile) return;
        $('profileName').value = profile.name || '';
        $('profileEmail').value = profile.email || '';
        $('profileRole').value = profile.role || '';
        $('profileId').value = profile.refId || '';
        $('submissionResearchTitle').value = myRecord?.research || '';
        $('submissionResearchGroup').value = myRecord?.groupId || '';
    }

    async function refreshAll() {
        const sequence = ++refreshSequence;
        $('studentDashboardState').setAttribute('aria-busy', 'true');
        $('studentDashboardState').textContent = 'Loading your workspace...';
        const [progress, documents, notifications] = await Promise.all([loadProgress(), loadDocuments(), loadNotifications()]);
        if (sequence !== refreshSequence) return;
        myRecord = progress.record;
        myHistory = progress.history;
        myDocuments = documents.records;
        myNotifications = notifications.records;
        loadErrors = { progress: progress.error, history: progress.historyError, documents: documents.error, notifications: notifications.error };
        render();
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

    document.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => go(b.dataset.go)));
    document.querySelectorAll('#portalNav button').forEach(b => b.addEventListener('click', () => go(b.dataset.page)));

    $('principalIndicator').addEventListener('click', () => {
        const reveal = $('protocolCodeReveal');
        if (reveal.hidden) {
            reveal.textContent = myRecord?.protocolCode
                ? `Protocol Code: ${myRecord.protocolCode}`
                : 'Protocol Code has not been assigned yet.';
        }
        reveal.hidden = !reveal.hidden;
    });

    // ------------------------------------------------------------------
    // Document submission
    // ------------------------------------------------------------------
    $('submissionForm').addEventListener('submit', async event => {
        event.preventDefault();
        const file = $('documentFile').files[0];
        if (!file) { toast('Please choose a file.'); return; }
        if (file.size > 20 * 1024 * 1024) { toast('File must be 20 MB or smaller.'); return; }

        const formData = new FormData();
        formData.append('document', file);
        formData.append('documentType', $('documentType').value);
        formData.append('stage', myRecord?.stage || 'Stage 1');
        const notesParts = [];
        if ($('submissionResearchTitle').value.trim()) notesParts.push(`Research title: ${$('submissionResearchTitle').value.trim()}`);
        if ($('submissionResearchGroup').value.trim()) notesParts.push(`Group: ${$('submissionResearchGroup').value.trim()}`);
        if ($('documentNotes').value.trim()) notesParts.push($('documentNotes').value.trim());
        formData.append('notes', notesParts.join(' | '));

        const submitBtn = event.target.querySelector('[type="submit"]');
        submitBtn.disabled = true;
        try {
            const data = await PrismUI.request('documents_api.php?action=upload', { method: 'POST', body: formData });
            event.target.reset();
            await refreshAll();
            go('documents');
            toast(data.message || 'Document submitted successfully.');
        } catch (e) {
            toast(e.message || 'Could not reach the server to submit this document.');
        } finally {
            submitBtn.disabled = false;
        }
    });

    $('documentFilter').addEventListener('change', renderDocs);

    $('notificationList').addEventListener('click', async e => {
        const btn = e.target.closest('[data-read]');
        if (!btn) return;
        try {
            const response = await fetch('notifications_api.php?action=mark_read', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(btn.dataset.read) }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error('Unable to mark this notification as read.');
            await refreshAll();
        } catch (_) { toast('Unable to mark this notification as read.'); }
    });

    $('markAllRead').addEventListener('click', async () => {
        try {
            const response = await fetch('notifications_api.php?action=mark_all_read', { method: 'POST' });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error('Unable to mark notifications as read.');
            await refreshAll();
            toast('All notifications marked as read.');
        } catch (_) {
            toast('Could not reach the server.');
        }
    });

    // ------------------------------------------------------------------
    // Profile
    // ------------------------------------------------------------------
    $('profileForm').addEventListener('submit', async event => {
        event.preventDefault();
        try {
            const res = await fetch('profile_api.php?action=update_profile', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: $('profileName').value.trim() }),
            });
            const data = await res.json();
            if (!data.ok) { toast(data.message || 'Profile could not be updated.'); return; }
            $('sideName').textContent = $('profileName').value.trim();
            $('welcomeName').textContent = $('profileName').value.trim();
            toast('Profile updated.');
        } catch (_) {
            toast('Could not reach the server to update your profile.');
        }
    });

    $('passwordForm').addEventListener('submit', async event => {
        event.preventDefault();
        if ($('newPassword').value !== $('confirmPassword').value) { toast('New passwords do not match.'); return; }
        try {
            const res = await fetch('profile_api.php?action=change_password', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ currentPassword: $('currentPassword').value, newPassword: $('newPassword').value }),
            });
            const data = await res.json();
            if (!data.ok) { toast(data.message || 'Password could not be changed.'); return; }
            event.target.reset();
            toast('Password changed successfully.');
        } catch (_) {
            toast('Could not reach the server to change your password.');
        }
    });

    // Profile photo is a local-only preview in this build (not persisted server-side).
    $('profileImageInput').addEventListener('change', () => {
        const file = $('profileImageInput').files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { toast('Choose a JPG, PNG, or WebP image.'); return; }
        const reader = new FileReader();
        reader.onload = () => {
            $('profileImagePreview').src = reader.result;
            $('navProfileImage').src = reader.result;
        };
        reader.readAsDataURL(file);
    });
    $('removeProfileImage').addEventListener('click', () => {
        $('profileImageInput').value = '';
        $('profileImagePreview').src = 'assets/images/default-avatar.svg';
        $('navProfileImage').src = 'assets/images/default-avatar.svg';
    });

    // ------------------------------------------------------------------
    // Help / support (kept lightweight: emails the RPMS office directly)
    // ------------------------------------------------------------------
    const supportModal = $('supportModal');
    const closeSupport = () => { supportModal.hidden = true; $('supportForm').reset(); };
    $('helpButton').addEventListener('click', () => { supportModal.hidden = false; $('supportSubject').focus(); });
    $('closeSupportModal').addEventListener('click', closeSupport);
    $('cancelSupport').addEventListener('click', closeSupport);
    supportModal.addEventListener('click', e => { if (e.target === supportModal) closeSupport(); });
    $('supportForm').addEventListener('submit', event => {
        event.preventDefault();
        const subject = encodeURIComponent(`PRISM Support: ${$('supportSubject').value.trim()}`);
        const bodyText = encodeURIComponent(`${$('supportType').value}\n\n${$('supportMessage').value.trim()}\n\nFrom: ${body.dataset.name} (${body.dataset.email})`);
        window.location.href = `mailto:rpms@ceu.edu.ph?subject=${subject}&body=${bodyText}`;
        closeSupport();
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
