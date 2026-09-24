document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

    const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c]);

    async function loadHistory() {
        try {
            const data = await PrismUI.request('reports_api.php?action=list');
            const items = (data.reports || []).filter(r => r.type === 'AI Summarized Report' || r.type === 'AI Full Report');
            document.getElementById('aiHistoryCount').textContent = `${items.length} report${items.length === 1 ? '' : 's'}`;
            const list = document.getElementById('aiHistory');
            list.replaceChildren();
            if (!items.length) {
                const empty = document.createElement('p');
                empty.className = 'empty-state';
                empty.textContent = 'No AI reports generated yet.';
                list.appendChild(empty);
                return;
            }
            items.forEach(item => {
                const card = document.createElement('article');
                card.className = 'history-item';
                card.style.cursor = 'pointer';
                card.title = 'Open PDF report';
                card.addEventListener('click', () => window.open(`reports_api.php?action=file&id=${encodeURIComponent(item.id)}`, '_blank'));
                card.innerHTML = `<strong>${esc(item.type)}</strong>
                    <small>${esc(new Date(item.generated_at.replace(' ', 'T')).toLocaleString('en-PH'))} &bull; by ${esc(item.generated_by)}</small>`;
                list.appendChild(card);
            });
        } catch (e) {
            PrismUI.toast(e.message || 'Could not load AI report history.', 'error');
        }
    }

    async function generateAiReport(mode, button) {
        const originalHTML = button.innerHTML;
        button.disabled = true;
        button.classList.add('loading');
        const note = document.getElementById('reportAiNote');
        note.textContent = '';
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
            button.disabled = false;
            button.classList.remove('loading');
            button.innerHTML = originalHTML;
        }
    }

    document.getElementById('generateSummarizedReport').addEventListener('click', e => generateAiReport('summary', e.currentTarget));
    document.getElementById('generateFullReport').addEventListener('click', e => generateAiReport('full', e.currentTarget));

    loadHistory();
});
