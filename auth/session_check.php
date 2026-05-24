<?php
// C:\xampp\htdocs\DrainGuard\auth\session_check.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Base URL
|--------------------------------------------------------------------------
| Keep this fixed for XAMPP path:
| http://localhost/DrainGuard/
|--------------------------------------------------------------------------
*/

$baseUrl = "/DrainGuard/";

/*
|--------------------------------------------------------------------------
| Login Check
|--------------------------------------------------------------------------
| User must be logged in before accessing protected pages.
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true ||
    empty($_SESSION["user_id"]) ||
    empty($_SESSION["user_role"])
) {
    header("Location: " . $baseUrl . "auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Role Redirect Map
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

/*
|--------------------------------------------------------------------------
| Role Check
|--------------------------------------------------------------------------
| Supports both:
|
| $allowed_role = "citizen";
|
| and:
|
| $allowed_roles = ["maintenance_team", "maintenance_member"];
|--------------------------------------------------------------------------
*/

$currentRole = $_SESSION["user_role"];

if (isset($allowed_role) && !empty($allowed_role)) {
    $allowed_roles = [$allowed_role];
}

if (isset($allowed_roles) && is_array($allowed_roles) && !empty($allowed_roles)) {
    if (!in_array($currentRole, $allowed_roles, true)) {
        $redirectUrl = $redirectMap[$currentRole] ?? "auth/login.php";

        header("Location: " . $baseUrl . $redirectUrl);
        exit();
    }
}
?>