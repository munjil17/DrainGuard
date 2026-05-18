document.addEventListener("DOMContentLoaded", function () {
    /*
    |--------------------------------------------------------------------------
    | Central Sidebar Mobile Toggle
    |--------------------------------------------------------------------------
    | Supports both old and new IDs:
    | - Toggle button: centralMobileToggle / dgCentralMobileToggle
    | - Sidebar: sidebar / dgCentralSidebar
    |--------------------------------------------------------------------------
    */

    const toggleBtn =
        document.getElementById("centralMobileToggle") ||
        document.getElementById("dgCentralMobileToggle");

    const sidebar =
        document.getElementById("sidebar") ||
        document.getElementById("dgCentralSidebar");

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener("click", function (event) {
            event.stopPropagation();
            sidebar.classList.toggle("active");
        });
    }

    document.addEventListener("click", function (event) {
        if (!sidebar || !toggleBtn) return;

        const clickedInsideSidebar = sidebar.contains(event.target);
        const clickedToggle = toggleBtn.contains(event.target);

        if (!clickedInsideSidebar && !clickedToggle) {
            sidebar.classList.remove("active");
        }
    });

    window.addEventListener("resize", function () {
        if (window.innerWidth > 992 && sidebar) {
            sidebar.classList.remove("active");
        }
    });
});