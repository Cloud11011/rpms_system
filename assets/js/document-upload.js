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
  function typeControl(file, index, value, locked = false) {
    const label = document.createElement('label'); label.className = 'upload-file-type';
    label.textContent = `Document type for ${file.name}`;
    const select = document.createElement('select'); select.dataset.uploadType = String(index);
    select.dataset.fileKey = `${file.name}:${file.size}:${file.lastModified}`;
    select.setAttribute('aria-label', label.textContent); select.required = true; select.disabled = locked;
    const types = [...PrismUI.documentTypes]; if (value && !types.includes(value)) types.push(value);
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
  window.PrismUpload = {render, prepare, typeControl, selectedTypes};
})();
