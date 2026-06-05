document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("vqSearch");
    const priorityFilter = document.getElementById("vqPriorityFilter");
    const cards = document.querySelectorAll(".vq-card");

    const detailButtons = document.querySelectorAll(".vq-info-btn");
    const modal = document.getElementById("vqDetailsModal");
    const closeBtn = document.getElementById("vqModalClose");

    function filterCards() {
        const searchValue = (searchInput?.value || "").toLowerCase().trim();
        const priorityValue = priorityFilter?.value || "all";

        cards.forEach(function (card) {
            const searchableText = [
                card.dataset.code || "",
                card.dataset.issue || "",
                card.dataset.area || "",
                card.dataset.citizen || ""
            ].join(" ").toLowerCase();

            const cardPriority = card.dataset.priority || "";

            const matchesSearch = searchableText.includes(searchValue);
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
        item.className = "vq-media-item";

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
        } else if (mediaType === "image") {
            const image = document.createElement("img");
            image.src = mediaPath;
            image.alt = originalName || "Complaint evidence";
            item.appendChild(image);
        } else {
            const fileBox = document.createElement("div");
            fileBox.className = "vq-media-empty";

            const fileLink = document.createElement("a");
            fileLink.href = mediaPath;
            fileLink.target = "_blank";
            fileLink.textContent = originalName || mediaPath.split("/").pop() || "Open file";

            fileBox.appendChild(fileLink);
            item.appendChild(fileBox);
        }

        const caption = document.createElement("div");
        caption.className = "vq-media-caption";
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
            empty.className = "vq-media-empty";
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

        setText("modalCode", button.dataset.code);
        setText("modalCitizen", button.dataset.citizen);
        setText("modalEmail", button.dataset.email);
        setText("modalIssue", button.dataset.issue);
        setText("modalPriority", button.dataset.priority);
        setText("modalCorporation", button.dataset.corporation);
        setText("modalThana", button.dataset.thana);
        setText("modalWard", button.dataset.ward);
        setText("modalArea", button.dataset.area);
        setText("modalAddress", button.dataset.address);
        setText("modalProblem", button.dataset.problem);
        setText("modalSubmitted", button.dataset.submitted);

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

    if (closeBtn) {
        closeBtn.addEventListener("click", closeModal);
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

    const verifyButtons = document.querySelectorAll(".vq-verify-btn");
    const rejectButtons = document.querySelectorAll(".vq-reject-btn");
    const duplicateButtons = document.querySelectorAll(".vq-duplicate-btn");

    verifyButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.preventDefault();
            const form = this.closest("form");

            showConfirmModal({
                title: "Verify Complaint",
                message: "Accept and verify this complaint? Status will become Verified.",
                confirmText: "Verify",
                cancelText: "Cancel",
                type: "success",
                onConfirm: function() {
                    form.submit();
                }
            });
        });
    });

    const reasonModal = document.getElementById("vqReasonModal");
    const reasonTitle = document.getElementById("vqReasonTitle");
    const reasonSubtitle = document.getElementById("vqReasonSubtitle");
    const reasonInput = document.getElementById("vqReasonInput");
    const reasonSubmit = document.getElementById("vqReasonSubmit");
    const reasonClose = document.getElementById("vqReasonClose");
    
    let activeForm = null;
    let activeAction = null;

    function openReasonModal(title, subtitle, form, action) {
        if (!reasonModal) return;
        reasonTitle.textContent = title;
        reasonSubtitle.textContent = subtitle;
        reasonInput.value = "";
        activeForm = form;
        activeAction = action;
        
        if (action === 'reject') {
            reasonSubmit.style.background = "#E11D48";
        } else if (action === 'duplicate') {
            reasonSubmit.style.background = "#7E22CE";
        }
        
        reasonModal.classList.add("active");
        document.body.style.overflow = "hidden";
        setTimeout(() => reasonInput.focus(), 100);
    }

    function closeReasonModal() {
        if (!reasonModal) return;
        reasonModal.classList.remove("active");
        document.body.style.overflow = "";
        activeForm = null;
        activeAction = null;
    }

    if (reasonClose) {
        reasonClose.addEventListener("click", closeReasonModal);
    }
    if (reasonModal) {
        reasonModal.addEventListener("click", function(e) {
            if (e.target === reasonModal) closeReasonModal();
        });
    }

    reasonSubmit.addEventListener("click", function() {
        const reason = reasonInput.value.trim();
        if (reason === "") {
            showWarningModal("Please provide a reason.");
            reasonInput.focus();
            return;
        }

        if (activeForm) {
            const reasonHidden = document.createElement("input");
            reasonHidden.type = "hidden";
            reasonHidden.name = "reason";
            reasonHidden.value = reason;
            activeForm.appendChild(reasonHidden);
            
            // Allow form submission to proceed
            activeForm.submit();
        }
    });

    rejectButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.preventDefault();
            openReasonModal("Reject Complaint", "Give a reason for rejection or click duplicate.", this.closest("form"), "reject");
        });
    });

    duplicateButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.preventDefault();
            openReasonModal("Mark as Duplicate", "Provide the reference Complaint ID or reason for duplicate.", this.closest("form"), "duplicate");
        });
    });

    filterCards();
});