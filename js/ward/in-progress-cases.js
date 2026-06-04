document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("ipcSearch");
    const statusFilter = document.getElementById("ipcStatusFilter");
    const rows = document.querySelectorAll(".ipc-row");
    const emptyState = document.getElementById("ipcEmptyState");

    function applyFilters() {
        const searchValue = (searchInput?.value || "").trim().toLowerCase();
        const statusValue = statusFilter?.value || "all";

        let visibleCount = 0;

        rows.forEach(function (row) {
            const rowSearch = row.getAttribute("data-search") || "";
            const rowStatus = row.getAttribute("data-status") || "";

            const matchesSearch = searchValue === "" || rowSearch.includes(searchValue);
            const matchesStatus = statusValue === "all" || rowStatus === statusValue;

            if (matchesSearch && matchesStatus) {
                row.classList.remove("ipc-hidden");
                visibleCount++;
            } else {
                row.classList.add("ipc-hidden");
            }
        });

        if (emptyState) {
            if (visibleCount === 0) {
                emptyState.style.display = "";
            } else {
                emptyState.style.display = "none";
            }
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", applyFilters);
    }

    if (statusFilter) {
        statusFilter.addEventListener("change", applyFilters);
    }

    applyFilters();
});