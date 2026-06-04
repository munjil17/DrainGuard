document.addEventListener("DOMContentLoaded", function () {
    const sidebar = document.querySelector(".inspector-sidebar");
    const menuLinks = document.querySelectorAll(".menu-link");
    const sidebarToggle = document.querySelector(".sidebar-toggle");
    const mobileToggle = document.querySelector(".mobile-toggle");

    menuLinks.forEach(function (link) {
        link.addEventListener("click", function () {
            if (window.innerWidth <= 991) {
                document.body.classList.remove("sidebar-open");

                if (sidebar) {
                    sidebar.classList.remove("show");
                }
            }
        });
    });

    if (sidebarToggle) {
        sidebarToggle.addEventListener("click", function () {
            document.body.classList.toggle("sidebar-open");

            if (sidebar) {
                sidebar.classList.toggle("show");
            }
        });
    }

    if (mobileToggle) {
        mobileToggle.addEventListener("click", function () {
            document.body.classList.toggle("sidebar-open");

            if (sidebar) {
                sidebar.classList.toggle("show");
            }
        });
    }

    document.addEventListener("click", function (event) {
        const clickedInsideSidebar = sidebar && sidebar.contains(event.target);
        const clickedToggle =
            (sidebarToggle && sidebarToggle.contains(event.target)) ||
            (mobileToggle && mobileToggle.contains(event.target));

        if (window.innerWidth <= 991 && !clickedInsideSidebar && !clickedToggle) {
            document.body.classList.remove("sidebar-open");

            if (sidebar) {
                sidebar.classList.remove("show");
            }
        }
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