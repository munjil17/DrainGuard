document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("drainSearch");
    const conditionFilter = document.getElementById("conditionFilter");
    const riskFilter = document.getElementById("riskFilter");
    const rows = document.querySelectorAll(".dr-row");

    const filterToggleBtn = document.getElementById("filterToggleBtn");
    const filterPanel = document.getElementById("filterPanel");
    const clearFilterBtn = document.getElementById("clearFilterBtn");

    function filterDrainRows() {
        const searchValue = (searchInput?.value || "").toLowerCase().trim();
        const conditionValue = conditionFilter?.value || "all";
        const riskValue = riskFilter?.value || "all";

        rows.forEach(function (row) {
            const searchableText = [
                row.dataset.code || "",
                row.dataset.location || "",
                row.dataset.ward || "",
                row.dataset.corporation || ""
            ].join(" ").toLowerCase();

            const rowCondition = row.dataset.condition || "";
            const rowRisk = row.dataset.risk || "";

            const matchesSearch = searchableText.includes(searchValue);
            const matchesCondition = conditionValue === "all" || rowCondition === conditionValue;
            const matchesRisk = riskValue === "all" || rowRisk === riskValue;

            row.style.display = matchesSearch && matchesCondition && matchesRisk ? "" : "none";
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterDrainRows);
    }

    if (conditionFilter) {
        conditionFilter.addEventListener("change", filterDrainRows);
    }

    if (riskFilter) {
        riskFilter.addEventListener("change", filterDrainRows);
    }

    if (filterToggleBtn && filterPanel) {
        filterToggleBtn.addEventListener("click", function () {
            filterPanel.classList.toggle("active");
        });
    }

    if (clearFilterBtn) {
        clearFilterBtn.addEventListener("click", function () {
            if (searchInput) searchInput.value = "";
            if (conditionFilter) conditionFilter.value = "all";
            if (riskFilter) riskFilter.value = "all";

            filterDrainRows();
        });
    }
});