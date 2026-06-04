document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("complaintSearch");
    const statusFilter = document.getElementById("statusFilter");
    const priorityFilter = document.getElementById("priorityFilter");
    const rows = document.querySelectorAll(".cm-row");
    const visibleComplaintCount = document.getElementById("visibleComplaintCount");
    const filterEmptyState = document.getElementById("filterEmptyState");

    const filterToggleBtn = document.getElementById("filterToggleBtn");
    const filterPanel = document.getElementById("filterPanel");
    const clearFilterBtn = document.getElementById("clearFilterBtn");
    const tabs = document.querySelectorAll(".cm-tab");

    const modal = document.getElementById("detailsModal");
    const modalCloseBtn = document.getElementById("modalCloseBtn");
    const detailButtons = document.querySelectorAll(".cm-details-btn");

    const acceptForms = document.querySelectorAll(".cm-accept-form");

    const rejectModal = document.getElementById("rejectModal");
    const rejectModalCloseBtn = document.getElementById("rejectModalCloseBtn");
    const rejectCancelBtn = document.getElementById("rejectCancelBtn");
    const rejectOpenButtons = document.querySelectorAll(".cm-reject-open-btn");
    const rejectForm = document.getElementById("centralRejectForm");
    const rejectComplaintId = document.getElementById("rejectComplaintId");
    const rejectModalCode = document.getElementById("rejectModalCode");
    const rejectReason = document.getElementById("rejectReason");
    const rejectReasonError = document.getElementById("rejectReasonError");

    function filterRows() {
        const searchValue = (searchInput?.value || "").toLowerCase().trim();
        const statusValue = statusFilter?.value || "all";
        const priorityValue = priorityFilter?.value || "all";

        let visibleCount = 0;

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

            const isVisible = matchesSearch && matchesStatus && matchesPriority;

            row.style.display = isVisible ? "" : "none";

            if (isVisible) {
                visibleCount++;
            }
        });

        if (visibleComplaintCount) {
            visibleComplaintCount.textContent = visibleCount;
        }

        if (filterEmptyState) {
            filterEmptyState.classList.toggle("show", rows.length > 0 && visibleCount === 0);
        }
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

            const allTab = document.querySelector('.cm-tab[data-filter="all"][data-priority="all"]');
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

    acceptForms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            const ok = confirm("Accept this complaint and mark it as Received?");
            if (!ok) {
                event.preventDefault();
            }
        });
    });

    function setText(id, value) {
        const element = document.getElementById(id);

        if (element) {
            const safeValue = String(value || "").trim();
            element.textContent = safeValue !== "" ? safeValue : "N/A";
        }
    }

    function parseMedia(rawMedia) {
        if (!rawMedia || rawMedia.trim() === "") {
            return [];
        }

        try {
            const media = JSON.parse(rawMedia);
            return Array.isArray(media) ? media : [];
        } catch (error) {
            return [];
        }
    }

    function createMediaElement(media) {
        const item = document.createElement("div");
        item.className = "cm-media-item";

        const mediaType = String(media.type || "").toLowerCase();
        const mediaPath = String(media.path || "").trim();
        const originalName = String(media.original_name || "").trim();

        if (mediaPath === "") {
            return null;
        }

        if (mediaType === "video") {
            const video = document.createElement("video");
            video.src = mediaPath;
            video.controls = true;
            video.preload = "metadata";

            item.appendChild(video);
        } else {
            const image = document.createElement("img");
            image.src = mediaPath;
            image.alt = originalName || "Complaint evidence";

            item.appendChild(image);
        }

        const caption = document.createElement("div");
        caption.className = "cm-media-caption";
        caption.textContent = originalName || mediaPath.split("/").pop() || "Evidence file";

        item.appendChild(caption);

        return item;
    }

    function renderMedia(rawMedia) {
        const mediaWrap = document.getElementById("modalMediaWrap");
        const gallery = document.getElementById("modalMediaGallery");

        if (!mediaWrap || !gallery) {
            return;
        }

        gallery.innerHTML = "";

        const mediaItems = parseMedia(rawMedia);

        if (mediaItems.length === 0) {
            const empty = document.createElement("div");
            empty.className = "cm-media-empty";
            empty.textContent = "No uploaded evidence found for this complaint.";

            gallery.appendChild(empty);
            mediaWrap.style.display = "block";
            return;
        }

        mediaItems.forEach(function (media) {
            const element = createMediaElement(media);

            if (element) {
                gallery.appendChild(element);
            }
        });

        mediaWrap.style.display = "block";
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
        setText("modalDrain", button.dataset.drain);
        setText("modalAddress", button.dataset.address);
        setText("modalProblem", button.dataset.problem);

        renderMedia(button.dataset.media || "[]");

        modal.classList.add("active");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) return;

        const gallery = document.getElementById("modalMediaGallery");

        if (gallery) {
            gallery.querySelectorAll("video").forEach(function (video) {
                video.pause();
                video.currentTime = 0;
            });
        }

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

    function openRejectModal(button) {
        if (!rejectModal || !button) return;

        const complaintId = button.dataset.complaintId || "";
        const complaintCode = button.dataset.complaintCode || "Complaint";

        if (rejectComplaintId) {
            rejectComplaintId.value = complaintId;
        }

        if (rejectModalCode) {
            rejectModalCode.textContent = complaintCode;
        }

        if (rejectReason) {
            rejectReason.value = "";
        }

        if (rejectReasonError) {
            rejectReasonError.textContent = "";
        }

        rejectModal.classList.add("active");
        document.body.style.overflow = "hidden";

        setTimeout(function () {
            if (rejectReason) {
                rejectReason.focus();
            }
        }, 100);
    }

    function closeRejectModal() {
        if (!rejectModal) return;

        rejectModal.classList.remove("active");
        document.body.style.overflow = "";

        if (rejectReason) {
            rejectReason.value = "";
        }

        if (rejectReasonError) {
            rejectReasonError.textContent = "";
        }
    }

    rejectOpenButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openRejectModal(this);
        });
    });

    if (rejectModalCloseBtn) {
        rejectModalCloseBtn.addEventListener("click", closeRejectModal);
    }

    if (rejectCancelBtn) {
        rejectCancelBtn.addEventListener("click", closeRejectModal);
    }

    if (rejectModal) {
        rejectModal.addEventListener("click", function (event) {
            if (event.target === rejectModal) {
                closeRejectModal();
            }
        });
    }

    if (rejectForm) {
        rejectForm.addEventListener("submit", function (event) {
            const reason = (rejectReason?.value || "").trim();

            if (reason.length < 8) {
                event.preventDefault();

                if (rejectReasonError) {
                    rejectReasonError.textContent = "Reject reason must be at least 8 characters.";
                }

                if (rejectReason) {
                    rejectReason.focus();
                }

                return;
            }

            const ok = confirm("Reject this complaint? The citizen will see this reason.");
            if (!ok) {
                event.preventDefault();
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            if (modal && modal.classList.contains("active")) {
                closeModal();
            }

            if (rejectModal && rejectModal.classList.contains("active")) {
                closeRejectModal();
            }
            
            if (discussionModal && discussionModal.classList.contains("active")) {
                closeDiscussionModal();
            }
        }
    });

    const discussionModal = document.getElementById("discussionModal");
    const discussionModalCloseBtn = document.getElementById("discussionModalCloseBtn");
    const discussionOpenButtons = document.querySelectorAll(".cm-discussion-btn");
    const discussionModalCode = document.getElementById("discussionModalCode");
    const commentComplaintId = document.getElementById("commentComplaintId");

    function openDiscussionModal(button) {
        if (!discussionModal || !button) return;

        const complaintId = button.dataset.complaintId || "";
        const complaintCode = button.dataset.complaintCode || "Complaint";

        if (discussionModalCode) discussionModalCode.textContent = complaintCode;
        if (commentComplaintId) commentComplaintId.value = complaintId;
        
        const currentContainer = document.getElementById("centralDiscussionContainer");
        if (currentContainer) {
            currentContainer.dataset.complaintId = complaintId;
        }

        discussionModal.classList.add("active");
        document.body.style.overflow = "hidden";

        // Call the global commentSystem load function if it exists
        if (typeof window.loadComments === "function") {
            window.loadComments();
        }
    }

    function closeDiscussionModal() {
        if (!discussionModal) return;
        discussionModal.classList.remove("active");
        document.body.style.overflow = "";
    }

    discussionOpenButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openDiscussionModal(this);
        });
    });

    if (discussionModalCloseBtn) {
        discussionModalCloseBtn.addEventListener("click", closeDiscussionModal);
    }

    if (discussionModal) {
        discussionModal.addEventListener("click", function (event) {
            if (event.target === discussionModal) {
                closeDiscussionModal();
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    const openDiscussionId = urlParams.get("open_discussion");

    if (openDiscussionId) {
        const targetBtn = document.querySelector(`.cm-discussion-btn[data-complaint-id="${openDiscussionId}"]`);
        if (targetBtn) {
            targetBtn.click();
            
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }

    filterRows();
});