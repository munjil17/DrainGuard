/* ==================================================
   DRAINGUARD - Local Team Assignment JS
   Priority filter removed; search-only filtering
================================================== */
document.addEventListener("DOMContentLoaded", function () {
    const searchInput  = document.getElementById("ltaSearch");
    const cards        = document.querySelectorAll(".lta-card");
    const noResults    = document.getElementById("ltaNoResults");
    const forms        = document.querySelectorAll(".lta-assign-form");

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
                alert("Assignment form is incomplete.");
                return;
            }

            if (!teamSelect.value) {
                alert("Please select a maintenance team.");
                teamSelect.focus();
                return;
            }

            if (!deadlineInput.value) {
                alert("Please select a deadline.");
                deadlineInput.focus();
                return;
            }

            if (!["Low", "Medium", "High"].includes(prioritySelect.value)) {
                alert("Invalid priority value.");
                return;
            }

            const teamName = teamSelect.options[teamSelect.selectedIndex].text;
            
            // Set message and show modal
            if (modalOverlay && modalMessage) {
                modalMessage.innerText = `Send to ${teamName}?`;
                pendingForm = form;
                modalOverlay.classList.add("active");
            } else {
                // Fallback if modal HTML is missing
                const confirmed = confirm(`Send to ${teamName}?`);
                if (confirmed) form.submit();
            }
        });
    });

    /* ---------- initial render ---------- */
    filterCards();
});