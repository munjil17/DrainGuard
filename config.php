<?php
// C:\xampp\htdocs\DrainGuard\config.php

/* =========================================================
   SESSION START
========================================================= */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================================
   DATABASE CONFIGURATION
========================================================= */
$host = "localhost";
$username = "root";
$password = "";
$database = "drainguard";

/* =========================================================
   BASE URL
   Correct browser URL: http://localhost/DrainGuard/
========================================================= */
$baseUrl = "/DrainGuard/";

/* =========================================================
   DATABASE CONNECTION
========================================================= */
mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

/* =========================================================
   REDIRECT HELPER
========================================================= */
function redirect_to($path)
{
    global $baseUrl;

    header("Location: " . $baseUrl . ltrim($path, "/"));
    exit();
}

/* =========================================================
   LOGIN CHECK HELPER
   Use this on protected pages.
========================================================= */
function require_login($allowedRoles = [])
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        redirect_to("auth/login.php");
    }

    if (!empty($allowedRoles) && !in_array($_SESSION['user_role'], $allowedRoles, true)) {
        redirect_to("auth/login.php");
    }
}

/* =========================================================
   ROLE DASHBOARD HELPER
========================================================= */
function get_role_dashboard($role)
{
    $dashboards = [
        "citizen" => "pages/citizen/dashboard.php",
        "central_officer" => "pages/central/dashboard.php",
        "ward_officer" => "pages/ward/dashboard.php",
        "maintenance_team" => "pages/maintenance/dashboard.php",
        "maintenance_member" => "pages/maintenance/dashboard.php",
        "inspector" => "pages/inspector/dashboard.php"
    ];

    return $dashboards[$role] ?? "auth/login.php";
}

/* =========================================================
   CURRENT USER HELPER
========================================================= */
function current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}

function current_user_role()
{
    return $_SESSION['user_role'] ?? null;
}

function current_user_name()
{
    return $_SESSION['user_name'] ?? "User";
}

/* =========================================================
   DB DEBUG HELPER
   Use temporarily only when data is not showing.
========================================================= */
function dg_debug_db($conn)
{
    $dbResult = mysqli_query($conn, "SELECT DATABASE() AS db_name");
    $dbRow = $dbResult ? mysqli_fetch_assoc($dbResult) : null;

    echo "<pre>";
    echo "Connected Database: " . ($dbRow['db_name'] ?? "Unknown") . PHP_EOL;
    echo "MySQL Error: " . mysqli_error($conn) . PHP_EOL;
    echo "</pre>";
}

function dg_table_count($conn, $tableName)
{
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);

    $sql = "SELECT COUNT(*) AS total FROM `$safeTable`";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return "SQL Error: " . mysqli_error($conn);
    }

    $row = mysqli_fetch_assoc($result);
    return $row['total'] ?? 0;
}
?>