<?php
$userName = $_SESSION['user_name'] ?? 'Ward Officer';
$userRoleLabel = 'Ward Officer';
$activePage = $activePage ?? '';
$sidebarProfileImage = "";

function ward_sidebar_profile_image_path($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);

    if (preg_match("/^https?:\/\//i", $path)) {
        return $path;
    }

    if (str_starts_with($path, "../../")) {
        return $path;
    }

    if (str_starts_with($path, "assets/")) {
        return "../../" . $path;
    }

    if (str_starts_with($path, "uploads/")) {
        return "../../assets/" . $path;
    }

    if (!str_contains($path, "/")) {
        return "../../assets/uploads/ward_officers/" . $path;
    }

    return "../../" . ltrim($path, "/");
}

if (isset($conn) && $conn && isset($_SESSION["user_id"])) {
    $sidebarUserId = (int)$_SESSION["user_id"];

    $hasProfileImageColumn = false;
    $columnCheckResult = mysqli_query($conn, "SHOW COLUMNS FROM ward_officers LIKE 'profile_image'");

    if ($columnCheckResult && mysqli_num_rows($columnCheckResult) > 0) {
        $hasProfileImageColumn = true;
    }

    $profileImageSelect = $hasProfileImageColumn ? ", profile_image" : ", NULL AS profile_image";

    $sidebarSql = "
        SELECT
            full_name,
            designation
            $profileImageSelect
        FROM ward_officers
        WHERE user_id = ?
        LIMIT 1
    ";

    $sidebarStmt = mysqli_prepare($conn, $sidebarSql);

    if ($sidebarStmt) {
        mysqli_stmt_bind_param($sidebarStmt, "i", $sidebarUserId);
        mysqli_stmt_execute($sidebarStmt);

        $sidebarResult = mysqli_stmt_get_result($sidebarStmt);
        $sidebarRow = $sidebarResult ? mysqli_fetch_assoc($sidebarResult) : null;

        if ($sidebarRow) {
            if (!empty($sidebarRow["full_name"])) {
                $userName = $sidebarRow["full_name"];
            }

            if (!empty($sidebarRow["designation"])) {
                $userRoleLabel = $sidebarRow["designation"];
            }

            if (!empty($sidebarRow["profile_image"])) {
                $sidebarProfileImage = ward_sidebar_profile_image_path($sidebarRow["profile_image"]);
            }

            $_SESSION["user_name"] = $userName;
            $_SESSION["user_role_label"] = $userRoleLabel;
        }

        mysqli_stmt_close($sidebarStmt);
    }
}
?>

<aside class="ward-sidebar offcanvas-lg offcanvas-start"
       tabindex="-1"
       id="sidebarOffcanvas"
       aria-labelledby="wardSidebarLabel">

    <div class="sidebar-brand">
        <div class="brand-icon">
            <i class="bi bi-droplet"></i>
        </div>

        <div class="brand-text">
            <h2 id="wardSidebarLabel">DrainGuard</h2>
            <p>Smart Drainage</p>
        </div>

        <button type="button"
                class="btn-close btn-close-white d-lg-none ms-auto"
                data-bs-dismiss="offcanvas"
                data-bs-target="#sidebarOffcanvas"
                aria-label="Close sidebar"></button>
    </div>

    <div class="sidebar-role">
        <div class="sidebar-role-top">
            <span class="sidebar-role-dot"></span>
            <small>Ward Officer Access</small>
        </div>

        <strong>Ward Operations</strong>
    </div>

    <nav class="sidebar-menu" aria-label="Ward Officer Navigation">

        <a href="dashboard.php" class="menu-link <?php echo ($activePage === 'dashboard') ? 'active' : ''; ?>">
            <i class="bi bi-grid"></i>
            <span>Dashboard</span>
        </a>

        <a href="ward-complaints.php" class="menu-link <?php echo ($activePage === 'ward-complaints') ? 'active' : ''; ?>">
            <i class="bi bi-file-earmark-text"></i>
            <span>Ward Complaints</span>
        </a>

        <a href="verification-queue.php" class="menu-link <?php echo ($activePage === 'verification-queue') ? 'active' : ''; ?>">
            <i class="bi bi-check2-circle"></i>
            <span>Verification Queue</span>
        </a>

        <a href="local-team-assignment.php" class="menu-link <?php echo ($activePage === 'local-team-assignment') ? 'active' : ''; ?>">
            <i class="bi bi-people"></i>
            <span>Local Team Assignment</span>
        </a>

        <a href="in-progress-cases.php" class="menu-link <?php echo ($activePage === 'in-progress-cases') ? 'active' : ''; ?>">
            <i class="bi bi-clock-history"></i>
            <span>In Progress Cases</span>
        </a>

        <a href="reopened-disputed.php" class="menu-link <?php echo ($activePage === 'reopened-disputed') ? 'active' : ''; ?>">
            <i class="bi bi-exclamation-triangle"></i>
            <span>Reopened / Disputed</span>
        </a>

        <a href="ward-risk-zones.php" class="menu-link <?php echo ($activePage === 'ward-risk-zones') ? 'active' : ''; ?>">
            <i class="bi bi-geo-alt"></i>
            <span>Ward Risk Zones</span>
        </a>

        <a href="local-reports.php" class="menu-link <?php echo ($activePage === 'local-reports') ? 'active' : ''; ?>">
            <i class="bi bi-bar-chart"></i>
            <span>Local Reports</span>
        </a>

        <a href="settings.php" class="menu-link <?php echo ($activePage === 'settings') ? 'active' : ''; ?>">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>

    </nav>

    <div class="sidebar-user">
        <div class="user-profile-row">
            <div class="user-avatar">
                <?php if ($sidebarProfileImage !== ""): ?>
                    <img src="<?php echo htmlspecialchars($sidebarProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Ward Officer Profile Photo">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </div>

            <div class="user-info">
                <h4><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></h4>
                <p><?php echo htmlspecialchars($userRoleLabel, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>

        <div class="user-actions">
            <a href="profile.php" class="profile-btn">
                <i class="bi bi-person"></i>
                <span>Profile</span>
            </a>

            <a href="../../auth/logout.php" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

</aside>