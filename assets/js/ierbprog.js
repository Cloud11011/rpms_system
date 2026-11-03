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
    ierbSearch.value = (new URLSearchParams(window.location.search).get('q') || '').slice(0,255);
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
    sort.update({sortBy:[['submission','Formal submission'],['title','Research Title'],['name','Name'],['studentId','Student ID'],['stage','IERB stage'],['status','Status'],['academicYear','Academic year'],['group','Research group']].map(([value,label])=>({value,label})),direction:[{value:'ASC',label:'Ascending'},{value:'DESC',label:'Descending'}]});
    sort.controls[0].options[0].textContent='Newest submission first (default)';sort.controls[1].options[0].textContent='Default direction';
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
    const STAGES = ['initial_submission','resubmission_amendment','approval_data_gathering','ierb_completed'];
    const stageLabels = window.PRISM_STAGE_LABELS || {};
    const labelForStage = stageKey => stageLabels[stageKey] || stageKey;

    // Show the configured form/document name instead of a bare "Stage N"
    // wherever a stage select appears, while keeping the option VALUE as
    // "Stage 1" etc so filtering/saving stays unchanged (feature request 7).
    function refreshStageNames() { document.querySelectorAll('#stageFilter option[value], #entryStage option[value]').forEach(opt => {
        if (opt.value) opt.textContent = labelForStage(opt.value);
    }); }
    refreshStageNames();
    document.getElementById('manageIerbStages')?.addEventListener('click', event => { const control=event.currentTarget; return PrismUI.runAction(control, 'Loading...', () => PrismUI.manageStages(async () => { refreshStageNames(); await loadRecords(); }, control)); });

    async function loadRecords() {
        filters.reconcile();
        searchReload.cancel();
        const requestSequence = ++recordRequestSequence;
        loadError = '';
        pager.loading();
        stageChart.setAttribute('aria-busy', 'true');
        tableBody.setAttribute('aria-busy', 'true');
        try {
            const query={action:'monitor',layout:'cards',page:pager.page,perPage:pager.pageSize,q:ierbSearch.value.trim(),...filters.query(),...sort.query()};
            const data = await PrismUI.request('ierb_api.php?' + new URLSearchParams(Object.fromEntries(Object.entries(query).filter(([,value])=>value!==''))));
            if (requestSequence !== recordRequestSequence) return;
            if (!Array.isArray(data.records)) throw new Error('The server returned an invalid record list.');
            records = data.records.map(row=>({
                ...row,id:row.studentRecordId,research:row.research,name:row.name,group:row.groupId,
                requirements:row.pending.join(', ')||'None',lastSubmissionDate:row.latestSubmission,
                academicUnitKey:row.academicUnitKey,yearLevel:row.yearLevel,
                v14:{stage:row.stage,stageLabel:row.stageLabel,progress:row.progress,labels:stageLabels,
                    resolvedOfficialResubmissionCount:row.resolvedOfficialResubmissionCount||0}
            }));
            filters.update(data.filterOptions);
            overview = data.overview || records.map(r => ({ stage: r.stage, status: r.status, c: 1 }));
            const statusSelect=statusFilter;
            if(statusSelect){let pills=document.getElementById('ierbStatusPills');if(!pills){pills=document.createElement('div');pills.id='ierbStatusPills';document.querySelector('.ierb-controls').after(pills);}pills.classList.toggle('ierb-adviser-tabs',!isAdmin);const counts={};(data.statusCounts||[]).forEach(row=>{counts[row.status]=(counts[row.status]||0)+Number(row.c);});PrismUI.statusPills(pills,[['','All'],...[...statusSelect.options].filter(option=>option.value).map(option=>[option.value,option.textContent])],statusSelect,counts);}
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
        document.dispatchEvent(new CustomEvent('prism:progress-changed'));
    }

    function renderOverview() {
        const stageCount = stage => overview.filter(r => r.stage === stage).reduce((sum, r) => sum + Number(r.c), 0);
        const total = STAGES.reduce((sum, stage) => sum + stageCount(stage), 0);
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
            title.textContent = loadError ? 'Progress overview unavailable' : 'No active research projects yet';
            const description = document.createElement('span');
            description.textContent = loadError || 'Stage distribution appears after approved research groups complete enrollment.';
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

    function renderTable() {
        tableBody.replaceChildren();
        tableBody.closest('table').classList.toggle('is-empty',!records.length);
        if(!records.length){const row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=7;
            cell.innerHTML='<div class="ierb-table-empty"><h3>'+escapeHtml(loadError?'Could not load IERB records':'No active research projects')+'</h3><p>'+escapeHtml(loadError||'Approved research groups appear after enrollment.')+'</p></div>';row.append(cell);tableBody.append(row);return;}
        records.forEach(record=>{
            const row=document.createElement('tr');row.innerHTML='<td colspan="7"></td>';tableBody.append(row);
            const actions=document.createElement('div');
            const guide=document.createElement('button');guide.type='button';guide.title='View stage guidance';guide.dataset.menuGroup='Review';guide.dataset.menuLabel='View stage guidance';
            guide.addEventListener('click',()=>PrismUI.showStageGuidance(record.stage));actions.append(guide);
            buildProgressCard(row,record,record.status==='Delayed'?'is-delayed':record.status==='On Track'?'is-on-track':'is-pending',record.progress,actions);
        });
    }

    const menuIcons = {'View stage guidance':'fa-circle-info'};
    function buildProgressCard(tr, record, statusClass, progress, actions) {
        const stageKey = record.v14 ? record.v14.stage : record.stage;
        const sequence = record.v14 ? Object.keys(record.v14.labels) : STAGES;
        const labelOf = name => record.v14?.labels?.[name] || labelForStage(name);
        const current = sequence.indexOf(stageKey);
        const unit = window.PRISM_ACADEMIC_CATALOG?.units?.[record.academicUnitKey]?.label || record.academicUnitKey;
        const level = [record.yearLevel, record.academicYear].filter(Boolean).join(', ').replace(/(\d{4})-(\d{4})/, '$1–$2');
        const pi = record.isPrincipalInvestigator == null ? 'Not recorded' : (record.isPrincipalInvestigator ? 'Yes' : 'No');
        const field = (label, value) => '<div><span class="ierb-pc-label">' + escapeHtml(label) + '</span><strong>' + escapeHtml(value == null || String(value).trim() === '' ? 'Not recorded' : value) + '</strong></div>';
        const group = (title, rows) => '<section class="ierb-pc-group"><h4>' + title + '</h4>' + rows.map(([l, v]) => field(l, v)).join('') + '</section>';
        const card = document.createElement('article');
        card.className = 'ierb-pc ' + statusClass;
        card.innerHTML = '<div class="ierb-pc-head">'
            + '<div class="ierb-pc-identity"><span class="ierb-pc-avatar" aria-hidden="true">' + escapeHtml(PrismUI.recordInitials(record.name)) + '</span><div><strong>' + escapeHtml(record.name || 'Student name unavailable') + '</strong><small>' + escapeHtml(record.course || 'Program not recorded') + '</small></div></div>'
            + '<div class="ierb-pc-progress"><div class="ierb-pc-stage"><span class="ierb-pc-stage-pill">' + escapeHtml(labelOf(stageKey)) + '</span></div>'
            + '<button type="button" class="ierb-pc-meter" data-requirements aria-expanded="false" aria-label="'+progress+'% progress. View document requirement checklist"><span class="ierb-progress-track" role="progressbar" aria-label="Overall progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + progress + '"><span style="width:' + progress + '%"></span></span><strong>' + progress + '%</strong></button></div>'
            + '<div class="ierb-pc-status">' + PrismUI.badge(record.status) + '</div>'
            + '<div class="ierb-pc-actions"></div></div>'
            + '<details class="ierb-pc-details"><summary>Show details</summary><div class="ierb-pc-body">'
            + '<section class="ierb-pc-group ierb-pc-stages"><h4>IERB stages</h4><ol aria-label="IERB stage sequence">' + sequence.map((name, i) => '<li' + (i === current ? ' aria-current="step"' : '') + ((name==='resubmission_amendment' ? stageKey!=='resubmission_amendment' && Number(record.v14?.resolvedOfficialResubmissionCount||0)>0 : current>=0&&i<current) ? ' class="is-complete"' : '') + '><span>' + (i + 1) + '</span>' + escapeHtml(labelOf(name)) + '</li>').join('') + '</ol></section>'
            + '<div class="ierb-pc-groups">'
            + group('Student', [['Student ID', record.studentId], ['Program', record.course], ['Level', level]])
            + group('Research', [['Title', record.research || 'Not recorded yet'], ['Group', record.groupId || record.group], ['Principal investigator', pi]])
            + group('Progress', [['Last submission', record.lastSubmissionDate || 'No submission yet'], ['Pending requirements', record.requirements || 'Nothing pending'], ['Academic unit', unit]])
            + '</div></div></details>';
        window.PrismRequirementsPopover?.bind(card.querySelector('[data-requirements]'), async () => {
            const response = await fetch('ierb_api.php?action=monitor_requirements&id=' + encodeURIComponent(record.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Requirements unavailable');
            return data.record;
        });
        const warning = tr.querySelector('.ierb-stage-review-warning');
        if (warning) card.querySelector('.ierb-pc-body').prepend(warning);
        const details = card.querySelector('details'), summary = details.querySelector('summary');
        details.addEventListener('toggle', () => { summary.textContent = details.open ? 'Hide details' : 'Show details'; });
        const host = card.querySelector('.ierb-pc-actions');
        let primary = null;
        if (isAdmin) {
            const view = document.createElement('a'); view.href = 'documents.php'; view.className = 'ierb-pc-view'; view.title = 'View documents'; view.setAttribute('aria-label', 'View documents'); view.innerHTML = '<i class="fa-regular fa-eye" aria-hidden="true"></i>'; host.append(view); primary = view;
        }
        const items = [...actions.querySelectorAll('button[data-menu-label]')];
        items.forEach(button => { button.className = ''; button.innerHTML = '<i class="fa-solid ' + menuIcons[button.dataset.menuLabel] + '" aria-hidden="true"></i>'; button.append(document.createTextNode(button.dataset.menuLabel)); button.setAttribute('aria-label', button.dataset.menuLabel); host.append(button); });
        PrismUI.actionMenu(host, { primary, label: 'Student actions' });
        let last = '';
        items.forEach(button => {
            if (button.dataset.menuGroup !== last) {
                last = button.dataset.menuGroup;
                const heading = document.createElement('span'); heading.className = 'ierb-menu-group'; heading.textContent = last; heading.setAttribute('role', 'presentation');
                button.before(heading);
            }
        });
        tr.closest('table')?.classList.add('prism-compact-table');
        const cell = document.createElement('td'); cell.colSpan = tr.cells.length || 1; cell.append(card);
        tr.replaceChildren(cell);
    }

    const searchReload = PrismUI.debounce(() => { pager.reset(); loadRecords(); });
    ierbSearch.addEventListener('input', () => { ++recordRequestSequence; pager.reset(); searchReload(); });
    filterControls.forEach(control=>control.addEventListener('change',()=>{pager.reset();loadRecords();}));

    if (isAdmin && document.getElementById('ierbGroupAdvancement')) {
        const groupModal = document.getElementById('ierbGroupModal');
        const openGroup = document.getElementById('openIerbGroupAdvancement');
        const closeGroup = [...groupModal.querySelectorAll('[data-close-group-advancement]')];
        const select = document.getElementById('ierbGroupSelect');
        const refresh = document.getElementById('ierbGroupRefresh');
        const review = document.getElementById('ierbGroupReview');
        const host = document.getElementById('ierbGroupPreview');
        const chips = document.getElementById('ierbGroupChips');
        const hint = document.getElementById('ierbGroupHint');
        const step3 = document.getElementById('ierbGroupStep3');
        const catalog = window.PRISM_ACADEMIC_CATALOG || {};
        const initials = name => PrismUI.recordInitials(name);
        function chipsHtml(data) {
            const id = data.identity || {};
            const values = [catalog.units?.[id.academic_unit_key]?.label || id.academic_unit_key, catalog.programs?.[id.program_key]?.label || id.program_key, id.year_level, id.academic_year ? 'AY ' + id.academic_year : ''].filter(Boolean);
            return values.map(value => `<span>${escapeHtml(value)}</span>`).join('');
        }
        const check = (ok, title, detail) => `<li class="${ok ? 'is-ok' : 'is-bad'}"><i class="fa-solid ${ok ? 'fa-check' : 'fa-xmark'}" aria-hidden="true"></i><div><strong>${escapeHtml(title)}</strong>${!ok && detail ? `<small>${escapeHtml(detail)}</small>` : ''}</div></li>`;
        function moveHtml(data) {
            const count = data.memberCount, blocked = data.members.filter(m => !m.eligible).length, reasons = data.reasons || [];
            const known = [/At least two/, /different official/, /Review the ineligible/, /standardized/];
            const extra = reasons.filter(r => !known.some(k => k.test(r)));
            const rows = [
                [count >= 2, 'The group has at least 2 students', `Only ${count} student${count === 1 ? ' is' : 's are'} linked to this group. Group Advancement is for groups of 2 or more. Check that the other members were added with this exact group code, or advance the student on their own.`],
                [blocked === 0, 'Every student can be advanced', `${blocked} student${blocked === 1 ? ' needs' : 's need'} attention. See the list below.`],
                [!reasons.some(r => /different official/.test(r)), 'Everyone is at the same stage', 'Group members are at different official stages. Review their records first.'],
                [!reasons.some(r => /standardized/.test(r)), 'The group code is valid', 'Legacy or inconsistent academic identifiers need record review.'],
                ...extra.map(r => [false, 'Stage rule', r])
            ];
            const bad = rows.filter(r => !r[0]).length;
            const summary = data.eligible
                ? '<div class="ierb-ga-result is-ready"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><strong>This group is ready to advance.</strong></div>'
                : `<div class="ierb-ga-result is-blocked"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><strong>This group can't be advanced yet — ${bad} thing${bad === 1 ? '' : 's'} to fix:</strong></div>`;
            return `<div class="ierb-ga-move"><div><span>Now</span><strong>${escapeHtml(data.currentStage || 'Review required')}</strong></div><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><div class="is-next"><span>After advancing</span><strong>${escapeHtml(data.nextStage || '—')}</strong></div><p><strong>${count}</strong> student${count === 1 ? '' : 's'} in this group</p></div>`
                + `<div class="ierb-ga-checks ${data.eligible ? 'is-ready' : 'is-blocked'}">${summary}<ul>${rows.map(r => check(...r)).join('')}</ul></div>`;
        }
        function studentsHtml(data) {
            return '<div class="ierb-ga-students"><h4>Students in this group</h4><ul>' + data.members.map(m => {
                const archived = m.issues.some(i => /archived/i.test(i));
                const note = archived
                    ? 'This student\'s account is archived, so PRISM can\'t change their stage. <a href="admin_archived_accounts.php">Restore in Archived Accounts</a>'
                    : escapeHtml(m.issues.join(' '));
                return `<li><span class="ierb-ga-avatar" aria-hidden="true">${escapeHtml(initials(m.name))}</span><div><strong>${escapeHtml(m.name)}</strong><small>${escapeHtml(m.studentId)} · ${escapeHtml(m.stage)} · ${escapeHtml(m.status)}</small></div>`
                    + `<div class="ierb-ga-member-state">${m.eligible ? '<span class="ierb-ga-pill is-ok">Ready</span>' : `<span class="ierb-ga-pill is-bad">Can't be included</span><p>${note}</p>`}</div></li>`;
            }).join('') + '</ul></div>';
        }
        let preview = null, sequence = 0, busy = false;
        openGroup.addEventListener('click', () => {
            if (busy || groupModal.open) return;
            groupModal.showModal();
            if (!select.disabled) select.focus();
        });
        closeGroup.forEach(button => button.addEventListener('click', () => {
            if (!busy) groupModal.close();
        }));
        groupModal.addEventListener('cancel', event => {
            if (busy) event.preventDefault();
        });
        groupModal.addEventListener('close', () => {
            if (!busy && !groupModal.open) openGroup.focus();
        });
        groupModal.addEventListener('click', event => {
            if (busy || event.target !== groupModal) return;
            const bounds = groupModal.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) groupModal.close();
        });
        const stageText = (key, label) => key ? (label && label !== key ? `${key} — ${label}` : key) : 'Review required';
        const memberHtml = data => '<ul class="ierb-group-members" tabindex="0" aria-label="Associated Students">' + data.members.map(m =>
            `<li><div><strong>${escapeHtml(m.name)}</strong><span>${escapeHtml(m.studentId)}</span></div>` +
            `<div>${escapeHtml(stageText(m.stage, m.stageLabel))}<span>${escapeHtml(m.status)}</span></div>` +
            (m.issues.length ? `<span class="ierb-group-issue">${m.issues.map(escapeHtml).join(' ')}</span>` : '') + '</li>').join('') + '</ul>';
        const factsHtml = data => `<dl class="ierb-group-facts"><div><dt>Current official stage</dt><dd>${escapeHtml(stageText(data.currentStage, data.currentStageLabel))}</dd></div>` +
            `<div><dt>Next official stage</dt><dd>${escapeHtml(stageText(data.nextStage, data.nextStageLabel))}</dd></div><div><dt>Associated Students</dt><dd>${data.memberCount}</dd></div></dl>`;
        function controls() {
            openGroup.disabled = busy;
            closeGroup.forEach(button => { button.disabled = busy; });
            select.disabled = busy;
            refresh.disabled = busy || !select.value;
            review.disabled = busy || !preview?.eligible || !preview?.confirmationToken || Date.now() >= preview.expiresAt * 1000;
            step3.classList.toggle('is-muted', review.disabled);
            hint.textContent = !select.value ? 'Choose a group first.' : (preview?.eligible ? 'This will advance every student listed above.' : 'Fix the items above first.');
        }
        async function cancelToken(data) {
            if (data?.confirmationToken) await PrismUI.postJson('ierb_api.php?action=group_advance_cancel', { confirmationToken: data.confirmationToken });
        }
        async function loadPreview() {
            if (busy) return;
            const requestSequence = ++sequence, old = preview;
            preview = null;
            chips.replaceChildren();
            controls();
            if (!select.value) { host.textContent = 'Select a group to review all associated Student records.'; if (old) cancelToken(old).catch(() => {}); return; }
            host.setAttribute('aria-busy', 'true');
            host.textContent = 'Loading all associated Student records…';
            try {
                if (old) await cancelToken(old).catch(() => {});
                const data = await PrismUI.postJson('ierb_api.php?action=group_advance_preview', { groupKey: select.value });
                if (requestSequence !== sequence) { cancelToken(data).catch(() => {}); return; }
                preview = data;
                chips.innerHTML = chipsHtml(data);
                host.innerHTML = moveHtml(data) + studentsHtml(data);
            } catch (e) {
                if (requestSequence === sequence) host.textContent = e.message;
            } finally {
                if (requestSequence === sequence) { host.setAttribute('aria-busy', 'false'); controls(); }
            }
        }
        select.addEventListener('change', loadPreview);
        refresh.addEventListener('click', loadPreview);
        review.addEventListener('click', async () => {
            if (busy || !preview?.eligible || !preview.confirmationToken) return;
            if (Date.now() >= preview.expiresAt * 1000) { preview = null; host.textContent = 'This confirmation expired. Refresh and review the group.'; controls(); return; }
            busy = true; controls();
            const reviewed = preview;
            try {
                // Let the shared confirmation own focus above the page, then restore this card.
                groupModal.close();
                const answer = await PrismUI.confirm({
                    title: 'Advance Research Group to Next IERB Stage?', confirmText: 'Confirm Group Advancement',
                    dialogClass: 'ierb-group-dialog', message: 'Official progress records for every listed Student will be changed. This decision does not grant institutional ethics approval.',
                    extraHtml: `<p>${escapeHtml(reviewed.label)}</p>` + factsHtml(reviewed) + memberHtml(reviewed),
                    reasonLabel: 'Reason for group advancement', reasonRequired: true, minReason: 5, maxReason: 500,
                    validate: (_values, reason) => /[\p{L}\p{N}]/u.test(reason) ? null : 'Explain this decision using letters or numbers.'
                });
                preview = null;
                if (!answer) {
                    await cancelToken(reviewed);
                    host.textContent = 'Group advancement canceled. Refresh the preview to review this group again.';
                    return;
                }
                groupModal.showModal();
                review.textContent = 'Advancing group…';
                const data = await PrismUI.postJson('ierb_api.php?action=group_advance', {
                    confirmationToken: reviewed.confirmationToken, reason: answer.reason, confirmed: true
                });
                PrismUI.toast(data.message, 'success');
                host.textContent = `${data.memberCount} Students advanced to ${stageText(data.stage, data.stageLabel)}. Refresh the preview before another decision.`;
                await loadRecords();
            } catch (e) {
                preview = null;
                host.textContent = e.message;
                PrismUI.toast(e.message, 'error');
            } finally {
                busy = false; review.textContent = 'Review & Advance Group'; controls();
                if (!groupModal.open) groupModal.showModal();
                refresh.focus();
            }
        });
        PrismUI.request('ierb_api.php?action=group_advance_list').then(data => {
            select.replaceChildren(new Option(data.groups.length ? 'Select a research group' : 'No associated research groups', ''));
            const codeOf = g => String(g.label).split(' / ')[0];
            const codeCounts = data.groups.reduce((m, g) => m.set(codeOf(g), (m.get(codeOf(g)) || 0) + 1), new Map());
            data.groups.forEach(g => select.add(new Option((codeCounts.get(codeOf(g)) > 1 ? g.label : codeOf(g)) + (g.standard ? '' : ' — record review required'), g.key)));
            controls();
        }).catch(e => { select.replaceChildren(new Option('Group list unavailable', '')); host.textContent = e.message; });
    }

    loadRecords().then(()=>{if(isAdmin&&location.hash==='#manage-stages')document.getElementById('manageIerbStages')?.click();});
})();
