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
      const scope = document.createElement('p'); scope.textContent = 'Project requirements are shared through the designated submission holder. RPMS advances eligible groups; Final Report formal submission completes the project automatically.';
      const refresh = document.createElement('button'); refresh.type = 'button'; refresh.className = 'prism-btn is-secondary'; refresh.textContent = 'Refresh requirements';
      refresh.onclick = () => load(studentId, host); host.append(title, stage, scope, refresh);
      if (data.workflow.correctionCase) {
        const policy = document.createElement('p'); policy.textContent = 'Open correction case: Amendment Form ' + (data.workflow.correctionCase.requiresAmendment ? 'required.' : 'not required.'); host.append(policy);
      }
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
              ...(row.key === 'form33' ? { extraHtml: '<label for="institutionalApprovalReference">Institutional approval reference</label><textarea id="institutionalApprovalReference" required minlength="5" maxlength="1000"></textarea>',
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
  async function open(studentId) {
    const dialog = document.createElement('dialog'); dialog.className = 'v14-checklist-dialog';
    const close = document.createElement('button'); close.textContent = 'Close requirements'; close.className = 'prism-btn is-secondary'; close.onclick = () => dialog.close();
    const host = document.createElement('section'); dialog.append(close, host); document.body.append(dialog);
    dialog.addEventListener('close', () => dialog.remove()); dialog.showModal(); await load(studentId, host);
    if (host.hidden) { host.hidden = false; host.textContent = 'This record uses the existing legacy workflow. V14 requires explicit authorized enrollment.'; }
  }
  window.PrismRequirements = { load, open };
})();
