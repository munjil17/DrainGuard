document.addEventListener("DOMContentLoaded", function () {
    const data = window.drainFilterData || {};

    const cityCorporations = Array.isArray(data.cityCorporations) ? data.cityCorporations : [];
    const thanas = Array.isArray(data.thanas) ? data.thanas : [];
    const wards = Array.isArray(data.wards) ? data.wards : [];
    const areas = Array.isArray(data.areas) ? data.areas : [];

    const cityFilter = document.getElementById("cityFilter");
    const cityCorporationFilter = document.getElementById("cityCorporationFilter");
    const thanaFilter = document.getElementById("thanaFilter");
    const wardFilter = document.getElementById("wardFilter");
    const areaFilter = document.getElementById("areaFilter");
    const conditionFilter = document.getElementById("conditionFilter");
    const clearBtn = document.getElementById("clearDrainFilters");

    const rows = Array.from(document.querySelectorAll(".dr-row"));
    const noResult = document.getElementById("drNoResult");

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

    function applyFilters() {
        const cityValue = cityFilter ? cityFilter.value : "";
        const cityCorValue = cityCorporationFilter ? cityCorporationFilter.value : "";
        const thanaValue = thanaFilter ? thanaFilter.value : "";
        const wardValue = wardFilter ? wardFilter.value : "";
        const areaValue = areaFilter ? areaFilter.value : "";
        const conditionValue = conditionFilter ? conditionFilter.value : "";

        let visibleCount = 0;

        rows.forEach(function (row) {
            const cityMatch = !cityValue || row.dataset.cityId === cityValue;
            const cityCorMatch = !cityCorValue || row.dataset.cityCorId === cityCorValue;
            const thanaMatch = !thanaValue || row.dataset.thanaId === thanaValue;
            const wardMatch = !wardValue || row.dataset.wardId === wardValue;
            const areaMatch = !areaValue || row.dataset.areaId === areaValue;
            const conditionMatch = !conditionValue || row.dataset.condition === conditionValue;

            if (
                cityMatch &&
                cityCorMatch &&
                thanaMatch &&
                wardMatch &&
                areaMatch &&
                conditionMatch
            ) {
                row.hidden = false;
                visibleCount++;
            } else {
                row.hidden = true;
            }
        });

        if (noResult) {
            noResult.hidden = visibleCount !== 0;
        }
    }

    function clearFilters() {
        if (cityFilter) cityFilter.value = "";
        resetSelect(cityCorporationFilter, "All City Corporation");
        resetSelect(thanaFilter, "All Thana");
        resetSelect(wardFilter, "All Ward");
        resetSelect(areaFilter, "All Area");

        if (conditionFilter) conditionFilter.value = "";

        applyFilters();
    }

    if (cityFilter) {
        cityFilter.addEventListener("change", function () {
            const cityId = cityFilter.value;

            resetSelect(cityCorporationFilter, "All City Corporation");
            resetSelect(thanaFilter, "All Thana");
            resetSelect(wardFilter, "All Ward");
            resetSelect(areaFilter, "All Area");

            if (!cityId) {
                applyFilters();
                return;
            }

            cityCorporations
                .filter(function (corp) {
                    return String(corp.city_id) === String(cityId);
                })
                .forEach(function (corp) {
                    addOption(cityCorporationFilter, corp.city_cor_id, corp.city_cor_name);
                });

            enableSelect(cityCorporationFilter);
            applyFilters();
        });
    }

    if (cityCorporationFilter) {
        cityCorporationFilter.addEventListener("change", function () {
            const cityCorId = cityCorporationFilter.value;

            resetSelect(thanaFilter, "All Thana");
            resetSelect(wardFilter, "All Ward");
            resetSelect(areaFilter, "All Area");

            if (!cityCorId) {
                applyFilters();
                return;
            }

            thanas
                .filter(function (thana) {
                    return String(thana.city_cor_id) === String(cityCorId);
                })
                .forEach(function (thana) {
                    addOption(thanaFilter, thana.thana_id, thana.thana_name);
                });

            enableSelect(thanaFilter);
            applyFilters();
        });
    }

    if (thanaFilter) {
        thanaFilter.addEventListener("change", function () {
            const thanaId = thanaFilter.value;

            resetSelect(wardFilter, "All Ward");
            resetSelect(areaFilter, "All Area");

            if (!thanaId) {
                applyFilters();
                return;
            }

            wards
                .filter(function (ward) {
                    return String(ward.thana_id) === String(thanaId);
                })
                .forEach(function (ward) {
                    const label = ward.ward_name || `Ward ${ward.ward_no || ""}`;
                    addOption(wardFilter, ward.ward_id, label);
                });

            enableSelect(wardFilter);
            applyFilters();
        });
    }

    if (wardFilter) {
        wardFilter.addEventListener("change", function () {
            const wardId = wardFilter.value;

            resetSelect(areaFilter, "All Area");

            if (!wardId) {
                applyFilters();
                return;
            }

            areas
                .filter(function (area) {
                    return String(area.ward_id) === String(wardId);
                })
                .forEach(function (area) {
                    addOption(areaFilter, area.area_id, area.area_name);
                });

            enableSelect(areaFilter);
            applyFilters();
        });
    }

    if (areaFilter) {
        areaFilter.addEventListener("change", applyFilters);
    }

    if (conditionFilter) {
        conditionFilter.addEventListener("change", applyFilters);
    }

    if (clearBtn) {
        clearBtn.addEventListener("click", clearFilters);
    }

    applyFilters();
});