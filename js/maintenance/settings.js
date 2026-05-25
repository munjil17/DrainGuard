document.addEventListener("DOMContentLoaded", function () {
    const notificationOptions = document.querySelectorAll(".notification-option input");

    notificationOptions.forEach(function (checkbox) {
        checkbox.addEventListener("change", function () {
            const label = checkbox.closest(".notification-option");
            const text = label ? label.innerText.trim() : "Notification preference";

            if (checkbox.checked) {
                console.log(text + " enabled");
            } else {
                console.log(text + " disabled");
            }
        });
    });
});