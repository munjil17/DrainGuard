<?php
$userName = $_SESSION['user_name'] ?? 'Team Alpha';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Maintenance Team';

$activePage = $activePage ?? '';

$sidebarProfileImage = '';

if (isset($conn) && isset($_SESSION['user_id'])) {
    $sidebarUserId = (int)$_SESSION['user_id'];

    $profileSql = "
        SELECT 
            mtm.full_name,
            mtm.role,
            mtm.profile_image
        FROM maintenance_team_members mtm
        WHERE mtm.user_id = ?
        LIMIT 1
    ";

    $profileStmt = mysqli_prepare($conn, $profileSql);

    if ($profileStmt) {
        mysqli_stmt_bind_param($profileStmt, "i", $sidebarUserId);
        mysqli_stmt_execute($profileStmt);
        $profileResult = mysqli_stmt_get_result($profileStmt);

        if ($profileResult && mysqli_num_rows($profileResult) > 0) {
            $profileRow = mysqli_fetch_assoc($profileResult);

            if (!empty($profileRow['full_name'])) {
                $userName = $profileRow['full_name'];
            }

            if (!empty($profileRow['role'])) {
                if ($profileRow['role'] === 'team_leader') {
                    $userRoleLabel = 'Team Leader';
                } elseif ($profileRow['role'] === 'assistant_team_leader') {
                    $userRoleLabel = 'Assistant Team Leader';
                } elseif ($profileRow['role'] === 'worker') {
                    $userRoleLabel = 'Worker';
                }
            }

            if (!empty($profileRow['profile_image'])) {
                $sidebarProfileImage = "../../" . $profileRow['profile_image'];
            }
        }

        mysqli_stmt_close($profileStmt);
    }
}
?>

<aside class="maintenance-sidebar">

    <div class="sidebar-brand">

        <div class="brand-icon">
            <i class="bi bi-droplet"></i>
        </div>

        <div class="brand-text">
            <h2>DrainGuard</h2>
            <p>Smart Drainage</p>
        </div>

    </div>

    <div class="sidebar-role">

        <div class="sidebar-role-top">
            <span class="sidebar-role-dot"></span>
            <small>Field Execution Access</small>
        </div>

        <strong>Maintenance Team</strong>

    </div>

    <nav class="sidebar-menu">

        <a href="dashboard.php"
           class="menu-link <?php echo ($activePage === 'dashboard') ? 'active' : ''; ?>">
            <i class="bi bi-grid"></i>
            <span>Dashboard</span>
        </a>

        <a href="assigned-tasks.php" class="menu-link">
            <i class="bi bi-list-check"></i>
            <span>Assigned Tasks</span>
        </a>

        <a href="in-progress-work.php" class="menu-link">
            <i class="bi bi-tools"></i>
            <span>In Progress Work</span>
        </a>

        <a href="upload-completion-proof.php" class="menu-link">
            <i class="bi bi-upload"></i>
            <span>Upload Completion Proof</span>
        </a>

        <a href="task-history.php" class="menu-link">
            <i class="bi bi-clock-history"></i>
            <span>Task History</span>
        </a>
        
        <a href="feedback.php" class="menu-link <?php echo ($activePage === 'feedback') ? 'active' : ''; ?>">
            <i class="bi bi-star"></i>
            <span>Feedback</span>
        </a>

        <a href="delayed-tasks.php" class="menu-link">
            <i class="bi bi-exclamation-circle"></i>
            <span>Delayed Tasks</span>
        </a>

        <a href="drain-area-reference.php" class="menu-link">
            <i class="bi bi-geo-alt"></i>
            <span>Drain / Area Reference</span>
        </a>

        <a href="settings.php" class="menu-link">
            <i class="bi bi-person-vcard"></i>
            <span>Team Profile</span>
        </a>

    </nav>

    <div class="sidebar-user">

        <div class="user-profile-row">

            <div class="user-avatar">
                <?php if (!empty($sidebarProfileImage)): ?>
                    <img src="<?php echo htmlspecialchars($sidebarProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile">
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
                Profile
            </a>

            <a href="../../auth/logout.php" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i>
                Logout
            </a>

        </div>

    </div>

</aside>