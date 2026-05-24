document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("wrzSearch");
    const riskFilter = document.getElementById("wrzRiskFilter");
    const cards = document.querySelectorAll(".wrz-card");

    const modalOverlay = document.getElementById("wrzModalOverlay");
    const modalClose = document.getElementById("wrzModalClose");
    const viewButtons = document.querySelectorAll(".wrz-btn.view-details");

    function applyFilters() {
        const searchValue = (searchInput?.value || "").trim().toLowerCase();
        const riskValue = riskFilter?.value || "all";

        cards.forEach(function (card) {
            const cardSearch = card.getAttribute("data-search") || "";
            const cardRisk = card.getAttribute("data-risk") || "";

            const matchesSearch = searchValue === "" || cardSearch.includes(searchValue);
            const matchesRisk = riskValue === "all" || cardRisk === riskValue;

            card.classList.toggle("wrz-hidden", !(matchesSearch && matchesRisk));
        });
    }

    function setText(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.textContent = value && String(value).trim() !== "" ? value : "N/A";
        }
    }

    function openModal(button) {
        if (!modalOverlay || !button) return;

        setText("modalAreaName", button.dataset.area);
        setText("modalRiskLevel", button.dataset.risk + " Risk");
        setText("modalComplaints", button.dataset.complaints);
        setText("modalTrend", button.dataset.trend);
        setText("modalLastIncident", button.dataset.lastIncident);
        setText("modalLastIssue", button.dataset.lastIssue);
        setText("modalLastCode", button.dataset.lastCode);
        setText("modalSuggestedAction", button.dataset.action);

        modalOverlay.classList.add("active");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modalOverlay) return;

        modalOverlay.classList.remove("active");
        document.body.style.overflow = "";
    }

    if (searchInput) {
        searchInput.addEventListener("input", applyFilters);
    }

    if (riskFilter) {
        riskFilter.addEventListener("change", applyFilters);
    }

    viewButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button);
        });
    });

    if (modalClose) {
        modalClose.addEventListener("click", closeModal);
    }

    if (modalOverlay) {
        modalOverlay.addEventListener("click", function (event) {
            if (event.target === modalOverlay) {
                closeModal();
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modalOverlay && modalOverlay.classList.contains("active")) {
            closeModal();
        }
    });

    applyFilters();
});