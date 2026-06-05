document.addEventListener("DOMContentLoaded", function () {
    const editBtn = document.getElementById("editProfileBtn");
    const cancelBtn = document.getElementById("cancelEditBtn");

    const viewMode = document.getElementById("profileViewMode");
    const editMode = document.getElementById("profileEditMode");

    const profilePictureInput = document.getElementById("profile_picture");
    const profileCameraBtn = document.getElementById("profileCameraBtn");
    const avatarBox = document.getElementById("profileAvatarBox");

    const profileForm = document.getElementById("profileEditMode");

    function showEditMode() {
        if (!viewMode || !editMode) return;

        viewMode.hidden = true;
        editMode.hidden = false;

        const firstInput = editMode.querySelector("input:not([type='file']), select, textarea");

        if (firstInput) {
            firstInput.focus();
        }
    }

    function showViewMode() {
        if (!viewMode || !editMode) return;

        editMode.hidden = true;
        viewMode.hidden = false;
    }

    function getInitial() {
        const nameInput = document.getElementById("full_name");
        const identityName = document.querySelector(".central-profile-identity h2");

        const nameText = nameInput?.value?.trim() || identityName?.textContent?.trim() || "C";

        return nameText.charAt(0).toUpperCase();
    }

    function fallbackAvatar() {
        if (!avatarBox) return;

        avatarBox.innerHTML = `<span>${getInitial()}</span>`;
    }

    function validateEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    if (editBtn) {
        editBtn.addEventListener("click", showEditMode);
    }

    if (cancelBtn) {
        cancelBtn.addEventListener("click", showViewMode);
    }

    if (profileCameraBtn && profilePictureInput) {
        profileCameraBtn.addEventListener("click", function () {
            profilePictureInput.click();
        });
    }

    const currentImage = document.querySelector(".central-profile-img");

    if (currentImage) {
        currentImage.addEventListener("error", fallbackAvatar);
    }

    if (profilePictureInput) {
        profilePictureInput.addEventListener("change", function () {
            const file = profilePictureInput.files && profilePictureInput.files[0];

            if (!file) {
                return;
            }

            const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
            const maxSize = 2 * 1024 * 1024;

            if (!allowedTypes.includes(file.type)) {
                showWarningModal("Only JPG, PNG, and WEBP images are allowed.");
                profilePictureInput.value = "";
                return;
            }

            if (file.size > maxSize) {
                showWarningModal("Profile picture must be less than 2MB.");
                profilePictureInput.value = "";
                return;
            }

            const reader = new FileReader();

            reader.onload = function (event) {
                if (!avatarBox) return;

                avatarBox.innerHTML = `
                    <img
                        src="${event.target.result}"
                        alt="Profile Preview"
                        class="central-profile-img"
                    >
                `;
            };

            reader.readAsDataURL(file);
        });
    }

    if (profileForm) {
        profileForm.addEventListener("submit", function (event) {
            const fullName = document.getElementById("full_name");
            const email = document.getElementById("user_mail");
            const phone = document.getElementById("phone");
            const employeeCode = document.getElementById("employee_code");
            const gender = document.getElementById("gender");
            const designation = document.getElementById("designation");
            
            const address = document.getElementById("address");
            const officeAddress = document.getElementById("office_address");

            if (!fullName.value.trim()) {
                event.preventDefault();
                showWarningModal("Full name is required.");
                fullName.focus();
                return;
            }

            if (!validateEmail(email.value.trim())) {
                event.preventDefault();
                showWarningModal("Please enter a valid email address.");
                email.focus();
                return;
            }

            if (!phone.value.trim()) {
                event.preventDefault();
                showWarningModal("Phone is required.");
                phone.focus();
                return;
            }

            if (!employeeCode.value.trim()) {
                event.preventDefault();
                showWarningModal("Employee Code is required.");
                employeeCode.focus();
                return;
            }

            if (!gender.value.trim()) {
                event.preventDefault();
                showWarningModal("Gender is required.");
                gender.focus();
                return;
            }

            if (!designation.value.trim()) {
                event.preventDefault();
                showWarningModal("Designation is required.");
                designation.focus();
                return;
            }

            

            if (!address.value.trim()) {
                event.preventDefault();
                showWarningModal("Address is required.");
                address.focus();
                return;
            }

            if (!officeAddress.value.trim()) {
                event.preventDefault();
                showWarningModal("Office address is required.");
                officeAddress.focus();
            }
        });
    }

    const passwordForm = document.getElementById("passwordForm");
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

    document.querySelectorAll(".central-profile-toggle-password").forEach(function (button) {
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

    if (passwordForm) {
        passwordForm.addEventListener("submit", function (event) {
            const currentValue = currentPassword ? currentPassword.value.trim() : "";
            const newValue = newPassword ? newPassword.value.trim() : "";
            const confirmValue = confirmPassword ? confirmPassword.value.trim() : "";

            if (currentValue === "" || newValue === "" || confirmValue === "") {
                event.preventDefault();
                showWarningModal("All password fields are required.");
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
            event.preventDefault();
            const form = this;
            showConfirmModal({
                title: "Change Password",
                message: "Are you sure you want to change your password?",
                confirmText: "Change Password",
                cancelText: "Cancel",
                type: "warning",
                onConfirm: function() {
                    form.submit();
                }
            });
        });
    }

});