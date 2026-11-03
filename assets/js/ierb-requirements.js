/* PRISM — Research project requirements (V14 checklist).
 *
 * Public API (same names as before, so role-portal.js and documents.js keep working):
 *   PrismRequirements.load(studentId, host)   → renders the checklist into `host`, returns the API data
 *   PrismRequirements.open(studentId, opts)   → opens the checklist in a dialog
 *   PrismRequirements.choose(opts)            → opens the "whose checklist?" picker first
 *       opts.loadStudents  async () => [{ id, name, studentId, protocolCode }]
 *       opts.onShowDocuments(studentId)        optional: "See their uploaded documents" on the legacy screen
 *
 * Every state and decision still comes from the server:
 *   documents_api.php?action=requirements | accept_requirement
 *   ierb_api.php?action=v14_correction_open | v14_correction_resolve
 */
(() => {
  'use strict';

  const api = (action, options = {}) => PrismUI.request('documents_api.php?action=' + action, options);
  const postJson = (url, body) => PrismUI.request(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

  const STAGES = [
    { key: 'initial_submission', n: 1, title: 'Initial Submission', sub: 'Everything the IERB needs to start the review' },
    { key: 'resubmission_amendment', n: 2, title: 'Resubmission / Amendment', sub: 'Only if RPMS opens an official resubmission' },
    { key: 'approval_data_gathering', n: 3, title: 'Approval of Data Gathering', sub: 'After the IERB approves the study' },
    { key: 'ierb_completed', n: 4, title: 'IERB Completed', sub: 'Sending the Final Report to RPMS finishes the project' }
  ];
  const STAGE_INDEX = Object.fromEntries(STAGES.map((s, i) => [s.key, i]));

  // Server state → plain-language status shown to people.
  const STATUS = {
    'Missing': { label: 'Missing', tone: 'missing' },
    'Uploaded / Pending Review': { label: 'With adviser', tone: 'adviser', note: 'Uploaded · waiting for adviser approval' },
    'Uploaded / Pending Adviser Review': { label: 'With adviser', tone: 'adviser', note: 'Uploaded · waiting for adviser approval' },
    'Formally Submitted to RPMS': { label: 'Sent to RPMS', tone: 'rpms', note: 'Adviser approved · RPMS can accept it' },
    'Returned for Revision': { label: 'Returned', tone: 'returned', note: 'Needs a new version from the group' },
    'Accepted': { label: 'Accepted', tone: 'accepted' }
  };
  const statusOf = row => STATUS[row.state] || { label: row.state, tone: 'missing' };
  const FILTERS = ['All', 'Missing', 'With adviser', 'Sent to RPMS', 'Returned', 'Accepted'];

  /* Official resubmission counter ("0 of 3 used").
   * The pushed V14 API does not send this yet. If your newer API does, map its field here. */
  function resubmissionUsage(data) {
    const u = data.officialResubmissions || data.workflow?.officialResubmissions || (data.workflow && {used:data.workflow.officialResubmissionCount,limit:data.workflow.officialResubmissionLimit});
    return u && Number.isFinite(Number(u.used)) && Number.isFinite(Number(u.limit)) ? { used: Number(u.used), limit: Number(u.limit) } : null;
  }

  // ------------------------------------------------------------------ tiny DOM helper (textContent only; no HTML injection)
  function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(props)) {
      if (value === null || value === undefined || value === false) continue;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
      else if (key === 'checked' || key === 'disabled' || key === 'value' || key === 'hidden') node[key] = value;
      else node.setAttribute(key, value === true ? '' : value);
    }
    children.flat().forEach(c => { if (c !== null && c !== undefined && c !== false) node.append(c); });
    return node;
  }
  const icon = name => el('i', { class: 'fa-solid ' + name, 'aria-hidden': 'true' });
  const alertBox = message => el('p', { class: 'prq-alert', role: 'alert', text: message });

  // PrismUI.confirm uses an overlay; inside a modal <dialog> (top layer) it must be moved into the dialog.
  function ask(host, options) {
    const pending = PrismUI.confirm(options);
    const dialog = host.closest('dialog'), overlay = document.querySelector('.prism-overlay');
    if (dialog && overlay) dialog.append(overlay);
    return pending;
  }

  // ------------------------------------------------------------------ main renderer
  async function load(studentId, host, ui = {}) {
    if (!host) return;
    const portal = host.id === 'v14RequirementChecklist';
    host.hidden = false;
    host.classList.add('prq');
    host.setAttribute('aria-busy', 'true');
    host.replaceChildren(el('p', { class: 'prq-muted', text: 'Loading project requirements…' }));
    let data;
    try {
      data = await api('requirements&studentId=' + encodeURIComponent(studentId));
    } catch (error) {
      host.replaceChildren(alertBox('Requirements unavailable: ' + error.message));
      host.setAttribute('aria-busy', 'false');
      return;
    }
    host.setAttribute('aria-busy', 'false');
    host.replaceChildren();
    host.hidden = !data.enrolled;
    if (!data.enrolled) return data;

    const view = { filter: 'All', open: new Set(), selected: new Set() };
    const refresh = () => load(studentId, host, ui);
    const currentKey = data.workflow.stage;
    const contextKey = data.workflow.correctionCase?.interruptedStage || currentKey;
    view.open.add(currentKey);

    const rows = portal ? portalRows(data) : data.requirements;
    const currentRows = portalRows(data).filter(r => currentKey === 'resubmission_amendment' || r.stage === contextKey);
    const legacy = Boolean(data.legacy);
    const accepted = currentRows.filter(r => r.state === 'Accepted' || ((legacy || r.key === 'final_report') && r.state === 'Formally Submitted to RPMS')).length;

    // ---- header
    const scope = data.submissionScope || {};
    const stageMeta = STAGES[STAGE_INDEX[currentKey]] || { n: '', title: data.workflow.stageLabel };
    const facts = [];
    if (scope.projectId) facts.push('Principal investigator: ' + (scope.principalInvestigator ? scope.principalInvestigator.name + ' (set by RPMS)' : 'not set by RPMS yet'));
    const head = el('div', { class: 'prq-head' },
      el('div', { class: 'prq-titles' },
        el('span', { class: 'prq-eyebrow', text: portal ? 'Your checklist' : 'Project requirements' }),
        el(portal ? 'h2' : 'h2', { class: 'prq-title', id: 'prqTitle', text: scope.researchTitle || 'Research project' }),
        facts.length ? el('span', { class: 'prq-muted', text: facts.join(' · ') }) : null),
      el('div', { class: 'prq-head-actions' },
        ui.onChangeStudent ? el('button', { type: 'button', class: 'prq-btn', text: 'Change student', onclick: ui.onChangeStudent }) : null,
        el('button', { type: 'button', class: 'prq-icon-btn', 'aria-label': 'Refresh requirements', title: 'Refresh', onclick: refresh }, icon('fa-rotate-right')),
        ui.onClose ? el('button', { type: 'button', class: 'prq-icon-btn is-round', 'aria-label': 'Close', onclick: ui.onClose }, icon('fa-xmark')) : null));

    const progress = el('div', { class: 'prq-summary' },
      el('div', { class: 'prq-stage-now' },
        el('span', { class: 'prq-muted prq-small', text: 'Current stage' }),
        el('span', {}, el('span', { class: 'prq-stage-pill', text: stageMeta.n ? 'Stage ' + stageMeta.n : 'Stage' }), ' ', el('strong', { text: data.workflow.stageLabel }))),
      currentKey === 'ierb_completed'
        ? el('div', { class: 'prq-bar-wrap' }, el('strong', { text: 'Project completed. Its documents are read-only.' }))
        : el('div', { class: 'prq-bar-wrap' },
          el('div', { class: 'prq-bar-text' }, el('strong', { text: `${accepted} of ${currentRows.length} ${legacy ? 'sent to RPMS' : 'accepted by RPMS'}` }), el('span', { class: 'prq-muted', text: legacy ? 'for this stage' : 'needed to move on' })),
          el('div', { class: 'prq-bar', role: 'progressbar', 'aria-label': 'Requirements accepted for this stage', 'aria-valuemin': '0', 'aria-valuemax': String(currentRows.length), 'aria-valuenow': String(accepted) },
            el('span', { style: `width:${currentRows.length ? Math.round(accepted / currentRows.length * 100) : 0}%` }))));

    const chipsHost = el('div', { class: 'prq-chips', role: 'group', 'aria-label': 'Filter documents by status' });
    const how = el('details', { class: 'prq-how' },
      el('summary', { text: 'How this checklist works' }),
      el('ul', {},
        el('li', { text: scope.uploadPolicy || 'Any verified group member can upload, revise and send documents once their adviser approves them.' }),
        el('li', { text: 'Each document only has to be accepted once for the whole project.' }),
        el('li', { text: 'When everything for a stage is accepted, RPMS moves the group to the next stage.' }),
        el('li', { text: 'Sending the Final Report to RPMS finishes the project automatically.' })));

    const top = el('div', { class: 'prq-top' }, head, progress, chipsHost, how);
    const notices = el('div', { class: 'prq-notices' });
    const stagesHost = el('div', { class: 'prq-stages' });
    const bulkHost = el('div');
    host.append(top, notices, stagesHost, bulkHost);

    if (data.workflow.correctionCase) {
      notices.append(el('p', { class: 'prq-note is-purple', role: 'status' }, icon('fa-rotate'),
        el('span', { text: 'Official resubmission is open. Amendment Form ' + (data.workflow.correctionCase.requiresAmendment ? 'is required.' : 'is not required.') + ' The group returns to its earlier stage once the fixes are accepted.' })));
    }


    // ---- stages + rows
    // Missing documents for a later stage (or an unopened resubmission) show as "Later", not "Missing".
    const ci0 = STAGE_INDEX[currentKey] ?? 0;
    const isLater = row => row.state === 'Missing' && row.stage !== currentKey
      && ((STAGE_INDEX[row.stage] ?? 0) > ci0 || row.stage === 'resubmission_amendment');
    const shown = row => isLater(row) ? { label: 'Later', tone: 'later' } : statusOf(row);

    function renderChips() {
      chipsHost.replaceChildren(...FILTERS.map(f => {
        const count = f === 'All' ? rows.length : rows.filter(r => shown(r).label === f).length;
        if (f !== 'All' && !count) return null;
        const on = view.filter === f;
        return el('button', { type: 'button', class: 'prq-chip' + (on ? ' is-on' : ''), 'aria-pressed': on ? 'true' : 'false',
          onclick: () => { view.filter = f; renderChips(); renderStages(); } },
          el('span', { class: 'prq-dot is-' + (f === 'All' ? 'all' : (STATUS[Object.keys(STATUS).find(k => STATUS[k].label === f)]?.tone || 'missing')) }),
          f + ' ', el('span', { class: 'prq-count', text: String(count) }));
      }).filter(Boolean));
    }

    function renderStages() {
      stagesHost.replaceChildren();
      const ci = STAGE_INDEX[currentKey] ?? 0;
      STAGES.forEach((stage, i) => {
        let stageRows = rows.filter(r => r.stage === stage.key);
        if (view.filter !== 'All') stageRows = stageRows.filter(r => shown(r).label === view.filter);
        if (!stageRows.length && (portal || view.filter !== 'All')) return;
        const isCurrent = stage.key === currentKey;
        const done = !isCurrent && (stage.key === 'resubmission_amendment'
          ? Number(data.workflow.resolvedOfficialResubmissionCount || 0) > 0 : i < ci);
        const tag = isCurrent ? ['Current stage', 'is-current']
          : done ? ['Done', 'is-done']
          : stage.key === 'resubmission_amendment' ? ['Only if needed', ''] : ['Later', ''];
        const open = view.open.has(stage.key);
        const panelId = 'prqStage' + stage.n;
        const toggle = el('button', { type: 'button', class: 'prq-stage-head', 'aria-expanded': open ? 'true' : 'false', 'aria-controls': panelId,
          onclick: () => { open ? view.open.delete(stage.key) : view.open.add(stage.key); renderStages(); } },
          el('span', { class: 'prq-num' + (isCurrent ? ' is-current' : done ? ' is-done' : ''), text: done ? '✓' : String(stage.n) }),
          el('span', { class: 'prq-stage-text' }, el('span', { class: 'prq-stage-title', text: stage.title }), el('span', { class: 'prq-muted prq-small', text: stage.sub })),
          el('span', { class: 'prq-tag ' + tag[1], text: tag[0] }),
          icon(open ? 'fa-chevron-up' : 'fa-chevron-down'));
        const list = el('ul', { class: 'prq-rows', id: panelId, hidden: !open });
        if (!stageRows.length) list.append(el('li', { class: 'prq-row' }, el('span', { class: 'prq-muted', text: stage.key === 'resubmission_amendment' ? 'Nothing requested. RPMS lists documents here only when it opens a resubmission.' : 'Nothing listed for this stage yet.' })));
        stageRows.forEach(row => list.append(renderRow(row, isLater(row))));
        stagesHost.append(el('section', { class: 'prq-stage' + (isCurrent ? ' is-current' : '') }, toggle, list));
      });
      if (!stagesHost.childElementCount) stagesHost.append(el('p', { class: 'prq-muted', text: 'No documents with this status.' }));
    }

    function renderRow(row, later) {
      const st = later ? { label: 'Later', tone: 'later' } : statusOf(row);
      const notes = [];
      if (!later && st.note) notes.push(st.note);
      if (row.versionNo) notes.push('Version ' + row.versionNo);
      if (row.key === 'final_report' && later) notes.push('Unlocks after RPMS accepts Form 33');
      if (row.acceptanceEventId) notes.push(`Accepted ${row.acceptedAt} · record #${row.acceptanceEventId}`);
      const bulkable = !portal && data.viewerRole === 'admin' && row.canAccept && row.state === 'Formally Submitted to RPMS' && row.key !== 'form33' && row.key !== 'final_report';
      const li = el('li', { class: 'prq-row' + (view.selected.has(row.key) ? ' is-selected' : '') });
      if (bulkable) {
        li.append(el('input', { type: 'checkbox', class: 'prq-check', checked: view.selected.has(row.key), 'aria-label': 'Select ' + row.label + ' to accept',
          onchange: e => { e.target.checked ? view.selected.add(row.key) : view.selected.delete(row.key); li.classList.toggle('is-selected', e.target.checked); renderBulk(); } }));
      }
      li.append(el('span', { class: 'prq-row-text' }, el('span', { class: 'prq-row-name', text: row.label }), notes.length ? el('span', { class: 'prq-muted prq-small', text: notes.join(' · ') }) : null),
        el('span', { class: 'prq-pill is-' + st.tone }, el('span', { class: 'prq-dot' }), st.label));
      const actions = el('span', { class: 'prq-row-actions' });
      const allowed = new Set(data.uploadTypes || []);
      if (portal && row.state === 'Missing' && allowed.has(row.label)) {
        actions.append(el('button', { type: 'button', class: 'prq-btn is-soft', text: 'Upload', 'aria-label': 'Upload ' + row.label,
          onclick: () => document.dispatchEvent(new CustomEvent('prism:upload-requirement', { detail: { documentType: row.label } })) }));
      }
      if (portal && row.state === 'Returned for Revision' && row.documentId && allowed.has(row.label)) {
        actions.append(el('button', { type: 'button', class: 'prq-btn is-soft', text: 'Upload revision', 'aria-label': 'Upload a revision of ' + row.label,
          onclick: () => document.dispatchEvent(new CustomEvent('prism:upload-revision', { detail: { id: row.documentId, originalName: row.label,
            documentType: row.documentType || row.label, versionNo: row.versionNo,
            stage: row.stage === 'initial_submission' ? 'Stage 1' : row.stage === 'resubmission_amendment' ? 'Stage 2' : 'Stage 3', actions: { uploadRevision: true } } })) }));
      }
      if (!portal && row.canAccept && !bulkable) actions.append(acceptButton(row));
      if (actions.childElementCount) li.append(actions);
      return li;
    }

    function acceptButton(row) {
      const button = el('button', { type: 'button', class: 'prq-btn is-soft', text: 'Accept' });
      button.addEventListener('click', async () => {
        const answer = await ask(host, { title: 'Accept RPMS requirement', confirmText: 'Accept current version', reasonLabel: 'RPMS acceptance reason', reasonRequired: true, minReason: 5,
          message: `Accept ${row.label} version ${row.versionNo} for this project?`,
          ...(row.key === 'form33' ? { extraHtml: '<label for="institutionalApprovalReference">Institutional approval reference</label><textarea id="institutionalApprovalReference" required minlength="5" maxlength="1000" placeholder="Enter the institutional approval reference"></textarea>',
            collect: dialog => ({ reference: dialog.querySelector('#institutionalApprovalReference').value }),
            validate: values => values.reference.trim().length < 5 ? 'Provide the institutional approval reference.' : null } : {}) });
        if (!answer) return;
        const release = PrismUI.busy(button, 'Accepting…'); if (!release) return;
        try {
          await api('accept_requirement', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
            studentId: Number(scope.studentId), requirementKey: row.key, documentId: row.documentId, revision: data.workflow.revision,
            reason: answer.reason, requestKey: crypto.randomUUID(), institutionalApprovalReference: answer.values?.reference || '' }) });
          PrismUI.toast(row.label + ' accepted.', 'success');
          await refresh();
        } catch (error) { notices.append(alertBox(error.message)); }
        finally { release(); }
      });
      return button;
    }

    // ---- bulk accept bar (admin)
    // V14 accepts the selected current versions in one atomic, idempotent transaction.
    function renderBulk() {
      bulkHost.replaceChildren();
      const n = view.selected.size;
      if (!n) return;
      const reason = el('textarea', { id: 'prqBulkReason', rows: '2', maxlength: '500', placeholder: 'e.g. Checked against the IERB checklist' });
      const accept = el('button', { type: 'button', class: 'prq-btn is-primary', text: `Accept ${n} ${n === 1 ? 'document' : 'documents'}`, disabled: true });
      reason.addEventListener('input', () => { accept.disabled = reason.value.trim().length < 5; });
      accept.addEventListener('click', async () => {
        const items = data.requirements.filter(r => view.selected.has(r.key)).map(r => ({ requirementKey: r.key, documentId: r.documentId, label: r.label }));
        const release = PrismUI.busy(accept, 'Accepting…'); if (!release) return;
        let done = 0;
        try {
          await api('accept_requirements_bulk', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
            studentId:Number(scope.studentId), items:items.map(({requirementKey,documentId})=>({requirementKey,documentId})),
            revision:data.workflow.revision, reason:reason.value.trim(), requestKey:crypto.randomUUID() }) });
          done=items.length;
          PrismUI.toast(`${done} ${done === 1 ? 'requirement' : 'requirements'} accepted.`, 'success');
          await refresh();
        } catch (error) {
          bulkHost.append(alertBox((done ? `${done} accepted before the error. ` : '') + error.message));
          release();
          if (done) setTimeout(refresh, 2500);
        }
      });
      bulkHost.append(el('div', { class: 'prq-bulk', role: 'region', 'aria-label': 'Accept selected documents' },
        el('div', { class: 'prq-bulk-row' }, el('strong', { text: `${n} selected to accept` }),
          el('button', { type: 'button', class: 'prq-link is-light', text: 'Clear', onclick: () => { view.selected.clear(); renderStages(); renderBulk(); } })),
        el('label', { for: 'prqBulkReason', text: 'Reason for accepting (saved in the history)' }), reason,
        el('div', { class: 'prq-bulk-row is-end' }, accept)));
    }

    renderChips();
    renderStages();
    if (!portal) resubmissionBox(data, studentId, host, refresh);
    return data;
  }

  // Student portal shows only what matters for the current stage.
  function portalRows(data) {
    const current = data.workflow.stage, allowed = new Set(data.uploadTypes || []);
    return data.requirements.filter(row => {
      if (current === 'initial_submission') return row.stage === 'initial_submission';
      if (current === 'resubmission_amendment') return allowed.has(row.label) || allowed.has(row.documentType) || (row.key === 'amendment' && data.workflow.correctionCase?.requiresAmendment);
      if (current === 'approval_data_gathering') return ['form33', 'final_report'].includes(row.key);
      return true;
    });
  }

  // ------------------------------------------------------------------ official resubmission (correction cases)
  const docChoices = data => data.requirements.filter(row => row.documentId && row.key !== 'final_report')
    .map(row => `<label class="v14-doc-choice"><input type="checkbox" name="affected" value="${PrismUI.esc(row.documentId)}"> ${PrismUI.esc(row.label)} (v${row.versionNo})</label>`).join('');
  const amendmentChoice = '<fieldset class="v14-amendment-choice"><legend>Amendment Form for this resubmission <span class="required-marker" aria-hidden="true">*</span></legend>'
    + '<label><input type="radio" name="amendment" value="1" required> Required</label><label><input type="radio" name="amendment" value="0"> Not required</label></fieldset>';
  const pickDocs = dialog => [...dialog.querySelectorAll('input[name="affected"]:checked')].map(i => i.value);
  const pickAmendment = dialog => dialog.querySelector('input[name="amendment"]:checked')?.value;

  function resubmissionBox(data, studentId, host, refresh) {
    const role = data.viewerRole, open = data.workflow.correctionCase;
    const base = { studentId: Number(data.submissionScope.studentId), revision: data.workflow.revision };
    const usage = resubmissionUsage(data);
    const canOpen = (!usage || usage.used < usage.limit) && !open && ['initial_submission', 'approval_data_gathering'].includes(data.workflow.stage);
    const actions = el('div', { class: 'prq-res-actions', role: 'group', 'aria-label': 'Official resubmission actions' });
    const errors = el('div');
    const add = (label, tone, handler) => {
      const b = el('button', { type: 'button', class: 'prq-btn ' + (tone === 'primary' ? 'is-primary' : 'is-soft'), text: label });
      b.addEventListener('click', () => handler(b)); actions.append(b);
    };
    const run = async (button, action, body) => {
      const release = PrismUI.busy(button, 'Saving…'); if (!release) return;
      try { await postJson('ierb_api.php?action=' + action, { ...body, requestKey: crypto.randomUUID() }); await refresh(); }
      catch (error) { errors.replaceChildren(alertBox(error.message)); release(); }
    };

    if (data.operationsActivated && ['adviser', 'admin'].includes(role)) {
      if (role === 'admin' && canOpen) add('Open official resubmission', 'soft', async b => {
        const a = await ask(host, { title: 'Open an official resubmission', confirmText: 'Open resubmission', reasonLabel: 'RPMS reason', reasonRequired: true, minReason: 5, maxReason: 1000,
          message: 'The whole project moves to Stage 2 until the corrected documents are accepted.',
          extraHtml: `<fieldset><legend>Documents to correct <span class="required-marker" aria-hidden="true">*</span></legend>${docChoices(data)}</fieldset>${amendmentChoice}`,
          collect: d => ({ documentIds: pickDocs(d), amendment: pickAmendment(d) }),
          validate: v => !v.documentIds.length ? 'Select at least one document to correct.' : (v.amendment ? null : 'Decide whether an Amendment Form is required.') });
        if (a) await run(b, 'v14_correction_open', { ...base, documentIds: a.values.documentIds, requiresAmendment: a.values.amendment === '1', reason: a.reason });
      });
      if (role === 'admin' && open) add('Close resubmission', 'primary', async b => {
        const a = await ask(host, { title: 'Close the official resubmission', confirmText: 'Close and resume review', reasonLabel: 'RPMS reason', reasonRequired: true, minReason: 5, maxReason: 1000,
          message: 'The corrected versions (and the Amendment Form, if required) must already be accepted. The project returns to the stage it was in before.' });
        if (a) await run(b, 'v14_correction_resolve', { ...base, reason: a.reason });
      });
    }

    if (!actions.childElementCount && !usage && !open) return;
    host.append(el('section', { class: 'prq-res', 'aria-labelledby': 'prqResTitle' },
      el('div', { class: 'prq-res-text' },
        el('strong', { id: 'prqResTitle', text: usage ? `Official resubmissions: ${usage.used} of ${usage.limit} used` : 'Official resubmission' }),
        el('span', { class: 'prq-muted', text: open
          ? 'A resubmission is open now. The group is in Stage 2 until RPMS accepts the corrected documents.'
          : 'Use one when RPMS needs the group to correct documents it already reviewed. The group moves to Stage 2 until the fixes are accepted. Normal adviser revisions don’t count.' })),
      actions, errors));
  }

  // ------------------------------------------------------------------ dialog shell: picker → legacy / checklist
  function shell() {
    const dialog = el('dialog', { class: 'prq-dialog', 'aria-labelledby': 'prqTitle' });
    const body = el('div', { class: 'prq-dialog-body' });
    dialog.append(body);
    document.body.append(dialog);
    dialog.addEventListener('close', () => dialog.remove());
    dialog.showModal();
    return { dialog, body, close: () => dialog.close() };
  }

  function closeButton(close) {
    return el('button', { type: 'button', class: 'prq-icon-btn is-round', 'aria-label': 'Close', onclick: close }, icon('fa-xmark'));
  }

  async function showFor(ctx, studentId, opts) {
    ctx.dialog.classList.remove('is-small');
    const host = el('section', { class: 'prq' });
    ctx.body.replaceChildren(host);
    const data = await load(studentId, host, { onClose: ctx.close, onChangeStudent: opts.loadStudents ? () => showPicker(ctx, opts) : null });
    if (data && !data.enrolled) showPending(ctx, studentId, opts);
  }

  function showPending(ctx, studentId, opts) {
    ctx.dialog.classList.add('is-small');
    const name = opts.students?.find(s => String(s.id) === String(studentId));
    ctx.body.replaceChildren(el('div', { class: 'prq prq-small-panel' },
      el('div', { class: 'prq-head' },
        el('div', { class: 'prq-titles' },
          el('span', { class: 'prq-eyebrow', text: 'Project requirements' }),
          el('h2', { class: 'prq-title is-sm', id: 'prqTitle', text: 'No checklist for this student yet' }),
          name ? el('span', { class: 'prq-muted', text: `${name.name}${name.studentId ? ' · ' + name.studentId : ''}` }) : null),
        closeButton(ctx.close)),
      el('div', { class: 'prq-explain' }, icon('fa-circle-info'),
        el('div', {},
          el('p', {}, 'Research setup is pending. The V14 checklist becomes available after RPMS confirms its research assignment.'),
          el('p', {}, 'An ', el('strong', { text: 'RPMS administrator' }), ' must verify the project membership and submission holder.'))),
      el('div', { class: 'prq-actions-end' },
        opts.loadStudents ? el('button', { type: 'button', class: 'prq-btn', text: 'Choose another student', onclick: () => showPicker(ctx, opts) }) : null,
        opts.onShowDocuments ? el('button', { type: 'button', class: 'prq-btn is-soft', text: 'See their uploaded documents', onclick: () => { ctx.close(); opts.onShowDocuments(studentId); } }) : null,
        !opts.loadStudents && !opts.onShowDocuments ? el('button', { type: 'button', class: 'prq-btn', text: 'Close', onclick: ctx.close }) : null)));
  }

  async function showPicker(ctx, opts) {
    ctx.dialog.classList.add('is-small');
    const select = el('select', { id: 'prqHolder', class: 'prq-select' }, el('option', { value: '', text: 'Loading students…' }));
    select.disabled = true;
    ctx.body.replaceChildren(el('div', { class: 'prq prq-small-panel' },
      el('div', { class: 'prq-head' },
        el('div', { class: 'prq-titles' },
          el('span', { class: 'prq-eyebrow', text: 'Project requirements' }),
          el('h2', { class: 'prq-title is-sm', id: 'prqTitle', text: 'Whose checklist do you want to see?' }),
          el('p', { class: 'prq-muted', text: 'Shows every document a research project needs for IERB, and which ones are still missing or waiting for RPMS.' })),
        closeButton(ctx.close)),
      el('div', { class: 'prq-field' },
        el('label', { for: 'prqHolder', text: 'Student who submits for the group' }), select,
        el('span', { class: 'prq-muted prq-small', text: 'Each research group has one submission holder. Their checklist covers the whole group.' }))));
    try {
      const students = await opts.loadStudents();
      opts.students = students;
      select.replaceChildren(el('option', { value: '', text: students.length ? 'Select a student' : 'No students available' }),
        ...students.map(s => el('option', { value: String(s.id), text: `${s.name}${s.studentId ? ' (' + s.studentId + ')' : ''}${s.protocolCode ? ' · ' + s.protocolCode : ''}` })));
      select.disabled = !students.length;
      select.focus();
    } catch (error) {
      select.replaceChildren(el('option', { value: '', text: 'Student list unavailable' }));
      select.after(alertBox(error.message));
    }
    select.addEventListener('change', () => { if (select.value) showFor(ctx, select.value, opts); });
  }

  async function open(studentId, opts = {}) {
    const ctx = shell();
    await showFor(ctx, studentId, opts);
  }

  function choose(opts = {}) {
    const ctx = shell();
    showPicker(ctx, opts);
  }

  window.PrismRequirements = { load: (studentId, host) => load(studentId, host), open, choose };
})();
