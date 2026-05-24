document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("wardOfficerForm");

    const cityCorSelect = document.getElementById("city_cor_id");
    const wardSelect = document.getElementById("assigned_ward_id");

    const loginAccess = document.getElementById("login_access");
    const loginAccessCard = document.getElementById("loginAccessCard");

    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirm_password");

    const toggles = document.querySelectorAll(".awo-password-toggle");
    const alerts = document.querySelectorAll(".awo-alert");

    const wards = Array.isArray(window.DG_WARD_OFFICER_WARDS)
        ? window.DG_WARD_OFFICER_WARDS
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

    function clearWardSelect(message) {
        if (!wardSelect) {
            return;
        }

        wardSelect.innerHTML = "";

        const option = document.createElement("option");
        option.value = "";
        option.textContent = message;

        wardSelect.appendChild(option);
    }

    function loadWardsByCityCorporation() {
        if (!cityCorSelect || !wardSelect) {
            return;
        }

        const selectedCityCorId = String(cityCorSelect.value || "").trim();

        clearWardSelect("Select ward");

        if (selectedCityCorId === "") {
            clearWardSelect("Select city corporation first");
            wardSelect.disabled = true;
            return;
        }

        const filteredWards = wards.filter(function (ward) {
            return String(ward.city_cor_id) === selectedCityCorId;
        });

        if (filteredWards.length === 0) {
            clearWardSelect("No ward found for this city corporation");
            wardSelect.disabled = true;
            return;
        }

        wardSelect.disabled = false;

        filteredWards.forEach(function (ward) {
            const option = document.createElement("option");

            option.value = ward.ward_id;

            if (ward.ward_name && String(ward.ward_name).trim() !== "") {
                option.textContent = ward.ward_name;
            } else {
                option.textContent = "Ward " + ward.ward_no;
            }

            wardSelect.appendChild(option);
        });
    }

    if (cityCorSelect && wardSelect) {
        cityCorSelect.addEventListener("change", loadWardsByCityCorporation);
        loadWardsByCityCorporation();
    }

    function toggleLoginAccess() {
        if (!loginAccess || !loginAccessCard || !password || !confirmPassword) {
            return;
        }

        if (loginAccess.value === "yes") {
            loginAccessCard.classList.add("show");

            password.setAttribute("required", "required");
            confirmPassword.setAttribute("required", "required");
        } else {
            loginAccessCard.classList.remove("show");

            password.removeAttribute("required");
            confirmPassword.removeAttribute("required");

            password.value = "";
            confirmPassword.value = "";
        }
    }

    if (loginAccess) {
        loginAccess.addEventListener("change", toggleLoginAccess);
        toggleLoginAccess();
    }

    toggles.forEach(function (button) {
        button.addEventListener("click", function () {
            const targetId = button.getAttribute("data-target");
            const input = document.getElementById(targetId);
            const icon = button.querySelector("i");

            if (!input || !icon) {
                return;
            }

            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("bi-eye");
                icon.classList.add("bi-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("bi-eye-slash");
                icon.classList.add("bi-eye");
            }
        });
    });

    if (form) {
        form.addEventListener("submit", function (event) {
            if (cityCorSelect && cityCorSelect.value.trim() === "") {
                event.preventDefault();
                alert("Please select city corporation.");
                cityCorSelect.focus();
                return;
            }

            if (wardSelect && wardSelect.value.trim() === "") {
                event.preventDefault();
                alert("Please select assigned ward.");
                wardSelect.focus();
                return;
            }

            if (!loginAccess) {
                return;
            }

            if (loginAccess.value === "yes") {
                if (!password || !confirmPassword) {
                    return;
                }

                if (password.value.trim().length < 6) {
                    event.preventDefault();
                    alert("Password must be at least 6 characters.");
                    password.focus();
                    return;
                }

                if (password.value !== confirmPassword.value) {
                    event.preventDefault();
                    alert("Password and confirm password do not match.");
                    confirmPassword.focus();
                }
            }
        });
    }
});