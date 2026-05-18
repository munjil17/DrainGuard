document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("riskSearch");
    const riskFilter = document.getElementById("riskFilter");
    const cards = document.querySelectorAll(".hra-card");

    function filterRiskAreas() {
        const searchValue = (searchInput?.value || "").toLowerCase().trim();
        const riskValue = riskFilter?.value || "all";

        cards.forEach(function (card) {
            const searchableText = [
                card.dataset.area || "",
                card.dataset.ward || "",
                card.dataset.thana || "",
                card.dataset.corporation || "",
                card.dataset.issues || ""
            ].join(" ").toLowerCase();

            const cardRisk = card.dataset.risk || "";

            const matchesSearch = searchableText.includes(searchValue);
            const matchesRisk = riskValue === "all" || cardRisk === riskValue;

            card.style.display = matchesSearch && matchesRisk ? "block" : "none";
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterRiskAreas);
    }

    if (riskFilter) {
        riskFilter.addEventListener("change", filterRiskAreas);
    }
});