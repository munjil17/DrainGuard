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