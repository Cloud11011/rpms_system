document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const STAGES = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
    const stageLabels = window.PRISM_STAGE_LABELS || {};
    const labelForStage = stageKey => stageLabels[stageKey] || stageKey;
    const $ = id => document.getElementById(id);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = v => v ? new Date(v).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : 'N/A';

    let myRecord = null;   // this student's IERB record (or null if none yet)
    let myDocuments = [];
    let myHistory = [];
    let myNotifications = [];

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
        document.querySelectorAll('#portalNav li').forEach(x => x.classList.toggle('active', x.querySelector('button').dataset.page === active));
        $('pageTitle').textContent = meta[0];
        $('pageSubtitle').textContent = meta[1];
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ------------------------------------------------------------------
    // Data loading
    // ------------------------------------------------------------------
    async function loadProgress() {
        try {
            const res = await fetch('ierb_api.php?action=list');
            const data = await res.json();
            const records = data.ok ? data.records : [];
            myRecord = records[0] || null;
            if (myRecord) {
                const hist = await fetch(`ierb_api.php?action=history&studentId=${encodeURIComponent(myRecord.id)}`);
                const histData = await hist.json();
                myHistory = histData.ok ? histData.history : [];
            } else {
                myHistory = [];
            }
        } catch (_) {
            myRecord = null;
            myHistory = [];
        }
    }

    async function loadDocuments() {
        try {
            const res = await fetch('documents_api.php?action=list');
            const data = await res.json();
            myDocuments = data.ok ? data.documents : [];
        } catch (_) {
            myDocuments = [];
        }
    }

    async function loadNotifications() {
        try {
            const res = await fetch('notifications_api.php?action=list');
            const data = await res.json();
            myNotifications = data.ok ? data.notifications : [];
        } catch (_) {
            myNotifications = [];
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
        const progress = myRecord ? myRecord.progress : 0;
        const status = myRecord ? myRecord.status : 'No record yet';
        const stageText = myRecord ? `Stage ${stageIndex} of ${STAGES.length} — ${labelForStage(myRecord.stage)}` : 'No IERB record yet';
        const pendingCount = myRecord && myRecord.requirements && myRecord.requirements !== 'None' ? 1 : 0;
        const latestDoc = myDocuments[0];

        $('welcomeName').textContent = body.dataset.name;
        $('researchTitle').textContent = myRecord?.research || 'No research details yet.';
        $('dashboardStageValue').textContent = stageText;
        $('dashboardProgressPercent').textContent = `${progress}%`;
        $('dashboardProgressBar').style.width = `${progress}%`;
        $('dashboardProgressTrack').setAttribute('aria-valuenow', progress);
        $('dashboardStatusValue').textContent = status;
        $('dashboardPendingValue').textContent = pendingCount;
        $('dashboardSubmissionValue').textContent = `${myDocuments.length} ${myDocuments.length === 1 ? 'document' : 'documents'}`;
        $('dashboardSubmissionStatus').textContent = latestDoc ? `Latest: ${latestDoc.reviewStatus}` : 'No submissions yet';

        const unread = myNotifications.filter(n => !n.read_at).length;
        $('navBadge').textContent = unread;
        $('navBadge').hidden = !unread;

        $('currentStage').textContent = myRecord ? `Stage ${stageIndex} — ${labelForStage(myRecord.stage)}` : 'No record yet';
        $('progressRing').style.background = `conic-gradient(var(--accent-pink) ${progress}%, var(--bg-color) 0)`;
        $('progressRing').innerHTML = `<b>${progress}%</b>`;

        $('stageList').innerHTML = STAGES.map((s, i) => {
            const cls = i + 1 < stageIndex ? 'complete' : i + 1 === stageIndex ? 'current' : '';
            const icon = i + 1 < stageIndex ? 'fa-circle-check' : 'fa-circle';
            return `<div class="stage-card ${cls}"><i class="fa-solid ${icon}"></i><strong>Stage ${i + 1}</strong><span>${esc(labelForStage(s))}</span></div>`;
        }).join('');

        $('completedRequirements').innerHTML = myRecord && stageIndex > 1
            ? `<div class="list-item"><i class="fa-solid fa-circle-check"></i><div><strong>Stages 1–${stageIndex - 1}</strong><span>Completed</span></div></div>`
            : empty('No completed requirements yet.');
        $('pendingRequirements').innerHTML = myRecord && myRecord.requirements && myRecord.requirements !== 'None'
            ? `<div class="list-item"><i class="fa-regular fa-clock"></i><div><strong>${esc(myRecord.requirements)}</strong><span>Pending</span></div></div>`
            : empty('No pending requirements.');

        $('progressHistory').innerHTML = myHistory.length
            ? myHistory.map(h => `<div class="list-item"><i class="fa-solid fa-clock-rotate-left"></i><div><strong>${esc(h.stage)} — ${esc(h.status)}</strong><span>${esc(h.note || 'Updated by RPMS')} &bull; ${fmt(h.created_at)} &bull; ${esc(h.actor || 'System')}</span></div></div>`).join('')
            : empty('No progress history yet.');

        $('recentSubmissions').innerHTML = myDocuments.slice(0, 4).map(docItem).join('') || empty('No submissions yet.');

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
        return `<div class="list-item"><i class="fa-solid fa-file-lines"></i><div><strong>${esc(d.documentType)}</strong><span>${esc(d.originalName)} &middot; ${fmt(d.uploadedAt)} &middot; ${esc(d.reviewStatus)}</span></div></div>`;
    }

    function renderDocs() {
        const filter = $('documentFilter').value;
        const docs = myDocuments.filter(d => !filter || d.reviewStatus === filter);
        $('documentRows').innerHTML = docs.map(d => `<tr>
            <td><strong>${esc(d.originalName)}</strong></td>
            <td>${esc(d.documentType)}</td>
            <td>${fmt(d.uploadedAt)}</td>
            <td><span class="status-chip ${String(d.reviewStatus).toLowerCase().replaceAll(' ', '-')}">${esc(d.reviewStatus)}</span></td>
            <td>${esc(d.reviewRemarks || 'Awaiting reviewer remarks')}</td>
            <td><a class="action-btn" href="documents_api.php?action=file&id=${encodeURIComponent(d.id)}" target="_blank">Preview</a>
                <a class="action-btn" href="documents_api.php?action=file&download=1&id=${encodeURIComponent(d.id)}">Download</a></td>
        </tr>`).join('') || `<tr><td colspan="6">${empty('No documents match this view.')}</td></tr>`;
    }

    function noticeItem(n) {
        const unread = !n.read_at;
        return `<div class="list-item notice ${unread ? 'unread' : ''}"><i class="fa-solid fa-bell"></i><div><strong>${esc(n.type)}</strong><span>${esc(n.message)}</span><time>${fmt(n.created_at)}</time></div>${unread ? `<button data-read="${n.id}" title="Mark read"><i class="fa-solid fa-check"></i></button>` : ''}</div>`;
    }

    function renderNotifications() {
        $('notificationList').innerHTML = myNotifications.map(noticeItem).join('') || empty('You have no notifications.');
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
        await Promise.all([loadProgress(), loadDocuments(), loadNotifications()]);
        render();
    }

    // ------------------------------------------------------------------
    // Navigation
    // ------------------------------------------------------------------
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
            const res = await fetch('documents_api.php?action=upload', { method: 'POST', body: formData });
            const data = await res.json();
            if (!data.ok) { toast(data.message || 'The document could not be submitted.'); return; }
            event.target.reset();
            await refreshAll();
            go('documents');
            toast('Document submitted successfully.');
        } catch (_) {
            toast('Could not reach the server to submit this document.');
        } finally {
            submitBtn.disabled = false;
        }
    });

    $('documentFilter').addEventListener('change', renderDocs);

    $('notificationList').addEventListener('click', async e => {
        const btn = e.target.closest('[data-read]');
        if (!btn) return;
        try {
            await fetch('notifications_api.php?action=mark_read', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(btn.dataset.read) }),
            });
            await loadNotifications();
            render();
        } catch (_) { /* ignore */ }
    });

    $('markAllRead').addEventListener('click', async () => {
        try {
            await fetch('notifications_api.php?action=mark_all_read', { method: 'POST' });
            await loadNotifications();
            render();
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
        go('dashboard');
    })();
});
