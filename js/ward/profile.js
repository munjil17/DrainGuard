document.addEventListener("DOMContentLoaded", function () {
    const changePhotoBtn = document.getElementById("changePhotoBtn");
    const profileImageInput = document.getElementById("profileImageInput");
    const photoForm = document.getElementById("photoForm");

    if (changePhotoBtn && profileImageInput) {
        changePhotoBtn.addEventListener("click", function () {
            profileImageInput.click();
        });
    }

    if (profileImageInput && photoForm) {
        profileImageInput.addEventListener("change", function () {
            const file = profileImageInput.files[0];

            if (!file) {
                return;
            }

            const allowedTypes = ["image/jpeg", "image/jpg", "image/png", "image/webp"];
            const maxSize = 2 * 1024 * 1024;

            if (!allowedTypes.includes(file.type)) {
                showWarningModal("Only JPG, PNG, or WEBP image is allowed.");
                profileImageInput.value = "";
                return;
            }

            if (file.size > maxSize) {
                showWarningModal("Profile photo must be less than 2MB.");
                profileImageInput.value = "";
                return;
            }

            photoForm.submit();
        });
    }
});