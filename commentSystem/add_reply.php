<?php
// C:\xampp\htdocs\DrainGuard\commentSystem\add_reply.php

require_once "../config.php";
require_once __DIR__ . '/discussion_logic.php';

header("Content-Type: application/json; charset=UTF-8");

function cs_reply_json_response($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

function cs_reply_clean_text($value)
{
    return trim((string)($value ?? ""));
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    cs_reply_json_response(false, "Invalid request. Please try again. Please try again.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    cs_reply_json_response(false, "You must be logged in to reply.");
}

$complaintId = (int)($_POST["complaint_id"] ?? 0);
$parentCommentId = (int)($_POST["parent_comment_id"] ?? 0);
$commentText = cs_reply_clean_text($_POST["comment_text"] ?? "");

if ($complaintId <= 0 || $parentCommentId <= 0) {
    cs_reply_json_response(false, "Invalid reply target.");
}

if ($commentText === "") {
    cs_reply_json_response(false, "Reply cannot be empty.");
}

if (mb_strlen($commentText) > 1000) {
    cs_reply_json_response(false, "Reply cannot exceed 1000 characters.");
}

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

// Access validation
$context = cs_get_discussion_context($conn, $complaintId);
if (!cs_has_discussion_access($context, $userId, $currentUserRole)) {
    cs_reply_json_response(false, "You are not allowed to participate in this discussion.");
}

$parentSql = "
    SELECT
        cc.id AS comment_id,
        cc.user_id AS parent_user_id,
        u.user_role AS parent_user_role,
        c.complaint_code
    FROM comment_likes cc
    INNER JOIN users u
        ON cc.user_id = u.user_id
    INNER JOIN complaints c
        ON cc.complaint_id = c.complaint_id
    WHERE cc.id = ?
      AND cc.complaint_id = ?
      AND cc.parent_id IS NULL
      AND cc.is_deleted = 0
    LIMIT 1
";

$parentStmt = mysqli_prepare($conn, $parentSql);

if (!$parentStmt) {
    cs_reply_json_response(false, "Unable to add reply. Please try again." . mysqli_error($conn));
}

mysqli_stmt_bind_param($parentStmt, "ii", $parentCommentId, $complaintId);
mysqli_stmt_execute($parentStmt);

$parentResult = mysqli_stmt_get_result($parentStmt);

if (!$parentResult || mysqli_num_rows($parentResult) !== 1) {
    mysqli_stmt_close($parentStmt);
    cs_reply_json_response(false, "Parent comment not found.");
}

$parentRow = mysqli_fetch_assoc($parentResult);
mysqli_stmt_close($parentStmt);

$sql = "
    INSERT INTO comment_likes (
        complaint_id,
        user_id,
        parent_id,
        type,
        comment_text,
        created_at
    )
    VALUES (?, ?, ?, 'comment', ?, NOW())
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    cs_reply_json_response(false, "Failed to prepare reply insert.");
}

mysqli_stmt_bind_param($stmt, "iiis", $complaintId, $userId, $parentCommentId, $commentText);
mysqli_stmt_execute($stmt);

if (mysqli_stmt_affected_rows($stmt) <= 0) {
    mysqli_stmt_close($stmt);
    cs_reply_json_response(false, "Unable to add reply. Please try again.");
}

$replyId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

// Reply notification goes to the parent author, and citizen replies also notify the responsible officer.
$parentNotificationType = (
    $currentUserRole === "citizen"
    && in_array((string)$parentRow["parent_user_role"], ["central_officer", "ward_officer"], true)
) ? "citizen_discussion_reply" : "comment_reply";

$sentNotifications = cs_dispatch_reply_notification($conn, $parentRow, $userId, $complaintId, $parentNotificationType);

if ($currentUserRole === "citizen") {
    cs_dispatch_notifications($conn, $context, $userId, $currentUserRole, $complaintId, true, $sentNotifications);
}

cs_reply_json_response(true, "Reply added successfully.", [
    "reply_id" => $replyId
]);
