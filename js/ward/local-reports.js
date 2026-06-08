document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("localReportForm");
    const reportType = document.getElementById("report_type");
    const reportPeriod = document.getElementById("report_period");
    const startDate = document.getElementById("start_date");
    const endDate = document.getElementById("end_date");
    const exportFormat = document.getElementById("export_format");
    const customDateFields = document.querySelectorAll(".custom-date-field");
    const previewCard = document.querySelector(".lr-preview-card");
    const downloadForm = document.querySelector(".lr-preview-download-form");
    const downloadFrame = document.getElementById("wardReportDownloadFrame");
    let downloadPending = false;
    let collapseTimer = null;

    function warn(message) {
        if (typeof showWarningModal === "function") {
            showWarningModal(message);
            return;
        }

        window.alert(message);
    }

    function toggleCustomDates() {
        const isCustom = reportPeriod && reportPeriod.value === "custom_range";

        customDateFields.forEach(function (field) {
            field.hidden = !isCustom;
        });

        if (startDate) startDate.required = isCustom;
        if (endDate) endDate.required = isCustom;
    }

    if (reportPeriod) {
        reportPeriod.addEventListener("change", toggleCustomDates);
        toggleCustomDates();
    }

    if (form) {
        form.addEventListener("submit", function (event) {
            if (!reportType || reportType.value === "") {
                event.preventDefault();
                warn("Please select a report type.");
                reportType?.focus();
                return;
            }

            if (!reportPeriod || reportPeriod.value === "") {
                event.preventDefault();
                warn("Please select a time period.");
                reportPeriod?.focus();
                return;
            }

            if (reportPeriod.value === "custom_range") {
                if (!startDate || startDate.value === "" || !endDate || endDate.value === "") {
                    event.preventDefault();
                    warn("Please select start date and end date.");
                    return;
                }

                if (startDate.value > endDate.value) {
                    event.preventDefault();
                    warn("Start date cannot be after the end date.");
                }
            }
        });
    }

    if (downloadForm && previewCard) {
        downloadForm.addEventListener("submit", function () {
            const hiddenFormat = downloadForm.querySelector('input[name="export_format"]');
            if (hiddenFormat && exportFormat) {
                hiddenFormat.value = exportFormat.value;
            }

            downloadPending = true;

            if (collapseTimer) {
                window.clearTimeout(collapseTimer);
            }

            collapseTimer = window.setTimeout(function () {
                if (downloadPending) {
                    previewCard.classList.add("is-collapsed");
                }
            }, 700);
        });
    }

    if (downloadFrame && previewCard) {
        downloadFrame.addEventListener("load", function () {
            if (!downloadPending) return;

            try {
                const frameDoc = downloadFrame.contentDocument || downloadFrame.contentWindow.document;
                const bodyText = frameDoc && frameDoc.body ? frameDoc.body.textContent.trim() : "";

                if (bodyText !== "") {
                    downloadPending = false;
                    if (collapseTimer) window.clearTimeout(collapseTimer);
                    previewCard.classList.remove("is-collapsed");
                    warn("Download failed. Please try again.");
                }
            } catch (error) {
                // Successful file downloads normally do not expose iframe contents.
            }
        });
    }
});
