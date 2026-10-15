document.addEventListener('DOMContentLoaded', () => {
    const monthGrid = document.getElementById('monthGrid');
    if (!monthGrid) return; // calendar page not present on this view

    const pad = value => String(value).padStart(2, '0');
    const toDateKey = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const fromDateKey = key => {
        const [year, month, day] = key.split('-').map(Number);
        return new Date(year, month - 1, day);
    };

    const storageKey = `prismReminders:${document.body.dataset.portalKey || 'default'}`;
    let reminders;
    try {
        reminders = JSON.parse(localStorage.getItem(storageKey)) || {};
        if (typeof reminders !== 'object' || Array.isArray(reminders)) reminders = {};
    } catch (_) {
        reminders = {};
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    let selectedDate = toDateKey(today);
    let visibleMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    let dashboardMonth = new Date(today.getFullYear(), today.getMonth(), 1);

    const monthLabel = document.getElementById('monthLabel');
    const selectedDateLabel = document.getElementById('selectedDateLabel');
    const taskCount = document.getElementById('taskCount');
    const taskList = document.getElementById('taskList');
    const form = document.getElementById('reminderForm');
    const reminderPanel = form.closest('.reminder-panel');
    const editingId = document.getElementById('editingId');
    const titleInput = document.getElementById('taskTitle');
    const timeInput = document.getElementById('taskTime');
    const notesInput = document.getElementById('taskNotes');
    const cancelEdit = document.getElementById('cancelEdit');
    const saveLabel = document.getElementById('saveLabel');

    const dashboardGrid = document.getElementById('dashboardCalendarGrid');
    const dashboardMonthLabel = document.getElementById('dashboardMonthLabel');
    const dashboardDeadlineValue = document.getElementById('dashboardDeadlineValue');
    const dashboardDeadlineHint = document.getElementById('dashboardDeadlineHint');
    const dashboardDeadlineTag = document.getElementById('dashboardDeadlineTag');
    const dashboardCalendarTasks = document.getElementById('dashboardCalendarTasks');
    const dashboardDeadlineState = document.getElementById('dashboardDeadlineState');
    const calendarOfficialSummary = document.getElementById('calendarOfficialSummary');
    const addReminderButton = document.getElementById('calendarAddReminder');

    const save = () => {
        try {
            localStorage.setItem(storageKey, JSON.stringify(reminders));
            return true;
        } catch (_) {
            PrismUI.toast('This reminder could not be saved in this browser. Check browser storage settings or clear unused site data.', 'error');
            return false;
        }
    };
    const tasksFor = key => Array.isArray(reminders[key]) ? reminders[key] : [];
    const formatDate = key => fromDateKey(key).toLocaleDateString('en-PH', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
    });
    const formatTime = value => value ? new Date(`2000-01-01T${value}`).toLocaleTimeString('en-PH', {
        hour: 'numeric', minute: '2-digit',
    }) : '';

    function resetForm() {
        form.reset();
        editingId.value = '';
        cancelEdit.hidden = true;
        saveLabel.textContent = 'Add task';
        form.hidden = false;
        addReminderButton?.setAttribute('aria-expanded', 'false');
    }

    function openTaskDialog(trigger) {
        const dialog = document.createElement('dialog');
        dialog.className = 'calendar-task-dialog';
        dialog.setAttribute('aria-label', `Tasks for ${formatDate(selectedDate)}`);

        const heading = document.createElement('header');
        heading.className = 'calendar-task-dialog-heading';
        const headingText = document.createElement('div');
        const eyebrow = document.createElement('span');
        eyebrow.textContent = 'Selected date';
        const dateTitle = document.createElement('h2');
        dateTitle.textContent = formatDate(selectedDate);
        headingText.append(eyebrow, dateTitle);
        const closeIcon = document.createElement('button');
        closeIcon.type = 'button';
        closeIcon.className = 'calendar-task-dialog-icon-close';
        closeIcon.setAttribute('aria-label', 'Close task dialog');
        closeIcon.textContent = '\u00d7';
        heading.append(headingText, closeIcon);

        const divider = document.createElement('hr');
        const actions = form.querySelector('.form-actions');
        const closeAction = document.createElement('button');
        closeAction.type = 'button';
        closeAction.className = 'calendar-task-dialog-close-action';
        closeAction.textContent = 'Close';
        actions.prepend(closeAction);
        dialog.append(heading, form, divider, taskList);
        document.body.appendChild(dialog);

        const close = () => { if (dialog.open) dialog.close(); };
        closeIcon.addEventListener('click', close);
        closeAction.addEventListener('click', close);
        dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
        dialog.addEventListener('close', () => {
            closeAction.remove();
            reminderPanel.append(form, taskList);
            dialog.remove();
            renderTasks();
            if (trigger?.isConnected) trigger.focus({ preventScroll: true });
        }, { once: true });
        dialog.showModal();
        renderTasks();
        titleInput.focus();
    }

    const official = PrismDeadlines.mount(renderCalendar);

    function refreshOfficial() {
        const first = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1);
        const start = new Date(first); start.setDate(1 - first.getDay());
        const end = new Date(start); end.setDate(start.getDate() + 41);
        official.refresh(selectedDate, toDateKey(start), toDateKey(end));
    }

    function renderCalendar() {
        monthLabel.textContent = visibleMonth.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
        monthGrid.replaceChildren();

        const first = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1);
        const gridStart = new Date(first);
        gridStart.setDate(1 - first.getDay());

        for (let index = 0; index < 42; index++) {
            const date = new Date(gridStart);
            date.setDate(gridStart.getDate() + index);
            const key = toDateKey(date);
            const dayTasks = tasksFor(key).sort((a, b) => (a.time || '99:99').localeCompare(b.time || '99:99'));
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'calendar-day';
            button.dataset.calendarDate = key;
            if (date.getMonth() !== visibleMonth.getMonth()) button.classList.add('outside');
            if (key === toDateKey(today)) button.classList.add('today');
            if (key === selectedDate) button.classList.add('selected');
            button.setAttribute('aria-label', `${formatDate(key)}, ${dayTasks.length} reminders`);

            const number = document.createElement('span');
            number.className = 'day-number';
            number.textContent = date.getDate();
            button.appendChild(number);

            const previews = document.createElement('div');
            previews.className = 'day-tasks';
            dayTasks.slice(0, 2).forEach(task => {
                const preview = document.createElement('div');
                preview.className = 'day-task';
                preview.textContent = `${task.time ? formatTime(task.time) + ' · ' : ''}${task.title}`;
                previews.appendChild(preview);
            });
            if (dayTasks.length > 2) {
                const more = document.createElement('div');
                more.className = 'more-tasks';
                more.textContent = `+${dayTasks.length - 2} more`;
                previews.appendChild(more);
            }
            const officialCount = official.count(key);
            button.dataset.officialDeadline = officialCount ? 'true' : 'false';
            if (officialCount) {
                button.classList.add('has-official-deadline');
                const marker = document.createElement('div'); marker.className = 'day-task official-marker';
                marker.textContent = `Official: ${officialCount}`; marker.title = `${officialCount} Official Deadline(s)`;
                previews.appendChild(marker);
                button.setAttribute('aria-label', button.getAttribute('aria-label') + `, ${officialCount} official deadlines`);
            }
            button.appendChild(previews);
            button.addEventListener('click', () => {
                selectedDate = key;
                if (date.getMonth() !== visibleMonth.getMonth()) {
                    visibleMonth = new Date(date.getFullYear(), date.getMonth(), 1);
                }
                resetForm();
                renderAll();
                openTaskDialog(monthGrid.querySelector(`[data-calendar-date="${key}"]`));
            });
            monthGrid.appendChild(button);
        }

        if (calendarOfficialSummary) {
            let totalOfficial = 0;
            const monthDays = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() + 1, 0).getDate();
            for (let day = 1; day <= monthDays; day++) totalOfficial += official.count(toDateKey(new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), day)));
            calendarOfficialSummary.textContent = totalOfficial
                ? `${totalOfficial} official deadline${totalOfficial === 1 ? '' : 's'} in this view.`
                : 'No active official deadlines in this view.';
        }
    }

    function renderTasks() {
        const tasks = tasksFor(selectedDate).sort((a, b) => (a.time || '99:99').localeCompare(b.time || '99:99'));
        selectedDateLabel.textContent = formatDate(selectedDate);
        taskCount.textContent = `${tasks.length} ${tasks.length === 1 ? 'task' : 'tasks'}`;
        taskList.replaceChildren();

        if (!tasks.length) {
            const empty = document.createElement('div');
            empty.className = 'empty-tasks';
            if (taskList.closest('.calendar-task-dialog')) empty.textContent = 'No tasks or notes for this date yet.';
            else empty.innerHTML = '<i class="fa-regular fa-calendar-check"></i>No upcoming task reminders.';
            taskList.appendChild(empty);
            return;
        }

        tasks.forEach(task => {
            const item = document.createElement('article');
            item.className = 'task-item';
            const head = document.createElement('div');
            head.className = 'task-item-head';
            const content = document.createElement('div');
            const heading = document.createElement('h3');
            heading.textContent = task.title;
            const kind = document.createElement('small'); kind.className = 'eyebrow'; kind.textContent = 'Personal Reminder';
            content.appendChild(kind);
            content.appendChild(heading);
            if (task.time) {
                const time = document.createElement('div');
                time.className = 'task-time';
                time.innerHTML = `<i class="fa-regular fa-clock"></i> ${formatTime(task.time)}`;
                content.appendChild(time);
            }
            head.appendChild(content);
            const buttons = document.createElement('div');
            buttons.className = 'task-buttons';
            [['edit', 'fa-pen', 'Edit reminder'], ['delete', 'fa-trash', 'Delete reminder']].forEach(([action, icon, label]) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'task-action';
                button.title = label;
                button.innerHTML = `<i class="fa-solid ${icon}"></i>`;
                button.addEventListener('click', () => action === 'edit' ? startEdit(task) : deleteTask(task.id));
                buttons.appendChild(button);
            });
            head.appendChild(buttons);
            item.appendChild(head);
            if (task.notes) {
                const notes = document.createElement('p');
                notes.className = 'task-notes';
                notes.textContent = task.notes;
                item.appendChild(notes);
            }
            taskList.appendChild(item);
        });
    }

    function startEdit(task) {
        form.hidden = false;
        addReminderButton?.setAttribute('aria-expanded', 'true');
        editingId.value = task.id;
        titleInput.value = task.title;
        timeInput.value = task.time || '';
        notesInput.value = task.notes || '';
        cancelEdit.hidden = false;
        saveLabel.textContent = 'Save changes';
        titleInput.focus();
    }

    async function deleteTask(id) {
        const answer = await PrismUI.confirm({
            title:'Delete reminder', icon:'fa-trash', tone:'danger', confirmText:'Delete',
            dialogClass:'calendar-delete-dialog',
            message:'Delete this personal reminder from this browser?'
        });
        if (!answer) return;
        const previous = reminders[selectedDate] ? reminders[selectedDate].slice() : null;
        reminders[selectedDate] = tasksFor(selectedDate).filter(task => task.id !== id);
        if (!reminders[selectedDate].length) delete reminders[selectedDate];
        if (!save()) {
            if (previous) reminders[selectedDate] = previous;
            return;
        }
        resetForm();
        renderAll();
    }

    // --- Dashboard calendar preview and selected-date reminders ---
    function renderDashboardCalendar() {
        if (!dashboardGrid) return;
        dashboardMonthLabel.textContent = dashboardMonth.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
        dashboardGrid.replaceChildren();

        const year = dashboardMonth.getFullYear(), month = dashboardMonth.getMonth();
        const firstDayIndex = new Date(year, month, 1).getDay();
        const lastDate = new Date(year, month + 1, 0).getDate();
        const prevMonthLastDate = new Date(year, month, 0).getDate();

        for (let x = firstDayIndex; x > 0; x--) {
            const cell = document.createElement('div');
            cell.className = 'mini-day text-muted';
            cell.textContent = prevMonthLastDate - x + 1;
            dashboardGrid.appendChild(cell);
        }
        for (let d = 1; d <= lastDate; d++) {
            const key = toDateKey(new Date(year, month, d));
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.dataset.deadlineDate = key;
            let cls = 'mini-day';
            if (d === today.getDate() && month === today.getMonth() && year === today.getFullYear()) cls += ' active-day';
            if (key === selectedDate) cls += ' selected-day';
            if (tasksFor(key).length) cls += ' has-event';
            cell.className = cls;
            cell.setAttribute('aria-label', `${formatDate(key)}${tasksFor(key).length ? `, ${tasksFor(key).length} personal reminders` : ''}`);
            cell.setAttribute('aria-pressed', String(key === selectedDate));
            cell.textContent = d;
            const marker=document.createElement('i');marker.className='fa-solid fa-calendar-day prism-deadline-marker';marker.hidden=true;marker.setAttribute('aria-hidden','true');cell.append(marker);
            cell.addEventListener('click', () => {
                selectedDate = key;
                visibleMonth = new Date(year, month, 1);
                resetForm();
                renderAll();
                openTaskDialog(dashboardGrid.querySelector(`[data-deadline-date="${key}"]`));
            });
            dashboardGrid.appendChild(cell);
        }
        const totalRendered = firstDayIndex + lastDate;
        const remaining = (Math.ceil(totalRendered / 7) * 7) - totalRendered;
        for (let j = 1; j <= remaining; j++) {
            const cell = document.createElement('div');
            cell.className = 'mini-day text-muted';
            cell.textContent = j;
            dashboardGrid.appendChild(cell);
        }

        window.PrismDashboardDeadlines?.refresh(dashboardMonth);
        if (dashboardDeadlineState) {
            const totalOfficial = [...dashboardGrid.querySelectorAll('[data-deadline-date]')]
                .reduce((total, day) => total + (day.dataset.officialDeadline === 'true' ? 1 : 0), 0);
            dashboardDeadlineState.textContent = totalOfficial
                ? 'Official deadlines are marked in this view.'
                : 'No active official deadlines in this view.';
        }

        if (dashboardDeadlineValue) {
            const upcomingKey = Object.keys(reminders)
                .filter(key => key >= toDateKey(today) && tasksFor(key).length)
                .sort()[0];
            if (upcomingKey) {
                const nextTask = tasksFor(upcomingKey).sort((a, b) => (a.time || '99:99').localeCompare(b.time || '99:99'))[0];
                const dueDate = fromDateKey(upcomingKey);
                const daysLeft = Math.max(0, Math.round((dueDate - today) / 86400000));
                dashboardDeadlineValue.textContent = dueDate.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
                if (dashboardDeadlineHint) dashboardDeadlineHint.textContent = nextTask.title;
                if (dashboardDeadlineTag) {
                    dashboardDeadlineTag.textContent = daysLeft === 0 ? 'Due today' : `Due in ${daysLeft} day${daysLeft === 1 ? '' : 's'}`;
                    dashboardDeadlineTag.hidden = false;
                }
            } else {
                dashboardDeadlineValue.textContent = 'No deadline';
                if (dashboardDeadlineHint) dashboardDeadlineHint.textContent = "You're clear for now. New deadlines will show up here.";
                if (dashboardDeadlineTag) dashboardDeadlineTag.hidden = true;
            }
        }
    }

    function renderDashboardTasks() {
        if (!dashboardCalendarTasks) return;
        const tasks = tasksFor(selectedDate).slice().sort((a, b) => (a.time || '99:99').localeCompare(b.time || '99:99'));
        dashboardCalendarTasks.replaceChildren();
        if (!tasks.length) {
            const empty = document.createElement('p');
            empty.className = 'dashboard-calendar-task-empty';
            empty.textContent = 'No upcoming task reminders.';
            dashboardCalendarTasks.appendChild(empty);
            return;
        }
        tasks.slice(0, 3).forEach(task => {
            const item = document.createElement('div');
            item.className = 'dashboard-calendar-task';
            const title = document.createElement('strong');
            title.textContent = task.title;
            const time = document.createElement('span');
            time.textContent = task.time ? formatTime(task.time) : formatDate(selectedDate);
            item.append(title, time);
            dashboardCalendarTasks.appendChild(item);
        });
    }

    function renderAll() {
        refreshOfficial();
        renderCalendar();
        renderTasks();
        renderDashboardCalendar();
        renderDashboardTasks();
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        const title = titleInput.value.trim();
        if (!title) return;
        const existing = tasksFor(selectedDate);
        const previous = existing.map(item => ({ ...item }));
        const task = {
            id: editingId.value || `${Date.now()}-${Math.random().toString(16).slice(2)}`,
            title,
            time: timeInput.value,
            notes: notesInput.value.trim(),
        };
        const index = existing.findIndex(item => item.id === task.id);
        if (index >= 0) existing[index] = task;
        else existing.push(task);
        reminders[selectedDate] = existing;
        if (!save()) {
            if (previous.length) reminders[selectedDate] = previous;
            else delete reminders[selectedDate];
            return;
        }
        resetForm();
        renderAll();
    });

    cancelEdit.addEventListener('click', resetForm);
    addReminderButton?.addEventListener('click', () => {
        if (form.hidden) openReminderForm();
        else {
            form.hidden = true;
            addReminderButton.setAttribute('aria-expanded', 'false');
        }
    });
    document.getElementById('previousMonth').addEventListener('click', () => {
        visibleMonth.setMonth(visibleMonth.getMonth() - 1);
        refreshOfficial();
        renderCalendar();
    });
    document.getElementById('nextMonth').addEventListener('click', () => {
        visibleMonth.setMonth(visibleMonth.getMonth() + 1);
        refreshOfficial();
        renderCalendar();
    });
    document.getElementById('dashboardPreviousMonth')?.addEventListener('click', () => {
        dashboardMonth.setMonth(dashboardMonth.getMonth() - 1);
        renderDashboardCalendar();
    });
    document.getElementById('dashboardNextMonth')?.addEventListener('click', () => {
        dashboardMonth.setMonth(dashboardMonth.getMonth() + 1);
        renderDashboardCalendar();
    });

    renderAll();
    renderDashboardTasks();
});
