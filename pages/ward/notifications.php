<?php
$activePage = "notifications";
$pageTitle = "Notifications";
$pageParent = "Ward Office Operations";
$pageChild = "Notifications";

require_once "../../config.php";


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
    if (in_array($type, ['maintenance_support_assigned_task', 'maintenance_support_in_progress'])) return 'bi-tools';
    return "bi-bell";
}

function nt_type_class($type)
{
    $type = strtolower(trim((string)$type));
    if (in_array($type, ['complaint_submitted', 'complaint_received', 'complaint_rejected'])) return 'type-track';
    if (in_array($type, ['inspector_report', 'team_update'])) return 'type-objection';
    if ($type === 'comment_reply') return 'type-reply';
    if (in_array($type, ['maintenance_support_assigned_task', 'maintenance_support_in_progress'])) return 'type-alert';
    return "type-system";
}

function nt_type_label($type)
{
    $type = strtolower(trim((string)$type));
    $labels = [
        "central_instruction" => "Central Instruction",
        "citizen_objection_submitted" => "Citizen Submitted Objection",
        "comment_reply" => "Comment Reply",
        "complaint_routed" => "Complaint Assigned",
        "inspector_false_completion_confirmed" => "False Completion Confirmed",
        "inspector_review_started" => "Inspector Review Started",
        "inspector_work_approved" => "Work Approved by Inspector",
        "maintenance_completion_proof_submitted" => "Completion Proof Submitted",
        "maintenance_start_work" => "Work Started",
        "maintenance_support_assigned_task" => "Support Requested for Assigned Task",
        "maintenance_support_in_progress" => "Support Requested for In Progress Work",
        "system" => "System"
    ];

    return $labels[$type] ?? ucwords(str_replace("_", " ", $type));
}

if (isset($_GET["read_id"])) {
    $readId = (int)$_GET["read_id"];
    $redirectType = trim($_GET["redirect"] ?? "");

    if ($readId > 0 && $userId > 0 && isset($conn) && $conn instanceof mysqli) {
        $readSql = "SELECT is_read, related_complaint_id FROM ward_notifications WHERE notification_id = ? AND recipient_user_id = ?";
        $readStmt = mysqli_prepare($conn, $readSql);
        
        if ($readStmt) {
            mysqli_stmt_bind_param($readStmt, "ii", $readId, $userId);
            mysqli_stmt_execute($readStmt);
            $readResult = mysqli_stmt_get_result($readStmt);
            
            if ($readResult && mysqli_num_rows($readResult) === 1) {
                $readRow = mysqli_fetch_assoc($readResult);
                
                $updateSql = "UPDATE ward_notifications SET is_read = 1 WHERE notification_id = ? AND recipient_user_id = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);
                if ($updateStmt) {
                    mysqli_stmt_bind_param($updateStmt, "ii", $readId, $userId);
                    mysqli_stmt_execute($updateStmt);
                    mysqli_stmt_close($updateStmt);
                }
                mysqli_stmt_close($readStmt);
                
                if ($redirectType === "complaints" || $redirectType === "ward-complaints") {
                    $param = !empty($readRow["related_complaint_id"]) ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&highlight=1" : "";
                    header("Location: ward-complaints.php" . $param);
                    exit;
                }
                
                if ($redirectType === "local-team-assignment") {
                    $param = !empty($readRow["related_complaint_id"]) ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&highlight=1" : "";
                    header("Location: local-team-assignment.php" . $param);
                    exit;
                }

                if ($redirectType === "in-progress-cases") {
                    $param = !empty($readRow["related_complaint_id"]) ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&highlight=1" : "";
                    header("Location: in-progress-cases.php" . $param);
                    exit;
                }
                
                if ($redirectType === "verification-queue") {
                    $param = !empty($readRow["related_complaint_id"]) ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&highlight=1" : "";
                    header("Location: verification-queue.php" . $param);
                    exit;
                }

                if ($redirectType === "reopened-disputed") {
                    $param = !empty($readRow["related_complaint_id"]) ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&highlight=1" : "";
                    header("Location: reopened-disputed.php" . $param);
                    exit;
                }

                if ($redirectType === "citizen-objections") {
                    $param = !empty($readRow["related_complaint_id"]) ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&highlight=1" : "";
                    header("Location: citizen-objections.php" . $param);
                    exit;
                }
                
                if ($redirectType === "discussion" && !empty($readRow["related_complaint_id"])) {
                    header("Location: discussion.php?id=" . urlencode($readRow["related_complaint_id"]));
                    exit;
                }
                header("Location: notifications.php");
                exit;
            }
            mysqli_stmt_close($readStmt);
        }
    }
    header("Location: notifications.php");
    exit;
}

if (isset($_GET["mark_all_read"]) && $_GET["mark_all_read"] === "1") {
    $markAllSql = "UPDATE ward_notifications SET is_read = 1 WHERE recipient_user_id = ?";
    $markAllStmt = mysqli_prepare($conn, $markAllSql);
    if ($markAllStmt) {
        mysqli_stmt_bind_param($markAllStmt, "i", $userId);
        mysqli_stmt_execute($markAllStmt);
        mysqli_stmt_close($markAllStmt);
    }
    header("Location: /DrainGuard/pages/ward/notifications.php");
    exit;
}

$availableTypes = [];
$typeSql = "SELECT DISTINCT notification_type FROM ward_notifications WHERE recipient_user_id = ? ORDER BY notification_type";
$typeStmt = mysqli_prepare($conn, $typeSql);
if ($typeStmt) {
    mysqli_stmt_bind_param($typeStmt, "i", $userId);
    mysqli_stmt_execute($typeStmt);
    $typeResult = mysqli_stmt_get_result($typeStmt);
    if ($typeResult) {
        while ($typeRow = mysqli_fetch_assoc($typeResult)) {
            $typeValue = trim((string)($typeRow["notification_type"] ?? ""));
            if ($typeValue !== "") $availableTypes[] = $typeValue;
        }
    }
    mysqli_stmt_close($typeStmt);
}

$allowedTypes = array_merge(["all"], $availableTypes);

$filterType = trim($_GET["type"] ?? "all");
$filterRead = trim($_GET["read"] ?? "all");
$page = isset($_GET["page"]) ? max(1, (int)$_GET["page"]) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

if (!in_array($filterType, $allowedTypes, true)) $filterType = "all";
if (!in_array($filterRead, ["all", "unread", "read"], true)) $filterRead = "all";

$whereSql = "cn.recipient_user_id = ?";
$params = [$userId];
$types = "i";

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
$countSql = "SELECT COUNT(*) AS total FROM ward_notifications cn {$whereSql}";
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
    FROM ward_notifications cn
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
$unreadTotalSql = "SELECT COUNT(*) AS unread_total FROM ward_notifications WHERE is_read = 0 AND recipient_user_id = $userId";
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
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
  
    <link rel="stylesheet" href="../../css/ward/notifications.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
</head>

<body class="ward">

<div class="dg-ward-layout">

    <?php include "../../includes/ward/sidebar.php"; ?>

    <main class="ward-main">

        <?php include "../../includes/ward/topbar.php"; ?>

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
                            <?php foreach ($availableTypes as $typeOption): ?>
                                <option value="<?php echo nt_safe($typeOption); ?>" <?php echo $filterType === $typeOption ? "selected" : ""; ?>>
                                    <?php echo nt_safe(nt_type_label($typeOption)); ?>
                                </option>
                            <?php endforeach; ?>
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
                                if ($notificationType === 'comment_reply') {
                                    $linkUrl .= "&redirect=discussion";
                                } elseif ($notificationType === 'complaint_routed') {
                                    $linkUrl .= "&redirect=verification-queue";
                                } elseif ($notificationType === 'maintenance_support_assigned_task') {
                                    $linkUrl .= "&redirect=local-team-assignment";
                                } elseif ($notificationType === 'maintenance_support_in_progress' || $notificationType === 'maintenance_start_work') {
                                    $linkUrl .= "&redirect=in-progress-cases";
                                } elseif ($notificationType === 'inspector_false_completion_confirmed') {
                                    $linkUrl .= "&redirect=reopened-disputed";
                                } elseif ($notificationType === 'citizen_objection_submitted') {
                                    $linkUrl .= "&redirect=citizen-objections";
                                } else {
                                    $linkUrl .= "&redirect=ward-complaints";
                                }
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

                    <div class="nt-empty">
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

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/notifications.js"></script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const typeSelect = document.getElementById("type");
        const readSelect = document.getElementById("read");
        const filterForm = document.getElementById("ntFilterForm");

        if (typeSelect && filterForm) {
            typeSelect.addEventListener("change", function() {
                filterForm.submit();
            });
        }
        
        if (readSelect && filterForm) {
            readSelect.addEventListener("change", function() {
                filterForm.submit();
            });
        }
    });
</script>

</body>
</html>
