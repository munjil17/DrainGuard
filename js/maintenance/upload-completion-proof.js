document.addEventListener("DOMContentLoaded", function () {
    const forms = document.querySelectorAll(".proof-form");

    const allowedImageTypes = [
        "image/jpeg",
        "image/png",
        "image/webp",
        "image/gif",
        "image/bmp",
        "image/svg+xml"
    ];

    const allowedVideoTypes = [
        "video/mp4",
        "video/webm",
        "video/ogg",
        "video/quicktime",
        "video/x-msvideo",
        "video/x-matroska"
    ];

    const maxFileSize = 25 * 1024 * 1024;

    function isAllowedFile(file) {
        return allowedImageTypes.includes(file.type) || allowedVideoTypes.includes(file.type);
    }

    function renderPreviews(input, previewGrid) {
        previewGrid.innerHTML = "";

        const files = Array.from(input.files || []);

        files.forEach(function (file) {
            const previewItem = document.createElement("div");
            previewItem.className = "preview-item";

            const typeBadge = document.createElement("span");
            typeBadge.className = "preview-type";
            typeBadge.textContent = file.type.startsWith("video/") ? "Video" : "Image";

            const url = URL.createObjectURL(file);

            if (file.type.startsWith("video/")) {
                const video = document.createElement("video");
                video.src = url;
                video.muted = true;
                video.controls = true;
                previewItem.appendChild(video);
            } else {
                const img = document.createElement("img");
                img.src = url;
                img.alt = file.name;
                previewItem.appendChild(img);
            }

            previewItem.appendChild(typeBadge);
            previewGrid.appendChild(previewItem);
        });
    }

    forms.forEach(function (form) {
        const fileInput = form.querySelector(".after-files-input");
        const previewGrid = form.querySelector(".preview-grid");
        const noteInput = form.querySelector(".completion-note");
        const submitButton = form.querySelector(".submit-proof-btn");

        let isSubmitting = false;

        if (fileInput && previewGrid) {
            fileInput.addEventListener("change", function () {
                const files = Array.from(fileInput.files || []);

                for (const file of files) {
                    if (!isAllowedFile(file)) {
                        showWarningModal("Unsupported file type: " + file.name);
                        fileInput.value = "";
                        previewGrid.innerHTML = "";
                        return;
                    }

                    if (file.size > maxFileSize) {
                        showWarningModal("Each file must be 25MB or less: " + file.name);
                        fileInput.value = "";
                        previewGrid.innerHTML = "";
                        return;
                    }
                }

                renderPreviews(fileInput, previewGrid);
            });
        }

        form.addEventListener("submit", function (event) {
            event.preventDefault();

            if (isSubmitting) {
                return;
            }

            const files = fileInput ? Array.from(fileInput.files || []) : [];
            const noteValue = noteInput ? noteInput.value.trim() : "";

            if (files.length === 0) {
                showWarningModal("Please upload at least one after-work photo or video.");
                return;
            }

            for (const file of files) {
                if (!isAllowedFile(file)) {
                    showWarningModal("Unsupported file type: " + file.name);
                    return;
                }

                if (file.size > maxFileSize) {
                    showWarningModal("Each file must be 25MB or less: " + file.name);
                    return;
                }
            }

            if (noteValue === "") {
                showWarningModal("Please write work completion notes.");
                return;
            }

            showConfirmModal({
                title: "Submit Proof",
                message: "Submit proof to Inspector? This will mark the complaint as Solved by Team.",
                confirmText: "Submit",
                cancelText: "Cancel",
                type: "success",
                onConfirm: function() {
                    isSubmitting = true;

                    const formData = new FormData(form);

                    submitButton.disabled = true;
                    submitButton.classList.add("is-loading");
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Submitting to Inspector...';

                    fetch("upload-completion-proof.php", {
                        method: "POST",
                        body: formData
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (data) {
                            showWarningModal(data.message || "Request processed.");

                            if (data.success) {
                                window.location.href = "upload-completion-proof.php";
                            } else {
                                isSubmitting = false;
                                submitButton.disabled = false;
                                submitButton.classList.remove("is-loading");
                                submitButton.innerHTML = '<i class="bi bi-send"></i> Submit to Inspector';
                            }
                        })
                        .catch(function () {
                            showWarningModal("Failed to submit proof. Please try again.");

                            isSubmitting = false;
                            submitButton.disabled = false;
                            submitButton.classList.remove("is-loading");
                            submitButton.innerHTML = '<i class="bi bi-send"></i> Submit to Inspector';
                        });
                }
            });
        });
    });
});