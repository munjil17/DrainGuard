<?php
$userName = $_SESSION['user_name'] ?? 'Ward Officer';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Ward Operations';
?>

<header class="ward-topbar">

    <div class="topbar-left">
        <button class="mobile-toggle" id="mobileToggle" type="button">
            <i class="bi bi-list"></i>
        </button>

        <div class="topbar-search">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search complaints, drains, areas...">
        </div>
    </div>

    <div class="topbar-right">
        <button class="notification-btn" type="button">
            <i class="bi bi-bell"></i>
            <span></span>
        </button>

        <div class="topbar-user">
            <div>
                <h4><?php echo htmlspecialchars($userName); ?></h4>
                <p><?php echo htmlspecialchars($userRoleLabel); ?></p>
            </div>

            <div class="topbar-avatar">
                <i class="bi bi-person"></i>
            </div>
        </div>
    </div>

</header>