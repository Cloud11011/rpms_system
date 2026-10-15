/* Read the current published policies without leaving the login form. */
(() => {
    document.querySelectorAll('.login-access-info').forEach(trigger => trigger.addEventListener('click', event => {
        const trigger = event.currentTarget;
        const dialog = document.createElement('dialog');
        dialog.className = 'login-policy-dialog login-access-dialog';
        const heading = document.createElement('h2');
        heading.id = 'loginAccessTitle';
        heading.textContent = 'How to get a PRISM account';
        dialog.setAttribute('aria-labelledby', heading.id);
        const content = document.createElement('div');
        content.className = 'login-policy-content';
        const intro = document.createElement('p');
        intro.textContent = 'PRISM uses invitations instead of public registration so account roles and research assignments can be managed by RPMS and authorized research advisers.';
        const steps = document.createElement('ol');
        [
            'Receive an invitation: RPMS invites research advisers and students. Authorized research advisers may also invite their students.',
            'Check your email and follow the secure account setup link to create your password.',
            'Sign in with your invited email, complete the required policy steps and your profile, and follow any applicable account approval process.'
        ].forEach(text => { const item = document.createElement('li'); item.textContent = text; steps.append(item); });
        const help = document.createElement('p');
        help.textContent = 'Need access or a new invitation? Contact RPMS or your research adviser. Check your spam folder if you are expecting an invitation email.';
        const contact = document.createElement('p');
        contact.append('RPMS email: ');
        const email = document.createElement('a');
        email.href = 'mailto:rpms.ceu@gmail.com';
        email.textContent = 'rpms.ceu@gmail.com';
        contact.append(email);
        content.append(intro, steps, help, contact);
        const close = document.createElement('button');
        close.type = 'button'; close.textContent = 'Got it';
        close.addEventListener('click', () => dialog.close());
        dialog.append(heading, content, close);
        document.body.append(dialog);
        dialog.addEventListener('close', () => { dialog.remove(); trigger.focus(); }, { once: true });
        dialog.showModal(); close.focus();
    }));
    const links = document.querySelectorAll('.login-form-column > .login-legal-footer a');
    links.forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        const dialog = document.createElement('dialog');
        dialog.className = 'login-policy-dialog';
        const heading = document.createElement('h2');
        heading.id = 'loginPolicyTitle';
        heading.textContent = link.textContent;
        dialog.setAttribute('aria-labelledby', heading.id);
        const content = document.createElement('div');
        content.className = 'login-policy-content';
        content.setAttribute('aria-live', 'polite');
        content.textContent = 'Loading policy...';
        const close = document.createElement('button');
        close.type = 'button';
        close.textContent = 'Close';
        close.addEventListener('click', () => dialog.close());
        dialog.append(heading, content, close);
        document.body.append(dialog);
        dialog.addEventListener('close', () => { dialog.remove(); link.focus(); }, { once: true });
        dialog.showModal();
        close.focus();
        fetch(link.href, { credentials: 'same-origin' }).then(response => {
            if (!response.ok) throw new Error('Policy unavailable');
            return response.text();
        }).then(html => {
            if (!dialog.isConnected) return;
            const policy = new DOMParser().parseFromString(html, 'text/html').querySelector('#legalContent');
            if (!policy) throw new Error('Policy unavailable');
            policy.querySelector('h1')?.remove();
            policy.querySelectorAll('script,style,iframe,form,object,embed').forEach(el => el.remove());
            policy.querySelectorAll('*').forEach(el => {
                [...el.attributes].forEach(attr => {
                    if (/^on/i.test(attr.name) || ['id', 'autofocus', 'srcdoc'].includes(attr.name)) el.removeAttribute(attr.name);
                });
                if (el.hasAttribute('href') && !/^(https?:|mailto:|#|\/|[a-z0-9_.-]+\.php)/i.test(el.getAttribute('href'))) el.removeAttribute('href');
            });
            content.replaceChildren(...policy.childNodes);
        }).catch(() => {
            if (dialog.isConnected) content.textContent = 'Unable to load this policy. Please close this popup and try again.';
        });
    }));
})();
