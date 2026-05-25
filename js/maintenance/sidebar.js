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