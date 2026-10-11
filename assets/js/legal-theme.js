(() => {
    const button = document.getElementById('legalTheme');
    if (!button) return;
    const sync = () => {
        const label = document.documentElement.classList.contains('dark-theme') ? 'Switch to light mode' : 'Switch to dark mode';
        button.setAttribute('aria-label', label); button.title = label;
    };
    button.addEventListener('click', () => {
        const dark = document.documentElement.classList.toggle('dark-theme');
        try { localStorage.setItem('prismTheme', dark ? 'dark' : 'light'); } catch (_) {}
        sync();
    });
    sync();
})();
