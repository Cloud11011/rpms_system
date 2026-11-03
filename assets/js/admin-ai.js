document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

    let historyRequest = 0;
    let generating = false;
    const selectedReports = new Map();
    const selectPage = document.getElementById('aiSelectPage');
    const selectedCount = document.getElementById('aiSelectedCount');
    const deleteSelected = document.getElementById('aiDeleteSelected');

    const pager = PrismUI.recordPager(document.getElementById('aiHistory'), document.getElementById('aiHistoryCount'), [], loadHistory);
    let displayedItems = [];

    function updateSelection() {
        const allVisibleSelected = displayedItems.length > 0 && displayedItems.every(item => selectedReports.has(String(item.id)));
        const someVisibleSelected = displayedItems.some(item => selectedReports.has(String(item.id)));
        selectPage.checked = allVisibleSelected;
        selectPage.indeterminate = someVisibleSelected && !allVisibleSelected;
        selectPage.disabled = displayedItems.length === 0;
        selectedCount.textContent = `${selectedReports.size} selected`;
        deleteSelected.disabled = selectedReports.size === 0;
        deleteSelected.title = selectedReports.size ? `Delete ${selectedReports.size} selected reports` : 'Delete selected reports';
        deleteSelected.setAttribute('aria-label', deleteSelected.title);
        deleteSelected.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>';
        document.querySelectorAll('#aiHistory [data-report-select]').forEach(input => {
            input.checked = selectedReports.has(input.dataset.reportSelect);
        });
    }

    async function confirmReportDeletion(reports) {
        const result = await PrismUI.confirm({
            title: reports.length === 1 ? 'Delete generated report?' : 'Delete selected reports?',
            icon: 'fa-trash-can',
            tone: 'danger',
            message: reports.length === 1
                ? `“${reports[0].title || reports[0].type || 'Untitled report'}” will be permanently deleted. This cannot be undone.`
                : `${reports.length} reports will be permanently deleted. This cannot be undone.`,
            confirmText: reports.length === 1 ? 'Yes, delete report' : `Yes, delete ${reports.length} reports`,
            cancelText: reports.length === 1 ? 'No, keep report' : 'No, keep reports',
            busyText: 'Deleting...',
            dialogClass: 'report-delete-confirmation',
            overlayClass: 'report-delete-overlay',
            onConfirm: async () => {
                const pending = reports.filter(report => selectedReports.has(String(report.id)));
                const deleted = [];
                const failures = [];
                for (const report of pending) {
                    try {
                        await PrismUI.postJson(`reports_api.php?action=delete&id=${encodeURIComponent(report.id)}`, {});
                        deleted.push(report);
                        selectedReports.delete(String(report.id));
                    } catch (error) {
                        failures.push(error);
                    }
                }
                if (deleted.length) await loadHistory();
                updateSelection();
                if (failures.length) {
                    throw new Error(`${deleted.length} deleted; ${failures.length} could not be deleted. ${failures[0].message}`);
                }
                if (!deleted.length) throw new Error('No selected reports were deleted. Refresh the list and try again.');
                PrismUI.toast('success', `${deleted.length} report${deleted.length === 1 ? '' : 's'} deleted.`);
                return deleted.length;
            }
        });
        return result !== null;
    }

    async function deleteOneReport(report) {
        const id = String(report.id);
        const alreadySelected = selectedReports.has(id);
        selectedReports.set(id, report);
        updateSelection();
        const confirmed = await confirmReportDeletion([report]);
        if (!confirmed && !alreadySelected) selectedReports.delete(id);
        updateSelection();
    }

    selectPage.addEventListener('change', () => {
        displayedItems.forEach(item => {
            const id = String(item.id);
            if (selectPage.checked) selectedReports.set(id, item);
            else selectedReports.delete(id);
        });
        updateSelection();
    });

    deleteSelected.addEventListener('click', () => {
        if (selectedReports.size) confirmReportDeletion([...selectedReports.values()]);
    });

    async function loadHistory() {
        const requestId = ++historyRequest;
        const list = document.getElementById('aiHistory');
        const count = document.getElementById('aiHistoryCount');
        count.textContent = 'Loading reports...';
        list.setAttribute('aria-busy', 'true');
        try {
            const data = await PrismUI.request(`reports_api.php?action=list&aiOnly=1&page=${pager.page}&perPage=${pager.pageSize}`);
            if (requestId !== historyRequest) return;
            const items = (data.reports || []).filter(r => r.type === 'AI Summarized Report' || r.type === 'AI Full Report');
            displayedItems = items;
            pager.render({...data,total:data.total??items.length});
            list.replaceChildren();
            if (!items.length) {
                const state = document.createElement('div');
                state.className = 'workspace-empty-state';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-file-circle-plus';
                icon.setAttribute('aria-hidden', 'true');
                const title = document.createElement('strong');
                title.textContent = 'No AI reports generated yet';
                const description = document.createElement('span');
                description.textContent = 'Choose a report type above. Generated PDFs will appear here for quick access.';
                state.append(icon, title, description);
                list.appendChild(state);
                updateSelection();
                return;
            }
            items.forEach(item => {
                const actions=document.createElement('span');actions.className='prism-document-quick-actions';
                if(item.requiresRegeneration){const regenerate=document.createElement('a');regenerate.href='reports.php';regenerate.textContent='Regenerate with Student IDs';actions.append(regenerate);}
                else {const view=document.createElement('a');view.href='reports_api.php?action=file&id='+encodeURIComponent(item.id);view.target='_blank';view.rel='noopener noreferrer';const download=document.createElement('a');download.href='reports_api.php?action=file&download=1&id='+encodeURIComponent(item.id);PrismUI.documentAction(view,'view','View report');PrismUI.documentAction(download,'download','Download report');actions.append(view,download);}
                const remove=document.createElement('button');remove.type='button';remove.className='ai-report-delete';remove.title='Delete report';remove.setAttribute('aria-label',`Delete ${item.title||item.type||'report'}`);remove.innerHTML='<i class="fa-solid fa-trash-can" aria-hidden="true"></i>';remove.addEventListener('click',()=>deleteOneReport(item));actions.append(remove);
                const card=PrismUI.reportCard(item,actions,{showDetails:false});
                card.classList.add('history-item','report-history-link');
                const selection=document.createElement('label');selection.className='ai-report-select';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.dataset.reportSelect=String(item.id);checkbox.setAttribute('aria-label',`Select ${item.title||item.type||'report'}`);checkbox.checked=selectedReports.has(String(item.id));checkbox.addEventListener('change',()=>{if(checkbox.checked)selectedReports.set(String(item.id),item);else selectedReports.delete(String(item.id));updateSelection();});selection.append(checkbox);card.prepend(selection);
                list.appendChild(card);
            });
            updateSelection();
        } catch (e) {
            if (requestId !== historyRequest) return;
            pager.error();
            count.textContent = 'History unavailable';
            list.replaceChildren();
            const state = document.createElement('div');
            state.className = 'workspace-empty-state';
            const icon = document.createElement('i');
            icon.className = 'fa-solid fa-triangle-exclamation';
            icon.setAttribute('aria-hidden', 'true');
            const title = document.createElement('strong');
            title.textContent = 'Could not load report history';
            const description = document.createElement('span');
            description.textContent = e.message || 'Refresh the page and try again.';
            state.append(icon, title, description);
            list.appendChild(state);
            displayedItems = [];
            updateSelection();
        } finally {
            if (requestId === historyRequest) list.setAttribute('aria-busy', 'false');
        }
    }

    async function generateAiReport(mode, button) {
        if (generating) return;
        generating = true;
        const release = PrismUI.busy(button, 'Generating...');
        if (!release) { generating = false; return; }
        button.setAttribute('aria-busy', 'true');
        button.classList.add('loading');
        const note = document.getElementById('reportAiNote');
        note.textContent = 'Generating your report. This may take a moment...';
        note.className = 'report-ai-note';
        try {
            const data = await PrismUI.postJson('reports_api.php?action=ai_report', { mode });
            if (!data.aiUsed) {
                note.textContent = 'AI service was unavailable, so this report was generated using the built-in local summarizer instead. It is ready to review and download.';
                note.classList.add('warn');
            } else {
                note.textContent = 'Report generated using AI. Review it before sharing officially.';
            }
            await loadHistory();
            const link = document.createElement('a');
            link.href = `reports_api.php?action=file&download=1&id=${encodeURIComponent(data.report.id)}`;
            link.download = '';
            document.body.appendChild(link);
            link.click();
            link.remove();
        } catch (e) {
            note.textContent = e.message || 'Could not generate this report.';
            note.classList.add('warn');
        } finally {
            generating = false;
            release();
            button.classList.remove('loading');
            button.setAttribute('aria-busy', 'false');
        }
    }

    document.getElementById('generateSummarizedReport').addEventListener('click', e => generateAiReport('summary', e.currentTarget));
    document.getElementById('generateFullReport').addEventListener('click', e => generateAiReport('full', e.currentTarget));

    loadHistory();
});
