<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . "/../../config.php";
}

$activePage = $activePage ?? "";
$baseUrl = "/DrainGuard/pages/central";

function centralSidebarSafeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function centralSidebarRoleLabel($role)
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

$userName = $_SESSION["user_name"] ?? "Central User";
$userRole = $_SESSION["user_role"] ?? "central_officer";
$userRoleLabel = $_SESSION["user_role_label"] ?? centralSidebarRoleLabel($userRole);

$loggedUserId = isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : 0;

if ($loggedUserId > 0) {
    $userSql = "
        SELECT user_id, user_name, user_mail, user_role
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
            $userName = $userData["user_name"];
            $userRole = $userData["user_role"];
            $userRoleLabel = centralSidebarRoleLabel($userRole);

            $_SESSION["user_id"] = $userData["user_id"];
            $_SESSION["user_name"] = $userData["user_name"];
            $_SESSION["user_email"] = $userData["user_mail"];
            $_SESSION["user_role"] = $userData["user_role"];
            $_SESSION["user_role_label"] = $userRoleLabel;
        }

        mysqli_stmt_close($userStmt);
    }
}
?>

<aside class="dg-central-sidebar" id="centralSidebar">

    <div class="dg-central-sidebar-brand">
        <div class="dg-central-brand-icon">
            <i class="bi bi-droplet"></i>
        </div>

        <div class="dg-central-brand-text">
            <h2>DrainGuard</h2>
            <p>Smart Drainage</p>
        </div>
    </div>

    <div class="dg-central-sidebar-role">
        <div class="dg-central-role-label">
            <span></span>
            <small>Central Control Access</small>
        </div>

        <strong>Central Command</strong>
    </div>

    <nav class="dg-central-menu">

        <a href="<?php echo $baseUrl; ?>/dashboard.php" class="dg-central-menu-link <?php echo ($activePage === 'dashboard') ? 'active' : ''; ?>">
            <i class="bi bi-grid"></i>
            <span>Dashboard</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/user-management.php" class="dg-central-menu-link <?php echo ($activePage === 'user-management') ? 'active' : ''; ?>">
            <i class="bi bi-people"></i>
            <span>User Management</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/ward-area.php" class="dg-central-menu-link <?php echo ($activePage === 'ward-area') ? 'active' : ''; ?>">
            <i class="bi bi-building"></i>
            <span>Ward & Area</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/drain-records.php" class="dg-central-menu-link <?php echo ($activePage === 'drain-records') ? 'active' : ''; ?>">
            <i class="bi bi-clipboard-data"></i>
            <span>Drain Records</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/complaints.php" class="dg-central-menu-link <?php echo ($activePage === 'complaints') ? 'active' : ''; ?>">
            <i class="bi bi-file-earmark-text"></i>
            <span>Complaints</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/routing-assignment.php" class="dg-central-menu-link <?php echo ($activePage === 'routing-assignment') ? 'active' : ''; ?>">
            <i class="bi bi-arrow-up-circle"></i>
            <span>Ward Verification</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/high-risk-zones.php" class="dg-central-menu-link <?php echo ($activePage === 'high-risk-zones') ? 'active' : ''; ?>">
            <i class="bi bi-exclamation-triangle"></i>
            <span>High Risk Zones</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/reports.php" class="dg-central-menu-link <?php echo ($activePage === 'reports') ? 'active' : ''; ?>">
            <i class="bi bi-bar-chart"></i>
            <span>Reports</span>
        </a>

        <a href="<?php echo $baseUrl; ?>/settings.php" class="dg-central-menu-link <?php echo ($activePage === 'settings') ? 'active' : ''; ?>">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>

    </nav>

    <div class="dg-central-sidebar-user">

        <div class="dg-central-user-row">
            <div class="dg-central-user-avatar">
                <i class="bi bi-person"></i>
            </div>

            <div class="dg-central-user-info">
                <h4><?php echo centralSidebarSafeText($userName); ?></h4>
                <p><?php echo centralSidebarSafeText($userRoleLabel); ?></p>
            </div>
        </div>

        <div class="dg-central-user-actions">
            <a href="<?php echo $baseUrl; ?>/profile.php" class="dg-central-profile-btn">
                <i class="bi bi-person"></i>
                <span>Profile</span>
            </a>

            <a href="/DrainGuard/auth/logout.php" class="dg-central-logout-btn">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </div>

    </div>

</aside>

<div class="dg-central-sidebar-overlay" id="centralSidebarOverlay"></div>