<?php

$activePage = "notifications";
$pageTitle = "Notifications";
$pageParent = "Citizen";
$pageChild = "Notifications";

require_once "../../config.php";
require_login(["citizen"]);

$userId = (int)($_SESSION["user_id"] ?? 0);

function nt_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function nt_time_ago($datetime)
{
    if (empty($datetime)) {
        return "Just now";
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return "Just now";
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return "Just now";
    }

    if ($diff < 3600) {
        return floor($diff / 60) . " min ago";
    }

    if ($diff < 86400) {
        return floor($diff / 3600) . " hr ago";
    }

    if ($diff < 604800) {
        return floor($diff / 86400) . " day ago";
    }

    return date("M d, Y", $timestamp);
}

function nt_icon($type)
{
    $type = strtolower(trim((string)$type));

    if (
        $type === "complaint_accepted" ||
        $type === "complaint_rejected" ||
        $type === "complaint_status_updated"
    ) {
        return "bi-signpost-split";
    }

    if (
        $type === "objection_submitted" ||
        $type === "objection_under_review" ||
        $type === "objection_reopened" ||
        $type === "objection_final_rejected"
    ) {
        return "bi-exclamation-diamond";
    }

    if ($type === "comment_reply") {
        return "bi-chat-dots";
    }

    return "bi-bell";
}

function nt_type_class($type)
{
    $type = strtolower(trim((string)$type));

    if (
        $type === "complaint_accepted" ||
        $type === "complaint_rejected" ||
        $type === "complaint_status_updated"
    ) {
        return "type-track";
    }

    if (
        $type === "objection_submitted" ||
        $type === "objection_under_review" ||
        $type === "objection_reopened" ||
        $type === "objection_final_rejected"
    ) {
        return "type-objection";
    }

    if ($type === "comment_reply") {
        return "type-reply";
    }

    return "type-system";
}

function nt_type_label($type)
{
    $labels = [
        "complaint_accepted" => "Complaint Accepted",
        "complaint_rejected" => "Complaint Rejected",
        "complaint_status_updated" => "Track Update",
        "objection_submitted" => "Objection Submitted",
        "objection_under_review" => "Objection Under Review",
        "objection_reopened" => "Complaint Reopened",
        "objection_final_rejected" => "Objection Final Rejected",
        "comment_reply" => "Comment Reply"
    ];

    return $labels[$type] ?? ucwords(str_replace("_", " ", $type));
}

/* Delete citizen notifications older than 6 months */
mysqli_query(
    $conn,
    "DELETE FROM citizen_notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)"
);

/* Mark single notification as read and redirect */
$readId = isset($_GET["read_id"]) ? (int)$_GET["read_id"] : 0;
$redirectType = trim($_GET["redirect"] ?? "");

if ($readId > 0) {
    $readSql = "
        SELECT
            cn.notification_id,
            cn.related_complaint_id,
            c.complaint_code
        FROM citizen_notifications cn
        LEFT JOIN complaints c
            ON cn.related_complaint_id = c.complaint_id
        WHERE cn.notification_id = ?
          AND cn.recipient_user_id = ?
        LIMIT 1
    ";

    $readStmt = mysqli_prepare($conn, $readSql);

    if ($readStmt) {
        mysqli_stmt_bind_param($readStmt, "ii", $readId, $userId);
        mysqli_stmt_execute($readStmt);

        $readResult = mysqli_stmt_get_result($readStmt);

        if ($readResult && mysqli_num_rows($readResult) === 1) {
            $readRow = mysqli_fetch_assoc($readResult);

            $updateSql = "
                UPDATE citizen_notifications
                SET is_read = 1
                WHERE notification_id = ?
                  AND recipient_user_id = ?
            ";

            $updateStmt = mysqli_prepare($conn, $updateSql);

            if ($updateStmt) {
                mysqli_stmt_bind_param($updateStmt, "ii", $readId, $userId);
                mysqli_stmt_execute($updateStmt);
                mysqli_stmt_close($updateStmt);
            }

            mysqli_stmt_close($readStmt);

            if ($redirectType === "track" && !empty($readRow["complaint_code"])) {
                redirect_to("pages/citizen/track-complaint.php?code=" . urlencode($readRow["complaint_code"]));
            }

            redirect_to("pages/citizen/notifications.php");
        }

        mysqli_stmt_close($readStmt);
    }

    redirect_to("pages/citizen/notifications.php");
}

/* Mark all notifications as read */
if (isset($_GET["mark_all_read"]) && $_GET["mark_all_read"] === "1") {
    $markAllSql = "
        UPDATE citizen_notifications
        SET is_read = 1
        WHERE recipient_user_id = ?
    ";

    $markAllStmt = mysqli_prepare($conn, $markAllSql);

    if ($markAllStmt) {
        mysqli_stmt_bind_param($markAllStmt, "i", $userId);
        mysqli_stmt_execute($markAllStmt);
        mysqli_stmt_close($markAllStmt);
    }

    redirect_to("pages/citizen/notifications.php");
}

$allowedTypes = [
    "all",
    "complaint_accepted",
    "complaint_rejected",
    "complaint_status_updated",
    "objection_submitted",
    "objection_under_review",
    "objection_reopened",
    "objection_final_rejected",
    "comment_reply"
];

$filterType = trim($_GET["type"] ?? "all");
$filterRead = trim($_GET["read"] ?? "all");
$page = isset($_GET["page"]) ? max(1, (int)$_GET["page"]) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

if (!in_array($filterType, $allowedTypes, true)) {
    $filterType = "all";
}

if (!in_array($filterRead, ["all", "unread", "read"], true)) {
    $filterRead = "all";
}

$whereSql = "WHERE cn.recipient_user_id = ?";
$params = [$userId];
$types = "i";

if ($filterType !== "all") {
    $whereSql .= " AND cn.notification_type = ?";
    $params[] = $filterType;
    $types .= "s";
} else {
    // No default type restriction, show all

}

if ($filterRead === "unread") {
    $whereSql .= " AND cn.is_read = 0";
}

if ($filterRead === "read") {
    $whereSql .= " AND cn.is_read = 1";
}

$totalNotifications = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM citizen_notifications cn
    {$whereSql}
";

$countStmt = mysqli_prepare($conn, $countSql);

if ($countStmt) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
    mysqli_stmt_execute($countStmt);

    $countResult = mysqli_stmt_get_result($countStmt);

    if ($countResult) {
        $countRow = mysqli_fetch_assoc($countResult);
        $totalNotifications = (int)($countRow["total"] ?? 0);
    }

    mysqli_stmt_close($countStmt);
}

$totalPages = max(1, (int)ceil($totalNotifications / $perPage));

$notifications = [];

$listSql = "
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
    {$whereSql}
    ORDER BY cn.created_at DESC, cn.notification_id DESC
    LIMIT ? OFFSET ?
";

$listParams = $params;
$listTypes = $types . "ii";
$listParams[] = $perPage;
$listParams[] = $offset;

$listStmt = mysqli_prepare($conn, $listSql);

if ($listStmt) {
    mysqli_stmt_bind_param($listStmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($listStmt);

    $listResult = mysqli_stmt_get_result($listStmt);

    if ($listResult) {
        while ($row = mysqli_fetch_assoc($listResult)) {
            $notifications[] = $row;
        }
    }

    mysqli_stmt_close($listStmt);
}

$unreadTotal = 0;

$unreadTotalSql = "
    SELECT COUNT(*) AS unread_total
    FROM citizen_notifications
    WHERE recipient_user_id = ?
      AND is_read = 0
";

$unreadTotalStmt = mysqli_prepare($conn, $unreadTotalSql);

if ($unreadTotalStmt) {
    mysqli_stmt_bind_param($unreadTotalStmt, "i", $userId);
    mysqli_stmt_execute($unreadTotalStmt);

    $unreadTotalResult = mysqli_stmt_get_result($unreadTotalStmt);

    if ($unreadTotalResult) {
        $unreadTotalRow = mysqli_fetch_assoc($unreadTotalResult);
        $unreadTotal = (int)($unreadTotalRow["unread_total"] ?? 0);
    }

    mysqli_stmt_close($unreadTotalStmt);
}

function nt_build_query($overrides = [])
{
    $query = array_merge($_GET, $overrides);

    unset($query["read_id"], $query["redirect"], $query["mark_all_read"]);

    return http_build_query($query);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/notifications.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="nt-page">

            <div class="nt-header">
                <div class="nt-header-left">
                    <a href="dashboard.php" class="nt-back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Back
                    </a>

                    <div>
                        <h1>Notifications</h1>
                        <p>Track updates, objection status, and replies to your comments</p>
                    </div>
                </div>

                <div class="nt-stats">
                    <span><?php echo (int)$unreadTotal; ?></span>
                    <small>Unread</small>
                </div>
            </div>

            <div class="nt-toolbar">
                <form method="GET" class="nt-filter-form">
                    <div class="nt-filter-group">
                        <label for="type">Type</label>
                        <select name="type" id="type">
                            <option value="all" <?php echo $filterType === "all" ? "selected" : ""; ?>>All Types</option>
                            <option value="complaint_accepted" <?php echo $filterType === "complaint_accepted" ? "selected" : ""; ?>>Complaint Accepted</option>
                            <option value="complaint_rejected" <?php echo $filterType === "complaint_rejected" ? "selected" : ""; ?>>Complaint Rejected</option>
                            <option value="complaint_status_updated" <?php echo $filterType === "complaint_status_updated" ? "selected" : ""; ?>>Track Update</option>
                            <option value="objection_submitted" <?php echo $filterType === "objection_submitted" ? "selected" : ""; ?>>Objection Submitted</option>
                            <option value="objection_under_review" <?php echo $filterType === "objection_under_review" ? "selected" : ""; ?>>Objection Under Review</option>
                            <option value="objection_reopened" <?php echo $filterType === "objection_reopened" ? "selected" : ""; ?>>Objection Reopened</option>
                            <option value="objection_final_rejected" <?php echo $filterType === "objection_final_rejected" ? "selected" : ""; ?>>Objection Final Rejected</option>
                            <option value="comment_reply" <?php echo $filterType === "comment_reply" ? "selected" : ""; ?>>Comment Reply</option>
                        </select>
                    </div>

                    <div class="nt-filter-group">
                        <label for="read">Read Status</label>
                        <select name="read" id="read">
                            <option value="all" <?php echo $filterRead === "all" ? "selected" : ""; ?>>All</option>
                            <option value="unread" <?php echo $filterRead === "unread" ? "selected" : ""; ?>>Unread</option>
                            <option value="read" <?php echo $filterRead === "read" ? "selected" : ""; ?>>Read</option>
                        </select>
                    </div>

                    <button type="submit">
                        <i class="bi bi-funnel"></i>
                        Filter
                    </button>

                    <a href="notifications.php" class="nt-reset-btn">
                        Reset
                    </a>
                </form>

                <?php if ($unreadTotal > 0): ?>
                    <a href="notifications.php?mark_all_read=1" class="nt-mark-all">
                        <i class="bi bi-check2-all"></i>
                        Mark All Read
                    </a>
                <?php endif; ?>
            </div>

            <div class="nt-card">
                <?php if (count($notifications) > 0): ?>

                    <div class="nt-list">
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                                $type = strtolower(trim((string)$notification["notification_type"]));
                                $icon = nt_icon($type);
                                $typeClass = nt_type_class($type);
                                $isUnread = ((int)$notification["is_read"] === 0);

                                $targetLink = "notifications.php?read_id=" . (int)$notification["notification_id"];

                                if (!empty($notification["complaint_code"])) {
                                    $targetLink .= "&redirect=track";
                                }
                            ?>

                            <a
                                href="<?php echo nt_safe($targetLink); ?>"
                                class="nt-item <?php echo $isUnread ? "unread" : "read"; ?> <?php echo nt_safe($typeClass); ?>"
                            >
                                <span class="nt-icon">
                                    <i class="bi <?php echo nt_safe($icon); ?>"></i>
                                </span>

                                <span class="nt-body">
                                    <span class="nt-topline">
                                        <strong><?php echo nt_safe($notification["notification_title"]); ?></strong>
                                        <em><?php echo nt_safe(nt_time_ago($notification["created_at"])); ?></em>
                                    </span>

                                    <small class="nt-type">
                                        <?php echo nt_safe(nt_type_label($type)); ?>
                                        <?php if (!empty($notification["complaint_code"])): ?>
                                            · <?php echo nt_safe($notification["complaint_code"]); ?>
                                        <?php endif; ?>
                                    </small>

                                    <p><?php echo nt_safe($notification["notification_message"]); ?></p>
                                </span>
                            </a>

                        <?php endforeach; ?>
                    </div>

                    <div class="nt-pagination">
                        <?php if ($page > 1): ?>
                            <a href="notifications.php?<?php echo nt_safe(nt_build_query(["page" => $page - 1])); ?>">
                                <i class="bi bi-chevron-left"></i>
                                Previous
                            </a>
                        <?php else: ?>
                            <span class="disabled">
                                <i class="bi bi-chevron-left"></i>
                                Previous
                            </span>
                        <?php endif; ?>

                        <strong>
                            Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?>
                        </strong>

                        <?php if ($page < $totalPages): ?>
                            <a href="notifications.php?<?php echo nt_safe(nt_build_query(["page" => $page + 1])); ?>">
                                Next
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled">
                                Next
                                <i class="bi bi-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <div class="nt-empty">
                        <i class="bi bi-bell-slash"></i>
                        <h3>No notifications found</h3>
                        <p>Your track updates, objection status, and comment reply notifications will appear here.</p>
                    </div>

                <?php endif; ?>
            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const filterForm = document.querySelector(".nt-filter-form");
    if (filterForm) {
        filterForm.querySelectorAll("select").forEach(function(select) {
            select.addEventListener("change", function() {
                // Manually construct the URL to guarantee navigation and reset pagination
                const url = new URL(window.location.href);
                const typeSelect = filterForm.querySelector("select[name='type']");
                const readSelect = filterForm.querySelector("select[name='read']");
                
                if (typeSelect) url.searchParams.set("type", typeSelect.value);
                if (readSelect) url.searchParams.set("read", readSelect.value);
                
                url.searchParams.delete("page"); // Reset to page 1
                window.location.href = url.toString();
            });
        });
        
        const filterBtn = filterForm.querySelector("button[type='submit']");
        if (filterBtn) {
            filterBtn.style.display = "none";
        }
    }
});
</script>

</body>
</html>