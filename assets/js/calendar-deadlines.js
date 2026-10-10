/* Official server deadlines coexist with, and never enter, personal browser storage. */
window.PrismDeadlines = (() => {
    function mount(onDatesChanged) {
        const host = document.getElementById('officialDeadlinePanel');
        if (!host) return { refresh() {}, count: () => 0 };
        const manager = ['admin', 'adviser'].includes(host.dataset.role);
        host.innerHTML = '<div class="panel-title"><div><span class="eyebrow">Official Deadline</span><h2>Official deadlines</h2></div></div>'
            + (manager ? '<details class="deadline-create"><summary>Create official deadline</summary><form id="deadlineForm" class="deadline-form"><label>Title<input name="title" maxlength="190" required></label><label>Deadline date<input name="date" type="date" required></label><label>Optional description<textarea name="description" maxlength="2000" rows="3"></textarea></label><label>Target<select name="target"><option value="all"></option><option value="selected">Selected research groups</option></select></label><fieldset id="deadlineGroupsLabel" class="prism-group-selector" hidden><legend>Research groups</legend><label for="deadlineGroupSearch">Search authorized groups</label><input id="deadlineGroupSearch" type="search" placeholder="Search groups..." aria-controls="deadlineGroupChoices"><select name="groups" multiple hidden aria-hidden="true" tabindex="-1"></select><div id="deadlineGroupChoices" class="prism-group-choices"></div><p id="deadlineGroupCount" role="status" aria-live="polite">0 selected</p><button type="button" id="deadlineGroupClear" class="prism-btn">Clear selection</button><small id="deadlineGroupHelp">Choose one or more groups from the authorized list.</small><p id="deadlineGroupError" class="prism-field-error" role="alert"></p></fieldset><p id="deadlineGroupState" role="status">Loading research groups...</p><button type="submit" class="save-task" disabled>Create deadline</button></form></details><label class="deadline-manage"><input type="checkbox" id="deadlineManage"> Manage deadlines (including cancelled)</label>' : '')
            + '<h3 id="officialDateLabel"></h3><p id="officialLoadState" role="status"></p><div id="officialDeadlineList" class="task-list"></div><p id="officialDeadlineCount" aria-live="polite"></p><nav id="officialDeadlinePager" class="prism-pagination" aria-label="Official deadline pages"></nav>';
        const list = host.querySelector('#officialDeadlineList');
        const state = host.querySelector('#officialLoadState');
        const pager = host.querySelector('#officialDeadlinePager');
        const count = host.querySelector('#officialDeadlineCount');
        const form = host.querySelector('#deadlineForm');
        const manage = host.querySelector('#deadlineManage');
        let date, rangeKey = '', page = 1, sequence = 0, datesSequence = 0, dates = new Map();
        let dirty, initialDateSet = false;
        const endpoint = (action, params) => 'calendar_deadlines_api.php?' + new URLSearchParams({ action, ...params });
        const displayDate = key => new Date(key + 'T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });

        async function loadList() {
            if (!date) return;
            const current = ++sequence;
            list.replaceChildren(); pager.replaceChildren(); count.textContent = '';
            state.textContent = 'Loading official deadlines...';
            host.querySelector('#officialDateLabel').textContent = displayDate(date);
            try {
                const data = await PrismUI.request(endpoint('list', { from: date, to: date, page, manage: manage?.checked ? '1' : '0' }));
                if (current !== sequence) return;
                if (!data.ok) throw new Error(data.message || 'Could not load official deadlines.');
                page = data.page;
                state.textContent = data.deadlines.length ? '' : 'No official deadlines for this date.';
                data.deadlines.forEach(deadline => {
                    const item = document.createElement('article'); item.className = 'task-item official-deadline';
                    const badge = document.createElement('small'); badge.className = 'eyebrow';
                    badge.textContent = 'Official Deadline' + (deadline.status === 'Cancelled' ? ' · Cancelled' : '');
                    const heading = document.createElement('h3'); heading.textContent = deadline.title;
                    const details = document.createElement('p'); details.className = 'task-notes'; details.textContent = deadline.description || '';
                    item.append(badge, heading, details);
                    if (deadline.groups?.length) {
                        const groups = document.createElement('p'); groups.className = 'task-notes';
                        groups.textContent = 'Research groups: ' + deadline.groups.join(', '); item.append(groups);
                    } else if (manager && deadline.target_scope === 'all') {
                        const target = document.createElement('p'); target.className = 'task-notes';
                        target.textContent = 'All applicable students'; item.append(target);
                    }
                    if (manager && deadline.canCancel) {
                        const button = document.createElement('button'); button.type = 'button'; button.className = 'cancel-edit';
                        button.textContent = 'Cancel deadline';
                        button.addEventListener('click', async () => {
                            if (button.disabled) return;
                            const release = PrismUI.busy(button, 'Processing...');
                            try {
                                const groupSummary = deadline.groups?.length ? deadline.groups.join(', ')
                                    : (host.dataset.role === 'adviser' ? 'All assigned research groups' : 'All applicable students / research groups');
                                if (!await PrismUI.confirm({ title: 'Cancel official deadline?',
                                    message: 'This deadline will be cancelled for the affected research groups. Students will be notified.',
                                    extraHtml: '<dl class="prism-cancel-summary"><dt>Deadline</dt><dd>' + PrismUI.esc(deadline.title)
                                        + '</dd><dt>Date</dt><dd>' + PrismUI.esc(displayDate(deadline.deadline_date || date))
                                        + '</dd><dt>Affected research groups</dt><dd>' + PrismUI.esc(groupSummary) + '</dd></dl>',
                                    cancelText: 'Keep deadline', focusCancel: true, confirmText: 'Cancel deadline', tone: 'danger' })) return;
                                const result = await PrismUI.postJson(endpoint('cancel', {}), { id: deadline.id });
                                if (!result.ok) throw new Error(result.message);
                                PrismUI.toast(result.message, 'success'); reload();
                            } catch (error) { PrismUI.toast(error.message || 'Could not cancel deadline.', 'error'); }
                            finally { release(); if (button.isConnected) button.focus(); }
                        });
                        item.append(button);
                    }
                    list.append(item);
                });
                PrismUI.pagination(pager, count, data, next => { page = next; loadList(); }, 'records');
            } catch (_) {
                if (current === sequence) state.textContent = 'Could not load official deadlines. Select the date again to retry.';
            }
        }

        async function loadDates(from, to) {
            const current = ++datesSequence;
            try {
                const data = await PrismUI.request(endpoint('dates', { from, to }));
                if (current !== datesSequence) return;
                if (!data.ok) throw new Error();
                dates = new Map(data.dates.map(row => [row.deadline_date, Number(row.total)]));
            } catch (_) {
                if (current !== datesSequence) return;
                rangeKey = ''; dates = new Map();
            }
            onDatesChanged();
        }
        let range;
        function reload() { page = 1; loadList(); if (range) loadDates(...range); }
        manage?.addEventListener('change', () => { page = 1; loadList(); });
        if (form) {
            const fields = form.elements;
            const selector = host.querySelector('#deadlineGroupsLabel'), choices = host.querySelector('#deadlineGroupChoices');
            const search = host.querySelector('#deadlineGroupSearch'), groupCount = host.querySelector('#deadlineGroupCount');
            search.setAttribute('data-prism-ignore-dirty', '');
            const error = host.querySelector('#deadlineGroupError');
            function syncGroups() {
                choices.querySelectorAll('input').forEach(box => {
                    const option = [...fields.groups.options].find(option => option.value === box.value);
                    if (option) option.selected = box.checked;
                });
                groupCount.textContent = fields.groups.selectedOptions.length + ' selected';
                error.textContent = ''; selector.removeAttribute('aria-invalid');
            }
            function searchGroups() {
                const query = search.value.trim().toLowerCase(); let visible = 0;
                choices.querySelectorAll('label').forEach(label => { label.hidden = !label.textContent.toLowerCase().includes(query); if (!label.hidden) visible++; });
                groupCount.textContent = fields.groups.selectedOptions.length + ' selected · ' + visible + ' visible';
            }
            search.addEventListener('input', searchGroups);
            choices.addEventListener('change', syncGroups);
            host.querySelector('#deadlineGroupClear').addEventListener('click', () => {
                choices.querySelectorAll('input').forEach(box => { box.checked = false; }); syncGroups();
                form.dispatchEvent(new Event('change', {bubbles:true}));
            });
            fields.target.options[0].textContent = host.dataset.role === 'adviser' ? 'All assigned research groups' : 'All applicable students / research groups';
            dirty = PrismUI.dirtyForm(form);
            fields.target.addEventListener('change', () => {
                host.querySelector('#deadlineGroupsLabel').hidden = fields.target.value !== 'selected';
                fields.groups.disabled = fields.target.value !== 'selected';
            });
            fields.groups.disabled = true;
            PrismUI.request(endpoint('group_options', {})).then(data => {
                if (!data.ok) throw new Error();
                [...new Set(data.groups)].forEach((group, index) => {
                    fields.groups.add(new Option(group, group));
                    const label = document.createElement('label'), box = document.createElement('input');
                    label.className = 'check-label';
                    box.type = 'checkbox'; box.id = 'deadlineGroup_' + index; box.value = group;
                    box.setAttribute('aria-describedby', 'deadlineGroupHelp deadlineGroupError');
                    label.htmlFor = box.id; label.append(box, document.createTextNode(group)); choices.append(label);
                });
                host.querySelector('#deadlineGroupState').textContent = data.groups.length ? '' : 'No assigned research groups.';
                form.querySelector('[type="submit"]').disabled = host.dataset.role === 'adviser' && !data.groups.length;
                dirty.trackFields();
            }).catch(() => { host.querySelector('#deadlineGroupState').textContent = 'Could not load authorized groups. Reload the calendar to retry.'; });
            form.addEventListener('submit', async event => {
                event.preventDefault();
                syncGroups();
                if (fields.target.value === 'selected' && !fields.groups.selectedOptions.length) {
                    error.textContent = 'Choose at least one research group.'; selector.setAttribute('aria-invalid', 'true');
                    (choices.querySelector('input') || search).focus(); return;
                }
                const release = PrismUI.busy(form.querySelector('[type="submit"]'), 'Saving...');
                if (!release) return;
                try {
                    const result = await PrismUI.postJson(endpoint('create', {}), {
                        title: fields.title.value, date: fields.date.value, description: fields.description.value,
                        target: fields.target.value,
                        groups: fields.target.value === 'selected' ? [...fields.groups.selectedOptions].map(option => option.value) : []
                    });
                    if (!result.ok) throw new Error(result.message);
                    form.reset(); fields.groups.disabled = true; fields.groups.required = false;
                    search.value = ''; syncGroups(); searchGroups();
                    host.querySelector('#deadlineGroupsLabel').hidden = true; dirty.clean();
                    PrismUI.toast(result.message, 'success');
                    if (result.delivery?.notificationFailures || result.delivery?.emailFailures) {
                        PrismUI.toast('Deadline saved. Some notifications could not be delivered; the calendar record remains available.', 'warning');
                    }
                    reload();
                } catch (error) { PrismUI.toast(error.message || 'Could not create deadline.', 'error'); }
                finally { release(); }
            });
        }
        return {
            count: key => dates.get(key) || 0,
            refresh(selected, from, to) {
                const changed = selected !== date;
                date = selected; range = [from, to];
                if (form && !initialDateSet) {
                    form.elements.date.defaultValue = date;
                    form.elements.date.value = date;
                    initialDateSet = true;
                    dirty.clean();
                }
                if (changed) page = 1;
                loadList();
                if (rangeKey !== from + to) { rangeKey = from + to; loadDates(from, to); }
            }
        };
    }
    return { mount };
})();
