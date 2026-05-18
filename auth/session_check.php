<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header("Location: /DrainGuard/auth/login.php");
    exit();
}

if (isset($allowed_role) && $_SESSION['user_role'] !== $allowed_role) {

    $redirectMap = [
        "citizen" => "/DrainGuard/pages/citizen/dashboard.php",
        "central_officer" => "/DrainGuard/pages/central/dashboard.php",
        "ward_officer" => "/DrainGuard/pages/ward/dashboard.php",
        "maintenance_team" => "/DrainGuard/pages/maintenance/dashboard.php",
        "maintenance_member" => "/DrainGuard/pages/maintenance/dashboard.php",
        "inspector" => "/DrainGuard/pages/inspector/dashboard.php"
    ];

    $redirectUrl = $redirectMap[$_SESSION['user_role']] ?? "/DrainGuard/auth/login.php";

    header("Location: " . $redirectUrl);
    exit();
}

?>