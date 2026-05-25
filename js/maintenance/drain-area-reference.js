document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("referenceSearchInput");
    const conditionFilter = document.getElementById("conditionFilter");
    const riskFilter = document.getElementById("riskFilter");
    const filterEmptyState = document.getElementById("filterEmptyState");

    function getItems() {
        return Array.from(document.querySelectorAll(".reference-item"));
    }

    function filterItems() {
        const items = getItems();

        const searchValue = searchInput ? searchInput.value.trim().toLowerCase() : "";
        const conditionValue = conditionFilter ? conditionFilter.value : "all";
        const riskValue = riskFilter ? riskFilter.value : "all";

        items.forEach(function (item) {
            const searchableText = item.getAttribute("data-search") || "";
            const condition = item.getAttribute("data-condition") || "";
            const risk = item.getAttribute("data-risk") || "";

            const matchesSearch = searchableText.includes(searchValue);
            const matchesCondition = conditionValue === "all" || condition === conditionValue;
            const matchesRisk = riskValue === "all" || risk === riskValue;

            item.style.display = matchesSearch && matchesCondition && matchesRisk
                ? "block"
                : "none";
        });

        const visibleItems = items.filter(function (item) {
            return item.style.display !== "none";
        });

        if (filterEmptyState) {
            filterEmptyState.style.display = items.length > 0 && visibleItems.length === 0
                ? "block"
                : "none";
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterItems);
    }

    if (conditionFilter) {
        conditionFilter.addEventListener("change", filterItems);
    }

    if (riskFilter) {
        riskFilter.addEventListener("change", filterItems);
    }

    filterItems();
});