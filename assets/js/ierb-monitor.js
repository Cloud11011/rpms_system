(function () {
    'use strict';
    const esc = PrismUI.esc;
    const stamp = value => value ? `${value} (Asia/Manila)` : 'Not recorded';
    const checklist = r => `<p>${esc(r.notes)}</p><p>Initial requirements accepted: ${r.initialAcceptedCount ?? 'Not recorded'}${r.model === 'V14' ? ' / 13' : ''}.</p><ul class="monitor-checklist">${r.requirements.map(x => `<li><strong>${esc(x.label)}</strong><span>${esc(x.state)}${x.versionNo ? ` · version ${x.versionNo}` : ''}${x.mandatory ? '' : ' · Later workflow requirement'}</span></li>`).join('') || '<li>Historical mandatory acceptance evidence is not recorded.</li>'}</ul>`;
    const pending = r => r.pending.length ? r.pending.map(x => `<li>${esc(x)}</li>`).join('') : '<li>None recorded</li>';
    document.querySelectorAll('[data-ierb-monitor]').forEach(host => {
        const find = selector => host.querySelector(selector), rows = find('[data-monitor-rows]'), state = find('[data-monitor-state]');
        const admin = host.dataset.admin === 'true', selected = new Set(), search = find('[data-monitor-search]');
        const sort = find('[data-monitor-sort]'), size = find('[data-monitor-size]'), filterControls = [...host.querySelectorAll('[data-monitor-filter]')];
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
            popover = document.createElement('aside'); popover.className = 'monitor-popover'; popover.id = 'monitor-requirement-popover';
            popover.setAttribute('aria-label', 'Document requirement checklist'); popover.setAttribute('role', 'region'); popover.tabIndex = 0;
            popover.innerHTML = `<button type="button" class="prism-btn is-secondary" aria-label="Close checklist">Close</button><h3>Document requirements</h3>${checklist(r)}`;
            popover.querySelector('button').onclick = () => { const previous = trigger; closePopover(); previous?.focus(); closePopover(); };
            popover.addEventListener('pointerenter', () => clearTimeout(hideTimer)); popover.addEventListener('pointerleave', delayedClose);
            popover.addEventListener('focusout', delayedClose); document.body.append(popover);
            button.setAttribute('aria-controls', popover.id); button.setAttribute('aria-expanded', 'true'); positionPopover();
        }
        function updateSelection() {
            if (!admin) return;
            find('[data-monitor-selection-count]').textContent = `${selected.size} selected`;
            find('[data-monitor-clear]').disabled = find('[data-monitor-compare]').disabled = selected.size === 0;
            const count = records.filter(r => selected.has(r.key)).length, all = find('[data-monitor-page]');
            all.checked = records.length > 0 && count === records.length; all.indeterminate = count > 0 && count < records.length; all.disabled = !records.length;
            rows.querySelectorAll('[data-record-key]').forEach(box => { box.checked = selected.has(box.dataset.recordKey); box.closest('tr').classList.toggle('is-selected', box.checked); });
        }
        function select(key, value) {
            if (!value) selected.delete(key);
            else if (selected.size < 100) selected.add(key);
            else PrismUI.toast('Selection is limited to 100 records. Clear some records to select more.', 'info');
        }
        function render() {
            closePopover(); rows.replaceChildren();
            if (!records.length) rows.innerHTML = `<tr><td colspan="${admin ? 8 : 6}">No matching progress records. Try changing the filters.</td></tr>`;
            records.forEach(r => {
                const tr = document.createElement('tr');
                tr.innerHTML = `${admin ? `<td><input type="checkbox" data-record-key="${esc(r.key)}" aria-label="Select ${esc(r.research || r.studentId)}"></td>` : ''}
                    <td><strong>${esc(r.research || 'Title not recorded')}</strong><small>Protocol Code: ${esc(r.protocolCode || 'Not yet assigned')}</small><small>${r.projectId ? 'Submission holder: ' : 'Student: '}${esc(r.name)} · ${esc(r.studentId)}</small><small>${esc(r.academicYear || 'Academic year not recorded')}</small></td>
                    <td><span>${esc(r.stageLabel)}</span><small>${esc(r.model)}${r.projectId ? ' project' : ' Student record'}</small></td>
                    <td><button type="button" class="monitor-progress" aria-expanded="false" aria-label="${r.progress}% official progress. View document requirement checklist"><strong>${r.progress}% official progress</strong><span class="monitor-progress-track" aria-hidden="true"><span style="width:${r.progress}%"></span></span><small>${r.model === 'V14' ? `${r.initialAcceptedCount} / 13 Initial requirements accepted` : 'Historical requirement evidence'} · Details</small></button></td>
                    <td><details><summary>${r.pending.length} pending requirement${r.pending.length === 1 ? '' : 's'}</summary><ul>${pending(r)}</ul></details></td>
                    <td>${esc(stamp(r.latestSubmission))}</td><td>${esc(r.status)}<small>${r.correction ? `Open correction #${r.correction.id}; Amendment ${r.correction.requiresAmendment ? 'required' : 'not required'}` : 'No active V14 correction case'}</small></td>
                    ${admin ? '<td><button type="button" class="prism-btn is-secondary" data-history>View history</button></td>' : ''}`;
                tr.querySelector('[data-record-key]')?.addEventListener('change', e => { select(r.key, e.target.checked); updateSelection(); });
                const b = tr.querySelector('.monitor-progress');
                b.addEventListener('pointerenter', e => { if (e.pointerType !== 'touch') showPopover(b, r); }); b.addEventListener('pointerleave', delayedClose);
                b.addEventListener('focus', () => showPopover(b, r)); b.addEventListener('blur', delayedClose);
                b.addEventListener('click', () => { if (pinned && trigger === b) closePopover(); else { showPopover(b, r); pinned = true; popover.focus(); } });
                tr.querySelector('[data-history]')?.addEventListener('click', e => openRecords([r.key], true, e.currentTarget)); rows.append(tr);
            });
            updateSelection();
        }
        async function load() {
            const sequence = ++requestId; state.textContent = 'Loading progress records…'; host.setAttribute('aria-busy', 'true');
            const [sortBy, direction] = sort.value.split(':');
            const query = {action: 'monitor', page, perPage: size.value, sortBy, direction, q: search.value.trim()};
            filterControls.forEach(c => { query[c.dataset.monitorFilter] = c.value; });
            try {
                const data = await PrismUI.request(`ierb_api.php?${new URLSearchParams(query)}`); if (sequence !== requestId) return;
                records = data.records; page = data.page; total = data.total;
                filterControls.forEach(c => {
                    const value = c.value, first = c.options[0].cloneNode(true); c.replaceChildren(first);
                    (data.filterOptions[c.dataset.monitorFilter] || []).forEach(o => c.add(new Option(o.label, o.value))); c.value = value;
                });
                state.textContent = `${total} progress record${total === 1 ? '' : 's'}. Selected records stay selected when filters change.`;
                find('[data-monitor-page-label]').textContent = `Page ${page} of ${Math.max(1, Math.ceil(total / Number(size.value)))}`;
                find('[data-monitor-prev]').disabled = page <= 1; find('[data-monitor-next]').disabled = page * Number(size.value) >= total;
                render(); document.dispatchEvent(new CustomEvent('prism:monitor-loaded', {detail: data}));
            } catch (e) {
                if (sequence !== requestId) return; records = []; rows.replaceChildren(); closePopover(); updateSelection();
                state.textContent = e.message; find('[data-monitor-prev]').disabled = find('[data-monitor-next]').disabled = true;
            } finally { if (sequence === requestId) host.setAttribute('aria-busy', 'false'); }
        }
        async function openRecords(keys, history, origin) {
            closePopover(); const modal = PrismUI.modal('<button type="button" class="prism-btn is-secondary monitor-dialog-close" data-close>Close</button><h2>Selected progress records</h2><p data-comparison-state role="status">Loading current records…</p><div data-comparison></div>', origin);
            modal.dialog.classList.add('monitor-dialog'); modal.dialog.setAttribute('aria-label', history ? 'Submission history and requirements' : 'Read-only progress comparison');
            modal.dialog.querySelector('[data-close]').onclick = modal.close; modal.dialog.querySelector('[data-close]').focus();
            let historyPage = 1;
            async function refresh() {
                const params = new URLSearchParams({action: history ? 'monitor_detail' : 'monitor_compare', page: historyPage}); keys.forEach(key => params.append('keys[]', key));
                const status = modal.dialog.querySelector('[data-comparison-state]');
                try {
                    const data = await PrismUI.request(`ierb_api.php?${params}`);
                    if (!modal.dialog.isConnected) return;
                    data.unavailableKeys.forEach(key => selected.delete(key)); updateSelection();
                    status.textContent = data.unavailableKeys.length ? `${data.unavailableKeys.length} unavailable or changed record(s) were removed from selection. Refresh the monitor.` : `${data.records.length} current record(s). Read-only comparison.`;
                    const content = modal.dialog.querySelector('[data-comparison]'); content.replaceChildren(); content.className = 'monitor-comparison';
                    data.records.forEach(r => {
                        const card = document.createElement('article'); card.innerHTML = `<h3>${esc(r.research || 'Title not recorded')}</h3><dl><dt>Protocol Code</dt><dd>${esc(r.protocolCode || 'Not yet assigned')}</dd><dt>Official stage</dt><dd>${esc(r.stageLabel)} (${esc(r.model)})</dd><dt>Official progress</dt><dd>${r.progress}%</dd><dt>Accepted requirements</dt><dd>${r.acceptedCount ?? 'Not recorded'}${r.mandatoryCount != null ? ` / ${r.mandatoryCount} applicable requirements; ${r.initialAcceptedCount} / 13 Initial` : ''}</dd><dt>Mandatory requirement completion</dt><dd>${r.fulfilledCount == null ? 'Not recorded' : `${r.fulfilledCount} / ${r.mandatoryCount} fulfilled (${r.requirementCompletionPercent}%)`}</dd><dt>Initial formal submission</dt><dd>${esc(stamp(r.firstSubmission))}</dd><dt>Latest formal submission</dt><dd>${esc(stamp(r.latestSubmission))}</dd><dt>Active correction</dt><dd>${r.correction ? `Case #${r.correction.id}; Amendment required: ${r.correction.requiresAmendment ? 'Yes' : 'No'}` : 'None recorded'}</dd><dt>Overall status</dt><dd>${esc(r.status)}</dd></dl><h4>Missing / pending requirements</h4><ul>${pending(r)}</ul><details><summary>Document checklist</summary>${checklist(r)}</details>`;
                        if (history) {
                            const timeline = document.createElement('section'); timeline.innerHTML = '<h4>Recorded submission and workflow history</h4><p>No report-row limit applies to this history. Missing dates remain unrecorded.</p>';
                            const list = document.createElement('ol');
                            r.history.events.forEach(x => { const li = document.createElement('li'); li.textContent = `${stamp(x.occurred_at)} · ${x.event_type} · ${x.category || ''} · ${x.detail}`; list.append(li); });
                            if (!r.history.events.length) list.textContent = 'No authoritative submission or workflow events recorded.';
                            timeline.append(list);
                            const nav = document.createElement('nav'); nav.setAttribute('aria-label', 'Submission history pages');
                            [-1, 1].forEach(delta => { const b = document.createElement('button'); b.type = 'button'; b.className = 'prism-btn is-secondary'; b.textContent = delta < 0 ? 'Newer history' : 'Older history'; b.disabled = delta < 0 ? r.history.page <= 1 : r.history.page * r.history.limit >= r.history.total; b.onclick = () => { historyPage = r.history.page + delta; refresh(); }; nav.append(b); });
                            timeline.append(nav); card.append(timeline);
                        }
                        content.append(card);
                    });
                } catch (e) { status.textContent = e.message; }
            }
            refresh();
        }
        const reload = () => { page = 1; load(); };
        search.addEventListener('input', PrismUI.debounce(reload, 250)); [sort, size, ...filterControls].forEach(c => c.addEventListener('change', reload));
        find('[data-monitor-prev]').onclick = () => { page--; load(); }; find('[data-monitor-next]').onclick = () => { page++; load(); };
        find('[data-monitor-refresh]').onclick = load;
        find('[data-monitor-page]')?.addEventListener('change', e => { records.forEach(r => select(r.key, e.target.checked)); updateSelection(); });
        find('[data-monitor-clear]')?.addEventListener('click', () => { selected.clear(); updateSelection(); });
        find('[data-monitor-compare]')?.addEventListener('click', e => openRecords([...selected], false, e.currentTarget));
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && popover) { const previous = trigger, inside = popover.contains(document.activeElement); closePopover(); if (inside) { previous?.focus(); closePopover(); } } });
        document.addEventListener('pointerdown', e => { if (popover && !popover.contains(e.target) && e.target !== trigger && !trigger?.contains(e.target)) closePopover(); });
        addEventListener('resize', positionPopover); addEventListener('scroll', positionPopover, true);
        ['prism:documents-changed', 'prism:progress-changed', 'prism:academic-years-changed'].forEach(event => document.addEventListener(event, load));
        addEventListener('focus', () => { if (!document.hidden) load(); });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
        load();
    });
})();
