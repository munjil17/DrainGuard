<?php
// C:\xampp\htdocs\DrainGuard\commentSystem\discussion_logic.php

if (!isset($conn)) {
    require_once __DIR__ . '/../config.php';
}

/**
 * Returns context array:
 * [
 *   "status" => "rejected_by_central", // or other status
 *   "citizen_id" => 1,
 *   "central_officer_id" => 5,
 *   "ward_officer_id" => 12,
 *   "complaint_code" => "DG-..."
 * ]
 */
function cs_get_discussion_context($conn, $complaintId) {
    $context = [
        "status" => "",
        "citizen_id" => 0,
        "central_officer_id" => 0,
        "ward_officer_id" => 0,
        "complaint_code" => ""
    ];

    // Fetch complaint status and citizen_id
    $sql = "SELECT complaint_status, user_id, complaint_code FROM complaints WHERE complaint_id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $complaintId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $context["status"] = (string)$row["complaint_status"];
            $context["citizen_id"] = (int)$row["user_id"];
            $context["complaint_code"] = (string)$row["complaint_code"];
        }
        mysqli_stmt_close($stmt);
    }

    if (!$context["status"]) {
        return $context;
    }

    $status = cs_infer_rejected_status($conn, $complaintId, $context["status"]);
    $context["status"] = $status;

    if ($status === "rejected_by_central") {
        $context["central_officer_id"] = cs_find_central_rejection_user_id($conn, $complaintId);
    } elseif (in_array($status, ["rejected_by_ward", "duplicate", "final_rejected"], true)) {
        $routeContext = cs_find_routed_officers($conn, $complaintId);
        $context["central_officer_id"] = $routeContext["central_officer_id"];
        $context["ward_officer_id"] = $routeContext["ward_officer_id"];

        $wardRejectUserId = cs_find_ward_rejection_user_id($conn, $complaintId);
        if ($wardRejectUserId > 0) {
            $context["ward_officer_id"] = $wardRejectUserId;
        }
    }

    return $context;
}

function cs_table_exists($conn, $tableName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, "s", $tableName);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ["total" => 0];
    mysqli_stmt_close($stmt);
    return (int)$row["total"] > 0;
}

function cs_column_exists($conn, $tableName, $columnName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, "ss", $tableName, $columnName);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ["total" => 0];
    mysqli_stmt_close($stmt);
    return (int)$row["total"] > 0;
}

function cs_normalize_rejected_status($status)
{
    $status = strtolower(trim((string)$status));
    if ($status === "rejected") {
        return "rejected_by_central";
    }
    return $status;
}

function cs_infer_rejected_status($conn, $complaintId, $status)
{
    $status = strtolower(trim((string)$status));
    if ($status !== "rejected") {
        return cs_normalize_rejected_status($status);
    }

    if (cs_table_exists($conn, "complaint_decisions")) {
        $hasRole = cs_column_exists($conn, "complaint_decisions", "decided_by_role");
        $roleSelect = $hasRole ? "decided_by_role" : "'' AS decided_by_role";
        $sql = "SELECT decision_type, {$roleSelect}
                FROM complaint_decisions
                WHERE complaint_id = ?
                ORDER BY created_at DESC
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $complaintId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $decisionType = strtolower(trim((string)($row["decision_type"] ?? "")));
                $decidedByRole = strtolower(trim((string)($row["decided_by_role"] ?? "")));
                mysqli_stmt_close($stmt);
                if ($decidedByRole === "ward_officer" || $decisionType === "ward_reject") {
                    return "rejected_by_ward";
                }
                if ($decidedByRole === "central_officer" || $decisionType === "central_reject") {
                    return "rejected_by_central";
                }
                return "rejected_by_central";
            }
            mysqli_stmt_close($stmt);
        }
    }

    return "rejected_by_central";
}

function cs_find_central_rejection_user_id($conn, $complaintId)
{
    if (cs_table_exists($conn, "complaint_status_logs")) {
        $orderColumn = cs_column_exists($conn, "complaint_status_logs", "log_id") ? "log_id" : "created_at";
        $sql = "SELECT action_by_user_id
                FROM complaint_status_logs
                WHERE complaint_id = ?
                AND action_by_role = 'central_officer'
                AND new_status IN ('rejected_by_central', 'rejected')
                ORDER BY {$orderColumn} DESC
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $complaintId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $userId = (int)$row["action_by_user_id"];
                if ($userId > 0) {
                    mysqli_stmt_close($stmt);
                    return $userId;
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    if (cs_table_exists($conn, "complaint_decisions") && cs_column_exists($conn, "complaint_decisions", "decided_by_user_id")) {
        $sql = "SELECT decided_by_user_id
                FROM complaint_decisions
                WHERE complaint_id = ?
                AND decision_type IN ('central_reject', 'rejected_by_central', 'rejected')
                ORDER BY created_at DESC
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $complaintId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $userId = (int)$row["decided_by_user_id"];
                if ($userId > 0) {
                    mysqli_stmt_close($stmt);
                    return $userId;
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    return 0;
}

function cs_find_ward_rejection_user_id($conn, $complaintId)
{
    if (cs_table_exists($conn, "complaint_decisions") && cs_column_exists($conn, "complaint_decisions", "decided_by_user_id")) {
        $sql = "SELECT decided_by_user_id
                FROM complaint_decisions
                WHERE complaint_id = ?
                AND decision_type IN ('ward_reject', 'rejected_by_ward', 'duplicate', 'final_rejected', 'rejected')
                ORDER BY created_at DESC
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $complaintId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $userId = (int)$row["decided_by_user_id"];
                if ($userId > 0) {
                    mysqli_stmt_close($stmt);
                    return $userId;
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    if (!cs_table_exists($conn, "complaint_status_logs")) return 0;

    $orderColumn = cs_column_exists($conn, "complaint_status_logs", "log_id") ? "log_id" : "created_at";
    $sql = "SELECT action_by_user_id
            FROM complaint_status_logs
            WHERE complaint_id = ?
            AND action_by_role = 'ward_officer'
            AND new_status IN ('rejected_by_ward', 'duplicate', 'final_rejected', 'rejected')
            ORDER BY {$orderColumn} DESC
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, "i", $complaintId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row["action_by_user_id"] ?? 0);
}

function cs_find_routed_officers($conn, $complaintId)
{
    $context = [
        "central_officer_id" => 0,
        "ward_officer_id" => 0,
    ];

    $sql = "SELECT ca.assigned_by, wo.user_id AS ward_officer_id
            FROM complaint_assignments ca
            LEFT JOIN ward_officers wo ON ca.ward_id = wo.assigned_ward_id
            WHERE ca.complaint_id = ?
            ORDER BY ca.assignment_id DESC
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $complaintId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $context["central_officer_id"] = (int)$row["assigned_by"];
            $context["ward_officer_id"] = (int)$row["ward_officer_id"];
        }
        mysqli_stmt_close($stmt);
    }

    return $context;
}

/**
 * Returns true if the user is allowed to view/participate in the discussion.
 */
function cs_has_discussion_access($context, $userId, $userRole) {
    if (!$context["status"] || $userId <= 0) {
        return false;
    }

    $status = $context["status"];
    
    // Check supported statuses
    if (!in_array($status, ['rejected_by_central', 'rejected_by_ward', 'duplicate', 'final_rejected'], true)) {
        return false;
    }

    // Role checks
    if ($userRole === 'citizen') {
        return true;
    }

    if ($userRole === 'central_officer') {
        // Any central officer or only the specific one?
        // User rules: "Central Officer who sent/routed"
        return ($context["central_officer_id"] > 0 && $userId === $context["central_officer_id"]);
    }

    if ($userRole === 'ward_officer') {
        // Cannot access Central rejected complaints
        if ($status === 'rejected_by_central') {
            return false;
        }
        // Must be the assigned ward officer
        if ($context["ward_officer_id"] > 0) {
            return ($userId === $context["ward_officer_id"]);
        }
        return false; 
    }

    return false; // Maintenance, Inspector, etc.
}

/**
 * Inserts a notification into the given table.
 */
function cs_insert_notification($conn, $table, $recipientUserId, $senderUserId, $complaintId, $title, $message, $notificationType = "comment_reply") {
    if ($recipientUserId <= 0 || $recipientUserId === (int)$senderUserId) return;
    $sql = "INSERT INTO {$table} (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iiisss", $recipientUserId, $senderUserId, $complaintId, $notificationType, $title, $message);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function cs_notify_unique($conn, $table, &$sent, $recipientUserId, $senderUserId, $complaintId, $title, $message, $notificationType = "comment_reply")
{
    $recipientUserId = (int)$recipientUserId;
    if ($recipientUserId <= 0 || $recipientUserId === (int)$senderUserId) return;
    $key = $table . ":" . $recipientUserId;
    if (isset($sent[$key])) return;
    $sent[$key] = true;
    cs_insert_notification($conn, $table, $recipientUserId, $senderUserId, $complaintId, $title, $message, $notificationType);
}

function cs_citizen_participant_ids($conn, $context, $complaintId)
{
    $ids = [];
    if ((int)$context["citizen_id"] > 0) {
        $ids[(int)$context["citizen_id"]] = true;
    }

    $sql = "SELECT DISTINCT cc.user_id
            FROM comment_likes cc
            INNER JOIN users u ON cc.user_id = u.user_id
            WHERE cc.complaint_id = ?
            AND cc.type = 'comment'
            AND cc.is_deleted = 0
            AND u.user_role = 'citizen'";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $complaintId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && $row = mysqli_fetch_assoc($res)) {
            $ids[(int)$row["user_id"]] = true;
        }
        mysqli_stmt_close($stmt);
    }

    return array_keys($ids);
}

/**
 * Dispatches notifications based on the actor's role and the complaint context.
 */
function cs_dispatch_notifications($conn, $context, $actorUserId, $actorRole, $complaintId, $isReply = false, $sent = []) {
    $status = $context["status"];
    $cCode = $context["complaint_code"] ?: ("#" . $complaintId);
    $notificationType = ($actorRole === "citizen") ? "citizen_discussion_reply" : "comment_reply";
    
    $title = $isReply ? "New Reply to Your Comment" : "New Comment on Complaint";
    $message = $isReply 
        ? "Someone replied to your comment on complaint {$cCode}."
        : "A new comment was added to complaint {$cCode}.";

    $sent = [];

    if ($status === 'rejected_by_central') {
        if ($actorRole === 'citizen') {
            cs_notify_unique($conn, "central_notifications", $sent, $context["central_officer_id"], $actorUserId, $complaintId, $title, $message, $notificationType);
        } elseif ($actorRole === 'central_officer') {
            foreach (cs_citizen_participant_ids($conn, $context, $complaintId) as $citizenId) {
                cs_notify_unique($conn, "citizen_notifications", $sent, $citizenId, $actorUserId, $complaintId, $title, $message, $notificationType);
            }
        }
    } elseif (in_array($status, ['rejected_by_ward', 'duplicate', 'final_rejected'], true)) {
        if ($actorRole === 'citizen') {
            cs_notify_unique($conn, "ward_notifications", $sent, $context["ward_officer_id"], $actorUserId, $complaintId, $title, $message, $notificationType);
        } elseif ($actorRole === 'central_officer') {
            foreach (cs_citizen_participant_ids($conn, $context, $complaintId) as $citizenId) {
                cs_notify_unique($conn, "citizen_notifications", $sent, $citizenId, $actorUserId, $complaintId, $title, $message, $notificationType);
            }
            cs_notify_unique($conn, "ward_notifications", $sent, $context["ward_officer_id"], $actorUserId, $complaintId, $title, $message, $notificationType);
        } elseif ($actorRole === 'ward_officer') {
            foreach (cs_citizen_participant_ids($conn, $context, $complaintId) as $citizenId) {
                cs_notify_unique($conn, "citizen_notifications", $sent, $citizenId, $actorUserId, $complaintId, $title, $message, $notificationType);
            }
            cs_notify_unique($conn, "central_notifications", $sent, $context["central_officer_id"], $actorUserId, $complaintId, $title, $message, $notificationType);
        }
    }

    return $sent;
}

function cs_notification_table_for_role($role)
{
    $role = strtolower(trim((string)$role));
    if ($role === "central_officer") return "central_notifications";
    if ($role === "ward_officer") return "ward_notifications";
    if ($role === "citizen") return "citizen_notifications";
    if (in_array($role, ["team_leader", "assistant_team_leader", "worker"], true)) return "maintenance_notifications";
    if ($role === "inspector") return "inspector_notifications";
    return "";
}

function cs_dispatch_reply_notification($conn, $parentRow, $actorUserId, $complaintId, $notificationType = "comment_reply")
{
    $recipientUserId = (int)($parentRow["parent_user_id"] ?? 0);
    $recipientRole = (string)($parentRow["parent_user_role"] ?? "");
    if ($recipientUserId <= 0 || $recipientUserId === (int)$actorUserId) return [];

    $table = cs_notification_table_for_role($recipientRole);
    if ($table === "") return [];

    $complaintCode = (string)($parentRow["complaint_code"] ?? "");
    $cCode = $complaintCode !== "" ? $complaintCode : ("#" . $complaintId);
    cs_insert_notification(
        $conn,
        $table,
        $recipientUserId,
        $actorUserId,
        $complaintId,
        "New Reply to Your Comment",
        "Someone replied to your comment on complaint {$cCode}.",
        $notificationType
    );

    return [$table . ":" . $recipientUserId => true];
}
