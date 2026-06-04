document.addEventListener("DOMContentLoaded", function () {
    const sidebarToggle = document.querySelector("[data-sidebar-toggle]");

    if (sidebarToggle) {
        sidebarToggle.addEventListener("click", function () {
            document.body.classList.toggle("sidebar-open");
        });
    }

    const sidebarLinks = document.querySelectorAll(".maintenance-sidebar .menu-link, .maintenance-sidebar .profile-btn");

    sidebarLinks.forEach(function (link) {
        link.addEventListener("click", function () {
            if (window.innerWidth <= 900) {
                document.body.classList.remove("sidebar-open");
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