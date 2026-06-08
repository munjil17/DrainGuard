<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? null;

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

$topbarName = $_SESSION['user_name'] ?? 'Maintenance User';
$topbarRole = 'Maintenance Team';
$topbarTeam = 'Maintenance Unit';
$topbarProfileImage = '';

if (isset($conn) && $userId) {
    $topbarSql = "
        SELECT 
            u.user_name,
            u.user_role,
            mtm.full_name,
            mtm.role AS member_role,
            mtm.profile_image,
            mt.team_name
        FROM users u
        LEFT JOIN maintenance_team_members mtm 
            ON mtm.user_id = u.user_id
        LEFT JOIN maintenance_teams mt 
            ON mt.maintenance_team_id = mtm.maintenance_team_id
        WHERE u.user_id = ?
        LIMIT 1
    ";

    $topbarStmt = mysqli_prepare($conn, $topbarSql);

    if ($topbarStmt) {
        mysqli_stmt_bind_param($topbarStmt, "i", $userId);
        mysqli_stmt_execute($topbarStmt);
        $topbarResult = mysqli_stmt_get_result($topbarStmt);

        if ($topbarResult && mysqli_num_rows($topbarResult) > 0) {
            $topbarUser = mysqli_fetch_assoc($topbarResult);

            $topbarName = !empty($topbarUser['full_name'])
                ? $topbarUser['full_name']
                : ($topbarUser['user_name'] ?? $topbarName);

            $topbarTeam = !empty($topbarUser['team_name'])
                ? $topbarUser['team_name']
                : $topbarTeam;

            $topbarProfileImage = !empty($topbarUser['profile_image'])
                ? "../../" . $topbarUser['profile_image']
                : '';

            if (!empty($topbarUser['member_role'])) {
                if ($topbarUser['member_role'] === 'team_leader') {
                    $topbarRole = 'Team Leader';
                } elseif ($topbarUser['member_role'] === 'assistant_team_leader') {
                    $topbarRole = 'Assistant Team Leader';
                } elseif ($topbarUser['member_role'] === 'worker') {
                    $topbarRole = 'Worker';
                }
            }
        }

        mysqli_stmt_close($topbarStmt);
    }
}

$pageTitle = $pageTitle ?? 'Maintenance Panel';

/* ==========================================================================
   Notification Logic
   ========================================================================== */
if (!function_exists('maintenanceTopbarSafeText')) {
    function maintenanceTopbarSafeText($text) {
        if ($text === null) return '';
        return htmlspecialchars(trim((string)$text), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('maintenance_topbar_time_ago')) {
    function maintenance_topbar_time_ago($datetime) {
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

if (!function_exists('maintenance_notification_icon')) {
    function maintenance_notification_icon($type) {
        $type = strtolower(trim((string)$type));
        if (in_array($type, ['task_assigned', 'status_update', 'verified', 'rejected'])) return 'bi-file-earmark-text';
        if (in_array($type, ['system', 'alert'])) return 'bi-exclamation-triangle';
        if ($type === 'ward_reply_support_request') return 'bi-reply-all';
        if ($type === 'comment_reply') return 'bi-chat-dots';
        return 'bi-bell';
    }
}

if (!function_exists('maintenance_notification_type_class')) {
    function maintenance_notification_type_class($type) {
        $type = strtolower(trim((string)$type));
        if (in_array($type, ['task_assigned', 'status_update', 'verified', 'rejected'])) return 'type-track';
        if (in_array($type, ['system', 'alert'])) return 'type-objection';
        if ($type === 'ward_reply_support_request') return 'type-alert';
        if ($type === 'comment_reply') return 'type-reply';
        return 'type-system';
    }
}

$maintenanceUnreadNotificationCount = 0;
$maintenanceTopbarNotifications = [];

if (isset($conn) && $conn instanceof mysqli && $userId) {
    $unreadSql = "SELECT COUNT(*) AS unread_total FROM maintenance_notifications WHERE is_read = 0 AND recipient_user_id = ?";
    $unreadStmt = mysqli_prepare($conn, $unreadSql);
    if ($unreadStmt) {
        mysqli_stmt_bind_param($unreadStmt, "i", $userId);
        mysqli_stmt_execute($unreadStmt);
        $unreadResult = mysqli_stmt_get_result($unreadStmt);
        if ($unreadResult) {
            $unreadRow = mysqli_fetch_assoc($unreadResult);
            $maintenanceUnreadNotificationCount = (int)($unreadRow['unread_total'] ?? 0);
        }
        mysqli_stmt_close($unreadStmt);
    }

    $notificationSql = "
        SELECT 
            mn.notification_id,
            mn.related_complaint_id,
            mn.notification_type,
            mn.notification_title,
            mn.notification_message,
            mn.is_read,
            mn.created_at,
            c.complaint_code,
            c.complaint_status
        FROM maintenance_notifications mn
        LEFT JOIN complaints c ON mn.related_complaint_id = c.complaint_id
        WHERE mn.recipient_user_id = ?
        ORDER BY mn.created_at DESC, mn.notification_id DESC
        LIMIT 10
    ";
    $notificationStmt = mysqli_prepare($conn, $notificationSql);
    if ($notificationStmt) {
        mysqli_stmt_bind_param($notificationStmt, "i", $userId);
        mysqli_stmt_execute($notificationStmt);
        $notificationResult = mysqli_stmt_get_result($notificationStmt);
        if ($notificationResult) {
            while ($row = mysqli_fetch_assoc($notificationResult)) {
                $maintenanceTopbarNotifications[] = $row;
            }
        }
        mysqli_stmt_close($notificationStmt);
    }
}
?>

<header class="topbar">
    <div class="topbar-left">
        <div>
            <h3><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
            <p><?php echo htmlspecialchars($topbarTeam, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>

    <div class="topbar-right">
        
        <details class="topbar-notification">
            <summary class="notification-btn" aria-label="Notifications">
                <i class="bi bi-bell"></i>

                <?php if ($maintenanceUnreadNotificationCount > 0): ?>
                    <em class="notification-count" style="width: 18px; height: 18px; border-radius: 50%; background: #EF4444; position: absolute; top: 0px; right: 0px; color: white; font-size: 10px; display: flex; align-items: center; justify-content: center; font-style: normal; font-weight: 700;">
                        <?php echo $maintenanceUnreadNotificationCount > 99 ? '99+' : (int)$maintenanceUnreadNotificationCount; ?>
                    </em>
                <?php endif; ?>
            </summary>

            <div class="notification-dropdown">
                <div class="notification-dropdown-header">
                    <div>
                        <h4>Notifications</h4>
                        <p>
                            <?php if ($maintenanceUnreadNotificationCount > 0): ?>
                                <?php echo (int)$maintenanceUnreadNotificationCount; ?> unread notification(s)
                            <?php else: ?>
                                No unread notifications
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="notification-list">
                    <?php if (count($maintenanceTopbarNotifications) > 0): ?>

                        <?php foreach ($maintenanceTopbarNotifications as $notification): ?>
                            <?php
                                $notificationType = strtolower(trim((string)$notification['notification_type']));
                                $notificationClass = maintenance_notification_type_class($notificationType);
                                $notificationIcon = maintenance_notification_icon($notificationType);
                                $isUnread = ((int)$notification['is_read'] === 0);

                                $notificationLink = 'notifications.php?read_id=' . (int)$notification['notification_id'];
                                if (!empty($notification['complaint_code'])) {
                                    if ($notificationType === 'comment_reply') {
                                        $notificationLink .= '&redirect=discussion';
                                    } elseif ($notificationType === 'ward_reply_support_request') {
                                        if (isset($notification["complaint_status"]) && $notification["complaint_status"] === 'in_progress') {
                                            $notificationLink .= '&redirect=in-progress-work';
                                        } else {
                                            $notificationLink .= '&redirect=assigned-tasks';
                                        }
                                    } elseif (in_array($notificationType, ['ward_team_reassign_removed', 'ward_team_transfer_removed'], true)) {
                                        $notificationLink .= '&redirect=dashboard';
                                    } elseif (in_array($notificationType, ['ward_team_reassigned', 'ward_in_progress_team_transfer'], true)) {
                                        $notificationLink .= '&redirect=assigned-tasks';
                                    } elseif (in_array($notificationType, ['inspector_review_started', 'inspector_work_approved', 'inspector_false_completion_confirmed', 'ward_confirm_inspector_claim', 'ward_reject_inspector_claim'])) {
                                        $notificationLink .= '&redirect=task-history';
                                    } elseif (in_array($notificationType, ['citizen_feedback_satisfied', 'citizen_objection_submitted'])) {
                                        $notificationLink .= '&redirect=feedback';
                                    } else {
                                        $notificationLink .= '&redirect=assigned-tasks'; // Or whatever page handles tasks
                                    }
                                }
                            ?>

                            <a
                                class="notification-item <?php echo $isUnread ? 'unread' : 'read'; ?> <?php echo maintenanceTopbarSafeText($notificationClass); ?>"
                                href="<?php echo maintenanceTopbarSafeText($notificationLink); ?>"
                            >
                                <span class="notification-icon">
                                    <i class="bi <?php echo maintenanceTopbarSafeText($notificationIcon); ?>"></i>
                                </span>

                                <span class="notification-content">
                                    <strong><?php echo maintenanceTopbarSafeText($notification['notification_title']); ?></strong>
                                    <small><?php echo maintenanceTopbarSafeText($notification['notification_message']); ?></small>
                                    <b><?php echo maintenance_topbar_time_ago($notification['created_at']); ?></b>
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

        <div class="topbar-user">
            <div class="topbar-user-text">
                <h4><?php echo htmlspecialchars($topbarName, ENT_QUOTES, 'UTF-8'); ?></h4>
                <p><?php echo htmlspecialchars($topbarRole, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="topbar-avatar">
                <?php if (!empty($topbarProfileImage)): ?>
                    <img src="<?php echo htmlspecialchars($topbarProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile">
                <?php else: ?>
                    <i class="bi bi-person-gear"></i>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
<!-- Global Notification Highlight -->
<link rel='stylesheet' href='/DrainGuard/css/global/notification-target.css'>
<script src='/DrainGuard/js/global/notification-target.js'></script>

<!-- Global Confirm Modal -->
<link rel='stylesheet' href='/DrainGuard/css/global/confirm-modal.css'>
<script src='/DrainGuard/js/global/confirm-modal.js'></script>
