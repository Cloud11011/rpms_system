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
    const reportButtons = [document.getElementById('generateSummarizedReport'), document.getElementById('generateFullReport')];

    async function loadHistory() {
        const requestId = ++historyRequest;
        const list = document.getElementById('aiHistory');
        const count = document.getElementById('aiHistoryCount');
        count.textContent = 'Loading reports...';
        list.setAttribute('aria-busy', 'true');
        try {
            const data = await PrismUI.request('reports_api.php?action=list');
            if (requestId !== historyRequest) return;
            const items = (data.reports || []).filter(r => r.type === 'AI Summarized Report' || r.type === 'AI Full Report');
            document.getElementById('aiHistoryCount').textContent = `${items.length} report${items.length === 1 ? '' : 's'}`;
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
                return;
            }
            items.forEach(item => {
                const card = document.createElement('a');
                card.className = 'history-item report-history-link';
                card.href = 'reports_api.php?action=file&id=' + encodeURIComponent(item.id);
                card.target = '_blank';
                card.rel = 'noopener noreferrer';
                card.title = 'Open PDF report in a new tab';
                const reportType = document.createElement('strong');
                reportType.textContent = item.type;
                const reportMeta = document.createElement('small');
                const rawDate = String(item.generated_at || '');
                const generatedAt = new Date(rawDate.replace(' ', 'T'));
                const when = Number.isNaN(generatedAt.getTime()) ? (rawDate || 'Date unavailable') : generatedAt.toLocaleString('en-PH');
                reportMeta.textContent = when + ' \u2022 by ' + (item.generated_by || 'Unknown author');
                const openLabel = document.createElement('span');
                openLabel.className = 'report-history-action';
                openLabel.textContent = 'Open PDF in a new tab';
                card.append(reportType, reportMeta, openLabel);
                list.appendChild(card);
            });
        } catch (e) {
            if (requestId !== historyRequest) return;
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
        } finally {
            if (requestId === historyRequest) list.setAttribute('aria-busy', 'false');
        }
    }

    async function generateAiReport(mode, button) {
        if (generating) return;
        generating = true;
        reportButtons.forEach(control => { control.disabled = true; });
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
            reportButtons.forEach(control => { control.disabled = false; });
            button.classList.remove('loading');
            button.setAttribute('aria-busy', 'false');
        }
    }

    document.getElementById('generateSummarizedReport').addEventListener('click', e => generateAiReport('summary', e.currentTarget));
    document.getElementById('generateFullReport').addEventListener('click', e => generateAiReport('full', e.currentTarget));

    loadHistory();
});
