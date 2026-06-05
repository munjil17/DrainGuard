<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . "/../../config.php";
}

function centralTopbarSafeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function centralTopbarRoleLabel($role)
{
    $roleLabels = [
        "central_officer" => "Central Command",
        "ward_officer" => "Ward Officer",
        "inspector" => "Inspector",
        "citizen" => "Citizen",
        "team_leader" => "Team Leader",
        "assistant_team_leader" => "Assistant Team Leader"
    ];

    return $roleLabels[$role] ?? ucwords(str_replace("_", " ", (string)$role));
}

function centralTopbarCleanPath($path)
{
    $path = str_replace("\\", "/", (string)$path);
    $path = ltrim($path, "/");

    return $path;
}

$topbarUserName = $_SESSION["user_name"] ?? "Central User";
$topbarUserRole = $_SESSION["user_role"] ?? "central_officer";
$topbarUserRoleLabel = $_SESSION["user_role_label"] ?? centralTopbarRoleLabel($topbarUserRole);
$topbarProfilePicture = "";
$topbarInitial = strtoupper(substr($topbarUserName, 0, 1));

$loggedUserId = isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : 0;

if ($loggedUserId > 0) {
    $userSql = "
        SELECT
            u.user_id,
            u.user_name,
            u.user_mail,
            u.user_role,
            co.full_name,
            co.designation,
            co.profile_picture
        FROM users u
        LEFT JOIN central_officers co
            ON u.user_id = co.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ";

    $userStmt = mysqli_prepare($conn, $userSql);

    if ($userStmt) {
        mysqli_stmt_bind_param($userStmt, "i", $loggedUserId);
        mysqli_stmt_execute($userStmt);

        $userResult = mysqli_stmt_get_result($userStmt);
        $userData = $userResult ? mysqli_fetch_assoc($userResult) : null;

        if ($userData) {
            $topbarUserName = !empty($userData["full_name"]) ? $userData["full_name"] : $userData["user_name"];
            $topbarUserRole = $userData["user_role"];
            $topbarUserRoleLabel = !empty($userData["designation"])
                ? $userData["designation"]
                : centralTopbarRoleLabel($topbarUserRole);

            if (!empty($userData["profile_picture"])) {
                $cleanProfilePath = centralTopbarCleanPath($userData["profile_picture"]);
                $topbarProfilePicture = "/DrainGuard/" . $cleanProfilePath;
            }

            $_SESSION["user_id"] = $userData["user_id"];
            $_SESSION["user_name"] = $topbarUserName;
            $_SESSION["user_email"] = $userData["user_mail"];
            $_SESSION["user_role"] = $userData["user_role"];
            $_SESSION["user_role_label"] = $topbarUserRoleLabel;
        }

        mysqli_stmt_close($userStmt);
    }
}

$topbarInitial = strtoupper(substr($topbarUserName, 0, 1));
?>

<?php
if (!function_exists('central_topbar_time_ago')) {
    function central_topbar_time_ago($datetime) {
        if (empty($datetime)) return 'Just now';
        $timestamp = strtotime($datetime);
        if (!$timestamp) return 'Just now';
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
        if ($diff < 604800) return floor($diff / 86400) . ' day ago';
        return date('M d, Y', $timestamp);
    }
}

if (!function_exists('central_notification_icon')) {
    function central_notification_icon($type) {
        $type = strtolower(trim((string)$type));
        if (in_array($type, ['complaint_submitted', 'complaint_received', 'complaint_rejected'])) return 'bi-file-earmark-text';
        if (in_array($type, ['inspector_report', 'team_update'])) return 'bi-clipboard-check';
        if ($type === 'comment_reply') return 'bi-chat-dots';
        return 'bi-bell';
    }
}

if (!function_exists('central_notification_type_class')) {
    function central_notification_type_class($type) {
        $type = strtolower(trim((string)$type));
        if (in_array($type, ['complaint_submitted', 'complaint_received', 'complaint_rejected'])) return 'type-track';
        if (in_array($type, ['inspector_report', 'team_update'])) return 'type-objection';
        if ($type === 'comment_reply') return 'type-reply';
        return 'type-system';
    }
}

$centralUnreadNotificationCount = 0;
$centralTopbarNotifications = [];

if ($loggedUserId > 0 && isset($conn) && $conn instanceof mysqli) {
    $unreadSql = "SELECT COUNT(*) AS unread_total FROM central_notifications WHERE is_read = 0 AND recipient_user_id = $loggedUserId";
    $unreadResult = mysqli_query($conn, $unreadSql);
    if ($unreadResult) {
        $unreadRow = mysqli_fetch_assoc($unreadResult);
        $centralUnreadNotificationCount = (int)($unreadRow['unread_total'] ?? 0);
    }

    $notificationSql = "
        SELECT 
            cn.notification_id,
            cn.related_complaint_id,
            cn.notification_type,
            cn.notification_title,
            cn.notification_message,
            cn.is_read,
            cn.created_at,
            c.complaint_code
        FROM central_notifications cn
        LEFT JOIN complaints c ON cn.related_complaint_id = c.complaint_id
        WHERE cn.recipient_user_id = $loggedUserId
        ORDER BY cn.created_at DESC, cn.notification_id DESC
        LIMIT 10
    ";
    $notificationResult = mysqli_query($conn, $notificationSql);
    if ($notificationResult) {
        while ($row = mysqli_fetch_assoc($notificationResult)) {
            $centralTopbarNotifications[] = $row;
        }
    }
}
?>

<header class="central-topbar">

    <div class="central-topbar-left">
        <button type="button" class="central-mobile-toggle" id="centralMobileToggle" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <div class="central-topbar-right">

        <details class="topbar-notification">
            <summary class="notification-btn" aria-label="Notifications">
                <i class="bi bi-bell"></i>

                <?php if ($centralUnreadNotificationCount > 0): ?>
                    <em class="notification-count">
                        <?php echo $centralUnreadNotificationCount > 99 ? '99+' : (int)$centralUnreadNotificationCount; ?>
                    </em>
                <?php endif; ?>
            </summary>

            <div class="notification-dropdown">
                <div class="notification-dropdown-header">
                    <div>
                        <h4>Notifications</h4>
                        <p>
                            <?php if ($centralUnreadNotificationCount > 0): ?>
                                <?php echo (int)$centralUnreadNotificationCount; ?> unread notification(s)
                            <?php else: ?>
                                No unread notifications
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="notification-list">
                    <?php if (count($centralTopbarNotifications) > 0): ?>

                        <?php foreach ($centralTopbarNotifications as $notification): ?>
                            <?php
                                $notificationType = strtolower(trim((string)$notification['notification_type']));
                                $notificationClass = central_notification_type_class($notificationType);
                                $notificationIcon = central_notification_icon($notificationType);
                                $isUnread = ((int)$notification['is_read'] === 0);

                                $notificationLink = 'notifications.php?read_id=' . (int)$notification['notification_id'];
                                if (!empty($notification['complaint_code'])) {
                                    if ($notificationType === 'comment_reply') {
                                        $notificationLink .= '&redirect=discussion';
                                    } else {
                                        $notificationLink .= '&redirect=complaints';
                                    }
                                }
                            ?>

                            <a
                                class="notification-item <?php echo $isUnread ? 'unread' : 'read'; ?> <?php echo centralTopbarSafeText($notificationClass); ?>"
                                href="<?php echo centralTopbarSafeText($notificationLink); ?>"
                            >
                                <span class="notification-icon">
                                    <i class="bi <?php echo centralTopbarSafeText($notificationIcon); ?>"></i>
                                </span>

                                <span class="notification-content">
                                    <strong>
                                        <?php echo centralTopbarSafeText($notification['notification_title'] ?? 'Notification'); ?>
                                    </strong>
                                    <small>
                                        <?php echo centralTopbarSafeText($notification['notification_message'] ?? ''); ?>
                                    </small>
                                    <b>
                                        <?php echo centralTopbarSafeText(central_topbar_time_ago($notification['created_at'] ?? null)); ?>
                                    </b>
                                </span>
                            </a>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="notification-empty">
                            <i class="bi bi-bell-slash"></i>
                            <h5>No notifications yet</h5>
                            <p>Updates on complaints and discussions will appear here.</p>
                        </div>

                    <?php endif; ?>
                </div>

                <div class="notification-dropdown-footer">
                    <a href="notifications.php">
                        View All Notifications
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </details>

        <div class="central-topbar-user">
            <div class="central-topbar-user-info">
                <h4><?php echo centralTopbarSafeText($topbarUserName); ?></h4>
                <p><?php echo centralTopbarSafeText($topbarUserRoleLabel); ?></p>
            </div>

            <div class="central-topbar-avatar" id="centralTopbarAvatar">
                <?php if ($topbarProfilePicture !== ""): ?>
                    <img
                        src="<?php echo centralTopbarSafeText($topbarProfilePicture); ?>"
                        alt="Profile Picture"
                        class="central-topbar-avatar-img"
                    >
                <?php else: ?>
                    <span class="central-topbar-avatar-initial">
                        <?php echo centralTopbarSafeText($topbarInitial); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

    </div>

</header>

<script src="../../js/central/topbar.js"></script>
<!-- Global Notification Highlight -->
<link rel='stylesheet' href='/DrainGuard/css/global/notification-target.css'>
<script src='/DrainGuard/js/global/notification-target.js'></script>

<!-- Global Confirm Modal -->
<link rel='stylesheet' href='/DrainGuard/css/global/confirm-modal.css'>
<script src='/DrainGuard/js/global/confirm-modal.js'></script>
