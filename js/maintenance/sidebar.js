document.addEventListener("DOMContentLoaded", function () {

    const sidebarLinks = document.querySelectorAll(".menu-link");

    sidebarLinks.forEach(function (link) {

        link.addEventListener("click", function () {

            sidebarLinks.forEach(function (item) {
                item.classList.remove("active");
            });

            this.classList.add("active");

        });

    });

});