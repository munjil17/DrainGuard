<?php
$userName = $_SESSION['user_name'] ?? 'Ward Officer';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Ward Officer';
$topbarProfileImage = "";

function ward_topbar_profile_image_path($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);

    if (preg_match("/^https?:\/\//i", $path)) {
        return $path;
    }

    if (str_starts_with($path, "../../")) {
        return $path;
    }

    if (str_starts_with($path, "assets/")) {
        return "../../" . $path;
    }

    if (str_starts_with($path, "uploads/")) {
        return "../../assets/" . $path;
    }

    if (!str_contains($path, "/")) {
        return "../../assets/uploads/ward_officers/" . $path;
    }

    return "../../" . ltrim($path, "/");
}

if (isset($conn) && $conn && isset($_SESSION["user_id"])) {
    $currentTopbarUserId = (int)$_SESSION["user_id"];

    $hasProfileImageColumn = false;
    $columnCheckResult = mysqli_query($conn, "SHOW COLUMNS FROM ward_officers LIKE 'profile_image'");

    if ($columnCheckResult && mysqli_num_rows($columnCheckResult) > 0) {
        $hasProfileImageColumn = true;
    }

    $profileImageSelect = $hasProfileImageColumn ? ", profile_image" : ", NULL AS profile_image";

    $topbarSql = "
        SELECT
            full_name,
            designation
            $profileImageSelect
        FROM ward_officers
        WHERE user_id = ?
        LIMIT 1
    ";

    $topbarStmt = mysqli_prepare($conn, $topbarSql);

    if ($topbarStmt) {
        mysqli_stmt_bind_param($topbarStmt, "i", $currentTopbarUserId);
        mysqli_stmt_execute($topbarStmt);

        $topbarResult = mysqli_stmt_get_result($topbarStmt);
        $topbarRow = $topbarResult ? mysqli_fetch_assoc($topbarResult) : null;

        if ($topbarRow) {
            if (!empty($topbarRow["full_name"])) {
                $userName = $topbarRow["full_name"];
            }

            if (!empty($topbarRow["designation"])) {
                $userRoleLabel = $topbarRow["designation"];
            }

            if (!empty($topbarRow["profile_image"])) {
                $topbarProfileImage = ward_topbar_profile_image_path($topbarRow["profile_image"]);
            }
        }

        mysqli_stmt_close($topbarStmt);
    }
}
?>
<?php
if (!function_exists('wardTopbarSafeText')) {
    function wardTopbarSafeText($text) {
        if ($text === null) return '';
        return htmlspecialchars(trim((string)$text), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ward_topbar_time_ago')) {
    function ward_topbar_time_ago($datetime) {
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

if (!function_exists('ward_notification_icon')) {
    function ward_notification_icon($type) {
        $type = strtolower(trim((string)$type));
        if (in_array($type, ['complaint_routed', 'status_update', 'verified', 'rejected'])) return 'bi-file-earmark-text';
        if (in_array($type, ['system', 'alert'])) return 'bi-exclamation-triangle';
        if (in_array($type, ['maintenance_support_assigned_task', 'maintenance_support_in_progress'])) return 'bi-tools';
        if ($type === 'comment_reply') return 'bi-chat-dots';
        return 'bi-bell';
    }
}

if (!function_exists('ward_notification_type_class')) {
    function ward_notification_type_class($type) {
        $type = strtolower(trim((string)$type));
        if (in_array($type, ['complaint_routed', 'status_update', 'verified', 'rejected'])) return 'type-track';
        if (in_array($type, ['system', 'alert'])) return 'type-objection';
        if (in_array($type, ['maintenance_support_assigned_task', 'maintenance_support_in_progress'])) return 'type-alert';
        if ($type === 'comment_reply') return 'type-reply';
        return 'type-system';
    }
}

$wardUnreadNotificationCount = 0;
$wardTopbarNotifications = [];

if (isset($conn) && $conn instanceof mysqli && isset($_SESSION["user_id"])) {
    $currentTopbarUserId = (int)$_SESSION["user_id"];

    $unreadSql = "SELECT COUNT(*) AS unread_total FROM ward_notifications WHERE is_read = 0 AND recipient_user_id = ?";
    $unreadStmt = mysqli_prepare($conn, $unreadSql);
    if ($unreadStmt) {
        mysqli_stmt_bind_param($unreadStmt, "i", $currentTopbarUserId);
        mysqli_stmt_execute($unreadStmt);
        $unreadResult = mysqli_stmt_get_result($unreadStmt);
        if ($unreadResult) {
            $unreadRow = mysqli_fetch_assoc($unreadResult);
            $wardUnreadNotificationCount = (int)($unreadRow['unread_total'] ?? 0);
        }
        mysqli_stmt_close($unreadStmt);
    }

    $notificationSql = "
        SELECT 
            wn.notification_id,
            wn.related_complaint_id,
            wn.notification_type,
            wn.notification_title,
            wn.notification_message,
            wn.is_read,
            wn.created_at,
            c.complaint_code
        FROM ward_notifications wn
        LEFT JOIN complaints c ON wn.related_complaint_id = c.complaint_id
        WHERE wn.recipient_user_id = ?
        ORDER BY wn.created_at DESC, wn.notification_id DESC
        LIMIT 10
    ";
    $notificationStmt = mysqli_prepare($conn, $notificationSql);
    if ($notificationStmt) {
        mysqli_stmt_bind_param($notificationStmt, "i", $currentTopbarUserId);
        mysqli_stmt_execute($notificationStmt);
        $notificationResult = mysqli_stmt_get_result($notificationStmt);
        if ($notificationResult) {
            while ($row = mysqli_fetch_assoc($notificationResult)) {
                $wardTopbarNotifications[] = $row;
            }
        }
        mysqli_stmt_close($notificationStmt);
    }
}
?>

<header class="ward-topbar">

    <div class="topbar-left">
        <button class="mobile-toggle"
                id="mobileToggle"
                type="button"
                data-bs-toggle="offcanvas"
                data-bs-target="#sidebarOffcanvas"
                aria-controls="sidebarOffcanvas"
                aria-label="Open sidebar menu">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <div class="topbar-right">
        
        <details class="topbar-notification">
            <summary class="notification-btn" aria-label="Notifications">
                <i class="bi bi-bell"></i>

                <?php if ($wardUnreadNotificationCount > 0): ?>
                    <em class="notification-count">
                        <?php echo $wardUnreadNotificationCount > 99 ? '99+' : (int)$wardUnreadNotificationCount; ?>
                    </em>
                <?php endif; ?>
            </summary>

            <div class="notification-dropdown">
                <div class="notification-dropdown-header">
                    <div>
                        <h4>Notifications</h4>
                        <p>
                            <?php if ($wardUnreadNotificationCount > 0): ?>
                                <?php echo (int)$wardUnreadNotificationCount; ?> unread notification(s)
                            <?php else: ?>
                                No unread notifications
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="notification-list">
                    <?php if (count($wardTopbarNotifications) > 0): ?>

                        <?php foreach ($wardTopbarNotifications as $notification): ?>
                            <?php
                                $notificationType = strtolower(trim((string)$notification['notification_type']));
                                $notificationClass = ward_notification_type_class($notificationType);
                                $notificationIcon = ward_notification_icon($notificationType);
                                $isUnread = ((int)$notification['is_read'] === 0);

                                $notificationLink = 'notifications.php?read_id=' . (int)$notification['notification_id'];
                                if ($notificationType === 'central_instruction') {
                                    $notificationLink .= '&redirect=instruction';
                                } elseif (!empty($notification['complaint_code'])) {
                                    if ($notificationType === 'comment_reply') {
                                        $notificationLink .= '&redirect=discussion';
                                    } elseif ($notificationType === 'complaint_routed') {
                                        $notificationLink .= '&redirect=verification-queue';
                                    } elseif ($notificationType === 'maintenance_support_assigned_task') {
                                        $notificationLink .= '&redirect=local-team-assignment';
                                    } elseif ($notificationType === 'maintenance_support_in_progress' || $notificationType === 'maintenance_start_work') {
                                        $notificationLink .= '&redirect=in-progress-cases';
                                    } else {
                                        $notificationLink .= '&redirect=ward-complaints';
                                    }
                                }
                            ?>

                            <a
                                class="notification-item <?php echo $isUnread ? 'unread' : 'read'; ?> <?php echo wardTopbarSafeText($notificationClass); ?>"
                                href="<?php echo wardTopbarSafeText($notificationLink); ?>"
                            >
                                <span class="notification-icon">
                                    <i class="bi <?php echo wardTopbarSafeText($notificationIcon); ?>"></i>
                                </span>

                                <span class="notification-content">
                                    <strong>
                                        <?php echo wardTopbarSafeText($notification['notification_title'] ?? 'Notification'); ?>
                                    </strong>
                                    <small>
                                        <?php echo wardTopbarSafeText($notification['notification_message'] ?? ''); ?>
                                    </small>
                                    <b>
                                        <?php echo wardTopbarSafeText(ward_topbar_time_ago($notification['created_at'] ?? null)); ?>
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
                    <a href="notifications.php">View All Notifications</a>
                </div>
            </div>
        </details>

        <div class="topbar-user">
            <div class="topbar-user-info">
                <h4><?php echo htmlspecialchars($userName, ENT_QUOTES, "UTF-8"); ?></h4>
                <p><?php echo htmlspecialchars($userRoleLabel, ENT_QUOTES, "UTF-8"); ?></p>
            </div>

            <a href="profile.php" class="topbar-avatar" title="Profile">
                <?php if ($topbarProfileImage !== ""): ?>
                    <img src="<?php echo htmlspecialchars($topbarProfileImage, ENT_QUOTES, "UTF-8"); ?>" alt="Ward Officer Profile Photo">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </a>
        </div>
    </div>

</header>
<!-- Global Notification Highlight -->
<link rel='stylesheet' href='/DrainGuard/css/global/notification-target.css'>
<script src='/DrainGuard/js/global/notification-target.js'></script>

<!-- Global Confirm Modal -->
<link rel='stylesheet' href='/DrainGuard/css/global/confirm-modal.css'>
<script src='/DrainGuard/js/global/confirm-modal.js'></script>
