document.addEventListener("DOMContentLoaded", function () {
    const filterData = window.reportFilterData || {};

    const thanas = Array.isArray(filterData.thanas) ? filterData.thanas : [];
    const wards = Array.isArray(filterData.wards) ? filterData.wards : [];
    const areas = Array.isArray(filterData.areas) ? filterData.areas : [];
    const selected = filterData.selected || {};

    const reportType = document.getElementById("report_type");
    const reportPeriod = document.getElementById("report_period");
    const cityCorSelect = document.getElementById("city_cor_id");
    const thanaSelect = document.getElementById("thana_id");
    const wardSelect = document.getElementById("ward_id");
    const areaSelect = document.getElementById("area_id");

    const startDate = document.getElementById("start_date");
    const endDate = document.getElementById("end_date");
    const customDateFields = document.querySelectorAll(".custom-date-field");

    const form = document.getElementById("reportBuilderForm");
    const previewCard = document.querySelector(".reports-preview-card");
    const downloadForm = document.querySelector(".reports-preview-download-form");
    const downloadFrame = document.getElementById("centralReportDownloadFrame");
    let downloadPending = false;
    let collapseTimer = null;

    function resetSelect(select, placeholder) {
        if (!select) return;

        select.innerHTML = `<option value="">${placeholder}</option>`;
        select.disabled = true;
    }

    function enableSelect(select) {
        if (!select) return;
        select.disabled = false;
    }

    function addOption(select, value, text) {
        if (!select) return;

        const option = document.createElement("option");
        option.value = value;
        option.textContent = text;
        select.appendChild(option);
    }

    function populateThanas(cityCorId, selectedValue) {
        resetSelect(thanaSelect, "All Thanas");
        resetSelect(wardSelect, "All Wards");
        resetSelect(areaSelect, "All Areas");

        if (!cityCorId) return;

        thanas
            .filter(function (thana) {
                return String(thana.city_cor_id) === String(cityCorId);
            })
            .forEach(function (thana) {
                addOption(thanaSelect, thana.thana_id, thana.thana_name);
            });

        enableSelect(thanaSelect);
        if (selectedValue) thanaSelect.value = String(selectedValue);
    }

    function populateWards(thanaId, cityCorId, selectedValue) {
        resetSelect(wardSelect, "All Wards");
        resetSelect(areaSelect, "All Areas");

        if (!thanaId) return;

        wards
            .filter(function (ward) {
                return String(ward.thana_id) === String(thanaId)
                    && (!cityCorId || String(ward.city_cor_id) === String(cityCorId));
            })
            .forEach(function (ward) {
                const label = ward.ward_name ? `Ward ${ward.ward_no} - ${ward.ward_name}` : `Ward ${ward.ward_no || ""}`;
                addOption(wardSelect, ward.ward_id, label);
            });

        enableSelect(wardSelect);
        if (selectedValue) wardSelect.value = String(selectedValue);
    }

    function populateAreas(wardId, selectedValue) {
        resetSelect(areaSelect, "All Areas");

        if (!wardId) return;

        areas
            .filter(function (area) {
                return String(area.ward_id) === String(wardId);
            })
            .forEach(function (area) {
                addOption(areaSelect, area.area_id, area.area_name);
            });

        enableSelect(areaSelect);
        if (selectedValue) areaSelect.value = String(selectedValue);
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

    if (cityCorSelect) {
        cityCorSelect.addEventListener("change", function () {
            populateThanas(cityCorSelect.value, "");
        });
    }

    if (thanaSelect) {
        thanaSelect.addEventListener("change", function () {
            populateWards(thanaSelect.value, cityCorSelect ? cityCorSelect.value : "", "");
        });
    }

    if (wardSelect) {
        wardSelect.addEventListener("change", function () {
            populateAreas(wardSelect.value, "");
        });
    }

    if (cityCorSelect && cityCorSelect.value) {
        populateThanas(cityCorSelect.value, selected.thanaId || "");
        populateWards(selected.thanaId || "", cityCorSelect.value, selected.wardId || "");
        populateAreas(selected.wardId || "", selected.areaId || "");
    }

    if (form) {
        form.addEventListener("submit", function (event) {
            if (!reportType || reportType.value === "") {
                event.preventDefault();
                showWarningModal("Please select a report type.");
                reportType.focus();
                return;
            }

            if (!reportPeriod || reportPeriod.value === "") {
                event.preventDefault();
                showWarningModal("Please select a time period.");
                reportPeriod.focus();
                return;
            }

            if (reportPeriod.value === "custom_range") {
                if (!startDate.value || !endDate.value) {
                    event.preventDefault();
                    showWarningModal("Please select start date and end date.");
                    return;
                }

                if (startDate.value > endDate.value) {
                    event.preventDefault();
                    showWarningModal("Start date cannot be after the end date.");
                    return;
                }
            }
        });
    }

    if (downloadForm && previewCard) {
        downloadForm.addEventListener("submit", function () {
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
                    showWarningModal("Download failed. Please try again.");
                }
            } catch (error) {
                // File downloads may not expose iframe contents; the collapse timer handles success.
            }
        });
    }
});
