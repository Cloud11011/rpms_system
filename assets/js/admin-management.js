(function () {
    'use strict';

    // Theme toggle (shared behavior with dashboard.php)
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

    const isAdviser = document.body.dataset.management === 'adviser';
    const apiUrl = isAdviser ? 'advisers_api.php' : 'students_api.php';
    // "loggedInRole" = the role of the person USING this page (admin or
    // adviser). Not to be confused with `isAdviser` above, which means
    // "this page instance manages the adviser roster".
    const loggedInRole = document.body.dataset.userRole || 'admin';
    const stageLabels = window.PRISM_STAGE_LABELS || {};

    function labelForStage(stageKey) {
        return stageLabels[stageKey] || stageKey;
    }

    // Populate the stage <select> option TEXT with the configured form/
    // document names, while keeping the underlying VALUE as "Stage 1" etc
    // so every other part of the app (filters, DB values) stays unchanged
    // (feature request 7: dynamic stage labels).
    const stageSelect = document.getElementById('stage');
    if (stageSelect) {
        stageSelect.querySelectorAll('option').forEach(opt => {
            if (opt.value) opt.textContent = `${opt.value} - ${labelForStage(opt.value)}`;
        });
    }

    // Advisers can add/assign only their OWN students (feature request 4);
    // the adviser dropdown would be misleading to show since the server
    // ignores it and forces their own id anyway, so hide it entirely.
    const adviserFieldLabel = document.getElementById('adviserFieldLabel');
    if (!isAdviser && loggedInRole === 'adviser' && adviserFieldLabel) {
        adviserFieldLabel.style.display = 'none';
    }

    // Protocol Code / Principal Investigator are RPMS-office decisions
    // (feature requests 10-11), not something an adviser sets themselves.
    const protocolCodeField = document.getElementById('protocolCodeField');
    const principalField = document.getElementById('principalField');
    if (!isAdviser && loggedInRole === 'adviser') {
        if (protocolCodeField) protocolCodeField.style.display = 'none';
        if (principalField) principalField.style.display = 'none';
    }

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);

    const searchInput = document.getElementById('recordSearch');
    const addBtn = document.getElementById('addRecord');
    const countEl = document.getElementById('recordCount');
    const rowsEl = document.getElementById('recordRows');

    const modal = document.getElementById('recordModal');
    const modalTitle = document.getElementById('modalTitle');
    const closeBtn = document.getElementById('closeRecord');
    const cancelBtn = document.getElementById('cancelRecord');
    const form = document.getElementById('recordForm');
    const idField = document.getElementById('recordId');
    const nameField = document.getElementById('recordName');
    const accountIdField = document.getElementById('accountId');
    const emailField = document.getElementById('recordEmail');

    const academicFields = isAdviser ? null : PrismAcademicFields.mount(document.getElementById('studentAcademicFields'));
    let returnFocus = null;
    let records = [];
    let loadError = false;
    let deletionAvailability = {available:false,message:'Checking permanent-delete schema verification...'};
    async function loadDeletionAvailability() {
        if(loggedInRole!=='admin') return;
        try {
            const data=await PrismUI.request('account_lifecycle_api.php?action=availability');
            deletionAvailability={available:data.available===true,message:data.message || 'Permanent deletion is unavailable.'};
        } catch(_) {
            deletionAvailability={available:false,message:'Schema verification could not be checked. Permanent deletion is unavailable; use Archive.'};
        }
        document.getElementById('permanentDeleteAvailability').textContent=deletionAvailability.message;
        render();
    }
    loadDeletionAvailability();

    const definitions = isAdviser ? [['department','Departments'],['status','Account statuses'],['group','Research groups']]
      : [['academicUnitKey','Academic units'],['programKey','Programs'],['academicYear','Academic years'],['yearLevel','Year levels'],['group','Research groups'],['stage','Stages'],['status','Statuses'],['protocol','Protocol status']];
    if (!isAdviser && loggedInRole === 'admin') definitions.push(['adviserId','Research advisers']);
    const filters = PrismUI.recordFilters(document.getElementById('recordFilters'), definitions);
    const sort = PrismUI.recordFilters(document.getElementById('recordFilters'), [['sortBy','Sort by'],['direction','Direction']]);
    const sortOptions = isAdviser
      ? [['name','Name'],['employeeId','Employee ID'],['email','Email'],['department','Academic unit / Department'],['status','Account status']]
      : [['name','Name'],['studentId','Student ID'],['email','Email'],['group','Research group'],['adviser','Research adviser'],['stage','IERB stage'],['status','Status'],['academicYear','Academic year']];
    sort.update({sortBy:sortOptions.map(([value,label])=>({value,label})),direction:[{value:'ASC',label:'Ascending'},{value:'DESC',label:'Descending'}]});
    sort.controls[0].options[0].textContent = 'Name (default)';
    sort.controls[1].options[0].textContent = 'Ascending (default)';
    if (loggedInRole === 'admin') {
        const lifecycleFilters=PrismUI.recordFilters(document.getElementById('recordFilters'), [['lifecycle','Lifecycle'],['retention','Retention status']]);
        filters.controls.push(...lifecycleFilters.controls);
        const baseQuery=filters.query, baseUpdate=filters.update;
        filters.query=()=>({...baseQuery(),...lifecycleFilters.query()});
        filters.update=options=>{baseUpdate(options);lifecycleFilters.update(options);};
    }
    const filterControls = [...filters.controls,...sort.controls];
    const pager = PrismUI.recordPager(rowsEl.closest('table').parentElement, countEl, [searchInput,...filterControls], loadRecords);
    const retentionUI=loggedInRole==='admin'?PrismRetentionAdmin.mount({
        type:isAdviser?'adviser':'student', rows:rowsEl, getScope:()=>({q:searchInput.value.trim(),...filters.query()}),
        getRecords:()=>records, getAvailability:()=>deletionAvailability, refresh:loadRecords,
        openBulk:openBulkDelete, openRecovery,
        reviewCleanup:async()=>{document.getElementById('recordFilters_lifecycle').value='archived';document.getElementById('recordFilters_retention').value='cleanup';pager.reset();await loadRecords();}
    }):null;
    const dirty = PrismUI.dirtyForm(form);
    let requestSequence = 0;
    async function loadRecords() {
        retentionUI?.syncScope();
        searchReload.cancel();
        const request = ++requestSequence;
        pager.loading();
        loadError = false;
        try {
            const data = await PrismUI.request(apiUrl + '?' + new URLSearchParams({action:'list',page:pager.page,q:searchInput.value.trim(),...filters.query(),...sort.query()}));
            const items = isAdviser ? data.advisers : data.students;
            if (!Array.isArray(items)) throw new Error('Could not load records.');
            if (request !== requestSequence) return;
            records = items;
            filters.update(data.filterOptions);
            pager.render({ ...data, total: data.total ?? records.length });
            retentionUI?.onPage(data.total ?? records.length);
        } catch (e) {
            if (request !== requestSequence) return;
            records = [];
            loadError = true;
            pager.error();
            PrismUI.toast(e.message || 'Could not load records.', 'error');
        }
        if (!isAdviser) {
            await loadAdviserOptions();
        }
        render();
    }

    async function loadAdviserOptions() {
        const select = document.getElementById('adviser');
        if (!select) return;
        try {
            const data = await PrismUI.request('students_api.php?action=adviser_options');
            const options = data.ok ? data.advisers : [];
            select.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
            options.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.full_name;
                select.appendChild(opt);
            });
        } catch (_) { /* keep default */ }
    }

    function matchesSearch(record, term) {
        if (!term) return true;
        const haystack = isAdviser
            ? [record.name, record.employeeId, record.email, record.department, record.groups]
            : [record.name, record.studentId, record.email, record.research, record.group, record.adviserName];
        return haystack.some(v => String(v ?? '').toLowerCase().includes(term));
    }

    function render() {
        const term = (searchInput.value || '').trim().toLowerCase();
        const filtered = records;
        rowsEl.replaceChildren();

        if (loadError) {
            countEl.textContent = 'Records unavailable';
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = (isAdviser ? 6 : 7)+(retentionUI?1:0);
            cell.className = 'empty-state';
            const message = document.createElement('p');
            message.setAttribute('role', 'alert');
            message.textContent = 'Could not load records. Please try again.';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'prism-btn';
            retry.textContent = 'Retry loading records';
            retry.addEventListener('click', async () => {
                retry.disabled = true;
                await loadRecords();
            });
            cell.append(message, retry);
            row.append(cell);
            rowsEl.append(row);
            return;
        }
        if (!filtered.length) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="${(isAdviser ? 6 : 7)+(retentionUI?1:0)}" class="empty-state">No ${isAdviser ? 'adviser' : 'student'} records found.</td>`;
            rowsEl.appendChild(tr);
            return;
        }

        filtered.forEach(record => {
            const tr = document.createElement('tr');
            if (isAdviser) {
                tr.innerHTML = `
                    <td><strong>${escapeHtml(record.name)}</strong>${record.archivedAt ? ' <span class="prism-badge">Archived</span>' : ''}<br><small>${escapeHtml(record.email)}</small></td>
                    <td>${escapeHtml(record.employeeId)}</td>
                    <td>${escapeHtml(record.department || 'N/A')}</td>
                    <td class="adviser-groups"></td>
                    <td>${PrismUI.badge(record.status)}</td>
                    <td class="row-actions"></td>`;
            } else {
                const protocolBadge = record.protocolCode
                    ? `<span class="protocol-badge" title="Protocol Code">${escapeHtml(record.protocolCode)}</span>`
                    : '<span class="muted">Not yet assigned</span>';
                const piBadge = record.isPrincipalInvestigator ? ' <span class="pi-badge" title="Principal Investigator">PI</span>' : '';
                tr.innerHTML = `
                    <td><strong>${escapeHtml(record.name)}</strong>${record.archivedAt ? ' <span class="prism-badge">Archived</span>' : ''}<br><small>${escapeHtml(record.email)}</small></td>
                    <td>${escapeHtml(record.studentId)}</td>
                    <td>${escapeHtml(record.research || 'Not set')}<br><small>${escapeHtml(record.group || 'No group')}</small></td>
                    <td>${escapeHtml(record.adviserName || 'Unassigned')}</td>
                    <td><span class="stage-tag" title="${escapeHtml(record.stage)}">${escapeHtml(record.stageLabel || labelForStage(record.stage))}</span> ${PrismUI.badge(record.status)}</td>
                    <td>${protocolBadge}${piBadge}</td>
                    <td class="row-actions"></td>`;
            }
            if (isAdviser) {
                const groups = Array.isArray(record.groups) ? record.groups : [];
                const cell = tr.querySelector('.adviser-groups');
                if (!groups.length) cell.textContent = 'No assigned research groups';
                groups.forEach(group => {
                    const chip = document.createElement('span');
                    chip.className = 'prism-badge adviser-group-chip';
                    chip.textContent = group;
                    cell.append(chip);
                });
            } else PrismAcademicFields.appendSummary(tr.cells[2], record);
            const actions = document.createElement('div');
            actions.className='lifecycle-actions';
            tr.querySelector('.row-actions').append(actions);
            const accountLabel=isAdviser?'Adviser':'Student';
            const archived=!!record.archivedAt;
            const editBtn = document.createElement('button');
            editBtn.type='button';
            editBtn.className = 'icon-btn lifecycle-action';
            editBtn.title = 'Edit '+accountLabel;
            editBtn.setAttribute('aria-label',editBtn.title);
            editBtn.innerHTML = '<i class="fa-solid fa-pen" aria-hidden="true"></i>';
            editBtn.addEventListener('click', () => openModal(record));
            const delBtn = document.createElement('button');
            delBtn.type='button';
            delBtn.className = 'icon-btn lifecycle-action lifecycle-archive';
            delBtn.title = isAdviser ? 'Archive Adviser' : 'Archive Student';
            if(archived) delBtn.title=accountLabel+' is already archived';
            delBtn.disabled=archived;
            delBtn.setAttribute('aria-label',delBtn.title);
            delBtn.innerHTML = '<i class="fa-solid fa-box-archive" aria-hidden="true"></i>';
            delBtn.addEventListener('click', e => PrismUI.runAction(e.currentTarget,'Processing...',() => deleteRecord(record)));
            actions.append(editBtn);
            if (loggedInRole === 'admin') {
                actions.append(delBtn);
                const hardDelete=document.createElement('button');
                hardDelete.type='button';
                hardDelete.className='icon-btn lifecycle-action lifecycle-danger';
                const disabledReason=!archived?'Archive '+accountLabel+' before permanent deletion.':
                    (!deletionAvailability.available?deletionAvailability.message:(record.lifecycle?.manualEligible?'':(record.lifecycle?.purgeBlockReason || 'Server eligibility must be checked.')));
                hardDelete.title=disabledReason || 'Permanently Delete '+accountLabel;
                hardDelete.setAttribute('aria-label','Permanently Delete '+accountLabel+(disabledReason?'. '+disabledReason:''));
                // Keep blocked actions keyboard-focusable so their explanation is discoverable.
                hardDelete.setAttribute('aria-disabled',String(!!disabledReason));
                hardDelete.innerHTML='<i class="fa-solid fa-trash" aria-hidden="true"></i>';
                hardDelete.addEventListener('click',()=>{if(!disabledReason)openPermanentDelete(record,hardDelete);});
                actions.append(hardDelete);
                if (archived) {
                    const restore=document.createElement('button');restore.type='button';restore.className='icon-btn lifecycle-action';restore.title='Restore '+accountLabel;restore.setAttribute('aria-label',restore.title);restore.innerHTML='<i class="fa-solid fa-rotate-left" aria-hidden="true"></i>';restore.addEventListener('click',()=>retentionUI.singleAction(record,'restore',restore));actions.append(restore);
                    const hold=document.createElement('button');hold.type='button';hold.className='icon-btn lifecycle-action';const held=record.lifecycle?.retentionHold;hold.title=(held?'Remove':'Place')+' Retention Hold';hold.setAttribute('aria-label',hold.title);hold.innerHTML='<i class="fa-solid fa-pause" aria-hidden="true"></i>';hold.addEventListener('click',()=>retentionUI.singleAction(record,held?'remove_hold':'hold',hold));actions.append(hold);
                    if(record.lifecycle?.overrideAvailable) {
                        const override=document.createElement('button');override.type='button';override.className='icon-btn lifecycle-action lifecycle-danger';override.title='Delete Now ? Override Grace Period';override.setAttribute('aria-label',override.title);override.setAttribute('aria-disabled',String(!deletionAvailability.available));override.innerHTML='<i class="fa-solid fa-clock" aria-hidden="true"></i>';override.addEventListener('click',()=>{if(deletionAvailability.available)openPermanentDelete(record,override,'grace_period_override');});actions.append(override);
                    }
                }
                retentionUI.attachRow(tr,record);
                if (archived) {
                    const state=document.createElement('small');state.className='retention-state';const lc=record.lifecycle || {};
                    state.textContent=lc.retentionHold?'Retention Hold':lc.unresolvedWorkflow?'Purge postponed ? Admin review required':lc.cleanupEligible?'Eligible for retention cleanup':lc.approachingRetention?'Approaching retention cleanup':lc.manualEligible?'Eligible for manual purge':lc.purgeBlockReason || 'Archived';
                    tr.querySelector('td:not(.retention-select)').append(state);
                }
            }
            rowsEl.appendChild(tr);
        });
    }

    function openModal(record) {
        returnFocus = document.activeElement;
        form.reset();
        academicFields?.setRecord(record);
        idField.value = record ? record.id : '';
        modalTitle.textContent = record ? `Edit ${isAdviser ? 'Research Adviser' : 'Student'} Record` : `Add ${isAdviser ? 'Research Adviser' : 'Student'}`;

        if (record) {
            nameField.value = record.name || '';
            accountIdField.value = isAdviser ? record.employeeId : record.studentId;
            emailField.value = record.email || '';
            if (isAdviser) {
                document.getElementById('department').value = record.department || '';
                document.getElementById('accountStatus').value = record.status || 'Active';
            } else {
                document.getElementById('research').value = record.research || '';
                document.getElementById('requirements').value = record.requirements || '';
                const adviserSelect = document.getElementById('adviser');
                if (record.adviserId && ![...adviserSelect.options].some(option => option.value === String(record.adviserId))) {
                    adviserSelect.add(new Option((record.adviserName || 'Existing adviser') + ' (retained assignment)', String(record.adviserId)));
                }
                adviserSelect.value = record.adviserId || '';
                document.getElementById('stage').value = record.stage || 'Stage 1';
                document.getElementById('recordStatus').value = record.status || 'On Track';
                const pcField = document.getElementById('protocolCode');
                const piField = document.getElementById('isPrincipal');
                if (pcField) pcField.value = record.protocolCode || '';
                if (piField) piField.checked = !!record.isPrincipalInvestigator;
            }
        }
        if (!isAdviser && loggedInRole === 'adviser') {
            [nameField, accountIdField, emailField].forEach(field => { field.readOnly = !!record; });
            ['stage', 'recordStatus'].forEach(id => { const field = document.getElementById(id); field.disabled = true; field.closest('label').style.display = record ? '' : 'none'; });
            document.getElementById('adviser').disabled = true;
            adviserFieldLabel.style.display = record ? '' : 'none';
            protocolCodeField.style.display = record ? '' : 'none';
            principalField.style.display = record ? '' : 'none';
            document.getElementById('protocolCode').readOnly = true;
            document.getElementById('isPrincipal').disabled = true;
        }
        if (isAdviser) {
            document.getElementById('accountStatus').disabled=!!record;
            const department = document.getElementById('department');
            department.querySelector('[data-legacy]')?.remove();
            const value = record?.department || '';
            if (value && ![...department.options].some(option=>option.value===value)) {
                const option = new Option(value + ' (existing legacy value)',value);option.dataset.legacy='1';department.add(option);
            }
            department.value=value;department.required=!record;
        }
        dirty.clean();
        modal.style.display = 'flex';
        (nameField.readOnly ? document.getElementById('research') : nameField).focus();
    }

    function closeModal() {
        dirty.clean();
        modal.style.display = 'none';
        returnFocus?.focus();
    }

    async function deleteRecord(record) {
        try {
            const fresh=await PrismUI.request('account_lifecycle_api.php?'+new URLSearchParams({action:'account_state',accountType:isAdviser?'adviser':'student',targetId:record.id}));
            const answer=await PrismUI.confirm({title:isAdviser?'Archive Adviser':'Archive Student',icon:'fa-box-archive',tone:'danger',confirmText:'Archive',
                message:isAdviser?`This Adviser currently has ${fresh.assignedStudents} assigned Students. They will become Unassigned. Archive ${record.name}?`:`Archive ${record.name} and deactivate their login? This starts a 7-day grace period and a 6-month retention countdown. History stays available during archive.`});
            if(!answer)return;
            const data=await PrismUI.postJson('account_lifecycle_api.php',{action:'archive',accountType:isAdviser?'adviser':'student',targetId:record.id,expectedAssignedStudents:fresh.assignedStudents});
            await loadRecords();PrismUI.toast(data.message,'success');
        } catch(e) { PrismUI.toast(e.message,'error'); }
    }

    const permanentDialog=document.getElementById('permanentDeleteDialog');
    const permanentForm=document.getElementById('permanentDeleteForm');
    let permanentRecord=null, permanentReturnFocus=null, permanentContext=null;
    function preparePermanentDialog(button,title,identity,label,warning) {
        permanentReturnFocus=button || document.activeElement;permanentForm.reset();
        document.getElementById('permanentDeleteTitle').textContent=title;
        document.getElementById('permanentDeleteIdentity').textContent=identity;
        document.getElementById('permanentDeleteConfirmationLabel').textContent=label;
        document.getElementById('permanentDeleteWarning').textContent=warning;
        document.getElementById('permanentDeleteResult').textContent='';
        const override=permanentContext?.action==='grace_period_override';
        document.getElementById('permanentDeleteReasonGroup').hidden=!override;
        permanentForm.elements.reason.required=override;
        permanentForm.querySelector('[type="submit"]').textContent=permanentContext?.mode==='recovery'?'Run recovery':permanentContext?.mode==='bulk'?'Apply confirmed batch':'Permanently delete';
        permanentDialog.showModal();
    }
    async function openPermanentDelete(record,button,action='permanent_delete') {
        if(!deletionAvailability.available || !record.archivedAt)return;
        try {
            const fresh=await PrismUI.request('account_lifecycle_api.php?'+new URLSearchParams({action:'account_state',accountType:isAdviser?'adviser':'student',targetId:record.id}));
            if(!(action==='grace_period_override'?fresh.lifecycle.overrideAvailable:fresh.lifecycle.manualEligible))throw new Error(fresh.lifecycle.purgeBlockReason || 'Purge is unavailable.');
            permanentRecord=record;permanentContext={mode:'single',action};
            preparePermanentDialog(button,action==='grace_period_override'?'Delete Now ? Override Grace Period':`Permanently Delete ${isAdviser?'Adviser':'Student'}`,
                record.name+' ? '+(isAdviser?record.employeeId:record.studentId),'Type the exact '+(isAdviser?'Employee ID':'Student ID'),
                isAdviser?'This permanently removes this Adviser account and credentials. Student assignments become Unassigned. Historical Adviser display attribution and workflow evidence may remain. This action cannot be undone.':'This permanently removes this Student account and applicable Student-specific PRISM records and uploaded files. This action cannot be undone.');
        } catch(error) { PrismUI.toast(error.message,'error'); }
    }
    function openBulkDelete(preview,button) {
        permanentRecord=null;permanentContext={mode:'bulk',preview};
        preparePermanentDialog(button,'Confirm bulk lifecycle action',`${preview.selected} selected; ${preview.eligible} eligible; ${preview.skipped.length} skipped. ${preview.unassignedStudents} assigned Students will become Unassigned.`,
            'Type: '+preview.phrase,preview.purge?'This permanently removes eligible accounts and applicable owned PRISM records and files. Historical aggregate reports and Adviser attribution may remain. This action cannot be undone.':'Each eligible account will be rechecked and updated independently. Review the selection and assignment impact before confirming.');
        const result=document.getElementById('permanentDeleteResult');
        result.textContent=preview.skipped.map(r=>r.identifier+' ? '+r.reason).join('\n');
    }
    function openRecovery(job,button) {
        permanentRecord=null;permanentContext={mode:'recovery',job};
        preparePermanentDialog(button,'Complete purge recovery',job.jobId,'Type: RECOVER '+job.jobId,
            'Recovery checks the database commit evidence. Committed jobs finalize quarantined files; rolled-back jobs restore files to their original owned records.');
    }
    function closePermanentDelete() { permanentDialog.close();permanentForm.reset();permanentRecord=null;permanentContext=null;permanentReturnFocus?.focus(); }
    document.getElementById('cancelPermanentDelete')?.addEventListener('click',closePermanentDelete);
    permanentDialog?.addEventListener('cancel',event=>{event.preventDefault();closePermanentDelete();});
    permanentForm?.addEventListener('submit',async event=>{
        event.preventDefault();if(!permanentContext)return;
        const button=permanentForm.querySelector('[type="submit"]'),release=PrismUI.busy(button,'Processing...');if(!release)return;
        try {
            const credentials={currentPassword:permanentForm.elements.currentPassword.value,confirmation:permanentForm.elements.confirmation.value,confirmed:permanentForm.elements.confirmed.checked};
            let data;
            if(permanentContext.mode==='bulk') data=await retentionUI.executeBulk(permanentContext.preview,credentials,text=>{document.getElementById('permanentDeleteResult').textContent=text;});
            else if(permanentContext.mode==='recovery') {
                if(credentials.confirmation!=='RECOVER '+permanentContext.job.jobId)throw new Error('Type the exact recovery phrase.');
                data=await PrismUI.postJson('account_lifecycle_api.php',{action:'recover',jobId:permanentContext.job.jobId,...credentials});
            } else data=await PrismUI.postJson('account_lifecycle_api.php',{accountType:isAdviser?'adviser':'student',action:permanentContext.action,targetId:permanentRecord.id,...credentials,reason:permanentForm.elements.reason.value.trim()});
            closePermanentDelete();await loadRecords();await retentionUI.refreshSummary();PrismUI.toast(data.message || 'Lifecycle batch completed. Review committed outcomes below.',data.recoveryRequired?'info':'success');
        } catch(error) { document.getElementById('permanentDeleteResult').textContent=error.message;permanentForm.elements.currentPassword.value='';PrismLifecycleForms.resetPassword(permanentForm);await retentionUI.refreshSummary(); }
        finally { release(); }
    });

    const recordNameForMessage = record => record?.name || 'this student';

    addBtn.addEventListener('click', () => openModal(null));
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    modal.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeModal(); }
        if (event.key !== 'Tab') return;
        const controls = [...modal.querySelectorAll('button,input,select,textarea,[tabindex]')].filter(e => !e.disabled && e.tabIndex >= 0 && e.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    const searchReload = PrismUI.debounce(() => { pager.reset(); loadRecords(); });
    searchInput.addEventListener('input', () => { ++requestSequence; pager.reset(); searchReload(); });
    filterControls.forEach(control=>control.addEventListener('change',()=>{pager.reset();loadRecords();}));

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const submitBtn = form.querySelector('[type="submit"]');
        const release = PrismUI.busy(submitBtn,'Saving...');
        if(!release)return;
        try {
            const payload = {
                id: idField.value ? Number(idField.value) : 0,
                name: nameField.value.trim(),
                email: emailField.value.trim(),
            };
            if (isAdviser) {
                payload.employeeId = accountIdField.value.trim();
                payload.department = document.getElementById('department').value.trim();
                payload.status = document.getElementById('accountStatus').value;
            } else {
                payload.studentId = accountIdField.value.trim();
                payload.research = document.getElementById('research').value.trim();
                payload.group = document.getElementById('group').value.trim();
                Object.assign(payload, academicFields.payload());
                payload.requirements = document.getElementById('requirements').value.trim();
                payload.adviserId = document.getElementById('adviser').value || null;
                payload.stage = document.getElementById('stage').value;
                payload.status = document.getElementById('recordStatus').value;
                const pcField = document.getElementById('protocolCode');
                const piField = document.getElementById('isPrincipal');
                if (pcField) payload.protocolCode = pcField.value.trim();
                if (piField) payload.isPrincipalInvestigator = piField.checked;
            }

            if (!isAdviser && loggedInRole === 'admin' && payload.id) {
                const original = records.find(r => r.id === payload.id);
                if (original && (original.stage !== payload.stage || original.status !== payload.status)) {
                    const answer = await PrismUI.confirm({
                        title:'Confirm progress change', icon:'fa-clipboard-check', confirmText:'Save changes',
                        message:`Changing ${recordNameForMessage(original)}'s stage or status will be added to the official progress history and the student will be notified.`,
                        reasonLabel:'Reason for this progress change', reasonRequired:true
                    });
                    if (!answer) return;
                    payload.reason = answer.reason;
                }
            }
            const data = await PrismUI.postJson(`${apiUrl}?action=save`, payload);
            closeModal();
            await loadRecords();
            PrismUI.toast(data.message || 'Record saved.', 'success');
            if (data.setupPending && !data.temporaryPassword) {
                PrismUI.toast(data.setupMessage || 'Account created. Contact the RPMS office to arrange password setup.', 'info');
            }
            if (data.temporaryPassword) {
                await PrismUI.confirm({
                    title:'Account setup required',
                    icon:'fa-key',
                    confirmText:'I copied it',
                    message:'Live account-setup email is not available. Give this one-time temporary password to the user securely. They will be required to change it after signing in.',
                    extraHtml:'<label>Temporary password</label><input id="prismTempPassword" readonly value="' + escapeHtml(data.temporaryPassword) + '" onclick="this.select()">'
                });
            }
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        } finally {
            release();
        }
    });

    loadRecords();
})();
