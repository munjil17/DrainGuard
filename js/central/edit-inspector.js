document.addEventListener("DOMContentLoaded", function () {
    const cityCorSelect = document.getElementById("city_cor_id");
    const wardSelect = document.getElementById("assigned_ward_id");

    const wards = Array.isArray(window.DG_INSPECTOR_WARDS)
        ? window.DG_INSPECTOR_WARDS
        : [];

    function clearWard(message) {
        wardSelect.innerHTML = "";

        const option = document.createElement("option");
        option.value = "";
        option.textContent = message;

        wardSelect.appendChild(option);
    }

    function loadWards() {
        if (!cityCorSelect || !wardSelect) return;

        const cityCorId = String(cityCorSelect.value || "");
        const selectedWard = String(wardSelect.dataset.selectedWard || "");

        clearWard("Select ward");

        if (cityCorId === "") {
            clearWard("Select city corporation first");
            return;
        }

        const filtered = wards.filter(function (ward) {
            return String(ward.city_cor_id) === cityCorId;
        });

        filtered.forEach(function (ward) {
            const option = document.createElement("option");
            option.value = ward.ward_id;
            option.textContent = ward.ward_name || ("Ward " + ward.ward_no);

            if (String(ward.ward_id) === selectedWard) {
                option.selected = true;
            }

            wardSelect.appendChild(option);
        });
    }

    if (cityCorSelect) {
        cityCorSelect.addEventListener("change", function () {
            if (wardSelect) {
                wardSelect.dataset.selectedWard = "";
            }

            loadWards();
        });
    }

    loadWards();
});