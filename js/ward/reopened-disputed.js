document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("rdSearch");
    const typeFilter = document.getElementById("rdTypeFilter");
    const cards = document.querySelectorAll(".rd-card");
    const forms = document.querySelectorAll(".rd-action-form");

    function applyFilters() {
        const searchValue = (searchInput?.value || "").trim().toLowerCase();
        const typeValue = typeFilter?.value || "all";

        cards.forEach(function (card) {
            const cardSearch = card.getAttribute("data-search") || "";
            const cardType = card.getAttribute("data-type") || "";

            const matchesSearch = searchValue === "" || cardSearch.includes(searchValue);
            const matchesType = typeValue === "all" || cardType === typeValue;

            card.classList.toggle("rd-hidden", !(matchesSearch && matchesType));
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", applyFilters);
    }

    if (typeFilter) {
        typeFilter.addEventListener("change", applyFilters);
    }

    forms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            const actionInput = form.querySelector('input[name="action"]');
            const action = actionInput ? actionInput.value : "";

            let message = "Are you sure you want to process this request?";

            if (action === "same_team") {
                message = "Reassign this complaint to the same maintenance team?";
            }

            if (action === "different_team") {
                message = "Move this complaint back to Local Team Assignment for a different team?";
            }

            if (action === "inspector") {
                message = "Send this complaint to Inspector Verification?";
            }

            const confirmed = confirm(message);

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    applyFilters();
});