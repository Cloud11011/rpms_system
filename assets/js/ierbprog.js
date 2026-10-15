(function () {
    'use strict';

    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

    const isAdmin = document.body.dataset.role === 'admin';
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);

    const stageChart = document.getElementById('stageChart');
    const overviewTotal = document.getElementById('overviewTotal');
    const ierbSearch = document.getElementById('ierbSearch');
    const stageFilter = document.getElementById('stageFilter');
    const statusFilter = document.getElementById('ierbStatusFilter');
    const recordCount = document.getElementById('ierbRecordCount');
    const tableBody = document.getElementById('ierbTableBody');
    const addBtn = document.getElementById('addIerbEntry');

    if (!isAdmin && addBtn) addBtn.style.display = 'none';

    let records = [], overview = [];
    const definitions = [['academicUnitKey','Academic units'],['programKey','Programs'],['academicYear','Academic years'],['yearLevel','Year levels'],['group','Research groups'],['protocol','Protocol status'],['stage','Stages',stageFilter],['status','Statuses',statusFilter]];
    if (isAdmin) definitions.push(['adviserId','Research advisers']);
    const filters = PrismUI.recordFilters(document.getElementById('ierbMoreFilters'), definitions);
    const sort = PrismUI.recordFilters(document.getElementById('ierbMoreFilters'), [['sortBy','Sort by'],['direction','Direction']]);
    sort.update({sortBy:[['name','Name'],['studentId','Student ID'],['stage','IERB stage'],['status','Status'],['academicYear','Academic year'],['group','Research group']].map(([value,label])=>({value,label})),direction:[{value:'ASC',label:'Ascending'},{value:'DESC',label:'Descending'}]});
    sort.controls[0].options[0].textContent='Name (default)';sort.controls[1].options[0].textContent='Ascending (default)';
    if (!isAdmin) {
        ierbSearch.placeholder = 'Search by student name or ID';
        const visibleSort = sort.controls[0].closest('label');
        visibleSort.classList.add('ierb-visible-sort');
        const sortCaption = document.createElement('span');
        sortCaption.textContent = 'Sort by';
        if (visibleSort.firstChild?.nodeType === Node.TEXT_NODE) visibleSort.firstChild.remove();
        visibleSort.prepend(sortCaption);
        visibleSort.querySelector('select')?.setAttribute('aria-label', 'Sort student progress');
        document.querySelector('.ierb-controls').append(visibleSort);
    }
    const filterControls = [...filters.controls,...sort.controls];
    // Move the existing stage/status filters with their labels; primary actions stay visible.
    [stageFilter,statusFilter].forEach(control => {
        document.getElementById('ierbMoreFilters').append(control.closest('label'));
    });
    document.querySelector('.ierb-filters').remove();
    const pager = PrismUI.recordPager(tableBody.closest('table').parentElement, recordCount, [ierbSearch,...filterControls], loadRecords,
        {host:document.getElementById('ierbMoreFilters'),toolbar:document.querySelector('.ierb-controls')});
    let recordRequestSequence = 0;
    let loadError = '';
    const STAGES = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
    const stageLabels = window.PRISM_STAGE_LABELS || {};
    const labelForStage = stageKey => stageLabels[stageKey] || stageKey;

    // Show the configured form/document name instead of a bare "Stage N"
    // wherever a stage select appears, while keeping the option VALUE as
    // "Stage 1" etc so filtering/saving stays unchanged (feature request 7).
    document.querySelectorAll('#stageFilter option[value], #entryStage option[value]').forEach(opt => {
        if (opt.value) opt.textContent = `${opt.value} - ${labelForStage(opt.value)}`;
    });

    async function loadRecords() {
        filters.reconcile();
        searchReload.cancel();
        const requestSequence = ++recordRequestSequence;
        loadError = '';
        pager.loading();
        stageChart.setAttribute('aria-busy', 'true');
        tableBody.setAttribute('aria-busy', 'true');
        try {
            const data = await PrismUI.request('ierb_api.php?' + new URLSearchParams({ action: 'list', page: pager.page, perPage: pager.pageSize, q: ierbSearch.value.trim(), ...filters.query(), ...sort.query() }));
            if (requestSequence !== recordRequestSequence) return;
            if (!Array.isArray(data.records)) throw new Error('The server returned an invalid record list.');
            records = data.records;
            filters.update(data.filterOptions);
            overview = data.overview || records.map(r => ({ stage: r.stage, status: r.status, c: 1 }));
            const statusSelect=statusFilter;
            if(statusSelect){let pills=document.getElementById('ierbStatusPills');if(!pills){pills=document.createElement('div');pills.id='ierbStatusPills';document.querySelector('.ierb-controls').after(pills);}pills.classList.toggle('ierb-adviser-tabs',!isAdmin);const counts={};overview.forEach(row=>{counts[row.status]=(counts[row.status]||0)+Number(row.c);});PrismUI.statusPills(pills,[['','All'],...[...statusSelect.options].filter(option=>option.value).map(option=>[option.value,option.textContent])],statusSelect,counts);}
            pager.render({ ...data, total: data.total ?? records.length });
        } catch (e) {
            if (requestSequence !== recordRequestSequence) return;
            records = []; overview = [];
            pager.error();
            loadError = e.message || 'Could not load IERB records.';
            PrismUI.toast(loadError, 'error');
        } finally {
            if (requestSequence === recordRequestSequence) {
                stageChart.setAttribute('aria-busy', 'false');
                tableBody.setAttribute('aria-busy', 'false');
            }
        }
        renderOverview();
        renderTable();
    }

    function renderOverview() {
        const total = overview.reduce((sum, r) => sum + Number(r.c), 0);
        const stageCount = stage => overview.filter(r => r.stage === stage).reduce((sum, r) => sum + Number(r.c), 0);
        overviewTotal.textContent = loadError
            ? 'Unavailable'
            : `${total} student${total === 1 ? '' : 's'} · Select a stage to filter the list`;
        stageChart.replaceChildren();
        stageChart.classList.toggle('is-empty', total === 0);
        stageChart.setAttribute('role', total ? 'group' : 'status');
        if (!total) {
            stageChart.removeAttribute('aria-label');
            const state = document.createElement('div');
            state.className = 'ierb-overview-empty';
            const icon = document.createElement('i');
            icon.className = 'fa-solid fa-chart-column';
            icon.setAttribute('aria-hidden', 'true');
            const copy = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = loadError ? 'Progress overview unavailable' : 'No progress data yet';
            const description = document.createElement('span');
            description.textContent = loadError || (isAdmin
                ? 'Stage distribution will appear after the first IERB record is added.'
                : 'Stage distribution will appear when IERB records are available for your assigned students.');
            copy.append(title, description);
            state.append(icon, copy);
            stageChart.appendChild(state);
            return;
        }
        stageChart.setAttribute('aria-label', STAGES.map(stage => `${labelForStage(stage)}: ${stageCount(stage)}`).join('; '));
        STAGES.forEach(stage => {
            const count = stageCount(stage);
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'stage-summary-card';
            card.dataset.stage = stage;
            card.setAttribute('aria-pressed', String(stageFilter.value === stage));
            card.setAttribute('aria-label', `${labelForStage(stage)}: ${count} student${count === 1 ? '' : 's'}`);

            const countLabel = document.createElement('strong');
            countLabel.className = 'stage-summary-count';
            countLabel.textContent = String(count);
            const stageLabel = document.createElement('span');
            stageLabel.className = 'stage-summary-label';
            stageLabel.textContent = labelForStage(stage);
            const track = document.createElement('span');
            track.className = 'stage-summary-track';
            track.setAttribute('aria-hidden', 'true');
            const fill = document.createElement('span');
            fill.style.width = `${(count / total) * 100}%`;
            track.appendChild(fill);
            card.append(countLabel, stageLabel, track);
            stageChart.appendChild(card);
        });
    }

    stageChart.addEventListener('click', event => {
        const card = event.target.closest('.stage-summary-card');
        if (!card) return;
        stageFilter.value = stageFilter.value === card.dataset.stage ? '' : card.dataset.stage;
        stageFilter.dispatchEvent(new Event('change', { bubbles: true }));
    });

    function filteredRecords() { return records; }

    const initialsFor = name => String(name || 'Student').trim().split(/\s+/).slice(0, 2).map(part => part.charAt(0)).join('').toUpperCase();

    function renderTable() {
        const rows = filteredRecords();
        if (loadError) recordCount.textContent = 'Unavailable';
        tableBody.replaceChildren();
        tableBody.closest('table').classList.toggle('is-empty', rows.length === 0);

        if (!rows.length) {
            const tr = document.createElement('tr');
            tr.className = 'ierb-empty-row';
            const cell = document.createElement('td');
            cell.colSpan = 7;
            const state = document.createElement('div');
            state.className = 'ierb-table-empty';
            const icon = document.createElement('i');
            const hasFilters = !!(ierbSearch.value.trim() || stageFilter.value || statusFilter.value);
            icon.className = hasFilters ? 'fa-solid fa-filter-circle-xmark' : 'fa-solid fa-folder-open';
            icon.setAttribute('aria-hidden', 'true');
            const title = document.createElement('h3');
            title.textContent = loadError ? 'Could not load IERB records' : (hasFilters ? 'No matching IERB records' : 'No IERB records yet');
            const description = document.createElement('p');
            description.textContent = loadError || (hasFilters
                ? 'Try clearing the search or choosing different stage and status filters.'
                : (isAdmin
                    ? 'Add an IERB entry to begin tracking student stages, requirements, and submissions.'
                    : 'Progress records for your assigned students will appear here.'));
            state.append(icon, title, description);
            cell.appendChild(state);
            tr.appendChild(cell);
            tableBody.appendChild(tr);
            return;
        }

        rows.forEach(record => {
            const tr = document.createElement('tr');
            const progress = Math.max(0, Math.min(100, Number(record.progress) || 0));
            const statusClass = record.status === 'Delayed' ? 'is-delayed' : (record.status === 'On Track' ? 'is-on-track' : 'is-pending');
            tr.innerHTML = `
                <td><div class="ierb-card-identity"><div class="ierb-student-head"><span class="ierb-student-avatar" aria-hidden="true">${escapeHtml(initialsFor(record.name))}</span><span class="ierb-student-copy"><strong class="ierb-student-name">${escapeHtml(record.name || 'Student name unavailable')}</strong><span class="ierb-student-meta">ID ${escapeHtml(record.studentId || 'Not recorded')}${record.course ? ` · ${escapeHtml(record.course)}` : ''}</span></span></div><strong class="ierb-research-title">${escapeHtml(record.research || 'Research title not set')}</strong><div class="ierb-card-meta">${record.protocolCode ? `<span>${escapeHtml(record.protocolCode)}</span>` : ''}${record.groupId ? `<span>${escapeHtml(record.groupId)}</span>` : ''}</div></div></td>
                <td><span class="stage-tag" title="${escapeHtml(record.stage)}">${escapeHtml(record.stageLabel || labelForStage(record.stage))}</span></td>
                <td><div class="ierb-card-progress"><div><span>Overall progress</span><strong>${progress}%</strong></div><span class="ierb-progress-track" role="progressbar" aria-label="Overall progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${progress}"><span style="width:${progress}%"></span></span></div></td>
                <td>${escapeHtml(record.requirements || 'None')}</td>
                <td>${escapeHtml(record.lastSubmissionDate || 'N/A')}</td>
                <td>${PrismUI.badge(record.status)}</td>
                <td class="row-actions"></td>`;
            tr.className = `ierb-progress-row ${statusClass}`;
            PrismAcademicFields.appendSummary(tr.cells[0], record);
            const actions = tr.querySelector('.row-actions');

            const noteBtn = document.createElement('button');
            noteBtn.className = 'icon-btn';
            noteBtn.title = 'Add note';
            noteBtn.innerHTML = '<i class="fa-solid fa-note-sticky"></i>';
            noteBtn.addEventListener('click', () => openNoteModal(record));
            actions.appendChild(noteBtn);

            const remindBtn = document.createElement('button');
            remindBtn.className = 'icon-btn';
            remindBtn.title = 'Send follow-up email';
            remindBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
            remindBtn.addEventListener('click', () => PrismUI.runAction(remindBtn, 'Sending...', () => sendFollowup(record)));
            actions.appendChild(remindBtn);

            if (isAdmin) {
                const editBtn = document.createElement('button');
                editBtn.className = 'icon-btn';
                editBtn.title = 'Edit entry';
                editBtn.innerHTML = '<i class="fa-solid fa-pen"></i>';
                editBtn.addEventListener('click', () => openEntryModal(record));
                actions.appendChild(editBtn);

                const delBtn = document.createElement('button');
                delBtn.className = 'icon-btn';
                delBtn.title = 'Archive student';
                delBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
                delBtn.addEventListener('click', e => PrismUI.runAction(e.currentTarget,'Processing...',() => deleteEntry(record)));
                actions.appendChild(delBtn);
            }

            if (!isAdmin) {
                PrismUI.actionMenu(actions, { label: 'Student actions' });
            } else {
                PrismUI.actionMenu(actions);
            }
            tableBody.appendChild(tr);
            PrismUI.compactRecord(tr,0,5,6,[],[1,2,3,4]);
            const card = tr.querySelector('.prism-record-card');
            if (card) {
                card.classList.add('ierb-progress-card', statusClass);
                const metadata=card.querySelector('.prism-record-summary');
                if (!isAdmin && metadata) {
                    [...metadata.children].forEach((field,index)=>field.classList.add('ierb-progress-field',`ierb-progress-field-${index+1}`));
                    metadata.replaceWith(...metadata.children);
                }
                const fields=card.querySelectorAll('.ierb-progress-field, .prism-record-summary > div');
                const detailsSummary=card.querySelector('.prism-record-details > summary');
                if(detailsSummary) detailsSummary.textContent='Show academic details';
                if(fields[0]) {
                    const stageSteps=document.createElement('span');
                    stageSteps.className='ierb-stage-steps';
                    stageSteps.setAttribute('aria-hidden','true');
                    const stageIndex=STAGES.indexOf(record.stage);
                    STAGES.forEach((stage,index)=>{
                        const step=document.createElement('i');
                        if(stageIndex>=0 && index<=stageIndex) step.classList.add('is-complete');
                        stageSteps.append(step);
                    });
                    fields[0].append(stageSteps);
                    if (!isAdmin && fields[1]) {
                        const progress=fields[1].querySelector('.ierb-card-progress');
                        if (progress) fields[0].append(progress);
                        fields[1].remove();
                    }
                }
                if(isAdmin) {
                    const docs=document.createElement('a');docs.href='documents.php';docs.className='prism-btn ierb-open-documents';docs.textContent='Open documents';
                    card.querySelector('.prism-record-actions').prepend(docs);
                }
            }
        });
    }

    const searchReload = PrismUI.debounce(() => { pager.reset(); loadRecords(); });
    ierbSearch.addEventListener('input', () => { ++recordRequestSequence; pager.reset(); searchReload(); });
    filterControls.forEach(control=>control.addEventListener('change',()=>{pager.reset();loadRecords();}));

    // --- Entry add/edit modal ---
    const entryModal = document.getElementById('ierbEntryModal');
    const entryForm = document.getElementById('ierbEntryForm');
    const entryDirty = PrismUI.dirtyForm(entryForm);
    const entryTitle = document.getElementById('ierbEntryTitle');
    const academicFields = PrismAcademicFields.mount(document.getElementById('entryAcademicFields'));
    let entryReturnFocus = null;
    let editingId = null;

    function openEntryModal(record) {
        entryReturnFocus = document.activeElement;
        entryForm.reset();
        academicFields.setRecord(record);
        editingId = record ? record.id : null;
        document.getElementById('entryGroupId').required = !record;
        entryTitle.textContent = record ? 'Edit IERB Entry' : 'Add IERB Entry';
        if (record) {
            document.getElementById('entryStudentName').value = record.name || '';
            document.getElementById('entryStudentId').value = record.studentId || '';
            document.getElementById('entryEmail').value = record.email || '';
            document.getElementById('entryStage').value = record.stage || 'Stage 1';
            document.getElementById('entryResearchTitle').value = record.research || '';
            document.getElementById('entryRequirements').value = record.requirements || '';
            document.getElementById('entrySubmissionDate').value = record.lastSubmissionDate || '';
            document.getElementById('entryStatus').value = record.status || 'On Track';
        }
        entryDirty.clean();
        entryModal.setAttribute('aria-hidden', 'false');
        entryModal.style.display = 'flex';
        document.getElementById('entryStudentName').focus();
    }
    function closeEntryModal() {
        entryDirty.clean();
        entryModal.style.display = 'none';
        entryReturnFocus?.focus();
        entryModal.setAttribute('aria-hidden', 'true');
    }
    document.getElementById('addIerbEntry')?.addEventListener('click', () => openEntryModal(null));
    document.getElementById('closeIerbEntry').addEventListener('click', closeEntryModal);
    document.getElementById('cancelIerbEntry').addEventListener('click', closeEntryModal);
    entryModal.addEventListener('click', e => { if (e.target === entryModal) closeEntryModal(); });
    entryModal.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeEntryModal(); return; }
        if (event.key !== 'Tab') return;
        const controls = [...entryModal.querySelectorAll('button,input,select,textarea,[tabindex]')].filter(e => !e.disabled && e.tabIndex >= 0 && e.getClientRects().length);
        if (!controls.length) return;
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    entryForm.addEventListener('submit', async event => {
        event.preventDefault();
        const payload = {
            id: editingId,
            name: document.getElementById('entryStudentName').value.trim(),
            studentId: document.getElementById('entryStudentId').value.trim(),
            email: document.getElementById('entryEmail').value.trim(),
            groupId: document.getElementById('entryGroupId').value.trim(),
            stage: document.getElementById('entryStage').value,
            research: document.getElementById('entryResearchTitle').value.trim(),
            requirements: document.getElementById('entryRequirements').value.trim(),
            submissionDate: document.getElementById('entrySubmissionDate').value,
            status: document.getElementById('entryStatus').value,
        };
        Object.assign(payload, academicFields.payload());
        const saveBtn = entryForm.querySelector('[type="submit"]');
        const release = PrismUI.busy(saveBtn, 'Saving...');
        if (!release) return;
        try {
            const original = editingId ? records.find(r => r.id === editingId) : null;
            if (original && (original.stage !== payload.stage || original.status !== payload.status)) {
                const answer = await PrismUI.confirm({
                    title:'Confirm progress override', icon:'fa-user-shield', tone:'override', confirmText:'Save changes',
                    message:'Changing the official stage or status is recorded as an Admin Override and the student will be notified.',
                    reasonLabel:'Reason for this change', reasonRequired:true
                });
                if (!answer) return;
                payload.reason = answer.reason;
            }
            const data = await PrismUI.postJson('ierb_api.php?action=save', payload);
            closeEntryModal();
            await loadRecords();
            PrismUI.toast(data.message || 'IERB record saved.', 'success');
            if (data.setupPending && !data.temporaryPassword) {
                PrismUI.toast(data.setupMessage || 'Account created. Contact the RPMS office to arrange password setup.', 'info');
            }
            if (data.temporaryPassword) {
                await PrismUI.confirm({
                    title:'Account setup required',
                    icon:'fa-key',
                    confirmText:'I copied it',
                    message:'Live account-setup email is not available. Give this one-time temporary password to the student securely. They will be required to change it after signing in.',
                    extraHtml:'<label>Temporary password</label><input id="prismTempPassword" readonly value="' + escapeHtml(data.temporaryPassword) + '" onclick="this.select()">'
                });
            }
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        } finally {
            release();
        }
    });

    async function deleteEntry(record) {
        const answer = await PrismUI.confirm({
            title:'Archive student record', icon:'fa-box-archive', tone:'danger', confirmText:'Archive',
            message:`Archive ${record.name} and deactivate their login? All Student, IERB and document history will be retained.`
        });
        if (!answer) return;
        try {
            const data = await PrismUI.postJson('ierb_api.php?action=delete', { id: record.id });
            await loadRecords();
            PrismUI.toast(data.message || 'Record deleted.', 'success');
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        }
    }

    // --- Note modal ---
    const actionModal = document.getElementById('ierbActionModal');
    const actionForm = document.getElementById('ierbActionForm');
    const noteDirty = PrismUI.dirtyForm(actionForm);
    const actionText = document.getElementById('ierbActionText');
    let noteTargetId = null;
    let noteReturnFocus = null;

    function openNoteModal(record) {
        noteReturnFocus = document.activeElement;
        noteTargetId = record.id;
        actionText.value = '';
        noteDirty.clean();
        document.getElementById('ierbActionTitle').textContent = `Add Note - ${record.name}`;
        actionModal.setAttribute('aria-hidden', 'false');
        actionModal.style.display = 'flex';
        actionText.focus();
    }
    function closeActionModal() {
        noteDirty.clean();
        actionModal.setAttribute('aria-hidden', 'true');
        actionModal.style.display = 'none';
        noteReturnFocus?.focus();
    }
    document.getElementById('closeIerbModal').addEventListener('click', closeActionModal);
    document.getElementById('cancelIerbAction').addEventListener('click', closeActionModal);
    actionModal.addEventListener('click', e => { if (e.target === actionModal) closeActionModal(); });
    actionModal.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeActionModal(); return; }
        if (event.key !== 'Tab') return;
        const controls = [...actionModal.querySelectorAll('button,input,textarea,[tabindex]')].filter(e => !e.disabled && e.tabIndex >= 0 && e.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });

    actionForm.addEventListener('submit', async event => {
        event.preventDefault();
        const note = actionText.value.trim();
        if (!note) { PrismUI.toast('Write a note before saving.', 'error'); actionText.focus(); return; }
        const btn = actionForm.querySelector('[type="submit"]');
        const release = PrismUI.busy(btn, 'Saving...');
        if (!release) return;
        try {
            const data = await PrismUI.postJson('ierb_api.php?action=note', { studentId: noteTargetId, note });
            closeActionModal();
            PrismUI.toast(data.message || 'Note saved.', 'success');
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        } finally {
            release();
        }
    });

    async function sendFollowup(record) {
        const answer = await PrismUI.confirm({
            title:'Send follow-up', icon:'fa-paper-plane', confirmText:'Send follow-up',
            dialogClass:'ierb-followup-dialog',
            message:`Send the standard IERB progress follow-up to ${record.name} at ${record.email}?`
        });
        if (!answer) return;
        try {
            const data = await PrismUI.postJson('send_followup.php', { studentDbId: record.id });
            PrismUI.toast(data.message || 'Follow-up sent.', 'success');
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        }
    }

    loadRecords();
})();
