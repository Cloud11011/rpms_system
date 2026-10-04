document.addEventListener('DOMContentLoaded', () => {
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

    const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c]);
    function downloadReport(id) {
        const link = document.createElement('a');
        link.href = `reports_api.php?action=file&download=1&id=${encodeURIComponent(id)}`;
        link.download = '';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    function openModal(m) { m.classList.add('show'); m.style.display = 'flex'; }
    function closeModal(m) { m.classList.remove('show'); m.style.display = 'none'; }
    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(document.getElementById(btn.dataset.close))));
    document.querySelectorAll('.report-modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) closeModal(m); }));

    const studentModal = document.getElementById('studentModal');

    document.getElementById('openStudentReport').addEventListener('click', async () => { await loadStudentOptions(); openModal(studentModal); });


    async function generateAiReport(mode, button) {
        if (button.disabled) return;
        const originalHTML = button.innerHTML;
        button.disabled = true;
        button.textContent = 'Generating...';
        button.classList.add('loading');
        const note = document.getElementById('reportAiNote');
        note.textContent = '';
        note.className = 'report-ai-note';
        try {
            const res = await fetch('reports_api.php?action=ai_report', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode }),
            });
            const data = await res.json();
            if (!data.ok) {
                note.textContent = data.message || 'The report could not be generated.';
                note.classList.add('warn');
                return;
            }
            if (!data.aiUsed) {
                note.textContent = 'AI service was unavailable, so this report was generated using the built-in local summarizer instead. It is ready to review and download.';
                note.classList.add('warn');
            } else {
                note.textContent = 'Report generated using AI. Review it before sharing officially.';
            }
            await loadReports();
            downloadReport(data.report.id);
        } catch (_) {
            note.textContent = 'Could not reach the server to generate this report.';
            note.classList.add('warn');
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
            button.innerHTML = originalHTML;
        }
    }

    document.getElementById('generateSummarizedReport').addEventListener('click', e => generateAiReport('summary', e.currentTarget));
    document.getElementById('generateFullReport').addEventListener('click', e => generateAiReport('full', e.currentTarget));

    async function loadStudentOptions() {
        const select = document.getElementById('reportStudent');
        select.replaceChildren();
        try {
            const data = await PrismUI.request('students_api.php?action=options');
            (data.students || []).forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = `${r.name} (${r.studentId})`;
                select.appendChild(opt);
            });
            if (!select.options.length) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'No students available';
                select.appendChild(opt);
            }
        } catch (e) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'Could not load students';
            select.appendChild(opt);
            PrismUI.toast(e.message, 'error');
        }
    }

    let reports = [], listSequence = 0;
    const pager = PrismUI.recordPager(document.getElementById('reportTableBody').closest('table').parentElement, document.getElementById('reportCount'), [], loadReports);

    async function loadReports() {
        const sequence = ++listSequence; pager.loading();
        try {
            const data = await PrismUI.request(`reports_api.php?action=list&page=${pager.page}`);
            if (sequence !== listSequence) return;
            reports = data.reports || [];
            pager.render({ ...data, total: data.total ?? reports.length });
        } catch (e) {
            if (sequence !== listSequence) return;
            reports = []; pager.error();
            PrismUI.toast(e.message, 'error');
        }
        renderReports();
    }

    function renderReports() {
        const body = document.getElementById('reportTableBody');

        body.replaceChildren();
        if (!reports.length) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="4" class="empty-state">No reports generated yet.</td>';
            body.appendChild(tr);
            return;
        }
        reports.forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${esc(r.title)}</td>
                <td>${esc(new Date(String(r.generated_at).replace(' ', 'T')).toLocaleString('en-PH'))}</td>
                <td>${esc(r.type)}</td>
                <td class="row-actions"></td>`;
            const actions = tr.querySelector('.row-actions');
            const view = document.createElement('a');
            view.className = 'icon-btn';
            view.title = 'View';
            view.target = '_blank';
            view.href = `reports_api.php?action=file&id=${encodeURIComponent(r.id)}`;
            view.innerHTML = '<i class="fa-solid fa-eye"></i>';
            const download = document.createElement('a');
            download.className = 'icon-btn';
            download.title = 'Download';
            download.href = `reports_api.php?action=file&download=1&id=${encodeURIComponent(r.id)}`;
            download.innerHTML = '<i class="fa-solid fa-download"></i>';
            actions.append(view, download);
            const del = document.createElement('button');
            del.className = 'icon-btn';
            del.title = 'Delete';
            del.innerHTML = '<i class="fa-solid fa-trash"></i>';
            del.addEventListener('click', async () => {
                const answer = await PrismUI.confirm({
                    title:'Delete report', icon:'fa-trash', tone:'danger', confirmText:'Delete',
                    message:`Delete “${r.title}”? This removes the generated PDF from PRISM.`
                });
                if (!answer) return;
                try {
                    const data = await PrismUI.postJson('reports_api.php?action=delete', { id: r.id });
                    PrismUI.toast(data.message || 'Report deleted.', 'success');
                    await loadReports();
                } catch (e) {
                    PrismUI.toast(e.message, 'error');
                }
            });
            actions.append(del);
            body.appendChild(tr);
        });
    }

    async function generateReport(payload) {
        try {
            const data = await PrismUI.postJson('reports_api.php?action=generate', payload);
            await loadReports();
            PrismUI.toast('Report generated successfully.', 'success');
            return data.report;
        } catch (e) {
            PrismUI.toast(e.message, 'error');
            return null;
        }
    }

    document.getElementById('studentReportForm').addEventListener('submit', async event => {
        event.preventDefault();
        const studentId = document.getElementById('reportStudent').value;
        if (!studentId) { PrismUI.toast('Select a student.', 'error'); return; }
        const btn = event.target.querySelector('[type="submit"], .generate-report-button');
        const release = PrismUI.busy(btn, 'Generating...');
        if (!release) return;
        const report = await generateReport({ type: 'Student Report', studentId: Number(studentId) });
        release();
        if (report) {
            closeModal(studentModal);
            downloadReport(report.id);
        }
    });

    document.getElementById('exportExcel').addEventListener('click', () => {
        window.location.href = 'reports_api.php?action=export_csv';
    });

    loadReports();
});
