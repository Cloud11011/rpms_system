/* Native file selection and safe, accessible per-file feedback shared by upload surfaces. */
(() => {
  'use strict';
  function render(data) {
    const host = document.getElementById('uploadResults');
    host.replaceChildren();
    const summary = document.createElement('p'); summary.textContent = data.message; host.append(summary);
    if (Array.isArray(data.results)) {
      const list = document.createElement('ul');
      data.results.forEach(result => {
        const item = document.createElement('li');
        item.textContent = `${result.name} — ${result.ok ? 'Uploaded' : 'Failed'}: ${result.message}`;
        list.append(item);
      });
      host.append(list);
    }
    host.focus();
  }
  function prepare(formData, count) {
    formData.set('requestId', crypto.randomUUID());
    formData.set('fileCount', String(count));
    const types = [...document.querySelectorAll('[data-upload-type]')];
    if (types.length === count) {
      formData.delete('documentTypes[]');
      types.forEach(select => formData.append('documentTypes[]', select.value));
    }
    return formData;
  }
  function typeControl(file, index, value, locked = false, availableTypes = null) {
    const label = document.createElement('label'); label.className = 'upload-file-type';
    label.textContent = `Document type for ${file.name}`;
    const select = document.createElement('select'); select.dataset.uploadType = String(index);
    select.dataset.fileKey = `${file.name}:${file.size}:${file.lastModified}`;
    select.setAttribute('aria-label', label.textContent); select.required = true; select.disabled = locked;
    const types = availableTypes === null ? [...PrismUI.documentTypes] : [...availableTypes];
    if (value && !types.includes(value) && availableTypes === null) types.push(value);
    types.forEach(type => select.add(new Option(type, type)));
    select.value = value || types[0]; label.append(select); return label;
  }
  function selectedTypes() {
    return new Map([...document.querySelectorAll('[data-upload-type]')].map(s => [s.dataset.fileKey, s.value]));
  }
  document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('documentFile');
    input?.addEventListener('change', () => {
      const host = document.getElementById('uploadSelection');
      if (document.getElementById('uploadForm')) {
        host.replaceChildren();
        Array.from(input.files).forEach((file, index) => host.append(typeControl(file, index, document.getElementById('documentType').value)));
      }
      document.getElementById('uploadResults').replaceChildren();
    });
  });
  // Queue IDs exist only in this browser session. File metadata is a duplicate-selection
  // hint, never a persisted document identity (the server owns those identities).
  function createQueue() {
    let entries = [];
    return {
      get entries() { return entries; },
      add(files, revisionType = null) {
        if (revisionType && (entries.length + files.length > 1)) return 'A revision accepts one file. Remove the queued file before choosing its replacement.';
        for (const file of files) {
          if (entries.some(e => e.file.name === file.name && e.file.size === file.size && e.file.lastModified === file.lastModified && e.file.type === file.type)) continue;
          entries.push({id: crypto.randomUUID(), file, type: revisionType || '', error: '', uncertain: false});
        }
        return '';
      },
      remove(id) { entries = entries.filter(e => e.id !== id); },
      clear() { entries = []; },
      settle(sent, data) {
        if (!Array.isArray(data?.results) || data.results.length !== sent.length) {
          sent.forEach(e => { e.uncertain = true; e.error = 'Outcome unknown. Check My Documents before retrying this file.'; });
          return;
        }
        data.results.forEach((r, i) => {
          if (r.ok) this.remove(sent[i].id);
          else { sent[i].error = r.message; sent[i].uncertain = Number(r.status) >= 500; }
        });
      },
      payload(fields) {
        const data = new FormData();
        data.set('requestId', crypto.randomUUID()); data.set('fileCount', String(entries.length));
        entries.forEach(e => { data.append('document[]', e.file); data.append('documentTypes[]', e.type); });
        Object.entries(fields).forEach(([key, value]) => data.set(key, value));
        return data;
      }
    };
  }
  window.PrismUpload = {render, prepare, typeControl, selectedTypes, createQueue};
})();
