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
      const portalChecklist = host.id === 'v14RequirementChecklist';
      const title = document.createElement('h2'); title.textContent = portalChecklist ? 'Current-stage document checklist' : 'Research project requirements';
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
      const used = Number(data.workflow.officialResubmissionCount || 0);
      const limit = Number(data.workflow.officialResubmissionLimit || 3);
      const cycleInfo = document.createElement('p'); cycleInfo.className = 'v14-resubmission-usage';
      cycleInfo.textContent = `Official RPMS resubmissions: ${used} of ${limit} used. Ordinary Adviser document revisions do not count.`;
      host.append(cycleInfo);
      if (portalChecklist) {
        const allowed = new Set(data.uploadTypes || []);
        const stageKey = data.workflow.stage;
        const visible = data.requirements.filter(row => stageKey === 'initial_submission'
          ? row.stage === 'initial_submission'
          : stageKey === 'resubmission_amendment'
            ? allowed.has(row.label) || row.key === 'amendment' && data.workflow.correctionCase?.requiresAmendment
            : stageKey === 'approval_data_gathering'
              ? ['form33', 'final_report'].includes(row.key)
              : true);
        const complete = visible.filter(row => row.state === 'Accepted' ||
          (row.key === 'final_report' && row.state === 'Formally Submitted to RPMS')).length;
        const summary = document.createElement('p');
        summary.className = 'v14-checklist-summary';
        summary.textContent = complete + ' of ' + visible.length + ' requirements accepted / completed for this stage';
        host.append(summary);
      }
      if (data.workflow.correctionCase) {
        const policy = document.createElement('p'); policy.textContent = 'Open correction case: Amendment Form ' + (data.workflow.correctionCase.requiresAmendment ? 'required.' : 'not required.'); host.append(policy);
      }

      if (!portalChecklist && data.viewerRole === 'admin') {
        const ready = data.requirements.filter(row =>
          row.canAccept && row.state === 'Formally Submitted to RPMS'
          && row.key !== 'form33' && row.key !== 'final_report');
        if (ready.length) {
          const bulk = document.createElement('section');
          bulk.className = 'v14-bulk-accept';
          const label = document.createElement('p');
          label.textContent = 'RPMS bulk review: select formally submitted requirements to accept together.';
          bulk.append(label);
          const selectors = [];
          ready.forEach(row => {
            const choice = document.createElement('label');
            const input = document.createElement('input'); input.type = 'checkbox';
            const name = document.createElement('span');
            name.textContent = row.label + ' (version ' + row.versionNo + ')';
            choice.append(input, name); bulk.append(choice);
            selectors.push({row,input});
          });
          const selectAll = document.createElement('button');
          selectAll.type = 'button'; selectAll.className = 'prism-btn is-secondary';
          selectAll.textContent = 'Select all eligible';
          selectAll.onclick = () => selectors.forEach(({input}) => { input.checked = true; });
          const accept = document.createElement('button');
          accept.type = 'button'; accept.className = 'prism-btn is-primary';
          accept.textContent = 'Accept selected requirements';
          accept.onclick = async () => {
            const chosen = selectors.filter(({input}) => input.checked).map(({row}) => ({
              requirementKey: row.key, documentId: row.documentId
            }));
            if (!chosen.length) {
              PrismUI.toast('Select at least one formally submitted requirement.', 'info');
              return;
            }
            const prompt = PrismUI.confirm({
              title: 'Accept selected RPMS requirements',
              confirmText: 'Accept ' + chosen.length + ' requirements',
              reasonRequired: true, minReason: 5,
              reasonLabel: 'RPMS acceptance reason',
              message: 'Review the selected current document versions. The whole batch will either succeed or be rolled back.'
            });
            const dialog = host.closest('dialog'), overlay = document.querySelector('.prism-overlay');
            if (dialog && overlay) dialog.append(overlay);
            const answer = await prompt;
            if (!answer) return;
            const release = PrismUI.busy(accept, 'Accepting...');
            if (!release) return;
            try {
              const result = await request('accept_requirements_bulk', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({studentId: Number(data.submissionScope.studentId),
                  items: chosen, revision: data.workflow.revision,
                  reason: answer.reason, requestKey: crypto.randomUUID()})
              });
              PrismUI.toast(result.message || 'RPMS requirements accepted.', 'success');
              await load(studentId, host);
            } catch (error) {
              const alert = document.createElement('p');
              alert.setAttribute('role', 'alert'); alert.textContent = error.message; bulk.append(alert);
            } finally { release(); }
          };
          bulk.append(selectAll, accept);
          host.append(bulk);
        }
      }
      corrections(data, studentId, host);
      const list = document.createElement('ul'); list.className = 'v14-requirements';
      const portalRows = !portalChecklist ? data.requirements : data.requirements.filter(row => {
        const current = data.workflow.stage;
        if (current === 'initial_submission') return row.stage === 'initial_submission';
        if (current === 'resubmission_amendment') return (data.uploadTypes || []).includes(row.label);
        if (current === 'approval_data_gathering') return ['form33', 'final_report'].includes(row.key);
        return true; // Completed projects retain a read-only record.
      });
      portalRows.forEach(row => {
        const item = document.createElement('li');
        const text = document.createElement('span'); text.textContent = `${row.label}: ${row.state}${row.versionNo ? ' (v' + row.versionNo + ')' : ''}`;
        item.append(text);
        if (portalChecklist && row.state === 'Missing' && (data.uploadTypes || []).includes(row.label)) {
          const upload = document.createElement('button');
          upload.type = 'button'; upload.className = 'prism-btn is-secondary';
          upload.textContent = 'Upload'; upload.setAttribute('aria-label', 'Upload ' + row.label);
          upload.addEventListener('click', () => document.dispatchEvent(new CustomEvent('prism:upload-requirement',
            {detail: {documentType: row.label}})));
          item.append(upload);
        }
        if (portalChecklist && row.state === 'Returned for Revision' && row.documentId
            && (data.uploadTypes || []).includes(row.label)) {
          const revise = document.createElement('button');
          revise.type = 'button'; revise.className = 'prism-btn is-secondary';
          revise.textContent = 'Upload revision';
          revise.addEventListener('click', () => document.dispatchEvent(new CustomEvent('prism:upload-revision', {
            detail: { id: row.documentId, originalName: row.label,
              documentType: row.documentType || row.label, versionNo: row.versionNo,
              stage: row.stage === 'initial_submission' ? 'Stage 1' : row.stage === 'resubmission_amendment' ? 'Stage 2' : 'Stage 3', actions: { uploadRevision: true } }
          })));
          item.append(revise);
        }
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
  // Official resubmissions are RPMS-initiated. Adviser document revisions stay in the normal document review UI.
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
    if (!data.operationsActivated || role !== 'admin') return;
    const used = Number(data.workflow.officialResubmissionCount || 0);
    const maximum = Number(data.workflow.officialResubmissionLimit || 3);
    const canOpen = used < maximum && !data.workflow.correctionCase && ['initial_submission', 'approval_data_gathering'].includes(data.workflow.stage);
    if (used >= maximum && !data.workflow.correctionCase) {
      const limitNote = document.createElement('p'); limitNote.setAttribute('role', 'status');
      limitNote.textContent = 'Maximum of three official resubmissions reached. RPMS cannot open another case; ordinary document revision remains separate.';
      host.append(limitNote);
    }
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
