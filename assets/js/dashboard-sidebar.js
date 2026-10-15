document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.prism-sidebar');
    const toggle = document.getElementById('prismSidebarToggle');
    if (!sidebar || !toggle) return;
    const groups = [...sidebar.querySelectorAll('.prism-nav-group-toggle')];
    const media = window.matchMedia('(max-width: 900px)');
    const submenu = button => document.getElementById(button.getAttribute('aria-controls'));
    const rememberedGroups = new Map();
    let wasCollapsed = false;
    let hoverExpanded = false;
    let hoverTimer;
    const hoverMedia = matchMedia('(hover:hover) and (pointer:fine) and (min-width:901px)');
    const backdrop=document.createElement('div');backdrop.className='prism-sidebar-backdrop';backdrop.hidden=true;backdrop.setAttribute('aria-hidden','true');sidebar.before(backdrop);
    function setGroup(button, open) {
        const list = submenu(button);
        if (!list) return;
        if (!open && list.contains(document.activeElement)) button.focus();
        button.setAttribute('aria-expanded', String(open));
        list.hidden = !open;
    }
    function setCollapsed(collapsed, remember = false) {
        if (collapsed && sidebar.contains(document.activeElement) && document.activeElement !== toggle) toggle.focus();
        sidebar.classList.toggle('is-collapsed', collapsed);
        backdrop.hidden=collapsed;
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
        if (collapsed && !wasCollapsed) groups.forEach(button => {
            rememberedGroups.set(button, button.getAttribute('aria-expanded') === 'true');
            setGroup(button, false);
        });
        if (!collapsed && wasCollapsed) groups.forEach(button => setGroup(button, rememberedGroups.get(button) === true));
        wasCollapsed = collapsed;
        if (remember) { try { sessionStorage.setItem('prismNavigationMinimized', String(collapsed)); } catch (_) {} }
    }
    let collapsed = media.matches;
    if (!media.matches) { try { collapsed = sessionStorage.getItem('prismNavigationMinimized') === 'true'; } catch (_) {} }
    // Apply the saved/mobile state before the sidebar's initial paint.
    sidebar.classList.add('is-initializing');
    setCollapsed(collapsed);
    requestAnimationFrame(() => requestAnimationFrame(() => sidebar.classList.remove('is-initializing')));
    toggle.addEventListener('click', () => {
        clearTimeout(hoverTimer);
        hoverExpanded = false;
        setCollapsed(toggle.getAttribute('aria-expanded') === 'true', true);
    });
    sidebar.addEventListener('pointerenter', event => {
        if (!hoverMedia.matches || event.pointerType === 'touch' || !sidebar.classList.contains('is-collapsed')) return;
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => {
            if (!hoverMedia.matches || !sidebar.matches(':hover')) return;
            hoverExpanded = true;
            setCollapsed(false);
        }, 240);
    });
    const endHover = () => {
        clearTimeout(hoverTimer);
        if (!hoverExpanded || sidebar.contains(document.activeElement)) return;
        hoverTimer = setTimeout(() => {
            if (!sidebar.matches(':hover') && !sidebar.contains(document.activeElement)) {
                hoverExpanded = false;
                setCollapsed(true);
            }
        }, 320);
    };
    sidebar.addEventListener('pointerleave', endHover);
    sidebar.addEventListener('focusout', () => {
        if (!sidebar.matches(':hover')) endHover();
    });
    groups.forEach(button => button.addEventListener('click', () => {
        clearTimeout(hoverTimer);
        hoverExpanded = false;
        const open = button.getAttribute('aria-expanded') !== 'true';
        setCollapsed(false, true);
        setGroup(button, open);
    }));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || event.defaultPrevented) return;
        if (sidebar.classList.contains('is-collapsed') || document.querySelector('dialog[open],.prism-filter-panel:not([hidden]),.prism-action-panel:not([hidden])')) return;
        setCollapsed(true, true); toggle.focus();
        event.preventDefault();
        event.stopPropagation();
    });
    document.addEventListener('click',event=>{
        if(!sidebar.classList.contains('is-collapsed') && !sidebar.contains(event.target) && !event.target.closest('dialog,.prism-filter-panel,.prism-action-panel'))setCollapsed(true,true);
    });
    media.addEventListener('change', () => setCollapsed(media.matches));
});
