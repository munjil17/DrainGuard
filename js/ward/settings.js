document.addEventListener("DOMContentLoaded", function () {
    const profileForm = document.getElementById("wardProfileForm");
    const passwordForm = document.getElementById("wardPasswordForm");

    if (profileForm) {
        profileForm.addEventListener("submit", function (event) {
            const fullName = profileForm.querySelector('input[name="full_name"]');
            const email = profileForm.querySelector('input[name="user_mail"]');
            const phone = profileForm.querySelector('input[name="phone_number"]');

            if (!fullName || fullName.value.trim() === "") {
                event.preventDefault();
                alert("Full name is required.");
                fullName?.focus();
                return;
            }

            if (!email || email.value.trim() === "") {
                event.preventDefault();
                alert("Email is required.");
                email?.focus();
                return;
            }

            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!emailPattern.test(email.value.trim())) {
                event.preventDefault();
                alert("Please enter a valid email address.");
                email.focus();
                return;
            }

            if (!phone || phone.value.trim() === "") {
                event.preventDefault();
                alert("Phone number is required.");
                phone?.focus();
                return;
            }
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener("submit", function (event) {
            const currentPassword = document.getElementById("currentPassword");
            const newPassword = document.getElementById("newPassword");
            const confirmPassword = document.getElementById("confirmPassword");

            if (!currentPassword || currentPassword.value.trim() === "") {
                event.preventDefault();
                alert("Current password is required.");
                currentPassword?.focus();
                return;
            }

            if (!newPassword || newPassword.value.trim() === "") {
                event.preventDefault();
                alert("New password is required.");
                newPassword?.focus();
                return;
            }

            if (newPassword.value.length < 8) {
                event.preventDefault();
                alert("New password must be at least 8 characters.");
                newPassword.focus();
                return;
            }

            if (!confirmPassword || confirmPassword.value.trim() === "") {
                event.preventDefault();
                alert("Confirm password is required.");
                confirmPassword?.focus();
                return;
            }

            if (newPassword.value !== confirmPassword.value) {
                event.preventDefault();
                alert("New password and confirm password do not match.");
                confirmPassword.focus();
            }
        });
    }
});