(() => {
    'use strict';
    document.querySelector('.data-export-note-close')?.addEventListener('click', event => event.currentTarget.closest('.data-export-note').remove());
    document.querySelectorAll('[data-export-download]').forEach(link => {
        const label = link.querySelector('span'), original = label.textContent;
        let timer;
        link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if (link.classList.contains('is-busy')) { event.preventDefault(); return; }
            clearTimeout(timer);
            link.classList.add('is-busy');
            link.setAttribute('aria-disabled', 'true');
            label.textContent = 'Preparing…';
            timer = setTimeout(() => {
                link.classList.remove('is-busy');
                link.removeAttribute('aria-disabled');
                label.textContent = original;
            }, 4000);
        });
        addEventListener('pageshow', () => { link.classList.remove('is-busy'); link.removeAttribute('aria-disabled'); label.textContent = original; });
    });
})();
