<?php
$userName = $_SESSION['user_name'] ?? 'Inspector Karim';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Inspector Verification';

$activePage = $activePage ?? '';
?>

<aside class="inspector-sidebar">

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
            <small>Inspector Verification Access</small>
        </div>

        <strong>Quality Control</strong>

    </div>

    <nav class="sidebar-menu">

        <a href="dashboard.php"
        class="menu-link <?php echo ($activePage === 'dashboard') ? 'active' : ''; ?>">

            <i class="bi bi-grid"></i>
            <span>Dashboard</span>

        </a>

        <a href="#" class="menu-link">
            <i class="bi bi-list-check"></i>
            <span>Inspection Queue</span>
        </a>

        <a href="#" class="menu-link">
            <i class="bi bi-check-circle"></i>
            <span>Solved by Team Cases</span>
        </a>

        <a href="#" class="menu-link">
            <i class="bi bi-image"></i>
            <span>Before / After Review</span>
        </a>

        <a href="#" class="menu-link">
            <i class="bi bi-chat-left"></i>
            <span>Citizen Objections</span>
        </a>

        <a href="#" class="menu-link">
            <i class="bi bi-flag"></i>
            <span>False Completion Reports</span>
        </a>

        <a href="#" class="menu-link">
            <i class="bi bi-arrow-counterclockwise"></i>
            <span>Reopened Decisions</span>
        </a>

        <a href="#" class="menu-link">
            <i class="bi bi-shield-check"></i>
            <span>Inspection Logs</span>
        </a>

        <a href="#" class="menu-link">
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

            <a href="#" class="profile-btn">
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