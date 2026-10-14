'use strict';
document.getElementById('themeToggle')?.addEventListener('click', () => {
    const dark = document.documentElement.classList.toggle('dark-theme');
    try { localStorage.setItem('prismTheme', dark ? 'dark' : 'light'); } catch (_) {}
});
document.querySelectorAll('[data-staff-copy]').forEach(button => {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.staffCopy);
        const status = document.getElementById('staffCopyResult');
        try {
            await navigator.clipboard.writeText(target.id === 'staffRegistrationLink' ? target.href : target.textContent.trim());
            status.textContent = target.id === 'staffRegistrationLink' ? 'Registration link copied.' : 'Code copied. Save it securely, then confirm.';
        } catch (_) { status.textContent = 'Copy unavailable. Select the displayed text and copy it manually.'; }
    });
});
window.addEventListener('pagehide', () => { document.getElementById('staffOneTime')?.remove(); });
window.addEventListener('pageshow', event => {
    if (event.persisted) window.location.replace('staff_registration_manage.php');
});
