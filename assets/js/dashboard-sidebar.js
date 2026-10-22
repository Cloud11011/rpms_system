document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.prism-sidebar');
    const toggle = document.getElementById('prismSidebarToggle');
    if (!sidebar || !toggle) return;
    const groups = [...sidebar.querySelectorAll('.prism-nav-group-toggle')];
    const submenu = button => document.getElementById(button.getAttribute('aria-controls'));
    sidebar.querySelectorAll('.prism-sidebar-toggle,.prism-nav-home,.prism-nav-group-toggle').forEach(item => {
        item.title = item.getAttribute('aria-label') || item.textContent.trim();
    });
    groups.forEach(button => button.classList.toggle('has-current-page', !!submenu(button)?.querySelector('[aria-current="page"]')));
    const media = window.matchMedia('(max-width: 900px)');
    const rememberedGroups = new Map();
    const groupAnimations = new WeakMap();
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    let wasCollapsed = false;
    let hoverExpanded = false;
    let hoverTimer;
    const hoverMedia = matchMedia('(hover:hover) and (pointer:fine) and (min-width:901px)');
    const backdrop=document.createElement('div');backdrop.className='prism-sidebar-backdrop';backdrop.hidden=true;backdrop.setAttribute('aria-hidden','true');sidebar.before(backdrop);
    const sidebarStyle = getComputedStyle(sidebar);
    const groupTiming = {
        duration: parseFloat(sidebarStyle.getPropertyValue('--prism-sidebar-duration')) || 300,
        easing: sidebarStyle.getPropertyValue('--prism-sidebar-easing').trim() || 'cubic-bezier(.22,.61,.36,1)'
    };
    function cancelHover() {
        clearTimeout(hoverTimer);
        hoverExpanded = false;
    }
    function setGroup(button, open) {
        const list = submenu(button);
        if (!list) return;
        // A repeated state request must not restart an in-flight group animation.
        if (button.getAttribute('aria-expanded') === String(open) &&
            (groupAnimations.has(list) || list.hidden === !open)) {
            list.inert = !open;
            list.setAttribute('aria-hidden', String(!open));
            return;
        }
        if (!open && list.contains(document.activeElement)) button.focus();
        const height = list.getBoundingClientRect().height;
        const currentStyle = getComputedStyle(list);
        const startOpacity = height ? currentStyle.opacity : '0';
        const startTop = height ? currentStyle.paddingTop : '0px';
        const startBottom = height ? currentStyle.paddingBottom : '0px';
        const startMarginTop = height ? currentStyle.marginTop : '0px';
        const startMarginBottom = height ? currentStyle.marginBottom : '0px';
        groupAnimations.get(list)?.cancel();
        groupAnimations.delete(list);
        button.setAttribute('aria-expanded', String(open));
        list.inert = !open;
        list.setAttribute('aria-hidden', String(!open));
        if (reducedMotion.matches || sidebar.classList.contains('is-initializing')) { list.hidden = !open; return; }
        list.hidden = false;
        const naturalStyle = getComputedStyle(list);
        // Read the natural border-box after cancelling the previous effect. With
        // fixed text wrapping this stays correct even while the rail is moving.
        const naturalHeight = list.getBoundingClientRect().height;
        const animation = list.animate([
            { height: height + 'px', opacity: startOpacity, paddingTop: startTop, paddingBottom: startBottom,
                marginTop: startMarginTop, marginBottom: startMarginBottom },
            { height: (open ? naturalHeight : 0) + 'px', opacity: open ? 1 : 0,
                paddingTop: open ? naturalStyle.paddingTop : '0px', paddingBottom: open ? naturalStyle.paddingBottom : '0px',
                marginTop: open ? naturalStyle.marginTop : '0px', marginBottom: open ? naturalStyle.marginBottom : '0px' }
        ], groupTiming);
        groupAnimations.set(list, animation);
        animation.onfinish = () => {
            if (groupAnimations.get(list) !== animation) return;
            list.hidden = !open; groupAnimations.delete(list);
        };
    }
    function setCollapsed(collapsed, remember = false, fromHover = false) {
        if (!fromHover) cancelHover();
        if (collapsed && sidebar.contains(document.activeElement) && document.activeElement !== toggle) toggle.focus();
        sidebar.classList.toggle('is-collapsed', collapsed);
        sidebar.querySelector('.sidebar-brand-logo')?.setAttribute('aria-hidden', String(collapsed));
        sidebar.querySelector('.sidebar-brand-icon')?.setAttribute('aria-hidden', String(!collapsed));
        sidebar.querySelector('.prism-navigation-caption')?.setAttribute('aria-hidden', String(collapsed));
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
        setCollapsed(toggle.getAttribute('aria-expanded') === 'true', true);
    });
    sidebar.addEventListener('pointerenter', event => {
        clearTimeout(hoverTimer);
        if (!hoverMedia.matches || event.pointerType === 'touch' || !sidebar.classList.contains('is-collapsed')) return;
        hoverTimer = setTimeout(() => {
            if (!hoverMedia.matches || !sidebar.matches(':hover')) return;
            hoverExpanded = true;
            setCollapsed(false, false, true);
        }, 240);
    });
    const endHover = () => {
        clearTimeout(hoverTimer);
        if (!hoverExpanded || sidebar.contains(document.activeElement)) return;
        hoverTimer = setTimeout(() => {
            if (!sidebar.matches(':hover') && !sidebar.contains(document.activeElement)) {
                setCollapsed(true);
            }
        }, 320);
    };
    sidebar.addEventListener('pointerleave', endHover);
    sidebar.addEventListener('focusout', event => {
        // Focus has not reached relatedTarget yet during focusout. Use that
        // destination so moving focus outside can actually schedule collapse.
        if (!sidebar.contains(event.relatedTarget) && !sidebar.matches(':hover')) {
            clearTimeout(hoverTimer);
            if (hoverExpanded) hoverTimer = setTimeout(() => {
                if (!sidebar.matches(':hover') && !sidebar.contains(document.activeElement)) setCollapsed(true);
            }, 320);
        }
    });
    groups.forEach(button => button.addEventListener('click', () => {
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
    reducedMotion.addEventListener('change', () => {
        if (!reducedMotion.matches) return;
        groups.forEach(button => {
            const list = submenu(button);
            if (!list) return;
            groupAnimations.get(list)?.cancel();
            groupAnimations.delete(list);
            list.hidden = button.getAttribute('aria-expanded') !== 'true';
        });
    });
});
