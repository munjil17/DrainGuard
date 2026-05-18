document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("inspectorForm");

    const loginAccess = document.getElementById("login_access");
    const loginAccessCard = document.getElementById("loginAccessCard");

    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirm_password");

    const toggles = document.querySelectorAll(".ai-password-toggle");
    const alerts = document.querySelectorAll(".ai-alert");

    /*
    |--------------------------------------------------------------------------
    | Auto Hide Message After 3 Seconds
    |--------------------------------------------------------------------------
    */

    alerts.forEach(function (alertBox) {
        setTimeout(function () {
            alertBox.style.opacity = "0";
            alertBox.style.transform = "translateY(-8px)";

            setTimeout(function () {
                alertBox.style.display = "none";
            }, 250);
        }, 3000);
    });

    /*
    |--------------------------------------------------------------------------
    | Login Access Yes/No
    |--------------------------------------------------------------------------
    */

    function toggleLoginAccess() {
        if (!loginAccess || !loginAccessCard || !password || !confirmPassword) {
            return;
        }

        if (loginAccess.value === "yes") {
            loginAccessCard.classList.add("show");

            password.setAttribute("required", "required");
            confirmPassword.setAttribute("required", "required");
        } else {
            loginAccessCard.classList.remove("show");

            password.removeAttribute("required");
            confirmPassword.removeAttribute("required");

            password.value = "";
            confirmPassword.value = "";
        }
    }

    if (loginAccess) {
        loginAccess.addEventListener("change", toggleLoginAccess);
        toggleLoginAccess();
    }

    /*
    |--------------------------------------------------------------------------
    | Password Show / Hide
    |--------------------------------------------------------------------------
    */

    toggles.forEach(function (button) {
        button.addEventListener("click", function () {
            const targetId = button.getAttribute("data-target");
            const input = document.getElementById(targetId);
            const icon = button.querySelector("i");

            if (!input || !icon) {
                return;
            }

            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("bi-eye");
                icon.classList.add("bi-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("bi-eye-slash");
                icon.classList.add("bi-eye");
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Frontend Submit Validation
    |--------------------------------------------------------------------------
    */

    if (form) {
        form.addEventListener("submit", function (event) {
            if (!loginAccess) {
                return;
            }

            if (loginAccess.value === "yes") {
                if (!password || !confirmPassword) {
                    return;
                }

                if (password.value.trim().length < 6) {
                    event.preventDefault();
                    alert("Password must be at least 6 characters.");
                    password.focus();
                    return;
                }

                if (password.value !== confirmPassword.value) {
                    event.preventDefault();
                    alert("Password and confirm password do not match.");
                    confirmPassword.focus();
                }
            }
        });
    }
});