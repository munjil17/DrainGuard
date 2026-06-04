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

/* ==========================================================================
   Notification Logic
   ========================================================================== */
if (!function_exists('inspectorTopbarSafeText')) {
    function inspectorTopbarSafeText($text) {
        if ($text === null) return '';
        return htmlspecialchars(trim((string)$text), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('inspector_topbar_time_ago')) {
    function inspector_topbar_time_ago($datetime) {
        if (empty($datetime)) return "Unknown";
        $time = strtotime($datetime);
        if (!$time) return "Unknown";

        $diff = time() - $time;
        if ($diff < 60) return "Just now";
        if ($diff < 3600) return floor($diff / 60) . "m ago";
        if ($diff < 86400) return floor($diff / 3600) . "h ago";
        if ($diff < 604800) return floor($diff / 86400) . "d ago";
        return date("M j", $time);
    }
}

if (!function_exists('inspector_notification_icon')) {
    function inspector_notification_icon($type) {
        $type = strtolower(trim((string)$type));
        if (in_array($type, ['verification_assigned', 'status_update', 'verified', 'rejected'])) return 'bi-file-earmark-text';
        if (in_array($type, ['system', 'alert'])) return 'bi-exclamation-triangle';
        if ($type === 'comment_reply') return 'bi-chat-dots';
        return 'bi-bell';
    }
}

if (!function_exists('inspector_notification_type_class')) {
    function inspector_notification_type_class($type) {
        $type = strtolower(trim((string)$type));
        if (in_array($type, ['verification_assigned', 'status_update', 'verified', 'rejected'])) return 'type-track';
        if (in_array($type, ['system', 'alert'])) return 'type-objection';
        if ($type === 'comment_reply') return 'type-reply';
        return 'type-system';
    }
}

$inspectorUnreadNotificationCount = 0;
$inspectorTopbarNotifications = [];

if (isset($conn) && $conn instanceof mysqli && $topbarUserId > 0) {
    $unreadSql = "SELECT COUNT(*) AS unread_total FROM inspector_notifications WHERE is_read = 0 AND recipient_user_id = ?";
    $unreadStmt = mysqli_prepare($conn, $unreadSql);
    if ($unreadStmt) {
        mysqli_stmt_bind_param($unreadStmt, "i", $topbarUserId);
        mysqli_stmt_execute($unreadStmt);
        $unreadResult = mysqli_stmt_get_result($unreadStmt);
        if ($unreadResult) {
            $unreadRow = mysqli_fetch_assoc($unreadResult);
            $inspectorUnreadNotificationCount = (int)($unreadRow['unread_total'] ?? 0);
        }
        mysqli_stmt_close($unreadStmt);
    }

    $notificationSql = "
        SELECT 
            inn.notification_id,
            inn.related_complaint_id,
            inn.notification_type,
            inn.notification_title,
            inn.notification_message,
            inn.is_read,
            inn.created_at,
            c.complaint_code
        FROM inspector_notifications inn
        LEFT JOIN complaints c ON inn.related_complaint_id = c.complaint_id
        WHERE inn.recipient_user_id = ?
        ORDER BY inn.created_at DESC, inn.notification_id DESC
        LIMIT 10
    ";
    $notificationStmt = mysqli_prepare($conn, $notificationSql);
    if ($notificationStmt) {
        mysqli_stmt_bind_param($notificationStmt, "i", $topbarUserId);
        mysqli_stmt_execute($notificationStmt);
        $notificationResult = mysqli_stmt_get_result($notificationStmt);
        if ($notificationResult) {
            while ($row = mysqli_fetch_assoc($notificationResult)) {
                $inspectorTopbarNotifications[] = $row;
            }
        }
        mysqli_stmt_close($notificationStmt);
    }
}
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

        <details class="topbar-notification">
            <summary class="notification-btn" aria-label="Notifications">
                <i class="bi bi-bell"></i>

                <?php if ($inspectorUnreadNotificationCount > 0): ?>
                    <em class="notification-count" style="width: 18px; height: 18px; border-radius: 50%; background: #EF4444; position: absolute; top: 0px; right: 0px; color: white; font-size: 10px; display: flex; align-items: center; justify-content: center; font-style: normal; font-weight: 700;">
                        <?php echo $inspectorUnreadNotificationCount > 99 ? '99+' : (int)$inspectorUnreadNotificationCount; ?>
                    </em>
                <?php endif; ?>
            </summary>

            <div class="notification-dropdown">
                <div class="notification-dropdown-header">
                    <div>
                        <h4>Notifications</h4>
                        <p>
                            <?php if ($inspectorUnreadNotificationCount > 0): ?>
                                <?php echo (int)$inspectorUnreadNotificationCount; ?> unread notification(s)
                            <?php else: ?>
                                No unread notifications
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="notification-list">
                    <?php if (count($inspectorTopbarNotifications) > 0): ?>

                        <?php foreach ($inspectorTopbarNotifications as $notification): ?>
                            <?php
                                $notificationType = strtolower(trim((string)$notification['notification_type']));
                                $notificationClass = inspector_notification_type_class($notificationType);
                                $notificationIcon = inspector_notification_icon($notificationType);
                                $isUnread = ((int)$notification['is_read'] === 0);

                                $notificationLink = 'notifications.php?read_id=' . (int)$notification['notification_id'];
                                if (!empty($notification['complaint_code'])) {
                                    if ($notification['notification_title'] === 'Inspection Required') {
                                        $notificationLink .= '&redirect=solved-cases';
                                    } else {
                                        $notificationLink .= '&redirect=tasks'; // Or whatever page handles tasks
                                    }
                                }
                            ?>

                            <a
                                class="notification-item <?php echo $isUnread ? 'unread' : 'read'; ?> <?php echo inspectorTopbarSafeText($notificationClass); ?>"
                                href="<?php echo inspectorTopbarSafeText($notificationLink); ?>"
                            >
                                <span class="notification-icon">
                                    <i class="bi <?php echo inspectorTopbarSafeText($notificationIcon); ?>"></i>
                                </span>

                                <span class="notification-content">
                                    <strong><?php echo inspectorTopbarSafeText($notification['notification_title']); ?></strong>
                                    <small><?php echo inspectorTopbarSafeText($notification['notification_message']); ?></small>
                                    <b><?php echo inspector_topbar_time_ago($notification['created_at']); ?></b>
                                </span>
                            </a>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <div class="notification-empty">
                            <i class="bi bi-bell-slash"></i>
                            <h5>All caught up!</h5>
                            <p>You have no new notifications.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="notification-dropdown-footer">
                    <a href="notifications.php">
                        View All Notifications <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </details>

        <a href="profile.php" class="topbar-user" style="text-decoration: none;">

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