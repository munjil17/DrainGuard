document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("maintenanceTeamForm");

    const cityCorSelect = document.getElementById("city_cor_id");
    const anchalSelect = document.getElementById("anchal_id");

    const alerts = document.querySelectorAll(".amt-alert");

    const anchals = Array.isArray(window.DG_MAINTENANCE_ANCHALS)
        ? window.DG_MAINTENANCE_ANCHALS
        : [];

    alerts.forEach(function (alertBox) {
        setTimeout(function () {
            alertBox.style.opacity = "0";
            alertBox.style.transform = "translateY(-8px)";

            setTimeout(function () {
                alertBox.style.display = "none";
            }, 250);
        }, 3000);
    });

    function clearAnchalSelect(message) {
        if (!anchalSelect) {
            return;
        }

        anchalSelect.innerHTML = "";

        const option = document.createElement("option");
        option.value = "";
        option.textContent = message;

        anchalSelect.appendChild(option);
    }

    function loadAnchalsByCityCorporation() {
        if (!cityCorSelect || !anchalSelect) {
            return;
        }

        const selectedCityCorId = String(cityCorSelect.value || "").trim();

        clearAnchalSelect("Select anchal");

        if (selectedCityCorId === "") {
            clearAnchalSelect("Select city corporation first");
            anchalSelect.disabled = true;
            return;
        }

        const filteredAnchals = anchals.filter(function (anchal) {
            return String(anchal.city_cor_id) === selectedCityCorId;
        });

        if (filteredAnchals.length === 0) {
            clearAnchalSelect("No anchal found for this city corporation");
            anchalSelect.disabled = true;
            return;
        }

        anchalSelect.disabled = false;

        filteredAnchals.forEach(function (anchal) {
            const option = document.createElement("option");

            option.value = anchal.anchal_id;
            option.textContent = anchal.anchal_name || "Anchal " + anchal.anchal_id;

            anchalSelect.appendChild(option);
        });
    }

    if (cityCorSelect && anchalSelect) {
        cityCorSelect.addEventListener("change", loadAnchalsByCityCorporation);
        loadAnchalsByCityCorporation();
    }

    if (form) {
        form.addEventListener("submit", function (event) {
            const teamName = document.getElementById("team_name");

            if (teamName && teamName.value.trim().length < 3) {
                event.preventDefault();
                showWarningModal("Team name must be at least 3 characters.");
                teamName.focus();
                return;
            }

            if (cityCorSelect && cityCorSelect.value.trim() === "") {
                event.preventDefault();
                showWarningModal("Please select city corporation.");
                cityCorSelect.focus();
                return;
            }

            if (anchalSelect && anchalSelect.value.trim() === "") {
                event.preventDefault();
                showWarningModal("Please select assigned anchal.");
                anchalSelect.focus();
            }
        });
    }
});