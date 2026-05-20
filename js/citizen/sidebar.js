document.addEventListener("DOMContentLoaded", function () {
    const mobileToggle = document.getElementById("mobileToggle");
    const sidebar = document.getElementById("sidebar");

    if (!mobileToggle || !sidebar) {
        return;
    }

    let overlay = document.querySelector(".sidebar-overlay");

    if (!overlay) {
        overlay = document.createElement("div");
        overlay.className = "sidebar-overlay";
        document.body.appendChild(overlay);
    }

    function openSidebar() {
        sidebar.classList.add("active");
        overlay.classList.add("active");
        document.body.classList.add("sidebar-open");

        mobileToggle.setAttribute("aria-expanded", "true");
    }

    function closeSidebar() {
        sidebar.classList.remove("active");
        overlay.classList.remove("active");
        document.body.classList.remove("sidebar-open");

        mobileToggle.setAttribute("aria-expanded", "false");
    }

    function toggleSidebar() {
        if (sidebar.classList.contains("active")) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    mobileToggle.setAttribute("aria-controls", "sidebar");
    mobileToggle.setAttribute("aria-expanded", "false");

    mobileToggle.addEventListener("click", toggleSidebar);

    overlay.addEventListener("click", closeSidebar);

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeSidebar();
        }
    });

    window.addEventListener("resize", function () {
        if (window.innerWidth > 992) {
            closeSidebar();
        }
    });

    const menuLinks = sidebar.querySelectorAll(".menu-link");

    menuLinks.forEach(function (link) {
        link.addEventListener("click", function () {
            if (window.innerWidth <= 992) {
                closeSidebar();
            }
        });
    });
});