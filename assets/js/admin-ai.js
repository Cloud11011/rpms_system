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
            const res = await fetch('reports_api.php?action=list');
            const data = await res.json();
            const items = data.ok ? data.reports.filter(r => r.type === 'AI Summarized Report' || r.type === 'AI Full Report') : [];
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
                    <small>${esc(new Date(item.generated_at).toLocaleString('en-PH'))} &bull; by ${esc(item.generated_by)}</small>`;
                list.appendChild(card);
            });
        } catch (_) { /* leave empty */ }
    }

    async function generateAiReport(mode, button) {
        const originalHTML = button.innerHTML;
        button.disabled = true;
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
            await loadHistory();
            window.open(`reports_api.php?action=file&id=${encodeURIComponent(data.report.id)}&download=1`, '_blank');
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

    loadHistory();
});
