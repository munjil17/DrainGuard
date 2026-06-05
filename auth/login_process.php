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
$rememberMe = isset($_POST["remember_me"]);

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
| Email Validation & Fake Domain Block
|--------------------------------------------------------------------------
*/
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION["email_error"] = "Invalid email format.";
    redirect_to("auth/login.php");
}

// Block fake / test domains
$blockedDomains = ['example.com', 'test.com', 'localhost', 'fake.com'];
$domain = substr(strrchr($email, "@"), 1);

if (in_array($domain, $blockedDomains)) {
    $_SESSION["email_error"] = "This email domain is not allowed for security reasons.";
    redirect_to("auth/login.php");
}

/*
|--------------------------------------------------------------------------
| Role Mapping
|--------------------------------------------------------------------------
*/
$roleMap = [
    "citizen"               => ["citizen"],
    "central"               => ["central_officer"],
    "central_officer"       => ["central_officer"],
    "ward"                  => ["ward_officer"],
    "ward_officer"          => ["ward_officer"],
    "maintenance"           => ["maintenance_team", "maintenance_member", "team_leader", "assistant_team_leader"],
    "maintenance_team"      => ["maintenance_team"],
    "maintenance_member"    => ["maintenance_member"],
    "team_leader"           => ["team_leader"],
    "assistant_team_leader" => ["assistant_team_leader"],
    "inspector"             => ["inspector"]
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
    $_SESSION["email_error"] = "System error during login.";
    redirect_to("auth/login.php");
}

$types = "s" . str_repeat("s", count($allowedRoles));
$params = array_merge([$email], $allowedRoles);

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) !== 1) {
    mysqli_stmt_close($stmt);
    $_SESSION["email_error"] = "Invalid email or role.";
    redirect_to("auth/login.php");
}

$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| Account & Access Check
|--------------------------------------------------------------------------
*/

// Check if there is an expired suspension that needs to be restored
require_once __DIR__ . "/../includes/disciplinary_helpers.php";
restoreExpiredSuspension($conn, $user["user_id"]);

// Fetch the latest login_access and user_status in case it was restored
$checkStatusSql = "SELECT user_status, login_access FROM users WHERE user_id = ?";
$checkStatusStmt = mysqli_prepare($conn, $checkStatusSql);
if ($checkStatusStmt) {
    mysqli_stmt_bind_param($checkStatusStmt, "i", $user["user_id"]);
    mysqli_stmt_execute($checkStatusStmt);
    $resStatus = mysqli_stmt_get_result($checkStatusStmt);
    if ($rowStatus = mysqli_fetch_assoc($resStatus)) {
        $user["user_status"] = $rowStatus["user_status"];
        $user["login_access"] = $rowStatus["login_access"];
    }
    mysqli_stmt_close($checkStatusStmt);
}

if (strtolower(trim($user["user_status"] ?? "")) !== "active") {
    $_SESSION["email_error"] = "Your account is inactive. Please contact support.";
    redirect_to("auth/login.php");
}

if (isset($user["login_access"]) && (int)$user["login_access"] !== 1) {
    $_SESSION["email_error"] = "Your login access has been disabled.";
    redirect_to("auth/login.php");
}

/*
|--------------------------------------------------------------------------
| Password Check (Secure password_verify)
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
$_SESSION["user_id"] = (int)$user["user_id"];
$_SESSION["user_name"] = $user["user_name"];
$_SESSION["user_email"] = $user["user_mail"];
$_SESSION["user_role"] = $user["user_role"];
$_SESSION["selected_role"] = $role;
$_SESSION["logged_in"] = true;

// Role label remove (কারণ আমরা sidebar/topbar-এ static করে দিয়েছি)
unset($_SESSION["user_role_label"]);

/*
|--------------------------------------------------------------------------
| Update Last Active Time (Direct Query, No Info Schema)
|--------------------------------------------------------------------------
*/
$updateLastActiveSql = "UPDATE users SET last_active = NOW() WHERE user_id = ?";
$updateLastActiveStmt = mysqli_prepare($conn, $updateLastActiveSql);
if ($updateLastActiveStmt) {
    mysqli_stmt_bind_param($updateLastActiveStmt, "i", $user["user_id"]);
    mysqli_stmt_execute($updateLastActiveStmt);
    mysqli_stmt_close($updateLastActiveStmt);
}

/*
|--------------------------------------------------------------------------
| Secure "Remember Me" Implementation
|--------------------------------------------------------------------------
*/
if ($rememberMe) {
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $hashed_validator = hash('sha256', $validator);
    
    // Cookie valid for 30 days
    $expires = time() + (86400 * 30);
    $expires_date = date('Y-m-d H:i:s', $expires);

    $tokenSql = "INSERT INTO remember_tokens (user_id, selector, hashed_validator, expires_at) VALUES (?, ?, ?, ?)";
    $tokenStmt = mysqli_prepare($conn, $tokenSql);

    if ($tokenStmt) {
        mysqli_stmt_bind_param($tokenStmt, "isss", $user["user_id"], $selector, $hashed_validator, $expires_date);
        mysqli_stmt_execute($tokenStmt);
        mysqli_stmt_close($tokenStmt);

        // Set secure cookie
        $cookieValue = $selector . ':' . $validator;
        setcookie(
            'remember_me', 
            $cookieValue, 
            [
                'expires' => $expires,
                'path' => '/',
                'domain' => '', 
                'secure' => isset($_SERVER['HTTPS']), 
                'httponly' => true, 
                'samesite' => 'Lax'
            ]
        );
    }
}

/*
|--------------------------------------------------------------------------
| Redirect By Role
|--------------------------------------------------------------------------
*/
$redirectUrl = get_role_dashboard($user["user_role"]);
redirect_to($redirectUrl);
?>