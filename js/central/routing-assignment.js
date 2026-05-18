document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("routeSearch");
    const priorityFilter = document.getElementById("priorityFilter");
    const cards = document.querySelectorAll(".ra-card");
    const bulkRouteBtn = document.getElementById("bulkRouteBtn");

    function filterCards() {
        const searchValue = (searchInput?.value || "").toLowerCase().trim();
        const priorityValue = priorityFilter?.value || "all";

        cards.forEach(function (card) {
            const searchableText = [
                card.dataset.code || "",
                card.dataset.issue || "",
                card.dataset.title || "",
                card.dataset.ward || "",
                card.dataset.area || ""
            ].join(" ").toLowerCase();

            const cardPriority = card.dataset.priority || "";

            const matchesSearch = searchableText.includes(searchValue);
            const matchesPriority = priorityValue === "all" || cardPriority === priorityValue;

            card.style.display = matchesSearch && matchesPriority ? "block" : "none";
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterCards);
    }

    if (priorityFilter) {
        priorityFilter.addEventListener("change", filterCards);
    }

    if (bulkRouteBtn) {
        bulkRouteBtn.addEventListener("click", function () {
            alert("Bulk route will be added later. For now, send complaints one by one.");
        });
    }

    const routeButtons = document.querySelectorAll(".ra-route-btn, .ra-different-btn");
    const rejectButtons = document.querySelectorAll(".ra-reject-btn");

    routeButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            const confirmed = confirm("Send this complaint to ward for verification? Tracking status will become Pending Verification.");

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    rejectButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            const confirmed = confirm("Are you sure you want to reject this complaint?");

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    filterCards();
});