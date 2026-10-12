/* Personal recipient copies and independent logical sender history. */
(() => {
    'use strict';
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text != null) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const when = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('en-PH') : 'Not recorded';
    function mount(options) {
        const { list, count, pager } = options;
        const endpoint = options.endpoint || 'notifications_api.php';
        const sentView = options.support ? 'requests' : 'sent_history';
        const inboxView = options.support ? 'responses' : 'list';
        let sequence = 0, page = 1, busy = false;
        const controls = element('div', null, 'notification-filters');
        function select(label, choices, key) {
            const wrapper = element('label', label);
            const input = element('select'); input.dataset.notificationFilter = key;
            for (const [value, text] of choices) input.add(new Option(text, value));
            wrapper.append(input); controls.append(wrapper);
            input.addEventListener('change', () => { syncStatus(); load(1); });
            return input;
        }
        const view = select('View', options.support ? [['responses', 'Support Responses'], ['requests', 'My Requests']] : options.allowSent ? [['list', 'Inbox'], ['sent_history', 'Sent History'], ['legacy_history', 'Legacy History']] : [['list', 'Inbox']], 'view');
        const status = select('Status', [['All', 'All'], ['Unread', 'Unread'], ['Read', 'Read'], ['Archived', 'Archived']], 'status');
        const kind = select('Kind', options.support ? [['support', 'Support']] : [['All', 'All'], ['staff', 'Staff'], ['workflow', 'Workflow'], ['system', 'System'], ['support', 'Support']], 'kind');
        const sort = select('Sort', [['Newest', 'Newest'], ['Oldest', 'Oldest']], 'sort');
        const feedback = element('p', '', 'notification-result'); feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite');
        list.before(controls, feedback);
        list.classList.add('notification-history-scroll'); list.tabIndex = 0;
        list.setAttribute('role', 'region'); list.setAttribute('aria-label', options.support ? 'Support history' : 'Notification history');
        function syncStatus() {
            const personal = view.value === inboxView;
            for (const option of status.options) if (['Unread', 'Read'].includes(option.value)) { option.hidden = !personal; option.disabled = !personal; }
            if (!personal && ['Unread', 'Read'].includes(status.value)) status.value = 'All';
            if (options.markAll) options.markAll.hidden = !personal || status.value === 'Archived';
        }
        async function mutate(row, operation, button) {
            if (busy) return;
            const sender = view.value === sentView;
            if (operation === 'delete' || operation === 'cancel') {
                const message = operation === 'cancel' ? 'Cancel this scheduled notification before it is sent?'
                    : sender ? 'This permanently removes this item from your Sent History. Recipient copies are not deleted.'
                        : 'This permanently removes your copy of this notification.';
                if (!await PrismUI.confirm({ title: operation === 'cancel' ? 'Cancel Scheduled Notification' : 'Delete Permanently', message,
                    confirmText: operation === 'cancel' ? 'Cancel Scheduled Notification' : 'Delete Permanently', tone: 'danger', focusCancel: true })) return;
            }
            busy = true; button.disabled = true;
            try {
                await PrismUI.postJson(`${endpoint}?action=${sender ? 'campaign_' : ''}${operation}`, { id: Number(row.id), confirmed: operation === 'delete' });
                feedback.textContent = operation === 'delete' ? 'Your copy was permanently deleted.' : operation === 'cancel' ? 'Scheduled notification cancelled.' : operation === 'mark_read' ? 'Notification marked read.' : operation === 'restore' ? 'Notification restored.' : 'Notification archived.';
                await load(page);
                if (!button.isConnected) list.focus();
                if (options.onChange) await options.onChange();
            } catch (error) { feedback.textContent = error.message; PrismUI.toast(error.message, 'error'); }
            finally { busy = false; if (button.isConnected) button.disabled = false; }
        }
        function render(data) {
            list.replaceChildren();
            const sender = view.value === sentView;
            for (const row of data.notifications || []) {
                const card = element('article', null, 'history-item notice');
                card.append(element('strong', row.subject || row.type || 'Notification'));
                const meta = element('div', null, 'notification-history-meta');
                const delivery = element('small');
                delivery.append(PrismUI.badgeElement(row.archived_at ? 'Archived' : row.kind === 'support' && row.status === 'Sent' ? 'Available in PRISM' : row.status || 'Status not recorded'));
                meta.append(element('span', row.kind || 'System'), delivery);
                if (row.kind === 'support') meta.append(element('span', row.type || (row.reply_to_campaign_id ? 'Support Response' : 'Support Request')), element('span', row.support_category || ''), element('span', row.created_by || ''));
                if (sender) meta.append(element('span', `${row.recipient_count ?? 0} recipient copies`));
                else meta.append(element('span', row.read_at ? 'Read' : 'Unread'));
                if (row.legacy) meta.append(element('span', 'Legacy notification'));
                meta.append(element('time', row.status === 'Scheduled' ? `Scheduled for ${when(row.scheduled_at)}` : when(row.sent_at || row.created_at)));
                card.append(meta);
                if (row.kind !== 'support') card.append(element('p', row.message || ''));
                const actions = element('div', null, 'notification-copy-actions');
                function action(label, operation) {
                    const button = element('button', label, 'notification-detail-button'); button.type = 'button';
                    button.dataset.notificationAction = operation; button.addEventListener('click', () => mutate(row, operation, button)); actions.append(button);
                }
                if (options.detail || row.kind === 'support') {
                    const button = element('button', 'View details', 'notification-detail-button'); button.type = 'button'; button.dataset.noticeDetail = row.id;
                    button.addEventListener('click', () => {
                        if (options.detail) options.detail(row);
                        else {
                            const dialog = element('dialog', null, 'prism-readonly-dialog notification-detail');
                            const title = element('h2', row.subject || 'Support Response'); title.id = 'supportNotificationTitle';
                            dialog.setAttribute('aria-labelledby', title.id);
                            const body = element('p', row.message || ''); body.style.whiteSpace = 'pre-wrap';
                            const close = element('button', 'Close details', 'prism-btn notification-detail-button'); close.type = 'button';
                            close.addEventListener('click', () => dialog.close());
                            dialog.addEventListener('close', () => { dialog.remove(); button.focus(); }, { once: true });
                            dialog.append(title, element('p', [row.support_category, row.created_by, row.created_at].filter(Boolean).join(' · ')), body, close);
                            document.body.append(dialog); dialog.showModal(); close.focus();
                        }
                    }); actions.append(button);
                }
                if (row.can_manage) {
                    if (sender && row.status === 'Scheduled') action('Cancel Scheduled Notification', 'cancel');
                    if (!['Scheduled', 'Sending'].includes(row.status)) {
                        if (!sender && !row.read_at) action('Mark read', 'mark_read');
                        if (row.archived_at) { action('Restore', 'restore'); action('Delete Permanently', 'delete'); }
                        else action('Archive', 'archive');
                    }
                }
                card.append(actions); list.append(card);
            }
            if (!list.children.length) list.append(element('p', 'No notifications match this view.', 'workspace-empty-state'));
            PrismUI.pagination(pager, count, { ...data, total: data.total ?? 0 }, load, 'notifications');
            if (options.markAll) options.markAll.disabled = !(data.unread_count > 0);
            document.dispatchEvent(new CustomEvent('prism:notification-count',{detail:{unread:data.unread_count}}));
        }
        async function load(nextPage = 1) {
            page = nextPage; const current = ++sequence;
            list.setAttribute('aria-busy', 'true');
            const query = new URLSearchParams({ action: view.value, status: status.value, kind: kind.value, sort: sort.value, page: String(page) });
            try {
                const data = await PrismUI.request(`${endpoint}?${query}`, options.support
                    ? { headers: { 'X-PRISM-Generation': window.PRISM_SESSION_GENERATION || '' } } : undefined);
                if (current !== sequence) return;
                page = data.page ?? page; render(data);
            } catch (error) {
                if (current !== sequence) return;
                feedback.textContent = error.message;
                list.replaceChildren(element('p', error.message)); count.textContent = 'History unavailable'; pager.replaceChildren();
            } finally { if (current === sequence) list.setAttribute('aria-busy', 'false'); }
        }
        if (options.markAll) options.markAll.addEventListener('click', async () => {
            if (busy) return; busy = true; options.markAll.disabled = true;
            try {
                await PrismUI.postJson('notifications_api.php?action=mark_all_read', {});
                feedback.textContent = 'Your active notifications were marked read.';
                await load(page); if (options.onChange) await options.onChange();
            } catch (error) { feedback.textContent = error.message; options.markAll.disabled = false; }
            finally { busy = false; }
        });
        syncStatus();
        return { load, sent: () => { view.value = sentView; status.value = 'All'; syncStatus(); return load(1); } };
    }
    window.PrismNotifications = { mount };
})();
