document.addEventListener("DOMContentLoaded", function () {
    const filterForm = document.getElementById("citizenObjectionsFilterForm");
    const objectionCards = document.querySelectorAll(".objection-card");
    const actionForms = document.querySelectorAll(".objection-actions");

    objectionCards.forEach(function (card, index) {
        card.style.animationDelay = `${index * 70}ms`;
        card.classList.add("objection-card-show");
    });

    if (filterForm) {
        const searchInput = filterForm.querySelector('input[name="search"]');
        const selectInputs = filterForm.querySelectorAll("select");

        let searchTimer = null;

        function resetPageAndSubmit() {
            const pageInput = filterForm.querySelector('input[name="page"]');

            if (pageInput) {
                pageInput.remove();
            }

            filterForm.submit();
        }

        if (searchInput) {
            searchInput.addEventListener("input", function () {
                clearTimeout(searchTimer);

                searchTimer = setTimeout(function () {
                    resetPageAndSubmit();
                }, 500);
            });

            searchInput.addEventListener("keydown", function (event) {
                if (event.key === "Enter") {
                    event.preventDefault();
                    resetPageAndSubmit();
                }
            });
        }

        selectInputs.forEach(function (select) {
            select.addEventListener("change", function () {
                resetPageAndSubmit();
            });
        });
    }


});