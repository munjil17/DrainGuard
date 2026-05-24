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

    const emailErrorText = document.getElementById("emailErrorText");
    const emailInputBox = emailInput ? emailInput.closest(".input-box") : null;

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

    function clearEmailError() {
        if (emailInputBox) {
            emailInputBox.classList.remove("input-error");
        }

        if (emailErrorText) {
            emailErrorText.textContent = "";
            emailErrorText.style.display = "none";
        }
    }

    function showEmailError(message) {
        if (emailInputBox) {
            emailInputBox.classList.add("input-error");
        }

        if (emailErrorText) {
            emailErrorText.textContent = message;
            emailErrorText.style.display = "block";
        }
    }

    function setRole(role) {
        const finalRole = roleConfig[role] ? role : "citizen";
        const config = roleConfig[finalRole];

        if (loginPage) {
            loginPage.setAttribute("data-active-role", finalRole);
        }

        if (selectedRoleInput) {
            selectedRoleInput.value = finalRole;
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
            card.classList.toggle("active", card.dataset.role === finalRole);
        });

        if (citizenSignupRedirect) {
            citizenSignupRedirect.style.display = finalRole === "citizen" ? "flex" : "none";
        }

        clearEmailError();
    }

    async function checkEmailByRole() {
        if (!emailInput || !selectedRoleInput) {
            return true;
        }

        const email = emailInput.value.trim();
        const selectedRole = selectedRoleInput.value.trim();

        clearEmailError();

        if (email === "") {
            showEmailError("Please enter your Gmail address.");
            return false;
        }

        const gmailPattern = /^[a-zA-Z0-9._%+-]+@gmail\.com$/;

        if (!gmailPattern.test(email)) {
            showEmailError("Only Gmail addresses are allowed.");
            return false;
        }

        const formData = new FormData();
        formData.append("email", email);
        formData.append("selected_role", selectedRole);

        try {
            const response = await fetch("check_email.php", {
                method: "POST",
                body: formData
            });

            const result = (await response.text()).trim();

            if (result === "exists") {
                return true;
            }

            if (result === "not_found") {
                showEmailError("This Gmail is not registered for the selected role.");
                return false;
            }

            if (result === "inactive") {
                showEmailError("Your account is inactive. Contact central control.");
                return false;
            }

            if (result === "access_disabled") {
                showEmailError("Your login access is disabled.");
                return false;
            }

            if (result === "invalid_gmail") {
                showEmailError("Only Gmail addresses are allowed.");
                return false;
            }

            if (result === "invalid_role") {
                showEmailError("Invalid selected role.");
                return false;
            }

            if (result === "sql_error") {
                showEmailError("Server query error. Check users table columns.");
                return false;
            }

            showEmailError("Email verification failed. Try again.");
            return false;
        } catch (error) {
            showEmailError("Could not verify email. Check server/AJAX path.");
            return false;
        }
    }

    const initialRole = loginPage ? loginPage.dataset.activeRole || "citizen" : "citizen";
    setRole(initialRole);

    roleCards.forEach(function (card) {
        card.addEventListener("click", function () {
            setRole(card.dataset.role || "citizen");
        });
    });

    if (passwordToggle && passwordInput) {
        passwordToggle.addEventListener("click", function () {
            const icon = passwordToggle.querySelector("i");

            if (!icon) {
                return;
            }

            const isPassword = passwordInput.type === "password";
            passwordInput.type = isPassword ? "text" : "password";

            icon.classList.toggle("bi-eye", !isPassword);
            icon.classList.toggle("bi-eye-slash", isPassword);
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

        emailInput.addEventListener("input", clearEmailError);
    }

    if (loginForm && rememberMe && emailInput) {
        loginForm.addEventListener("submit", async function (event) {
            event.preventDefault();

            const isValidEmail = await checkEmailByRole();

            if (!isValidEmail) {
                return;
            }

            const email = emailInput.value.trim();

            if (rememberMe.checked && email !== "") {
                localStorage.setItem("drainguard_saved_email", email);
            } else {
                localStorage.removeItem("drainguard_saved_email");
            }

            loginForm.submit();
        });
    }
});