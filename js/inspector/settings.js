document.addEventListener("DOMContentLoaded", function () {
    const profileForm = document.getElementById("inspectorProfileForm");
    const passwordForm = document.getElementById("passwordForm");
    const notificationForm = document.getElementById("notificationForm");
    const toggleButtons = document.querySelectorAll(".toggle-password");

    toggleButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            const targetId = button.dataset.target;
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

    if (profileForm) {
        profileForm.addEventListener("submit", function (event) {
            const fullName = document.getElementById("full_name");
            const email = document.getElementById("email");
            const phone = document.getElementById("phone_number");

            if (!fullName || !email || !phone) {
                return;
            }

            const fullNameValue = fullName.value.trim();
            const emailValue = email.value.trim();
            const phoneValue = phone.value.trim();

            if (fullNameValue.length < 3) {
                event.preventDefault();
                alert("Full name must be at least 3 characters.");
                fullName.focus();
                return;
            }

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
                event.preventDefault();
                alert("Please enter a valid email address.");
                email.focus();
                return;
            }

            if (phoneValue.length < 8 || phoneValue.length > 20) {
                event.preventDefault();
                alert("Phone number must be between 8 and 20 characters.");
                phone.focus();
                return;
            }

            const submitBtn = profileForm.querySelector("button[type='submit']");

            if (submitBtn) {
                submitBtn.innerHTML = "Updating...";
                submitBtn.style.pointerEvents = "none";
            }
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener("submit", function (event) {
            const currentPassword = document.getElementById("current_password");
            const newPassword = document.getElementById("new_password");
            const confirmPassword = document.getElementById("confirm_password");

            if (!currentPassword || !newPassword || !confirmPassword) {
                return;
            }

            const currentValue = currentPassword.value.trim();
            const newValue = newPassword.value.trim();
            const confirmValue = confirmPassword.value.trim();

            if (currentValue === "" || newValue === "" || confirmValue === "") {
                event.preventDefault();
                alert("All password fields are required.");
                return;
            }

            if (newValue.length < 8) {
                event.preventDefault();
                alert("New password must be at least 8 characters.");
                newPassword.focus();
                return;
            }

            if (newValue !== confirmValue) {
                event.preventDefault();
                alert("New password and confirm password do not match.");
                confirmPassword.focus();
                return;
            }

            const confirmChange = confirm("Are you sure you want to change your password?");

            if (!confirmChange) {
                event.preventDefault();
                return;
            }

            const submitBtn = passwordForm.querySelector("button[type='submit']");

            if (submitBtn) {
                submitBtn.innerHTML = "Changing...";
                submitBtn.style.pointerEvents = "none";
            }
        });
    }

    if (notificationForm) {
        notificationForm.addEventListener("submit", function () {
            const submitBtn = notificationForm.querySelector("button[type='submit']");

            if (submitBtn) {
                submitBtn.innerHTML = "Saving...";
                submitBtn.style.pointerEvents = "none";
            }
        });
    }
});