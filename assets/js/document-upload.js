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
    return formData;
  }
  document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('documentFile');
    input?.addEventListener('change', () => {
      document.getElementById('uploadSelection').textContent = Array.from(input.files).map(f => `${f.name} (${Math.ceil(f.size / 1024)} KB)`).join('; ');
      document.getElementById('uploadResults').replaceChildren();
    });
  });
  window.PrismUpload = {render, prepare};
})();
