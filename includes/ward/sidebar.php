<?php
$userName = $_SESSION['user_name'] ?? 'Ward Officer';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Ward Operations';
$activePage = $activePage ?? '';
?>

<div class="mobile-top-bar d-md-none p-3 text-white d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-droplet text-info fs-3"></i>
        <span class="fw-bold fs-5 text-white">DrainGuard</span>
    </div>
    <button class="btn btn-outline-light border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
        <i class="bi bi-list fs-2"></i>
    </button>
</div>

<aside class="ward-sidebar offcanvas-md offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
    
    <div class="sidebar-brand">
        <div class="brand-icon">
            <i class="bi bi-droplet"></i>
        </div>
        <div class="brand-text">
            <h2>DrainGuard</h2>
            <p>Smart Drainage</p>
        </div>
        <button type="button" class="btn-close btn-close-white d-md-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebarOffcanvas"></button>
    </div>

    <div class="sidebar-role">
        <div class="sidebar-role-top">
            <span class="sidebar-role-dot"></span>
            <small>Ward Officer Access</small>
        </div>
        <strong>Ward Operations</strong>
    </div>

    <nav class="sidebar-menu">
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
            <i class="bi bi-clock"></i>
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
                <i class="bi bi-person"></i>
            </div>
            <div class="user-info">
                <h4><?php echo htmlspecialchars($userName); ?></h4>
                <p><?php echo htmlspecialchars($userRoleLabel); ?></p>
            </div>
        </div>

        <div class="user-actions">
            <a href="profile.php" class="profile-btn">
                <i class="bi bi-person"></i> Profile
            </a>
            <a href="../../auth/logout.php" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>

</aside>