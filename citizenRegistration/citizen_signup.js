document.addEventListener("DOMContentLoaded", function () {
    // Password Toggle Feature
    const toggleButtons = document.querySelectorAll(".toggle-password");
    toggleButtons.forEach(btn => {
        btn.addEventListener("click", function () {
            const targetId = this.getAttribute("data-target");
            const input = document.getElementById(targetId);
            const icon = this.querySelector("i");
            
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

    // File Upload Name Display update
    const photoInput = document.getElementById("profile_photo");
    const fileNameText = document.getElementById("fileNameText");
    
    photoInput.addEventListener("change", function () {
        if (this.files && this.files.length > 0) {
            fileNameText.textContent = this.files[0].name;
            fileNameText.style.color = "#31F6E6"; // Highlight when file is chosen
        } else {
            fileNameText.textContent = "No file chosen";
            fileNameText.style.color = "#8EA2C8";
        }
    });
});