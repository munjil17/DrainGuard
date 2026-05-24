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
                alert("Only JPG, PNG, and WEBP images are allowed.");
                profilePictureInput.value = "";
                return;
            }

            if (file.size > maxSize) {
                alert("Profile picture must be less than 2MB.");
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
            const employeeId = document.getElementById("employee_id");
            const gender = document.getElementById("gender");
            const designation = document.getElementById("designation");
            const department = document.getElementById("department");
            const address = document.getElementById("address");
            const officeAddress = document.getElementById("office_address");

            if (!fullName.value.trim()) {
                event.preventDefault();
                alert("Full name is required.");
                fullName.focus();
                return;
            }

            if (!validateEmail(email.value.trim())) {
                event.preventDefault();
                alert("Please enter a valid email address.");
                email.focus();
                return;
            }

            if (!phone.value.trim()) {
                event.preventDefault();
                alert("Phone is required.");
                phone.focus();
                return;
            }

            if (!employeeId.value.trim()) {
                event.preventDefault();
                alert("Employee ID is required.");
                employeeId.focus();
                return;
            }

            if (!gender.value.trim()) {
                event.preventDefault();
                alert("Gender is required.");
                gender.focus();
                return;
            }

            if (!designation.value.trim()) {
                event.preventDefault();
                alert("Designation is required.");
                designation.focus();
                return;
            }

            if (!department.value.trim()) {
                event.preventDefault();
                alert("Department is required.");
                department.focus();
                return;
            }

            if (!address.value.trim()) {
                event.preventDefault();
                alert("Address is required.");
                address.focus();
                return;
            }

            if (!officeAddress.value.trim()) {
                event.preventDefault();
                alert("Office address is required.");
                officeAddress.focus();
            }
        });
    }
});