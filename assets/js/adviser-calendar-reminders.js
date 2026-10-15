document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const days = document.getElementById('adviserCalendarDays');
    const dialog = document.getElementById('adviserReminderDialog');
    const form = document.getElementById('adviserReminderForm');
    const title = document.getElementById('adviserReminderTitle');
    const time = document.getElementById('adviserReminderTime');
    const notes = document.getElementById('adviserReminderNotes');
    const dateHeading = document.getElementById('adviserReminderDate');
    const list = document.getElementById('adviserReminderList');
    const summary = document.getElementById('adviserCalendarTaskSummary');
    const dismiss = document.getElementById('adviserReminderDismiss');
    const close = document.getElementById('adviserReminderClose');
    const add = document.getElementById('adviserAddReminder');
    if (![days, dialog, form, title, time, notes, dateHeading, list, summary, dismiss, close, add].every(Boolean)) return;

    const pad = value => String(value).padStart(2, '0');
    const dateKey = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const fromDateKey = key => {
        const [year, month, day] = key.split('-').map(Number);
        return new Date(year, month - 1, day);
    };
    const formatDate = key => fromDateKey(key).toLocaleDateString('en-PH', { weekday:'long', month:'long', day:'numeric', year:'numeric' });
    const storageKey = `prismReminders:${document.body.dataset.reminderUser || 'default'}`;
    let reminders = {};
    let selectedDate = dateKey(new Date());
    let opener = null;
    try {
        reminders = JSON.parse(localStorage.getItem(storageKey)) || {};
        if (typeof reminders !== 'object' || Array.isArray(reminders)) reminders = {};
    } catch (_) { reminders = {}; }
    const tasksFor = key => Array.isArray(reminders[key]) ? reminders[key] : [];
    const persist = () => {
        try { localStorage.setItem(storageKey, JSON.stringify(reminders)); return true; }
        catch (_) { window.PrismUI?.toast('This reminder could not be saved in this browser.', 'error'); return false; }
    };
    const make = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text != null) element.textContent = String(text);
        if (className) element.className = className;
        return element;
    };

    function renderDialogTasks() {
        const tasks = tasksFor(selectedDate).slice().sort((a,b) => (a.time || '99:99').localeCompare(b.time || '99:99'));
        list.replaceChildren();
        if (!tasks.length) {
            const empty = make('p', 'No tasks or notes for this date yet.', 'adviser-reminder-empty');
            list.append(empty);
            return;
        }
        tasks.forEach(task => {
            const item = make('article', null, 'adviser-reminder-item');
            const textBlock = make('div', null, 'adviser-reminder-item-text');
            textBlock.append(make('strong', task.title));
            if (task.time) textBlock.append(make('time', new Date(`2000-01-01T${task.time}`).toLocaleTimeString('en-PH', {hour:'numeric', minute:'2-digit'})));
            if (task.notes) textBlock.append(make('p', task.notes));
            const remove = make('button', null, 'adviser-reminder-remove');
            remove.type = 'button';
            remove.setAttribute('aria-label', `Delete reminder: ${task.title}`);
            remove.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>';
            remove.addEventListener('click', () => {
                reminders[selectedDate] = tasksFor(selectedDate).filter(entry => entry.id !== task.id);
                if (!reminders[selectedDate].length) delete reminders[selectedDate];
                if (persist()) { renderDialogTasks(); refresh(); }
            });
            item.append(textBlock, remove);
            list.append(item);
        });
    }

    function open(key, trigger = null) {
        selectedDate = key;
        opener = trigger;
        days.querySelectorAll('[data-selected-day]').forEach(cell => {
            delete cell.dataset.selectedDay;
            cell.removeAttribute('aria-current');
        });
        const selected = days.querySelector(`[data-deadline-date="${key}"]`);
        if (selected) {
            selected.dataset.selectedDay = 'true';
            selected.setAttribute('aria-current', 'date');
        }
        dateHeading.textContent = formatDate(key);
        form.reset();
        renderDialogTasks();
        if (!dialog.open) dialog.showModal();
        title.focus();
    }

    function refresh() {
        days.querySelectorAll('[data-deadline-date]').forEach(cell => {
            const hasTasks = tasksFor(cell.dataset.deadlineDate).length > 0;
            cell.dataset.personalReminder = hasTasks ? 'true' : 'false';
            cell.setAttribute('aria-label', `${formatDate(cell.dataset.deadlineDate)}${hasTasks ? `, ${tasksFor(cell.dataset.deadlineDate).length} personal ${tasksFor(cell.dataset.deadlineDate).length === 1 ? 'reminder' : 'reminders'}` : ''}${cell.dataset.officialDeadline === 'true' ? ', official deadline' : ''}`);
        });
        const month = document.getElementById('adviserCalendarMonth').textContent;
        const matches = Object.keys(reminders).filter(key => {
            const date = fromDateKey(key);
            return date.toLocaleDateString('en-PH', {month:'long',year:'numeric'}) === month && tasksFor(key).length;
        }).sort();
        summary.replaceChildren();
        if (!matches.length) {
            summary.append(make('p', 'No upcoming task reminders.'));
            return;
        }
        matches.forEach(key => {
            const item = make('button', null, 'adviser-calendar-task-link');
            item.type = 'button';
            const date = fromDateKey(key);
            const label = date.toLocaleDateString('en-PH', {weekday:'short',month:'short',day:'numeric'});
            item.textContent = `${label} · ${tasksFor(key).map(task => task.title).join(', ')}`;
            item.addEventListener('click', () => open(key, days.querySelector(`[data-deadline-date="${key}"]`)));
            summary.append(item);
        });
    }

    window.PrismAdviserReminders = { open, refresh };
    add.addEventListener('click', () => open(selectedDate, days.querySelector('[data-selected-day]')));
    [dismiss, close].forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => { if (opener?.isConnected) opener.focus({preventScroll:true}); });
    form.addEventListener('submit', event => {
        event.preventDefault();
        const taskTitle = title.value.trim();
        if (!taskTitle) { title.focus(); return; }
        const existing = tasksFor(selectedDate).slice();
        existing.push({ id:`${Date.now()}-${Math.random().toString(16).slice(2)}`, title:taskTitle, time:time.value, notes:notes.value.trim() });
        reminders[selectedDate] = existing;
        if (!persist()) return;
        form.reset();
        renderDialogTasks();
        refresh();
        title.focus();
    });
    refresh();
});
