document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

    const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c]);

    const form = document.getElementById('notificationForm');
    const audience = document.getElementById('noticeAudience');
    const groupLabel = document.getElementById('groupLabel');
    const groupInput = document.getElementById('noticeGroup');
    const type = document.getElementById('noticeType');
    const message = document.getElementById('noticeMessage');
    const automated = document.getElementById('automatedNotice');
    const scheduleLabel = document.getElementById('scheduleLabel');
    const scheduleInput = document.getElementById('noticeSchedule');
    const historyList = document.getElementById('noticeHistory');
    const historyCount = document.getElementById('noticeCount');

    audience.addEventListener('change', () => {
        groupLabel.hidden = audience.value !== 'Specific Research Group';
    });
    automated.addEventListener('change', () => {
        scheduleLabel.hidden = !automated.checked;
    });

    async function loadHistory() {
        try {
            const res = await fetch('notifications_api.php?action=list');
            const data = await res.json();
            const items = data.ok ? data.notifications : [];
            historyCount.textContent = `${items.length} notification${items.length === 1 ? '' : 's'}`;
            historyList.replaceChildren();
            if (!items.length) {
                const empty = document.createElement('p');
                empty.className = 'empty-state';
                empty.textContent = 'No notifications sent yet.';
                historyList.appendChild(empty);
                return;
            }
            items.forEach(n => {
                const card = document.createElement('article');
                card.className = 'history-item';
                const statusClass = String(n.status || '').toLowerCase();
                card.innerHTML = `<strong>${esc(n.subject || n.type)}</strong>
                    <small>${esc(n.recipient_name || n.recipient_email)} &bull; ${esc(n.type)} &bull;
                    <span class="status-badge ${esc(statusClass)}">${esc(n.status)}</span> &bull;
                    ${esc(new Date(n.created_at).toLocaleString('en-PH'))}</small>
                    <p>${esc(n.message)}</p>`;
                historyList.appendChild(card);
            });
        } catch (_) { /* leave empty */ }
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const payload = {
            audience: audience.value,
            group: groupInput.value.trim(),
            type: type.value,
            message: message.value.trim(),
            automated: automated.checked,
            scheduleAt: scheduleInput.value,
        };
        const submitBtn = form.querySelector('[type="submit"]');
        submitBtn.disabled = true;
        try {
            const res = await fetch('notifications_api.php?action=send', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (!data.ok) { alert(data.message || 'This notification could not be sent.'); return; }
            alert(data.scheduled
                ? `Scheduled for ${data.total} recipient(s).`
                : `Sent to ${data.sent} of ${data.total} recipient(s).`);
            form.reset();
            groupLabel.hidden = true;
            scheduleLabel.hidden = true;
            await loadHistory();
        } catch (_) {
            alert('Could not reach the server to send this notification.');
        } finally {
            submitBtn.disabled = false;
        }
    });

    loadHistory();
});
