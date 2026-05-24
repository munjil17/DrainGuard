document.addEventListener("DOMContentLoaded", function () {
    const profileForm = document.getElementById("profileForm");
    const passwordForm = document.getElementById("passwordForm");

    const fullName = document.getElementById("full_name");
    const email = document.getElementById("email");
    const phone = document.getElementById("phone");

    const currentPassword = document.getElementById("current_password");
    const newPassword = document.getElementById("new_password");
    const confirmPassword = document.getElementById("confirm_password");

    const passwordHelp = document.getElementById("passwordHelp");
    const confirmHelp = document.getElementById("confirmHelp");

    function setHelp(element, message, type) {
        if (!element) return;

        element.textContent = message || "";
        element.classList.remove("error", "success");

        if (type) {
            element.classList.add(type);
        }
    }

    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    document.querySelectorAll(".settings-toggle-password").forEach(function (button) {
        button.addEventListener("click", function () {
            const targetId = button.getAttribute("data-target");
            const input = document.getElementById(targetId);
            const icon = button.querySelector("i");

            if (!input || !icon) return;

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

    if (newPassword) {
        newPassword.addEventListener("input", function () {
            const value = newPassword.value.trim();

            if (value.length === 0) {
                setHelp(passwordHelp, "Minimum 8 characters required.", "");
                return;
            }

            if (value.length < 8) {
                setHelp(passwordHelp, "Password must be at least 8 characters.", "error");
                return;
            }

            setHelp(passwordHelp, "Password length is valid.", "success");
        });
    }

    if (confirmPassword) {
        confirmPassword.addEventListener("input", function () {
            const newValue = newPassword ? newPassword.value.trim() : "";
            const confirmValue = confirmPassword.value.trim();

            if (confirmValue.length === 0) {
                setHelp(confirmHelp, "", "");
                return;
            }

            if (newValue !== confirmValue) {
                setHelp(confirmHelp, "Passwords do not match.", "error");
                return;
            }

            setHelp(confirmHelp, "Passwords match.", "success");
        });
    }

    if (profileForm) {
        profileForm.addEventListener("submit", function (event) {
            const nameValue = fullName ? fullName.value.trim() : "";
            const emailValue = email ? email.value.trim() : "";
            const phoneValue = phone ? phone.value.trim() : "";

            if (nameValue.length < 2) {
                event.preventDefault();
                alert("Full name must be at least 2 characters.");
                fullName.focus();
                return;
            }

            if (!isValidEmail(emailValue)) {
                event.preventDefault();
                alert("Please enter a valid email address.");
                email.focus();
                return;
            }

            if (phoneValue.length < 8) {
                event.preventDefault();
                alert("Phone number looks too short.");
                phone.focus();
            }
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener("submit", function (event) {
            const currentValue = currentPassword ? currentPassword.value.trim() : "";
            const newValue = newPassword ? newPassword.value.trim() : "";
            const confirmValue = confirmPassword ? confirmPassword.value.trim() : "";

            if (currentValue === "" || newValue === "" || confirmValue === "") {
                event.preventDefault();
                alert("All password fields are required.");
                return;
            }

            if (newValue.length < 8) {
                event.preventDefault();
                setHelp(passwordHelp, "Password must be at least 8 characters.", "error");
                newPassword.focus();
                return;
            }

            if (newValue !== confirmValue) {
                event.preventDefault();
                setHelp(confirmHelp, "Passwords do not match.", "error");
                confirmPassword.focus();
                return;
            }

            const confirmed = confirm("Are you sure you want to change your password?");

            if (!confirmed) {
                event.preventDefault();
            }
        });
    }
});