<?php
// C:\xampp\htdocs\DrainGuard\auth\login.php

require_once "../config.php";

/*
|--------------------------------------------------------------------------
| If already logged in, send user to own dashboard
|--------------------------------------------------------------------------
*/

if (
    !empty($_SESSION["logged_in"]) &&
    $_SESSION["logged_in"] === true &&
    !empty($_SESSION["user_role"])
) {
    $dashboardPath = get_role_dashboard($_SESSION["user_role"]);
    redirect_to($dashboardPath);
}

$pageTitle = "Login | DrainGuard";

$emailError = $_SESSION["email_error"] ?? "";
$passwordError = $_SESSION["password_error"] ?? "";
$successMessage = $_SESSION["success_message"] ?? "";

$selectedRole = $_SESSION["selected_role"] ?? "citizen";

unset(
    $_SESSION["email_error"],
    $_SESSION["password_error"],
    $_SESSION["success_message"]
);

function safeText($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

$validFrontendRoles = [
    "citizen",
    "central",
    "ward",
    "maintenance",
    "inspector"
];

if (!in_array($selectedRole, $validFrontendRoles, true)) {
    $selectedRole = "citizen";
}

$showCitizenSignup = ($selectedRole === "citizen") ? "flex" : "none";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo safeText($pageTitle); ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../css/global/login.css">
    <link rel="stylesheet" href="../css/global/confirm-modal.css">
</head>

<body>

<main class="login-page" data-active-role="<?php echo safeText($selectedRole); ?>">

    <section class="login-left">

        <div class="brand-block">
            <div class="brand-logo">
                <i class="bi bi-droplet-fill"></i>
            </div>

            <div>
                <h2>DrainGuard</h2>
                <p>Smart Urban Drainage</p>
            </div>
        </div>

        <div class="role-selector">

            <p class="role-helper">Choose your access level to continue.</p>

            <div class="role-list">

                <button type="button" class="role-card" data-role="citizen">
                    <span class="role-icon">
                        <i class="bi bi-person"></i>
                    </span>

                    <div class="role-info">
                        <h3>Citizen <span>Complaint Portal</span></h3>
                        <p>Report drainage issues and track your complaint status.</p>
                    </div>

                    <i class="bi bi-check-circle check-icon"></i>
                </button>

                <button type="button" class="role-card" data-role="central">
                    <span class="role-icon">
                        <i class="bi bi-building"></i>
                    </span>

                    <div class="role-info">
                        <h3>Central Control <span>City Oversight</span></h3>
                        <p>Review, route, and monitor citywide drainage complaints.</p>
                    </div>

                    <i class="bi bi-check-circle check-icon"></i>
                </button>

                <button type="button" class="role-card" data-role="ward">
                    <span class="role-icon">
                        <i class="bi bi-geo-alt"></i>
                    </span>

                    <div class="role-info">
                        <h3>Ward Officer <span>Local Verification</span></h3>
                        <p>Verify local complaints and assign maintenance teams.</p>
                    </div>

                    <i class="bi bi-check-circle check-icon"></i>
                </button>

                <button type="button" class="role-card" data-role="maintenance">
                    <span class="role-icon">
                        <i class="bi bi-tools"></i>
                    </span>

                    <div class="role-info">
                        <h3>Maintenance <span>Field Operations</span></h3>
                        <p>Manage assigned work and submit completion evidence.</p>
                    </div>

                    <i class="bi bi-check-circle check-icon"></i>
                </button>

                <button type="button" class="role-card" data-role="inspector">
                    <span class="role-icon">
                        <i class="bi bi-search"></i>
                    </span>

                    <div class="role-info">
                        <h3>Inspector <span>Quality Verification</span></h3>
                        <p>Review completed work and confirm final resolution.</p>
                    </div>

                    <i class="bi bi-check-circle check-icon"></i>
                </button>

            </div>
        </div>

    </section>

    <section class="login-right">

        <div class="login-card">

            <div class="login-badge">
                <span></span>
                Accessing <strong id="selectedRoleText">Citizen Portal</strong>
            </div>

            <div class="login-header">
                <h1 id="loginTitle">Citizen Complaint Portal</h1>
                <p id="loginSubtitle">Report and track your drainage complaint</p>
            </div>

            <?php if (!empty($successMessage)): ?>
                <div class="success-text">
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <form action="login_process.php" method="POST" id="loginForm">

                <input 
                    type="hidden" 
                    name="selected_role" 
                    id="selectedRole" 
                    value="<?php echo safeText($selectedRole); ?>"
                >

                <div class="form-group">
                    <label for="loginEmailInput">Email Address</label>

                    <div class="input-box <?php echo !empty($emailError) ? 'input-error' : ''; ?>">
                        <i class="bi bi-envelope"></i>

                        <!-- autocomplete="username" better for password managers -->
                        <input
                            type="email"
                            name="email_or_phone"
                            id="loginEmailInput"
                            placeholder="example@email.com"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <small 
                        class="error-text" 
                        id="emailErrorText" 
                        style="display: <?php echo !empty($emailError) ? 'block' : 'none'; ?>;"
                    >
                        <?php echo safeText($emailError); ?>
                    </small>
                </div>

                <div class="form-group">
                    <label for="passwordInput">Password</label>

                    <div class="input-box <?php echo !empty($passwordError) ? 'input-error' : ''; ?>">
                        <i class="bi bi-lock"></i>

                        <input
                            type="password"
                            name="password"
                            id="passwordInput"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >

                        <button type="button" class="password-toggle" id="passwordToggle">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>

                    <?php if (!empty($passwordError)): ?>
                        <small class="error-text"><?php echo safeText($passwordError); ?></small>
                    <?php endif; ?>
                </div>

                <div class="auth-options">
                    <label class="remember-box" for="rememberMe">
                        <input type="checkbox" name="remember_me" id="rememberMe">
                        <span class="remember-custom-box"></span>
                        <span>Remember me</span>
                    </label>

                    <!-- Forgot password link updated -->
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>

                <button type="submit" class="submit-btn" id="submitBtn">
                    <i class="bi bi-shield-lock"></i>
                    <span id="submitBtnText">Continue to Citizen Portal</span>
                    <i class="bi bi-arrow-right"></i>
                </button>

                <div 
                    class="signup-redirect" 
                    id="citizenSignupRedirect" 
                    style="display: <?php echo safeText($showCitizenSignup); ?>;"
                >
                    <span>Need an account?</span>
                   <a href="../citizenRegistration/citizen_signup.php">Register as Citizen</a>
                </div>

            </form>

        </div>

    </section>

</main>

<script src="../js/global/login.js"></script>

<script src="../js/global/confirm-modal.js"></script>
</body>
</html>