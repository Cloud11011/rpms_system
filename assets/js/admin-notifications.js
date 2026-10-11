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
    let supportRequestId = null;
    const supportContext = document.getElementById('noticeSupportContext');
    const supportSubject = document.getElementById('noticeSupportSubject');
    const respond = document.getElementById('respondSupport');
    function supportMode(id = null, context = null) {
        supportRequestId = id;
        supportContext.hidden = !id;
        supportContext.textContent = id ? `Support Response to ${context.recipient_name} · ${context.category}. Available in PRISM only.` : '';
        document.getElementById('noticeSupportSubjectLabel').hidden = !id;
        supportSubject.required = !!id;
        document.getElementById('noticeSupportExit').hidden = !id;
        for (const node of [document.getElementById('noticeAudienceLabel'), document.getElementById('noticeTypeLabel'), recipientPreview, automated.closest('label')]) node.hidden = !!id;
        if (id) {
            automated.checked = false; groupLabel.hidden = true; scheduleLabel.hidden = true;
            supportSubject.value = Array.from(`Re: ${context.subject || 'Support request'}`).slice(0, 255).join('');
        } else refreshRecipientPreview();
        syncScheduleUi();
    }
    document.getElementById('noticeSupportExit').addEventListener('click', () => { supportMode(); form.reset(); dirty.clean(); });
    respond.addEventListener('click', async () => {
        if (sending) return;
        respond.disabled = true;
        try {
            const context = await PrismUI.request(`notifications_api.php?action=support_context&id=${respond.dataset.requestId}`,
                { headers: { 'X-PRISM-Generation': window.PRISM_SESSION_GENERATION || '' } });
            detailDialog.close(); supportMode(Number(respond.dataset.requestId), context);
            form.scrollIntoView({ block: 'center' }); supportSubject.focus();
        } catch (error) { PrismUI.toast(error.message, 'error'); }
        finally { respond.disabled = false; }
    });

    function syncMessageCount() {
        messageCount.textContent = `${Array.from(message.value).length} / 600 characters`;
    }

    function refreshRecipientPreview(delay = 0) {
        if (supportRequestId) return;
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
            supportMode();
        });
    });

    function formatNoticeDate(value) {
        if (!value) return 'Not recorded';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('en-PH');
    }

    // Delivery diagnostics stay in storage; the interface uses the recorded status.
    function deliveryStatus(notification) {
        return ['Sent', 'Scheduled', 'Failed', 'Logged', 'Cancelled', 'Queued', 'Sending'].includes(notification.status)
            ? notification.status : 'Status not recorded';
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
            ['Type', notification.type || notification.kind],
            ['Recipient copies', notification.recipient_count],
            ['Status', notification.status],
            ['Created by', notification.created_by],
            ['Category', notification.support_category],
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
        document.getElementById('notificationDetailDelivery').textContent = notification.kind === 'support' ? 'Available in PRISM only.' : deliveryStatus(notification);
        respond.hidden = !(document.body.dataset.userRole === 'admin' && notification.kind === 'support' && notification.type === 'Support Request' && notification.reply_to_campaign_id == null && notification.can_manage);
        respond.dataset.requestId = notification.id;
        if (!detailDialog.open) detailDialog.showModal();
    }
    function syncScheduleUi() {
        scheduleLabel.hidden = !automated.checked;
        scheduleInput.required = automated.checked;
        if (!sending) document.getElementById('noticeSubmitLabel').textContent = supportRequestId ? 'Send Support Response' : automated.checked ? 'Schedule notification' : 'Send notification';
        if (automated.checked) {
            const now = new Date(Date.now() + 60 * 1000);
            now.setSeconds(0, 0);
            scheduleInput.min = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        } else {
            scheduleInput.value = '';
        }
    }
    automated.addEventListener('change', syncScheduleUi);

    const history = PrismNotifications.mount({list: historyList, count: historyCount,
        pager: document.getElementById('noticePagination'), allowSent: true, detail: showNoticeDetail});
    async function loadHistory(page = 1) { return history.load(page); }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (sending) return;
        if (supportRequestId) {
            sending = true;
            const release = PrismUI.busy(form.querySelector('[type="submit"]'), 'Sending…', document.getElementById('noticeSubmitLabel'));
            try {
                const data = await PrismUI.postJson('notifications_api.php?action=support_respond', {
                    request_id: supportRequestId, subject: supportSubject.value, message: message.value,
                    request_token: document.getElementById('noticeSupportToken').value });
                document.getElementById('noticeSupportToken').value = data.request_token;
                PrismUI.toast(data.message, 'success'); form.reset(); dirty.clean(); supportMode(); await history.sent();
            } catch (error) { PrismUI.toast(error.message, 'error'); }
            finally { sending = false; if (release) release(); syncScheduleUi(); }
            return;
        }
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
                    ? `Delivered to ${data.sent}; recorded a metadata-only fallback for ${data.logged} recipient(s).`
                    : `Sent to ${data.sent} of ${data.total} recipient(s).`);
            PrismUI.toast(deliveryText, data.logged ? 'info' : 'success');
            form.reset(); dirty.clean();
            await history.sent();
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
