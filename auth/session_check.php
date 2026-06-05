<?php
// C:\xampp\htdocs\DrainGuard\auth\session_check.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Base URL
|--------------------------------------------------------------------------
*/
$baseUrl = "/DrainGuard/";

/*
|--------------------------------------------------------------------------
| Auto-Login (Remember Me Check)
|--------------------------------------------------------------------------
| If session is empty but a valid cookie exists, auto-login the user.
|--------------------------------------------------------------------------
*/
if (empty($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    
    // Check if cookie exists and $conn is available
    global $conn;
    if (isset($_COOKIE['remember_me']) && !empty($conn)) {
        
        $cookieParts = explode(':', $_COOKIE['remember_me']);
        
        if (count($cookieParts) === 2) {
            $selector = $cookieParts[0];
            $validator = $cookieParts[1];

            $tokenSql = "
                SELECT t.hashed_validator, t.user_id, u.user_name, u.user_mail, u.user_role, u.user_status, u.login_access
                FROM remember_tokens t
                JOIN users u ON t.user_id = u.user_id
                WHERE t.selector = ? AND t.expires_at >= NOW()
                LIMIT 1
            ";

            $tokenStmt = mysqli_prepare($conn, $tokenSql);

            if ($tokenStmt) {
                mysqli_stmt_bind_param($tokenStmt, "s", $selector);
                mysqli_stmt_execute($tokenStmt);
                $result = mysqli_stmt_get_result($tokenStmt);

                if ($row = mysqli_fetch_assoc($result)) {
                    // Hash check
                    if (hash_equals($row['hashed_validator'], hash('sha256', $validator))) {
                        // User status & access check for auto-login
                        if (strtolower(trim($row["user_status"])) === "active" && (int)$row["login_access"] === 1) {
                            
                            // Auto Login Success!
                            $_SESSION["user_id"] = (int)$row["user_id"];
                            $_SESSION["user_name"] = $row["user_name"];
                            $_SESSION["user_email"] = $row["user_mail"];
                            $_SESSION["user_role"] = $row["user_role"];
                            $_SESSION["logged_in"] = true;
                            
                            session_regenerate_id(true);
                        }
                    }
                }
                mysqli_stmt_close($tokenStmt);
            }
        }
    }
}

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

// Active suspension & ban check
global $conn;
if (isset($conn) && !empty($_SESSION["user_id"])) {
    require_once __DIR__ . "/../includes/disciplinary_helpers.php";
    restoreExpiredSuspension($conn, $_SESSION["user_id"]);

    $checkStatusSql = "SELECT user_status, login_access FROM users WHERE user_id = ?";
    $checkStatusStmt = mysqli_prepare($conn, $checkStatusSql);
    if ($checkStatusStmt) {
        mysqli_stmt_bind_param($checkStatusStmt, "i", $_SESSION["user_id"]);
        mysqli_stmt_execute($checkStatusStmt);
        $resStatus = mysqli_stmt_get_result($checkStatusStmt);
        if ($rowStatus = mysqli_fetch_assoc($resStatus)) {
            if ((int)$rowStatus["login_access"] !== 1 || strtolower(trim($rowStatus["user_status"])) !== "active") {
                // Suspended or banned while logged in
                session_destroy();
                header("Location: " . $baseUrl . "auth/login.php");
                exit();
            }
        }
        mysqli_stmt_close($checkStatusStmt);
    }
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