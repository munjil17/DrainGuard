<?php
require_once "../config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json");

$userId = $_SESSION['user_id'] ?? 0;

if ($userId <= 0) {
    echo json_encode(["success" => false, "message" => "Unauthorized request."]);
    exit;
}

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
$reason = trim($_POST['support_reason'] ?? '');
$otherReason = trim($_POST['other_reason'] ?? '');
$supportDetails = trim($_POST['support_details'] ?? '');

$allowedReasons = [
    'equipment_needed' => 'Equipment Needed',
    'extra_manpower_needed' => 'Extra Manpower Needed',
    'location_access_problem' => 'Location / Access Problem',
    'complaint_info_unclear' => 'Complaint Info Unclear',
    'safety_risk' => 'Safety Risk',
    'large_work_scope' => 'Large Work Scope',
    'others' => 'Others'
];

if ($assignmentId <= 0 || !array_key_exists($reason, $allowedReasons)) {
    echo json_encode(["success" => false, "message" => "Invalid support request."]);
    exit;
}

if ($reason === 'others' && empty($otherReason)) {
    echo json_encode(["success" => false, "message" => "Please specify your other reason."]);
    exit;
}

if (empty($supportDetails)) {
    echo json_encode(["success" => false, "message" => "Support details are required."]);
    exit;
}

mysqli_begin_transaction($conn);

try {
    // 1. Verify Assignment & Maintenance Team, and fetch Complaint Data
    $assignmentSql = "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.maintenance_team_id,
            c.complaint_code,
            c.complaint_status,
            l.ward_id,
            l.city_cor_id,
            wo.user_id AS dynamic_ward_officer_user_id
        FROM complaint_assignments ca
        INNER JOIN complaints c ON c.complaint_id = ca.complaint_id
        INNER JOIN locations l ON l.loc_id = c.loc_id
        INNER JOIN maintenance_team_members mtm ON mtm.maintenance_team_id = ca.maintenance_team_id
        LEFT JOIN ward_officers wo ON wo.assigned_ward_id = l.ward_id AND wo.city_cor_id = l.city_cor_id
        WHERE ca.assignment_id = ? AND mtm.user_id = ?
        LIMIT 1
    ";

    $assignmentStmt = mysqli_prepare($conn, $assignmentSql);
    mysqli_stmt_bind_param($assignmentStmt, "ii", $assignmentId, $userId);
    mysqli_stmt_execute($assignmentStmt);
    $assignmentResult = mysqli_stmt_get_result($assignmentStmt);

    if (!$assignmentResult || mysqli_num_rows($assignmentResult) === 0) {
        throw new Exception("Assignment not found or unauthorized.");
    }

    $assignment = mysqli_fetch_assoc($assignmentResult);
    mysqli_stmt_close($assignmentStmt);

    $complaintId = (int)$assignment['complaint_id'];
    $maintenanceTeamId = (int)$assignment['maintenance_team_id'];
    $wardOfficerUserId = (int)$assignment['dynamic_ward_officer_user_id'];
    $complaintCode = $assignment['complaint_code'];
    $complaintStatus = $assignment['complaint_status'];

    if ($wardOfficerUserId <= 0) {
        throw new Exception("Related Ward officer not found for this location.");
    }

    // 2. Insert Support Request
    $insertSql = "
        INSERT INTO maintenance_support_requests (
            assignment_id,
            complaint_id,
            maintenance_team_id,
            requested_by,
            ward_officer_user_id,
            support_reason,
            other_reason,
            support_details,
            support_message,
            request_status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ";

    $messageFallback = $allowedReasons[$reason] . " requested for " . $complaintCode;

    $insertStmt = mysqli_prepare($conn, $insertSql);
    mysqli_stmt_bind_param(
        $insertStmt,
        "iiiiissss",
        $assignmentId,
        $complaintId,
        $maintenanceTeamId,
        $userId,
        $wardOfficerUserId,
        $reason,
        $otherReason,
        $supportDetails,
        $messageFallback
    );

    if (!mysqli_stmt_execute($insertStmt)) {
        throw new Exception("Failed to save support request.");
    }
    
    $supportRequestId = mysqli_insert_id($conn);
    mysqli_stmt_close($insertStmt);

    // 3. Send Notification to Ward Officer
    // Determine URL based on complaint status
    $notifUrl = "/DrainGuard/pages/ward/local-team-assignment.php?highlight=" . $complaintCode;
    if ($complaintStatus === 'in_progress') {
        $notifUrl = "/DrainGuard/pages/ward/in-progress-cases.php?highlight=" . $complaintCode;
    }

    $notifTitle = "Maintenance Support Required";
    $notifMessage = "Support requested for task " . $complaintCode . " by Maintenance Team.";
    
    $notifSql = "
        INSERT INTO ward_notifications (
            recipient_user_id, 
            sender_user_id,
            related_complaint_id, 
            notification_type, 
            notification_title, 
            notification_message, 
            is_read
        ) VALUES (?, ?, ?, 'system', ?, ?, 0)
    ";
    
    $notifStmt = mysqli_prepare($conn, $notifSql);
    mysqli_stmt_bind_param($notifStmt, "iiiss", $wardOfficerUserId, $userId, $complaintId, $notifTitle, $notifMessage);
    mysqli_stmt_execute($notifStmt);
    mysqli_stmt_close($notifStmt);

    mysqli_commit($conn);
    
    echo json_encode(["success" => true, "message" => "Support request sent to Ward Officer."]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>