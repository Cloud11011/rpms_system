document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

    const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c]);

    function showHistoryState(iconClass, titleText, descriptionText) {
        historyList.replaceChildren();
        const state = document.createElement('div');
        state.className = 'workspace-empty-state';
        const icon = document.createElement('i');
        icon.className = `fa-solid ${iconClass}`;
        icon.setAttribute('aria-hidden', 'true');
        const title = document.createElement('strong');
        title.textContent = titleText;
        const description = document.createElement('span');
        description.textContent = descriptionText;
        state.append(icon, title, description);
        historyList.appendChild(state);
    }

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
    function syncScheduleUi() {
        scheduleLabel.hidden = !automated.checked;
        scheduleInput.required = automated.checked;
        document.getElementById('noticeSubmitLabel').textContent = automated.checked ? 'Schedule notification' : 'Send notification';
        if (automated.checked) {
            const now = new Date(Date.now() + 60 * 1000);
            now.setSeconds(0, 0);
            scheduleInput.min = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        } else {
            scheduleInput.value = '';
        }
    }
    automated.addEventListener('change', syncScheduleUi);

    let historyRequestSequence = 0;
    async function loadHistory() {
        const requestSequence = ++historyRequestSequence;
        historyCount.textContent = 'Loading notifications...';
        historyList.setAttribute('aria-busy', 'true');
        try {
            const data = await PrismUI.request('notifications_api.php?action=list');
            if (requestSequence !== historyRequestSequence) return;
            const items = data.notifications || [];
            historyCount.textContent = `${items.length} notification${items.length === 1 ? '' : 's'}`;
            historyList.replaceChildren();
            if (!items.length) {
                showHistoryState('fa-bell', 'No notifications yet', 'Sent and scheduled notifications will appear here with their recipients and delivery status.');
                return;
            }
            items.forEach(n => {
                const card = document.createElement('article');
                card.className = 'history-item';
                const statusClass = String(n.status || '').toLowerCase();
                const when = n.status === 'Scheduled' && n.scheduled_at
                    ? `Scheduled for ${new Date(n.scheduled_at.replace(' ', 'T')).toLocaleString('en-PH')}`
                    : new Date(n.created_at.replace(' ', 'T')).toLocaleString('en-PH');
                card.innerHTML = `<strong>${esc(n.subject || n.type)}</strong>
                    <small>${esc(n.recipient_name || n.recipient_email)} &bull; ${esc(n.type)} &bull;
                    <span class="status-badge ${esc(statusClass)}">${esc(n.status)}</span> &bull;
                    ${esc(when)}</small>
                    <p>${esc(n.message)}</p>${n.delivery_info ? `<small>${esc(n.delivery_info)}</small>` : ''}`;
                historyList.appendChild(card);
            });
        } catch (e) {
            if (requestSequence !== historyRequestSequence) return;
            historyCount.textContent = 'History unavailable';
            showHistoryState('fa-triangle-exclamation', 'Could not load notification history', e.message);
        } finally {
            if (requestSequence === historyRequestSequence) historyList.setAttribute('aria-busy', 'false');
        }
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
        if (!payload.message) {
            PrismUI.toast('Write a message before sending.', 'error');
            message.focus();
            return;
        }
        if (payload.audience === 'Specific Research Group' && !payload.group) {
            PrismUI.toast('Enter a research group for this audience.', 'error');
            groupInput.focus();
            return;
        }
        if (payload.automated && !payload.scheduleAt) {
            PrismUI.toast('Choose when this notification should be sent.', 'error');
            scheduleInput.focus();
            return;
        }
        const submitBtn = form.querySelector('[type="submit"]');
        submitBtn.disabled = true;
        try {
            const data = await PrismUI.postJson('notifications_api.php?action=send', payload);
            const deliveryText = data.scheduled
                ? `Scheduled for ${data.total} recipient(s).`
                : (data.logged
                    ? `Delivered to ${data.sent}; logged locally for ${data.logged} recipient(s) because live email is not configured.`
                    : `Sent to ${data.sent} of ${data.total} recipient(s).`);
            PrismUI.toast(deliveryText, data.logged ? 'info' : 'success');
            form.reset();
            groupLabel.hidden = true;
            syncScheduleUi();
            await loadHistory();
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        } finally {
            submitBtn.disabled = false;
        }
    });

    syncScheduleUi();
    loadHistory();
});
