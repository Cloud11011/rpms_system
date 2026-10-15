/* Shell presentation only. Existing page scripts own data, workflow and prismTheme. */
document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    if (!body.classList.contains('prism-workspace')) return;
    document.querySelectorAll('[data-prism-resources]').forEach(resources => {
        resources.querySelectorAll('.res-item > .res-row[aria-controls]').forEach(button => {
            const panelId = button.getAttribute('aria-controls');
            const panel = panelId ? document.getElementById(panelId) : null;
            if (!panel || !resources.contains(panel)) return;
            const initiallyOpen = button.getAttribute('aria-expanded') === 'true';
            panel.setAttribute('aria-hidden', String(!initiallyOpen));
            panel.inert = !initiallyOpen;
            button.addEventListener('click', () => {
                const open = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', String(open));
                panel.setAttribute('aria-hidden', String(!open));
                panel.inert = !open;
                panel.classList.toggle('open', open);
            });
        });
    });
    const resourcePreview = document.getElementById('prismResourcePreview');
    if (resourcePreview) {
        const previewImage = document.getElementById('prismResourcePreviewImage');
        const previewTitle = document.getElementById('prismResourcePreviewTitle');
        const previewDownload = document.getElementById('prismResourcePreviewDownload');
        document.addEventListener('click', event => {
            const resourceLink = event.target.closest('[data-prism-resource-preview]');
            if (resourceLink) {
                event.preventDefault();
                previewTitle.textContent = resourceLink.dataset.resourceTitle || 'Research resource';
                previewImage.src = resourceLink.href;
                previewImage.alt = resourceLink.querySelector('img')?.alt || previewTitle.textContent;
                previewDownload.href = resourceLink.href;
                previewDownload.download = resourceLink.href.split('/').pop().split('?')[0];
                resourcePreview.showModal();
                return;
            }
            if (event.target === resourcePreview || event.target.closest('[data-prism-preview-close]')) {
                resourcePreview.close();
            }
        });
    }
    const account = document.querySelector('[data-prism-admin-account]');
    const controls = document.querySelector('.top-controls');
    if (account && controls) controls.append(account);
    const utilities = document.querySelector('.portal-nav-right');
    const theme = document.getElementById('themeToggle');
    if (body.classList.contains('portal-shell') && utilities && theme) {
        utilities.insertBefore(theme, utilities.querySelector('.prism-account-menu, .portal-profile-menu'));
    }
    const topHost = utilities || controls;
    utilities?.querySelector('.portal-help')?.remove(); // Help stays in the profile; ? is Quick Tour.
    if (topHost && theme) {
        const group=document.createElement('div');group.className='prism-topbar-group';group.setAttribute('role','group');group.setAttribute('aria-label','Workspace controls');
        const icon=path=>`<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">${path}</svg>`;
        theme.innerHTML=icon('<g class="light-icon"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l2 2m10 10l2 2M5 19l2-2M17 7l2-2"/></g><path class="dark-icon" d="M20 15A9 9 0 0 1 9 3a9 9 0 1 0 11 12Z"/>');
        const tour=document.createElement('button');tour.type='button';tour.title='Tour';tour.setAttribute('aria-label','Tour');tour.dataset.prismTopTour='';tour.innerHTML=icon('<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4m0 3v1"/>');
        tour.addEventListener('click',()=>window.PrismGuidance?.tour());group.append(tour);
        group.append(theme);
        let notification=topHost.querySelector('.notification-menu-wrap,.portal-notification');
        if(!notification){notification=document.createElement('a');notification.href='admin_notifications.php';notification.className='portal-notification';notification.setAttribute('aria-label','Notifications');notification.title='Notifications';notification.innerHTML=icon('<path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5Z"/><path d="M10 20h4"/>');}
        group.append(notification);
        const bellControl=notification.matches('a,button')?notification:notification.querySelector('button');
        if(bellControl && !notification.classList.contains('notification-menu-wrap')) {
            const panel=document.createElement('section');panel.className='prism-bell-preview';panel.hidden=true;panel.id='prismBellPreview';panel.setAttribute('aria-label','Recent notifications');
            const heading=document.createElement('div');heading.className='prism-bell-preview-heading';
            const headingText=document.createElement('div');
            const eyebrow=document.createElement('small');eyebrow.textContent='STATUS CENTER';
            const title=document.createElement('strong');title.textContent='Notifications & Confirmations';
            headingText.append(eyebrow,title);
            const headingIcon=document.createElement('span');headingIcon.className='prism-bell-preview-heading-icon';headingIcon.innerHTML=icon('<path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/><path d="m16 17 2 2 4-4"/>');
            heading.append(headingText,headingIcon);
            const list=document.createElement('div');list.className='prism-bell-preview-list';
            const all=document.createElement('a');all.href=body.classList.contains('student-page')?'#notifications':'admin_notifications.php';all.textContent='View all notifications';
            if(body.classList.contains('student-page'))all.dataset.go='notifications';
            panel.append(heading,list,all);body.append(panel);
            bellControl.setAttribute('aria-expanded','false');bellControl.setAttribute('aria-controls',panel.id);
            const close=()=>{panel.hidden=true;bellControl.setAttribute('aria-expanded','false');};
            const position=()=>{const r=bellControl.getBoundingClientRect();panel.style.left=Math.max(12,Math.min(r.right-panel.offsetWidth,innerWidth-panel.offsetWidth-12))+'px';panel.style.top=Math.max(12,Math.min(r.bottom+10,innerHeight-panel.offsetHeight-12))+'px';};
            bellControl.addEventListener('click',async event=>{
                event.preventDefault();event.stopImmediatePropagation();
                if(!panel.hidden){close();return;}panel.hidden=false;bellControl.setAttribute('aria-expanded','true');panel.setAttribute('aria-busy','true');list.textContent='Loading notifications…';position();
                try {
                    const response=await fetch('notifications_api.php?action=list&preview=5&personal=1');if(!response.ok)throw Error();const data=await response.json();
                    const rows=data.notifications||[];list.replaceChildren();
                    rows.forEach(row=>{const item=document.createElement('div');item.className='prism-bell-preview-item';const title=document.createElement('strong');title.textContent=row.subject||row.type||'Notification';const message=document.createElement('p');message.textContent=row.message||'';item.append(title,message);list.append(item);});
                    if(!rows.length){const empty=document.createElement('div');empty.className='prism-bell-preview-empty';const emptyIcon=document.createElement('i');emptyIcon.className='fa-regular fa-bell-slash';emptyIcon.setAttribute('aria-hidden','true');const emptyText=document.createElement('p');emptyText.textContent='No status updates, email confirmations, or automated notifications to display.';empty.append(emptyIcon,emptyText);list.append(empty);}
                } catch(_){list.textContent='Notifications could not be loaded. Please try again.';}
                panel.setAttribute('aria-busy','false');
                if(!panel.hidden)position();
            },true);
            document.addEventListener('click',event=>{if(!panel.contains(event.target)&&!bellControl.contains(event.target))close();});
            document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!panel.hidden){close();bellControl.focus();}});
            window.addEventListener('resize',close);document.addEventListener('scroll',close,true);
        }
        bellControl?.querySelector('i')?.replaceWith((()=>{const span=document.createElement('span');span.innerHTML=icon('<path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5Z"/><path d="M10 20h4"/>');return span;})());
        const profileMenu=topHost.querySelector('.prism-account-menu,.portal-profile-menu');
        topHost.append(group);
        if(profileMenu)topHost.append(profileMenu);
        const tooltip=document.createElement('span');tooltip.className='prism-control-tooltip';tooltip.id='prismControlTooltip';tooltip.setAttribute('role','tooltip');tooltip.hidden=true;body.append(tooltip);
        const hideTip=()=>{tooltip.hidden=true;document.querySelectorAll('[aria-describedby="prismControlTooltip"]').forEach(control=>control.removeAttribute('aria-describedby'));};
        group.querySelectorAll('button,a').forEach(control=>{
            control.dataset.prismTooltip='';
        });
        document.querySelectorAll('[data-prism-tooltip]').forEach(control=>{
            const show=()=>{const label=control.getAttribute('aria-label')||control.title;if(!label)return;tooltip.textContent=label;tooltip.hidden=false;control.setAttribute('aria-describedby',tooltip.id);const anchor=control.getBoundingClientRect(),box=tooltip.getBoundingClientRect(),left=anchor.left+(anchor.width-box.width)/2;tooltip.style.left=Math.max(12,Math.min(left,innerWidth-box.width-12))+'px';tooltip.style.top=Math.max(12,Math.min(anchor.bottom+8,innerHeight-box.height-12))+'px';};
            control.addEventListener('pointerenter',show);control.addEventListener('pointerleave',hideTip);control.addEventListener('focus',show);control.addEventListener('blur',hideTip);control.addEventListener('click',hideTip);
        });
        document.addEventListener('keydown',event=>{if(event.key==='Escape')hideTip();});
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
    // The navbar is translated to center it, which makes fixed descendants
    // use the navbar as their containing block. Portal the menu to the body
    // so its viewport-based coordinates stay anchored to the visible button.
    if (links) document.body.append(links);
    const navButton = document.getElementById('adviserNavigationToggle');
    const nav = navButton && document.getElementById(navButton.getAttribute('aria-controls'));
    const media = window.matchMedia('(max-width: 1120px)');
    function positionAccount() {
        if (!button || !links || links.hidden) return;
        const margin=12, gap=8, anchor=button.getBoundingClientRect();
        links.style.position='fixed';links.style.right='auto';
        links.style.maxHeight=Math.max(0,innerHeight-anchor.bottom-gap-margin)+'px';links.style.overflowY='auto';
        const box=links.getBoundingClientRect();
        links.style.left=Math.max(margin,Math.min(anchor.right-box.width,innerWidth-box.width-margin))+'px';
        links.style.top=Math.max(margin,anchor.bottom+gap)+'px';
    }
    function setAccount(open, focus = false) {
        if (!button || !links) return;
        if (!open && (focus || links.contains(document.activeElement))) button.focus();
        button.setAttribute('aria-expanded', String(open));
        links.hidden = !open;
        if (open) positionAccount();
    }
    links?.addEventListener('click', event => {
        if (event.target.closest('a,button')) setAccount(false);
    });
    window.addEventListener('resize',positionAccount);
    document.addEventListener('scroll',positionAccount,true);
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
    // Research resources dropdown management for Adviser and Student
    function closeAllPrismDropdowns(focusToggle = false) {
        document.querySelectorAll('[data-prism-dropdown]').forEach(wrap => {
            const toggle = wrap.querySelector('.portal-dropdown-toggle, .prism-dropdown-toggle');
            const menu = wrap.querySelector('.portal-resources-dropdown, .portal-dropdown-menu');
            if (menu && !menu.hidden) {
                menu.hidden = true;
                toggle?.setAttribute('aria-expanded', 'false');
                if (focusToggle && toggle) toggle.focus();
            }
        });
    }
    document.querySelectorAll('[data-prism-dropdown]').forEach(wrap => {
        const toggle = wrap.querySelector('.portal-dropdown-toggle, .prism-dropdown-toggle');
        const menu = wrap.querySelector('.portal-resources-dropdown, .portal-dropdown-menu');
        if (!toggle || !menu) return;
        toggle.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';
            closeAllPrismDropdowns();
            if (!isOpen) {
                menu.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
            }
        });
        menu.addEventListener('click', event => {
            if (event.target.closest('a')) closeAllPrismDropdowns();
        });
    });
    document.addEventListener('click', event => {
        if (!button?.contains(event.target) && !links?.contains(event.target)) setAccount(false);
        if (!event.target.closest('.portal-navbar')) setNavigation(false);
        if (!event.target.closest('[data-prism-dropdown]')) closeAllPrismDropdowns();
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || event.defaultPrevented) return;
        if (links && !links.hidden) { setAccount(false, true); event.preventDefault(); }
        else if (nav && media.matches && !nav.hidden) { setNavigation(false, true); event.preventDefault(); }
        else { closeAllPrismDropdowns(true); }
    });
    document.querySelectorAll('[data-prism-resources-link]').forEach(link => link.addEventListener('click', event => {
        const resources = document.getElementById('prismResourcesTitle');
        event.preventDefault();
        const studentDashboard = document.querySelector('#portalNav [data-page="dashboard"]');
        if (!resources && !studentDashboard) {
            window.location.href = link.href;
            return;
        }
        if (studentDashboard && !studentDashboard.closest('.portal-page')?.classList.contains('active')) studentDashboard.click();
        requestAnimationFrame(() => {
            const panelId = link.dataset.prismResourceTarget;
            if (panelId) {
                const panel = document.getElementById(panelId);
                const trigger = panel?.previousElementSibling;
                if (panel && trigger?.getAttribute('aria-expanded') !== 'true') trigger?.click();
            }
            document.getElementById('prismResourcesTitle')?.scrollIntoView({block:'start', behavior:'smooth'});
        });
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
