/* ==================================================
   DRAINGUARD - Local Team Assignment JS
   Priority filter removed; search-only filtering
================================================== */
document.addEventListener("DOMContentLoaded", function () {
    const searchInput  = document.getElementById("ltaSearch");
    const cards        = document.querySelectorAll(".lta-card");
    const noResults    = document.getElementById("ltaNoResults");
    const forms        = document.querySelectorAll(".lta-assign-form");
    const instructionModal = document.getElementById("ltaInstructionModal");
    const openInstructionModal = document.getElementById("openInstructionModal");
    const instructionForm = document.getElementById("instructionForm");
    const instructionType = document.getElementById("instructionType");
    const instructionMessage = document.getElementById("instructionMessage");
    const changeTeamModal = document.getElementById("ltaChangeTeamModal");
    const changeTeamForm = document.getElementById("changeAssignedTeamForm");
    const changeComplaintId = document.getElementById("changeComplaintId");
    const changeAssignmentId = document.getElementById("changeAssignmentId");
    const changeCurrentTeam = document.getElementById("changeCurrentTeam");
    const changeNewTeam = document.getElementById("changeNewTeam");

    /* ---------- filtering ---------- */
    function filterCards() {
        const searchValue = (searchInput ? searchInput.value : "").trim().toLowerCase();
        let visibleCount  = 0;

        cards.forEach(function (card) {
            const cardSearch = (card.getAttribute("data-search") || "").toLowerCase();
            const matches    = searchValue === "" || cardSearch.includes(searchValue);

            card.style.display = matches ? "" : "none";
            if (matches) visibleCount++;
        });

        if (noResults) {
            noResults.style.display = (cards.length > 0 && visibleCount === 0) ? "" : "none";
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterCards);
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

    function showPageMessage(message, type) {
        const page = document.querySelector(".lta-page");
        const toolbar = document.querySelector(".lta-toolbar");
        if (!page || !toolbar) return;

        document.querySelectorAll(".lta-js-message").forEach(function (alert) {
            alert.remove();
        });

        const alert = document.createElement("div");
        alert.className = `lta-alert lta-js-message ${type === "success" ? "lta-success" : "lta-error"}`;
        alert.innerHTML = `<i class="bi ${type === "success" ? "bi-check-circle" : "bi-exclamation-circle"}"></i><span></span>`;
        alert.querySelector("span").textContent = message;
        page.insertBefore(alert, toolbar);
    }

    if (openInstructionModal) {
        openInstructionModal.addEventListener("click", function () {
            openModal(instructionModal);
        });
    }

    document.querySelectorAll("[data-close-instruction]").forEach(function (button) {
        button.addEventListener("click", function () {
            closeModal(instructionModal);
            if (instructionForm) instructionForm.reset();
        });
    });

    if (instructionType && instructionMessage) {
        instructionType.addEventListener("change", function () {
            const defaults = window.ltaInstructionDefaults || {};
            instructionMessage.value = defaults[instructionType.value] || "";
            instructionMessage.focus();
        });
    }

    document.querySelectorAll(".lta-change-team-btn").forEach(function (button) {
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

    document.querySelectorAll("[data-close-change-team]").forEach(function (button) {
        button.addEventListener("click", function () {
            closeModal(changeTeamModal);
            if (changeTeamForm) changeTeamForm.reset();
        });
    });

    if (instructionForm) {
        instructionForm.addEventListener("submit", function (event) {
            event.preventDefault();
            const team = instructionForm.querySelector('select[name="instruction_team_id"]');
            const type = instructionForm.querySelector('select[name="instruction_type"]');
            const message = instructionForm.querySelector('textarea[name="instruction_message"]');
            const submitButton = instructionForm.querySelector('button[type="submit"]');

            if (!team || !team.value) {
                showWarningModal("Please select a maintenance team.");
                team?.focus();
                return;
            }

            if (!type || !type.value) {
                showWarningModal("Please select an instruction type.");
                type?.focus();
                return;
            }

            if (!message || message.value.trim() === "") {
                showWarningModal("Instruction message cannot be empty.");
                message?.focus();
                return;
            }

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = "Sending...";
            }

            fetch(instructionForm.getAttribute("action") || "local-team-assignment.php", {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Accept": "application/json"
                },
                body: new FormData(instructionForm)
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { success: false, message: "Instruction request failed. Please try again." };
                    });
                })
                .then(function (data) {
                    if (data && data.success) {
                        showPageMessage(data.message || "Instruction sent to the selected maintenance team.", "success");
                        closeModal(instructionModal);
                        instructionForm.reset();
                    } else {
                        showPageMessage((data && data.message) || "Instruction could not be sent.", "error");
                    }
                })
                .catch(function () {
                    showPageMessage("Instruction request failed. Please try again.", "error");
                })
                .finally(function () {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = "Send Selected Instruction";
                    }
                });
        });
    }

    if (changeTeamForm) {
        changeTeamForm.addEventListener("submit", function (event) {
            const team = changeTeamForm.querySelector('select[name="new_maintenance_team_id"]');
            const reason = changeTeamForm.querySelector('textarea[name="change_reason"]');

            if (!team || !team.value) {
                event.preventDefault();
                showWarningModal("Please select a new maintenance team.");
                team?.focus();
                return;
            }

            if (!reason || reason.value.trim() === "") {
                event.preventDefault();
                showWarningModal("Reason is required for team change.");
                reason?.focus();
            }
        });
    }

    /* ---------- deadline min date ---------- */
    const today = new Date().toISOString().split("T")[0];
    document.querySelectorAll('input[type="date"][name="deadline_at"]').forEach(function (input) {
        if (!input.hasAttribute("min")) {
            input.setAttribute("min", today);
        }
    });

    /* ---------- custom modal confirm ---------- */
    const modalOverlay  = document.getElementById("ltaConfirmModal");
    const modalMessage  = document.getElementById("ltaConfirmMessage");
    const btnCancel     = document.getElementById("ltaBtnCancel");
    const btnConfirm    = document.getElementById("ltaBtnConfirm");
    let pendingForm     = null;

    if (btnCancel && modalOverlay) {
        btnCancel.addEventListener("click", function() {
            modalOverlay.classList.remove("active");
            pendingForm = null;
        });
    }

    if (btnConfirm && modalOverlay) {
        btnConfirm.addEventListener("click", function() {
            if (pendingForm) {
                // Submit the pending form programmatically bypassing the event listener
                pendingForm.submit();
                modalOverlay.classList.remove("active");
            }
        });
    }

    /* ---------- form submission guard ---------- */
    forms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            event.preventDefault();

            const teamSelect     = form.querySelector('select[name="maintenance_team_id"]');
            const deadlineInput  = form.querySelector('input[name="deadline_at"]');
            const prioritySelect = form.querySelector('input[name="assignment_priority"]');

            if (!teamSelect || !deadlineInput || !prioritySelect) {
                showWarningModal("Assignment form is incomplete.");
                return;
            }

            if (!teamSelect.value) {
                showWarningModal("Please select a maintenance team.");
                teamSelect.focus();
                return;
            }

            if (!deadlineInput.value) {
                showWarningModal("Please select a deadline.");
                deadlineInput.focus();
                return;
            }

            if (!["Low", "Medium", "High"].includes(prioritySelect.value)) {
                showWarningModal("Invalid priority value.");
                return;
            }

            const teamName = teamSelect.options[teamSelect.selectedIndex].text;
            
            showConfirmModal({
                title: "Confirm Assignment",
                message: `Send to ${teamName}?`,
                confirmText: "Assign",
                cancelText: "Cancel",
                type: "confirm",
                onConfirm: function() {
                    form.submit();
                }
            });
        });
    });

    /* ---------- initial render ---------- */
    filterCards();
});
