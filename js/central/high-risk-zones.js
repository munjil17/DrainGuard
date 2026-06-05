document.addEventListener("DOMContentLoaded", function () {
    const filterData = window.highRiskFilterData || {};

    const cityCorporations = Array.isArray(filterData.cityCorporations) ? filterData.cityCorporations : [];
    const thanas = Array.isArray(filterData.thanas) ? filterData.thanas : [];
    const wards = Array.isArray(filterData.wards) ? filterData.wards : [];
    const areas = Array.isArray(filterData.areas) ? filterData.areas : [];

    const cityFilter = document.getElementById("cityFilter");
    const cityCorporationFilter = document.getElementById("cityCorporationFilter");
    const thanaFilter = document.getElementById("thanaFilter");
    const wardFilter = document.getElementById("wardFilter");
    const areaFilter = document.getElementById("areaFilter");
    const riskFilter = document.getElementById("riskFilter");
    const clearRiskFilters = document.getElementById("clearRiskFilters");

    const cards = Array.from(document.querySelectorAll(".hrz-zone-card"));
    const noResult = document.getElementById("hrzNoResult");

    const modal = document.getElementById("riskDetailsModal");
    const closeModalBtn = document.getElementById("closeRiskModal");

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

    function filterCards() {
        const cityValue = cityFilter?.value || "";
        const cityCorValue = cityCorporationFilter?.value || "";
        const thanaValue = thanaFilter?.value || "";
        const wardValue = wardFilter?.value || "";
        const areaValue = areaFilter?.value || "";
        const riskValue = riskFilter?.value || "";

        let visibleCount = 0;

        cards.forEach(function (card) {
            const cityMatch = !cityValue || card.dataset.cityId === cityValue;
            const cityCorMatch = !cityCorValue || card.dataset.cityCorId === cityCorValue;
            const thanaMatch = !thanaValue || card.dataset.thanaId === thanaValue;
            const wardMatch = !wardValue || card.dataset.wardId === wardValue;
            const areaMatch = !areaValue || card.dataset.areaId === areaValue;
            const riskMatch = !riskValue || card.dataset.risk === riskValue;

            const shouldShow =
                cityMatch &&
                cityCorMatch &&
                thanaMatch &&
                wardMatch &&
                areaMatch &&
                riskMatch;

            card.style.display = shouldShow ? "block" : "none";

            if (shouldShow) {
                visibleCount++;
            }
        });

        if (noResult) {
            noResult.hidden = visibleCount !== 0;
        }
    }

    function clearFilters() {
        if (cityFilter) cityFilter.value = "";
        if (riskFilter) riskFilter.value = "";

        resetSelect(cityCorporationFilter, "All City Corporation");
        resetSelect(thanaFilter, "All Thana");
        resetSelect(wardFilter, "All Ward");
        resetSelect(areaFilter, "All Area");

        filterCards();
    }

    function setText(id, value) {
        const element = document.getElementById(id);

        if (!element) {
            return;
        }

        const safeValue = String(value || "").trim();
        element.textContent = safeValue !== "" ? safeValue : "N/A";
    }

    function openDetailsModal(button) {
        if (!modal || !button) {
            return;
        }

        setText("modalAreaName", button.dataset.area);
        setText("modalRiskLevel", `${button.dataset.risk || "N/A"} Risk`);
        setText("modalCity", button.dataset.city);
        setText("modalCorporation", button.dataset.corporation);
        setText("modalThana", button.dataset.thana);
        setText("modalWard", button.dataset.ward);
        setText("modalCount30", button.dataset.count30);
        setText("modalCount7", button.dataset.count7);
        setText("modalCountWeek", `+${button.dataset.countweek || 0} this week`);
        setText("modalFirstReported", button.dataset.firstreported);
        setText("modalLastReported", button.dataset.lastreported);
        setText("modalComplaint", button.dataset.complaint);
        setText("modalIssue", button.dataset.issue);
        setText("modalCitizen", button.dataset.citizen);
        setText("modalAddress", button.dataset.address);
        setText("modalProblem", button.dataset.problem);

        modal.classList.add("active");
        document.body.style.overflow = "hidden";
    }

    function closeDetailsModal() {
        if (!modal) {
            return;
        }

        modal.classList.remove("active");
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
                filterCards();
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
            filterCards();
        });
    }

    if (cityCorporationFilter) {
        cityCorporationFilter.addEventListener("change", function () {
            const cityCorId = cityCorporationFilter.value;

            resetSelect(thanaFilter, "All Thana");
            resetSelect(wardFilter, "All Ward");
            resetSelect(areaFilter, "All Area");

            if (!cityCorId) {
                filterCards();
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
            filterCards();
        });
    }

    if (thanaFilter) {
        thanaFilter.addEventListener("change", function () {
            const thanaId = thanaFilter.value;

            resetSelect(wardFilter, "All Ward");
            resetSelect(areaFilter, "All Area");

            if (!thanaId) {
                filterCards();
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
            filterCards();
        });
    }

    if (wardFilter) {
        wardFilter.addEventListener("change", function () {
            const wardId = wardFilter.value;

            resetSelect(areaFilter, "All Area");

            if (!wardId) {
                filterCards();
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
            filterCards();
        });
    }

    if (areaFilter) {
        areaFilter.addEventListener("change", filterCards);
    }

    if (riskFilter) {
        riskFilter.addEventListener("change", filterCards);
    }

    if (clearRiskFilters) {
        clearRiskFilters.addEventListener("click", clearFilters);
    }

    document.querySelectorAll(".hrz-details-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            openDetailsModal(button);
        });
    });

    document.querySelectorAll(".hrz-alert-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            const area = button.dataset.area || "this area";
            const ward = button.dataset.ward || "selected ward";

            showWarningModal(`Alert feature will be connected later.\nArea: ${area}\nWard: ${ward}`);
        });
    });

    if (closeModalBtn) {
        closeModalBtn.addEventListener("click", closeDetailsModal);
    }

    if (modal) {
        modal.addEventListener("click", function (event) {
            if (event.target === modal) {
                closeDetailsModal();
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal && modal.classList.contains("active")) {
            closeDetailsModal();
        }
    });

    filterCards();
});