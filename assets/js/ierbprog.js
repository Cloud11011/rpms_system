(function () {
    'use strict';

    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    if (profileToggle && profileMenu) {
        profileToggle.addEventListener('click', e => { e.stopPropagation(); profileMenu.classList.toggle('show'); });
        document.addEventListener('click', () => profileMenu.classList.remove('show'));
    }

    const isAdmin = document.body.dataset.role === 'admin';
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);

    const stageChart = document.getElementById('stageChart');
    const overviewTotal = document.getElementById('overviewTotal');
    const ierbSearch = document.getElementById('ierbSearch');
    const stageFilter = document.getElementById('stageFilter');
    const statusFilter = document.getElementById('ierbStatusFilter');
    const recordCount = document.getElementById('ierbRecordCount');
    const tableBody = document.getElementById('ierbTableBody');
    const addBtn = document.getElementById('addIerbEntry');

    if (!isAdmin && addBtn) addBtn.style.display = 'none';

    let records = [];
    const STAGES = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];

    async function loadRecords() {
        try {
            const res = await fetch('ierb_api.php?action=list');
            const data = await res.json();
            records = data.ok ? data.records : [];
        } catch (_) {
            records = [];
        }
        renderOverview();
        renderTable();
    }

    function renderOverview() {
        overviewTotal.textContent = `${records.length} student${records.length === 1 ? '' : 's'}`;
        stageChart.replaceChildren();
        const max = Math.max(1, ...STAGES.map(s => records.filter(r => r.stage === s).length));
        STAGES.forEach(stage => {
            const count = records.filter(r => r.stage === stage).length;
            const col = document.createElement('div');
            col.className = 'stage-bar-col';
            col.innerHTML = `<div class="stage-bar" style="height:${Math.max(4, (count / max) * 100)}%"><span>${count}</span></div><small>${escapeHtml(stage)}</small>`;
            stageChart.appendChild(col);
        });
    }

    function filteredRecords() {
        const term = (ierbSearch.value || '').trim().toLowerCase();
        const stage = stageFilter.value;
        const status = statusFilter.value;
        return records.filter(r => {
            if (stage && r.stage !== stage) return false;
            if (status && r.status !== status) return false;
            if (!term) return true;
            return [r.name, r.studentId, r.groupId, r.research].some(v => String(v ?? '').toLowerCase().includes(term));
        });
    }

    function renderTable() {
        const rows = filteredRecords();
        recordCount.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'}`;
        tableBody.replaceChildren();

        if (!rows.length) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="7" class="empty-state">No IERB records match your filters.</td>';
            tableBody.appendChild(tr);
            return;
        }

        rows.forEach(record => {
            const tr = document.createElement('tr');
            const statusClass = String(record.status || 'Pending').toLowerCase().replace(/\s+/g, '-');
            tr.innerHTML = `
                <td><strong>${escapeHtml(record.name)}</strong><br><small>${escapeHtml(record.studentId)}</small></td>
                <td><span class="stage-tag">${escapeHtml(record.stage)}</span></td>
                <td>${escapeHtml(record.progress)}%</td>
                <td>${escapeHtml(record.requirements || 'None')}</td>
                <td>${escapeHtml(record.lastSubmissionDate || 'N/A')}</td>
                <td><span class="status-badge ${statusClass}">${escapeHtml(record.status)}</span></td>
                <td class="row-actions"></td>`;
            const actions = tr.querySelector('.row-actions');

            const noteBtn = document.createElement('button');
            noteBtn.className = 'icon-btn';
            noteBtn.title = 'Add note';
            noteBtn.innerHTML = '<i class="fa-solid fa-note-sticky"></i>';
            noteBtn.addEventListener('click', () => openNoteModal(record));
            actions.appendChild(noteBtn);

            const remindBtn = document.createElement('button');
            remindBtn.className = 'icon-btn';
            remindBtn.title = 'Send follow-up email';
            remindBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
            remindBtn.addEventListener('click', () => sendFollowup(record));
            actions.appendChild(remindBtn);

            if (isAdmin) {
                const editBtn = document.createElement('button');
                editBtn.className = 'icon-btn';
                editBtn.title = 'Edit entry';
                editBtn.innerHTML = '<i class="fa-solid fa-pen"></i>';
                editBtn.addEventListener('click', () => openEntryModal(record));
                actions.appendChild(editBtn);

                const delBtn = document.createElement('button');
                delBtn.className = 'icon-btn';
                delBtn.title = 'Delete entry';
                delBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
                delBtn.addEventListener('click', () => deleteEntry(record));
                actions.appendChild(delBtn);
            }

            tableBody.appendChild(tr);
        });
    }

    ierbSearch.addEventListener('input', renderTable);
    stageFilter.addEventListener('change', renderTable);
    statusFilter.addEventListener('change', renderTable);

    // --- Entry add/edit modal ---
    const entryModal = document.getElementById('ierbEntryModal');
    const entryForm = document.getElementById('ierbEntryForm');
    const entryTitle = document.getElementById('ierbEntryTitle');
    let editingId = null;

    function openEntryModal(record) {
        entryForm.reset();
        editingId = record ? record.id : null;
        entryTitle.textContent = record ? 'Edit IERB Entry' : 'Add IERB Entry';
        if (record) {
            document.getElementById('entryStudentName').value = record.name || '';
            document.getElementById('entryStudentId').value = record.studentId || '';
            document.getElementById('entryEmail').value = record.email || '';
            document.getElementById('entryGroupId').value = record.groupId || '';
            document.getElementById('entryCourse').value = record.course || '';
            document.getElementById('entryStage').value = record.stage || 'Stage 1';
            document.getElementById('entryResearchTitle').value = record.research || '';
            document.getElementById('entryRequirements').value = record.requirements || '';
            document.getElementById('entrySubmissionDate').value = record.lastSubmissionDate || '';
            document.getElementById('entryStatus').value = record.status || 'On Track';
        }
        entryModal.setAttribute('aria-hidden', 'false');
        entryModal.style.display = 'flex';
    }
    function closeEntryModal() {
        entryModal.setAttribute('aria-hidden', 'true');
        entryModal.style.display = 'none';
    }
    document.getElementById('addIerbEntry').addEventListener('click', () => openEntryModal(null));
    document.getElementById('closeIerbEntry').addEventListener('click', closeEntryModal);
    document.getElementById('cancelIerbEntry').addEventListener('click', closeEntryModal);
    entryModal.addEventListener('click', e => { if (e.target === entryModal) closeEntryModal(); });

    entryForm.addEventListener('submit', async event => {
        event.preventDefault();
        const payload = {
            id: editingId,
            name: document.getElementById('entryStudentName').value.trim(),
            studentId: document.getElementById('entryStudentId').value.trim(),
            email: document.getElementById('entryEmail').value.trim(),
            groupId: document.getElementById('entryGroupId').value.trim(),
            course: document.getElementById('entryCourse').value.trim(),
            stage: document.getElementById('entryStage').value,
            research: document.getElementById('entryResearchTitle').value.trim(),
            requirements: document.getElementById('entryRequirements').value.trim(),
            submissionDate: document.getElementById('entrySubmissionDate').value,
            status: document.getElementById('entryStatus').value,
        };
        try {
            const res = await fetch('ierb_api.php?action=save', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (!data.ok) { alert(data.message || 'This entry could not be saved.'); return; }
            closeEntryModal();
            await loadRecords();
        } catch (_) {
            alert('Could not reach the server to save this entry.');
        }
    });

    async function deleteEntry(record) {
        if (!confirm(`Delete the IERB record for ${record.name}?`)) return;
        try {
            const res = await fetch('ierb_api.php?action=delete', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: record.id }),
            });
            const data = await res.json();
            if (!data.ok) { alert(data.message || 'Could not delete this record.'); return; }
            await loadRecords();
        } catch (_) {
            alert('Could not reach the server to delete this record.');
        }
    }

    // --- Note modal ---
    const actionModal = document.getElementById('ierbActionModal');
    const actionForm = document.getElementById('ierbActionForm');
    const actionText = document.getElementById('ierbActionText');
    let noteTargetId = null;

    function openNoteModal(record) {
        noteTargetId = record.id;
        actionText.value = '';
        document.getElementById('ierbActionTitle').textContent = `Add Note - ${record.name}`;
        actionModal.setAttribute('aria-hidden', 'false');
        actionModal.style.display = 'flex';
        actionText.focus();
    }
    function closeActionModal() {
        actionModal.setAttribute('aria-hidden', 'true');
        actionModal.style.display = 'none';
    }
    document.getElementById('closeIerbModal').addEventListener('click', closeActionModal);
    document.getElementById('cancelIerbAction').addEventListener('click', closeActionModal);
    actionModal.addEventListener('click', e => { if (e.target === actionModal) closeActionModal(); });

    actionForm.addEventListener('submit', async event => {
        event.preventDefault();
        try {
            const res = await fetch('ierb_api.php?action=note', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ studentId: noteTargetId, note: actionText.value.trim() }),
            });
            const data = await res.json();
            if (!data.ok) { alert(data.message || 'The note could not be saved.'); return; }
            closeActionModal();
        } catch (_) {
            alert('Could not reach the server to save this note.');
        }
    });

    async function sendFollowup(record) {
        if (!confirm(`Send an IERB follow-up email to ${record.name}?`)) return;
        try {
            const res = await fetch('send_followup.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: record.email, name: record.name, studentDbId: record.id,
                    studentId: record.studentId, stage: record.stage, status: record.status,
                    requirements: record.requirements,
                }),
            });
            const data = await res.json();
            alert(data.message || (data.ok ? 'Follow-up sent.' : 'Could not send follow-up.'));
        } catch (_) {
            alert('Could not reach the server to send the follow-up email.');
        }
    }

    loadRecords();
})();
