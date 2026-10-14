/* Read-only guidance uses the navigation rendered after the existing onboarding gate. */
(() => {
    'use strict';
    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text != null) element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    // Reuse PRISM's native-dialog pattern, with its existing palette and button classes.
    function dialog(title, trigger = document.activeElement) {
        const element = node('dialog', null, 'prism-readonly-dialog');
        const heading = node('h2', title);
        heading.id = 'prismReadonlyTitle';
        element.setAttribute('aria-labelledby', heading.id);
        const content = node('div', null, 'prism-readonly-content');
        const actions = node('div', null, 'prism-dialog-actions');
        element.append(heading, content, actions);
        document.body.append(element);
        let restoreFocus = true;
        const close = (restore = true) => { restoreFocus = restore !== false; element.close(); };
        element.addEventListener('close', () => {
            element.remove();
            if (restoreFocus && trigger?.isConnected) trigger.focus({ preventScroll: true });
        }, { once: true });
        element.addEventListener('keydown', event => {
            if (event.key === 'Escape') event.stopPropagation();
            if (event.key === 'Tab') {
                const controls = [...element.querySelectorAll('button:not([disabled]), a[href], [tabindex="0"]')].filter(control => control.getClientRects().length);
                const first = controls[0], last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            }
        });
        element.addEventListener('click', event => { event.stopPropagation(); if (event.target === element) {
            const rect = element.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
        } });
        element.showModal();
        return { element, heading, content, actions, close };
    }
    function button(label, action, primary = false) {
        const control = node('button', label, 'prism-btn ' + (primary ? 'is-primary' : 'is-secondary'));
        control.type = 'button';
        control.addEventListener('click', action);
        return control;
    }
    window.PrismReadOnly = { dialog, node, button };

    document.addEventListener('DOMContentLoaded', () => {
        const metadata = document.querySelector('[data-prism-guide-role]');
        if (!metadata) return; // No normal tour on onboarding/security pages.
        const role = metadata.dataset.prismGuideRole;
        if (!['student', 'adviser', 'admin'].includes(role)) return;
        const embeddedPreview = window.self !== window.top;
        const menu = document.getElementById(role === 'student' ? 'portalProfileLinks' : 'prismAccountLinks');
        if (!menu) return;
        if (role === 'student' && metadata.content.childElementCount) menu.append(metadata.content.cloneNode(true));
        const identity = metadata.dataset.prismGuideUser || role;
        const key = `prismTour.${role}.${identity}.v1`;
        const remember = value => { try { localStorage.setItem(key, value); } catch (_) {} };
        const hasSeenTour = () => { try { return localStorage.getItem(key) !== null; } catch (_) { return false; } };

        const descriptions = {
            'dashboard.php': 'See research counts, IERB summaries and students needing attention. Select a marked Calendar date to read official deadlines.',
            'research_adviser.php': 'See assigned student counts, your current document review queue and recent notifications. Select a marked Calendar date for deadlines that apply to your groups.',
            'admin_students.php': role === 'adviser' ? 'View your assigned students and their research progress. Use Invite Student here to invite a student by email.' : 'Find student records, review research progress and use the existing invitation and account lifecycle controls when permitted.',
            'admin_archived_accounts.php': 'Review archived Students and Research Advisers. Restore accounts, manage Retention Hold, and review server-authorized deletion eligibility.',
            'admin_advisers.php': 'Find research adviser records, review assignments and use the existing invitation and account lifecycle controls.',
            'documents.php': role === 'adviser' ? 'Open current submissions and version history for your assigned students. Review documents and provide feedback using the existing review tools.' : 'View research document submissions, preview or download files and use your permitted document tools.',
            'ierbprog.php': 'Monitor current IERB stages and progress. Find records using the existing search and filters.',
            'calendar.php': role === 'adviser' ? 'View official deadlines applicable to your assigned groups and browser-local reminders. Use the existing Calendar tools for permitted deadline actions.' : 'View official research deadlines and browser-local reminders. Use the existing Calendar tools for permitted deadline actions.',
            'admin_notifications.php': 'Read notifications and review delivery status. Use the existing Notification Center tools available to your account.',
            'reports.php': 'View and generate research progress reports using the existing report controls.',
            'admin_ai.php': 'Request and review AI progress reports using the current reporting tools.',
            'data_export.php': 'Export permitted research data using the existing CSV download controls.',
            'account.php': 'View account information and use the permitted profile and security settings.',
            'admin_legal_policies.php': 'Review published Privacy and Terms versions, prepare drafts and approve or reject changes using the legal policy safeguards.',
            'account.php#activity': 'Review activity logs for actions visible to your account.',
            'account.php#security': 'Open account security settings.',
            dashboard: 'See your current research progress, document status and recent submissions.',
            submit: 'Use Submit document to upload research requirements. Uploads follow the current Adviser review and RPMS submission process.',
            documents: 'Check document status and Adviser decisions. Use Preview and Download, or Upload Revision when changes are required. Adviser approval automatically formally submits the current version to RPMS.',
            progress: 'Monitor your current IERB stage and research progress.',
            calendar: 'View official deadlines applicable to your research group and manage personal reminders stored in this browser.',
            notifications: 'Read RPMS and Adviser updates addressed to you.',
            profile: 'View your profile and access current account and password settings.',
            help: 'Send an in-app request to RPMS Administrators and read your own Support Responses. You can use Help & Support after the password and legal requirements, including while your profile is Pending.',
            resources: 'Browse the Research Agenda and IERB Portal resources.',
            logout: 'End your current PRISM session using Log out.'
        };
        function entries() {
            const selectors = role === 'student'
                ? '#portalNav button[data-page], #portalNav a, .welcome-card [data-go="submit"], .portal-nav-right [data-go="notifications"], #portalProfileLinks [data-go="profile"], #portalProfileLinks a'
                : '#prismPrimaryNavigation a, .prism-sidebar-utilities a, .portal-nav-right a, #prismAccountLinks a';
            const seen = new Set();
            return [...document.querySelectorAll(selectors)].flatMap(target => {
                const href = target.getAttribute('href');
                const id = href === 'help_support.php' ? 'help' : href?.includes('prismResourcesTitle') ? 'resources'
                    : href === 'logout.php' ? 'logout' : target.dataset.page || target.dataset.go || href;
                const description = descriptions[id];
                const rawLabel = target.textContent.trim() || target.getAttribute('aria-label') || target.title;
                const isNotificationLink = id === 'admin_notifications.php'
                    || target.matches('.portal-notification,[aria-label="Notifications"]')
                    || /notification/i.test(target.getAttribute('title') || '');
                const label = isNotificationLink ? 'Notifications'
                    : id === 'account.php#activity' ? 'Activity Logs'
                    : id === 'calendar.php' ? 'Personal Calendar' : rawLabel;
                if (!description || !label || seen.has(label)) return [];
                seen.add(label);
                return [{ target, label, description, id }];
            });
        }
        function guide() {
            const view = dialog('Navigation Guide');
            const list = node('dl', null, 'prism-guide-list');
            for (const entry of entries()) {
                const term = node('dt');
                term.append(button(entry.label, () => { view.close(false); entry.target.click(); }));
                list.append(term, node('dd', entry.description));
            }
            view.content.append(list);
            const close = button('Close', view.close);
            view.actions.append(close);
            close.focus();
        }
        function glyph(target) {
            const source = target?.querySelector('i[class*="fa-"]');
            if (!source) return null;
            const copy = source.cloneNode(false);
            copy.setAttribute('aria-hidden', 'true');
            return copy;
        }
        function sectionPreview(entry) {
            const figure = node('figure', null, 'prism-tour-preview');
            const bar = node('div', null, 'prism-tour-preview-bar');
            const previewLabel = (entry.id === 'admin_notifications.php' || entry.target?.matches('.portal-notification,[aria-label="Notifications"]')) ? 'Notifications'
                : entry.id === 'account.php#activity' ? 'Activity Logs' : entry.label;
            bar.append(node('span', 'PRISM'), node('span', previewLabel));
            const frame = node('div', null, 'prism-tour-live-frame');
            const canvas = node('div', null, 'prism-tour-live-canvas');
            canvas.inert = true;
            frame.append(canvas);
            figure.append(bar, frame);
            function show(source) {
                if (!source) { canvas.replaceChildren(node('p', 'This section is available from your portal navigation.')); return; }
                const copy = source.cloneNode(true);
                copy.querySelectorAll('script,style,iframe,dialog').forEach(el => el.remove());
                for (const el of [copy, ...copy.querySelectorAll('*')]) {
                    for (const attr of [...el.attributes]) {
                        if (/^on/i.test(attr.name) || ['autofocus', 'srcdoc'].includes(attr.name)) el.removeAttribute(attr.name);
                    }
                    if (el.id) el.id = 'tour-preview-' + el.id;
                    if (el.matches('input[type=password]')) { el.value = ''; el.removeAttribute('value'); }
                }
                if (entry.id === 'progress') {
                    copy.classList.add('prism-tour-progress-preview');
                    copy.querySelector('#tour-preview-principalIndicator')?.remove();
                    copy.querySelector('#tour-preview-protocolCodeReveal')?.remove();
                    copy.querySelector('.progress-meta')?.remove();
                    copy.querySelector('.requirement-panels')?.remove();
                    copy.querySelector('.progress-history-panel')?.remove();
                    const ring = copy.querySelector('#tour-preview-progressRing');
                    const percent = ring?.querySelector('b');
                    if (ring && percent) {
                        const label = node('span', 'complete', 'prism-tour-progress-ring-label');
                        ring.replaceChildren(percent, label);
                    }
                }
                if (entry.id === 'calendar.php') {
                    copy.classList.add('prism-tour-calendar-preview');
                    copy.querySelector('.official-deadline-panel')?.remove();
                    copy.querySelector('.ceu-footer')?.remove();
                    const now = new Date();
                    const monthGrid = copy.querySelector('#tour-preview-monthGrid');
                    const monthLabel = copy.querySelector('#tour-preview-monthLabel');
                    const selectedDate = copy.querySelector('#tour-preview-selectedDateLabel');
                    if (monthGrid) {
                        const year = now.getFullYear(), month = now.getMonth();
                        const firstWeekday = new Date(year, month, 1).getDay();
                        const daysInMonth = new Date(year, month + 1, 0).getDate();
                        monthGrid.replaceChildren();
                        for (let cell = 0; cell < 42; cell++) {
                            const day = cell - firstWeekday + 1;
                            if (day < 1 || day > daysInMonth) {
                                monthGrid.append(node('span', null, 'calendar-day outside'));
                                continue;
                            }
                            const dateCell = node('span', null, `calendar-day${day === now.getDate() ? ' today selected' : ''}`);
                            dateCell.append(node('span', String(day), 'day-number'));
                            monthGrid.append(dateCell);
                        }
                        if (monthLabel) monthLabel.textContent = now.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
                        if (selectedDate) selectedDate.textContent = now.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
                    }
                }
                if (entry.id === 'admin_notifications.php') {
                    copy.classList.add('prism-tour-notifications-preview');
                    copy.querySelector('.notification-history-card')?.remove();
                    copy.querySelector('.ceu-footer')?.remove();
                }
                if (entry.id === 'account.php#activity') {
                    copy.classList.add('prism-tour-activity-preview');
                    copy.querySelector('.ceu-footer')?.remove();
                }
                copy.classList.add('prism-tour-live-source');
                copy.removeAttribute('hidden');
                if (entry.id === 'navigation') {
                    copy.querySelectorAll('[hidden]').forEach(el => el.removeAttribute('hidden'));
                    copy.querySelectorAll('a').forEach(link => {
                        if (/research agenda|sustainable development goals|\bsdgs?\b/i.test(link.textContent)) link.remove();
                    });
                }
                canvas.replaceChildren(copy);
                resize();
            }
            function resize() {
                const isStaticPage = !!canvas.querySelector('.prism-tour-static-page');
                const width = isStaticPage ? 560 : ['quick-actions', 'help'].includes(entry.id) ? 560
                    : ['calendar.php', 'admin_notifications.php', 'account.php#activity'].includes(entry.id) ? 760
                    : entry.id === 'navigation' && role === 'admin' ? 600 : 1050;
                canvas.style.width = width + 'px';
                canvas.style.padding = isStaticPage ? '0' : '';
                canvas.style.transform = `scale(${frame.clientWidth / width})`;
            }
            function showStaticPage() {
                const page = node('div', null, 'prism-tour-static-page');
                const titles = {
                    'research_adviser.php': 'Adviser Dashboard', 'dashboard.php': 'Dashboard',
                    'admin_students.php': role === 'adviser' ? 'Assigned Students' : 'Students',
                    'admin_advisers.php': 'Research Advisers', 'admin_archived_accounts.php': 'Archived Accounts',
                    'documents.php': role === 'adviser' ? 'Documents to Review' : 'Research Documents',
                    'ierbprog.php': 'IERB Progress', 'calendar.php': 'Personal Calendar',
                    'admin_notifications.php': 'Notifications', 'reports.php': 'Reports',
                    'admin_ai.php': 'AI Reports', 'data_export.php': 'Data Export',
                    'account.php': 'My Account', 'account.php#activity': 'Activity Logs',
                    'account.php#security': 'Security Settings', 'admin_legal_policies.php': 'Legal Policies'
                };
                const title = titles[entry.id] || previewLabel;
                page.append(node('h3', title, 'prism-tour-static-title'));
                const panel = (heading, ...items) => {
                    const card = node('section', null, 'prism-tour-static-panel');
                    card.append(node('h4', heading), ...items);
                    return card;
                };
                const line = (text, className = '') => node('span', text, `prism-tour-static-line ${className}`);
                const controls = (...labels) => {
                    const row = node('div', null, 'prism-tour-static-controls');
                    labels.forEach(label => row.append(line(label)));
                    return row;
                };
                const stats = (...labels) => {
                    const row = node('div', null, 'prism-tour-static-stats');
                    labels.forEach(label => row.append(panel(label, line('—', 'is-value'))));
                    return row;
                };
                const empty = label => node('div', label, 'prism-tour-static-empty');
                if (['research_adviser.php', 'dashboard.php'].includes(entry.id)) {
                    const hero = node('section', null, 'prism-tour-static-hero');
                    hero.append(node('small', role === 'adviser' ? 'RESEARCH ADVISER WORKSPACE' : 'RPMS ADMIN WORKSPACE'), node('strong', role === 'adviser' ? 'Welcome to your adviser workspace' : 'Research management overview'), line(role === 'adviser' ? 'Review assigned research and documents.' : 'Monitor research activity across PRISM.'));
                    page.append(hero, stats(...(role === 'adviser' ? ['Assigned students', 'Pending adviser review', 'Needs revision', 'Approved documents'] : ['Students', 'Research advisers', 'Documents', 'IERB progress'])), panel(role === 'adviser' ? 'Documents to Review' : 'Research Overview', controls('Search', 'Filter'), empty('Current records appear here.')));
                } else if (entry.id === 'ierbprog.php') {
                    page.append(panel('Progress Overview', stats('Protocol Submission', 'Initial Ethics Review', 'Revisions & Resubmission')), panel('Detailed Progress', controls('Search by student name or ID', 'Sort'), controls('All', 'On track', 'Delayed'), empty('Student progress cards appear here.')));
                } else if (entry.id === 'documents.php') {
                    page.append(panel('Documents to Review', controls('Search documents', 'Sort'), controls('All', 'Pending review', 'Needs revision', 'Submitted to RPMS'), empty('Document submissions appear here.')));
                } else if (entry.id === 'calendar.php') {
                    const calendar = node('div', null, 'prism-tour-static-calendar');
                    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', ...Array.from({ length: 28 }, (_, index) => String(index + 1))].forEach(day => calendar.append(line(day)));
                    page.append(panel('Personal Calendar', controls('‹', 'Month', '›', 'Today'), calendar), panel('Selected date', line('Official deadlines and personal reminders appear here.')));
                } else if (entry.id === 'admin_notifications.php') {
                    page.append(panel('Notification History', controls('Inbox', 'Unread', 'Archived'), controls('View', 'Kind', 'Sort'), empty('Notifications appear here.')));
                } else if (entry.id === 'account.php#activity') {
                    page.append(panel('Activity Logs', controls('Search activity', 'Sort'), controls('Date', 'Action', 'Details'), empty('Account activity appears here.')));
                } else if (entry.id.startsWith('account.php')) {
                    page.append(panel(entry.id.endsWith('#security') ? 'Security Settings' : 'Profile Information', controls('Name', 'Email', 'Role'), empty('Account settings appear here.')));
                } else if (['admin_students.php', 'admin_advisers.php', 'admin_archived_accounts.php'].includes(entry.id)) {
                    page.append(panel(title, controls('Search by name or ID', 'Sort', 'Filters'), controls('Name', 'Email', 'Status', 'Actions'), empty('Account records appear here.')));
                } else if (entry.id === 'reports.php') {
                    page.append(panel('Research Progress Reports', controls('Report type', 'Date range', 'Generate report'), stats('Students', 'Documents', 'IERB progress')));
                } else if (entry.id === 'admin_ai.php') {
                    page.append(panel('AI Progress Reports', controls('Select report', 'Generate'), empty('Generated reports appear here.')));
                } else if (entry.id === 'data_export.php') {
                    page.append(panel('Export Research Data', controls('Data set', 'Date range', 'Download CSV'), empty('Choose the data you need to export.')));
                } else if (entry.id === 'admin_legal_policies.php') {
                    page.append(panel('Privacy and Terms', controls('Published', 'Drafts', 'Review'), empty('Policy versions appear here.')));
                } else page.append(panel(title, empty('Page tools appear here.')));
                canvas.replaceChildren(page);
                resize();
            }
            const observer = new ResizeObserver(resize);
            observer.observe(frame);
            // Stop observing when this slide is replaced or the tour closes.
            const cleanup = new MutationObserver(() => {
                if (!figure.isConnected) { observer.disconnect(); cleanup.disconnect(); }
            });
            cleanup.observe(document.body, { childList: true, subtree: true });
            if (entry.id === 'quick-actions') {
                const wrapper = node('div', null, 'prism-tour-quick-actions');
                [
                    ['Quick tour', 'fa-circle-question'],
                    ['Theme', 'fa-sun'],
                    ['Notifications', 'fa-bell']
                ].forEach(([label, iconName]) => {
                    const action = node('div', null, 'prism-tour-quick-action');
                    action.setAttribute('aria-label', label);
                    action.title = label;
                    const icon = node('span', null, 'prism-tour-quick-action-icon');
                    const glyph = node('i', null, `fa-solid ${iconName}`);
                    glyph.setAttribute('aria-hidden', 'true');
                    icon.append(glyph);
                    action.append(icon);
                    wrapper.append(action);
                });
                wrapper.append(node('span', 'Tour', 'prism-tour-quick-tooltip'));
                wrapper.classList.add('prism-tour-live-source');
                canvas.replaceChildren(wrapper);
                resize();
            } else if (entry.id === 'navigation') {
                const navigation = document.querySelector(role === 'student' ? '#portalNav' : '#prismPrimaryNavigation');
                if (role === 'admin' && navigation) {
                    const sidebar = node('div', null, 'sidebar prism-sidebar');
                    sidebar.append(navigation.cloneNode(true));
                    show(sidebar);
                } else show(navigation);
            } else if (entry.id === 'help') {
                const preview = node('div', null, 'prism-tour-help-preview');
                const title = node('h3', 'Help & Support');
                const intro = node('p', 'Send a private request to RPMS Administrators. Replies appear here in PRISM only.');
                const tabs = node('div', null, 'prism-tour-help-tabs');
                tabs.append(node('span', 'New request', 'is-active'), node('span', 'My requests & replies'));
                const form = node('div', null, 'prism-tour-help-form');
                form.append(node('strong', 'What do you need help with?'));
                const chips = node('div', null, 'prism-tour-help-chips');
                const categories = [...document.querySelectorAll('#supportModal .sp-chip')].map(chip => chip.textContent.trim()).slice(0, 3);
                (categories.length ? categories : ['Account & access', 'Documents', 'Other']).forEach(label => chips.append(node('span', label)));
                form.append(chips, node('label', 'Subject'), node('div', 'Short summary of your request', 'prism-tour-help-field'), node('label', 'Message'), node('div', 'Describe the issue or question…', 'prism-tour-help-field is-message'), node('button', 'Send to RPMS', 'prism-tour-help-submit'));
                preview.append(title, intro, tabs, form);
                show(preview);
            } else {
                const section = role === 'student' && document.querySelector(`[data-section="${entry.id}"]`);
                const href = entry.target?.getAttribute('href');
                if (section) show(section);
                else if (entry.id === 'resources') show(document.getElementById('prismResourcesTitle')?.closest('section,article'));
                else if (role !== 'student') showStaticPage();
                else if (href) {
                    const url = new URL(href, location.href);
                    const hashTarget = url.hash ? document.querySelector(decodeURIComponent(url.hash)) : null;
                    if (url.pathname === location.pathname) show(hashTarget?.closest('.management-card,section,article') || hashTarget || document.querySelector('main,.main-content'));
                    else {
                        canvas.append(node('p', 'Loading section preview...'));
                        fetch(url.href, { credentials: 'same-origin' }).then(r => {
                            if (!r.ok) throw Error('Preview unavailable');
                            return r.text();
                        }).then(html => {
                            if (!figure.isConnected) return;
                            const page = new DOMParser().parseFromString(html, 'text/html');
                            const pageTarget = url.hash ? page.querySelector(decodeURIComponent(url.hash)) : null;
                            show(pageTarget?.closest('.management-card,section,article') || pageTarget || page.querySelector('main,.main-content'));
                        }).catch(() => { if (figure.isConnected) show(null); });
                    }
                } else show(document.querySelector('main,.main-content'));
            }
            return figure;
        }
        function tour() {
            // Rebuild from real controls each time; absent optional controls safely disappear.
            const visited = new Set();
            const order = role === 'student' ? ['dashboard', 'submit', 'documents', 'progress', 'calendar', 'notifications', 'help', 'profile', 'resources']
                : role === 'adviser' ? ['research_adviser.php', 'admin_students.php', 'documents.php', 'ierbprog.php', 'calendar.php', 'admin_notifications.php', 'account.php', 'account.php#activity', 'resources']
                : ['dashboard.php', 'admin_students.php', 'admin_advisers.php', 'documents.php', 'ierbprog.php', 'admin_notifications.php', 'calendar.php', 'reports.php', 'admin_ai.php', 'data_export.php', 'account.php', 'account.php#activity', 'account.php#security'];
            const destinations = entries().filter(entry => {
                if (entry.id === 'logout' || entry.id === 'resources' || /sustainable development goals|\bsdgs?\b/i.test(entry.label) || visited.has(entry.id)) return false;
                if (/^\d+$/.test(entry.label) && /notification/i.test(entry.target?.getAttribute('aria-label') || entry.target?.getAttribute('title') || '')) entry.label = 'Notifications';
                if (entry.id === 'calendar.php') entry.label = 'Personal Calendar';
                if (entry.id === 'admin_notifications.php') entry.label = 'Notifications';
                if (entry.id === 'account.php#activity') entry.label = 'Activity Logs';
                visited.add(entry.id); return true;
            }).sort((a, b) => {
                const rank = id => order.includes(id) ? order.indexOf(id) : order.length;
                return rank(a.id) - rank(b.id);
            });
            if (!destinations.length) return;
            const steps = [
                { id: 'quick-actions', label: 'Quick Actions', description: 'Open Notifications for updates and Activity Logs to review account events.' },
                { id: 'navigation', label: 'Navigation Guide', description: role === 'admin' ? 'Use the sidebar to move between Research Management, Reports & Communication, and Account & Planning.' : 'Use the navigation bar to move between the sections of your portal. On smaller screens, open Menu to see these links.' },
                ...destinations
            ];
            const total = steps.length;
            const view = dialog('Quick Actions');
            view.element.classList.add('prism-tour-dialog');
            view.content.setAttribute('aria-live', 'polite');
            view.content.setAttribute('aria-atomic', 'true');
            const skip = button('Skip \u2014 close tour', () => complete('skipped'));
            skip.classList.add('prism-tour-skip');
            view.element.append(skip);

            let index = 0, recorded = false;
            const complete = (value, restore = true) => { recorded = true; remember(value); view.close(restore); };
            view.element.addEventListener('cancel', () => { recorded = true; remember('skipped'); });
            view.element.addEventListener('close', () => { if (!recorded) remember('skipped'); });

            function go(delta) {
                const target = index + delta;
                if (target < 0 || target >= total) return; // Arrow keys and swipes never finish the tour by accident.
                const direction = delta;
                index = target;
                render(direction);
            }
            // Left/Right arrow keys.
            view.element.addEventListener('keydown', event => {
                if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
                if (event.key === 'ArrowRight') { event.preventDefault(); go(1); }
                else if (event.key === 'ArrowLeft') { event.preventDefault(); go(-1); }
            });
            // Horizontal swipe on touch screens; vertical scrolling is left alone.
            let startX = null, startY = 0;
            view.element.addEventListener('touchstart', event => {
                const touch = event.changedTouches[0]; startX = touch.clientX; startY = touch.clientY;
            }, { passive: true });
            view.element.addEventListener('touchend', event => {
                if (startX === null) return;
                const touch = event.changedTouches[0];
                const dx = touch.clientX - startX, dy = touch.clientY - startY;
                startX = null;
                if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) go(dx < 0 ? 1 : -1);
            }, { passive: true });

            function render(direction = 0) {
                view.content.replaceChildren();
                view.actions.replaceChildren();
                const step = steps[index];

                const top = node('div', null, 'prism-tour-top');
                top.append(node('span', 'Quick tour', 'prism-tour-pill'));
                const progress = node('span', `Step ${index + 1} of ${total}`, 'prism-tour-progress');
                progress.setAttribute('aria-live', 'polite');
                top.append(progress);

                const slide = node('div', null, 'prism-tour-slide');
                if (direction) slide.dataset.direction = direction > 0 ? 'next' : 'back';
                const icon = glyph(step.target);
                const badge = node('span', null, 'prism-tour-badge');
                badge.setAttribute('aria-hidden', 'true');
                if (icon) badge.append(icon);
                else badge.textContent = '\u2728';

                view.heading.textContent = step.label;
                const lead = node('p', null, 'prism-tour-lead');
                lead.textContent = step.description;
                slide.append(sectionPreview(step), badge, view.heading, lead);
                view.content.append(top, slide);

                const dots = node('div', null, 'prism-tour-dots');
                dots.setAttribute('aria-hidden', 'true');
                for (let dot = 0; dot < total; dot++) dots.append(node('span', null, dot === index ? 'is-active' : ''));
                const buttons = node('div', null, 'prism-tour-buttons');
                if (index > 0) buttons.append(button('Back', () => go(-1)));
                const last = index === total - 1;
                const next = button(last ? 'Finish' : 'Next', () => { if (last) complete('finished'); else go(1); }, true);
                buttons.append(next);
                view.actions.append(dots, buttons);
                skip.hidden = last;
                next.focus();
            }
            render();
        }
        menu.querySelector('[data-prism-tour]')?.addEventListener('click', tour);
        menu.querySelector('[data-prism-guide]')?.addEventListener('click', guide);
        window.PrismGuidance = { tour, guide };
        const welcomeVisit = metadata.dataset.prismGuideAuto === '1';
        const autoStart = !embeddedPreview && (welcomeVisit || !hasSeenTour());
        if (welcomeVisit) {
            const cleanUrl = new URL(window.location.href);
            cleanUrl.searchParams.delete('welcome');
            window.history.replaceState(null, '', cleanUrl);
        }
        if (autoStart) window.setTimeout(tour, 700);
    });
})();
