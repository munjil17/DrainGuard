<?php
// C:\xampp\htdocs\DrainGuard\commentSystem\add_comment.php

require_once "../config.php";
require_once __DIR__ . '/discussion_logic.php';

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
    cs_json_response(false, "You are not allowed to participate in this discussion.");
}

// Insert comment
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

// Notifications
cs_dispatch_notifications($conn, $context, $userId, $currentUserRole, $complaintId, false);

cs_json_response(true, "Comment added successfully.", [
    "comment_id" => $commentId
]);