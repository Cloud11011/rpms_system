(function () {
    'use strict';

    // Theme toggle (shared behavior with dashboard.php)
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

    const isAdviser = document.body.dataset.management === 'adviser';
    const apiUrl = isAdviser ? 'advisers_api.php' : 'students_api.php';
    // "loggedInRole" = the role of the person USING this page (admin or
    // adviser). Not to be confused with `isAdviser` above, which means
    // "this page instance manages the adviser roster".
    const loggedInRole = document.body.dataset.userRole || 'admin';
    const stageLabels = window.PRISM_STAGE_LABELS || {};

    function labelForStage(stageKey) {
        return stageLabels[stageKey] || stageKey;
    }

    // Populate the stage <select> option TEXT with the configured form/
    // document names, while keeping the underlying VALUE as "Stage 1" etc
    // so every other part of the app (filters, DB values) stays unchanged
    // (feature request 7: dynamic stage labels).
    const stageSelect = document.getElementById('stage');
    if (stageSelect) {
        stageSelect.querySelectorAll('option').forEach(opt => {
            if (opt.value) opt.textContent = `${opt.value} - ${labelForStage(opt.value)}`;
        });
    }

    // Advisers can add/assign only their OWN students (feature request 4);
    // the adviser dropdown would be misleading to show since the server
    // ignores it and forces their own id anyway, so hide it entirely.
    const adviserFieldLabel = document.getElementById('adviserFieldLabel');
    if (!isAdviser && loggedInRole === 'adviser' && adviserFieldLabel) {
        adviserFieldLabel.style.display = 'none';
    }

    // Protocol Code / Principal Investigator are RPMS-office decisions
    // (feature requests 10-11), not something an adviser sets themselves.
    const protocolCodeField = document.getElementById('protocolCodeField');
    const principalField = document.getElementById('principalField');
    if (!isAdviser && loggedInRole === 'adviser') {
        if (protocolCodeField) protocolCodeField.style.display = 'none';
        if (principalField) principalField.style.display = 'none';
    }

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);

    const searchInput = document.getElementById('recordSearch');
    const addBtn = document.getElementById('addRecord');
    const countEl = document.getElementById('recordCount');
    const rowsEl = document.getElementById('recordRows');

    const modal = document.getElementById('recordModal');
    const modalTitle = document.getElementById('modalTitle');
    const closeBtn = document.getElementById('closeRecord');
    const cancelBtn = document.getElementById('cancelRecord');
    const form = document.getElementById('recordForm');
    const idField = document.getElementById('recordId');
    const nameField = document.getElementById('recordName');
    const accountIdField = document.getElementById('accountId');
    const emailField = document.getElementById('recordEmail');

    let records = [];

    async function loadRecords() {
        try {
            const res = await fetch(`${apiUrl}?action=list`);
            const data = await res.json();
            records = data.ok ? (isAdviser ? data.advisers : data.students) : [];
        } catch (e) {
            records = [];
            PrismUI.toast(e.message || 'Could not load records.', 'error');
        }
        if (!isAdviser) {
            await loadAdviserOptions();
        }
        render();
    }

    async function loadAdviserOptions() {
        const select = document.getElementById('adviser');
        if (!select) return;
        try {
            const res = await fetch('students_api.php?action=adviser_options');
            const data = await res.json();
            const options = data.ok ? data.advisers : [];
            select.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
            options.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.full_name;
                select.appendChild(opt);
            });
        } catch (_) { /* keep default */ }
    }

    function matchesSearch(record, term) {
        if (!term) return true;
        const haystack = isAdviser
            ? [record.name, record.employeeId, record.email, record.department, record.groups]
            : [record.name, record.studentId, record.email, record.research, record.group, record.adviserName];
        return haystack.some(v => String(v ?? '').toLowerCase().includes(term));
    }

    function render() {
        const term = (searchInput.value || '').trim().toLowerCase();
        const filtered = records.filter(r => matchesSearch(r, term));
        countEl.textContent = `${filtered.length} record${filtered.length === 1 ? '' : 's'}`;
        rowsEl.replaceChildren();

        if (!filtered.length) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="${isAdviser ? 6 : 7}" class="empty-state">No ${isAdviser ? 'adviser' : 'student'} records found.</td>`;
            rowsEl.appendChild(tr);
            return;
        }

        filtered.forEach(record => {
            const tr = document.createElement('tr');
            if (isAdviser) {
                tr.innerHTML = `
                    <td><strong>${escapeHtml(record.name)}</strong><br><small>${escapeHtml(record.email)}</small></td>
                    <td>${escapeHtml(record.employeeId)}</td>
                    <td>${escapeHtml(record.department || 'N/A')}</td>
                    <td>${escapeHtml(record.groups || 'None')}</td>
                    <td><span class="status-badge ${String(record.status).toLowerCase().replace(/\s+/g, '-')}">${escapeHtml(record.status)}</span></td>
                    <td class="row-actions"></td>`;
            } else {
                const protocolBadge = record.protocolCode
                    ? `<span class="protocol-badge" title="Protocol Code">${escapeHtml(record.protocolCode)}</span>`
                    : '<span class="muted">Not yet assigned</span>';
                const piBadge = record.isPrincipalInvestigator ? ' <span class="pi-badge" title="Principal Investigator">PI</span>' : '';
                tr.innerHTML = `
                    <td><strong>${escapeHtml(record.name)}</strong><br><small>${escapeHtml(record.email)}</small></td>
                    <td>${escapeHtml(record.studentId)}</td>
                    <td>${escapeHtml(record.research || 'Not set')}<br><small>${escapeHtml(record.group || 'No group')}</small></td>
                    <td>${escapeHtml(record.adviserName || 'Unassigned')}</td>
                    <td><span class="stage-tag" title="${escapeHtml(record.stage)}">${escapeHtml(record.stageLabel || labelForStage(record.stage))}</span> <span class="status-badge ${String(record.status).toLowerCase().replace(/\s+/g, '-')}">${escapeHtml(record.status)}</span></td>
                    <td>${protocolBadge}${piBadge}</td>
                    <td class="row-actions"></td>`;
            }
            const actions = tr.querySelector('.row-actions');
            const editBtn = document.createElement('button');
            editBtn.className = 'icon-btn';
            editBtn.title = 'Edit';
            editBtn.innerHTML = '<i class="fa-solid fa-pen"></i>';
            editBtn.addEventListener('click', () => openModal(record));
            const delBtn = document.createElement('button');
            delBtn.className = 'icon-btn';
            delBtn.title = 'Delete';
            delBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
            delBtn.addEventListener('click', () => deleteRecord(record));
            actions.append(editBtn, delBtn);
            rowsEl.appendChild(tr);
        });
    }

    function openModal(record) {
        form.reset();
        idField.value = record ? record.id : '';
        modalTitle.textContent = record ? `Edit ${isAdviser ? 'Research Adviser' : 'Student'} Record` : `Add ${isAdviser ? 'Research Adviser' : 'Student'}`;

        if (record) {
            nameField.value = record.name || '';
            accountIdField.value = isAdviser ? record.employeeId : record.studentId;
            emailField.value = record.email || '';
            if (isAdviser) {
                document.getElementById('department').value = record.department || '';
                document.getElementById('groups').value = record.groups || '';
                document.getElementById('accountStatus').value = record.status || 'Active';
            } else {
                document.getElementById('research').value = record.research || '';
                document.getElementById('group').value = record.group || '';
                document.getElementById('adviser').value = record.adviserId || '';
                document.getElementById('stage').value = record.stage || 'Stage 1';
                document.getElementById('recordStatus').value = record.status || 'On Track';
                const pcField = document.getElementById('protocolCode');
                const piField = document.getElementById('isPrincipal');
                if (pcField) pcField.value = record.protocolCode || '';
                if (piField) piField.checked = !!record.isPrincipalInvestigator;
            }
        }
        modal.style.display = 'flex';
        nameField.focus();
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    async function deleteRecord(record) {
        const answer = await PrismUI.confirm({
            title:`Delete ${isAdviser ? 'adviser' : 'student'} record`,
            icon:'fa-trash', tone:'danger', confirmText:'Delete',
            message:`Delete the record for ${record.name}? The associated login will be deactivated.`
        });
        if (!answer) return;
        try {
            const data = await PrismUI.postJson(`${apiUrl}?action=delete`, { id: record.id });
            await loadRecords();
            PrismUI.toast(data.message || 'Record deleted.', 'success');
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        }
    }

    const recordNameForMessage = record => record?.name || 'this student';

    addBtn.addEventListener('click', () => openModal(null));
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    searchInput.addEventListener('input', render);

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const payload = {
            id: idField.value ? Number(idField.value) : 0,
            name: nameField.value.trim(),
            email: emailField.value.trim(),
        };
        if (isAdviser) {
            payload.employeeId = accountIdField.value.trim();
            payload.department = document.getElementById('department').value.trim();
            payload.groups = document.getElementById('groups').value.trim();
            payload.status = document.getElementById('accountStatus').value;
        } else {
            payload.studentId = accountIdField.value.trim();
            payload.research = document.getElementById('research').value.trim();
            payload.group = document.getElementById('group').value.trim();
            payload.adviserId = document.getElementById('adviser').value || null;
            payload.stage = document.getElementById('stage').value;
            payload.status = document.getElementById('recordStatus').value;
            const pcField = document.getElementById('protocolCode');
            const piField = document.getElementById('isPrincipal');
            if (pcField) payload.protocolCode = pcField.value.trim();
            if (piField) payload.isPrincipalInvestigator = piField.checked;
        }

        if (!isAdviser && payload.id) {
            const original = records.find(r => r.id === payload.id);
            if (original && (original.stage !== payload.stage || original.status !== payload.status)) {
                const answer = await PrismUI.confirm({
                    title:'Confirm progress change', icon:'fa-clipboard-check', confirmText:'Save changes',
                    message:`Changing ${recordNameForMessage(original)}'s stage or status will be added to the official progress history and the student will be notified.`,
                    reasonLabel:'Reason for this progress change', reasonRequired:true
                });
                if (!answer) return;
                payload.reason = answer.reason;
            }
        }
        const submitBtn = form.querySelector('[type="submit"]');
        submitBtn.disabled = true;
        try {
            const data = await PrismUI.postJson(`${apiUrl}?action=save`, payload);
            closeModal();
            await loadRecords();
            PrismUI.toast(data.message || 'Record saved.', 'success');
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        } finally {
            submitBtn.disabled = false;
        }
    });

    loadRecords();
})();
