document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("signupForm");

    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirmPassword");

    const toggleButtons = document.querySelectorAll(".toggle-password");

    const profilePhotoInput = document.getElementById("profile_photo");
    const fileNameText = document.getElementById("fileNameText");

    /*
    |--------------------------------------------------------------------------
    | Password Show / Hide
    |--------------------------------------------------------------------------
    */

    toggleButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            const targetId = this.dataset.target;
            const targetInput = document.getElementById(targetId);
            const icon = this.querySelector("i");

            if (!targetInput || !icon) {
                return;
            }

            if (targetInput.type === "password") {
                targetInput.type = "text";

                icon.classList.remove("bi-eye");
                icon.classList.add("bi-eye-slash");
            } else {
                targetInput.type = "password";

                icon.classList.remove("bi-eye-slash");
                icon.classList.add("bi-eye");
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Profile Photo File Name Show
    |--------------------------------------------------------------------------
    */

    if (profilePhotoInput && fileNameText) {
        profilePhotoInput.addEventListener("change", function () {
            if (this.files && this.files.length > 0) {
                fileNameText.textContent = this.files[0].name;
            } else {
                fileNameText.textContent = "No file chosen";
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Frontend Validation
    |--------------------------------------------------------------------------
    */

    if (form) {
        form.addEventListener("submit", function (event) {
            const passwordValue = password ? password.value.trim() : "";
            const confirmValue = confirmPassword ? confirmPassword.value.trim() : "";

            if (passwordValue.length < 6) {
                event.preventDefault();
                alert("Password must be at least 6 characters.");
                password.focus();
                return;
            }

            if (passwordValue !== confirmValue) {
                event.preventDefault();
                alert("Password and confirm password do not match.");
                confirmPassword.focus();
                return;
            }

            if (profilePhotoInput && profilePhotoInput.files.length > 0) {
                const file = profilePhotoInput.files[0];

                const allowedTypes = [
                    "image/jpeg",
                    "image/jpg",
                    "image/png",
                    "image/webp"
                ];

                if (!allowedTypes.includes(file.type)) {
                    event.preventDefault();
                    alert("Only JPG, JPEG, PNG, and WEBP files are allowed.");
                    profilePhotoInput.focus();
                    return;
                }

                if (file.size > 2 * 1024 * 1024) {
                    event.preventDefault();
                    alert("Image size must be less than 2MB.");
                    profilePhotoInput.focus();
                }
            }
        });
    }
});