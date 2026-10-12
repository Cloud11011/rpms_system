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
    const topHost = utilities || controls;
    if (topHost && theme) {
        const group=document.createElement('div');group.className='prism-topbar-group';group.setAttribute('role','group');group.setAttribute('aria-label','Workspace controls');
        const icon=path=>`<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">${path}</svg>`;
        theme.innerHTML=icon('<g class="light-icon"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l2 2m10 10l2 2M5 19l2-2M17 7l2-2"/></g><path class="dark-icon" d="M20 15A9 9 0 0 1 9 3a9 9 0 1 0 11 12Z"/>');
        group.append(theme);
        let notification=topHost.querySelector('.notification-menu-wrap,.portal-notification');
        if(!notification){notification=document.createElement('a');notification.href='admin_notifications.php';notification.className='portal-notification';notification.setAttribute('aria-label','Notifications');notification.title='Notifications';notification.innerHTML=icon('<path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5Z"/><path d="M10 20h4"/>');}
        group.append(notification);
        const bellControl=notification.matches('a,button')?notification:notification.querySelector('button');
        bellControl?.querySelector('i')?.replaceWith((()=>{const span=document.createElement('span');span.innerHTML=icon('<path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5Z"/><path d="M10 20h4"/>');return span;})());
        const tour=document.createElement('button');tour.type='button';tour.title='Quick Tour';tour.setAttribute('aria-label','Quick Tour');tour.dataset.prismTopTour='';tour.innerHTML=icon('<circle cx="12" cy="12" r="9"/><path d="m16 8-2 6-6 2 2-6Z"/>');
        tour.addEventListener('click',()=>document.querySelector('#prismAccountLinks [data-prism-tour],#portalProfileLinks [data-prism-tour]')?.click());group.append(tour);
        const persistence=document.createElement('button');persistence.type='button';persistence.dataset.prismPersistence='';persistence.setAttribute('aria-disabled','true');persistence.setAttribute('aria-label','Keep Me Signed In: unavailable');persistence.title='Keep Me Signed In is unavailable; normal inactivity expiry applies.';persistence.innerHTML=icon('<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z"/><path d="m8 12 3 3 5-6"/>');
        persistence.addEventListener('click',()=>{
            if(!window.PrismReadOnly)return;
            const view=PrismReadOnly.dialog('Keep Me Signed In');view.content.append(PrismReadOnly.node('p','24-hour sign-in persistence is unavailable. Your normal inactivity expiry remains active.'));const close=PrismReadOnly.button('Close',view.close);view.actions.append(close);close.focus();
        });group.append(persistence);
        const profileMenu=topHost.querySelector('.prism-account-menu,.portal-profile-menu');
        if(profileMenu)group.append(profileMenu);
        topHost.append(group);
        document.querySelectorAll('.portal-profile-btn').forEach(profile=>{
            const image=profile.querySelector('img');
            if(!image)return;
            const initials=document.createElement('span');initials.className='prism-initials';initials.setAttribute('aria-hidden','true');if(image.id)initials.id=image.id;image.replaceWith(initials);
            const name=profile.querySelector('strong');
            const update=()=>{const displayName=(name?.textContent||body.dataset.name||'PRISM').trim();const parts=displayName.split(/\s+/).filter(Boolean);initials.textContent=(Array.from(parts[0]||'P')[0]+(parts.length>1?Array.from(parts.at(-1))[0]:'')).toLocaleUpperCase();profile.title=displayName+' · '+(profile.querySelector('small')?.textContent||'Account');profile.setAttribute('aria-label',displayName+' account menu');};update();if(name)new MutationObserver(update).observe(name,{childList:true,characterData:true,subtree:true});
        });
        const updateUnread=value=>{
            if(!bellControl||!Number.isInteger(value)||value<0)return;
            let badge=bellControl.querySelector('.nav-badge');if(!badge){badge=document.createElement('b');badge.className='nav-badge';bellControl.append(badge);}badge.textContent=value;badge.hidden=!value;bellControl.setAttribute('aria-label',`Notifications: ${value} unread`);
        };
        document.addEventListener('prism:notification-count',event=>updateUnread(event.detail?.unread));
        if(!body.classList.contains('student-page')){
            const bell=bellControl;
            fetch('notifications_api.php?action=list&preview=1&personal=1').then(r=>r.ok?r.json():Promise.reject()).then(data=>{
                updateUnread(data.unread_count);
            }).catch(()=>{if(bell)bell.title='Notifications; unread count unavailable';});
        }
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
        if (event.key !== 'Escape' || event.defaultPrevented) return;
        if (links && !links.hidden) { setAccount(false, true); event.preventDefault(); }
        else if (nav && media.matches && !nav.hidden) { setNavigation(false, true); event.preventDefault(); }
    });
    document.querySelectorAll('[data-prism-resources-link]').forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        const dashboard = document.querySelector('#portalNav [data-page="dashboard"]');
        if (dashboard) dashboard.click();
        requestAnimationFrame(() => document.getElementById('prismResourcesTitle')?.scrollIntoView({block:'start'}));
    }));
    function updateSectionLocation() {
        document.querySelectorAll('.portal-nav-links a[href*="#"]').forEach(link => {
            const url = new URL(link.href);
            if (url.pathname === location.pathname && url.hash === location.hash) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }
    window.addEventListener('hashchange', updateSectionLocation);
    updateSectionLocation();
});
