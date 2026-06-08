<?php
$activePage = "notifications";
$pageTitle = "Notifications";

require_once "../../config.php";
$allowed_role = "inspector";
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
    if (in_array($type, ['solved_by_team', 'completion_proof', 'review_request'])) return 'bi-file-earmark-text';
    if (in_array($type, ['inspection_queue', 'reopened_case', 're_verification'])) return 'bi-arrow-repeat';
    if (in_array($type, ['work_approved', 'inspection_decision'])) return 'bi-clipboard-check';
    if (in_array($type, ['false_completion', 'citizen_objection', 'system', 'alert'])) return 'bi-exclamation-triangle';
    if ($type === 'comment_reply') return 'bi-chat-dots';
    return "bi-bell";
}

function nt_type_class($type)
{
    $type = strtolower(trim((string)$type));
    if (in_array($type, ['solved_by_team', 'completion_proof', 'review_request', 'inspection_queue', 'reopened_case', 're_verification'])) return 'type-track';
    if (in_array($type, ['false_completion', 'citizen_objection', 'system', 'alert'])) return 'type-objection';
    if ($type === 'comment_reply') return 'type-reply';
    return "type-system";
}

function nt_type_label($type)
{
    $type = strtolower(trim((string)$type));
    $labels = [
        "central_instruction" => "Central Instruction",
        "citizen_objection_submitted" => "Citizen Submitted Objection",
        "maintenance_completion_proof_submitted" => "Completion Proof Submitted",
        "system" => "System",
        "ward_citizen_claim_true" => "Citizen Claim Marked True",
        "ward_confirm_inspector_claim" => "Inspector Claim Confirmed by Ward Officer",
        "ward_reject_inspector_claim" => "Inspector Claim Rejected by Ward Officer"
    ];

    return $labels[$type] ?? ucwords(str_replace("_", " ", $type));
}

if (isset($_GET["read_id"])) {
    $readId = (int)$_GET["read_id"];
    $redirectType = trim($_GET["redirect"] ?? "");

    if ($readId > 0 && $userId > 0 && isset($conn) && $conn instanceof mysqli) {
        $readSql = "SELECT inn.is_read, inn.related_complaint_id, inn.notification_type, c.complaint_code FROM inspector_notifications inn LEFT JOIN complaints c ON inn.related_complaint_id = c.complaint_id WHERE inn.notification_id = ? AND inn.recipient_user_id = ?";
        $readStmt = mysqli_prepare($conn, $readSql);
        
        if ($readStmt) {
            mysqli_stmt_bind_param($readStmt, "ii", $readId, $userId);
            mysqli_stmt_execute($readStmt);
            $readResult = mysqli_stmt_get_result($readStmt);
            
            if ($readResult && mysqli_num_rows($readResult) === 1) {
                $readRow = mysqli_fetch_assoc($readResult);
                
                $updateSql = "UPDATE inspector_notifications SET is_read = 1 WHERE notification_id = ? AND recipient_user_id = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);
                if ($updateStmt) {
                    mysqli_stmt_bind_param($updateStmt, "ii", $readId, $userId);
                    mysqli_stmt_execute($updateStmt);
                    mysqli_stmt_close($updateStmt);
                }
                mysqli_stmt_close($readStmt);
                
                if ($redirectType === "tasks" || $redirectType === "verification-queue" || $redirectType === "inspection-queue") {
                    $complaintIdParam = $readRow["related_complaint_id"] ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&focus=1" : "";
                    header("Location: inspection-queue.php" . $complaintIdParam);
                    exit;
                }
                
                if ($redirectType === "solved-cases") {
                    $complaintIdParam = $readRow["related_complaint_id"] ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&focus=1" : "";
                    header("Location: solved-cases.php" . $complaintIdParam);
                    exit;
                }

                if ($redirectType === "false-completion-reports") {
                    $complaintIdParam = $readRow["related_complaint_id"] ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&focus=1" : "";
                    header("Location: false-completion-reports.php" . $complaintIdParam);
                    exit;
                }

                if ($redirectType === "inspection-logs" || in_array($readRow["notification_type"], ['ward_reject_inspector_claim', 'ward_citizen_claim_true', 'ward_citizen_claim_false', 'citizen_objection_submitted'])) {
                    $complaintIdParam = $readRow["related_complaint_id"] ? "?complaint_id=" . urlencode($readRow["related_complaint_id"]) . "&focus=1" : "";
                    header("Location: inspection-logs.php" . $complaintIdParam);
                    exit;
                }
                
                if ($redirectType === "discussion" && !empty($readRow["related_complaint_id"])) {
                    header("Location: discussion.php?id=" . urlencode($readRow["related_complaint_id"]));
                    exit;
                }
                
                if ($redirectType === "instruction") {
                    $mapSql = "SELECT instruction_id FROM instruction_notifications_map WHERE notification_id = ? AND role_type = 'inspector' LIMIT 1";
                    $mapStmt = mysqli_prepare($conn, $mapSql);
                    if ($mapStmt) {
                        mysqli_stmt_bind_param($mapStmt, "i", $readId);
                        mysqli_stmt_execute($mapStmt);
                        $mapResult = mysqli_stmt_get_result($mapStmt);
                        $mapRow = $mapResult ? mysqli_fetch_assoc($mapResult) : null;
                        mysqli_stmt_close($mapStmt);
                        
                        if ($mapRow) {
                            header("Location: instruction-details.php?id=" . urlencode($mapRow["instruction_id"]));
                            exit;
                        }
                    }
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
    $markAllSql = "UPDATE inspector_notifications SET is_read = 1 WHERE recipient_user_id = ?";
    $markAllStmt = mysqli_prepare($conn, $markAllSql);
    if ($markAllStmt) {
        mysqli_stmt_bind_param($markAllStmt, "i", $userId);
        mysqli_stmt_execute($markAllStmt);
        mysqli_stmt_close($markAllStmt);
    }
    header("Location: /DrainGuard/pages/inspector/notifications.php");
    exit;
}

$availableTypes = [];
$typeSql = "SELECT DISTINCT notification_type FROM inspector_notifications WHERE recipient_user_id = ? ORDER BY notification_type";
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

$whereSql = "inn.recipient_user_id = ?";
$params = [$userId];
$types = "i";

if ($filterType !== "all") {
    $whereSql .= ($whereSql ? " AND " : "") . "inn.notification_type = ?";
    $params[] = $filterType;
    $types .= "s";
}

if ($filterRead === "unread") {
    $whereSql .= ($whereSql ? " AND " : "") . "inn.is_read = 0";
}

if ($filterRead === "read") {
    $whereSql .= ($whereSql ? " AND " : "") . "inn.is_read = 1";
}

if ($whereSql !== "") {
    $whereSql = "WHERE " . $whereSql;
}

$totalNotifications = 0;
$countSql = "SELECT COUNT(*) AS total FROM inspector_notifications inn {$whereSql}";
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
    {$whereSql}
    ORDER BY inn.created_at DESC, inn.notification_id DESC
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
$unreadTotalSql = "SELECT COUNT(*) AS unread_total FROM inspector_notifications WHERE is_read = 0 AND recipient_user_id = ?";
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
    <title>Notifications | Inspector | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
  
    <link rel="stylesheet" href="../../css/inspector/notifications.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="inspector">

<div class="dg-inspector-layout" style="display: flex; min-height: 100vh;">

    <?php include "../../includes/inspector/sidebar.php"; ?>

    <main class="inspector-main" style="flex: 1; min-width: 0;">

        <?php include "../../includes/inspector/topbar.php"; ?>

        <section class="nt-page" style="padding: 30px; max-width: 1200px; margin: 0 auto; width: 100%; box-sizing: border-box;">

            <div class="nt-header">
                <div class="nt-header-left">
                    <a href="dashboard.php" class="nt-back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Back
                    </a>

                    <div>
                        <h1>Notifications</h1>
                        <p>Track inspection requests, verification queues, and alerts</p>
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
                            if ($notificationType === 'central_instruction') {
                                $linkUrl .= "&redirect=instruction";
                            } elseif (!empty($notification["complaint_code"])) {
                                if ($notificationType === 'comment_reply') {
                                    $linkUrl .= "&redirect=discussion";
                                } elseif ($notification["notification_title"] === 'Inspection Required' || $notificationType === 'maintenance_completion_proof_submitted') {
                                    $linkUrl .= "&redirect=solved-cases";
                                } elseif ($notificationType === 'ward_confirm_inspector_claim') {
                                    $linkUrl .= "&redirect=false-completion-reports";
                                } elseif (in_array($notificationType, ['ward_reject_inspector_claim', 'ward_citizen_claim_true', 'ward_citizen_claim_false', 'citizen_objection_submitted'])) {
                                    $linkUrl .= "&redirect=inspection-logs";
                                } else {
                                    $linkUrl .= "&redirect=inspection-queue";
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

<script src="../../js/inspector/sidebar.js"></script>
<script src="../../js/inspector/notifications.js"></script>

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

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
