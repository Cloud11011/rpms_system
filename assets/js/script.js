function togglePassword(inputId, iconId){

    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);

    if(input && icon){

        const control = icon.closest(".toggle-password") || icon;

        control.addEventListener("click", function(){

            if(input.type === "password"){

                input.type = "text";
                icon.classList.replace("fa-eye","fa-eye-slash");

            }else{

                input.type = "password";
                icon.classList.replace("fa-eye-slash","fa-eye");

            }

            if(control.tagName === "BUTTON"){
                const visible = input.type === "text";
                const label = inputId === "confirmPassword" ? "confirm password" : "password";
                control.setAttribute("aria-label", (visible ? "Hide " : "Show ") + label);
                control.setAttribute("aria-pressed", String(visible));
            }

        });

    }

}

togglePassword("password","togglePassword");
togglePassword("confirmPassword","toggleConfirmPassword");
