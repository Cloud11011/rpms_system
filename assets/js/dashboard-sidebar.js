document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.prism-sidebar');
    const toggle = document.getElementById('prismSidebarToggle');
    if (!sidebar || !toggle) return;
    const groups = [...sidebar.querySelectorAll('.prism-nav-group-toggle')];
    const media = window.matchMedia('(max-width: 900px)');
    const submenu = button => document.getElementById(button.getAttribute('aria-controls'));
    function setGroup(button, open) {
        const list = submenu(button);
        if (!open && list.contains(document.activeElement)) button.focus();
        button.setAttribute('aria-expanded', String(open));
        list.hidden = !open;
    }
    function setCollapsed(collapsed, remember = false) {
        if (collapsed && sidebar.contains(document.activeElement) && document.activeElement !== toggle) toggle.focus();
        sidebar.classList.toggle('is-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
        if (collapsed) groups.forEach(button => setGroup(button, false));
        if (remember) { try { sessionStorage.setItem('prismNavigationMinimized', String(collapsed)); } catch (_) {} }
    }
    let collapsed = media.matches;
    if (!media.matches) { try { collapsed = sessionStorage.getItem('prismNavigationMinimized') === 'true'; } catch (_) {} }
    setCollapsed(collapsed);
    toggle.addEventListener('click', () => setCollapsed(toggle.getAttribute('aria-expanded') === 'true', true));
    groups.forEach(button => button.addEventListener('click', () => {
        const open = button.getAttribute('aria-expanded') !== 'true';
        setCollapsed(false, true);
        setGroup(button, open);
    }));
    sidebar.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const group = groups.find(button => button === event.target || submenu(button).contains(event.target));
        if (group && group.getAttribute('aria-expanded') === 'true') {
            setGroup(group, false); group.focus();
        } else { setCollapsed(true, true); toggle.focus(); }
        event.preventDefault();
    });
    media.addEventListener('change', () => setCollapsed(media.matches));
    // Preserve existing profile click handlers; add keyboard access and synchronized ARIA.
    const profile = document.getElementById('profileToggle');
    const menu = document.getElementById('profileMenu');
    if (profile && menu) {
        profile.setAttribute('role', 'button'); profile.tabIndex = 0;
        profile.setAttribute('aria-controls', 'profileMenu');
        const sync = () => { const open = menu.classList.contains('show'); profile.setAttribute('aria-expanded', String(open)); menu.inert = !open; };
        sync(); new MutationObserver(sync).observe(menu, { attributes:true, attributeFilter:['class'] });
        profile.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); profile.click(); }
        });
        menu.addEventListener('keydown', event => {
            if (event.key === 'Escape') { menu.classList.remove('show'); profile.focus(); event.preventDefault(); event.stopPropagation(); }
        });
    }
});
