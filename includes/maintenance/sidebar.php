<?php
$userName = $_SESSION['user_name'] ?? 'Team Alpha';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Maintenance Team';

$activePage = $activePage ?? '';
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

        <a href="delayed-tasks.php" class="menu-link">
            <i class="bi bi-exclamation-circle"></i>
            <span>Delayed Tasks</span>
        </a>

        <a href="drain-area-reference.php" class="menu-link">
            <i class="bi bi-geo-alt"></i>
            <span>Drain / Area Reference</span>
        </a>

        <a href="settings.php" class="menu-link">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>

    </nav>

    <div class="sidebar-user">

        <div class="user-profile-row">

            <div class="user-avatar">
                <i class="bi bi-person"></i>
            </div>

            <div class="user-info">
                <h4><?php echo $userName; ?></h4>
                <p><?php echo $userRoleLabel; ?></p>
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