/* PRISM — Upload documents modal: stage checklist + multiple files.
 *
 * Needs: prism-ui.js (PrismUI.request, PrismUI.toast, PrismUI.documentTypes)
 *        document-upload.js (PrismUpload.createQueue)
 * Server: documents_api.php?action=requirements&studentId=…   (existing V14 checklist)
 *         documents_api.php?action=upload                     (existing multi-file upload)
 *
 * The stage is NOT sent. The server already files every upload under the student's
 * current stage (ierb_v14_upload_stage), so the old "IERB stage" dropdown is gone.
 */
(() => {
  'use strict';

  const MAX_BYTES = 20 * 1024 * 1024;
  const ALLOWED_EXT = ['pdf', 'docx'];
  const STAGE_NUMBER = {
    initial_submission: 'Stage 1',
    resubmission_amendment: 'Stage 2',
    approval_data_gathering: 'Stage 3',
    ierb_completed: 'Stage 4'
  };
  // Checked in order: the more specific keys come first.
  const KEYWORDS = [
    ['form11', 'Form 11 Review Checklist'], ['form12', 'Form 12 Registration and Application Form'],
    ['form13', 'Form 13 Study Protocol Assessment Form'], ['form14', 'Form 14 Informed Consent Assessment Form'],
    ['form330b', 'Form 33-0B - Final Report Form'], ['finalreport', 'Form 33-0B - Final Report Form'],
    ['form33', 'Form 33 - Certificate of Approval'], ['amendment', 'Amendment Form'],
    ['ethics', 'Certification of Ethics'], ['curriculum', 'Curriculum Vitae'], ['vitae', 'Curriculum Vitae'],
    ['questionnaire', 'Data Collection Questionnaire'], ['workflow', 'Diagrammatic Workflow'], ['diagram', 'Diagrammatic Workflow'],
    ['checklist', 'Form 11 Review Checklist'], ['registration', 'Form 12 Registration and Application Form'],
    ['english', 'Informed Consent in English'], ['local', 'Informed Consent in Local Language'],
    ['filipino', 'Informed Consent in Local Language'], ['tagalog', 'Informed Consent in Local Language'],
    ['letter', 'Letter to IERB Chair'], ['payment', 'Payment Receipt'], ['receipt', 'Payment Receipt'],
    ['protocol', 'Study Protocol'], ['certificate', 'Form 33 - Certificate of Approval']
  ];
  const DONE_STATES = ['Accepted', 'Formally Submitted to RPMS'];

  const compact = text => String(text).toLowerCase().replace(/\.[a-z0-9]+$/, '').replace(/[^a-z0-9]/g, '');
  const extOf = name => (String(name).split('.').pop() || '').toLowerCase();
  const size = bytes => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
  const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

  function guessType(fileName, allowed, taken) {
    const name = compact(fileName);
    for (const label of allowed) {           // exact label inside the file name wins
      if (!taken.has(label) && name.includes(compact(label))) return label;
    }
    for (const [key, label] of KEYWORDS) {
      if (name.includes(key) && allowed.includes(label) && !taken.has(label)) return label;
    }
    return '';
  }

  function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    Object.entries(props).forEach(([key, value]) => {
      if (value === null || value === undefined || value === false) return;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
      else if (key in node && key !== 'list') node[key] = value;
      else node.setAttribute(key, value === true ? '' : value);
    });
    children.flat().forEach(child => { if (child !== null && child !== undefined && child !== false) node.append(child); });
    return node;
  }
  const icon = name => el('i', { class: `fa-solid ${name}`, 'aria-hidden': 'true' });

  document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('uploadModal');
    const form = document.getElementById('uploadForm');
    if (!modal || !form || !window.PrismUpload || !window.PrismUI) return;

    const role = document.body.dataset.role || 'admin';
    const isStaff = role === 'admin' || role === 'adviser';
    const $ = selector => modal.querySelector(selector);
    const studentSelect = $('#documentStudent');
    const stageBox = $('[data-upc-stage]');
    const notice = $('[data-upc-notice]');
    const checklistBox = $('[data-upc-checklist]');
    const reqList = $('[data-upc-req-list]');
    const checklistSummary = $('[data-upc-checklist-summary]');
    const checklistNote = $('[data-upc-checklist-note]');
    const drop = $('[data-upc-drop]');
    const picker = $('#upcFileInput');
    const filesBox = $('[data-upc-files]');
    const fileList = $('[data-upc-file-list]');
    const fileCount = $('[data-upc-file-count]');
    const results = $('#uploadResults');
    const status = $('[data-upc-status]');
    const submit = $('[data-upc-submit]');
    const submitLabel = $('[data-upc-submit-label]');

    const queue = window.PrismUpload.createQueue();
    // mode: 'idle' (no student) | 'loading' | 'checklist' (V14) | 'catalog' (legacy/student) | 'closed' (completed) | 'error'
    let state = { mode: isStaff ? 'idle' : 'catalog', allowed: isStaff ? [] : [...PrismUI.documentTypes], rows: [], workflow: null, message: '' };
    let busy = false, loadSeq = 0;

    // ---------------------------------------------------------------- data
    // keepQueue: after an upload, keep failed files and the result message on screen.
    async function loadChecklist(keepQueue = false) {
      const studentId = studentSelect?.value || '';
      const seq = ++loadSeq;
      if (keepQueue !== true) clearQueue();
      if (!studentId) { state = { mode: 'idle', allowed: [], rows: [], workflow: null, message: '' }; render(); return; }
      state = { ...state, mode: 'loading' }; render();
      try {
        const data = await PrismUI.request(`documents_api.php?action=requirements&studentId=${encodeURIComponent(studentId)}`);
        if (seq !== loadSeq) return;
        if (!data.enrolled) {
          state = { mode: 'error', allowed: [], rows: [], workflow: null,
            message: 'Research setup is pending. RPMS must confirm the V14 project before documents can be uploaded.' };
        } else {
          const allowed = Array.isArray(data.uploadTypes) ? data.uploadTypes : [];
          const stageKey = data.workflow?.stage;
          const rows = (data.requirements || []).filter(r => r.stage === stageKey || allowed.includes(r.label));
          // Correction cases can request documents that are not in the fixed list.
          allowed.filter(t => !rows.some(r => r.label === t)).forEach(t => rows.push({ label: t, state: 'Requested in correction case' }));
          state = { mode: stageKey === 'ierb_completed' ? 'closed' : 'checklist', allowed, rows, workflow: data.workflow, message: '' };
        }
      } catch (error) {
        if (seq !== loadSeq) return;
        state = { mode: 'error', allowed: [], rows: [], workflow: null, message: error.message };
      }
      render();
    }

    // ---------------------------------------------------------------- queue
    function clearQueue() { queue.clear(); results.replaceChildren(); }

    function addFiles(fileListLike, forcedType = '') {
      if (!canAddFiles()) return;
      const files = Array.from(fileListLike || []);
      if (!files.length) return;
      results.replaceChildren();
      if (forcedType) queue.entries.filter(e => e.type === forcedType).forEach(e => queue.remove(e.id));
      const before = new Set(queue.entries.map(e => e.id));
      queue.add(files);
      const taken = new Set(queue.entries.filter(e => before.has(e.id)).map(e => e.type).filter(Boolean));
      queue.entries.filter(e => !before.has(e.id)).forEach(entry => {
        const ext = extOf(entry.file.name);
        if (!ALLOWED_EXT.includes(ext)) entry.error = 'Only PDF or DOCX files can be uploaded.';
        else if (entry.file.size > MAX_BYTES) entry.error = 'This file is larger than 20 MB.';
        else if (entry.file.size === 0) entry.error = 'This file is empty.';
        if (entry.error) { entry.local = true; return; }
        entry.type = forcedType && !taken.has(forcedType) ? forcedType : guessType(entry.file.name, state.allowed, taken);
        if (entry.type) taken.add(entry.type);
      });
      render();
    }

    const canAddFiles = () => !busy && (state.mode === 'checklist' || state.mode === 'catalog') && state.allowed.length > 0;

    function duplicateTypes() {
      const seen = new Map();
      queue.entries.forEach(e => { if (e.type && !e.local) seen.set(e.type, (seen.get(e.type) || 0) + 1); });
      return new Set([...seen].filter(([, n]) => n > 1).map(([t]) => t));
    }

    // ---------------------------------------------------------------- render
    function render() {
      renderStage(); renderNotice(); renderChecklist(); renderFiles(); renderFooter();
      drop.classList.toggle('is-disabled', !canAddFiles());
      picker.disabled = !canAddFiles();
    }

    function renderStage() {
      stageBox.replaceChildren();
      if (!isStaff) { stageBox.append(el('span', { class: 'upc-stage-muted', text: 'Your current stage' })); return; }
      if (state.mode === 'idle') { stageBox.append(el('span', { class: 'upc-stage-muted', text: 'Choose a student first' })); return; }
      if (state.mode === 'loading') { stageBox.append(el('span', { class: 'upc-stage-muted', text: 'Checking…' })); return; }
      if (state.workflow) {
        stageBox.append(el('span', { class: 'upc-stage-pill', text: STAGE_NUMBER[state.workflow.stage] || 'Stage' }),
          el('span', { class: 'upc-stage-name', text: state.workflow.stageLabel || '' }));
        return;
      }
      stageBox.append(el('span', { class: 'upc-stage-muted', text: 'Student’s current stage' }));
    }

    function renderNotice() {
      notice.replaceChildren();
      if (state.mode === 'closed') {
        notice.append(el('div', { class: 'upc-notice is-success' }, icon('fa-circle-check'),
          el('span', {}, el('strong', { text: 'This project is completed. ' }), 'Its documents are read-only, so there’s nothing left to upload.')));
      } else if (state.mode === 'error') {
        notice.append(el('div', { class: 'upc-notice is-error' }, icon('fa-circle-exclamation'),
          el('span', { text: state.message || 'The checklist could not be loaded.' }),
          el('button', { type: 'button', class: 'upc-link', text: 'Try again', onclick: () => loadChecklist() })));
      } else if (state.message) {
        notice.append(el('div', { class: 'upc-notice' }, icon('fa-circle-info'), el('span', { text: state.message })));
      } else if (state.mode === 'checklist' && !state.allowed.length) {
        notice.append(el('div', { class: 'upc-notice' }, icon('fa-circle-info'),
          el('span', { text: 'Nothing can be uploaded at this stage right now. Check the student’s requirements for what RPMS is waiting on.' })));
      }
    }

    function renderChecklist() {
      checklistBox.hidden = state.mode !== 'checklist' || !state.rows.length;
      if (checklistBox.hidden) return;
      const attached = new Map(queue.entries.filter(e => e.type && !e.error).map(e => [e.type, e.file.name]));
      const accepted = state.rows.filter(r => r.state === 'Accepted').length;
      checklistSummary.textContent = `${accepted} of ${state.rows.length} accepted · ${attached.size} attached now`;
      reqList.replaceChildren(...state.rows.map(row => {
        const file = attached.get(row.label);
        const done = DONE_STATES.includes(row.state);
        const canAttach = state.allowed.includes(row.label) && !done;
        const input = el('input', { type: 'file', accept: '.pdf,.docx', class: 'upc-row-input',
          'aria-label': `${file ? 'Replace file for' : 'Attach file for'} ${row.label}`,
          onchange: e => { addFiles(e.target.files, row.label); e.target.value = ''; } });
        input.disabled = !canAddFiles();
        return el('li', { class: `upc-req${file ? ' is-attached' : ''}${done ? ' is-done' : ''}` },
          el('span', { class: 'upc-req-text' },
            el('span', { class: 'upc-req-name', text: row.label }),
            el('span', { class: 'upc-req-state', text: file ? `Attached: ${file}` : row.state })),
          canAttach ? el('label', { class: 'upc-attach' }, icon(file ? 'fa-rotate' : 'fa-plus'), file ? 'Replace' : 'Attach', input) : null);
      }));
      const later = state.workflow?.stage === 'approval_data_gathering' && !state.allowed.includes('Form 33-0B - Final Report Form');
      checklistNote.hidden = !later;
      checklistNote.textContent = later ? 'Form 33-0B – Final Report Form appears here after RPMS accepts Form 33.' : '';
    }

    function renderFiles() {
      const entries = queue.entries;
      filesBox.hidden = !entries.length;
      fileCount.textContent = entries.length ? `(${entries.length})` : '';
      const dupes = duplicateTypes();
      fileList.replaceChildren(...entries.map(entry => {
        const dup = entry.type && dupes.has(entry.type);
        const needsType = !entry.error && !entry.type;
        const tone = entry.error ? 'is-error' : (dup || needsType ? 'is-warning' : '');
        let meta = size(entry.file.size);
        if (entry.error) meta = entry.error;
        else if (dup) meta += ' · Another file already uses this document type';
        else if (needsType) meta += ' · Choose which document this is';
        const select = el('select', { class: 'upc-type', 'aria-label': `Document type for ${entry.file.name}`,
          onchange: e => { entry.type = e.target.value; entry.error = ''; render(); } },
          el('option', { value: '', text: 'Choose document type' }),
          state.allowed.map(t => el('option', { value: t, text: t })));
        select.value = entry.type || '';
        select.disabled = busy;
        return el('li', { class: `upc-file ${tone}` },
          el('span', { class: 'upc-file-ext', 'aria-hidden': 'true', text: (extOf(entry.file.name) || 'file').toUpperCase().slice(0, 4) }),
          el('span', { class: 'upc-file-text' },
            el('span', { class: 'upc-file-name', text: entry.file.name }),
            el('span', { class: 'upc-file-meta', text: meta })),
          entry.local ? null : select,
          el('button', { type: 'button', class: 'upc-remove', 'aria-label': `Remove ${entry.file.name}`, disabled: busy,
            onclick: () => { queue.remove(entry.id); render(); } }, icon('fa-xmark')));
      }));
    }

    function readiness() {
      const entries = queue.entries;
      const blocked = entries.filter(e => e.local).length;
      const untyped = entries.filter(e => !e.local && !e.type).length;
      const dupes = duplicateTypes().size;
      const sendable = entries.filter(e => !e.local);
      return { entries, blocked, untyped, dupes, sendable,
        ready: canAddFiles() && sendable.length > 0 && !blocked && !untyped && !dupes && !entries.some(e => e.uncertain) };
    }

    function renderFooter() {
      const r = readiness();
      let text = 'Add at least one file.', tone = '';
      if (isStaff && state.mode === 'idle') text = 'Choose a student first.';
      else if (state.mode === 'loading') text = 'Loading the stage checklist…';
      else if (state.mode === 'closed') text = 'Nothing to upload for a completed project.';
      else if (state.mode === 'error') { text = 'The checklist could not be loaded.'; tone = 'is-error'; }
      else if (r.blocked) { text = `${plural(r.blocked, 'file', 'files')} can’t be uploaded. Remove ${r.blocked === 1 ? 'it' : 'them'} to continue.`; tone = 'is-error'; }
      else if (r.dupes) { text = 'Two files use the same document type. Change or remove one.'; tone = 'is-error'; }
      else if (r.untyped) { text = `${plural(r.untyped, 'file needs', 'files need')} a document type.`; tone = 'is-warning'; }
      else if (r.sendable.length) { text = `${plural(r.sendable.length, 'file', 'files')} ready to upload.`; tone = 'is-ready'; }
      if (busy) { text = `Uploading ${plural(r.sendable.length, 'file', 'files')}…`; tone = ''; }
      status.textContent = text;
      status.className = `upc-footer-status ${tone}`;
      submit.disabled = busy || !r.ready;
      submitLabel.textContent = busy ? 'Uploading…' : (r.sendable.length > 1 ? `Upload ${r.sendable.length} files` : 'Upload');
    }

    function renderResults(data) {
      results.replaceChildren();
      if (!data) return;
      const ok = (data.results || []).filter(x => x.ok).length;
      const failed = (data.results || []).length - ok;
      results.append(el('div', { class: `upc-notice ${failed ? 'is-error' : 'is-success'}` },
        icon(failed ? 'fa-circle-exclamation' : 'fa-circle-check'),
        el('span', { text: failed
          ? `${data.message || ''} The files that failed are still listed above with the reason. Fix them and upload again.`
          : `${data.message || 'Upload complete.'} They’re now waiting for adviser review.` })));
      results.focus();
    }

    // ---------------------------------------------------------------- events
    studentSelect?.addEventListener('change', () => loadChecklist());
    picker.addEventListener('change', () => { addFiles(picker.files); picker.value = ''; });

    ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, e => {
      if (!canAddFiles()) return;
      e.preventDefault(); drop.classList.add('is-over');
    }));
    ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', e => { if (!canAddFiles()) return; e.preventDefault(); addFiles(e.dataTransfer?.files); });

    // Fresh state every time the modal opens; documents.js announces it after the Student list has loaded.
    document.addEventListener('prism:upload-open', () => {
      clearQueue();
      if (isStaff) loadChecklist(); else render();
    });

    form.addEventListener('submit', async event => {
      event.preventDefault();
      const r = readiness();
      if (!r.ready || busy) { render(); return; }
      busy = true; render();
      const sent = [...queue.entries];
      const fields = isStaff ? { studentDbId: studentSelect.value } : {};
      try {
        const data = await PrismUI.request('documents_api.php?action=upload', { method: 'POST', body: queue.payload(fields) });
        queue.settle(sent, data);
        renderResults(data);
        PrismUI.toast(data.message, data.failed ? 'error' : 'success');
      } catch (error) {
        queue.settle(sent, error.data);
        renderResults(error.data?.results ? error.data : { message: `${error.message} Check Documents before trying again; some files may have uploaded.`, results: [{ ok: false }] });
        PrismUI.toast(error.message, 'error');
      } finally {
        busy = false;
        document.dispatchEvent(new CustomEvent('prism:upload-complete'));
        // Refresh the checklist so newly uploaded items show their new state; failed files stay queued.
        if (isStaff && studentSelect.value) await loadChecklist(true);
        else render();
      }
    });

    render();
  });
})();
