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
            // Illustrations use their own responsive layout. Cloned live pages inherit
            // page grids and IDs and cannot safely be scaled into a small dialog.
            const figure = node('figure', null, 'prism-tour-preview');
            const bar = node('div', null, 'prism-tour-preview-bar');
            bar.append(node('span', 'PRISM'), node('span', entry.label));
            const page = node('div', null, 'prism-tour-static-page');
            page.inert = true;
            const line = text => node('span', text, 'prism-tour-static-line');
            const controls = (...labels) => {
                const row = node('div', null, 'prism-tour-static-controls');
                labels.forEach(label => row.append(line(label)));
                return row;
            };
            const panel = (heading, ...items) => {
                const card = node('section', null, 'prism-tour-static-panel');
                card.append(node('h4', heading), ...items);
                return card;
            };
            const empty = label => node('p', label, 'prism-tour-static-empty');
            const stats = (...labels) => {
                const row = node('div', null, 'prism-tour-static-stats');
                labels.forEach(label => row.append(panel(label, line('Overview'))));
                return row;
            };
            page.append(node('h3', entry.label, 'prism-tour-static-title'));
            const id = entry.id;
            if (id === 'navigation') {
                const links = [...document.querySelectorAll(role === 'student' ? '#portalNav [data-page]' : '#prismPrimaryNavigation a')]
                    .map(link => link.textContent.trim()).filter(Boolean);
                page.append(panel('Your workspace', controls(...links)));
            } else if (id === 'quick-actions') {
                page.append(panel('Quick Actions', controls('Quick tour', 'Theme', 'Notifications'), empty('Reopen this guide, switch themes, or check your latest updates.')));
            } else if (['dashboard', 'dashboard.php', 'research_adviser.php'].includes(id)) {
                const hero = node('section', null, 'prism-tour-static-hero');
                hero.append(node('strong', 'Welcome to your PRISM workspace'), line(role === 'student' ? 'Track your research and next steps.' : 'Monitor research activity and review submissions.'));
                page.append(hero, stats(...(role === 'student' ? ['IERB progress', 'My documents', 'Upcoming reminders'] : ['Students', 'Documents', 'IERB progress'])));
            } else if (['progress', 'ierbprog.php'].includes(id)) {
                page.append(panel('IERB journey', controls('Submission', 'Ethics review', 'Revisions'), stats('Current stage', 'Requirements'), empty('Stage details and progress history appear here.')));
            } else if (['documents', 'documents.php'].includes(id)) {
                page.append(panel(role === 'student' ? 'My Documents' : 'Research Documents', controls('Search', 'Status', 'Sort'), empty('File details, review status, version history and permitted actions.')));
            } else if (id === 'submit') {
                page.append(panel('Submit a document', controls('Document type', 'Choose PDF / DOCX'), empty('Review your selection and submit for adviser review.')));
            } else if (['calendar', 'calendar.php'].includes(id)) {
                const calendar = node('div', null, 'prism-tour-static-calendar');
                ['S', 'M', 'T', 'W', 'T', 'F', 'S', ...Array.from({length: 28}, (_, index) => String(index + 1))].forEach(day => calendar.append(line(day)));
                page.append(panel('Personal Calendar', controls('Previous', 'Month', 'Next', 'Today'), calendar), empty('Official deadlines and your personal reminders.'));
            } else if (['notifications', 'admin_notifications.php'].includes(id)) {
                page.append(panel('Notification history', controls('Inbox', 'Unread', 'Archived'), empty('Submission updates, reminders and confirmations.')));
            } else if (id === 'activity' || id === 'account.php#activity') {
                page.append(panel('Activity Logs', controls('Date', 'Action', 'Details'), empty('Review events recorded for your account.')));
            } else if (id === 'profile' || id.startsWith('account.php')) {
                page.append(panel(id.endsWith('#security') ? 'Security settings' : 'Account information', controls('Name', 'Email', 'Role'), empty('Review your details and manage permitted account settings.')));
            } else if (['admin_students.php', 'admin_advisers.php', 'admin_archived_accounts.php'].includes(id)) {
                page.append(panel(entry.label, controls('Search by name or ID', 'Sort', 'Filters'), empty('Account records, status and permitted actions.')));
            } else if (id === 'reports.php' || id === 'admin_ai.php') {
                page.append(panel('Research reports', controls('Report type', 'Date range', 'Generate'), empty('Review reports using your permitted tools.')));
            } else if (id === 'data_export.php') {
                page.append(panel('Export research data', controls('Data set', 'Date range', 'Download CSV')));
            } else if (id === 'help' || id === 'help_support.php') {
                page.append(panel('Help & Support', controls('New request', 'My requests & replies'), empty('Choose a category, add a subject and describe your question.')));
            } else if (id === 'resources') {
                page.append(panel('Research resources', controls('Guidelines', 'Forms', 'Research links')));
            } else {
                page.append(panel(entry.label, empty(entry.description || 'Open this section from your workspace navigation.')));
            }
            figure.append(bar, page, node('figcaption', 'Illustration of the section; your records appear in the workspace.'));
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
