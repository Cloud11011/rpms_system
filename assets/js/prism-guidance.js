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
        const menu = document.getElementById(role === 'student' ? 'portalProfileLinks' : 'prismAccountLinks');
        if (!menu) return;
        if (metadata.content.childElementCount) menu.append(metadata.content.cloneNode(true));
        const key = `prismTour.${role}.v1`;
        const remember = value => { try { localStorage.setItem(key, value); } catch (_) {} };

        const descriptions = {
            'dashboard.php': 'See research counts, IERB summaries and students needing attention. Select a marked Calendar date to read official deadlines.',
            'research_adviser.php': 'See assigned student counts, your current document review queue and recent notifications. Select a marked Calendar date for deadlines that apply to your groups.',
            'admin_students.php': role === 'adviser' ? 'View your assigned students and their research progress. Use Invite Student here to invite a student by email.' : 'Find student records, review research progress and use the existing invitation and account lifecycle controls when permitted.',
            'admin_advisers.php': 'Find research adviser records, review assignments and use the existing invitation and account lifecycle controls.',
            'documents.php': role === 'adviser' ? 'Open current submissions and version history for your assigned students. Review documents and provide feedback using the existing review tools.' : 'View research document submissions, preview or download files and use your permitted document tools.',
            'ierbprog.php': 'Monitor current IERB stages and progress. Find records using the existing search and filters.',
            'calendar.php': role === 'adviser' ? 'View official deadlines applicable to your assigned groups and browser-local reminders. Use the existing Calendar tools for permitted deadline actions.' : 'View official research deadlines and browser-local reminders. Use the existing Calendar tools for permitted deadline actions.',
            'admin_notifications.php': 'Read notifications and review delivery status. Use the existing Notification Center tools available to your account.',
            'reports.php': 'View and generate research progress reports using the existing report controls.',
            'admin_ai.php': 'Request and review AI progress reports using the current reporting tools.',
            'data_export.php': 'Export permitted research data using the existing CSV download controls.',
            'account.php': 'View account information and use the permitted profile and security settings.',
            'account.php#activity': 'Review activity logs for actions visible to your account.',
            'account.php#security': 'Open account security settings.',
            dashboard: 'See your current research progress, document status and recent submissions.',
            submit: 'Use Submit document to upload research requirements. Uploads follow the current Adviser review and RPMS submission process.',
            documents: 'Check document status and Adviser decisions. Use Preview, Download and the existing revision or submission controls when available.',
            progress: 'Monitor your current IERB stage and research progress.',
            calendar: 'View official deadlines applicable to your research group and manage personal reminders stored in this browser.',
            notifications: 'Read RPMS and Adviser updates addressed to you.',
            profile: 'View your profile and access current account and password settings.',
            help: 'Use Help and support when you need assistance. The current form prepares a message in your email app.',
            resources: 'Browse the Sustainable Development Goals, Research Agenda and IERB Portal resources.',
            logout: 'End your current PRISM session using Log out.'
        };
        function entries() {
            const selectors = role === 'student'
                ? '#portalNav button[data-page], #portalNav a, .welcome-card [data-go="submit"], .portal-nav-right [data-go="notifications"], #helpButton, #portalProfileLinks [data-go="profile"], #portalProfileLinks a[href="logout.php"]'
                : '#prismPrimaryNavigation a, .prism-sidebar-utilities a, .portal-nav-right a, #prismAccountLinks a';
            const seen = new Set();
            return [...document.querySelectorAll(selectors)].flatMap(target => {
                const href = target.getAttribute('href');
                const id = target.id === 'helpButton' ? 'help' : href?.includes('prismResourcesTitle') ? 'resources'
                    : href === 'logout.php' ? 'logout' : target.dataset.page || target.dataset.go || href;
                const description = descriptions[id];
                const label = target.textContent.trim() || target.getAttribute('aria-label') || target.title;
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
        function tour() {
            // Rebuild from real controls each time; absent optional controls safely disappear.
            const visited = new Set();
            const order = role === 'student' ? ['dashboard', 'submit', 'documents', 'progress', 'calendar', 'notifications', 'help', 'profile', 'resources']
                : role === 'adviser' ? ['research_adviser.php', 'admin_students.php', 'documents.php', 'ierbprog.php', 'calendar.php', 'admin_notifications.php', 'account.php', 'account.php#activity', 'resources']
                : ['dashboard.php', 'admin_students.php', 'admin_advisers.php', 'documents.php', 'ierbprog.php', 'admin_notifications.php', 'calendar.php', 'reports.php', 'admin_ai.php', 'data_export.php', 'account.php', 'account.php#activity', 'account.php#security'];
            const steps = entries().filter(entry => {
                if (entry.id === 'logout' || visited.has(entry.id)) return false;
                visited.add(entry.id); return true;
            }).sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
            if (!steps.length) return;
            const view = dialog('Welcome to PRISM');
            view.content.setAttribute('aria-live', 'polite');
            view.content.setAttribute('aria-atomic', 'true');
            let index = -1, recorded = false;
            const complete = (value, restore = true) => { recorded = true; remember(value); view.close(restore); };
            view.element.addEventListener('cancel', () => { recorded = true; remember('skipped'); });
            view.element.addEventListener('close', () => { if (!recorded) remember('skipped'); });
            function render() {
                view.content.replaceChildren();
                view.actions.replaceChildren();
                if (index < 0) {
                    view.heading.textContent = 'Welcome to PRISM';
                    let done = false;
                    try { done = !!localStorage.getItem(key); } catch (_) {}
                    view.content.append(node('p', done ? 'Take the quick tour again at your own pace.' : 'Would you like a quick tour? You can restart it from your account menu at any time.'));
                    const start = button('Start Tour', () => { index = 0; render(); }, true);
                    view.actions.append(button('Maybe Later / Skip', () => complete('skipped')), start);
                    start.focus();
                    return;
                }
                const step = steps[index];
                view.heading.textContent = step.label;
                const progress = node('p', `Step ${index + 1} of ${steps.length}`, 'prism-tour-progress');
                progress.setAttribute('aria-live', 'polite');
                view.content.append(progress, node('p', step.description));
                // Destination activation is explicit; stepping never navigates or changes records.
                view.content.append(button(`Open ${step.label}`, () => { complete('skipped', false); step.target.click(); }));
                const back = button('Back', () => { index--; render(); });
                back.disabled = index === 0;
                const next = button(index === steps.length - 1 ? 'Finish' : 'Next', () => {
                    if (index === steps.length - 1) complete('finished');
                    else { index++; render(); }
                }, true);
                view.actions.append(button('Skip', () => complete('skipped')), back, next);
                next.focus();
            }
            render();
        }
        menu.querySelector('[data-prism-tour]')?.addEventListener('click', tour);
        menu.querySelector('[data-prism-guide]')?.addEventListener('click', guide);
    });
})();
