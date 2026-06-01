document.addEventListener("DOMContentLoaded", function () {
    // Password Visibility Toggle
    const toggleBtns = document.querySelectorAll(".cor-password-toggle");

    toggleBtns.forEach(btn => {
        btn.addEventListener("click", function () {
            const targetId = this.getAttribute("data-target");
            const input = document.getElementById(targetId);
            const icon = this.querySelector("i");

            if (input.type === "password") {
                input.type = "text";
                icon.classList.replace("bi-eye", "bi-eye-slash");
            } else {
                input.type = "password";
                icon.classList.replace("bi-eye-slash", "bi-eye");
            }
        });
    });
});