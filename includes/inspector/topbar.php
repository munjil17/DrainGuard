<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   Database Connection Safety
========================= */

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

/* =========================
   Default Values
========================= */

$pageTitle = $pageTitle ?? 'Inspector Dashboard';

$topbarUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$topbarUserName = $_SESSION['user_name'] ?? 'Inspector';
$topbarUserRole = $_SESSION['user_role_label'] ?? 'Quality Control';
$topbarProfileImage = '';

/* =========================
   Fetch Inspector Info
========================= */

if ($topbarUserId > 0 && isset($conn) && $conn) {
    $topbarSql = "
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

    $topbarStmt = mysqli_prepare($conn, $topbarSql);

    if ($topbarStmt) {
        mysqli_stmt_bind_param($topbarStmt, "i", $topbarUserId);
        mysqli_stmt_execute($topbarStmt);

        $topbarResult = mysqli_stmt_get_result($topbarStmt);
        $topbarInspector = $topbarResult ? mysqli_fetch_assoc($topbarResult) : null;

        mysqli_stmt_close($topbarStmt);

        if ($topbarInspector) {
            $topbarUserName = !empty($topbarInspector['full_name'])
                ? $topbarInspector['full_name']
                : ($topbarInspector['user_name'] ?? 'Inspector');

            $topbarUserRole = !empty($topbarInspector['designation'])
                ? $topbarInspector['designation']
                : 'Quality Control';

            $topbarProfileImage = trim((string) ($topbarInspector['profile_image'] ?? ''));

            $_SESSION['user_name'] = $topbarUserName;
            $_SESSION['user_role_label'] = $topbarUserRole;
        }
    }
}

/* =========================
   Helper Functions
========================= */

function inspectorTopbarText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function inspectorTopbarImagePath($path)
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

$topbarProfileImageSrc = inspectorTopbarImagePath($topbarProfileImage);
?>

<header class="topbar">

    <div class="topbar-left">

        <button type="button" class="mobile-toggle" aria-label="Open Sidebar">
            <i class="bi bi-list"></i>
        </button>

        <div class="topbar-title">
            <h3><?php echo inspectorTopbarText($pageTitle); ?></h3>
            <p>Quality control and complaint verification panel</p>
        </div>

    </div>

    <div class="topbar-right">

        <button type="button" class="notification-btn" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <span></span>
        </button>

        <a href="profile.php" class="topbar-user">

            <div class="topbar-user-info">
                <h4><?php echo inspectorTopbarText($topbarUserName); ?></h4>
                <p><?php echo inspectorTopbarText($topbarUserRole); ?></p>
            </div>

            <div class="topbar-avatar">
                <?php if ($topbarProfileImageSrc !== ''): ?>
                    <img
                        src="<?php echo inspectorTopbarText($topbarProfileImageSrc); ?>"
                        alt="Inspector profile image">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </div>

        </a>

    </div>

</header>