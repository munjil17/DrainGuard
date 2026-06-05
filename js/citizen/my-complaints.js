document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("complaintSearch");
    const statusFilter = document.getElementById("statusFilter");
    const urgencyFilter = document.getElementById("urgencyFilter");
    const wardFilter = document.getElementById("wardFilter");

    const filterToggleBtn = document.getElementById("filterToggleBtn");
    const filterPanel = document.getElementById("filterPanel");
    const clearFilterBtn = document.getElementById("clearFilterBtn");
    const visibleComplaintCount = document.getElementById("visibleComplaintCount");
    const filterEmptyState = document.getElementById("filterEmptyState");

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

    const modalMediaWrapper = document.getElementById("modalMediaWrapper");
    const modalMediaGrid = document.getElementById("modalMediaGrid");

    function normalize(value) {
        return String(value || "").toLowerCase().trim();
    }

    function filterComplaints() {
        const searchValue = normalize(searchInput ? searchInput.value : "");
        const selectedStatus = statusFilter ? statusFilter.value : "all";
        const selectedUrgency = urgencyFilter ? urgencyFilter.value : "all";
        const selectedWard = wardFilter ? wardFilter.value : "all";

        let visibleCount = 0;

        cards.forEach(function (card) {
            const code = normalize(card.dataset.code);
            const issue = normalize(card.dataset.issue);
            const city = normalize(card.dataset.city);
            const corporation = normalize(card.dataset.corporation);
            const thana = normalize(card.dataset.thana);
            const ward = normalize(card.dataset.ward);
            const area = normalize(card.dataset.area);

            const wardId = card.dataset.wardId || "";
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

            const wardMatched =
                selectedWard === "all" || String(wardId) === String(selectedWard);

            if (searchMatched && statusMatched && urgencyMatched && wardMatched) {
                card.style.display = "";
                visibleCount += 1;
            } else {
                card.style.display = "none";
            }
        });

        if (visibleComplaintCount) {
            visibleComplaintCount.textContent = visibleCount;
        }

        if (filterEmptyState) {
            filterEmptyState.classList.toggle("show", visibleCount === 0 && cards.length > 0);
        }
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

    if (wardFilter) {
        wardFilter.addEventListener("change", filterComplaints);
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
            if (wardFilter) wardFilter.value = "all";

            filterComplaints();
        });
    }

    function setText(element, value) {
        if (!element) {
            return;
        }

        element.textContent = value || "N/A";
    }

    function parseMedia(button) {
        if (!button || !button.dataset.media) {
            return [];
        }

        try {
            const parsed = JSON.parse(button.dataset.media);

            if (Array.isArray(parsed)) {
                return parsed;
            }
        } catch (error) {
            return [];
        }

        return [];
    }

    function renderMedia(mediaItems) {
        if (!modalMediaWrapper || !modalMediaGrid) {
            return;
        }

        modalMediaGrid.innerHTML = "";

        if (!Array.isArray(mediaItems) || mediaItems.length === 0) {
            modalMediaWrapper.classList.remove("show");
            return;
        }

        mediaItems.forEach(function (media) {
            const mediaPath = media.media_path || "";
            const mediaType = media.media_type || "";
            const originalName = media.original_name || "Evidence file";

            if (mediaPath.trim() === "") {
                return;
            }

            const item = document.createElement("div");
            const caption = document.createElement("div");

            item.className = "pb-media-item";
            caption.className = "pb-media-caption";
            caption.textContent = originalName;

            if (mediaType === "video") {
                const video = document.createElement("video");

                video.src = mediaPath;
                video.controls = true;
                video.preload = "metadata";

                item.appendChild(video);
            } else {
                const image = document.createElement("img");

                image.src = mediaPath;
                image.alt = originalName;
                image.loading = "lazy";

                image.onerror = function () {
                    caption.textContent = "File not found: " + originalName;
                    item.classList.add("pb-media-error");
                };

                item.appendChild(image);
            }

            item.appendChild(caption);
            modalMediaGrid.appendChild(item);
        });

        if (modalMediaGrid.children.length > 0) {
            modalMediaWrapper.classList.add("show");
        } else {
            modalMediaWrapper.classList.remove("show");
        }
    }

    function openModal(button) {
        if (!modal || !button) {
            return;
        }

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

        renderMedia(parseMedia(button));

        modal.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) {
            return;
        }

        modal.classList.remove("show");
        document.body.style.overflow = "";

        if (modalMediaGrid) {
            modalMediaGrid.innerHTML = "";
        }

        if (modalMediaWrapper) {
            modalMediaWrapper.classList.remove("show");
        }
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

    filterComplaints();
});