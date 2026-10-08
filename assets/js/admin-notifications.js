document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

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
    const dirty = PrismUI.dirtyForm(form);
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

    const recipientPreview = document.getElementById('noticeRecipientPreview');
    const messageCount = document.getElementById('noticeMessageCount');
    const cancelButton = document.getElementById('cancelNotification');
    const detailDialog = document.getElementById('notificationDetail');
    let recipientRequestSequence = 0;
    let recipientPreviewTimer;
    let sending = false;

    function syncMessageCount() {
        messageCount.textContent = `${Array.from(message.value).length} / 600 characters`;
    }

    function refreshRecipientPreview(delay = 0) {
        // Invalidate immediately, including while a new debounced request is waiting.
        const requestSequence = ++recipientRequestSequence;
        clearTimeout(recipientPreviewTimer);
        groupLabel.hidden = audience.value !== 'Specific Research Group';
        const payload = { audience: audience.value, group: groupInput.value.trim() };
        recipientPreview.classList.remove('is-error');
        if (payload.audience === 'Specific Research Group' && !payload.group) {
            recipientPreview.textContent = 'Choose a research group to preview its recipients.';
            recipientPreview.setAttribute('aria-busy', 'false');
            return;
        }
        recipientPreview.textContent = 'Checking recipients...';
        recipientPreview.setAttribute('aria-busy', 'true');
        recipientPreviewTimer = setTimeout(async () => {
            try {
                const data = await PrismUI.postJson('notifications_api.php?action=recipients_preview', payload);
                if (requestSequence !== recipientRequestSequence) return;
                if (!Array.isArray(data.recipients)) throw new Error('Recipient preview is unavailable.');
                const count = data.recipients.length;
                recipientPreview.textContent = count
                    ? `${count} matching recipient${count === 1 ? '' : 's'}. Recipients are checked again when sending.`
                    : 'No matching recipients. Choose another audience or check the research group.';
            } catch (e) {
                if (requestSequence !== recipientRequestSequence) return;
                recipientPreview.classList.add('is-error');
                recipientPreview.textContent = `Could not preview recipients. ${e.message || 'Please try again.'}`;
            } finally {
                if (requestSequence === recipientRequestSequence) recipientPreview.setAttribute('aria-busy', 'false');
            }
        }, delay);
    }

    audience.addEventListener('change', () => refreshRecipientPreview());
    groupInput.addEventListener('change', () => refreshRecipientPreview());
    (async function loadGroups() {
        try {
            const data = await PrismUI.request('notifications_api.php?action=group_options');
            groupInput.replaceChildren(new Option(data.groups.length ? 'Choose a research group' : 'No standardized research groups available', ''));
            data.groups.forEach(id => groupInput.add(new Option(id, id)));
            groupInput.disabled = !data.groups.length;
        } catch (_) {
            groupInput.replaceChildren(new Option('Research groups could not be loaded. Reload to retry.', ''));
            groupInput.disabled = true;
        }
    })();
    message.addEventListener('input', syncMessageCount);
    form.addEventListener('reset', () => {
        // Native reset applies field defaults after the reset event has finished.
        queueMicrotask(() => {
            syncScheduleUi();
            syncMessageCount();
            refreshRecipientPreview();
            dirty.clean();
        });
    });

    function formatNoticeDate(value) {
        if (!value) return 'Not recorded';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('en-PH');
    }

    function showNoticeDetail(notification) {
        document.getElementById('notificationDetailTitle').textContent = notification.subject || notification.type || 'Notification';
        const metadata = document.getElementById('notificationDetailMeta');
        metadata.replaceChildren();
        const fields = [
            ['Recipient', notification.recipient_name],
            ['Email', notification.recipient_email],
            ['Recipient type', notification.recipient_type],
            ['Recipient ID', notification.recipient_id],
            ['Notification ID', notification.id],
            ['Type', notification.type],
            ['Status', notification.status],
            ['Created by', notification.created_by],
            ['Created', formatNoticeDate(notification.created_at)],
            ['Scheduled for', notification.scheduled_at ? formatNoticeDate(notification.scheduled_at) : 'Not scheduled'],
            ['Sent at', notification.sent_at ? formatNoticeDate(notification.sent_at) : 'Not recorded'],
            ['Read at', notification.read_at ? formatNoticeDate(notification.read_at) : 'Not recorded'],
        ];
        fields.forEach(([label, value]) => {
            const term = document.createElement('dt');
            term.textContent = label;
            const description = document.createElement('dd');
            description.textContent = value == null || value === '' ? 'Not recorded' : String(value);
            metadata.append(term, description);
        });
        document.getElementById('notificationDetailMessage').textContent = notification.message || '';
        document.getElementById('notificationDetailDelivery').textContent = notification.delivery_info || 'No delivery information recorded.';
        if (!detailDialog.open) detailDialog.showModal();
    }
    function syncScheduleUi() {
        scheduleLabel.hidden = !automated.checked;
        scheduleInput.required = automated.checked;
        if (!sending) document.getElementById('noticeSubmitLabel').textContent = automated.checked ? 'Schedule notification' : 'Send notification';
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
    async function loadHistory(page = 1) {
        const requestSequence = ++historyRequestSequence;
        historyCount.textContent = 'Loading notifications...';
        historyList.setAttribute('aria-busy', 'true');
        const pager = document.getElementById('noticePagination');
        pager.querySelectorAll('button').forEach(button => { button.disabled = true; });
        try {
            const data = await PrismUI.request(`notifications_api.php?action=list&page=${page}`);
            if (requestSequence !== historyRequestSequence) return;
            const items = data.notifications || [];
            PrismUI.pagination(pager, historyCount, { ...data, total: data.total ?? items.length }, loadHistory, 'notifications');
            historyList.replaceChildren();
            if (!items.length) {
                showHistoryState('fa-bell', 'No notifications yet', 'Sent and scheduled notifications will appear here with their recipients and delivery status.');
                return;
            }
            items.forEach(n => {
                const card = document.createElement('article');
                card.className = 'history-item';
                const heading = document.createElement('strong');
                heading.textContent = n.subject || n.type || 'Notification';
                const metadata = document.createElement('small');
                metadata.className = 'notification-history-meta';
                const recipient = document.createElement('span');
                recipient.textContent = n.recipient_name || n.recipient_email || 'Recipient not recorded';
                const kind = document.createElement('span');
                kind.textContent = n.type || 'Notification';
                const status = PrismUI.badgeElement(n.status || 'Status not recorded');
                const when = document.createElement('span');
                when.textContent = n.status === 'Scheduled' && n.scheduled_at
                    ? `Scheduled for ${formatNoticeDate(n.scheduled_at)}`
                    : formatNoticeDate(n.created_at);
                metadata.append(recipient, kind, status, when);
                const content = document.createElement('p');
                content.textContent = n.message || '';
                card.append(heading, metadata, content);
                if (n.delivery_info) {
                    const delivery = document.createElement('small');
                    delivery.textContent = n.delivery_info;
                    card.appendChild(delivery);
                }
                const detailButton = document.createElement('button');
                detailButton.type = 'button';
                detailButton.className = 'notification-detail-button';
                detailButton.dataset.noticeDetail = String(n.id ?? '');
                detailButton.textContent = 'View details';
                detailButton.setAttribute('aria-label', `View details for ${n.subject || n.type || 'notification'} to ${n.recipient_name || n.recipient_email || 'recipient'}`);
                detailButton.addEventListener('click', () => showNoticeDetail(n));
                card.appendChild(detailButton);
                historyList.appendChild(card);
            });
        } catch (e) {
            if (requestSequence !== historyRequestSequence) return;
            historyCount.textContent = 'History unavailable';
            pager.replaceChildren();
            showHistoryState('fa-triangle-exclamation', 'Could not load notification history', e.message);
        } finally {
            if (requestSequence === historyRequestSequence) historyList.setAttribute('aria-busy', 'false');
        }
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (sending) return;
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
        sending = true;
        const release = PrismUI.busy(submitBtn,payload.automated?'Scheduling...':'Sending...',document.getElementById('noticeSubmitLabel'));
        if(!release){sending=false;return;}
        try {
            const data = await PrismUI.postJson('notifications_api.php?action=send', payload);
            const deliveryText = data.queued ? `Queued for delivery to ${data.total} recipient(s).` : data.scheduled
                ? `Scheduled for ${data.total} recipient(s).`
                : (data.logged
                    ? `Delivered to ${data.sent}; logged locally for ${data.logged} recipient(s) because live email is not configured.`
                    : `Sent to ${data.sent} of ${data.total} recipient(s).`);
            PrismUI.toast(deliveryText, data.logged ? 'info' : 'success');
            form.reset(); dirty.clean();
            await loadHistory();
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        } finally {
            sending = false;
            release();
            syncScheduleUi();
        }
    });

    syncScheduleUi();
    syncMessageCount();
    refreshRecipientPreview();
    loadHistory();
});
