/* Official dashboard deadlines are server reads, separate from personal reminder storage. */
(() => {
    'use strict';
    let sequence = 0;
    const dateKey = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const displayDate = key => new Date(key + 'T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
    const endpoint = params => 'calendar_deadlines_api.php?' + new URLSearchParams(params);
    async function openDay(key, trigger) {
        if (!window.PrismReadOnly) return;
        const { dialog, node, button } = PrismReadOnly;
        const view = dialog('Official deadlines — ' + displayDate(key), trigger);
        view.element.addEventListener('close',()=>{
            if (!trigger?.isConnected && trigger?.dataset.calendarDate) document.querySelector(`[data-calendar-date="${key}"]`)?.focus({preventScroll:true});
        },{once:true});
        const state = node('p', 'Loading official deadlines...');
        state.setAttribute('role', 'status');
        view.content.append(state);
        const close = button('Close', view.close);
        const calendar = node('a', 'View Calendar', 'prism-btn is-secondary');
        calendar.href = document.body.classList.contains('student-page') ? '#calendar' : 'calendar.php';
        if(document.body.classList.contains('student-page'))calendar.addEventListener('click',()=>view.close(false));
        view.actions.append(calendar, close);
        close.focus();
        try {
            const rows = [];
            let page = 1, pages = 1;
            do {
                const data = await PrismUI.request(endpoint({ action: 'dashboard_day', date: key, page }));
                if (!view.element.isConnected) return;
                rows.push(...data.deadlines);
                pages = data.pages;
                page++;
            } while (page <= pages);
            view.content.replaceChildren();
            const unique = [...new Map(rows.map(row => [row.id, row])).values()];
            if (!unique.length) view.content.append(node('p', 'No active official deadlines for this date.'));
            for (const row of unique) {
                const article = node('article', null, 'prism-deadline-detail');
                article.append(node('h3', row.title));
                const details = node('dl');
                const groups = row.target_scope === 'all'
                    ? (document.body.dataset.userRole === 'admin' ? 'All research groups' : 'Institution-wide official deadline')
                    : row.groups.join(', ');
                for (const [label, value] of [['Deadline date', displayDate(row.deadline_date)], ['Research groups', groups || 'No currently assigned research groups'], ['Status', row.status]]) {
                    details.append(node('dt', label), node('dd', value));
                }
                article.append(details);
                view.content.append(article);
            }
        } catch (_) {
            if (!view.element.isConnected) return;
            state.textContent = 'Could not load official deadlines. Close and select this date to try again.';
            state.setAttribute('role', 'alert');
        }
    }
    async function refresh(date, mode = 'month') {
        const state = document.getElementById('dashboardDeadlineState');
        if (!state) return;
        const current = ++sequence;
        state.textContent = 'Loading official deadlines...';
        state.setAttribute('aria-busy', 'true');
        const cells = [...document.querySelectorAll('[data-deadline-date]')];
        cells.forEach(cell => {
            cell.dataset.officialDeadline = '';
            cell.querySelector('.prism-deadline-marker').hidden = true;
            cell.setAttribute('aria-label', 'View ' + displayDate(cell.dataset.deadlineDate));
        });
        try {
            // The existing range API is limited to 63 days; year view reads one month at a time.
            const months = mode === 'year' ? [...Array(12).keys()] : [date.getMonth()];
            const responses = await Promise.all(months.map(month => PrismUI.request(endpoint({ action: 'dates',
                from: dateKey(new Date(date.getFullYear(), month, 1)), to: dateKey(new Date(date.getFullYear(), month + 1, 0)) }))));
            if (current !== sequence) return;
            const counts = new Map(responses.flatMap(data => data.dates).map(row => [row.deadline_date, Number(row.total)]));
            cells.forEach(cell => {
                const count = counts.get(cell.dataset.deadlineDate);
                if (!count) return;
                cell.dataset.officialDeadline = 'true';
                cell.querySelector('.prism-deadline-marker').hidden = false;
                cell.setAttribute('aria-label', `${displayDate(cell.dataset.deadlineDate)}: ${count} official ${count === 1 ? 'deadline' : 'deadlines'}. View details.`);
            });
            state.textContent = counts.size ? 'Calendar icon: official deadlines. Select a marked date to view details.' : 'No active official deadlines in this view.';
        } catch (_) {
            if (current !== sequence) return;
            state.textContent = 'Could not load official deadlines. Use View Calendar or change the month to try again.';
        } finally { if (current === sequence) state.setAttribute('aria-busy', 'false'); }
    }
    window.PrismDashboardDeadlines = { refresh, openDay };
    document.addEventListener('DOMContentLoaded', () => {
        const adminDays = document.getElementById('calendarDays');
        const adviserDays = document.getElementById('adviserCalendarDays');
        const studentDays = document.getElementById('dashboardCalendarGrid');
        if (!adminDays && !adviserDays && !studentDays) return;
        const container = adminDays ? adminDays.closest('.calendar-card') || adminDays.parentElement.parentElement : (adviserDays || studentDays).closest('.prism-deadline-calendar,.dashboard-calendar-panel');
        // Capture before the existing Admin personal-task click handler or year/month handler.
        const activate = event => {
            const trigger = event.target.closest('[data-deadline-date]');
            if (trigger?.dataset.officialDeadline !== 'true') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            openDay(trigger.dataset.deadlineDate, trigger);
        };
        container.addEventListener('click', activate, true);
        document.getElementById('yearCalendarView')?.addEventListener('click', activate, true);
        if (adviserDays) {
            const date = new Date();
            date.setDate(1);
            function render() {
                document.getElementById('adviserCalendarMonth').textContent = date.toLocaleDateString('en-PH', { year: 'numeric', month: 'long' });
                adviserDays.replaceChildren();
                for (let i = 0; i < date.getDay(); i++) adviserDays.append(document.createElement('span'));
                const last = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
                for (let day = 1; day <= last; day++) {
                    const cell = document.createElement('button');
                    cell.type = 'button';
                    cell.textContent = day;
                    cell.dataset.deadlineDate = dateKey(new Date(date.getFullYear(), date.getMonth(), day));
                    const icon = document.createElement('i');
                    icon.className = 'fa-solid fa-calendar-day prism-deadline-marker';
                    icon.setAttribute('aria-hidden', 'true');
                    icon.hidden = true;
                    cell.append(icon);
                    cell.addEventListener('click', () => openDay(cell.dataset.deadlineDate, cell));
                    adviserDays.append(cell);
                }
                refresh(date);
            }
            container.querySelectorAll('[data-deadline-month]').forEach(button => button.addEventListener('click', () => {
                date.setMonth(date.getMonth() + Number(button.dataset.deadlineMonth)); render();
            }));
            render();
        } else if (adminDays) refresh(new Date());
    });
})();
