document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("ltaSearch");
    const priorityFilter = document.getElementById("ltaPriorityFilter");
    const cards = document.querySelectorAll(".lta-card");
    const forms = document.querySelectorAll(".lta-assign-form");

    function filterCards() {
        const searchValue = (searchInput?.value || "").trim().toLowerCase();
        const priorityValue = priorityFilter?.value || "all";

        cards.forEach(function (card) {
            const cardSearch = card.getAttribute("data-search") || "";
            const cardPriority = card.getAttribute("data-priority") || "";

            const matchesSearch = searchValue === "" || cardSearch.includes(searchValue);
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

    const today = new Date().toISOString().split("T")[0];

    document.querySelectorAll('input[type="date"][name="deadline_at"]').forEach(function (input) {
        input.setAttribute("min", today);
    });

    forms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            const teamSelect = form.querySelector('select[name="maintenance_team_id"]');
            const deadlineInput = form.querySelector('input[name="deadline_at"]');
            const prioritySelect = form.querySelector('select[name="assignment_priority"]');

            if (!teamSelect || !deadlineInput || !prioritySelect) {
                event.preventDefault();
                alert("Assignment form is incomplete.");
                return;
            }

            if (teamSelect.value === "") {
                event.preventDefault();
                alert("Please select a maintenance team.");
                teamSelect.focus();
                return;
            }

            if (deadlineInput.value === "") {
                event.preventDefault();
                alert("Please select a deadline.");
                deadlineInput.focus();
                return;
            }

            if (!["Low", "Medium", "High"].includes(prioritySelect.value)) {
                event.preventDefault();
                alert("Please select a valid priority.");
                prioritySelect.focus();
                return;
            }

            const confirmed = confirm("Assign this complaint to the selected maintenance team? Citizen tracking status will become Assigned to Team.");

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    filterCards();
});