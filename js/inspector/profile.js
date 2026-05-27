document.addEventListener("DOMContentLoaded", function () {
    const profileImageInput = document.getElementById("profile_image");
    const profileImageForm = document.getElementById("profileImageForm");
    const profilePreview = document.getElementById("profilePreview");
    const profilePlaceholder = document.getElementById("profilePlaceholder");
    const profileForm = document.getElementById("inspectorProfileForm");

    if (profileImageInput && profileImageForm) {
        profileImageInput.addEventListener("change", function () {
            const file = this.files && this.files[0];

            if (!file) {
                return;
            }

            const allowedTypes = ["image/jpeg", "image/jpg", "image/png", "image/webp"];

            if (!allowedTypes.includes(file.type)) {
                alert("Only JPG, PNG, or WEBP image is allowed.");
                this.value = "";
                return;
            }

            if (file.size > 3 * 1024 * 1024) {
                alert("Profile image must be less than 3MB.");
                this.value = "";
                return;
            }

            const reader = new FileReader();

            reader.onload = function (event) {
                if (profilePreview) {
                    profilePreview.src = event.target.result;
                    profilePreview.classList.remove("hidden-preview");
                    profilePreview.style.display = "block";
                }

                if (profilePlaceholder) {
                    profilePlaceholder.style.display = "none";
                }

                const confirmUpload = confirm("Upload this image as your profile picture?");

                if (confirmUpload) {
                    profileImageForm.submit();
                } else {
                    profileImageInput.value = "";
                    window.location.reload();
                }
            };

            reader.readAsDataURL(file);
        });
    }

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
});