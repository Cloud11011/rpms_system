/* Shell presentation only. Existing page scripts own data, workflow and prismTheme. */
document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    if (!body.classList.contains('prism-workspace')) return;
    const account = document.querySelector('[data-prism-admin-account]');
    const controls = document.querySelector('.top-controls');
    if (account && controls) controls.append(account);
    const utilities = document.querySelector('.portal-nav-right');
    const theme = document.getElementById('themeToggle');
    if (body.classList.contains('portal-shell') && utilities && theme) {
        utilities.insertBefore(theme, utilities.querySelector('.prism-account-menu, .portal-profile-menu'));
    }
    const button = document.querySelector('[data-prism-account-toggle]');
    const links = button && document.getElementById(button.getAttribute('aria-controls'));
    const navButton = document.getElementById('adviserNavigationToggle');
    const nav = navButton && document.getElementById(navButton.getAttribute('aria-controls'));
    const media = window.matchMedia('(max-width: 1120px)');
    function setAccount(open, focus = false) {
        if (!button || !links) return;
        if (!open && (focus || links.contains(document.activeElement))) button.focus();
        button.setAttribute('aria-expanded', String(open));
        links.hidden = !open;
    }
    function setNavigation(open, focus = false) {
        if (!navButton || !nav) return;
        if (!open && media.matches && (focus || nav.contains(document.activeElement))) navButton.focus();
        nav.hidden = media.matches && !open;
        navButton.setAttribute('aria-expanded', String(media.matches && open));
    }
    if (button && links) button.addEventListener('click', () => {
        setNavigation(false);
        setAccount(links.hidden);
    });
    if (navButton && nav) {
        navButton.addEventListener('click', () => { setAccount(false); setNavigation(nav.hidden); });
        nav.addEventListener('click', event => { if (event.target.closest('a')) setNavigation(false); });
        media.addEventListener('change', () => setNavigation(false));
        setNavigation(false);
    }
    document.addEventListener('click', event => {
        if (!event.target.closest('.prism-account-menu')) setAccount(false);
        if (!event.target.closest('.portal-navbar')) setNavigation(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (links && !links.hidden) { setAccount(false, true); event.preventDefault(); }
        else if (nav && media.matches && !nav.hidden) { setNavigation(false, true); event.preventDefault(); }
    });
    document.querySelectorAll('[data-prism-resources-link]').forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        const dashboard = document.querySelector('#portalNav [data-page="dashboard"]');
        if (dashboard) dashboard.click();
        requestAnimationFrame(() => document.getElementById('prismResourcesTitle')?.scrollIntoView({block:'start'}));
    }));
});
