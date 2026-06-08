<?php

if (!function_exists('dg_workflow_notification_types')) {
    function dg_workflow_notification_types()
    {
        return [
            'complaint_submitted',
            'complaint_received',
            'complaint_accepted',
            'complaint_rejected',
            'complaint_status_updated',
            'status_update',
            'verified',
            'rejected',
            'complaint_routed',
            'ward_accept_verify',
            'ward_reject',
            'ward_duplicate',
            'ward_reopen_assign_team',
            'ward_team_reassigned',
            'ward_in_progress_team_transfer',
            'task_assigned',
            'maintenance_start_work',
            'maintenance_completion_proof_submitted',
            'inspector_review_started',
            'inspector_work_approved',
            'inspector_false_completion_confirmed',
            'ward_citizen_claim_true',
            'ward_citizen_claim_false',
        ];
    }
}

if (!function_exists('dg_is_workflow_notification_type')) {
    function dg_is_workflow_notification_type($type)
    {
        return in_array(strtolower(trim((string)$type)), dg_workflow_notification_types(), true);
    }
}

if (!function_exists('dg_cleanup_workflow_notifications')) {
    function dg_cleanup_workflow_notifications($conn, $tableName, $recipientUserId, $complaintId, $newType)
    {
        $recipientUserId = (int)$recipientUserId;
        $complaintId = (int)$complaintId;
        $newType = strtolower(trim((string)$newType));

        if (!$conn || $recipientUserId <= 0 || $complaintId <= 0 || !dg_is_workflow_notification_type($newType)) {
            return;
        }

        $allowedTables = [
            'citizen_notifications' => true,
            'central_notifications' => true,
            'ward_notifications' => true,
            'maintenance_notifications' => true,
            'inspector_notifications' => true,
        ];

        if (!isset($allowedTables[$tableName])) {
            return;
        }

        $types = dg_workflow_notification_types();
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $sql = "
            DELETE FROM `$tableName`
            WHERE recipient_user_id = ?
            AND related_complaint_id = ?
            AND notification_type IN ($placeholders)
        ";

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return;
        }

        $bindTypes = 'ii' . str_repeat('s', count($types));
        $params = array_merge([$recipientUserId, $complaintId], $types);
        mysqli_stmt_bind_param($stmt, $bindTypes, ...$params);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('dg_insert_notification_with_workflow_cleanup')) {
    function dg_insert_notification_with_workflow_cleanup($conn, $tableName, $recipientUserId, $senderUserId, $complaintId, $type, $title, $message, $createdAt = null)
    {
        if ((int)$recipientUserId <= 0) {
            return false;
        }

        dg_cleanup_workflow_notifications($conn, $tableName, $recipientUserId, $complaintId, $type);

        if ($createdAt === null) {
            $sql = "
                INSERT INTO `$tableName`
                    (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
            ";
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) return false;
            mysqli_stmt_bind_param($stmt, "iiisss", $recipientUserId, $senderUserId, $complaintId, $type, $title, $message);
        } else {
            $sql = "
                INSERT INTO `$tableName`
                    (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?)
            ";
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) return false;
            mysqli_stmt_bind_param($stmt, "iiissss", $recipientUserId, $senderUserId, $complaintId, $type, $title, $message, $createdAt);
        }

        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}
