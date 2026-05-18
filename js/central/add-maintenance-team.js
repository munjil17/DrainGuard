document.addEventListener("DOMContentLoaded", function () {
    const alerts = document.querySelectorAll(".amt-alert");

    alerts.forEach(function (alertBox) {
        setTimeout(function () {
            alertBox.style.opacity = "0";
            alertBox.style.transform = "translateY(-8px)";

            setTimeout(function () {
                alertBox.style.display = "none";
            }, 250);
        }, 8000);
    });
});