document.addEventListener("DOMContentLoaded", function () {
    const filterData = window.reportFilterData || {};

    const thanas = Array.isArray(filterData.thanas) ? filterData.thanas : [];
    const wards = Array.isArray(filterData.wards) ? filterData.wards : [];
    const areas = Array.isArray(filterData.areas) ? filterData.areas : [];

    const reportType = document.getElementById("report_type");
    const reportPeriod = document.getElementById("report_period");
    const exportFormat = document.getElementById("export_format");

    const cityCorSelect = document.getElementById("city_cor_id");
    const thanaSelect = document.getElementById("thana_id");
    const wardSelect = document.getElementById("ward_id");
    const areaSelect = document.getElementById("area_id");

    const startDate = document.getElementById("start_date");
    const endDate = document.getElementById("end_date");
    const customDateFields = document.querySelectorAll(".custom-date-field");

    const form = document.getElementById("reportBuilderForm");

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
            const cityCorId = cityCorSelect.value;

            resetSelect(thanaSelect, "All Thanas");
            resetSelect(wardSelect, "All Wards");
            resetSelect(areaSelect, "All Areas");

            if (!cityCorId) {
                return;
            }

            thanas
                .filter(function (thana) {
                    return String(thana.city_cor_id) === String(cityCorId);
                })
                .forEach(function (thana) {
                    addOption(thanaSelect, thana.thana_id, thana.thana_name);
                });

            enableSelect(thanaSelect);
        });
    }

    if (thanaSelect) {
        thanaSelect.addEventListener("change", function () {
            const thanaId = thanaSelect.value;
            const cityCorId = cityCorSelect ? cityCorSelect.value : "";

            resetSelect(wardSelect, "All Wards");
            resetSelect(areaSelect, "All Areas");

            if (!thanaId) {
                return;
            }

            wards
                .filter(function (ward) {
                    return String(ward.thana_id) === String(thanaId)
                        && (!cityCorId || String(ward.city_cor_id) === String(cityCorId));
                })
                .forEach(function (ward) {
                    const label = ward.ward_name || `Ward ${ward.ward_no || ""}`;
                    addOption(wardSelect, ward.ward_id, label);
                });

            enableSelect(wardSelect);
        });
    }

    if (wardSelect) {
        wardSelect.addEventListener("change", function () {
            const wardId = wardSelect.value;

            resetSelect(areaSelect, "All Areas");

            if (!wardId) {
                return;
            }

            areas
                .filter(function (area) {
                    return String(area.ward_id) === String(wardId);
                })
                .forEach(function (area) {
                    addOption(areaSelect, area.area_id, area.area_name);
                });

            enableSelect(areaSelect);
        });
    }

    if (form) {
        form.addEventListener("submit", function (event) {
            if (!reportType || reportType.value === "") {
                event.preventDefault();
                alert("Please select a report type.");
                reportType.focus();
                return;
            }

            if (!reportPeriod || reportPeriod.value === "") {
                event.preventDefault();
                alert("Please select a time period.");
                reportPeriod.focus();
                return;
            }

            if (reportPeriod.value === "custom_range") {
                if (!startDate.value || !endDate.value) {
                    event.preventDefault();
                    alert("Please select start date and end date.");
                    return;
                }

                if (startDate.value > endDate.value) {
                    event.preventDefault();
                    alert("Start date cannot be after end date.");
                    return;
                }
            }

            if (!exportFormat || exportFormat.value === "") {
                event.preventDefault();
                alert("Please select an export format.");
                exportFormat.focus();
                return;
            }

            const confirmed = confirm("Generate and download this report now?");

            if (!confirmed) {
                event.preventDefault();
            }
        });
    }
});