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

    if ($context["status"] === "rejected_by_central") {
        // Find the central officer who rejected it from complaint_status_logs
        $sql = "SELECT action_by_user_id FROM complaint_status_logs 
                WHERE complaint_id = ? AND action_by_role = 'central_officer' AND status_to = 'rejected_by_central' 
                ORDER BY log_id DESC LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $complaintId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $context["central_officer_id"] = (int)$row["action_by_user_id"];
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        // For ward rejected / duplicate / etc, find central officer and ward officer via assignment
        $sql = "SELECT ca.assigned_by, wo.user_id AS ward_officer_id 
                FROM complaint_assignments ca 
                LEFT JOIN ward_officers wo ON ca.ward_id = wo.assigned_ward_id 
                WHERE ca.complaint_id = ? LIMIT 1";
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
        return ($userId === $context["citizen_id"]);
    }

    if ($userRole === 'central_officer') {
        // Any central officer or only the specific one?
        // User rules: "Central Officer who sent/routed"
        if ($context["central_officer_id"] > 0) {
            return ($userId === $context["central_officer_id"]);
        }
        return true; // fallback if assignment not found
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
function cs_insert_notification($conn, $table, $recipientUserId, $senderUserId, $complaintId, $title, $message) {
    if ($recipientUserId <= 0) return;
    $sql = "INSERT INTO {$table} (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at)
            VALUES (?, ?, ?, 'comment_reply', ?, ?, 0, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iiiss", $recipientUserId, $senderUserId, $complaintId, $title, $message);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/**
 * Dispatches notifications based on the actor's role and the complaint context.
 */
function cs_dispatch_notifications($conn, $context, $actorUserId, $actorRole, $complaintId, $isReply = false) {
    $status = $context["status"];
    $cCode = $context["complaint_code"] ?: ("#" . $complaintId);
    
    $title = $isReply ? "New Reply to Your Comment" : "New Comment on Complaint";
    $message = $isReply 
        ? "Someone replied to your comment on complaint {$cCode}."
        : "A new comment was added to complaint {$cCode}.";

    // Case 1: Central Rejected
    if ($status === 'rejected_by_central') {
        if ($actorRole === 'citizen') {
            // Notify Central Officer
            cs_insert_notification($conn, "central_notifications", $context["central_officer_id"], $actorUserId, $complaintId, $title, $message);
        } elseif ($actorRole === 'central_officer') {
            // Notify Citizen
            cs_insert_notification($conn, "citizen_notifications", $context["citizen_id"], $actorUserId, $complaintId, $title, $message);
        }
    } 
    // Case 2 & 3: Ward Rejected / Duplicate
    elseif (in_array($status, ['rejected_by_ward', 'duplicate', 'final_rejected'], true)) {
        if ($actorRole === 'citizen') {
            // Notify Central & Ward
            cs_insert_notification($conn, "central_notifications", $context["central_officer_id"], $actorUserId, $complaintId, $title, $message);
            cs_insert_notification($conn, "ward_notifications", $context["ward_officer_id"], $actorUserId, $complaintId, $title, $message);
        } elseif ($actorRole === 'central_officer') {
            // Notify Citizen & Ward
            cs_insert_notification($conn, "citizen_notifications", $context["citizen_id"], $actorUserId, $complaintId, $title, $message);
            cs_insert_notification($conn, "ward_notifications", $context["ward_officer_id"], $actorUserId, $complaintId, $title, $message);
        } elseif ($actorRole === 'ward_officer') {
            // Notify Citizen & Central
            cs_insert_notification($conn, "citizen_notifications", $context["citizen_id"], $actorUserId, $complaintId, $title, $message);
            cs_insert_notification($conn, "central_notifications", $context["central_officer_id"], $actorUserId, $complaintId, $title, $message);
        }
    }
}
