document.addEventListener("DOMContentLoaded", function () {
    const modal = document.getElementById("progressModal");
    const detailButtons = document.querySelectorAll(".details-btn");
    const closeButtons = document.querySelectorAll("[data-close-progress-modal]");

    const modalComplaintCode = document.getElementById("modalComplaintCode");
    const modalIssue = document.getElementById("modalIssue");
    const modalPriority = document.getElementById("modalPriority");
    const modalAddress = document.getElementById("modalAddress");
    const modalWard = document.getElementById("modalWard");
    const modalArea = document.getElementById("modalArea");
    const modalProblem = document.getElementById("modalProblem");
    const modalNote = document.getElementById("modalNote");
    const modalAssignedBy = document.getElementById("modalAssignedBy");
    const modalStartedAt = document.getElementById("modalStartedAt");
    const modalDeadline = document.getElementById("modalDeadline");
    const modalMediaBox = document.getElementById("modalMediaBox");
    const modalDownloadBtn = document.getElementById("modalDownloadBtn");

    function setText(element, value) {
        if (element) {
            element.textContent = value || "N/A";
        }
    }

    function openModal(button) {
        if (!modal) {
            return;
        }

        const mediaPath = button.getAttribute("data-media-path") || "";
        const mediaType = button.getAttribute("data-media-type") || "";

        setText(modalComplaintCode, button.getAttribute("data-complaint-code"));
        setText(modalIssue, button.getAttribute("data-issue"));
        setText(modalPriority, button.getAttribute("data-priority"));
        setText(modalAddress, button.getAttribute("data-address"));
        setText(modalWard, button.getAttribute("data-ward"));
        setText(modalArea, button.getAttribute("data-area"));
        setText(modalProblem, button.getAttribute("data-problem"));
        setText(modalNote, button.getAttribute("data-note"));
        setText(modalAssignedBy, button.getAttribute("data-assigned-by"));
        setText(modalStartedAt, button.getAttribute("data-started-at"));
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
        if (!modal) {
            return;
        }

        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    detailButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button);
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener("click", closeModal);
    });

    const supportModal = document.getElementById("supportModal");
    const supportButtons = document.querySelectorAll(".need-support-btn");
    const closeSupportButtons = document.querySelectorAll("[data-close-support-modal]");
    const supportReasonButtons = document.querySelectorAll(".support-reason-btn");
    const supportComplaintCode = document.getElementById("supportComplaintCode");

    let activeSupportAssignmentId = null;

    function openSupportModal(button) {
        if (!supportModal) {
            return;
        }

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
        if (!supportModal) {
            return;
        }

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

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal();
            closeSupportModal();
        }
    });
});