document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const form = document.getElementById('supportForm'), result = document.getElementById('supportResult');
    if (!form || form.dataset.prismSupportInitialized === '1') return;
    form.dataset.prismSupportInitialized = '1';
    const dirty = PrismUI.dirtyForm(form);
    let sending = false;
    const history = PrismNotifications.mount({ list: document.getElementById('supportHistory'), count: document.getElementById('supportCount'),
        pager: document.getElementById('supportPagination'), support: true, endpoint: 'help_support_api.php',
        detail: row => {
            document.getElementById('supportDetailTitle').textContent = row.subject || 'Support';
            document.getElementById('supportDetailMeta').textContent = [row.type || 'Support Request', row.support_category, row.created_by, row.created_at].filter(Boolean).join(' · ');
            document.getElementById('supportDetailBody').textContent = row.message || '';
            document.getElementById('supportDetail').showModal();
        } });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (sending) return;
        sending = true; const button = form.querySelector('[type=submit]'); button.disabled = true; result.textContent = 'Sending…';
        try {
            const data = await PrismUI.postJson('help_support_api.php?action=submit', {
                category: document.getElementById('supportCategory').value, subject: document.getElementById('supportSubject').value,
                message: document.getElementById('supportMessage').value, request_token: document.getElementById('supportToken').value });
            form.reset(); document.getElementById('supportToken').value = data.request_token; dirty.clean();
            result.textContent = data.message; await history.sent();
        } catch (error) { result.textContent = error.message; }
        finally { sending = false; button.disabled = false; }
    });
    history.load();
});
