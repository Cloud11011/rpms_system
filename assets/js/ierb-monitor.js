(function () {
    'use strict';
    const esc = PrismUI.esc;
    const stamp = value => value ? `${value} (Asia/Manila)` : 'Not recorded';
    const checklist = r => `<p>${esc(r.notes)}</p><p>Initial requirements accepted: ${r.initialAcceptedCount ?? 'Not recorded'}${r.model === 'V14' ? ' / 13' : ''}.</p><ul class="monitor-checklist">${r.requirements.map(x => `<li><strong>${esc(x.label)}</strong><span>${esc(x.state)}${x.versionNo ? ` · version ${x.versionNo}` : ''}${x.mandatory ? '' : ' · Later workflow requirement'}</span></li>`).join('') || '<li>Historical mandatory acceptance evidence is not recorded.</li>'}</ul>`;
    const pending = r => r.pending.length ? r.pending.map(x => `<li>${esc(x)}</li>`).join('') : '<li>None recorded</li>';
    const tone = status => ['Delayed','Correction open'].includes(status) ? 'late' : ['On Track','IERB Completed'].includes(status) ? 'ok' : 'wait';
    const requirementTone = state => /^(Accepted|Formally Submitted to RPMS|Submitted to RPMS)$/.test(state) ? 'ok' : /Missing|Revision|Denied/.test(state) ? 'late' : /Ready/.test(state) ? 'ready' : 'wait';
    const readableDate = value => {
        if (!value) return '<span class="monitor-muted">Not recorded</span>';
        const date = new Date(String(value).replace(' ','T')+'+08:00');
        return Number.isNaN(date.getTime()) ? esc(value) : `<time datetime="${esc(String(value).replace(' ','T'))}+08:00">${esc(date.toLocaleString('en-PH',{year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit',timeZone:'Asia/Manila'}))}</time>`;
    };
    const stageText = value => String(value || '').replace(/initial_submission|resubmission_amendment|approval_data_gathering|ierb_completed/g,key => window.PRISM_V14_LABELS?.[key] || key);
    const eventTitle = value => ({document_submitted_to_rpms:'Document formally submitted',document_resubmitted_to_rpms:'Document formally resubmitted',admin_override_submit_to_rpms:'Submission recorded by Admin override',document_returned_for_revision:'Document returned for revision',initial_requirements_accepted:'Initial requirements accepted',open_correction:'Correction opened',resolve_correction:'Correction resolved',final_report_submitted:'Final Report formally submitted'})[value] || String(value).replace(/_/g,' ').replace(/^./,c=>c.toUpperCase());
    const recordOverview = r => `<header class="monitor-record-heading"><div><span class="monitor-record-kind">${r.model==='V14'?'Current workflow':'Historical record'}</span><h3>${esc(r.research || r.groupId || r.name || 'Research record')}</h3><p>${r.projectId?'Submission holder: ':'Student: '}${esc(r.name)} · ${esc(r.studentId)}</p></div><span class="monitor-status-pill is-${tone(r.status)}">${esc(r.status)}</span></header>
        <div class="monitor-record-meta"><span><i class="fa-solid fa-users" aria-hidden="true"></i> ${esc(r.groupId || 'Group not recorded')}</span><span><i class="fa-regular fa-calendar" aria-hidden="true"></i> ${esc(r.academicYear || 'Academic year not recorded')}</span>${r.research?'':'<span>Research title not recorded</span>'}</div>
        <div class="monitor-overview-grid"><div><span class="monitor-detail-label">Official stage</span><strong>${esc(r.stageLabel)}</strong>${r.correction?`<small>Correction #${r.correction.id} · Amendment ${r.correction.requiresAmendment?'required':'not required'}</small>`:'<small>No active correction case</small>'}</div><div><span class="monitor-detail-label">Official progress</span><strong>${r.progress}%</strong><span class="monitor-progress-track" aria-hidden="true"><span style="width:${r.progress}%"></span></span><small>Stage progress is separate from document approval.</small></div></div>
        ${r.model==='V14'?`<div class="monitor-acceptance-grid"><div><span class="monitor-detail-label">Initial requirements accepted</span><strong>${r.initialAcceptedCount} <small>/ 13</small></strong></div><div><span class="monitor-detail-label">Applicable requirements accepted</span><strong>${r.acceptedCount} <small>/ ${r.mandatoryCount}</small></strong></div><div><span class="monitor-detail-label">Applicable requirements fulfilled</span><strong>${r.fulfilledCount} <small>/ ${r.mandatoryCount} · ${r.requirementCompletionPercent ?? 'Not recorded'}%</small></strong></div></div>`:'<p class="monitor-legacy-note"><i class="fa-regular fa-circle-question" aria-hidden="true"></i> This older record uses historical stages. Requirement acceptance totals were not recorded.</p>'}
        <dl class="monitor-date-grid"><div><dt>First formal submission</dt><dd>${readableDate(r.firstSubmission)}</dd></div><div><dt>Latest formal submission</dt><dd>${readableDate(r.latestSubmission)}</dd></div></dl>`;
    const recordDocuments = r => `<details class="monitor-record-disclosure"><summary><span>Pending requirements</span><span class="monitor-count-pill">${r.pending.length}</span></summary>${r.pending.length?`<ul class="monitor-pending-list">${pending(r)}</ul>`:'<p class="monitor-section-empty">No pending requirements recorded.</p>'}</details>
        <details class="monitor-record-disclosure"><summary><span>Document checklist</span><span class="monitor-count-pill">${r.requirements.length} on record</span></summary><ul class="monitor-document-list">${r.requirements.map(x=>`<li><div><strong>${esc(x.label)}</strong><span class="monitor-status-pill is-${requirementTone(x.state)}">${esc(x.state)}</span></div><small>${x.versionNo?`Version ${esc(x.versionNo)} · `:''}${x.mandatory?'Required for the current workflow':r.model==='V14'?'Later workflow requirement':'Historical document evidence'}</small></li>`).join('') || '<li class="monitor-section-empty">No documents on record yet.</li>'}</ul><details class="monitor-record-notes"><summary>How this record is calculated</summary><p>${esc(r.notes)}</p></details></details>`;
    const cardChecklist = r => `<div class="dpm-checklist-summary"><span>Initial requirements accepted<strong>${r.initialAcceptedCount ?? 'Not recorded'}${r.model === 'V14' ? ' / 13' : ''}</strong></span>${r.model === 'V14' ? `<span class="monitor-progress-track"><span style="width:${Number(r.initialAcceptedCount || 0) / 13 * 100}%"></span></span>` : ''}</div>${r.model === 'Legacy' && r.pending.length ? `<section class="dpm-checklist-pending"><h4>Pending requirements</h4><ul>${pending(r)}</ul></section>` : ''}<ul class="monitor-checklist">${r.requirements.map(x => `<li><div><strong>${esc(x.label)}</strong><span class="dpm-requirement-badge is-${requirementTone(x.state)}">${esc(x.state)}</span></div><small>${x.versionNo ? `Version ${esc(x.versionNo)} · ` : ''}${x.mandatory ? 'Required for the current workflow' : r.model === 'V14' ? 'Later workflow requirement' : 'Historical document evidence'}</small></li>`).join('') || '<li>No documents on record yet.</li>'}</ul><p class="dpm-checklist-note">${esc(r.notes)}</p>`;
    // Hover/focus/click checklist for any progress bar; `load` resolves the monitor record on first use.
    (function () {
        let popover = null, trigger = null, pinned = false, hideTimer;
        const close = () => { clearTimeout(hideTimer); popover?.remove(); popover = null; trigger?.setAttribute('aria-expanded', 'false'); trigger?.removeAttribute('aria-controls'); trigger = null; pinned = false; };
        const place = () => {
            if (!popover || !trigger) return;
            const b = trigger.getBoundingClientRect();
            popover.style.left = `${Math.max(8, Math.min(b.left, innerWidth - popover.offsetWidth - 8))}px`;
            popover.style.top = `${Math.max(8, Math.min(b.bottom + 8, innerHeight - popover.offsetHeight - 8))}px`;
        };
        const delayedClose = () => { clearTimeout(hideTimer); hideTimer = setTimeout(() => { if (!pinned && !popover?.matches(':hover') && !popover?.contains(document.activeElement) && trigger !== document.activeElement) close(); }, 180); };
        window.PrismRequirementsPopover = { bind(button, load) {
            let pending = null;
            const show = async () => {
                clearTimeout(hideTimer);
                if (trigger === button && popover) return;
                close(); trigger = button;
                const el = popover = document.createElement('aside');
                el.className = 'monitor-popover is-dashboard'; el.id = 'monitor-requirement-popover'; el.tabIndex = 0;
                el.setAttribute('aria-label', 'Document requirement checklist'); el.setAttribute('role', 'region');
                el.innerHTML = '<button type="button" class="prism-btn is-secondary" aria-label="Close checklist">Close</button><h3>Document requirements</h3><p data-requirements-body>Loading requirements…</p>';
                el.querySelector('button').onclick = () => { const previous = trigger; close(); previous?.focus(); };
                el.addEventListener('pointerenter', () => clearTimeout(hideTimer)); el.addEventListener('pointerleave', delayedClose); el.addEventListener('focusout', delayedClose);
                document.body.append(el); button.setAttribute('aria-controls', el.id); button.setAttribute('aria-expanded', 'true'); place();
                try {
                    pending = pending || load();
                    const record = await pending;
                    if (popover !== el) return;
                    el.querySelector('[data-requirements-body]').outerHTML = cardChecklist(record);
                } catch (error) {
                    pending = null;
                    if (popover === el) el.querySelector('[data-requirements-body]').textContent = 'Requirements could not be loaded. Try again.';
                }
                place();
            };
            button.addEventListener('pointerenter', e => { if (e.pointerType !== 'touch') show(); }); button.addEventListener('pointerleave', delayedClose);
            button.addEventListener('focus', show); button.addEventListener('blur', delayedClose);
            button.addEventListener('click', () => { if (pinned && trigger === button) close(); else { show(); pinned = true; } });
        } };
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && popover) { const previous = trigger; close(); previous?.focus(); } });
        window.addEventListener('scroll', place, true); window.addEventListener('resize', place);
    })();
    document.querySelectorAll('[data-ierb-monitor]').forEach(host => {
        const find = selector => host.querySelector(selector), rows = find('[data-monitor-rows]'), state = find('[data-monitor-state]');
        const admin = host.dataset.admin === 'true', selected = new Set(), search = find('[data-monitor-search]');
        const cards = host.dataset.monitorLayout === 'cards';
        const sort = find('[data-monitor-sort]'), size = find('[data-monitor-size]'), filterControls = [...host.querySelectorAll('[data-monitor-filter]')];
        let selectionMode = !cards;
        let page = 1, records = [], requestId = 0, total = 0, popover = null, trigger = null, pinned = false, hideTimer;
        function closePopover() {
            clearTimeout(hideTimer); popover?.remove(); popover = null;
            trigger?.setAttribute('aria-expanded', 'false'); trigger?.removeAttribute('aria-controls'); trigger = null; pinned = false;
        }
        function positionPopover() {
            if (!popover || !trigger) return;
            const b = trigger.getBoundingClientRect(), width = popover.offsetWidth, height = popover.offsetHeight;
            popover.style.left = `${Math.max(8, Math.min(b.left, innerWidth - width - 8))}px`;
            popover.style.top = `${Math.max(8, Math.min(b.bottom + 8, innerHeight - height - 8))}px`;
        }
        function delayedClose() { clearTimeout(hideTimer); hideTimer = setTimeout(() => { if (!pinned && !popover?.matches(':hover') && !popover?.contains(document.activeElement) && trigger !== document.activeElement) closePopover(); }, 180); }
        function showPopover(button, r) {
            clearTimeout(hideTimer);
            if (trigger === button && popover) return;
            closePopover(); trigger = button;
            popover = document.createElement('aside'); popover.className = 'monitor-popover' + (cards ? ' is-dashboard' : ''); popover.id = 'monitor-requirement-popover';
            popover.setAttribute('aria-label', 'Document requirement checklist'); popover.setAttribute('role', 'region'); popover.tabIndex = 0;
            popover.innerHTML = `<button type="button" class="prism-btn is-secondary" aria-label="Close checklist">Close</button><h3>Document requirements</h3>${cards ? cardChecklist(r) : checklist(r)}`;
            popover.querySelector('button').onclick = () => { const previous = trigger; closePopover(); previous?.focus(); closePopover(); };
            popover.addEventListener('pointerenter', () => clearTimeout(hideTimer)); popover.addEventListener('pointerleave', delayedClose);
            popover.addEventListener('focusout', delayedClose); document.body.append(popover);
            button.setAttribute('aria-controls', popover.id); button.setAttribute('aria-expanded', 'true'); positionPopover();
        }
        function updateSelection() {
            if (!admin) return;
            find('[data-monitor-selection-count]').textContent = `${selected.size} selected`;
            find('[data-monitor-clear]').disabled = find('[data-monitor-compare]').disabled = selected.size === 0;
            host.querySelectorAll('[data-monitor-placeholder]').forEach(button => { button.disabled = selected.size === 0; });
            if (cards) find('[data-monitor-bulk]').hidden = selected.size === 0;
            const count = records.filter(r => selected.has(r.key)).length, all = find('[data-monitor-page]');
            if (cards) {
                host.classList.toggle('is-selection-mode',selectionMode);
                find('[data-monitor-selection-toggle]').setAttribute('aria-pressed',String(selectionMode));
                find('[data-monitor-selection-toggle]').textContent=selectionMode?'Exit selection mode':'Select records';
                find('[data-monitor-select-page]').hidden=!selectionMode;
            }
            all.checked = records.length > 0 && count === records.length; all.indeterminate = count > 0 && count < records.length; all.disabled = !records.length;
            rows.querySelectorAll('[data-record-key]').forEach(box => {
                if(cards) box.hidden=!selectionMode;
                box.checked = selected.has(box.dataset.recordKey);
                box.closest('[data-monitor-record],tr').classList.toggle('is-selected', box.checked);
            });
        }
        function select(key, value) {
            if (!value) selected.delete(key);
            else if (selected.size < 100) selected.add(key);
            else PrismUI.toast('Selection is limited to 100 records. Clear some records to select more.', 'info');
        }
        function render() {
            closePopover(); rows.replaceChildren();
            if (!records.length) rows.innerHTML = cards ? '<div class="dpm-empty"><strong>No records match</strong><p>Try a different search or clear the filters.</p><button type="button" class="dpm-btn is-small" data-monitor-reset>Clear filters</button></div>' : `<tr><td colspan="${admin ? 8 : 6}">No matching progress records. Try changing the filters.</td></tr>`;
            records.forEach(r => {
                const tr = document.createElement(cards ? 'article' : 'tr');
                if (cards) {
                    tr.className = `dpm-row is-${tone(r.status)}`; tr.dataset.monitorRecord = r.key;
                    tr.innerHTML = `<input type="checkbox" data-record-key="${esc(r.key)}" aria-label="Select ${esc(r.research || r.studentId)}"${selectionMode?'':' hidden'}>
                        <div class="dpm-main"><h3${r.research ? '' : ' class="is-missing"'}>${esc(r.research || 'Title not recorded')}</h3><div class="dpm-meta"><span class="dpm-chip">${esc(r.groupId || 'Group not recorded')}</span><span>${r.projectId ? 'Submission holder: ' : 'Student: '}${esc(r.name)} · ${esc(r.studentId)}</span></div><p class="dpm-meta">Academic year ${esc(r.academicYear || 'not recorded')}</p></div>
                        <div class="dpm-cell"><span class="dpm-label">Official stage</span><span class="dpm-stage">${esc(r.stageLabel)}</span></div>
                        <div class="dpm-cell"><span class="dpm-label">Official progress</span><button type="button" class="monitor-progress" aria-expanded="false" aria-label="${r.progress}% official progress. View document requirement checklist"><strong>${r.progress}% official progress</strong><span class="monitor-progress-track" aria-hidden="true"><span style="width:${r.progress}%"></span></span><span class="dpm-details"><i class="fa-regular fa-circle-question" aria-hidden="true"></i> Requirement details</span></button></div>
                        <div class="dpm-cell"><span class="dpm-label">Pending requirements</span><button type="button" class="dpm-pending ${r.pending.length ? '' : 'is-none'}" data-monitor-requirements aria-expanded="false" aria-label="View ${r.pending.length} pending requirements">${r.pending.length ? `${r.pending.length} pending` : '<i class="fa-solid fa-check" aria-hidden="true"></i> None'} <i class="fa-regular fa-circle-question" aria-hidden="true"></i></button></div>
                        <div class="dpm-cell"><span class="dpm-label">Latest submission</span><span class="dpm-date${r.latestSubmission ? '' : ' is-missing'}">${esc(r.latestSubmission || 'Not recorded')}</span></div>
                        <div class="dpm-cell"><span class="dpm-label">Status</span><span class="dpm-status is-${tone(r.status)}">${esc(r.status)}</span><small>${r.correction ? `Open correction #${r.correction.id}; Amendment ${r.correction.requiresAmendment ? 'required' : 'not required'}` : 'No active correction case'}</small></div>
                        <button type="button" class="dpm-btn is-soft is-small dpm-history" data-history>View history</button>`;
                } else tr.innerHTML = `${admin ? `<td><input type="checkbox" data-record-key="${esc(r.key)}" aria-label="Select ${esc(r.research || r.studentId)}"></td>` : ''}
                    <td><strong>${esc(r.research || 'Title not recorded')}</strong><small>${esc(r.groupId || 'Group not recorded')}</small><small>${r.projectId ? 'Submission holder: ' : 'Student: '}${esc(r.name)} · ${esc(r.studentId)}</small><small>${esc(r.academicYear || 'Academic year not recorded')}</small></td>
                    <td><span>${esc(r.stageLabel)}</span></td>
                    <td><button type="button" class="monitor-progress" aria-expanded="false" aria-label="${r.progress}% official progress. View document requirement checklist"><strong>${r.progress}% official progress</strong><span class="monitor-progress-track" aria-hidden="true"><span style="width:${r.progress}%"></span></span><small>${r.model === 'V14' ? `${r.initialAcceptedCount} / 13 Initial requirements accepted` : 'Historical requirement evidence'} · Details</small></button></td>
                    <td><details><summary>${r.pending.length} pending requirement${r.pending.length === 1 ? '' : 's'}</summary><ul>${pending(r)}</ul></details></td>
                    <td>${esc(stamp(r.latestSubmission))}</td><td>${esc(r.status)}<small>${r.correction ? `Open correction #${r.correction.id}; Amendment ${r.correction.requiresAmendment ? 'required' : 'not required'}` : 'No active correction case'}</small></td>
                    ${admin ? '<td><button type="button" class="prism-btn is-secondary" data-history>View history</button></td>' : ''}`;
                tr.querySelector('[data-record-key]')?.addEventListener('change', e => { select(r.key, e.target.checked); updateSelection(); });
                tr.querySelectorAll('.monitor-progress,[data-monitor-requirements]').forEach(b => {
                b.addEventListener('pointerenter', e => { if (e.pointerType !== 'touch') showPopover(b, r); }); b.addEventListener('pointerleave', delayedClose);
                b.addEventListener('focus', () => showPopover(b, r)); b.addEventListener('blur', delayedClose);
                b.addEventListener('click', () => { if (pinned && trigger === b) closePopover(); else { showPopover(b, r); pinned = true; popover.focus(); } });
                });
                tr.querySelector('[data-history]')?.addEventListener('click', e => openRecords([r.key], true, e.currentTarget)); rows.append(tr);
            });
            updateSelection();
        }
        function renderCardControls(data) {
            const status = find('[data-monitor-filter="status"]'), tabs = find('[data-monitor-status-tabs]');
            const focusStatus = document.activeElement?.dataset.monitorStatus;
            const counts = new Map((data.statusCounts || []).map(r => [String(r.status || '').trim() || '__blank__', Number(r.c)]));
            const definitions = [['','All'],['Pending','Pending'],['On Track','On track'],['Delayed','Delayed']];
            data.filterOptions.status.forEach(o => {
                if (!definitions.some(([key]) => key === o.value) && (counts.has(o.value) || o.value === status.value)) definitions.push([o.value,o.label]);
            });
            tabs.replaceChildren();
            definitions.forEach(([value,label]) => {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'dpm-tab'; button.dataset.monitorStatus = value;
                button.setAttribute('aria-pressed', String(status.value === value));
                const text = document.createElement('span'); text.textContent = label;
                const number = document.createElement('small'); number.textContent = value === '' ? [...counts.values()].reduce((a,b) => a+b,0) : counts.get(value) || 0;
                button.append(text,number); button.onclick = () => { status.value = value; reload(); }; tabs.append(button);
                if (focusStatus === value) button.focus({preventScroll:true});
            });
            const active = filterControls.filter(c => c.dataset.monitorFilter !== 'status' && c.value !== '').length + Number(sort.value !== 'submission:DESC');
            const badge = find('[data-monitor-filter-count]'); badge.textContent = active; badge.hidden = active === 0;
            find('[data-monitor-result-count]').textContent = total ? `Showing ${(page-1)*data.limit+1}–${(page-1)*data.limit+records.length} of ${total} records` : 'No records';
            const pager = find('[data-monitor-pages]'), pages = Math.max(1,Math.ceil(total/data.limit)); pager.replaceChildren();
            const numbers = [...new Set([1,page-1,page,page+1,pages])].filter(n => n>=1 && n<=pages).sort((a,b) => a-b);
            numbers.forEach((n,i) => {
                if (i && n > numbers[i-1]+1) { const dots = document.createElement('span'); dots.textContent = '…'; pager.append(dots); }
                const button = document.createElement('button'); button.type = 'button'; button.textContent = n;
                button.setAttribute('aria-label',`Page ${n}`); if (n === page) button.setAttribute('aria-current','page');
                button.onclick = () => { if (page !== n) { page = n; load(); } }; pager.append(button);
            });
        }
        async function load() {
            const sequence = ++requestId; state.textContent = 'Loading progress records…'; host.setAttribute('aria-busy', 'true');
            const [sortBy, direction] = sort.value.split(':');
            const query = {action: 'monitor', page, perPage: size.value, sortBy, direction, q: search.value.trim()};
            if (cards) query.layout = 'cards';
            filterControls.forEach(c => { query[c.dataset.monitorFilter] = c.value; });
            try {
                const data = await PrismUI.request(`ierb_api.php?${new URLSearchParams(query)}`); if (sequence !== requestId) return;
                records = data.records; page = data.page; total = data.total;
                size.value = String(data.limit);
                filterControls.forEach(c => {
                    const value = c.value, first = c.options[0].cloneNode(true); c.replaceChildren(first);
                    (data.filterOptions[c.dataset.monitorFilter] || []).forEach(o => c.add(new Option(o.label, o.value))); c.value = value;
                });
                state.textContent = cards ? '' : `${total} progress record${total === 1 ? '' : 's'}. Selected records stay selected when filters change.`;
                find('[data-monitor-page-label]').textContent = `Page ${page} of ${Math.max(1, Math.ceil(total / Number(size.value)))}`;
                find('[data-monitor-prev]').disabled = page <= 1; find('[data-monitor-next]').disabled = page * Number(size.value) >= total;
                if (cards) renderCardControls(data);
                render(); document.dispatchEvent(new CustomEvent('prism:monitor-loaded', {detail: data}));
            } catch (e) {
                if (sequence !== requestId) return; records = []; rows.replaceChildren(); closePopover(); updateSelection();
                state.textContent = e.message; find('[data-monitor-prev]').disabled = find('[data-monitor-next]').disabled = true;
            } finally { if (sequence === requestId) host.setAttribute('aria-busy', 'false'); }
        }
        async function openRecords(keys, history, origin) {
            closePopover(); const modal = PrismUI.modal(`<header class="monitor-dialog-header"><div><span class="monitor-dialog-eyebrow">IERB Progress</span><h2 id="monitorDetailTitle">${history?'Research history':'Selected progress records'}</h2><p>${history?'Recorded submissions and workflow changes':'Compare official progress and requirements'} · Asia/Manila</p></div><button type="button" class="prism-btn is-secondary monitor-dialog-close" data-close aria-label="Close ${history?'research history':'selected records'}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header><div class="monitor-dialog-body"><p data-comparison-state role="status" aria-live="polite">Loading ${history?'history':'current records'}…</p><button type="button" class="prism-btn is-secondary" data-monitor-retry hidden>Try again</button><div data-comparison></div></div>`, origin);
            modal.dialog.classList.add('monitor-dialog','monitor-details-dialog'); modal.dialog.classList.toggle('is-history',history); modal.dialog.setAttribute('aria-labelledby','monitorDetailTitle');
            modal.dialog.querySelector('[data-close]').onclick = modal.close; modal.dialog.querySelector('[data-close]').focus();
            let historyPage = 1, detailRequest=0;
            async function refresh() {
                const sequence=++detailRequest;
                const params = new URLSearchParams({action: history ? 'monitor_detail' : 'monitor_compare', page: historyPage}); keys.forEach(key => params.append('keys[]', key));
                const status = modal.dialog.querySelector('[data-comparison-state]');
                const retry = modal.dialog.querySelector('[data-monitor-retry]'); retry.hidden=true;
                status.setAttribute('role','status'); modal.dialog.setAttribute('aria-busy','true');
                try {
                    const data = await PrismUI.request(`ierb_api.php?${params}`);
                    if (!modal.dialog.isConnected || sequence!==detailRequest) return;
                    data.unavailableKeys.forEach(key => selected.delete(key)); updateSelection();
                    status.textContent = data.unavailableKeys.length ? `${data.unavailableKeys.length} unavailable or changed record(s) were removed from selection. Refresh the monitor.` : '';
                    const content = modal.dialog.querySelector('[data-comparison]'); content.replaceChildren(); content.className = 'monitor-comparison';
                    data.records.forEach(r => {
                        const card = document.createElement('article'); card.className='monitor-record'; card.innerHTML = recordOverview(r);
                        if (history) {
                            const timeline = document.createElement('section'); timeline.className='monitor-history';
                            timeline.innerHTML = `<div class="monitor-section-heading"><h4>Submission &amp; workflow history</h4><span class="monitor-count-pill">${r.history.total} event${r.history.total===1?'':'s'}</span></div>${r.history.events.length?`<ol class="monitor-timeline">${r.history.events.map(x=>`<li><span class="monitor-event-icon"><i class="fa-solid ${/return|correction/i.test(x.event_type)?'fa-rotate-left':/complet|final_report/i.test(x.event_type)?'fa-circle-check':'fa-file-arrow-up'}" aria-hidden="true"></i></span><div><div class="monitor-event-date">${readableDate(x.occurred_at)}</div><h5>${esc(eventTitle(x.event_type))}</h5>${x.category?`<p>${esc(stageText(x.category))}</p>`:''}${x.detail && x.detail!=='Official workflow event'?`<small>${esc(x.detail)}</small>`:''}</div></li>`).join('')}</ol>`:'<div class="monitor-history-empty"><i class="fa-regular fa-clock" aria-hidden="true"></i><strong>No history recorded yet</strong><p>Recorded submissions and workflow changes will appear here.</p></div>'}`;
                            if (r.history.total>0) {
                                const nav = document.createElement('nav'); nav.className='monitor-history-pagination'; nav.setAttribute('aria-label', 'Submission history pages');
                                const label=document.createElement('span'); label.textContent=`Page ${r.history.page} of ${Math.max(1,Math.ceil(r.history.total/r.history.limit))}`; nav.append(label);
                                [-1, 1].forEach(delta => { const b = document.createElement('button'); b.type = 'button'; b.className = 'prism-btn is-secondary'; b.textContent = delta < 0 ? 'Newer' : 'Older'; b.disabled = delta < 0 ? r.history.page <= 1 : r.history.page * r.history.limit >= r.history.total; b.onclick = () => { historyPage = r.history.page + delta; status.textContent='Loading history…'; refresh(); }; nav.append(b); });
                                timeline.append(nav);
                            }
                            card.append(timeline);
                        }
                        const documents=document.createElement('div'); documents.className='monitor-record-documents'; documents.innerHTML=recordDocuments(r); card.append(documents);
                        content.append(card);
                    });
                } catch (e) { if(modal.dialog.isConnected && sequence===detailRequest){status.setAttribute('role','alert');status.textContent=e.message;retry.hidden=false;} }
                finally {if(modal.dialog.isConnected && sequence===detailRequest)modal.dialog.setAttribute('aria-busy','false');}
            }
            modal.dialog.querySelector('[data-monitor-retry]').onclick=refresh;
            refresh();
        }
        const reload = () => { page = 1; load(); };
        search.addEventListener('input', PrismUI.debounce(reload, 250)); [sort, size, ...filterControls].forEach(c => c.addEventListener('change', reload));
        find('[data-monitor-prev]').onclick = () => { page--; load(); }; find('[data-monitor-next]').onclick = () => { page++; load(); };
        find('[data-monitor-refresh]')?.addEventListener('click',load);
        find('[data-monitor-page]')?.addEventListener('change', e => { records.forEach(r => select(r.key, e.target.checked)); updateSelection(); });
        find('[data-monitor-clear]')?.addEventListener('click', () => { selected.clear(); updateSelection(); });
        find('[data-monitor-compare]')?.addEventListener('click', e => openRecords([...selected], false, e.currentTarget));
        host.querySelectorAll('[data-monitor-placeholder]').forEach(button => button.addEventListener('click', () => {
            const action = button.dataset.monitorPlaceholder === 'archive' ? 'Archive' : 'Delete';
            PrismUI.toast(`${action} selected is a UI preview only. No records were changed.`, 'info');
        }));
        if (cards) {
            function closeMenus(focus = false) {
                host.querySelectorAll('[data-monitor-menu-toggle]').forEach(button => {
                    const menu = find(`[data-monitor-menu="${button.dataset.monitorMenuToggle}"]`);
                    if (focus && !menu.hidden && menu.contains(document.activeElement)) button.focus();
                    button.setAttribute('aria-expanded','false'); menu.hidden = true;
                });
            }
            host.querySelectorAll('[data-monitor-menu-toggle]').forEach(button => button.addEventListener('click', () => {
                const menu = find(`[data-monitor-menu="${button.dataset.monitorMenuToggle}"]`), open = menu.hidden;
                closeMenus(); menu.hidden = !open; button.setAttribute('aria-expanded',String(open));
            }));
            find('[data-monitor-selection-toggle]')?.addEventListener('click', () => {
                if(selectionMode && selected.size) {
                    const count=selected.size;
                    selected.clear();
                    PrismUI.toast(`Selection cleared for ${count} record${count===1?'':'s'}.`,'info');
                }
                selectionMode=!selectionMode;
                updateSelection();
                closeMenus();
            });
            host.addEventListener('click', e => {
                if (e.target.closest('[data-monitor-menu-close]')) closeMenus(true);
                if (e.target.closest('[data-monitor-reset]')) {
                    search.value = ''; sort.value = 'submission:DESC'; filterControls.forEach(c => { c.value = ''; }); reload();
                }
            });
            document.addEventListener('click', e => { if (!e.target.closest('.dpm-dropdown')) closeMenus(); });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenus(true); });
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && popover) { const previous = trigger, inside = popover.contains(document.activeElement); closePopover(); if (inside) { previous?.focus(); closePopover(); } } });
        document.addEventListener('pointerdown', e => { if (popover && !popover.contains(e.target) && e.target !== trigger && !trigger?.contains(e.target)) closePopover(); });
        addEventListener('resize', positionPopover); addEventListener('scroll', positionPopover, true);
        ['prism:documents-changed', 'prism:progress-changed', 'prism:academic-years-changed'].forEach(event => document.addEventListener(event, load));
        addEventListener('focus', () => { if (!document.hidden) load(); });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
        load();
    });
})();
