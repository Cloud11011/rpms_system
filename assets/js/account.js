document.addEventListener('DOMContentLoaded', () => {
    const $ = id => document.getElementById(id);
    const fmt = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('en-PH') : 'N/A';

    $('themeToggle')?.addEventListener('click', () => {
        const dark = document.documentElement.classList.toggle('dark-theme');
        try { localStorage.setItem('prismTheme', dark ? 'dark' : 'light'); } catch (_) {}
    });

    async function loadProfile() {
        try {
            const data = await PrismUI.request('profile_api.php?action=me');
            const u = data.user || {};
            $('accountName').value = u.name || '';
            $('accountEmail').value = u.email || '';
            $('accountRole').value = u.role || '';
            $('accountRef').value = u.refId || '';
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        }
    }

    $('accountProfileForm').addEventListener('submit', async e => {
        e.preventDefault();
        const name = $('accountName').value.trim();
        const btn = e.currentTarget.querySelector('[type="submit"]');
        btn.disabled = true;
        try {
            await PrismUI.postJson('profile_api.php?action=update_profile', { name });
            $('sideAccountName').textContent = name;
            PrismUI.toast('Profile updated.', 'success');
        } catch (err) {
            PrismUI.toast(err.message, 'error');
        } finally { btn.disabled = false; }
    });

    $('accountPasswordForm').addEventListener('submit', async e => {
        e.preventDefault();
        const currentPassword = $('accountCurrentPassword').value;
        const newPassword = $('accountNewPassword').value;
        if (newPassword !== $('accountConfirmPassword').value) {
            PrismUI.toast('New passwords do not match.', 'error');
            return;
        }
        const btn = e.currentTarget.querySelector('[type="submit"]');
        btn.disabled = true;
        try {
            await PrismUI.postJson('profile_api.php?action=change_password', { currentPassword, newPassword });
            e.currentTarget.reset();
            PrismUI.toast('Password changed successfully.', 'success');
        } catch (err) {
            PrismUI.toast(err.message, 'error');
        } finally { btn.disabled = false; }
    });

    async function loadActivity() {
        const qs = new URLSearchParams({ action: 'list', limit: '100' });
        if ($('activitySearch').value.trim()) qs.set('q', $('activitySearch').value.trim());
        if ($('activityFrom').value) qs.set('from', $('activityFrom').value);
        if ($('activityTo').value) qs.set('to', $('activityTo').value);
        if ($('activityOverride').checked) qs.set('override', '1');
        const host = $('activityList');
        host.replaceChildren();
        try {
            const data = await PrismUI.request('audit_api.php?' + qs.toString());
            const entries = data.entries || [];
            $('activityCount').textContent = entries.length + (entries.length === 1 ? ' entry' : ' entries');
            if (!entries.length) {
                host.innerHTML = PrismUI.emptyState({ icon:'fa-clock-rotate-left', title:'No matching activity', text:'Try different filters or check back after PRISM actions are recorded.' });
                return;
            }
            entries.forEach(x => {
                const row = document.createElement('article');
                row.className = 'activity-row';
                const student = x.studentName ? ' · ' + x.studentName + (x.protocolCode ? ' (' + x.protocolCode + ')' : '') : '';
                row.innerHTML = '<header><strong>' + PrismUI.esc(x.actionLabel || x.action) + '</strong><time>' + PrismUI.esc(fmt(x.at)) + '</time></header>' +
                    '<small>' + PrismUI.esc((x.actorName || x.actorEmail || 'System') + student) + (x.override ? ' · Admin Override' : '') + '</small>' +
                    (x.details ? '<p>' + PrismUI.esc(x.details) + '</p>' : '') +
                    (x.reason ? '<small>Reason: ' + PrismUI.esc(x.reason) + '</small>' : '');
                host.appendChild(row);
            });
        } catch (e) {
            host.innerHTML = PrismUI.emptyState({ icon:'fa-triangle-exclamation', title:'Could not load activity', text:e.message });
        }
    }

    $('activityFilterForm').addEventListener('submit', e => { e.preventDefault(); loadActivity(); });
    loadProfile();
    loadActivity();
});