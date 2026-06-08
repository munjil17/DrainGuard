<?php
// C:\xampp\htdocs\DrainGuard\config.php

/* =========================================================
   SESSION START
========================================================= */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================================
   TIMEZONE
========================================================= */
date_default_timezone_set('Asia/Dhaka');

/* =========================================================
   DATABASE CONFIGURATION
========================================================= */
$host     = "localhost";
$username = "root";
$password = "";
$database = "drainguard";

/* =========================================================
   BASE URL
========================================================= */
$baseUrl = "/DrainGuard/";

/* =========================================================
   SMTP CONFIGURATION
   Local credentials are loaded from .env in the project root.
   Never commit real SMTP credentials to source control.
========================================================= */
function dg_load_env_file($envPath)
{
    if (!is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === "" || strpos($line, "#") === 0) {
            continue;
        }

        $equalsPos = strpos($line, "=");
        if ($equalsPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $equalsPos));
        $value = trim(substr($line, $equalsPos + 1));

        if ($key === "") {
            continue;
        }

        if (
            (strlen($value) >= 2) &&
            (($value[0] === '"' && substr($value, -1) === '"') ||
             ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . "=" . $value);
        }

        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
        }

        if (!isset($_SERVER[$key])) {
            $_SERVER[$key] = $value;
        }
    }
}

dg_load_env_file(__DIR__ . DIRECTORY_SEPARATOR . ".env");

function dg_env($key, $default = "")
{
    $value = getenv($key);
    if ($value === false || $value === "") {
        return $default;
    }

    return $value;
}

if (!defined("DG_SMTP_HOST")) {
    define("DG_SMTP_HOST", dg_env("SMTP_HOST", ""));
}

if (!defined("DG_SMTP_PORT")) {
    define("DG_SMTP_PORT", (int)dg_env("SMTP_PORT", 587));
}

if (!defined("DG_SMTP_USERNAME")) {
    define("DG_SMTP_USERNAME", dg_env("SMTP_USERNAME", ""));
}

if (!defined("DG_SMTP_PASSWORD")) {
    define("DG_SMTP_PASSWORD", dg_env("SMTP_PASSWORD", ""));
}

if (!defined("DG_SMTP_FROM_EMAIL")) {
    define("DG_SMTP_FROM_EMAIL", dg_env("SMTP_FROM_EMAIL", DG_SMTP_USERNAME));
}

if (!defined("DG_SMTP_FROM_NAME")) {
    define("DG_SMTP_FROM_NAME", dg_env("SMTP_FROM_NAME", "DrainGuard"));
}

function dg_smtp_is_configured()
{
    return DG_SMTP_HOST !== ""
        && DG_SMTP_PORT > 0
        && DG_SMTP_USERNAME !== ""
        && DG_SMTP_PASSWORD !== ""
        && DG_SMTP_FROM_EMAIL !== "";
}

/* =========================================================
   DATABASE CONNECTION
========================================================= */
mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Service is temporarily unavailable. Please try again.");
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
   — Fixed: team_leader + assistant_team_leader
   — Fixed: all 5 roles now correctly mapped
========================================================= */
function get_role_dashboard($role)
{
    $dashboards = [
        "citizen"                => "pages/citizen/dashboard.php",
        "central_officer"        => "pages/central/dashboard.php",
        "ward_officer"           => "pages/ward/dashboard.php",
        "team_leader"            => "pages/maintenance/dashboard.php",
        "assistant_team_leader"  => "pages/maintenance/dashboard.php",
        "inspector"              => "pages/inspector/dashboard.php",
    ];

    return $dashboards[$role] ?? "auth/login.php";
}

/* =========================================================
   CURRENT USER HELPERS
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
   DB DEBUG HELPERS
   — Use temporarily when data is not showing
========================================================= */
function dg_debug_db($conn)
{
    $dbResult = mysqli_query($conn, "SELECT DATABASE() AS db_name");
    $dbRow    = $dbResult ? mysqli_fetch_assoc($dbResult) : null;

    echo "<pre>";
    echo "Service status    : " . ($dbRow['db_name'] ?? "Unknown") . PHP_EOL;
    echo "Last service error: " . mysqli_error($conn) . PHP_EOL;
    echo "Timezone           : " . date_default_timezone_get() . PHP_EOL;
    echo "Current Time       : " . date("Y-m-d H:i:s") . PHP_EOL;
    echo "</pre>";
}

function dg_table_count($conn, $tableName)
{
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $sql       = "SELECT COUNT(*) AS total FROM `$safeTable`";
    $result    = mysqli_query($conn, $sql);

    if (!$result) {
        return "Unable to load records. " . mysqli_error($conn);
    }

    $row = mysqli_fetch_assoc($result);
    return $row['total'] ?? 0;
}

/* =========================================================
   TEAM AVAILABILITY HELPER
========================================================= */
function autoUpdateTeamAvailability($conn, $maintenanceTeamId)
{
    $maintenanceTeamId = (int)$maintenanceTeamId;
    if ($maintenanceTeamId <= 0) return false;

    // Count active tasks for this team (team_assigned or in_progress)
    $sql = "
        SELECT COUNT(*) AS active_tasks
        FROM complaint_assignments ca
        INNER JOIN complaints c ON c.complaint_id = ca.complaint_id
        WHERE ca.maintenance_team_id = ?
          AND c.complaint_status IN ('team_assigned', 'in_progress')
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, "i", $maintenanceTeamId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $activeCount = 0;
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $activeCount = (int)$row['active_tasks'];
    }
    mysqli_stmt_close($stmt);

    // Rule: active tasks >= 3 means busy, else available
    $newStatus = ($activeCount >= 3) ? 'busy' : 'available';

    // Update the maintenance_teams table
    $updateSql = "UPDATE maintenance_teams SET availability_status = ? WHERE maintenance_team_id = ?";
    $updStmt = mysqli_prepare($conn, $updateSql);
    if ($updStmt) {
        mysqli_stmt_bind_param($updStmt, "si", $newStatus, $maintenanceTeamId);
        mysqli_stmt_execute($updStmt);
        mysqli_stmt_close($updStmt);
    }
    
    return $newStatus;
}
?>
