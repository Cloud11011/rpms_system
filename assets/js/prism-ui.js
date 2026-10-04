/* PRISM UI kit - shared behaviour for badges, toasts, confirmations, empty states, tooltips, first-time hints,
   list filters, mobile-friendly tables, and the self-contained widgets:
     [data-prism-student-workflow]   student "Submit to RPMS" panel (add ="compact" for the dashboard version)
     [data-prism-needs-attention]    admin/adviser "Students Needing Attention" list
     [data-prism-hint="id"]          dismissible first-time help (texts live in HINTS below)
     [data-prism-tip="text"]         small "?" tooltip
     table[data-prism-filter="Status,Stage"]   search box + dropdown filters for the listed column headings
     table[data-prism-responsive]    card layout under 760px (also auto-applied to the known document/monitor tables)
   No dependencies. Depends on prism-ui.css and Font Awesome 6 (already loaded by every PRISM page). */
(function (root) {
  'use strict';

  const STAGES = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];

  // label -> [tone, icon]. One place that defines how every status looks across the system.
 const BADGES = {
    // Document workflow
    'Pending Adviser Review':           ['warning', 'fa-hourglass-half'],
    'Ready for Formal RPMS Submission': ['info', 'fa-paper-plane'],
    'Submitted to RPMS':                ['success', 'fa-circle-check'],
    'Needs Revision':                   ['danger', 'fa-rotate-left'],
    'Superseded':                       ['neutral', 'fa-clock-rotate-left'],

    // Document review
    'Submitted':                        ['neutral', 'fa-inbox'],
    'Received':                         ['neutral', 'fa-inbox'],
    'Under Review':                     ['warning', 'fa-magnifying-glass'],
    'Verified':                         ['info', 'fa-clipboard-check'],
    'Resubmission Requested':           ['danger', 'fa-rotate-left'],
    'Approved':                         ['success', 'fa-check'],
    'Denied':                           ['danger', 'fa-xmark'],

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

    // Administrative
    'Admin Override':                   ['override', 'fa-user-shield']
};

  const TIPS = {
    'Pending Adviser Review': 'Waiting for the research adviser to review this document.',
    'Ready for Formal RPMS Submission': 'Approved by the adviser. The student can now submit it to RPMS.',
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
        'When it is approved, click Submit to RPMS. That is the official submission.',
        'Need to fix a file? Upload it again. Earlier versions are kept.'
      ]
    },
    'student-submit': {
      title: 'Before you upload',
      text: 'Upload one file per requirement. Uploading the same document type again for the same stage creates a new version. Your earlier version stays in the history.'
    },
    'adviser-documents': {
      title: 'Reviewing your students\u2019 documents',
      text: 'Approve a document to let the student submit it to RPMS. To ask for changes, deny it or request a resubmission and add a short remark so the student knows what to fix.'
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
  function modal(innerHtml) {
    const previous = document.activeElement;
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
      if (e.key === 'Escape') { e.stopPropagation(); if (api.onCancel) api.onCancel(); close(); return; }
      if (e.key === 'Tab') {
        const f = dlg.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
    document.addEventListener('keydown', onKey, true);
    const api = { overlay: overlay, dialog: dlg, close: close, onCancel: null };
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { if (api.onCancel) api.onCancel(); close(); } });
    return api;
  }

  /**
   * Confirmation dialog. Resolves to null when cancelled, else { reason, values }.
   * o: title, icon, message (plain text), tone (primary|danger|override), confirmText,
   *    reasonLabel + reasonRequired + minReason, extraHtml (trusted markup), collect(dialog), validate(values)
   */
  function confirmDialog(o) {
    return new Promise(function (resolve) {
      const tone = o.tone || 'primary';
      const m = modal(
        '<h3 id="prismDlgTitle">' + (o.icon ? '<i class="fa-solid ' + esc(o.icon) + '" aria-hidden="true"></i>' : '') + esc(o.title) + '</h3>' +
        (o.message ? '<p>' + esc(o.message) + '</p>' : '') +
        (o.extraHtml || '') +
        (o.reasonLabel ? '<label for="prismReason">' + esc(o.reasonLabel) + '</label><textarea id="prismReason" maxlength="500"></textarea>' : '') +
        '<div class="prism-field-error" role="alert"></div>' +
        '<div class="prism-dialog-actions"><button type="button" class="prism-btn is-secondary" data-act="cancel">Cancel</button>' +
        '<button type="button" class="prism-btn is-' + tone + '" data-act="ok">' + esc(o.confirmText || 'Confirm') + '</button></div>'
      );
      m.dialog.setAttribute('aria-labelledby', 'prismDlgTitle');
      const err = m.dialog.querySelector('.prism-field-error');
      const reasonBox = m.dialog.querySelector('#prismReason');
      const dirty = dirtyForm(m.dialog);
      const close = m.close;
      m.close = () => { dirty.clean(); dirty.dispose(); close(); };
      m.onCancel = function () { dirty.clean(); dirty.dispose(); resolve(null); };
      m.dialog.querySelector('[data-act="cancel"]').addEventListener('click', function () { m.close(); resolve(null); });
      m.dialog.querySelector('[data-act="ok"]').addEventListener('click', function () {
        const reason = reasonBox ? reasonBox.value.trim() : '';
        if (o.reasonRequired && reason.length < (o.minReason || 5)) {
          err.textContent = 'Add a reason (at least ' + (o.minReason || 5) + ' characters).';
          reasonBox.focus();
          return;
        }
        const values = o.collect ? o.collect(m.dialog) : {};
        const problem = o.validate ? o.validate(values, reason) : null;
        if (problem) { err.textContent = problem; return; }
        m.close();
        resolve({ reason: reason, values: values });
      });
      (reasonBox || m.dialog.querySelector('select') || m.dialog.querySelector('[data-act="ok"]')).focus();
    });
  }

  // ------------------------------------------------------------------ network
  async function request(url, opts) {
    let res, data = null;
    try {
      res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}));
    } catch (_) {
      throw new Error('Could not reach the server. Check your connection and try again.');
    }
    try { data = await res.json(); } catch (_) { /* handled below */ }
    if (!data) throw new Error('The server sent an unexpected response. Refresh the page and try again.');
    if (!data.ok) {
      const e = new Error(data.message || 'Something went wrong. Please try again.');
      e.data = data;
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
  /** Student (or admin on their behalf): formally submit an approved document. Resolves true when submitted. */
  async function submitToRpms(doc) {
    const res = await confirmDialog({
      title: 'Submit to RPMS', icon: 'fa-paper-plane', confirmText: 'Submit to RPMS',
      message: 'Submit \u201c' + doc.originalName + '\u201d to RPMS? This is the official submission. Once submitted, the file is locked and you can\u2019t change it yourself.'
    });
    if (!res) return false;
    try {
      const data = await postJson('documents_api.php?action=submit_to_rpms&id=' + encodeURIComponent(doc.id), {});
      toast(data.message, 'success');
      changed();
      return true;
    } catch (e) {
      toast(e.message, 'error');
      return false;
    }
  }

  /** Admin: change a student's stage and/or status. Reason is required and logged with name + time. */
  async function overrideStudent(record) {
    const labels = root.PRISM_STAGE_LABELS || {};
    const stageOpts = STAGES.map(function (s) {
      return '<option value="' + esc(s) + '"' + (s === record.stage ? ' selected' : '') + '>' + esc(labels[s] ? labels[s] + ' (' + s + ')' : s) + '</option>';
    }).join('');
    const statusOpts = ['On Track', 'Pending', 'Delayed'].map(function (s) {
      return '<option' + (s === record.status ? ' selected' : '') + '>' + esc(s) + '</option>';
    }).join('');
    const res = await confirmDialog({
      title: 'Admin Override', icon: 'fa-user-shield', tone: 'override', confirmText: 'Save override',
      message: 'You are changing the official IERB progress of ' + record.name + '. This is saved with your name, the time and your reason. The student and adviser are notified.',
      extraHtml: '<label for="prismOvStage">Stage</label><select id="prismOvStage">' + stageOpts + '</select>' +
        '<label for="prismOvStatus">Status</label><select id="prismOvStatus">' + statusOpts + '</select>',
      reasonLabel: 'Reason for this override (required)', reasonRequired: true,
      collect: function (d) { return { stage: d.querySelector('#prismOvStage').value, status: d.querySelector('#prismOvStatus').value }; },
      validate: function (v) { return v.stage === record.stage && v.status === record.status ? 'Change the stage or the status first.' : null; }
    });
    if (!res) return false;
    try {
      const data = await postJson('ierb_api.php?action=override', { id: record.id, stage: res.values.stage, status: res.values.status, reason: res.reason });
      toast(data.message, 'success');
      return true;
    } catch (e) {
      toast(e.message, 'error');
      return false;
    }
  }

  /** Admin: change a document's review status. Reason is required and logged. */
  async function overrideDocument(doc) {
    const statuses = ['Submitted', 'Under Review', 'Received', 'Verified', 'Resubmission Requested', 'Approved', 'Denied'];
    const opts = statuses.map(function (s) { return '<option' + (s === doc.reviewStatus ? ' selected' : '') + '>' + esc(s) + '</option>'; }).join('');
    const res = await confirmDialog({
      title: 'Admin Override', icon: 'fa-user-shield', tone: 'override', confirmText: 'Save override',
      message: 'You are overriding the adviser review of \u201c' + doc.originalName + '\u201d. This is saved with your name, the time and your reason. The student is notified.',
      extraHtml: '<label for="prismOvDoc">New status</label><select id="prismOvDoc">' + opts + '</select>',
      reasonLabel: 'Reason for this override (required)', reasonRequired: true,
      collect: function (d) { return { status: d.querySelector('#prismOvDoc').value }; },
      validate: function (v) { return v.status === doc.reviewStatus ? 'Choose a different status.' : null; }
    });
    if (!res) return false;
    try {
      const data = await postJson('documents_api.php?action=override_review&id=' + encodeURIComponent(doc.id), { status: res.values.status, reason: res.reason });
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
    let data;
    try { data = await request('documents_api.php?action=versions&id=' + encodeURIComponent(docId)); }
    catch (e) { toast(e.message, 'error'); return; }
    const items = data.versions.map(function (v) {
      return '<li><div><strong>Version ' + esc(v.versionNo) + (v.isCurrent ? ' (current)' : '') + '</strong>' +
        '<small>' + esc(v.originalName) + ' \u00b7 uploaded ' + esc(fmtDate(v.uploadedAt)) + ' by ' + esc(v.uploadedBy) + '</small>' +
        (v.reviewRemarks ? '<small>Remarks: ' + esc(v.reviewRemarks) + '</small>' : '') +
        '</div><div class="prism-badges">' + badge(v.workflowState) + (v.adminOverride ? badge('Admin Override') : '') +
        '<a class="prism-btn is-secondary is-sm" target="_blank" rel="noopener" href="documents_api.php?action=file&id=' + encodeURIComponent(v.id) + '">Open</a></div></li>';
    }).join('');
    const m = modal('<h3><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>Version history</h3>' +
      '<ul class="prism-version-list">' + items + '</ul>' +
      '<div class="prism-dialog-actions"><button type="button" class="prism-btn is-secondary" data-act="close">Close</button></div>');
    m.dialog.querySelector('[data-act="close"]').addEventListener('click', m.close);
    m.dialog.querySelector('[data-act="close"]').focus();
  }

  // ------------------------------------------------------------------ widgets
  function goToSubmitPage() {
    const nav = document.querySelector('[data-go="submit"]') || document.querySelector('[data-page="submit"]');
    if (nav) nav.click();
  }

  async function mountStudentWorkflow(host) {
    const compact = host.getAttribute('data-prism-student-workflow') === 'compact';
    host.classList.add('prism-wf-panel');

    async function render() {
      let docs, data;
      try { data = await request('documents_api.php?action=list'); docs = data.documents; }
      catch (e) {
        host.innerHTML = compact ? '' : emptyState({ icon: 'fa-triangle-exclamation', title: 'Couldn\u2019t load your documents', text: e.message });
        return;
      }
      const ready = docs.filter(function (d) { return d.workflowState === 'Ready for Formal RPMS Submission'; });
      const revise = docs.filter(function (d) { return d.workflowState === 'Needs Revision'; });
      const counts = data.counts || {};
      if(!data.counts) docs.forEach(function (d) { counts[d.workflowState] = (counts[d.workflowState] || 0) + 1; });

      let html = '';
      if (!docs.length) {
        html = compact ? '' : emptyState({
          icon: 'fa-file-circle-plus', title: 'You haven\u2019t submitted any documents yet',
          text: 'Start by uploading your first requirement. Your adviser will review it, and you will be notified.',
          actionLabel: 'Submit a document', actionId: 'go-submit'
        });
      } else {
        if (!compact) {
          html += '<div class="prism-wf-summary">' + ['Pending Adviser Review', 'Needs Revision', 'Ready for Formal RPMS Submission', 'Submitted to RPMS']
            .filter(function (s) { return counts[s]; })
            .map(function (s) { return badge(s, { text: counts[s] + ' ' + s }); }).join('') + '</div>';
        }
        if (ready.length || revise.length) {
          html += '<h3 class="prism-wf-title"><i class="fa-solid fa-bolt" aria-hidden="true"></i>Action needed</h3><ul class="prism-wf-list">';
          ready.forEach(function (d) {
            html += '<li class="prism-wf-item"><div><strong>' + esc(d.originalName) + '</strong>' +
              '<div class="prism-badges">' + badge(d.workflowState) + '<span class="prism-sub">' + esc(d.documentType) + ' \u00b7 ' + esc(d.stageLabel) +
              (d.versionNo > 1 ? ' \u00b7 version ' + esc(d.versionNo) : '') + '</span></div>' +
              '<p class="prism-wf-remarks">Approved by ' + esc(d.reviewedBy || 'your adviser') + '. Submit it to RPMS to make it official.</p></div>' +
              '<div class="prism-wf-actions"><button type="button" class="prism-btn is-primary" data-submit="' + esc(d.id) + '"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Submit to RPMS</button>' +
              (d.versionNo > 1 ? '<button type="button" class="prism-btn is-secondary is-sm" data-versions="' + esc(d.id) + '">Version history</button>' : '') + '</div></li>';
          });
          revise.forEach(function (d) {
            html += '<li class="prism-wf-item"><div><strong>' + esc(d.originalName) + '</strong>' +
              '<div class="prism-badges">' + badge(d.workflowState) + '<span class="prism-sub">' + esc(d.documentType) + ' \u00b7 ' + esc(d.stageLabel) + '</span></div>' +
              '<p class="prism-wf-remarks">' + (d.reviewRemarks ? 'Adviser remarks: ' + esc(d.reviewRemarks) : 'Your adviser asked for changes.') + '</p></div>' +
              '<div class="prism-wf-actions"><button type="button" class="prism-btn is-secondary" data-go-submit="1"><i class="fa-solid fa-upload" aria-hidden="true"></i> Upload a corrected version</button>' +
              (d.versionNo > 1 ? '<button type="button" class="prism-btn is-secondary is-sm" data-versions="' + esc(d.id) + '">Version history</button>' : '') + '</div></li>';
          });
          html += '</ul>';
        } else if (!compact) {
          html += '<p class="prism-sub">Nothing needs your action right now.</p>';
        }
      }
      if (data.total > docs.length) html += '<p class="prism-sub"><a href="student.php#documents">View all documents and submission actions</a></p>';
      host.innerHTML = html;

      host.querySelectorAll('[data-submit]').forEach(function (b) {
        b.addEventListener('click', async function () {
          const doc = docs.find(function (d) { return d.id === b.getAttribute('data-submit'); });
          const release = busy(b,'Submitting...');
          if(!release)return;
          const done = await submitToRpms(doc); // on success it fires prism:documents-changed, which re-renders this panel
          if (!done) release();
        });
      });
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
      const title = s.protocolCode || s.groupId || s.studentId;
      return '<li class="prism-attn-item sev-' + s.topSeverity + '"><div class="prism-attn-main"><strong>' + esc(title) + '</strong>' +
        '<span class="prism-sub">' + esc(s.name) + (s.adviser ? ' \u00b7 Adviser: ' + esc(s.adviser) : '') + '</span>' +
        '<ul class="prism-attn-reasons">' + s.reasons.map(function (r) { return '<li>' + esc(r.label) + '</li>'; }).join('') + '</ul></div>' +
        '<div class="prism-badges">' + badge(s.stage, { text: s.stageLabel }) + badge(s.status) + '</div></li>';
    }).join('');
    const more = data.total > data.students.length
      ? '<p class="prism-sub">Showing ' + data.students.length + ' of ' + data.total + '. <a href="ierbprog.php">See all in IERB Progress</a></p>' : '';
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

  // Compact controls shared by server-paginated history views.
  function pagination(host, count, data, onPage, noun = 'entries') {
    const total = Math.max(0, Number(data.total) || 0);
    const limit = 10;
    const pages = Math.max(1, Math.ceil(total / limit));
    const page = Math.max(1, Math.min(pages, Number(data.page) || 1));
    const first = total ? (page - 1) * limit + 1 : 0;
    count.textContent = `Showing ${first}\u2013${Math.min(page * limit, total)} of ${total} ${noun}`;
    const restoreFocus = host.contains(document.activeElement);
    host.replaceChildren();
    const button = (label, target, disabled = false) => {
      const el = document.createElement('button');
      el.type = 'button';
      el.textContent = label;
      el.disabled = disabled;
      if (typeof label === 'number') {
        el.setAttribute('aria-label', `Page ${target}`);
        if (target === page) el.setAttribute('aria-current', 'page');
      }
      el.addEventListener('click', () => { if (target !== page) onPage(target); });
      host.append(el);
    };
    button('Previous', page - 1, page === 1);
    const visible = new Set([1, pages]);
    const start = Math.max(1, Math.min(page - 1, pages - 3));
    const end = Math.min(pages, Math.max(page + 1, 4));
    for (let n = start; n <= end; n++) visible.add(n);
    let previous = 0;
    [...visible].sort((a, b) => a - b).forEach(n => {
      if (n - previous > 1) {
        const gap = document.createElement('span');
        gap.textContent = '\u2026';
        gap.setAttribute('aria-hidden', 'true');
        host.append(gap);
      }
      button(n, n);
      previous = n;
    });
    button('Next', page + 1, page === pages);
    if (restoreFocus) host.querySelector('[aria-current="page"]')?.focus();
  }

  // Small helpers shared by the touched record pages.
  function busy(control, label, labelElement = control) {
    if (!control || control.disabled) return null;
    const original = labelElement.innerHTML;
    control.disabled = true;
    labelElement.textContent = label;
    return () => { labelElement.innerHTML = original; control.disabled = false; };
  }

  async function runAction(control, label, action) {
    const release = busy(control, label);
    if (!release) return;
    try { return await action(); } finally { release(); }
  }

  function dirtyForm(form) {
    const fields = () => [...form.querySelectorAll('input,select,textarea')].filter(field => !field.disabled && !field.readOnly && field.type !== 'hidden');
    const value = field => field.type === 'checkbox' || field.type === 'radio' ? field.checked : field.value;
    let changed = false, baseline;
    const clean = () => { changed = false; baseline = new Map(fields().map(field => [field,value(field)])); };
    const mark = () => { changed = fields().some(field => baseline.has(field) && baseline.get(field) !== value(field)); };
    clean();
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    const warn = event => { if (changed) { event.preventDefault(); event.returnValue = ''; } };
    root.addEventListener('beforeunload', warn);
    return { clean: clean, dispose: () => { root.removeEventListener('beforeunload', warn); form.removeEventListener('input',mark); form.removeEventListener('change',mark); } };
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

  function recordPager(anchor, count, controls, load) {
    const pager = document.createElement('nav');
    pager.className = 'prism-pagination';
    pager.setAttribute('aria-label', 'Record pages');
    anchor.insertAdjacentElement('afterend', pager);
    let page = 1;
    clearFilters(controls, () => { page = 1; load(); });
    return {
      get page() { return page; },
      reset: () => { page = 1; },
      loading: () => { count.textContent = 'Loading records...'; pager.querySelectorAll('button').forEach(b => { b.disabled = true; }); },
      error: () => { pager.replaceChildren(); count.textContent = 'Could not load records.'; },
      render: data => { page = Number(data.page) || 1; pagination(pager, count, data, next => { page = next; load(); }, 'records'); }
    };
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
    confirm: confirmDialog, request: request, postJson: postJson, esc: esc, pagination: pagination, busy: busy, runAction: runAction, dirtyForm: dirtyForm, recordPager: recordPager, clearFilters: clearFilters,
    submitToRpms: submitToRpms, overrideStudent: overrideStudent, overrideDocument: overrideDocument, showVersions: showVersions,
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
