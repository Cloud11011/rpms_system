/* Shared-cookie browser profiles support one active PRISM identity. */
(() => {
  'use strict';
  const generation = window.PRISM_SESSION_GENERATION;
  if (!generation) return;
  let changed = false;
  function ensureStyles() {
    if (document.getElementById('prismSessionAlertStyles')) return;
    const style = document.createElement('style');
    style.id = 'prismSessionAlertStyles';
    style.textContent = `
      #prismSessionChanged {
        position: fixed !important;
        top: 24px !important;
        left: 50% !important;
        right: auto !important;
        bottom: auto !important;
        transform: translateX(-50%) !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        z-index: 100000 !important;
        max-width: min(620px, calc(100vw - 32px)) !important;
        width: max-content !important;
        outline: none !important;
        overflow: visible !important;
        animation: prismAlertDrop 0.32s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
      }
      #prismSessionChanged::backdrop {
        background: rgba(14, 18, 42, 0.42) !important;
        backdrop-filter: blur(4px) !important;
        animation: prismAlertFade 0.22s ease-out forwards !important;
      }
      @keyframes prismAlertDrop {
        from { opacity: 0; transform: translate(-50%, -18px) scale(0.97); }
        to { opacity: 1; transform: translate(-50%, 0) scale(1); }
      }
      @keyframes prismAlertFade {
        from { opacity: 0; }
        to { opacity: 1; }
      }
      .prism-session-alert-card {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px 20px;
        border: 1px solid rgba(181, 20, 110, 0.24);
        border-left: 4px solid #b5146e;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 16px 44px rgba(20, 23, 58, 0.22), 0 2px 10px rgba(0, 0, 0, 0.08);
        font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        color: #14173a;
        box-sizing: border-box;
      }
      .dark-theme .prism-session-alert-card, html.dark-theme #prismSessionChanged .prism-session-alert-card {
        background: #1f1f2e !important;
        border-color: rgba(246, 82, 150, 0.35) !important;
        border-left-color: #f65296 !important;
        color: #f1eef8 !important;
        box-shadow: 0 16px 44px rgba(0, 0, 0, 0.6) !important;
      }
      .prism-session-alert-icon {
        display: flex;
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background: #fce9f3;
        color: #b5146e;
      }
      .dark-theme .prism-session-alert-icon, html.dark-theme #prismSessionChanged .prism-session-alert-icon {
        background: #3d2438 !important;
        color: #f2a9ce !important;
      }
      .prism-session-alert-body {
        flex: 1 1 auto;
        min-width: 0;
        text-align: left;
      }
      .prism-session-alert-body strong {
        display: block;
        font-size: 14.5px;
        font-weight: 800;
        line-height: 1.35;
        color: #14173a;
      }
      .dark-theme .prism-session-alert-body strong, html.dark-theme #prismSessionChanged .prism-session-alert-body strong {
        color: #fff !important;
      }
      .prism-session-alert-body p {
        margin: 3px 0 0 !important;
        font-size: 12.5px !important;
        line-height: 1.45 !important;
        color: #5f5a70 !important;
      }
      .dark-theme .prism-session-alert-body p, html.dark-theme #prismSessionChanged .prism-session-alert-body p {
        color: #b0aabf !important;
      }
      .prism-session-alert-reload {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 40px;
        height: 40px;
        padding: 0 18px;
        border: 0;
        border-radius: 10px;
        background: #b5146e;
        color: #ffffff;
        font-family: inherit;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 4px 12px rgba(181, 20, 110, 0.28);
        transition: transform 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
      }
      .prism-session-alert-reload:hover {
        transform: translateY(-1px);
        background: #960f5a;
        box-shadow: 0 6px 18px rgba(181, 20, 110, 0.42);
      }
      .prism-session-alert-reload:active {
        transform: translateY(0);
      }
      .prism-session-alert-reload:focus-visible {
        outline: 3px solid #f65296;
        outline-offset: 2px;
      }
      @media (max-width: 540px) {
        .prism-session-alert-card {
          flex-direction: column;
          align-items: stretch;
          text-align: center;
          gap: 12px;
          padding: 18px 16px;
        }
        .prism-session-alert-icon {
          margin: 0 auto;
        }
        .prism-session-alert-body {
          text-align: center;
        }
        .prism-session-alert-reload {
          width: 100%;
        }
      }
    `;
    document.head.append(style);
  }
  function check() {
    const current = document.cookie.split('; ').find(c => c.startsWith('prism_generation='))?.slice(17);
    if (current === generation) return !changed;
    changed = true;
    if (!document.getElementById('prismSessionChanged') && document.body) {
      ensureStyles();
      const dialog = document.createElement('dialog');
      dialog.id = 'prismSessionChanged';
      dialog.setAttribute('aria-labelledby', 'prismSessionChangedTitle');
      dialog.innerHTML = `
        <div class="prism-session-alert-card" role="alert">
          <div class="prism-session-alert-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
              <path d="M3 3v5h5"/>
              <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
              <path d="M16 21h5v-5"/>
            </svg>
          </div>
          <div class="prism-session-alert-body">
            <strong id="prismSessionChangedTitle">Session Changed in Another Tab</strong>
            <p>Your PRISM session changed in another tab. Reload this page to continue.</p>
          </div>
          <button type="button" class="prism-session-alert-reload">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
              <path d="M3 3v5h5"/>
              <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
              <path d="M16 21h5v-5"/>
            </svg>
            <span>Reload page</span>
          </button>
        </div>
      `;
      dialog.querySelector('.prism-session-alert-reload').addEventListener('click', () => location.reload());
      dialog.addEventListener('cancel', e => e.preventDefault());
      document.body.append(dialog);
      dialog.showModal();
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
    return originalFetch(input, options).then(async response => {
      if (url.origin === location.origin && response.status === 403
          && response.headers.get('content-type')?.includes('application/json')) {
        const data = await response.clone().json().catch(() => null);
        if (data?.code === 'legal_acceptance_required') location.assign('legal_consent.php');
      }
      return response;
    });
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
