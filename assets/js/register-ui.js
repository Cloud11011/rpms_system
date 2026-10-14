(() => {
    'use strict';
    const form = document.getElementById('registerForm');
    if (!form) return;

    const panels = [...form.querySelectorAll('.wiz-panel')];
    const total = panels.length;
    const next = document.getElementById('wizNext');
    const back = document.getElementById('wizBack');
    const submit = document.getElementById('wizSubmit');
    const label = document.getElementById('wizLabel');
    const fill = document.getElementById('wizFill');
    const lead = document.getElementById('wizLead');
    const leads = [
        'First, enter your staff code and who you are.',
        'Last step. Add your email and choose a password.'
    ];
    let step = 1;

    function showStep(number) {
        step = number;
        panels.forEach(panel => {
            panel.hidden = Number(panel.dataset.step) !== step;
        });
        label.textContent = `Step ${step} of ${total}`;
        fill.style.width = `${step / total * 100}%`;
        lead.textContent = leads[step - 1];
        back.hidden = step === 1;
        next.hidden = step === total;
        submit.hidden = step !== total;
        if (step > 1) panels[step - 1].querySelector('input')?.focus();
    }

    next.addEventListener('click', () => {
        for (const field of panels[step - 1].querySelectorAll('input')) {
            if (!field.checkValidity()) {
                field.reportValidity();
                return;
            }
        }
        showStep(step + 1);
    });
    back.addEventListener('click', () => showStep(step - 1));
    form.addEventListener('keydown', event => {
        if (event.key === 'Enter' && step < total && event.target.tagName === 'INPUT') {
            event.preventDefault();
            next.click();
        }
    });
    showStep(1);

    const password = document.getElementById('password');
    const confirmation = document.getElementById('confirmPassword');
    const count = document.getElementById('regCount');
    const lengthRule = document.getElementById('regLen');
    const matchRule = document.getElementById('regMatch');
    const matchText = document.getElementById('regMatchText');
    if (!password || !confirmation) return;

    function updatePasswordFeedback() {
        const length = password.value.length;
        const lengthValid = length >= 12 && length <= 200;
        const matches = confirmation.value.length > 0 && password.value === confirmation.value;

        count.textContent = length;
        lengthRule.className = `setup-rule${lengthValid ? ' ok' : (length > 0 ? ' bad' : '')}`;
        password.classList.toggle('is-bad', length > 0 && !lengthValid);
        matchRule.hidden = confirmation.value.length === 0;
        matchRule.className = `setup-rule${matches ? ' ok' : ' bad'}`;
        matchText.textContent = matches ? 'Passwords match' : 'Passwords do not match yet';
        confirmation.classList.toggle('is-bad', confirmation.value.length > 0 && !matches);
        confirmation.setCustomValidity(confirmation.value.length > 0 && !matches
            ? 'Passwords do not match.'
            : '');
    }

    password.addEventListener('input', updatePasswordFeedback);
    confirmation.addEventListener('input', updatePasswordFeedback);
    updatePasswordFeedback();
})();
