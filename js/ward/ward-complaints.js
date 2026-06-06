document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("wardComplaintSearch");
    const topbarSearchInput = document.getElementById("wardTopbarSearch");
    const statusFilter = document.getElementById("wardStatusFilter");
    const selectedStatusCount = document.getElementById("wardSelectedStatusCount");
    const complaintRows = document.querySelectorAll(".wc-complaint-row");
    const emptyState = document.getElementById("wardComplaintEmptyState");

    const filterButton = document.getElementById("wardFilterButton");
    const filterMenu = document.getElementById("urgencyFilterMenu");
    const urgencyFilterLabel = document.getElementById("urgencyFilterLabel");
    const urgencyButtons = document.querySelectorAll("#urgencyFilterMenu button");

    let activeStatusFilter = "all";
    let activeUrgencyFilter = "all";
    let searchValue = "";

    function updateSelectedStatusCount() {
        if (!statusFilter || !selectedStatusCount) {
            return;
        }

        const selectedOption = statusFilter.options[statusFilter.selectedIndex];
        selectedStatusCount.textContent = selectedOption ? selectedOption.getAttribute("data-count") || "0" : "0";
    }

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function applyFilters() {
        let visibleCount = 0;

        complaintRows.forEach(function (row) {
            const rowStatus = row.getAttribute("data-filter") || "";
            const rowUrgency = row.getAttribute("data-urgency") || "";
            const rowSearch = row.getAttribute("data-search") || "";

            const matchesStatus = activeStatusFilter === "all" || rowStatus === activeStatusFilter;
            const matchesUrgency = activeUrgencyFilter === "all" || rowUrgency === activeUrgencyFilter;
            const matchesSearch = searchValue === "" || rowSearch.includes(searchValue);

            if (matchesStatus && matchesUrgency && matchesSearch) {
                row.classList.remove("wc-hidden");
                visibleCount++;
            } else {
                row.classList.add("wc-hidden");
            }
        });

        if (emptyState) {
            if (visibleCount === 0) {
                emptyState.classList.remove("d-none");
            } else {
                emptyState.classList.add("d-none");
            }
        }
    }

    function setSearchValue(value) {
        searchValue = value.trim().toLowerCase();

        if (searchInput && searchInput.value !== value) {
            searchInput.value = value;
        }

        if (topbarSearchInput && topbarSearchInput.value !== value) {
            topbarSearchInput.value = value;
        }

        applyFilters();
    }

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            setSearchValue(searchInput.value);
        });
    }

    if (topbarSearchInput) {
        topbarSearchInput.addEventListener("input", function () {
            setSearchValue(topbarSearchInput.value);
        });
    }

    if (statusFilter) {
        activeStatusFilter = statusFilter.value || "all";
        updateSelectedStatusCount();

        statusFilter.addEventListener("change", function () {
            activeStatusFilter = statusFilter.value || "all";
            updateSelectedStatusCount();
            applyFilters();
        });
    }

    if (filterButton && filterMenu) {
        filterButton.addEventListener("click", function (event) {
            event.stopPropagation();
            filterMenu.classList.toggle("show");
            filterButton.classList.toggle("active");
        });
    }

    urgencyButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            urgencyButtons.forEach(function (item) {
                item.classList.remove("active");
            });

            button.classList.add("active");

            activeUrgencyFilter = button.getAttribute("data-urgency") || "all";

            const labelText = button.textContent.trim();
            if (urgencyFilterLabel) {
                urgencyFilterLabel.textContent = activeUrgencyFilter === "all" ? "Filter" : labelText;
            }

            if (filterMenu) {
                filterMenu.classList.remove("show");
            }

            if (filterButton) {
                filterButton.classList.remove("active");
            }

            applyFilters();
        });
    });

    document.addEventListener("click", function () {
        if (filterMenu) {
            filterMenu.classList.remove("show");
        }

        if (filterButton) {
            filterButton.classList.remove("active");
        }
    });

    if (filterMenu) {
        filterMenu.addEventListener("click", function (event) {
            event.stopPropagation();
        });
    }

    function renderMedia(mediaList) {
        const modalMediaGrid = document.getElementById("modalMediaGrid");
        const modalMediaEmpty = document.getElementById("modalMediaEmpty");

        if (!modalMediaGrid || !modalMediaEmpty) {
            return;
        }

        modalMediaGrid.innerHTML = "";

        if (!Array.isArray(mediaList) || mediaList.length === 0) {
            modalMediaGrid.classList.add("d-none");
            modalMediaEmpty.classList.remove("d-none");
            return;
        }

        modalMediaGrid.classList.remove("d-none");
        modalMediaEmpty.classList.add("d-none");

        mediaList.forEach(function (media) {
            const path = media.path || "";
            const type = media.type || "file";
            const name = media.name || "media-file";

            if (!path) {
                return;
            }

            let mediaHtml = "";

            if (type === "image") {
                mediaHtml = `
                    <div class="wc-media-card">
                        <img src="${escapeHtml(path)}" alt="${escapeHtml(name)}">
                        <div class="wc-media-caption">
                            <span>${escapeHtml(name)}</span>
                            <a href="${escapeHtml(path)}" target="_blank">Open</a>
                        </div>
                    </div>
                `;
            } else if (type === "video") {
                mediaHtml = `
                    <div class="wc-media-card">
                        <video controls>
                            <source src="${escapeHtml(path)}">
                            Your browser does not support the video tag.
                        </video>
                        <div class="wc-media-caption">
                            <span>${escapeHtml(name)}</span>
                            <a href="${escapeHtml(path)}" target="_blank">Open</a>
                        </div>
                    </div>
                `;
            } else {
                mediaHtml = `
                    <div class="wc-media-card">
                        <div class="wc-media-file">
                            <i class="bi bi-file-earmark"></i>
                            <a href="${escapeHtml(path)}" target="_blank">${escapeHtml(name)}</a>
                        </div>
                    </div>
                `;
            }

            modalMediaGrid.insertAdjacentHTML("beforeend", mediaHtml);
        });

        if (modalMediaGrid.innerHTML.trim() === "") {
            modalMediaGrid.classList.add("d-none");
            modalMediaEmpty.classList.remove("d-none");
        }
    }

    const complaintDetailsModal = document.getElementById("complaintDetailsModal");

    if (complaintDetailsModal) {
        complaintDetailsModal.addEventListener("show.bs.modal", function (event) {
            const button = event.relatedTarget;

            if (!button) {
                return;
            }

            const code = button.getAttribute("data-code") || "N/A";
            const issue = button.getAttribute("data-issue") || "N/A";
            const area = button.getAttribute("data-area") || "N/A";
            const priority = button.getAttribute("data-priority") || "N/A";
            const status = button.getAttribute("data-status") || "N/A";
            const date = button.getAttribute("data-date") || "N/A";
            const address = button.getAttribute("data-address") || "N/A";
            const description = button.getAttribute("data-description") || "N/A";
            const mediaRaw = button.getAttribute("data-media") || "[]";

            let mediaList = [];

            try {
                mediaList = JSON.parse(mediaRaw);
            } catch (error) {
                mediaList = [];
            }

            const modalComplaintCode = document.getElementById("modalComplaintCode");
            const modalIssue = document.getElementById("modalIssue");
            const modalArea = document.getElementById("modalArea");
            const modalPriority = document.getElementById("modalPriority");
            const modalStatus = document.getElementById("modalStatus");
            const modalDate = document.getElementById("modalDate");
            const modalAddress = document.getElementById("modalAddress");
            const modalDescription = document.getElementById("modalDescription");

            if (modalComplaintCode) modalComplaintCode.textContent = code;
            if (modalIssue) modalIssue.textContent = issue;
            if (modalArea) modalArea.textContent = area;
            if (modalPriority) modalPriority.textContent = priority;
            if (modalStatus) modalStatus.textContent = status;
            if (modalDate) modalDate.textContent = date;
            if (modalAddress) modalAddress.textContent = address;
            if (modalDescription) modalDescription.textContent = description;

            renderMedia(mediaList);
        });
    }

    applyFilters();
});
