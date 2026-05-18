document.addEventListener("DOMContentLoaded", function () {
    const citySelect = document.getElementById("citySelect");
    const corporationSelect = document.getElementById("cityCorporationSelect");
    const thanaSelect = document.getElementById("thanaSelect");
    const wardSelect = document.getElementById("wardSelect");
    const areaSelect = document.getElementById("areaSelect");
    const locationIdInput = document.getElementById("locationId");

    const uploadInput = document.getElementById("complaintImage");
    const uploadText = document.getElementById("uploadText");

    const rows = Array.isArray(locationRows) ? locationRows : [];

    function resetSelect(select, placeholder) {
        if (!select) return;

        select.innerHTML = `<option value="">${placeholder}</option>`;
        select.disabled = true;
    }

    function enableSelect(select) {
        if (!select) return;

        select.disabled = false;
    }

    function uniqueById(items, idKey) {
        const map = new Map();

        items.forEach(function (item) {
            if (!map.has(item[idKey])) {
                map.set(item[idKey], item);
            }
        });

        return Array.from(map.values());
    }

    function addOption(select, value, text) {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = text;
        select.appendChild(option);
    }

    function resetAfterCity() {
        resetSelect(corporationSelect, "Select city corporation");
        resetSelect(thanaSelect, "Select thana");
        resetSelect(wardSelect, "Select ward");
        resetSelect(areaSelect, "Select area");
        locationIdInput.value = "";
    }

    function resetAfterCorporation() {
        resetSelect(thanaSelect, "Select thana");
        resetSelect(wardSelect, "Select ward");
        resetSelect(areaSelect, "Select area");
        locationIdInput.value = "";
    }

    function resetAfterThana() {
        resetSelect(wardSelect, "Select ward");
        resetSelect(areaSelect, "Select area");
        locationIdInput.value = "";
    }

    function resetAfterWard() {
        resetSelect(areaSelect, "Select area");
        locationIdInput.value = "";
    }

    if (citySelect && corporationSelect && thanaSelect && wardSelect && areaSelect && locationIdInput) {

        const cities = uniqueById(rows, "city_id");

        cities.forEach(function (city) {
            addOption(citySelect, city.city_id, city.city_name);
        });

        citySelect.addEventListener("change", function () {
            const cityId = this.value;

            resetAfterCity();

            if (!cityId) return;

            const filteredRows = rows.filter(function (row) {
                return String(row.city_id) === String(cityId);
            });

            const corporations = uniqueById(filteredRows, "city_cor_id");

            corporations.forEach(function (corp) {
                addOption(corporationSelect, corp.city_cor_id, corp.city_cor_name);
            });

            enableSelect(corporationSelect);
        });

        corporationSelect.addEventListener("change", function () {
            const cityId = citySelect.value;
            const cityCorId = this.value;

            resetAfterCorporation();

            if (!cityId || !cityCorId) return;

            const filteredRows = rows.filter(function (row) {
                return String(row.city_id) === String(cityId)
                    && String(row.city_cor_id) === String(cityCorId);
            });

            const thanas = uniqueById(filteredRows, "thana_id");

            thanas.forEach(function (thana) {
                addOption(thanaSelect, thana.thana_id, thana.thana_name);
            });

            enableSelect(thanaSelect);
        });

        thanaSelect.addEventListener("change", function () {
            const cityId = citySelect.value;
            const cityCorId = corporationSelect.value;
            const thanaId = this.value;

            resetAfterThana();

            if (!cityId || !cityCorId || !thanaId) return;

            const filteredRows = rows.filter(function (row) {
                return String(row.city_id) === String(cityId)
                    && String(row.city_cor_id) === String(cityCorId)
                    && String(row.thana_id) === String(thanaId);
            });

            const wards = uniqueById(filteredRows, "ward_id");

            wards.forEach(function (ward) {
                addOption(
                    wardSelect,
                    ward.ward_id,
                    "Ward " + ward.ward_no + " - " + ward.ward_name
                );
            });

            enableSelect(wardSelect);
        });

        wardSelect.addEventListener("change", function () {
            const cityId = citySelect.value;
            const cityCorId = corporationSelect.value;
            const thanaId = thanaSelect.value;
            const wardId = this.value;

            resetAfterWard();

            if (!cityId || !cityCorId || !thanaId || !wardId) return;

            const filteredRows = rows.filter(function (row) {
                return String(row.city_id) === String(cityId)
                    && String(row.city_cor_id) === String(cityCorId)
                    && String(row.thana_id) === String(thanaId)
                    && String(row.ward_id) === String(wardId);
            });

            const areas = uniqueById(filteredRows, "area_id");

            areas.forEach(function (area) {
                const option = document.createElement("option");
                option.value = area.area_id;
                option.textContent = area.area_name;
                option.dataset.locId = area.loc_id;
                areaSelect.appendChild(option);
            });

            enableSelect(areaSelect);
        });

        areaSelect.addEventListener("change", function () {
            const selectedOption = this.options[this.selectedIndex];

            if (selectedOption && selectedOption.dataset.locId) {
                locationIdInput.value = selectedOption.dataset.locId;
            } else {
                locationIdInput.value = "";
            }
        });
    }

    if (uploadInput && uploadText) {
        uploadInput.addEventListener("change", function () {
            if (this.files.length > 0) {
                uploadText.textContent = this.files[0].name;
            } else {
                uploadText.textContent = "Click to upload image";
            }
        });
    }
});