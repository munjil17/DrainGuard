document.addEventListener("DOMContentLoaded", function () {
    const loginPage = document.querySelector(".login-page");
    const roleCards = document.querySelectorAll(".role-card");

    const selectedRoleInput = document.getElementById("selectedRole");
    const selectedRoleText = document.getElementById("selectedRoleText");
    const loginTitle = document.getElementById("loginTitle");
    const loginSubtitle = document.getElementById("loginSubtitle");
    const submitBtnText = document.getElementById("submitBtnText");

    const citizenSignupRedirect = document.getElementById("citizenSignupRedirect");

    const passwordToggle = document.getElementById("passwordToggle");
    const passwordInput = document.getElementById("passwordInput");

    const rememberMe = document.getElementById("rememberMe");
    const emailInput = document.getElementById("loginEmailInput");
    const loginForm = document.getElementById("loginForm");

    const roleConfig = {
        citizen: {
            text: "Citizen",
            title: "Citizen Login",
            subtitle: "Access your complaint portal",
            button: "Sign In as Citizen"
        },

        central: {
            text: "Central Control",
            title: "Central Control Login",
            subtitle: "Access city oversight panel",
            button: "Sign In as Central Control"
        },

        ward: {
            text: "Ward Officer",
            title: "Ward Officer Login",
            subtitle: "Access ward operations panel",
            button: "Sign In as Ward Officer"
        },

        maintenance: {
            text: "Maintenance",
            title: "Maintenance Login",
            subtitle: "Access assigned task panel",
            button: "Sign In as Maintenance"
        },

        inspector: {
            text: "Inspector",
            title: "Inspector Login",
            subtitle: "Access inspection and QA panel",
            button: "Sign In as Inspector"
        }
    };

    function setRole(role) {
        const config = roleConfig[role] || roleConfig.citizen;

        if (loginPage) {
            loginPage.setAttribute("data-active-role", role);
        }

        if (selectedRoleInput) {
            selectedRoleInput.value = role;
        }

        if (selectedRoleText) {
            selectedRoleText.textContent = config.text;
        }

        if (loginTitle) {
            loginTitle.textContent = config.title;
        }

        if (loginSubtitle) {
            loginSubtitle.textContent = config.subtitle;
        }

        if (submitBtnText) {
            submitBtnText.textContent = config.button;
        }

        roleCards.forEach(function (card) {
            if (card.dataset.role === role) {
                card.classList.add("active");
            } else {
                card.classList.remove("active");
            }
        });

        if (citizenSignupRedirect) {
            citizenSignupRedirect.style.display = role === "citizen" ? "flex" : "none";
        }
    }

    const initialRole = loginPage ? (loginPage.dataset.activeRole || "citizen") : "citizen";
    setRole(initialRole);

    roleCards.forEach(function (card) {
        card.addEventListener("click", function () {
            const role = card.dataset.role || "citizen";
            setRole(role);
        });
    });

    if (passwordToggle && passwordInput) {
        passwordToggle.addEventListener("click", function () {
            const icon = passwordToggle.querySelector("i");

            if (!icon) return;

            if (passwordInput.type === "password") {
                passwordInput.type = "text";
                icon.classList.remove("bi-eye");
                icon.classList.add("bi-eye-slash");
            } else {
                passwordInput.type = "password";
                icon.classList.remove("bi-eye-slash");
                icon.classList.add("bi-eye");
            }
        });
    }

    if (rememberMe && emailInput) {
        const savedEmail = localStorage.getItem("drainguard_saved_email");

        if (savedEmail) {
            emailInput.value = savedEmail;
            rememberMe.checked = true;
        }

        rememberMe.addEventListener("change", function () {
            if (!rememberMe.checked) {
                localStorage.removeItem("drainguard_saved_email");
            }
        });
    }

    if (loginForm && rememberMe && emailInput) {
        loginForm.addEventListener("submit", function () {
            if (rememberMe.checked) {
                localStorage.setItem("drainguard_saved_email", emailInput.value.trim());
            } else {
                localStorage.removeItem("drainguard_saved_email");
            }
        });
    }
});