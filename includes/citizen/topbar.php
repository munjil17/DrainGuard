<?php

$topbarUserName  = $_SESSION['citizen_full_name'] ?? $_SESSION['user_name'] ?? 'Citizen User';
$topbarRoleLabel = 'Citizen';
$topbarUserId    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if (!function_exists('citizen_safe_topbar')) {
    function citizen_safe_topbar($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('citizen_topbar_time_ago')) {
    function citizen_topbar_time_ago($datetime)
    {
        if (empty($datetime)) {
            return 'Just now';
        }

        $timestamp = strtotime($datetime);

        if (!$timestamp) {
            return 'Just now';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'Just now';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . ' min ago';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . ' hr ago';
        }

        if ($diff < 604800) {
            return floor($diff / 86400) . ' day ago';
        }

        return date('M d, Y', $timestamp);
    }
}

if (!function_exists('citizen_notification_icon')) {
    function citizen_notification_icon($type)
    {
        $type = strtolower(trim((string)$type));

        if (
            $type === 'complaint_accepted' ||
            $type === 'complaint_rejected' ||
            $type === 'complaint_status_updated'
        ) {
            return 'bi-signpost-split';
        }

        if (
            $type === 'objection_submitted' ||
            $type === 'objection_under_review' ||
            $type === 'objection_reopened' ||
            $type === 'objection_final_rejected'
        ) {
            return 'bi-exclamation-diamond';
        }

        if ($type === 'comment_reply') {
            return 'bi-chat-dots';
        }

        return 'bi-bell';
    }
}

if (!function_exists('citizen_notification_type_class')) {
    function citizen_notification_type_class($type)
    {
        $type = strtolower(trim((string)$type));

        if (
            $type === 'complaint_accepted' ||
            $type === 'complaint_rejected' ||
            $type === 'complaint_status_updated'
        ) {
            return 'type-track';
        }

        if (
            $type === 'objection_submitted' ||
            $type === 'objection_under_review' ||
            $type === 'objection_reopened' ||
            $type === 'objection_final_rejected'
        ) {
            return 'type-objection';
        }

        if ($type === 'comment_reply') {
            return 'type-reply';
        }

        return 'type-system';
    }
}

$topbarPhotoPath = '';

if (!empty($_SESSION['profile_photo'])) {
    $topbarPhotoPath = '../../' . ltrim($_SESSION['profile_photo'], '/');
}

$citizenUnreadNotificationCount = 0;
$citizenTopbarNotifications = [];

if ($topbarUserId > 0 && isset($conn) && $conn instanceof mysqli) {
    $unreadSql = "
        SELECT COUNT(*) AS unread_total
        FROM citizen_notifications
        WHERE recipient_user_id = ?
          AND is_read = 0
    ";

    $unreadStmt = mysqli_prepare($conn, $unreadSql);

    if ($unreadStmt) {
        mysqli_stmt_bind_param($unreadStmt, "i", $topbarUserId);
        mysqli_stmt_execute($unreadStmt);

        $unreadResult = mysqli_stmt_get_result($unreadStmt);

        if ($unreadResult) {
            $unreadRow = mysqli_fetch_assoc($unreadResult);
            $citizenUnreadNotificationCount = (int)($unreadRow['unread_total'] ?? 0);
        }

        mysqli_stmt_close($unreadStmt);
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
        FROM citizen_notifications cn
        LEFT JOIN complaints c
            ON cn.related_complaint_id = c.complaint_id
        WHERE cn.recipient_user_id = ?
        ORDER BY cn.created_at DESC, cn.notification_id DESC
        LIMIT 10
    ";

    $notificationStmt = mysqli_prepare($conn, $notificationSql);

    if ($notificationStmt) {
        mysqli_stmt_bind_param($notificationStmt, "i", $topbarUserId);
        mysqli_stmt_execute($notificationStmt);

        $notificationResult = mysqli_stmt_get_result($notificationStmt);

        if ($notificationResult) {
            while ($notificationRow = mysqli_fetch_assoc($notificationResult)) {
                $citizenTopbarNotifications[] = $notificationRow;
            }
        }

        mysqli_stmt_close($notificationStmt);
    }
}
?>

<header class="citizen-topbar">

    <div class="topbar-left">
        <button
            class="mobile-toggle"
            id="mobileToggle"
            type="button"
            aria-label="Open sidebar"
            aria-controls="sidebar"
            aria-expanded="false"
        >
            <i class="bi bi-list"></i>
        </button>

        <div class="topbar-page-title">
            <h3><?php echo citizen_safe_topbar($pageTitle ?? 'Citizen Panel'); ?></h3>
            <p>
                <?php echo citizen_safe_topbar($pageParent ?? 'Citizen'); ?>
                <?php if (!empty($pageChild)): ?>
                    <span>/</span>
                    <?php echo citizen_safe_topbar($pageChild); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="topbar-right">

        <details class="topbar-notification">
            <summary class="notification-btn" aria-label="Notifications">
                <i class="bi bi-bell"></i>

                <?php if ($citizenUnreadNotificationCount > 0): ?>
                    <em class="notification-count">
                        <?php echo $citizenUnreadNotificationCount > 99 ? '99+' : (int)$citizenUnreadNotificationCount; ?>
                    </em>
                <?php endif; ?>
            </summary>

            <div class="notification-dropdown">
                <div class="notification-dropdown-header">
                    <div>
                        <h4>Notifications</h4>
                        <p>
                            <?php if ($citizenUnreadNotificationCount > 0): ?>
                                <?php echo (int)$citizenUnreadNotificationCount; ?> unread notification(s)
                            <?php else: ?>
                                No unread notifications
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="notification-list">
                    <?php if (count($citizenTopbarNotifications) > 0): ?>

                        <?php foreach ($citizenTopbarNotifications as $notification): ?>
                            <?php
                                $notificationType = strtolower(trim((string)$notification['notification_type']));
                                $notificationClass = citizen_notification_type_class($notificationType);
                                $notificationIcon = citizen_notification_icon($notificationType);
                                $isUnread = ((int)$notification['is_read'] === 0);

                                $notificationLink = 'notifications.php?read_id=' . (int)$notification['notification_id'];

                                if (!empty($notification['complaint_code'])) {
                                    if ($notificationType === 'comment_reply') {
                                        $notificationLink .= '&redirect=discussion';
                                    } else {
                                        $notificationLink .= '&redirect=track';
                                    }
                                }
                            ?>

                            <a
                                class="notification-item <?php echo $isUnread ? 'unread' : 'read'; ?> <?php echo citizen_safe_topbar($notificationClass); ?>"
                                href="<?php echo citizen_safe_topbar($notificationLink); ?>"
                            >
                                <span class="notification-icon">
                                    <i class="bi <?php echo citizen_safe_topbar($notificationIcon); ?>"></i>
                                </span>

                                <span class="notification-content">
                                    <strong>
                                        <?php echo citizen_safe_topbar($notification['notification_title'] ?? 'Notification'); ?>
                                    </strong>

                                    <small>
                                        <?php echo citizen_safe_topbar($notification['notification_message'] ?? ''); ?>
                                    </small>

                                    <b>
                                        <?php echo citizen_safe_topbar(citizen_topbar_time_ago($notification['created_at'] ?? null)); ?>
                                    </b>
                                </span>
                            </a>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="notification-empty">
                            <i class="bi bi-bell-slash"></i>
                            <h5>No notifications yet</h5>
                            <p>Track updates, objection status, and comment replies will appear here.</p>
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

        <div class="topbar-user">
            <div>
                <h4><?php echo citizen_safe_topbar($topbarUserName); ?></h4>
                <p><?php echo citizen_safe_topbar($topbarRoleLabel); ?></p>
            </div>

            <a href="profile.php" class="topbar-avatar" title="View Profile">
                <?php if ($topbarPhotoPath !== ''): ?>
                    <img
                        src="<?php echo citizen_safe_topbar($topbarPhotoPath); ?>"
                        alt="Profile Photo"
                        loading="lazy"
                    >
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </a>
        </div>

    </div>

</header>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const details = document.querySelector("details.topbar-notification");
    if (!details) return;

    const summary = details.querySelector("summary");

    // Close when clicking anywhere outside
    document.addEventListener("click", function(e) {
        if (details.hasAttribute("open") && !details.contains(e.target)) {
            details.removeAttribute("open");
        }
    }, true);

    // Toggle on icon click
    if (summary) {
        summary.addEventListener("click", function(e) {
            e.preventDefault();
            if (details.hasAttribute("open")) {
                details.removeAttribute("open");
            } else {
                details.setAttribute("open", "");
            }
        });
    }
});
</script>