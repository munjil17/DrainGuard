<?php
// C:\xampp\htdocs\DrainGuard\auth\login_process.php

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
    redirect_to("auth/login.php");
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

unset(
    $_SESSION["email_error"],
    $_SESSION["password_error"],
    $_SESSION["login_error"]
);

/*
|--------------------------------------------------------------------------
| Empty Field Validation
|--------------------------------------------------------------------------
*/

if ($email === "" || $password === "" || $role === "") {
    $_SESSION["email_error"] = "Please fill up all required fields.";
    redirect_to("auth/login.php");
}

/*
|--------------------------------------------------------------------------
| Email Validation
|--------------------------------------------------------------------------
*/

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION["email_error"] = "Invalid email format.";
    redirect_to("auth/login.php");
}

/*
|--------------------------------------------------------------------------
| Optional Gmail Validation
| Keep this only if your project allows Gmail only.
|--------------------------------------------------------------------------
*/

if (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
    $_SESSION["email_error"] = "Only Gmail addresses are allowed.";
    redirect_to("auth/login.php");
}

/*
|--------------------------------------------------------------------------
| Role Mapping: Frontend Role → Database Role
|--------------------------------------------------------------------------
| Frontend role values usually:
| citizen, central, ward, maintenance, inspector
|
| Database role values may be:
| citizen
| central_officer
| ward_officer
| maintenance_team
| maintenance_member
| team_leader
| assistant_team_leader
| inspector
|--------------------------------------------------------------------------
*/

$roleMap = [
    "citizen" => ["citizen"],

    "central" => ["central_officer"],
    "central_officer" => ["central_officer"],

    "ward" => ["ward_officer"],
    "ward_officer" => ["ward_officer"],

    "maintenance" => [
        "maintenance_team",
        "maintenance_member",
        "team_leader",
        "assistant_team_leader"
    ],
    "maintenance_team" => ["maintenance_team"],
    "maintenance_member" => ["maintenance_member"],
    "team_leader" => ["team_leader"],
    "assistant_team_leader" => ["assistant_team_leader"],

    "inspector" => ["inspector"]
];

$allowedRoles = $roleMap[$role] ?? [];

if (empty($allowedRoles)) {
    $_SESSION["email_error"] = "Invalid selected role.";
    redirect_to("auth/login.php");
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
    $_SESSION["email_error"] = "Login query failed. SQL Error: " . mysqli_error($conn);
    redirect_to("auth/login.php");
}

$types = "s" . str_repeat("s", count($allowedRoles));
$params = array_merge([$email], $allowedRoles);

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) !== 1) {
    mysqli_stmt_close($stmt);

    $_SESSION["email_error"] = "Invalid Gmail or selected role.";
    redirect_to("auth/login.php");
}

$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| Account Status Check
|--------------------------------------------------------------------------
*/

$userStatus = strtolower(trim($user["user_status"] ?? ""));

if ($userStatus !== "active") {
    $_SESSION["email_error"] = "Your account is inactive. Contact central control.";
    redirect_to("auth/login.php");
}

/*
|--------------------------------------------------------------------------
| Login Access Check
|--------------------------------------------------------------------------
| login_access = 1 means allowed
| login_access = 0 means blocked
|--------------------------------------------------------------------------
*/

if (isset($user["login_access"]) && (int)$user["login_access"] !== 1) {
    $_SESSION["email_error"] = "Your login access is disabled.";
    redirect_to("auth/login.php");
}

/*
|--------------------------------------------------------------------------
| Password Check
|--------------------------------------------------------------------------
*/

if (!password_verify($password, $user["user_password"])) {
    $_SESSION["password_error"] = "Incorrect password.";
    redirect_to("auth/login.php");
}

/*
|--------------------------------------------------------------------------
| Secure Session Regeneration
|--------------------------------------------------------------------------
*/

session_regenerate_id(true);

/*
|--------------------------------------------------------------------------
| Set Login Session
|--------------------------------------------------------------------------
*/

unset(
    $_SESSION["email_error"],
    $_SESSION["password_error"],
    $_SESSION["login_error"]
);

$_SESSION["user_id"] = (int)$user["user_id"];
$_SESSION["user_name"] = $user["user_name"];
$_SESSION["user_email"] = $user["user_mail"];
$_SESSION["user_role"] = $user["user_role"];
$_SESSION["selected_role"] = $role;
$_SESSION["logged_in"] = true;

/*
|--------------------------------------------------------------------------
| User Role Label
|--------------------------------------------------------------------------
*/

$roleLabels = [
    "citizen" => "Public Portal",
    "central_officer" => "Central Command",
    "ward_officer" => "Ward Operations",
    "maintenance_team" => "Maintenance Team",
    "maintenance_member" => "Maintenance Member",
    "team_leader" => "Maintenance Team Leader",
    "assistant_team_leader" => "Assistant Team Leader",
    "inspector" => "Quality Control"
];

$_SESSION["user_role_label"] = $roleLabels[$user["user_role"]] ?? "User";

/*
|--------------------------------------------------------------------------
| Update Last Active Time
| Safe check: only runs if last_active column exists.
|--------------------------------------------------------------------------
*/

$columnCheckSql = "
    SELECT COUNT(*) AS total
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'last_active'
";

$columnCheckResult = mysqli_query($conn, $columnCheckSql);
$columnCheckRow = $columnCheckResult ? mysqli_fetch_assoc($columnCheckResult) : null;

if (($columnCheckRow["total"] ?? 0) > 0) {
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
}

/*
|--------------------------------------------------------------------------
| Redirect By Role
|--------------------------------------------------------------------------
*/

$redirectMap = [
    "citizen" => "pages/citizen/dashboard.php",
    "central_officer" => "pages/central/dashboard.php",
    "ward_officer" => "pages/ward/dashboard.php",

    "maintenance_team" => "pages/maintenance/dashboard.php",
    "maintenance_member" => "pages/maintenance/dashboard.php",
    "team_leader" => "pages/maintenance/dashboard.php",
    "assistant_team_leader" => "pages/maintenance/dashboard.php",

    "inspector" => "pages/inspector/dashboard.php"
];

$redirectUrl = $redirectMap[$user["user_role"]] ?? "auth/login.php";

redirect_to($redirectUrl);
?>