document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("myComplaintSearch");
    const statusFilter = document.getElementById("myStatusFilter");
    const rows = document.querySelectorAll(".mc-row");

    function normalize(value) {
        return String(value || "").toLowerCase().trim();
    }

    function filterComplaints() {
        const searchValue = normalize(searchInput ? searchInput.value : "");
        const selectedStatus = statusFilter ? statusFilter.value : "all";

        rows.forEach(function (row) {
            const code = normalize(row.dataset.code);
            const issue = normalize(row.dataset.issue);
            const area = normalize(row.dataset.area);
            const status = row.dataset.status || "";

            const matchesSearch =
                searchValue === "" ||
                code.includes(searchValue) ||
                issue.includes(searchValue) ||
                area.includes(searchValue);

            const matchesStatus =
                selectedStatus === "all" || status === selectedStatus;

            row.style.display = matchesSearch && matchesStatus ? "" : "none";
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterComplaints);
    }

    if (statusFilter) {
        statusFilter.addEventListener("change", filterComplaints);
    }
});