document.getElementById('publicTheme')?.addEventListener('click', () => {
    const dark = document.documentElement.classList.toggle('dark-theme');
    try { localStorage.setItem('prismTheme', dark ? 'dark' : 'light'); } catch (_) {}
});
