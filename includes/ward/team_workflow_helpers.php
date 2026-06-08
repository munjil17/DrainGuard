<?php

require_once __DIR__ . "/../notification_workflow_cleanup.php";

function wtw_fetch_one($conn, $sql, $types = "", $params = [])
{
    $rows = wtw_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function wtw_fetch_all($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception("Unable to load records. " . mysqli_error($conn));
    }

    if ($types !== "" && count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception("Unable to load records. " . $error);
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);
    return $rows;
}

function wtw_ward_context($conn, $userId)
{
    $row = wtw_fetch_one($conn, "
        SELECT
            wo.ward_officer_id,
            wo.user_id,
            wo.assigned_ward_id,
            wo.full_name,
            w.ward_no,
            w.ward_name,
            w.city_cor_id,
            w.anchal_id,
            an.anchal_name,
            cc.city_cor_name
        FROM ward_officers wo
        INNER JOIN wards w ON wo.assigned_ward_id = w.ward_id
        LEFT JOIN anchals an ON w.anchal_id = an.anchal_id
        LEFT JOIN city_corporations cc ON w.city_cor_id = cc.city_cor_id
        WHERE wo.user_id = ?
        LIMIT 1
    ", "i", [(int)$userId]);

    if (!$row) {
        throw new Exception("No assigned ward found for this Ward Officer.");
    }

    return [
        "ward_id" => (int)$row["assigned_ward_id"],
        "ward_no" => $row["ward_no"] ?? "",
        "ward_name" => $row["ward_name"] ?? "",
        "city_cor_id" => (int)($row["city_cor_id"] ?? 0),
        "anchal_id" => (int)($row["anchal_id"] ?? 0),
        "anchal_name" => $row["anchal_name"] ?? "Assigned Anchal",
        "city_cor_name" => $row["city_cor_name"] ?? "City Corporation",
        "full_name" => $row["full_name"] ?? "Ward Officer",
    ];
}

function wtw_anchal_teams($conn, $cityCorId, $anchalId)
{
    return wtw_fetch_all($conn, "
        SELECT
            maintenance_team_id,
            team_name,
            availability_status
        FROM maintenance_teams
        WHERE city_cor_id = ?
        AND anchal_id = ?
        ORDER BY team_name ASC
    ", "ii", [(int)$cityCorId, (int)$anchalId]);
}

function wtw_team_in_anchal($conn, $teamId, $cityCorId, $anchalId)
{
    $row = wtw_fetch_one($conn, "
        SELECT maintenance_team_id, team_name
        FROM maintenance_teams
        WHERE maintenance_team_id = ?
        AND city_cor_id = ?
        AND anchal_id = ?
        LIMIT 1
    ", "iii", [(int)$teamId, (int)$cityCorId, (int)$anchalId]);

    if (!$row) {
        throw new Exception("Selected team is outside your assigned anchal.");
    }

    return $row;
}

function wtw_team_member_user_ids($conn, $teamId)
{
    $rows = wtw_fetch_all($conn, "
        SELECT user_id
        FROM maintenance_team_members
        WHERE maintenance_team_id = ?
        AND status = 'active'
        AND member_status = 'active'
        AND user_id IS NOT NULL
        ORDER BY
            CASE role WHEN 'team_leader' THEN 1 WHEN 'assistant_team_leader' THEN 2 ELSE 3 END,
            member_id ASC
    ", "i", [(int)$teamId]);

    return array_values(array_unique(array_map(function ($row) {
        return (int)$row["user_id"];
    }, $rows)));
}

function wtw_insert_maintenance_notification($conn, $recipientUserId, $senderUserId, $complaintId, $type, $title, $message)
{
    if ((int)$recipientUserId <= 0) return;

    dg_cleanup_workflow_notifications($conn, "maintenance_notifications", $recipientUserId, $complaintId, $type);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO maintenance_notifications
            (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
    ");
    if (!$stmt) {
        throw new Exception("Unable to prepare team notification. " . mysqli_error($conn));
    }

    $complaintIdValue = (int)$complaintId > 0 ? (int)$complaintId : null;
    mysqli_stmt_bind_param($stmt, "iiisss", $recipientUserId, $senderUserId, $complaintIdValue, $type, $title, $message);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception("Unable to send team notification. " . $error);
    }

    mysqli_stmt_close($stmt);
}

function wtw_notify_team($conn, $teamId, $senderUserId, $complaintId, $type, $title, $message)
{
    $memberIds = wtw_team_member_user_ids($conn, $teamId);
    if (count($memberIds) === 0) {
        throw new Exception("No active login user was found for the selected maintenance team.");
    }

    foreach ($memberIds as $memberId) {
        wtw_insert_maintenance_notification($conn, $memberId, $senderUserId, $complaintId, $type, $title, $message);
    }
}

function wtw_insert_role_notification($conn, $table, $recipientUserId, $senderUserId, $complaintId, $type, $title, $message)
{
    if ((int)$recipientUserId <= 0) return;

    dg_cleanup_workflow_notifications($conn, $table, $recipientUserId, $complaintId, $type);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO {$table}
            (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
    ");
    if (!$stmt) {
        throw new Exception("Unable to prepare notification. " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "iiisss", $recipientUserId, $senderUserId, $complaintId, $type, $title, $message);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception("Unable to send notification. " . $error);
    }

    mysqli_stmt_close($stmt);
}

function wtw_related_central_officer_id($conn, $complaintId, $fallbackUserId = 0)
{
    $row = wtw_fetch_one($conn, "
        SELECT ca.assigned_by
        FROM complaint_assignments ca
        INNER JOIN users u ON u.user_id = ca.assigned_by
        WHERE ca.complaint_id = ?
        AND u.user_role = 'central_officer'
        ORDER BY ca.assignment_id DESC
        LIMIT 1
    ", "i", [(int)$complaintId]);

    $centralUserId = (int)($row["assigned_by"] ?? 0);
    if ($centralUserId > 0) {
        return $centralUserId;
    }

    $fallbackUserId = (int)$fallbackUserId;
    if ($fallbackUserId <= 0) {
        return 0;
    }

    $fallback = wtw_fetch_one($conn, "
        SELECT user_id
        FROM users
        WHERE user_id = ?
        AND user_role = 'central_officer'
        LIMIT 1
    ", "i", [$fallbackUserId]);

    return (int)($fallback["user_id"] ?? 0);
}

function wtw_instruction_default_message($type)
{
    $messages = [
        "verify_new_complaint" => "Please review the new complaint details and verify the field condition as soon as possible.",
        "visit_complaint_location" => "Please visit the complaint location and report the current site condition.",
        "assign_maintenance_team" => "Please coordinate with the assigned maintenance members and prepare for the required work.",
        "follow_up_pending_complaint" => "Please follow up on this pending complaint and share the latest status update.",
        "review_reopened_complaint" => "Please review this reopened complaint and confirm what additional work or verification is needed.",
        "monitor_high_risk_area" => "Please monitor the high risk area and report any urgent drainage condition immediately.",
        "submit_ward_status_report" => "Please submit the latest ward status report with current work progress and pending issues.",
        "custom_instruction" => "",
    ];

    return $messages[$type] ?? "";
}

function wtw_instruction_label($type)
{
    $labels = [
        "verify_new_complaint" => "Verify New Complaint",
        "visit_complaint_location" => "Visit Complaint Location",
        "assign_maintenance_team" => "Assign Maintenance Team",
        "follow_up_pending_complaint" => "Follow Up Pending Complaint",
        "review_reopened_complaint" => "Review Reopened Complaint",
        "monitor_high_risk_area" => "Monitor High Risk Area",
        "submit_ward_status_report" => "Submit Ward Status Report",
        "custom_instruction" => "Custom Instruction",
    ];

    return $labels[$type] ?? "Instruction";
}

function wtw_validate_instruction_type($type)
{
    $allowed = array_keys([
        "verify_new_complaint" => true,
        "visit_complaint_location" => true,
        "assign_maintenance_team" => true,
        "follow_up_pending_complaint" => true,
        "review_reopened_complaint" => true,
        "monitor_high_risk_area" => true,
        "submit_ward_status_report" => true,
        "custom_instruction" => true,
    ]);

    if (!in_array($type, $allowed, true)) {
        throw new Exception("Please select a valid instruction type.");
    }
}

function wtw_send_instruction($conn, $wardContext, $senderUserId, $teamId, $instructionType, $message)
{
    wtw_team_in_anchal($conn, $teamId, $wardContext["city_cor_id"], $wardContext["anchal_id"]);
    wtw_validate_instruction_type($instructionType);

    $message = trim((string)$message);
    if ($message === "") {
        throw new Exception("Instruction message cannot be empty.");
    }

    $label = wtw_instruction_label($instructionType);
    $body = "{$label}: {$message}";

    wtw_notify_team($conn, $teamId, $senderUserId, 0, "ward_team_instruction", "Ward Officer Instruction", $body);
}

function wtw_current_assignment_for_change($conn, $complaintId, $assignmentId, $wardId, $mode)
{
    $expectedComplaintStatus = $mode === "in_progress" ? "in_progress" : "team_assigned";
    $expectedAssignmentStatus = $mode === "in_progress" ? "in_progress" : "team_assigned";

    $row = wtw_fetch_one($conn, "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.ward_id,
            ca.maintenance_team_id,
            ca.assigned_by,
            ca.assignment_status,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,
            c.complaint_code,
            c.complaint_status,
            mt.team_name AS current_team_name
        FROM complaint_assignments ca
        INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
        INNER JOIN locations l ON c.loc_id = l.loc_id
        LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
        WHERE ca.assignment_id = ?
        AND ca.complaint_id = ?
        AND ca.ward_id = ?
        AND l.ward_id = ?
        AND ca.assignment_id = (
            SELECT MAX(ca2.assignment_id)
            FROM complaint_assignments ca2
            WHERE ca2.complaint_id = ca.complaint_id
        )
        LIMIT 1
    ", "iiii", [(int)$assignmentId, (int)$complaintId, (int)$wardId, (int)$wardId]);

    if (!$row) {
        throw new Exception("This task is not available for team change.");
    }

    if ($row["complaint_status"] !== $expectedComplaintStatus || $row["assignment_status"] !== $expectedAssignmentStatus) {
        throw new Exception("This task is not in the correct stage for this team change.");
    }

    if (in_array($row["complaint_status"], ["solved_by_team", "inspector_verification", "closed", "rejected_by_central", "rejected_by_ward", "duplicate", "final_rejected"], true)) {
        throw new Exception("This complaint can no longer be reassigned.");
    }

    $proof = wtw_fetch_one($conn, "
        SELECT proof_id
        FROM maintenance_proofs
        WHERE assignment_id = ?
        AND proof_stage = 'after'
        AND proof_status = 'submitted'
        LIMIT 1
    ", "i", [(int)$assignmentId]);

    if ($proof) {
        throw new Exception("This task already has submitted final proof and cannot be reassigned.");
    }

    return $row;
}

function wtw_change_team($conn, $wardContext, $senderUserId, $complaintId, $assignmentId, $newTeamId, $reason, $instruction, $mode)
{
    $reason = trim((string)$reason);
    $instruction = trim((string)$instruction);

    if ($reason === "") {
        throw new Exception("Reason is required for team change.");
    }

    $assignment = wtw_current_assignment_for_change($conn, $complaintId, $assignmentId, $wardContext["ward_id"], $mode);
    $newTeam = wtw_team_in_anchal($conn, $newTeamId, $wardContext["city_cor_id"], $wardContext["anchal_id"]);
    $oldTeamId = (int)$assignment["maintenance_team_id"];

    if ($oldTeamId === (int)$newTeamId) {
        throw new Exception("Please select a different maintenance team.");
    }

    $noteParts = [];
    if (trim((string)$assignment["task_note"]) !== "") {
        $noteParts[] = trim((string)$assignment["task_note"]);
    }
    $noteParts[] = "Team changed by Ward Officer. Reason: " . $reason;
    if ($instruction !== "") {
        $noteParts[] = "Instruction: " . $instruction;
    }
    $newTaskNote = implode("\n\n", $noteParts);

    $stmt = mysqli_prepare($conn, "
        UPDATE complaint_assignments
        SET maintenance_team_id = ?,
            assignment_status = 'team_assigned',
            task_note = ?,
            assigned_at = CURRENT_TIMESTAMP
        WHERE assignment_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Unable to update task ownership.");
    }

    mysqli_stmt_bind_param($stmt, "isi", $newTeamId, $newTaskNote, $assignmentId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new Exception("Unable to update task ownership.");
    }
    mysqli_stmt_close($stmt);

    $complaintStmt = mysqli_prepare($conn, "
        UPDATE complaints
        SET complaint_status = 'team_assigned',
            updated_at = CURRENT_TIMESTAMP
        WHERE complaint_id = ?
    ");
    if (!$complaintStmt) {
        throw new Exception("Unable to update complaint status.");
    }

    mysqli_stmt_bind_param($complaintStmt, "i", $complaintId);
    if (!mysqli_stmt_execute($complaintStmt)) {
        mysqli_stmt_close($complaintStmt);
        throw new Exception("Unable to update complaint status.");
    }
    mysqli_stmt_close($complaintStmt);

    $complaintCode = (string)$assignment["complaint_code"];
    $newTeamName = (string)$newTeam["team_name"];
    $oldMessage = "This task has been reassigned by the Ward Officer. Your team is no longer responsible for this complaint.";
    $newMessage = "A task has been reassigned to your team by the Ward Officer. Please check Assigned Tasks and start work.";

    wtw_notify_team($conn, $oldTeamId, $senderUserId, $complaintId, "ward_team_reassign_removed", "Task Reassigned Away", $oldMessage);
    wtw_notify_team($conn, $newTeamId, $senderUserId, $complaintId, "ward_team_reassigned", "Task Reassigned to Your Team", $newMessage);

    $complaintUser = wtw_fetch_one($conn, "
        SELECT user_id, complaint_code
        FROM complaints
        WHERE complaint_id = ?
        LIMIT 1
    ", "i", [(int)$complaintId]);

    $citizenUserId = (int)($complaintUser["user_id"] ?? 0);
    $centralOfficerId = wtw_related_central_officer_id($conn, $complaintId, (int)($assignment["assigned_by"] ?? 0));
    $citizenMessage = "The maintenance team for complaint #{$complaintCode} has been changed by the Ward Officer. Please track your complaint for updates.";
    $centralMessage = "Ward Officer reassigned the maintenance team for complaint #{$complaintCode}. The complaint is back in Team Assigned status.";

    wtw_insert_role_notification(
        $conn,
        "citizen_notifications",
        $citizenUserId,
        $senderUserId,
        $complaintId,
        "complaint_status_updated",
        "Maintenance Team Reassigned",
        $citizenMessage
    );

    wtw_insert_role_notification(
        $conn,
        "central_notifications",
        $centralOfficerId,
        $senderUserId,
        $complaintId,
        "ward_team_reassigned",
        "Maintenance Team Reassigned",
        $centralMessage
    );

    if (function_exists("autoUpdateTeamAvailability")) {
        autoUpdateTeamAvailability($conn, $oldTeamId);
        autoUpdateTeamAvailability($conn, $newTeamId);
    }

    return $newTeamName;
}
