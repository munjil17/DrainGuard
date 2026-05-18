document.addEventListener("DOMContentLoaded", function () {
    const addUserWrapper = document.getElementById("addUserWrapper");
    const addUserBtn = document.getElementById("addUserBtn");

    const searchInput = document.getElementById("userSearchInput");
    const roleFilter = document.getElementById("roleFilter");
    const rows = document.querySelectorAll(".um-user-row");
    const emptyState = document.getElementById("emptyState");

    if (addUserBtn && addUserWrapper) {
        addUserBtn.addEventListener("click", function (event) {
            event.stopPropagation();
            addUserWrapper.classList.toggle("open");
        });

        document.addEventListener("click", function (event) {
            if (!addUserWrapper.contains(event.target)) {
                addUserWrapper.classList.remove("open");
            }
        });
    }

    function filterUsers() {
        const searchValue = searchInput ? searchInput.value.toLowerCase().trim() : "";
        const selectedRole = roleFilter ? roleFilter.value : "all";

        let visibleCount = 0;

        rows.forEach(function (row) {
            const name = row.getAttribute("data-name") || "";
            const email = row.getAttribute("data-email") || "";
            const role = row.getAttribute("data-role") || "";
            const status = row.getAttribute("data-status") || "";
            const assigned = row.getAttribute("data-assigned") || "";

            const matchesSearch =
                name.includes(searchValue) ||
                email.includes(searchValue) ||
                role.includes(searchValue) ||
                status.includes(searchValue) ||
                assigned.includes(searchValue);

            const matchesRole =
                selectedRole === "all" || role === selectedRole;

            if (matchesSearch && matchesRole) {
                row.style.display = "";
                visibleCount++;
            } else {
                row.style.display = "none";
            }
        });

        if (emptyState) {
            emptyState.style.display = visibleCount === 0 ? "block" : "none";
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterUsers);
    }

    if (roleFilter) {
        roleFilter.addEventListener("change", filterUsers);
    }

    filterUsers();
});