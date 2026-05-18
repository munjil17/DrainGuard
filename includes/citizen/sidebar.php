<?php
$userName = $_SESSION['user_name'] ?? 'Citizen User';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Public Portal';
$activePage = $activePage ?? '';

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$sidebarPhotoPath = "";

/*
    Sidebar profile photo source:
    1. First try $_SESSION['profile_photo']
    2. If session empty, fetch from citizens table
*/

if (!empty($_SESSION['profile_photo'])) {
    $sidebarPhotoPath = "../../" . $_SESSION['profile_photo'];
} elseif ($userId > 0 && isset($conn)) {

    $photoColumns = [
        "profile_photo",
        "profile_image",
        "citizen_photo",
        "citizen_image",
        "photo",
        "image"
    ];

    $foundPhotoColumn = "";

    foreach ($photoColumns as $column) {
        $checkSql = "
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'citizens'
            AND COLUMN_NAME = ?
            LIMIT 1
        ";

        $checkStmt = mysqli_prepare($conn, $checkSql);

        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, "s", $column);
            mysqli_stmt_execute($checkStmt);

            $checkResult = mysqli_stmt_get_result($checkStmt);

            if ($checkResult && mysqli_num_rows($checkResult) > 0) {
                $foundPhotoColumn = $column;
                mysqli_stmt_close($checkStmt);
                break;
            }

            mysqli_stmt_close($checkStmt);
        }
    }

    if ($foundPhotoColumn !== "") {
        $photoSql = "
            SELECT {$foundPhotoColumn} AS profile_photo
            FROM citizens
            WHERE user_id = ?
            LIMIT 1
        ";

        $photoStmt = mysqli_prepare($conn, $photoSql);

        if ($photoStmt) {
            mysqli_stmt_bind_param($photoStmt, "i", $userId);
            mysqli_stmt_execute($photoStmt);

            $photoResult = mysqli_stmt_get_result($photoStmt);

            if ($photoResult && mysqli_num_rows($photoResult) === 1) {
                $photoRow = mysqli_fetch_assoc($photoResult);
                $dbPhotoPath = trim((string)($photoRow['profile_photo'] ?? ''));

                if ($dbPhotoPath !== "") {
                    $_SESSION['profile_photo'] = $dbPhotoPath;
                    $sidebarPhotoPath = "../../" . $dbPhotoPath;
                }
            }

            mysqli_stmt_close($photoStmt);
        }
    }
}
?>

<aside class="citizen-sidebar" id="sidebar">

    <div class="sidebar-brand">
        <div class="brand-icon">
            <i class="bi bi-droplet"></i>
        </div>

        <div>
            <h2>DrainGuard</h2>
            <p>Smart Drainage</p>
        </div>
    </div>

    <div class="sidebar-role">
        <span></span>
        <small>Citizen Access</small>
        <strong>Public Portal</strong>
    </div>

    <nav class="sidebar-menu">

        <a href="dashboard.php" class="menu-link <?php echo ($activePage === 'dashboard') ? 'active' : ''; ?>">
            <i class="bi bi-grid"></i>
            <span>Dashboard</span>
        </a>

        <a href="submit-complaint.php" class="menu-link <?php echo ($activePage === 'submit-complaint') ? 'active' : ''; ?>">
            <i class="bi bi-plus-lg"></i>
            <span>Submit Complaint</span>
        </a>

        <a href="public-board.php" class="menu-link <?php echo ($activePage === 'public-board') ? 'active' : ''; ?>">
            <i class="bi bi-eye"></i>
            <span>Public Complaint Board</span>
        </a>

        <a href="my-complaints.php" class="menu-link <?php echo ($activePage === 'my-complaints') ? 'active' : ''; ?>">
            <i class="bi bi-file-earmark-text"></i>
            <span>My Complaints</span>
        </a>

        <a href="track-complaint.php" class="menu-link <?php echo ($activePage === 'track-complaint') ? 'active' : ''; ?>">
            <i class="bi bi-geo-alt"></i>
            <span>Track Complaint</span>
        </a>

        <a href="high-risk-areas.php" class="menu-link <?php echo ($activePage === 'high-risk-areas') ? 'active' : ''; ?>">
            <i class="bi bi-exclamation-triangle"></i>
            <span>High Risk Areas</span>
        </a>

        <a href="feedback-reopen.php" class="menu-link <?php echo ($activePage === 'feedback-reopen') ? 'active' : ''; ?>">
            <i class="bi bi-chat-left"></i>
            <span>Feedback / Reopen</span>
        </a>

        <a href="settings.php" class="menu-link <?php echo ($activePage === 'settings') ? 'active' : ''; ?>">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>

    </nav>

    <div class="sidebar-user">

        <div class="user-profile-row">

            <a href="profile.php" class="user-avatar" title="View Profile">
                <?php if ($sidebarPhotoPath !== ""): ?>
                    <img src="<?php echo htmlspecialchars($sidebarPhotoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Photo">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </a>

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