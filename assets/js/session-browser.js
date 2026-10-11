/* Shared-cookie browser profiles support one active PRISM identity. */
(() => {
  'use strict';
  const generation = window.PRISM_SESSION_GENERATION;
  if (!generation) return;
  let changed = false;
  function check() {
    const current = document.cookie.split('; ').find(c => c.startsWith('prism_generation='))?.slice(17);
    if (current === generation) return !changed;
    changed = true;
    if (!document.getElementById('prismSessionChanged') && document.body) {
      const dialog = document.createElement('dialog');
      dialog.id = 'prismSessionChanged';
      dialog.setAttribute('aria-labelledby', 'prismSessionChangedTitle');
      dialog.innerHTML = '<h2 id="prismSessionChangedTitle">Your PRISM session changed in another tab. Reload this page to continue.</h2><button type="button">Reload page</button>';
      Object.assign(dialog.style, {maxWidth:'min(520px, calc(100vw - 40px))', padding:'24px', borderRadius:'12px'});
      dialog.querySelector('button').addEventListener('click', () => location.reload());
      dialog.addEventListener('cancel', e => e.preventDefault());
      document.body.append(dialog); dialog.showModal();
    }
    return false;
  }
  const originalFetch = window.fetch.bind(window);
  window.fetch = (input, options = {}) => {
    const url = new URL(input instanceof Request ? input.url : input, location.href);
    const method = (options.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
    if (url.origin === location.origin && !['GET','HEAD','OPTIONS'].includes(method)) {
      if (!check()) return Promise.reject(new Error('Your PRISM session changed in another tab. Reload this page to continue.'));
      const headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));
      headers.set('X-PRISM-Generation', generation);
      options = {...options, headers};
    }
    return originalFetch(input, options);
  };
  document.addEventListener('submit', event => {
    const form = event.target;
    if (form.method.toLowerCase() !== 'post' || new URL(form.action, location.href).origin !== location.origin) return;
    if (!check()) { event.preventDefault(); event.stopImmediatePropagation(); return; }
    let marker = form.querySelector('input[name="prism_generation"]');
    if (!marker) { marker = document.createElement('input'); marker.type='hidden'; marker.name='prism_generation'; form.append(marker); }
    marker.value = generation;
  }, true);
  window.addEventListener('focus', check);
  window.addEventListener('pageshow', check);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
})();
