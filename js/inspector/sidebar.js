document.addEventListener("DOMContentLoaded", function () {

    const links = document.querySelectorAll(".menu-link");

    links.forEach(function (link) {

        link.addEventListener("click", function () {

            links.forEach(function (item) {
                item.classList.remove("active");
            });

            this.classList.add("active");

        });

    });

});