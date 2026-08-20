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
        } catch (_) {
            records = [];
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
            tr.innerHTML = `<td colspan="6" class="empty-state">No ${isAdviser ? 'adviser' : 'student'} records found.</td>`;
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
                tr.innerHTML = `
                    <td><strong>${escapeHtml(record.name)}</strong><br><small>${escapeHtml(record.email)}</small></td>
                    <td>${escapeHtml(record.studentId)}</td>
                    <td>${escapeHtml(record.research || 'Not set')}<br><small>${escapeHtml(record.group || 'No group')}</small></td>
                    <td>${escapeHtml(record.adviserName || 'Unassigned')}</td>
                    <td><span class="stage-tag">${escapeHtml(record.stage)}</span> <span class="status-badge ${String(record.status).toLowerCase().replace(/\s+/g, '-')}">${escapeHtml(record.status)}</span></td>
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
            }
        }
        modal.style.display = 'flex';
        nameField.focus();
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    async function deleteRecord(record) {
        if (!confirm(`Delete the record for ${record.name}? This cannot be undone.`)) return;
        try {
            const res = await fetch(`${apiUrl}?action=delete`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: record.id }),
            });
            const data = await res.json();
            if (!data.ok) { alert(data.message || 'Could not delete this record.'); return; }
            await loadRecords();
        } catch (_) {
            alert('Could not reach the server to delete this record.');
        }
    }

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
        }

        try {
            const res = await fetch(`${apiUrl}?action=save`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (!data.ok) { alert(data.message || 'This record could not be saved.'); return; }
            closeModal();
            await loadRecords();
        } catch (_) {
            alert('Could not reach the server to save this record.');
        }
    });

    loadRecords();
})();
