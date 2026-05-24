document.addEventListener("DOMContentLoaded", function () {
    const thanaFilter = document.getElementById("thanaFilter");
    const wardFilter = document.getElementById("wardFilter");
    const areaFilter = document.getElementById("areaFilter");
    const riskFilter = document.getElementById("riskFilter");
    const clearFilterBtn = document.getElementById("clearRiskFilterBtn");

    const cards = Array.from(document.querySelectorAll(".hra-card"));
    const visibleRiskCount = document.getElementById("visibleRiskCount");
    const emptyState = document.getElementById("riskEmptyState");

    const loadMoreBtn = document.getElementById("loadMoreRiskBtn");
    const initialVisibleCount = 6;
    let visibleLimit = initialVisibleCount;

    const modal = document.getElementById("riskDetailsModal");
    const modalClose = document.getElementById("riskModalClose");
    const modalDrainList = document.getElementById("modalDrainList");

    function setText(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.textContent = value || "N/A";
        }
    }

    function getSelectValue(select, fallback = "all") {
        return select ? select.value : fallback;
    }

    function getFilteredCards() {
        const selectedThana = getSelectValue(thanaFilter);
        const selectedWard = getSelectValue(wardFilter);
        const selectedArea = getSelectValue(areaFilter);
        const selectedRisk = getSelectValue(riskFilter);

        return cards.filter(function (card) {
            const matchesThana =
                selectedThana === "all" || String(card.dataset.thanaId) === selectedThana;

            const matchesWard =
                selectedWard === "all" || String(card.dataset.wardId) === selectedWard;

            const matchesArea =
                selectedArea === "all" || String(card.dataset.areaId) === selectedArea;

            const matchesRisk =
                selectedRisk === "all" || String(card.dataset.risk) === selectedRisk;

            return matchesThana && matchesWard && matchesArea && matchesRisk;
        });
    }

    function applyFilters() {
        const filteredCards = getFilteredCards();

        cards.forEach(function (card) {
            card.style.display = "none";
            card.classList.remove("is-hidden-by-load");
        });

        filteredCards.forEach(function (card, index) {
            if (index < visibleLimit) {
                card.style.display = "";
            } else {
                card.style.display = "none";
                card.classList.add("is-hidden-by-load");
            }
        });

        if (visibleRiskCount) {
            visibleRiskCount.textContent = filteredCards.length;
        }

        if (emptyState) {
            emptyState.style.display = filteredCards.length === 0 && cards.length > 0 ? "block" : "none";
        }

        if (loadMoreBtn) {
            if (filteredCards.length > visibleLimit) {
                loadMoreBtn.style.display = "inline-flex";
            } else {
                loadMoreBtn.style.display = "none";
            }
        }
    }

    function resetVisibleLimit() {
        visibleLimit = initialVisibleCount;
    }

    function handleFilterChange() {
        resetVisibleLimit();
        applyFilters();
    }

    function clearFilters() {
        if (thanaFilter) {
            thanaFilter.value = "all";
        }

        if (wardFilter) {
            wardFilter.value = "all";
        }

        if (areaFilter) {
            areaFilter.value = "all";
        }

        if (riskFilter) {
            riskFilter.value = "all";
        }

        resetVisibleLimit();
        applyFilters();
    }

    function escapeHtml(value) {
        return String(value || "")
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    function renderDrainBreakdown(drains) {
        if (!modalDrainList) {
            return;
        }

        modalDrainList.innerHTML = "";

        if (!Array.isArray(drains) || drains.length === 0) {
            modalDrainList.innerHTML = `
                <div class="hra-drain-empty">
                    No drain-wise complaint breakdown found for this area in the last 30 days.
                </div>
            `;
            return;
        }

        drains.forEach(function (drain) {
            const item = document.createElement("div");
            item.className = "hra-drain-item";

            const drainName = escapeHtml(drain.drain_name || "Unnamed Drain");
            const drainAddress = escapeHtml(drain.drain_address_description || "No address description");
            const totalComplaints = escapeHtml(drain.total_complaints || "0");
            const lastIncident = escapeHtml(drain.last_incident || "N/A");

            item.innerHTML = `
                <div class="hra-drain-main">
                    <strong>${drainName}</strong>
                    <span>${drainAddress}</span>
                </div>

                <div class="hra-drain-stat">
                    <strong>${totalComplaints}</strong>
                    <small>complaints</small>
                    <small>Last: ${lastIncident}</small>
                </div>
            `;

            modalDrainList.appendChild(item);
        });
    }

    function openModal(button) {
        if (!modal || !button) {
            return;
        }

        setText("modalRiskArea", button.dataset.area || "Risk Area Details");
        setText("modalRiskWard", button.dataset.ward || "Ward");
        setText("modalRiskLevel", button.dataset.risk);
        setText("modalCount30", button.dataset.count30);
        setText("modalCount7", button.dataset.count7);
        setText("modalWeek", "+" + (button.dataset.week || "0") + " this week");
        setText("modalLast", button.dataset.last);
        setText("modalFirst", button.dataset.first);
        setText("modalThana", button.dataset.thana);
        setText("modalCorporation", button.dataset.corporation);
        setText("modalIssue", button.dataset.issue);
        setText("modalAffected", button.dataset.affected);
        setText("modalCode", button.dataset.code);
        setText("modalAddress", button.dataset.address);
        setText("modalProblem", button.dataset.problem);

        let drains = [];

        try {
            drains = JSON.parse(button.dataset.drains || "[]");
        } catch (error) {
            drains = [];
        }

        renderDrainBreakdown(drains);

        modal.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) {
            return;
        }

        modal.classList.remove("show");
        document.body.style.overflow = "";
    }

    document.querySelectorAll(".hra-view-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button);
        });
    });

    document.querySelectorAll(".hra-alert-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            alert("Safety Notice: Avoid walking or driving through this risky drainage area during rain or flooding.");
        });
    });

    if (thanaFilter) {
        thanaFilter.addEventListener("change", handleFilterChange);
    }

    if (wardFilter) {
        wardFilter.addEventListener("change", handleFilterChange);
    }

    if (areaFilter) {
        areaFilter.addEventListener("change", handleFilterChange);
    }

    if (riskFilter) {
        riskFilter.addEventListener("change", handleFilterChange);
    }

    if (clearFilterBtn) {
        clearFilterBtn.addEventListener("click", clearFilters);
    }

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener("click", function () {
            visibleLimit += initialVisibleCount;
            applyFilters();
        });
    }

    if (modalClose) {
        modalClose.addEventListener("click", closeModal);
    }

    if (modal) {
        modal.addEventListener("click", function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal();
        }
    });

    applyFilters();
});