document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("taskSearchInput");
    const priorityFilter = document.getElementById("priorityFilter");
    const wardFilter = document.getElementById("wardFilter");
    const areaFilter = document.getElementById("areaFilter");
    const sortFilter = document.getElementById("sortFilter");
    const taskList = document.getElementById("assignedTaskList");
    const filterEmptyState = document.getElementById("filterEmptyState");
    const initialEmptyState = document.querySelector(".initial-empty-state");

    function getTaskCards() {
        return Array.from(document.querySelectorAll(".task-card"));
    }

    function priorityRank(priority) {
        if (priority === "High") return 1;
        if (priority === "Medium") return 2;
        return 3;
    }

    function getDateValue(dateString, fallbackValue) {
        if (!dateString) return fallbackValue;

        const dateValue = new Date(dateString).getTime();

        if (Number.isNaN(dateValue)) {
            return fallbackValue;
        }

        return dateValue;
    }

    function isOverdue(card) {
        const deadline = card.getAttribute("data-deadline") || "";

        if (!deadline) return false;

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const deadlineDate = new Date(deadline);
        deadlineDate.setHours(0, 0, 0, 0);

        return deadlineDate < today;
    }

    function syncAreaOptionsWithWard() {
        if (!wardFilter || !areaFilter) return;

        const selectedWardId = wardFilter.value;
        const areaOptions = Array.from(areaFilter.options);

        areaOptions.forEach(function (option) {
            if (option.value === "all") {
                option.hidden = false;
                return;
            }

            const optionWardId = option.getAttribute("data-ward-id");
            option.hidden = selectedWardId !== "all" && optionWardId !== selectedWardId;
        });

        const selectedAreaOption = areaFilter.options[areaFilter.selectedIndex];

        if (selectedAreaOption && selectedAreaOption.hidden) {
            areaFilter.value = "all";
        }
    }

    function filterAndSortTasks() {
        syncAreaOptionsWithWard();

        const searchValue = searchInput ? searchInput.value.trim().toLowerCase() : "";
        const priorityValue = priorityFilter ? priorityFilter.value : "all";
        const wardValue = wardFilter ? wardFilter.value : "all";
        const areaValue = areaFilter ? areaFilter.value : "all";
        const sortValue = sortFilter ? sortFilter.value : "priority";

        const cards = getTaskCards();

        cards.forEach(function (card) {
            const searchableText = card.getAttribute("data-search") || "";
            const cardPriority = card.getAttribute("data-priority") || "";
            const cardWardId = card.getAttribute("data-ward-id") || "";
            const cardAreaId = card.getAttribute("data-area-id") || "";

            const matchesSearch = searchableText.includes(searchValue);
            const matchesPriority = priorityValue === "all" || cardPriority === priorityValue;
            const matchesWard = wardValue === "all" || cardWardId === wardValue;
            const matchesArea = areaValue === "all" || cardAreaId === areaValue;

            let matchesDeadlineMode = true;

            if (sortValue === "overdue") {
                matchesDeadlineMode = isOverdue(card);
            }

            card.style.display =
                matchesSearch && matchesPriority && matchesWard && matchesArea && matchesDeadlineMode
                    ? "grid"
                    : "none";
        });

        const visibleCards = cards.filter(function (card) {
            return card.style.display !== "none";
        });

        visibleCards.sort(function (a, b) {
            if (sortValue === "newest") {
                return getDateValue(b.getAttribute("data-assigned-at"), 0) - getDateValue(a.getAttribute("data-assigned-at"), 0);
            }

            if (sortValue === "closest_deadline" || sortValue === "overdue") {
                return getDateValue(a.getAttribute("data-deadline"), 9999999999999) - getDateValue(b.getAttribute("data-deadline"), 9999999999999);
            }

            return priorityRank(a.getAttribute("data-priority")) - priorityRank(b.getAttribute("data-priority"));
        });

        if (taskList) {
            visibleCards.forEach(function (card) {
                taskList.appendChild(card);
            });
        }

        if (filterEmptyState) {
            filterEmptyState.style.display = cards.length > 0 && visibleCards.length === 0 ? "block" : "none";
        }

        if (initialEmptyState) {
            initialEmptyState.style.display = cards.length === 0 ? "block" : "none";
        }
    }

    if (searchInput) searchInput.addEventListener("input", filterAndSortTasks);
    if (priorityFilter) priorityFilter.addEventListener("change", filterAndSortTasks);
    if (wardFilter) wardFilter.addEventListener("change", filterAndSortTasks);
    if (areaFilter) areaFilter.addEventListener("change", filterAndSortTasks);
    if (sortFilter) sortFilter.addEventListener("change", filterAndSortTasks);

    filterAndSortTasks();

    const modal = document.getElementById("complaintModal");
    const detailButtons = document.querySelectorAll(".details-btn");
    const closeModalButtons = document.querySelectorAll("[data-close-modal]");

    const modalComplaintCode = document.getElementById("modalComplaintCode");
    const modalIssue = document.getElementById("modalIssue");
    const modalPriority = document.getElementById("modalPriority");
    const modalAssignmentStatus = document.getElementById("modalAssignmentStatus");
    const modalComplaintStatus = document.getElementById("modalComplaintStatus");
    const modalAddress = document.getElementById("modalAddress");
    const modalWard = document.getElementById("modalWard");
    const modalArea = document.getElementById("modalArea");
    const modalProblem = document.getElementById("modalProblem");
    const modalNote = document.getElementById("modalNote");
    const modalAssignedBy = document.getElementById("modalAssignedBy");
    const modalAssignedAt = document.getElementById("modalAssignedAt");
    const modalSubmittedAt = document.getElementById("modalSubmittedAt");
    const modalDeadline = document.getElementById("modalDeadline");
    const modalMediaBox = document.getElementById("modalMediaBox");
    const modalDownloadBtn = document.getElementById("modalDownloadBtn");

    function setText(element, value) {
        if (element) {
            element.textContent = value || "N/A";
        }
    }

    function openModal(button) {
        if (!modal) return;

        const mediaPath = button.getAttribute("data-media-path") || "";
        const mediaType = button.getAttribute("data-media-type") || "";

        setText(modalComplaintCode, button.getAttribute("data-complaint-code"));
        setText(modalIssue, button.getAttribute("data-issue"));
        setText(modalPriority, button.getAttribute("data-priority"));
        setText(modalAssignmentStatus, button.getAttribute("data-assignment-status"));
        setText(modalComplaintStatus, button.getAttribute("data-complaint-status"));
        setText(modalAddress, button.getAttribute("data-address"));
        setText(modalWard, button.getAttribute("data-ward"));
        setText(modalArea, button.getAttribute("data-area"));
        setText(modalProblem, button.getAttribute("data-problem"));
        setText(modalNote, button.getAttribute("data-note"));
        setText(modalAssignedBy, button.getAttribute("data-assigned-by"));
        setText(modalAssignedAt, button.getAttribute("data-assigned-at"));
        setText(modalSubmittedAt, button.getAttribute("data-submitted-at"));
        setText(modalDeadline, button.getAttribute("data-deadline"));

        if (modalMediaBox) {
            if (mediaPath && mediaType === "image") {
                modalMediaBox.innerHTML = '<img src="' + mediaPath + '" alt="Complaint media">';
            } else if (mediaPath && mediaType === "video") {
                modalMediaBox.innerHTML = '<video src="' + mediaPath + '" controls></video>';
            } else {
                modalMediaBox.innerHTML =
                    '<div class="modal-no-media">' +
                    '<i class="bi bi-image"></i>' +
                    '<span>No media available</span>' +
                    '</div>';
            }
        }

        if (modalDownloadBtn) {
            if (mediaPath) {
                modalDownloadBtn.setAttribute("href", mediaPath);
                modalDownloadBtn.classList.remove("is-disabled");
                modalDownloadBtn.innerHTML = '<i class="bi bi-download"></i> Download Complaint Photo';
            } else {
                modalDownloadBtn.setAttribute("href", "#");
                modalDownloadBtn.classList.add("is-disabled");
                modalDownloadBtn.innerHTML = '<i class="bi bi-download"></i> No Photo Available';
            }
        }

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

    detailButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button);
        });
    });

    closeModalButtons.forEach(function (button) {
        button.addEventListener("click", closeModal);
    });

    const supportModal = document.getElementById("supportModal");
    const supportButtons = document.querySelectorAll(".need-support-btn");
    const closeSupportButtons = document.querySelectorAll("[data-close-support-modal]");
    const supportReasonButtons = document.querySelectorAll(".support-reason-btn");
    const supportComplaintCode = document.getElementById("supportComplaintCode");

    let activeSupportAssignmentId = null;

    function openSupportModal(button) {
        if (!supportModal) return;

        activeSupportAssignmentId = button.getAttribute("data-assignment-id");

        const complaintCode = button.getAttribute("data-complaint-code") || "Complaint";

        if (supportComplaintCode) {
            supportComplaintCode.textContent = complaintCode;
        }

        supportModal.classList.add("is-open");
        supportModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
    }

    function closeSupportModal() {
        if (!supportModal) return;

        supportModal.classList.remove("is-open");
        supportModal.setAttribute("aria-hidden", "true");
        activeSupportAssignmentId = null;
        document.body.style.overflow = "";
    }

    supportButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openSupportModal(button);
        });
    });

    closeSupportButtons.forEach(function (button) {
        button.addEventListener("click", closeSupportModal);
    });

    supportReasonButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            const reason = button.getAttribute("data-reason");

            if (!activeSupportAssignmentId || !reason) {
                alert("Support request data missing.");
                return;
            }

            const formData = new FormData();
            formData.append("assignment_id", activeSupportAssignmentId);
            formData.append("support_reason", reason);

            button.classList.add("is-loading");
            button.disabled = true;

            fetch("../../notifications/send_maintenance_support_request.php", {
                method: "POST",
                body: formData
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    alert(data.message || "Support request processed.");

                    if (data.success) {
                        closeSupportModal();
                    }
                })
                .catch(function () {
                    alert("Failed to send support request.");
                })
                .finally(function () {
                    button.classList.remove("is-loading");
                    button.disabled = false;
                });
        });
    });

    const startButtons = document.querySelectorAll(".start-btn");

    startButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            const assignmentId = button.getAttribute("data-assignment-id");

            if (!assignmentId) {
                alert("Assignment ID not found.");
                return;
            }

            const confirmed = confirm("Do you want to start work on this assigned task?");

            if (!confirmed) return;

            const formData = new FormData();
            formData.append("action", "start_work");
            formData.append("assignment_id", assignmentId);

            button.disabled = true;
            button.classList.add("is-loading");
            button.innerHTML = '<i class="bi bi-hourglass-split"></i> Starting...';

            fetch("assigned-tasks.php", {
                method: "POST",
                body: formData
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    alert(data.message || "Request processed.");

                    if (data.success) {
                        window.location.href = "in-progress-work.php";
                    } else {
                        button.disabled = false;
                        button.classList.remove("is-loading");
                        button.innerHTML = '<i class="bi bi-wrench"></i> Start Work';
                    }
                })
                .catch(function () {
                    alert("Failed to start work. Please try again.");

                    button.disabled = false;
                    button.classList.remove("is-loading");
                    button.innerHTML = '<i class="bi bi-wrench"></i> Start Work';
                });
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal();
            closeSupportModal();
        }
    });
});