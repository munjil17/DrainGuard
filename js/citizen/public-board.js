document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("complaintSearch");
    const statusFilter = document.getElementById("statusFilter");
    const urgencyFilter = document.getElementById("urgencyFilter");
    const filterToggleBtn = document.getElementById("filterToggleBtn");
    const filterPanel = document.getElementById("filterPanel");
    const clearFilterBtn = document.getElementById("clearFilterBtn");

    const cards = document.querySelectorAll(".pb-card");

    const modal = document.getElementById("detailsModal");
    const modalCloseBtn = document.getElementById("modalCloseBtn");

    const modalIssue = document.getElementById("modalIssue");
    const modalCode = document.getElementById("modalCode");
    const modalStatus = document.getElementById("modalStatus");
    const modalUrgency = document.getElementById("modalUrgency");
    const modalDate = document.getElementById("modalDate");
    const modalCorporation = document.getElementById("modalCorporation");
    const modalThana = document.getElementById("modalThana");
    const modalWard = document.getElementById("modalWard");
    const modalArea = document.getElementById("modalArea");
    const modalCity = document.getElementById("modalCity");
    const modalAddress = document.getElementById("modalAddress");
    const modalProblem = document.getElementById("modalProblem");
    const modalImageWrapper = document.getElementById("modalImageWrapper");
    const modalImage = document.getElementById("modalImage");

    function normalize(value) {
        return String(value || "").toLowerCase().trim();
    }

    function filterComplaints() {
        const searchValue = normalize(searchInput ? searchInput.value : "");
        const selectedStatus = statusFilter ? statusFilter.value : "all";
        const selectedUrgency = urgencyFilter ? urgencyFilter.value : "all";

        cards.forEach(function (card) {
            const code = normalize(card.dataset.code);
            const issue = normalize(card.dataset.issue);
            const city = normalize(card.dataset.city);
            const corporation = normalize(card.dataset.corporation);
            const thana = normalize(card.dataset.thana);
            const ward = normalize(card.dataset.ward);
            const area = normalize(card.dataset.area);
            const status = card.dataset.status || "";
            const urgency = card.dataset.urgency || "";

            const searchMatched =
                searchValue === "" ||
                code.includes(searchValue) ||
                issue.includes(searchValue) ||
                city.includes(searchValue) ||
                corporation.includes(searchValue) ||
                thana.includes(searchValue) ||
                ward.includes(searchValue) ||
                area.includes(searchValue);

            const statusMatched =
                selectedStatus === "all" || status === selectedStatus;

            const urgencyMatched =
                selectedUrgency === "all" || urgency === selectedUrgency;

            if (searchMatched && statusMatched && urgencyMatched) {
                card.style.display = "";
            } else {
                card.style.display = "none";
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterComplaints);
    }

    if (statusFilter) {
        statusFilter.addEventListener("change", filterComplaints);
    }

    if (urgencyFilter) {
        urgencyFilter.addEventListener("change", filterComplaints);
    }

    if (filterToggleBtn && filterPanel) {
        filterToggleBtn.addEventListener("click", function () {
            filterPanel.classList.toggle("show");
        });
    }

    if (clearFilterBtn) {
        clearFilterBtn.addEventListener("click", function () {
            if (searchInput) searchInput.value = "";
            if (statusFilter) statusFilter.value = "all";
            if (urgencyFilter) urgencyFilter.value = "all";

            filterComplaints();
        });
    }

    function setText(element, value) {
        if (!element) return;
        element.textContent = value || "N/A";
    }

    function openModal(button) {
        if (!modal || !button) return;

        setText(modalIssue, button.dataset.issue);
        setText(modalCode, button.dataset.code);
        setText(modalStatus, button.dataset.status);
        setText(modalUrgency, button.dataset.urgency);
        setText(modalDate, button.dataset.date);
        setText(modalCorporation, button.dataset.corporation);
        setText(modalThana, button.dataset.thana);
        setText(modalWard, button.dataset.ward);
        setText(modalArea, button.dataset.area);
        setText(modalCity, button.dataset.city);
        setText(modalAddress, button.dataset.address);
        setText(modalProblem, button.dataset.problem);

        const imagePath = button.dataset.image || "";

        if (modalImageWrapper && modalImage) {
            if (imagePath.trim() !== "") {
                modalImage.src = imagePath;
                modalImageWrapper.classList.add("show");
            } else {
                modalImage.src = "";
                modalImageWrapper.classList.remove("show");
            }
        }

        modal.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) return;

        modal.classList.remove("show");
        document.body.style.overflow = "";
    }

    document.querySelectorAll(".pb-details-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button);
        });
    });

    if (modalCloseBtn) {
        modalCloseBtn.addEventListener("click", closeModal);
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
});