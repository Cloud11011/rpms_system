(() => {
    'use strict';
    const form = document.getElementById('completeProfileForm');
    if (!form) return;

    const total = Number.parseInt(form.dataset.steps || '1', 10);
    const panels = [...form.querySelectorAll('.wiz-panel')];
    const next = document.getElementById('wizNext');
    const back = document.getElementById('wizBack');
    const submit = document.getElementById('wizSubmit');
    const label = document.getElementById('wizLabel');
    const fill = document.getElementById('wizFill');
    const lead = document.getElementById('wizLead');
    const leads = [
        'Your profile is Pending. Complete these details to use the workspace.',
        'Last step. Tell us about your studies.'
    ];
    let step = 1;

    function showStep(number) {
        step = number;
        panels.forEach(panel => {
            panel.hidden = Number(panel.dataset.step) !== step;
        });
        label.textContent = `Step ${step} of ${total}`;
        fill.style.width = `${step / total * 100}%`;
        if (total > 1) lead.textContent = leads[step - 1];
        back.hidden = step === 1;
        next.hidden = step === total;
        submit.hidden = step !== total;
        if (step > 1) panels[step - 1].querySelector('input,select')?.focus();
    }

    next.addEventListener('click', () => {
        const fields = panels[step - 1].querySelectorAll('input,select');
        for (const field of fields) {
            if (!field.checkValidity()) {
                field.reportValidity();
                return;
            }
        }
        showStep(step + 1);
    });
    back.addEventListener('click', () => showStep(step - 1));
    form.addEventListener('submit', event => {
        if (step < total) {
            event.preventDefault();
            event.stopImmediatePropagation();
            next.click();
        }
    }, true);
    showStep(1);
})();
