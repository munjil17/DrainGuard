<?php
// C:\xampp8\htdocs\DrainGuard\commentSystem\add_comment.php

require_once "../config.php";

header("Content-Type: application/json; charset=UTF-8");

function cs_json_response($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

function cs_clean_text($value)
{
    return trim((string)($value ?? ""));
}

function cs_is_discussion_allowed($conn, $complaintId)
{
    $allowedStatuses = [
        "closed",
        "rejected_by_central",
        "rejected_by_ward",
        "duplicate",
        "final_rejected",
        "reopened",
        "disputed"
    ];

    $sql = "
        SELECT complaint_status
        FROM complaints
        WHERE complaint_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $complaintId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (!$result || mysqli_num_rows($result) !== 1) {
        mysqli_stmt_close($stmt);
        return false;
    }

    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return in_array((string)$row["complaint_status"], $allowedStatuses, true);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    cs_json_response(false, "Invalid request method.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    cs_json_response(false, "You must be logged in to comment.");
}

$complaintId = (int)($_POST["complaint_id"] ?? 0);
$commentText = cs_clean_text($_POST["comment_text"] ?? "");

if ($complaintId <= 0) {
    cs_json_response(false, "Invalid complaint.");
}

if ($commentText === "") {
    cs_json_response(false, "Comment cannot be empty.");
}

if (mb_strlen($commentText) > 1000) {
    cs_json_response(false, "Comment cannot exceed 1000 characters.");
}

if (!cs_is_discussion_allowed($conn, $complaintId)) {
    cs_json_response(false, "Discussion is available only after complaint is closed, rejected, duplicate, or final rejected.");
}

$sql = "
    INSERT INTO comment_likes (
        complaint_id,
        user_id,
        type,
        comment_text,
        created_at
    )
    VALUES (?, ?, 'comment', ?, NOW())
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    cs_json_response(false, "Failed to prepare comment insert.");
}

mysqli_stmt_bind_param($stmt, "iis", $complaintId, $userId, $commentText);
mysqli_stmt_execute($stmt);

if (mysqli_stmt_affected_rows($stmt) <= 0) {
    mysqli_stmt_close($stmt);
    cs_json_response(false, "Failed to insert comment.");
}

$commentId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

// Get current user role
$roleSql = "SELECT user_role FROM users WHERE user_id = ? LIMIT 1";
$roleStmt = mysqli_prepare($conn, $roleSql);
$currentUserRole = "";
if ($roleStmt) {
    mysqli_stmt_bind_param($roleStmt, "i", $userId);
    mysqli_stmt_execute($roleStmt);
    $roleRes = mysqli_stmt_get_result($roleStmt);
    if ($roleRes && $roleRow = mysqli_fetch_assoc($roleRes)) {
        $currentUserRole = (string)$roleRow["user_role"];
    }
    mysqli_stmt_close($roleStmt);
}

// Get complaint details
$compSql = "SELECT user_id AS citizen_id, complaint_code FROM complaints WHERE complaint_id = ? LIMIT 1";
$compStmt = mysqli_prepare($conn, $compSql);
$citizenId = 0;
$complaintCode = "";
if ($compStmt) {
    mysqli_stmt_bind_param($compStmt, "i", $complaintId);
    mysqli_stmt_execute($compStmt);
    $compRes = mysqli_stmt_get_result($compStmt);
    if ($compRes && $compRow = mysqli_fetch_assoc($compRes)) {
        $citizenId = (int)$compRow["citizen_id"];
        $complaintCode = (string)$compRow["complaint_code"];
    }
    mysqli_stmt_close($compStmt);
}

// Insert Notification Function
function cs_insert_notification($conn, $table, $recipientUserId, $senderUserId, $complaintId, $title, $message) {
    $sql = "INSERT INTO {$table} (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at)
            VALUES (?, ?, ?, 'comment_reply', ?, ?, 0, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iiiss", $recipientUserId, $senderUserId, $complaintId, $title, $message);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

$title = "New Comment on Complaint";
$message = "A new comment was added to complaint " . ($complaintCode ?: "#" . $complaintId) . ".";

if ($currentUserRole === 'citizen') {
    // Notify Central Officer(s)
    $centralSql = "SELECT action_by_user_id FROM complaint_status_logs WHERE complaint_id = ? AND action_by_role = 'central_officer' ORDER BY log_id DESC LIMIT 1";
    $centralStmt = mysqli_prepare($conn, $centralSql);
    $centralId = 0;
    if ($centralStmt) {
        mysqli_stmt_bind_param($centralStmt, "i", $complaintId);
        mysqli_stmt_execute($centralStmt);
        $centralRes = mysqli_stmt_get_result($centralStmt);
        if ($centralRes && $centralRow = mysqli_fetch_assoc($centralRes)) {
            $centralId = (int)$centralRow["action_by_user_id"];
        }
        mysqli_stmt_close($centralStmt);
    }

    if ($centralId === 0) {
        // Fallback: Notify all central officers
        $allCentralSql = "SELECT user_id FROM users WHERE user_role = 'central_officer' AND status = 'active'";
        $allCentralRes = mysqli_query($conn, $allCentralSql);
        if ($allCentralRes) {
            while ($cRow = mysqli_fetch_assoc($allCentralRes)) {
                cs_insert_notification($conn, "central_notifications", (int)$cRow["user_id"], $userId, $complaintId, $title, $message);
            }
        }
    } else {
        cs_insert_notification($conn, "central_notifications", $centralId, $userId, $complaintId, $title, $message);
    }

} elseif ($currentUserRole === 'central_officer') {
    // Notify Citizen
    if ($citizenId > 0 && $citizenId !== $userId) {
        cs_insert_notification($conn, "citizen_notifications", $citizenId, $userId, $complaintId, $title, $message);
    }
}

cs_json_response(true, "Comment added successfully.", [
    "comment_id" => $commentId
]);