document.addEventListener("DOMContentLoaded", function () {
    const profilePhotoButton = document.getElementById("profilePhotoButton");
    const profileImageInput = document.getElementById("profileImageInput");
    const profilePhotoForm = document.getElementById("profilePhotoForm");
    const profilePhotoPreview = document.getElementById("profilePhotoPreview");

    const profileInfoForm = document.getElementById("profileInfoForm");
    const passwordForm = document.getElementById("passwordForm");

    const allowedImageTypes = ["image/jpeg", "image/png", "image/webp", "image/gif"];
    const maxImageSize = 5 * 1024 * 1024;

    function setButtonLoading(button, text) {
        if (!button) return;

        button.disabled = true;
        button.classList.add("is-loading");
        button.dataset.originalHtml = button.innerHTML;
        button.innerHTML = '<i class="bi bi-hourglass-split"></i> ' + text;
    }

    function resetButton(button) {
        if (!button) return;

        button.disabled = false;
        button.classList.remove("is-loading");

        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
        }
    }

    if (profilePhotoButton && profileImageInput && profilePhotoForm && profilePhotoPreview) {
        profilePhotoButton.addEventListener("click", function () {
            profileImageInput.click();
        });

        profileImageInput.addEventListener("change", function () {
            const file = profileImageInput.files[0];

            if (!file) {
                return;
            }

            if (!allowedImageTypes.includes(file.type)) {
                showWarningModal("Only JPG, PNG, WEBP, or GIF image is allowed.");
                profileImageInput.value = "";
                return;
            }

            if (file.size > maxImageSize) {
                showWarningModal("Profile photo must be 5MB or less.");
                profileImageInput.value = "";
                return;
            }

            showConfirmModal({
                title: "Update Photo",
                message: "Update your profile photo?",
                confirmText: "Update",
                cancelText: "Cancel",
                type: "confirm",
                onConfirm: function() {
                    const formData = new FormData(profilePhotoForm);

                    profilePhotoButton.classList.add("is-loading");

                    fetch("profile.php", {
                        method: "POST",
                        body: formData
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (data) {
                            showWarningModal(data.message || "Request processed.");

                            if (data.success && data.image_path) {
                                profilePhotoPreview.innerHTML = "";

                                const img = document.createElement("img");
                                img.src = data.image_path + "?t=" + new Date().getTime();
                                img.alt = "Profile photo";

                                profilePhotoPreview.appendChild(img);

                                setTimeout(function () {
                                    window.location.reload();
                                }, 500);
                            } else {
                                profileImageInput.value = "";
                                profilePhotoButton.classList.remove("is-loading");
                            }
                        })
                        .catch(function () {
                            showWarningModal("Failed to update profile photo. Please try again.");
                            profileImageInput.value = "";
                            profilePhotoButton.classList.remove("is-loading");
                        });
                },
                onCancel: function() {
                    profileImageInput.value = "";
                }
            });
        });
    }

    if (profileInfoForm) {
        profileInfoForm.addEventListener("submit", function (event) {
            event.preventDefault();

            const submitBtn = profileInfoForm.querySelector(".update-btn");

            const fullName = profileInfoForm.querySelector('input[name="full_name"]');
            const phone = profileInfoForm.querySelector('input[name="phone_number"]');
            const email = profileInfoForm.querySelector('input[name="user_mail"]');
            const employeeCode = profileInfoForm.querySelector('input[name="employee_code"]');
            const address = profileInfoForm.querySelector('textarea[name="address"]');

            if (!fullName.value.trim()) {
                showWarningModal("Full name is required.");
                fullName.focus();
                return;
            }

            if (!phone.value.trim()) {
                showWarningModal("Phone number is required.");
                phone.focus();
                return;
            }

            if (!email.value.trim()) {
                showWarningModal("Email is required.");
                email.focus();
                return;
            }

            if (!employeeCode.value.trim()) {
                showWarningModal("Employee code is required.");
                employeeCode.focus();
                return;
            }

            if (!address.value.trim()) {
                showWarningModal("Address is required.");
                address.focus();
                return;
            }

            showConfirmModal({
                title: "Update Profile",
                message: "Update profile information?",
                confirmText: "Update",
                cancelText: "Cancel",
                type: "confirm",
                onConfirm: function() {
                    setButtonLoading(submitBtn, "Updating...");

                    const formData = new FormData(profileInfoForm);

                    fetch("profile.php", {
                        method: "POST",
                        body: formData
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (data) {
                            showWarningModal(data.message || "Request processed.");

                            if (data.success) {
                                window.location.reload();
                            } else {
                                resetButton(submitBtn);
                            }
                        })
                        .catch(function () {
                            showWarningModal("Failed to update profile. Please try again.");
                            resetButton(submitBtn);
                        });
                }
            });
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener("submit", function (event) {
            event.preventDefault();

            const submitBtn = passwordForm.querySelector(".password-btn");

            const currentPassword = passwordForm.querySelector('input[name="current_password"]');
            const newPassword = passwordForm.querySelector('input[name="new_password"]');
            const confirmPassword = passwordForm.querySelector('input[name="confirm_password"]');

            if (!currentPassword.value.trim()) {
                showWarningModal("Current password is required.");
                currentPassword.focus();
                return;
            }

            if (newPassword.value.length < 8) {
                showWarningModal("New password must be at least 8 characters.");
                newPassword.focus();
                return;
            }

            if (newPassword.value !== confirmPassword.value) {
                showWarningModal("New password and confirm password do not match.");
                confirmPassword.focus();
                return;
            }

            showConfirmModal({
                title: "Change Password",
                message: "Change your password?",
                confirmText: "Change",
                cancelText: "Cancel",
                type: "warning",
                onConfirm: function() {
                    setButtonLoading(submitBtn, "Changing Password...");

                    const formData = new FormData(passwordForm);

                    fetch("profile.php", {
                        method: "POST",
                        body: formData
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (data) {
                            showWarningModal(data.message || "Request processed.");

                            if (data.success) {
                                passwordForm.reset();
                            }

                            resetButton(submitBtn);
                        })
                        .catch(function () {
                            showWarningModal("Failed to change password. Please try again.");
                            resetButton(submitBtn);
                        });
                }
            });
        });
    }

    const toggleButtons = document.querySelectorAll(".toggle-password");

    toggleButtons.forEach(function (button) {
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
});