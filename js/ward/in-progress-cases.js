document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("ipcSearch");
    const statusFilter = document.getElementById("ipcStatusFilter");
    const rows = document.querySelectorAll(".ipc-row");
    const emptyState = document.getElementById("ipcEmptyState");
    const changeTeamModal = document.getElementById("ipcChangeTeamModal");
    const changeTeamForm = document.getElementById("ipcChangeTeamForm");
    const changeComplaintId = document.getElementById("ipcChangeComplaintId");
    const changeAssignmentId = document.getElementById("ipcChangeAssignmentId");
    const changeCurrentTeam = document.getElementById("ipcChangeCurrentTeam");
    const changeNewTeam = document.getElementById("ipcChangeNewTeam");

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

    function warn(message) {
        if (typeof showWarningModal === "function") {
            showWarningModal(message);
            return;
        }

        window.alert(message);
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add("active");
        modal.setAttribute("aria-hidden", "false");
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove("active");
        modal.setAttribute("aria-hidden", "true");
    }

    document.querySelectorAll(".ipc-change-team-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            if (changeComplaintId) changeComplaintId.value = button.dataset.complaintId || "";
            if (changeAssignmentId) changeAssignmentId.value = button.dataset.assignmentId || "";
            if (changeCurrentTeam) changeCurrentTeam.textContent = button.dataset.currentTeam || "Current Team";
            if (changeNewTeam) {
                changeNewTeam.value = "";
                Array.from(changeNewTeam.options).forEach(function (option) {
                    option.hidden = option.value !== "" && option.value === (button.dataset.currentTeamId || "");
                });
            }
            openModal(changeTeamModal);
        });
    });

    document.querySelectorAll("[data-close-ipc-change-team]").forEach(function (button) {
        button.addEventListener("click", function () {
            closeModal(changeTeamModal);
            if (changeTeamForm) changeTeamForm.reset();
        });
    });

    if (changeTeamForm) {
        changeTeamForm.addEventListener("submit", function (event) {
            const team = changeTeamForm.querySelector('select[name="new_maintenance_team_id"]');
            const reason = changeTeamForm.querySelector('textarea[name="change_reason"]');

            if (!team || !team.value) {
                event.preventDefault();
                warn("Please select a new maintenance team.");
                team?.focus();
                return;
            }

            if (!reason || reason.value.trim() === "") {
                event.preventDefault();
                warn("Reason is required for team change.");
                reason?.focus();
            }
        });
    }

    applyFilters();
});
