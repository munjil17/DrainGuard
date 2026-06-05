document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("addTeamMemberForm");

    const teamSelect = document.getElementById("maintenance_team_id");
    const memberRole = document.getElementById("member_role");
    const loginAccess = document.getElementById("login_access");

    const memberInfoCard = document.getElementById("memberInfoCard");
    const loginAccessCard = document.getElementById("loginAccessCard");

    const memberTitle = document.getElementById("memberTitle");
    const memberSubtitle = document.getElementById("memberSubtitle");
    const roleNote = document.getElementById("roleNote");

    const fullName = document.getElementById("full_name");
    const phoneNumber = document.getElementById("phone_number");
    const gmail = document.getElementById("gmail");
    const gender = document.getElementById("gender");
    const address = document.getElementById("address");
    const memberStatus = document.getElementById("member_status");

    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirm_password");

    const toggles = document.querySelectorAll(".atm-password-toggle");
    const alerts = document.querySelectorAll(".atm-alert");

    const teamPreview = document.getElementById("teamPreview");
    const previewTeam = document.getElementById("previewTeam");
    const previewCityCorporation = document.getElementById("previewCityCorporation");
    const previewAnchal = document.getElementById("previewAnchal");
    const previewAvailability = document.getElementById("previewAvailability");

    const memberRequiredFields = [
        fullName,
        phoneNumber,
        gmail,
        gender,
        address,
        memberStatus
    ];

    alerts.forEach(function (alertBox) {
        setTimeout(function () {
            alertBox.style.opacity = "0";
            alertBox.style.transform = "translateY(-8px)";

            setTimeout(function () {
                alertBox.style.display = "none";
            }, 250);
        }, 5000);
    });

    function setRequired(fields, required) {
        fields.forEach(function (field) {
            if (!field) return;

            if (required) {
                field.setAttribute("required", "required");
            } else {
                field.removeAttribute("required");
            }
        });
    }

    function selectedTeamOption() {
        if (!teamSelect) return null;
        return teamSelect.options[teamSelect.selectedIndex] || null;
    }

    function hasSelectedBaseInfo() {
        return (
            teamSelect &&
            memberRole &&
            loginAccess &&
            teamSelect.value !== "" &&
            memberRole.value !== "" &&
            loginAccess.value !== ""
        );
    }

    function shouldShowLogin() {
        if (!memberRole || !loginAccess) return false;

        if (memberRole.value === "team_leader") {
            return true;
        }

        if (memberRole.value === "assistant_team_leader" && loginAccess.value === "yes") {
            return true;
        }

        return false;
    }

    function formatText(value) {
        return String(value || "N/A")
            .replaceAll("_", " ")
            .replace(/\b\w/g, function (char) {
                return char.toUpperCase();
            });
    }

    function updateTeamPreview() {
        const option = selectedTeamOption();

        if (!teamPreview || !option || !teamSelect || teamSelect.value === "") {
            if (teamPreview) {
                teamPreview.classList.remove("show");
            }

            return;
        }

        teamPreview.classList.add("show");

        if (previewTeam) {
            previewTeam.textContent = option.dataset.teamName || "N/A";
        }

        if (previewCityCorporation) {
            previewCityCorporation.textContent = option.dataset.cityCorporation || "N/A";
        }

        if (previewAnchal) {
            previewAnchal.textContent = option.dataset.anchal || "N/A";
        }

        if (previewAvailability) {
            previewAvailability.textContent = formatText(option.dataset.availability);
        }
    }

    function normalizeLoginAccessByRole() {
        if (!memberRole || !loginAccess) return;

        if (memberRole.value === "team_leader") {
            loginAccess.value = "yes";
        }

        if (memberRole.value === "worker") {
            loginAccess.value = "no";
        }
    }

    function updateMemberUI() {
        updateTeamPreview();
        normalizeLoginAccessByRole();

        if (!memberRole || !memberInfoCard || !loginAccessCard) return;

        const role = memberRole.value;
        const access = loginAccess ? loginAccess.value : "";

        if (!hasSelectedBaseInfo()) {
            memberInfoCard.classList.remove("show");
            loginAccessCard.classList.remove("show");

            setRequired(memberRequiredFields, false);
            setRequired([password, confirmPassword], false);

            if (roleNote) {
                roleNote.className = "atm-note";
                roleNote.innerHTML = `
                    <i class="bi bi-info-circle"></i>
                    <span>Select team, role, and login access to continue.</span>
                `;
            }

            return;
        }

        memberInfoCard.classList.add("show");
        setRequired(memberRequiredFields, true);

        if (role === "team_leader") {
            if (memberTitle) memberTitle.textContent = "Team Leader Information";
            if (memberSubtitle) memberSubtitle.textContent = "Team leader always gets login access.";

            if (roleNote) {
                roleNote.className = "atm-note leader";
                roleNote.innerHTML = `
                    <i class="bi bi-check-circle"></i>
                    <span>Team Leader will be inserted into users table as team_leader and can login.</span>
                `;
            }
        }

        if (role === "assistant_team_leader") {
            if (memberTitle) memberTitle.textContent = "Assistant Team Leader Information";
            if (memberSubtitle) memberSubtitle.textContent = "Assistant login depends on selected Login Access.";

            if (roleNote) {
                roleNote.className = "atm-note assistant";

                if (access === "yes") {
                    roleNote.innerHTML = `
                        <i class="bi bi-info-circle"></i>
                        <span>Assistant Team Leader will be inserted into users table and can login.</span>
                    `;
                } else {
                    roleNote.innerHTML = `
                        <i class="bi bi-info-circle"></i>
                        <span>Assistant Team Leader will be inserted into users table, but login_access will be 0.</span>
                    `;
                }
            }
        }

        if (role === "worker") {
            if (memberTitle) memberTitle.textContent = "Worker Information";
            if (memberSubtitle) memberSubtitle.textContent = "Worker does not get login access.";

            if (roleNote) {
                roleNote.className = "atm-note worker";
                roleNote.innerHTML = `
                    <i class="bi bi-info-circle"></i>
                    <span>Worker will be inserted only into maintenance_team_members table. No users table insert.</span>
                `;
            }
        }

        if (shouldShowLogin()) {
            loginAccessCard.classList.add("show");
            setRequired([password, confirmPassword], true);
        } else {
            loginAccessCard.classList.remove("show");
            setRequired([password, confirmPassword], false);

            if (password) password.value = "";
            if (confirmPassword) confirmPassword.value = "";
        }
    }

    if (teamSelect) {
        teamSelect.addEventListener("change", updateMemberUI);
    }

    if (memberRole) {
        memberRole.addEventListener("change", updateMemberUI);
    }

    if (loginAccess) {
        loginAccess.addEventListener("change", updateMemberUI);
    }

    toggles.forEach(function (button) {
        button.addEventListener("click", function () {
            const targetId = button.getAttribute("data-target");
            const input = document.getElementById(targetId);
            const icon = button.querySelector("i");

            if (!input || !icon) return;

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
            normalizeLoginAccessByRole();

            if (!teamSelect || teamSelect.value === "") {
                event.preventDefault();
                showWarningModal("Please select a maintenance team.");
                teamSelect.focus();
                return;
            }

            if (!memberRole || memberRole.value === "") {
                event.preventDefault();
                showWarningModal("Please select a team member role.");
                memberRole.focus();
                return;
            }

            if (!loginAccess || loginAccess.value === "") {
                event.preventDefault();
                showWarningModal("Please select login access.");
                loginAccess.focus();
                return;
            }

            if (shouldShowLogin()) {
                if (!password || !confirmPassword) return;

                if (password.value.trim().length < 6) {
                    event.preventDefault();
                    showWarningModal("Password must be at least 6 characters.");
                    password.focus();
                    return;
                }

                if (password.value !== confirmPassword.value) {
                    event.preventDefault();
                    showWarningModal("Password and confirm password do not match.");
                    confirmPassword.focus();
                }
            }
        });
    }

    updateMemberUI();
});