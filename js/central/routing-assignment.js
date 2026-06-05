document.addEventListener("DOMContentLoaded", function () {
    const locationData = window.routingLocationData || {};

    const cityCorporations = Array.isArray(locationData.cityCorporations) ? locationData.cityCorporations : [];
    const thanas = Array.isArray(locationData.thanas) ? locationData.thanas : [];
    const wards = Array.isArray(locationData.wards) ? locationData.wards : [];
    const areas = Array.isArray(locationData.areas) ? locationData.areas : [];

    const searchInput = document.getElementById("routeSearch");
    const priorityFilter = document.getElementById("priorityFilter");

    const cityFilter = document.getElementById("cityFilter");
    const cityCorporationFilter = document.getElementById("cityCorporationFilter");
    const thanaFilter = document.getElementById("thanaFilter");
    const wardFilter = document.getElementById("wardFilter");
    const areaFilter = document.getElementById("areaFilter");
    const clearLocationFilters = document.getElementById("clearLocationFilters");

    const cards = document.querySelectorAll(".ra-card");
    const bulkRouteBtn = document.getElementById("bulkRouteBtn");

    const modal = document.getElementById("raDetailsModal");
    const modalCloseBtn = document.getElementById("raModalClose");

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
        const searchValue = (searchInput?.value || "").toLowerCase().trim();
        const priorityValue = priorityFilter?.value || "all";

        const cityValue = cityFilter?.value || "";
        const cityCorValue = cityCorporationFilter?.value || "";
        const thanaValue = thanaFilter?.value || "";
        const wardValue = wardFilter?.value || "";
        const areaValue = areaFilter?.value || "";

        cards.forEach(function (card) {
            const searchableText = [
                card.dataset.code || "",
                card.dataset.issue || "",
                card.dataset.title || "",
                card.dataset.ward || "",
                card.dataset.area || ""
            ].join(" ").toLowerCase();

            const matchesSearch = searchableText.includes(searchValue);
            const matchesPriority = priorityValue === "all" || card.dataset.priority === priorityValue;

            const matchesCity = !cityValue || card.dataset.cityId === cityValue;
            const matchesCityCor = !cityCorValue || card.dataset.cityCorId === cityCorValue;
            const matchesThana = !thanaValue || card.dataset.thanaId === thanaValue;
            const matchesWard = !wardValue || card.dataset.wardId === wardValue;
            const matchesArea = !areaValue || card.dataset.areaId === areaValue;

            const shouldShow =
                matchesSearch &&
                matchesPriority &&
                matchesCity &&
                matchesCityCor &&
                matchesThana &&
                matchesWard &&
                matchesArea;

            card.style.display = shouldShow ? "block" : "none";
        });
    }

    function clearAllLocationFilters() {
        if (cityFilter) cityFilter.value = "";

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

        setText("raModalTitle", button.dataset.title);
        setText("raModalCode", button.dataset.code);
        setText("raModalIssue", button.dataset.issue);
        setText("raModalAffectedArea", button.dataset.affectedArea);
        setText("raModalPriority", button.dataset.priority);
        setText("raModalCitizen", button.dataset.citizen);
        setText("raModalEmail", button.dataset.email);
        setText("raModalCity", button.dataset.city);
        setText("raModalCorporation", button.dataset.corporation);
        setText("raModalThana", button.dataset.thana);
        setText("raModalWard", button.dataset.ward);
        setText("raModalArea", button.dataset.area);
        setText("raModalDrain", button.dataset.drain);
        setText("raModalDate", button.dataset.date);
        setText("raModalAddress", button.dataset.address);
        setText("raModalProblem", button.dataset.title);

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

    if (searchInput) {
        searchInput.addEventListener("input", filterCards);
    }

    if (priorityFilter) {
        priorityFilter.addEventListener("change", filterCards);
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

    if (clearLocationFilters) {
        clearLocationFilters.addEventListener("click", clearAllLocationFilters);
    }

    if (bulkRouteBtn) {
        bulkRouteBtn.addEventListener("click", function () {
            showWarningModal("Bulk route will be added later. For now, send complaints one by one.");
        });
    }

    document.querySelectorAll(".ra-route-btn").forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.preventDefault();
            const form = this.closest("form");
            showConfirmModal({
                title: "Confirm Routing",
                message: "Send this complaint to ward for verification? Tracking status will become Pending Verification.",
                confirmText: "Route",
                cancelText: "Cancel",
                type: "confirm",
                onConfirm: function() {
                    if (form) form.submit();
                }
            });
        });
    });

    document.querySelectorAll(".ra-details-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            openDetailsModal(button);
        });
    });

    if (modalCloseBtn) {
        modalCloseBtn.addEventListener("click", closeDetailsModal);
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