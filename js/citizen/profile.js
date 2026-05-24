document.addEventListener("DOMContentLoaded", function () {
    const photoInput = document.getElementById("profilePhotoInput");
    const photoForm = document.getElementById("photoForm");

    if (photoInput && photoForm) {
        photoInput.addEventListener("change", function () {
            if (photoInput.files && photoInput.files.length > 0) {
                photoForm.submit();
            }
        });
    }
});