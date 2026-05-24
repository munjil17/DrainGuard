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
    const riskFilter = document.getElementById("riskFilter");
    const clearBtn = document.getElementById("clearDrainFilters");

    const rows = Array.from(document.querySelectorAll(".dr-row"));
    const noResult = document.getElementById("drNoResult");

    const modal = document.getElementById("drainDetailsModal");
    const closeModalBtn = document.getElementById("closeDrainModal");

    const modalDrainName = document.getElementById("modalDrainName");
    const modalDrainCode = document.getElementById("modalDrainCode");
    const modalDrainLocation = document.getElementById("modalDrainLocation");
    const modalDrainWard = document.getElementById("modalDrainWard");
    const modalDrainCondition = document.getElementById("modalDrainCondition");
    const modalDrainRisk = document.getElementById("modalDrainRisk");
    const modalDrainAddress = document.getElementById("modalDrainAddress");
    const modalDrainCity = document.getElementById("modalDrainCity");
    const modalDrainCorporation = document.getElementById("modalDrainCorporation");
    const modalDrainUpdatedBy = document.getElementById("modalDrainUpdatedBy");
    const modalDrainUpdatedRole = document.getElementById("modalDrainUpdatedRole");
    const modalDrainUpdatedAt = document.getElementById("modalDrainUpdatedAt");

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
        const riskValue = riskFilter ? riskFilter.value : "";

        let visibleCount = 0;

        rows.forEach(function (row) {
            const cityMatch = !cityValue || row.dataset.cityId === cityValue;
            const cityCorMatch = !cityCorValue || row.dataset.cityCorId === cityCorValue;
            const thanaMatch = !thanaValue || row.dataset.thanaId === thanaValue;
            const wardMatch = !wardValue || row.dataset.wardId === wardValue;
            const areaMatch = !areaValue || row.dataset.areaId === areaValue;
            const conditionMatch = !conditionValue || row.dataset.condition === conditionValue;
            const riskMatch = !riskValue || row.dataset.risk === riskValue;

            if (
                cityMatch &&
                cityCorMatch &&
                thanaMatch &&
                wardMatch &&
                areaMatch &&
                conditionMatch &&
                riskMatch
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
        if (riskFilter) riskFilter.value = "";

        applyFilters();
    }

    function openModal(button) {
        if (!modal || !button) return;

        if (modalDrainName) modalDrainName.textContent = button.dataset.name || "Drain Details";
        if (modalDrainCode) modalDrainCode.textContent = button.dataset.code || "Drain Code";
        if (modalDrainLocation) modalDrainLocation.textContent = button.dataset.location || "-";
        if (modalDrainWard) modalDrainWard.textContent = button.dataset.ward || "-";
        if (modalDrainCondition) modalDrainCondition.textContent = button.dataset.condition || "-";
        if (modalDrainRisk) modalDrainRisk.textContent = button.dataset.risk || "-";
        if (modalDrainAddress) modalDrainAddress.textContent = button.dataset.address || "-";
        if (modalDrainCity) modalDrainCity.textContent = button.dataset.city || "-";
        if (modalDrainCorporation) modalDrainCorporation.textContent = button.dataset.corporation || "-";
        if (modalDrainUpdatedBy) modalDrainUpdatedBy.textContent = button.dataset.updatedBy || "-";
        if (modalDrainUpdatedRole) modalDrainUpdatedRole.textContent = button.dataset.updatedRole || "-";
        if (modalDrainUpdatedAt) modalDrainUpdatedAt.textContent = button.dataset.updatedAt || "-";

        modal.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) return;

        modal.classList.remove("show");
        document.body.style.overflow = "";
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

    if (riskFilter) {
        riskFilter.addEventListener("change", applyFilters);
    }

    if (clearBtn) {
        clearBtn.addEventListener("click", clearFilters);
    }

    document.querySelectorAll(".dr-view-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button);
        });
    });

    if (closeModalBtn) {
        closeModalBtn.addEventListener("click", closeModal);
    }

    if (modal) {
        modal.addEventListener("click", function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal();
        }
    });

    applyFilters();
});