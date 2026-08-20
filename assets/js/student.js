document.addEventListener('DOMContentLoaded', () => {
    const storageKey = `prismStudents:${document.body.dataset.studentUser || 'default'}`;
    let students = [];
    try {
        const stored = JSON.parse(localStorage.getItem(storageKey));
        students = Array.isArray(stored) ? stored : [];
    } catch (_) {}

    const tableBody = document.getElementById('studentTableBody');
    const search = document.getElementById('studentSearch');
    const statusFilter = document.getElementById('statusFilter');
    const count = document.getElementById('studentCount');
    const form = document.getElementById('studentForm');
    const formModal = document.getElementById('studentFormModal');
    const profileModal = document.getElementById('studentProfileModal');
    const researchMemberRows = document.getElementById('researchMemberRows');
    const fields = {
        recordId: document.getElementById('studentRecordId'), name: document.getElementById('studentName'),
        studentId: document.getElementById('studentId'), email: document.getElementById('studentEmail'),
        groupId: document.getElementById('studentGroupId'), course: document.getElementById('studentCourse'),
        year: document.getElementById('studentYear'), researchTitle: document.getElementById('studentResearchTitle'),
        stage: document.getElementById('studentStage'), status: document.getElementById('studentStatus'),
        progress: document.getElementById('studentProgress'), requirements: document.getElementById('studentRequirements')
    };

    const save = () => localStorage.setItem(storageKey, JSON.stringify(students));
    const escapeHtml = value => String(value).replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[char]);
    const statusClass = value => value.toLowerCase().replace(/\s+/g, '-');
    const formatDate = value => new Date(value).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });

    function filteredStudents() {
        const query = search.value.trim().toLowerCase();
        const filter = statusFilter.value;
        return students.filter(student => {
            const haystack = `${student.name} ${student.studentId} ${student.course}`.toLowerCase();
            return (!query || haystack.includes(query)) && (!filter || student.status === filter);
        });
    }

    function openModal(modal) { modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false'); }
    function closeModal(modal) { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }

    function addResearchMemberRow(member = {}) {
        const row = document.createElement('div');
        row.className = 'research-member-row';
        row.innerHTML = `<input class="member-name" maxlength="120" placeholder="Member name" value="${escapeHtml(member.name || '')}" required><input class="member-id" maxlength="40" placeholder="Student ID" value="${escapeHtml(member.studentId || '')}" required><input class="member-email" type="email" maxlength="150" placeholder="Email (optional)" value="${escapeHtml(member.email || '')}"><button type="button" title="Remove member" aria-label="Remove member"><i class="fa-solid fa-trash"></i></button>`;
        row.querySelector('button').addEventListener('click', () => row.remove());
        researchMemberRows.appendChild(row);
    }

    function setResearchMembers(members = []) {
        researchMemberRows.replaceChildren();
        members.forEach(addResearchMemberRow);
    }

    function collectResearchMembers() {
        return Array.from(researchMemberRows.querySelectorAll('.research-member-row')).map(row => ({
            name: row.querySelector('.member-name').value.trim(),
            studentId: row.querySelector('.member-id').value.trim(),
            email: row.querySelector('.member-email').value.trim()
        })).filter(member => member.name && member.studentId);
    }

    function render() {
        const filtered = filteredStudents();
        count.textContent = `${filtered.length} ${filtered.length === 1 ? 'student' : 'students'}`;
        tableBody.replaceChildren();
        if (!filtered.length) {
            const row = document.createElement('tr');
            row.innerHTML = `<td colspan="6" class="student-empty"><i class="fa-solid fa-user-graduate"></i>${students.length ? 'No students match your search.' : 'No student records yet.'}</td>`;
            tableBody.appendChild(row);
            return;
        }
        filtered.sort((a, b) => a.name.localeCompare(b.name)).forEach(student => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><div class="student-identity"><strong>${escapeHtml(student.name)}</strong><span>${escapeHtml(student.studentId)}</span></div></td>
                <td><div class="course-year"><strong>${escapeHtml(student.course)}</strong><span>${escapeHtml(student.year)}</span></div></td>
                <td><span class="stage-label">${escapeHtml(student.stage)}</span></td>
                <td><span class="status-label ${statusClass(student.status)}">${escapeHtml(student.status)}</span></td>
                <td>${escapeHtml(formatDate(student.updatedAt))}</td>
                <td><div class="student-actions">
                    <button class="table-action" data-action="view" title="View profile" aria-label="View profile"><i class="fa-solid fa-eye"></i></button>
                    <button class="table-action" data-action="update" title="Update status" aria-label="Update status"><i class="fa-solid fa-pen"></i></button>
                    <button class="table-action" data-action="remind" title="Send reminder" aria-label="Send reminder"><i class="fa-solid fa-envelope"></i></button>
                </div></td>`;
            row.querySelector('[data-action="view"]').addEventListener('click', () => viewStudent(student.id));
            row.querySelector('[data-action="update"]').addEventListener('click', () => editStudent(student.id));
            row.querySelector('[data-action="remind"]').addEventListener('click', () => sendReminder(student.id));
            tableBody.appendChild(row);
        });
    }

    function addStudent() {
        form.reset(); fields.recordId.value = '';
        setResearchMembers();
        document.getElementById('studentFormTitle').textContent = 'Add New Student';
        openModal(formModal); fields.name.focus();
    }

    function editStudent(id) {
        const student = students.find(item => item.id === id); if (!student) return;
        Object.keys(fields).forEach(key => { if (key !== 'recordId') fields[key].value = student[key] || ''; });
        setResearchMembers(Array.isArray(student.researchMembers) ? student.researchMembers : []);
        fields.recordId.value = student.id;
        document.getElementById('studentFormTitle').textContent = 'Update Student Status';
        openModal(formModal); fields.stage.focus();
    }

    function viewStudent(id) {
        const student = students.find(item => item.id === id); if (!student) return;
        document.getElementById('profileStudentName').textContent = student.name;
        const content = document.getElementById('studentProfileContent');
        const history = Array.isArray(student.history) ? student.history : [];
        const members = Array.isArray(student.researchMembers) ? student.researchMembers : [];
        content.innerHTML = `<div class="profile-fields">
            <div class="profile-field"><span>Student ID</span><strong>${escapeHtml(student.studentId)}</strong></div>
            <div class="profile-field"><span>Email</span><strong>${escapeHtml(student.email)}</strong></div>
            <div class="profile-field"><span>Course / Year</span><strong>${escapeHtml(student.course)} / ${escapeHtml(student.year)}</strong></div>
            <div class="profile-field"><span>IERB progress</span><strong>${escapeHtml(student.stage)} - ${escapeHtml(student.status)}</strong></div>
            <div class="profile-field"><span>Research group</span><strong>${escapeHtml(student.groupId || 'Not assigned')}</strong></div>
            <div class="profile-field"><span>Overall progress</span><strong>${escapeHtml(student.progress || '0')}%</strong></div>
            <div class="profile-field"><span>Research title</span><strong>${escapeHtml(student.researchTitle || 'Not provided')}</strong></div>
            <div class="profile-field"><span>Pending requirements</span><strong>${escapeHtml(student.requirements || 'None')}</strong></div>
        </div><h3 class="history-title">Research Members</h3><ul class="profile-members">${members.length ? members.map(member => `<li><strong>${escapeHtml(member.name)}</strong><span>${escapeHtml(member.studentId)}${member.email ? ` - ${escapeHtml(member.email)}` : ''}</span></li>`).join('') : '<li><span>No additional research members.</span></li>'}</ul><h3 class="history-title">Activity History</h3><ul class="student-history">${history.length ? history.map(entry => `<li>${escapeHtml(entry.message)}<time>${escapeHtml(formatDate(entry.at))}</time></li>`).join('') : '<li>No activity recorded.</li>'}</ul>`;
        openModal(profileModal);
    }

    function sendReminder(id) {
        const student = students.find(item => item.id === id); if (!student) return;
        if (!confirm(`Prepare an IERB follow-up email for ${student.name}?`)) return;
        const now = new Date().toISOString();
        student.history = Array.isArray(student.history) ? student.history : [];
        student.history.unshift({ message: 'IERB follow-up email prepared.', at: now });
        student.updatedAt = now; save(); render();
        const subject = encodeURIComponent(`IERB Progress Follow-up - ${student.studentId}`);
        const body = encodeURIComponent(`Hello ${student.name},\n\nThis is a follow-up regarding your current IERB progress (${student.stage}, ${student.status}). Please contact the RPMS office with your latest update.\n\nThank you.`);
        window.location.href = `mailto:${encodeURIComponent(student.email)}?subject=${subject}&body=${body}`;
    }

    function exportStudents() {
        const records = filteredStudents();
        if (!records.length) {
            alert('There are no student records to export.');
            return;
        }
        const headers = ['Student Name', 'Student ID', 'Email', 'Research Group ID', 'Course', 'Year Level', 'Research Title', 'Research Members', 'IERB Stage', 'Pending Requirements', 'Overall Progress', 'Status', 'Last Updated'];
        const csvCell = value => `"${String(value ?? '').replace(/"/g, '""')}"`;
        const rows = records.map(student => {
            const members = Array.isArray(student.researchMembers)
                ? student.researchMembers.map(member => `${member.name} (${member.studentId}${member.email ? `, ${member.email}` : ''})`).join('; ')
                : '';
            return [student.name, student.studentId, student.email, student.groupId, student.course, student.year,
                student.researchTitle, members, student.stage, student.requirements || 'None', `${student.progress || 0}%`,
                student.status, new Date(student.updatedAt).toISOString()].map(csvCell).join(',');
        });
        const blob = new Blob([`\uFEFF${[headers.map(csvCell).join(','), ...rows].join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `prism-students-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        const now = new Date().toISOString();
        const values = Object.fromEntries(Object.entries(fields).filter(([key]) => key !== 'recordId').map(([key, input]) => [key, input.value.trim()]));
        values.researchMembers = collectResearchMembers();
        const duplicate = students.find(item => item.studentId.toLowerCase() === values.studentId.toLowerCase() && item.id !== fields.recordId.value);
        if (duplicate) { alert('That student ID already exists.'); fields.studentId.focus(); return; }
        const existing = students.find(item => item.id === fields.recordId.value);
        if (existing) {
            const changed = existing.stage !== values.stage || existing.status !== values.status;
            Object.assign(existing, values, { updatedAt: now });
            existing.history = Array.isArray(existing.history) ? existing.history : [];
            existing.history.unshift({ message: changed ? `Progress updated to ${values.stage} - ${values.status}.` : 'Student profile updated.', at: now });
        } else {
            students.push({ id: `${Date.now()}-${Math.random().toString(16).slice(2)}`, ...values, updatedAt: now, history: [{ message: 'Student profile created.', at: now }] });
        }
        save(); closeModal(formModal); render();
    });

    document.getElementById('addStudentButton').addEventListener('click', addStudent);
    document.getElementById('exportStudentsButton').addEventListener('click', exportStudents);
    document.getElementById('addResearchMember').addEventListener('click', () => addResearchMemberRow());
    search.addEventListener('input', render); statusFilter.addEventListener('change', render);
    document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => closeModal(document.getElementById(button.dataset.close))));
    [formModal, profileModal].forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) closeModal(modal); }));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeModal(formModal); closeModal(profileModal); } });

    const themeToggle = document.getElementById('themeToggle');
    themeToggle.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark-theme');
        try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
    });
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    profileToggle.addEventListener('click', event => { event.stopPropagation(); profileMenu.classList.toggle('show'); });
    document.addEventListener('click', () => profileMenu.classList.remove('show'));
    render();
    if (new URLSearchParams(window.location.search).get('action') === 'add') addStudent();
});
