<?php
$activePage = "notifications";
$pageTitle = "Notifications";
$pageParent = "Central Control";
$pageChild = "Notifications";

require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$userId = (int)($_SESSION["user_id"] ?? 0);

function nt_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function nt_time_ago($datetime)
{
    if (empty($datetime)) return "Just now";
    $timestamp = strtotime($datetime);
    if (!$timestamp) return "Just now";
    $diff = time() - $timestamp;
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . " min ago";
    if ($diff < 86400) return floor($diff / 3600) . " hr ago";
    if ($diff < 604800) return floor($diff / 86400) . " day ago";
    return date("M d, Y", $timestamp);
}

function nt_icon($type)
{
    $type = strtolower(trim((string)$type));
    if (in_array($type, ['complaint_submitted', 'complaint_received', 'complaint_rejected'])) return 'bi-file-earmark-text';
    if (in_array($type, ['inspector_report', 'team_update'])) return 'bi-clipboard-check';
    if ($type === 'comment_reply') return 'bi-chat-dots';
    return "bi-bell";
}

function nt_type_class($type)
{
    $type = strtolower(trim((string)$type));
    if (in_array($type, ['complaint_submitted', 'complaint_received', 'complaint_rejected'])) return 'type-track';
    if (in_array($type, ['inspector_report', 'team_update'])) return 'type-objection';
    if ($type === 'comment_reply') return 'type-reply';
    return "type-system";
}

if (isset($_GET["read_id"])) {
    $readId = (int)$_GET["read_id"];
    $redirectType = trim($_GET["redirect"] ?? "");

    if ($readId > 0 && $userId > 0 && isset($conn) && $conn instanceof mysqli) {
        $readSql = "SELECT is_read, related_complaint_id FROM central_notifications WHERE notification_id = ? AND recipient_user_id = ?";
        $readStmt = mysqli_prepare($conn, $readSql);
        
        if ($readStmt) {
            mysqli_stmt_bind_param($readStmt, "ii", $readId, $userId);
            mysqli_stmt_execute($readStmt);
            $readResult = mysqli_stmt_get_result($readStmt);
            
            if ($readResult && mysqli_num_rows($readResult) === 1) {
                $readRow = mysqli_fetch_assoc($readResult);
                
                $updateSql = "UPDATE central_notifications SET is_read = 1 WHERE notification_id = ? AND recipient_user_id = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);
                if ($updateStmt) {
                    mysqli_stmt_bind_param($updateStmt, "ii", $readId, $userId);
                    mysqli_stmt_execute($updateStmt);
                    mysqli_stmt_close($updateStmt);
                }
                mysqli_stmt_close($readStmt);
                
                if ($redirectType === "complaints") {
                    $complaintIdParam = $readRow["related_complaint_id"] ? "?open_discussion=" . urlencode($readRow["related_complaint_id"]) : "";
                    header("Location: /DrainGuard/pages/central/complaints.php" . $complaintIdParam);
                    exit;
                }
                header("Location: /DrainGuard/pages/central/notifications.php");
                exit;
            }
            mysqli_stmt_close($readStmt);
        }
    }
    header("Location: /DrainGuard/pages/central/notifications.php");
    exit;
}

if (isset($_GET["mark_all_read"]) && $_GET["mark_all_read"] === "1") {
    $markAllSql = "UPDATE central_notifications SET is_read = 1 WHERE recipient_user_id = ?";
    $markAllStmt = mysqli_prepare($conn, $markAllSql);
    if ($markAllStmt) {
        mysqli_stmt_bind_param($markAllStmt, "i", $userId);
        mysqli_stmt_execute($markAllStmt);
        mysqli_stmt_close($markAllStmt);
    }
    header("Location: /DrainGuard/pages/central/notifications.php");
    exit;
}

$allowedTypes = [
    "all",
    "complaint_submitted",
    "complaint_received",
    "complaint_rejected",
    "comment_reply",
    "inspector_report",
    "team_update"
];

$filterType = trim($_GET["type"] ?? "all");
$filterRead = trim($_GET["read"] ?? "all");
$page = isset($_GET["page"]) ? max(1, (int)$_GET["page"]) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

if (!in_array($filterType, $allowedTypes, true)) $filterType = "all";
if (!in_array($filterRead, ["all", "unread", "read"], true)) $filterRead = "all";

$whereSql = "";
$params = [];
$types = "";

if ($filterType !== "all") {
    $whereSql .= ($whereSql ? " AND " : "") . "cn.notification_type = ?";
    $params[] = $filterType;
    $types .= "s";
}

if ($filterRead === "unread") {
    $whereSql .= ($whereSql ? " AND " : "") . "cn.is_read = 0";
}

if ($filterRead === "read") {
    $whereSql .= ($whereSql ? " AND " : "") . "cn.is_read = 1";
}

if ($whereSql !== "") {
    $whereSql = "WHERE " . $whereSql;
}

$totalNotifications = 0;
$countSql = "SELECT COUNT(*) AS total FROM central_notifications cn {$whereSql}";
$countStmt = mysqli_prepare($conn, $countSql);

if ($countStmt) {
    if (!empty($params)) mysqli_stmt_bind_param($countStmt, $types, ...$params);
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
    FROM central_notifications cn
    LEFT JOIN complaints c ON cn.related_complaint_id = c.complaint_id
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
$unreadTotalSql = "SELECT COUNT(*) AS unread_total FROM central_notifications WHERE is_read = 0";
$unreadTotalResult = mysqli_query($conn, $unreadTotalSql);
if ($unreadTotalResult) {
    $unreadTotalRow = mysqli_fetch_assoc($unreadTotalResult);
    $unreadTotal = (int)($unreadTotalRow["unread_total"] ?? 0);
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
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
  
    <link rel="stylesheet" href="../../css/central/notifications.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="nt-page">

            <div class="nt-header">
                <div class="nt-header-left">
                    <a href="dashboard.php" class="nt-back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Back
                    </a>

                    <div>
                        <h1>Notifications</h1>
                        <p>Track updates, reports, and replies to your comments</p>
                    </div>
                </div>

                <div class="nt-stats">
                    <span><?php echo (int)$unreadTotal; ?></span>
                    <small>Unread</small>
                </div>
            </div>

            <div class="nt-toolbar">
                <form method="GET" class="nt-filter-form" id="ntFilterForm">
                    <div class="nt-filter-group">
                        <label for="type">Type</label>
                        <select name="type" id="type">
                            <option value="all" <?php echo $filterType === "all" ? "selected" : ""; ?>>All Types</option>
                            <option value="complaint_submitted" <?php echo $filterType === "complaint_submitted" ? "selected" : ""; ?>>Complaint Submitted</option>
                            <option value="complaint_received" <?php echo $filterType === "complaint_received" ? "selected" : ""; ?>>Complaint Received</option>
                            <option value="complaint_rejected" <?php echo $filterType === "complaint_rejected" ? "selected" : ""; ?>>Complaint Rejected</option>
                            <option value="comment_reply" <?php echo $filterType === "comment_reply" ? "selected" : ""; ?>>Comment Reply</option>
                            <option value="inspector_report" <?php echo $filterType === "inspector_report" ? "selected" : ""; ?>>Field Report</option>
                            <option value="team_update" <?php echo $filterType === "team_update" ? "selected" : ""; ?>>Team Update</option>
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

                    <?php foreach ($notifications as $notification): ?>
                        <?php
                            $notificationType = strtolower(trim((string)$notification["notification_type"]));
                            $notificationClass = nt_type_class($notificationType);
                            $notificationIcon = nt_icon($notificationType);
                            $isUnread = ((int)$notification["is_read"] === 0);

                            $linkUrl = "notifications.php?read_id=" . (int)$notification["notification_id"];
                            if (!empty($notification["complaint_code"])) {
                                $linkUrl .= "&redirect=complaints";
                            }
                        ?>

                        <a
                            href="<?php echo nt_safe($linkUrl); ?>"
                            class="nt-item <?php echo $isUnread ? "unread" : "read"; ?> <?php echo nt_safe($notificationClass); ?>"
                        >
                            <div class="nt-icon-box">
                                <i class="bi <?php echo nt_safe($notificationIcon); ?>"></i>
                            </div>

                            <div class="nt-content">
                                <strong><?php echo nt_safe($notification["notification_title"]); ?></strong>
                                <p><?php echo nt_safe($notification["notification_message"]); ?></p>
                                
                                <?php if (!empty($notification["complaint_code"])): ?>
                                    <div class="nt-ref">
                                        Ref: <?php echo nt_safe($notification["complaint_code"]); ?>
                                    </div>
                                <?php endif; ?>

                                <time class="nt-time">
                                    <?php echo nt_safe(nt_time_ago($notification["created_at"])); ?>
                                </time>
                            </div>

                            <?php if ($isUnread): ?>
                                <span class="nt-dot"></span>
                            <?php endif; ?>
                        </a>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="nt-empty-state">
                        <i class="bi bi-bell-slash"></i>
                        <h3>No notifications found</h3>
                        <p>You're all caught up! Updates will appear here.</p>
                        
                        <?php if ($filterType !== "all" || $filterRead !== "all"): ?>
                            <a href="notifications.php" class="nt-clear-filters">
                                Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="nt-pagination">
                    <?php if ($page > 1): ?>
                        <a href="notifications.php?<?php echo nt_build_query(["page" => $page - 1]); ?>" class="nt-page-btn">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="nt-page-btn disabled">
                            <i class="bi bi-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <div class="nt-page-numbers">
                        <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1) {
                                echo '<a href="notifications.php?' . nt_build_query(["page" => 1]) . '" class="nt-page-num">1</a>';
                                if ($startPage > 2) {
                                    echo '<span class="nt-page-dots">...</span>';
                                }
                            }

                            for ($i = $startPage; $i <= $endPage; $i++) {
                                $activeClass = ($i === $page) ? "active" : "";
                                echo '<a href="notifications.php?' . nt_build_query(["page" => $i]) . '" class="nt-page-num ' . $activeClass . '">' . $i . '</a>';
                            }

                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<span class="nt-page-dots">...</span>';
                                }
                                echo '<a href="notifications.php?' . nt_build_query(["page" => $totalPages]) . '" class="nt-page-num">' . $totalPages . '</a>';
                            }
                        ?>
                    </div>

                    <?php if ($page < $totalPages): ?>
                        <a href="notifications.php?<?php echo nt_build_query(["page" => $page + 1]); ?>" class="nt-page-btn">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="nt-page-btn disabled">
                            <i class="bi bi-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </section>
        

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/notifications.js"></script>

</body>
</html>