<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) && isset($connection)) {
    $conn = $connection;
}

if (!isset($conn)) {
    $configPath = __DIR__ . '/../../config.php';

    if (file_exists($configPath)) {
        require_once $configPath;
    }

    if (!isset($conn) && isset($connection)) {
        $conn = $connection;
    }
}

$activePage = $activePage ?? '';

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$userName = $_SESSION['user_name'] ?? 'Inspector';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Inspector Verification';
$userProfileImage = '';

if ($userId > 0 && isset($conn) && $conn) {
    $sidebarSql = "
        SELECT
            i.full_name,
            i.designation,
            i.profile_image,
            u.user_name
        FROM inspectors i
        INNER JOIN users u ON u.user_id = i.user_id
        WHERE i.user_id = ?
        LIMIT 1
    ";

    $sidebarStmt = mysqli_prepare($conn, $sidebarSql);

    if ($sidebarStmt) {
        mysqli_stmt_bind_param($sidebarStmt, "i", $userId);
        mysqli_stmt_execute($sidebarStmt);

        $sidebarResult = mysqli_stmt_get_result($sidebarStmt);
        $sidebarInspector = $sidebarResult ? mysqli_fetch_assoc($sidebarResult) : null;

        mysqli_stmt_close($sidebarStmt);

        if ($sidebarInspector) {
            $userName = !empty($sidebarInspector['full_name'])
                ? $sidebarInspector['full_name']
                : ($sidebarInspector['user_name'] ?? 'Inspector');

            $userRoleLabel = !empty($sidebarInspector['designation'])
                ? $sidebarInspector['designation']
                : 'Inspector Verification';

            $userProfileImage = trim((string) ($sidebarInspector['profile_image'] ?? ''));

            $_SESSION['user_name'] = $userName;
            $_SESSION['user_role_label'] = $userRoleLabel;
        }
    }
}

function inspectorActive($pageName, $activePage)
{
    return ($activePage === $pageName) ? 'active' : '';
}

function inspectorSidebarText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function inspectorSidebarImagePath($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }

    if (substr($path, 0, 6) === '../../') {
        return $path;
    }

    return '../../' . ltrim($path, '/');
}

$sidebarProfileImageSrc = inspectorSidebarImagePath($userProfileImage);
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

        <strong><?php echo inspectorSidebarText($userRoleLabel); ?></strong>

    </div>

    <nav class="sidebar-menu">

        <a href="dashboard.php" class="menu-link <?php echo inspectorActive('dashboard', $activePage); ?>">
            <i class="bi bi-grid"></i>
            <span>Dashboard</span>
        </a>

        <a href="solved-cases.php" class="menu-link <?php echo inspectorActive('solved-cases', $activePage); ?>">
            <i class="bi bi-check-circle"></i>
            <span>Solved by Team Cases</span>
        </a>

        <a href="before-after-review.php" class="menu-link <?php echo inspectorActive('before-after-review', $activePage); ?>">
            <i class="bi bi-image"></i>
            <span>Before / After Review</span>
        </a>

        <a href="inspection-queue.php" class="menu-link <?php echo inspectorActive('inspection-queue', $activePage); ?>">
            <i class="bi bi-list-check"></i>
            <span>Inspection Queue</span>
        </a>

        <a href="citizen-objections.php" class="menu-link <?php echo inspectorActive('citizen-objections', $activePage); ?>">
            <i class="bi bi-chat-left"></i>
            <span>Citizen Objections</span>
        </a>

        <a href="false-completion-reports.php" class="menu-link <?php echo inspectorActive('false-completion-reports', $activePage); ?>">
            <i class="bi bi-flag"></i>
            <span>False Completion Reports</span>
        </a>

        <a href="inspection-logs.php" class="menu-link <?php echo inspectorActive('inspection-logs', $activePage); ?>">
            <i class="bi bi-shield-check"></i>
            <span>Inspection Logs</span>
        </a>



    </nav>

    <div class="sidebar-user">

        <div class="user-profile-row">

            <div class="user-avatar">
                <?php if ($sidebarProfileImageSrc !== ''): ?>
                    <img src="<?php echo inspectorSidebarText($sidebarProfileImageSrc); ?>" alt="Inspector profile image">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </div>

            <div class="user-info">
                <h4><?php echo inspectorSidebarText($userName); ?></h4>
                <p><?php echo inspectorSidebarText($userRoleLabel); ?></p>
            </div>

        </div>

        <div class="user-actions">

            <a href="profile.php" class="profile-btn <?php echo inspectorActive('profile', $activePage); ?>">
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