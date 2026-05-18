document.addEventListener("DOMContentLoaded", function () {
    const verifyButtons = document.querySelectorAll(".verification-item button");

    verifyButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            alert("Complaint verification action will be connected with backend later.");
        });
    });
});