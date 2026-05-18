document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("centralOfficerForm");

    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirm_password");

    const toggleButtons = document.querySelectorAll(".cor-password-toggle");

    /*
    |--------------------------------------------------------------------------
    | Password Show / Hide
    |--------------------------------------------------------------------------
    */

    toggleButtons.forEach(function (button) {
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
    | Frontend Validation
    |--------------------------------------------------------------------------
    */

    if (form) {
        form.addEventListener("submit", function (event) {
            if (!password || !confirmPassword) {
                return;
            }

            const passwordValue = password.value.trim();
            const confirmPasswordValue = confirmPassword.value.trim();

            if (passwordValue.length < 8) {
                event.preventDefault();
                alert("Password must be at least 8 characters.");
                password.focus();
                return;
            }

            if (passwordValue !== confirmPasswordValue) {
                event.preventDefault();
                alert("Password and Confirm Password do not match.");
                confirmPassword.focus();
            }
        });
    }
});