/* Lifecycle forms reuse Login/Register's togglePassword() and eye icons. */
(function () {
    'use strict';
    function resetPassword(form) {
        form.querySelectorAll('[data-lifecycle-password]').forEach(button => {
            const input=document.getElementById(button.getAttribute('aria-controls'));
            input.type='password';
            button.setAttribute('aria-label','Show password');
            button.setAttribute('aria-pressed','false');
            button.querySelector('i').classList.replace('fa-eye-slash','fa-eye');
        });
    }
    document.querySelectorAll('[data-lifecycle-password]').forEach(button => {
        togglePassword(button.getAttribute('aria-controls'),button.querySelector('i').id);
    });
    document.querySelectorAll('.lifecycle-form').forEach(form => {
        form.addEventListener('reset',()=>resetPassword(form));
    });
    window.PrismLifecycleForms={resetPassword};
})();
