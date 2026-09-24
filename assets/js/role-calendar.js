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
    const editingId = document.getElementById('editingId');
    const titleInput = document.getElementById('taskTitle');
    const timeInput = document.getElementById('taskTime');
    const notesInput = document.getElementById('taskNotes');
    const cancelEdit = document.getElementById('cancelEdit');
    const saveLabel = document.getElementById('saveLabel');

    const dashboardGrid = document.getElementById('dashboardCalendarGrid');
    const dashboardMonthLabel = document.getElementById('dashboardMonthLabel');
    const dashboardDeadlineValue = document.getElementById('dashboardDeadlineValue');

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
        saveLabel.textContent = 'Add reminder';
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
            button.appendChild(previews);
            button.addEventListener('click', () => {
                selectedDate = key;
                if (date.getMonth() !== visibleMonth.getMonth()) {
                    visibleMonth = new Date(date.getFullYear(), date.getMonth(), 1);
                }
                resetForm();
                renderAll();
                titleInput.focus();
            });
            monthGrid.appendChild(button);
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
            empty.innerHTML = '<i class="fa-regular fa-calendar-check"></i>No reminders yet.<br>Add one using the form above.';
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
        editingId.value = task.id;
        titleInput.value = task.title;
        timeInput.value = task.time || '';
        notesInput.value = task.notes || '';
        cancelEdit.hidden = false;
        saveLabel.textContent = 'Save changes';
        titleInput.focus();
    }

    function deleteTask(id) {
        if (!window.confirm('Delete this reminder?')) return;
        reminders[selectedDate] = tasksFor(selectedDate).filter(task => task.id !== id);
        if (!reminders[selectedDate].length) delete reminders[selectedDate];
        save();
        resetForm();
        renderAll();
    }

    // --- Dashboard mini-calendar (read-only month view + next-deadline card) ---
    function renderDashboardCalendar() {
        if (!dashboardGrid) return;
        dashboardMonthLabel.textContent = dashboardMonth.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
        dashboardGrid.replaceChildren();

        ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(d => {
            const name = document.createElement('div');
            name.className = 'mini-day-name';
            name.textContent = d;
            dashboardGrid.appendChild(name);
        });

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
            const cell = document.createElement('div');
            let cls = 'mini-day';
            if (d === today.getDate() && month === today.getMonth() && year === today.getFullYear()) cls += ' active-day';
            if (tasksFor(key).length) cls += ' has-event';
            cell.className = cls;
            cell.textContent = d;
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

        if (dashboardDeadlineValue) {
            const upcomingKey = Object.keys(reminders)
                .filter(key => key >= toDateKey(today) && tasksFor(key).length)
                .sort()[0];
            if (upcomingKey) {
                const nextTask = tasksFor(upcomingKey).sort((a, b) => (a.time || '99:99').localeCompare(b.time || '99:99'))[0];
                dashboardDeadlineValue.textContent = `${fromDateKey(upcomingKey).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })} — ${nextTask.title}`;
            } else {
                dashboardDeadlineValue.textContent = 'No deadline';
            }
        }
    }

    function renderAll() {
        renderCalendar();
        renderTasks();
        renderDashboardCalendar();
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        const title = titleInput.value.trim();
        if (!title) return;
        const existing = tasksFor(selectedDate);
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
        save();
        resetForm();
        renderAll();
    });

    cancelEdit.addEventListener('click', resetForm);
    document.getElementById('previousMonth').addEventListener('click', () => {
        visibleMonth.setMonth(visibleMonth.getMonth() - 1);
        renderCalendar();
    });
    document.getElementById('nextMonth').addEventListener('click', () => {
        visibleMonth.setMonth(visibleMonth.getMonth() + 1);
        renderCalendar();
    });
    document.getElementById('todayButton').addEventListener('click', () => {
        selectedDate = toDateKey(today);
        visibleMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        resetForm();
        renderAll();
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
});
