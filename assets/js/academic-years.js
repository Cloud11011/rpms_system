(function () {
    'use strict';
    document.querySelectorAll('[data-academic-year-manager]').forEach(host => {
        const status = host.querySelector('[role="status"]'), form = host.querySelector('form'), input = form.querySelector('input');
        form.addEventListener('submit', async event => {
            event.preventDefault(); const button = form.querySelector('button');
            await PrismUI.runAction(button, 'Saving…', async () => {
                try {
                    const data = await PrismUI.postJson('academic_years_api.php?action=add', {academicYear: input.value});
                    status.textContent = `${data.academicYear} added. Existing record assignments are unchanged.`; input.value = '';
                    if (window.PRISM_ACADEMIC_CATALOG) window.PRISM_ACADEMIC_CATALOG.academicYears = data.academicYears;
                    document.querySelectorAll('[data-academic="academic-year"]').forEach(select => {
                        const value = select.value;
                        data.academicYears.forEach(year => { if (![...select.options].some(o => o.value === year)) select.add(new Option(year, year)); });
                        select.value = value;
                    });
                    document.dispatchEvent(new CustomEvent('prism:academic-years-changed', {detail: data.academicYears}));
                } catch (e) { status.textContent = e.message; }
            });
        });
    });
})();
