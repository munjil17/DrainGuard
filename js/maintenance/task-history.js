document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("historySearchInput");
    const statusFilter = document.getElementById("statusFilter");
    const areaFilter = document.getElementById("areaFilter");
    const timeFilter = document.getElementById("timeFilter");
    const tableBody = document.getElementById("historyTableBody");
    const filterEmptyState = document.getElementById("filterEmptyState");
    const initialEmptyState = document.querySelector(".initial-empty-state");

    function getRows() {
        return Array.from(document.querySelectorAll(".history-row"));
    }

    function isThisWeek(dateString) {
        if (!dateString) return false;

        const date = new Date(dateString);
        if (Number.isNaN(date.getTime())) return false;

        const now = new Date();
        const start = new Date(now);
        start.setDate(now.getDate() - now.getDay());
        start.setHours(0, 0, 0, 0);

        const end = new Date(start);
        end.setDate(start.getDate() + 7);

        return date >= start && date < end;
    }

    function isThisMonth(dateString) {
        if (!dateString) return false;

        const date = new Date(dateString);
        if (Number.isNaN(date.getTime())) return false;

        const now = new Date();

        return date.getFullYear() === now.getFullYear()
            && date.getMonth() === now.getMonth();
    }

    function filterRows() {
        const rows = getRows();

        const searchValue = searchInput ? searchInput.value.trim().toLowerCase() : "";
        const statusValue = statusFilter ? statusFilter.value : "all";
        const areaValue = areaFilter ? areaFilter.value : "all";
        const timeValue = timeFilter ? timeFilter.value : "all";

        rows.forEach(function (row) {
            const searchableText = row.getAttribute("data-search") || "";
            const rowStatus = row.getAttribute("data-status") || "";
            const rowArea = row.getAttribute("data-area-id") || "";
            const rowDate = row.getAttribute("data-date") || "";

            const matchesSearch = searchableText.includes(searchValue);
            const matchesStatus = statusValue === "all" || rowStatus === statusValue;
            const matchesArea = areaValue === "all" || rowArea === areaValue;

            let matchesTime = true;

            if (timeValue === "week") {
                matchesTime = isThisWeek(rowDate);
            }

            if (timeValue === "month") {
                matchesTime = isThisMonth(rowDate);
            }

            row.style.display = matchesSearch && matchesStatus && matchesArea && matchesTime
                ? "table-row"
                : "none";
        });

        const visibleRows = rows.filter(function (row) {
            return row.style.display !== "none";
        });

        if (filterEmptyState) {
            filterEmptyState.style.display = rows.length > 0 && visibleRows.length === 0 ? "block" : "none";
        }

        if (initialEmptyState) {
            initialEmptyState.style.display = rows.length === 0 ? "block" : "none";
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterRows);
    }

    if (statusFilter) {
        statusFilter.addEventListener("change", filterRows);
    }

    if (areaFilter) {
        areaFilter.addEventListener("change", filterRows);
    }

    if (timeFilter) {
        timeFilter.addEventListener("change", filterRows);
    }

    filterRows();

    const modal = document.getElementById("historyModal");
    const closeButtons = document.querySelectorAll("[data-close-history-modal]");
    const viewButtons = document.querySelectorAll(".view-history-btn");

    const modalTaskCode = document.getElementById("modalTaskCode");
    const modalIssue = document.getElementById("modalIssue");
    const modalComplaintStatus = document.getElementById("modalComplaintStatus");
    const modalStatus = document.getElementById("modalStatus");
    const modalArea = document.getElementById("modalArea");
    const modalWard = document.getElementById("modalWard");
    const modalAddress = document.getElementById("modalAddress");
    const modalAssignedBy = document.getElementById("modalAssignedBy");
    const modalStartedAt = document.getElementById("modalStartedAt");
    const modalCompletedAt = document.getElementById("modalCompletedAt");
    const modalProblem = document.getElementById("modalProblem");
    const modalNote = document.getElementById("modalNote");
    const modalProofFiles = document.getElementById("modalProofFiles");

    function setText(element, value) {
        if (element) {
            element.textContent = value || "N/A";
        }
    }

    function createProofFileElement(fileString) {
        const parts = fileString.split("::");

        const proofId = parts[0] || "";
        const mediaType = parts[1] || "";
        const mediaPath = parts[2] || "";
        const originalName = parts[3] || "Proof file";
        const proofStatus = parts[4] || "submitted";
        const uploadedAt = parts[5] || "Not available";

        const item = document.createElement("div");
        item.className = "proof-file-item";

        const mediaBox = document.createElement("div");
        mediaBox.className = "proof-file-media";

        if (mediaType === "image") {
            const img = document.createElement("img");
            img.src = "../../" + mediaPath;
            img.alt = originalName;
            mediaBox.appendChild(img);
        } else if (mediaType === "video") {
            const video = document.createElement("video");
            video.src = "../../" + mediaPath;
            video.controls = true;
            mediaBox.appendChild(video);
        } else {
            mediaBox.innerHTML = '<div class="no-proof-text">No preview</div>';
        }

        const info = document.createElement("div");
        info.className = "proof-file-info";

        const title = document.createElement("strong");
        title.textContent = originalName;

        const meta = document.createElement("span");
        meta.textContent = proofStatus + " • " + uploadedAt;

        info.appendChild(title);
        info.appendChild(meta);

        item.appendChild(mediaBox);
        item.appendChild(info);

        return item;
    }

    function renderProofFiles(filesString) {
        if (!modalProofFiles) return;

        modalProofFiles.innerHTML = "";

        if (!filesString || filesString.trim() === "") {
            modalProofFiles.innerHTML = '<p class="no-proof-text">No proof file found.</p>';
            return;
        }

        const files = filesString.split("||").filter(Boolean);

        if (files.length === 0) {
            modalProofFiles.innerHTML = '<p class="no-proof-text">No proof file found.</p>';
            return;
        }

        files.forEach(function (fileString) {
            modalProofFiles.appendChild(createProofFileElement(fileString));
        });
    }

    function openModal(button) {
        if (!modal) return;

        setText(modalTaskCode, button.getAttribute("data-code"));
        setText(modalIssue, button.getAttribute("data-issue"));
        setText(modalComplaintStatus, button.getAttribute("data-complaint-status"));
        setText(modalStatus, button.getAttribute("data-status"));
        setText(modalArea, button.getAttribute("data-area"));
        setText(modalWard, button.getAttribute("data-ward"));
        setText(modalAddress, button.getAttribute("data-address"));
        setText(modalAssignedBy, button.getAttribute("data-assigned-by"));
        setText(modalStartedAt, button.getAttribute("data-started-at"));
        setText(modalCompletedAt, button.getAttribute("data-completed-at"));
        setText(modalProblem, button.getAttribute("data-problem"));
        setText(modalNote, button.getAttribute("data-note"));

        renderProofFiles(button.getAttribute("data-files"));

        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) return;

        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    viewButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button);
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener("click", closeModal);
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal();
        }
    });
});