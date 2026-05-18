document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("complaintSearch");
    const statusFilter = document.getElementById("statusFilter");
    const priorityFilter = document.getElementById("priorityFilter");
    const rows = document.querySelectorAll(".cm-row");

    const filterToggleBtn = document.getElementById("filterToggleBtn");
    const filterPanel = document.getElementById("filterPanel");
    const clearFilterBtn = document.getElementById("clearFilterBtn");
    const tabs = document.querySelectorAll(".cm-tab");

    const modal = document.getElementById("detailsModal");
    const modalCloseBtn = document.getElementById("modalCloseBtn");
    const detailButtons = document.querySelectorAll(".cm-details-btn");

    function filterRows() {
        const searchValue = (searchInput?.value || "").toLowerCase().trim();
        const statusValue = statusFilter?.value || "all";
        const priorityValue = priorityFilter?.value || "all";

        rows.forEach(function (row) {
            const searchableText = [
                row.dataset.code || "",
                row.dataset.title || "",
                row.dataset.user || "",
                row.dataset.ward || "",
                row.dataset.area || "",
                row.dataset.type || ""
            ].join(" ").toLowerCase();

            const rowStatus = row.dataset.status || "";
            const rowPriority = row.dataset.priority || "";

            const matchesSearch = searchableText.includes(searchValue);
            const matchesStatus = statusValue === "all" || rowStatus === statusValue;
            const matchesPriority = priorityValue === "all" || rowPriority === priorityValue;

            row.style.display = matchesSearch && matchesStatus && matchesPriority ? "" : "none";
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterRows);
    }

    if (statusFilter) {
        statusFilter.addEventListener("change", filterRows);
    }

    if (priorityFilter) {
        priorityFilter.addEventListener("change", filterRows);
    }

    if (filterToggleBtn && filterPanel) {
        filterToggleBtn.addEventListener("click", function () {
            filterPanel.classList.toggle("active");
        });
    }

    if (clearFilterBtn) {
        clearFilterBtn.addEventListener("click", function () {
            if (searchInput) searchInput.value = "";
            if (statusFilter) statusFilter.value = "all";
            if (priorityFilter) priorityFilter.value = "all";

            tabs.forEach(function (tab) {
                tab.classList.remove("active");
            });

            const allTab = document.querySelector('.cm-tab[data-filter="all"]');
            if (allTab) allTab.classList.add("active");

            filterRows();
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
            tabs.forEach(function (item) {
                item.classList.remove("active");
            });

            this.classList.add("active");

            const status = this.dataset.filter || "all";
            const priority = this.dataset.priority || "all";

            if (statusFilter) statusFilter.value = status;
            if (priorityFilter) priorityFilter.value = priority;

            filterRows();
        });
    });

    function setText(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.textContent = value && value.trim() !== "" ? value : "N/A";
        }
    }

    function openModal(button) {
        if (!modal || !button) return;

        setText("modalTitle", button.dataset.title);
        setText("modalCode", button.dataset.code);
        setText("modalUser", button.dataset.user);
        setText("modalEmail", button.dataset.email);
        setText("modalType", button.dataset.type);
        setText("modalPriority", button.dataset.priority);
        setText("modalStatus", button.dataset.status);
        setText("modalDate", button.dataset.date);
        setText("modalCorporation", button.dataset.corporation);
        setText("modalThana", button.dataset.thana);
        setText("modalWard", button.dataset.ward);
        setText("modalArea", button.dataset.area);
        setText("modalAddress", button.dataset.address);
        setText("modalProblem", button.dataset.problem);

        const imageWrap = document.getElementById("modalImageWrap");
        const modalImage = document.getElementById("modalImage");

        if (imageWrap && modalImage) {
            if (button.dataset.image && button.dataset.image.trim() !== "") {
                imageWrap.style.display = "block";
                modalImage.src = button.dataset.image;
            } else {
                imageWrap.style.display = "none";
                modalImage.src = "";
            }
        }

        modal.classList.add("active");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) return;

        modal.classList.remove("active");
        document.body.style.overflow = "";
    }

    detailButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(this);
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
        if (event.key === "Escape" && modal && modal.classList.contains("active")) {
            closeModal();
        }
    });

    filterRows();
});