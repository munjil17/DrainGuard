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
            element.textContent = value && value.trim() !== "" ? value : "N/A";
        }
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

        const imageWrap = document.getElementById("modalImageWrap");
        const modalImage = document.getElementById("modalImage");
        const downloadBtn = document.getElementById("modalDownloadBtn");

        if (imageWrap && modalImage && downloadBtn) {
            const imagePath = button.dataset.image || "";

            if (imagePath.trim() !== "") {
                imageWrap.style.display = "block";
                modalImage.src = imagePath;
                downloadBtn.href = imagePath;
            } else {
                imageWrap.style.display = "none";
                modalImage.src = "";
                downloadBtn.href = "#";
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
            const confirmed = confirm("Accept and verify this complaint? Status will become Verified.");

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    rejectButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            const confirmed = confirm("Reject this complaint? Status will become Rejected.");

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    duplicateButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            const confirmed = confirm("Mark this complaint as Duplicate? Status will become Duplicate.");

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    filterCards();
});