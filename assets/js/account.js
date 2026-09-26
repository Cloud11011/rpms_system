document.addEventListener('DOMContentLoaded', () => {
    const $ = id => document.getElementById(id);
    const fmt = value => {
        if (!value) return 'N/A';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('en-PH');
    };
    let activityRequest = 0;

    function renderActivityState(host, iconClass, titleText, descriptionText) {
        host.replaceChildren();
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
        host.appendChild(state);
    }

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
        const form = e.currentTarget;
        const currentPassword = $('accountCurrentPassword').value;
        const newPassword = $('accountNewPassword').value;
        if (newPassword !== $('accountConfirmPassword').value) {
            PrismUI.toast('New passwords do not match.', 'error');
            return;
        }
        const btn = form.querySelector('[type="submit"]');
        btn.disabled = true;
        try {
            await PrismUI.postJson('profile_api.php?action=change_password', { currentPassword, newPassword });
            form.reset();
            PrismUI.toast('Password changed successfully.', 'success');
        } catch (err) {
            PrismUI.toast(err.message, 'error');
        } finally { btn.disabled = false; }
    });

    async function loadActivity() {
        const requestId = ++activityRequest;
        const qs = new URLSearchParams({ action: 'list', limit: '100' });
        if ($('activitySearch').value.trim()) qs.set('q', $('activitySearch').value.trim());
        if ($('activityFrom').value) qs.set('from', $('activityFrom').value);
        if ($('activityTo').value) qs.set('to', $('activityTo').value);
        if ($('activityOverride').checked) qs.set('override', '1');
        const host = $('activityList');
        const count = $('activityCount');
        count.textContent = 'Loading activity...';
        host.setAttribute('aria-busy', 'true');
        try {
            const data = await PrismUI.request('audit_api.php?' + qs.toString());
            if (requestId !== activityRequest) return;
            const entries = data.entries || [];
            count.textContent = entries.length + (entries.length === 1 ? ' entry' : ' entries');
            host.replaceChildren();
            if (!entries.length) {
                renderActivityState(host, 'fa-clock-rotate-left', 'No matching activity', 'Try different filters or check back after PRISM actions are recorded.');
                return;
            }
            entries.forEach(x => {
                const row = document.createElement('article');
                row.className = 'activity-row';

                const header = document.createElement('header');
                const action = document.createElement('strong');
                action.textContent = x.actionLabel || x.action || 'Activity';
                const time = document.createElement('time');
                time.textContent = fmt(x.at);
                header.append(action, time);

                const meta = document.createElement('div');
                meta.className = 'activity-meta';
                const actor = document.createElement('span');
                actor.textContent = x.actorName || x.actorEmail || 'System';
                meta.appendChild(actor);
                if (x.studentName || x.protocolCode) {
                    const student = document.createElement('span');
                    student.textContent = '\u2022 ' + (x.studentName || '') + (x.protocolCode ? (x.studentName ? ` (${x.protocolCode})` : x.protocolCode) : '');
                    meta.appendChild(student);
                }
                if (x.override) {
                    const override = document.createElement('span');
                    override.className = 'activity-override-badge';
                    override.textContent = 'Admin Override';
                    meta.appendChild(override);
                }

                row.append(header, meta);
                if (x.details) {
                    const details = document.createElement('p');
                    details.className = 'activity-details';
                    details.textContent = x.details;
                    row.appendChild(details);
                }
                if (x.reason) {
                    const reason = document.createElement('p');
                    reason.className = 'activity-reason';
                    const label = document.createElement('strong');
                    label.textContent = 'Reason: ';
                    reason.append(label, document.createTextNode(x.reason));
                    row.appendChild(reason);
                }

                const audit = document.createElement('details');
                audit.className = 'activity-audit-details';
                const summary = document.createElement('summary');
                summary.textContent = 'Audit details';
                const fields = document.createElement('dl');
                fields.className = 'activity-audit-fields';
                [
                    ['Entry ID', x.id], ['Action code', x.action], ['Actor email', x.actorEmail],
                    ['Actor role', x.actorRole], ['Entity type', x.entityType], ['Entity ID', x.entityId],
                    ['Student ID', x.studentId], ['Recorded at', x.at], ['Before', x.before], ['After', x.after]
                ].forEach(([label, value]) => {
                    if (value === null || value === undefined || value === '') return;
                    const term = document.createElement('dt');
                    term.textContent = label;
                    const detail = document.createElement('dd');
                    detail.textContent = typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);
                    fields.append(term, detail);
                });
                if (fields.children.length) {
                    audit.append(summary, fields);
                    row.appendChild(audit);
                }
                host.appendChild(row);
            });
        } catch (e) {
            if (requestId !== activityRequest) return;
            count.textContent = 'Activity unavailable';
            renderActivityState(host, 'fa-triangle-exclamation', 'Could not load activity', e.message);
        } finally {
            if (requestId === activityRequest) host.setAttribute('aria-busy', 'false');
        }
    }

    $('activityFilterForm').addEventListener('submit', e => { e.preventDefault(); loadActivity(); });
    loadProfile();
    loadActivity();
});
