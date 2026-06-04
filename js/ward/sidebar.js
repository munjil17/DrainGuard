document.addEventListener("DOMContentLoaded", function () {
    const sidebarElement = document.getElementById("sidebarOffcanvas");

    if (!sidebarElement || typeof bootstrap === "undefined") {
        return;
    }

    const bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(sidebarElement);
    const menuLinks = sidebarElement.querySelectorAll(".menu-link");

    menuLinks.forEach(function (link) {
        link.addEventListener("click", function () {
            if (window.innerWidth <= 992) {
                bsOffcanvas.hide();
            }
        });
    });
});
document.addEventListener("DOMContentLoaded", function() {
    // Notification Dropdown Click Behavior
    const notificationDetails = document.querySelector(".topbar-notification");
    if (notificationDetails) {
        document.addEventListener("click", function(event) {
            // If click is outside the <details> element entirely
            if (!notificationDetails.contains(event.target)) {
                notificationDetails.removeAttribute("open");
            }
        });
        
        // Prevent closing when clicking INSIDE the dropdown (unless it is a link)
        const dropdown = notificationDetails.querySelector(".notification-dropdown");
        if (dropdown) {
            dropdown.addEventListener("click", function(event) {
                // Let links work normally
                if (event.target.closest("a")) return;
            });
        }
    }
});
