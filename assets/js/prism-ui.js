/* PRISM UI kit - shared behaviour for badges, toasts, confirmations, empty states, tooltips, first-time hints,
   list filters, mobile-friendly tables, and the self-contained widgets:
     [data-prism-student-workflow]   student revision panel (add ="compact" for the dashboard version)
     [data-prism-needs-attention]    admin/adviser "Students Needing Attention" list
     [data-prism-hint="id"]          dismissible first-time help (texts live in HINTS below)
     [data-prism-tip="text"]         small "?" tooltip
     table[data-prism-filter="Status,Stage"]   search box + dropdown filters for the listed column headings
     table[data-prism-responsive]    card layout under 760px (also auto-applied to the known document/monitor tables)
   No dependencies. Depends on prism-ui.css and Font Awesome 6 (already loaded by every PRISM page). */
(function (root) {
  'use strict';

  const STAGES = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
  // New-upload labels only. Historical records and filter values remain literal.
  const DOCUMENT_TYPES = Object.freeze(Array.isArray(root.PRISM_DOCUMENT_TYPES) ? root.PRISM_DOCUMENT_TYPES.slice() : []);

  // label -> [tone, icon]. One place that defines how every status looks across the system.
 const BADGES = {
    // Document workflow
    'Pending Adviser Review':           ['warning', 'fa-hourglass-half'],
    'Ready for Formal RPMS Submission': ['info', 'fa-paper-plane'],
    'Submitted to RPMS':                ['success', 'fa-circle-check'],
    'Needs Revision':                   ['danger', 'fa-rotate-left'],
    'Returned for Revision':            ['warning', 'fa-rotate-left'],
    'Superseded':                       ['neutral', 'fa-clock-rotate-left'],

    // Document review
    'Submitted':                        ['neutral', 'fa-inbox'],
    'Received':                         ['neutral', 'fa-inbox'],
    'Under Review':                     ['warning', 'fa-magnifying-glass'],
    'Verified':                         ['info', 'fa-clipboard-check'],
    'Resubmission Requested':           ['danger', 'fa-rotate-left'],
    'Approved':                         ['success', 'fa-check'],
    'Denied':                           ['danger', 'fa-xmark'],
    'Revision Required':                ['danger', 'fa-rotate-left'],
    'Cancelled':                        ['neutral', 'fa-ban'],

    // IERB progress
    'On Track':                         ['success', 'fa-circle-check'],
    'Pending':                          ['warning', 'fa-clock'],
    'Delayed':                          ['danger', 'fa-triangle-exclamation'],
    'Completed':                        ['success', 'fa-flag-checkered'],

    // Accounts
    'Active':                           ['success', 'fa-circle-check'],
    'Inactive':                         ['neutral', 'fa-circle-minus'],
    'Pending Activation':               ['warning', 'fa-clock'],

    // Notifications / delivery
    'Sent':                             ['success', 'fa-paper-plane'],
    'Scheduled':                        ['warning', 'fa-calendar-clock'],
    'Logged':                           ['neutral', 'fa-file-lines'],
    'Sending':                          ['info', 'fa-spinner'],
    'Failed':                           ['danger', 'fa-circle-exclamation'],
    'Archived':                         ['neutral', 'fa-box-archive'],

    // Administrative
    'Admin Override':                   ['override', 'fa-user-shield']
};

  const TIPS = {
    'Pending Adviser Review': 'Waiting for the research adviser to review this document.',
    'Ready for Formal RPMS Submission': 'Approved but not yet formally submitted. Any verified project member may submit an Adviser approval; RPMS submits an Admin-reviewed approval.',
    'Returned for Revision': 'RPMS returned the submitted version. Upload a new PDF/DOCX revision for Adviser review.',
    'Submitted to RPMS': 'Formally submitted to RPMS. This record is locked.',
    'Needs Revision': 'The adviser asked for changes. Upload a corrected version.',
    'Superseded': 'An older version. A newer version replaced it.',
    'Admin Override': 'An RPMS administrator changed this. Who, when and why is in the audit log.'
  };

  const HINTS = {
    'student-documents': {
      title: 'How your documents move',
      steps: [
        'You upload a document. It shows as Pending Adviser Review.',
        'Your adviser approves it, or asks for changes.',
        'Adviser approval automatically and formally submits the current version to RPMS.',
        'An earlier Adviser approval that was not forwarded shows Ready for Formal RPMS Submission. Any verified project member can then use Submit to RPMS.',
        'If RPMS returns it, use Upload Revision. The new version returns to Adviser review; earlier versions are kept.'
      ]
    },
    'student-submit': {
      title: 'Before you upload',
      text: 'Upload one file per requirement. Uploading the same document type again for the same stage creates a new version. Your earlier version stays in the history.'
    },
    'adviser-documents': {
      title: 'Reviewing your students\u2019 documents',
      text: 'Approve the eligible current version to formally submit it to RPMS in the same operation. Deny or Request Revision requires feedback and does not formally submit. The Student can upload a new revision.'
    },
    'admin-dashboard': {
      title: 'Students Needing Attention',
      text: 'This list shows delayed students, documents waiting too long for an adviser, approved documents nobody has submitted to RPMS, and students with no recent activity. Every Admin Override is saved with your name, the time and your reason.'
    }
  };

  // ------------------------------------------------------------------ helpers
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function el(tag, cls, html) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }

  function fmtDate(v) {
    if (!v) return '';
    const d = new Date(String(v).replace(' ', 'T'));
    return isNaN(d) ? String(v) : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  // ------------------------------------------------------------------ badges
  function badge(label, opts) {
    opts = opts || {};
    const meta = BADGES[label] || (/^Stage \d/.test(label) ? ['neutral', 'fa-layer-group'] : ['neutral', 'fa-circle']);
    const text = opts.text != null ? opts.text : label;
    const title = opts.title || TIPS[label] || '';
    return '<span class="prism-badge tone-' + meta[0] + (opts.small ? ' is-small' : '') + '"' +
      (title ? ' title="' + esc(title) + '"' : '') + '>' +
      '<i class="fa-solid ' + meta[1] + '" aria-hidden="true"></i><span>' + esc(text) + '</span></span>';
  }

  function badgeElement(label, opts) {
    const template = document.createElement('template');
    template.innerHTML = badge(label, opts); // badge escapes every caller-supplied value.
    return template.content.firstElementChild;
  }

  /** Small "2 awaiting review / 1 ready to submit" badges for a student's `docs` counts. */
  function docMini(docs) {
    if (!docs) return '';
    const out = [];
    if (docs.pendingReview) out.push(badge('Pending Adviser Review', { small: true, text: docs.pendingReview + ' awaiting review' }));
    if (docs.needsRevision) out.push(badge('Needs Revision', { small: true, text: docs.needsRevision + ' need revision' }));
    if (docs.readyForRpms) out.push(badge('Ready for Formal RPMS Submission', { small: true, text: docs.readyForRpms + ' ready to submit' }));
    return out.length ? '<div class="prism-badges">' + out.join('') + '</div>' : '';
  }

  /** Empty state: say what is missing and what to do next. */
  function emptyState(o) {
    o = o || {};
    let action = '';
    if (o.actionLabel && o.actionHref) {
      action = '<a class="prism-btn is-primary is-sm" href="' + esc(o.actionHref) + '">' + esc(o.actionLabel) + '</a>';
    } else if (o.actionLabel && o.actionId) {
      action = '<button type="button" class="prism-btn is-primary is-sm" data-prism-action="' + esc(o.actionId) + '">' + esc(o.actionLabel) + '</button>';
    }
    return '<div class="prism-empty">' + (o.icon ? '<i class="fa-solid ' + esc(o.icon) + '" aria-hidden="true"></i>' : '') +
      '<h4>' + esc(o.title || 'Nothing here yet') + '</h4>' + (o.text ? '<p>' + esc(o.text) + '</p>' : '') + action + '</div>';
  }

  function tip(text) {
    return '<span class="prism-tip" tabindex="0" role="note" aria-label="' + esc(text) + '" data-tip="' + esc(text) +
      '"><i class="fa-solid fa-question" aria-hidden="true"></i></span>';
  }

  // ------------------------------------------------------------------ toasts
  function toast(message, type, ms) {
    type = type || 'success';
    let wrap = document.querySelector('.prism-toast-wrap');
    if (!wrap) {
      wrap = el('div', 'prism-toast-wrap');
      document.body.appendChild(wrap);
    }
    const icon = { success: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info' }[type] || 'fa-circle-info';
    const t = el('div', 'prism-toast is-' + type, '<i class="fa-solid ' + icon + '" aria-hidden="true"></i><span></span>');
    t.querySelector('span').textContent = message;
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    wrap.appendChild(t);
    setTimeout(function () { t.remove(); }, ms || (type === 'error' ? 8000 : 5000));
  }

  // ------------------------------------------------------------------ dialogs
  function modal(innerHtml, returnFocus = document.activeElement) {
    const previous = returnFocus;
    const overlay = el('div', 'prism-overlay');
    const dlg = el('div', 'prism-dialog', innerHtml);
    dlg.setAttribute('role', 'dialog');
    dlg.setAttribute('aria-modal', 'true');
    overlay.appendChild(dlg);
    document.body.appendChild(overlay);

    let closed = false;
    function close() {
      if (closed) return;
      closed = true;
      document.removeEventListener('keydown', onKey, true);
      overlay.remove();
      if (previous && previous.focus) previous.focus();
    }
    function onKey(e) {
      if (e.key === 'Escape') { e.stopPropagation(); if (api.locked) { e.preventDefault(); return; } if (api.onCancel) api.onCancel(); close(); return; }
      if (e.key === 'Tab') {
        const f = [...dlg.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
    document.addEventListener('keydown', onKey, true);
    const api = { overlay: overlay, dialog: dlg, close: close, onCancel: null, locked: false };
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay && !api.locked) { if (api.onCancel) api.onCancel(); close(); } });
    return api;
  }

  /**
   * Confirmation dialog. Resolves to null when cancelled, else { reason, values }.
   * o: title, icon, message (plain text), tone (primary|danger|override), confirmText,
   *    reasonLabel + reasonRequired + minReason + reasonPlaceholder, overlayClass, dialogClass, extraHtml (trusted markup), collect(dialog), validate(values),
   *    busyText + onConfirm(result): optional async action run while the dialog stays open; a thrown error is shown in the dialog
   *    and the user may retry or cancel. The dialog resolves only after onConfirm succeeds, with { reason, values, result }.
   */
  let dialogErrorSequence = 0;
  function confirmDialog(o) {
    return new Promise(function (resolve) {
      const tone = o.tone || 'primary';
      const m = modal(
        '<h3 id="prismDlgTitle">' + (o.icon ? '<i class="fa-solid ' + esc(o.icon) + '" aria-hidden="true"></i>' : '') + esc(o.title) + '</h3>' +
        (o.message ? '<p>' + esc(o.message) + '</p>' : '') +
        (o.extraHtml || '') +
        (o.reasonLabel ? '<label for="prismReason">' + esc(o.reasonLabel) + '</label><textarea id="prismReason" maxlength="' + (o.maxReason || 500) + '"' +
          (o.reasonRequired ? ' required aria-required="true"' : '') + ' placeholder="' + esc(o.reasonPlaceholder || (o.reasonRequired ? 'Explain the reason for this action' : 'Add remarks (optional)')) + '"></textarea>' : '') +
        '<div class="prism-field-error" role="alert"></div>' +
        '<div class="prism-dialog-actions"><button type="button" class="prism-btn is-secondary" data-act="cancel">' + esc(o.cancelText || 'Cancel') + '</button>' +
        '<button type="button" class="prism-btn is-' + tone + '" data-act="ok">' + esc(o.confirmText || 'Confirm') + '</button></div>'
      );
      m.dialog.setAttribute('aria-labelledby', 'prismDlgTitle');
      if (o.overlayClass) {
        String(o.overlayClass).split(/\s+/).filter(Boolean).forEach(function (className) {
          m.overlay.classList.add(className);
        });
      }
      if (o.dialogClass) {
        String(o.dialogClass).split(/\s+/).filter(Boolean).forEach(function (className) {
          m.dialog.classList.add(className);
        });
      }
      if (tone === 'override') m.dialog.classList.add('prism-override-dialog');
      const err = m.dialog.querySelector('.prism-field-error');
      const reasonBox = m.dialog.querySelector('#prismReason');
      // The inline reason error is programmatically tied to its field, and cleared once the reason is long enough.
      err.id = 'prismDlgError' + (++dialogErrorSequence);
      if (reasonBox) reasonBox.addEventListener('input', function () {
        if (reasonBox.getAttribute('aria-invalid') === 'true' && reasonBox.value.trim().length >= (o.minReason || 5)) { reasonBox.removeAttribute('aria-invalid'); err.textContent = ''; }
      });
      const dirty = dirtyForm(m.dialog);
      const close = m.close;
      m.close = () => { dirty.clean(); dirty.dispose(); close(); };
      m.onCancel = function () { dirty.clean(); dirty.dispose(); resolve(null); };
      m.dialog.querySelector('[data-act="cancel"]').addEventListener('click', function () { m.close(); resolve(null); });
      const okButton = m.dialog.querySelector('[data-act="ok"]'), cancelButton = m.dialog.querySelector('[data-act="cancel"]');
      if (o.confirmDisabled) okButton.disabled = true;
      let pending = false;
      okButton.addEventListener('click', async function () {
        if (pending) return;
        const reason = reasonBox ? reasonBox.value.trim() : '';
        if (o.reasonRequired && reason.length < (o.minReason || 5)) {
          err.textContent = 'Add a reason (at least ' + (o.minReason || 5) + ' characters).';
          reasonBox.setAttribute('aria-invalid', 'true'); reasonBox.setAttribute('aria-describedby', err.id);
          reasonBox.focus();
          return;
        }
        const values = o.collect ? o.collect(m.dialog) : {};
        const problem = o.validate ? o.validate(values, reason) : null;
        if (problem) { err.textContent = problem; return; }
        if (typeof o.onConfirm !== 'function') {
          m.close();
          resolve({ reason: reason, values: values });
          return;
        }
        // Keep the dialog open, prevent duplicate requests, and report failures in place.
        pending = true; m.locked = true; err.textContent = '';
        const label = okButton.innerHTML;
        okButton.disabled = true; cancelButton.disabled = true; okButton.setAttribute('aria-busy', 'true');
        okButton.textContent = o.busyText || 'Working...';
        if (reasonBox) reasonBox.readOnly = true;
        try {
          const result = await o.onConfirm({ reason: reason, values: values });
          m.locked = false;
          m.close();
          resolve({ reason: reason, values: values, result: result });
        } catch (error) {
          pending = false; m.locked = false;
          okButton.innerHTML = label; okButton.disabled = false; cancelButton.disabled = false; okButton.removeAttribute('aria-busy');
          if (reasonBox) reasonBox.readOnly = false;
          err.textContent = (error && error.message) || 'The action could not be completed. Nothing was changed.';
          (error && error.retryable === false ? cancelButton : okButton).focus();
          if (error && error.retryable === false) okButton.disabled = true;
        }
      });
      const initialFocus = o.confirmDisabled ? cancelButton
        : reasonBox || m.dialog.querySelector('select') || (o.focusCancel ? cancelButton : okButton);
      initialFocus.focus();
    });
  }

  // ------------------------------------------------------------------ network
  let expiryRedirecting = false;
  function handleSessionExpiry(response, data) {
    if (response.status !== 401 || data?.code !== 'session_expired') return false;
    if (!expiryRedirecting && !/\/login\.php$/.test(root.location.pathname)) {
      expiryRedirecting = true;
      root.location.replace('login.php?expired=1');
    }
    return true;
  }

  async function request(url, opts) {
    let res, data = null;
    try {
      res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}));
    } catch (_) {
      throw new Error('Could not reach the server. Check your connection and try again.');
    }
    try { data = await res.json(); } catch (_) { /* handled below */ }
    handleSessionExpiry(res, data);
    if (!data) throw new Error('The server sent an unexpected response. Refresh the page and try again.');
    if (!res.ok || !data.ok) {
      const e = new Error(data.message || 'Something went wrong. Please try again.');
      e.data = data; e.status = res.status;
      throw e;
    }
    return data;
  }

  function postJson(url, body) {
    return request(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
  }

  function changed() {
    document.dispatchEvent(new CustomEvent('prism:documents-changed'));
  }

  // ------------------------------------------------------------------ critical actions
  const UPLOADER_ROLES = { student: 'Student', adviser: 'Research Adviser', admin: 'RPMS Administrator' };
  function dateTimeText(value) {
    const date = value ? new Date(String(value).replace(' ', 'T')) : null;
    return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : 'Not recorded';
  }

  /**
   * Permanent single-document deletion. The dialog shows the authoritative server record
   * (never the possibly stale list row), and the delete request is bound to that exact version.
   * Resolves to the API result after a confirmed deletion, or null when cancelled/unavailable.
   */
  async function confirmDocumentDelete(doc) {
    let data;
    try { data = (await request('documents_api.php?action=delete_preview&id=' + encodeURIComponent(doc.id))).document; }
    catch (e) { toast(e.message, 'error'); return null; }
    const uploader = data.uploadedBy ? data.uploadedBy + (UPLOADER_ROLES[data.uploaderRole] ? ' (' + UPLOADER_ROLES[data.uploaderRole] + ')' : '') : 'Not recorded';
    const rows = [
      ['Filename', data.originalName], ['Uploaded by', uploader], ['Uploaded on', dateTimeText(data.uploadedAt)],
      ['Document type', data.documentType || 'Not recorded'],
      ['Version', 'Version ' + data.versionNo + (data.isCurrent ? ' (current version)' : ' (previous version)')],
      ['Student record', data.studentName || 'Not recorded'],
    ];
    if (data.researchTitle) rows.push(['Research title', data.researchTitle + (data.projectIdentifier ? ' (' + data.projectIdentifier + ')' : '')]);
    const notes = [];
    if (data.locked) notes.push('This document was formally submitted to RPMS. The reason and your identity are recorded in the audit trail.');
    if (data.restoresPrevious) notes.push('The previous version will become the current version again.');
    const extraHtml = '<dl class="prism-delete-review">' + rows.map(([label, value]) => '<dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd>').join('') + '</dl>' +
      notes.map(text => '<p class="prism-sub">' + esc(text) + '</p>').join('') +
      (data.canDelete ? '<p class="prism-delete-warning">This action cannot be undone. The record and its stored file will be permanently deleted.</p>'
        : '<p class="prism-delete-warning">' + esc(data.blockedReason || 'This document cannot be deleted.') + '</p>');
    const answer = await confirmDialog({
      title: 'Delete Document?', icon: 'fa-trash', tone: 'danger', confirmText: 'Delete Document', busyText: 'Deleting...',
      extraHtml: extraHtml, focusCancel: true, confirmDisabled: !data.canDelete, dialogClass: 'prism-document-delete-dialog',
      reasonLabel: data.canDelete && data.requiresReason ? 'Reason for deleting this submitted document' : '',
      reasonRequired: data.canDelete && data.requiresReason, minReason: data.minReason || 5, maxReason: 5000,
      onConfirm: async ({ reason }) => {
        try { return await postJson('documents_api.php?action=delete', { id: data.id, reason: reason || '', expectedVersion: data.expectedVersion }); }
        catch (e) { if (e.status === 409 || e.status === 403 || e.status === 404) e.retryable = false; throw e; }
      }
    });
    return answer ? answer.result : null;
  }

  /** Existing Admin submission/override tool, retained for B11 compatibility. Students are denied by the API. */
  async function submitToRpms(doc) {
    const res = await confirmDialog({
      title: 'Submit to RPMS', icon: 'fa-paper-plane', confirmText: 'Submit to RPMS',
      message: 'Submit \u201c' + doc.originalName + '\u201d to RPMS on behalf of the student? This administrative intervention is recorded as Admin Override. The submitted version is locked.',
      reasonLabel:'Administrative submission reason',reasonRequired:true,maxReason:5000
    });
    if (!res) return false;
    try {
      const data = await postJson('documents_api.php?action=submit_to_rpms&id=' + encodeURIComponent(doc.id), {reason:res.reason});
      toast(data.message, 'success');
      changed();
      return true;
    } catch (e) {
      toast(e.message, 'error');
      return false;
    }
  }

  /** Any verified project member formally submits the exact Adviser-approved version that was not forwarded automatically. */
  async function memberSubmitToRpms(doc) {
    const res = await confirmDialog({
      title: 'Submit to RPMS', icon: 'fa-paper-plane', confirmText: 'Submit to RPMS',
      message: 'Formally submit “' + doc.originalName + '” version ' + doc.versionNo + ' to RPMS? Your Adviser approved this version. Any verified member of your research project may submit it once; the submitted version is then locked and the submission is recorded under your name.'
    });
    if (!res) return false;
    try {
      const data = await postJson('documents_api.php?action=submit_to_rpms&id=' + encodeURIComponent(doc.id), {versionNo: doc.versionNo});
      toast(data.message, 'success');
      changed();
      return true;
    } catch (e) {
      // Another member may have submitted it first; refresh so the action disappears.
      toast(e.message, 'error');
      changed();
      return false;
    }
  }

  /** Explicit RPMS decision; document activity never advances official stages. */
  async function changeStudentProgress(record, operation) {
    const labels = root.PRISM_STAGE_LABELS || {};
    const stageText = key => (labels[key] || key) + ' (' + key + ')';
    const index = STAGES.indexOf(record.stage);
    const next = STAGES[index + 1];
    if (operation === 'advance' && (!next || index < 0)) { toast('Completed is the final stage; it cannot advance further.', 'info'); return false; }
    const titles = {advance:'Advance to Next Stage',correct:'Correct IERB Stage',status:'Update Progress Status'};
    let extra = '<dl class="prism-override-context"><dt>Student</dt><dd>' + esc(record.name) + '</dd><dt>Current official stage</dt><dd>' + esc(stageText(record.stage)) + '</dd><dt>Current progress status</dt><dd>' + esc(record.status) + '</dd>';
    if (operation === 'advance') extra += '<dt>Proposed official stage</dt><dd>' + esc(stageText(next)) + '</dd>';
    extra += '</dl>';
    if (operation === 'correct') extra += '<label for="prismOvStage">Corrected official stage</label><select id="prismOvStage">' + STAGES.map(key => '<option value="' + esc(key) + '"' + (key === record.stage ? ' selected' : '') + '>' + esc(stageText(key)) + '</option>').join('') + '</select>';
    extra += '<label for="prismOvStatus">' + (operation === 'status' ? 'Proposed progress status' : 'Progress status (optional change)') + '</label><select id="prismOvStatus">' + (operation === 'status' ? '' : '<option value="">Keep ' + esc(record.status) + '</option>') + ['On Track','Pending','Delayed'].map(status => '<option' + (operation === 'status' && status === record.status ? ' selected' : '') + '>' + esc(status) + '</option>').join('') + '</select>';
    const answer = await confirmDialog({
      dialogClass:'prism-progress-decision', title: titles[operation], icon: operation === 'advance' ? 'fa-arrow-right' : 'fa-user-shield', tone: operation === 'correct' ? 'override' : 'primary',
      confirmText: operation === 'advance' ? 'Confirm advancement' : operation === 'correct' ? 'Confirm correction' : 'Confirm status update',
      message: operation === 'correct' ? 'Use a correction for exceptional backward or nonsequential movement. Your identity, time and reason are recorded, and the student and adviser are notified.' : 'RPMS decides readiness. This decision is recorded with your identity, time and reason. Documents and individual requirements do not automatically advance or block the official stage.',
      extraHtml: extra, reasonLabel: 'Reason for this decision (required)', reasonRequired: true, maxReason: 500,
      collect: dialog => ({stage:dialog.querySelector('#prismOvStage')?.value,status:dialog.querySelector('#prismOvStatus').value}),
      validate: values => operation === 'correct' && values.stage === record.stage ? 'Choose a different stage for a correction.' : operation === 'status' && values.status === record.status ? 'Choose a different progress status.' : null
    });
    if (!answer) return false;
    const payload = {id:record.id,reason:answer.reason,confirmed:true,expectedStage:record.stage,expectedStatus:record.status,expectedVersion:String(record.progressVersion)};
    if (operation === 'correct') payload.stage = answer.values.stage;
    if (answer.values.status) payload.status = answer.values.status;
    try {
      const data = await postJson('ierb_api.php?action=' + operation, payload);
      toast(data.message, 'success'); return true;
    } catch (error) { toast(error.message, 'error'); return false; }
  }

  async function overrideStudent(record) { return changeStudentProgress(record, 'correct'); }

  /** Always render content as text. The endpoint is the only persistent source. */
  async function showStageGuidance(stageKey) {
    const m = modal('<h3 id="prismGuidanceTitle">Guidance from RPMS</h3><div class="prism-stage-guidance" role="status">Loading guidance...</div><p>This guidance is informational. RPMS determines when your official IERB stage may advance.</p><div class="prism-dialog-actions"><button type="button" class="prism-btn is-secondary" data-close-guidance>Close</button></div>');
    m.dialog.classList.add('prism-stage-guidance-dialog');
    m.dialog.setAttribute('aria-labelledby','prismGuidanceTitle');
    const close = m.dialog.querySelector('[data-close-guidance]'); close.addEventListener('click',m.close); close.focus();
    try {
      const data = await request('stage_labels_api.php?action=list');
      if (!m.dialog.isConnected) return;
      const stage = data.stages.find(item => item.key === stageKey);
      m.dialog.querySelector('h3').textContent = 'Guidance from RPMS - ' + (stage?.label || stageKey);
      m.dialog.querySelector('.prism-stage-guidance').textContent = !data.guidanceAvailable ? 'Stage guidance is currently unavailable. Contact RPMS for guidance.' : stage?.guidance || 'No guidance has been added for this stage. Contact RPMS if you need help.';
    } catch (error) { if (m.dialog.isConnected) m.dialog.querySelector('.prism-stage-guidance').textContent = error.message; }
  }

  async function manageStages(onSaved, returnFocus = document.activeElement) {
    let data;
    try { data = await request('stage_labels_api.php?action=list'); }
    catch (error) { toast(error.message,'error'); return; }
    const m = modal('<h3 id="prismManageStagesTitle">' + (data.fixedStageNames?'Edit V14 Stage Guidance':'Manage IERB Stages') + '</h3><p>' + (data.fixedStageNames?'V14 stage names and order are fixed. Edit guidance for Students and Advisers only.':'Edit office-wide display names and optional guidance. Internal keys and ordering stay fixed.') + '</p><form><label for="prismStageKey">Fixed stage key</label><select id="prismStageKey">' + data.stages.map(stage => '<option>' + esc(stage.key) + '</option>').join('') + '</select><label for="prismStageName">Display name</label><input id="prismStageName" maxlength="190" placeholder="Enter the stage display name" required><label for="prismStageGuidance">Guidance from RPMS (optional)</label><textarea id="prismStageGuidance" rows="6" maxlength="4000" aria-describedby="prismStageGuidanceHelp" placeholder="Enter guidance for Students and Advisers (optional)"></textarea><p id="prismStageGuidanceHelp">Plain text, up to 4,000 characters. Students and Advisers can read this guidance. Clear it to remove guidance for this stage.</p><div class="prism-field-error" role="status" aria-live="polite"></div><div class="prism-dialog-actions"><button type="button" class="prism-btn is-secondary" data-close-stages>Close</button><button type="submit" class="prism-btn is-primary">Save stage</button></div></form>', returnFocus);
    m.dialog.classList.add('prism-stage-management');
    m.dialog.setAttribute('aria-labelledby','prismManageStagesTitle');
    const form=m.dialog.querySelector('form'), key=m.dialog.querySelector('select'), name=m.dialog.querySelector('input'), guidance=m.dialog.querySelector('textarea'), feedback=m.dialog.querySelector('.prism-field-error'), save=form.querySelector('[type=submit]');
    let selected, saving=false;
    function populate() {
      selected = data.stages.find(stage => stage.key === key.value);
      name.value = selected.label; name.readOnly = data.fixedStageNames === true;
      guidance.value = selected.guidance || ''; feedback.textContent = '';
      guidance.disabled = !data.guidanceAvailable;
      if (!data.guidanceAvailable) feedback.textContent = 'Guidance saving is unavailable until the authorized stage-guidance migration is applied. Display names can still be saved.';
    }
    const dirty=dirtyForm(form), originalClose=m.close;
    m.close=()=>{if(saving)return;dirty.clean();dirty.dispose();originalClose();};
    m.onCancel=()=>{ if(saving)return; dirty.clean();dirty.dispose(); };
    key.addEventListener('change',()=>{populate();dirty.clean();});
    m.dialog.querySelector('[data-close-stages]').addEventListener('click',()=>m.close());
    form.addEventListener('submit',async event=>{
      event.preventDefault();
      const release=busy(save,'Saving...'); if(!release)return;
      saving=true; key.disabled=true; name.disabled=true; guidance.disabled=true; feedback.textContent='Saving stage configuration...';
      const payload={stageKey:selected.key,label:name.value.trim(),expectedVersion:selected.version};
      if(data.guidanceAvailable)payload.guidance=guidance.value;
      try {
        data=await postJson('stage_labels_api.php?action=save',payload);
        if (!m.dialog.isConnected) return;
        Object.assign(root.PRISM_STAGE_LABELS || (root.PRISM_STAGE_LABELS={}),data.labels);
        name.disabled=false; guidance.disabled=!data.guidanceAvailable; populate(); dirty.clean(); feedback.textContent=data.message;
        toast(data.message,'success'); if(onSaved)await onSaved(data);
      } catch(error) { if(m.dialog.isConnected)feedback.textContent=error.message; toast(error.message,'error'); }
      finally { saving=false;key.disabled=false;name.disabled=false;guidance.disabled=!data.guidanceAvailable;release(); }
    });
    populate();dirty.clean();key.focus();
  }

  /** Admin: change a document's review status. Reason is required and logged. */
  async function overrideDocument(doc) {
    const statuses = ['Submitted', 'Under Review', 'Received', 'Verified', 'Resubmission Requested', 'Approved', 'Denied'];
    const opts = statuses.map(function (s) { return '<option value="' + esc(s) + '"' + (s === doc.reviewStatus ? ' selected' : '') + '>' + esc(s === 'Resubmission Requested' ? 'Request Revision' : s) + '</option>'; }).join('');
    const res = await confirmDialog({
      title: 'Admin Override', icon: 'fa-user-shield', tone: 'override', confirmText: 'Save override',
      message: 'You are overriding the adviser review of \u201c' + doc.originalName + '\u201d. The student and assigned adviser are notified. This does not itself formally submit the file to RPMS. The official IERB stage remains unchanged.',
      extraHtml: '<dl class="prism-override-context"><dt>Student / document version</dt><dd>'+esc((doc.student||doc.studentName||'Student not recorded')+' / v'+(doc.versionNo||1))+'</dd><dt>Document type / official stage</dt><dd>'+esc((doc.documentType||'Not recorded')+' / '+(doc.stageLabel||doc.stage||'Not recorded'))+'</dd><dt>Current review / workflow</dt><dd>'+esc(doc.reviewStatus+' / '+(doc.workflowState||doc.reviewStatus))+'</dd><dt>Prior feedback</dt><dd>'+esc(doc.reviewRemarks||'No feedback recorded')+'</dd></dl><label for="prismOvDoc">Proposed review status</label><select id="prismOvDoc">' + opts + '</select>',
      reasonLabel: 'Reason for this override (required)', reasonRequired: true,
      collect: function (d) { return { status: d.querySelector('#prismOvDoc').value }; },
      validate: function (v) { return v.status === doc.reviewStatus ? 'Choose a different status.' : null; }
    });
    if (!res) return false;
    try {
      const data = await postJson('documents_api.php?action=override_review&id=' + encodeURIComponent(doc.id), { status: res.values.status, reason: res.reason, expectedStatus:doc.reviewStatus });
      toast(data.message, 'success');
      changed();
      return true;
    } catch (e) {
      toast(e.message, 'error');
      return false;
    }
  }

  /** Version history of one document (older versions are kept, and can still be opened). */
  async function showVersions(docId) {
    const m = modal('<h3>Version history</h3><p class="prism-version-count" aria-live="polite"></p><ul class="prism-version-list"></ul><div class="prism-dialog-actions"><button type="button" class="prism-btn is-secondary" data-act="close">Close</button></div>');
    const list = m.dialog.querySelector('.prism-version-list');
    const count = m.dialog.querySelector('.prism-version-count');
    let sequence = 0;
    const pager = recordPager(list, count, [], load);
    m.dialog.querySelector('[data-act="close"]').addEventListener('click', m.close);
    m.dialog.querySelector('[data-act="close"]').focus();
    async function load() {
      const current = ++sequence; pager.loading(); list.setAttribute('aria-busy', 'true');
      try {
        const data = await request('documents_api.php?' + new URLSearchParams({action:'versions',id:docId,page:pager.page,perPage:pager.pageSize}));
        if (current !== sequence || !list.isConnected) return;
        const items = data.versions.map(function (v) {
          return '<li><div><strong>Version ' + esc(v.versionNo) + (v.isCurrent ? ' (current)' : '') + '</strong>' +
            '<small>' + esc(v.originalName) + ' \u00b7 uploaded ' + esc(fmtDate(v.uploadedAt)) + ' by ' + esc(v.uploadedBy) + '</small>' +
            (v.reviewedAt ? '<small>Reviewed: ' + esc(v.reviewStatus) + ' by ' + esc(v.reviewedBy) + ' · ' + esc(fmtDate(v.reviewedAt)) + '</small>' : '') +
            (v.rpmsSubmittedAt ? '<small>Formally submitted: ' + esc(fmtDate(v.rpmsSubmittedAt)) + ' by ' + esc(v.rpmsSubmittedBy) + '</small>' : '') +
            (v.rpmsReturnedAt ? '<small>RPMS returned for revision: ' + esc(fmtDate(v.rpmsReturnedAt)) + ' by ' + esc(v.rpmsReturnedBy) + '. Reason: ' + esc(v.rpmsReturnReason) + '</small>' : '') +
            (v.adminOverride ? '<small>Admin Override by '+esc(v.overrideBy)+' at '+esc(fmtDate(v.overrideAt))+'. Reason: '+esc(v.overrideReason)+'</small>' : '') +
            (v.reviewRemarks ? '<small>Remarks: ' + esc(v.reviewRemarks) + '</small>' : '') +
            '</div><div class="prism-badges">' + badge(v.workflowState) + (v.adminOverride ? badge('Admin Override') : '') +
            documentActionsHtml(v) + '</div></li>';
        }).join('');
        list.innerHTML = items;
        pager.render(data);
      } catch (error) { if (current === sequence) { pager.error(); list.textContent = error.message; } }
      finally { if (current === sequence) list.setAttribute('aria-busy', 'false'); }
    }
    await load();
  }

  // ------------------------------------------------------------------ widgets
  function goToSubmitPage() {
    const nav = document.querySelector('[data-go="submit"]') || document.querySelector('[data-page="submit"]');
    if (nav) nav.click();
  }

  function uploadRevision(doc) {
    goToSubmitPage();
    document.dispatchEvent(new CustomEvent('prism:upload-revision', { detail: doc }));
  }

  async function mountStudentWorkflow(host) {
    const compact = host.getAttribute('data-prism-student-workflow') === 'compact';
    host.classList.add('prism-wf-panel');

    async function render() {
      let docs, data;
      try { data = await request('documents_api.php?action=list&actionable=1'); docs = data.documents; }
      catch (e) {
        host.innerHTML = compact ? '' : emptyState({ icon: 'fa-triangle-exclamation', title: 'Couldn\u2019t load your documents', text: e.message });
        return;
      }
      const ready = docs.filter(function (d) { return d.workflowState === 'Ready for Formal RPMS Submission'; });
      const revise = docs.filter(function (d) { return ['Needs Revision','Returned for Revision'].includes(d.workflowState); });
      const counts = data.counts || {};
      if(!data.counts) docs.forEach(function (d) { counts[d.workflowState] = (counts[d.workflowState] || 0) + 1; });

      let html = '';
      if (!docs.length) {
        html = compact ? '' : emptyState({
          icon: 'fa-file-circle-plus', title: 'No document action needed',
          text: 'No revisions require your attention. Adviser approval automatically submits an eligible version to RPMS.',
          actionLabel: host.hasAttribute('data-prism-hide-submit') ? '' : 'Submit a document',
          actionId: host.hasAttribute('data-prism-hide-submit') ? '' : 'go-submit'
        });
      } else {
        if (!compact) {
          html += '<div class="prism-wf-summary">' + ['Pending Adviser Review', 'Needs Revision', 'Returned for Revision', 'Ready for Formal RPMS Submission', 'Submitted to RPMS']
            .filter(function (s) { return counts[s]; })
            .map(function (s) { return badge(s, { text: counts[s] + ' ' + s }); }).join('') + '</div>';
        }
        if (ready.length || revise.length) {
          html += '<h3 class="prism-wf-title"><i class="fa-solid fa-bolt" aria-hidden="true"></i>Action needed</h3><ul class="prism-wf-list">';
          ready.forEach(function (d) {
            html += '<li class="prism-wf-item"><div><strong>' + esc(d.originalName) + '</strong>' +
              '<div class="prism-badges">' + badge(d.workflowState) + '<span class="prism-sub">' + esc(d.documentType) + ' \u00b7 ' + esc(d.stageLabel) +
              (d.versionNo > 1 ? ' \u00b7 version ' + esc(d.versionNo) : '') + '</span></div>' +
              '<p class="prism-wf-remarks">Approved by ' + esc(d.reviewedBy || 'your adviser') + '. Contact RPMS to complete this historical or Admin-reviewed submission.</p></div>' +
              '<div class="prism-wf-actions">' +
              (d.versionNo > 1 ? '<button type="button" class="prism-btn is-secondary is-sm" data-versions="' + esc(d.id) + '">Version history</button>' : '') + '</div></li>';
          });
          revise.forEach(function (d) {
            html += '<li class="prism-wf-item"><div><strong>' + esc(d.originalName) + '</strong>' +
              '<div class="prism-badges">' + badge(d.workflowState) + '<span class="prism-sub">' + esc(d.documentType) + ' \u00b7 ' + esc(d.stageLabel) + '</span></div>' +
              '<p class="prism-wf-remarks">' + (d.rpmsReturnReason ? 'RPMS return reason: ' + esc(d.rpmsReturnReason) : d.reviewRemarks ? 'Adviser remarks: ' + esc(d.reviewRemarks) : 'Your adviser asked for changes.') + '</p></div>' +
              '<div class="prism-wf-actions"><button type="button" class="prism-btn is-secondary" data-upload-revision="' + esc(d.id) + '"><i class="fa-solid fa-upload" aria-hidden="true"></i> Upload Revision</button>' +
              (d.versionNo > 1 ? '<button type="button" class="prism-btn is-secondary is-sm" data-versions="' + esc(d.id) + '">Version history</button>' : '') + '</div></li>';
          });
          html += '</ul>';
        } else if (!compact) {
          html += '<p class="prism-sub">Nothing needs your action right now.</p>';
        }
      }
      if (data.total > docs.length) html += '<p class="prism-sub"><a href="student.php#documents">View all documents</a></p>';
      host.innerHTML = html;

      host.querySelectorAll('[data-upload-revision]').forEach(b => b.addEventListener('click', () => uploadRevision(docs.find(d => d.id === b.dataset.uploadRevision))));
      host.querySelectorAll('[data-versions]').forEach(function (b) {
        b.addEventListener('click', function () { showVersions(b.getAttribute('data-versions')); });
      });
      host.querySelectorAll('[data-go-submit], [data-prism-action="go-submit"]').forEach(function (b) {
        b.addEventListener('click', goToSubmitPage);
      });
    }
    render();
    document.addEventListener('prism:documents-changed', function () { if (document.body.contains(host)) render(); });
  }

  async function mountNeedsAttention(host) {
    host.innerHTML = '<p class="prism-sub">Checking who needs attention\u2026</p>';
    let data;
    try { data = await request('ierb_api.php?action=needs_attention&limit=' + (parseInt(host.getAttribute('data-limit'), 10) || 10)); }
    catch (e) {
      host.innerHTML = emptyState({ icon: 'fa-triangle-exclamation', title: 'Couldn\u2019t load this list', text: e.message });
      return;
    }
    if (!data.students.length) {
      host.innerHTML = emptyState({
        icon: 'fa-circle-check', title: 'Everyone is on track',
        text: 'No student is delayed, waiting too long for review, or inactive right now.'
      });
      return;
    }
    const items = data.students.map(function (s) {
      const title = s.research || s.protocolCode || s.studentId;
      return '<li class="prism-attn-item sev-' + s.topSeverity + '"><div class="prism-attn-main"><strong>' + esc(title) + '</strong>' +
        '<span class="prism-sub">' + esc(s.name) + (s.adviser ? ' \u00b7 Adviser: ' + esc(s.adviser) : '') + '</span>' +
        '<ul class="prism-attn-reasons">' + s.reasons.map(function (r) { return '<li>' + esc(r.label) + '</li>'; }).join('') + '</ul></div>' +
        '<div class="prism-badges">' + badge(s.stage, { text: s.stageLabel }) + badge(s.status) + '</div></li>';
    }).join('');
    const more = data.total > data.students.length
      ? '<p class="prism-sub">Showing ' + data.students.length + ' of ' + data.total + '.</p>' : '';
    host.innerHTML = '<ul class="prism-attn-list">' + items + '</ul>' + more;
  }

  // ------------------------------------------------------------------ tables
  /** Card layout on small screens: copies each column heading onto its cells as data-label. */
  function enhanceTable(table) {
    if (!table || table.dataset.prismEnhanced) return;
    // Existing scroll containers preserve whole stage/status labels on narrow screens.
    if (table.closest('.dashboard-table-scroll, .documents-table-wrap, .document-table-wrap')) return;
    table.dataset.prismEnhanced = '1';
    table.classList.add('prism-cards');
    const labels = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    function apply() {
      Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (tr) {
        Array.prototype.forEach.call(tr.children, function (td, i) {
          if (td.tagName === 'TD' && !td.hasAttribute('colspan') && labels[i] && td.getAttribute('data-label') !== labels[i]) {
            td.setAttribute('data-label', labels[i]);
          }
        });
      });
    }
    apply();
    const body = table.tBodies[0];
    if (body) new MutationObserver(apply).observe(body, { childList: true });
  }

  /** Search box + dropdown filters above a table. Works on rows the page renders later, too. */
  function tableFilter(table, o) {
    o = o || {};
    if (!table || table.dataset.prismFilterReady) return;
    const body = table.tBodies[0];
    if (!body) return;
    table.dataset.prismFilterReady = '1';

    const headings = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    const bar = el('div', 'prism-filterbar');
    const input = el('input');
    input.type = 'search';
    input.placeholder = o.placeholder || 'Search this list';
    input.setAttribute('aria-label', o.placeholder || 'Search this list');
    bar.appendChild(input);

    const selects = [];
    (o.filters || []).forEach(function (name) {
      const col = headings.findIndex(function (h) { return h.toLowerCase() === name.toLowerCase(); });
      if (col < 0) return;
      const sel = el('select');
      sel.setAttribute('aria-label', 'Filter by ' + name);
      bar.appendChild(sel);
      selects.push({ sel: sel, col: col, name: name });
    });

    const anchor = table.closest('.documents-table-wrap, .document-table-wrap, .table-wrap') || table;
    anchor.parentNode.insertBefore(bar, anchor);

    function dataRows() {
      return Array.prototype.filter.call(body.rows, function (r) {
        return !r.classList.contains('prism-noresult') && !Array.prototype.some.call(r.cells, function (c) { return c.hasAttribute('colspan'); });
      });
    }
    function refreshOptions() {
      const rows = dataRows();
      selects.forEach(function (s) {
        const keep = s.sel.value;
        const values = Array.from(new Set(rows.map(function (r) { return r.cells[s.col] ? r.cells[s.col].textContent.trim() : ''; }).filter(Boolean))).sort();
        s.sel.innerHTML = '<option value="">All ' + esc(s.name.toLowerCase()) + '</option>' +
          values.map(function (v) { return '<option' + (v === keep ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('');
      });
    }
    function apply() {
      const q = input.value.trim().toLowerCase();
      const rows = dataRows();
      let visible = 0;
      rows.forEach(function (r) {
        let ok = !q || r.textContent.toLowerCase().indexOf(q) !== -1;
        selects.forEach(function (s) {
          if (ok && s.sel.value && (!r.cells[s.col] || r.cells[s.col].textContent.trim() !== s.sel.value)) ok = false;
        });
        r.classList.toggle('prism-hidden', !ok);
        if (ok) visible++;
      });
      let none = body.querySelector('.prism-noresult');
      if (rows.length && !visible) {
        if (!none) {
          none = body.insertRow();
          none.className = 'prism-noresult';
          const td = none.insertCell();
          td.colSpan = headings.length || 1;
          td.innerHTML = emptyState({ icon: 'fa-magnifying-glass', title: 'No matches', text: 'Try a different search word, or clear the filters.' });
        }
      } else if (none) {
        none.remove();
      }
      obs.takeRecords(); // ignore the changes we just made ourselves
    }
    const obs = new MutationObserver(function () { refreshOptions(); apply(); });
    obs.observe(body, { childList: true });
    input.addEventListener('input', apply);
    selects.forEach(function (s) { s.sel.addEventListener('change', apply); });
    refreshOptions();
    apply();
  }

  // ------------------------------------------------------------------ hints
  function hint(host, id) {
    const h = HINTS[id];
    const key = 'prismHint:' + id;
    try { if (!h || localStorage.getItem(key)) return; } catch (_) { if (!h) return; }
    const body = (h.text ? esc(h.text) : '') +
      (h.steps ? '<ol>' + h.steps.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ol>' : '');
    host.innerHTML = '<div class="prism-hint" role="note"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i>' +
      '<div><strong>' + esc(h.title) + '</strong>' + body + '</div>' +
      '<button type="button" class="prism-hint-close" aria-label="Dismiss this tip">\u00d7</button></div>';
    host.querySelector('.prism-hint-close').addEventListener('click', function () {
      host.innerHTML = '';
      try { localStorage.setItem(key, '1'); } catch (_) { /* private mode: it will simply show again */ }
    });
  }

  // Keep ranges bounded even for thousands of pages; null denotes an inert ellipsis.
  function paginationWindow(pages, page, width = Infinity) {
    pages = Number.isFinite(Number(pages)) ? Math.max(1, Math.min(Number.MAX_SAFE_INTEGER, Math.floor(Number(pages)))) : 1;
    page = Number.isFinite(Number(page)) ? Math.max(1, Math.min(pages, Math.floor(Number(page)))) : 1;
    if (width < 280) return [page];
    const radius = width < 520 ? 0 : 2;
    const edge = radius ? 4 : 1;
    const visible = new Set([1, page, pages]);
    const start = Math.max(1, Math.min(page - radius, pages - edge + 1));
    const end = Math.min(pages, Math.max(page + radius, edge));
    for (let n = start; n <= end; n++) visible.add(n);
    const window = [];
    [...visible].sort((a, b) => a - b).forEach(n => {
      if (window.length) {
        const previous = window[window.length - 1];
        if (n - previous === 2) window.push(previous + 1);
        else if (n - previous > 2) window.push(null);
      }
      window.push(n);
    });
    return window;
  }

  // Resize the visible window without refetching records or changing the selected page.
  const paginationStates = new WeakMap();
  const paginationObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(entries => {
    entries.forEach(entry => {
      const state = paginationStates.get(entry.target);
      const width = Math.floor(entry.contentRect.width);
      if (state && width > 0 && width !== state.width) state.render(width);
    });
  }) : null;

  // Compact controls shared by server-paginated history views.
  function pagination(host, count, data, onPage, noun = 'entries') {
    const integer = (value, fallback, minimum) => Number.isFinite(Number(value))
      ? Math.max(minimum, Math.min(Number.MAX_SAFE_INTEGER, Math.floor(Number(value)))) : fallback;
    const total = integer(data.total, 0, 0);
    const limit = integer(data.limit, 10, 1);
    const pages = Math.max(1, Math.ceil(total / limit));
    const page = Math.min(pages, integer(data.page, 1, 1));
    const first = total ? (page - 1) * limit + 1 : 0;
    count.textContent = `Showing ${first}\u2013${Math.min(page * limit, total)} of ${total} ${noun}`;
    const render = width => {
      const restoreFocus = host.contains(document.activeElement)
        || (paginationStates.get(host).focusPending && document.activeElement === document.body);
      paginationStates.get(host).focusPending = false;
      paginationStates.get(host).width = width;
      host.replaceChildren();
      const button = (label, target, disabled = false) => {
        const el = document.createElement('button');
        el.type = 'button';
        el.textContent = label;
        if (label === 'Previous' || label === 'Next') {
          el.setAttribute('aria-label', label + ' page');
          el.title = label + ' page';
          el.innerHTML = label === 'Previous'
            ? '<span aria-hidden="true">&#8249;</span>'
            : '<span aria-hidden="true">&#8250;</span>';
        }
        el.disabled = disabled;
        if (typeof label === 'number') {
          el.setAttribute('aria-label', `Page ${target}`);
          if (target === page) el.setAttribute('aria-current', 'page');
        }
        el.addEventListener('click', () => {
          if (!el.disabled && target !== page && target >= 1 && target <= pages) {
            paginationStates.get(host).focusPending = document.activeElement === el;
            onPage(target);
          }
        });
        host.append(el);
      };
      button('Previous', page - 1, page === 1);
      paginationWindow(pages, page, width).forEach(n => {
        if (n === null) {
          const gap = document.createElement('span');
          gap.textContent = '\u2026';
          gap.setAttribute('aria-hidden', 'true');
          host.append(gap);
        } else button(n, n);
      });
      button('Next', page + 1, page === pages);
      if (restoreFocus) host.querySelector('[aria-current="page"]')?.focus();
    };
    const observed = paginationStates.has(host);
    paginationStates.set(host, { width: 0, render, focusPending: paginationStates.get(host)?.focusPending || false });
    render(Math.floor(host.clientWidth || root.innerWidth || 520));
    if (!observed) paginationObserver?.observe(host);
  }

  function debounce(action, delay = 250) {
    let timer;
    const run = (...args) => { clearTimeout(timer); timer = setTimeout(() => action(...args), delay); };
    run.cancel = () => clearTimeout(timer);
    return run;
  }

  function recordFilters(host, definitions) {
    const catalog = root.PRISM_ACADEMIC_CATALOG;
    const restored = new URLSearchParams(root.location.search);
    const choices = {};
    const notice = document.createElement('p');
    notice.className = 'prism-filter-notice'; notice.setAttribute('role', 'status'); notice.hidden = true;
    const fields = definitions.map(([key, label, existing]) => {
      const select = existing || document.createElement('select');
      if (!existing) {
        const wrapper = document.createElement('label');
        wrapper.textContent = label;
        select.id = host.id + '_' + key;
        select.append(new Option('All ' + label, ''));
        wrapper.append(select); host.append(wrapper);
      }
      if (restored.has(key)) {
        const value = restored.get(key);
        if (value && ![...select.options].some(option => option.value === value)) select.add(new Option(value, value));
        select.value = value;
      }
      return {key, select};
    });
    const field = key => fields.find(item => item.key === key)?.select;
    function permitted(key, value) {
      const unit = field('academicUnitKey')?.value || '';
      const program = catalog?.programs[field('programKey')?.value];
      if (key === 'programKey' && unit && value !== '__blank__') {
        const entry = catalog?.programs[value];
        return unit === '__blank__' ? (!entry || entry.unit === null) : entry?.unit === unit;
      }
      if (key === 'yearLevel' && program && value !== '__blank__') {
        const levels = program.level === 'undergraduate'
          ? Array.from({length: program.duration}, (_, i) => `${i + 1}${['st','nd','rd'][i] || 'th'} Year`) : [];
        return levels.includes(value);
      }
      return true;
    }
    function reconcile() {
      const cleared = [];
      fields.forEach(({key,select}) => {
        if (catalog && select.value && !permitted(key, select.value)) { select.value = ''; cleared.push(key === 'programKey' ? 'Program' : 'Year level'); }
        if (!choices[key]) return;
        const selected = select.value, first = select.options[0].textContent;
        const entries = choices[key].filter(option => !catalog || permitted(key, option.value));
        select.replaceChildren(new Option(first, ''));
        entries.forEach(option => select.add(new Option(option.label, option.value)));
        // Retain unavailable independent filters explicitly so a reload cannot broaden scope.
        if (selected && !entries.some(option => option.value === selected)) select.add(new Option(selected + ' (unavailable)', selected));
        select.value = selected;
      });
      if (cleared.length) { notice.textContent = cleared.join(' and ') + ' cleared to match the selected academic filters.'; notice.hidden = false; }
    }
    if (field('academicUnitKey')) {
      host.append(notice);
      ['academicUnitKey','programKey'].forEach(key => field(key)?.addEventListener('change', reconcile));
      reconcile();
    }
    return {
      controls: fields.map(field => field.select),
      reconcile,
      query: () => Object.fromEntries(fields.map(({key,select}) => [key,select.value])),
      update: options => { fields.forEach(({key}) => { if (options?.[key]) choices[key] = options[key]; }); reconcile(); host.dispatchEvent(new Event('prism:filters-updated')); }
    };
  }

  // One active floating panel. Keep its DOM beside the trigger for normal Tab order.
  // Fixed positioning avoids clipping by the tables' horizontal scroll containers.
  let floating = null;
  let floatingSequence = 0;
  function positionFloating() {
    if (!floating) return;
    const { trigger, panel } = floating;
    if (!trigger.isConnected) { closeFloating(false); return; }
    const margin = 12, anchor = trigger.getBoundingClientRect();
    panel.style.maxHeight = Math.max(80, innerHeight - margin * 2) + 'px';
    const box = panel.getBoundingClientRect();
    const left = Math.max(margin, Math.min(anchor.right - box.width, innerWidth - box.width - margin));
    const below = anchor.bottom + 8;
    const above = anchor.top - box.height - 8;
    const top = below + box.height <= innerHeight - margin ? below
      : above >= margin ? above : Math.max(margin, innerHeight - box.height - margin);
    panel.style.setProperty('left', left + 'px', 'important'); panel.style.setProperty('top', top + 'px', 'important');
  }
  function closeFloating(returnFocus = true) {
    if (!floating) return;
    const { trigger, panel, observer, removalObserver, portal, originParent, originNext } = floating;
    floating = null; observer.disconnect(); removalObserver.disconnect(); panel.hidden = true;
    if (portal && originParent?.isConnected) {
      originParent.insertBefore(panel, originNext?.parentNode === originParent ? originNext : null);
    }
    trigger.setAttribute('aria-expanded', 'false');
    if (returnFocus && trigger.isConnected) trigger.focus({ preventScroll: true });
  }
  function toggleFloating(trigger, panel, portal = false) {
    if (floating?.trigger === trigger) { closeFloating(); return; }
    closeFloating(false);
    const originParent = panel.parentNode, originNext = panel.nextSibling;
    if (portal) (trigger.closest('dialog[open],.prism-dialog') || document.body).append(panel);
    panel.hidden = false; trigger.setAttribute('aria-expanded', 'true');
    const observer = new ResizeObserver(positionFloating);
    const removalObserver = new MutationObserver(() => { if (!trigger.isConnected && floating?.trigger === trigger) closeFloating(false); });
    removalObserver.observe(document.body, { childList: true, subtree: true });
    floating = { trigger, panel, observer, removalObserver, portal, originParent, originNext }; observer.observe(panel); positionFloating();
  }
  function menuDisclosure(trigger, panel) {
    if (!panel.id) panel.id = 'prismMenu' + (++floatingSequence);
    trigger.setAttribute('aria-controls', panel.id);
    trigger.addEventListener('click', event => {
      event.preventDefault(); toggleFloating(trigger, panel, true);
    });
    const navigate = event => {
      if (!['ArrowDown','ArrowUp','Home','End'].includes(event.key)) return;
      event.preventDefault();
      if (panel.hidden) toggleFloating(trigger, panel, true);
      const items = [...panel.querySelectorAll('a[href],button:not(:disabled)')];
      if (!items.length) return;
      const current = items.indexOf(document.activeElement);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
        : current < 0 ? (event.key === 'ArrowUp' ? items.length - 1 : 0)
        : (current + (event.key === 'ArrowUp' ? -1 : 1) + items.length) % items.length;
      items[next].focus();
    };
    trigger.addEventListener('keydown', navigate); panel.addEventListener('keydown', navigate);
    return { close(focus = false) { if (floating?.panel === panel) closeFloating(focus); } };
  }
  document.addEventListener('click', event => {
    if (floating && !floating.panel.contains(event.target) && !floating.trigger.contains(event.target))
      closeFloating(floating.panel.contains(document.activeElement));
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Tab' && floating) {
      const { trigger, panel } = floating;
      const items = [...panel.querySelectorAll('button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),textarea:not(:disabled)')].filter(item => !item.closest('[hidden]'));
      if (document.activeElement === trigger && !event.shiftKey && items.length) { event.preventDefault(); items[0].focus(); }
      else if (document.activeElement === items[0] && event.shiftKey) { event.preventDefault(); trigger.focus(); }
      else if (document.activeElement === items.at(-1) && !event.shiftKey) {
        event.preventDefault(); closeFloating();
        const all = [...document.querySelectorAll('button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),textarea:not(:disabled)')].filter(item => item.getClientRects().length);
        all[all.indexOf(trigger) + 1]?.focus();
      }
    }
    if (event.key === 'Escape' && floating && !document.querySelector('dialog[open], .prism-modal')) {
      event.preventDefault(); event.stopPropagation(); closeFloating();
    }
  });
  document.addEventListener('focusin', event => {
    if (floating && !floating.panel.contains(event.target) && !floating.trigger.contains(event.target)) closeFloating(false);
  });
  window.addEventListener('resize', positionFloating);
  document.addEventListener('scroll', event => {
    if (floating && !floating.panel.contains(event.target)) positionFloating();
  }, true);

  // Shared document controls keep the existing authenticated URL and handlers.
  function documentAction(control, kind, label) {
    control.classList.add('prism-document-action');
    control.dataset.documentAction = kind;
    control.title = label;
    control.setAttribute('aria-label', label);
    control.innerHTML = documentActionIcon(kind);
    return control;
  }
  function documentActionIcon(kind) {
    const path = kind === 'download' ? 'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4'
      : 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'+path+'"/></svg>';
  }
  function documentActionsHtml(doc) {
    const id = encodeURIComponent(doc.id);
    const preview = ['application/pdf','image/png','image/jpeg'].includes(doc.mime)
      ? 'document_preview.php?id='+id : 'documents_api.php?action=file&download=1&id='+id;
    return '<a class="prism-document-action" data-document-action="view" title="View document" aria-label="View '+esc(doc.originalName||'document')+'" target="_blank" rel="noopener" href="'+preview+'">'+documentActionIcon('view')+'</a>'
      + '<a class="prism-document-action" data-document-action="download" title="Download document" aria-label="Download '+esc(doc.originalName||'document')+'" href="documents_api.php?action=file&download=1&id='+id+'">'+documentActionIcon('download')+'</a>';
  }

  /** Consolidate existing authorized controls without recreating their handlers. */
  function actionMenu(host, { primary = null, label = 'Actions' } = {}) {
    const quick = [...host.children].filter(e => e.matches('a[href*="document_preview.php"],a[href*="documents_api.php?action=file"],.prism-document-action'));
    quick.forEach((control,index) => documentAction(control, control.dataset.documentAction || (/download/i.test(control.title || '') ? 'download' : 'view'), control.getAttribute('aria-label') || control.title || 'View document'));
    quick.sort((a,b)=>(a.dataset.documentAction==='download')-(b.dataset.documentAction==='download')).forEach(control=>host.append(control));
    const items = [...host.children].filter(e => e.matches('button,a') && e !== primary && !quick.includes(e));
    if (!items.length || host.querySelector('.prism-action-menu')) return;
    const wrap = document.createElement('div'); wrap.className = 'prism-action-menu';
    const trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'prism-btn prism-btn-neutral prism-action-trigger';
    trigger.textContent = '...';
    trigger.setAttribute('aria-label', label);
    trigger.title = label;
    const panel = document.createElement('div'); panel.id = 'prismActions' + (++floatingSequence);
    panel.className = 'prism-action-panel'; panel.hidden = true;
    panel.setAttribute('role', 'group'); panel.setAttribute('aria-label', 'Record actions');
    trigger.setAttribute('aria-controls', panel.id); trigger.setAttribute('aria-expanded', 'false');
    items.forEach(item => {
      const name = item.textContent.trim() || item.getAttribute('aria-label')?.split('. ')[0] || item.title || 'View';
      item.append(document.createTextNode(item.textContent.trim() ? '' : name));
      if (!item.querySelector('i,svg')) {
        const icon = document.createElementNS('http://www.w3.org/2000/svg','svg');
        icon.setAttribute('viewBox','0 0 24 24'); icon.setAttribute('width','16'); icon.setAttribute('height','16'); icon.setAttribute('aria-hidden','true');
        const path = document.createElementNS('http://www.w3.org/2000/svg','path');
        path.setAttribute('d', /download/i.test(name) ? 'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4' : /history|version/i.test(name) ? 'M12 8v5l4 2M3 12a9 9 0 1 0 3-7M3 3v5h5' : /override/i.test(name) ? 'm12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z' : /delete|archive/i.test(name) ? 'M4 7h16M7 7v14h10V7M9 3h6v4' : 'M4 12s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6Zm6 0a2 2 0 1 0 4 0 2 2 0 1 0-4 0');
        path.setAttribute('fill','none');path.setAttribute('stroke','currentColor');path.setAttribute('stroke-width','1.7');icon.append(path);item.prepend(icon);
      }
      item.classList.add('prism-action-item');
      // Menu rows must shed compact icon-button sizing before gaining a label.
      item.classList.remove('is-icon');
      item.querySelectorAll('i,svg').forEach(icon=>icon.setAttribute('aria-hidden','true'));
      if (/archive|delete|purge|deny|cancel|remove.*hold|override/i.test(name)) item.classList.add('prism-action-danger');
      item.addEventListener('click', () => {
        // Focus the stable trigger before the existing handler opens a dialog.
        closeFloating();
      }, true);
      panel.append(item);
    });
    wrap.append(trigger, panel); host.append(wrap);
    trigger.addEventListener('click', () => toggleFloating(trigger, panel, true));
    const navigateActions = event => {
      if (!['ArrowDown','ArrowUp','Home','End'].includes(event.key)) return;
      event.preventDefault();
      if (panel.hidden) toggleFloating(trigger, panel, true);
      const available = [...panel.querySelectorAll('button:not(:disabled),a[href]')];
      if (!available.length) return;
      const index = available.indexOf(document.activeElement);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? available.length - 1
        : (index + (event.key === 'ArrowUp' ? -1 : 1) + available.length) % available.length;
      available[next].focus();
    };
    wrap.addEventListener('keydown', navigateActions);
    // The open panel is portalled to body, so its keys do not bubble to wrap.
    panel.addEventListener('keydown', navigateActions);
  }

  /** Floating non-modal filters preserve controls, defaults, cascade and Tab order. */
  function filtersDisclosure(host, controls, reload, toolbar) {
    const wrap = document.createElement('div'); wrap.className = 'prism-filter-disclosure';
    const trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'prism-btn';
    trigger.setAttribute('aria-expanded', 'false'); trigger.setAttribute('aria-controls', host.id);
    const compactStudentFilters = document.body.classList.contains('student-records-page') || !!document.querySelector('.ierb-page,.documents-page');
    if (compactStudentFilters) {
      trigger.classList.add('student-filter-action');
      trigger.setAttribute('aria-label', 'Filters');
      const icon = document.createElement('i'); icon.className = 'fa-solid fa-sliders'; icon.setAttribute('aria-hidden', 'true');
      const text = document.createElement('span'); text.className = 'student-filter-text'; text.textContent = 'Filters';
      trigger.append(icon,text);
    }
    host.classList.add('prism-filter-panel'); host.hidden = true;
    const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'prism-btn prism-filter-clear'; clear.textContent = 'Clear Filters';
    host.append(clear);
    host.before(wrap); wrap.append(trigger, host);
    if (toolbar) toolbar.append(wrap);
    const defaults = new Map(controls.map(control => [control, control.tagName === 'SELECT' ? control.options[0]?.value || '' : '']));
    function update() {
      const active = controls.filter(control => control.tagName === 'SELECT' && control.value !== defaults.get(control)).length;
      const label = document.body.classList.contains('student-records-page') ? 'Filters' : 'Filters & Sort';
      if (compactStudentFilters) {
        const accessibleLabel = label + (active ? ' (' + active + ')' : '');
        trigger.setAttribute('aria-label', accessibleLabel);
        trigger.querySelector('.student-filter-text').textContent = accessibleLabel;
      } else trigger.textContent = label + (active ? ' (' + active + ')' : '');
      clear.disabled = !controls.some(control => control.value !== defaults.get(control));
    }
    function close() { if (floating?.panel === host) closeFloating(); else { host.hidden = true; trigger.setAttribute('aria-expanded', 'false'); } }
    trigger.addEventListener('click', () => toggleFloating(trigger, host, true));
    wrap.addEventListener('keydown', event => { if (event.key === 'Escape' && !host.hidden) { event.preventDefault(); event.stopPropagation(); close(); trigger.focus(); } });
    host.addEventListener('keydown', event => { if (event.key === 'Escape' && !host.hidden) { event.preventDefault(); event.stopPropagation(); close(); } });
    clear.addEventListener('click', () => {
      controls.forEach(control => { control.value = defaults.get(control); });
      host.querySelectorAll('.prism-filter-notice').forEach(notice => { notice.hidden = true; });
      host.dispatchEvent(new Event('prism:filters-cleared')); update(); reload();
    });
    controls.forEach(control => control.addEventListener('change', update));
    controls.filter(control => control.tagName === 'INPUT').forEach(control => control.addEventListener('input', update));
    host.addEventListener('prism:filters-updated', update);
    update();
    return { update, trigger };
  }

  // Small helpers shared by the touched record pages.
  function busy(control, label, labelElement = control) {
    if (!control || control.disabled) return null;
    const original = labelElement.innerHTML;
    control.disabled = true;
    control.setAttribute('aria-busy', 'true');
    if(control.classList.contains('prism-invitation-action')) {
      labelElement.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>';
      if(control.classList.contains('prism-action-item')) labelElement.append(document.createTextNode(label));
    }
    else labelElement.textContent = label;
    return () => {
      labelElement.innerHTML = original; control.disabled = false; control.removeAttribute('aria-busy');
      if (control.form) root.PrismForms?.refresh(control.form); // Re-apply validity/changed gating.
    };
  }

  async function runAction(control, label, action) {
    const release = busy(control, label);
    if (!release) return;
    try { return await action(); } finally { release(); }
  }

  function dirtyForm(form) {
    const fields = () => [...form.querySelectorAll('input,select,textarea')].filter(field => !field.disabled && !field.readOnly && field.type !== 'hidden' && !field.hasAttribute('data-prism-ignore-dirty'));
    const value = field => field.type === 'checkbox' || field.type === 'radio' ? field.checked : field.value;
    let changed = false, baseline;
    const clean = () => {
      changed = false; baseline = new Map(fields().map(field => [field,value(field)]));
      if (form.tagName === 'FORM') form.dispatchEvent(new Event('prism:form-clean'));
    };
    const mark = () => { changed = fields().some(field => baseline.has(field) && baseline.get(field) !== value(field)); };
    clean();
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    const warn = event => { if (changed) { event.preventDefault(); event.returnValue = ''; } };
    root.addEventListener('beforeunload', warn);
    return { clean: clean, trackFields: () => { fields().forEach(field => { if (!baseline.has(field)) baseline.set(field,value(field)); }); mark(); },
      dispose: () => { root.removeEventListener('beforeunload', warn); form.removeEventListener('input',mark); form.removeEventListener('change',mark); } };
  }

  function clearFilters(controls, reload) {
    if (controls.length) {
      const reset = document.createElement('button');
      reset.type = 'button'; reset.className = 'prism-btn'; reset.textContent = 'Clear Filters';
      const last = controls[controls.length - 1];
      (last.closest('.management-search') || last.closest('label') || last).insertAdjacentElement('afterend', reset);
      reset.addEventListener('click', () => {
        controls.forEach(control => { if (control.type === 'checkbox') control.checked = false; else if (control.tagName === 'SELECT') control.selectedIndex = 0; else control.value = ''; });
        reload();
      });
    }
  }

  function pageSizeControl(pager, onChange) {
    const label = document.createElement('label'); label.className = 'prism-row-size'; label.append('Rows per page: ');
    const size = document.createElement('select'); size.setAttribute('aria-label', 'Rows per page');
    [10,25,50,100].forEach(value => size.add(new Option(String(value), String(value))));
    label.append(size);
    const footer = document.createElement('div'); footer.className = 'prism-record-footer';
    pager.before(footer); footer.append(label, pager);
    size.addEventListener('change', () => onChange(Number(size.value)));
    return { get value() { return Number(size.value); } };
  }

  // Personal reminders already live in browser storage; paginate that existing array.
  function clientPager(anchor, reload) {
    const count = document.createElement('p'); count.setAttribute('aria-live', 'polite');
    anchor.after(count);
    const pager = recordPager(count, count, [], reload);
    let previousScope;
    return { slice(rows, scope) {
      if (scope !== previousScope) { pager.reset(); previousScope = scope; }
      const page = Math.min(pager.page, Math.max(1, Math.ceil(rows.length / pager.pageSize)));
      pager.render({total: rows.length, page, limit: pager.pageSize});
      return rows.slice((page - 1) * pager.pageSize, page * pager.pageSize);
    } };
  }

  function recordPager(anchor, count, controls, load, disclosure) {
    const pager = document.createElement('nav');
    pager.className = 'prism-pagination';
    pager.setAttribute('aria-label', 'Record pages');
    anchor.insertAdjacentElement('afterend', pager);
    let page = 1;
    let pageSize = 10;
    pageSizeControl(pager, value => { pageSize = value; page = 1; load(); });
    if (disclosure) {
      filtersDisclosure(disclosure.host, controls, () => { page = 1; load(); }, disclosure.toolbar);
      // Search remains primary and has its own clear affordance, including keyboard support.
      controls.filter(control => control.tagName === 'INPUT').forEach(control => {
        if (control.type !== 'checkbox') control.type = 'search';
      });
    } else clearFilters(controls, () => { page = 1; load(); });
    return {
      get page() { return page; },
      get pageSize() { return pageSize; },
      reset: () => { page = 1; },
      loading: () => { count.textContent = 'Loading records...'; pager.querySelectorAll('button').forEach(b => { b.disabled = true; }); },
      error: () => { pager.replaceChildren(); count.textContent = 'Could not load records.'; },
      render: data => { page = Number(data.page) || 1; pagination(pager, count, data, next => { page = next; load(); }, 'records'); }
    };
  }

  // Shared progress identity uses the same avatar/name/label hierarchy as management cards.
  function recordInitials(name, fallback='Student') {
    const parts=String(name||fallback).trim().split(/\s+/).filter(Boolean);
    return (Array.from(parts[0]||fallback)[0]+(parts.length>1?Array.from(parts[parts.length-1])[0]:'')).toLocaleUpperCase();
  }

  function progressIdentityHtml(record) {
    const name = record.name || 'Student name unavailable';
    const initials = recordInitials(record.name);
    return (record.research ? '<strong class="ierb-research-title">'+esc(record.research)+'</strong><small class="prism-sub">'+'Protocol Code: '+esc(record.protocolCode || 'Not yet assigned')+'</small>' : '') + '<div class="ierb-card-identity"><span class="ierb-field-label">Student</span><div class="ierb-student-head"><span class="ierb-student-avatar" aria-hidden="true">'+esc(initials)+'</span><div><strong class="ierb-student-name">'+esc(name)+'</strong>'
      + (record.protocolCode ? '<small class="ierb-protocol-code">Protocol code: '+esc(record.protocolCode)+'</small>' : '') + '</div></div></div>';
  }

  function recordField(label, value) {
    const field=document.createElement('div'),term=document.createElement('dt'),content=document.createElement('dd');
    term.textContent=label;content.textContent=value==null||String(value).trim()===''?'Not recorded':String(value);
    field.append(term,content);return field;
  }

  // Keep native details/summary keyboard semantics and each renderer's record association.
  function recordDetails(details) {
    if(!details || details.dataset.prismDetails) return;
    details.dataset.prismDetails='true';details.classList.add('prism-details');
    const summary=details.querySelector(':scope > summary');
    const label=document.createElement('span'),chevron=document.createElement('i');
    chevron.className='fa-solid fa-chevron-down';chevron.setAttribute('aria-hidden','true');summary.replaceChildren(label,chevron);
    const update=()=>{label.textContent=details.open?'Hide Details':'Show More Details';summary.setAttribute('aria-expanded',String(details.open));};
    details.addEventListener('toggle',update);update();
  }

  function recordFeedback(record) {
    const remarks=record.reviewRemarks||'', reason=record.rpmsReturnReason||'';
    if(!remarks&&!reason)return null;
    const actionable=!!reason||/revision|resubmission|returned|denied/i.test(record.workflowState||record.reviewStatus||'');
    const note=document.createElement('p');note.className=actionable?'prism-record-feedback':'prism-feedback-indicator';
    note.textContent=actionable?'Required revision / reviewer feedback: '+[...new Set([reason,remarks].filter(Boolean))].join(' · '):'Reviewer feedback available in details';
    return note;
  }

  function compactProgressRecord(row, record, primaryIndex, statusIndex, actionsIndex, summaryIndices, stages=[]) {
    compactRecord(row,primaryIndex,statusIndex,actionsIndex,[],summaryIndices);
    const card=row.querySelector('.prism-record-card');card.classList.add('prism-progress-record');
    const summary=card.querySelector('.prism-record-summary'),fields=[...summary.children],stage=fields[0];
    stage.querySelector('dt').textContent='Current IERB Stage';
    const progress=summary.querySelector('.ierb-card-progress'),progressField=progress.closest('.prism-record-summary > div');
    stage.querySelector('dd').append(progress);progressField.remove();
    const requirements=fields.find(field=>field!==stage&&field!==progressField);
    requirements.querySelector('dt').textContent='Pending Requirements';
    const date=fields.find(field=>field!==stage&&field!==progressField&&field!==requirements);
    if(date){date.querySelector('dt').textContent='Last Submission';date.querySelector('dd').textContent=record.lastSubmissionDate||'Not recorded';}
    else summary.append(recordField('Last Submission',record.lastSubmissionDate));
    const statusLabel=document.createElement('span');statusLabel.className='ierb-field-label';statusLabel.textContent='Workflow Status';card.querySelector('.prism-record-status').prepend(statusLabel);
    const details=card.querySelector('.prism-record-details'),list=details.querySelector('dl');list.classList.add('prism-details-grid');
    const unit=root.PRISM_ACADEMIC_CATALOG?.units?.[record.academicUnitKey]?.label||record.academicUnitKey;
    list.replaceChildren(...[['Student ID',record.studentId],['Program',record.course],['Research Title',record.research],['Protocol Code',record.protocolCode||'Not yet assigned'],['Academic Unit',unit],['Year Level',record.yearLevel],['Academic Year',record.academicYear],['Principal Investigator',record.isPrincipalInvestigator==null?'Not recorded':record.isPrincipalInvestigator?'Yes':'No']].map(([label,value])=>recordField(label,value)));
    // This is the distinct stage journey, not a second Overall Progress metric.
    if(stages.length){const sequence=document.createElement('div'),label=document.createElement('span'),steps=document.createElement('ol');sequence.className='ierb-stage-sequence';label.className='ierb-field-label';label.textContent='IERB stage sequence';steps.setAttribute('aria-label','IERB stage sequence');const current=stages.indexOf(record.stage);stages.forEach((name,index)=>{const step=document.createElement('li');step.textContent=record.v14?.labels?.[name]||(root.PRISM_STAGE_LABELS||{})[name]||name;step.title=name;if(index===current)step.setAttribute('aria-current','step');const conditional=Boolean(record.v14)&&name==='resubmission_amendment';if(conditional){step.textContent+=' (Conditional)';step.className='is-conditional';}else if(current>=0&&index<=current)step.className='is-complete';steps.append(step);});sequence.append(label,steps);details.append(sequence);}
    return card;
  }

  function reportCard(report, actions) {
    const card=document.createElement('article');card.className='prism-record-shell report-result-card';
    const identity=document.createElement('div');identity.className='prism-record-primary';
    const title=document.createElement('strong'),type=document.createElement('small');title.textContent=report.title||report.type||'Untitled report';type.textContent=report.type||'Report';identity.append(title,type);
    const raw=String(report.generated_at||''),parsed=new Date(raw.replace(' ','T'));
    const summary=document.createElement('dl');summary.className='prism-record-summary';summary.append(recordField('Generated',Number.isNaN(parsed.getTime())?raw||'Not recorded':parsed.toLocaleString('en-PH')));
    const status=document.createElement('div');status.className='prism-record-status';const label=document.createElement('span');label.className='prism-record-label';label.textContent='Report status';status.append(label,badgeElement(report.requiresRegeneration?'Regeneration required':'Generated'));
    actions.classList.add('prism-record-actions');
    const details=document.createElement('details');details.className='prism-record-details';details.append(document.createElement('summary'));const fields=document.createElement('dl');fields.className='prism-details-grid';fields.append(recordField('Generated by',report.generated_by),recordField('Report ID',report.id));details.append(fields);recordDetails(details);
    card.append(identity,summary,status,actions,details);return card;
  }

  // Compact presentation reuses the original nodes and bound Actions handlers.
  function compactRecord(row, primaryIndex, statusIndex, actionsIndex, extra = [], summaryIndices = []) {
    const table = row.closest('table');
    if (!table) return;
    const cells = [...row.cells];
    const labels = [...table.querySelectorAll('thead th')].map(cell => cell.textContent.trim());
    table.classList.add('prism-compact-table');
    const host = document.createElement('td'); host.colSpan = cells.length;
    const card = document.createElement('article'); card.className = 'prism-record-card prism-record-shell';
    const primary = document.createElement('div'); primary.className = 'prism-record-primary';
    const status = document.createElement('div'); status.className = 'prism-record-status';
    const actions = document.createElement('div'); actions.className = 'prism-record-actions';
    const metadata = document.createElement('dl');metadata.className = 'prism-record-summary';
    if(summaryIndices.length)card.classList.add('has-summary');
    const details = document.createElement('details'); details.className = 'prism-record-details';
    const summary = document.createElement('summary'); summary.textContent = 'Show details & remarks';
    const fields = document.createElement('dl');
    const append = (container, cell) => { while(cell.firstChild) container.append(cell.firstChild); };
    cells.forEach((cell,index) => {
      if(cell.classList.contains('retention-select')) {const selection=document.createElement('span');selection.className=cell.className;selection.hidden=cell.hidden;append(selection,cell);primary.append(selection);}
      else if(index===primaryIndex) append(primary,cell);
      else if(index===statusIndex) append(status,cell);
      else if(index===actionsIndex) append(actions,cell);
      else { const field=document.createElement('div'), term=document.createElement('dt'),value=document.createElement('dd');term.textContent=labels[index]||'Record information';append(value,cell);field.append(term,value);(summaryIndices.includes(index)?metadata:fields).append(field); }
    });
    // Move dense academic/remarks content below the compact identity line.
    [...primary.querySelectorAll('.academic-record-summary,.adviser-document-meta,.adviser-document-remarks'),...metadata.querySelectorAll('.academic-record-summary')].forEach(content => {const field=document.createElement('div');field.append(content);fields.append(field);});
    extra.forEach(([label,value]) => { const field=document.createElement('div'),term=document.createElement('dt'),content=document.createElement('dd');term.textContent=label;content.textContent=value==null||value===''?'Not recorded':String(value);field.append(term,content);fields.append(field); });
    details.append(summary,fields);
    fields.classList.add('prism-details-grid');recordDetails(details);
    const review=actions.querySelector('[data-review-id],[data-review]');
    const file=actions.querySelector('a[href*="documents_api.php?action=file"]:not([href*="download=1"])');
    if(review && !actions.querySelector('.prism-document-action')){const contextual=document.createElement(review?'button':'a');contextual.className='prism-btn prism-context-action';
      if(review){contextual.type='button';contextual.textContent='Review';contextual.addEventListener('click',()=>review.click());}
      else {contextual.textContent='View';contextual.href=file.href;contextual.target=file.target;contextual.rel='noopener';}
      actions.prepend(contextual);
    }
    card.append(primary);if(summaryIndices.length)card.append(metadata);card.append(status,actions,details);host.append(card);row.replaceChildren(host);
  }
  function statusPills(host, definitions, select, counts = {}) {
    host.classList.add('prism-filter-pills');
    host.setAttribute('aria-label','Status filters');
    host.replaceChildren();
    definitions.forEach(([value,label,keys]) => {
      const button=document.createElement('button');button.type='button';button.className='prism-filter-pill';button.dataset.filterState=value;
      const total=(keys||[value]).reduce((sum,key)=>sum+Number(counts?.[key]||0),0);
      if(host.classList.contains('student-stage-filters')) {
        const count=counts==null?null:value===''?Object.values(counts).reduce((sum,n)=>sum+Number(n),0):total;
        const text=document.createElement('span');text.textContent=label+(count===null?'':' \u00b7 '+count);
        button.append(text);
      } else button.textContent=label+(counts===null?'':' · '+(value===''?Object.values(counts).reduce((sum,n)=>sum+Number(n),0):total));
      button.setAttribute('aria-pressed',String(select.value===value));
      button.addEventListener('click',()=>{select.value=value;select.dispatchEvent(new Event('change',{bubbles:true}));});
      host.append(button);
    });
  }
  // ------------------------------------------------------------------ init
  function init() {
    document.querySelectorAll('[data-prism-tip]').forEach(function (n) {
      n.outerHTML = tip(n.getAttribute('data-prism-tip'));
    });
    document.querySelectorAll('[data-prism-hint]').forEach(function (n) { hint(n, n.getAttribute('data-prism-hint')); });
    document.querySelectorAll('[data-prism-student-workflow]').forEach(mountStudentWorkflow);
    document.querySelectorAll('[data-prism-needs-attention]').forEach(mountNeedsAttention);
    document.querySelectorAll('table[data-prism-responsive], .documents-table, .document-table-wrap table, .data-table').forEach(enhanceTable);
    document.querySelectorAll('table[data-prism-filter]').forEach(function (t) {
      tableFilter(t, { filters: (t.getAttribute('data-prism-filter') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean) });
    });
  }

  const api = {
    badge: badge, badgeElement: badgeElement, docMini: docMini, emptyState: emptyState, tip: tip, toast: toast,
    documentAction: documentAction, documentActionsHtml: documentActionsHtml, progressIdentityHtml: progressIdentityHtml,
    recordField: recordField, recordDetails: recordDetails, recordFeedback: recordFeedback, recordInitials: recordInitials, compactProgressRecord: compactProgressRecord, reportCard: reportCard,
    documentTypes: DOCUMENT_TYPES, actionMenu: actionMenu, compactRecord: compactRecord, statusPills: statusPills,
    actionOrigin: control => control?.closest('.prism-action-menu')?.querySelector('.prism-action-trigger') || control,
    modal: modal, confirm: confirmDialog, request: request, handleSessionExpiry: handleSessionExpiry, debounce: debounce, recordFilters: recordFilters, filtersDisclosure: filtersDisclosure, postJson: postJson, esc: esc, pagination: pagination, pageSizeControl: pageSizeControl, clientPager: clientPager, menuDisclosure: menuDisclosure, paginationWindow: paginationWindow, busy: busy, runAction: runAction, dirtyForm: dirtyForm, recordPager: recordPager, clearFilters: clearFilters,
    confirmDocumentDelete: confirmDocumentDelete,
    changeStudentProgress: changeStudentProgress, manageStages: manageStages, showStageGuidance: showStageGuidance, submitToRpms: submitToRpms, memberSubmitToRpms: memberSubmitToRpms, uploadRevision: uploadRevision, overrideStudent: overrideStudent, overrideDocument: overrideDocument, showVersions: showVersions,
    enhanceTable: enhanceTable, tableFilter: tableFilter, hint: hint,
    mountStudentWorkflow: mountStudentWorkflow, mountNeedsAttention: mountNeedsAttention, init: init,
    BADGES: BADGES
  };
  root.PrismUI = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
  }
})(typeof window !== 'undefined' ? window : globalThis);
