<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . "/../../config.php";
}

function centralTopbarSafeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function centralTopbarRoleLabel($role)
{
    $roleLabels = [
        "central_officer" => "Central Command",
        "ward_officer" => "Ward Officer",
        "inspector" => "Inspector",
        "citizen" => "Citizen",
        "team_leader" => "Team Leader",
        "assistant_team_leader" => "Assistant Team Leader"
    ];

    return $roleLabels[$role] ?? ucwords(str_replace("_", " ", (string)$role));
}

$topbarUserName = $_SESSION["user_name"] ?? "Central User";
$topbarUserRole = $_SESSION["user_role"] ?? "central_officer";
$topbarUserRoleLabel = $_SESSION["user_role_label"] ?? centralTopbarRoleLabel($topbarUserRole);

$loggedUserId = isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : 0;

if ($loggedUserId > 0) {
    $userSql = "
        SELECT user_id, user_name, user_role
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ";

    $userStmt = mysqli_prepare($conn, $userSql);

    if ($userStmt) {
        mysqli_stmt_bind_param($userStmt, "i", $loggedUserId);
        mysqli_stmt_execute($userStmt);

        $userResult = mysqli_stmt_get_result($userStmt);
        $userData = mysqli_fetch_assoc($userResult);

        if ($userData) {
            $topbarUserName = $userData["user_name"];
            $topbarUserRole = $userData["user_role"];
            $topbarUserRoleLabel = centralTopbarRoleLabel($topbarUserRole);

            $_SESSION["user_id"] = $userData["user_id"];
            $_SESSION["user_name"] = $topbarUserName;
            $_SESSION["user_role"] = $topbarUserRole;
            $_SESSION["user_role_label"] = $topbarUserRoleLabel;
        }

        mysqli_stmt_close($userStmt);
    }
}
?>

<header class="central-topbar">

    <div class="central-topbar-left">
        <button type="button" class="central-mobile-toggle" id="centralMobileToggle" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <div class="central-topbar-right">

        <button type="button" class="central-notification-btn" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <span></span>
        </button>

        <div class="central-topbar-user">
            <div class="central-topbar-user-info">
                <h4><?php echo centralTopbarSafeText($topbarUserName); ?></h4>
                <p><?php echo centralTopbarSafeText($topbarUserRoleLabel); ?></p>
            </div>

            <div class="central-topbar-avatar">
                <i class="bi bi-person"></i>
            </div>
        </div>

    </div>

</header>