document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
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
    const v14Reports = window.PRISM_V14_REPORTS === true;
    const projectModal=document.getElementById('projectModal');
    function createReportTool(iconClass, label) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'report-tool';
        const icon = document.createElement('span');
        icon.className = 'report-tool-icon';
        const iconGlyph = document.createElement('i');
        iconGlyph.className = `fa-solid ${iconClass}`;
        iconGlyph.setAttribute('aria-hidden', 'true');
        icon.append(iconGlyph);
        const copy = document.createElement('span');
        const title = document.createElement('strong');
        title.textContent = label;
        copy.append(title);
        const arrow = document.createElement('i');
        arrow.className = 'fa-solid fa-chevron-right';
        arrow.setAttribute('aria-hidden', 'true');
        button.append(icon, copy, arrow);
        return button;
    }
    const projectButton = createReportTool('fa-file-lines', 'Generate IERB Project Track Sheet');
    const aggregateButton = createReportTool('fa-building-columns', 'Generate Institutional Project Report');
    document.querySelector('.report-tools').append(projectButton, aggregateButton);
    aggregateButton.addEventListener('click',async()=>{const release=PrismUI.busy(aggregateButton,'Generating...');if(!release)return;try{const data=await PrismUI.postJson('reports_api.php?action=project_aggregate',{});await loadReports();downloadReport(data.report.id)}catch(e){PrismUI.toast(e.message,'error')}finally{release()}});
    projectButton.addEventListener('click',async()=>{
        const release=PrismUI.busy(projectButton,'Loading projects...');if(!release)return;
        try{const data=await PrismUI.request('reports_api.php?action=project_options');const select=document.getElementById('reportProject');
            select.replaceChildren(...data.projects.map(p=>new Option(p.research_title+' · '+(p.protocolCode!==undefined?'Protocol Code: '+(p.protocolCode||'Not yet assigned'):p.public_identifier),p.id)));
            if(!data.projects.length)select.add(new Option('No canonical projects available',''));
            const c=data.counts;document.getElementById('projectCounts').textContent=c.studentRecords+' Student records · '+c.distinctResearchProjects+' distinct research projects · '+c.v14EnrolledProjects+' V14 projects · '+c.unreconciledStudentRecords+' unreconciled Student records';openModal(projectModal);
        }catch(e){PrismUI.toast(e.message,'error')}finally{release()}
    });
    document.getElementById('projectReportForm').addEventListener('submit',async event=>{
        event.preventDefault();const id=Number(document.getElementById('reportProject').value);if(!id)return;
        const release=PrismUI.busy(event.target.querySelector('button'),'Generating...');if(!release)return;
        try{const data=await PrismUI.postJson('reports_api.php?action=project_report',{projectId:id});await loadReports();closeModal(projectModal);downloadReport(data.report.id)}catch(e){PrismUI.toast(e.message,'error')}finally{release()}
    });

    document.getElementById('openStudentReport').addEventListener('click', async () => { await loadStudentOptions(); openModal(studentModal); });


    async function loadStudentOptions() {
        const select = document.getElementById('reportStudent');
        select.replaceChildren();
        try {
            const data = await PrismUI.request('students_api.php?action=options&activeOnly=1');
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

    let reports = [], listSequence = 0, reportLoadError = '';
    const selectedReports = new Map();
    const selectPage = document.getElementById('reportSelectPage');
    const selectedCount = document.getElementById('reportSelectedCount');
    const deleteSelected = document.getElementById('reportDeleteSelected');
    function updateReportSelection() {
        const allVisibleSelected = reports.length > 0 && reports.every(report => selectedReports.has(String(report.id)));
        const someVisibleSelected = reports.some(report => selectedReports.has(String(report.id)));
        selectPage.checked = allVisibleSelected;
        selectPage.indeterminate = someVisibleSelected && !allVisibleSelected;
        selectPage.disabled = reports.length === 0;
        selectedCount.textContent = `${selectedReports.size} selected`;
        deleteSelected.disabled = selectedReports.size === 0;
        const label = selectedReports.size ? `Delete ${selectedReports.size} selected reports` : 'Delete selected reports';
        deleteSelected.title = label;
        deleteSelected.setAttribute('aria-label', label);
        document.querySelectorAll('#reportTableBody [data-report-select]').forEach(input => {
            input.checked = selectedReports.has(input.dataset.reportSelect);
        });
    }

    async function deleteSelectedReports(items) {
        const result = await PrismUI.confirm({
            title: items.length === 1 ? 'Delete report?' : 'Delete selected reports?',
            icon: 'fa-trash-can',
            tone: 'danger',
            message: items.length === 1
                ? `“${items[0].title || 'Untitled report'}” will be permanently deleted. This cannot be undone.`
                : `${items.length} reports will be permanently deleted. This cannot be undone.`,
            confirmText: items.length === 1 ? 'Yes, delete report' : `Yes, delete ${items.length} reports`,
            cancelText: items.length === 1 ? 'No, keep report' : 'No, keep reports',
            busyText: 'Deleting...',
            dialogClass: 'report-delete-confirmation',
            overlayClass: 'report-delete-overlay',
            onConfirm: async () => {
                const deleted = [];
                const failures = [];
                for (const report of items.filter(item => selectedReports.has(String(item.id)))) {
                    try {
                        await PrismUI.postJson('reports_api.php?action=delete', { id: report.id });
                        deleted.push(report);
                        selectedReports.delete(String(report.id));
                    } catch (error) {
                        failures.push(error);
                    }
                }
                if (deleted.length) await loadReports();
                updateReportSelection();
                if (failures.length) {
                    throw new Error(`${deleted.length} deleted; ${failures.length} could not be deleted. ${failures[0].message}`);
                }
                if (!deleted.length) throw new Error('No selected reports were deleted. Refresh the list and try again.');
                PrismUI.toast(`${deleted.length} report${deleted.length === 1 ? '' : 's'} deleted.`, 'success');
                return deleted.length;
            }
        });
        return result !== null;
    }

    selectPage.addEventListener('change', () => {
        reports.forEach(report => {
            const id = String(report.id);
            if (selectPage.checked) selectedReports.set(id, report);
            else selectedReports.delete(id);
        });
        updateReportSelection();
    });
    deleteSelected.addEventListener('click', () => {
        if (selectedReports.size) deleteSelectedReports([...selectedReports.values()]);
    });
    document.getElementById('reportSort')?.addEventListener('change', () => { pager.reset(); loadReports(); });
    const pager = PrismUI.recordPager(document.getElementById('reportTableBody').closest('table').parentElement, document.getElementById('reportCount'), [], loadReports);

    async function loadReports() {
        const sequence = ++listSequence; pager.loading();
        try {
            const sort = document.getElementById('reportSort')?.value || 'newest';
            const data = await PrismUI.request(`reports_api.php?action=list&sort=${encodeURIComponent(sort)}&page=${pager.page}&perPage=${pager.pageSize}`);
            if (sequence !== listSequence) return;
            reports = data.reports || [];
            reportLoadError = '';
            pager.render({ ...data, total: data.total ?? reports.length });
        } catch (e) {
            if (sequence !== listSequence) return;
            reports = []; reportLoadError = e.message || 'Please try again.'; pager.error();
            PrismUI.toast(e.message, 'error');
        }
        renderReports();
    }

    function renderReports() {
        const body = document.getElementById('reportTableBody');

        body.replaceChildren();
        if (!reports.length) {
            const tr = document.createElement('tr');
            const cell=document.createElement('td');cell.colSpan=4;cell.className='empty-state';
            if(reportLoadError){const message=document.createElement('p');message.setAttribute('role','alert');message.textContent='Could not load reports. '+reportLoadError;const retry=document.createElement('button');retry.type='button';retry.className='prism-btn';retry.textContent='Retry loading reports';retry.addEventListener('click',()=>PrismUI.runAction(retry,'Loading...',loadReports));cell.append(message,retry);}
            else cell.textContent='No reports generated yet.';
            tr.append(cell);
            body.appendChild(tr);
            updateReportSelection();
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
            if (r.requiresRegeneration) {
                const warning = document.createElement('span');warning.textContent='Regenerate with Student IDs';actions.append(warning);
            } else {PrismUI.documentAction(view,'view','View report');PrismUI.documentAction(download,'download','Download report');actions.append(view, download);}
            const del = document.createElement('button');
            del.className = 'icon-btn delete';
            del.title = 'Delete report';
            del.setAttribute('aria-label', 'Delete report');
            del.innerHTML = '<i class="fa-solid fa-trash"></i>';
            del.addEventListener('click', async () => {
                const answer = await PrismUI.confirm({
                    title:'Delete report', icon:'fa-trash', tone:'danger', confirmText:'Delete',
                    cancelText:'No, keep report', confirmText:'Yes, delete report',
                    message:`Delete “${r.title}”? This removes the generated PDF from PRISM.`
                });
                if (!answer) return;
                try {
                    const data = await PrismUI.postJson('reports_api.php?action=delete', { id: r.id });
                    selectedReports.delete(String(r.id));
                    PrismUI.toast(data.message || 'Report deleted.', 'success');
                    await loadReports();
                } catch (e) {
                    PrismUI.toast(e.message, 'error');
                }
            });
            actions.append(del);
            const host=document.createElement('td');host.colSpan=4;
            const card=PrismUI.reportCard(r,actions);
            const selection=document.createElement('label');selection.className='report-row-select';
            const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.dataset.reportSelect=String(r.id);checkbox.setAttribute('aria-label',`Select ${r.title||'report'}`);checkbox.checked=selectedReports.has(String(r.id));
            checkbox.addEventListener('change',()=>{if(checkbox.checked)selectedReports.set(String(r.id),r);else selectedReports.delete(String(r.id));updateReportSelection();});
            selection.append(checkbox);card.prepend(selection);host.append(card);tr.replaceChildren(host);
            body.appendChild(tr);
        });
        updateReportSelection();
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
