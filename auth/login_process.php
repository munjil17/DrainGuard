<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config.php";

/*
|--------------------------------------------------------------------------
| Allow POST Request Only
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Collect Form Data
|--------------------------------------------------------------------------
*/

$email = strtolower(trim($_POST["email_or_phone"] ?? $_POST["email"] ?? ""));
$password = trim($_POST["password"] ?? "");
$role = trim($_POST["selected_role"] ?? $_POST["role"] ?? "citizen");

$_SESSION["selected_role"] = $role;

unset($_SESSION["email_error"], $_SESSION["password_error"]);

/*
|--------------------------------------------------------------------------
| Empty Field Validation
|--------------------------------------------------------------------------
*/

if ($email === "" || $password === "" || $role === "") {
    $_SESSION["email_error"] = "Please fill up all required fields.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Email Validation
|--------------------------------------------------------------------------
*/

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION["email_error"] = "Invalid email format.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

if (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
    $_SESSION["email_error"] = "Only Gmail addresses are allowed.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Role Mapping: Frontend Role → Database Role
|--------------------------------------------------------------------------
|
| Current maintenance roles:
| Team Leader           = users.user_role = team_leader
| Assistant Team Leader = users.user_role = assistant_team_leader
| Worker                = no users table login
|
|--------------------------------------------------------------------------
*/

$allowedRoles = [];

if ($role === "citizen") {
    $allowedRoles = ["citizen"];
}

if ($role === "central" || $role === "central_officer") {
    $allowedRoles = ["central_officer"];
}

if ($role === "ward" || $role === "ward_officer") {
    $allowedRoles = ["ward_officer"];
}

if (
    $role === "maintenance" ||
    $role === "maintenance_team" ||
    $role === "maintenance_member" ||
    $role === "team_leader" ||
    $role === "assistant_team_leader"
) {
    $allowedRoles = ["team_leader", "assistant_team_leader"];
}

if ($role === "inspector") {
    $allowedRoles = ["inspector"];
}

if (empty($allowedRoles)) {
    $_SESSION["email_error"] = "Invalid selected role.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Fetch User From Database
|--------------------------------------------------------------------------
*/

$rolePlaceholders = implode(",", array_fill(0, count($allowedRoles), "?"));

$sql = "
    SELECT 
        user_id,
        user_name,
        user_mail,
        user_password,
        user_role,
        user_status,
        login_access
    FROM users
    WHERE user_mail = ?
    AND user_role IN ($rolePlaceholders)
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    $_SESSION["email_error"] = "Something went wrong. Please try again.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

$types = "s" . str_repeat("s", count($allowedRoles));
$params = array_merge([$email], $allowedRoles);

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) !== 1) {
    mysqli_stmt_close($stmt);

    $_SESSION["email_error"] = "Invalid Gmail or selected role.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| Account Status Check
|--------------------------------------------------------------------------
*/

if (strtolower($user["user_status"]) !== "active") {
    $_SESSION["email_error"] = "Your account is inactive. Contact central control.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Login Access Check
|--------------------------------------------------------------------------
|
| login_access = 1 means allowed
| login_access = 0 means blocked
|
|--------------------------------------------------------------------------
*/

if ((int)$user["login_access"] !== 1) {
    $_SESSION["email_error"] = "Your login access is disabled.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Password Check
|--------------------------------------------------------------------------
*/

if (!password_verify($password, $user["user_password"])) {
    $_SESSION["password_error"] = "Incorrect password.";
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Set Login Session
|--------------------------------------------------------------------------
*/

unset($_SESSION["email_error"], $_SESSION["password_error"]);

$_SESSION["user_id"] = $user["user_id"];
$_SESSION["user_name"] = $user["user_name"];
$_SESSION["user_email"] = $user["user_mail"];
$_SESSION["user_role"] = $user["user_role"];
$_SESSION["selected_role"] = $user["user_role"];

/*
|--------------------------------------------------------------------------
| User Role Label
|--------------------------------------------------------------------------
*/

$roleLabels = [
    "citizen" => "Public Portal",
    "central_officer" => "Central Command",
    "ward_officer" => "Ward Operations",
    "team_leader" => "Maintenance Team Leader",
    "assistant_team_leader" => "Assistant Team Leader",
    "inspector" => "Quality Control"
];

$_SESSION["user_role_label"] = $roleLabels[$user["user_role"]] ?? "User";

/*
|--------------------------------------------------------------------------
| Update Last Active Time
|--------------------------------------------------------------------------
| This requires:
| users.last_active DATETIME NULL
|--------------------------------------------------------------------------
*/

$updateLastActiveSql = "
    UPDATE users 
    SET last_active = NOW() 
    WHERE user_id = ?
";

$updateLastActiveStmt = mysqli_prepare($conn, $updateLastActiveSql);

if ($updateLastActiveStmt) {
    mysqli_stmt_bind_param($updateLastActiveStmt, "i", $user["user_id"]);
    mysqli_stmt_execute($updateLastActiveStmt);
    mysqli_stmt_close($updateLastActiveStmt);
}

/*
|--------------------------------------------------------------------------
| Redirect By Role
|--------------------------------------------------------------------------
*/

$redirectMap = [
    "citizen" => "/DrainGuard/pages/citizen/dashboard.php",
    "central_officer" => "/DrainGuard/pages/central/dashboard.php",
    "ward_officer" => "/DrainGuard/pages/ward/dashboard.php",
    "team_leader" => "/DrainGuard/pages/maintenance/dashboard.php",
    "assistant_team_leader" => "/DrainGuard/pages/maintenance/dashboard.php",
    "inspector" => "/DrainGuard/pages/inspector/dashboard.php"
];

$redirectUrl = $redirectMap[$user["user_role"]] ?? "/DrainGuard/auth/login.php";

header("Location: " . $redirectUrl);
exit();

?>