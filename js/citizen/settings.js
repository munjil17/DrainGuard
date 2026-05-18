document.addEventListener("DOMContentLoaded", function () {
    const newPassword = document.getElementById("newPassword");
    const confirmPassword = document.getElementById("confirmPassword");

    if (newPassword && confirmPassword) {
        confirmPassword.addEventListener("input", function () {
            if (confirmPassword.value !== newPassword.value) {
                confirmPassword.setCustomValidity("Passwords do not match.");
            } else {
                confirmPassword.setCustomValidity("");
            }
        });

        newPassword.addEventListener("input", function () {
            if (confirmPassword.value !== "" && confirmPassword.value !== newPassword.value) {
                confirmPassword.setCustomValidity("Passwords do not match.");
            } else {
                confirmPassword.setCustomValidity("");
            }
        });
    }
});