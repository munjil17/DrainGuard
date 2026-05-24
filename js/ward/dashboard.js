document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("wardTopbarSearch");
    const kpiCards = document.querySelectorAll(".kpi-card");
    const complaintItems = document.querySelectorAll(".complaint-item");

    if (!searchInput) {
        return;
    }

    searchInput.addEventListener("input", function () {
        const searchValue = searchInput.value.trim().toLowerCase();

        kpiCards.forEach(function (card) {
            const text = (card.getAttribute("data-search") || card.textContent).toLowerCase();

            if (text.includes(searchValue)) {
                card.classList.remove("dashboard-hidden");
            } else {
                card.classList.add("dashboard-hidden");
            }
        });

        complaintItems.forEach(function (item) {
            const text = (item.getAttribute("data-search") || item.textContent).toLowerCase();

            if (text.includes(searchValue)) {
                item.classList.remove("dashboard-hidden");
            } else {
                item.classList.add("dashboard-hidden");
            }
        });
    });
});