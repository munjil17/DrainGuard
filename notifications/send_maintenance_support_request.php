<?php
require_once "../config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json");

$userId = $_SESSION['user_id'] ?? 0;

if ($userId <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized request."
    ]);
    exit;
}

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
$reason = trim($_POST['support_reason'] ?? '');

$allowedReasons = [
    'equipment_needed' => 'Equipment Needed',
    'extra_manpower_needed' => 'Extra Manpower Needed',
    'location_access_problem' => 'Location / Access Problem',
    'complaint_info_unclear' => 'Complaint Info Unclear',
    'safety_risk' => 'Safety Risk',
    'large_work_scope' => 'Large Work Scope'
];
if ($assignmentId <= 0 || !array_key_exists($reason, $allowedReasons)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid support request."
    ]);
    exit;
}

$assignmentSql = "
    SELECT
        ca.assignment_id,
        ca.complaint_id,
        ca.maintenance_team_id,
        ca.assigned_by AS ward_officer_user_id,
        c.complaint_code
    FROM complaint_assignments ca
    INNER JOIN complaints c
        ON c.complaint_id = ca.complaint_id
    INNER JOIN maintenance_team_members mtm
        ON mtm.maintenance_team_id = ca.maintenance_team_id
    WHERE ca.assignment_id = ?
    AND mtm.user_id = ?
    LIMIT 1
";

$assignmentStmt = mysqli_prepare($conn, $assignmentSql);

if (!$assignmentStmt) {
    echo json_encode([
        "success" => false,
        "message" => "Database prepare failed."
    ]);
    exit;
}

mysqli_stmt_bind_param($assignmentStmt, "ii", $assignmentId, $userId);
mysqli_stmt_execute($assignmentStmt);
$assignmentResult = mysqli_stmt_get_result($assignmentStmt);

if (!$assignmentResult || mysqli_num_rows($assignmentResult) === 0) {
    mysqli_stmt_close($assignmentStmt);

    echo json_encode([
        "success" => false,
        "message" => "Assignment not found for this maintenance team."
    ]);
    exit;
}

$assignment = mysqli_fetch_assoc($assignmentResult);
mysqli_stmt_close($assignmentStmt);

$complaintId = (int)$assignment['complaint_id'];
$maintenanceTeamId = (int)$assignment['maintenance_team_id'];
$wardOfficerUserId = (int)$assignment['ward_officer_user_id'];
$complaintCode = $assignment['complaint_code'] ?? 'Complaint';

if ($wardOfficerUserId <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Ward officer not found for this assignment."
    ]);
    exit;
}

$message = $allowedReasons[$reason] . " requested for " . $complaintCode;

$insertSql = "
    INSERT INTO maintenance_support_requests (
        assignment_id,
        complaint_id,
        maintenance_team_id,
        requested_by,
        ward_officer_user_id,
        support_reason,
        support_message,
        request_status
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
";

$insertStmt = mysqli_prepare($conn, $insertSql);

if (!$insertStmt) {
    echo json_encode([
        "success" => false,
        "message" => "Support request insert prepare failed."
    ]);
    exit;
}

mysqli_stmt_bind_param(
    $insertStmt,
    "iiiiiss",
    $assignmentId,
    $complaintId,
    $maintenanceTeamId,
    $userId,
    $wardOfficerUserId,
    $reason,
    $message
);

if (!mysqli_stmt_execute($insertStmt)) {
    mysqli_stmt_close($insertStmt);

    echo json_encode([
        "success" => false,
        "message" => "Failed to send support request."
    ]);
    exit;
}

mysqli_stmt_close($insertStmt);

echo json_encode([
    "success" => true,
    "message" => "Support request sent to Ward Officer."
]);
exit;