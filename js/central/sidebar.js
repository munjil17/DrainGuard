document.addEventListener("DOMContentLoaded", function () {
    /*
    |--------------------------------------------------------------------------
    | Central Sidebar Mobile Toggle
    |--------------------------------------------------------------------------
    | Supported IDs:
    | Toggle:
    | - centralMobileToggle
    | - dgCentralMobileToggle
    |
    | Sidebar:
    | - centralSidebar
    | - dgCentralSidebar
    | - sidebar
    |
    | Overlay:
    | - centralSidebarOverlay
    | - dgCentralSidebarOverlay
    |--------------------------------------------------------------------------
    */

    const toggleBtn =
        document.getElementById("centralMobileToggle") ||
        document.getElementById("dgCentralMobileToggle");

    const sidebar =
        document.getElementById("centralSidebar") ||
        document.getElementById("dgCentralSidebar") ||
        document.getElementById("sidebar");

    const overlay =
        document.getElementById("centralSidebarOverlay") ||
        document.getElementById("dgCentralSidebarOverlay");

    const avatarBox = document.getElementById("dgCentralUserAvatar");
    const avatarImage = avatarBox ? avatarBox.querySelector("img") : null;

    function openSidebar() {
        if (!sidebar) return;

        sidebar.classList.add("active");

        if (overlay) {
            overlay.classList.add("active");
        }

        document.body.classList.add("central-sidebar-open");
    }

    function closeSidebar() {
        if (!sidebar) return;

        sidebar.classList.remove("active");

        if (overlay) {
            overlay.classList.remove("active");
        }

        document.body.classList.remove("central-sidebar-open");
    }

    function toggleSidebar(event) {
        if (event) {
            event.stopPropagation();
        }

        if (!sidebar) return;

        if (sidebar.classList.contains("active")) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    function getAvatarInitial() {
        const nameElement = document.querySelector(".dg-central-user-info h4");
        const name = nameElement ? nameElement.textContent.trim() : "C";

        if (!name) {
            return "C";
        }

        return name.charAt(0).toUpperCase();
    }

    function fallbackAvatar() {
        if (!avatarBox) {
            return;
        }

        avatarBox.innerHTML = `<span class="dg-central-user-avatar-initial">${getAvatarInitial()}</span>`;
    }

    if (avatarImage) {
        avatarImage.addEventListener("error", fallbackAvatar);
    }

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener("click", toggleSidebar);
    }

    if (overlay) {
        overlay.addEventListener("click", closeSidebar);
    }

    document.addEventListener("click", function (event) {
        if (!sidebar || !toggleBtn) return;

        const clickedInsideSidebar = sidebar.contains(event.target);
        const clickedToggle = toggleBtn.contains(event.target);

        if (!clickedInsideSidebar && !clickedToggle && window.innerWidth <= 992) {
            closeSidebar();
        }
    });

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

    document.querySelectorAll(".dg-central-menu-link").forEach(function (link) {
        link.addEventListener("click", function () {
            if (window.innerWidth <= 992) {
                closeSidebar();
            }
        });
    });
});