(function () {
    'use strict';
    const host = document.querySelector('[data-academic-year-manager]');
    if (!host) return;

    const form = host.querySelector('[data-add-year]');
    const input = form.querySelector('input');
    const addButton = form.querySelector('button');
    const status = host.querySelector('[data-settings-status]');
    const currentYear = host.querySelector('[data-current-year]');
    const rows = host.querySelector('[data-year-rows]');
    let canManage = false;
    let canDelete = false;
    let settings = [];

    function validAcademicYear(year) {
        const parts = /^([1-9][0-9]{3})-([1-9][0-9]{3})$/.exec(year);
        return !!parts && Number(parts[2]) === Number(parts[1]) + 1;
    }

    function render() {
        const current = settings.find(item => item.isCurrent);
        currentYear.textContent = current
            ? `Current academic year: ${current.academicYear}`
            : 'No current academic year is selected.';
        rows.replaceChildren();
        if (!settings.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 4;
            cell.textContent = 'No academic years are in the catalog.';
            row.appendChild(cell);
            rows.appendChild(row);
            return;
        }

        settings.forEach(item => {
            const row = document.createElement('tr');
            const yearCell = document.createElement('th');
            yearCell.scope = 'row';
            yearCell.textContent = item.academicYear;

            const currentCell = document.createElement('td');
            currentCell.appendChild(badge(item.isCurrent ? 'Current' : 'Not current', item.isCurrent));

            const availabilityCell = document.createElement('td');
            availabilityCell.appendChild(badge(
                item.isDeleted ? 'Removed' : item.availableForRegistration ? 'Available' : 'Unavailable',
                item.availableForRegistration
            ));

            const actions = document.createElement('td');
            actions.className = 'academic-year-actions';
            const currentButton = document.createElement('button');
            currentButton.type = 'button';
            currentButton.className = 'prism-btn prism-btn-secondary';
            currentButton.textContent = item.isCurrent ? 'Current year' : 'Set current';
            currentButton.dataset.action = 'set-current';
            currentButton.dataset.year = item.academicYear;
            currentButton.disabled = !canManage || item.isCurrent || item.isDeleted || !validAcademicYear(item.academicYear);
            actions.appendChild(currentButton);

            const availabilityButton = document.createElement('button');
            availabilityButton.type = 'button';
            availabilityButton.className = 'prism-btn prism-btn-secondary';
            availabilityButton.textContent = item.availableForRegistration ? 'Make unavailable' : item.isDeleted ? 'Restore year' : 'Make available';
            availabilityButton.dataset.action = 'set-availability';
            availabilityButton.dataset.year = item.academicYear;
            availabilityButton.dataset.available = item.availableForRegistration ? 'false' : 'true';
            availabilityButton.disabled = !canManage || (item.isCurrent && item.availableForRegistration)
                || (!validAcademicYear(item.academicYear) && !item.availableForRegistration);
            actions.appendChild(availabilityButton);

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'prism-btn is-danger';
            deleteButton.textContent = 'Delete Academic Year';
            deleteButton.dataset.action = 'remove';
            deleteButton.dataset.year = item.academicYear;
            deleteButton.disabled = !canDelete || item.isCurrent || item.isDeleted
                || !validAcademicYear(item.academicYear);
            deleteButton.title = !canDelete
                ? 'Year removal requires the approved settings migration.'
                : item.isCurrent
                ? 'Select another active academic year before deleting this one.'
                : 'Remove this year from new student registration choices.';
            actions.appendChild(deleteButton);

            row.append(yearCell, currentCell, availabilityCell, actions);
            rows.appendChild(row);
        });
    }

    function badge(text, active) {
        const element = document.createElement('span');
        element.className = `academic-year-badge${active ? ' is-active' : ''}`;
        element.textContent = text;
        return element;
    }

    async function refresh() {
        const data = await PrismUI.request('academic_years_api.php?action=list');
        if (typeof data.canManage !== 'boolean' || typeof data.canDelete !== 'boolean' || !Array.isArray(data.yearSettings)) {
            throw new Error('The academic-year catalog response was incomplete. Refresh the page to try again.');
        }
        canManage = data.canManage;
        canDelete = data.canDelete;
        settings = data.yearSettings;
        const canAdd = typeof data.canAdd === 'boolean' ? data.canAdd : canManage;
        addButton.disabled = !canAdd;
        input.disabled = !canAdd;
        render();
        return data;
    }

    async function run(button, label, action, successMessage) {
        await PrismUI.runAction(button, label, async () => {
            try {
                const data = await action();
                if (Array.isArray(data.yearSettings)) settings = data.yearSettings;
                else await refresh();
                render();
                status.dataset.state = 'success';
                status.textContent = successMessage;
            } catch (error) {
                status.dataset.state = 'error';
                status.textContent = error.message || 'The academic-year setting could not be saved.';
            }
        });
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        if (!form.reportValidity() || addButton.disabled) return;
        const year = input.value.trim();
        run(addButton, 'Adding…', async () => {
            const data = await PrismUI.postJson('academic_years_api.php?action=add', {academicYear: year});
            input.value = '';
            return data;
        }, `${year} was added and is available for registration.`);
    });

    rows.addEventListener('click', async event => {
        const button = event.target.closest('button[data-action]');
        if (!button || !canManage) return;
        const year = button.dataset.year;
        if (button.dataset.action === 'set-current') {
            const current = settings.find(item => item.isCurrent);
            const message = current
                ? `Set ${year} as the current academic year instead of ${current.academicYear}? Student assignments will not change.`
                : `Set ${year} as the current academic year? Student assignments will not change.`;
            if (!window.confirm(message)) return;
            run(button, 'Updating…', () => PrismUI.postJson(
                'academic_years_api.php?action=set-current', {academicYear: year}
            ), `${year} is now the current academic year.`);
        } else if (button.dataset.action === 'set-availability') {
            const available = button.dataset.available === 'true';
            if (!window.confirm(`Make ${year} ${available ? 'available for' : 'unavailable for'} new student registrations? Existing records will remain unchanged.`)) return;
            run(button, 'Updating…', () => PrismUI.postJson(
                'academic_years_api.php?action=set-availability',
                {academicYear: year, availableForRegistration: available}
            ), `${year} is ${available ? 'available' : 'unavailable'} for new student registrations.`);
        } else if (button.dataset.action === 'remove') {
            const answer = await PrismUI.confirm({
                title: 'Delete Academic Year?',
                icon: 'fa-trash-can',
                tone: 'danger',
                confirmText: 'Delete Academic Year',
                message: `You are about to remove ${year} from the available academic years. Students already assigned to ${year} keep their year. This action will prevent the year from being selected for new student registrations.`
            });
            if (!answer) return;
            run(button, 'Deleting…', () => PrismUI.postJson(
                'academic_years_api.php?action=remove', {academicYear: year}
            ), `${year} was removed from new registration choices. Existing student records were not changed.`);
        }
    });

    refresh().then(() => {
        status.dataset.state = canManage ? 'ready' : 'notice';
        status.textContent = canManage
            ? 'Changes apply to new registrations. Historical academic years remain in the catalog.'
            : !addButton.disabled
                ? 'You can add academic years now. Other academic-year settings will become available after the database update.'
                : 'Academic-year management is read-only until the approved database migration is applied.';
    }).catch(error => {
        status.dataset.state = 'error';
        status.textContent = error.message || 'Academic years could not be loaded. Refresh the page to try again.';
        rows.replaceChildren();
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 4;
        cell.textContent = 'Academic years could not be loaded.';
        row.appendChild(cell);
        rows.appendChild(row);
        addButton.disabled = true;
        input.disabled = true;
    });
})();
