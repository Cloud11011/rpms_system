/* PRISM shared form behavior (Batch 6). Loaded on every page through prism_frontend_styles().
   - Required markers follow the live `required`/`aria-required` state of enabled, editable controls.
   - Student ID fields accept digits only for new values; an unchanged legacy value stays valid.
   - Opt-in gating (`data-prism-gate`) enables submit only when the form is valid (and changed, for "dirty").
   - Opt-in `data-prism-submit-once` prevents duplicate native form submissions.
   Server-side validation remains authoritative; this module only improves feedback. */
(function (root) {
  'use strict';
  const doc = root.document;
  if (!doc || root.PrismForms) return;
  const DIGITS = /^[0-9]+$/;
  const CONTROLS = 'input,select,textarea,[aria-required]';
  const gated = new Set();
  let scheduled = false;

  // ------------------------------------------------------------------ required markers
  function marksRequired(control) {
    if (!(control.required || control.getAttribute('aria-required') === 'true')) return false;
    if (control.disabled || control.readOnly || control.type === 'hidden') return false;
    return !control.closest('[data-prism-no-required-mark]');
  }

  function labelsOf(control) {
    const labels = new Set(control.labels ? [...control.labels] : []);
    (control.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean).forEach(id => {
      const label = doc.getElementById(id);
      if (label && label.tagName !== 'INPUT') labels.add(label);
    });
    return [...labels].filter(label => !label.hasAttribute('data-prism-no-required-mark') && !label.classList.contains('prism-sr-only') && !label.classList.contains('sr-only'));
  }

  // Keep the asterisk on the same line as the label text, even when the label is a
  // flex/grid column that wraps its control: the mark joins the text that precedes the control.
  function placeMark(label, control, mark) {
    if (!(control && label.contains(control) && !['checkbox', 'radio'].includes(control.type))) {
      if (label.lastChild !== mark) label.append(mark);
      return;
    }
    let child = control;
    while (child.parentNode !== label) child = child.parentNode;
    let previous = child.previousSibling;
    while (previous && (previous === mark || (previous.nodeType === 3 && !previous.textContent.trim()))) previous = previous.previousSibling;
    if (previous && previous.nodeType === 3) {
      const text = doc.createElement('span');
      text.className = 'prism-label-text';
      label.insertBefore(text, previous);
      text.append(previous);
      previous = text;
    }
    if (previous && previous.nodeType === 1 && !previous.matches('input,select,textarea,button') && !previous.querySelector('input,select,textarea,button')) {
      if (previous.lastChild !== mark) previous.append(mark);
    } else if (mark.nextSibling !== child) label.insertBefore(mark, child);
  }

  function syncMarkers() {
    const wanted = new Map();
    doc.querySelectorAll(CONTROLS).forEach(control => {
      if (!marksRequired(control)) return;
      labelsOf(control).forEach(label => { if (!wanted.has(label)) wanted.set(label, control); });
    });
    doc.querySelectorAll('.prism-required-mark[data-prism-mark]').forEach(mark => {
      if (!wanted.has(mark.prismLabel || mark.parentElement)) mark.remove();
    });
    wanted.forEach((control, label) => {
      let mark = label.querySelector('.prism-required-mark[data-prism-mark]');
      if (!mark) {
        mark = doc.createElement('span');
        mark.className = 'prism-required-mark';
        mark.dataset.prismMark = '';
        mark.setAttribute('aria-hidden', 'true');
        mark.textContent = '*';
      }
      mark.prismLabel = label;
      placeMark(label, control, mark);
    });
  }

  // ------------------------------------------------------------------ field messages
  function messageFor(control) {
    const id = control.id ? control.id + 'PrismMessage' : '';
    let message = id ? doc.getElementById(id) : null;
    if (!message) {
      message = doc.createElement('span');
      message.className = 'prism-field-message';
      message.setAttribute('aria-live', 'polite');
      if (id) message.id = id;
      const anchor = control.closest('.reg-field,.password-field-wrap,.lifecycle-password') || control;
      anchor.insertAdjacentElement('afterend', message);
      if (id) {
        const described = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (!described.includes(id)) control.setAttribute('aria-describedby', described.concat(id).join(' '));
      }
    }
    return message;
  }

  // kind 'notice' marks a transient explanation (for example a blocked paste). Validation
  // refreshes never clear it; the user's next edit does.
  function showMessage(control, text, tone, kind = 'validity') {
    const message = messageFor(control);
    if (!text && kind !== 'notice' && message.dataset.kind === 'notice' && !message.hidden) return;
    message.textContent = text || '';
    message.hidden = !text;
    message.dataset.tone = tone || 'error';
    message.dataset.kind = kind;
  }

  // ------------------------------------------------------------------ Student ID
  function legacyValue(control) {
    return control.hasAttribute('data-prism-legacy-value') ? control.getAttribute('data-prism-legacy-value') : null;
  }

  function validateStudentId(control) {
    const value = control.value;
    const legacy = legacyValue(control);
    let problem = '';
    if (value !== '' && value !== legacy && !DIGITS.test(value)) problem = 'Student ID must contain numbers only (0-9), with no letters, spaces or symbols.';
    control.setCustomValidity(problem);
    control.setAttribute('aria-invalid', problem ? 'true' : 'false');
    if (problem) showMessage(control, problem, 'error');
    else if (legacy !== null && legacy !== '' && value === legacy && !DIGITS.test(legacy)) showMessage(control, 'This existing ID is retained as recorded. A changed ID must contain numbers only.', 'info');
    else showMessage(control, '', 'error');
    return !problem;
  }

  function insertText(control, text) {
    const start = control.selectionStart ?? control.value.length, end = control.selectionEnd ?? control.value.length;
    control.setRangeText(text, start, end, 'end');
    control.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function enhanceStudentId(control) {
    if (control.dataset.prismStudentIdReady) return;
    control.dataset.prismStudentIdReady = '1';
    control.inputMode = 'numeric';
    control.autocomplete = control.getAttribute('autocomplete') || 'off';
    control.spellcheck = false;
    control.addEventListener('beforeinput', event => {
      if (!['insertText', 'insertReplacementText', 'insertFromDrop', 'insertCompositionText'].includes(event.inputType)) return;
      const text = event.data ?? event.dataTransfer?.getData('text') ?? '';
      if (text && !DIGITS.test(text)) {
        event.preventDefault();
        showMessage(control, 'Student ID accepts numbers only. "' + text.slice(0, 20) + '" was not added.', 'error', 'notice');
      }
    });
    control.addEventListener('paste', event => {
      const raw = event.clipboardData?.getData('text') ?? '';
      const trimmed = raw.trim();
      event.preventDefault();
      if (DIGITS.test(trimmed)) { insertText(control, trimmed); return; }
      showMessage(control, 'The pasted value contains letters, spaces or symbols. Paste a Student ID with numbers only.', 'error', 'notice');
    });
    control.addEventListener('input', () => { showMessage(control, '', 'error', 'notice'); validateStudentId(control); });
    validateStudentId(control);
  }

  // ------------------------------------------------------------------ matching fields
  function validateMatch(control) {
    const other = doc.querySelector(control.getAttribute('data-prism-match'));
    const problem = other && control.value !== '' && control.value !== other.value ? (control.getAttribute('data-prism-match-message') || 'The values do not match.') : '';
    control.setCustomValidity(problem);
    showMessage(control, problem, 'error');
  }

  function enhanceMatch(control) {
    if (control.dataset.prismMatchReady) return;
    control.dataset.prismMatchReady = '1';
    const other = doc.querySelector(control.getAttribute('data-prism-match'));
    control.addEventListener('input', () => validateMatch(control));
    other?.addEventListener('input', () => validateMatch(control));
    control.form?.addEventListener('reset', () => setTimeout(() => validateMatch(control)));
  }

  // ------------------------------------------------------------------ submit gating
  const valueOf = field => field.type === 'checkbox' || field.type === 'radio' ? String(field.checked) : field.type === 'file' ? [...(field.files || [])].map(file => file.name + file.size).join('|') : field.value;
  const trackable = form => [...form.elements].filter(field => field.matches('input,select,textarea') && field.type !== 'hidden' && !field.hasAttribute('data-prism-ignore-dirty'));
  function submitButtons(form) {
    const own = [...form.querySelectorAll('button[type=submit],input[type=submit]')];
    const external = form.id ? [...doc.querySelectorAll('[type=submit][form="' + CSS.escape(form.id) + '"]')] : [];
    // Only buttons that submit this form; a nested button bound to another form (e.g. Log out) is never gated.
    return own.concat(external).filter(button => button.form === form && !button.hasAttribute('data-prism-gate-ignore'));
  }

  function baseline(form) {
    form.prismBaseline = new Map(trackable(form).map(field => [field, valueOf(field)]));
  }

  function statusFor(form, button) {
    let status = form.querySelector('.prism-gate-status');
    if (!status && button) {
      status = doc.createElement('p');
      status.className = 'prism-gate-status';
      status.setAttribute('aria-live', 'polite');
      if (form.id) status.id = form.id + 'GateStatus';
      const holder = button.closest('.management-actions,.wiz-actions,.ierb-modal-actions,.document-modal-actions,.prism-dialog-actions,.form-actions,.account-actions') || button;
      holder.insertAdjacentElement('beforebegin', status);
    }
    return status;
  }

  function gateState(form) {
    const fields = trackable(form);
    const invalid = fields.find(field => field.willValidate && !field.validity.valid && !field.closest('[hidden]'));
    const hiddenInvalid = fields.find(field => field.willValidate && !field.validity.valid);
    if (invalid || hiddenInvalid) {
      const field = invalid || hiddenInvalid;
      if (field.validity.valueMissing) return { ok: false, reason: 'Complete the required fields marked * to continue.' };
      return { ok: false, reason: field.validationMessage || 'Correct the highlighted field to continue.' };
    }
    if (form.dataset.prismGate === 'dirty') {
      const map = form.prismBaseline || new Map();
      const changed = fields.some(field => !map.has(field) || map.get(field) !== valueOf(field));
      if (!changed) return { ok: false, reason: form.dataset.prismGateUnchanged || 'Make a change to enable saving.' };
    }
    const custom = typeof form.prismGateCheck === 'function' ? form.prismGateCheck() : '';
    if (custom) return { ok: false, reason: custom };
    return { ok: true, reason: '' };
  }

  function refresh(form) {
    if (!form || !form.hasAttribute || !form.hasAttribute('data-prism-gate')) return;
    form.querySelectorAll('[data-prism-student-id]').forEach(validateStudentId);
    form.querySelectorAll('[data-prism-match]').forEach(validateMatch);
    const state = gateState(form);
    const buttons = submitButtons(form);
    const pending = form.dataset.prismPending === '1';
    buttons.forEach(button => {
      if (button.getAttribute('aria-busy') === 'true' || pending) return;
      if (!state.ok && !button.disabled) { button.disabled = true; button.dataset.prismGateDisabled = '1'; }
      else if (state.ok && button.dataset.prismGateDisabled) { button.disabled = false; delete button.dataset.prismGateDisabled; }
    });
    const status = statusFor(form, buttons[0]);
    if (status) {
      status.textContent = pending ? '' : state.reason;
      status.hidden = !status.textContent;
      buttons.forEach(button => {
        const described = (button.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (status.id && !described.includes(status.id)) button.setAttribute('aria-describedby', described.concat(status.id).join(' '));
      });
    }
  }

  function enhanceGate(form) {
    if (gated.has(form)) return;
    gated.add(form);
    baseline(form);
    const update = () => refresh(form);
    ['input', 'change', 'focusin'].forEach(type => form.addEventListener(type, update));
    form.addEventListener('reset', () => setTimeout(update));
    form.addEventListener('prism:form-clean', () => { baseline(form); update(); });
    update();
  }

  // ------------------------------------------------------------------ duplicate native submissions
  function submitOnce(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-prism-submit-once')) return;
    if (event.defaultPrevented) return; // A script handles this submission itself.
    if (form.dataset.prismPending === '1') { event.preventDefault(); return; }
    form.dataset.prismPending = '1';
    const buttons = submitButtons(form);
    setTimeout(() => buttons.forEach(button => {
      button.dataset.prismOriginalLabel = button.innerHTML;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      if (button.dataset.busyLabel) button.textContent = button.dataset.busyLabel;
    }));
  }

  root.addEventListener('pageshow', () => {
    doc.querySelectorAll('form[data-prism-submit-once][data-prism-pending="1"]').forEach(form => {
      delete form.dataset.prismPending;
      submitButtons(form).forEach(button => {
        if (button.dataset.prismOriginalLabel) button.innerHTML = button.dataset.prismOriginalLabel;
        button.disabled = false; button.removeAttribute('aria-busy');
      });
      refresh(form);
    });
  });

  // ------------------------------------------------------------------ lifecycle
  function scan() {
    scheduled = false;
    doc.querySelectorAll('input[data-prism-student-id]').forEach(enhanceStudentId);
    doc.querySelectorAll('input[data-prism-match]').forEach(enhanceMatch);
    doc.querySelectorAll('form[data-prism-gate]').forEach(enhanceGate);
    syncMarkers();
    gated.forEach(form => { if (form.isConnected) refresh(form); else gated.delete(form); });
  }

  function schedule() {
    if (scheduled) return;
    scheduled = true;
    (root.requestAnimationFrame || setTimeout)(scan);
  }

  function start() {
    scan();
    new MutationObserver(records => {
      // Ignore the module's own marker and message insertions to keep the observer quiet.
      if (records.every(record => record.type === 'childList' && [...record.addedNodes, ...record.removedNodes].every(node => (node.nodeType === 1 && node.matches('.prism-required-mark,.prism-field-message,.prism-gate-status,.prism-label-text'))
        || (node.nodeType === 3 && node.parentNode?.classList?.contains('prism-label-text'))))) return;
      schedule();
    }).observe(doc.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['required', 'disabled', 'readonly', 'aria-required', 'data-prism-gate', 'data-prism-legacy-value'] });
  }

  doc.addEventListener('submit', submitOnce);
  root.PrismForms = {
    refresh: form => { if (form) { refresh(form); syncMarkers(); } else scan(); },
    markClean: form => { if (form) form.dispatchEvent(new Event('prism:form-clean')); },
    setLegacyValue(control, value) {
      if (!control) return;
      if (value === null || value === undefined || value === '') control.removeAttribute('data-prism-legacy-value');
      else control.setAttribute('data-prism-legacy-value', String(value));
      enhanceStudentId(control);
      validateStudentId(control);
    },
    isStudentId: value => DIGITS.test(String(value)),
    scan,
  };
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start);
  else start();
})(typeof window !== 'undefined' ? window : globalThis);
