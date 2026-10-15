function togglePassword(inputId, iconId){

    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);

    if(input && icon){

        const control = icon.closest(".toggle-password") || icon;

        if(control.tagName !== "BUTTON"){
            control.setAttribute("role","button"); control.tabIndex = 0;
            control.addEventListener("keydown", event => {if(event.key === "Enter" || event.key === " "){event.preventDefault();control.click();}});
        }
        const passwordLabel = control.dataset.passwordLabel || (inputId.toLowerCase().includes('confirm') ? "confirm password" : inputId.toLowerCase().includes('current') ? "current password" : inputId.toLowerCase().includes('new') ? "new password" : "password");
        control.setAttribute("aria-label", "Show " + passwordLabel); control.setAttribute("aria-pressed","false");

        control.addEventListener("click", function(event){
            event.preventDefault(); // Keep a keyboard toggle inside a label focused on the control.

            if(input.type === "password"){

                input.type = "text";
                icon.classList.replace("fa-eye","fa-eye-slash");

            }else{

                input.type = "password";
                icon.classList.replace("fa-eye-slash","fa-eye");

            }

            {
                const visible = input.type === "text";
                const label = control.dataset.passwordLabel || (inputId.toLowerCase().includes('confirm') ? "confirm password" : inputId.toLowerCase().includes('current') ? "current password" : inputId.toLowerCase().includes('new') ? "new password" : "password");
                control.setAttribute("aria-label", (visible ? "Hide " : "Show ") + label);
                control.setAttribute("aria-pressed", String(visible));
            }

        });

    }

}

togglePassword("password","togglePassword");
togglePassword("confirmPassword","toggleConfirmPassword");
