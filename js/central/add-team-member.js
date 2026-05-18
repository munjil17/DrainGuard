document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("addTeamMemberForm");

    const teamSelect = document.getElementById("maintenance_team_id");
    const memberRole = document.getElementById("member_role");
    const assistantLoginAccess = document.getElementById("assistant_login_access");

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
    const previewThana = document.getElementById("previewThana");
    const previewSkill = document.getElementById("previewSkill");
    const previewAvailability = document.getElementById("previewAvailability");
    const previewAssistantAccess = document.getElementById("previewAssistantAccess");

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
        }, 8000);
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

    function hasSelectedTeamRoleAccess() {
        return (
            teamSelect &&
            memberRole &&
            assistantLoginAccess &&
            teamSelect.value !== "" &&
            memberRole.value !== "" &&
            assistantLoginAccess.value !== ""
        );
    }

    function shouldShowLogin() {
        if (!memberRole || !assistantLoginAccess) return false;

        if (memberRole.value === "team_leader") {
            return true;
        }

        if (memberRole.value === "assistant_team_leader" && assistantLoginAccess.value === "yes") {
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

        if (!teamPreview || !option || teamSelect.value === "") {
            if (teamPreview) {
                teamPreview.classList.remove("show");
            }

            return;
        }

        teamPreview.classList.add("show");

        if (previewTeam) previewTeam.textContent = option.dataset.teamName || "N/A";
        if (previewThana) previewThana.textContent = option.dataset.thana || "N/A";
        if (previewSkill) previewSkill.textContent = formatText(option.dataset.skill);
        if (previewAvailability) previewAvailability.textContent = formatText(option.dataset.availability);
        if (previewAssistantAccess) previewAssistantAccess.textContent = formatText(option.dataset.assistantAccess);
    }

    function updateMemberUI() {
        updateTeamPreview();

        if (!memberRole || !memberInfoCard || !loginAccessCard) return;

        const role = memberRole.value;
        const access = assistantLoginAccess ? assistantLoginAccess.value : "";

        if (!hasSelectedTeamRoleAccess()) {
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
            memberTitle.textContent = "Team Leader Information";
            memberSubtitle.textContent = "Team leader always gets login access.";
            roleNote.className = "atm-note leader";
            roleNote.innerHTML = `
                <i class="bi bi-check-circle"></i>
                <span>Team Leader will be inserted into users table as maintenance_team and can login.</span>
            `;
        }

        if (role === "assistant_team_leader") {
            memberTitle.textContent = "Assistant Team Leader Information";
            memberSubtitle.textContent = "Assistant login depends on selected Login Access.";
            roleNote.className = "atm-note assistant";

            if (access === "yes") {
                roleNote.innerHTML = `
                    <i class="bi bi-info-circle"></i>
                    <span>Assistant will get login access because Login Access is Yes.</span>
                `;
            } else {
                roleNote.innerHTML = `
                    <i class="bi bi-info-circle"></i>
                    <span>Assistant will be inserted into users table, but login_access will be 0.</span>
                `;
            }
        }

        if (role === "worker") {
            memberTitle.textContent = "Worker Information";
            memberSubtitle.textContent = "Worker does not get login access.";
            roleNote.className = "atm-note worker";
            roleNote.innerHTML = `
                <i class="bi bi-info-circle"></i>
                <span>Worker will be inserted only into maintenance_team_members table.</span>
            `;
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

    if (assistantLoginAccess) {
        assistantLoginAccess.addEventListener("change", updateMemberUI);
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
            if (!teamSelect || teamSelect.value === "") {
                event.preventDefault();
                alert("Please select a maintenance team.");
                teamSelect.focus();
                return;
            }

            if (!memberRole || memberRole.value === "") {
                event.preventDefault();
                alert("Please select a team member role.");
                memberRole.focus();
                return;
            }

            if (!assistantLoginAccess || assistantLoginAccess.value === "") {
                event.preventDefault();
                alert("Please select login access.");
                assistantLoginAccess.focus();
                return;
            }

            if (shouldShowLogin()) {
                if (!password || !confirmPassword) return;

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

    updateMemberUI();
});