/* Every displayed state and decision comes from the authorized requirement service. */
(() => {
  'use strict';
  const request = (action, options = {}) => PrismUI.request('documents_api.php?action=' + action, options);
  async function load(studentId, host) {
    if (!host) return;
    host.hidden = false; host.textContent = 'Loading project requirements...';
    try {
      const data = await request('requirements&studentId=' + encodeURIComponent(studentId));
      host.replaceChildren(); host.hidden = !data.enrolled;
      if (!data.enrolled) return;
      const title = document.createElement('h2'); title.textContent = 'Research project requirements';
      const stage = document.createElement('p'); stage.textContent = `${data.workflow.stageLabel} (${data.workflow.progress}%)`;
      const scopeInfo = data.submissionScope || {};
      const scope = document.createElement('p'); scope.textContent = (scopeInfo.uploadPolicy ? scopeInfo.uploadPolicy + ' ' : '') + 'Requirements are satisfied once for the project. RPMS advances eligible groups; Final Report formal submission completes the project automatically.';
      const refresh = document.createElement('button'); refresh.type = 'button'; refresh.className = 'prism-btn is-secondary'; refresh.textContent = 'Refresh requirements';
      refresh.onclick = () => load(studentId, host); host.append(title, stage);
      if (scopeInfo.researchTitle) { const t = document.createElement('p'); t.textContent = 'Research title: ' + scopeInfo.researchTitle; host.append(t); }
      if (scopeInfo.projectId) {
        const pi = document.createElement('p'); const info = scopeInfo.principalInvestigator;
        pi.textContent = 'Principal Investigator: ' + (info ? `${info.name} (designated by RPMS)` : 'Not designated by RPMS');
        host.append(pi);
      }
      host.append(scope, refresh);
      if (data.workflow.correctionCase) {
        const policy = document.createElement('p'); policy.textContent = 'Open correction case: Amendment Form ' + (data.workflow.correctionCase.requiresAmendment ? 'required.' : 'not required.'); host.append(policy);
      }
      corrections(data, studentId, host);
      const list = document.createElement('ul'); list.className = 'v14-requirements';
      data.requirements.forEach(row => {
        const item = document.createElement('li');
        const text = document.createElement('span'); text.textContent = `${row.label}: ${row.state}${row.versionNo ? ' (v' + row.versionNo + ')' : ''}`;
        item.append(text);
        if (row.acceptanceEventId) { const evidence = document.createElement('small'); evidence.textContent = `Acceptance evidence #${row.acceptanceEventId} / ${row.acceptedAt}`; item.append(evidence); }
        if (row.canAccept) {
          const button = document.createElement('button'); button.type = 'button'; button.className = 'prism-btn is-secondary'; button.textContent = 'Accept requirement';
          button.onclick = async () => {
            const pending = PrismUI.confirm({ title: 'Accept RPMS requirement', confirmText: 'Accept current version', reasonLabel: 'RPMS acceptance reason', reasonRequired: true, minReason: 5,
              message: `Accept ${row.label} version ${row.versionNo} for this project submission?`,
              ...(row.key === 'form33' ? { extraHtml: '<label for="institutionalApprovalReference">Institutional approval reference</label><textarea id="institutionalApprovalReference" required minlength="5" maxlength="1000" placeholder="Enter the institutional approval reference"></textarea>',
                collect: dialog => ({ reference: dialog.querySelector('#institutionalApprovalReference').value }),
                validate: values => values.reference.trim().length < 5 ? 'Provide the institutional approval reference.' : null } : {}) });
            const dialog = host.closest('dialog'); const overlay = document.querySelector('.prism-overlay');
            if (dialog && overlay) dialog.append(overlay);
            const answer = await pending;
            if (!answer) return;
            const release = PrismUI.busy(button, 'Accepting...'); if (!release) return;
            try {
              await request('accept_requirement', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ studentId: Number(data.submissionScope.studentId), requirementKey: row.key,
                documentId: row.documentId, revision: data.workflow.revision, reason: answer.reason, requestKey: crypto.randomUUID(),
                institutionalApprovalReference: answer.values?.reference || '' }) });
              await load(studentId, host);
            } catch (error) { const message = document.createElement('p'); message.setAttribute('role','alert'); message.textContent = error.message; host.append(message); }
            finally { release(); }
          }; item.append(button);
        }
        list.append(item);
      }); host.append(list); return data;
    } catch (error) { host.textContent = 'Requirements unavailable: ' + error.message; }
  }
  // Correction requests (Adviser) and decisions (RPMS). The server re-checks every role, assignment and revision.
  const docChoices = data => data.requirements.filter(row => row.documentId && row.key !== 'final_report')
    .map(row => `<label class="v14-doc-choice"><input type="checkbox" name="affected" value="${PrismUI.esc(row.documentId)}"> ${PrismUI.esc(row.label)} (v${row.versionNo})</label>`).join('');
  const amendmentChoice = '<fieldset class="v14-amendment-choice"><legend>Amendment Form for this case <span class="required-marker" aria-hidden="true">*</span></legend>'
    + '<label><input type="radio" name="amendment" value="1" required> Required</label><label><input type="radio" name="amendment" value="0"> Not required</label></fieldset>';
  // The panel can sit in a modal <dialog> (top layer); keep the confirmation above it, as Accept requirement does.
  function ask(host, options) {
    const pending = PrismUI.confirm(options);
    const dialog = host.closest('dialog'), overlay = document.querySelector('.prism-overlay');
    if (dialog && overlay) dialog.append(overlay);
    return pending;
  }
  async function operate(button, action, body, studentId, host) {
    const release = PrismUI.busy(button, 'Saving...'); if (!release) return;
    try {
      await PrismUI.request('ierb_api.php?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, requestKey: crypto.randomUUID() }) });
      await load(studentId, host);
    } catch (error) { const message = document.createElement('p'); message.setAttribute('role', 'alert'); message.textContent = error.message; host.append(message); }
    finally { release(); }
  }
  function corrections(data, studentId, host) {
    const pending = data.pendingCorrectionRequest, role = data.viewerRole, base = { studentId: Number(data.submissionScope.studentId), revision: data.workflow.revision };
    if (pending) {
      const note = document.createElement('p'); note.setAttribute('role', 'status');
      note.textContent = `Correction request pending RPMS review since ${pending.requestedAt}. The official IERB stage is unchanged until RPMS decides.`
        + (pending.reason ? ` Requested by ${pending.requestedBy}: ${pending.reason}` : '');
      host.append(note);
    }
    if (!data.operationsActivated || !['adviser', 'admin'].includes(role)) return;
    const canOpen = !data.workflow.correctionCase && ['initial_submission', 'approval_data_gathering'].includes(data.workflow.stage);
    const actions = document.createElement('div'); actions.className = 'v14-correction-actions'; actions.setAttribute('role', 'group'); actions.setAttribute('aria-label', 'Correction case actions');
    const add = (label, handler, tone = 'secondary') => { const b = document.createElement('button'); b.type = 'button'; b.className = 'prism-btn is-' + tone; b.textContent = label; b.onclick = () => handler(b); actions.append(b); };
    const pickDocs = dialog => [...dialog.querySelectorAll('input[name="affected"]:checked')].map(input => input.value);
    const pickAmendment = dialog => dialog.querySelector('input[name="amendment"]:checked')?.value;
    if (role === 'adviser' && canOpen && !pending) add('Request correction case', async button => {
      const answer = await ask(host, { title: 'Request a correction case', confirmText: 'Send request to RPMS', reasonLabel: 'Reason for the correction request', reasonRequired: true, minReason: 5, maxReason: 1000,
        message: 'RPMS reviews this request. The official IERB stage does not change unless RPMS approves it.',
        extraHtml: `<fieldset><legend>Affected current documents <span class="required-marker" aria-hidden="true">*</span></legend>${docChoices(data) || '<p>No current documents are available.</p>'}</fieldset><label><input type="checkbox" id="suggestAmendment"> Suggest that an Amendment Form is required</label>`,
        collect: dialog => ({ documentIds: pickDocs(dialog), suggestAmendment: dialog.querySelector('#suggestAmendment').checked }),
        validate: values => values.documentIds.length ? null : 'Select at least one affected current document.' });
      if (answer) await operate(button, 'v14_correction_request', { ...base, reason: answer.reason, ...answer.values }, studentId, host);
    });
    if (role === 'admin' && pending) {
      add('Approve request', async button => {
        const answer = await ask(host, { title: 'Approve correction request', confirmText: 'Approve and open case', reasonLabel: 'RPMS decision reason', reasonRequired: true, minReason: 5, maxReason: 1000,
          message: 'Approval opens the official Resubmission / Amendment case for the whole project.', extraHtml: amendmentChoice,
          collect: dialog => ({ amendment: pickAmendment(dialog) }), validate: values => values.amendment ? null : 'Decide whether an Amendment Form is required.' });
        if (answer) await operate(button, 'v14_correction_decide', { ...base, requestEventId: pending.requestEventId, decision: 'approve', requiresAmendment: answer.values.amendment === '1', reason: answer.reason }, studentId, host);
      }, 'primary');
      add('Reject request', async button => {
        const answer = await ask(host, { title: 'Reject correction request', confirmText: 'Reject request', tone: 'danger', reasonLabel: 'Reason shared with the Adviser and members', reasonRequired: true, minReason: 5, maxReason: 1000,
          message: 'The official IERB stage stays unchanged.' });
        if (answer) await operate(button, 'v14_correction_decide', { ...base, requestEventId: pending.requestEventId, decision: 'reject', reason: answer.reason }, studentId, host);
      });
    }
    if (role === 'admin' && canOpen) add('Open correction case', async button => {
      const answer = await ask(host, { title: 'Open an RPMS correction case', confirmText: 'Open case', reasonLabel: 'RPMS reason', reasonRequired: true, minReason: 5, maxReason: 1000,
        message: pending ? 'A pending Adviser request will be recorded as superseded by this RPMS case.' : 'This moves the whole project to Resubmission / Amendment.',
        extraHtml: `<fieldset><legend>Affected current documents <span class="required-marker" aria-hidden="true">*</span></legend>${docChoices(data)}</fieldset>${amendmentChoice}`,
        collect: dialog => ({ documentIds: pickDocs(dialog), amendment: pickAmendment(dialog) }),
        validate: values => !values.documentIds.length ? 'Select at least one affected current document.' : (values.amendment ? null : 'Decide whether an Amendment Form is required.') });
      if (answer) await operate(button, 'v14_correction_open', { ...base, documentIds: answer.values.documentIds, requiresAmendment: answer.values.amendment === '1', reason: answer.reason }, studentId, host);
    });
    if (role === 'admin' && data.workflow.correctionCase) add('Resolve correction case', async button => {
      const answer = await ask(host, { title: 'Resolve correction case', confirmText: 'Resolve and resume review', reasonLabel: 'RPMS resolution reason', reasonRequired: true, minReason: 5, maxReason: 1000,
        message: 'Resolution requires accepted corrected versions (and the Amendment Form when required). The project returns to its interrupted review stage.' });
      if (answer) await operate(button, 'v14_correction_resolve', { ...base, reason: answer.reason }, studentId, host);
    });
    if (actions.childElementCount) host.append(actions);
  }
  async function open(studentId) {
    const dialog = document.createElement('dialog'); dialog.className = 'v14-checklist-dialog';
    const close = document.createElement('button'); close.textContent = 'Close requirements'; close.className = 'prism-btn is-secondary'; close.onclick = () => dialog.close();
    const host = document.createElement('section'); dialog.append(close, host); document.body.append(dialog);
    dialog.addEventListener('close', () => dialog.remove()); dialog.showModal(); await load(studentId, host);
    if (host.hidden) { host.hidden = false; host.textContent = 'This record uses the existing legacy workflow. V14 requires explicit authorized enrollment.'; }
  }
  window.PrismRequirements = { load, open };
})();
