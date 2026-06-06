document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("localReportForm");
    const reportPeriod = document.getElementById("reportPeriod");
    const customRangeBox = document.getElementById("customRangeBox");
    const startDate = document.getElementById("startDate");
    const endDate = document.getElementById("endDate");
    const autoDownloadReport = document.getElementById("autoDownloadReport");

    function toggleCustomRange() {
        if (!reportPeriod || !customRangeBox) return;

        if (reportPeriod.value === "custom_range") {
            customRangeBox.classList.remove("d-none");

            if (startDate) {
                startDate.setAttribute("required", "required");
            }

            if (endDate) {
                endDate.setAttribute("required", "required");
            }
        } else {
            customRangeBox.classList.add("d-none");

            if (startDate) {
                startDate.removeAttribute("required");
                startDate.value = "";
            }

            if (endDate) {
                endDate.removeAttribute("required");
                endDate.value = "";
            }
        }
    }

    if (reportPeriod) {
        reportPeriod.addEventListener("change", toggleCustomRange);
    }

    if (form) {
        form.addEventListener("submit", function (event) {
            const reportType = form.querySelector('select[name="report_type"]');
            const period = form.querySelector('select[name="report_period"]');
            const exportFormat = form.querySelector('select[name="export_format"]');

            if (!reportType || reportType.value === "") {
                event.preventDefault();
                showWarningModal("Please select a report type.");
                reportType?.focus();
                return;
            }

            if (!period || period.value === "") {
                event.preventDefault();
                showWarningModal("Please select a time period.");
                period?.focus();
                return;
            }

            if (period.value === "custom_range") {
                if (!startDate || startDate.value === "") {
                    event.preventDefault();
                    showWarningModal("Please select a start date.");
                    startDate?.focus();
                    return;
                }

                if (!endDate || endDate.value === "") {
                    event.preventDefault();
                    showWarningModal("Please select an end date.");
                    endDate?.focus();
                    return;
                }

                if (startDate.value > endDate.value) {
                    event.preventDefault();
                    showWarningModal("Start date cannot be after the end date.");
                    startDate.focus();
                    return;
                }
            }

            if (!exportFormat || exportFormat.value === "") {
                event.preventDefault();
                showWarningModal("Please select an export format.");
                exportFormat?.focus();
            }
        });
    }

    if (autoDownloadReport) {
        setTimeout(function () {
            autoDownloadReport.click();
        }, 600);
    }

    toggleCustomRange();
});